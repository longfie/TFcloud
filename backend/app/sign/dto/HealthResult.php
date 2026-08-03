<?php

namespace app\sign\dto;

final readonly class HealthResult
{
    public function __construct(
        public bool $healthy,
        public string $message = 'ok',
    ) {
    }
}
