<?php

declare(strict_types=1);

namespace Trix\Tebex\api;

use quark\scheduler\AsyncTask;
use Vow\Deferred;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_reset;
use function curl_setopt_array;
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
use const CURLOPT_SSL_VERIFYHOST;
use const CURLOPT_SSL_VERIFYPEER;
use const CURLOPT_TCP_KEEPALIVE;
use const CURLOPT_TIMEOUT_MS;
use const CURLOPT_URL;
use const CURLOPT_USERAGENT;
use const CURLOPT_WRITEFUNCTION;
use const CURLPROTO_HTTP;
use const CURLPROTO_HTTPS;

/**
 * @internal
 */
final class HttpTask extends AsyncTask {
    private const string DEFERRED = "deferred";
    private const int MAX_RESPONSE_BYTES = 8 * 1024 * 1024;

    // Reuse the worker's connection across requests.
    private static ?\CurlHandle $curl = null;

    /**
     * @phpstan-param Deferred<array{int, ?string, string, ?string}> $deferred
     */
    public function __construct(
        private string $method,
        private string $url,
        #[\SensitiveParameter] private string $secret,
        private string $body,
        private int $timeoutMs,
        private bool $allowHttp,
        Deferred $deferred
    ) {
        $this->storeLocal(self::DEFERRED, $deferred);
    }

    public function onRun(): void {
        try {
            $this->setResult($this->request());
        } catch (\Throwable) {
            self::$curl = null;
            $this->setResult(null);
        }
    }

    /**
     * @phpstan-return array{int, ?string, string, ?string}
     */
    private function request(): array {
        $curl = self::$curl ??= curl_init();
        curl_reset($curl);
        $body = "";
        $retryAfter = null;
        $headers = ["X-Tebex-Secret: " . $this->secret, "Accept: application/json"];
        if ($this->body !== "") {
            $headers[] = "Content-Type: application/x-www-form-urlencoded";
        }
        $configured = curl_setopt_array($curl, [
            CURLOPT_URL => $this->url,
            CURLOPT_CUSTOMREQUEST => $this->method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => "Quark-Tebex/1.0",
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => $this->allowHttp ? CURLPROTO_HTTPS | CURLPROTO_HTTP : CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => 5000,
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_ENCODING => "",
            CURLOPT_WRITEFUNCTION => static function (\CurlHandle $_, string $chunk) use (&$body): int {
                $length = strlen($chunk);
                if (strlen($body) + $length > self::MAX_RESPONSE_BYTES) return 0;

                $body .= $chunk;
                return $length;
            },
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $_, string $line) use (&$retryAfter): int {
                if (stripos($line, "retry-after:") === 0) {
                    $retryAfter = trim(substr($line, 12));
                } elseif (str_starts_with($line, "HTTP/")) {
                    $retryAfter = null;
                }
                return strlen($line);
            },
        ] + ($this->body !== "" ? [CURLOPT_POSTFIELDS => $this->body] : []));

        if (!$configured || curl_exec($curl) !== true) {
            self::$curl = null;
            return [0, null, "", "Request failed"];
        }
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_reset($curl);
        return [$status, $retryAfter, $body, null];
    }

    public function onCompletion(): void {
        /** @phpstan-var Deferred<array{int, ?string, string, ?string}> $deferred */
        $deferred = $this->fetchLocal(self::DEFERRED);
        /** @phpstan-var array{int, ?string, string, ?string} $result */
        $result = $this->getResult() ?? [0, null, "", "Request failed"];
        $deferred->resolve($result);
    }
}
