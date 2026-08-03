<?php

namespace app\service;

use app\sign\executor\SensitiveDataRedactor;
use support\Db;
use support\Log;

final class AuditService
{
    public function record(
        ?int $userId,
        string $action,
        ?string $resourceType = null,
        string|int|null $resourceId = null,
        array $context = []
    ): void {
        try {
            $requestId = null;
            $ip = null;
            try {
                $request = request();
                if ($request instanceof \support\Request) {
                    $requestId = $request->requestId() ?: null;
                    $ip = mb_substr($request->getRealIp(), 0, 64);
                }
            } catch (\Throwable) {
            }

            Db::table('TF_audit_logs')->insert([
                'request_id' => $requestId,
                'user_id' => $userId,
                'action' => mb_substr($action, 0, 128),
                'resource_type' => $resourceType ? mb_substr($resourceType, 0, 64) : null,
                'resource_id' => $resourceId !== null ? mb_substr((string)$resourceId, 0, 191) : null,
                'ip_address' => $ip,
                'context_json' => json_encode(
                    (new SensitiveDataRedactor())->redact($context),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('audit log write failed', ['exception' => $exception::class]);
        }
    }
}
