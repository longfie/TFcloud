<?php

namespace app\process;

use app\sign\executor\SignTaskExecutor;
use app\sign\executor\SensitiveDataRedactor;
use support\Log;
use Workerman\Timer;
use Workerman\Worker;

final class SignWorker
{
    private bool $busy = false;

    public function onWorkerStart(Worker $worker): void
    {
        $workerId = 'sign-worker:' . gethostname() . ':' . getmypid();
        $executor = new SignTaskExecutor();
        try {
            $executor->recoverStale((int)(getenv('TASK_LOCK_TIMEOUT_SECONDS') ?: 7200));
        } catch (\Throwable $exception) {
            Log::warning('initial stale task recovery failed', ['exception' => $exception::class]);
        }
        Timer::add(300.0, function () use ($executor): void {
            if (!$this->busy) {
                try {
                    $executor->recoverStale((int)(getenv('TASK_LOCK_TIMEOUT_SECONDS') ?: 7200));
                } catch (\Throwable $exception) {
                    Log::warning('stale task recovery failed', ['exception' => $exception::class]);
                }
            }
        });
        Timer::add(1.0, function () use ($workerId): void {
            if ($this->busy) {
                return;
            }
            $this->busy = true;
            try {
                (new SignTaskExecutor())->processNext($workerId);
            } catch (\Throwable $exception) {
                Log::error('sign worker loop failed', [
                    'exception' => $exception::class,
                    'message' => (new SensitiveDataRedactor())->redactString($exception->getMessage()),
                ]);
            } finally {
                $this->busy = false;
            }
        });
    }
}
