<?php

namespace app\service;

use support\Db;
use support\Log;

final class NotificationMailService
{
    public function credentialExpired(int $userId, int $accountId, string $platform, string $accountName, string $message): void
    {
        $credentialVersion = (string)(Db::table('TF_plugin_credentials')
            ->where('account_id', $accountId)
            ->orderByDesc('id')
            ->value('updated_at') ?: 'initial');
        $this->safelyQueue(
            $userId,
            'credential_expired',
            "credential_expired:{$accountId}:" . substr(hash('sha256', $credentialVersion), 0, 20),
            '平台账号登录状态已失效',
            '平台账号登录状态已失效，自动签到已暂停。点击下方按钮可直接进入更新凭据页面，重新登录后会自动恢复签到。',
            [
                '平台' => $platform,
                '账号' => $accountName,
                '状态' => '已失效',
                '原因' => $message ?: '登录凭据无效或已过期',
            ],
            '#f59e0b',
            '一键更新凭据',
            $this->frontendUrl('/TFYT/accounts?renew=' . $accountId)
        );
    }

    public function taskFailed(
        int $userId,
        string $taskNo,
        string $pluginCode,
        string $action,
        string $message
    ): void {
        $this->safelyQueue(
            $userId,
            'task_failed',
            'task_failed:' . $taskNo,
            '签到任务执行失败',
            '有一个签到任务在多次自动重试后仍然失败，请查看详情并根据原因处理。',
            [
                '平台' => $this->platformName($pluginCode),
                '动作' => $action,
                '失败原因' => $message !== '' ? $message : '执行失败',
                '发生时间' => date('Y-m-d H:i:s'),
            ],
            '#ef4444',
            '查看任务详情',
            $this->frontendUrl('/TFYT/tasks/' . $taskNo)
        );
    }

    public function dispatchScheduled(): void
    {
        $hour = (int)date('G');
        $settings = (new SettingsService())->group('mail');
        if ($hour >= (int)$settings['daily_summary_hour']) {
            $this->dispatchDailySummaries(date('Y-m-d'));
        }
    }

    private function dispatchDailySummaries(string $date): void
    {
        $users = Db::table('TF_users')
            ->where('status', 'active')
            ->where('daily_sign_email', 1)
            ->whereNotNull('email')
            ->where('email', '<>', '')
            ->orderBy('id')
            ->limit(1000)
            ->get(['id']);
        foreach ($users as $user) {
            $userId = (int)$user->id;
            $tasks = Db::table('TF_sign_tasks')
                ->where('user_id', $userId)
                ->where('schedule_date', $date)
                ->get(['status', 'plugin_code', 'success_count', 'already_count', 'failed_count']);
            $total = $tasks->count();
            $completed = $tasks->whereIn('status', ['succeeded', 'partial'])->count();
            $failed = $tasks->where('status', 'failed')->count();
            $pending = max(0, $total - $completed - $failed);
            $platforms = $tasks->pluck('plugin_code')->unique()->filter()
                ->map(fn ($code) => $this->platformName((string)$code))
                ->implode('、');
            $this->safelyQueue(
                $userId,
                'daily_sign_summary',
                "daily_sign_summary:{$userId}:{$date}",
                $date . ' 签到状态汇总',
                $total > 0 ? '今日的自动签到任务状态已经整理完成。' : '今日没有产生签到任务。',
                [
                    '统计日期' => $date,
                    '任务总数' => $total . ' 个',
                    '任务完成' => $completed . ' 个',
                    '任务失败' => $failed . ' 个',
                    '等待执行' => $pending . ' 个',
                    '涉及平台' => $platforms !== '' ? $platforms : '暂无',
                ],
                $failed > 0 ? '#f59e0b' : '#6366f1',
                '查看签到任务',
                $this->frontendUrl('/TFYT/tasks')
            );
        }
    }

    private function safelyQueue(
        int $userId,
        string $eventType,
        string $eventKey,
        string $title,
        string $message,
        array $facts,
        string $accent,
        string $actionLabel,
        ?string $actionUrl = null
    ): void {
        try {
            $user = Db::table('TF_users')->where('id', $userId)->first();
            if (!$user) return;
            $now = date('Y-m-d H:i:s');
            $inserted = false;
            Db::transaction(function () use (
                $user, $eventType, $eventKey, $title, $message, $facts, $accent, $actionLabel, $actionUrl, $now, &$inserted
            ): void {
                $inserted = (bool)Db::table('TF_notification_events')->insertOrIgnore([
                    'user_id' => $user->id,
                    'mail_task_id' => null,
                    'event_type' => $eventType,
                    'event_key' => $eventKey,
                    'status' => 'queued',
                    'payload_json' => json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                if (!$inserted) return;
                $email = trim((string)$user->email);
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    Db::table('TF_notification_events')->where('event_key', $eventKey)->update([
                        'status' => 'skipped',
                        'updated_at' => $now,
                    ]);
                    return;
                }
                $template = (new EmailTemplateService())->notification(
                    $title,
                    $message,
                    (string)$user->username,
                    $message,
                    $facts,
                    $accent,
                    $actionLabel,
                    $actionUrl
                );
                $task = (new MailService())->queueSystem(
                    (int)$user->id,
                    $email,
                    $eventType,
                    $title,
                    $template['html'],
                    $template['text']
                );
                Db::table('TF_notification_events')->where('event_key', $eventKey)->update([
                    'mail_task_id' => $task['id'],
                    'updated_at' => $now,
                ]);
            });
            // 即时推送在事务外发送，避免外部 HTTP 阻塞数据库事务。
            if ($inserted) {
                (new PushNotificationService())->sendToUser($user, $title, $message, $facts, $actionUrl);
            }
        } catch (\Throwable $exception) {
            Log::warning('notification email queue failed', [
                'event_type' => $eventType,
                'user_id' => $userId,
                'exception' => $exception::class,
            ]);
        }
    }

    private function frontendUrl(string $path): ?string
    {
        $base = rtrim(trim((string)((new SettingsService())->group('site')['base_url'] ?? '')), '/');
        return $base !== '' ? $base . $path : null;
    }

    private function platformName(string $code): string
    {
        return match ($code) {
            'tieba' => '百度贴吧',
            'bilibili' => '哔哩哔哩',
            'netease' => '网易云音乐',
            'iqiyi' => '爱奇艺',
            'sport' => '小米运动',
            'cloud189' => '天翼云盘',
            'picacomic' => '哔咔漫画',
            default => $code,
        };
    }
}
