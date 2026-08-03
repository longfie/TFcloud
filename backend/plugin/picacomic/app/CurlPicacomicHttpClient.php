<?php

namespace plugin\picacomic\app;

use app\exception\ApiException;

final class CurlPicacomicHttpClient implements PicacomicHttpClientInterface
{
    public function request(string $method, string $url, array $options = []): array
    {
        if (!function_exists('curl_init')) {
            throw new ApiException('PICACOMIC_RUNTIME_MISSING', '服务器缺少 curl 扩展', 500);
        }

        $headers = [];
        foreach ((array)($options['headers'] ?? []) as $name => $value) {
            $headers[] = is_int($name) ? (string)$value : $name . ': ' . $value;
        }
        $responseHeaders = [];
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => (int)($options['connect_timeout'] ?? 8),
            CURLOPT_TIMEOUT => (int)($options['timeout'] ?? 20),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $separator = strpos($line, ':');
                if ($separator !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $separator)))]
                        = trim(substr($line, $separator + 1));
                }
                return strlen($line);
            },
        ]);
        if (array_key_exists('body', $options)) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, (string)$options['body']);
        } elseif (isset($options['json']) && is_array($options['json'])) {
            curl_setopt(
                $handle,
                CURLOPT_POSTFIELDS,
                json_encode($options['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        $body = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($body === false || $error !== '') {
            throw new ApiException('UPSTREAM_REQUEST_FAILED', '哔咔服务暂时无法连接', 502);
        }
        if (strlen($body) > 1024 * 1024) {
            throw new ApiException('UPSTREAM_RESPONSE_TOO_LARGE', '哔咔返回内容过大', 502);
        }
        $json = $body === '' ? null : json_decode($body, true);

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => (string)$body,
            'json' => is_array($json) ? $json : null,
        ];
    }
}
