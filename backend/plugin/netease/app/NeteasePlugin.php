<?php

namespace plugin\netease\app;

use app\sign\contract\AbstractSignPlugin;
use app\sign\dto\AccountProfile;
use app\sign\dto\HealthResult;
use app\sign\dto\PluginMetadata;
use app\sign\dto\SignRecord;
use app\sign\dto\SignContext;
use app\sign\dto\SignResult;

final class NeteasePlugin extends AbstractSignPlugin
{
    public function metadata(): PluginMetadata
    {
        return new PluginMetadata('netease', '网易云音乐', '0.2.0', '网易云音乐每日签到插件');
    }

    public function credentialRules(): array
    {
        return [
            'music_u' => ['required', 'string'],
            'csrf' => ['required', 'string'],
        ];
    }

    public function validateAccount(array $credentials): AccountProfile
    {
        $this->requireCredentials($credentials, ['music_u', 'csrf']);
        return (new NeteaseClient($credentials))->profile();
    }

    public function supportedActions(): array
    {
        return ['daily_sign'];
    }

    public function execute(SignContext $context): SignResult
    {
        if ($context->action !== 'daily_sign') {
            throw new \InvalidArgumentException('unsupported netease action');
        }
        $this->requireCredentials($context->credentials, ['music_u', 'csrf']);
        $result = (new NeteaseClient($context->credentials))->dailySign();
        $record = new SignRecord(
            key: 'account:daily_sign',
            action: 'daily_sign',
            status: $result['status'],
            targetType: 'account',
            code: $result['code'],
            message: $result['message'],
            rewards: ['points' => $result['points']],
        );
        return new SignResult(
            $result['status'] === 'failed' ? 'failed' : 'succeeded',
            $result['message'],
            [$record],
            ['points' => $result['points']]
        );
    }

    public function healthCheck(): HealthResult
    {
        $healthy = extension_loaded('curl') && extension_loaded('openssl');
        return new HealthResult($healthy, $healthy ? 'ok' : 'curl or openssl extension is missing');
    }
}
