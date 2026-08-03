<?php

namespace app\service;

use app\exception\ApiException;

final class QqRelayCodec
{
    public function decode(string $encoded, string $decodeKey, string $appSecret): array
    {
        if ($encoded === '' || strlen($encoded) > 4096 || $decodeKey === '' || $appSecret === '') {
            throw new ApiException('QQ_RELAY_PAYLOAD_INVALID', 'QQ 中转回调参数无效', 422);
        }

        $normalized = str_replace(['O0O0O', 'o000o', 'oo00o'], ['=', '+', '/'], $encoded);
        $pairs = str_split($normalized, 2);
        foreach (str_split($decodeKey) as $index => $character) {
            if (isset($pairs[$index][1]) && hash_equals($character, $pairs[$index][1])) {
                $pairs[$index] = $pairs[$index][0];
            }
        }
        $json = base64_decode(implode('', $pairs), true);
        $payload = $json === false ? null : json_decode($json, true);
        if (!is_array($payload)
            || !isset($payload['token'], $payload['qqkey'])
            || !hash_equals(md5($decodeKey . $appSecret), (string)$payload['token'])) {
            throw new ApiException('QQ_RELAY_SIGNATURE_INVALID', 'QQ 中转回调签名校验失败', 422);
        }

        $subject = trim((string)$payload['qqkey']);
        if ($subject === '' || mb_strlen($subject) > 191) {
            throw new ApiException('QQ_RELAY_PAYLOAD_INVALID', 'QQ 中转身份无效', 422);
        }
        return [
            'subject' => $subject,
            'nickname' => mb_substr(trim((string)($payload['uinfoname'] ?? 'QQ 用户')), 0, 191) ?: 'QQ 用户',
        ];
    }
}
