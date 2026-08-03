<?php

namespace app\sign\credential;

use app\exception\ApiException;

final class CredentialVault
{
    public function encrypt(array $payload, string $associatedData): array
    {
        $key = $this->encryptionKey();
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($json, $associatedData, $nonce, $key);

        return [
            'payload_cipher' => $cipher,
            'nonce' => $nonce,
            'key_version' => (int)(getenv('CREDENTIAL_KEY_VERSION') ?: 1),
            'fingerprint' => $this->fingerprint($payload),
        ];
    }

    public function decrypt(string $cipher, string $nonce, string $associatedData): array
    {
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $cipher,
            $associatedData,
            $nonce,
            $this->encryptionKey()
        );

        if ($plain === false) {
            throw new ApiException('CREDENTIAL_DECRYPT_FAILED', '平台凭据无法解密', 500);
        }

        $decoded = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new ApiException('CREDENTIAL_FORMAT_INVALID', '平台凭据格式无效', 500);
        }
        return $decoded;
    }

    public function fingerprint(array $payload): string
    {
        $key = (string)(getenv('CREDENTIAL_FINGERPRINT_KEY') ?: '');
        if ($key === '') {
            throw new ApiException('CREDENTIAL_KEY_MISSING', '凭据指纹密钥尚未配置', 500);
        }

        $normalized = $this->sortRecursive($payload);
        return hash_hmac(
            'sha256',
            json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $key
        );
    }

    public static function associatedData(string $pluginCode, int $accountId, string $type): string
    {
        return $pluginCode . ':' . $accountId . ':' . $type;
    }

    private function encryptionKey(): string
    {
        $configured = (string)(getenv('APP_KEY') ?: '');
        if (str_starts_with($configured, 'base64:')) {
            $configured = (string)base64_decode(substr($configured, 7), true);
        }

        if (strlen($configured) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new ApiException('APP_KEY_INVALID', 'APP_KEY 必须是32字节密钥', 500);
        }
        return $configured;
    }

    private function sortRecursive(array $value): array
    {
        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->sortRecursive($item);
            }
        }
        unset($item);
        return $value;
    }
}
