<?php

namespace app\exception;

use app\http\ApiResponse;
use support\exception\Handler;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;

final class ApiExceptionHandler extends Handler
{
    public function report(Throwable $exception): void
    {
        if (!$exception instanceof ApiException) {
            parent::report($exception);
        }
    }

    public function render(Request $request, Throwable $exception): Response
    {
        if ($exception instanceof ApiException) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->httpStatus,
                $exception->data
            );
        }

        $message = config('app.debug') ? $exception->getMessage() : '服务器内部错误';
        return ApiResponse::error('INTERNAL_ERROR', $message, 500);
    }
}
