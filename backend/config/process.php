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

use support\Log;
use support\Request;
use app\process\Http;

global $argv;

return [
    'webman' => [
        'handler' => Http::class,
        'listen' => 'http://0.0.0.0:8787',
        'count' => (int)(getenv('WEBMAN_WORKER_COUNT') ?: 4),
        'user' => '',
        'group' => '',
        'reusePort' => false,
        'eventLoop' => '',
        'context' => [],
        'constructor' => [
            'requestClass' => Request::class,
            'logger' => Log::channel('default'),
            'appPath' => app_path(),
            'publicPath' => public_path()
        ]
    ],
    // File update detection and automatic reload
    'monitor' => [
        'handler' => app\process\Monitor::class,
        'reloadable' => false,
        'constructor' => [
            // Monitor these directories
            'monitorDir' => array_merge([
                app_path(),
                config_path(),
                base_path() . '/process',
                base_path() . '/support',
                base_path() . '/resource',
                base_path() . '/.env',
            ], glob(base_path() . '/plugin/*/app'), glob(base_path() . '/plugin/*/config'), glob(base_path() . '/plugin/*/api')),
            // Files with these suffixes will be monitored
            'monitorExtensions' => [
                'php', 'html', 'htm', 'env'
            ],
            'options' => [
                'enable_file_monitor' => !in_array('-d', $argv) && DIRECTORY_SEPARATOR === '/',
                'enable_memory_monitor' => DIRECTORY_SEPARATOR === '/',
            ]
        ]
    ],
    'sign-worker' => [
        'handler' => app\process\SignWorker::class,
        'count' => (int)(getenv('SIGN_WORKER_COUNT') ?: 1),
        'reloadable' => true,
        'enable' => filter_var(getenv('SIGN_WORKER_ENABLED') ?: false, FILTER_VALIDATE_BOOL),
    ],
    'tieba-retry-worker' => [
        'handler' => app\process\TiebaRetryWorker::class,
        'count' => (int)(getenv('TIEBA_RETRY_WORKER_COUNT') ?: 1),
        'reloadable' => true,
        // 旧版 .env 没有专用开关时跟随普通签到 Worker，升级后无需额外配置。
        'enable' => filter_var(
            getenv('TIEBA_RETRY_WORKER_ENABLED') !== false
                ? getenv('TIEBA_RETRY_WORKER_ENABLED')
                : (getenv('SIGN_WORKER_ENABLED') ?: false),
            FILTER_VALIDATE_BOOL
        ),
    ],
    'sign-scheduler' => [
        'handler' => app\process\SignScheduler::class,
        'count' => 1,
        'reloadable' => true,
        'enable' => filter_var(getenv('SIGN_SCHEDULER_ENABLED') ?: false, FILTER_VALIDATE_BOOL),
    ],
    'mail-worker' => [
        'handler' => app\process\MailWorker::class,
        'count' => 1,
        'reloadable' => true,
        'enable' => filter_var(getenv('MAIL_WORKER_ENABLED') ?: true, FILTER_VALIDATE_BOOL),
    ],
    'maintenance' => [
        'handler' => app\process\MaintenanceWorker::class,
        'count' => 1,
        'reloadable' => true,
        'enable' => filter_var(getenv('MAINTENANCE_ENABLED') ?: false, FILTER_VALIDATE_BOOL),
    ],
];
