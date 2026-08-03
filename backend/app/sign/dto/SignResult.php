<?php

namespace app\sign\dto;

final readonly class SignResult
{
    public function __construct(
        public string $status,
        public string $message,
        public array $records,
        public array $summary = [],
        /** @var array<string, mixed>|null 合并写入账号 profile_json 的字段 */
        public ?array $profilePatch = null,
    ) {
        foreach ($records as $record) {
            if (!$record instanceof SignRecord) {
                throw new \InvalidArgumentException('records must contain SignRecord instances');
            }
        }
    }
}
