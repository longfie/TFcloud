<?php

namespace app\middleware;

use app\http\ApiResponse;
use app\service\InstallService;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

final class InstallGuardMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $path = '/' . ltrim($request->path(), '/');
        if ($this->isInstallBypass($path)) {
            return $handler($request);
        }

        if (!(new InstallService())->isInstalled()) {
            return ApiResponse::error(
                'INSTALL_REQUIRED',
                '系统尚未安装，请先完成安装引导',
                503,
                ['install_path' => '/install']
            );
        }

        return $handler($request);
    }

    private function isInstallBypass(string $path): bool
    {
        if ($path === '/health/live') {
            return true;
        }
        return str_starts_with($path, '/api/install');
    }
}
