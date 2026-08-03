<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$target = $root . '/.env';
$force = in_array('--force', $argv, true);
$finalize = in_array('--finalize', $argv, true);

if ($finalize) {
    if (!is_file($target)) {
        fwrite(STDERR, ".env does not exist.\n");
        exit(2);
    }
    $content = (string)file_get_contents($target);
    foreach (['LEGACY_DB_DSN', 'LEGACY_DB_USER', 'LEGACY_DB_PASSWORD'] as $name) {
        $content = (string)preg_replace('/^' . preg_quote($name, '/') . '=.*$/m', $name . '=""', $content);
    }
    writePrivateFile($target, $content);
    fwrite(STDOUT, "Legacy database connection removed from .env.\n");
    exit(0);
}

if (is_file($target) && !$force) {
    fwrite(STDERR, ".env already exists; pass --force to replace it.\n");
    exit(2);
}

$legacyCandidates = [
    dirname($root) . '/TFcore/common.php',
    dirname($root) . '/Mobile/core.php',
];
$legacySalt = null;
foreach ($legacyCandidates as $candidate) {
    if (!is_file($candidate)) {
        continue;
    }
    $tokens = token_get_all((string) file_get_contents($candidate));
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        $constant = decodePhpString($tokens[$i][1]);
        if ($constant !== 'TF_JW') {
            continue;
        }
        for ($j = $i + 1; $j < min($i + 12, $count); $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                $legacySalt = decodePhpString($tokens[$j][1]);
                break 2;
            }
        }
    }
}

if (!is_string($legacySalt) || $legacySalt === '') {
    fwrite(STDERR, "Unable to locate the legacy TF_JW password salt.\n");
    exit(3);
}

$values = [
    'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:' . base64_encode(random_bytes(32)),
    'CREDENTIAL_KEY_VERSION' => '1',
    'CREDENTIAL_FINGERPRINT_KEY' => bin2hex(random_bytes(32)),
    'LEGACY_PASSWORD_SALT' => $legacySalt,
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_NAME' => 'tf_sign',
    'DB_USER' => 'Ankang',
    'DB_PASSWORD' => '',
    'DB_SOCKET' => '/tmp/mysql.sock',
    'DB_COLLATION' => 'utf8mb4_unicode_ci',
    'LEGACY_DB_DSN' => 'mysql:unix_socket=/tmp/mysql.sock;dbname=tf_legacy_dump_20260721;charset=utf8mb4',
    'LEGACY_DB_USER' => 'Ankang',
    'LEGACY_DB_PASSWORD' => '',
    'CORS_ALLOWED_ORIGINS' => '',
    'SESSION_SECURE' => 'true',
    'SIGN_WORKER_ENABLED' => 'false',
    'SIGN_WORKER_COUNT' => '1',
    'TIEBA_RETRY_WORKER_ENABLED' => 'false',
    'TIEBA_RETRY_WORKER_COUNT' => '1',
    'SIGN_SCHEDULER_ENABLED' => 'false',
    'TASK_LOCK_TIMEOUT_SECONDS' => '7200',
    'MAINTENANCE_ENABLED' => 'false',
    'WEBMAN_WORKER_COUNT' => '2',
];

$lines = [];
foreach ($values as $name => $value) {
    $lines[] = $name . '=' . quoteEnv($value);
}

writePrivateFile($target, implode("\n", $lines) . "\n");

fwrite(STDOUT, ".env created with generated encryption keys and detected legacy salt.\n");

function decodePhpString(string $literal): string
{
    $quote = $literal[0] ?? '';
    $inner = substr($literal, 1, -1);
    return $quote === "'"
        ? str_replace(["\\\\", "\\'"], ["\\", "'"], $inner)
        : stripcslashes($inner);
}

function quoteEnv(string $value): string
{
    return '"' . str_replace(
        ["\\", '"', "\n", "\r"],
        ["\\\\", '\\"', '\\n', '\\r'],
        $value
    ) . '"';
}

function writePrivateFile(string $target, string $content): void
{
    $temporary = $target . '.tmp.' . bin2hex(random_bytes(6));
    if (file_put_contents($temporary, $content, LOCK_EX) === false) {
        fwrite(STDERR, "Unable to write temporary environment file.\n");
        exit(4);
    }
    chmod($temporary, 0600);
    if (!rename($temporary, $target)) {
        @unlink($temporary);
        fwrite(STDERR, "Unable to install environment file.\n");
        exit(5);
    }
    chmod($target, 0600);
}
