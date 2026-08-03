<?php

namespace app\service;

use app\exception\ApiException;
use support\Db;

final class AccountQuotaService
{
    public function assertCanActivate(int $userId, ?int $exceptAccountId = null): void
    {
        $user = Db::table('TF_users')->where('id', $userId)->first();
        if (!$user) throw new ApiException('USER_NOT_FOUND', '用户不存在', 404);
        if ((new AccessControlService())->allows(
            (string)$user->role,
            AccessControlService::ACCOUNT_QUOTA_BYPASS
        )) return;
        $query = Db::table('TF_plugin_accounts')->where('user_id', $userId)->whereNull('deleted_at')
            ->whereIn('status', ['active', 'pending_verification']);
        if ($exceptAccountId !== null) $query->where('id', '<>', $exceptAccountId);
        if ((int)$query->count() >= (int)$user->quota) {
            throw new ApiException('PLUGIN_ACCOUNT_QUOTA_EXCEEDED', '当前启用账号数已达到账号配额', 409);
        }
    }

    public function enforceAccountQuota(int $userId, int $quota): int
    {
        $activeIds = Db::table('TF_plugin_accounts')->where('user_id', $userId)->whereNull('deleted_at')
            ->whereIn('status', ['active', 'pending_verification'])->orderBy('id')->pluck('id')
            ->map(static fn ($id) => (int)$id)->all();
        $suspendIds = array_slice($activeIds, max(0, $quota));
        if ($suspendIds === []) return 0;
        return Db::table('TF_plugin_accounts')->whereIn('id', $suspendIds)->update([
            'status' => 'disabled', 'next_run_at' => null,
            'last_error_code' => 'ACCOUNT_QUOTA_REDUCED',
            'last_error_message' => '账号配额调整后该账号已暂停，请停用其他账号后再启用',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
