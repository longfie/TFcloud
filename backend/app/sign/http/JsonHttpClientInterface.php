<?php

namespace app\sign\http;

interface JsonHttpClientInterface
{
    public function request(string $method, string $url, array $options = []): array;
}
