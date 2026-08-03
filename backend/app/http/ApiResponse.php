<?php

namespace app\http;

use support\Response;

final class ApiResponse
{
    public static function success(mixed $data = null, string $message = 'success', int $status = 200): Response
    {
        return self::make('SUCCESS', $message, $data, $status);
    }

    public static function created(mixed $data = null, string $message = 'created'): Response
    {
        return self::make('SUCCESS', $message, $data, 201);
    }

    public static function error(
        string $code,
        string $message,
        int $status = 400,
        mixed $data = null
    ): Response {
        return self::make($code, $message, $data, $status);
    }

    private static function make(string $code, string $message, mixed $data, int $status): Response
    {
        $requestId = '';
        try {
            $current = request();
            if ($current instanceof \support\Request) {
                $requestId = $current->requestId();
            }
        } catch (\Throwable) {
        }

        $body = json_encode([
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'request_id' => $requestId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return new Response($status, [
            'Content-Type' => 'application/json; charset=utf-8',
        ], $body);
    }
}
