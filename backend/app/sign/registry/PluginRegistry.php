<?php

namespace app\sign\registry;

use app\exception\ApiException;
use app\sign\contract\SignPluginInterface;
use plugin\bilibili\app\BilibiliPlugin;
use plugin\cloud189\app\Cloud189Plugin;
use plugin\iqiyi\app\IqiyiPlugin;
use plugin\netease\app\NeteasePlugin;
use plugin\picacomic\app\PicacomicPlugin;
use plugin\sport\app\SportPlugin;
use plugin\tieba\app\TiebaPlugin;

final class PluginRegistry
{
    /** @var array<string, SignPluginInterface>|null */
    private static ?array $plugins = null;

    public function all(): array
    {
        return array_values(self::plugins());
    }

    public function get(string $code): SignPluginInterface
    {
        $plugin = self::plugins()[$code] ?? null;
        if (!$plugin) {
            throw new ApiException('PLUGIN_NOT_FOUND', '签到插件不存在', 404);
        }
        return $plugin;
    }

    private static function plugins(): array
    {
        if (self::$plugins === null) {
            $instances = [
                new TiebaPlugin(),
                new BilibiliPlugin(),
                new NeteasePlugin(),
                new IqiyiPlugin(),
                new SportPlugin(),
                new Cloud189Plugin(),
                new PicacomicPlugin(),
            ];
            self::$plugins = [];
            foreach ($instances as $plugin) {
                self::$plugins[$plugin->metadata()->code] = $plugin;
            }
        }

        return self::$plugins;
    }
}
