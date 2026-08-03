<?php

namespace plugin\iqiyi\app;

use app\sign\contract\AbstractSignPlugin;
use app\sign\dto\AccountProfile;
use app\sign\dto\HealthResult;
use app\sign\dto\PluginMetadata;
use app\sign\dto\SignRecord;
use app\sign\dto\SignContext;
use app\sign\dto\SignResult;

final class IqiyiPlugin extends AbstractSignPlugin
{
    public function metadata(): PluginMetadata
    {
        return new PluginMetadata('iqiyi', '爱奇艺', '0.2.0', '爱奇艺会员与积分签到插件');
    }

    public function credentialRules(): array
    {
        return [
            'p00001' => ['required', 'string'],
            'p00003' => ['required', 'string'],
        ];
    }

    public function validateAccount(array $credentials): AccountProfile
    {
        $this->requireCredentials($credentials, ['p00001', 'p00003']);
        return (new IqiyiClient($credentials))->profile();
    }

    public function supportedActions(): array
    {
        return ['vip_sign', 'score_sign', 'daily_tasks'];
    }

    public function execute(SignContext $context): SignResult
    {
        $this->requireCredentials($context->credentials, ['p00001', 'p00003']);
        $client = new IqiyiClient($context->credentials);
        $actions = $context->action === 'daily_tasks'
            ? ['vip_sign', 'score_sign']
            : [$context->action];
        $records = [];
        foreach ($actions as $action) {
            try {
                $result = match ($action) {
                    'vip_sign' => $client->vipSign(),
                    'score_sign' => $client->scoreSign(),
                    default => throw new \InvalidArgumentException('unsupported iqiyi action'),
                };
                $records[] = new SignRecord(
                    key: 'account:' . $action,
                    action: $action,
                    status: $result['status'],
                    targetType: 'account',
                    targetId: (string)$context->accountId,
                    targetName: '爱奇艺账号',
                    code: $result['code'],
                    message: $result['message'],
                    rewards: ['points' => $result['points']],
                    metrics: array_filter([
                        'sign_days' => $result['sign_days'] ?? null,
                        'continuous_days' => $result['continuous_days'] ?? null,
                    ], static fn ($value): bool => $value !== null),
                );
            } catch (\Throwable $exception) {
                $records[] = new SignRecord(
                    key: 'account:' . $action,
                    action: $action,
                    status: 'failed',
                    code: $exception instanceof \app\exception\ApiException
                        ? $exception->errorCode
                        : 'IQIYI_ACTION_FAILED',
                    message: $exception->getMessage(),
                );
            }
        }
        $failed = count(array_filter($records, static fn (SignRecord $record): bool => $record->status === 'failed'));
        return new SignResult(
            $failed === 0 ? 'succeeded' : ($failed < count($records) ? 'partial' : 'failed'),
            $failed === 0 ? '爱奇艺签到完成' : '部分爱奇艺签到失败',
            $records,
            ['total' => count($records), 'failed' => $failed]
        );
    }

    public function healthCheck(): HealthResult
    {
        return extension_loaded('curl')
            ? new HealthResult(true)
            : new HealthResult(false, 'curl extension is missing');
    }
}
