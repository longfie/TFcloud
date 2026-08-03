<?php

namespace app\service;

use app\sign\executor\SignRetryPolicy;
use support\Db;

final class TiebaRetryService
{
    /**
     * 将当天旧版本已经结束、但最后一次执行确因“第三方平台请求失败”的任务
     * 重新放入独立补签队列。
     */
    public function requeueFailedToday(int $limit = 100): int
    {
        $tasks = Db::table('TF_sign_tasks as t')
            ->join('TF_plugin_accounts as a', 'a.id', '=', 't.account_id')
            ->where('t.plugin_code', 'tieba')
            ->where('t.schedule_date', date('Y-m-d'))
            ->whereIn('t.status', ['failed', 'partial'])
            ->whereIn('a.status', ['active', 'pending_verification'])
            ->whereNull('a.deleted_at')
            ->orderBy('t.id')
            ->limit(max(1, min(500, $limit)))
            ->get(['t.*']);

        $requeued = 0;
        $retryPolicy = new SignRetryPolicy();
        foreach ($tasks as $task) {
            $latestRunId = (int)(Db::table('TF_sign_runs')
                ->where('task_id', $task->id)
                ->orderByDesc('id')
                ->value('id') ?? 0);
            $requestFailedForumIds = $latestRunId > 0
                ? Db::table('TF_sign_records')
                    ->where('run_id', $latestRunId)
                    ->where('status', 'failed')
                    ->where('result_code', 'UPSTREAM_REQUEST_FAILED')
                    ->whereNotNull('target_id')
                    ->pluck('target_id')
                    ->map(static fn ($value): string => (string)$value)
                    ->unique()
                    ->values()
                    ->all()
                : [];
            $wholeRunFailed = (string)($task->last_error_code ?? '') === 'UPSTREAM_REQUEST_FAILED';
            if (!$wholeRunFailed && $requestFailedForumIds === []) {
                continue;
            }

            $summary = json_decode((string)($task->summary_json ?? '{}'), true) ?: [];
            if ($requestFailedForumIds !== []) {
                $summary['request_failed_forum_ids'] = $requestFailedForumIds;
                $summary['request_failed_date'] = date('Y-m-d');
            }
            $now = date('Y-m-d H:i:s');
            $updated = Db::table('TF_sign_tasks')
                ->where('id', $task->id)
                ->whereIn('status', ['failed', 'partial'])
                ->update([
                    'status' => 'retrying',
                    'max_attempts' => $retryPolicy->extendedAttemptLimit(
                        (int)$task->attempts,
                        (int)$task->max_attempts
                    ),
                    'summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'last_error_code' => 'UPSTREAM_REQUEST_FAILED',
                    'last_error_message' => '第三方平台请求失败，已进入贴吧自动补签队列',
                    'available_at' => $now,
                    'finished_at' => null,
                    'locked_at' => null,
                    'locked_by' => null,
                    'updated_at' => $now,
                ]);
            $requeued += (int)$updated;
        }
        return $requeued;
    }
}
