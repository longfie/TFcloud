<?php

namespace app\service;

use app\exception\ApiException;

final class QqRelayApplicationCodec
{
    private const MAX_CLOCK_SKEW_SECONDS = 300;

    /**
     * @param array<string, mixed> $payload
     * @return array{subject: string, nickname: string}
     */
    public function decode(array $payload, string $clientSecret, ?int $now = null): array
    {
        $subject = trim((string)($payload['subject'] ?? ''));
        $nicknameEncoded = trim((string)($payload['nickname'] ?? ''));
        $issuedAtValue = trim((string)($payload['issued_at'] ?? ''));
        $state = trim((string)($payload['state'] ?? ''));
        $signature = strtolower(trim((string)($payload['signature'] ?? '')));

        if ($clientSecret === ''
            || !preg_match('/^[a-f0-9]{64}$/i', $subject)
            || $nicknameEncoded === ''
            || strlen($nicknameEncoded) > 2048
            || !preg_match('/^\d{10}$/', $issuedAtValue)
            || !preg_match('/^[A-Za-z0-9._~-]{16,128}$/', $state)
            || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            throw new ApiException('QQ_RELAY_CALLBACK_INVALID', 'QQ 中转回调参数无效', 422);
        }

        $issuedAt = (int)$issuedAtValue;
        if (abs(($now ?? time()) - $issuedAt) > self::MAX_CLOCK_SKEW_SECONDS) {
            throw new ApiException('QQ_RELAY_CALLBACK_EXPIRED', 'QQ 中转登录结果已过期，请重新登录', 422);
        }

        $signatureText = $subject . "\n" . $nicknameEncoded . "\n" . $issuedAtValue . "\n" . $state;
        $expected = hash_hmac('sha256', $signatureText, $clientSecret);
        if (!hash_equals($expected, $signature)) {
            throw new ApiException('QQ_RELAY_SIGNATURE_INVALID', 'QQ 中转回调签名校验失败', 422);
        }

        $nickname = base64_decode($nicknameEncoded, true);
        if ($nickname === false) {
            throw new ApiException('QQ_RELAY_CALLBACK_INVALID', 'QQ 中转昵称格式无效', 422);
        }
        $nickname = mb_substr(trim(stripslashes($nickname)), 0, 191);

        return [
            'subject' => strtolower($subject),
            'nickname' => $nickname !== '' ? $nickname : 'QQ 用户',
        ];
    }
}
