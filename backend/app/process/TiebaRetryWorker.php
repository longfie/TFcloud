<?php

namespace app\process;

use app\service\TiebaRetryService;
use app\sign\executor\SensitiveDataRedactor;
use app\sign\executor\SignTaskExecutor;
use support\Log;
use Workerman\Timer;
use Workerman\Worker;

/** 独立领取贴吧“第三方平台请求失败”的自动补签任务。 */
final class TiebaRetryWorker
{
    private bool $busy = false;

    public function onWorkerStart(Worker $worker): void
    {
        $workerId = 'tieba-retry-worker:' . gethostname() . ':' . getmypid();
        $retryService = new TiebaRetryService();
        try {
            $retryService->requeueFailedToday();
        } catch (\Throwable $exception) {
            Log::warning('initial tieba retry scan failed', ['exception' => $exception::class]);
        }
        Timer::add(30.0, function () use ($retryService): void {
            if ($this->busy) {
                return;
            }
            try {
                $retryService->requeueFailedToday();
            } catch (\Throwable $exception) {
                Log::warning('tieba retry scan failed', [
                    'exception' => $exception::class,
                    'message' => (new SensitiveDataRedactor())->redactString($exception->getMessage()),
                ]);
            }
        });
        Timer::add(1.0, function () use ($workerId): void {
            if ($this->busy) {
                return;
            }
            $this->busy = true;
            try {
                (new SignTaskExecutor())->processNext(
                    $workerId,
                    SignTaskExecutor::QUEUE_TIEBA_RETRY
                );
            } catch (\Throwable $exception) {
                Log::error('tieba retry worker loop failed', [
                    'exception' => $exception::class,
                    'message' => (new SensitiveDataRedactor())->redactString($exception->getMessage()),
                ]);
            } finally {
                $this->busy = false;
            }
        });
    }
}
