<?php

namespace app\sign\executor;

use app\sign\dto\SignRecord;
use app\sign\dto\SignResult;

final class SignRetryPolicy
{
    /** 仅精确匹配统一 HTTP 客户端的“第三方平台请求失败”。 */
    private const TIEBA_RETRY_ERROR = 'UPSTREAM_REQUEST_FAILED';

    public function retriesUntilSuccess(string $pluginCode, ?string $errorCode): bool
    {
        return $pluginCode === 'tieba'
            && $errorCode !== null
            && $errorCode === self::TIEBA_RETRY_ERROR;
    }

    /**
     * @return array{code:string,message:string}|null
     */
    public function transientFailureFromResult(string $pluginCode, SignResult $result): ?array
    {
        if ($pluginCode !== 'tieba') {
            return null;
        }

        foreach ($result->records as $record) {
            $failure = $this->transientFailureFromRecord($pluginCode, $record);
            if ($failure !== null) {
                return $failure;
            }
        }
        return null;
    }

    public function retryDelaySeconds(int $attempts): int
    {
        return min(900, max(60, 60 * max(1, $attempts)));
    }

    /**
     * 持续补签每次将任务上限向后推进，避免普通任务的 24 次上限终止它。
     */
    public function extendedAttemptLimit(int $attempts, int $currentLimit): int
    {
        return max($currentLimit, min(65535, $attempts + 1));
    }

    /**
     * @return array{code:string,message:string}|null
     */
    private function transientFailureFromRecord(string $pluginCode, SignRecord $record): ?array
    {
        if ($record->status === 'failed' && $this->retriesUntilSuccess($pluginCode, $record->code)) {
            return [
                'code' => (string)$record->code,
                'message' => trim((string)$record->message) !== ''
                    ? (string)$record->message
                    : '第三方平台请求失败，稍后自动补签',
            ];
        }

        foreach ($record->children as $child) {
            if (!$child instanceof SignRecord) {
                continue;
            }
            $failure = $this->transientFailureFromRecord($pluginCode, $child);
            if ($failure !== null) {
                return $failure;
            }
        }
        return null;
    }
}
