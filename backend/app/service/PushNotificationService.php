<?php

namespace app\service;

use app\exception\ApiException;
use support\Db;
use support\Log;

/**
 * 即时推送渠道（微信/APP 推送），作为邮件通知的补充。
 * 发送失败只记录日志，绝不影响业务主流程。
 */
final class PushNotificationService
{
    public const CHANNELS = ['pushplus', 'serverchan', 'bark'];

    public function sendToUser(
        object $user,
        string $title,
        string $message,
        array $facts = [],
        ?string $actionUrl = null
    ): bool {
        $channel = trim((string)($user->push_channel ?? ''));
        $token = trim((string)($user->push_token ?? ''));
        if (!in_array($channel, self::CHANNELS, true) || $token === '') {
            return false;
        }
        $lines = [$message];
        foreach ($facts as $label => $value) {
            $lines[] = $label . '：' . $value;
        }
        if ($actionUrl !== null && $actionUrl !== '') {
            $lines[] = '处理入口：' . $actionUrl;
        }
        $body = implode("\n", $lines);
        try {
            return match ($channel) {
                'pushplus' => $this->sendPushplus($token, $title, $body),
                'serverchan' => $this->sendServerchan($token, $title, $body),
                'bark' => $this->sendBark($token, $title, $body),
            };
        } catch (\Throwable $exception) {
            Log::warning('push notification failed', [
                'channel' => $channel,
                'exception' => $exception::class,
                'message' => mb_substr($exception->getMessage(), 0, 200),
            ]);
            return false;
        }
    }

    public function test(int $userId): void
    {
        $user = Db::table('TF_users')->where('id', $userId)->first();
        if (!$user) {
            throw new ApiException('USER_NOT_FOUND', '用户不存在', 404);
        }
        $channel = trim((string)($user->push_channel ?? ''));
        if (!in_array($channel, self::CHANNELS, true) || trim((string)($user->push_token ?? '')) === '') {
            throw new ApiException('PUSH_NOT_CONFIGURED', '请先保存推送渠道和令牌后再测试', 422);
        }
        $sent = $this->sendToUser(
            $user,
            '推送测试',
            '这是一条来自天方云签的测试推送，收到即代表推送渠道配置成功。',
            ['发送时间' => date('Y-m-d H:i:s')]
        );
        if (!$sent) {
            throw new ApiException('PUSH_SEND_FAILED', '推送发送失败，请检查令牌是否正确', 502);
        }
    }

    private function sendPushplus(string $token, string $title, string $body): bool
    {
        $response = $this->request(
            'https://www.pushplus.plus/send',
            json_encode([
                'token' => $token,
                'title' => $title,
                'content' => nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
                'template' => 'html',
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ['Content-Type: application/json']
        );
        return (int)($response['code'] ?? 0) === 200;
    }

    private function sendServerchan(string $token, string $title, string $body): bool
    {
        // Server酱³ 的 key 形如 sctp{uid}t...，走独立域名；旧版 SCT key 走 sctapi。
        $endpoint = preg_match('/^sctp(\d+)t/i', $token, $matches)
            ? "https://{$matches[1]}.push.ft07.com/send/{$token}.send"
            : "https://sctapi.ftqq.com/{$token}.send";
        $response = $this->request(
            $endpoint,
            http_build_query(['title' => $title, 'desp' => $body]),
            ['Content-Type: application/x-www-form-urlencoded']
        );
        return (int)($response['code'] ?? -1) === 0;
    }

    private function sendBark(string $token, string $title, string $body): bool
    {
        $base = str_starts_with($token, 'http://') || str_starts_with($token, 'https://')
            ? rtrim($token, '/')
            : 'https://api.day.app/' . rawurlencode($token);
        $response = $this->request($base . '/' . rawurlencode($title) . '/' . rawurlencode($body), null, []);
        return (int)($response['code'] ?? 0) === 200;
    }

    private function request(string $url, ?string $payload, array $headers): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return [];
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 2,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($payload !== null) {
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
        }
        $raw = curl_exec($handle);
        curl_close($handle);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
