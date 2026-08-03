<?php

namespace app\sign\contract;

use app\sign\dto\AccountProfile;
use app\sign\dto\HealthResult;
use app\sign\dto\PluginMetadata;
use app\sign\dto\SignContext;
use app\sign\dto\SignResult;

interface SignPluginInterface
{
    public function metadata(): PluginMetadata;

    public function credentialRules(): array;

    public function validateAccount(array $credentials): AccountProfile;

    public function supportedActions(): array;

    public function execute(SignContext $context): SignResult;

    public function healthCheck(): HealthResult;
}
