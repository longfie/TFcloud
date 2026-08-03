<?php

namespace app\service;

use app\exception\ApiException;
use support\Db;
use support\Request;

final class QqAuthService
{
    private const STATE_SESSION_KEY = 'auth_qq_oauth_state';
    private const EXCHANGE_SESSION_KEY = 'auth_qq_exchange';
    private const ONBOARDING_SESSION_KEY = 'auth_qq_onboarding';
    private const STATE_TTL_SECONDS = 600;
    private const EXCHANGE_TTL_SECONDS = 120;
    private const ONBOARDING_TTL_SECONDS = 600;

    public function start(Request $request, string $returnUrl): array
    {
        return $this->startFlow($request, $returnUrl, 'login', null);
    }

    public function startBinding(Request $request, int $userId, string $returnUrl): array
    {
        $user = Db::table('TF_users')->where('id', $userId)->where('status', 'active')->first();
        if (!$user) {
            throw new ApiException('USER_NOT_FOUND', '用户不存在', 404);
        }
        $identity = Db::table('TF_user_identities')->where('user_id', $userId)->whereIn('provider', ['qq', 'qq_relay'])->first();
        if ($identity && $this->canReplaceLegacyRelayIdentity($identity, $this->config())) {
            return $this->startFlow($request, $returnUrl, 'rebind', $userId);
        }
        if ($identity) {
            throw new ApiException('QQ_ALREADY_LINKED', '当前账号已经绑定 QQ 快捷登录', 409);
        }
        return $this->startFlow($request, $returnUrl, 'bind', $userId);
    }

    private function startFlow(Request $request, string $returnUrl, string $purpose, ?int $userId): array
    {
        $config = $this->config();
        $this->assertConfigured($config);
        $returnUrl = $this->validateReturnUrl($request, $returnUrl, $config);
        $callbackUrl = $this->callbackUrl($request, $config);
        $state = bin2hex(random_bytes(24));
        $provider = (string)$config['qq_login_provider'];
        $relayMode = $provider === 'relay' && $this->relayApplicationReady($config) ? 'application' : 'legacy';
        $request->session()->set(self::STATE_SESSION_KEY, [
            'state_hash' => hash('sha256', $state),
            'return_url' => $returnUrl,
            'callback_url' => $callbackUrl,
            'provider' => $provider,
            'relay_mode' => $relayMode,
            'purpose' => $purpose,
            'user_id' => $userId,
            'agent_hash' => hash('sha256', (string)$request->header('user-agent', '')),
            'expires_at' => time() + self::STATE_TTL_SECONDS,
        ]);

        $authorizationUrl = $provider === 'relay'
            ? $this->appendQuery($relayMode === 'application'
                ? (string)$config['qq_relay_login_url']
                : (string)$config['qq_relay_authorize_url'], $relayMode === 'application'
                ? ['state' => $state]
                : [
                'callback' => rtrim($callbackUrl, '/') . '/relay/' . $state,
                ])
            : 'https://graph.qq.com/oauth2.0/authorize?' . http_build_query([
                'response_type' => 'code',
                'client_id' => $config['qq_app_id'],
                'redirect_uri' => $callbackUrl,
                'state' => $state,
                'scope' => 'get_user_info',
            ], '', '&', PHP_QUERY_RFC3986);
        return [
            'authorization_url' => $authorizationUrl,
            'provider' => $provider,
            'expires_in' => self::STATE_TTL_SECONDS,
        ];
    }

    public function callbackReturnUrl(Request $request): string
    {
        $state = $request->session()->get(self::STATE_SESSION_KEY);
        return is_array($state) && isset($state['return_url'])
            ? (string)$state['return_url']
            : $this->defaultReturnUrl($request, $this->config());
    }

    public function complete(
        Request $request,
        string $stateValue,
        string $code,
        string $providerError = '',
        string $relayPayload = ''
    ): string
    {
        $state = $request->session()->pull(self::STATE_SESSION_KEY);
        if (!is_array($state)
            || (int)($state['expires_at'] ?? 0) < time()
            || !isset($state['state_hash'], $state['agent_hash'], $state['return_url'], $state['callback_url'], $state['provider'])
            || $stateValue === ''
            || !hash_equals((string)$state['state_hash'], hash('sha256', $stateValue))
            || !hash_equals((string)$state['agent_hash'], hash('sha256', (string)$request->header('user-agent', '')))) {
            throw new ApiException('QQ_LOGIN_STATE_INVALID', 'QQ 登录状态已失效，请重新发起登录', 422);
        }
        if ($providerError !== '') {
            throw new ApiException('QQ_LOGIN_CANCELLED', 'QQ 登录已取消', 422);
        }
        $provider = (string)$state['provider'];
        $config = $this->config();
        $this->assertConfigured($config, $provider);
        if ($provider === 'relay') {
            $relayMode = (string)($state['relay_mode'] ?? 'legacy');
            $identity = $relayMode === 'application'
                ? (new QqRelayApplicationCodec())->decode([
                    'subject' => $request->get('subject', ''),
                    'nickname' => $request->get('nickname', ''),
                    'issued_at' => $request->get('issued_at', ''),
                    'state' => $stateValue,
                    'signature' => $request->get('signature', ''),
                ], (string)$config['qq_relay_client_secret'])
                : (new QqRelayCodec())->decode(
                    $relayPayload,
                    (string)$config['qq_relay_decode_key'],
                    (string)$config['qq_relay_app_secret']
                );
            $subject = (string)$identity['subject'];
            $profile = ['nickname' => $identity['nickname'], 'avatar' => '', 'gender' => '', 'source' => $relayMode === 'application' ? 'relay_application' : 'relay'];
            $identityProvider = 'qq_relay';
        } else {
            if ($code === '' || strlen($code) > 512) {
                throw new ApiException('QQ_LOGIN_CODE_INVALID', 'QQ 登录授权码无效', 422);
            }
            $token = $this->exchangeToken($config, $code, (string)$state['callback_url']);
            $subject = $this->fetchOpenId($token);
            $profile = $this->fetchProfile($config, $token, $subject);
            $identityProvider = 'qq';
        }
        $purpose = (string)($state['purpose'] ?? 'login');
        if (in_array($purpose, ['bind', 'rebind'], true)) {
            if ($purpose === 'rebind') {
                $this->replaceLegacyRelayIdentity((int)($state['user_id'] ?? 0), $subject, $profile);
            } else {
                $this->bindIdentity((int)($state['user_id'] ?? 0), $identityProvider, $subject, $profile);
            }
            return $this->appendQuery((string)$state['return_url'], ['qq_bound' => '1']);
        }
        $userId = $this->resolveIdentityUser($identityProvider, $subject, $profile);
        if ($userId === null && empty($config['qq_auto_register'])) {
            throw new ApiException('QQ_LOGIN_NOT_LINKED', '该 QQ 尚未绑定平台账号', 403);
        }

        $exchangeCode = bin2hex(random_bytes(24));
        $exchange = [
            'code_hash' => hash('sha256', $exchangeCode),
            'agent_hash' => hash('sha256', (string)$request->header('user-agent', '')),
            'expires_at' => time() + self::EXCHANGE_TTL_SECONDS,
        ];
        if ($userId !== null) {
            $exchange['user_id'] = $userId;
        } else {
            $exchange['onboarding'] = [
                'provider' => $identityProvider,
                'subject' => $subject,
                'profile' => $profile,
            ];
        }
        $request->session()->set(self::EXCHANGE_SESSION_KEY, $exchange);

        return $this->appendQuery((string)$state['return_url'], ['qq_code' => $exchangeCode]);
    }

    public function exchange(Request $request, string $exchangeCode): array
    {
        $exchange = $request->session()->pull(self::EXCHANGE_SESSION_KEY);
        if (!is_array($exchange)
            || $exchangeCode === ''
            || (int)($exchange['expires_at'] ?? 0) < time()
            || !isset($exchange['code_hash'], $exchange['agent_hash'])
            || !hash_equals((string)$exchange['code_hash'], hash('sha256', $exchangeCode))
            || !hash_equals((string)$exchange['agent_hash'], hash('sha256', (string)$request->header('user-agent', '')))) {
            throw new ApiException('QQ_LOGIN_EXCHANGE_INVALID', 'QQ 登录凭证已失效，请重新登录', 422);
        }

        if (isset($exchange['user_id'])) {
            return (new AuthService())->loginExternal(
                (int)$exchange['user_id'],
                $request->getRealIp(),
                (string)$request->header('user-agent', '')
            );
        }
        $onboarding = $exchange['onboarding'] ?? null;
        if (!is_array($onboarding) || empty($onboarding['provider']) || empty($onboarding['subject'])) {
            throw new ApiException('QQ_LOGIN_EXCHANGE_INVALID', 'QQ 登录凭证已失效，请重新登录', 422);
        }
        $onboardingToken = bin2hex(random_bytes(24));
        $request->session()->set(self::ONBOARDING_SESSION_KEY, [
            'token_hash' => hash('sha256', $onboardingToken),
            'onboarding' => $onboarding,
            'agent_hash' => hash('sha256', (string)$request->header('user-agent', '')),
            'expires_at' => time() + self::ONBOARDING_TTL_SECONDS,
        ]);
        return [
            'requires_profile_completion' => true,
            'onboarding_token' => $onboardingToken,
            'qq_profile' => [
                'nickname' => (string)($onboarding['profile']['nickname'] ?? 'QQ 用户'),
                'avatar' => (string)($onboarding['profile']['avatar'] ?? ''),
            ],
            'expires_in' => self::ONBOARDING_TTL_SECONDS,
        ];
    }

    public function completeRegistration(
        Request $request,
        string $onboardingToken,
        string $username,
        string $email,
        string $emailCode = ''
    ): array {
        $pending = $request->session()->get(self::ONBOARDING_SESSION_KEY);
        if (!is_array($pending)
            || $onboardingToken === ''
            || (int)($pending['expires_at'] ?? 0) < time()
            || !isset($pending['token_hash'], $pending['agent_hash'], $pending['onboarding'])
            || !hash_equals((string)$pending['token_hash'], hash('sha256', $onboardingToken))
            || !hash_equals((string)$pending['agent_hash'], hash('sha256', (string)$request->header('user-agent', '')))) {
            throw new ApiException('QQ_ONBOARDING_EXPIRED', 'QQ 注册信息已过期，请重新授权', 422);
        }
        $username = trim($username);
        $email = mb_strtolower(trim($email));
        if (mb_strlen($username) < 2 || mb_strlen($username) > 64) {
            throw new ApiException('VALIDATION_FAILED', '用户名长度必须为2到64个字符', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) {
            throw new ApiException('VALIDATION_FAILED', '请输入有效的邮箱地址', 422);
        }
        $config = $this->config();
        if (empty($config['qq_auto_register'])) {
            throw new ApiException('QQ_LOGIN_NOT_LINKED', '当前不允许使用 QQ 创建新账号', 403);
        }
        $site = (new SettingsService())->group('site');
        if (!empty($site['registration_email_verification']) && !empty((new SettingsService())->group('mail')['enabled'])) {
            (new EmailVerificationService())->consume($email, trim($emailCode), 'register');
        }
        $onboarding = $pending['onboarding'];
        $provider = (string)($onboarding['provider'] ?? '');
        $subject = (string)($onboarding['subject'] ?? '');
        $profile = is_array($onboarding['profile'] ?? null) ? $onboarding['profile'] : [];
        $now = date('Y-m-d H:i:s');
        $userId = Db::transaction(function () use (
            $provider, $subject, $profile, $username, $email, $config, $now
        ): int {
            if (Db::table('TF_user_identities')->where('provider', $provider)->where('external_subject', $subject)->lockForUpdate()->exists()) {
                throw new ApiException('QQ_ALREADY_LINKED', '该 QQ 已绑定其他平台账号，请直接登录', 409);
            }
            if (Db::table('TF_users')->where('username', $username)->exists()) {
                throw new ApiException('USER_FIELD_DUPLICATE', '用户名已存在', 409);
            }
            if (Db::table('TF_users')->where('email', $email)->exists()) {
                throw new ApiException('USER_FIELD_DUPLICATE', '邮箱已被使用', 409);
            }
            $userId = (int)Db::table('TF_users')->insertGetId([
                'username' => $username,
                'password_hash' => null,
                'display_name' => '',
                'email' => $email,
                'role' => 'user',
                'status' => 'active',
                'quota' => max(0, (int)($config['default_quota'] ?? 2)),
                'daily_sign_email' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            Db::table('TF_user_identities')->insert([
                'user_id' => $userId,
                'provider' => $provider,
                'external_subject' => $subject,
                'display_name' => mb_substr((string)($profile['nickname'] ?? 'QQ 用户'), 0, 191),
                'profile_json' => json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'linked_at' => $now,
                'last_login_at' => $now,
                'updated_at' => $now,
            ]);
            return $userId;
        });
        $request->session()->delete(self::ONBOARDING_SESSION_KEY);
        return (new AuthService())->loginExternal(
            $userId,
            $request->getRealIp(),
            (string)$request->header('user-agent', '')
        );
    }

    public function failureRedirect(string $returnUrl, string $errorCode): string
    {
        $allowed = [
            'QQ_LOGIN_CANCELLED', 'QQ_LOGIN_STATE_INVALID', 'QQ_LOGIN_NOT_LINKED',
            'QQ_LOGIN_UNAVAILABLE', 'QQ_ALREADY_LINKED',
        ];
        return $this->appendQuery($returnUrl, [
            'qq_error' => in_array($errorCode, $allowed, true) ? $errorCode : 'QQ_LOGIN_FAILED',
        ]);
    }

    private function config(): array
    {
        return (new SettingsService())->group('site', true);
    }

    private function assertConfigured(array $config, ?string $selectedProvider = null): void
    {
        if (empty($config['qq_login_enabled'])) {
            throw new ApiException('QQ_LOGIN_DISABLED', 'QQ 快捷登录未启用', 422);
        }
        if (trim((string)($config['qq_callback_url'] ?? '')) === '') {
            throw new ApiException('QQ_LOGIN_UNAVAILABLE', '请先配置 QQ OAuth 回调地址', 503);
        }
        $provider = $selectedProvider ?? (string)($config['qq_login_provider'] ?? 'relay');
        if ($provider === 'relay') {
            $legacyReady = trim((string)($config['qq_relay_authorize_url'] ?? '')) !== ''
                && trim((string)($config['qq_relay_decode_key'] ?? '')) !== ''
                && trim((string)($config['qq_relay_app_secret'] ?? '')) !== '';
            if (!$this->relayApplicationReady($config) && !$legacyReady) {
                throw new ApiException('QQ_LOGIN_UNAVAILABLE', 'QQ 中转登录尚未完成配置', 503);
            }
            return;
        }
        if ($provider !== 'official'
            || trim((string)($config['qq_app_id'] ?? '')) === ''
            || trim((string)($config['qq_app_key'] ?? '')) === '') {
            throw new ApiException('QQ_LOGIN_UNAVAILABLE', 'QQ 官方登录尚未完成配置', 503);
        }
    }

    private function relayApplicationReady(array $config): bool
    {
        return (string)($config['qq_relay_application_status'] ?? '') === 'active'
            && trim((string)($config['qq_relay_client_id'] ?? '')) !== ''
            && trim((string)($config['qq_relay_client_secret'] ?? '')) !== ''
            && trim((string)($config['qq_relay_login_url'] ?? '')) !== '';
    }

    private function canReplaceLegacyRelayIdentity(object $identity, array $config): bool
    {
        if ((string)($identity->provider ?? '') !== 'qq_relay' || !$this->relayApplicationReady($config)) {
            return false;
        }
        $profile = json_decode((string)($identity->profile_json ?? ''), true);
        return !is_array($profile) || (string)($profile['source'] ?? '') !== 'relay_application';
    }

    private function validateReturnUrl(Request $request, string $returnUrl, array $config): string
    {
        $returnUrl = trim($returnUrl);
        $parts = parse_url($returnUrl);
        if (!$parts || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new ApiException('VALIDATION_FAILED', '登录返回地址无效', 422);
        }

        $allowedOrigins = array_filter(array_map('trim', explode(',', (string)(getenv('CORS_ALLOWED_ORIGINS') ?: ''))));
        foreach ([(string)($config['base_url'] ?? ''), (string)$request->header('origin', ''), $this->requestOrigin($request)] as $url) {
            $origin = $this->origin($url);
            if ($origin !== '') {
                $allowedOrigins[] = $origin;
            }
        }
        if (!in_array($this->origin($returnUrl), array_unique($allowedOrigins), true)) {
            throw new ApiException('VALIDATION_FAILED', '登录返回地址不在允许范围内', 422);
        }
        return $returnUrl;
    }

    private function callbackUrl(Request $request, array $config): string
    {
        $configured = trim((string)($config['qq_callback_url'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }
        $baseUrl = rtrim(trim((string)($config['base_url'] ?? '')), '/');
        return ($baseUrl !== '' ? $baseUrl : $this->requestOrigin($request)) . '/api/auth/qq/callback';
    }

    private function defaultReturnUrl(Request $request, array $config): string
    {
        $baseUrl = rtrim(trim((string)($config['base_url'] ?? '')), '/');
        return ($baseUrl !== '' ? $baseUrl : $this->requestOrigin($request)) . '/login';
    }

    private function requestOrigin(Request $request): string
    {
        $proto = strtolower(trim(explode(',', (string)$request->header('x-forwarded-proto', ''))[0] ?? ''));
        if (!in_array($proto, ['http', 'https'], true)) {
            $proto = $request->connection->transport === 'ssl' ? 'https' : 'http';
        }
        $host = trim(explode(',', (string)$request->header('x-forwarded-host', $request->header('host', '')))[0] ?? '');
        if ($host === '' || preg_match('/[\r\n]/', $host)) {
            throw new ApiException('QQ_LOGIN_UNAVAILABLE', '无法确定 QQ 登录回调地址', 503);
        }
        return $proto . '://' . $host;
    }

    private function origin(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        return strtolower((string)$parts['scheme']) . '://' . strtolower((string)$parts['host']) . $port;
    }

    private function exchangeToken(array $config, string $code, string $callbackUrl): string
    {
        $payload = $this->requestJsonOrQuery('https://graph.qq.com/oauth2.0/token?' . http_build_query([
            'grant_type' => 'authorization_code',
            'client_id' => $config['qq_app_id'],
            'client_secret' => $config['qq_app_key'],
            'code' => $code,
            'redirect_uri' => $callbackUrl,
            'fmt' => 'json',
        ], '', '&', PHP_QUERY_RFC3986));
        $token = trim((string)($payload['access_token'] ?? ''));
        if ($token === '') {
            throw new ApiException('QQ_LOGIN_FAILED', 'QQ 授权令牌获取失败', 502);
        }
        return $token;
    }

    private function fetchOpenId(string $token): string
    {
        $payload = $this->requestJsonOrQuery('https://graph.qq.com/oauth2.0/me?' . http_build_query([
            'access_token' => $token,
            'fmt' => 'json',
        ], '', '&', PHP_QUERY_RFC3986));
        $subject = trim((string)($payload['openid'] ?? ''));
        if ($subject === '' || strlen($subject) > 191) {
            throw new ApiException('QQ_LOGIN_FAILED', 'QQ 用户身份获取失败', 502);
        }
        return $subject;
    }

    private function fetchProfile(array $config, string $token, string $subject): array
    {
        $payload = $this->requestJsonOrQuery('https://graph.qq.com/user/get_user_info?' . http_build_query([
            'access_token' => $token,
            'oauth_consumer_key' => $config['qq_app_id'],
            'openid' => $subject,
            'format' => 'json',
        ], '', '&', PHP_QUERY_RFC3986));
        if (isset($payload['ret']) && (int)$payload['ret'] !== 0) {
            throw new ApiException('QQ_LOGIN_FAILED', 'QQ 用户资料获取失败', 502);
        }
        return [
            'nickname' => mb_substr(trim((string)($payload['nickname'] ?? 'QQ 用户')), 0, 191),
            'avatar' => trim((string)($payload['figureurl_qq_2'] ?? $payload['figureurl_qq_1'] ?? '')),
            'gender' => mb_substr(trim((string)($payload['gender'] ?? '')), 0, 32),
        ];
    }

    private function resolveIdentityUser(string $identityProvider, string $subject, array $profile): ?int
    {
        $identity = Db::table('TF_user_identities')
            ->where('provider', $identityProvider)
            ->where('external_subject', $subject)
            ->first();
        $now = date('Y-m-d H:i:s');
        $profileJson = json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($identity) {
            Db::table('TF_user_identities')->where('id', $identity->id)->update([
                'display_name' => $profile['nickname'],
                'profile_json' => $profileJson,
                'last_login_at' => $now,
                'updated_at' => $now,
            ]);
            return (int)$identity->user_id;
        }
        return null;
    }

    private function bindIdentity(int $userId, string $provider, string $subject, array $profile): void
    {
        if ($userId < 1 || !Db::table('TF_users')->where('id', $userId)->where('status', 'active')->exists()) {
            throw new ApiException('USER_NOT_FOUND', '用户不存在', 404);
        }
        $now = date('Y-m-d H:i:s');
        Db::transaction(function () use ($userId, $provider, $subject, $profile, $now): void {
            $external = Db::table('TF_user_identities')
                ->where('provider', $provider)
                ->where('external_subject', $subject)
                ->lockForUpdate()
                ->first();
            if ($external && (int)$external->user_id !== $userId) {
                throw new ApiException('QQ_ALREADY_LINKED', '该 QQ 已绑定其他平台账号', 409);
            }
            $current = Db::table('TF_user_identities')
                ->where('user_id', $userId)
                ->whereIn('provider', ['qq', 'qq_relay'])
                ->lockForUpdate()
                ->first();
            if ($current && (!$external || (int)$current->id !== (int)$external->id)) {
                throw new ApiException('QQ_ALREADY_LINKED', '当前账号已经绑定 QQ 快捷登录', 409);
            }
            if ($external) {
                return;
            }
            Db::table('TF_user_identities')->insert([
                'user_id' => $userId,
                'provider' => $provider,
                'external_subject' => $subject,
                'display_name' => mb_substr((string)($profile['nickname'] ?? 'QQ 用户'), 0, 191),
                'profile_json' => json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'linked_at' => $now,
                'last_login_at' => null,
                'updated_at' => $now,
            ]);
        });
    }

    private function replaceLegacyRelayIdentity(int $userId, string $subject, array $profile): void
    {
        if ($userId < 1 || !Db::table('TF_users')->where('id', $userId)->where('status', 'active')->exists()) {
            throw new ApiException('USER_NOT_FOUND', '用户不存在', 404);
        }
        $now = date('Y-m-d H:i:s');
        Db::transaction(function () use ($userId, $subject, $profile, $now): void {
            $external = Db::table('TF_user_identities')
                ->where('provider', 'qq_relay')
                ->where('external_subject', $subject)
                ->lockForUpdate()
                ->first();
            if ($external && (int)$external->user_id !== $userId) {
                throw new ApiException('QQ_ALREADY_LINKED', '该 QQ 已绑定其他平台账号', 409);
            }
            $current = Db::table('TF_user_identities')
                ->where('user_id', $userId)
                ->where('provider', 'qq_relay')
                ->lockForUpdate()
                ->first();
            if (!$current) {
                throw new ApiException('QQ_LOGIN_STATE_INVALID', '原 QQ 绑定不存在，请重新绑定', 422);
            }
            if ($external && (int)$external->id !== (int)$current->id) {
                throw new ApiException('QQ_ALREADY_LINKED', '该 QQ 已绑定其他平台账号', 409);
            }
            Db::table('TF_user_identities')->where('id', $current->id)->update([
                'external_subject' => $subject,
                'display_name' => mb_substr((string)($profile['nickname'] ?? 'QQ 用户'), 0, 191),
                'profile_json' => json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'linked_at' => $now,
                'last_login_at' => null,
                'updated_at' => $now,
            ]);
        });
    }

    private function requestJsonOrQuery(string $url): array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'TF-Sign/QQ-OAuth',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false || $error !== '' || $status < 200 || $status >= 300) {
            throw new ApiException('QQ_LOGIN_FAILED', 'QQ 登录服务暂时不可用', 502);
        }
        $body = trim((string)$body);
        $payload = json_decode($body, true);
        if (!is_array($payload) && preg_match('/^callback\s*\(\s*(\{.*\})\s*\);?$/s', $body, $match)) {
            $payload = json_decode($match[1], true);
        }
        if (!is_array($payload)) {
            parse_str($body, $payload);
        }
        if (!is_array($payload) || isset($payload['error']) || (isset($payload['ret']) && (int)$payload['ret'] < 0)) {
            throw new ApiException('QQ_LOGIN_FAILED', 'QQ 登录服务返回异常', 502);
        }
        return $payload;
    }

    private function appendQuery(string $url, array $parameters): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }
}
