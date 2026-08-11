<?php

namespace plugin\bilibili\app;

use app\sign\contract\AbstractSignPlugin;
use app\sign\dto\AccountProfile;
use app\sign\dto\HealthResult;
use app\sign\dto\PluginMetadata;
use app\sign\dto\SignContext;
use app\sign\dto\SignRecord;
use app\sign\dto\SignResult;

final class BilibiliPlugin extends AbstractSignPlugin
{
    public function metadata(): PluginMetadata
    {
        return new PluginMetadata('bilibili', '哔哩哔哩', '0.4.0', '哔哩哔哩主站、直播每日任务与粉丝牌挂机插件');
    }

    public function credentialRules(): array
    {
        return [
            'sessdata' => ['required', 'string'],
            'bili_jct' => ['required', 'string'],
            'dede_user_id' => ['required', 'string'],
            'dede_user_id_ckmd5' => ['nullable', 'string'],
            'live_buvid' => ['nullable', 'string'],
            'buvid3' => ['nullable', 'string'],
            'buvid4' => ['nullable', 'string'],
        ];
    }

    public function validateAccount(array $credentials): AccountProfile
    {
        $this->requireCredentials($credentials, ['sessdata', 'bili_jct', 'dede_user_id']);
        return (new BilibiliClient($credentials))->profile();
    }

    public function supportedActions(): array
    {
        return [
            'daily_tasks',
            'watch_video',
            'share_video',
            'give_coin',
            'live_sign',
            'live_daily_bag',
            'live_heartbeat',
            'live_fans_medal',
            'capsule_open',
            'comic_sign',
        ];
    }

    public function execute(SignContext $context): SignResult
    {
        $this->requireCredentials($context->credentials, ['sessdata', 'bili_jct', 'dede_user_id']);
        if ($context->action === 'live_fans_medal') {
            throw new \LogicException('粉丝牌挂机任务必须由独立 Bilibili Live Worker 执行');
        }
        $client = new BilibiliClient($context->credentials);
        $actions = $context->action === 'daily_tasks'
            ? $this->dailyActions($context->settings)
            : [$context->action];

        $records = [];
        foreach ($actions as $action) {
            if ($action === 'give_coin' && ($context->settings['allow_coin_spend'] ?? false) !== true) {
                $record = $this->record([
                    'key' => 'video:give_coin',
                    'action' => 'give_coin',
                    'status' => 'skipped',
                    'target_type' => 'video',
                    'target_id' => null,
                    'target_name' => null,
                    'code' => 'COIN_SPEND_NOT_ALLOWED',
                    'message' => '未显式允许消耗硬币，已跳过投币',
                    'rewards' => [],
                    'metrics' => [],
                ]);
                $records[] = $record;
                $context->report($record);
                continue;
            }
            if ($action === 'capsule_open' && ($context->settings['allow_capsule_spend'] ?? false) !== true) {
                $record = $this->record([
                    'key' => 'live:capsule_open',
                    'action' => 'capsule_open',
                    'status' => 'skipped',
                    'target_type' => 'live',
                    'target_id' => null,
                    'target_name' => '哔哩哔哩直播扭蛋',
                    'code' => 'CAPSULE_SPEND_NOT_ALLOWED',
                    'message' => '未显式允许使用扭蛋币，已跳过扭蛋任务',
                    'rewards' => [],
                    'metrics' => [],
                ]);
                $records[] = $record;
                $context->report($record);
                continue;
            }
            $record = $this->invoke($client, $action, $context->settings);
            $records[] = $record;
            $context->report($record);
        }

        $failed = count(array_filter($records, static fn (SignRecord $record): bool => $record->status === 'failed'));
        $completed = count(array_filter(
            $records,
            static fn (SignRecord $record): bool => in_array($record->status, ['succeeded', 'already_done'], true)
        ));
        return new SignResult(
            $failed === 0 ? 'succeeded' : ($completed > 0 ? 'partial' : 'failed'),
            $failed === 0 ? '哔哩哔哩任务完成' : '部分哔哩哔哩任务失败',
            $records,
            ['total' => count($records), 'completed' => $completed, 'failed' => $failed]
        );
    }

    public function healthCheck(): HealthResult
    {
        return extension_loaded('curl')
            ? new HealthResult(true)
            : new HealthResult(false, 'curl extension is missing');
    }

    private function dailyActions(array $settings): array
    {
        $mapping = [
            'watch_video' => $settings['watch_enabled'] ?? true,
            'share_video' => $settings['share_enabled'] ?? true,
            'give_coin' => $settings['coin_enabled'] ?? false,
            'live_sign' => $settings['live_sign_enabled'] ?? true,
            'live_daily_bag' => $settings['live_daily_bag_enabled'] ?? false,
            'live_heartbeat' => $settings['live_heartbeat_enabled'] ?? false,
            'capsule_open' => $settings['capsule_enabled'] ?? false,
            'comic_sign' => $settings['comic_sign_enabled'] ?? false,
        ];
        return array_keys(array_filter($mapping, static fn ($enabled): bool => $enabled === true));
    }

    private function invoke(BilibiliClient $client, string $action, array $settings): SignRecord
    {
        try {
            $result = match ($action) {
                'watch_video' => $client->watchVideo(),
                'share_video' => $client->shareVideo(),
                'give_coin' => $client->giveCoin((int)($settings['coin_count'] ?? 1)),
                'live_sign' => $client->liveSign(),
                'live_daily_bag' => $client->liveDailyBag(),
                'live_heartbeat' => $client->liveHeartbeat((string)($settings['live_room_id'] ?? '')),
                'capsule_open' => $client->openCapsule((int)($settings['capsule_count'] ?? 1)),
                'comic_sign' => $client->comicSign(),
                default => throw new \InvalidArgumentException('unsupported bilibili action'),
            };
            return $this->record($result);
        } catch (\Throwable $exception) {
            return new SignRecord(
                key: 'action:' . $action,
                action: $action,
                status: 'failed',
                code: $exception instanceof \app\exception\ApiException
                    ? $exception->errorCode
                    : 'BILIBILI_ACTION_FAILED',
                message: $exception->getMessage(),
            );
        }
    }

    private function record(array $result): SignRecord
    {
        return new SignRecord(
            key: (string)$result['key'],
            action: (string)$result['action'],
            status: (string)$result['status'],
            targetType: $result['target_type'],
            targetId: $result['target_id'],
            targetName: $result['target_name'],
            code: (string)$result['code'],
            message: (string)$result['message'],
            rewards: $result['rewards'],
            metrics: $result['metrics'],
        );
    }
}
