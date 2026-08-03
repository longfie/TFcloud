<?php

namespace app\controller\Api;

use app\exception\ApiException;
use app\http\ApiResponse;
use app\service\InstallService;
use support\Request;
use support\Response;

final class InstallController
{
    public function status(): Response
    {
        return ApiResponse::success((new InstallService())->status());
    }

    public function checks(): Response
    {
        $service = new InstallService();
        if ($service->isInstalled()) {
            throw new ApiException('INSTALL_LOCKED', '系统已安装', 409);
        }
        return ApiResponse::success($service->environmentChecks());
    }

    public function testDatabase(Request $request): Response
    {
        $service = new InstallService();
        if ($service->isInstalled()) {
            throw new ApiException('INSTALL_LOCKED', '系统已安装', 409);
        }
        $result = $service->testDatabase((array)$request->post());
        if (!$result['ok']) {
            return ApiResponse::error('INSTALL_DB_FAILED', $result['message'], 422, $result);
        }
        return ApiResponse::success($result);
    }

    public function run(Request $request): Response
    {
        $result = (new InstallService())->install((array)$request->post());
        return ApiResponse::success($result, $result['message'] ?? 'installed');
    }
}
