<?php

declare(strict_types=1);

use Dotenv\Dotenv;
require dirname(__DIR__) . '/vendor/autoload.php';
Dotenv::createUnsafeImmutable(dirname(__DIR__))->safeLoad();

$options = getopt('', ['username:']);
$username = trim((string)($options['username'] ?? ''));
if ($username === '' || mb_strlen($username) > 64) {
    fwrite(STDERR, "Usage: ADMIN_PASSWORD='strong-password' php scripts/create-admin.php --username=admin\n");
    exit(2);
}

$socket = (string)(getenv('DB_SOCKET') ?: '');
$dsn = $socket !== ''
    ? 'mysql:unix_socket=' . $socket . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4'
    : 'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4';
$pdo = new PDO($dsn, (string)getenv('DB_USER'), (string)getenv('DB_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
]);
$password = (string)(getenv('ADMIN_PASSWORD') ?: '');
$find = $pdo->prepare('SELECT id FROM TF_users WHERE username=:username LIMIT 1');
$find->execute(['username' => $username]);
$existing = $find->fetch();
$now = date('Y-m-d H:i:s');

if ($existing) {
    $updates = ['role' => 'admin', 'status' => 'active', 'updated_at' => $now];
    if ($password !== '') {
        if (strlen($password) < 8) {
            fwrite(STDERR, "ADMIN_PASSWORD must contain at least 8 characters.\n");
            exit(3);
        }
        $updates['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        $updates['password_migrated_at'] = $now;
    }
    $sets = implode(', ', array_map(static fn (string $field): string => "`{$field}`=:{$field}", array_keys($updates)));
    $statement = $pdo->prepare("UPDATE TF_users SET {$sets} WHERE id=:id");
    $statement->execute($updates + ['id' => (int)$existing->id]);
    fwrite(STDOUT, "Existing user promoted to active administrator.\n");
    exit(0);
}

if (strlen($password) < 8) {
    fwrite(STDERR, "ADMIN_PASSWORD with at least 8 characters is required when creating an administrator.\n");
    exit(3);
}
$statement = $pdo->prepare(
    'INSERT INTO TF_users
     (username,password_hash,display_name,role,status,quota,password_migrated_at,created_at,updated_at)
     VALUES (:username,:password_hash,:display_name,:role,:status,10,:password_migrated_at,:created_at,:updated_at)'
);
$statement->execute([
    'username' => $username,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'display_name' => '系统管理员',
    'role' => 'admin',
    'status' => 'active',
    'password_migrated_at' => $now,
    'created_at' => $now,
    'updated_at' => $now,
]);
fwrite(STDOUT, "Administrator created.\n");
