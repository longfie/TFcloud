<?php

namespace plugin\bilibili\app;

use app\exception\ApiException;
use app\sign\dto\AccountProfile;
use app\sign\http\CurlJsonHttpClient;
use app\sign\http\JsonHttpClientInterface;

final class BilibiliClient
{
    private const COIN_TARGET_BVIDS = [
        'BV1fF411275R',
        'BV1P841167pD',
        'BV18u411N78X',
    ];

    private JsonHttpClientInterface $http;
    private ?array $video = null;
    private ?array $coinVideo = null;

    public function __construct(
        private readonly array $credentials,
        ?JsonHttpClientInterface $http = null
    ) {
        $this->http = $http ?? new CurlJsonHttpClient();
    }

    public function profile(): AccountProfile
    {
        $response = $this->get('https://api.bilibili.com/x/web-interface/nav');
        if ((int)($response['code'] ?? -1) === -101 || empty($response['data']['isLogin'])) {
            throw new ApiException('PLUGIN_CREDENTIAL_EXPIRED', '哔哩哔哩登录状态已经失效', 422);
        }
        $this->assertSuccess($response, '获取哔哩哔哩账号信息失败');
        return new AccountProfile(
            (string)($response['data']['mid'] ?? $this->credentials['dede_user_id']),
            (string)($response['data']['uname'] ?? '哔哩哔哩账号'),
            ['level' => (int)($response['data']['level_info']['current_level'] ?? 0)]
        );
    }

    public function watchVideo(): array
    {
        $video = $this->video();
        $duration = max(1, (int)($video['duration'] ?? 1));
        $response = $this->post('https://api.bilibili.com/x/click-interface/web/heartbeat', [
            'aid' => $video['aid'],
            'cid' => $video['cid'],
            'mid' => $this->credentials['dede_user_id'],
            'csrf' => $this->credentials['bili_jct'],
            'played_time' => max(0, $duration - 1),
            'realtime' => $duration,
            'start_ts' => time(),
            'play_type' => 0,
        ], $this->referer($video));
        return $this->result($response, 'watch_video', $video, '视频观看任务提交成功');
    }

    public function shareVideo(): array
    {
        $video = $this->video();
        $response = $this->post('https://api.bilibili.com/x/web-interface/share/add', [
            'aid' => $video['aid'],
            'csrf' => $this->credentials['bili_jct'],
        ], $this->referer($video));
        return $this->result($response, 'share_video', $video, '视频分享任务提交成功');
    }

    public function giveCoin(int $count): array
    {
        $video = $this->coinVideo();
        $response = $this->post('https://api.bilibili.com/x/web-interface/coin/add', [
            'aid' => $video['aid'],
            'multiply' => max(1, min(2, $count)),
            'select_like' => 0,
            'cross_domain' => 'true',
            'csrf' => $this->credentials['bili_jct'],
        ], $this->referer($video));
        $result = $this->result($response, 'give_coin', $video, '视频投币成功');
        $result['rewards'] = ['coins_spent' => max(1, min(2, $count))];
        return $result;
    }

    private function coinVideo(): array
    {
        if ($this->coinVideo !== null) {
            return $this->coinVideo;
        }

        $targets = self::COIN_TARGET_BVIDS;
        $offset = ((int)sprintf(
            '%u',
            crc32(date('Y-m-d') . '|' . (string)$this->credentials['dede_user_id'])
        )) % count($targets);

        for ($attempt = 0, $count = count($targets); $attempt < $count; $attempt++) {
            $bvid = $targets[($offset + $attempt) % $count];
            try {
                $response = $this->get(
                    'https://api.bilibili.com/x/web-interface/view',
                    'https://www.bilibili.com/video/' . $bvid,
                    ['bvid' => $bvid]
                );
            } catch (\Throwable) {
                continue;
            }

            $video = $response['data'] ?? null;
            if (
                (int)($response['code'] ?? -1) === 0
                && is_array($video)
                && !empty($video['aid'])
                && !empty($video['cid'])
            ) {
                $video['bvid'] = (string)($video['bvid'] ?? $bvid);
                return $this->coinVideo = $video;
            }
        }

        throw new ApiException(
            'BILIBILI_COIN_TARGET_UNAVAILABLE',
            '预设投币视频暂时不可用，本次未消耗硬币',
            502
        );
    }

    public function liveSign(): array
    {
        $response = $this->get(
            'https://api.live.bilibili.com/xlive/web-ucenter/v1/sign/DoSign',
            'https://live.bilibili.com/'
        );
        $code = (int)($response['code'] ?? -1);
        return [
            'key' => 'live:daily_sign',
            'action' => 'live_sign',
            'status' => $code === 0 ? 'succeeded' : ($code === 1011040 ? 'already_done' : 'failed'),
            'target_type' => 'live',
            'target_id' => null,
            'target_name' => '哔哩哔哩直播',
            'code' => (string)$code,
            'message' => (string)($response['message'] ?? $response['data']['text'] ?? ($code === 0 ? '直播签到成功' : '直播签到失败')),
            'rewards' => ['text' => (string)($response['data']['text'] ?? '')],
            'metrics' => [],
        ];
    }

    public function liveDailyBag(): array
    {
        $response = $this->get(
            'https://api.live.bilibili.com/gift/v2/live/receive_daily_bag',
            'https://live.bilibili.com/'
        );
        $code = (int)($response['code'] ?? -1);
        $message = (string)($response['message'] ?? $response['msg'] ?? '');
        $alreadyDone = $code !== 0 && $this->alreadyDoneMessage($message);
        $rewards = [];
        foreach ((array)($response['data']['bag_list'] ?? $response['data'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string)($item['gift_name'] ?? $item['name'] ?? ''));
            $count = (int)($item['gift_num'] ?? $item['num'] ?? 0);
            if ($name !== '' || $count > 0) {
                $rewards[] = ['name' => $name !== '' ? $name : '直播礼包', 'count' => $count];
            }
            if (count($rewards) >= 20) {
                break;
            }
        }

        return $this->simpleResult(
            'live_daily_bag',
            $code === 0 ? 'succeeded' : ($alreadyDone ? 'already_done' : 'failed'),
            (string)$code,
            $message !== '' ? $message : ($code === 0 ? '直播每日礼包领取成功' : '直播每日礼包领取失败'),
            'live',
            '哔哩哔哩直播礼包',
            $rewards
        );
    }

    public function liveHeartbeat(string $roomId): array
    {
        $roomId = trim($roomId);
        if (!preg_match('/^[1-9][0-9]{0,19}$/', $roomId)) {
            throw new ApiException('VALIDATION_FAILED', '直播心跳需要填写正确的直播间 ID', 422);
        }
        $response = $this->get(
            'https://live-trace.bilibili.com/xlive/rdata-interface/v1/heartbeat/webHeartBeat',
            'https://live.bilibili.com/' . $roomId,
            ['pf' => 'web', 'hb' => base64_encode('0|' . $roomId . '|1|0')]
        );
        $code = (int)($response['code'] ?? -1);
        return $this->simpleResult(
            'live_heartbeat',
            $code === 0 ? 'succeeded' : 'failed',
            (string)$code,
            (string)($response['message'] ?? ($code === 0 ? '直播心跳提交成功' : '直播心跳提交失败')),
            'live',
            '直播间 ' . $roomId,
            [],
            ['room_id' => $roomId, 'next_interval' => (int)($response['data']['next_interval'] ?? 0)],
            $roomId
        );
    }

    public function openCapsule(int $requestedCount): array
    {
        $detail = $this->get(
            'https://api.live.bilibili.com/xlive/web-ucenter/v1/capsule/get_detail',
            'https://live.bilibili.com/'
        );
        $this->assertSuccess($detail, '获取直播扭蛋币信息失败');
        $available = max(0, (int)($detail['data']['normal']['coin'] ?? 0));
        if ($available === 0) {
            return $this->simpleResult(
                'capsule_open',
                'already_done',
                '0',
                '当前没有可使用的普通扭蛋币',
                'live',
                '哔哩哔哩直播扭蛋'
            );
        }

        $count = min(max(1, $requestedCount), min(100, $available));
        $response = $this->post(
            'https://api.live.bilibili.com/xlive/web-ucenter/v1/capsule/open_capsule',
            [
                'csrf_token' => $this->credentials['bili_jct'],
                'csrf' => $this->credentials['bili_jct'],
                'count' => $count,
                'type' => 'normal',
                'platform' => 'h5',
            ],
            'https://live.bilibili.com/'
        );
        $code = (int)($response['code'] ?? -1);
        $rewards = [];
        foreach ((array)($response['data']['awards'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string)($item['gift_name'] ?? $item['name'] ?? ''));
            $amount = (int)($item['num'] ?? $item['gift_num'] ?? 0);
            if ($name !== '' || $amount > 0) {
                $rewards[] = ['name' => $name !== '' ? $name : '扭蛋奖励', 'count' => $amount];
            }
            if (count($rewards) >= 20) {
                break;
            }
        }

        return $this->simpleResult(
            'capsule_open',
            $code === 0 ? 'succeeded' : 'failed',
            (string)$code,
            (string)($response['message'] ?? ($code === 0 ? '普通扭蛋币使用成功' : '普通扭蛋币使用失败')),
            'live',
            '哔哩哔哩直播扭蛋',
            $rewards,
            ['capsules_spent' => $code === 0 ? $count : 0, 'capsules_available_before' => $available]
        );
    }

    public function comicSign(): array
    {
        $info = $this->post('https://manga.bilibili.com/twirp/activity.v1.Activity/GetClockInInfo', [
            'platform' => 'android',
        ], 'https://manga.bilibili.com/');
        $this->assertSuccess($info, '获取漫画签到状态失败');
        if ((int)($info['data']['status'] ?? 0) === 1) {
            return $this->simpleResult('comic_sign', 'already_done', '0', '漫画签到今日已完成', 'comic', '哔哩哔哩漫画');
        }

        $response = $this->post('https://manga.bilibili.com/twirp/activity.v1.Activity/ClockIn', [
            'platform' => 'android',
        ], 'https://manga.bilibili.com/');
        $code = (int)($response['code'] ?? -1);
        return $this->simpleResult(
            'comic_sign',
            $code === 0 ? 'succeeded' : 'failed',
            (string)$code,
            (string)($response['msg'] ?? ($code === 0 ? '漫画签到成功' : '漫画签到失败')),
            'comic',
            '哔哩哔哩漫画'
        );
    }

    private function video(): array
    {
        if ($this->video !== null) {
            return $this->video;
        }
        $response = $this->http->request('GET', 'https://api.bilibili.com/x/web-interface/popular', [
            'query' => ['ps' => 20, 'pn' => 1],
            'cookie' => $this->cookie(),
        ]);
        $this->assertSuccess($response, '获取热门视频失败');
        foreach (($response['data']['list'] ?? []) as $video) {
            if (!empty($video['aid']) && !empty($video['cid']) && !empty($video['bvid'])) {
                return $this->video = $video;
            }
        }
        throw new ApiException('BILIBILI_VIDEO_NOT_FOUND', '没有找到可用于任务的视频', 502);
    }

    private function result(array $response, string $action, array $video, string $successMessage): array
    {
        $code = (int)($response['code'] ?? -1);
        return [
            'key' => $action . ':' . $video['aid'],
            'action' => $action,
            'status' => $code === 0 ? 'succeeded' : 'failed',
            'target_type' => 'video',
            'target_id' => (string)$video['aid'],
            'target_name' => (string)$video['bvid'],
            'code' => (string)$code,
            'message' => (string)($response['message'] ?? ($code === 0 ? $successMessage : '任务失败')),
            'rewards' => [],
            'metrics' => ['bvid' => (string)$video['bvid']],
        ];
    }

    private function simpleResult(
        string $action,
        string $status,
        string $code,
        string $message,
        string $targetType,
        string $targetName,
        array $rewards = [],
        array $metrics = [],
        ?string $targetId = null
    ): array {
        return [
            'key' => $targetType . ':' . $action,
            'action' => $action,
            'status' => $status,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'target_name' => $targetName,
            'code' => $code,
            'message' => $message,
            'rewards' => $rewards,
            'metrics' => $metrics,
        ];
    }

    private function get(string $url, ?string $referer = null, array $query = []): array
    {
        return $this->http->request('GET', $url, [
            'query' => $query,
            'cookie' => $this->cookie(),
            'referer' => $referer ?? 'https://www.bilibili.com/',
        ]);
    }

    private function post(string $url, array $form, ?string $referer = null): array
    {
        return $this->http->request('POST', $url, [
            'form' => $form,
            'cookie' => $this->cookie(),
            'referer' => $referer ?? 'https://www.bilibili.com/',
        ]);
    }

    private function cookie(): string
    {
        $parts = [
            'DedeUserID=' . $this->credentials['dede_user_id'],
            'SESSDATA=' . $this->credentials['sessdata'],
            'bili_jct=' . $this->credentials['bili_jct'],
        ];
        if (!empty($this->credentials['dede_user_id_ckmd5'])) {
            $parts[] = 'DedeUserID__ckMd5=' . $this->credentials['dede_user_id_ckmd5'];
        }
        return implode('; ', $parts);
    }

    private function referer(array $video): string
    {
        return 'https://www.bilibili.com/video/' . $video['bvid'];
    }

    private function assertSuccess(array $response, string $message): void
    {
        if ((int)($response['code'] ?? -1) !== 0) {
            throw new ApiException('BILIBILI_API_FAILED', (string)($response['message'] ?? $message), 502);
        }
    }

    private function alreadyDoneMessage(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));
        return str_contains($normalized, '已领取')
            || str_contains($normalized, '已经领取')
            || str_contains($normalized, 'already');
    }
}
