<?php

namespace app\sign\progress;

use app\sign\dto\SignRecord;
use app\sign\executor\SensitiveDataRedactor;
use support\Db;

/**
 * 将插件执行过程中的明细即时落库，供前端轮询渲染。
 */
final class SignProgressReporter
{
    private int $sequence = 0;
    /** @var array{total:int,success:int,already:int,skipped:int,failed:int} */
    private array $counts = [
        'total' => 0,
        'success' => 0,
        'already' => 0,
        'skipped' => 0,
        'failed' => 0,
    ];
    private bool $used = false;

    public function __construct(
        private readonly object $task,
        private readonly int $runId,
    ) {
    }

    public function push(SignRecord $record, ?int $parentId = null): void
    {
        $this->used = true;
        $this->persist($record, $parentId);
        Db::table('TF_sign_tasks')->where('id', $this->task->id)->update([
            'total_count' => $this->counts['total'],
            'success_count' => $this->counts['success'],
            'already_count' => $this->counts['already'],
            'skipped_count' => $this->counts['skipped'],
            'failed_count' => $this->counts['failed'],
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Db::table('TF_sign_runs')->where('id', $this->runId)->update([
            'total_count' => $this->counts['total'],
            'success_count' => $this->counts['success'],
            'already_count' => $this->counts['already'],
            'skipped_count' => $this->counts['skipped'],
            'failed_count' => $this->counts['failed'],
        ]);
    }

    public function wasUsed(): bool
    {
        return $this->used;
    }

    /** @return array{total:int,success:int,already:int,skipped:int,failed:int} */
    public function counts(): array
    {
        return $this->counts;
    }

    private function persist(SignRecord $record, ?int $parentId): void
    {
        $this->sequence++;
        $this->counts['total']++;
        $bucket = match ($record->status) {
            'succeeded' => 'success',
            'already_done' => 'already',
            'skipped' => 'skipped',
            default => 'failed',
        };
        $this->counts[$bucket]++;
        $redactor = new SensitiveDataRedactor();
        $recordId = (int)Db::table('TF_sign_records')->insertGetId([
            'task_id' => $this->task->id,
            'run_id' => $this->runId,
            'parent_record_id' => $parentId,
            'user_id' => $this->task->user_id,
            'account_id' => $this->task->account_id,
            'plugin_code' => $this->task->plugin_code,
            'record_key' => $record->key,
            'sequence_no' => $this->sequence,
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
                $this->persist($child, $recordId);
            }
        }
    }
}
