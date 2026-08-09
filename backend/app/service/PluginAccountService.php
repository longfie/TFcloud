<?php

namespace app\service;

use app\exception\ApiException;
use app\sign\contract\CredentialRefreshAwareInterface;
use app\sign\contract\SignPluginInterface;
use app\sign\credential\CredentialVault;
use app\sign\dto\AccountProfile;
use app\sign\registry\PluginRegistry;
use app\sign\schedule\DailySchedule;
use support\Db;

final class PluginAccountService
{
    public function list(int $userId): array
    {
        return Db::table('TF_plugin_accounts as a')
            ->leftJoin('TF_plugin_credentials as c', 'c.account_id', '=', 'a.id')
            ->where('a.user_id', $userId)
            ->whereNull('a.deleted_at')
            ->orderByDesc('a.id')
            ->select([
                'a.id', 'a.plugin_code', 'a.external_user_id', 'a.display_name',
                'a.status', 'a.settings_json', 'a.profile_json', 'a.last_verified_at', 'a.last_run_at',
                'a.next_run_at', 'a.last_error_code', 'a.last_error_message',
                'a.created_at', 'a.updated_at', 'c.id as credential_id',
            ])
            ->get()
            ->map(fn ($row) => $this->present($row))
            ->all();
    }

    public function get(int $userId, int $accountId): array
    {
        return $this->present($this->findOwned($userId, $accountId));
    }

    public function create(int $userId, array $input): array
    {
        $user = Db::table('TF_users')->where('id', $userId)->first();
        if (!$user) {
            throw new ApiException('USER_NOT_FOUND', '用户不存在', 404);
        }
        $pluginCode = trim((string)($input['plugin_code'] ?? ''));
        $credentials = $input['credentials'] ?? null;
        if ($pluginCode === '' || !is_array($credentials) || $credentials === []) {
            throw new ApiException('VALIDATION_FAILED', 'plugin_code 和 credentials 不能为空', 422);
        }

        $plugin = (new PluginRegistry())->get($pluginCode);
        $this->assertCredentialSize($credentials);
        $this->validateCredentialShape($plugin, $credentials);
        $profile = $this->initialProfile($pluginCode, $credentials, $plugin);
        if (array_key_exists('settings', $input) && !is_array($input['settings'])) {
            throw new ApiException('VALIDATION_FAILED', 'settings 必须是对象', 422);
        }
        $settings = is_array($input['settings'] ?? null) ? $input['settings'] : [];
        $this->validateSettings($plugin, $settings, $pluginCode . ':' . $userId . ':' . $profile->externalUserId);

        // 同一平台账号重新登录时视为“更新凭据”：不新建、不占新配额，保留原有签到计划。
        if ($profile->externalUserId !== '') {
            $existing = Db::table('TF_plugin_accounts')
                ->where('user_id', $userId)
                ->where('plugin_code', $pluginCode)
                ->where('external_user_id', $profile->externalUserId)
                ->whereNull('deleted_at')
                ->first();
            if ($existing) {
                (new AccountQuotaService())->assertCanActivate($userId, (int)$existing->id);
                $this->replaceCredentials($existing, $credentials);
                return $this->get($userId, (int)$existing->id);
            }
        }

        $accountCount = Db::table('TF_plugin_accounts')->where('user_id', $userId)->whereNull('deleted_at')->count();
        if ($accountCount >= (int)$user->quota) {
            throw new ApiException('PLUGIN_ACCOUNT_QUOTA_EXCEEDED', '平台账号数量已达到配额上限', 409);
        }
        $credentialType = $this->credentialType($plugin->metadata()->credentialTypes);
        $vault = new CredentialVault();
        $fingerprint = $this->credentialFingerprint($vault, $pluginCode, $credentials);
        $duplicate = Db::table('TF_plugin_credentials as c')
            ->join('TF_plugin_accounts as a', 'a.id', '=', 'c.account_id')
            ->where('a.plugin_code', $pluginCode)
            ->where('c.fingerprint', $fingerprint)
            ->whereNull('a.deleted_at')
            ->exists();
        if ($duplicate) {
            throw new ApiException('PLUGIN_CREDENTIAL_DUPLICATE', '该平台凭据已经绑定', 409);
        }

        $now = date('Y-m-d H:i:s');
        $accountId = Db::transaction(function () use (
            $userId, $pluginCode, $credentials, $profile, $settings, $vault, $now, $credentialType, $plugin,
            $fingerprint
        ): int {
            $displayName = trim($profile->displayName);
            $accountId = (int)Db::table('TF_plugin_accounts')->insertGetId([
                'user_id' => $userId,
                'plugin_code' => $pluginCode,
                'external_user_id' => $profile->externalUserId !== '' ? $profile->externalUserId : null,
                'display_name' => $displayName !== '' ? $displayName : $plugin->metadata()->name . '账号',
                'status' => 'active',
                'settings_json' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'profile_json' => json_encode($profile->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'last_verified_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($plugin->metadata()->implementationStatus === 'ready') {
                Db::table('TF_plugin_accounts')->where('id', $accountId)->update([
                    'next_run_at' => DailySchedule::next($settings, $pluginCode . ':' . $userId . ':' . $accountId),
                ]);
            }

            $encrypted = $vault->encrypt(
                $credentials,
                CredentialVault::associatedData($pluginCode, $accountId, $credentialType)
            );
            $encrypted['fingerprint'] = $fingerprint;
            Db::table('TF_plugin_credentials')->insert($encrypted + [
                'account_id' => $accountId,
                'credential_type' => $credentialType,
                'verified_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return $accountId;
        });

        return $this->get($userId, $accountId);
    }

    public function update(int $userId, int $accountId, array $input): array
    {
        $account = $this->findOwned($userId, $accountId);
        if (array_key_exists('credentials', $input) && !is_array($input['credentials'])) {
            throw new ApiException('VALIDATION_FAILED', 'credentials 必须是对象', 422);
        }
        $updates = ['updated_at' => date('Y-m-d H:i:s')];
        if (array_key_exists('display_name', $input)) {
            $updates['display_name'] = mb_substr(trim((string)$input['display_name']), 0, 191);
        }
        if (array_key_exists('settings', $input) && !is_array($input['settings'])) {
            throw new ApiException('VALIDATION_FAILED', 'settings 必须是对象', 422);
        }
        if (array_key_exists('settings', $input)) {
            $plugin = (new PluginRegistry())->get((string)$account->plugin_code);
            $this->validateSettings($plugin, $input['settings'], $account->plugin_code . ':' . $userId . ':' . $accountId);
            $updates['settings_json'] = json_encode($input['settings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (array_key_exists('status', $input) && in_array($input['status'], ['active', 'disabled'], true)) {
            $credentialExpired = $account->status === 'credential_expired'
                || in_array($account->last_error_code, ['PLUGIN_CREDENTIAL_EXPIRED', 'PLUGIN_CREDENTIAL_INVALID'], true);
            if ($input['status'] === 'active' && $credentialExpired) {
                if (!array_key_exists('credentials', $input)) {
                    throw new ApiException(
                        'PLUGIN_CREDENTIAL_EXPIRED',
                        '平台登录状态已经失效，请更新登录凭据后再启用',
                        409
                    );
                }
                // Credential replacement re-enters scheduling. The new value
                // is checked automatically immediately before execution.
            } else {
                if ($input['status'] === 'active') (new AccountQuotaService())->assertCanActivate($userId, $accountId);
                $updates['status'] = $input['status'];
            }
        }
        Db::table('TF_plugin_accounts')->where('id', $account->id)->update($updates);

        if (array_key_exists('credentials', $input)) {
            (new AccountQuotaService())->assertCanActivate($userId, $accountId);
            $this->replaceCredentials($this->findOwned($userId, $accountId), $input['credentials']);
        }

        if (isset($updates['status']) && array_key_exists('credentials', $input)) {
            Db::table('TF_plugin_accounts')->where('id', $accountId)->update(['status' => $updates['status']]);
        }

        if (array_key_exists('settings', $input)
            || array_key_exists('status', $input)
            || array_key_exists('credentials', $input)) {
            $current = $this->findOwned($userId, $accountId);
            $settings = DailySchedule::normalizeLegacy(
                json_decode((string)($current->settings_json ?? '{}'), true) ?: [],
                $current->next_run_at !== null ? (string)$current->next_run_at : null
            );
            $plugin = (new PluginRegistry())->get((string)$current->plugin_code);
            $nextRunAt = in_array($current->status, ['active', 'pending_verification'], true)
                && $plugin->metadata()->implementationStatus === 'ready'
                    ? DailySchedule::next($settings, $current->plugin_code . ':' . $userId . ':' . $accountId)
                    : null;
            Db::table('TF_plugin_accounts')->where('id', $accountId)->update([
                'next_run_at' => $nextRunAt,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        return $this->get($userId, $accountId);
    }

    public function validateBeforeExecution(object $account, array $payload): void
    {
        $plugin = (new PluginRegistry())->get($account->plugin_code);
        if ($plugin->metadata()->implementationStatus !== 'ready') {
            throw new ApiException('PLUGIN_VERIFICATION_NOT_READY', '该插件的在线校验仍在迁移中', 409);
        }
        try {
            $profile = $plugin->validateAccount($payload);
        } catch (ApiException $exception) {
            if ($this->isCredentialFailure($exception)) {
                $now = date('Y-m-d H:i:s');
                Db::table('TF_plugin_accounts')->where('id', $account->id)->update([
                    'status' => 'credential_expired',
                    'next_run_at' => null,
                    'last_error_code' => $exception->errorCode,
                    'last_error_message' => mb_substr($exception->getMessage(), 0, 500),
                    'updated_at' => $now,
                ]);
                (new NotificationMailService())->credentialExpired(
                    (int)$account->user_id,
                    (int)$account->id,
                    $plugin->metadata()->name,
                    (string)$account->display_name,
                    $exception->getMessage()
                );
            }
            throw $exception;
        }
        if ($plugin instanceof CredentialRefreshAwareInterface) {
            $refreshed = $plugin->refreshedCredentials();
            if (is_array($refreshed) && $refreshed !== []) {
                $this->persistRefreshedCredentials($account, $refreshed);
            }
        }
        $now = date('Y-m-d H:i:s');
        $displayName = trim($profile->displayName);
        Db::table('TF_plugin_accounts')->where('id', $account->id)->update([
            'external_user_id' => $profile->externalUserId !== '' ? $profile->externalUserId : $account->external_user_id,
            'display_name' => $displayName !== '' ? $displayName : $account->display_name,
            'profile_json' => $this->mergedProfileJson($account, $profile->metadata),
            'status' => 'active',
            'last_verified_at' => $now,
            'last_error_code' => null,
            'last_error_message' => null,
            'updated_at' => $now,
        ]);
        Db::table('TF_plugin_credentials')->where('account_id', $account->id)->update([
            'verified_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function delete(int $userId, int $accountId): void
    {
        $account = $this->findOwned($userId, $accountId);
        Db::transaction(function () use ($account): void {
            Db::table('TF_plugin_credentials')->where('account_id', $account->id)->delete();
            Db::table('TF_plugin_accounts')->where('id', $account->id)->update([
                'status' => 'deleted',
                'external_user_id' => null,
                'deleted_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        });
    }

    public function decryptedCredential(object $account): array
    {
        $credential = $this->credential((int)$account->id);
        return (new CredentialVault())->decrypt(
            $credential->payload_cipher,
            $credential->nonce,
            CredentialVault::associatedData($account->plugin_code, (int)$account->id, $credential->credential_type)
        );
    }

    public function findOwned(int $userId, int $accountId): object
    {
        $account = Db::table('TF_plugin_accounts')
            ->where('id', $accountId)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->first();
        if (!$account) {
            throw new ApiException('PLUGIN_ACCOUNT_NOT_FOUND', '插件账号不存在', 404);
        }
        return $account;
    }

    private function replaceCredentials(object $account, array $credentials): void
    {
        $plugin = (new PluginRegistry())->get($account->plugin_code);
        $this->assertCredentialSize($credentials);
        $this->validateCredentialShape($plugin, $credentials);
        $profile = $this->initialProfile((string)$account->plugin_code, $credentials, $plugin);
        $credentialType = $this->credentialType($plugin->metadata()->credentialTypes);
        $vault = new CredentialVault();
        $fingerprint = $this->credentialFingerprint($vault, (string)$account->plugin_code, $credentials);
        $duplicate = Db::table('TF_plugin_credentials as c')
            ->join('TF_plugin_accounts as a', 'a.id', '=', 'c.account_id')
            ->where('a.plugin_code', $account->plugin_code)
            ->where('c.fingerprint', $fingerprint)
            ->where('a.id', '<>', $account->id)
            ->whereNull('a.deleted_at')
            ->exists();
        if ($duplicate) {
            throw new ApiException('PLUGIN_CREDENTIAL_DUPLICATE', '该平台凭据已经绑定', 409);
        }
        $encrypted = $vault->encrypt(
            $credentials,
            CredentialVault::associatedData($account->plugin_code, (int)$account->id, $credentialType)
        );
        $encrypted['fingerprint'] = $fingerprint;
        $now = date('Y-m-d H:i:s');
        $settings = DailySchedule::normalizeLegacy(
            json_decode((string)($account->settings_json ?? '{}'), true) ?: [],
            $account->next_run_at !== null ? (string)$account->next_run_at : null
        );
        $nextRunAt = $plugin->metadata()->implementationStatus === 'ready'
            ? DailySchedule::next($settings, $account->plugin_code . ':' . $account->user_id . ':' . $account->id)
            : null;
        Db::transaction(function () use (
            $account, $profile, $encrypted, $now, $credentialType, $nextRunAt
        ): void {
            Db::table('TF_plugin_credentials')
                ->where('account_id', $account->id)
                ->where('credential_type', '<>', $credentialType)
                ->delete();
            Db::table('TF_plugin_credentials')->updateOrInsert(
                ['account_id' => $account->id, 'credential_type' => $credentialType],
                $encrypted + [
                    'verified_at' => null,
                    'rotated_at' => $now,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
            Db::table('TF_plugin_accounts')->where('id', $account->id)->update([
                'external_user_id' => $profile->externalUserId !== '' ? $profile->externalUserId : $account->external_user_id,
                'display_name' => trim($profile->displayName) !== '' ? $profile->displayName : $account->display_name,
                'profile_json' => $this->mergedProfileJson($account, $profile->metadata),
                'status' => 'active',
                'last_verified_at' => null,
                'next_run_at' => $nextRunAt,
                'last_error_code' => null,
                'last_error_message' => null,
                'updated_at' => $now,
            ]);
        });
    }

    private function credential(int $accountId): object
    {
        $credential = Db::table('TF_plugin_credentials')
            ->where('account_id', $accountId)
            ->orderBy('id')
            ->first();
        if (!$credential) {
            throw new ApiException('PLUGIN_CREDENTIAL_NOT_FOUND', '平台凭据不存在', 404);
        }
        return $credential;
    }

    private function persistRefreshedCredentials(object $account, array $credentials): void
    {
        $this->assertCredentialSize($credentials);
        $plugin = (new PluginRegistry())->get((string)$account->plugin_code);
        $this->validateCredentialShape($plugin, $credentials);
        $credential = $this->credential((int)$account->id);
        $vault = new CredentialVault();
        $encrypted = $vault->encrypt(
            $credentials,
            CredentialVault::associatedData(
                (string)$account->plugin_code,
                (int)$account->id,
                (string)$credential->credential_type
            )
        );
        $now = date('Y-m-d H:i:s');
        Db::table('TF_plugin_credentials')->where('id', $credential->id)->update([
            'payload_cipher' => $encrypted['payload_cipher'],
            'nonce' => $encrypted['nonce'],
            'key_version' => $encrypted['key_version'],
            'rotated_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function present(object $row): array
    {
        $credentialConfigured = property_exists($row, 'credential_id')
            ? (bool)$row->credential_id
            : Db::table('TF_plugin_credentials')->where('account_id', $row->id)->exists();
        $settings = DailySchedule::normalizeLegacy(
            json_decode((string)($row->settings_json ?? '{}'), true) ?: [],
            $row->next_run_at !== null ? (string)$row->next_run_at : null
        );
        $profile = json_decode((string)($row->profile_json ?? '{}'), true) ?: [];
        $avatarUrl = trim((string)($profile['avatar'] ?? ''));
        $credentialExpired = $row->status === 'credential_expired'
            || in_array($row->last_error_code, ['PLUGIN_CREDENTIAL_EXPIRED', 'PLUGIN_CREDENTIAL_INVALID'], true);

        return [
            'id' => (int)$row->id,
            'plugin_code' => (string)$row->plugin_code,
            'external_user_id' => $row->external_user_id,
            'display_name' => (string)$row->display_name,
            'avatar_url' => $avatarUrl !== '' ? $avatarUrl : null,
            'status' => (string)$row->status,
            'login_status' => $credentialExpired ? 'expired' : 'normal',
            'settings' => $settings,
            'credential_configured' => $credentialConfigured,
            'last_verified_at' => $row->last_verified_at,
            'last_run_at' => $row->last_run_at,
            'next_run_at' => $row->next_run_at,
            'last_error' => $row->last_error_code ? [
                'code' => (string)$row->last_error_code,
                'message' => $row->last_error_message,
            ] : null,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    private function credentialType(array $types): string
    {
        $type = (string)($types[0] ?? 'cookie');
        if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $type)) {
            throw new ApiException('PLUGIN_CREDENTIAL_TYPE_INVALID', '插件凭据类型配置无效', 500);
        }
        return $type;
    }

    private function credentialFingerprint(CredentialVault $vault, string $pluginCode, array $credentials): string
    {
        if (!in_array($pluginCode, ['sport', 'cloud189', 'picacomic'], true)) {
            return $vault->fingerprint($credentials);
        }
        $username = mb_strtolower(trim((string)($credentials['username'] ?? '')));
        if ($pluginCode === 'sport' && !str_contains($username, '@') && !str_starts_with($username, '+86')) {
            $username = '+86' . $username;
        }
        return $vault->fingerprint(['username' => $username]);
    }

    private function assertCredentialSize(array $credentials): void
    {
        $encoded = json_encode($credentials, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 65535) {
            throw new ApiException('PLUGIN_CREDENTIAL_TOO_LARGE', '平台凭据内容过大', 422);
        }
    }

    private function validateCredentialShape(SignPluginInterface $plugin, array $credentials): void
    {
        foreach ($plugin->credentialRules() as $field => $rules) {
            $required = in_array('required', $rules, true);
            if ($required && (!array_key_exists($field, $credentials) || trim((string)$credentials[$field]) === '')) {
                throw new ApiException('PLUGIN_CREDENTIAL_INVALID', '缺少平台凭据：' . $field, 422);
            }
            if (array_key_exists($field, $credentials) && !is_string($credentials[$field])) {
                throw new ApiException('PLUGIN_CREDENTIAL_INVALID', '平台凭据字段格式无效：' . $field, 422);
            }
        }
    }

    private function initialProfile(string $pluginCode, array $credentials, SignPluginInterface $plugin): AccountProfile
    {
        // 贴吧凭据里没有稳定用户标识，必须在线拉取昵称 / uid / 头像。
        if ($pluginCode === 'tieba') {
            return $plugin->validateAccount($credentials);
        }

        $externalId = match ($pluginCode) {
            'bilibili' => trim((string)($credentials['dede_user_id'] ?? '')),
            'iqiyi' => trim((string)($credentials['p00003'] ?? '')),
            'sport' => trim((string)($credentials['user_id'] ?? '')),
            'picacomic' => trim((string)($credentials['user_id'] ?? '')),
            'cloud189' => substr(hash(
                'sha256',
                mb_strtolower(trim((string)($credentials['username'] ?? '')))
            ), 0, 24),
            default => '',
        };
        $displayName = $pluginCode === 'picacomic'
            ? trim((string)($credentials['display_name'] ?? ''))
            : '';
        return new AccountProfile(
            $externalId,
            $displayName !== '' ? $displayName : $plugin->metadata()->name . '账号'
        );
    }

    private function isCredentialFailure(ApiException $exception): bool
    {
        return in_array($exception->errorCode, [
            'PLUGIN_CREDENTIAL_EXPIRED',
            'PLUGIN_CREDENTIAL_INVALID',
        ], true);
    }

    /**
     * 校验/换绑时保留插件缓存字段（如贴吧 liked_forums），仅覆盖本次 metadata。
     */
    private function mergedProfileJson(object $account, array $metadata): string
    {
        $existing = json_decode((string)($account->profile_json ?? '{}'), true) ?: [];
        return json_encode(
            array_merge($existing, $metadata),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    private function validateSettings(SignPluginInterface $plugin, array $settings, string $seed): void
    {
        if (array_key_exists('schedule_enabled', $settings) && !is_bool($settings['schedule_enabled'])) {
            throw new ApiException('VALIDATION_FAILED', 'schedule_enabled 必须是布尔值', 422);
        }
        if (isset($settings['schedule_mode']) && !in_array($settings['schedule_mode'], ['auto', 'fixed'], true)) {
            throw new ApiException('VALIDATION_FAILED', 'schedule_mode 必须是 auto 或 fixed', 422);
        }
        DailySchedule::next($settings, $seed);
        if (isset($settings['scheduled_action'])
            && !in_array((string)$settings['scheduled_action'], $plugin->supportedActions(), true)) {
            throw new ApiException('PLUGIN_ACTION_UNSUPPORTED', '定时动作不受该插件支持', 422);
        }
        if ($plugin->metadata()->code === 'bilibili') {
            $this->validateBilibiliSettings($settings);
        }
        if ($plugin->metadata()->code === 'sport') {
            $this->validateSportSettings($settings);
        }
    }

    private function validateBilibiliSettings(array $settings): void
    {
        $booleanKeys = [
            'watch_enabled',
            'share_enabled',
            'coin_enabled',
            'allow_coin_spend',
            'live_sign_enabled',
            'live_daily_bag_enabled',
            'live_heartbeat_enabled',
            'capsule_enabled',
            'allow_capsule_spend',
            'comic_sign_enabled',
        ];
        foreach ($booleanKeys as $key) {
            if (array_key_exists($key, $settings) && !is_bool($settings[$key])) {
                throw new ApiException('VALIDATION_FAILED', $key . ' 必须是布尔值', 422);
            }
        }

        if (array_key_exists('coin_count', $settings)) {
            $count = filter_var($settings['coin_count'], FILTER_VALIDATE_INT);
            if ($count === false || $count < 1 || $count > 2) {
                throw new ApiException('VALIDATION_FAILED', '单次投币数量必须为 1 或 2', 422);
            }
        }
        if (array_key_exists('capsule_count', $settings)) {
            $count = filter_var($settings['capsule_count'], FILTER_VALIDATE_INT);
            if ($count === false || $count < 1 || $count > 100) {
                throw new ApiException('VALIDATION_FAILED', '单次使用扭蛋币数量必须在 1 到 100 之间', 422);
            }
        }
        if (array_key_exists('live_room_id', $settings) && trim((string)$settings['live_room_id']) !== '') {
            $roomId = trim((string)$settings['live_room_id']);
            if (!preg_match('/^[1-9][0-9]{0,19}$/', $roomId)) {
                throw new ApiException('VALIDATION_FAILED', '直播间 ID 格式不正确', 422);
            }
        }
    }

    private function validateSportSettings(array $settings): void
    {
        $mode = (string)($settings['step_mode'] ?? 'fixed');
        if (!in_array($mode, ['fixed', 'random'], true)) {
            throw new ApiException('VALIDATION_FAILED', '步数模式必须是 fixed 或 random', 422);
        }
        if (array_key_exists('progressive_by_time', $settings) && !is_bool($settings['progressive_by_time'])) {
            throw new ApiException('VALIDATION_FAILED', 'progressive_by_time 必须是布尔值', 422);
        }
        foreach (['steps', 'min_steps', 'max_steps'] as $key) {
            if (!array_key_exists($key, $settings)) {
                continue;
            }
            $value = filter_var($settings[$key], FILTER_VALIDATE_INT);
            if ($value === false || $value < 1 || $value > 98800) {
                throw new ApiException('VALIDATION_FAILED', '运动步数必须在 1 到 98800 之间', 422);
            }
        }
        if (isset($settings['min_steps'], $settings['max_steps'])
            && (int)$settings['min_steps'] > (int)$settings['max_steps']) {
            throw new ApiException('VALIDATION_FAILED', '随机步数最小值不能大于最大值', 422);
        }
    }
}
