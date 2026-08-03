<?php

namespace app\service;

use app\exception\ApiException;
use support\Db;

final class AuthService
{
    public function register(array $input, string $ip, string $userAgent): array
    {
        $username = trim((string)($input['username'] ?? ''));
        $password = (string)($input['password'] ?? '');
        $email = mb_strtolower(trim((string)($input['email'] ?? '')));
        if (mb_strlen($username) < 2 || mb_strlen($username) > 64) {
            throw new ApiException('VALIDATION_FAILED', '用户名长度必须为2到64个字符', 422);
        }
        if (strlen($password) < 8 || strlen($password) > 1024) {
            throw new ApiException('VALIDATION_FAILED', '密码长度必须为8到1024个字符', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) {
            throw new ApiException('VALIDATION_FAILED', '请输入有效的邮箱地址', 422);
        }
        if (Db::table('TF_users')->where('username', $username)->exists()) {
            throw new ApiException('USER_FIELD_DUPLICATE', '用户名已存在', 409);
        }
        if (Db::table('TF_users')->where('email', $email)->exists()) {
            throw new ApiException('USER_FIELD_DUPLICATE', '邮箱已被使用', 409);
        }

        $site = (new SettingsService())->group('site');
        if (!empty($site['registration_email_verification']) && !empty((new SettingsService())->group('mail')['enabled'])) {
            (new EmailVerificationService())->consume($email, trim((string)($input['email_code'] ?? '')), 'register');
        }
        $now = date('Y-m-d H:i:s');
        try {
            $userId = (int)Db::table('TF_users')->insertGetId([
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'display_name' => '',
                'email' => $email,
                'role' => 'user',
                'status' => 'active',
                'quota' => max(0, (int)($site['default_quota'] ?? 2)),
                'daily_sign_email' => 0,
                'password_migrated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $exception) {
            if (Db::table('TF_users')->where('username', $username)->exists()
                || Db::table('TF_users')->where('email', $email)->exists()) {
                throw new ApiException('USER_FIELD_DUPLICATE', '用户名或邮箱已被使用', 409);
            }
            throw $exception;
        }
        return $this->issueSession($userId, $ip, $userAgent, $now);
    }

    public function login(string $account, string $password, string $ip, string $userAgent): array
    {
        $account = trim($account);
        $user = filter_var($account, FILTER_VALIDATE_EMAIL)
            ? Db::table('TF_users')->where('email', mb_strtolower($account))->first()
            : Db::table('TF_users')->where('username', $account)->first();
        if (!$user || $user->status !== 'active' || !$this->verifyPassword($password, (string)$user->password_hash)) {
            throw new ApiException('AUTH_INVALID_CREDENTIALS', '账号或密码错误', 401);
        }

        $now = date('Y-m-d H:i:s');
        if (!str_starts_with((string)$user->password_hash, '$')) {
            Db::table('TF_users')->where('id', $user->id)->update([
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'password_migrated_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $this->issueSession((int)$user->id, $ip, $userAgent, $now);
    }

    public function loginWithEmailCode(string $email, string $code, string $ip, string $userAgent): array
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) {
            throw new ApiException('VALIDATION_FAILED', '请输入有效的登录邮箱', 422);
        }
        $user = Db::table('TF_users')->where('email', $email)->first();
        if (!$user || $user->status !== 'active') {
            throw new ApiException('AUTH_INVALID_EMAIL_CODE', '邮箱或验证码错误', 401);
        }
        try {
            (new EmailVerificationService())->consume($email, $code, 'login');
        } catch (ApiException $exception) {
            if (str_starts_with($exception->errorCode, 'VERIFICATION_CODE_')) {
                throw new ApiException('AUTH_INVALID_EMAIL_CODE', '邮箱或验证码错误', 401);
            }
            throw $exception;
        }
        return $this->issueSession((int)$user->id, $ip, $userAgent, date('Y-m-d H:i:s'));
    }

    public function loginExternal(int $userId, string $ip, string $userAgent): array
    {
        $user = Db::table('TF_users')->where('id', $userId)->first();
        if (!$user || $user->status !== 'active') {
            throw new ApiException('AUTH_ACCOUNT_DISABLED', '账号已停用，无法使用快捷登录', 403);
        }
        return $this->issueSession($userId, $ip, $userAgent, date('Y-m-d H:i:s'));
    }

    public function logout(string $plainToken): void
    {
        Db::table('TF_sessions')
            ->where('token_hash', hash('sha256', $plainToken))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => date('Y-m-d H:i:s')]);
    }

    public function publicUser(int $userId): array
    {
        $user = Db::table('TF_users')->where('id', $userId)->first();
        if (!$user) {
            throw new ApiException('USER_NOT_FOUND', '用户不存在', 404);
        }
        $qqIdentity = Db::table('TF_user_identities')
            ->where('user_id', $userId)
            ->whereIn('provider', ['qq', 'qq_relay'])
            ->orderByDesc('id')
            ->first();
        $qqProfile = $qqIdentity ? json_decode((string)($qqIdentity->profile_json ?? ''), true) : null;
        $siteSettings = (new SettingsService())->group('site');
        $qqRequiresRebind = $qqIdentity !== null
            && (string)$qqIdentity->provider === 'qq_relay'
            && (string)($siteSettings['qq_relay_application_status'] ?? '') === 'active'
            && (!is_array($qqProfile) || (string)($qqProfile['source'] ?? '') !== 'relay_application');

        return [
            'id' => (int)$user->id,
            'username' => (string)$user->username,
            'display_name' => (string)$user->display_name,
            'email' => $user->email,
            'qq' => $user->qq,
            'role' => (new AccessControlService())->normalizeRole((string)$user->role),
            'status' => (string)$user->status,
            'quota' => (int)$user->quota,
            'daily_sign_email' => (bool)$user->daily_sign_email,
            'push_channel' => (string)($user->push_channel ?? 'none') ?: 'none',
            'push_token' => (string)($user->push_token ?? ''),
            'has_password' => trim((string)$user->password_hash) !== '',
            'qq_binding' => [
                'bound' => $qqIdentity !== null,
                'requires_rebind' => $qqRequiresRebind,
                'provider' => $qqIdentity?->provider,
                'display_name' => $qqIdentity?->display_name,
                'linked_at' => $qqIdentity?->linked_at,
            ],
            'last_login_at' => $user->last_login_at,
            'created_at' => $user->created_at,
        ];
    }

    public function updateProfile(int $userId, array $input): array
    {
        $user = Db::table('TF_users')->where('id', $userId)->first();
        if (!$user) {
            throw new ApiException('USER_NOT_FOUND', '用户不存在', 404);
        }

        $updates = ['updated_at' => date('Y-m-d H:i:s')];
        $currentEmail = mb_strtolower(trim((string)($user->email ?? '')));
        foreach (['email', 'qq'] as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $value = trim((string)$input[$field]);
            if ($field === 'email') {
                $value = mb_strtolower($value);
                if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw new ApiException('VALIDATION_FAILED', '邮箱格式不正确', 422);
                }
                if ($value !== $currentEmail && $currentEmail !== '') {
                    $mailEnabled = !empty((new SettingsService())->group('mail')['enabled']);
                    if ($mailEnabled) {
                        (new EmailVerificationService())->consume(
                            $currentEmail,
                            trim((string)($input['current_email_code'] ?? '')),
                            'change_email'
                        );
                    }
                }
            }
            $limit = $field === 'email' ? 191 : 32;
            if (mb_strlen($value) > $limit) {
                throw new ApiException('VALIDATION_FAILED', $field === 'email' ? '邮箱过长' : 'QQ号过长', 422);
            }
            if ($value !== '' && Db::table('TF_users')->where($field, $value)->where('id', '<>', $userId)->exists()) {
                throw new ApiException('USER_FIELD_DUPLICATE', $field === 'email' ? '邮箱已被使用' : 'QQ号已被使用', 409);
            }
            $updates[$field] = $value !== '' ? $value : null;
        }
        if (array_key_exists('daily_sign_email', $input)) {
            $value = filter_var($input['daily_sign_email'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($value === null) {
                throw new ApiException('VALIDATION_FAILED', '每日签到邮件开关格式无效', 422);
            }
            if ($value && trim((string)($updates['email'] ?? $user->email)) === '') {
                throw new ApiException('EMAIL_REQUIRED', '开启每日签到邮件前请先设置邮箱', 422);
            }
            $updates['daily_sign_email'] = $value ? 1 : 0;
        }
        if (array_key_exists('push_channel', $input)) {
            $channel = trim((string)$input['push_channel']);
            if (!in_array($channel, array_merge(['none'], PushNotificationService::CHANNELS), true)) {
                throw new ApiException('VALIDATION_FAILED', '推送渠道无效', 422);
            }
            $token = array_key_exists('push_token', $input)
                ? trim((string)$input['push_token'])
                : trim((string)($user->push_token ?? ''));
            if (strlen($token) > 255) {
                throw new ApiException('VALIDATION_FAILED', '推送令牌过长', 422);
            }
            if ($channel !== 'none' && $token === '') {
                throw new ApiException('VALIDATION_FAILED', '请填写所选推送渠道的令牌', 422);
            }
            $updates['push_channel'] = $channel;
            $updates['push_token'] = $channel === 'none' ? null : $token;
        }

        Db::table('TF_users')->where('id', $userId)->update($updates);
        return $this->publicUser($userId);
    }

    private function verifyPassword(string $password, string $storedHash): bool
    {
        if (str_starts_with($storedHash, '$')) {
            return password_verify($password, $storedHash);
        }

        $legacySalt = (string)(getenv('LEGACY_PASSWORD_SALT') ?: '');
        if ($legacySalt === '') {
            return false;
        }

        $legacyHash = md5(md5($password) . md5($legacySalt));
        return hash_equals($storedHash, $legacyHash);
    }

    private function issueSession(int $userId, string $ip, string $userAgent, string $now): array
    {
        $plainToken = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 14 * 86400);
        Db::table('TF_sessions')->insert([
            'user_id' => $userId,
            'token_hash' => hash('sha256', $plainToken),
            'ip_address' => mb_substr($ip, 0, 64),
            'user_agent' => mb_substr($userAgent, 0, 500),
            'last_seen_at' => $now,
            'expires_at' => $expiresAt,
            'created_at' => $now,
        ]);

        Db::table('TF_users')->where('id', $userId)->update([
            'last_login_at' => $now,
            'last_login_ip' => mb_substr($ip, 0, 64),
            'updated_at' => $now,
        ]);

        return [
            'access_token' => $plainToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt,
            'user' => $this->publicUser($userId),
        ];
    }
}
