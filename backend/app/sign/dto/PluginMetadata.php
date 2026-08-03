<?php

namespace app\sign\dto;

final readonly class PluginMetadata
{
    public function __construct(
        public string $code,
        public string $name,
        public string $version,
        public string $description,
        public array $credentialTypes = ['cookie'],
        public string $implementationStatus = 'ready',
    ) {
    }

    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'version' => $this->version,
            'description' => $this->description,
            'credential_types' => $this->credentialTypes,
            'implementation_status' => $this->implementationStatus,
        ];
    }
}
