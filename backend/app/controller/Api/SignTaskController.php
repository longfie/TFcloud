<?php

namespace app\controller\Api;

use app\exception\ApiException;
use app\http\ApiResponse;
use app\service\SignTaskService;
use app\service\AuditService;
use support\Request;
use support\Response;

final class SignTaskController
{
    public function index(Request $request): Response
    {
        return ApiResponse::success((new SignTaskService())->list(
            (int)$request->userId(),
            $request->get()
        ));
    }

    public function store(Request $request): Response
    {
        $accountId = (int)$request->post('account_id', 0);
        $action = trim((string)$request->post('action', ''));
        if ($accountId < 1 || $action === '') {
            throw new ApiException('VALIDATION_FAILED', 'account_id 和 action 不能为空', 422);
        }
        $result = (new SignTaskService())->create(
            (int)$request->userId(),
            $accountId,
            $action,
            'manual'
        );
        (new AuditService())->record($request->userId(), 'sign_task.create', 'sign_task', $result['task_no'], [
            'account_id' => $accountId,
            'action' => $action,
        ]);
        return ApiResponse::created($result, '签到任务已创建');
    }

    public function calendar(Request $request): Response
    {
        return ApiResponse::success((new SignTaskService())->calendar(
            (int)$request->userId(),
            trim((string)$request->get('month', date('Y-m')))
        ));
    }

    public function show(Request $request, string $taskNo): Response
    {
        return ApiResponse::success((new SignTaskService())->show((int)$request->userId(), $taskNo));
    }

    public function runs(Request $request, string $taskNo): Response
    {
        return ApiResponse::success((new SignTaskService())->runs((int)$request->userId(), $taskNo, $request->get()));
    }

    public function records(Request $request, string $taskNo): Response
    {
        return ApiResponse::success((new SignTaskService())->records((int)$request->userId(), $taskNo, $request->get()));
    }

    public function retry(Request $request, string $taskNo): Response
    {
        $result = (new SignTaskService())->retry((int)$request->userId(), $taskNo);
        (new AuditService())->record($request->userId(), 'sign_task.retry', 'sign_task', $taskNo);
        return ApiResponse::success($result, '任务已重新排队');
    }

    public function cancel(Request $request, string $taskNo): Response
    {
        $result = (new SignTaskService())->cancel((int)$request->userId(), $taskNo);
        (new AuditService())->record($request->userId(), 'sign_task.cancel', 'sign_task', $taskNo);
        return ApiResponse::success($result, '任务已取消');
    }
}
