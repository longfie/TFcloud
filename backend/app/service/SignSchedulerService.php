<?php

namespace app\service;

use app\sign\executor\SensitiveDataRedactor;
use app\sign\registry\PluginRegistry;
use app\sign\schedule\DailySchedule;
use support\Db;
use support\Log;
use Throwable;

final class SignSchedulerService
{
    public function dispatchDue(int $limit = 100): int
    {
        $accounts = Db::table('TF_plugin_accounts as a')
            ->join('TF_users as u', 'u.id', '=', 'a.user_id')
            ->whereIn('a.status', ['active', 'pending_verification'])
            ->whereNull('a.deleted_at')
            ->whereNotNull('a.next_run_at')
            ->where('a.next_run_at', '<=', date('Y-m-d H:i:s'))
            ->orderBy('a.next_run_at')
            ->orderBy('a.id')
            ->limit(max(1, min(500, $limit)))
            ->select('a.*')
            ->get();

        $dispatched = 0;
        foreach ($accounts as $account) {
            try {
                if ($this->dispatchAccount($account)) {
                    $dispatched++;
                }
            } catch (Throwable $exception) {
                $this->recordDispatchFailure($account, $exception);
            }
        }

        return $dispatched;
    }

    private function dispatchAccount(object $account): bool
    {
        $plugin = (new PluginRegistry())->get((string)$account->plugin_code);
        if ($plugin->metadata()->implementationStatus !== 'ready') {
            Db::table('TF_plugin_accounts')->where('id', $account->id)->update([
                'next_run_at' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return false;
        }

        $settings = DailySchedule::normalizeLegacy(
            json_decode((string)($account->settings_json ?? '{}'), true) ?: [],
            $account->next_run_at !== null ? (string)$account->next_run_at : null
        );
        // 在创建任务前先计算下次时间，避免异常配置留下半批任务并卡住队首。
        $nextRunAt = DailySchedule::next($settings, $this->seed($account));
        $action = trim((string)($settings['scheduled_action'] ?? ($plugin->supportedActions()[0] ?? '')));
        (new SignTaskService())->create(
            (int)$account->user_id,
            (int)$account->id,
            $action,
            'schedule',
            date('Y-m-d')
        );

        Db::table('TF_plugin_accounts')->where('id', $account->id)->update([
            'next_run_at' => $nextRunAt,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return true;
    }

    private function recordDispatchFailure(object $account, Throwable $exception): void
    {
        $message = (new SensitiveDataRedactor())->redactString($exception->getMessage());
        Log::error('account schedule dispatch failed', [
            'account_id' => (int)$account->id,
            'plugin_code' => (string)$account->plugin_code,
            'exception' => $exception::class,
            'message' => $message,
        ]);
        try {
            Db::table('TF_plugin_accounts')->where('id', $account->id)->update([
                'next_run_at' => date('Y-m-d H:i:s', time() + 300),
                'last_error_code' => 'SCHEDULE_DISPATCH_FAILED',
                'last_error_message' => mb_substr($message !== '' ? $message : '自动任务创建失败', 0, 500),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $updateException) {
            Log::error('account schedule failure state update failed', [
                'account_id' => (int)$account->id,
                'exception' => $updateException::class,
            ]);
        }
    }

    /**
     * 晚间兜底补签：当天自动重试耗尽仍失败（非凭据问题）的任务，
     * 每天 20 点后追加一轮重试机会，减少用户手动干预。
     * 通过 max_attempts 是否等于默认值保证每个任务只补一次。
     */
    public function requeueFailedToday(int $limit = 200): int
    {
        if ((int)date('G') < 20) {
            return 0;
        }
        $now = date('Y-m-d H:i:s');
        $tasks = Db::table('TF_sign_tasks as t')
            ->join('TF_plugin_accounts as a', 'a.id', '=', 't.account_id')
            ->join('TF_users as u', 'u.id', '=', 't.user_id')
            ->where('t.schedule_date', date('Y-m-d'))
            ->where('t.status', 'failed')
            ->where('t.max_attempts', SignTaskService::MAX_ATTEMPTS)
            ->whereColumn('t.attempts', '>=', 't.max_attempts')
            ->whereNotIn('t.last_error_code', ['PLUGIN_CREDENTIAL_EXPIRED', 'PLUGIN_CREDENTIAL_INVALID'])
            ->whereIn('a.status', ['active', 'pending_verification'])
            ->whereNull('a.deleted_at')
            ->orderBy('t.id')
            ->limit(max(1, min(500, $limit)))
            ->get(['t.id', 't.attempts']);

        $requeued = 0;
        foreach ($tasks as $task) {
            $requeued += (int)Db::table('TF_sign_tasks')
                ->where('id', $task->id)
                ->where('status', 'failed')
                ->update([
                    'status' => 'pending',
                    'max_attempts' => (int)$task->attempts + 3,
                    'available_at' => $now,
                    'finished_at' => null,
                    'locked_at' => null,
                    'locked_by' => null,
                    'updated_at' => $now,
                ]);
        }
        return $requeued;
    }

    private function seed(object $account): string
    {
        return $account->plugin_code . ':' . $account->user_id . ':' . $account->id;
    }
}
