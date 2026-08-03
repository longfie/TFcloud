<?php

namespace app\service;

use app\exception\ApiException;
use PHPMailer\PHPMailer\PHPMailer;
use support\Db;

final class MailService
{
    public function summary(): array
    {
        return [
            'tasks' => (int)Db::table('TF_mail_tasks')->count(),
            'pending' => (int)Db::table('TF_mail_records')->whereIn('status', ['pending', 'sending'])->count(),
            'sent' => (int)Db::table('TF_mail_records')->where('status', 'sent')->count(),
            'failed' => (int)Db::table('TF_mail_records')->where('status', 'failed')->count(),
            'today_sent' => (int)Db::table('TF_mail_records')->where('status', 'sent')->where('sent_at', '>=', date('Y-m-d 00:00:00'))->count(),
        ];
    }

    public function tasks(array $filters): array
    {
        [$limit, $offset] = $this->pagination($filters);
        $query = Db::table('TF_mail_tasks as t')->leftJoin('TF_users as u', 'u.id', '=', 't.user_id')
            ->select('t.*', 'u.username');
        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '') $query->where('t.status', $status);
        $total = (int)(clone $query)->count('t.id');
        $items = $query->orderByDesc('t.id')->offset($offset)->limit($limit)->get()->map(static fn ($row): array => [
            'id' => (int)$row->id,
            'task_no' => (string)$row->task_no,
            'user_id' => $row->user_id ? (int)$row->user_id : null,
            'username' => $row->username,
            'template_code' => (string)$row->template_code,
            'subject' => (string)$row->subject,
            'status' => (string)$row->status,
            'total_count' => (int)$row->total_count,
            'success_count' => (int)$row->success_count,
            'failed_count' => (int)$row->failed_count,
            'scheduled_at' => $row->scheduled_at,
            'started_at' => $row->started_at,
            'finished_at' => $row->finished_at,
            'created_at' => $row->created_at,
        ])->all();
        return compact('items', 'total', 'limit', 'offset');
    }

    public function records(string $taskNo): array
    {
        $task = Db::table('TF_mail_tasks')->where('task_no', $taskNo)->first();
        if (!$task) throw new ApiException('MAIL_TASK_NOT_FOUND', '邮件任务不存在', 404);
        return Db::table('TF_mail_records')->where('task_id', $task->id)->orderBy('id')->get()->map(static fn ($row): array => [
            'id' => (int)$row->id,
            'message_no' => (string)$row->message_no,
            'recipient' => (string)$row->recipient,
            'status' => (string)$row->status,
            'attempts' => (int)$row->attempts,
            'last_error_code' => $row->last_error_code,
            'last_error_message' => $row->last_error_message,
            'sent_at' => $row->sent_at,
            'created_at' => $row->created_at,
        ])->all();
    }

    public function allRecords(array $filters): array
    {
        [$limit, $offset] = $this->pagination($filters);
        $query = Db::table('TF_mail_records as r')
            ->leftJoin('TF_mail_tasks as t', 't.id', '=', 'r.task_id')
            ->leftJoin('TF_users as u', 'u.id', '=', 't.user_id')
            ->select('r.*', 't.task_no', 't.template_code', 't.subject', 'u.username');
        $status = trim((string)($filters['status'] ?? ''));
        $templateCode = trim((string)($filters['template_code'] ?? ''));
        $keyword = mb_substr(trim((string)($filters['keyword'] ?? '')), 0, 191);
        if ($status !== '') $query->where('r.status', $status);
        if ($templateCode !== '') $query->where('t.template_code', $templateCode);
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword): void {
                $builder->where('r.recipient', 'like', '%' . $keyword . '%')
                    ->orWhere('t.subject', 'like', '%' . $keyword . '%')
                    ->orWhere('u.username', 'like', '%' . $keyword . '%');
            });
        }
        $total = (int)(clone $query)->count('r.id');
        $items = $query->orderByDesc('r.id')->offset($offset)->limit($limit)->get()->map(static fn ($row): array => [
            'id' => (int)$row->id,
            'message_no' => (string)$row->message_no,
            'task_no' => $row->task_no,
            'template_code' => $row->template_code,
            'subject' => $row->subject,
            'username' => $row->username,
            'recipient' => (string)$row->recipient,
            'status' => (string)$row->status,
            'attempts' => (int)$row->attempts,
            'provider_message_id' => $row->provider_message_id,
            'last_error_code' => $row->last_error_code,
            'last_error_message' => $row->last_error_message,
            'sent_at' => $row->sent_at,
            'created_at' => $row->created_at,
        ])->all();
        return compact('items', 'total', 'limit', 'offset');
    }

    public function deleteRecord(int $recordId): void
    {
        $record = Db::table('TF_mail_records')->where('id', $recordId)->first();
        if (!$record) throw new ApiException('MAIL_RECORD_NOT_FOUND', '邮件发送记录不存在', 404);
        if ($record->status === 'sending') throw new ApiException('MAIL_RECORD_SENDING', '正在发送的邮件不能删除', 409);
        Db::transaction(function () use ($record): void {
            Db::table('TF_mail_records')->where('id', $record->id)->delete();
            if (!$record->task_id) return;
            $taskId = (int)$record->task_id;
            $remaining = (int)Db::table('TF_mail_records')->where('task_id', $taskId)->count();
            if ($remaining === 0) {
                Db::table('TF_notification_events')->where('mail_task_id', $taskId)->update([
                    'status' => 'deleted',
                    'mail_task_id' => null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                Db::table('TF_mail_tasks')->where('id', $taskId)->delete();
                return;
            }
            $success = (int)Db::table('TF_mail_records')->where('task_id', $taskId)->where('status', 'sent')->count();
            $failed = (int)Db::table('TF_mail_records')->where('task_id', $taskId)->where('status', 'failed')->count();
            Db::table('TF_mail_tasks')->where('id', $taskId)->update([
                'total_count' => $remaining,
                'success_count' => $success,
                'failed_count' => $failed,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        });
    }

    public function compose(array $input, int $adminId): array
    {
        $subject = mb_substr(trim((string)($input['subject'] ?? '')), 0, 255);
        $html = trim((string)($input['html_body'] ?? ''));
        $text = trim((string)($input['text_body'] ?? ''));
        if ($subject === '' || ($html === '' && $text === '')) throw new ApiException('VALIDATION_FAILED', '邮件主题和正文不能为空', 422);
        $config = (new SettingsService())->group('mail', true);
        if (!$config['enabled']) throw new ApiException('MAIL_DISABLED', '请先启用邮件服务', 409);
        $recipients = $this->resolveRecipients($input, (int)$config['batch_limit']);
        if ($recipients === []) throw new ApiException('MAIL_RECIPIENT_EMPTY', '没有有效收件人', 422);

        $taskNo = bin2hex(random_bytes(16));
        $now = date('Y-m-d H:i:s');
        $taskId = Db::transaction(function () use ($taskNo, $adminId, $subject, $html, $text, $recipients, $now): int {
            $taskId = (int)Db::table('TF_mail_tasks')->insertGetId([
                'task_no' => $taskNo, 'user_id' => $adminId, 'template_code' => 'admin_custom', 'subject' => $subject,
                'context_json' => json_encode(['html_body' => $html, 'text_body' => $text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'status' => 'pending', 'priority' => 0, 'total_count' => count($recipients), 'success_count' => 0, 'failed_count' => 0,
                'scheduled_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($recipients as $recipient) {
                Db::table('TF_mail_records')->insert([
                    'task_id' => $taskId, 'message_no' => bin2hex(random_bytes(16)), 'recipient' => $recipient,
                    'recipient_hash' => hash('sha256', mb_strtolower($recipient)), 'status' => 'pending', 'attempts' => 0,
                    'max_attempts' => 3, 'available_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            return $taskId;
        });
        return $this->task($taskNo);
    }

    public function retry(string $taskNo): array
    {
        $task = Db::table('TF_mail_tasks')->where('task_no', $taskNo)->first();
        if (!$task) throw new ApiException('MAIL_TASK_NOT_FOUND', '邮件任务不存在', 404);
        $now = date('Y-m-d H:i:s');
        Db::table('TF_mail_records')->where('task_id', $task->id)->where('status', 'failed')->whereColumn('attempts', '<', 'max_attempts')
            ->update(['status' => 'pending', 'available_at' => $now, 'updated_at' => $now]);
        Db::table('TF_mail_tasks')->where('id', $task->id)->update(['status' => 'pending', 'finished_at' => null, 'updated_at' => $now]);
        return $this->task($taskNo);
    }

    public function processPending(): bool
    {
        $task = Db::table('TF_mail_tasks')->whereIn('status', ['pending', 'running'])->orderBy('id')->first();
        if (!$task) return false;
        $config = (new SettingsService())->group('mail', true);
        if (!$config['enabled']) return false;
        $this->processTask((int)$task->id, $config);
        return true;
    }

    public function recoverStale(): void
    {
        $threshold = date('Y-m-d H:i:s', time() - 600);
        $now = date('Y-m-d H:i:s');
        Db::table('TF_mail_records')->where('status', 'sending')->where('updated_at', '<', $threshold)
            ->update(['status' => 'pending', 'available_at' => $now, 'updated_at' => $now]);
        Db::table('TF_mail_tasks')->where('status', 'running')->where('updated_at', '<', $threshold)
            ->update(['status' => 'pending', 'updated_at' => $now]);
    }

    public function test(string $recipient): void
    {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) throw new ApiException('VALIDATION_FAILED', '测试邮箱格式不正确', 422);
        $template = (new EmailTemplateService())->notification(
            '邮件服务连接成功',
            'SMTP 配置测试成功',
            '管理员',
            '这是一封由管理后台发出的测试邮件。你能看到它，说明 SMTP 配置与品牌化 HTML 模板均工作正常。',
            ['检测时间' => date('Y-m-d H:i:s'), '发送状态' => '连接成功'],
            '#22c55e',
            '返回管理后台'
        );
        $this->sendTransactional($recipient, '邮件服务连接成功', $template['html'], $template['text'], 'mail_test');
    }

    public function sendTransactional(
        string $recipient,
        string $subject,
        string $html,
        string $text,
        string $templateCode = 'transactional',
        ?int $userId = null
    ): void
    {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException('VALIDATION_FAILED', '收件邮箱格式不正确', 422);
        }
        $config = (new SettingsService())->group('mail', true);
        if (!$config['enabled']) {
            throw new ApiException('MAIL_DISABLED', '邮件服务尚未启用，请联系管理员', 409);
        }
        $taskNo = bin2hex(random_bytes(16));
        $messageNo = bin2hex(random_bytes(16));
        $now = date('Y-m-d H:i:s');
        $taskId = (int)Db::table('TF_mail_tasks')->insertGetId([
            'task_no' => $taskNo,
            'user_id' => $userId,
            'template_code' => mb_substr($templateCode, 0, 64),
            'subject' => mb_substr($subject, 0, 255),
            'context_json' => null,
            'status' => 'running',
            'priority' => 20,
            'total_count' => 1,
            'success_count' => 0,
            'failed_count' => 0,
            'scheduled_at' => $now,
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $recordId = (int)Db::table('TF_mail_records')->insertGetId([
            'task_id' => $taskId,
            'message_no' => $messageNo,
            'recipient' => mb_strtolower($recipient),
            'recipient_hash' => hash('sha256', mb_strtolower($recipient)),
            'status' => 'sending',
            'attempts' => 1,
            'max_attempts' => 1,
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        try {
            $messageId = $this->sendOne($config, $recipient, $subject, $html, $text);
            Db::table('TF_mail_records')->where('id', $recordId)->update([
                'status' => 'sent',
                'provider_message_id' => $messageId,
                'sent_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            Db::table('TF_mail_tasks')->where('id', $taskId)->update([
                'status' => 'succeeded',
                'success_count' => 1,
                'finished_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $exception) {
            Db::table('TF_mail_records')->where('id', $recordId)->update([
                'status' => 'failed',
                'last_error_code' => 'SMTP_SEND_FAILED',
                'last_error_message' => mb_substr($exception->getMessage(), 0, 500),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            Db::table('TF_mail_tasks')->where('id', $taskId)->update([
                'status' => 'failed',
                'failed_count' => 1,
                'finished_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            throw $exception;
        }
    }

    public function queueSystem(
        int $userId,
        string $recipient,
        string $templateCode,
        string $subject,
        string $html,
        string $text
    ): array {
        $now = date('Y-m-d H:i:s');
        $taskNo = bin2hex(random_bytes(16));
        $taskId = (int)Db::table('TF_mail_tasks')->insertGetId([
            'task_no' => $taskNo,
            'user_id' => $userId,
            'template_code' => mb_substr($templateCode, 0, 64),
            'subject' => mb_substr($subject, 0, 255),
            'context_json' => json_encode(['html_body' => $html, 'text_body' => $text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'priority' => 10,
            'total_count' => 1,
            'success_count' => 0,
            'failed_count' => 0,
            'scheduled_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::table('TF_mail_records')->insert([
            'task_id' => $taskId,
            'message_no' => bin2hex(random_bytes(16)),
            'recipient' => mb_strtolower($recipient),
            'recipient_hash' => hash('sha256', mb_strtolower($recipient)),
            'status' => 'pending',
            'attempts' => 0,
            'max_attempts' => 3,
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return ['id' => $taskId, 'task_no' => $taskNo];
    }

    private function processTask(int $taskId, array $config): void
    {
        $task = Db::table('TF_mail_tasks')->where('id', $taskId)->first();
        if (!$task) return;
        $context = json_decode((string)($task->context_json ?? '{}'), true) ?: [];
        $now = date('Y-m-d H:i:s');
        Db::table('TF_mail_tasks')->where('id', $taskId)->update(['status' => 'running', 'started_at' => $task->started_at ?: $now, 'updated_at' => $now]);
        $records = Db::table('TF_mail_records')->where('task_id', $taskId)->where('status', 'pending')->orderBy('id')->get();
        foreach ($records as $record) {
            $attempts = (int)$record->attempts + 1;
            try {
                Db::table('TF_mail_records')->where('id', $record->id)->update(['status' => 'sending', 'attempts' => $attempts, 'updated_at' => date('Y-m-d H:i:s')]);
                $html = (string)($context['html_body'] ?? '');
                $text = (string)($context['text_body'] ?? '');
                if ($task->template_code === 'admin_custom') {
                    $username = (string)(Db::table('TF_users')->where('email', $record->recipient)->value('username') ?: '用户');
                    $rendered = (new EmailTemplateService())->custom((string)$task->subject, $html, $text, $username);
                    $html = $rendered['html'];
                    $text = $rendered['text'];
                }
                $messageId = $this->sendOne($config, (string)$record->recipient, (string)$task->subject, $html, $text);
                Db::table('TF_mail_records')->where('id', $record->id)->update(['status' => 'sent', 'provider_message_id' => $messageId, 'sent_at' => date('Y-m-d H:i:s'), 'last_error_code' => null, 'last_error_message' => null, 'updated_at' => date('Y-m-d H:i:s')]);
            } catch (\Throwable $exception) {
                Db::table('TF_mail_records')->where('id', $record->id)->update(['status' => 'failed', 'last_error_code' => 'SMTP_SEND_FAILED', 'last_error_message' => mb_substr($exception->getMessage(), 0, 500), 'updated_at' => date('Y-m-d H:i:s')]);
            }
        }
        $success = (int)Db::table('TF_mail_records')->where('task_id', $taskId)->where('status', 'sent')->count();
        $failed = (int)Db::table('TF_mail_records')->where('task_id', $taskId)->where('status', 'failed')->count();
        $total = (int)$task->total_count;
        $status = $success === $total ? 'succeeded' : ($success > 0 ? 'partial' : 'failed');
        Db::table('TF_mail_tasks')->where('id', $taskId)->update(['status' => $status, 'success_count' => $success, 'failed_count' => $failed, 'finished_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    }

    private function sendOne(array $config, string $recipient, string $subject, string $html, string $text): string
    {
        if (trim((string)$config['smtp_host']) === '' || trim((string)$config['from_email']) === '') throw new ApiException('MAIL_CONFIG_INCOMPLETE', 'SMTP 主机和发件邮箱尚未配置', 409);
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->CharSet = 'UTF-8';
        $mail->Host = (string)$config['smtp_host'];
        $mail->Port = (int)$config['smtp_port'];
        $mail->SMTPAuth = trim((string)$config['smtp_username']) !== '';
        $mail->Username = (string)$config['smtp_username'];
        $mail->Password = (string)$config['smtp_password'];
        $mail->SMTPAutoTLS = false;
        if ($config['smtp_encryption'] === 'ssl') $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        if ($config['smtp_encryption'] === 'tls') $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->setFrom((string)$config['from_email'], (string)$config['from_name']);
        if (trim((string)$config['reply_to']) !== '') $mail->addReplyTo((string)$config['reply_to']);
        $mail->addAddress($recipient);
        $mail->Subject = $subject;
        if ($html !== '') {
            $mail->isHTML(true); $mail->Body = $html; $mail->AltBody = $text !== '' ? $text : trim(strip_tags($html));
        } else {
            $mail->isHTML(false); $mail->Body = $text;
        }
        $mail->send();
        return trim($mail->getLastMessageID(), '<>');
    }

    private function resolveRecipients(array $input, int $limit): array
    {
        $type = (string)($input['recipient_type'] ?? 'emails');
        if ($type === 'all_active') {
            $emails = Db::table('TF_users')->where('status', 'active')->whereNotNull('email')->where('email', '<>', '')->limit($limit + 1)->pluck('email')->all();
        } elseif ($type === 'user_ids') {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($input['user_ids'] ?? [])))));
            $emails = $ids ? Db::table('TF_users')->whereIn('id', $ids)->whereNotNull('email')->pluck('email')->all() : [];
        } else {
            $raw = is_array($input['emails'] ?? null) ? $input['emails'] : preg_split('/[,;\s]+/', (string)($input['emails'] ?? ''));
            $emails = array_values(array_filter(array_map('trim', $raw ?: []), static fn ($email): bool => (bool)filter_var($email, FILTER_VALIDATE_EMAIL)));
        }
        $emails = array_values(array_unique(array_map('mb_strtolower', $emails)));
        if (count($emails) > $limit) throw new ApiException('MAIL_BATCH_LIMIT_EXCEEDED', "单次最多发送 {$limit} 封邮件", 422);
        return $emails;
    }

    private function task(string $taskNo): array
    {
        $row = Db::table('TF_mail_tasks')->where('task_no', $taskNo)->first();
        return ['task_no' => $row->task_no, 'subject' => $row->subject, 'status' => $row->status, 'total_count' => (int)$row->total_count, 'success_count' => (int)$row->success_count, 'failed_count' => (int)$row->failed_count, 'created_at' => $row->created_at, 'finished_at' => $row->finished_at];
    }

    private function pagination(array $filters): array { return [max(1, min(100, (int)($filters['limit'] ?? 20))), max(0, (int)($filters['offset'] ?? 0))]; }
}
