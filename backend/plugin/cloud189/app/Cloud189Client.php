<?php

namespace plugin\cloud189\app;

use app\exception\ApiException;

/**
 * Tianyi Cloud protocol adapter rewritten for TF Sign from
 * wes-lin/cloud189-sdk. See ../LICENSE.cloud189-sdk and
 * ../THIRD_PARTY_NOTICES.md.
 */
final class Cloud189Client
{
    private const WEB_URL = 'https://cloud.189.cn';
    private const AUTH_URL = 'https://open.e.189.cn';
    private const API_URL = 'https://api.cloud.189.cn';
    private const APP_ID = '8025431004';
    private const CLIENT_TYPE = '10020';
    private const RETURN_URL = 'https://m.cloud.189.cn/zhuanti/2020/loginErrorPc/index.html';
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/87.0.4280.88 Safari/537.36';

    private Cloud189HttpClientInterface $http;
    private array $credentials;
    private ?array $session = null;
    private bool $credentialsChanged = false;

    public function __construct(array $credentials, ?Cloud189HttpClientInterface $http = null)
    {
        $this->credentials = $credentials;
        $this->http = $http ?? new CurlCloud189HttpClient();
    }

    public function authenticate(): array
    {
        if ($this->session !== null) {
            return $this->session;
        }
        $this->assertCredentials();
        $accessToken = trim((string)($this->credentials['access_token'] ?? ''));
        $expiresAt = (int)($this->credentials['access_token_expires_at'] ?? 0);
        if ($accessToken !== '' && $expiresAt > time() + 60) {
            $session = $this->getSession(['accessToken' => $accessToken], false);
            if ($session !== null) {
                return $this->session = $session;
            }
        }

        $refreshToken = trim((string)($this->credentials['refresh_token'] ?? ''));
        if ($refreshToken !== '') {
            $refreshed = $this->refreshToken($refreshToken);
            if ($refreshed !== null) {
                $session = $this->getSession(['accessToken' => $refreshed['access_token']], false);
                if ($session !== null) {
                    $this->rememberTokens(
                        $refreshed['access_token'],
                        $refreshed['refresh_token'],
                        $refreshed['expires_in']
                    );
                    return $this->session = $session;
                }
            }
        }

        $redirectUrl = $this->passwordLogin(
            trim((string)$this->credentials['username']),
            (string)$this->credentials['password']
        );
        $session = $this->getSession(['redirectURL' => $redirectUrl], true);
        if ($session === null) {
            throw new ApiException('CLOUD189_LOGIN_FAILED', '天翼云盘登录会话获取失败', 502);
        }
        $this->rememberTokens(
            (string)$session['accessToken'],
            (string)$session['refreshToken'],
            6 * 24 * 60 * 60
        );
        return $this->session = $session;
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

    public function dailySign(?array $session = null): array
    {
        $session ??= $this->authenticate();
        $response = $this->http->request('GET', self::WEB_URL . '/mkt/userSign.action', [
            'query' => [
                'rand' => (string)round(microtime(true) * 1000),
                'clientType' => 'TELEANDROID',
                'version' => '9.0.6',
                'model' => 'KB2000',
                'sessionKey' => (string)$session['sessionKey'],
            ],
            'headers' => $this->webHeaders(),
        ]);
        $this->assertAvailable($response);
        $json = $response['json'];
        if (!is_array($json) || !array_key_exists('isSign', $json)) {
            throw new ApiException('CLOUD189_RESPONSE_INVALID', '天翼云盘签到返回格式错误', 502);
        }
        $already = filter_var($json['isSign'], FILTER_VALIDATE_BOOLEAN);
        $bonus = max(0, (int)($json['netdiskBonus'] ?? 0));
        return [
            'key' => 'cloud189:daily_sign:' . date('Y-m-d'),
            'action' => 'daily_sign',
            'status' => $already ? 'already_done' : 'succeeded',
            'target_type' => 'cloud_storage',
            'target_id' => $this->accountIdentifier(),
            'target_name' => '天翼云盘',
            'code' => '0',
            'message' => $already
                ? '天翼云盘今日已签到，获得 ' . $bonus . ' MB'
                : '天翼云盘签到成功，获得 ' . $bonus . ' MB',
            'rewards' => ['storage_mb' => $bonus],
            'metrics' => [],
        ];
    }

    public function accountIdentifier(): string
    {
        return substr(hash('sha256', mb_strtolower(trim((string)($this->credentials['username'] ?? '')))), 0, 24);
    }

    public function maskedLoginName(?array $session = null): string
    {
        $session ??= $this->authenticate();
        $loginName = trim((string)($session['loginName'] ?? $this->credentials['username'] ?? ''));
        if (preg_match('/^(\d{3})\d+(\d{4})$/', $loginName, $match)) {
            return $match[1] . '****' . $match[2];
        }
        if (str_contains($loginName, '@')) {
            [$name, $domain] = explode('@', $loginName, 2);
            return mb_substr($name, 0, 2) . '***@' . $domain;
        }
        return '天翼云盘账号';
    }

    private function passwordLogin(string $username, string $password): string
    {
        try {
            return $this->passwordLoginForPc($username, $password);
        } catch (ApiException $exception) {
            if (!in_array($exception->errorCode, [
                'CLOUD189_LOGIN_FORM_CHANGED',
                'CLOUD189_ENCRYPT_CONFIG_INVALID',
                'CLOUD189_LOGIN_FAILED',
            ], true)) {
                throw $exception;
            }
        }
        return $this->passwordLoginForMobile($username, $password);
    }

    private function passwordLoginForPc(string $username, string $password): string
    {
        if (!function_exists('openssl_public_encrypt')) {
            throw new ApiException('CLOUD189_RUNTIME_MISSING', '服务器缺少 openssl 扩展', 500);
        }
        $loginForm = $this->getLoginForm();
        $encryptResponse = $this->http->request(
            'POST',
            self::AUTH_URL . '/api/logbox/config/encryptConf.do',
            ['headers' => $this->authHeaders()]
        );
        $this->assertAvailable($encryptResponse);
        $encrypt = $encryptResponse['json']['data'] ?? null;
        if (!is_array($encrypt) || empty($encrypt['pubKey']) || !isset($encrypt['pre'])) {
            throw new ApiException('CLOUD189_ENCRYPT_CONFIG_INVALID', '天翼云盘登录公钥获取失败', 502);
        }
        $prefix = (string)$encrypt['pre'];
        $response = $this->http->request(
            'POST',
            self::AUTH_URL . '/api/logbox/oauth2/loginSubmit.do',
            [
                'headers' => $this->authHeaders() + [
                    'Referer' => self::AUTH_URL,
                    'lt' => $loginForm['lt'],
                    'REQID' => $loginForm['reqId'],
                ],
                'form' => [
                    'appKey' => self::APP_ID,
                    'accountType' => '02',
                    'validateCode' => '',
                    'captchaToken' => $loginForm['captchaToken'],
                    'dynamicCheck' => 'FALSE',
                    'clientType' => '1',
                    'cb_SaveName' => '3',
                    'isOauth2' => 'false',
                    'returnUrl' => self::RETURN_URL,
                    'paramId' => $loginForm['paramId'],
                    'userName' => $prefix . $this->rsaEncrypt((string)$encrypt['pubKey'], $username),
                    'password' => $prefix . $this->rsaEncrypt((string)$encrypt['pubKey'], $password),
                ],
            ]
        );
        $this->assertAvailable($response);
        $json = $response['json'];
        if (!is_array($json)) {
            throw new ApiException('CLOUD189_LOGIN_FAILED', '天翼云盘登录返回格式错误', 502);
        }
        if ((int)($json['result'] ?? -1) !== 0 || empty($json['toUrl'])) {
            $message = trim((string)($json['msg'] ?? '登录失败'));
            if (str_contains($message, '验证码') || str_contains($message, '设备锁')
                || str_contains($message, '安全验证')) {
                throw new ApiException(
                    'CLOUD189_INTERACTION_REQUIRED',
                    '天翼云盘要求人工验证，请先在网页端完成验证或关闭设备锁',
                    409
                );
            }
            throw new ApiException('PLUGIN_CREDENTIAL_INVALID', '天翼云盘账号或密码不正确：' . $message, 422);
        }
        return (string)$json['toUrl'];
    }

    private function passwordLoginForMobile(string $username, string $password): string
    {
        $entry = $this->http->request(
            'GET',
            'https://m.cloud.189.cn/udb/udb_login.jsp',
            [
                'query' => [
                    'pageId' => '1',
                    'pageKey' => 'default',
                    'clientType' => 'wap',
                    'redirectURL' => 'https://m.cloud.189.cn/zhuanti/2021/shakeLottery/index.html',
                ],
                'headers' => $this->webHeaders(),
                'follow_redirects' => true,
            ]
        );
        $this->assertAvailable($entry);
        if (!preg_match("/href\\s*=\\s*['\"]([^'\"]*autoLogin[^'\"]*)['\"]/i", $entry['body'], $match)) {
            throw new ApiException('CLOUD189_LOGIN_FORM_CHANGED', '天翼云盘动态登录入口获取失败', 502);
        }
        $autoLoginUrl = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
        if (!str_starts_with($autoLoginUrl, 'http')) {
            $autoLoginUrl = 'https://m.cloud.189.cn/' . ltrim($autoLoginUrl, '/');
        }
        $redirect = $this->http->request('GET', $autoLoginUrl, [
            'headers' => $this->webHeaders(),
            'follow_redirects' => true,
        ]);
        $this->assertAvailable($redirect);
        $effectiveUrl = (string)($redirect['effective_url'] ?? $autoLoginUrl);
        parse_str((string)parse_url($effectiveUrl, PHP_URL_QUERY), $params);
        if ($params === []) {
            throw new ApiException('CLOUD189_LOGIN_FORM_CHANGED', '天翼云盘动态登录参数获取失败', 502);
        }

        $confResponse = $this->http->request(
            'POST',
            self::AUTH_URL . '/api/logbox/oauth2/wap/appConf.do',
            ['query' => $params, 'headers' => $this->authHeaders()]
        );
        $this->assertAvailable($confResponse);
        $conf = $confResponse['json'];
        if (!is_array($conf) || (string)($conf['result'] ?? '-1') !== '0' || !is_array($conf['data'] ?? null)) {
            throw new ApiException('CLOUD189_LOGIN_FORM_CHANGED', '天翼云盘动态登录配置获取失败', 502);
        }
        $data = $conf['data'];
        foreach (['lt', 'returnUrl', 'paramId'] as $key) {
            if (empty($data[$key])) {
                throw new ApiException('CLOUD189_LOGIN_FORM_CHANGED', '天翼云盘动态登录配置不完整', 502);
            }
        }

        $parts = parse_url($effectiveUrl);
        $path = preg_replace('~/index\\.html$~', '/login.html', (string)($parts['path'] ?? ''));
        $loginPageUrl = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'open.e.189.cn') . $path;
        if (!empty($parts['query'])) {
            $loginPageUrl .= '?' . $parts['query'];
        }
        $loginPage = $this->http->request('GET', $loginPageUrl, [
            'headers' => $this->authHeaders(),
            'follow_redirects' => true,
        ]);
        $this->assertAvailable($loginPage);
        if (!preg_match('/id=["\']j_rsaKey["\'][^>]*value=["\']([^"\']+)["\']/i', $loginPage['body'], $match)) {
            throw new ApiException('CLOUD189_LOGIN_FORM_CHANGED', '天翼云盘动态登录公钥获取失败', 502);
        }

        $response = $this->http->request(
            'POST',
            self::AUTH_URL . '/api/logbox/oauth2/loginSubmit.do',
            [
                'headers' => $this->authHeaders() + [
                    'Referer' => self::AUTH_URL,
                    'lt' => (string)$data['lt'],
                ],
                'form' => [
                    'appKey' => 'cloud',
                    'accountType' => (string)($data['accountType'] ?? '02'),
                    'userName' => '{RSA}' . $this->rsaEncrypt((string)$match[1], $username),
                    'password' => '{RSA}' . $this->rsaEncrypt((string)$match[1], $password),
                    'validateCode' => '',
                    'captchaToken' => '',
                    'returnUrl' => (string)$data['returnUrl'],
                    'mailSuffix' => '@189.cn',
                    'paramId' => (string)$data['paramId'],
                ],
            ]
        );
        $this->assertAvailable($response);
        $json = $response['json'];
        if (!is_array($json) || (string)($json['result'] ?? '-1') !== '0' || empty($json['toUrl'])) {
            $message = trim((string)($json['msg'] ?? '登录失败'));
            if (str_contains($message, '验证码') || str_contains($message, '设备锁')
                || str_contains($message, '安全验证')) {
                throw new ApiException(
                    'CLOUD189_INTERACTION_REQUIRED',
                    '天翼云盘要求人工验证，请先在网页端完成验证或关闭设备锁',
                    409
                );
            }
            throw new ApiException('PLUGIN_CREDENTIAL_INVALID', '天翼云盘账号或密码不正确：' . $message, 422);
        }
        return (string)$json['toUrl'];
    }

    private function getLoginForm(): array
    {
        $response = $this->http->request(
            'GET',
            self::WEB_URL . '/api/portal/unifyLoginForPC.action',
            [
                'query' => [
                    'appId' => self::APP_ID,
                    'clientType' => self::CLIENT_TYPE,
                    'returnURL' => self::RETURN_URL,
                    'timeStamp' => (string)round(microtime(true) * 1000),
                ],
                'headers' => $this->webHeaders(),
                'follow_redirects' => true,
            ]
        );
        $this->assertAvailable($response);
        $html = $response['body'];
        $patterns = [
            'captchaToken' => "/name=['\"]captchaToken['\"][^>]*value=['\"]([^'\"]+)['\"]/i",
            'lt' => '/\\blt\\s*=\\s*["\']([^"\']+)["\']/i',
            'paramId' => '/\\bparamId\\s*=\\s*["\']([^"\']+)["\']/i',
            'reqId' => '/\\breqId\\s*=\\s*["\']([^"\']+)["\']/i',
        ];
        $values = [];
        foreach ($patterns as $key => $pattern) {
            if (!preg_match($pattern, $html, $match)) {
                throw new ApiException('CLOUD189_LOGIN_FORM_CHANGED', '天翼云盘登录页面结构已经变化', 502);
            }
            $values[$key] = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
        }
        return $values;
    }

    private function getSession(array $credential, bool $required): ?array
    {
        $response = $this->http->request('POST', self::API_URL . '/getSessionForPC.action', [
            'query' => [
                'appId' => self::APP_ID,
                'clientType' => 'TELEPC',
                'version' => '6.2',
                'channelId' => 'web_cloud.189.cn',
                'rand' => (string)round(microtime(true) * 1000),
            ] + $credential,
            'headers' => $this->apiHeaders(),
        ]);
        $this->assertAvailable($response);
        $json = $response['json'];
        $valid = is_array($json)
            && (int)($json['res_code'] ?? -1) === 0
            && !empty($json['sessionKey'])
            && !empty($json['accessToken']);
        if (!$valid) {
            if ($required) {
                throw new ApiException('CLOUD189_LOGIN_FAILED', '天翼云盘登录会话无效', 502);
            }
            return null;
        }
        return $json;
    }

    private function refreshToken(string $refreshToken): ?array
    {
        $response = $this->http->request('POST', self::AUTH_URL . '/api/oauth2/refreshToken.do', [
            'headers' => $this->authHeaders(),
            'form' => [
                'clientId' => self::APP_ID,
                'refreshToken' => $refreshToken,
                'grantType' => 'refresh_token',
                'format' => 'json',
            ],
        ]);
        $this->assertAvailable($response);
        $json = $response['json'];
        if (!is_array($json) || empty($json['accessToken']) || empty($json['refreshToken'])) {
            return null;
        }
        return [
            'access_token' => (string)$json['accessToken'],
            'refresh_token' => (string)$json['refreshToken'],
            'expires_in' => max(60, (int)($json['expiresIn'] ?? 6 * 24 * 60 * 60)),
        ];
    }

    private function rememberTokens(string $accessToken, string $refreshToken, int $expiresIn): void
    {
        $this->credentials['access_token'] = $accessToken;
        $this->credentials['refresh_token'] = $refreshToken;
        $this->credentials['access_token_expires_at'] = time() + max(60, $expiresIn);
        $this->credentials['token_updated_at'] = date(DATE_ATOM);
        $this->credentialsChanged = true;
    }

    private function rsaEncrypt(string $publicKey, string $value): string
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(preg_replace('/\\s+/', '', $publicKey), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
        $encrypted = '';
        if (!openssl_public_encrypt($value, $encrypted, $pem, OPENSSL_PKCS1_PADDING)) {
            throw new ApiException('CLOUD189_LOGIN_ENCRYPT_FAILED', '天翼云盘登录信息加密失败', 500);
        }
        return bin2hex($encrypted);
    }

    private function assertCredentials(): void
    {
        if (trim((string)($this->credentials['username'] ?? '')) === ''
            || (string)($this->credentials['password'] ?? '') === '') {
            throw new ApiException('PLUGIN_CREDENTIAL_INVALID', '天翼云盘账号和密码不能为空', 422);
        }
    }

    private function assertAvailable(array $response): void
    {
        $status = (int)($response['status'] ?? 0);
        if ($status === 429) {
            throw new ApiException('CLOUD189_RATE_LIMITED', '天翼云盘请求过于频繁，请稍后再试', 429);
        }
        if ($status === 0 || $status >= 500) {
            throw new ApiException('UPSTREAM_HTTP_ERROR', '天翼云盘服务暂时不可用', 502);
        }
    }

    private function authHeaders(): array
    {
        return ['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json;charset=UTF-8'];
    }

    private function apiHeaders(): array
    {
        return ['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json;charset=UTF-8'];
    }

    private function webHeaders(): array
    {
        return [
            'User-Agent' => self::USER_AGENT,
            'Referer' => self::WEB_URL . '/web/main/',
            'Accept' => 'application/json;charset=UTF-8',
        ];
    }
}
