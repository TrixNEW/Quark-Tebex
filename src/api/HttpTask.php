<?php

declare(strict_types=1);

namespace Trix\Tebex\api;

use quark\scheduler\AsyncTask;
use Vow\Deferred;
use function curl_errno;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_reset;
use function curl_setopt_array;
use function igbinary_serialize;
use function igbinary_unserialize;
use function is_string;
use function str_starts_with;
use function stripos;
use function strlen;
use function substr;
use function trim;
use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_CONNECTTIMEOUT_MS;
use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_ENCODING;
use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_HEADERFUNCTION;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_NOSIGNAL;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_PROTOCOLS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_SSL_VERIFYHOST;
use const CURLOPT_SSL_VERIFYPEER;
use const CURLOPT_TCP_KEEPALIVE;
use const CURLOPT_TIMEOUT_MS;
use const CURLOPT_URL;
use const CURLOPT_USERAGENT;
use const CURLPROTO_HTTP;
use const CURLPROTO_HTTPS;

/**
 * Runs one request on a dedicated worker. The curl handle lives as long as the worker so connections are reused.
 * @internal
 */
final class HttpTask extends AsyncTask{
	private const string DEFERRED = "deferred";

	private static ?\CurlHandle $curl = null;

	/**
	 * @phpstan-param Deferred<array{int, ?string, string, ?string}> $deferred
	 */
	public function __construct(
		private string $method,
		private string $url,
		private string $secret,
		private string $body,
		private int $timeoutMs,
		private bool $allowHttp,
		Deferred $deferred
	){
		$this->storeLocal(self::DEFERRED, $deferred);
	}

	public function onRun() : void{
		try{
			$this->setResult(igbinary_serialize($this->request()));
		}catch(\Throwable $e){
			$this->setResult(igbinary_serialize([0, null, "", $e::class . ": " . $e->getMessage()]));
		}
	}

	/**
	 * @phpstan-return array{int, ?string, string, ?string}
	 */
	private function request() : array{
		$curl = self::$curl ??= curl_init();
		curl_reset($curl);
		$retryAfter = null;
		$headers = ["X-Tebex-Secret: " . $this->secret, "Accept: application/json"];
		if($this->body !== ""){
			$headers[] = "Content-Type: application/x-www-form-urlencoded";
		}
		curl_setopt_array($curl, [
			CURLOPT_URL => $this->url,
			CURLOPT_CUSTOMREQUEST => $this->method,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_USERAGENT => "Quark-Tebex/1.0",
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_PROTOCOLS => $this->allowHttp ? CURLPROTO_HTTPS | CURLPROTO_HTTP : CURLPROTO_HTTPS,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_CONNECTTIMEOUT_MS => 5000,
			CURLOPT_TIMEOUT_MS => $this->timeoutMs,
			CURLOPT_NOSIGNAL => true,
			CURLOPT_TCP_KEEPALIVE => 1,
			CURLOPT_ENCODING => "",
			CURLOPT_HEADERFUNCTION => static function(\CurlHandle $_, string $line) use (&$retryAfter) : int{
				if(stripos($line, "retry-after:") === 0){
					$retryAfter = trim(substr($line, 12));
				}elseif(str_starts_with($line, "HTTP/")){
					$retryAfter = null;
				}
				return strlen($line);
			},
		] + ($this->body !== "" ? [CURLOPT_POSTFIELDS => $this->body] : []));

		$body = curl_exec($curl);
		if(!is_string($body)){
			$error = curl_errno($curl) . " " . curl_error($curl);
			self::$curl = null;
			return [0, null, "", $error];
		}
		return [curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $retryAfter, $body, null];
	}

	public function onCompletion() : void{
		/** @phpstan-var Deferred<array{int, ?string, string, ?string}> $deferred */
		$deferred = $this->fetchLocal(self::DEFERRED);
		$raw = $this->getResult();
		/** @phpstan-var array{int, ?string, string, ?string} $result */
		$result = is_string($raw) ? igbinary_unserialize($raw) : [0, null, "", "Request produced no result"];
		$deferred->resolve($result);
	}
}
