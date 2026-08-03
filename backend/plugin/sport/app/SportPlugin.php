<?php

namespace plugin\sport\app;

use app\sign\contract\AbstractSignPlugin;
use app\sign\contract\CredentialRefreshAwareInterface;
use app\sign\dto\AccountProfile;
use app\sign\dto\HealthResult;
use app\sign\dto\PluginMetadata;
use app\sign\dto\SignContext;
use app\sign\dto\SignRecord;
use app\sign\dto\SignResult;

final class SportPlugin extends AbstractSignPlugin implements CredentialRefreshAwareInterface
{
    private ?array $cachedSession = null;
    private ?string $cachedCredentialKey = null;
    private ?array $refreshedCredentials = null;

    public function metadata(): PluginMetadata
    {
        return new PluginMetadata(
            'sport',
            '小米运动',
            '0.2.0',
            'Zepp Life（原小米运动）步数更新插件',
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
        $client = new SportClient($credentials);
        $session = $client->authenticate();
        $this->cachedCredentialKey = $this->credentialKey($credentials);
        $this->cachedSession = $session;
        $this->refreshedCredentials = $client->refreshedCredentials();

        return new AccountProfile(
            (string)$session['user_id'],
            trim((string)($session['nickname'] ?? '')) ?: '小米运动账号',
            ['provider' => 'Zepp Life', 'login_verified_by' => 'app_token']
        );
    }

    public function supportedActions(): array
    {
        return ['update_steps'];
    }

    public function execute(SignContext $context): SignResult
    {
        $this->requireCredentials($context->credentials, ['username', 'password']);
        $client = new SportClient($context->credentials);
        $session = $this->cachedCredentialKey === $this->credentialKey($context->credentials)
            ? $this->cachedSession
            : null;
        try {
            $steps = $this->stepsFor($context);
            $record = $this->record($client->updateSteps($steps, $session));
            return new SignResult(
                $record->status === 'succeeded' ? 'succeeded' : 'failed',
                $record->message ?? '小米运动步数任务完成',
                [$record],
                ['steps' => $steps]
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
        if (!extension_loaded('openssl') || !function_exists('gzuncompress')) {
            return new HealthResult(false, 'openssl or zlib extension is missing');
        }
        return new HealthResult(true);
    }

    private function stepsFor(SignContext $context): int
    {
        $mode = (string)($context->settings['step_mode'] ?? 'fixed');
        if ($mode !== 'random') {
            return max(1, min(98800, (int)($context->settings['steps'] ?? 18000)));
        }

        $minimum = max(1, min(98800, (int)($context->settings['min_steps'] ?? 18000)));
        $maximum = max($minimum, min(98800, (int)($context->settings['max_steps'] ?? 25000)));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai'));
        if (($context->settings['progressive_by_time'] ?? false) === true) {
            $minutes = ((int)$now->format('G') * 60) + (int)$now->format('i');
            $rate = min(1, $minutes / (22 * 60));
            $minimum = max(1, (int)floor($minimum * $rate));
            $maximum = max($minimum, (int)floor($maximum * $rate));
        }

        $range = $maximum - $minimum + 1;
        $seed = hash('sha256', $now->format('Y-m-d') . ':' . $context->accountId . ':sport');
        $offset = (int)(hexdec(substr($seed, 0, 8)) % $range);
        return $minimum + $offset;
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
