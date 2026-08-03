<?php

namespace app\controller\Api;

use app\http\ApiResponse;
use app\service\AuditService;
use app\service\MailService;
use support\Request;
use support\Response;

final class AdminMailController
{
    public function summary(): Response { return ApiResponse::success((new MailService())->summary()); }
    public function tasks(Request $request): Response { return ApiResponse::success((new MailService())->tasks($request->get())); }
    public function records(string $taskNo): Response { return ApiResponse::success((new MailService())->records($taskNo)); }
    public function allRecords(Request $request): Response { return ApiResponse::success((new MailService())->allRecords($request->get())); }

    public function deleteRecord(Request $request, int $id): Response
    {
        (new MailService())->deleteRecord($id);
        (new AuditService())->record($request->userId(), 'admin.mail.record.delete', 'mail_record', $id);
        return ApiResponse::success(null, '邮件发送记录已删除');
    }

    public function compose(Request $request): Response
    {
        $result = (new MailService())->compose($request->all(), (int)$request->userId());
        (new AuditService())->record($request->userId(), 'admin.mail.compose', 'mail_task', $result['task_no'], ['total_count' => $result['total_count'], 'subject' => $result['subject']]);
        return ApiResponse::created($result, '邮件任务已执行');
    }

    public function retry(Request $request, string $taskNo): Response
    {
        $result = (new MailService())->retry($taskNo);
        (new AuditService())->record($request->userId(), 'admin.mail.retry', 'mail_task', $taskNo);
        return ApiResponse::success($result, '失败邮件已重试');
    }

    public function test(Request $request): Response
    {
        (new MailService())->test((string)$request->post('recipient', ''));
        (new AuditService())->record($request->userId(), 'admin.mail.test', 'mail', null, ['recipient' => $request->post('recipient', '')]);
        return ApiResponse::success(null, '测试邮件已发送');
    }
}
