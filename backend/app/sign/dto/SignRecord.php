<?php

namespace app\sign\dto;

final readonly class SignRecord
{
    public function __construct(
        public string $key,
        public string $action,
        public string $status,
        public ?string $targetType = null,
        public ?string $targetId = null,
        public ?string $targetName = null,
        public ?string $code = null,
        public ?string $message = null,
        public array $rewards = [],
        public array $metrics = [],
        public array $safeResult = [],
        public array $children = [],
    ) {
    }
}
