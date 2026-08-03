<?php

namespace app\service;

use app\exception\ApiException;
use app\sign\registry\PluginRegistry;
use support\Db;

final class SignTaskService
{
    public const MAX_ATTEMPTS = 24;

    public function create(
        int $userId,
        int $accountId,
        string $action,
        string $triggerType = 'manual',
        ?string $scheduleDate = null
    ): array {
        $accountService = new PluginAccountService();
        $account = $accountService->findOwned($userId, $accountId);
        if (!in_array($account->status, ['active', 'pending_verification'], true)) {
            throw new ApiException('PLUGIN_ACCOUNT_DISABLED', '插件账号未启用', 409);
        }
        $plugin = (new PluginRegistry())->get($account->plugin_code);
        if ($plugin->metadata()->implementationStatus !== 'ready') {
            throw new ApiException('PLUGIN_ACTION_NOT_READY', '该插件动作仍在迁移中', 409);
        }
        if (!in_array($action, $plugin->supportedActions(), true)) {
            throw new ApiException('PLUGIN_ACTION_UNSUPPORTED', '插件不支持该动作', 422);
        }

        // 贴吧上游故障会持续补签。跨天调度或重复点击时复用尚未结束的任务，
        // 避免为同一账号创建多条并行重试链。
        if ((string)$account->plugin_code === 'tieba') {
            $active = Db::table('TF_sign_tasks')
                ->where('account_id', $accountId)
                ->where('plugin_code', 'tieba')
                ->where('action', $action)
                ->whereIn('status', ['pending', 'retrying', 'running'])
                ->orderByDesc('id')
                ->first();
            if ($active) {
                return $this->present($active) + ['duplicated' => true];
            }
        }

        $scheduleDate ??= date('Y-m-d');
        $idempotencyKey = hash('sha256', implode(':', [
            $account->plugin_code,
            $accountId,
            $action,
            $scheduleDate,
        ]));
        $existing = Db::table('TF_sign_tasks')->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $this->present($existing) + ['duplicated' => true];
        }

        $now = date('Y-m-d H:i:s');
        $taskNo = bin2hex(random_bytes(16));
        Db::table('TF_sign_tasks')->insertOrIgnore([
            'task_no' => $taskNo,
            'request_id' => $this->currentRequestId(),
            'user_id' => $userId,
            'account_id' => $accountId,
            'plugin_code' => $account->plugin_code,
            'action' => $action,
            'trigger_type' => $triggerType,
            'idempotency_key' => $idempotencyKey,
            'schedule_date' => $scheduleDate,
            'status' => 'pending',
            'priority' => $triggerType === 'manual' ? 10 : 0,
            'max_attempts' => self::MAX_ATTEMPTS,
            'available_at' => $now,
            'account_snapshot_json' => json_encode([
                'external_user_id' => $account->external_user_id,
                'display_name' => $account->display_name,
                'plugin_version' => $plugin->metadata()->version,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $task = Db::table('TF_sign_tasks')->where('idempotency_key', $idempotencyKey)->first();
        return $this->present($task) + ['duplicated' => $task->task_no !== $taskNo];
    }

    public function list(int $userId, array $filters = []): array
    {
        [$page, $perPage, $offset] = $this->pagination($filters);
        $query = Db::table('TF_sign_tasks')->where('user_id', $userId);
        $pluginCode = trim((string)($filters['plugin_code'] ?? ''));
        $status = trim((string)($filters['status'] ?? ''));
        if ($pluginCode !== '') {
            $query->where('plugin_code', $pluginCode);
        }
        if ($status === 'completed') {
            $query->whereIn('status', ['succeeded', 'partial']);
        } elseif ($status !== '') {
            $query->where('status', $status);
        }
        $total = (int)(clone $query)->count();
        $items = $query
            ->orderByDesc('id')
            ->offset($offset)
            ->limit($perPage)
            ->get()
            ->map(fn ($row) => $this->present($row))
            ->all();
        return $this->pageResult($items, $total, $page, $perPage);
    }

    public function show(int $userId, string $taskNo): array
    {
        return $this->present($this->findOwned($userId, $taskNo));
    }

    public function calendar(int $userId, string $month): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new ApiException('VALIDATION_FAILED', '月份格式必须为 YYYY-MM', 422);
        }
        $start = $month . '-01';
        $end = date('Y-m-t', strtotime($start));
        $rows = Db::table('TF_sign_tasks')
            ->where('user_id', $userId)
            ->whereNotNull('schedule_date')
            ->whereBetween('schedule_date', [$start, $end])
            ->groupBy('schedule_date', 'status')
            ->orderBy('schedule_date')
            ->get([Db::raw('schedule_date'), Db::raw('status'), Db::raw('COUNT(*) as total')]);
        $days = [];
        foreach ($rows as $row) {
            $date = (string)$row->schedule_date;
            $days[$date] ??= ['date' => $date, 'total' => 0, 'completed' => 0, 'failed' => 0, 'pending' => 0, 'cancelled' => 0];
            $count = (int)$row->total;
            $days[$date]['total'] += $count;
            if (in_array($row->status, ['succeeded', 'partial'], true)) {
                $days[$date]['completed'] += $count;
            } elseif ($row->status === 'failed') {
                $days[$date]['failed'] += $count;
            } elseif ($row->status === 'cancelled') {
                $days[$date]['cancelled'] += $count;
            } else {
                $days[$date]['pending'] += $count;
            }
        }
        return ['month' => $month, 'days' => array_values($days)];
    }

    public function runs(int $userId, string $taskNo, array $filters = []): array
    {
        $task = $this->findOwned($userId, $taskNo);
        [$page, $perPage, $offset] = $this->pagination($filters);
        $query = Db::table('TF_sign_runs')->where('task_id', $task->id);
        $total = (int)(clone $query)->count();
        $items = $query->orderByDesc('attempt_no')->offset($offset)->limit($perPage)->get()
            ->map(static fn ($row) => (array)$row)->all();
        return $this->pageResult($items, $total, $page, $perPage);
    }

    public function records(int $userId, string $taskNo, array $filters = []): array
    {
        $task = $this->findOwned($userId, $taskNo);
        // 实时进度弹窗需要一次拉更多明细；上限单独放宽到 500。
        $page = max(1, (int)($filters['page'] ?? 1));
        $perPage = max(1, min(500, (int)($filters['per_page'] ?? $filters['limit'] ?? 20)));
        $offset = ($page - 1) * $perPage;
        $query = Db::table('TF_sign_records')->where('task_id', $task->id);
        $status = trim((string)($filters['status'] ?? ''));
        if (in_array($status, ['succeeded', 'already_done', 'skipped', 'failed'], true)) {
            $query->where('status', $status);
        }
        $action = trim((string)($filters['action'] ?? ''));
        if ($action !== '' && mb_strlen($action) <= 64) {
            $query->where('action', $action);
        }
        $attemptNo = max(0, (int)($filters['attempt_no'] ?? 0));
        if ($attemptNo > 0) {
            $runId = (int)(Db::table('TF_sign_runs')
                ->where('task_id', $task->id)
                ->where('attempt_no', $attemptNo)
                ->value('id') ?? 0);
            $query->where('run_id', $runId > 0 ? $runId : -1);
        }
        $keyword = trim((string)($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $keyword = mb_substr($keyword, 0, 100);
            $like = '%' . addcslashes($keyword, '\\%_') . '%';
            $query->where(function ($search) use ($like): void {
                $search->where('target_name', 'like', $like)
                    ->orWhere('target_id', 'like', $like)
                    ->orWhere('message', 'like', $like)
                    ->orWhere('result_code', 'like', $like);
            });
        }
        $total = (int)(clone $query)->count();
        $items = $query->orderBy('sequence_no')->orderBy('id')->offset($offset)->limit($perPage)->get()
            ->map(static function ($row): array {
                $data = (array)$row;
                foreach (['reward_json', 'metrics_json', 'safe_result_json'] as $field) {
                    $data[$field] = json_decode((string)($data[$field] ?? '{}'), true) ?: [];
                }
                return $data;
            })->all();
        return $this->pageResult($items, $total, $page, $perPage);
    }

    public function retry(int $userId, string $taskNo): array
    {
        $task = $this->findOwned($userId, $taskNo);
        if (!in_array($task->status, ['failed', 'partial', 'cancelled'], true)) {
            throw new ApiException('TASK_NOT_RETRYABLE', '当前任务状态不能重试', 409);
        }
        if ((int)$task->attempts >= (int)$task->max_attempts) {
            throw new ApiException('TASK_RETRY_EXHAUSTED', '任务重试次数已经用完', 409);
        }
        Db::table('TF_sign_tasks')->where('id', $task->id)->update([
            'status' => 'pending',
            'available_at' => date('Y-m-d H:i:s'),
            'locked_at' => null,
            'locked_by' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->show($userId, $taskNo);
    }

    /** 管理员完整重跑：创建独立任务，保留原任务及其执行记录。贴吧会重新拉取关注列表。 */
    public function fullRerun(int $userId, string $taskNo): array
    {
        $source = $this->findOwned($userId, $taskNo);
        if (in_array((string)$source->status, ['pending', 'retrying', 'running'], true)) {
            throw new ApiException('TASK_STILL_ACTIVE', '任务仍在执行或等待中，不能完整重跑', 409);
        }
        $accountService = new PluginAccountService();
        $account = $accountService->findOwned($userId, (int)$source->account_id);
        if (!in_array($account->status, ['active', 'pending_verification'], true)) {
            throw new ApiException('PLUGIN_ACCOUNT_DISABLED', '插件账号未启用，不能重新执行', 409);
        }
        $plugin = (new PluginRegistry())->get((string)$source->plugin_code);
        if ($plugin->metadata()->implementationStatus !== 'ready') {
            throw new ApiException('PLUGIN_ACTION_NOT_READY', '该插件动作当前不可执行', 409);
        }
        if (!in_array((string)$source->action, $plugin->supportedActions(), true)) {
            throw new ApiException('PLUGIN_ACTION_UNSUPPORTED', '插件已不再支持该任务动作', 422);
        }

        $now = date('Y-m-d H:i:s');
        $newTaskNo = bin2hex(random_bytes(16));
        Db::table('TF_sign_tasks')->insert([
            'task_no' => $newTaskNo,
            'request_id' => $this->currentRequestId(),
            'user_id' => $userId,
            'account_id' => (int)$source->account_id,
            'plugin_code' => (string)$source->plugin_code,
            'action' => (string)$source->action,
            'trigger_type' => 'admin_rerun',
            'idempotency_key' => hash('sha256', $source->task_no . ':admin_rerun:' . $newTaskNo),
            'schedule_date' => date('Y-m-d'),
            'status' => 'pending',
            'priority' => 10,
            'max_attempts' => self::MAX_ATTEMPTS,
            'available_at' => $now,
            'account_snapshot_json' => json_encode([
                'external_user_id' => $account->external_user_id,
                'display_name' => $account->display_name,
                'plugin_version' => $plugin->metadata()->version,
                'rerun_of' => (string)$source->task_no,
                'refresh_followed_forums' => (string)$source->plugin_code === 'tieba',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $task = Db::table('TF_sign_tasks')->where('task_no', $newTaskNo)->first();
        return $this->present($task) + ['source_task_no' => (string)$source->task_no];
    }

    public function cancel(int $userId, string $taskNo): array
    {
        $task = $this->findOwned($userId, $taskNo);
        if (!in_array($task->status, ['pending', 'retrying'], true)) {
            throw new ApiException('TASK_NOT_CANCELLABLE', '当前任务状态不能取消', 409);
        }
        Db::table('TF_sign_tasks')->where('id', $task->id)->update([
            'status' => 'cancelled',
            'finished_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->show($userId, $taskNo);
    }

    private function findOwned(int $userId, string $taskNo): object
    {
        $task = Db::table('TF_sign_tasks')->where('task_no', $taskNo)->where('user_id', $userId)->first();
        if (!$task) {
            throw new ApiException('SIGN_TASK_NOT_FOUND', '签到任务不存在', 404);
        }
        return $task;
    }

    private function present(object $task): array
    {
        return [
            'task_no' => (string)$task->task_no,
            'account_id' => (int)$task->account_id,
            'plugin_code' => (string)$task->plugin_code,
            'action' => (string)$task->action,
            'trigger_type' => (string)$task->trigger_type,
            'status' => $task->status === 'partial' ? 'completed' : (string)$task->status,
            'schedule_date' => $task->schedule_date,
            'attempts' => (int)$task->attempts,
            'max_attempts' => (int)$task->max_attempts,
            'counts' => [
                'total' => (int)$task->total_count,
                'success' => (int)$task->success_count,
                'already' => (int)$task->already_count,
                'skipped' => (int)$task->skipped_count,
                'failed' => (int)$task->failed_count,
            ],
            'summary' => json_decode((string)($task->summary_json ?? '{}'), true) ?: [],
            'last_error' => $task->last_error_code ? [
                'code' => $task->last_error_code,
                'message' => $task->last_error_message,
            ] : null,
            'scheduled_at' => $task->scheduled_at,
            'started_at' => $task->started_at,
            'finished_at' => $task->finished_at,
            'created_at' => $task->created_at,
            'updated_at' => $task->updated_at,
        ];
    }

    private function pagination(array $filters): array
    {
        $page = max(1, (int)($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int)($filters['per_page'] ?? $filters['limit'] ?? 20)));
        return [$page, $perPage, ($page - 1) * $perPage];
    }

    private function pageResult(array $items, int $total, int $page, int $perPage): array
    {
        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, (int)ceil($total / $perPage)),
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ];
    }

    private function currentRequestId(): ?string
    {
        try {
            $request = request();
            return $request instanceof \support\Request && $request->requestId() !== ''
                ? $request->requestId()
                : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
