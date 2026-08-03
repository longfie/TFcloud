<?php

namespace app\controller\Api;

use app\http\ApiResponse;
use app\service\AuditService;
use app\service\QqRelayApplicationService;
use app\service\SettingsService;
use app\service\VersionService;
use support\Request;
use support\Response;

final class AdminSettingsController
{
    public function version(Request $request): Response
    {
        $forceRefresh = filter_var($request->get('refresh', false), FILTER_VALIDATE_BOOL);
        return ApiResponse::success((new VersionService())->information($forceRefresh));
    }

    public function index(): Response
    {
        return ApiResponse::success((new SettingsService())->all());
    }

    public function show(string $group): Response
    {
        return ApiResponse::success((new SettingsService())->group($group));
    }

    public function update(Request $request, string $group): Response
    {
        $result = (new SettingsService())->update($group, $request->all(), (int)$request->userId());
        (new AuditService())->record($request->userId(), 'admin.settings.update', 'settings', $group, [
            'changed_fields' => array_keys($request->all()),
        ]);
        return ApiResponse::success($result, '配置已保存');
    }

    public function applyQqRelay(Request $request): Response
    {
        $result = (new QqRelayApplicationService())->applyAndVerify($request, (int)$request->userId());
        (new AuditService())->record($request->userId(), 'admin.qq_relay.apply', 'settings', 'site', [
            'client_id' => $result['client_id'] ?? null,
            'status' => $result['status'] ?? null,
            'verified' => $result['verified'] ?? false,
        ]);
        return ApiResponse::success(
            $result,
            !empty($result['verified']) ? 'QQ 快捷登录申请和域名验证已完成' : '申请已提交，等待域名验证'
        );
    }

    public function verifyQqRelay(Request $request): Response
    {
        $result = (new QqRelayApplicationService())->verify((int)$request->userId());
        (new AuditService())->record($request->userId(), 'admin.qq_relay.verify', 'settings', 'site', [
            'client_id' => $result['client_id'] ?? null,
            'status' => $result['status'] ?? null,
        ]);
        return ApiResponse::success($result, 'QQ 快捷登录域名验证已完成');
    }

    public function configureQqRelay(Request $request): Response
    {
        $result = (new QqRelayApplicationService())->configureManually(
            $request,
            (int)$request->userId(),
            (string)$request->post('client_id', ''),
            (string)$request->post('client_secret', '')
        );
        (new AuditService())->record($request->userId(), 'admin.qq_relay.configure', 'settings', 'site', [
            'client_id' => $result['client_id'] ?? null,
            'status' => $result['status'] ?? null,
        ]);
        return ApiResponse::success($result, '自定义 QQ 快捷登录凭据已验证并保存');
    }
}
