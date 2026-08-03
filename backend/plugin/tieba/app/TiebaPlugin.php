<?php

/**
 * 百度贴吧签到插件
 *
 * 本插件来自「天方云签」，用于自动获取账号当前关注的全部贴吧并执行每日签到，
 * 支持签到明细记录、已签到识别、关注列表更新、后台完整重跑，以及仅针对
 * “第三方平台请求失败”的自动补签，避免重复执行当天已经成功的贴吧。
 *
 * 作者：龙辉
 * QQ：1790716272
 * 交流群：701550577
 * 博客：https://blog.eirds.cn/
 * 官网：https://www.yunsign.net
 */

namespace plugin\tieba\app;

use app\sign\contract\AbstractSignPlugin;
use app\sign\dto\AccountProfile;
use app\sign\dto\HealthResult;
use app\sign\dto\PluginMetadata;
use app\sign\dto\SignRecord;
use app\sign\dto\SignContext;
use app\sign\dto\SignResult;

final class TiebaPlugin extends AbstractSignPlugin
{
    public function metadata(): PluginMetadata
    {
        return new PluginMetadata(
            'tieba',
            '百度贴吧',
            '0.1.0',
            '来自天方云签（www.yunsign.net）：自动获取全部关注贴吧并签到，支持执行明细、完整重跑和第三方请求失败自动补签'
        );
    }

    public function credentialRules(): array
    {
        return [
            'bduss' => ['required', 'string'],
            'stoken' => ['nullable', 'string'],
        ];
    }

    public function validateAccount(array $credentials): AccountProfile
    {
        $this->requireCredentials($credentials, ['bduss']);
        return (new TiebaClient($credentials))->profile();
    }

    public function supportedActions(): array
    {
        return ['daily_sign'];
    }

    public function execute(SignContext $context): SignResult
    {
        if ($context->action !== 'daily_sign') {
            throw new \InvalidArgumentException('unsupported tieba action');
        }
        $this->requireCredentials($context->credentials, ['bduss']);
        $client = new TiebaClient($context->credentials);
        $toRecord = static fn (array $detail): SignRecord => new SignRecord(
            key: 'forum:' . $detail['fid'],
            action: 'forum_sign',
            status: $detail['status'],
            targetType: 'forum',
            targetId: (string)$detail['fid'],
            targetName: (string)$detail['name'],
            code: (string)$detail['code'],
            message: (string)$detail['message'],
            rewards: ['bonus_points' => (int)$detail['bonus_points']],
            metrics: ['sign_rank' => (int)$detail['sign_rank'], 'page_no' => (int)$detail['page_no']],
        );
        $profile = is_array($context->options['profile'] ?? null) ? $context->options['profile'] : [];
        $likedCache = is_array($profile['liked_forums'] ?? null) ? $profile['liked_forums'] : null;
        $retryForumIds = is_array($context->options['retry_forum_ids'] ?? null)
            ? $context->options['retry_forum_ids']
            : null;
        $forceForumRefresh = ($context->options['force_refresh_forums'] ?? false) === true;
        $details = $client->signAll(static function (array $detail) use ($context, $toRecord): void {
            $context->report($toRecord($detail));
        }, $likedCache, $retryForumIds, $forceForumRefresh);
        $records = array_map($toRecord, $details);
        $failed = count(array_filter($details, static fn (array $item): bool => $item['status'] === 'failed'));
        $succeeded = count(array_filter($details, static fn (array $item): bool => $item['status'] === 'succeeded'));
        $already = count(array_filter($details, static fn (array $item): bool => $item['status'] === 'already_done'));
        $requestFailedForumIds = array_values(array_map(
            static fn (array $item): string => (string)$item['fid'],
            array_filter(
                $details,
                static fn (array $item): bool => (string)($item['code'] ?? '') === 'UPSTREAM_REQUEST_FAILED'
            )
        ));
        $likedState = $client->likedForumsState();
        $profilePatch = null;
        if (is_array($likedState)) {
            $profilePatch = [
                'liked_forums' => [
                    'synced_at' => (string)$likedState['synced_at'],
                    'fingerprint' => (string)$likedState['fingerprint'],
                    'items' => $likedState['items'],
                ],
            ];
        }

        return new SignResult(
            $failed === 0 ? 'succeeded' : (($succeeded + $already) > 0 ? 'partial' : 'failed'),
            $failed === 0 ? '贴吧签到完成' : '部分贴吧签到失败',
            $records,
            [
                'total' => count($records),
                'succeeded' => $succeeded,
                'already_done' => $already,
                'failed' => $failed,
                'request_failed_forum_ids' => $requestFailedForumIds,
                'request_failed_date' => date('Y-m-d'),
                'forum_sync_mode' => (string)($likedState['sync_mode'] ?? 'unknown'),
                'forum_cache_size' => count($likedState['items'] ?? []),
            ],
            $profilePatch,
        );
    }

    public function healthCheck(): HealthResult
    {
        return extension_loaded('curl')
            ? new HealthResult(true)
            : new HealthResult(false, 'curl extension is missing');
    }
}
