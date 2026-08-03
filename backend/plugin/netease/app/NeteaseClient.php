<?php

namespace plugin\netease\app;

use app\exception\ApiException;
use app\sign\dto\AccountProfile;
use app\sign\http\CurlJsonHttpClient;
use app\sign\http\JsonHttpClientInterface;

final class NeteaseClient
{
    private const NONCE = '0CoJUm6Qyw8W8jud';
    private const IV = '0102030405060708';
    private const SECRET_KEY = 'TA3YiYCfY2dDJQgg';
    private const ENC_SEC_KEY = '84ca47bca10bad09a6b04c5c927ef077d9b9f1e37098aa3eac6ea70eb59df0aa28b691b7e75e4f1f9831754919ea784c8f74fbfadf2898b0be17849fd656060162857830e241aba44991601f137624094c114ea8d17bce815b0cd4e5b8e2fbaba978c6d1d14dc3d1faf852bdd28818031ccdaaa13a6018e1024e2aae98844210';

    private JsonHttpClientInterface $http;

    public function __construct(
        private readonly array $credentials,
        ?JsonHttpClientInterface $http = null
    ) {
        $this->http = $http ?? new CurlJsonHttpClient();
    }

    public function profile(): AccountProfile
    {
        $response = $this->http->request('GET', 'https://music.163.com/api/nuser/account/get', [
            'cookie' => $this->cookie(),
            'referer' => 'https://music.163.com/',
        ]);
        if ((int)($response['code'] ?? 0) !== 200 || empty($response['account']['id'])) {
            throw new ApiException('PLUGIN_CREDENTIAL_EXPIRED', '网易云音乐登录状态已经失效', 422);
        }
        return new AccountProfile(
            (string)$response['account']['id'],
            (string)($response['profile']['nickname'] ?? '网易云音乐账号'),
            ['vip_type' => (int)($response['profile']['vipType'] ?? 0)]
        );
    }

    public function dailySign(): array
    {
        $response = $this->http->request('POST', 'https://music.163.com/weapi/point/dailyTask', [
            'form' => $this->encryptPayload([
                'type' => 0,
                'csrf_token' => (string)$this->credentials['csrf'],
            ]),
            'cookie' => $this->cookie(),
            'referer' => 'https://music.163.com/',
        ]);
        $code = (int)($response['code'] ?? -1);
        $already = $code === -2 || str_contains((string)($response['msg'] ?? ''), '重复');
        return [
            'status' => $code === 200 ? 'succeeded' : ($already ? 'already_done' : 'failed'),
            'code' => (string)$code,
            'message' => (string)($response['msg'] ?? ($code === 200 ? '网易云音乐签到成功' : '网易云音乐签到失败')),
            'points' => (int)($response['point'] ?? 0),
        ];
    }

    public function encryptPayload(array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $first = openssl_encrypt($json, 'aes-128-cbc', self::NONCE, 0, self::IV);
        if ($first === false) {
            throw new ApiException('NETEASE_ENCRYPT_FAILED', '网易云请求加密失败', 500);
        }
        $params = openssl_encrypt($first, 'aes-128-cbc', self::SECRET_KEY, 0, self::IV);
        if ($params === false) {
            throw new ApiException('NETEASE_ENCRYPT_FAILED', '网易云请求加密失败', 500);
        }
        return ['params' => $params, 'encSecKey' => self::ENC_SEC_KEY];
    }

    private function cookie(): string
    {
        return 'os=pc; appver=2.9.7; MUSIC_U=' . $this->credentials['music_u']
            . '; __csrf=' . $this->credentials['csrf'];
    }
}
