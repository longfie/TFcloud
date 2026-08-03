<?php

namespace plugin\cloud189\app;

use app\exception\ApiException;

final class CurlCloud189HttpClient implements Cloud189HttpClientInterface
{
    private array $cookies = [];

    public function request(string $method, string $url, array $options = []): array
    {
        if (!function_exists('curl_init')) {
            throw new ApiException('CLOUD189_RUNTIME_MISSING', '服务器缺少 curl 扩展', 500);
        }
        $query = is_array($options['query'] ?? null) ? $options['query'] : [];
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $responseHeaders = [];
        $headers = [];
        foreach ((array)($options['headers'] ?? []) as $name => $value) {
            $headers[] = is_int($name) ? (string)$value : $name . ': ' . $value;
        }
        if ($this->cookies !== []) {
            $headers[] = 'Cookie: ' . implode('; ', array_map(
                static fn (string $name, string $value): string => $name . '=' . $value,
                array_keys($this->cookies),
                array_values($this->cookies)
            ));
        }
        $setCookies = [];
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => ($options['follow_redirects'] ?? false) === true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => (int)($options['connect_timeout'] ?? 8),
            CURLOPT_TIMEOUT => (int)($options['timeout'] ?? 20),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders, &$setCookies): int {
                $separator = strpos($line, ':');
                if ($separator !== false) {
                    $name = strtolower(trim(substr($line, 0, $separator)));
                    $value = trim(substr($line, $separator + 1));
                    $responseHeaders[$name] = $value;
                    if ($name === 'set-cookie') {
                        $setCookies[] = $value;
                    }
                }
                return strlen($line);
            },
        ]);
        if (isset($options['form']) && is_array($options['form'])) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($options['form']));
        }

        $body = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $effectiveUrl = (string)curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
        curl_close($handle);

        if ($body === false || $error !== '') {
            throw new ApiException('UPSTREAM_REQUEST_FAILED', '天翼云盘服务暂时无法连接', 502);
        }
        if (strlen($body) > 1024 * 1024) {
            throw new ApiException('UPSTREAM_RESPONSE_TOO_LARGE', '天翼云盘返回内容过大', 502);
        }
        foreach ($setCookies as $cookie) {
            $pair = explode(';', $cookie, 2)[0];
            if (str_contains($pair, '=')) {
                [$name, $value] = array_map('trim', explode('=', $pair, 2));
                if ($name !== '') {
                    if ($value === '') {
                        unset($this->cookies[$name]);
                    } else {
                        $this->cookies[$name] = $value;
                    }
                }
            }
        }
        $decoded = $body === '' ? null : json_decode($body, true);

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => (string)$body,
            'json' => is_array($decoded) ? $decoded : null,
            'effective_url' => $effectiveUrl,
        ];
    }
}
