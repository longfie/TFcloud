<?php

namespace app\service;

use Throwable;

final class VersionService
{
    private const CACHE_TTL_SECONDS = 1800;
    private const DEFAULT_VERSION = '0.0.0';
    private const DEFAULT_VERSION_URL = 'https://raw.githubusercontent.com/longfie/TFcloud/main/backend/VERSION';
    private const DEFAULT_UPDATE_PAGE_URL = 'https://github.com/longfie/TFcloud/releases';
    private const REPOSITORY_URL = 'https://github.com/longfie/TFcloud';

    /**
     * @return array<string, mixed>
     */
    public function information(bool $forceRefresh = false): array
    {
        $currentVersion = $this->localVersion();
        $remote = $this->remoteVersion($forceRefresh);
        $latestVersion = $remote['version'];
        $status = $latestVersion === null
            ? 'unavailable'
            : $this->comparisonStatus($currentVersion, $latestVersion);

        return [
            'program' => [
                'name' => '天方云签',
                'description' => '基于 Webman 与 React 构建的模块化自动签到管理平台，支持多平台账号统一管理、定时任务、运行记录、消息通知和插件扩展。',
                'features' => [
                    '多平台账号与签到任务统一管理',
                    '敏感凭据加密存储，运行时按需解密',
                    '计划调度、失败重试、运行记录与通知',
                    '插件化平台接入，便于持续扩展',
                ],
            ],
            'author' => [
                'name' => '龙辉',
                'qq' => '1790716272',
                'qq_group' => '701550577',
                'blog_url' => 'https://blog.eirds.cn/',
            ],
            'copyright' => [
                'owner' => '龙辉',
                'start_year' => 2016,
                'website' => 'www.yunsign.net',
                'website_url' => 'https://www.yunsign.net',
                'notice' => '天方云签 · 龙辉 版权所有',
            ],
            'version' => [
                'current' => $currentVersion,
                'latest' => $latestVersion,
                'status' => $status,
                'update_available' => $status === 'update_available',
                'checked_at' => $remote['checked_at'],
                'error' => $remote['error'],
                'update_page_url' => $this->updatePageUrl(),
            ],
            'repository_url' => self::REPOSITORY_URL,
        ];
    }

    public function comparisonStatus(string $currentVersion, string $latestVersion): string
    {
        $comparison = version_compare($this->normalizeVersion($latestVersion), $this->normalizeVersion($currentVersion));
        if ($comparison > 0) {
            return 'update_available';
        }
        if ($comparison < 0) {
            return 'ahead';
        }
        return 'up_to_date';
    }

    private function localVersion(): string
    {
        $path = base_path() . '/VERSION';
        if (!is_readable($path)) {
            return self::DEFAULT_VERSION;
        }
        return $this->normalizeVersion((string)file_get_contents($path));
    }

    /**
     * @return array{version: ?string, checked_at: ?string, error: ?string}
     */
    private function remoteVersion(bool $forceRefresh): array
    {
        $cached = $this->readCache();
        if (!$forceRefresh && $cached !== null && (int)$cached['expires_at'] > time()) {
            return $cached['result'];
        }

        $checkedAt = gmdate('c');
        try {
            $version = $this->fetchRemoteVersion();
            $result = [
                'version' => $version,
                'checked_at' => $checkedAt,
                'error' => null,
            ];
            $this->writeCache($result);
            return $result;
        } catch (Throwable $exception) {
            // A stale successful result is more useful than losing all remote
            // version information during a temporary GitHub outage.
            if ($cached !== null && isset($cached['result']['version']) && $cached['result']['version'] !== null) {
                return [
                    'version' => (string)$cached['result']['version'],
                    'checked_at' => $checkedAt,
                    'error' => '远程检查失败，当前显示上次成功获取的版本。',
                ];
            }
            return [
                'version' => null,
                'checked_at' => $checkedAt,
                'error' => '暂时无法访问远程版本源，请稍后重试。',
            ];
        }
    }

    private function fetchRemoteVersion(): string
    {
        $handle = curl_init($this->versionUrl());
        if ($handle === false) {
            throw new \RuntimeException('unable to initialize version request');
        }
        $headers = [
            'Accept: text/plain, application/json',
            'User-Agent: tfsign-version-checker/' . $this->localVersion(),
        ];
        $githubToken = trim((string)(getenv('UPDATE_GITHUB_TOKEN') ?: ''));
        if ($githubToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $githubToken;
            $headers[] = 'X-GitHub-Api-Version: 2022-11-28';
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 7,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $raw = curl_exec($handle);
        $statusCode = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (!is_string($raw) || $statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException($error !== '' ? $error : 'remote version request failed');
        }
        if (strlen($raw) > 65_536) {
            throw new \RuntimeException('remote version response is too large');
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['content']) && ($decoded['encoding'] ?? '') === 'base64') {
            $decodedContent = base64_decode(str_replace(["\r", "\n"], '', (string)$decoded['content']), true);
            $candidate = is_string($decodedContent) ? trim(strtok($decodedContent, "\r\n") ?: '') : '';
        } else {
            $candidate = is_array($decoded)
                ? (string)($decoded['tag_name'] ?? $decoded['version'] ?? '')
                : trim(strtok($raw, "\r\n") ?: '');
        }
        if (!$this->isValidVersion($candidate)) {
            throw new \RuntimeException('remote version is invalid');
        }
        return $this->normalizeVersion($candidate);
    }

    private function versionUrl(): string
    {
        $configured = trim((string)(getenv('UPDATE_VERSION_URL') ?: ''));
        return $configured !== '' ? $configured : self::DEFAULT_VERSION_URL;
    }

    private function updatePageUrl(): string
    {
        $configured = trim((string)(getenv('UPDATE_PAGE_URL') ?: ''));
        if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_URL)) {
            $scheme = strtolower((string)parse_url($configured, PHP_URL_SCHEME));
            if (in_array($scheme, ['http', 'https'], true)) {
                return $configured;
            }
        }
        return self::DEFAULT_UPDATE_PAGE_URL;
    }

    private function normalizeVersion(string $version): string
    {
        $version = trim($version);
        if (str_starts_with(strtolower($version), 'v')) {
            $version = substr($version, 1);
        }
        return $this->isValidVersion($version) ? $version : self::DEFAULT_VERSION;
    }

    private function isValidVersion(string $version): bool
    {
        return preg_match('/^v?\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', trim($version)) === 1;
    }

    /**
     * @return array{expires_at: int, result: array{version: ?string, checked_at: ?string, error: ?string}}|null
     */
    private function readCache(): ?array
    {
        $path = $this->cachePath();
        if (!is_readable($path)) {
            return null;
        }
        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded) || !isset($decoded['expires_at']) || !is_array($decoded['result'] ?? null)) {
            return null;
        }
        return $decoded;
    }

    /**
     * @param array{version: ?string, checked_at: ?string, error: ?string} $result
     */
    private function writeCache(array $result): void
    {
        $path = $this->cachePath();
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(4));
        $content = json_encode([
            'expires_at' => time() + self::CACHE_TTL_SECONDS,
            'result' => $result,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($content === false || @file_put_contents($temporary, $content, LOCK_EX) === false) {
            return;
        }
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
        }
    }

    private function cachePath(): string
    {
        return base_path() . '/runtime/cache/version-check.json';
    }
}
