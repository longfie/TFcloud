<?php

namespace app\service;

use app\sign\registry\PluginRegistry;

final class PlatformCatalogService
{
    private const CONNECTIONS = [
        'tieba' => ['sms', 'qr', 'password', 'qq_qr', 'wechat_qr', 'bduss'],
        'bilibili' => ['qr', 'cookie'],
        'sport' => ['password'],
        'netease' => ['password', 'cookie'],
        'iqiyi' => ['qr', 'cookie'],
        'cloud189' => ['password'],
        'picacomic' => ['password'],
    ];

    private const FIELD_LABELS = [
        'bduss' => 'BDUSS', 'stoken' => 'STOKEN（可选）',
        'sessdata' => 'SESSDATA', 'bili_jct' => 'bili_jct',
        'dede_user_id' => 'DedeUserID', 'dede_user_id_ckmd5' => 'DedeUserID__ckMd5（可选）',
        'music_u' => 'MUSIC_U', 'csrf' => '__csrf',
        'p00001' => 'P00001', 'p00003' => 'P00003',
    ];

    public function list(): array
    {
        $rows = [];
        $registry = new PluginRegistry();
        foreach (array_keys(self::CONNECTIONS) as $code) {
            $platform = $registry->get($code);
            $metadata = $platform->metadata();
            if ($metadata->implementationStatus !== 'ready') {
                continue;
            }
            $rows[] = $this->present($platform, false);
        }
        return $rows;
    }

    public function get(string $code): array
    {
        $platform = (new PluginRegistry())->get($code);
        if ($platform->metadata()->implementationStatus !== 'ready' || !isset(self::CONNECTIONS[$code])) {
            throw new \app\exception\ApiException('PLATFORM_NOT_AVAILABLE', '该平台暂未开放接入', 404);
        }
        return $this->present($platform, true);
    }

    private function present(object $platform, bool $detail): array
    {
        $metadata = $platform->metadata();
        $result = [
            'code' => $metadata->code,
            'name' => $metadata->name,
            'description' => str_replace('插件', '', $metadata->description),
            'connection_methods' => self::CONNECTIONS[$metadata->code],
            'actions' => $platform->supportedActions(),
        ];
        if ($detail) {
            $result['credential_fields'] = array_map(
                static fn (string $field, array $rules): array => [
                    'key' => $field,
                    'label' => match ($metadata->code . ':' . $field) {
                        'sport:username' => 'Zepp Life 手机号或邮箱',
                        'sport:password' => 'Zepp Life 密码',
                        'cloud189:username' => '天翼云盘手机号或邮箱',
                        'cloud189:password' => '天翼云盘密码',
                        'picacomic:username' => '哔咔登录邮箱',
                        'picacomic:password' => '哔咔密码',
                        default => self::FIELD_LABELS[$field] ?? $field,
                    },
                    'required' => in_array('required', $rules, true),
                ],
                array_keys($platform->credentialRules()),
                array_values($platform->credentialRules())
            );
        }
        return $result;
    }
}
