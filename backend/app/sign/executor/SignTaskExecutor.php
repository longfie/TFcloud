<?php

namespace app\sign\executor;

use app\exception\ApiException;
use app\service\NotificationMailService;
use app\service\PluginAccountService;
use app\sign\dto\SignContext;
use app\sign\dto\SignRecord;
use app\sign\dto\SignResult;
use app\sign\progress\SignProgressReporter;
use app\sign\registry\PluginRegistry;
use support\Db;
use Throwable;

final class SignTaskExecutor
{
    public const QUEUE_DEFAULT = 'default';
    public const QUEUE_TIEBA_RETRY = 'tieba_retry';

    public function recoverStale(int $timeoutSeconds = 7200, int $limit = 100): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - max(300, $timeoutSeconds));
        $tasks = Db::table('TF_sign_tasks')
            ->where('status', 'running')
            ->whereNotNull('locked_at')
            ->where('locked_at', '<', $cutoff)
            ->orderBy('locked_at')
            ->limit(max(1, min(500, $limit)))
            ->get();
        $recovered = 0;
        foreach ($tasks as $task) {
            $retryPolicy = new SignRetryPolicy();
            $continuousRetry = $retryPolicy->retriesUntilSuccess(
                (string)$task->plugin_code,
                $task->last_error_code !== null ? (string)$task->last_error_code : null
            );
            $ordinaryRetry = (string)$task->plugin_code !== 'tieba'
                && (int)$task->attempts < (int)$task->max_attempts;
            $failed = !$continuousRetry && !$ordinaryRetry;
            $now = date('Y-m-d H:i:s');
            $maxAttempts = $continuousRetry
                ? $retryPolicy->extendedAttemptLimit((int)$task->attempts, (int)$task->max_attempts)
                : (int)$task->max_attempts;
            $updated = Db::table('TF_sign_tasks')
                ->where('id', $task->id)
                ->where('status', 'running')
                ->where('locked_at', $task->locked_at)
                ->update([
                    'status' => $failed ? 'failed' : 'retrying',
                    'max_attempts' => $maxAttempts,
                    'available_at' => $failed ? null : $now,
                    'locked_at' => null,
                    'locked_by' => null,
                    'last_error_code' => 'WORKER_TIMEOUT',
                    'last_error_message' => '执行进程超时或已退出',
                    'finished_at' => $failed ? $now : null,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                continue;
            }
            Db::table('TF_sign_runs')
                ->where('task_id', $task->id)
                ->where('status', 'running')
                ->update([
                    'status' => 'failed',
                    'failed_count' => Db::raw('GREATEST(failed_count, 1)'),
                    'error_code' => 'WORKER_TIMEOUT',
                    'error_message' => '执行进程超时或已退出',
                    'finished_at' => $now,
                ]);
            $recovered++;
        }
        return $recovered;
    }

    public function processNext(string $workerId, string $queue = self::QUEUE_DEFAULT): bool
    {
        $candidateQuery = Db::table('TF_sign_tasks')
            ->where('available_at', '<=', date('Y-m-d H:i:s'))
            ->orderByDesc('priority')
            ->orderBy('id');
        if ($queue === self::QUEUE_TIEBA_RETRY) {
            $candidateQuery
                ->where('status', 'retrying')
                ->where('plugin_code', 'tieba')
                ->where('last_error_code', 'UPSTREAM_REQUEST_FAILED');
        } else {
            // 独立补签进程专门领取贴吧请求失败任务，普通签到 Worker 不再竞争它们。
            $candidateQuery->where(function ($query): void {
                $query->where('status', 'pending')
                    ->orWhere(function ($retrying): void {
                        $retrying->where('status', 'retrying')
                            ->where(function ($notTiebaRequestFailure): void {
                                $notTiebaRequestFailure
                                    ->where('plugin_code', '!=', 'tieba')
                                    ->orWhereNull('last_error_code')
                                    ->orWhere('last_error_code', '!=', 'UPSTREAM_REQUEST_FAILED');
                            });
                    });
            });
        }
        $candidate = $candidateQuery->first();
        if (!$candidate) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $claimed = Db::table('TF_sign_tasks')
            ->where('id', $candidate->id)
            ->whereIn('status', ['pending', 'retrying'])
            ->update([
                'status' => 'running',
                'attempts' => Db::raw('attempts + 1'),
                'locked_at' => $now,
                'locked_by' => $workerId,
                'started_at' => Db::raw('COALESCE(started_at, NOW(3))'),
                'updated_at' => $now,
            ]);
        if ($claimed !== 1) {
            return false;
        }

        $this->execute((int)$candidate->id, $workerId);
        return true;
    }

    public function execute(int $taskId, string $workerId): void
    {
        $task = Db::table('TF_sign_tasks')->where('id', $taskId)->first();
        if (!$task) {
            return;
        }
        $plugin = (new PluginRegistry())->get($task->plugin_code);
        $runStarted = microtime(true);
        $runId = (int)Db::table('TF_sign_runs')->insertGetId([
            'run_no' => bin2hex(random_bytes(16)),
            'task_id' => $task->id,
            'attempt_no' => $task->attempts,
            'plugin_code' => $task->plugin_code,
            'plugin_version' => $plugin->metadata()->version,
            'worker_id' => $workerId,
            'status' => 'running',
            'started_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        try {
            $accountService = new PluginAccountService();
            $account = $accountService->findOwned((int)$task->user_id, (int)$task->account_id);
            if (!in_array($account->status, ['active', 'pending_verification'], true)) {
                throw new ApiException('PLUGIN_ACCOUNT_DISABLED', '插件账号当前不可执行', 409);
            }
            $credentials = $accountService->decryptedCredential($account);
            $accountService->validateBeforeExecution($account, $credentials);
            // 校验可能合并写回 profile，重新读取后再交给插件（如贴吧关注缓存）。
            $account = $accountService->findOwned((int)$task->user_id, (int)$task->account_id);
            $settings = json_decode((string)($account->settings_json ?? '{}'), true) ?: [];
            $profile = json_decode((string)($account->profile_json ?? '{}'), true) ?: [];
            $taskSummary = json_decode((string)($task->summary_json ?? '{}'), true) ?: [];
            $options = ['profile' => $profile];
            $forceTiebaForumRefresh = (string)$task->plugin_code === 'tieba'
                && (string)$task->trigger_type === 'admin_rerun'
                && (int)$task->attempts === 1;
            if ($forceTiebaForumRefresh) {
                // 后台完整重跑必须基于当前关注列表从头执行，不能沿用旧任务的
                // 关注缓存或 request_failed_forum_ids；自动补签仍只处理失败项。
                $options['force_refresh_forums'] = true;
            } elseif ((string)$task->plugin_code === 'tieba'
                && (string)($task->last_error_code ?? '') === 'UPSTREAM_REQUEST_FAILED'
                && is_array($taskSummary['request_failed_forum_ids'] ?? null)
                && (string)($taskSummary['request_failed_date'] ?? '') === date('Y-m-d')
            ) {
                $options['retry_forum_ids'] = $taskSummary['request_failed_forum_ids'];
            }
            $progress = new SignProgressReporter($task, $runId);
            $result = $plugin->execute(new SignContext(
                (int)$task->user_id,
                (int)$task->account_id,
                (string)$task->action,
                $credentials,
                $settings,
                $options,
                $progress,
            ));
            $this->complete($task, $runId, $result, $runStarted, $progress);
        } catch (Throwable $exception) {
            $this->fail($task, $runId, $exception, $runStarted);
        }
    }

    private function complete(
        object $task,
        int $runId,
        SignResult $result,
        float $started,
        ?SignProgressReporter $progress = null
    ): void {
        $counts = ['total' => 0, 'success' => 0, 'already' => 0, 'skipped' => 0, 'failed' => 0];
        if ($progress?->wasUsed()) {
            $counts = $progress->counts();
        } else {
            $sequence = 0;
            foreach ($result->records as $record) {
                $this->saveRecord($task, $runId, $record, null, $sequence, $counts);
            }
        }

        $runStatus = $counts['failed'] > 0 && $counts['success'] + $counts['already'] > 0
            ? 'partial'
            : ($counts['failed'] > 0 ? 'failed' : 'succeeded');
        $finishedAt = date('Y-m-d H:i:s');
        $duration = (int)round((microtime(true) - $started) * 1000);
        $summary = (new SensitiveDataRedactor())->redact($result->summary);
        $retryPolicy = new SignRetryPolicy();
        $retryFailure = $retryPolicy->transientFailureFromResult((string)$task->plugin_code, $result);
        $taskStatus = $retryFailure !== null ? 'retrying' : $runStatus;
        $availableAt = $retryFailure !== null
            ? date('Y-m-d H:i:s', time() + $retryPolicy->retryDelaySeconds((int)$task->attempts))
            : null;
        $maxAttempts = $retryFailure !== null
            ? $retryPolicy->extendedAttemptLimit((int)$task->attempts, (int)$task->max_attempts)
            : (int)$task->max_attempts;
        if ($retryFailure !== null) {
            $summary['auto_retry'] = [
                'scheduled' => true,
                'available_at' => $availableAt,
                'reason' => $retryFailure['code'],
            ];
        }

        Db::transaction(function () use (
            $task, $runId, $runStatus, $taskStatus, $counts, $summary, $finishedAt, $duration,
            $result, $retryFailure, $availableAt, $maxAttempts
        ): void {
            Db::table('TF_sign_runs')->where('id', $runId)->update([
                'status' => $runStatus,
                'total_count' => $counts['total'],
                'success_count' => $counts['success'],
                'already_count' => $counts['already'],
                'skipped_count' => $counts['skipped'],
                'failed_count' => $counts['failed'],
                'error_code' => $retryFailure['code'] ?? null,
                'error_message' => isset($retryFailure['message'])
                    ? mb_substr((new SensitiveDataRedactor())->redactString($retryFailure['message']), 0, 500)
                    : null,
                'summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'finished_at' => $finishedAt,
                'duration_ms' => $duration,
            ]);
            Db::table('TF_sign_tasks')->where('id', $task->id)->update([
                'status' => $taskStatus,
                'max_attempts' => $maxAttempts,
                'total_count' => $counts['total'],
                'success_count' => $counts['success'],
                'already_count' => $counts['already'],
                'skipped_count' => $counts['skipped'],
                'failed_count' => $counts['failed'],
                'summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'last_error_code' => $retryFailure['code'] ?? null,
                'last_error_message' => isset($retryFailure['message'])
                    ? mb_substr((new SensitiveDataRedactor())->redactString($retryFailure['message']), 0, 500)
                    : null,
                'available_at' => $availableAt,
                'locked_at' => null,
                'locked_by' => null,
                'finished_at' => $taskStatus === 'retrying' ? null : $finishedAt,
                'updated_at' => $finishedAt,
            ]);
            $accountUpdates = [
                'last_run_at' => $finishedAt,
                'last_error_code' => $retryFailure['code'] ?? ($runStatus === 'failed' ? 'PLUGIN_EXECUTION_FAILED' : null),
                'last_error_message' => isset($retryFailure['message'])
                    ? mb_substr((new SensitiveDataRedactor())->redactString($retryFailure['message']), 0, 500)
                    : null,
                'updated_at' => $finishedAt,
            ];
            if (is_array($result->profilePatch) && $result->profilePatch !== []) {
                $account = Db::table('TF_plugin_accounts')->where('id', $task->account_id)->first();
                $profile = json_decode((string)($account->profile_json ?? '{}'), true) ?: [];
                foreach ($result->profilePatch as $key => $value) {
                    $profile[$key] = $value;
                }
                $accountUpdates['profile_json'] = json_encode(
                    $profile,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }
            Db::table('TF_plugin_accounts')->where('id', $task->account_id)->update($accountUpdates);
        });
    }

    private function fail(object $task, int $runId, Throwable $exception, float $started): void
    {
        $finishedAt = date('Y-m-d H:i:s');
        $duration = (int)round((microtime(true) - $started) * 1000);
        $errorCode = $exception instanceof ApiException ? $exception->errorCode : 'PLUGIN_EXECUTION_FAILED';
        $message = mb_substr((new SensitiveDataRedactor())->redactString($exception->getMessage()), 0, 500);
        $credentialExpired = in_array($errorCode, ['PLUGIN_CREDENTIAL_EXPIRED', 'PLUGIN_CREDENTIAL_INVALID'], true);
        $retryPolicy = new SignRetryPolicy();
        $continuousRetry = $retryPolicy->retriesUntilSuccess((string)$task->plugin_code, $errorCode);
        $ordinaryRetry = (string)$task->plugin_code !== 'tieba'
            && (int)$task->attempts < (int)$task->max_attempts;
        $retryable = !$credentialExpired && ($continuousRetry || $ordinaryRetry);
        $nextStatus = $retryable ? 'retrying' : 'failed';
        $availableAt = $retryable
            ? date('Y-m-d H:i:s', time() + $retryPolicy->retryDelaySeconds((int)$task->attempts))
            : null;
        $maxAttempts = $continuousRetry
            ? $retryPolicy->extendedAttemptLimit((int)$task->attempts, (int)$task->max_attempts)
            : (int)$task->max_attempts;

        Db::transaction(function () use (
            $task, $runId, $exception, $errorCode, $message, $finishedAt, $duration,
            $nextStatus, $availableAt, $credentialExpired, $maxAttempts
        ): void {
            Db::table('TF_sign_runs')->where('id', $runId)->update([
                'status' => 'failed',
                'failed_count' => 1,
                'error_code' => $errorCode,
                'error_message' => $message,
                'exception_class' => $exception::class,
                'finished_at' => $finishedAt,
                'duration_ms' => $duration,
            ]);
            Db::table('TF_sign_tasks')->where('id', $task->id)->update([
                'status' => $nextStatus,
                'max_attempts' => $maxAttempts,
                'failed_count' => 1,
                'last_error_code' => $errorCode,
                'last_error_message' => $message,
                'available_at' => $availableAt,
                'locked_at' => null,
                'locked_by' => null,
                'finished_at' => $nextStatus === 'failed' ? $finishedAt : null,
                'updated_at' => $finishedAt,
            ]);
            $accountUpdates = [
                'last_run_at' => $finishedAt,
                'last_error_code' => $errorCode,
                'last_error_message' => $message,
                'updated_at' => $finishedAt,
            ];
            if ($credentialExpired) {
                $accountUpdates['status'] = 'credential_expired';
                $accountUpdates['next_run_at'] = null;
            }
            Db::table('TF_plugin_accounts')->where('id', $task->account_id)->update($accountUpdates);
        });

        // 通知在事务外发送；NotificationMailService 内部有事件幂等和异常兜底。
        if ($credentialExpired) {
            $account = Db::table('TF_plugin_accounts')->where('id', $task->account_id)->first();
            (new NotificationMailService())->credentialExpired(
                (int)$task->user_id,
                (int)$task->account_id,
                (new PluginRegistry())->get((string)$task->plugin_code)->metadata()->name,
                (string)($account?->display_name ?? ''),
                $message
            );
        } elseif ($nextStatus === 'failed') {
            (new NotificationMailService())->taskFailed(
                (int)$task->user_id,
                (string)$task->task_no,
                (string)$task->plugin_code,
                (string)$task->action,
                $message
            );
        }
    }

    private function saveRecord(
        object $task,
        int $runId,
        SignRecord $record,
        ?int $parentId,
        int &$sequence,
        array &$counts
    ): void {
        $sequence++;
        $counts['total']++;
        $bucket = match ($record->status) {
            'succeeded' => 'success',
            'already_done' => 'already',
            'skipped' => 'skipped',
            default => 'failed',
        };
        $counts[$bucket]++;
        $redactor = new SensitiveDataRedactor();
        $recordId = (int)Db::table('TF_sign_records')->insertGetId([
            'task_id' => $task->id,
            'run_id' => $runId,
            'parent_record_id' => $parentId,
            'user_id' => $task->user_id,
            'account_id' => $task->account_id,
            'plugin_code' => $task->plugin_code,
            'record_key' => $record->key,
            'sequence_no' => $sequence,
            'schema_version' => 1,
            'action' => $record->action,
            'target_type' => $record->targetType,
            'target_id' => $record->targetId,
            'target_name' => $record->targetName,
            'status' => $record->status,
            'result_code' => $record->code,
            'message' => mb_substr($redactor->redactString((string)$record->message), 0, 500),
            'reward_json' => json_encode($redactor->redact($record->rewards), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'metrics_json' => json_encode($redactor->redact($record->metrics), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'safe_result_json' => json_encode($redactor->redact($record->safeResult), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        foreach ($record->children as $child) {
            if ($child instanceof SignRecord) {
                $this->saveRecord($task, $runId, $child, $recordId, $sequence, $counts);
            }
        }
    }
}
