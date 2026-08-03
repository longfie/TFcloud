<?php

namespace plugin\sport\app;

use app\exception\ApiException;

final class CurlSportHttpClient implements SportHttpClientInterface
{
    public function request(string $method, string $url, array $options = []): array
    {
        if (!function_exists('curl_init')) {
            throw new ApiException('SPORT_RUNTIME_MISSING', '服务器缺少 curl 扩展', 500);
        }
        $query = is_array($options['query'] ?? null) ? $options['query'] : [];
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $responseHeaders = [];
        $handle = curl_init($url);
        $headers = [];
        foreach ((array)($options['headers'] ?? []) as $name => $value) {
            $headers[] = is_int($name) ? (string)$value : $name . ': ' . $value;
        }
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => (int)($options['connect_timeout'] ?? 8),
            CURLOPT_TIMEOUT => (int)($options['timeout'] ?? 20),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $separator = strpos($line, ':');
                if ($separator !== false) {
                    $name = strtolower(trim(substr($line, 0, $separator)));
                    $responseHeaders[$name] = trim(substr($line, $separator + 1));
                }
                return strlen($line);
            },
        ]);
        if (array_key_exists('raw', $options)) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, (string)$options['raw']);
        } elseif (isset($options['form']) && is_array($options['form'])) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($options['form']));
        }

        $body = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($body === false || $error !== '') {
            throw new ApiException('UPSTREAM_REQUEST_FAILED', 'Zepp Life 服务暂时无法连接', 502);
        }
        if (strlen($body) > 1024 * 1024) {
            throw new ApiException('UPSTREAM_RESPONSE_TOO_LARGE', 'Zepp Life 返回内容过大', 502);
        }
        $decoded = $body === '' ? null : json_decode($body, true);

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => (string)$body,
            'json' => is_array($decoded) ? $decoded : null,
        ];
    }
}
