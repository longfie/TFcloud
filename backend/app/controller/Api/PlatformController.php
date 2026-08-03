<?php

namespace app\controller\Api;

use app\http\ApiResponse;
use app\service\PlatformCatalogService;
use support\Response;

final class PlatformController
{
    public function index(): Response
    {
        return ApiResponse::success((new PlatformCatalogService())->list());
    }

    public function show(string $platformCode): Response
    {
        return ApiResponse::success((new PlatformCatalogService())->get($platformCode));
    }
}
