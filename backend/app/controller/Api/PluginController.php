<?php

namespace app\controller\Api;

use app\http\ApiResponse;
use app\sign\registry\PluginRegistry;
use support\Response;

final class PluginController
{
    public function index(): Response
    {
        $plugins = array_map(static function ($plugin): array {
            $health = $plugin->healthCheck();
            return $plugin->metadata()->toArray() + [
                'actions' => $plugin->supportedActions(),
                'healthy' => $health->healthy,
                'health_message' => $health->message,
            ];
        }, (new PluginRegistry())->all());

        return ApiResponse::success($plugins);
    }

    public function show(string $pluginCode): Response
    {
        $plugin = (new PluginRegistry())->get($pluginCode);
        return ApiResponse::success($plugin->metadata()->toArray() + [
            'credential_rules' => $plugin->credentialRules(),
            'actions' => $plugin->supportedActions(),
        ]);
    }

    public function actions(string $pluginCode): Response
    {
        return ApiResponse::success((new PluginRegistry())->get($pluginCode)->supportedActions());
    }
}
