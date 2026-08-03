<?php

namespace plugin\iqiyi\app;

use app\exception\ApiException;
use app\sign\dto\AccountProfile;
use app\sign\http\CurlJsonHttpClient;
use app\sign\http\JsonHttpClientInterface;

final class IqiyiClient
{
    private const VIP_SIGN_KEY = 'UKobMjDMsDoScuWOfp6F';
    private const SCORE_SIGN_KEY = 'DO58SzN6ip9nbJ4QkM8H';
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) TF-Sign/1.0';

    private JsonHttpClientInterface $http;

    public function __construct(
        private readonly array $credentials,
        ?JsonHttpClientInterface $http = null
    ) {
        $this->http = $http ?? new CurlJsonHttpClient();
    }

    public function profile(): AccountProfile
    {
        $response = $this->http->request('GET', 'https://serv.vip.iqiyi.com/vipgrowth/query.action', [
            'cookie' => $this->cookie(),
            'referer' => 'https://www.iqiyi.com/',
        ]);
        if ((string)($response['code'] ?? '') !== 'A00000') {
            throw new ApiException('PLUGIN_CREDENTIAL_EXPIRED', '爱奇艺登录状态已经失效', 422);
        }
        return new AccountProfile(
            (string)$this->credentials['p00003'],
            (string)($response['data']['userName'] ?? '爱奇艺账号'),
            ['vip_level' => (int)($response['data']['level'] ?? 0)]
        );
    }

    public function vipSign(): array
    {
        $query = [
            'agentType' => '1',
            'agentversion' => '1.0',
            'appKey' => 'basic_pcw',
            'authCookie' => $this->credentials['p00001'],
            'qyid' => bin2hex(random_bytes(16)),
            'task_code' => 'natural_month_sign',
            'timestamp' => (string)round(microtime(true) * 1000),
            'typeCode' => 'point',
            'userId' => $this->credentials['p00003'],
        ];
        $query['sign'] = $this->signature($query, self::VIP_SIGN_KEY);
        $response = $this->http->request('POST', 'https://community.iqiyi.com/openApi/task/execute', [
            'query' => $query,
            'json' => [
                'natural_month_sign' => [
                    'agentType' => '1',
                    'agentversion' => '1',
                    'authCookie' => $this->credentials['p00001'],
                    'qyid' => bin2hex(random_bytes(16)),
                    'taskCode' => 'iQIYI_mofhr',
                    'verticalCode' => 'iQIYI',
                ],
            ],
            'cookie' => $this->cookie(),
            'referer' => 'https://www.iqiyi.com/',
        ]);

        $outerCode = (string)($response['code'] ?? '');
        $code = (string)($response['data']['code'] ?? $outerCode);
        if ($outerCode === 'A00401') {
            throw new ApiException('PLUGIN_CREDENTIAL_EXPIRED', '爱奇艺登录状态已经失效', 422);
        }
        $status = $outerCode === 'A00000' && $code === 'A0000'
            ? 'succeeded'
            : ($outerCode === 'A00000' && $code === 'A0014' ? 'already_done' : 'failed');
        return [
            'status' => $status,
            'code' => $code,
            'message' => (string)($response['data']['msg'] ?? $response['message'] ?? ($status === 'succeeded' ? '会员签到成功' : '会员签到失败')),
            'points' => (int)($response['data']['data']['rewards'][0]['rewardCount'] ?? 0),
            'sign_days' => (int)($response['data']['data']['signDays'] ?? 0),
        ];
    }

    public function scoreSign(): array
    {
        $query = [
            'agenttype' => '1',
            'agentversion' => '0',
            'appKey' => 'basic_pca',
            'appver' => '0',
            'authCookie' => $this->credentials['p00001'],
            'channelCode' => 'sign_pcw',
            'scoreType' => '1',
            'srcplatform' => '1',
            'typeCode' => 'point',
            'userId' => $this->credentials['p00003'],
            'user_agent' => self::USER_AGENT,
            'verticalCode' => 'iQIYI',
        ];
        $query['sign'] = $this->signature($query, self::SCORE_SIGN_KEY);
        $response = $this->http->request('GET', 'https://community.iqiyi.com/openApi/score/add', [
            'query' => $query,
            'cookie' => $this->cookie(),
            'referer' => 'https://www.iqiyi.com/',
        ]);
        $outerCode = (string)($response['code'] ?? '');
        $code = (string)($response['data'][0]['code'] ?? $outerCode);
        $status = $outerCode === 'A00000' && $code === 'A0000'
            ? 'succeeded'
            : ($outerCode === 'A00000' && $code === 'A0002' ? 'already_done' : 'failed');
        return [
            'status' => $status,
            'code' => $code,
            'message' => (string)($response['data'][0]['message'] ?? $response['message'] ?? ($status === 'succeeded' ? '积分签到成功' : '积分签到失败')),
            'points' => (int)($response['data'][0]['score'] ?? 0),
            'continuous_days' => (int)($response['data'][0]['continuousValue'] ?? 0),
        ];
    }

    public function signature(array $data, string $key): string
    {
        $text = '';
        foreach ($data as $name => $value) {
            $text .= $name . '=' . $value . '|';
        }
        return md5($text . $key);
    }

    private function cookie(): string
    {
        return 'P00001=' . $this->credentials['p00001'] . '; P00003=' . $this->credentials['p00003'];
    }
}
