<?php

namespace app\sign\http;

use app\exception\ApiException;

final class CurlJsonHttpClient implements JsonHttpClientInterface
{
    public function request(string $method, string $url, array $options = []): array
    {
        $query = is_array($options['query'] ?? null) ? $options['query'] : [];
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $handle = curl_init($url);
        $headers = array_values($options['headers'] ?? []);
        $headers[] = 'Accept: application/json';
        $headers[] = 'Accept-Language: zh-CN,zh;q=0.8';
        $headers[] = 'User-Agent: Mozilla/5.0 TF-Sign/1.0';
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => (int)($options['connect_timeout'] ?? 5),
            CURLOPT_TIMEOUT => (int)($options['timeout'] ?? 20),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if (isset($options['form']) && is_array($options['form'])) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($options['form']));
        }
        if (isset($options['json']) && is_array($options['json'])) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode(
                $options['json'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ));
        }
        if (isset($options['cookie'])) {
            curl_setopt($handle, CURLOPT_COOKIE, (string)$options['cookie']);
        }
        if (isset($options['referer'])) {
            curl_setopt($handle, CURLOPT_REFERER, (string)$options['referer']);
        }

        $body = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($body === false || $error !== '') {
            throw new ApiException('UPSTREAM_REQUEST_FAILED', '第三方平台请求失败', 502);
        }
        if ($status < 200 || $status >= 300) {
            throw new ApiException('UPSTREAM_HTTP_ERROR', '第三方平台返回 HTTP ' . $status, 502);
        }
        if (strlen($body) > 1024 * 1024) {
            throw new ApiException('UPSTREAM_RESPONSE_TOO_LARGE', '第三方平台响应内容过大', 502);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new ApiException('UPSTREAM_RESPONSE_INVALID', '第三方平台返回格式错误', 502);
        }
        return $decoded;
    }
}
