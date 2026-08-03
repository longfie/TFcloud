<?php

/**
 * 天方云签 · 百度贴吧客户端
 *
 * 负责校验贴吧登录状态、分页获取全部关注贴吧、提交签到请求和维护关注列表缓存。
 * 自动补签只处理“第三方平台请求失败”的贴吧；后台重跑则重新获取完整关注列表。
 *
 * 作者：龙辉（QQ 1790716272）
 * 交流群：701550577
 * 博客：https://blog.eirds.cn/
 * 官网：https://www.yunsign.net
 */

namespace plugin\tieba\app;

use app\exception\ApiException;
use app\sign\dto\AccountProfile;
use app\sign\http\CurlJsonHttpClient;
use app\sign\http\JsonHttpClientInterface;

final class TiebaClient
{
    private const CLIENT_VERSION = '8.1.0.4';
    private const SIGN_KEY = 'tiebaclient!!!';

    /** 签到返回「未关注」时从缓存剔除。 */
    private const UNFOLLOWED_CODES = ['340012'];

    private array $mobileHeaders = [
        'Content-Type: application/x-www-form-urlencoded',
        'Charset: UTF-8',
        'net: 3',
        'User-Agent: bdtb for Android 8.4.0.1',
        'Accept-Encoding: gzip',
    ];

    private readonly string $bduss;
    private readonly string $stoken;
    private readonly JsonHttpClientInterface $http;
    private ?string $cachedTbs = null;

    /** @var array{synced_at: string, fingerprint: string, items: list<array{fid: string, name: string}>}|null */
    private ?array $likedForumsState = null;

    public function __construct(
        array $credentials,
        ?JsonHttpClientInterface $http = null
    )
    {
        $this->bduss = trim((string)($credentials['bduss'] ?? ''));
        $this->stoken = trim((string)($credentials['stoken'] ?? ''));
        $this->http = $http ?? new CurlJsonHttpClient();
    }

    public function profile(): AccountProfile
    {
        // TBS 仍是权威登录态校验；用户名/头像再走客户端 login 同步接口。
        $this->tbs();
        $user = $this->fetchUser();

        $externalId = trim((string)($user['id'] ?? $user['user_id'] ?? ''));
        $displayName = trim((string)(
            $user['name_show']
            ?? $user['name']
            ?? $user['user_name']
            ?? ''
        ));
        $portrait = $this->normalizePortrait((string)($user['portrait'] ?? ''));
        $avatar = $portrait !== ''
            ? 'https://himg.bdimg.com/sys/portrait/item/' . $portrait
            : '';

        return new AccountProfile($externalId, $displayName, [
            'login_verified_by' => 'tbs',
            'portrait' => $portrait,
            'avatar' => $avatar,
            'name' => trim((string)($user['name'] ?? '')),
            'name_show' => trim((string)($user['name_show'] ?? '')),
        ]);
    }

    /**
     * @param array|null $likedForumsCache profile_json.liked_forums
     * @return list<array>
     */
    public function signAll(
        ?callable $onEach = null,
        ?array $likedForumsCache = null,
        ?array $targetForumIds = null,
        bool $forceForumRefresh = false
    ): array
    {
        $tbs = $this->tbs();

        $cachedItems = $this->normalizeCachedItems($likedForumsCache);
        if (!$forceForumRefresh && is_array($targetForumIds) && $cachedItems !== []) {
            // 当天自动补签只处理上次请求失败的吧，直接使用刚同步过的完整缓存。
            $forums = $cachedItems;
            $fingerprint = (string)($likedForumsCache['fingerprint'] ?? '');
            $syncMode = 'retry_cache';
        } else {
            // 关注列表的排序不保证新关注项出现在第一页，因此每日首次签到必须
            // 翻页到 has_more=0，不能再依赖第一页指纹判断完整缓存是否有效。
            $firstPage = $this->forumPage(1);
            $page1Forums = $this->extractForums($firstPage, 1);
            $hasMore = (string)($firstPage['has_more'] ?? '0') === '1';
            $fingerprint = $this->fingerprint($page1Forums, $hasMore);
            if (!$hasMore) {
                $forums = $page1Forums;
                $syncMode = 'full_sync';
            } else {
                $forums = $this->fetchRemainingForums($page1Forums, $firstPage, 1);
                $syncMode = 'full_sync';
            }
        }

        if ($forceForumRefresh) {
            // 管理员重跑是一次全新执行，不继承旧任务的失败目标范围。
            $targetForumIds = null;
            $syncMode = 'forced_full_sync';
        }

        $kept = [];
        foreach ($forums as $forum) {
            $kept[(string)$forum['fid']] = [
                'fid' => (string)$forum['fid'],
                'name' => (string)$forum['name'],
            ];
        }

        $signForums = $forums;
        if (is_array($targetForumIds)) {
            $targetSet = [];
            foreach ($targetForumIds as $forumId) {
                $forumId = trim((string)$forumId);
                if ($forumId !== '') {
                    $targetSet[$forumId] = true;
                }
            }
            $signForums = array_values(array_filter(
                $forums,
                static fn (array $forum): bool => isset($targetSet[(string)$forum['fid']])
            ));
        }

        $records = [];
        foreach ($signForums as $forum) {
            $detail = $this->signForum($forum, $tbs);
            $records[] = $detail;
            if ($onEach !== null) {
                $onEach($detail);
            }
            if ($this->isUnfollowed($detail)) {
                unset($kept[(string)$forum['fid']]);
            }
        }

        $this->likedForumsState = [
            'synced_at' => gmdate('c'),
            'fingerprint' => $fingerprint,
            'items' => array_values($kept),
            'sync_mode' => $syncMode,
        ];

        return $records;
    }

    /**
     * @return array{synced_at: string, fingerprint: string, items: list<array{fid: string, name: string}>, sync_mode?: string}|null
     */
    public function likedForumsState(): ?array
    {
        return $this->likedForumsState;
    }

    private function tbs(): string
    {
        if ($this->cachedTbs !== null) {
            return $this->cachedTbs;
        }

        $data = $this->http->request('GET', 'https://tieba.baidu.com/dc/common/tbs', [
            'cookie' => $this->webCookie(),
            'referer' => 'https://tieba.baidu.com/',
            'headers' => ['X-Requested-With: XMLHttpRequest'],
        ]);
        if ((int)($data['is_login'] ?? 0) !== 1) {
            throw new ApiException('PLUGIN_CREDENTIAL_EXPIRED', '贴吧登录状态已经失效', 422);
        }
        $tbs = trim((string)($data['tbs'] ?? ''));
        if ($tbs === '') {
            throw new ApiException('TIEBA_TBS_FAILED', '获取贴吧签名参数失败', 502);
        }
        return $this->cachedTbs = $tbs;
    }

    /**
     * 通过客户端 login 同步当前 BDUSS 对应用户资料（昵称、portrait）。
     *
     * @return array<string, mixed>
     */
    private function fetchUser(): array
    {
        $params = [
            'bdusstoken' => $this->bduss,
            '_client_type' => '4',
            '_client_version' => self::CLIENT_VERSION,
        ];
        $params['sign'] = $this->clientSign($params);
        $data = $this->http->request('POST', 'https://c.tieba.baidu.com/c/s/login', [
            'form' => $params,
            'cookie' => 'BDUSS=' . $this->bduss,
            'headers' => $this->mobileHeaders,
        ]);
        $user = is_array($data['user'] ?? null) ? $data['user'] : [];
        if ($user === []) {
            throw new ApiException(
                'TIEBA_PROFILE_FAILED',
                (string)($data['error_msg'] ?? '获取贴吧账号资料失败'),
                502
            );
        }
        return $user;
    }

    /**
     * @param array<string, scalar> $params
     */
    private function clientSign(array $params): string
    {
        ksort($params);
        $text = '';
        foreach ($params as $key => $value) {
            $text .= $key . '=' . $value;
        }
        return strtoupper(md5($text . self::SIGN_KEY));
    }

    private function normalizePortrait(string $portrait): string
    {
        $portrait = trim($portrait);
        if ($portrait === '') {
            return '';
        }
        if (str_contains($portrait, '://')) {
            if (preg_match('#/item/([^/?#]+)#', $portrait, $matches) === 1) {
                return rawurldecode($matches[1]);
            }
            return '';
        }
        $portrait = explode('?', $portrait, 2)[0];
        return trim($portrait);
    }

    private function forumPage(int $page): array
    {
        $params = [
            'BDUSS' => $this->bduss,
            '_client_version' => self::CLIENT_VERSION,
            'page_no' => $page,
            'page_size' => 100,
        ];
        $signText = 'BDUSS=' . $this->bduss
            . '_client_version=' . self::CLIENT_VERSION
            . 'page_no=' . $page
            . 'page_size=100'
            . self::SIGN_KEY;
        $params['sign'] = md5($signText);
        $data = $this->http->request('POST', 'https://c.tieba.baidu.com/c/f/forum/like', [
            'form' => $params,
            'cookie' => 'ca=open',
            'headers' => $this->mobileHeaders,
        ]);
        if ((string)($data['error_code'] ?? '0') !== '0') {
            throw new ApiException('TIEBA_FORUM_LIST_FAILED', (string)($data['error_msg'] ?? '获取贴吧列表失败'), 502);
        }
        return $data;
    }

    /**
     * @param list<array{fid: string, name: string, page_no?: int}> $page1Forums
     * @return list<array{fid: string, name: string, page_no: int}>
     */
    private function fetchRemainingForums(array $page1Forums, array $firstPageData, int $startPage): array
    {
        $seenForumIds = [];
        $forums = [];
        foreach ($page1Forums as $forum) {
            $seenForumIds[$forum['fid']] = true;
            $forums[] = $forum;
        }

        $page = $startPage;
        $data = $firstPageData;
        while ((string)($data['has_more'] ?? '0') === '1') {
            $page++;
            $data = $this->forumPage($page);
            $newForumCount = 0;
            foreach ($this->extractForums($data, $page) as $forum) {
                if (isset($seenForumIds[$forum['fid']])) {
                    continue;
                }
                $seenForumIds[$forum['fid']] = true;
                $forums[] = $forum;
                $newForumCount++;
            }
            if ($newForumCount === 0) {
                throw new ApiException(
                    'TIEBA_FORUM_LIST_STALLED',
                    '贴吧关注列表分页未继续前进，已停止本次任务以避免重复签到',
                    502
                );
            }
        }

        return $forums;
    }

    private function extractForums(array $data, int $page): array
    {
        $result = [];
        $forumList = is_array($data['forum_list'] ?? null) ? $data['forum_list'] : [];
        foreach (['gconforum', 'non-gconforum'] as $group) {
            foreach (($forumList[$group] ?? []) as $forum) {
                if (!isset($forum['id'], $forum['name'])) {
                    continue;
                }
                $result[(string)$forum['id']] = [
                    'fid' => (string)$forum['id'],
                    'name' => (string)$forum['name'],
                    'page_no' => $page,
                ];
            }
        }
        return array_values($result);
    }

    /**
     * @param list<array{fid: string, name: string}> $page1Forums
     */
    private function fingerprint(array $page1Forums, bool $hasMore): string
    {
        $fids = array_map(static fn (array $forum): string => (string)$forum['fid'], $page1Forums);
        sort($fids, SORT_STRING);
        return hash(
            'sha256',
            implode(',', $fids) . '|' . ($hasMore ? '1' : '0') . '|' . count($page1Forums)
        );
    }

    /**
     * @return list<array{fid: string, name: string}>
     */
    private function normalizeCachedItems(?array $likedForumsCache): array
    {
        if (!is_array($likedForumsCache)) {
            return [];
        }
        $items = $likedForumsCache['items'] ?? null;
        if (!is_array($items) || $items === []) {
            return [];
        }
        $normalized = [];
        $seen = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $fid = trim((string)($item['fid'] ?? ''));
            $name = trim((string)($item['name'] ?? ''));
            if ($fid === '' || $name === '' || isset($seen[$fid])) {
                continue;
            }
            $seen[$fid] = true;
            $normalized[] = ['fid' => $fid, 'name' => $name, 'page_no' => 0];
        }
        return $normalized;
    }

    private function isUnfollowed(array $detail): bool
    {
        $code = (string)($detail['code'] ?? '');
        if (in_array($code, self::UNFOLLOWED_CODES, true)) {
            return true;
        }
        $message = (string)($detail['message'] ?? '');
        return str_contains($message, '未关注') || str_contains($message, '还未关注');
    }

    private function signForum(array $forum, string $tbs): array
    {
        $signText = 'BDUSS=' . $this->bduss
            . 'fid=' . $forum['fid']
            . 'kw=' . $forum['name']
            . 'tbs=' . $tbs
            . self::SIGN_KEY;
        $params = [
            'BDUSS' => $this->bduss,
            'fid' => $forum['fid'],
            'kw' => $forum['name'],
            'sign' => md5($signText),
            'tbs' => $tbs,
        ];

        try {
            $data = $this->http->request('POST', 'https://c.tieba.baidu.com/c/c/forum/sign', [
                'form' => $params,
                'cookie' => 'ca=open',
                'headers' => $this->mobileHeaders,
            ]);
            $code = (string)($data['error_code'] ?? '');
            $status = match ($code) {
                '0' => 'succeeded',
                '160002' => 'already_done',
                default => 'failed',
            };
            return $forum + [
                'status' => $status,
                'code' => $code,
                'message' => (string)($data['error_msg'] ?? ($code === '0' ? '签到成功' : '签到失败')),
                'bonus_points' => (int)($data['user_info']['sign_bonus_point'] ?? 0),
                'sign_rank' => (int)($data['user_info']['user_sign_rank'] ?? 0),
            ];
        } catch (\Throwable $exception) {
            return $forum + [
                'status' => 'failed',
                // 只有统一 HTTP 客户端明确标记的上游故障才会持续补签；
                // 程序错误保留为普通执行失败，避免代码缺陷造成无限重试。
                'code' => $exception instanceof ApiException
                    ? $exception->errorCode
                    : 'PLUGIN_EXECUTION_FAILED',
                'message' => mb_substr($exception->getMessage(), 0, 500),
                'bonus_points' => 0,
                'sign_rank' => 0,
            ];
        }
    }

    private function webCookie(): string
    {
        $cookie = 'BDUSS=' . $this->bduss;
        if ($this->stoken !== '') {
            $cookie .= '; STOKEN=' . $this->stoken;
        }
        return $cookie;
    }
}
