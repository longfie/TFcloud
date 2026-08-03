<?php

namespace plugin\cloud189\app;

interface Cloud189HttpClientInterface
{
    /**
     * @return array{status:int, headers:array<string,string>, body:string, json:?array, effective_url?:string}
     */
    public function request(string $method, string $url, array $options = []): array;
}
