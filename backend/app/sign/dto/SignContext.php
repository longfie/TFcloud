<?php

namespace app\sign\dto;

use app\sign\progress\SignProgressReporter;

final readonly class SignContext
{
    public function __construct(
        public int $userId,
        public int $accountId,
        public string $action,
        public array $credentials,
        public array $settings = [],
        public array $options = [],
        public ?SignProgressReporter $progress = null,
    ) {
    }

    public function report(SignRecord $record): void
    {
        $this->progress?->push($record);
    }
}
