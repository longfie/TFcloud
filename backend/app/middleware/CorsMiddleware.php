<?php

namespace app\middleware;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

final class CorsMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $origin = trim((string)$request->header('origin', ''));
        $allowed = array_values(array_filter(array_map(
            'trim',
            explode(',', (string)(getenv('CORS_ALLOWED_ORIGINS') ?: ''))
        )));

        // 安装完成前允许当前页面 Origin，避免引导页跨域被拦。
        $allowInstallOrigin = false;
        if ($origin !== '' && $allowed === []) {
            try {
                $allowInstallOrigin = !(new \app\service\InstallService())->isInstalled();
            } catch (\Throwable) {
                $allowInstallOrigin = true;
            }
        }

        if ($request->method() === 'OPTIONS') {
            $response = response('', 204);
        } else {
            $response = $handler($request);
        }

        if ($origin !== '' && (in_array($origin, $allowed, true) || $allowInstallOrigin)) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Vary', 'Origin')
                ->withHeader('Access-Control-Allow-Credentials', 'true')
                ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Request-Id, X-CSRF-Token')
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PATCH, DELETE, OPTIONS');
        }

        return $response;
    }
}
