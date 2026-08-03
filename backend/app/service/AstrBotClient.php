<?php

namespace app\service;

use app\exception\ApiException;

final class AstrBotClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeoutSeconds = 90
    ) {
    }

    public function chat(
        string $username,
        string $sessionId,
        string $message,
        ?string $configId = null
    ): array {
        [$status, $body, $contentType] = $this->request(
            'POST',
            $this->chatUrl(),
            $this->chatPayload($username, $sessionId, $message, $configId, false)
        );
        if ($status < 200 || $status >= 300) {
            throw new ApiException(
                'ASTRBOT_REQUEST_FAILED',
                $this->remoteError($body, "AstrBot 返回 HTTP {$status}"),
                502
            );
        }

        if (str_contains(strtolower($contentType), 'text/event-stream') || str_contains($body, 'data:')) {
            return $this->parseSse($body);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new ApiException('ASTRBOT_INVALID_RESPONSE', 'AstrBot 返回了无法识别的响应', 502);
        }
        if (($decoded['status'] ?? '') === 'error' || ($decoded['code'] ?? 0) >= 400) {
            throw new ApiException(
                'ASTRBOT_REQUEST_FAILED',
                (string)($decoded['message'] ?? $decoded['detail'] ?? 'AstrBot 对话失败'),
                502
            );
        }

        $text = $this->extractText($decoded['data'] ?? $decoded);
        if ($text === '') {
            throw new ApiException('ASTRBOT_EMPTY_RESPONSE', 'AstrBot 没有返回可展示的内容', 502);
        }
        return ['content' => $text, 'session_id' => $sessionId];
    }

    public function chatStream(
        string $username,
        string $sessionId,
        string $message,
        ?string $configId,
        callable $onChunk
    ): array {
        $handle = curl_init($this->chatUrl());
        $headers = [
            'Accept: text/event-stream, application/json',
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
        ];
        $payload = json_encode(
            $this->chatPayload($username, $sessionId, $message, $configId, true),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $body = '';
        $buffer = '';
        $state = ['text' => '', 'session_id' => '', 'errors' => [], 'events' => 0];

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (
                &$body,
                &$buffer,
                &$state,
                $onChunk
            ): int {
                $body .= $chunk;
                $buffer .= $chunk;
                while ($this->shiftSseEvent($buffer, $event)) {
                    $this->consumeSseEvent($event, $state, $onChunk);
                }
                return strlen($chunk);
            },
        ]);

        $executed = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $contentType = (string)curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($executed === false || $error !== '') {
            throw new ApiException(
                'ASTRBOT_CONNECTION_FAILED',
                '无法连接 AstrBot：' . ($error ?: '未知网络错误'),
                502
            );
        }
        if ($status < 200 || $status >= 300) {
            throw new ApiException(
                'ASTRBOT_REQUEST_FAILED',
                $this->remoteError($body, "AstrBot 返回 HTTP {$status}"),
                502
            );
        }

        if ($state['events'] > 0 || str_contains(strtolower($contentType), 'text/event-stream')) {
            if (trim($buffer) !== '') {
                $this->consumeSseEvent($buffer, $state, $onChunk);
            }
            $text = trim((string)$state['text']);
            if ($text === '') {
                $messageText = trim(implode("\n", array_filter($state['errors'])));
                throw new ApiException(
                    $messageText !== '' ? 'ASTRBOT_REQUEST_FAILED' : 'ASTRBOT_EMPTY_RESPONSE',
                    $messageText !== '' ? $messageText : 'AstrBot 没有返回可展示的内容',
                    502
                );
            }
            return [
                'content' => $text,
                'session_id' => (string)($state['session_id'] ?: $sessionId),
            ];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new ApiException('ASTRBOT_INVALID_RESPONSE', 'AstrBot 返回了无法识别的响应', 502);
        }
        if (($decoded['status'] ?? '') === 'error' || ($decoded['code'] ?? 0) >= 400) {
            throw new ApiException(
                'ASTRBOT_REQUEST_FAILED',
                (string)($decoded['message'] ?? $decoded['detail'] ?? 'AstrBot 对话失败'),
                502
            );
        }
        $text = trim($this->extractText($decoded['data'] ?? $decoded));
        if ($text === '') {
            throw new ApiException('ASTRBOT_EMPTY_RESPONSE', 'AstrBot 没有返回可展示的内容', 502);
        }
        $onChunk($text, true);
        return ['content' => $text, 'session_id' => $sessionId];
    }

    public function test(string $username = 'tfsign_connection_test'): array
    {
        $query = http_build_query([
            'username' => $username,
            'page' => 1,
            'page_size' => 1,
        ], '', '&', PHP_QUERY_RFC3986);
        [$status, $body] = $this->request('GET', $this->apiBaseUrl() . '/chat/sessions?' . $query);
        if ($status < 200 || $status >= 300) {
            throw new ApiException(
                'ASTRBOT_CONNECTION_FAILED',
                $this->remoteError($body, "AstrBot 返回 HTTP {$status}"),
                502
            );
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || ($decoded['status'] ?? 'ok') === 'error') {
            throw new ApiException(
                'ASTRBOT_CONNECTION_FAILED',
                (string)($decoded['message'] ?? 'AstrBot 连接测试失败'),
                502
            );
        }
        return ['connected' => true, 'message' => 'AstrBot 连接正常'];
    }

    public function parseSse(string $body): array
    {
        $state = ['text' => '', 'session_id' => '', 'errors' => [], 'events' => 0];
        $events = preg_split("/\r?\n\r?\n/", trim($body)) ?: [];

        foreach ($events as $event) {
            $this->consumeSseEvent($event, $state, static function (): void {
            });
        }

        $text = trim((string)$state['text']);
        if ($text === '') {
            $message = trim(implode("\n", array_filter($state['errors'])));
            throw new ApiException(
                $message !== '' ? 'ASTRBOT_REQUEST_FAILED' : 'ASTRBOT_EMPTY_RESPONSE',
                $message !== '' ? $message : 'AstrBot 没有返回可展示的内容',
                502
            );
        }

        return ['content' => $text, 'session_id' => (string)$state['session_id']];
    }

    private function chatPayload(
        string $username,
        string $sessionId,
        string $message,
        ?string $configId,
        bool $streaming
    ): array {
        $payload = [
            'username' => $username,
            'session_id' => $sessionId,
            'message' => $message,
            'enable_streaming' => $streaming,
            'flags' => [
                'enable_streaming' => $streaming,
                'enable_default_system_prompt' => true,
            ],
        ];
        if ($configId !== null && $configId !== '') {
            $payload['config_id'] = $configId;
        }
        return $payload;
    }

    private function shiftSseEvent(string &$buffer, ?string &$event): bool
    {
        if (!preg_match("/\r?\n\r?\n/", $buffer, $match, PREG_OFFSET_CAPTURE)) {
            $event = null;
            return false;
        }
        $separator = (string)$match[0][0];
        $offset = (int)$match[0][1];
        $event = substr($buffer, 0, $offset);
        $buffer = substr($buffer, $offset + strlen($separator));
        return true;
    }

    private function consumeSseEvent(string $event, array &$state, callable $onChunk): void
    {
        $dataLines = [];
        foreach (preg_split("/\r?\n/", trim($event)) ?: [] as $line) {
            if (str_starts_with($line, 'data:')) {
                $dataLines[] = ltrim(substr($line, 5));
            }
        }
        if ($dataLines === []) {
            return;
        }
        $payload = json_decode(implode("\n", $dataLines), true);
        if (!is_array($payload)) {
            return;
        }

        $state['events']++;
        $type = (string)($payload['type'] ?? $payload['t'] ?? '');
        $data = $payload['data'] ?? '';
        if ($type === 'session_id' || $type === 'session_bound') {
            $state['session_id'] = (string)($payload['session_id'] ?? (is_string($data) ? $data : ''));
            return;
        }
        if ($type === 'error') {
            $state['errors'][] = $this->extractText($data);
            return;
        }
        if ($type === 'plain' && !in_array((string)($payload['chain_type'] ?? ''), [
            'reasoning',
            'tool_call',
            'tool_call_result',
        ], true)) {
            $chunk = $this->extractText($data);
            if ($chunk === '') {
                return;
            }
            $replace = ($payload['streaming'] ?? true) === false;
            $state['text'] = $replace ? $chunk : $state['text'] . $chunk;
            $onChunk($chunk, $replace);
            return;
        }
        if (in_array($type, ['complete', 'break'], true) && $state['text'] === '') {
            $chunk = $this->extractText($data);
            if ($chunk !== '') {
                $state['text'] = $chunk;
                $onChunk($chunk, true);
            }
        }
    }

    private function request(string $method, string $url, ?array $payload = null): array
    {
        $handle = curl_init($url);
        $headers = [
            'Accept: text/event-stream, application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ];
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ));
        }

        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $contentType = (string)curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false || $error !== '') {
            throw new ApiException(
                'ASTRBOT_CONNECTION_FAILED',
                '无法连接 AstrBot：' . ($error ?: '未知网络错误'),
                502
            );
        }
        return [$status, (string)$body, $contentType];
    }

    private function apiBaseUrl(): string
    {
        $url = rtrim($this->baseUrl, '/');
        if (str_ends_with($url, '/api/v1/chat')) {
            return substr($url, 0, -5);
        }
        return str_ends_with($url, '/api/v1') ? $url : $url . '/api/v1';
    }

    private function chatUrl(): string
    {
        $url = rtrim($this->baseUrl, '/');
        return str_ends_with($url, '/api/v1/chat') ? $url : $this->apiBaseUrl() . '/chat';
    }

    private function remoteError(string $body, string $fallback): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return $fallback;
        }
        return trim((string)($decoded['message'] ?? $decoded['detail'] ?? '')) ?: $fallback;
    }

    private function extractText(mixed $value): string
    {
        if (is_string($value) || is_numeric($value)) {
            return (string)$value;
        }
        if (!is_array($value)) {
            return '';
        }
        foreach (['text', 'content', 'message', 'data'] as $key) {
            if (array_key_exists($key, $value)) {
                $text = $this->extractText($value[$key]);
                if ($text !== '') {
                    return $text;
                }
            }
        }
        return '';
    }
}
