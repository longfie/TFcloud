<?php

namespace app\sign\contract;

use app\exception\ApiException;
use app\sign\dto\HealthResult;

abstract class AbstractSignPlugin implements SignPluginInterface
{
    public function healthCheck(): HealthResult
    {
        return new HealthResult(true);
    }

    protected function requireCredentials(array $credentials, array $requiredKeys): void
    {
        $missing = [];
        foreach ($requiredKeys as $key) {
            if (!isset($credentials[$key]) || trim((string)$credentials[$key]) === '') {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw new ApiException(
                'PLUGIN_CREDENTIAL_INVALID',
                '缺少平台凭据：' . implode(', ', $missing),
                422
            );
        }
    }

    protected function unavailableUntilMigrated(): never
    {
        throw new ApiException(
            'PLUGIN_ACTION_NOT_IMPLEMENTED',
            '插件动作正在迁移，暂不可执行',
            503
        );
    }
}
