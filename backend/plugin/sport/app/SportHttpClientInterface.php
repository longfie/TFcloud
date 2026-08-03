<?php

namespace plugin\sport\app;

interface SportHttpClientInterface
{
    /**
     * @return array{status:int, headers:array<string,string>, body:string, json:?array}
     */
    public function request(string $method, string $url, array $options = []): array;
}
