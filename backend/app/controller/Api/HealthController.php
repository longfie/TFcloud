<?php

namespace app\controller\Api;

use app\http\ApiResponse;
use support\Db;
use support\Response;

final class HealthController
{
    public function live(): Response
    {
        return ApiResponse::success([
            'service' => 'tf-sign-backend',
            'status' => 'up',
            'time' => date(DATE_ATOM),
        ]);
    }

    public function ready(): Response
    {
        try {
            Db::select('SELECT 1');
            return ApiResponse::success(['database' => 'up']);
        } catch (\Throwable) {
            return ApiResponse::error('SERVICE_NOT_READY', '数据库尚未就绪', 503, [
                'database' => 'down',
            ]);
        }
    }
}
