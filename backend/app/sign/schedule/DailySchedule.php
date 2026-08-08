<?php

namespace app\sign\schedule;

use app\exception\ApiException;
use DateTimeImmutable;

final class DailySchedule
{
    /**
     * 兼容早期只有 schedule_enabled、没有 schedule_time 的账号。
     * 优先沿用数据库里已经分配的下次执行时刻，避免升级后改变用户原来的计划。
     */
    public static function normalizeLegacy(array $settings, ?string $nextRunAt): array
    {
        $mode = trim((string)($settings['schedule_mode'] ?? ''));
        if ($mode === '') {
            $mode = !empty($settings['schedule_enabled']) ? 'fixed' : 'auto';
            $settings['schedule_mode'] = $mode;
        }
        if ($mode !== 'fixed' || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', trim((string)($settings['schedule_time'] ?? '')))) {
            return $settings;
        }

        $fallbackTime = is_string($nextRunAt) && strlen($nextRunAt) >= 16
            ? substr($nextRunAt, 11, 5)
            : '';
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $fallbackTime)) {
            $settings['schedule_time'] = $fallbackTime;
        } else {
            $settings['schedule_mode'] = 'auto';
            unset($settings['schedule_time']);
        }
        return $settings;
    }

    public static function next(array $settings, string $seed, ?DateTimeImmutable $now = null): ?string
    {
        $mode = trim((string)($settings['schedule_mode'] ?? ''));
        if ($mode === '') {
            $mode = !empty($settings['schedule_enabled']) ? 'fixed' : 'auto';
        }
        if (!in_array($mode, ['auto', 'fixed'], true)) {
            throw new ApiException('SCHEDULE_MODE_INVALID', 'schedule_mode 必须是 auto 或 fixed', 422);
        }
        $time = trim((string)($settings['schedule_time'] ?? ''));
        if ($mode === 'auto') {
            $minuteOfDay = 300 + (self::unsignedCrc32($seed) % 180);
            $time = sprintf('%02d:%02d', intdiv($minuteOfDay, 60), $minuteOfDay % 60);
        } elseif (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new ApiException('SCHEDULE_TIME_INVALID', 'schedule_time 必须使用 HH:MM 格式', 422);
        }

        $now ??= new DateTimeImmutable('now');
        $candidate = new DateTimeImmutable($now->format('Y-m-d') . ' ' . $time . ':00');
        if ($candidate <= $now) {
            $candidate = $candidate->modify('+1 day');
        }
        return $candidate->format('Y-m-d H:i:s');
    }

    private static function unsignedCrc32(string $value): int
    {
        return (int)sprintf('%u', crc32($value));
    }
}
