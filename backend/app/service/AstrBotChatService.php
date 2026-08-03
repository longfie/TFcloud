<?php

namespace app\service;

use app\exception\ApiException;
use support\Db;

final class AstrBotChatService
{
    public function status(): array
    {
        $settings = (new SettingsService())->group('astrbot');
        $ready = (bool)$settings['enabled']
            && trim((string)$settings['base_url']) !== ''
            && !empty($settings['api_key_configured']);
        return [
            'enabled' => (bool)$settings['enabled'],
            'ready' => $ready,
            'name' => trim((string)$settings['bot_name']) ?: '天方助手',
            'welcome_message' => (string)$settings['welcome_message'],
            'max_message_length' => (int)$settings['max_message_length'],
        ];
    }

    public function history(int $userId, array $filters = []): array
    {
        $page = max(1, (int)($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int)($filters['per_page'] ?? 50)));
        $conversation = Db::table('TF_astrbot_conversations')->where('user_id', $userId)->first();
        if (!$conversation) {
            return $this->pageResult([], 0, $page, $perPage);
        }
        $query = Db::table('TF_astrbot_messages')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $userId);
        $total = (int)(clone $query)->count();
        $items = $query
            ->orderByDesc('id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->reverse()
            ->map(fn ($row): array => $this->presentMessage($row))
            ->values()
            ->all();
        return $this->pageResult($items, $total, $page, $perPage);
    }

    public function send(int $userId, array $input): array
    {
        $context = $this->beginMessage($userId, $input);
        try {
            $client = new AstrBotClient(
                (string)$context['settings']['base_url'],
                (string)$context['settings']['api_key'],
                (int)$context['settings']['request_timeout_seconds']
            );
            $result = $client->chat(
                'tfsign_user_' . $userId,
                (string)$context['conversation']->astrbot_session_id,
                $context['message'],
                trim((string)$context['settings']['config_id']) ?: null
            );
            $assistantMessage = $this->completeMessage($context, $result['content']);
        } catch (\Throwable $exception) {
            $this->failMessage($context, $exception);
            if ($exception instanceof ApiException) {
                throw $exception;
            }
            $messageText = mb_substr($exception->getMessage(), 0, 500);
            throw new ApiException('ASTRBOT_REQUEST_FAILED', 'AstrBot 对话失败：' . $messageText, 502, null, $exception);
        }

        return [
            'user_message' => $this->presentMessage($context['user_message']),
            'assistant_message' => $this->presentMessage($assistantMessage),
        ];
    }

    public function stream(int $userId, array $input, callable $emit): void
    {
        $context = $this->beginMessage($userId, $input);
        $emit('started', [
            'user_message' => $this->presentMessage($context['user_message']),
            'assistant_message' => $this->presentMessage($context['pending']),
        ]);

        try {
            $client = new AstrBotClient(
                (string)$context['settings']['base_url'],
                (string)$context['settings']['api_key'],
                (int)$context['settings']['request_timeout_seconds']
            );
            $result = $client->chatStream(
                'tfsign_user_' . $userId,
                (string)$context['conversation']->astrbot_session_id,
                $context['message'],
                trim((string)$context['settings']['config_id']) ?: null,
                static function (string $chunk, bool $replace) use ($emit): void {
                    $emit($replace ? 'replace' : 'delta', ['content' => $chunk]);
                }
            );
            $assistantMessage = $this->completeMessage($context, $result['content']);
            $emit('done', [
                'user_message' => $this->presentMessage($context['user_message']),
                'assistant_message' => $this->presentMessage($assistantMessage),
            ]);
        } catch (\Throwable $exception) {
            $this->failMessage($context, $exception);
            if ($exception instanceof ApiException) {
                throw $exception;
            }
            $messageText = mb_substr($exception->getMessage(), 0, 500);
            throw new ApiException('ASTRBOT_REQUEST_FAILED', 'AstrBot 对话失败：' . $messageText, 502, null, $exception);
        }
    }

    public function reset(int $userId): array
    {
        Db::transaction(function () use ($userId): void {
            $conversation = Db::table('TF_astrbot_conversations')
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();
            if (!$conversation) {
                return;
            }
            $activePending = Db::table('TF_astrbot_messages')
                ->where('conversation_id', $conversation->id)
                ->where('role', 'assistant')
                ->where('status', 'pending')
                ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 300))
                ->exists();
            if ($activePending) {
                throw new ApiException('ASTRBOT_BUSY', '当前回复仍在生成，暂时不能开始新对话', 409);
            }
            Db::table('TF_astrbot_messages')->where('conversation_id', $conversation->id)->delete();
            Db::table('TF_astrbot_conversations')->where('id', $conversation->id)->update([
                'astrbot_session_id' => $this->uuid(),
                'last_message_at' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        });
        return ['reset' => true];
    }

    public function testConnection(): array
    {
        $settings = $this->readySettings();
        return (new AstrBotClient(
            (string)$settings['base_url'],
            (string)$settings['api_key'],
            min(30, (int)$settings['request_timeout_seconds'])
        ))->test();
    }

    private function readySettings(): array
    {
        $settings = (new SettingsService())->group('astrbot', true);
        if (!(bool)$settings['enabled']) {
            throw new ApiException('ASTRBOT_DISABLED', '智能助手尚未开启', 503);
        }
        if (trim((string)$settings['base_url']) === '' || trim((string)$settings['api_key']) === '') {
            throw new ApiException('ASTRBOT_NOT_CONFIGURED', 'AstrBot 地址或 API Key 尚未配置', 503);
        }
        return $settings;
    }

    private function beginMessage(int $userId, array $input): array
    {
        $settings = $this->readySettings();
        $message = trim((string)($input['message'] ?? ''));
        if ($message === '') {
            throw new ApiException('VALIDATION_FAILED', '请输入对话内容', 422);
        }
        if (mb_strlen($message) > (int)$settings['max_message_length']) {
            throw new ApiException(
                'VALIDATION_FAILED',
                '每条消息最多 ' . (int)$settings['max_message_length'] . ' 个字符',
                422
            );
        }

        $hourlyCount = (int)Db::table('TF_astrbot_messages')
            ->where('user_id', $userId)
            ->where('role', 'user')
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 3600))
            ->count();
        if ($hourlyCount >= (int)$settings['hourly_message_limit']) {
            throw new ApiException('ASTRBOT_RATE_LIMITED', '本小时对话次数已用完，请稍后再试', 429);
        }

        $pending = null;
        $conversation = null;
        $userMessage = null;
        Db::transaction(function () use ($userId, $message, &$conversation, &$userMessage, &$pending): void {
            $conversation = Db::table('TF_astrbot_conversations')
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();
            if (!$conversation) {
                $now = date('Y-m-d H:i:s');
                Db::table('TF_astrbot_conversations')->insertOrIgnore([
                    'conversation_no' => bin2hex(random_bytes(16)),
                    'user_id' => $userId,
                    'astrbot_session_id' => $this->uuid(),
                    'last_message_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $conversation = Db::table('TF_astrbot_conversations')
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->first();
                if (!$conversation) {
                    throw new \RuntimeException('Unable to create AstrBot conversation');
                }
            }

            $activePending = Db::table('TF_astrbot_messages')
                ->where('conversation_id', $conversation->id)
                ->where('role', 'assistant')
                ->where('status', 'pending')
                ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 300))
                ->exists();
            if ($activePending) {
                throw new ApiException('ASTRBOT_BUSY', '上一条消息仍在处理中，请稍候', 409);
            }

            $now = date('Y-m-d H:i:s');
            Db::table('TF_astrbot_messages')
                ->where('conversation_id', $conversation->id)
                ->where('role', 'assistant')
                ->where('status', 'pending')
                ->update([
                    'status' => 'failed',
                    'error_code' => 'ASTRBOT_REQUEST_INTERRUPTED',
                    'error_message' => '上一次请求未正常结束',
                    'updated_at' => $now,
                ]);
            $userMessageId = Db::table('TF_astrbot_messages')->insertGetId([
                'conversation_id' => $conversation->id,
                'user_id' => $userId,
                'role' => 'user',
                'content' => $message,
                'status' => 'completed',
                'error_code' => null,
                'error_message' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $pendingId = Db::table('TF_astrbot_messages')->insertGetId([
                'conversation_id' => $conversation->id,
                'user_id' => $userId,
                'role' => 'assistant',
                'content' => '',
                'status' => 'pending',
                'error_code' => null,
                'error_message' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            Db::table('TF_astrbot_conversations')->where('id', $conversation->id)->update([
                'last_message_at' => $now,
                'updated_at' => $now,
            ]);
            $userMessage = Db::table('TF_astrbot_messages')->where('id', $userMessageId)->first();
            $pending = Db::table('TF_astrbot_messages')->where('id', $pendingId)->first();
        });

        return [
            'settings' => $settings,
            'message' => $message,
            'conversation' => $conversation,
            'user_message' => $userMessage,
            'pending' => $pending,
        ];
    }

    private function completeMessage(array $context, string $content): object
    {
        $now = date('Y-m-d H:i:s');
        Db::table('TF_astrbot_messages')->where('id', $context['pending']->id)->update([
            'content' => $content,
            'status' => 'completed',
            'error_code' => null,
            'error_message' => null,
            'updated_at' => $now,
        ]);
        Db::table('TF_astrbot_conversations')->where('id', $context['conversation']->id)->update([
            'last_message_at' => $now,
            'updated_at' => $now,
        ]);
        return Db::table('TF_astrbot_messages')->where('id', $context['pending']->id)->first();
    }

    private function failMessage(array $context, \Throwable $exception): void
    {
        Db::table('TF_astrbot_messages')->where('id', $context['pending']->id)->update([
            'status' => 'failed',
            'error_code' => $exception instanceof ApiException ? $exception->errorCode : 'ASTRBOT_REQUEST_FAILED',
            'error_message' => mb_substr($exception->getMessage(), 0, 500),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function presentMessage(object $message): array
    {
        return [
            'id' => (int)$message->id,
            'role' => (string)$message->role,
            'content' => (string)$message->content,
            'status' => (string)$message->status,
            'error' => $message->error_code ? [
                'code' => (string)$message->error_code,
                'message' => $message->error_message ? (string)$message->error_message : null,
            ] : null,
            'created_at' => (string)$message->created_at,
            'updated_at' => (string)$message->updated_at,
        ];
    }

    private function pageResult(array $items, int $total, int $page, int $perPage): array
    {
        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, (int)ceil($total / $perPage)),
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ];
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return implode('-', [
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ]);
    }
}
