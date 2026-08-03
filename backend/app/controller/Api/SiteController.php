<?php

namespace app\controller\Api;

use app\http\ApiResponse;
use app\service\SettingsService;
use support\Db;
use support\Response;

final class SiteController
{
    public function show(): Response
    {
        $site = (new SettingsService())->publicSite();
        $site['user_count'] = (int)Db::table('TF_users')->count();
        $todaySignedAccounts = (int)Db::table('TF_sign_tasks')
            ->whereIn('status', ['succeeded', 'partial'])
            ->where('finished_at', '>=', date('Y-m-d 00:00:00'))
            ->count();
        $site['today_signed_accounts'] = $todaySignedAccounts;
        // Keep older frontend builds usable during rolling deployments.
        $site['today_signed_users'] = $todaySignedAccounts;
        return ApiResponse::success($site);
    }
}
