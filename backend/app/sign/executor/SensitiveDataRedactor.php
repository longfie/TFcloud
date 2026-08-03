<?php

namespace app\sign\executor;

final class SensitiveDataRedactor
{
    private const SENSITIVE_KEYS = [
        'cookie', 'cookies', 'bduss', 'stoken', 'ptoken', 'sessdata', 'bili_jct',
        'music_u', 'p00001', 'p00003', 'token', 'access_token', 'refresh_token',
        'password', 'passwd', 'pwd', 'authorization',
    ];

    public function redact(array $data): array
    {
        foreach ($data as $key => &$value) {
            if (in_array(strtolower((string)$key), self::SENSITIVE_KEYS, true)) {
                $value = '[REDACTED]';
            } elseif (is_array($value)) {
                $value = $this->redact($value);
            } elseif (is_string($value)) {
                $value = $this->redactString($value);
            }
        }
        unset($value);
        return $data;
    }

    public function redactString(string $value): string
    {
        $keys = implode('|', array_map(static fn (string $key): string => preg_quote($key, '/'), self::SENSITIVE_KEYS));
        $value = preg_replace(
            '/\b(?:' . $keys . ')\b\s*[=:]\s*["\']?[^"\'\s;,&]+["\']?/i',
            '[REDACTED]',
            $value
        ) ?? $value;

        return preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=\-]+/i', 'Bearer [REDACTED]', $value) ?? $value;
    }
}
