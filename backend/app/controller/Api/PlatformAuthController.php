<?php

namespace app\controller\Api;

use app\http\ApiResponse;
use app\service\AuditService;
use app\service\PlatformAuthService;
use support\Request;
use support\Response;

final class PlatformAuthController
{
    public function startQr(Request $request, string $platformCode): Response
    {
        $result = (new PlatformAuthService())->startQr(
            (int)$request->userId(),
            $platformCode,
            trim((string)$request->post('method', 'qr'))
        );
        return ApiResponse::created($result, '登录二维码已生成');
    }

    public function pollQr(Request $request, string $platformCode, string $flowNo): Response
    {
        $result = (new PlatformAuthService())->pollQr((int)$request->userId(), $platformCode, $flowNo);
        if (($result['status'] ?? '') === 'succeeded') {
            (new AuditService())->record($request->userId(), 'platform_account.qr_connect', 'plugin_account', $result['account']['id'] ?? null, [
                'platform_code' => $platformCode,
            ]);
        }
        return ApiResponse::success($result);
    }

    public function password(Request $request, string $platformCode): Response
    {
        $result = (new PlatformAuthService())->password((int)$request->userId(), $platformCode, $request->all());
        if (($result['status'] ?? '') === 'succeeded') {
            (new AuditService())->record($request->userId(), 'platform_account.password_connect', 'plugin_account', $result['account']['id'] ?? null, [
                'platform_code' => $platformCode,
            ]);
        }
        return ApiResponse::success($result);
    }

    public function sendSms(Request $request, string $platformCode): Response
    {
        $result = (new PlatformAuthService())->sendSms((int)$request->userId(), $platformCode, $request->all());
        return ApiResponse::success($result);
    }

    public function completeSms(Request $request, string $platformCode): Response
    {
        $result = (new PlatformAuthService())->completeSms((int)$request->userId(), $platformCode, $request->all());
        (new AuditService())->record($request->userId(), 'platform_account.sms_connect', 'plugin_account', $result['account']['id'] ?? null, [
            'platform_code' => $platformCode,
        ]);
        return ApiResponse::success($result);
    }
}
