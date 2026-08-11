<?php

namespace app\service;

use app\exception\ApiException;
use app\sign\executor\SensitiveDataRedactor;
use app\sign\executor\SignTaskExecutor;
use plugin\bilibili\app\BilibiliAsyncHttpClient;
use plugin\bilibili\app\BilibiliLiveHeartbeatSigner;
use support\Db;
use support\Log;
use Throwable;
use Workerman\Http\Response;

/** 使用数据库状态机推进粉丝勋章直播挂机，每次调用最多发起有限个异步请求。 */
final class BilibiliLiveSessionService
{
    private const TERMINAL_ROOM_STATUSES = ['completed', 'failed', 'skipped'];
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 TF-Sign/1.0';

    public function __construct(private readonly BilibiliAsyncHttpClient $http = new BilibiliAsyncHttpClient())
    {
    }

    public function initializeNextTask(string $workerId): bool
    {
        $candidate = Db::table('TF_sign_tasks')
            ->where('queue_name', SignTaskExecutor::QUEUE_BILIBILI_LIVE)
            ->whereIn('status', ['pending', 'retrying'])
            ->where('available_at', '<=', date('Y-m-d H:i:s'))
            ->orderByDesc('priority')
            ->orderBy('id')
            ->first();
        if (!$candidate) {
            return false;
        }
        $now = date('Y-m-d H:i:s.v');
        $claimed = Db::table('TF_sign_tasks')->where('id', $candidate->id)
            ->whereIn('status', ['pending', 'retrying'])
            ->update([
                'status' => 'running',
                'attempts' => Db::raw('attempts + 1'),
                'locked_at' => $now,
                'locked_by' => $workerId,
                'started_at' => Db::raw('COALESCE(started_at, NOW(3))'),
                'updated_at' => $now,
            ]);
        if ($claimed !== 1) {
            return false;
        }

        try {
            $task = Db::table('TF_sign_tasks')->where('id', $candidate->id)->first();
            $account = (new PluginAccountService())->findOwned((int)$task->user_id, (int)$task->account_id);
            if (!in_array((string)$account->status, ['active', 'pending_verification'], true)) {
                throw new ApiException('PLUGIN_ACCOUNT_DISABLED', '插件账号当前不可执行', 409);
            }
            $accountSettings = json_decode((string)($account->settings_json ?? '{}'), true) ?: [];
            // 开源版没有私有版的插件级全局开关，直接使用账号设置与安全默认值。
            $global = [];
            $target = max(1, min(180, (int)($accountSettings['live_heartbeat_count'] ?? $global['live_default_heartbeat_count'] ?? 70)));
            $maxRooms = max(1, min(
                (int)($global['live_max_rooms_per_account'] ?? 5),
                (int)($accountSettings['live_max_rooms'] ?? $global['live_max_rooms_per_account'] ?? 5)
            ));
            $maxFailures = max(1, min(20, (int)($accountSettings['live_max_failures'] ?? $global['live_max_failures'] ?? 5)));
            $runId = (int)Db::table('TF_sign_runs')->insertGetId([
                'run_no' => bin2hex(random_bytes(16)),
                'task_id' => $task->id,
                'attempt_no' => $task->attempts,
                'plugin_code' => 'bilibili',
                'plugin_version' => '0.4.0',
                'worker_id' => $workerId,
                'status' => 'running',
                'started_at' => $now,
                'created_at' => $now,
            ]);
            Db::table('TF_bilibili_live_sessions')->insert([
                'session_no' => bin2hex(random_bytes(16)),
                'task_id' => $task->id,
                'run_id' => $runId,
                'user_id' => $task->user_id,
                'account_id' => $task->account_id,
                'status' => 'discovering',
                'target_heartbeat_count' => $target,
                'max_failures' => $maxFailures,
                'max_rooms' => $maxRooms,
                'settings_json' => json_encode($accountSettings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'summary_json' => json_encode(['stage' => 'cookie'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'next_action_at' => $now,
                'expires_at' => date('Y-m-d H:i:s', time() + $target * 70 + 1800),
                'started_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return true;
        } catch (Throwable $exception) {
            $this->failTask((int)$candidate->id, $exception);
            return false;
        }
    }

    public function dispatchDue(string $workerId, int $limit, callable $settled): int
    {
        $remaining = max(0, $limit);
        $started = 0;
        for ($scan = 0; $scan < max(1, $limit * 2) && $remaining > 0; $scan++) {
            $session = $this->claimDueSession($workerId);
            if (!$session) {
                break;
            }
            if ($this->advanceDiscovery($session, $settled)) {
                $started++;
                $remaining--;
            }
        }
        for ($scan = 0; $scan < $limit && $remaining > 0; $scan++) {
            $room = $this->claimDueRoom($workerId);
            if (!$room) {
                break;
            }
            if ($this->advanceRoom($room, $settled)) {
                $started++;
                $remaining--;
            }
        }
        return $started;
    }

    public function recover(): void
    {
        $now = date('Y-m-d H:i:s.v');
        Db::table('TF_bilibili_live_sessions')
            ->whereIn('status', ['discovering', 'watching'])
            ->whereNotNull('lock_until')->where('lock_until', '<', $now)
            ->update(['lock_token' => null, 'lock_owner' => null, 'lock_until' => null, 'next_action_at' => $now, 'updated_at' => $now]);
        Db::table('TF_bilibili_live_room_sessions')
            ->whereNotIn('status', self::TERMINAL_ROOM_STATUSES)
            ->whereNotNull('lock_until')->where('lock_until', '<', $now)
            ->update(['lock_token' => null, 'lock_owner' => null, 'lock_until' => null, 'next_heartbeat_at' => $now, 'updated_at' => $now]);

        // 修复异常退出后遗留的“可运行但没有下一次时间”的状态，否则永远无法再次领取。
        Db::table('TF_bilibili_live_sessions')
            ->where('status', 'discovering')->whereNull('next_action_at')->whereNull('lock_token')
            ->update(['next_action_at' => $now, 'updated_at' => $now]);
        Db::table('TF_bilibili_live_room_sessions')
            ->whereNotIn('status', self::TERMINAL_ROOM_STATUSES)
            ->whereNull('next_heartbeat_at')->whereNull('lock_token')
            ->update(['next_heartbeat_at' => $now, 'updated_at' => $now]);

        $expired = Db::table('TF_bilibili_live_sessions')
            ->whereIn('status', ['discovering', 'watching'])->where('expires_at', '<=', $now)->limit(100)->get();
        foreach ($expired as $session) {
            Db::table('TF_bilibili_live_room_sessions')->where('session_id', $session->id)
                ->whereNotIn('status', self::TERMINAL_ROOM_STATUSES)->update([
                    'status' => 'failed',
                    'last_error_code' => 'LIVE_SESSION_EXPIRED',
                    'last_error_message' => '直播挂机超过截止时间',
                    'finished_at' => $now,
                    'lock_token' => null,
                    'lock_owner' => null,
                    'lock_until' => null,
                    'updated_at' => $now,
                ]);
            $this->finalize((int)$session->id, 'LIVE_SESSION_EXPIRED');
        }

        // Worker 可能在最后一个房间落库后、会话收尾前退出。定时对账保证任务不会永久显示执行中。
        $watching = Db::table('TF_bilibili_live_sessions as s')
            ->where('s.status', 'watching')
            ->whereRaw(
                "NOT EXISTS (SELECT 1 FROM TF_bilibili_live_room_sessions room "
                . "WHERE room.session_id = s.id AND room.status NOT IN ('completed', 'failed', 'skipped'))"
            )
            ->orderBy('s.id')->limit(200)->select('s.*')->get();
        foreach ($watching as $session) {
            $this->finalizeIfDone((int)$session->id);
        }

        $terminal = Db::table('TF_bilibili_live_sessions as s')
            ->join('TF_sign_tasks as t', 't.id', '=', 's.task_id')
            ->join('TF_sign_runs as r', 'r.id', '=', 's.run_id')
            ->whereIn('s.status', ['completed', 'failed'])
            ->where(function ($query): void {
                $query->where('t.status', 'running')->orWhere('r.status', 'running');
            })
            ->orderBy('s.id')->limit(100)->select('s.*')->get();
        foreach ($terminal as $session) {
            $this->reconcileTerminalSession($session);
        }

        // 领取任务后若进程在创建直播会话前退出，任务没有任何会话可恢复，直接明确失败。
        $orphanedTasks = Db::table('TF_sign_tasks as t')
            ->leftJoin('TF_bilibili_live_sessions as s', 's.task_id', '=', 't.id')
            ->where('t.plugin_code', 'bilibili')->where('t.action', 'live_fans_medal')
            ->where('t.status', 'running')->whereNull('s.id')
            ->whereNotNull('t.locked_at')
            ->where('t.locked_at', '<', date('Y-m-d H:i:s', time() - $this->lockTtl() * 2))
            ->orderBy('t.id')->limit(100)->get(['t.id']);
        foreach ($orphanedTasks as $task) {
            $this->failTask(
                (int)$task->id,
                new ApiException('BILIBILI_LIVE_SESSION_MISSING', '直播挂机会话创建中断，请重新执行任务', 500)
            );
        }
    }

    private function advanceDiscovery(object $session, callable $settled): bool
    {
        $summary = json_decode((string)($session->summary_json ?? '{}'), true) ?: [];
        $stage = (string)($summary['stage'] ?? 'cookie');
        try {
            $credentials = $this->credentials($session);
            if ($stage === 'cookie' && !empty($credentials['live_buvid'])) {
                $this->releaseSession($session, ['summary_json' => json_encode(['stage' => 'medal_wall']), 'next_action_at' => date('Y-m-d H:i:s.v')]);
                return false;
            }
            if ($stage === 'cookie') {
                $this->http->request('GET', 'https://api.live.bilibili.com/news/v1/notice/recom?product=live', $credentials, [
                    'referer' => 'https://live.bilibili.com/',
                ], function (array $json, Response $response) use ($session, $settled): void {
                    try {
                        $this->assertApiSuccess($json);
                        $patch = $this->runtimeCookies($response);
                        if (empty($patch['live_buvid'])) {
                            throw new ApiException('BILIBILI_LIVE_COOKIE_MISSING', '未能获取 LIVE_BUVID，请重新扫码登录', 422);
                        }
                        (new PluginAccountService())->mergeRuntimeCredentials((int)$session->account_id, $patch);
                        $this->releaseSession($session, [
                            'summary_json' => json_encode(['stage' => 'medal_wall']),
                            'next_action_at' => date('Y-m-d H:i:s.v'),
                        ]);
                    } catch (Throwable $exception) {
                        $this->failSession((int)$session->id, $exception);
                    } finally {
                        $settled();
                    }
                }, function (Throwable $exception) use ($session, $settled): void {
                    try {
                        $this->retrySession($session, $exception);
                    } finally {
                        $settled();
                    }
                });
                return true;
            }

            $this->http->request('GET', 'https://api.live.bilibili.com/xlive/web-ucenter/user/MedalWall', $credentials, [
                'query' => ['target_id' => $credentials['dede_user_id']],
                'referer' => 'https://live.bilibili.com/',
                'origin' => 'https://live.bilibili.com',
            ], function (array $json) use ($session, $settled): void {
                try {
                    $this->assertApiSuccess($json);
                    $this->storeMedalRooms($session, (array)($json['data']['list'] ?? []));
                } catch (Throwable $exception) {
                    $this->failSession((int)$session->id, $exception);
                } finally {
                    $settled();
                }
            }, function (Throwable $exception) use ($session, $settled): void {
                try {
                    $this->retrySession($session, $exception);
                } finally {
                    $settled();
                }
            });
            return true;
        } catch (Throwable $exception) {
            $this->failSession((int)$session->id, $exception);
            return false;
        }
    }

    private function advanceRoom(object $room, callable $settled): bool
    {
        $session = Db::table('TF_bilibili_live_sessions')->where('id', $room->session_id)->first();
        if (!$session || !in_array((string)$session->status, ['discovering', 'watching'], true)) {
            $this->releaseRoom($room, ['status' => 'skipped', 'finished_at' => date('Y-m-d H:i:s.v')]);
            return false;
        }
        try {
            $credentials = $this->credentials($session);
            $settings = json_decode((string)($session->settings_json ?? '{}'), true) ?: [];
            $success = function (callable $callback) use ($room, $settled): callable {
                return function (array $json) use ($callback, $room, $settled): void {
                    try {
                        $callback($json);
                    } catch (Throwable $exception) {
                        $this->retryRoom($room, $exception);
                    } finally {
                        $settled();
                    }
                };
            };
            $failure = function (Throwable $exception) use ($room, $settled): void {
                try {
                    $this->retryRoom($room, $exception);
                } finally {
                    $settled();
                }
            };

            if ($room->status === 'space_info') {
                // 旧的空间资料接口已要求 WBI 签名。直播端接口可直接按主播 UID
                // 解析房间号，不会因缺少 WBI 参数一直重试而无法进入心跳阶段。
                $this->http->request('GET', 'https://api.live.bilibili.com/room/v1/Room/getRoomInfoOld', $credentials, [
                    'query' => ['mid' => $room->medal_target_id],
                    'referer' => 'https://live.bilibili.com/',
                ], $success(function (array $json) use ($room): void {
                    $this->assertApiSuccess($json);
                    $roomId = (int)($json['data']['roomid'] ?? 0);
                    if ($roomId <= 0) {
                        $this->releaseRoom($room, ['status' => 'skipped', 'last_error_code' => 'LIVE_ROOM_NOT_FOUND', 'last_error_message' => '该粉丝牌主播没有直播间', 'finished_at' => date('Y-m-d H:i:s.v')]);
                        $this->finalizeIfDone((int)$room->session_id);
                        return;
                    }
                    $this->releaseRoom($room, ['room_id' => $roomId, 'status' => 'room_info', 'next_heartbeat_at' => date('Y-m-d H:i:s.v')]);
                }), $failure);
                return true;
            }

            if ($room->status === 'room_info') {
                $this->http->request('GET', 'https://api.live.bilibili.com/room/v1/Room/get_info', $credentials, [
                    'query' => ['room_id' => $room->room_id, 'from' => 'room'],
                    'referer' => 'https://live.bilibili.com/' . $room->room_id,
                ], $success(function (array $json) use ($room, $settings, $session): void {
                    $this->assertApiSuccess($json);
                    $data = (array)($json['data'] ?? []);
                    if ((int)($data['live_status'] ?? 0) === 0) {
                        $this->releaseRoom($room, ['status' => 'skipped', 'last_error_code' => 'LIVE_ROOM_OFFLINE', 'last_error_message' => '主播当前未开播', 'finished_at' => date('Y-m-d H:i:s.v')]);
                        $this->finalizeIfDone((int)$room->session_id);
                        return;
                    }
                    $selected = Db::table('TF_bilibili_live_room_sessions')
                        ->where('session_id', $room->session_id)
                        ->whereIn('status', ['danmaku', 'like', 'entering', 'watching', 'completed'])
                        ->count();
                    if ($selected >= (int)$session->max_rooms) {
                        $this->releaseRoom($room, ['status' => 'skipped', 'last_error_code' => 'LIVE_ROOM_LIMIT_REACHED', 'last_error_message' => '已达到本账号最大挂机直播间数量', 'finished_at' => date('Y-m-d H:i:s.v')]);
                        $this->finalizeIfDone((int)$room->session_id);
                        return;
                    }
                    $next = ($settings['live_send_danmaku_enabled'] ?? false) === true ? 'danmaku'
                        : (($settings['live_like_enabled'] ?? true) === true ? 'like' : 'entering');
                    $this->releaseRoom($room, [
                        'ruid' => (int)($data['uid'] ?? $room->ruid),
                        'parent_area_id' => (int)($data['parent_area_id'] ?? 0),
                        'area_id' => (int)($data['area_id'] ?? 0),
                        'room_name' => (string)($data['title'] ?? $room->room_name),
                        'status' => $next,
                        'next_heartbeat_at' => date('Y-m-d H:i:s.v'),
                    ]);
                    $this->writeRoomProgress(
                        (int)$room->session_id,
                        (int)$room->id,
                        (int)$room->heartbeat_count,
                        (int)$session->target_heartbeat_count,
                        false
                    );
                }), $failure);
                return true;
            }

            if ($room->status === 'danmaku') {
                $this->http->request('POST', 'https://api.live.bilibili.com/msg/send', $credentials, [
                    'form' => [
                        'bubble' => '0', 'msg' => (string)($settings['live_danmaku_content'] ?? 'OvO'),
                        'color' => '16777215', 'mode' => '1', 'fontsize' => '25', 'rnd' => time(),
                        'roomid' => $room->room_id, 'csrf' => $credentials['bili_jct'], 'csrf_token' => $credentials['bili_jct'],
                    ],
                    'referer' => 'https://live.bilibili.com/' . $room->room_id,
                    'origin' => 'https://live.bilibili.com',
                ], $success(function (array $json) use ($room, $settings): void {
                    $this->assertApiSuccess($json);
                    $this->releaseRoom($room, ['status' => ($settings['live_like_enabled'] ?? true) === true ? 'like' : 'entering', 'next_heartbeat_at' => date('Y-m-d H:i:s.v')]);
                }), $failure);
                return true;
            }

            if ($room->status === 'like') {
                $this->http->request('POST', 'https://api.live.bilibili.com/xlive/app-ucenter/v1/like_info_v3/like/likeReportV3', $credentials, [
                    'form' => [
                        'click_time' => max(1, min(100, (int)($settings['live_like_count'] ?? 30))),
                        'room_id' => $room->room_id, 'uid' => $credentials['dede_user_id'], 'anchor_id' => $room->ruid,
                        'csrf_token' => $credentials['bili_jct'], 'csrf' => $credentials['bili_jct'],
                    ],
                    'referer' => 'https://live.bilibili.com/' . $room->room_id,
                    'origin' => 'https://live.bilibili.com',
                ], $success(function (array $json) use ($room): void {
                    $this->assertApiSuccess($json);
                    $this->releaseRoom($room, ['status' => 'entering', 'next_heartbeat_at' => date('Y-m-d H:i:s.v')]);
                }), $failure);
                return true;
            }

            if (!in_array((string)$room->status, ['entering', 'watching'], true)) {
                throw new ApiException('BILIBILI_LIVE_STATE_INVALID', '直播挂机房间状态异常：' . (string)$room->status, 500);
            }
            $signer = new BilibiliLiveHeartbeatSigner();
            $entering = $room->status === 'entering';
            $form = $entering
                ? $signer->enterForm($room, $credentials, self::USER_AGENT)
                : $signer->heartbeatForm($room, $credentials, self::USER_AGENT);
            $endpoint = $entering
                ? 'https://live-trace.bilibili.com/xlive/data-interface/v1/x25Kn/E'
                : 'https://live-trace.bilibili.com/xlive/data-interface/v1/x25Kn/X';
            $this->http->request('POST', $endpoint, $credentials, [
                'form' => $form,
                'referer' => 'https://live.bilibili.com/' . $room->room_id,
                'origin' => 'https://live.bilibili.com',
                'user_agent' => self::USER_AGENT,
            ], $success(function (array $json) use ($room, $session): void {
                $this->assertApiSuccess($json);
                $data = (array)($json['data'] ?? []);
                $count = (int)$room->heartbeat_count + 1;
                $target = (int)$session->target_heartbeat_count;
                $completed = $count >= $target;
                $interval = max(45, min(120, (int)($data['heartbeat_interval'] ?? 60)));
                $this->releaseRoom($room, [
                    'status' => $completed ? 'completed' : 'watching',
                    'sequence_no' => (int)$room->sequence_no + 1,
                    'heartbeat_count' => $count,
                    'consecutive_failures' => 0,
                    'server_timestamp' => (int)($data['timestamp'] ?? $room->server_timestamp),
                    'secret_key' => (string)($data['secret_key'] ?? $room->secret_key),
                    'secret_rule_json' => json_encode((array)($data['secret_rule'] ?? json_decode((string)$room->secret_rule_json, true) ?: [])),
                    'next_heartbeat_at' => $completed ? null : date('Y-m-d H:i:s', time() + $interval + random_int(1, 4)),
                    'last_error_code' => null,
                    'last_error_message' => null,
                    'finished_at' => $completed ? date('Y-m-d H:i:s.v') : null,
                ]);
                $this->writeRoomProgress((int)$room->session_id, (int)$room->id, $count, $target, $completed);
                $this->finalizeIfDone((int)$room->session_id);
            }), $failure);
            return true;
        } catch (Throwable $exception) {
            $this->retryRoom($room, $exception);
            return false;
        }
    }

    private function claimDueSession(string $workerId): ?object
    {
        $now = date('Y-m-d H:i:s.v');
        $row = Db::table('TF_bilibili_live_sessions')->where('status', 'discovering')
            ->where('next_action_at', '<=', $now)
            ->where(fn ($query) => $query->whereNull('lock_until')->orWhere('lock_until', '<', $now))
            ->orderBy('next_action_at')->orderBy('id')->first();
        if (!$row) return null;
        $token = bin2hex(random_bytes(16));
        $claimed = Db::table('TF_bilibili_live_sessions')->where('id', $row->id)
            ->where(fn ($query) => $query->whereNull('lock_until')->orWhere('lock_until', '<', $now))
            ->update(['lock_token' => $token, 'lock_owner' => $workerId, 'lock_until' => date('Y-m-d H:i:s', time() + $this->lockTtl()), 'updated_at' => $now]);
        if ($claimed !== 1) return null;
        $row->lock_token = $token;
        return $row;
    }

    private function claimDueRoom(string $workerId): ?object
    {
        $now = date('Y-m-d H:i:s.v');
        $row = Db::table('TF_bilibili_live_room_sessions as r')
            ->join('TF_bilibili_live_sessions as s', 's.id', '=', 'r.session_id')
            ->where('s.status', 'watching')->whereNotIn('r.status', self::TERMINAL_ROOM_STATUSES)
            ->where('r.next_heartbeat_at', '<=', $now)
            ->where(fn ($query) => $query->whereNull('r.lock_until')->orWhere('r.lock_until', '<', $now))
            ->whereRaw(
                'NOT EXISTS (SELECT 1 FROM TF_bilibili_live_room_sessions busy '
                . 'INNER JOIN TF_bilibili_live_sessions busy_session ON busy_session.id = busy.session_id '
                . 'WHERE busy_session.account_id = s.account_id AND busy.lock_until >= ?)',
                [$now]
            )
            ->orderBy('r.next_heartbeat_at')->orderBy('r.id')->select('r.*')->first();
        if (!$row) return null;
        $token = bin2hex(random_bytes(16));
        $claimed = Db::transaction(function () use ($row, $token, $workerId, $now): int {
            $session = Db::table('TF_bilibili_live_sessions')->where('id', $row->session_id)->first();
            if (!$session) return 0;
            // 锁住账号行作为跨 Worker 的账号级信号量，保证同一账号同时最多一个直播请求。
            Db::table('TF_plugin_accounts')->where('id', $session->account_id)->lockForUpdate()->first();
            $busy = Db::table('TF_bilibili_live_room_sessions as room')
                ->join('TF_bilibili_live_sessions as live', 'live.id', '=', 'room.session_id')
                ->where('live.account_id', $session->account_id)
                ->where('room.lock_until', '>=', $now)
                ->exists();
            if ($busy) return 0;
            return Db::table('TF_bilibili_live_room_sessions')->where('id', $row->id)
                ->where(fn ($query) => $query->whereNull('lock_until')->orWhere('lock_until', '<', $now))
                ->update(['lock_token' => $token, 'lock_owner' => $workerId, 'lock_until' => date('Y-m-d H:i:s', time() + $this->lockTtl()), 'request_started_at' => $now, 'updated_at' => $now]);
        });
        if ($claimed !== 1) return null;
        $row->lock_token = $token;
        return $row;
    }

    private function storeMedalRooms(object $session, array $medals): void
    {
        $settings = json_decode((string)($session->settings_json ?? '{}'), true) ?: [];
        $skipLevel20 = ($settings['live_skip_level_20_medal'] ?? true) === true;
        $stored = 0;
        $now = date('Y-m-d H:i:s.v');
        foreach ($medals as $medal) {
            if ($stored >= 200 || !is_array($medal)) continue;
            $info = is_array($medal['medal_info'] ?? null) ? $medal['medal_info'] : [];
            $level = (int)($info['level'] ?? 0);
            if ($skipLevel20 && $level >= 20) continue;
            // MedalWall 已明确标记未开播时无需再创建房间状态机；字段缺失时仍继续解析以兼容旧响应。
            if (array_key_exists('live_status', $medal) && (int)$medal['live_status'] === 0) continue;
            $targetId = (int)($info['target_id'] ?? 0);
            if ($targetId <= 0) continue;
            $link = (string)($medal['link'] ?? '');
            $linkHost = strtolower((string)(parse_url($link, PHP_URL_HOST) ?: ''));
            $hasRoomLink = ($linkHost === 'live.bilibili.com' || str_ends_with($linkHost, '.live.bilibili.com'))
                && preg_match('~/([1-9][0-9]{0,19})(?:[/?#]|$)~', $link, $matches) === 1;
            // 没有可信直播间链接时先以主播 UID 占位，下一 tick 再由直播接口解析房间号。
            $roomId = $hasRoomLink ? (int)$matches[1] : $targetId;
            $inserted = Db::table('TF_bilibili_live_room_sessions')->insertOrIgnore([
                'session_id' => $session->id,
                'room_id' => $roomId,
                'ruid' => $targetId,
                'medal_target_id' => $targetId,
                'room_name' => mb_substr((string)($medal['target_name'] ?? ''), 0, 255),
                'medal_name' => mb_substr((string)($info['medal_name'] ?? ''), 0, 120),
                'medal_level' => $level,
                'status' => $hasRoomLink ? 'room_info' : 'space_info',
                'next_heartbeat_at' => $now,
                'started_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($inserted > 0) {
                $stored++;
            }
        }
        $this->releaseSession($session, [
            'status' => 'watching',
            'summary_json' => json_encode(['stage' => 'watching', 'rooms' => $stored]),
            'next_action_at' => null,
        ]);
        if ($stored === 0) {
            $this->finalize((int)$session->id, null, '没有符合条件且正在直播的粉丝勋章直播间');
        }
    }

    private function credentials(object $session): array
    {
        $account = (new PluginAccountService())->findOwned((int)$session->user_id, (int)$session->account_id);
        return (new PluginAccountService())->decryptedCredential($account);
    }

    private function runtimeCookies(Response $response): array
    {
        $values = $response->getHeader('Set-Cookie');
        $values = is_array($values) ? $values : [$values];
        $patch = [];
        foreach ($values as $line) {
            foreach (['LIVE_BUVID' => 'live_buvid', 'buvid3' => 'buvid3', 'buvid4' => 'buvid4'] as $name => $key) {
                if (preg_match('/(?:^|,\s*)' . preg_quote($name, '/') . '=([^;]+)/i', (string)$line, $matches)) {
                    $patch[$key] = rawurldecode($matches[1]);
                }
            }
        }
        return $patch;
    }

    private function assertApiSuccess(array $json): void
    {
        $code = (int)($json['code'] ?? -1);
        if ($code === -101) {
            throw new ApiException('PLUGIN_CREDENTIAL_EXPIRED', '哔哩哔哩登录状态已经失效', 422);
        }
        if ($code !== 0) {
            throw new ApiException('BILIBILI_API_FAILED', (string)($json['message'] ?? $json['msg'] ?? '哔哩哔哩接口请求失败'), 502);
        }
    }

    private function retrySession(object $session, Throwable $exception): void
    {
        $message = $this->safeMessage($exception);
        $summary = json_decode((string)$session->summary_json, true) ?: [];
        $failures = (int)($summary['consecutive_failures'] ?? 0) + 1;
        if ($failures >= (int)$session->max_failures) {
            $this->failSession((int)$session->id, $exception);
            return;
        }
        $this->releaseSession($session, [
            'next_action_at' => date('Y-m-d H:i:s', time() + min(300, 10 * (2 ** min(5, $failures - 1)))),
            'summary_json' => json_encode(['stage' => $summary['stage'] ?? 'cookie', 'consecutive_failures' => $failures, 'last_error' => $message]),
        ]);
    }

    private function retryRoom(object $room, Throwable $exception): void
    {
        $failures = (int)$room->consecutive_failures + 1;
        $session = Db::table('TF_bilibili_live_sessions')->where('id', $room->session_id)->first();
        if (!$session) return;
        if ($exception instanceof ApiException && $exception->errorCode === 'PLUGIN_CREDENTIAL_EXPIRED') {
            $now = date('Y-m-d H:i:s.v');
            Db::table('TF_bilibili_live_room_sessions')->where('session_id', $room->session_id)
                ->whereNotIn('status', self::TERMINAL_ROOM_STATUSES)->update([
                    'status' => 'failed', 'last_error_code' => $exception->errorCode,
                    'last_error_message' => $this->safeMessage($exception), 'next_heartbeat_at' => null,
                    'finished_at' => $now, 'lock_token' => null, 'lock_owner' => null,
                    'lock_until' => null, 'request_started_at' => null, 'updated_at' => $now,
                ]);
            Db::table('TF_plugin_accounts')->where('id', $session->account_id)->update([
                'status' => 'credential_expired', 'next_run_at' => null,
                'last_error_code' => $exception->errorCode, 'last_error_message' => $this->safeMessage($exception),
                'updated_at' => $now,
            ]);
            $this->finalize((int)$room->session_id, $exception->errorCode);
            return;
        }
        $terminal = $failures >= (int)($session->max_failures ?? 5);
        $this->releaseRoom($room, [
            'status' => $terminal ? 'failed' : $room->status,
            'consecutive_failures' => $failures,
            'last_error_code' => $exception instanceof ApiException ? $exception->errorCode : 'UPSTREAM_REQUEST_FAILED',
            'last_error_message' => $this->safeMessage($exception),
            'next_heartbeat_at' => $terminal ? null : date('Y-m-d H:i:s', time() + min(300, 10 * (2 ** min(5, $failures - 1)))),
            'finished_at' => $terminal ? date('Y-m-d H:i:s.v') : null,
        ]);
        $this->finalizeIfDone((int)$room->session_id);
    }

    private function releaseSession(object $session, array $updates): void
    {
        Db::table('TF_bilibili_live_sessions')->where('id', $session->id)->where('lock_token', $session->lock_token)->update($updates + [
            'lock_token' => null, 'lock_owner' => null, 'lock_until' => null, 'updated_at' => date('Y-m-d H:i:s.v'),
        ]);
    }

    private function releaseRoom(object $room, array $updates): void
    {
        Db::table('TF_bilibili_live_room_sessions')->where('id', $room->id)->where('lock_token', $room->lock_token)->update($updates + [
            'lock_token' => null, 'lock_owner' => null, 'lock_until' => null, 'request_started_at' => null, 'updated_at' => date('Y-m-d H:i:s.v'),
        ]);
    }

    private function failSession(int $sessionId, Throwable $exception): void
    {
        $session = Db::table('TF_bilibili_live_sessions')->where('id', $sessionId)->first();
        if (!$session) return;
        $errorCode = $exception instanceof ApiException ? $exception->errorCode : 'BILIBILI_LIVE_FAILED';
        Db::table('TF_bilibili_live_sessions')->where('id', $sessionId)->update([
            'status' => 'failed', 'summary_json' => json_encode([
                'error_code' => $errorCode,
                'error' => $this->safeMessage($exception),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'finished_at' => date('Y-m-d H:i:s.v'), 'lock_token' => null, 'lock_owner' => null, 'lock_until' => null, 'updated_at' => date('Y-m-d H:i:s.v'),
        ]);
        $this->finishTask($session, 'failed', 0, 0, 1, $errorCode, $this->safeMessage($exception));
    }

    private function finalizeIfDone(int $sessionId): void
    {
        $active = Db::table('TF_bilibili_live_room_sessions')->where('session_id', $sessionId)->whereNotIn('status', self::TERMINAL_ROOM_STATUSES)->exists();
        if (!$active) $this->finalize($sessionId);
    }

    /** 会话已落入终态但任务/运行记录仍是 running 时，依据房间终态重新对账。 */
    private function reconcileTerminalSession(object $session): void
    {
        $rooms = Db::table('TF_bilibili_live_room_sessions')->where('session_id', $session->id)->get();
        $success = $rooms->where('status', 'completed')->count();
        $failed = $rooms->where('status', 'failed')->count();
        $skipped = $rooms->where('status', 'skipped')->count();
        if ((string)$session->status === 'failed' && $failed === 0) {
            $failed = 1;
        }
        $status = (string)$session->status === 'failed'
            ? ($success + $skipped > 0 ? 'partial' : 'failed')
            : ($failed > 0 ? ($success + $skipped > 0 ? 'partial' : 'failed') : 'succeeded');
        $summary = json_decode((string)($session->summary_json ?? '{}'), true) ?: [];
        $errorCode = $status === 'failed' || $status === 'partial'
            ? (string)($summary['error_code'] ?? 'BILIBILI_LIVE_FAILED')
            : null;
        $message = isset($summary['error']) ? mb_substr((string)$summary['error'], 0, 500) : null;
        $this->finishTask($session, $status, $success, $skipped, $failed, $errorCode, $message);
    }

    private function finalize(int $sessionId, ?string $errorCode = null, ?string $emptyMessage = null): void
    {
        $session = Db::table('TF_bilibili_live_sessions')->where('id', $sessionId)->first();
        if (!$session || in_array((string)$session->status, ['completed', 'failed', 'cancelled'], true)) return;
        $rooms = Db::table('TF_bilibili_live_room_sessions')->where('session_id', $sessionId)->orderBy('id')->get();
        $success = $rooms->where('status', 'completed')->count();
        $failed = $rooms->where('status', 'failed')->count();
        $skipped = $rooms->where('status', 'skipped')->count();
        $status = $failed > 0 && $success + $skipped > 0 ? 'partial' : ($failed > 0 ? 'failed' : 'succeeded');
        $now = date('Y-m-d H:i:s.v');
        $sequence = 0;
        foreach ($rooms as $room) {
            $sequence++;
            Db::table('TF_sign_records')->insertOrIgnore([
                'task_id' => $session->task_id, 'run_id' => $session->run_id, 'user_id' => $session->user_id,
                'account_id' => $session->account_id, 'plugin_code' => 'bilibili',
                'record_key' => 'live:room:' . $room->room_id, 'sequence_no' => $sequence, 'schema_version' => 1,
                'action' => 'live_fans_medal', 'target_type' => 'live', 'target_id' => (string)$room->room_id,
                'target_name' => $room->room_name ?: ('直播间 ' . $room->room_id), 'status' => $room->status === 'completed' ? 'succeeded' : $room->status,
                'result_code' => $room->last_error_code ?: ($room->status === 'completed' ? '0' : strtoupper((string)$room->status)),
                'message' => $room->last_error_message ?: ($room->status === 'completed' ? '直播挂机完成' : '直播间已跳过'),
                'reward_json' => '[]',
                'metrics_json' => json_encode(['heartbeat_count' => (int)$room->heartbeat_count, 'target' => (int)$session->target_heartbeat_count, 'medal_name' => $room->medal_name, 'medal_level' => (int)$room->medal_level], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'safe_result_json' => '{}', 'started_at' => $room->started_at, 'finished_at' => $room->finished_at ?: $now, 'created_at' => $now,
            ]);
            // 运行中已创建过进度记录时，将同一记录收敛为最终状态。
            Db::table('TF_sign_records')->where('run_id', $session->run_id)->where('record_key', 'live:room:' . $room->room_id)->update([
                'target_id' => (string)$room->room_id,
                'target_name' => $room->room_name ?: ('直播间 ' . $room->room_id),
                'status' => $room->status === 'completed' ? 'succeeded' : $room->status,
                'result_code' => $room->last_error_code ?: ($room->status === 'completed' ? '0' : strtoupper((string)$room->status)),
                'message' => $room->last_error_message ?: ($room->status === 'completed' ? '直播挂机完成' : '直播间已跳过'),
                'metrics_json' => json_encode(['heartbeat_count' => (int)$room->heartbeat_count, 'target' => (int)$session->target_heartbeat_count, 'medal_name' => $room->medal_name, 'medal_level' => (int)$room->medal_level], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'finished_at' => $room->finished_at ?: $now,
            ]);
        }
        if ($rooms->isEmpty()) {
            Db::table('TF_sign_records')->insertOrIgnore([
                'task_id' => $session->task_id, 'run_id' => $session->run_id, 'user_id' => $session->user_id, 'account_id' => $session->account_id,
                'plugin_code' => 'bilibili', 'record_key' => 'live:discovery', 'sequence_no' => 1, 'schema_version' => 1,
                'action' => 'live_fans_medal', 'target_type' => 'live', 'target_name' => '粉丝勋章直播间', 'status' => 'skipped',
                'result_code' => 'NO_ACTIVE_LIVE_ROOMS', 'message' => $emptyMessage ?? '没有符合条件的直播间',
                'reward_json' => '[]', 'metrics_json' => '{}', 'safe_result_json' => '{}', 'finished_at' => $now, 'created_at' => $now,
            ]);
            $skipped = 1;
        }
        Db::table('TF_bilibili_live_sessions')->where('id', $sessionId)->update([
            'status' => $status === 'failed' ? 'failed' : 'completed',
            'summary_json' => json_encode(['rooms' => $rooms->count(), 'completed' => $success, 'skipped' => $skipped, 'failed' => $failed]),
            'finished_at' => $now, 'lock_token' => null, 'lock_owner' => null, 'lock_until' => null, 'updated_at' => $now,
        ]);
        $this->finishTask($session, $status, $success, $skipped, $failed, $errorCode, $errorCode ? '直播挂机超过截止时间' : null);
    }

    private function finishTask(object $session, string $status, int $success, int $skipped, int $failed, ?string $errorCode, ?string $message): void
    {
        $now = date('Y-m-d H:i:s.v');
        $total = $success + $skipped + $failed;
        $runStatus = $status === 'partial' ? 'partial' : ($status === 'failed' ? 'failed' : 'succeeded');
        Db::table('TF_sign_runs')->where('id', $session->run_id)->update([
            'status' => $runStatus, 'total_count' => $total, 'success_count' => $success, 'skipped_count' => $skipped,
            'already_count' => 0, 'failed_count' => $failed, 'error_code' => $errorCode, 'error_message' => $message,
            'summary_json' => json_encode(['total' => $total, 'completed' => $success, 'skipped' => $skipped, 'failed' => $failed], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'finished_at' => $now, 'duration_ms' => Db::raw('TIMESTAMPDIFF(MICROSECOND, started_at, NOW(3)) DIV 1000'),
        ]);
        Db::table('TF_sign_tasks')->where('id', $session->task_id)->update([
            'status' => $runStatus, 'total_count' => $total, 'success_count' => $success, 'skipped_count' => $skipped,
            'already_count' => 0, 'failed_count' => $failed, 'summary_json' => json_encode(['total' => $total, 'completed' => $success, 'skipped' => $skipped, 'failed' => $failed], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_error_code' => $errorCode, 'last_error_message' => $message,
            'locked_at' => null, 'locked_by' => null, 'finished_at' => $now, 'updated_at' => $now,
        ]);
        Db::table('TF_plugin_accounts')->where('id', $session->account_id)->update([
            'last_run_at' => $now, 'last_error_code' => $errorCode, 'last_error_message' => $message, 'updated_at' => $now,
        ]);
    }

    private function writeRoomProgress(int $sessionId, int $roomRowId, int $count, int $target, bool $completed): void
    {
        $session = Db::table('TF_bilibili_live_sessions')->where('id', $sessionId)->first();
        $room = Db::table('TF_bilibili_live_room_sessions')->where('id', $roomRowId)->first();
        if (!$session || !$room) return;
        $now = date('Y-m-d H:i:s.v');
        $recordKey = 'live:room:' . $room->room_id;
        Db::table('TF_sign_records')->insertOrIgnore([
            'task_id' => $session->task_id, 'run_id' => $session->run_id, 'user_id' => $session->user_id,
            'account_id' => $session->account_id, 'plugin_code' => 'bilibili', 'record_key' => $recordKey,
            'sequence_no' => (int)$room->id, 'schema_version' => 1, 'action' => 'live_fans_medal',
            'target_type' => 'live', 'target_id' => (string)$room->room_id,
            'target_name' => $room->room_name ?: ('直播间 ' . $room->room_id),
            'status' => $completed ? 'succeeded' : 'running', 'result_code' => $completed ? '0' : 'LIVE_HEARTBEAT_RUNNING',
            'message' => "直播心跳 {$count}/{$target}", 'reward_json' => '[]',
            'metrics_json' => json_encode(['heartbeat_count' => $count, 'target' => $target, 'medal_name' => $room->medal_name, 'medal_level' => (int)$room->medal_level], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'safe_result_json' => '{}', 'started_at' => $room->started_at, 'finished_at' => $completed ? $now : null, 'created_at' => $now,
        ]);
        Db::table('TF_sign_records')->where('run_id', $session->run_id)->where('record_key', $recordKey)->update([
            'status' => $completed ? 'succeeded' : 'running', 'result_code' => $completed ? '0' : 'LIVE_HEARTBEAT_RUNNING',
            'message' => "直播心跳 {$count}/{$target}",
            'metrics_json' => json_encode(['heartbeat_count' => $count, 'target' => $target, 'medal_name' => $room->medal_name, 'medal_level' => (int)$room->medal_level], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'finished_at' => $completed ? $now : null,
        ]);
        Db::table('TF_sign_tasks')->where('id', $session->task_id)->update([
            'summary_json' => json_encode(['current_room' => (int)$room->room_id, 'heartbeat_count' => $count, 'heartbeat_target' => $target], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => $now,
        ]);
    }

    private function failTask(int $taskId, Throwable $exception): void
    {
        $now = date('Y-m-d H:i:s.v');
        $errorCode = $exception instanceof ApiException ? $exception->errorCode : 'BILIBILI_LIVE_INIT_FAILED';
        $message = $this->safeMessage($exception);
        Db::table('TF_sign_runs')->where('task_id', $taskId)->where('status', 'running')->update([
            'status' => 'failed', 'failed_count' => 1, 'error_code' => $errorCode,
            'error_message' => $message, 'finished_at' => $now,
        ]);
        Db::table('TF_sign_tasks')->where('id', $taskId)->update([
            'status' => 'failed', 'failed_count' => 1,
            'last_error_code' => $errorCode, 'last_error_message' => $message,
            'locked_at' => null, 'locked_by' => null, 'finished_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function lockTtl(): int
    {
        return max(15, (int)(getenv('BILIBILI_LIVE_LOCK_TTL_SECONDS') ?: 30));
    }

    private function safeMessage(Throwable $exception): string
    {
        return mb_substr((new SensitiveDataRedactor())->redactString($exception->getMessage()), 0, 500);
    }
}
