<?php

namespace plugin\cloud189\app;

use app\sign\contract\AbstractSignPlugin;
use app\sign\contract\CredentialRefreshAwareInterface;
use app\sign\dto\AccountProfile;
use app\sign\dto\HealthResult;
use app\sign\dto\PluginMetadata;
use app\sign\dto\SignContext;
use app\sign\dto\SignRecord;
use app\sign\dto\SignResult;

final class Cloud189Plugin extends AbstractSignPlugin implements CredentialRefreshAwareInterface
{
    private ?array $cachedSession = null;
    private ?string $cachedCredentialKey = null;
    private ?array $refreshedCredentials = null;

    public function metadata(): PluginMetadata
    {
        return new PluginMetadata(
            'cloud189',
            '天翼云盘',
            '0.1.0',
            '天翼云盘账号登录与每日签到插件',
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
        $client = new Cloud189Client($credentials);
        $session = $client->authenticate();
        $this->cachedCredentialKey = $this->credentialKey($credentials);
        $this->cachedSession = $session;
        $this->refreshedCredentials = $client->refreshedCredentials();

        return new AccountProfile(
            $client->accountIdentifier(),
            $client->maskedLoginName($session),
            ['provider' => '天翼云盘', 'login_verified_by' => 'session']
        );
    }

    public function supportedActions(): array
    {
        return ['daily_sign'];
    }

    public function execute(SignContext $context): SignResult
    {
        $this->requireCredentials($context->credentials, ['username', 'password']);
        $client = new Cloud189Client($context->credentials);
        $session = $this->cachedCredentialKey === $this->credentialKey($context->credentials)
            ? $this->cachedSession
            : null;
        try {
            $record = $this->record($client->dailySign($session));
            return new SignResult(
                in_array($record->status, ['succeeded', 'already_done'], true) ? 'succeeded' : 'failed',
                $record->message ?? '天翼云盘签到任务完成',
                [$record],
                ['storage_mb' => (int)($record->rewards['storage_mb'] ?? 0)]
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
        if (!extension_loaded('curl')) {
            return new HealthResult(false, 'curl extension is missing');
        }
        if (!extension_loaded('openssl')) {
            return new HealthResult(false, 'openssl extension is missing');
        }
        return new HealthResult(true);
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
