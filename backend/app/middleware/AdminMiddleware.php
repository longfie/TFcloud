<?php

namespace app\middleware;

use app\exception\ApiException;
use app\service\AccessControlService;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

final class AdminMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        if (!$request instanceof \support\Request || !$request->userId()) {
            throw new ApiException('AUTH_REQUIRED', '请先登录', 401);
        }
        (new AccessControlService())->assert(
            $request->userRole(),
            AccessControlService::ADMIN_ACCESS
        );
        return $handler($request);
    }
}
