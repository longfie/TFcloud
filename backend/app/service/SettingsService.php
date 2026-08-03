<?php

namespace app\service;

use app\exception\ApiException;
use app\sign\credential\CredentialVault;
use support\Db;

final class SettingsService
{
    private const DEFINITIONS = [
        'site' => [
            'name' => ['default' => '天方云签', 'type' => 'string', 'max' => 80],
            'title' => ['default' => '天方云签', 'type' => 'string', 'max' => 120],
            'description' => ['default' => '轻量化多平台自动签到中心', 'type' => 'string', 'max' => 500],
            'base_url' => ['default' => '', 'type' => 'url'],
            'logo_url' => ['default' => '', 'type' => 'url_optional'],
            'home_background_url' => ['default' => '', 'type' => 'url_optional'],
            'home_background_type' => ['default' => 'auto', 'type' => 'enum', 'options' => ['auto', 'image', 'video']],
            'contact_email' => ['default' => '', 'type' => 'email_optional'],
            'icp_number' => ['default' => '', 'type' => 'string', 'max' => 80],
            'announcement' => ['default' => '', 'type' => 'string', 'max' => 2000],
            'home_announcement' => ['default' => '', 'type' => 'string', 'max' => 2000],
            'dashboard_announcement' => ['default' => '', 'type' => 'string', 'max' => 2000],
            'maintenance_mode' => ['default' => false, 'type' => 'bool'],
            'registration_enabled' => ['default' => false, 'type' => 'bool'],
            'login_challenge_enabled' => ['default' => true, 'type' => 'bool'],
            'registration_challenge_enabled' => ['default' => true, 'type' => 'bool'],
            'registration_email_verification' => ['default' => true, 'type' => 'bool'],
            'default_quota' => ['default' => 2, 'type' => 'int', 'min' => 0, 'max' => 100000],
            'qq_login_enabled' => ['default' => false, 'type' => 'bool'],
            'qq_auto_register' => ['default' => true, 'type' => 'bool'],
            'qq_login_provider' => ['default' => 'relay', 'type' => 'enum', 'options' => ['relay', 'official']],
            'qq_app_id' => ['default' => '', 'type' => 'string', 'max' => 64],
            'qq_app_key' => ['default' => '', 'type' => 'string', 'secret' => true],
            'qq_callback_url' => ['default' => '', 'type' => 'url_optional'],
            'qq_relay_authorize_url' => ['default' => '', 'type' => 'url_optional'],
            'qq_relay_decode_key' => ['default' => '', 'type' => 'string', 'secret' => true],
            'qq_relay_app_secret' => ['default' => '', 'type' => 'string', 'secret' => true],
            'qq_relay_client_id' => ['default' => '', 'type' => 'string', 'max' => 191],
            'qq_relay_client_secret' => ['default' => '', 'type' => 'string', 'secret' => true],
            'qq_relay_login_url' => ['default' => '', 'type' => 'url_optional'],
            'qq_relay_application_status' => [
                'default' => 'unconfigured',
                'type' => 'enum',
                'options' => ['unconfigured', 'pending', 'active'],
            ],
            'qq_relay_installation_id' => ['default' => '', 'type' => 'string', 'max' => 64],
            'qq_relay_verification_content' => ['default' => '', 'type' => 'string', 'secret' => true],
            'qq_relay_verification_expires_at' => ['default' => '', 'type' => 'string', 'max' => 64],
        ],
        'mail' => [
            'enabled' => ['default' => false, 'type' => 'bool'],
            'smtp_host' => ['default' => '', 'type' => 'string', 'max' => 191],
            'smtp_port' => ['default' => 465, 'type' => 'int', 'min' => 1, 'max' => 65535],
            'smtp_encryption' => ['default' => 'ssl', 'type' => 'enum', 'options' => ['none', 'tls', 'ssl']],
            'smtp_username' => ['default' => '', 'type' => 'string', 'max' => 191],
            'smtp_password' => ['default' => '', 'type' => 'string', 'secret' => true],
            'from_email' => ['default' => '', 'type' => 'email_optional'],
            'from_name' => ['default' => '天方云签', 'type' => 'string', 'max' => 191],
            'reply_to' => ['default' => '', 'type' => 'email_optional'],
            'batch_limit' => ['default' => 100, 'type' => 'int', 'min' => 1, 'max' => 1000],
            'daily_summary_hour' => ['default' => 23, 'type' => 'int', 'min' => 0, 'max' => 23],
        ],
        'astrbot' => [
            'enabled' => ['default' => false, 'type' => 'bool'],
            'base_url' => ['default' => '', 'type' => 'url_optional'],
            'api_key' => ['default' => '', 'type' => 'string', 'secret' => true],
            'config_id' => ['default' => '', 'type' => 'string', 'max' => 128],
            'bot_name' => ['default' => '天方助手', 'type' => 'string', 'max' => 80],
            'welcome_message' => ['default' => '你好，我是天方助手。有什么可以帮你？', 'type' => 'string', 'max' => 500],
            'request_timeout_seconds' => ['default' => 90, 'type' => 'int', 'min' => 10, 'max' => 180],
            'max_message_length' => ['default' => 4000, 'type' => 'int', 'min' => 100, 'max' => 10000],
            'hourly_message_limit' => ['default' => 30, 'type' => 'int', 'min' => 1, 'max' => 1000],
        ],
    ];

    public function all(): array
    {
        $result = [];
        foreach (array_keys(self::DEFINITIONS) as $group) {
            $result[$group] = $this->group($group);
        }
        return $result;
    }

    public function group(string $group, bool $includeSecrets = false): array
    {
        $definitions = self::DEFINITIONS[$group] ?? null;
        if (!$definitions) {
            throw new ApiException('SETTINGS_GROUP_NOT_FOUND', '配置分组不存在', 404);
        }
        $rows = Db::table('TF_settings')->where('setting_key', 'like', $group . '.%')->get()->keyBy('setting_key');
        $result = [];
        foreach ($definitions as $name => $definition) {
            $key = $group . '.' . $name;
            $row = $rows->get($key);
            if (!empty($definition['secret'])) {
                $configured = $row !== null;
                if ($includeSecrets && $configured) {
                    $result[$name] = $this->decodeSecret((string)$row->setting_value, $key);
                } else {
                    $result[$name] = '';
                }
                $result[$name . '_configured'] = $configured;
                continue;
            }
            $result[$name] = $row ? json_decode((string)$row->setting_value, true) : $definition['default'];
        }
        return $result;
    }

    public function update(string $group, array $input, int $userId): array
    {
        $definitions = self::DEFINITIONS[$group] ?? null;
        if (!$definitions) {
            throw new ApiException('SETTINGS_GROUP_NOT_FOUND', '配置分组不存在', 404);
        }
        $now = date('Y-m-d H:i:s');
        foreach ($definitions as $name => $definition) {
            if (!array_key_exists($name, $input)) {
                continue;
            }
            $value = $input[$name];
            if (!empty($definition['secret']) && trim((string)$value) === '') {
                continue;
            }
            $value = $this->validate($name, $value, $definition);
            $key = $group . '.' . $name;
            $encoded = !empty($definition['secret'])
                ? $this->encodeSecret((string)$value, $key)
                : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            Db::table('TF_settings')->updateOrInsert(['setting_key' => $key], [
                'setting_value' => $encoded,
                'is_secret' => !empty($definition['secret']) ? 1 : 0,
                'updated_by' => $userId,
                'updated_at' => $now,
            ]);
        }
        return $this->group($group);
    }

    public function publicSite(): array
    {
        $site = $this->group('site');
        $astrbot = $this->group('astrbot');
        $legacyAnnouncement = trim((string)($site['announcement'] ?? ''));
        $site['home_announcement'] = trim((string)($site['home_announcement'] ?? '')) ?: $legacyAnnouncement;
        $site['dashboard_announcement'] = trim((string)($site['dashboard_announcement'] ?? '')) ?: $legacyAnnouncement;
        // Keep older frontend builds functional during rolling deployments.
        $site['announcement'] = $site['dashboard_announcement'];
        $startedAt = Db::table('TF_users')->min('created_at');
        $startedTimestamp = $startedAt ? strtotime((string)$startedAt) : false;
        $site['running_since'] = $startedTimestamp
            ? date('Y-m-d', $startedTimestamp)
            : date('Y-m-d');
        $site['running_days'] = $startedTimestamp
            ? max(1, (int)floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', $startedTimestamp))) / 86400) + 1)
            : 1;
        $relayApplicationReady = ($site['qq_relay_application_status'] ?? '') === 'active'
            && trim((string)($site['qq_relay_client_id'] ?? '')) !== ''
            && trim((string)($site['qq_relay_login_url'] ?? '')) !== ''
            && !empty($site['qq_relay_client_secret_configured']);
        $relayLegacyReady = trim((string)$site['qq_relay_authorize_url']) !== ''
            && !empty($site['qq_relay_decode_key_configured'])
            && !empty($site['qq_relay_app_secret_configured']);
        $providerReady = $site['qq_login_provider'] === 'relay'
            ? $relayApplicationReady || $relayLegacyReady
            : trim((string)$site['qq_app_id']) !== '' && !empty($site['qq_app_key_configured']);
        $site['qq_login_enabled'] = (bool)$site['qq_login_enabled']
            && trim((string)$site['qq_callback_url']) !== ''
            && $providerReady;
        $site['assistant_enabled'] = (bool)$astrbot['enabled']
            && trim((string)$astrbot['base_url']) !== ''
            && !empty($astrbot['api_key_configured']);
        $site['assistant_name'] = trim((string)$astrbot['bot_name']) ?: '天方助手';
        $site['registration_email_verification'] = (bool)$site['registration_email_verification']
            && !empty($this->group('mail')['enabled']);
        return array_intersect_key($site, array_flip([
            'name', 'title', 'description', 'base_url', 'logo_url',
            'home_background_url', 'home_background_type', 'contact_email',
            'icp_number', 'announcement', 'home_announcement', 'dashboard_announcement',
            'maintenance_mode', 'registration_enabled',
            'login_challenge_enabled', 'registration_challenge_enabled',
            'registration_email_verification',
            'qq_login_enabled', 'qq_auto_register', 'running_since', 'running_days',
            'assistant_enabled', 'assistant_name',
        ]));
    }

    private function validate(string $name, mixed $value, array $definition): mixed
    {
        return match ($definition['type']) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? throw new ApiException('VALIDATION_FAILED', "{$name} 必须是布尔值", 422),
            'int' => $this->validateInt($name, $value, $definition),
            'enum' => in_array($value, $definition['options'], true) ? $value : throw new ApiException('VALIDATION_FAILED', "{$name} 选项无效", 422),
            'url' => filter_var($value, FILTER_VALIDATE_URL) ? (string)$value : throw new ApiException('VALIDATION_FAILED', "{$name} 必须是有效网址", 422),
            'url_optional' => trim((string)$value) === '' || filter_var($value, FILTER_VALIDATE_URL) ? trim((string)$value) : throw new ApiException('VALIDATION_FAILED', "{$name} 必须是有效网址", 422),
            'email_optional' => trim((string)$value) === '' || filter_var($value, FILTER_VALIDATE_EMAIL) ? trim((string)$value) : throw new ApiException('VALIDATION_FAILED', "{$name} 必须是有效邮箱", 422),
            default => $this->validateString($name, $value, $definition),
        };
    }

    private function validateInt(string $name, mixed $value, array $definition): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false || $number < $definition['min'] || $number > $definition['max']) {
            throw new ApiException('VALIDATION_FAILED', "{$name} 数值无效", 422);
        }
        return $number;
    }

    private function validateString(string $name, mixed $value, array $definition): string
    {
        $value = trim((string)$value);
        if (isset($definition['max']) && mb_strlen($value) > $definition['max']) {
            throw new ApiException('VALIDATION_FAILED', "{$name} 内容过长", 422);
        }
        return $value;
    }

    private function encodeSecret(string $value, string $key): string
    {
        $encrypted = (new CredentialVault())->encrypt(['value' => $value], 'setting:' . $key);
        return json_encode([
            'cipher' => base64_encode($encrypted['payload_cipher']),
            'nonce' => base64_encode($encrypted['nonce']),
            'key_version' => $encrypted['key_version'],
        ], JSON_THROW_ON_ERROR);
    }

    private function decodeSecret(string $encoded, string $key): string
    {
        $payload = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        $decoded = (new CredentialVault())->decrypt(
            (string)base64_decode((string)($payload['cipher'] ?? ''), true),
            (string)base64_decode((string)($payload['nonce'] ?? ''), true),
            'setting:' . $key
        );
        return (string)($decoded['value'] ?? '');
    }
}
