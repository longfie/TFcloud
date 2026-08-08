<?php

namespace app\service;

use app\exception\ApiException;
use app\sign\schedule\DailySchedule;
use support\Db;

final class AdminService
{
    public function users(array $filters): array
    {
        [$limit, $offset] = $this->pagination($filters);
        $query = Db::table('TF_users');
        $keyword = mb_substr(trim((string)($filters['keyword'] ?? '')), 0, 191);
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword): void {
                $builder->where('username', 'like', '%' . $keyword . '%')
                    ->orWhere('display_name', 'like', '%' . $keyword . '%')
                    ->orWhere('email', 'like', '%' . $keyword . '%')
                    ->orWhere('qq', 'like', '%' . $keyword . '%');
            });
        }
        foreach (['role', 'status'] as $field) {
            $value = trim((string)($filters[$field] ?? ''));
            if ($value !== '') {
                $query->where($field, $value);
            }
        }
        $total = (int)(clone $query)->count();
        $items = $query->orderByDesc('id')->offset($offset)->limit($limit)->get()
            ->map(fn ($row): array => $this->presentUser($row))->all();
        return compact('items', 'total', 'limit', 'offset');
    }

    public function user(int $userId): array
    {
        $user = Db::table('TF_users')->where('id', $userId)->first();
        if (!$user) {
            throw new ApiException('USER_NOT_FOUND', '用户不存在', 404);
        }
        return $this->presentUser($user) + [
            'active_sessions' => Db::table('TF_sessions')->where('user_id', $userId)
                ->whereNull('revoked_at')->where('expires_at', '>', date('Y-m-d H:i:s'))->count(),
            'plugin_account_count' => Db::table('TF_plugin_accounts')->where('user_id', $userId)
                ->whereNull('deleted_at')->count(),
            'task_count' => Db::table('TF_sign_tasks')->where('user_id', $userId)->count(),
        ];
    }

    public function createUser(array $input): array
    {
        $username = trim((string)($input['username'] ?? ''));
        $password = (string)($input['password'] ?? '');
        $this->assertUsername($username);
        $this->assertPassword($password);
        $this->assertUniqueUserField('username', $username);
        $values = $this->editableUserValues($input);
        $now = date('Y-m-d H:i:s');
        if (!array_key_exists('quota', $input)) {
            $values['quota'] = (int)(new SettingsService())->group('site')['default_quota'];
        }
        $userId = (int)Db::table('TF_users')->insertGetId($values + [
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'password_migrated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $this->user($userId);
    }

    public function updateUser(int $actorUserId, int $userId, array $input): array
    {
        $current = Db::table('TF_users')->where('id', $userId)->first();
        if (!$current) {
            throw new ApiException('USER_NOT_FOUND', '用户不存在', 404);
        }
        if ($actorUserId === $userId) {
            if (isset($input['role']) && $input['role'] !== 'admin') {
                throw new ApiException('ADMIN_SELF_PROTECTED', '不能移除自己的管理员权限', 409);
            }
            if (isset($input['status']) && $input['status'] !== 'active') {
                throw new ApiException('ADMIN_SELF_PROTECTED', '不能禁用当前管理员账号', 409);
            }
        }

        $updates = $this->editableUserValues($input, true, $userId);
        if (array_key_exists('username', $input)) {
            $username = trim((string)$input['username']);
            $this->assertUsername($username);
            $this->assertUniqueUserField('username', $username, $userId);
            $updates['username'] = $username;
        }
        if ($updates === []) {
            return $this->user($userId);
        }
        $updates['updated_at'] = date('Y-m-d H:i:s');
        Db::table('TF_users')->where('id', $userId)->update($updates);
        $roleChanged = array_key_exists('role', $updates) && $updates['role'] !== $current->role;
        if (($updates['status'] ?? null) === 'disabled' || $roleChanged) {
            $this->revokeSessions($userId);
        }
        return $this->user($userId);
    }

    public function deleteUser(int $actorUserId, int $userId): void
    {
        $user = Db::table('TF_users')->where('id', $userId)->first();
        if (!$user) {
            throw new ApiException('USER_NOT_FOUND', '用户不存在', 404);
        }
        if ($actorUserId === $userId) {
            throw new ApiException('ADMIN_SELF_PROTECTED', '不能删除自己的账号', 409);
        }
        if ($user->role === 'admin') {
            throw new ApiException('ADMIN_PROTECTED', '不能直接删除管理员账号，请先将其降级为普通用户', 409);
        }
        Db::transaction(static function () use ($userId): void {
            // 签到任务与平台账号存在 RESTRICT 外键，必须先于用户删除；
            // 其余关联表（会话、验证码、身份、通知等）依赖 ON DELETE CASCADE/SET NULL。
            Db::table('TF_sign_tasks')->where('user_id', $userId)->delete();
            Db::table('TF_plugin_accounts')->where('user_id', $userId)->delete();
            Db::table('TF_users')->where('id', $userId)->delete();
        });
    }

    public function resetPassword(int $userId, string $password): void
    {
        $this->assertPassword($password);
        if (!Db::table('TF_users')->where('id', $userId)->exists()) {
            throw new ApiException('USER_NOT_FOUND', '用户不存在', 404);
        }
        $now = date('Y-m-d H:i:s');
        Db::transaction(function () use ($userId, $password, $now): void {
            Db::table('TF_users')->where('id', $userId)->update([
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'password_migrated_at' => $now,
                'updated_at' => $now,
            ]);
            $this->revokeSessions($userId);
        });
    }

    public function revokeSessions(int $userId): int
    {
        if (!Db::table('TF_users')->where('id', $userId)->exists()) {
            throw new ApiException('USER_NOT_FOUND', '用户不存在', 404);
        }
        return Db::table('TF_sessions')->where('user_id', $userId)->whereNull('revoked_at')->update([
            'revoked_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function accounts(array $filters): array
    {
        [$limit, $offset] = $this->pagination($filters);
        $query = Db::table('TF_plugin_accounts as a')
            ->join('TF_users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('TF_plugin_credentials as c', 'c.account_id', '=', 'a.id')
            ->whereNull('a.deleted_at');
        foreach (['plugin_code' => 'a.plugin_code', 'status' => 'a.status', 'user_id' => 'a.user_id'] as $input => $column) {
            $value = trim((string)($filters[$input] ?? ''));
            if ($value !== '') {
                $query->where($column, $value);
            }
        }
        $keyword = mb_substr(trim((string)($filters['keyword'] ?? '')), 0, 191);
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword): void {
                $builder->where('a.display_name', 'like', '%' . $keyword . '%')
                    ->orWhere('a.external_user_id', 'like', '%' . $keyword . '%')
                    ->orWhere('u.username', 'like', '%' . $keyword . '%');
            });
        }
        $total = (int)(clone $query)->count('a.id');
        $items = $query->orderByDesc('a.id')->offset($offset)->limit($limit)->get([
            'a.id', 'a.user_id', 'u.username', 'a.plugin_code', 'a.external_user_id', 'a.display_name',
            'a.status', 'a.settings_json', 'a.profile_json', 'a.last_verified_at', 'a.last_run_at', 'a.next_run_at',
            'a.last_error_code', 'a.last_error_message', 'a.created_at', 'a.updated_at', 'c.id as credential_id',
        ])->map(static function ($row): array {
            $settings = DailySchedule::normalizeLegacy(
                json_decode((string)($row->settings_json ?? '{}'), true) ?: [],
                $row->next_run_at !== null ? (string)$row->next_run_at : null
            );
            $profile = json_decode((string)($row->profile_json ?? '{}'), true) ?: [];
            $avatarUrl = trim((string)($profile['avatar'] ?? ''));
            $expired = $row->status === 'credential_expired'
                || in_array($row->last_error_code, ['PLUGIN_CREDENTIAL_EXPIRED', 'PLUGIN_CREDENTIAL_INVALID'], true);
            return [
                'id' => (int)$row->id,
                'user_id' => (int)$row->user_id,
                'username' => (string)$row->username,
                'plugin_code' => (string)$row->plugin_code,
                'external_user_id' => $row->external_user_id,
                'display_name' => (string)$row->display_name,
                'avatar_url' => $avatarUrl !== '' ? $avatarUrl : null,
                'status' => (string)$row->status,
                'login_status' => $expired ? 'expired' : 'normal',
                'settings' => $settings,
                'credential_configured' => (bool)$row->credential_id,
                'last_verified_at' => $row->last_verified_at,
                'last_run_at' => $row->last_run_at,
                'next_run_at' => $row->next_run_at,
                'last_error' => $row->last_error_code ? [
                    'code' => $row->last_error_code,
                    'message' => $row->last_error_message,
                ] : null,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ];
        })->all();
        return compact('items', 'total', 'limit', 'offset');
    }

    public function accountOwner(int $accountId): int
    {
        $value = Db::table('TF_plugin_accounts')->where('id', $accountId)->whereNull('deleted_at')->value('user_id');
        if ($value === null) {
            throw new ApiException('PLUGIN_ACCOUNT_NOT_FOUND', '插件账号不存在', 404);
        }
        return (int)$value;
    }

    public function taskOwner(string $taskNo): int
    {
        $value = Db::table('TF_sign_tasks')->where('task_no', $taskNo)->value('user_id');
        if ($value === null) {
            throw new ApiException('SIGN_TASK_NOT_FOUND', '签到任务不存在', 404);
        }
        return (int)$value;
    }

    private function editableUserValues(array $input, bool $partial = false, ?int $userId = null): array
    {
        $values = [];
        $defaults = ['display_name' => '', 'email' => null, 'qq' => null, 'role' => 'user', 'status' => 'active', 'quota' => 2];
        foreach ($defaults as $field => $default) {
            if ($partial && !array_key_exists($field, $input)) {
                continue;
            }
            $value = $input[$field] ?? $default;
            if ($field === 'display_name') {
                $values[$field] = mb_substr(trim((string)$value), 0, 191);
            } elseif ($field === 'email') {
                $value = trim((string)$value);
                if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw new ApiException('VALIDATION_FAILED', '邮箱格式不正确', 422);
                }
                if ($value !== '') {
                    $this->assertUniqueUserField('email', $value, $userId);
                }
                $values[$field] = $value !== '' ? $value : null;
            } elseif ($field === 'qq') {
                $value = trim((string)$value);
                if (mb_strlen($value) > 32) {
                    throw new ApiException('VALIDATION_FAILED', 'QQ号过长', 422);
                }
                if ($value !== '') {
                    $this->assertUniqueUserField('qq', $value, $userId);
                }
                $values[$field] = $value !== '' ? $value : null;
            } elseif ($field === 'role') {
                if (!(new AccessControlService())->isSupportedRole($value)) {
                    throw new ApiException('VALIDATION_FAILED', '用户角色无效', 422);
                }
                $values[$field] = $value;
            } elseif ($field === 'status') {
                if (!in_array($value, ['active', 'disabled'], true)) {
                    throw new ApiException('VALIDATION_FAILED', '用户状态无效', 422);
                }
                $values[$field] = $value;
            } elseif ($field === 'quota') {
                $quota = filter_var($value, FILTER_VALIDATE_INT);
                if ($quota === false || $quota < 0 || $quota > 100000) {
                    throw new ApiException('VALIDATION_FAILED', '账号配额无效', 422);
                }
                $values[$field] = $quota;
            }
        }
        return $values;
    }

    private function presentUser(object $row): array
    {
        return [
            'id' => (int)$row->id,
            'username' => (string)$row->username,
            'display_name' => (string)$row->display_name,
            'email' => $row->email,
            'qq' => $row->qq,
            'role' => (string)$row->role,
            'status' => (string)$row->status,
            'quota' => (int)$row->quota,
            'last_login_at' => $row->last_login_at,
            'last_login_ip' => $row->last_login_ip,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    private function assertUniqueUserField(string $field, string $value, ?int $exceptUserId = null): void
    {
        if (!in_array($field, ['username', 'email', 'qq'], true)) {
            throw new \InvalidArgumentException('Unsupported unique field');
        }
        $query = Db::table('TF_users')->where($field, $value);
        if ($exceptUserId !== null) {
            $query->where('id', '<>', $exceptUserId);
        }
        if ($query->exists()) {
            throw new ApiException('USER_FIELD_DUPLICATE', match ($field) {
                'username' => '用户名已存在',
                'email' => '邮箱已被使用',
                default => 'QQ号已被使用',
            }, 409);
        }
    }

    private function assertUsername(string $username): void
    {
        if (mb_strlen($username) < 2 || mb_strlen($username) > 64) {
            throw new ApiException('VALIDATION_FAILED', '用户名长度必须为2到64个字符', 422);
        }
    }

    private function assertPassword(string $password): void
    {
        if (strlen($password) < 8 || strlen($password) > 1024) {
            throw new ApiException('VALIDATION_FAILED', '密码长度必须为8到1024个字符', 422);
        }
    }

    private function pagination(array $filters): array
    {
        return [
            max(1, min(100, (int)($filters['limit'] ?? 20))),
            max(0, (int)($filters['offset'] ?? 0)),
        ];
    }
}
