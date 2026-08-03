<?php

namespace app\service;

use app\exception\ApiException;

final class AccessControlService
{
    public const ROLE_USER = 'user';
    public const ROLE_ADMIN = 'admin';

    public const ADMIN_ACCESS = 'admin.access';
    public const ACCOUNT_QUOTA_BYPASS = 'account_quota.bypass';

    private const ROLE_PERMISSIONS = [
        self::ROLE_USER => [],
        self::ROLE_ADMIN => [
            self::ADMIN_ACCESS,
            self::ACCOUNT_QUOTA_BYPASS,
        ],
    ];

    public function normalizeRole(?string $role): string
    {
        $value = trim((string)$role);
        return array_key_exists($value, self::ROLE_PERMISSIONS) ? $value : self::ROLE_USER;
    }

    public function isSupportedRole(mixed $role): bool
    {
        return is_string($role) && array_key_exists($role, self::ROLE_PERMISSIONS);
    }

    public function allows(string $role, string $permission): bool
    {
        return in_array($permission, self::ROLE_PERMISSIONS[$this->normalizeRole($role)], true);
    }

    public function assert(string $role, string $permission): void
    {
        if (!$this->allows($role, $permission)) {
            throw new ApiException('ADMIN_REQUIRED', '需要管理员权限', 403);
        }
    }
}
