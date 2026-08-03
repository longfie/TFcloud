<?php

namespace app\controller\Api;

use app\exception\ApiException;
use app\http\ApiResponse;
use app\service\AstrBotChatService;
use support\Request;
use support\Response;
use Workerman\Protocols\Http\Chunk;
use Workerman\Timer;

final class AstrBotController
{
    public function status(): Response
    {
        return ApiResponse::success((new AstrBotChatService())->status());
    }

    public function messages(Request $request): Response
    {
        return ApiResponse::success(
            (new AstrBotChatService())->history((int)$request->userId(), $request->get())
        );
    }

    public function send(Request $request): Response
    {
        return ApiResponse::created(
            (new AstrBotChatService())->send((int)$request->userId(), $request->all()),
            '回复已生成'
        );
    }

    public function stream(Request $request): Response
    {
        $connection = $request->connection;
        $userId = (int)$request->userId();
        $input = $request->all();

        Timer::delay(0.001, function () use ($connection, $userId, $input): void {
            $emit = static function (string $event, array $data) use ($connection): void {
                $payload = json_encode(
                    ['event' => $event, 'data' => $data],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                );
                $connection->send(new Chunk($payload . "\n"));
            };

            try {
                (new AstrBotChatService())->stream($userId, $input, $emit);
            } catch (\Throwable $exception) {
                $emit('error', [
                    'code' => $exception instanceof ApiException
                        ? $exception->errorCode
                        : 'ASTRBOT_REQUEST_FAILED',
                    'message' => mb_substr($exception->getMessage(), 0, 500),
                ]);
            } finally {
                $connection->send(new Chunk(''));
            }
        });

        return new Response(200, [
            'Content-Type' => 'application/x-ndjson; charset=utf-8',
            'Transfer-Encoding' => 'chunked',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function reset(Request $request): Response
    {
        return ApiResponse::success(
            (new AstrBotChatService())->reset((int)$request->userId()),
            '新对话已创建'
        );
    }

    public function test(): Response
    {
        return ApiResponse::success(
            (new AstrBotChatService())->testConnection(),
            'AstrBot 连接正常'
        );
    }
}
