<?php

namespace app\process;

use support\Db;
use support\Log;
use Workerman\Timer;
use Workerman\Worker;

final class MaintenanceWorker
{
    public function onWorkerStart(Worker $worker): void
    {
        Timer::add(60.0, fn () => $this->cleanup(), [], false);
        Timer::add(3600.0, fn () => $this->cleanup());
    }

    private function cleanup(): void
    {
        try {
            $now = date('Y-m-d H:i:s');
            Db::table('TF_sign_record_payloads')->where('expires_at', '<', $now)->delete();
            Db::table('TF_password_change_codes')
                ->where(function ($query) use ($now): void {
                    $query->where('expires_at', '<', date('Y-m-d H:i:s', time() - 86400))
                        ->orWhere(function ($query) use ($now): void {
                            $query->whereNotNull('consumed_at')
                                ->where('consumed_at', '<', date('Y-m-d H:i:s', time() - 7 * 86400));
                        });
                })
                ->delete();
            Db::table('TF_sessions')
                ->where(function ($query) use ($now): void {
                    $query->where('expires_at', '<', $now)
                        ->orWhere(function ($query): void {
                            $query->whereNotNull('revoked_at')
                                ->where('revoked_at', '<', date('Y-m-d H:i:s', time() - 30 * 86400));
                        });
                })
                ->delete();
        } catch (\Throwable $exception) {
            Log::warning('maintenance cleanup failed', ['exception' => $exception::class]);
        }
    }
}
