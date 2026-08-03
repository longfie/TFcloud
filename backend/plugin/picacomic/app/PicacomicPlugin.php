<?php

namespace plugin\picacomic\app;

use app\sign\contract\AbstractSignPlugin;
use app\sign\contract\CredentialRefreshAwareInterface;
use app\sign\dto\AccountProfile;
use app\sign\dto\HealthResult;
use app\sign\dto\PluginMetadata;
use app\sign\dto\SignContext;
use app\sign\dto\SignRecord;
use app\sign\dto\SignResult;

final class PicacomicPlugin extends AbstractSignPlugin implements CredentialRefreshAwareInterface
{
    private ?array $cachedSession = null;
    private ?string $cachedCredentialKey = null;
    private ?array $refreshedCredentials = null;

    public function metadata(): PluginMetadata
    {
        return new PluginMetadata(
            'picacomic',
            '哔咔漫画',
            '0.1.0',
            '哔咔漫画账号登录与每日签到插件',
            ['account_password'],
            'ready'
        );
    }

    public function credentialRules(): array
    {
        return [
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    public function validateAccount(array $credentials): AccountProfile
    {
        $this->requireCredentials($credentials, ['username', 'password']);
        $client = new PicacomicClient($credentials);
        $session = $client->authenticate();
        $this->cachedCredentialKey = $this->credentialKey($credentials);
        $this->cachedSession = $session;
        $this->refreshedCredentials = $client->refreshedCredentials();

        return new AccountProfile(
            $client->accountIdentifier($session),
            $client->displayName($session),
            [
                'provider' => '哔咔漫画',
                'level' => max(0, (int)($session['profile']['level'] ?? 0)),
                'login_verified_by' => 'token',
            ]
        );
    }

    public function supportedActions(): array
    {
        return ['daily_sign'];
    }

    public function execute(SignContext $context): SignResult
    {
        $this->requireCredentials($context->credentials, ['username', 'password']);
        $client = new PicacomicClient($context->credentials);
        $session = $this->cachedCredentialKey === $this->credentialKey($context->credentials)
            ? $this->cachedSession
            : null;
        try {
            $record = $this->record($client->dailySign($session));
            return new SignResult(
                in_array($record->status, ['succeeded', 'already_done'], true) ? 'succeeded' : 'failed',
                $record->message ?? '哔咔签到任务完成',
                [$record],
                ['experience' => (int)($record->rewards['experience'] ?? 0)]
            );
        } finally {
            $this->cachedSession = null;
            $this->cachedCredentialKey = null;
        }
    }

    public function refreshedCredentials(): ?array
    {
        return $this->refreshedCredentials;
    }

    public function healthCheck(): HealthResult
    {
        return extension_loaded('curl')
            ? new HealthResult(true)
            : new HealthResult(false, 'curl extension is missing');
    }

    private function credentialKey(array $credentials): string
    {
        return hash('sha256', json_encode($credentials, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
