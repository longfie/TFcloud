<?php

namespace app\sign\dto;

final readonly class AccountProfile
{
    public function __construct(
        public string $externalUserId,
        public string $displayName,
        public array $metadata = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'external_user_id' => $this->externalUserId,
            'display_name' => $this->displayName,
            'metadata' => $this->metadata,
        ];
    }
}
