<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$route = (string)file_get_contents($root . '/config/route.php');
$schema = (string)file_get_contents($root . '/database/schema.sql');
$settings = (string)file_get_contents($root . '/app/service/SettingsService.php');
$qqAuth = (string)file_get_contents($root . '/app/service/QqAuthService.php');
$qqApplication = (string)file_get_contents($root . '/app/service/QqRelayApplicationService.php');
$qqCodec = (string)file_get_contents($root . '/app/service/QqRelayApplicationCodec.php');
$assert(str_contains($route, "Route::disableDefaultRoute()"), 'default routes must be disabled');
$assert(str_contains($route, "Route::get('/api") === false, 'route declarations must use the grouped API prefix');
$assert(!preg_match('/payments|redeem-cards|migrations|Alipay|PaymentController/i', $route), 'open-source routes must not expose private modules');
$assert(!preg_match('/TF_(orders|payment_products|payment_transactions|redeem_cards|legacy_mappings|migration_checkpoints)/', $schema), 'open-source schema must not create private tables');
$assert(str_contains($schema, 'TF_sign_tasks'), 'core sign task table must exist');
$assert(str_contains($schema, 'TF_plugin_credentials'), 'encrypted credential table must exist');
$assert(str_contains($schema, 'TF_notification_events'), 'notification event table must exist');
$scheduler = (string)file_get_contents($root . '/app/service/SignSchedulerService.php');
$process = (string)file_get_contents($root . '/config/process.php');
$schedule = (string)file_get_contents($root . '/app/sign/schedule/DailySchedule.php');
$assert(str_contains($scheduler, 'recordDispatchFailure($account, $exception)'), 'one broken account must not stop the whole scheduler batch');
$assert(str_contains($scheduler, 'normalizeLegacy'), 'scheduler must recover legacy schedule settings');
$assert(str_contains($schedule, 'normalizeLegacy'), 'daily schedule must provide legacy settings compatibility');
$assert(str_contains($process, "\$envFlag('SIGN_WORKER_ENABLED', true)"), 'sign worker must stay enabled by default on upgraded installs');
$assert(str_contains($process, "\$envFlag('SIGN_SCHEDULER_ENABLED', \$signWorkerEnabled)"), 'scheduler must follow the core worker when its env flag is absent');
$liveSession = (string)file_get_contents($root . '/app/service/BilibiliLiveSessionService.php');
$liveWorker = (string)file_get_contents($root . '/app/process/BilibiliLiveWorker.php') . $liveSession;
$composer = (string)file_get_contents($root . '/composer.json');
$assert(!preg_match('/\b(?:sleep|usleep)\s*\(/', $liveWorker), 'bilibili live worker must not block the event loop');
$assert(!preg_match('/\bwhile\s*\(/', $liveWorker), 'bilibili live worker must use bounded event-loop ticks');
$assert(str_contains($liveSession, '/room/v1/Room/getRoomInfoOld')
    && !str_contains($liveSession, '/x/space/acc/info'),
    'bilibili live room discovery must use the supported live endpoint');
$assert(str_contains($liveSession, 'reconcileTerminalSession($session)')
    && str_contains($liveSession, 'BILIBILI_LIVE_SESSION_MISSING'),
    'bilibili live recovery must reconcile terminal and orphaned sessions');
$assert(str_contains($liveSession, "array_key_exists('live_status', \$medal)")
    && str_contains($liveSession, "whereNull('next_heartbeat_at')"),
    'bilibili live discovery must skip known offline medals and repair missing due times');
foreach (['queue_name', 'TF_bilibili_live_sessions', 'TF_bilibili_live_room_sessions', 'next_heartbeat_at'] as $liveSchemaToken) {
    $assert(str_contains($schema, $liveSchemaToken), "bilibili live schema must contain {$liveSchemaToken}");
}
$assert(str_contains($process, 'bilibili-live-worker'), 'bilibili live worker must be registered');
$assert(str_contains($scheduler, "'live_fans_medal'"), 'scheduler must enqueue enabled fans-medal sessions');
$assert(str_contains($composer, 'workerman/http-client'), 'bilibili live worker requires the async HTTP client');
$assert(str_contains($route, "qq-relay/apply"), 'QQ relay application route must exist');
$assert(str_contains($route, "qq-login-verification.txt"), 'QQ relay verification route must exist');
$assert(str_contains($settings, "qq_relay_application_status"), 'QQ relay application settings must exist');
$assert(str_contains($qqAuth, 'QqRelayApplicationCodec'), 'QQ auth must support signed application callbacks');
$assert(str_contains($qqAuth, 'replaceLegacyRelayIdentity'), 'QQ auth must support rebinding legacy relay identities');
$assert(str_contains($qqApplication, 'verification_url'), 'QQ application must publish a deployable verification URL');
$assert(str_contains($qqCodec, 'hash_hmac'), 'QQ application callback signatures must use HMAC');

$assert(!is_dir($root . '/app/payment') || glob($root . '/app/payment/*') === [], 'private payment module must not contain files');
foreach (['/service/PaymentService.php', '/service/UserPaymentService.php', '/service/RedeemCardService.php', '/migration/LegacyMigrationService.php'] as $relative) {
    $assert(!file_exists($root . $relative), "private module {$relative} must not be present");
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, array_map(static fn (string $failure): string => 'FAIL: ' . $failure, $failures)) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Open-source backend checks passed.\n");
