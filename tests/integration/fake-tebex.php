<?php

declare(strict_types=1);

// A stand-in for the Tebex Plugin API: php fake-tebex.php <port> <state.json>
// The test rewrites the state file to change behaviour, every request is appended to <state.json>.log.

[, $port, $statePath] = $argv;
$logPath = $statePath . ".log";
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $error) ?: throw new RuntimeException($error);

/** @return array{int, string, list<string>} */
function route(array &$state, string $method, string $path, string $body, string $secret) : array{
	$json = fn(int $status, mixed $data, array $headers = []) : array => [$status, is_string($data) ? $data : json_encode($data), $headers];
	if($secret !== $state["secret"]){
		return $json(403, ["error_code" => 403, "error_message" => "No server could be found with the specified secret key."]);
	}
	$route = "$method $path";
	if(($state["fail"][$route] ?? 0) > 0){
		$state["fail"][$route]--;
		return $json(500, ["error_code" => 500, "error_message" => "Internal Server Error"]);
	}
	if(($state["limit"][$route] ?? 0) > 0){
		$state["limit"][$route]--;
		return $json(429, ["error_code" => 429, "error_message" => "Too many requests"], ["Retry-After: " . $state["retry_after"]]);
	}
	if(($state["malformed"][$route] ?? 0) > 0){
		$state["malformed"][$route]--;
		return $json(200, "{\"meta\": {\"next_check\"");
	}
	switch(true){
		case $route === "GET /information":
			return $json(200, [
				"account" => ["id" => 77, "domain" => "https://test.tebex.io", "name" => "Test Store", "currency" => ["iso_4217" => "USD", "symbol" => "$"], "online_mode" => true, "game_type" => $state["game_type"], "log_events" => false],
				"server" => ["id" => 5, "name" => "Integration"]
			]);
		case $route === "GET /queue":
			return $json(200, ["meta" => ["execute_offline" => $state["offline"] !== [], "next_check" => $state["next_check"], "more" => false], "players" => $state["players"]]);
		case $route === "GET /queue/offline-commands":
			$limit = $state["offline_limit"];
			return $json(200, ["meta" => ["limited" => count($state["offline"]) > $limit], "commands" => array_slice($state["offline"], 0, $limit)]);
		case $method === "GET" && str_starts_with($path, "/queue/online-commands/"):
			return $json(200, ["commands" => $state["online"][substr($path, 23)] ?? []]);
		case $route === "DELETE /queue":
			parse_str($body, $form);
			$ids = array_map("intval", (array) ($form["ids"] ?? []));
			if($ids === []){
				return $json(422, ["error_code" => 422, "error_message" => "ids is required"]);
			}
			if(!($state["sticky"] ?? false)){
				$state["offline"] = array_values(array_filter($state["offline"], fn(array $c) : bool => !in_array($c["id"], $ids, true)));
			}
			$state["acked"] = array_values(array_unique(array_merge($state["acked"], $ids)));
			return [204, "", []];
		default:
			return $json(404, ["error_code" => 404, "error_message" => "Not found"]);
	}
}

while(true){
	$client = @stream_socket_accept($server, -1);
	if($client === false){
		continue;
	}
	stream_set_timeout($client, 5);
	// One request per connection, so the plugin's two workers never wait on each other here.
	if(($line = fgets($client)) !== false && trim($line) !== ""){
		[$method, $uri] = explode(" ", trim($line), 3);
		$headers = [];
		while(($header = fgets($client)) !== false && trim($header) !== ""){
			[$name, $value] = explode(":", $header, 2) + [1 => ""];
			$headers[strtolower(trim($name))] = trim($value);
		}
		$length = (int) ($headers["content-length"] ?? 0);
		$body = $length > 0 ? (string) stream_get_contents($client, $length) : "";
		$path = (string) parse_url($uri, PHP_URL_PATH);

		$lock = fopen($statePath . ".lock", "c");
		flock($lock, LOCK_EX);
		$state = json_decode((string) file_get_contents($statePath), true);
		file_put_contents($logPath, json_encode(["t" => microtime(true), "method" => $method, "path" => $path, "query" => (string) parse_url($uri, PHP_URL_QUERY), "body" => $body]) . "\n", FILE_APPEND);
		[$status, $content, $extra] = route($state, $method, $path, $body, $headers["x-tebex-secret"] ?? "");
		file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT));
		flock($lock, LOCK_UN);
		fclose($lock);

		$response = "HTTP/1.1 $status X\r\nConnection: close\r\nContent-Length: " . strlen($content) . "\r\n" . ($content !== "" ? "Content-Type: application/json\r\n" : "");
		foreach($extra as $header){
			$response .= "$header\r\n";
		}
		fwrite($client, $response . "\r\n" . $content);
	}
	fclose($client);
}
