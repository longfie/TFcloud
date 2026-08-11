<?php

namespace app\process;

use app\service\BilibiliLiveSessionService;
use app\sign\executor\SensitiveDataRedactor;
use support\Log;
use Workerman\Timer;
use Workerman\Worker;

final class BilibiliLiveWorker
{
    private int $inFlight = 0;
    private bool $ticking = false;

    public function onWorkerStart(Worker $worker): void
    {
        $workerId = 'bilibili-live-worker:' . gethostname() . ':' . getmypid();
        $service = new BilibiliLiveSessionService();
        $maxInFlight = max(1, (int)(getenv('BILIBILI_LIVE_MAX_INFLIGHT_PER_WORKER') ?: 8));
        $interval = max(0.2, (float)(getenv('BILIBILI_LIVE_TICK_INTERVAL') ?: 1));
        $settled = function (): void {
            $this->inFlight = max(0, $this->inFlight - 1);
        };

        try {
            $service->recover();
        } catch (\Throwable $exception) {
            Log::warning('initial bilibili live recovery failed', ['exception' => $exception::class]);
        }

        Timer::add($interval, function () use ($service, $workerId, $maxInFlight, $settled): void {
            if ($this->ticking || $this->inFlight >= $maxInFlight) {
                return;
            }
            $this->ticking = true;
            try {
                for ($i = 0; $i < 4; $i++) {
                    if (!$service->initializeNextTask($workerId)) {
                        break;
                    }
                }
                $slots = $maxInFlight - $this->inFlight;
                if ($slots > 0) {
                    $started = $service->dispatchDue($workerId, $slots, $settled);
                    $this->inFlight += $started;
                }
            } catch (\Throwable $exception) {
                Log::error('bilibili live worker tick failed', [
                    'exception' => $exception::class,
                    'message' => (new SensitiveDataRedactor())->redactString($exception->getMessage()),
                ]);
            } finally {
                $this->ticking = false;
            }
        });

        Timer::add(30.0, function () use ($service): void {
            try {
                $service->recover();
            } catch (\Throwable $exception) {
                Log::warning('bilibili live recovery failed', [
                    'exception' => $exception::class,
                    'message' => (new SensitiveDataRedactor())->redactString($exception->getMessage()),
                ]);
            }
        });
    }
}
