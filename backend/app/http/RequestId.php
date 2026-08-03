<?php

namespace app\http;

final class RequestId
{
    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function isValid(string $value): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9_.:-]{8,64}$/', $value);
    }
}
