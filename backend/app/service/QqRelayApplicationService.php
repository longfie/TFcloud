<?php

namespace app\service;

use app\exception\ApiException;
use support\Request;

final class QqRelayApplicationService
{
    private const APPLY_URL = 'https://blog.eirds.cn/qqlogin/API/apply.php';
    private const VERIFY_URL = 'https://blog.eirds.cn/qqlogin/API/verify.php';
    private const STATUS_URL = 'https://blog.eirds.cn/qqlogin/API/status.php';
    private const SERVICE_ORIGIN = 'https://blog.eirds.cn';

    /**
     * Submit an application, publish its challenge through the local public route,
     * then ask the relay service to verify the domain immediately.
     *
     * @return array<string, mixed>
     */
    public function applyAndVerify(Request $request, int $userId): array
    {
        $settings = new SettingsService();
        $site = $settings->group('site', true);
        if (($site['qq_relay_application_status'] ?? '') === 'active'
            && trim((string)($site['qq_relay_client_id'] ?? '')) !== ''
            && !empty($site['qq_relay_client_secret_configured'])) {
            throw new ApiException('QQ_RELAY_ALREADY_ACTIVE', 'QQ 快捷登录应用已经生效，无需重复申请', 409);
        }

        $origin = $this->currentOrigin($request);
        $callbackUrl = $origin . '/api/auth/qq/callback';
        $verificationUrl = $origin . '/api/auth/qq/verification';
        $installationId = $this->installationId($site, $settings, $userId);
        $application = $this->postJson(self::APPLY_URL, [
            'site_name' => trim((string)($site['name'] ?? '')) ?: '天方云签',
            'site_url' => $origin,
            'callback_url' => $callbackUrl,
            'verification_url' => $verificationUrl,
            'installation_id' => $installationId,
            'software_name' => '天方云签',
            'software_version' => $this->softwareVersion(),
            'contact_email' => trim((string)($site['contact_email'] ?? '')),
        ]);

        $clientId = trim((string)($application['client_id'] ?? ''));
        $clientSecret = trim((string)($application['client_secret'] ?? ''));
        $verificationContent = trim((string)($application['verification_file_content'] ?? ''));
        $verificationExpiresAt = trim((string)($application['verification_expires_at'] ?? ''));
        if (!preg_match('/^site_[A-Za-z0-9._~-]{8,180}$/', $clientId)
            || !preg_match('/^qs_[A-Za-z0-9._~-]{16,250}$/', $clientSecret)
            || $verificationContent === ''
            || strlen($verificationContent) > 4096) {
            throw new ApiException('QQ_RELAY_APPLY_INVALID_RESPONSE', 'QQ 快捷登录申请服务返回的数据不完整', 502);
        }

        $returnedVerificationUrl = trim((string)($application['verification_url'] ?? $verificationUrl));
        if (!hash_equals($verificationUrl, $returnedVerificationUrl)) {
            throw new ApiException('QQ_RELAY_APPLY_INVALID_RESPONSE', 'QQ 快捷登录申请服务返回了错误的验证地址', 502);
        }

        $settings->update('site', [
            'qq_login_provider' => 'relay',
            'qq_callback_url' => $callbackUrl,
            'qq_relay_client_id' => $clientId,
            'qq_relay_client_secret' => $clientSecret,
            'qq_relay_login_url' => '',
            'qq_relay_application_status' => 'pending',
            'qq_relay_verification_content' => $verificationContent,
            'qq_relay_verification_expires_at' => $verificationExpiresAt,
        ], $userId);

        try {
            return $this->verify($userId);
        } catch (ApiException $exception) {
            return [
                'client_id' => $clientId,
                'client_secret_configured' => true,
                'status' => 'pending',
                'verified' => false,
                'callback_url' => $callbackUrl,
                'verification_url' => $verificationUrl,
                'verification_expires_at' => $verificationExpiresAt,
                'verification_error' => $exception->getMessage(),
            ];
        }
    }

    /** @return array<string, mixed> */
    public function verify(int $userId): array
    {
        $settings = new SettingsService();
        $site = $settings->group('site', true);
        $clientId = trim((string)($site['qq_relay_client_id'] ?? ''));
        $clientSecret = trim((string)($site['qq_relay_client_secret'] ?? ''));
        if ($clientId === '' || $clientSecret === ''
            || ($site['qq_relay_application_status'] ?? '') !== 'pending'
            || empty($site['qq_relay_verification_content_configured'])) {
            throw new ApiException('QQ_RELAY_APPLICATION_NOT_PENDING', '没有等待验证的 QQ 快捷登录申请', 422);
        }

        $application = $this->postJson(self::VERIFY_URL, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);
        $returnedClientId = trim((string)($application['client_id'] ?? ''));
        $loginUrl = trim((string)($application['login_url'] ?? ''));
        if (!hash_equals($clientId, $returnedClientId) || !$this->trustedLoginUrl($loginUrl, $clientId)) {
            throw new ApiException('QQ_RELAY_VERIFY_INVALID_RESPONSE', 'QQ 快捷登录验证服务返回的数据无效', 502);
        }

        $updated = $settings->update('site', [
            'qq_login_provider' => 'relay',
            'qq_login_enabled' => true,
            'qq_relay_login_url' => $loginUrl,
            'qq_relay_application_status' => 'active',
            'qq_relay_verification_expires_at' => '',
        ], $userId);

        return [
            'client_id' => $clientId,
            'client_secret_configured' => true,
            'status' => 'active',
            'verified' => true,
            'callback_url' => (string)$updated['qq_callback_url'],
            'login_url' => $loginUrl,
        ];
    }

    public function verificationContent(): ?string
    {
        $site = (new SettingsService())->group('site', true);
        if (($site['qq_relay_application_status'] ?? '') !== 'pending') {
            return null;
        }
        $content = trim((string)($site['qq_relay_verification_content'] ?? ''));
        return $content !== '' ? $content : null;
    }

    /** @return array<string, mixed> */
    public function configureManually(
        Request $request,
        int $userId,
        string $clientId,
        string $clientSecret
    ): array {
        $clientId = trim($clientId);
        $clientSecret = trim($clientSecret);
        if (!preg_match('/^site_[A-Za-z0-9._~-]{8,180}$/', $clientId)) {
            throw new ApiException('QQ_RELAY_CLIENT_ID_INVALID', '请输入有效的中转 AppID', 422);
        }

        $settings = new SettingsService();
        $site = $settings->group('site', true);
        $secretToVerify = $clientSecret !== ''
            ? $clientSecret
            : trim((string)($site['qq_relay_client_secret'] ?? ''));
        if (!preg_match('/^qs_[A-Za-z0-9._~-]{16,250}$/', $secretToVerify)) {
            throw new ApiException('QQ_RELAY_CLIENT_SECRET_INVALID', '请输入有效的中转 AppKey', 422);
        }

        $application = $this->postJson(self::STATUS_URL, [
            'client_id' => $clientId,
            'client_secret' => $secretToVerify,
        ]);
        $origin = $this->currentOrigin($request);
        $callbackUrl = $origin . '/api/auth/qq/callback';
        $returnedClientId = trim((string)($application['client_id'] ?? ''));
        $status = trim((string)($application['status'] ?? ''));
        $canonicalOrigin = rtrim(trim((string)($application['canonical_origin'] ?? '')), '/');
        $returnedCallback = trim((string)($application['callback_url'] ?? ''));
        $loginUrl = trim((string)($application['login_url'] ?? ''));
        if (!hash_equals($clientId, $returnedClientId)
            || $status !== 'active'
            || !hash_equals($origin, $canonicalOrigin)
            || !hash_equals($callbackUrl, $returnedCallback)
            || !$this->trustedLoginUrl($loginUrl, $clientId)) {
            throw new ApiException(
                'QQ_RELAY_CREDENTIALS_SITE_MISMATCH',
                '该 AppID/AppKey 未激活，或绑定的域名、回调地址与当前站点不一致',
                422
            );
        }

        $updates = [
            'qq_login_provider' => 'relay',
            'qq_login_enabled' => true,
            'qq_callback_url' => $callbackUrl,
            'qq_relay_client_id' => $clientId,
            'qq_relay_login_url' => $loginUrl,
            'qq_relay_application_status' => 'active',
            'qq_relay_verification_expires_at' => '',
        ];
        if ($clientSecret !== '') {
            $updates['qq_relay_client_secret'] = $clientSecret;
        }
        $settings->update('site', $updates, $userId);

        return [
            'client_id' => $clientId,
            'client_secret_configured' => true,
            'status' => 'active',
            'verified' => true,
            'callback_url' => $callbackUrl,
            'login_url' => $loginUrl,
        ];
    }

    /** @param array<string, mixed> $site */
    private function installationId(array $site, SettingsService $settings, int $userId): string
    {
        $current = trim((string)($site['qq_relay_installation_id'] ?? ''));
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $current)) {
            return strtolower($current);
        }

        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        $uuid = sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
        $settings->update('site', ['qq_relay_installation_id' => $uuid], $userId);
        return $uuid;
    }

    private function currentOrigin(Request $request): string
    {
        $originHeader = trim((string)$request->header('origin', ''));
        $origin = $this->normalizeOrigin($originHeader);
        if ($origin !== '') {
            return $origin;
        }

        $proto = strtolower(trim(explode(',', (string)$request->header('x-forwarded-proto', ''))[0] ?? ''));
        if (!in_array($proto, ['http', 'https'], true)) {
            $proto = $request->connection->transport === 'ssl' ? 'https' : 'http';
        }
        $host = trim(explode(',', (string)$request->header('x-forwarded-host', $request->header('host', '')))[0] ?? '');
        if ($host === '' || preg_match('/[\r\n]/', $host)) {
            throw new ApiException('QQ_RELAY_SITE_URL_INVALID', '无法确定当前站点域名', 422);
        }
        $origin = $this->normalizeOrigin($proto . '://' . $host);
        if ($origin === '') {
            throw new ApiException('QQ_RELAY_SITE_URL_INVALID', '当前站点域名无效', 422);
        }
        return $origin;
    }

    private function normalizeOrigin(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        return strtolower((string)$parts['scheme']) . '://' . strtolower((string)$parts['host']) . $port;
    }

    private function softwareVersion(): string
    {
        $path = base_path() . '/VERSION';
        $version = is_readable($path) ? trim((string)file_get_contents($path)) : '';
        return preg_match('/^v?\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version)
            ? ltrim($version, 'v')
            : '0.0.0';
    }

    private function trustedLoginUrl(string $url, string $clientId): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || $this->normalizeOrigin($url) !== self::SERVICE_ORIGIN
            || !str_starts_with((string)($parts['path'] ?? ''), '/qqlogin/oauth/')) {
            return false;
        }
        parse_str((string)($parts['query'] ?? ''), $query);
        return isset($query['key']) && is_string($query['key']) && hash_equals($clientId, $query['key']);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $payload): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new ApiException('QQ_RELAY_SERVICE_UNAVAILABLE', '无法初始化 QQ 快捷登录申请请求', 502);
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 18,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'TF-Sign/' . $this->softwareVersion() . ' QQ-Relay-Application',
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body,
        ]);
        $response = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($response === false || $error !== '') {
            throw new ApiException('QQ_RELAY_SERVICE_UNAVAILABLE', '无法连接 QQ 快捷登录申请服务', 502);
        }
        if (strlen((string)$response) > 65536) {
            throw new ApiException('QQ_RELAY_SERVICE_INVALID_RESPONSE', 'QQ 快捷登录申请服务响应过大', 502);
        }
        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            throw new ApiException('QQ_RELAY_SERVICE_INVALID_RESPONSE', 'QQ 快捷登录申请服务返回了无法识别的数据', 502);
        }
        if ($status < 200 || $status >= 300 || empty($decoded['ok']) || !is_array($decoded['data'] ?? null)) {
            $message = trim((string)($decoded['message'] ?? ''));
            throw new ApiException(
                'QQ_RELAY_SERVICE_REJECTED',
                $message !== '' ? $message : 'QQ 快捷登录申请服务暂时不可用',
                502
            );
        }
        return $decoded['data'];
    }
}
