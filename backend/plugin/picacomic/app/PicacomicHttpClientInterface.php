<?php

namespace plugin\picacomic\app;

interface PicacomicHttpClientInterface
{
    /**
     * @return array{status:int, headers:array<string,string>, body:string, json:?array}
     */
    public function request(string $method, string $url, array $options = []): array;
}
