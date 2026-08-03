<?php

namespace app\sign\contract;

interface CredentialRefreshAwareInterface
{
    /**
     * Returns the complete credential payload when the plugin refreshed
     * short-lived upstream tokens during account validation.
     */
    public function refreshedCredentials(): ?array;
}
