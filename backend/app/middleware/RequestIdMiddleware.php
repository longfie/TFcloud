<?php

namespace app\middleware;

use app\http\RequestId;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

final class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $incoming = trim((string)$request->header('x-request-id', ''));
        $requestId = RequestId::isValid($incoming) ? $incoming : RequestId::generate();
        if (!$request instanceof \support\Request) {
            throw new \RuntimeException('Configured request class must be support\\Request');
        }
        $request->setRequestId($requestId);

        return $handler($request)->withHeader('X-Request-Id', $requestId);
    }
}
