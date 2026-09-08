<?php

declare(strict_types=1);

namespace Sveevee\Worker\Http;

final class CurlHttpClient implements HttpClientInterface
{
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        array $options = [],
    ): HttpResponse {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new TransportException('Unable to initialize cURL.');
        }

        $responseHeaders = [];
        $responseBody = '';
        $maximumBytes = max(1024, (int) ($options['max_bytes'] ?? 5_242_880));
        $timeout = max(1, (int) ($options['timeout'] ?? 30));
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = is_int($name) ? (string) $value : $name.': '.$value;
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => (string) ($options['user_agent'] ?? 'SveeveeResearchWorker/1.0'),
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))][] = trim($value);
                }

                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$responseBody, $maximumBytes): int {
                if (strlen($responseBody) + strlen($chunk) > $maximumBytes) {
                    return 0;
                }
                $responseBody .= $chunk;

                return strlen($chunk);
            },
        ]);

        if (isset($options['resolve_ip'])) {
            $host = (string) parse_url($url, PHP_URL_HOST);
            $port = (int) (parse_url($url, PHP_URL_PORT) ?: (parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80));
            $ip = (string) $options['resolve_ip'];
            if (str_contains($ip, ':')) {
                $ip = '['.$ip.']';
            }
            curl_setopt($handle, CURLOPT_RESOLVE, ["{$host}:{$port}:{$ip}"]);
        }

        $success = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        $errorNumber = curl_errno($handle);
        curl_close($handle);

        if ($success === false) {
            if ($errorNumber === CURLE_WRITE_ERROR && strlen($responseBody) >= $maximumBytes) {
                throw new TransportException("HTTP response exceeded {$maximumBytes} bytes.");
            }
            throw new TransportException($error !== '' ? $error : 'HTTP transport failed.');
        }

        return new HttpResponse($status, $responseHeaders, $responseBody);
    }
}

