<?php

namespace app\controller\Api;

use app\exception\ApiException;
use app\http\ApiResponse;
use app\service\AdminService;
use app\service\AuditService;
use app\service\PluginAccountService;
use app\service\SignTaskService;
use support\Db;
use support\Request;
use support\Response;

final class AdminController
{
    public function overview(): Response
    {
        $today = date('Y-m-d 00:00:00');
        $taskStatuses = Db::table('TF_sign_tasks')
            ->where('created_at', '>=', $today)
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(static fn ($value): int => (int)$value)
            ->all();
        $accountPlugins = Db::table('TF_plugin_accounts')
            ->whereNull('deleted_at')
            ->selectRaw('plugin_code, status, COUNT(*) AS total')
            ->groupBy('plugin_code', 'status')
            ->get()
            ->map(static fn ($row): array => [
                'plugin_code' => (string)$row->plugin_code,
                'status' => (string)$row->status,
                'total' => (int)$row->total,
            ])->all();

        return ApiResponse::success([
            'users' => [
                'total' => Db::table('TF_users')->count(),
                'active' => Db::table('TF_users')->where('status', 'active')->count(),
            ],
            'plugin_accounts' => $accountPlugins,
            'tasks_today' => $taskStatuses,
            'queue' => [
                'pending' => Db::table('TF_sign_tasks')->whereIn('status', ['pending', 'retrying'])->count(),
                'running' => Db::table('TF_sign_tasks')->where('status', 'running')->count(),
                'failed_24h' => Db::table('TF_sign_tasks')
                    ->where('status', 'failed')
                    ->where('updated_at', '>=', date('Y-m-d H:i:s', time() - 86400))
                    ->count(),
            ],
        ]);
    }

    public function tasks(Request $request): Response
    {
        $page = max(1, (int)$request->get('page', 1));
        $perPage = max(1, min(100, (int)$request->get('per_page', $request->get('limit', 20))));
        $query = Db::table('TF_sign_tasks');
        $status = trim((string)$request->get('status', ''));
        $pluginCode = trim((string)$request->get('plugin_code', ''));
        if ($status === 'completed') {
            $query->whereIn('status', ['succeeded', 'partial']);
        } elseif ($status !== '') {
            $query->where('status', $status);
        }
        if ($pluginCode !== '') {
            $query->where('plugin_code', $pluginCode);
        }
        $total = (int)(clone $query)->count();
        $rows = $query->orderByDesc('id')->offset(($page - 1) * $perPage)->limit($perPage)->get()->map(static fn ($row): array => [
            'task_no' => (string)$row->task_no,
            'user_id' => (int)$row->user_id,
            'account_id' => (int)$row->account_id,
            'plugin_code' => (string)$row->plugin_code,
            'action' => (string)$row->action,
            'trigger_type' => (string)$row->trigger_type,
            'status' => $row->status === 'partial' ? 'completed' : (string)$row->status,
            'attempts' => (int)$row->attempts,
            'max_attempts' => (int)$row->max_attempts,
            'schedule_date' => $row->schedule_date,
            'counts' => [
                'total' => (int)$row->total_count,
                'success' => (int)$row->success_count,
                'already' => (int)$row->already_count,
                'skipped' => (int)$row->skipped_count,
                'failed' => (int)$row->failed_count,
            ],
            'summary' => json_decode((string)($row->summary_json ?? '{}'), true) ?: [],
            'last_error' => $row->last_error_code ? [
                'code' => $row->last_error_code,
                'message' => $row->last_error_message,
            ] : null,
            'scheduled_at' => $row->scheduled_at,
            'started_at' => $row->started_at,
            'finished_at' => $row->finished_at,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ])->all();
        return ApiResponse::success([
            'items' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, (int)ceil($total / $perPage)),
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ]);
    }

    public function auditLogs(Request $request): Response
    {
        $limit = max(1, min(100, (int)$request->get('limit', 50)));
        $beforeId = max(0, (int)$request->get('before_id', 0));
        $query = Db::table('TF_audit_logs')->orderByDesc('id');
        if ($beforeId > 0) {
            $query->where('id', '<', $beforeId);
        }
        $rows = $query->limit($limit)->get()->map(static function ($row): array {
            return [
                'id' => (int)$row->id,
                'request_id' => $row->request_id,
                'user_id' => $row->user_id ? (int)$row->user_id : null,
                'action' => (string)$row->action,
                'resource_type' => $row->resource_type,
                'resource_id' => $row->resource_id,
                'ip_address' => $row->ip_address,
                'context' => json_decode((string)($row->context_json ?? '{}'), true) ?: [],
                'created_at' => $row->created_at,
            ];
        })->all();
        return ApiResponse::success($rows);
    }

    public function users(Request $request): Response
    {
        return ApiResponse::success((new AdminService())->users($request->get()));
    }

    public function user(int $id): Response
    {
        return ApiResponse::success((new AdminService())->user($id));
    }

    public function createUser(Request $request): Response
    {
        $result = (new AdminService())->createUser($request->all());
        (new AuditService())->record($request->userId(), 'admin.user.create', 'user', $result['id'], [
            'role' => $result['role'],
        ]);
        return ApiResponse::created($result, '用户已创建');
    }

    public function updateUser(Request $request, int $id): Response
    {
        $result = (new AdminService())->updateUser((int)$request->userId(), $id, $request->all());
        (new AuditService())->record($request->userId(), 'admin.user.update', 'user', $id, [
            'changed_fields' => array_values(array_intersect(array_keys($request->all()), [
                'username', 'display_name', 'email', 'qq', 'role', 'status', 'quota',
            ])),
        ]);
        return ApiResponse::success($result, '用户已更新');
    }

    public function deleteUser(Request $request, int $id): Response
    {
        (new AdminService())->deleteUser((int)$request->userId(), $id);
        (new AuditService())->record($request->userId(), 'admin.user.delete', 'user', $id);
        return ApiResponse::success(null, '用户已删除');
    }

    public function resetUserPassword(Request $request, int $id): Response
    {
        $password = (string)$request->post('password', '');
        if ($password === '') {
            throw new ApiException('VALIDATION_FAILED', '新密码不能为空', 422);
        }
        (new AdminService())->resetPassword($id, $password);
        (new AuditService())->record($request->userId(), 'admin.user.password.reset', 'user', $id);
        return ApiResponse::success(null, '密码已重置，原会话已撤销');
    }

    public function revokeUserSessions(Request $request, int $id): Response
    {
        $count = (new AdminService())->revokeSessions($id);
        (new AuditService())->record($request->userId(), 'admin.user.sessions.revoke', 'user', $id, [
            'revoked_count' => $count,
        ]);
        return ApiResponse::success(['revoked_count' => $count], '用户会话已撤销');
    }

    public function pluginAccounts(Request $request): Response
    {
        return ApiResponse::success((new AdminService())->accounts($request->get()));
    }

    public function updatePluginAccount(Request $request, int $id): Response
    {
        $admin = new AdminService();
        $ownerId = $admin->accountOwner($id);
        $result = (new PluginAccountService())->update($ownerId, $id, $request->all());
        (new AuditService())->record($request->userId(), 'admin.plugin_account.update', 'plugin_account', $id, [
            'owner_user_id' => $ownerId,
            'changed_fields' => array_values(array_intersect(array_keys($request->all()), [
                'display_name', 'settings', 'status', 'credentials',
            ])),
        ]);
        return ApiResponse::success($result, '插件账号已更新');
    }

    public function deletePluginAccount(Request $request, int $id): Response
    {
        $ownerId = (new AdminService())->accountOwner($id);
        (new PluginAccountService())->delete($ownerId, $id);
        (new AuditService())->record($request->userId(), 'admin.plugin_account.delete', 'plugin_account', $id, [
            'owner_user_id' => $ownerId,
        ]);
        return ApiResponse::success(null, '插件账号已删除');
    }

    public function pluginAccountAction(Request $request, int $id, string $action): Response
    {
        $ownerId = (new AdminService())->accountOwner($id);
        $result = (new SignTaskService())->create($ownerId, $id, $action, 'admin_manual');
        (new AuditService())->record($request->userId(), 'admin.sign_task.create', 'sign_task', $result['task_no'], [
            'owner_user_id' => $ownerId,
            'account_id' => $id,
            'action' => $action,
        ]);
        return ApiResponse::created($result, '签到任务已创建');
    }

    public function task(string $taskNo): Response
    {
        $ownerId = (new AdminService())->taskOwner($taskNo);
        return ApiResponse::success((new SignTaskService())->show($ownerId, $taskNo));
    }

    public function taskRuns(Request $request, string $taskNo): Response
    {
        $ownerId = (new AdminService())->taskOwner($taskNo);
        return ApiResponse::success((new SignTaskService())->runs($ownerId, $taskNo, $request->get()));
    }

    public function taskRecords(Request $request, string $taskNo): Response
    {
        $ownerId = (new AdminService())->taskOwner($taskNo);
        return ApiResponse::success((new SignTaskService())->records($ownerId, $taskNo, $request->get()));
    }

    public function retryTask(Request $request, string $taskNo): Response
    {
        $ownerId = (new AdminService())->taskOwner($taskNo);
        $result = (new SignTaskService())->fullRerun($ownerId, $taskNo);
        (new AuditService())->record(
            $request->userId(),
            'admin.sign_task.full_rerun',
            'sign_task',
            $result['task_no'],
            ['source_task_no' => $taskNo, 'owner_user_id' => $ownerId]
        );
        return ApiResponse::success(
            $result,
            ($result['plugin_code'] ?? '') === 'tieba'
                ? '已创建任务，将重新获取当前关注的贴吧后执行'
                : '已创建完整重跑任务'
        );
    }

    public function cancelTask(Request $request, string $taskNo): Response
    {
        $ownerId = (new AdminService())->taskOwner($taskNo);
        $result = (new SignTaskService())->cancel($ownerId, $taskNo);
        (new AuditService())->record($request->userId(), 'admin.sign_task.cancel', 'sign_task', $taskNo);
        return ApiResponse::success($result, '任务已取消');
    }
}
