<?php

namespace plugin\bilibili\app;

use RuntimeException;

final class BilibiliLiveHeartbeatSigner
{
    public function sign(string $text, array $rules, string $key): string
    {
        $algorithms = [0 => 'md5', 1 => 'sha1', 2 => 'sha256', 3 => 'sha224', 4 => 'sha512', 5 => 'sha384'];
        $result = $text;
        foreach ($rules as $rule) {
            $algorithm = $algorithms[(int)$rule] ?? null;
            if ($algorithm === null || !in_array($algorithm, hash_hmac_algos(), true)) {
                throw new RuntimeException('不支持的直播心跳签名规则');
            }
            $result = hash_hmac($algorithm, $result, $key);
        }
        return $result;
    }

    public function heartbeatForm(object $room, array $credentials, string $userAgent): array
    {
        $timestamp = (int)floor(microtime(true) * 1000);
        $uuid = self::uuid();
        $device = json_encode([(string)($credentials['live_buvid'] ?? ''), $uuid], JSON_UNESCAPED_SLASHES);
        $payload = [
            'platform' => 'web',
            'parent_id' => (int)$room->parent_area_id,
            'area_id' => (int)$room->area_id,
            'seq_id' => (int)$room->sequence_no,
            'room_id' => (int)$room->room_id,
            'buvid' => (string)($credentials['live_buvid'] ?? ''),
            'uuid' => $uuid,
            'ets' => (int)$room->server_timestamp,
            'time' => 60,
            'ts' => $timestamp,
        ];
        $rules = json_decode((string)($room->secret_rule_json ?? '[]'), true) ?: [];
        return [
            's' => $this->sign(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $rules, (string)$room->secret_key),
            'id' => json_encode([(int)$room->parent_area_id, (int)$room->area_id, (int)$room->sequence_no, (int)$room->room_id]),
            'ets' => (int)$room->server_timestamp,
            'benchmark' => (string)$room->secret_key,
            'time' => 60,
            'ts' => $timestamp,
            'ua' => $userAgent,
            'csrf_token' => (string)$credentials['bili_jct'],
            'csrf' => (string)$credentials['bili_jct'],
            'visit_id' => '',
            'device' => $device,
        ];
    }

    public function enterForm(object $room, array $credentials, string $userAgent): array
    {
        $uuid = self::uuid();
        return [
            'id' => json_encode([(int)$room->parent_area_id, (int)$room->area_id, 0, (int)$room->room_id]),
            'ruid' => (int)$room->ruid,
            'ts' => (int)floor(microtime(true) * 1000),
            'is_patch' => 0,
            'heart_beat' => '[]',
            'ua' => $userAgent,
            'csrf_token' => (string)$credentials['bili_jct'],
            'csrf' => (string)$credentials['bili_jct'],
            'visit_id' => '',
            'device' => json_encode([(string)($credentials['live_buvid'] ?? ''), $uuid], JSON_UNESCAPED_SLASHES),
        ];
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
