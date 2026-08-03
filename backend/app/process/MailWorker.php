<?php

namespace app\process;

use app\service\MailService;
use app\service\NotificationMailService;
use support\Log;
use Workerman\Timer;
use Workerman\Worker;

final class MailWorker
{
    private bool $busy = false;

    public function onWorkerStart(Worker $worker): void
    {
        try {
            (new MailService())->recoverStale();
        } catch (\Throwable $exception) {
            Log::warning('mail worker stale recovery failed', ['exception' => $exception::class]);
        }
        Timer::add(1.0, function (): void {
            if ($this->busy) return;
            $this->busy = true;
            try {
                (new MailService())->processPending();
            } catch (\Throwable $exception) {
                Log::error('mail worker loop failed', [
                    'exception' => $exception::class,
                    'message' => mb_substr($exception->getMessage(), 0, 500),
                ]);
            } finally {
                $this->busy = false;
            }
        });
        Timer::add(60.0, fn () => $this->dispatchNotifications(), [], false);
        Timer::add(3600.0, fn () => $this->dispatchNotifications());
    }

    private function dispatchNotifications(): void
    {
        try {
            (new NotificationMailService())->dispatchScheduled();
        } catch (\Throwable $exception) {
            Log::warning('notification scheduler failed', ['exception' => $exception::class]);
        }
    }
}
