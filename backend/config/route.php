<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use Webman\Route;

Route::get('/health/live', [app\controller\Api\HealthController::class, 'live']);
Route::get('/health/ready', [app\controller\Api\HealthController::class, 'ready']);
Route::get('/.well-known/qq-login-verification.txt', [app\controller\Api\QqRelayController::class, 'verification']);

Route::group('/api', function (): void {
    Route::get('/install/status', [app\controller\Api\InstallController::class, 'status']);
    Route::get('/install/checks', [app\controller\Api\InstallController::class, 'checks']);
    Route::post('/install/test-database', [app\controller\Api\InstallController::class, 'testDatabase']);
    Route::post('/install', [app\controller\Api\InstallController::class, 'run']);
    Route::post('/auth/human-challenge', [app\controller\Api\AuthController::class, 'issueHumanChallenge']);
    Route::post('/auth/human-challenge/verify', [app\controller\Api\AuthController::class, 'verifyHumanChallenge']);
    Route::post('/auth/qq/start', [app\controller\Api\AuthController::class, 'startQqLogin']);
    Route::get('/auth/qq/verification', [app\controller\Api\QqRelayController::class, 'verification']);
    Route::get('/auth/qq/callback', [app\controller\Api\AuthController::class, 'qqCallback']);
    Route::get('/auth/qq/callback/relay/{state:[a-f0-9]{48}}', [app\controller\Api\AuthController::class, 'qqRelayCallback']);
    Route::post('/auth/qq/exchange', [app\controller\Api\AuthController::class, 'exchangeQqLogin']);
    Route::post('/auth/qq/register', [app\controller\Api\AuthController::class, 'completeQqRegistration']);
    Route::post('/auth/login', [app\controller\Api\AuthController::class, 'login']);
    Route::post('/auth/login/email-code', [app\controller\Api\AuthController::class, 'issueLoginEmailCode']);
    Route::post('/auth/login/email', [app\controller\Api\AuthController::class, 'loginWithEmailCode']);
    Route::post('/auth/register', [app\controller\Api\AuthController::class, 'register']);
    Route::post('/auth/register/email-code', [app\controller\Api\AuthController::class, 'issueRegisterEmailCode']);
    Route::post('/auth/password/forgot', [app\controller\Api\AuthController::class, 'issuePasswordResetCode']);
    Route::post('/auth/password/reset', [app\controller\Api\AuthController::class, 'resetPassword']);
    Route::get('/site', [app\controller\Api\SiteController::class, 'show']);
    Route::post('/auth/logout', [app\controller\Api\AuthController::class, 'logout'])
        ->middleware(app\middleware\AuthMiddleware::class);
    Route::get('/me', [app\controller\Api\AuthController::class, 'me'])
        ->middleware(app\middleware\AuthMiddleware::class);
    Route::patch('/me', [app\controller\Api\AuthController::class, 'updateMe'])
        ->middleware(app\middleware\AuthMiddleware::class);
    Route::post('/me/email/code', [app\controller\Api\AuthController::class, 'issueChangeEmailCode'])
        ->middleware(app\middleware\AuthMiddleware::class);
    Route::post('/me/password/code', [app\controller\Api\AuthController::class, 'issuePasswordCode'])
        ->middleware(app\middleware\AuthMiddleware::class);
    Route::post('/me/password', [app\controller\Api\AuthController::class, 'changePassword'])
        ->middleware(app\middleware\AuthMiddleware::class);
    Route::post('/me/qq/start', [app\controller\Api\AuthController::class, 'startQqBinding'])
        ->middleware(app\middleware\AuthMiddleware::class);
    Route::post('/me/push/test', [app\controller\Api\AuthController::class, 'testPush'])
        ->middleware(app\middleware\AuthMiddleware::class);

    Route::get('/platforms', [app\controller\Api\PlatformController::class, 'index'])
        ->middleware(app\middleware\AuthMiddleware::class);
    Route::get('/platforms/{platformCode}', [app\controller\Api\PlatformController::class, 'show'])
        ->middleware(app\middleware\AuthMiddleware::class);
    Route::post('/platform-auth/{platformCode}/qr/start', [app\controller\Api\PlatformAuthController::class, 'startQr'])
        ->middleware(app\middleware\AuthMiddleware::class);
    Route::get('/platform-auth/{platformCode}/qr/{flowNo}', [app\controller\Api\PlatformAuthController::class, 'pollQr'])
        ->middleware(app\middleware\AuthMiddleware::class);
    Route::post('/platform-auth/{platformCode}/password', [app\controller\Api\PlatformAuthController::class, 'password'])
        ->middleware(app\middleware\AuthMiddleware::class);
    Route::post('/platform-auth/{platformCode}/sms/send', [app\controller\Api\PlatformAuthController::class, 'sendSms'])
        ->middleware(app\middleware\AuthMiddleware::class);
    Route::post('/platform-auth/{platformCode}/sms/complete', [app\controller\Api\PlatformAuthController::class, 'completeSms'])
        ->middleware(app\middleware\AuthMiddleware::class);

    Route::group('/plugins', function (): void {
        Route::get('', [app\controller\Api\PluginController::class, 'index']);
        Route::get('/{pluginCode}', [app\controller\Api\PluginController::class, 'show']);
        Route::get('/{pluginCode}/actions', [app\controller\Api\PluginController::class, 'actions']);
    })->middleware([
        app\middleware\AuthMiddleware::class,
        app\middleware\AdminMiddleware::class,
    ]);

    Route::group('/plugin-accounts', function (): void {
        Route::get('', [app\controller\Api\PluginAccountController::class, 'index']);
        Route::post('', [app\controller\Api\PluginAccountController::class, 'store']);
        Route::get('/{id:\\d+}', [app\controller\Api\PluginAccountController::class, 'show']);
        Route::patch('/{id:\\d+}', [app\controller\Api\PluginAccountController::class, 'update']);
        Route::delete('/{id:\\d+}', [app\controller\Api\PluginAccountController::class, 'destroy']);
        Route::post('/{id:\\d+}/actions/{action}', [app\controller\Api\PluginAccountController::class, 'action']);
    })->middleware(app\middleware\AuthMiddleware::class);

    Route::group('/sign-tasks', function (): void {
        Route::get('', [app\controller\Api\SignTaskController::class, 'index']);
        Route::post('', [app\controller\Api\SignTaskController::class, 'store']);
        Route::get('/calendar', [app\controller\Api\SignTaskController::class, 'calendar']);
        Route::get('/{taskNo}', [app\controller\Api\SignTaskController::class, 'show']);
        Route::get('/{taskNo}/runs', [app\controller\Api\SignTaskController::class, 'runs']);
        Route::get('/{taskNo}/records', [app\controller\Api\SignTaskController::class, 'records']);
        Route::post('/{taskNo}/retry', [app\controller\Api\SignTaskController::class, 'retry']);
        Route::post('/{taskNo}/cancel', [app\controller\Api\SignTaskController::class, 'cancel']);
    })->middleware(app\middleware\AuthMiddleware::class);

    Route::group('/assistant', function (): void {
        Route::get('/status', [app\controller\Api\AstrBotController::class, 'status']);
        Route::get('/messages', [app\controller\Api\AstrBotController::class, 'messages']);
        Route::post('/messages', [app\controller\Api\AstrBotController::class, 'send']);
        Route::post('/messages/stream', [app\controller\Api\AstrBotController::class, 'stream']);
        Route::delete('/messages', [app\controller\Api\AstrBotController::class, 'reset']);
    })->middleware(app\middleware\AuthMiddleware::class);

    Route::group('/admin', function (): void {
        Route::get('/overview', [app\controller\Api\AdminController::class, 'overview']);
        Route::get('/system/version', [app\controller\Api\AdminSettingsController::class, 'version']);
        Route::get('/settings', [app\controller\Api\AdminSettingsController::class, 'index']);
        Route::get('/settings/{group}', [app\controller\Api\AdminSettingsController::class, 'show']);
        Route::patch('/settings/{group}', [app\controller\Api\AdminSettingsController::class, 'update']);
        Route::post('/settings/qq-relay/apply', [app\controller\Api\AdminSettingsController::class, 'applyQqRelay']);
        Route::post('/settings/qq-relay/verify', [app\controller\Api\AdminSettingsController::class, 'verifyQqRelay']);
        Route::post('/settings/qq-relay/configure', [app\controller\Api\AdminSettingsController::class, 'configureQqRelay']);
        Route::get('/mail/summary', [app\controller\Api\AdminMailController::class, 'summary']);
        Route::get('/mail/tasks', [app\controller\Api\AdminMailController::class, 'tasks']);
        Route::get('/mail/tasks/{taskNo}/records', [app\controller\Api\AdminMailController::class, 'records']);
        Route::get('/mail/records', [app\controller\Api\AdminMailController::class, 'allRecords']);
        Route::delete('/mail/records/{id:\d+}', [app\controller\Api\AdminMailController::class, 'deleteRecord']);
        Route::post('/mail/tasks/{taskNo}/retry', [app\controller\Api\AdminMailController::class, 'retry']);
        Route::post('/mail/compose', [app\controller\Api\AdminMailController::class, 'compose']);
        Route::post('/mail/test', [app\controller\Api\AdminMailController::class, 'test']);
        Route::get('/sign-tasks', [app\controller\Api\AdminController::class, 'tasks']);
        Route::get('/sign-tasks/{taskNo}', [app\controller\Api\AdminController::class, 'task']);
        Route::get('/sign-tasks/{taskNo}/runs', [app\controller\Api\AdminController::class, 'taskRuns']);
        Route::get('/sign-tasks/{taskNo}/records', [app\controller\Api\AdminController::class, 'taskRecords']);
        Route::post('/sign-tasks/{taskNo}/retry', [app\controller\Api\AdminController::class, 'retryTask']);
        Route::post('/sign-tasks/{taskNo}/cancel', [app\controller\Api\AdminController::class, 'cancelTask']);
        Route::get('/audit-logs', [app\controller\Api\AdminController::class, 'auditLogs']);
        Route::get('/users', [app\controller\Api\AdminController::class, 'users']);
        Route::post('/users', [app\controller\Api\AdminController::class, 'createUser']);
        Route::get('/users/{id:\\d+}', [app\controller\Api\AdminController::class, 'user']);
        Route::patch('/users/{id:\\d+}', [app\controller\Api\AdminController::class, 'updateUser']);
        Route::delete('/users/{id:\\d+}', [app\controller\Api\AdminController::class, 'deleteUser']);
        Route::post('/users/{id:\\d+}/reset-password', [app\controller\Api\AdminController::class, 'resetUserPassword']);
        Route::post('/users/{id:\\d+}/revoke-sessions', [app\controller\Api\AdminController::class, 'revokeUserSessions']);
        Route::get('/plugin-accounts', [app\controller\Api\AdminController::class, 'pluginAccounts']);
        Route::patch('/plugin-accounts/{id:\\d+}', [app\controller\Api\AdminController::class, 'updatePluginAccount']);
        Route::delete('/plugin-accounts/{id:\\d+}', [app\controller\Api\AdminController::class, 'deletePluginAccount']);
        Route::post('/plugin-accounts/{id:\\d+}/actions/{action}', [app\controller\Api\AdminController::class, 'pluginAccountAction']);
        Route::post('/astrbot/test', [app\controller\Api\AstrBotController::class, 'test']);
    })->middleware([
        app\middleware\AuthMiddleware::class,
        app\middleware\AdminMiddleware::class,
    ]);
});

Route::fallback(static fn () => app\http\ApiResponse::error(
    'ROUTE_NOT_FOUND',
    '接口不存在',
    404
))->middleware([
    app\middleware\RequestIdMiddleware::class,
    app\middleware\CorsMiddleware::class,
]);

Route::disableDefaultRoute();
