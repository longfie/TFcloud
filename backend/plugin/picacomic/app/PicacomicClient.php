<?php

namespace plugin\picacomic\app;

use app\exception\ApiException;

/**
 * Picacomic protocol adapter rewritten for TF Sign from the LGPL-3.0
 * picacomic-api and picacomic-Punch projects. See ../THIRD_PARTY_NOTICES.md.
 */
final class PicacomicClient
{
    private const BASE_URL = 'https://picaapi.picacomic.com/';
    private const API_KEY = 'C69BAF41DA5ABD1FFEDC6D2FEA56B';
    private const SIGNATURE_KEY = '~d}$Q7$eIni=V)9\\RK/P.RM4;9[7|@/CA}b~OW!3?EV`:<>M7pddUBL5n|0/*Cn';
    private const APP_VERSION = '2.2.1.2.3.4';
    private const BUILD_VERSION = '45';

    private PicacomicHttpClientInterface $http;
    private array $credentials;
    private ?array $session = null;
    private bool $credentialsChanged = false;

    public function __construct(array $credentials, ?PicacomicHttpClientInterface $http = null)
    {
        $this->credentials = $credentials;
        $this->http = $http ?? new CurlPicacomicHttpClient();
    }

    public function authenticate(): array
    {
        if ($this->session !== null) {
            return $this->session;
        }
        $this->assertCredentials();
        $token = trim((string)($this->credentials['token'] ?? ''));
        if ($token !== '') {
            try {
                $profile = $this->profile($token);
                return $this->session = ['token' => $token, 'profile' => $profile];
            } catch (ApiException $exception) {
                if ($exception->errorCode !== 'PLUGIN_CREDENTIAL_EXPIRED') {
                    throw $exception;
                }
            }
        }

        $login = $this->apiRequest('POST', 'auth/sign-in', [
            'json' => [
                'email' => trim((string)$this->credentials['username']),
                'password' => (string)$this->credentials['password'],
            ],
        ], null, true);
        $token = trim((string)($login['token'] ?? ''));
        if ($token === '') {
            throw new ApiException('PICACOMIC_LOGIN_FAILED', '哔咔登录未返回有效令牌', 502);
        }
        $profile = $this->profile($token);
        $this->credentials['token'] = $token;
        $this->credentials['user_id'] = (string)($profile['_id'] ?? '');
        $this->credentials['display_name'] = (string)($profile['name'] ?? '');
        $this->credentials['token_updated_at'] = date(DATE_ATOM);
        $this->credentialsChanged = true;

        return $this->session = ['token' => $token, 'profile' => $profile];
    }

    public function credentialsWithSession(): array
    {
        $this->authenticate();
        return $this->credentials;
    }

    public function refreshedCredentials(): ?array
    {
        return $this->credentialsChanged ? $this->credentials : null;
    }

    public function accountIdentifier(?array $session = null): string
    {
        $session ??= $this->authenticate();
        $userId = trim((string)($session['profile']['_id'] ?? $this->credentials['user_id'] ?? ''));
        if ($userId !== '') {
            return $userId;
        }
        return substr(hash('sha256', mb_strtolower(trim((string)$this->credentials['username']))), 0, 24);
    }

    public function displayName(?array $session = null): string
    {
        $session ??= $this->authenticate();
        return trim((string)($session['profile']['name'] ?? '')) ?: '哔咔漫画账号';
    }

    public function dailySign(?array $session = null): array
    {
        $session ??= $this->authenticate();
        $profile = is_array($session['profile'] ?? null)
            ? $session['profile']
            : $this->profile((string)$session['token']);
        $userId = $this->accountIdentifier($session);
        if (($profile['isPunched'] ?? false) === true) {
            return $this->signResult('already_done', $userId, $profile, 0, '哔咔今日已签到');
        }

        $beforeExp = max(0, (int)($profile['exp'] ?? 0));
        $punch = $this->apiRequest(
            'POST',
            'users/punch-in',
            ['body' => ''],
            (string)$session['token']
        );
        $status = mb_strtolower(trim((string)($punch['res']['status'] ?? '')));
        $after = $this->profile((string)$session['token']);
        $nowPunched = ($after['isPunched'] ?? false) === true;
        if (!$nowPunched && !in_array($status, ['ok', 'success'], true)) {
            throw new ApiException('PICACOMIC_SIGN_FAILED', '哔咔签到失败', 502);
        }
        $experience = max(0, (int)($after['exp'] ?? 0) - $beforeExp);

        return $this->signResult('succeeded', $userId, $after, $experience, '哔咔签到成功');
    }

    private function profile(string $token): array
    {
        try {
            $data = $this->apiRequest('GET', 'users/profile', [], $token);
        } catch (ApiException $exception) {
            if (in_array($exception->errorCode, ['PICACOMIC_UNAUTHORIZED', 'PICACOMIC_API_ERROR'], true)) {
                throw new ApiException('PLUGIN_CREDENTIAL_EXPIRED', '哔咔登录状态已经失效', 422);
            }
            throw $exception;
        }
        $profile = $data['user'] ?? null;
        if (!is_array($profile) || empty($profile['_id'])) {
            throw new ApiException('PICACOMIC_PROFILE_INVALID', '哔咔用户资料返回格式错误', 502);
        }
        return $profile;
    }

    private function apiRequest(
        string $method,
        string $relativeUrl,
        array $options = [],
        ?string $token = null,
        bool $loginRequest = false
    ): array {
        $headers = $this->headers($relativeUrl, $method, $token);
        if (strtoupper($method) === 'POST') {
            $headers['Content-Type'] = 'application/json; charset=UTF-8';
        }
        $response = $this->http->request(
            $method,
            self::BASE_URL . $relativeUrl,
            $options + ['headers' => $headers]
        );
        $status = (int)($response['status'] ?? 0);
        if ($status === 429) {
            throw new ApiException('PICACOMIC_RATE_LIMITED', '哔咔请求过于频繁，请稍后再试', 429);
        }
        if (in_array($status, [401, 403], true)) {
            throw new ApiException('PICACOMIC_UNAUTHORIZED', '哔咔登录令牌无效', 401);
        }
        if ($status === 0 || $status >= 500) {
            throw new ApiException('UPSTREAM_HTTP_ERROR', '哔咔服务暂时不可用', 502);
        }
        $json = $response['json'];
        if (!is_array($json)) {
            throw new ApiException('PICACOMIC_RESPONSE_INVALID', '哔咔返回格式错误', 502);
        }
        $success = (int)($json['code'] ?? 0) === 200
            && (int)($json['error'] ?? -1) === 0
            && (string)($json['message'] ?? '') === 'success';
        if (!$success) {
            $message = trim((string)($json['message'] ?? $json['detail'] ?? '请求失败'));
            if ($loginRequest) {
                throw new ApiException('PLUGIN_CREDENTIAL_INVALID', '哔咔账号或密码不正确：' . $message, 422);
            }
            $lowerMessage = mb_strtolower($message);
            if (str_contains($lowerMessage, 'token') || str_contains($lowerMessage, 'unauthorized')
                || str_contains($message, '登录')) {
                throw new ApiException('PICACOMIC_UNAUTHORIZED', '哔咔登录令牌无效', 401);
            }
            throw new ApiException('PICACOMIC_API_ERROR', '哔咔请求失败：' . $message, 502);
        }
        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }

    private function headers(string $relativeUrl, string $method, ?string $token): array
    {
        $timestamp = (string)time();
        $nonce = bin2hex(random_bytes(16));
        $payload = mb_strtolower($relativeUrl . $timestamp . $nonce . strtoupper($method) . self::API_KEY);
        $headers = [
            'api-key' => self::API_KEY,
            'accept' => 'application/vnd.picacomic.com.v1+json',
            'signature' => hash_hmac('sha256', $payload, self::SIGNATURE_KEY),
            'app-channel' => '1',
            'time' => $timestamp,
            'app-version' => self::APP_VERSION,
            'app-build-version' => self::BUILD_VERSION,
            'nonce' => $nonce,
            'app-platform' => 'android',
            'app-uuid' => 'defaultUuid',
            'User-Agent' => 'okhttp/3.8.1',
            'Host' => 'picaapi.picacomic.com',
            'image-quality' => 'original',
        ];
        if ($token !== null && $token !== '') {
            $headers['authorization'] = $token;
        }
        return $headers;
    }

    private function signResult(
        string $status,
        string $userId,
        array $profile,
        int $experience,
        string $message
    ): array {
        return [
            'key' => 'picacomic:daily_sign:' . date('Y-m-d'),
            'action' => 'daily_sign',
            'status' => $status,
            'target_type' => 'account',
            'target_id' => $userId,
            'target_name' => $this->displayName(['profile' => $profile]),
            'code' => '0',
            'message' => $message,
            'rewards' => ['experience' => $experience],
            'metrics' => [
                'level' => max(0, (int)($profile['level'] ?? 0)),
                'experience' => max(0, (int)($profile['exp'] ?? 0)),
            ],
        ];
    }

    private function assertCredentials(): void
    {
        if (trim((string)($this->credentials['username'] ?? '')) === ''
            || (string)($this->credentials['password'] ?? '') === '') {
            throw new ApiException('PLUGIN_CREDENTIAL_INVALID', '哔咔账号和密码不能为空', 422);
        }
    }
}
