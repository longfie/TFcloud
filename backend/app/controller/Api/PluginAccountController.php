<?php

namespace app\controller\Api;

use app\http\ApiResponse;
use app\service\PluginAccountService;
use app\service\SignTaskService;
use app\service\AuditService;
use support\Request;
use support\Response;

final class PluginAccountController
{
    public function index(Request $request): Response
    {
        return ApiResponse::success((new PluginAccountService())->list((int)$request->userId()));
    }

    public function store(Request $request): Response
    {
        $result = (new PluginAccountService())->create((int)$request->userId(), $request->all());
        (new AuditService())->record($request->userId(), 'plugin_account.create', 'plugin_account', $result['id'], [
            'plugin_code' => $result['plugin_code'],
        ]);
        return ApiResponse::created(
            $result,
            '插件账号添加成功'
        );
    }

    public function show(Request $request, int $id): Response
    {
        return ApiResponse::success((new PluginAccountService())->get((int)$request->userId(), $id));
    }

    public function update(Request $request, int $id): Response
    {
        $result = (new PluginAccountService())->update((int)$request->userId(), $id, $request->all());
        (new AuditService())->record($request->userId(), 'plugin_account.update', 'plugin_account', $id, [
            'changed_fields' => array_values(array_intersect(array_keys($request->all()), ['display_name', 'settings', 'status', 'credentials'])),
        ]);
        return ApiResponse::success(
            $result,
            '插件账号更新成功'
        );
    }

    public function destroy(Request $request, int $id): Response
    {
        (new PluginAccountService())->delete((int)$request->userId(), $id);
        (new AuditService())->record($request->userId(), 'plugin_account.delete', 'plugin_account', $id);
        return ApiResponse::success(null, '插件账号删除成功');
    }

    public function action(Request $request, int $id, string $action): Response
    {
        $result = (new SignTaskService())->create((int)$request->userId(), $id, $action, 'manual');
        (new AuditService())->record($request->userId(), 'sign_task.create', 'sign_task', $result['task_no'], [
            'account_id' => $id,
            'action' => $action,
        ]);
        return ApiResponse::created(
            $result,
            '签到任务已创建'
        );
    }
}
