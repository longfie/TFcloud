<?php

namespace app\process;

use app\service\SignSchedulerService;
use app\sign\executor\SensitiveDataRedactor;
use support\Log;
use Workerman\Timer;
use Workerman\Worker;

final class SignScheduler
{
    private bool $busy = false;

    public function onWorkerStart(Worker $worker): void
    {
        Timer::add(30.0, function (): void {
            if ($this->busy) {
                return;
            }
            $this->busy = true;
            try {
                (new SignSchedulerService())->dispatchDue();
            } catch (\Throwable $exception) {
                Log::error('sign scheduler failed', [
                    'exception' => $exception::class,
                    'message' => (new SensitiveDataRedactor())->redactString($exception->getMessage()),
                ]);
            } finally {
                $this->busy = false;
            }
        });
        Timer::add(600.0, function (): void {
            try {
                (new SignSchedulerService())->requeueFailedToday();
            } catch (\Throwable $exception) {
                Log::error('sign make-up requeue failed', [
                    'exception' => $exception::class,
                    'message' => (new SensitiveDataRedactor())->redactString($exception->getMessage()),
                ]);
            }
        });
    }
}
