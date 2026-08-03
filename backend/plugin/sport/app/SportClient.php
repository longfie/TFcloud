<?php

namespace plugin\sport\app;

use app\exception\ApiException;

/**
 * Zepp protocol adapter rewritten for TF Sign from TonyJiangWJ/mimotion.
 * The upstream implementation was substantially changed from Python to PHP;
 * see ../LICENSE.mimotion and ../THIRD_PARTY_NOTICES.md.
 */
final class SportClient
{
    private const LOGIN_KEY = 'xeNtBVqzDc6tuNTh';
    private const LOGIN_IV = 'MAAAYAAAAAAAAABg';
    private const USER_AGENT = 'MiFit6.14.0 (M2007J1SC; Android 12; Density/2.75)';
    private const CLIENT_ID = '428135909242707968';

    /**
     * Compressed activity template derived from TonyJiangWJ/mimotion.
     * The date, total steps and stable device id are replaced before upload.
     */
    private const BAND_DATA_TEMPLATE = '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';

    private SportHttpClientInterface $http;
    private array $credentials;
    private ?array $session = null;
    private bool $credentialsChanged = false;

    public function __construct(array $credentials, ?SportHttpClientInterface $http = null)
    {
        $this->credentials = $credentials;
        $this->http = $http ?? new CurlSportHttpClient();
    }

    public function authenticate(): array
    {
        if ($this->session !== null) {
            return $this->session;
        }
        $username = $this->normalizedUsername();
        $deviceId = trim((string)($this->credentials['device_id'] ?? ''));
        if (!preg_match('/^[0-9a-f-]{36}$/i', $deviceId)) {
            $deviceId = $this->uuid();
            $this->credentials['device_id'] = $deviceId;
            $this->credentialsChanged = true;
        }

        $appToken = trim((string)($this->credentials['app_token'] ?? ''));
        $userId = trim((string)($this->credentials['user_id'] ?? ''));
        if ($appToken !== '' && $userId !== '') {
            $profile = $this->checkAppToken($appToken, $userId);
            if ($profile !== null) {
                return $this->session = [
                    'app_token' => $appToken,
                    'user_id' => $userId,
                    'device_id' => $deviceId,
                    'nickname' => (string)($profile['nickname'] ?? ''),
                ];
            }
        }

        $loginToken = trim((string)($this->credentials['login_token'] ?? ''));
        if ($loginToken !== '' && $userId !== '') {
            $appToken = $this->grantAppToken($loginToken);
            if ($appToken !== null) {
                return $this->rememberSession($appToken, $userId, $deviceId);
            }
        }

        $accessToken = trim((string)($this->credentials['access_token'] ?? ''));
        if ($accessToken === '') {
            $accessToken = $this->loginAccessToken($username, (string)$this->credentials['password']);
        }
        $tokens = $this->grantLoginTokens($accessToken, $deviceId, str_starts_with($username, '+86'));
        if ($tokens === null && isset($this->credentials['access_token'])) {
            $accessToken = $this->loginAccessToken($username, (string)$this->credentials['password']);
            $tokens = $this->grantLoginTokens($accessToken, $deviceId, str_starts_with($username, '+86'));
        }
        if ($tokens === null) {
            throw new ApiException('SPORT_LOGIN_FAILED', 'Zepp Life 登录令牌获取失败', 502);
        }

        $this->credentials['access_token'] = $accessToken;
        $this->credentials['login_token'] = $tokens['login_token'];
        $this->credentials['app_token'] = $tokens['app_token'];
        $this->credentials['user_id'] = $tokens['user_id'];
        $this->credentials['token_updated_at'] = date(DATE_ATOM);
        $this->credentialsChanged = true;

        return $this->session = [
            'app_token' => $tokens['app_token'],
            'user_id' => $tokens['user_id'],
            'device_id' => $deviceId,
            'nickname' => '',
        ];
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

    public function updateSteps(int $steps, ?array $session = null): array
    {
        if ($steps < 1 || $steps > 98800) {
            throw new ApiException('VALIDATION_FAILED', '运动步数必须在 1 到 98800 之间', 422);
        }
        $session ??= $this->authenticate();
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        $deviceId = (string)$session['device_id'];
        $bandDeviceId = strtoupper(substr(hash('sha256', $deviceId), 0, 16));
        $response = $this->http->request(
            'POST',
            'https://api-mifit-cn.huami.com/v1/data/band_data.json',
            [
                'query' => ['t' => (string)round(microtime(true) * 1000), 'r' => $this->uuid()],
                'headers' => [
                    'apptoken' => (string)$session['app_token'],
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'User-Agent' => self::USER_AGENT,
                ],
                'form' => [
                    'userid' => (string)$session['user_id'],
                    'last_sync_data_time' => '1597306380',
                    'device_type' => '0',
                    'last_deviceid' => $bandDeviceId,
                    'data_json' => $this->bandData($date, $steps, $bandDeviceId),
                ],
            ]
        );
        $this->assertNotRateLimited($response);
        $message = (string)($response['json']['message'] ?? '');
        $success = $response['status'] === 200 && $message === 'success';

        return [
            'key' => 'sport:steps:' . $date,
            'action' => 'update_steps',
            'status' => $success ? 'succeeded' : 'failed',
            'target_type' => 'sport',
            'target_id' => (string)$session['user_id'],
            'target_name' => 'Zepp Life',
            'code' => $success ? '0' : (string)($response['json']['code'] ?? $response['status']),
            'message' => $success ? '运动步数已更新为 ' . $steps : ($message !== '' ? $message : '运动步数更新失败'),
            'rewards' => [],
            'metrics' => ['steps' => $steps, 'date' => $date],
        ];
    }

    private function loginAccessToken(string $username, string $password): string
    {
        if (!function_exists('openssl_encrypt')) {
            throw new ApiException('SPORT_RUNTIME_MISSING', '服务器缺少 openssl 扩展', 500);
        }
        $plain = http_build_query([
            'emailOrPhone' => $username,
            'password' => $password,
            'state' => 'REDIRECTION',
            'client_id' => 'HuaMi',
            'country_code' => 'CN',
            'token' => 'access',
            'redirect_uri' => 'https://s3-us-west-2.amazonaws.com/hm-registration/successsignin.html',
        ]);
        $encrypted = openssl_encrypt($plain, 'AES-128-CBC', self::LOGIN_KEY, OPENSSL_RAW_DATA, self::LOGIN_IV);
        if ($encrypted === false) {
            throw new ApiException('SPORT_LOGIN_ENCRYPT_FAILED', 'Zepp Life 登录请求加密失败', 500);
        }
        $response = $this->http->request('POST', 'https://api-user.zepp.com/v2/registrations/tokens', [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
                'User-Agent' => self::USER_AGENT,
                'app_name' => 'com.xiaomi.hm.health',
                'appname' => 'com.xiaomi.hm.health',
                'appplatform' => 'android_phone',
                'x-hm-ekv' => '1',
                'hm-privacy-ceip' => 'false',
            ],
            'raw' => $encrypted,
        ]);
        $this->assertNotRateLimited($response);
        $location = (string)($response['headers']['location'] ?? '');
        if ($response['status'] !== 303 || $location === '') {
            if (in_array($response['status'], [400, 401, 403], true)) {
                throw new ApiException('PLUGIN_CREDENTIAL_INVALID', 'Zepp Life 账号或密码不正确', 422);
            }
            throw new ApiException('SPORT_LOGIN_FAILED', 'Zepp Life 登录服务返回异常状态', 502);
        }
        $accessToken = preg_match('/(?:[?&#])access=([^&]+)/', $location, $match)
            ? trim(rawurldecode($match[1]))
            : '';
        if ($accessToken === '') {
            $error = preg_match('/(?:[?&#])error=([^&]+)/', $location, $match)
                ? trim(rawurldecode($match[1]))
                : '账号或密码不正确';
            throw new ApiException(
                'PLUGIN_CREDENTIAL_INVALID',
                'Zepp Life 登录失败：' . $error,
                422
            );
        }
        return $accessToken;
    }

    private function grantLoginTokens(string $accessToken, string $deviceId, bool $isPhone): ?array
    {
        $common = [
            'app_name' => 'com.xiaomi.hm.health',
            'app_version' => '6.14.0',
            'code' => $accessToken,
            'country_code' => 'CN',
            'device_id' => $deviceId,
            'grant_type' => 'access_token',
        ];
        $form = $isPhone
            ? $common + ['device_model' => 'phone', 'third_name' => 'huami_phone']
            : $common + [
                'allow_registration' => 'false',
                'device_model' => 'android_phone',
                'dn' => 'account.zepp.com,api-user.zepp.com,api-mifit.zepp.com,api-watch.zepp.com,app-analytics.zepp.com,api-analytics.huami.com,auth.zepp.com',
                'lang' => 'zh_CN',
                'os_version' => '1.5.0',
                'source' => 'com.xiaomi.hm.health:6.14.0:50818',
                'third_name' => 'email',
            ];
        $response = $this->http->request('POST', 'https://account.huami.com/v2/client/login', [
            'headers' => $this->accountHeaders(),
            'form' => $form,
        ]);
        $this->assertNotRateLimited($response);
        $json = $response['json'] ?? [];
        if ($response['status'] !== 200 || ($json['result'] ?? '') !== 'ok') {
            return null;
        }
        $tokenInfo = (array)($json['token_info'] ?? []);
        if (empty($tokenInfo['login_token']) || empty($tokenInfo['app_token']) || empty($tokenInfo['user_id'])) {
            return null;
        }
        return [
            'login_token' => (string)$tokenInfo['login_token'],
            'app_token' => (string)$tokenInfo['app_token'],
            'user_id' => (string)$tokenInfo['user_id'],
        ];
    }

    private function grantAppToken(string $loginToken): ?string
    {
        $response = $this->http->request('GET', 'https://account-cn.huami.com/v1/client/app_tokens', [
            'query' => [
                'app_name' => 'com.xiaomi.hm.health',
                'dn' => 'api-user.huami.com,api-mifit.huami.com,app-analytics.huami.com',
                'login_token' => $loginToken,
            ],
            'headers' => ['User-Agent' => 'MiFit/5.3.0 (iPhone; iOS 14.7.1; Scale/3.00)'],
        ]);
        $this->assertNotRateLimited($response);
        if ($response['status'] !== 200 || ($response['json']['result'] ?? '') !== 'ok') {
            return null;
        }
        $token = trim((string)($response['json']['token_info']['app_token'] ?? ''));
        return $token !== '' ? $token : null;
    }

    private function checkAppToken(string $appToken, string $userId): ?array
    {
        $response = $this->http->request('GET', 'https://api-mifit-cn3.zepp.com/huami.health.getUserInfo.json', [
            'query' => [
                'r' => $this->uuid(),
                'userid' => $userId,
                'appid' => self::CLIENT_ID,
                'channel' => 'Normal',
                'country' => 'CN',
                'cv' => '50818_6.14.0',
                'device' => 'android_31',
                'device_type' => 'android_phone',
                'lang' => 'zh_CN',
                'timezone' => 'Asia/Shanghai',
                'v' => '2.0',
            ],
            'headers' => $this->apiHeaders($appToken),
        ]);
        $this->assertNotRateLimited($response);
        if ($response['status'] !== 200 || ($response['json']['message'] ?? '') !== 'success') {
            return null;
        }
        return is_array($response['json']['data'] ?? null) ? $response['json']['data'] : [];
    }

    private function rememberSession(string $appToken, string $userId, string $deviceId): array
    {
        $this->credentials['app_token'] = $appToken;
        $this->credentials['token_updated_at'] = date(DATE_ATOM);
        $this->credentialsChanged = true;
        return $this->session = [
            'app_token' => $appToken,
            'user_id' => $userId,
            'device_id' => $deviceId,
            'nickname' => '',
        ];
    }

    private function bandData(string $date, int $steps, string $deviceId): string
    {
        $compressed = base64_decode(self::BAND_DATA_TEMPLATE, true);
        $template = $compressed === false ? false : gzuncompress($compressed);
        $payload = is_string($template) ? json_decode($template, true) : null;
        if (!is_array($payload) || !isset($payload[0]['summary'], $payload[0]['data'][0])) {
            throw new ApiException('SPORT_TEMPLATE_INVALID', '运动数据模板无法读取', 500);
        }
        $summary = json_decode((string)$payload[0]['summary'], true);
        if (!is_array($summary) || !isset($summary['stp'])) {
            throw new ApiException('SPORT_TEMPLATE_INVALID', '运动数据摘要模板无法读取', 500);
        }
        $payload[0]['date'] = $date;
        $payload[0]['data'][0]['did'] = $deviceId;
        $summary['stp']['ttl'] = $steps;
        $payload[0]['summary'] = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function normalizedUsername(): string
    {
        $username = trim((string)($this->credentials['username'] ?? ''));
        $password = (string)($this->credentials['password'] ?? '');
        if ($username === '' || $password === '') {
            throw new ApiException('PLUGIN_CREDENTIAL_INVALID', '小米运动账号和密码不能为空', 422);
        }
        if (!str_contains($username, '@') && !str_starts_with($username, '+86')) {
            $username = '+86' . $username;
        }
        return $username;
    }

    private function accountHeaders(): array
    {
        return [
            'app_name' => 'com.xiaomi.hm.health',
            'x-request-id' => $this->uuid(),
            'accept-language' => 'zh-CN',
            'appname' => 'com.xiaomi.hm.health',
            'cv' => '50818_6.14.0',
            'v' => '2.0',
            'appplatform' => 'android_phone',
            'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
            'User-Agent' => self::USER_AGENT,
        ];
    }

    private function apiHeaders(string $appToken): array
    {
        return [
            'User-Agent' => self::USER_AGENT,
            'country' => 'CN',
            'appplatform' => 'android_phone',
            'x-request-id' => $this->uuid(),
            'timezone' => 'Asia/Shanghai',
            'channel' => 'Normal',
            'cv' => '50818_6.14.0',
            'appname' => 'com.xiaomi.hm.health',
            'v' => '2.0',
            'apptoken' => $appToken,
            'lang' => 'zh_CN',
            'clientid' => self::CLIENT_ID,
        ];
    }

    private function assertNotRateLimited(array $response): void
    {
        if ((int)($response['status'] ?? 0) === 429) {
            throw new ApiException('SPORT_RATE_LIMITED', 'Zepp Life 登录请求过于频繁，请稍后再试', 429);
        }
        if ((int)($response['status'] ?? 0) >= 500 || (int)($response['status'] ?? 0) === 0) {
            throw new ApiException('UPSTREAM_HTTP_ERROR', 'Zepp Life 服务暂时不可用', 502);
        }
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
