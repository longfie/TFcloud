<?php

namespace app\service;

use app\exception\ApiException;
use PDO;
use Throwable;

final class InstallService
{
    private const LOCK_RELATIVE = 'runtime/install.lock';
    private const LEGACY_PASSWORD_SALT = 'qq1790716272';
    private const MEBIBYTE = 1024 * 1024;
    private const GIBIBYTE = 1024 * self::MEBIBYTE;
    private static bool $installedForProcess = false;

    public function lockPath(): string
    {
        return base_path() . '/' . self::LOCK_RELATIVE;
    }

    public function envPath(): string
    {
        $configured = trim((string)(getenv('INSTALL_ENV_PATH') ?: ''));
        if ($configured !== '' && str_starts_with($configured, '/')) {
            return $configured;
        }
        return base_path() . '/.env';
    }

    public function schemaPath(): string
    {
        return base_path() . '/database/schema.sql';
    }

    /**
     * Keep process sizing conservative: each effective CPU core needs roughly
     * 1 GiB of server memory before it contributes additional workers.
     *
     * Optional arguments make the sizing rule deterministic in tests while a
     * normal installation always detects the current server automatically.
     *
     * @return array{cpu_cores: int, memory_mb: int, webman: int, sign_worker: int}
     */
    public function recommendProcessCounts(?int $cpuCores = null, ?int $memoryBytes = null): array
    {
        $cpuCores = max(1, $cpuCores ?? $this->detectCpuCoreCount());
        $memoryBytes = max(self::MEBIBYTE, $memoryBytes ?? $this->detectMemoryBytes());

        // The 256 MiB tolerance maps the commonly reported ~3.8 GiB of a 4 GiB
        // server back to four capacity units without overcommitting small hosts.
        $memoryCapacity = max(1, intdiv($memoryBytes + (256 * self::MEBIBYTE), self::GIBIBYTE));
        $effectiveCapacity = min($cpuCores, $memoryCapacity, 16);

        return [
            'cpu_cores' => $cpuCores,
            'memory_mb' => max(1, intdiv($memoryBytes, self::MEBIBYTE)),
            'webman' => min(32, max(2, $effectiveCapacity * 2)),
            'sign_worker' => min(8, max(1, intdiv($effectiveCapacity + 1, 2))),
        ];
    }

    public function isInstalled(): bool
    {
        if (self::$installedForProcess) {
            return true;
        }
        if (is_file($this->lockPath())) {
            return self::$installedForProcess = true;
        }
        if ($this->looksLikeExistingDeployment()) {
            $this->writeLock([
                'source' => 'auto',
                'note' => 'Existing deployment detected; install lock created automatically.',
            ]);
            return self::$installedForProcess = true;
        }
        return false;
    }

    public function status(): array
    {
        $installed = $this->isInstalled();
        return [
            'installed' => $installed,
            'lock_exists' => is_file($this->lockPath()),
            'env_exists' => is_file($this->envPath()),
            'can_install' => !$installed,
            'restart_hint' => '安装完成后请重启 Webman 进程以使环境变量与后台任务生效。',
        ];
    }

    public function environmentChecks(): array
    {
        $phpRequired = '8.2.0';
        $extensions = ['curl', 'mbstring', 'openssl', 'pdo_mysql', 'sodium'];
        $checks = [];

        $checks[] = [
            'key' => 'php_version',
            'label' => 'PHP 版本',
            'ok' => version_compare(PHP_VERSION, $phpRequired, '>='),
            'detail' => '当前 ' . PHP_VERSION . '，需要 >= ' . $phpRequired . '（推荐 8.4）',
        ];

        foreach ($extensions as $extension) {
            $checks[] = [
                'key' => 'ext_' . $extension,
                'label' => '扩展 ' . $extension,
                'ok' => extension_loaded($extension),
                'detail' => extension_loaded($extension) ? '已加载' : '未加载',
            ];
        }

        $checks[] = [
            'key' => 'ext_pcntl',
            'label' => '扩展 pcntl（Webman 进程）',
            'ok' => extension_loaded('pcntl') || PHP_OS_FAMILY === 'Windows',
            'detail' => extension_loaded('pcntl')
                ? '已加载'
                : (PHP_OS_FAMILY === 'Windows' ? 'Windows 可跳过' : '未加载，Webman 常驻进程需要它'),
        ];

        $sortDirectionOk = enum_exists('SortDirection', true);
        $checks[] = [
            'key' => 'sort_direction',
            'label' => 'SortDirection',
            'ok' => $sortDirectionOk,
            'detail' => $sortDirectionOk
                ? '可用'
                : '缺失：请在 backend 目录执行 composer install，并确认已安装 symfony/polyfill-php86',
        ];

        $runtime = base_path() . '/runtime';
        $checks[] = [
            'key' => 'runtime_writable',
            'label' => 'runtime 目录可写',
            'ok' => $this->ensureWritableDirectory($runtime),
            'detail' => $runtime,
        ];

        $envDir = dirname($this->envPath());
        $checks[] = [
            'key' => 'env_writable',
            'label' => '.env 可写入',
            'ok' => is_writable($envDir) && (!is_file($this->envPath()) || is_writable($this->envPath())),
            'detail' => $this->envPath(),
        ];

        $checks[] = [
            'key' => 'schema_readable',
            'label' => '数据库结构文件',
            'ok' => is_readable($this->schemaPath()),
            'detail' => $this->schemaPath(),
        ];

        $passed = !in_array(false, array_column($checks, 'ok'), true);
        return [
            'passed' => $passed,
            'checks' => $checks,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, server_version?: string, message: string}
     */
    public function testDatabase(array $input): array
    {
        $config = $this->normalizeDatabaseInput($input);
        try {
            $pdo = $this->connect($config, false);
            $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
            $pdo = null;
            return [
                'ok' => true,
                'server_version' => $version,
                'message' => '数据库连接成功',
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'message' => '数据库连接失败：' . $this->safeError($exception),
            ];
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function install(array $input): array
    {
        if ($this->isInstalled()) {
            throw new ApiException('INSTALL_LOCKED', '系统已安装，如需重装请先删除 runtime/install.lock', 409);
        }

        $checks = $this->environmentChecks();
        if (!$checks['passed']) {
            throw new ApiException('INSTALL_ENV_FAILED', '环境检测未通过，请先修复依赖问题', 422, $checks);
        }

        $database = $this->normalizeDatabaseInput($input['database'] ?? []);
        $siteUrl = $this->normalizeSiteUrl((string)($input['site_url'] ?? ''));
        $sessionSecure = array_key_exists('session_secure', $input)
            ? (bool)$input['session_secure']
            : str_starts_with($siteUrl, 'https://');
        $admin = $this->normalizeAdminInput($input['admin'] ?? []);

        try {
            $pdo = $this->connect($database, true);
        } catch (Throwable $exception) {
            throw new ApiException('INSTALL_DB_FAILED', '无法连接或创建数据库：' . $this->safeError($exception), 422);
        }

        $this->assertDatabaseEmptyOrCompatible($pdo);

        try {
            $this->importSchema($pdo);
            $this->createAdministrator($pdo, $admin);
        } catch (ApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ApiException('INSTALL_SCHEMA_FAILED', '导入数据库结构失败：' . $this->safeError($exception), 500);
        }

        $appKey = 'base64:' . base64_encode(random_bytes(32));
        $fingerprintKey = bin2hex(random_bytes(32));
        $processCounts = $this->recommendProcessCounts();
        $envValues = [
            'APP_DEBUG' => 'false',
            'APP_KEY' => $appKey,
            'CREDENTIAL_KEY_VERSION' => '1',
            'CREDENTIAL_FINGERPRINT_KEY' => $fingerprintKey,
            'LEGACY_PASSWORD_SALT' => self::LEGACY_PASSWORD_SALT,
            'DB_HOST' => $database['host'],
            'DB_PORT' => (string)$database['port'],
            'DB_NAME' => $database['name'],
            'DB_USER' => $database['user'],
            'DB_PASSWORD' => $database['password'],
            'DB_SOCKET' => $database['socket'],
            'LEGACY_DB_DSN' => '',
            'LEGACY_DB_USER' => '',
            'LEGACY_DB_PASSWORD' => '',
            'CORS_ALLOWED_ORIGINS' => $siteUrl,
            'SESSION_SECURE' => $sessionSecure ? 'true' : 'false',
            'SIGN_WORKER_ENABLED' => 'true',
            'SIGN_WORKER_COUNT' => (string)$processCounts['sign_worker'],
            'TIEBA_RETRY_WORKER_ENABLED' => 'true',
            'TIEBA_RETRY_WORKER_COUNT' => '1',
            'SIGN_SCHEDULER_ENABLED' => 'true',
            'MAIL_WORKER_ENABLED' => 'true',
            'TASK_LOCK_TIMEOUT_SECONDS' => '7200',
            'MAINTENANCE_ENABLED' => 'true',
            'WEBMAN_WORKER_COUNT' => (string)$processCounts['webman'],
            'UPDATE_VERSION_URL' => 'https://raw.githubusercontent.com/longfie/TFcloud/main/backend/VERSION',
            'UPDATE_PAGE_URL' => 'https://github.com/longfie/TFcloud/releases',
            'UPDATE_GITHUB_TOKEN' => '',
        ];

        try {
            $this->writeEnvFile($envValues);
            foreach ($envValues as $name => $value) {
                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
            $this->writeLock([
                'source' => 'wizard',
                'site_url' => $siteUrl,
                'admin_username' => $admin['username'],
                'database' => $database['name'],
            ]);
        } catch (Throwable $exception) {
            throw new ApiException('INSTALL_WRITE_FAILED', '写入配置失败：' . $this->safeError($exception), 500);
        }

        return [
            'installed' => true,
            'admin_username' => $admin['username'],
            'site_url' => $siteUrl,
            'server_resources' => [
                'cpu_cores' => $processCounts['cpu_cores'],
                'memory_mb' => $processCounts['memory_mb'],
            ],
            'process_counts' => [
                'webman' => $processCounts['webman'],
                'sign_worker' => $processCounts['sign_worker'],
            ],
            'restart_required' => true,
            'message' => '安装完成。请重启后端进程后登录管理账号。',
        ];
    }

    private function detectCpuCoreCount(): int
    {
        $candidates = [];

        if (PHP_OS_FAMILY === 'Linux') {
            $cpuInfo = $this->readTextFile('/proc/cpuinfo');
            if ($cpuInfo !== null && preg_match_all('/^processor\s*:/m', $cpuInfo, $matches) > 0) {
                $candidates[] = count($matches[0]);
            }

            foreach (['/sys/fs/cgroup/cpuset.cpus.effective', '/sys/fs/cgroup/cpuset/cpuset.cpus'] as $path) {
                $cpuSetCount = $this->countCpuSet((string)($this->readTextFile($path) ?? ''));
                if ($cpuSetCount > 0) {
                    $candidates[] = $cpuSetCount;
                    break;
                }
            }

            $status = $this->readTextFile('/proc/self/status');
            if ($status !== null && preg_match('/^Cpus_allowed_list:\s*(.+)$/m', $status, $match)) {
                $cpuSetCount = $this->countCpuSet(trim($match[1]));
                if ($cpuSetCount > 0) {
                    $candidates[] = $cpuSetCount;
                }
            }

            foreach (['/sys/fs/cgroup/cpu.max', '/sys/fs/cgroup/cpu/cpu.cfs_quota_us'] as $path) {
                $quota = $this->readTextFile($path);
                if ($quota === null || trim($quota) === '' || str_starts_with(trim($quota), 'max')) {
                    continue;
                }
                if ($path === '/sys/fs/cgroup/cpu.max') {
                    $parts = preg_split('/\s+/', trim($quota));
                    $period = isset($parts[1]) ? (int)$parts[1] : 0;
                    $quotaValue = isset($parts[0]) ? (int)$parts[0] : 0;
                } else {
                    $period = (int)($this->readTextFile('/sys/fs/cgroup/cpu/cpu.cfs_period_us') ?? 0);
                    $quotaValue = (int)trim($quota);
                }
                if ($quotaValue > 0 && $period > 0) {
                    $candidates[] = max(1, (int)floor($quotaValue / $period));
                    break;
                }
            }
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $logicalCpus = $this->runStaticCommand('sysctl -n hw.logicalcpu');
            if ($logicalCpus !== null && (int)$logicalCpus > 0) {
                $candidates[] = (int)$logicalCpus;
            }
        }

        $windowsCpus = (int)(getenv('NUMBER_OF_PROCESSORS') ?: 0);
        if ($windowsCpus > 0) {
            $candidates[] = $windowsCpus;
        }

        if ($candidates === []) {
            $onlineCpus = $this->runStaticCommand('getconf _NPROCESSORS_ONLN');
            if ($onlineCpus !== null && (int)$onlineCpus > 0) {
                $candidates[] = (int)$onlineCpus;
            }
        }

        return $candidates === [] ? 1 : max(1, min($candidates));
    }

    private function detectMemoryBytes(): int
    {
        $candidates = [];

        if (PHP_OS_FAMILY === 'Linux') {
            $memoryInfo = $this->readTextFile('/proc/meminfo');
            if ($memoryInfo !== null && preg_match('/^MemTotal:\s*(\d+)\s+kB$/mi', $memoryInfo, $match)) {
                $candidates[] = (int)$match[1] * 1024;
            }

            foreach (['/sys/fs/cgroup/memory.max', '/sys/fs/cgroup/memory/memory.limit_in_bytes'] as $path) {
                $limit = trim((string)($this->readTextFile($path) ?? ''));
                if ($limit !== '' && $limit !== 'max' && ctype_digit($limit)) {
                    $bytes = (int)$limit;
                    // Some cgroup v1 installations expose an enormous sentinel
                    // value when no memory limit is configured.
                    if ($bytes > 0 && $bytes < 1_125_899_906_842_624) {
                        $candidates[] = $bytes;
                        break;
                    }
                }
            }
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $memory = $this->runStaticCommand('sysctl -n hw.memsize');
            if ($memory !== null && (int)$memory > 0) {
                $candidates[] = (int)$memory;
            }
        } elseif (PHP_OS_FAMILY === 'Windows') {
            $memory = $this->runStaticCommand('wmic computersystem get TotalPhysicalMemory /value');
            if ($memory !== null && preg_match('/TotalPhysicalMemory=(\d+)/i', $memory, $match)) {
                $candidates[] = (int)$match[1];
            }
        }

        // A failed probe deliberately falls back to 1 GiB and therefore the
        // smallest safe process recommendation.
        return $candidates === [] ? self::GIBIBYTE : max(self::MEBIBYTE, min($candidates));
    }

    private function countCpuSet(string $cpuSet): int
    {
        $count = 0;
        foreach (explode(',', trim($cpuSet)) as $part) {
            if (preg_match('/^(\d+)-(\d+)$/', trim($part), $match)) {
                $start = (int)$match[1];
                $end = (int)$match[2];
                if ($end >= $start) {
                    $count += $end - $start + 1;
                }
            } elseif (ctype_digit(trim($part))) {
                $count++;
            }
        }
        return $count;
    }

    private function readTextFile(string $path): ?string
    {
        if (!is_readable($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        return $content === false ? null : $content;
    }

    private function runStaticCommand(string $command): ?string
    {
        if (!function_exists('shell_exec')) {
            return null;
        }
        try {
            $output = @shell_exec($command . (PHP_OS_FAMILY === 'Windows' ? ' 2>NUL' : ' 2>/dev/null'));
        } catch (Throwable) {
            return null;
        }
        $output = is_string($output) ? trim($output) : '';
        return $output === '' ? null : $output;
    }

    private function looksLikeExistingDeployment(): bool
    {
        if (!is_file($this->envPath())) {
            return false;
        }
        $appKey = trim((string)(getenv('APP_KEY') ?: ''));
        if ($appKey === '' || str_contains($appKey, 'replace-with')) {
            return false;
        }
        try {
            $host = (string)(getenv('DB_HOST') ?: '127.0.0.1');
            $port = (int)(getenv('DB_PORT') ?: 3306);
            $name = (string)(getenv('DB_NAME') ?: '');
            $user = (string)(getenv('DB_USER') ?: '');
            $password = (string)(getenv('DB_PASSWORD') ?: '');
            $socket = (string)(getenv('DB_SOCKET') ?: '');
            if ($name === '' || $user === '') {
                return false;
            }
            $pdo = $this->connect([
                'host' => $host,
                'port' => $port,
                'name' => $name,
                'user' => $user,
                'password' => $password,
                'socket' => $socket,
            ], false);
            $exists = (int)$pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = " . $pdo->quote($name) . " AND table_name = 'TF_users'"
            )->fetchColumn();
            return $exists > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{host: string, port: int, name: string, user: string, password: string, socket: string}
     */
    private function normalizeDatabaseInput(array $input): array
    {
        $host = trim((string)($input['host'] ?? '127.0.0.1'));
        $port = (int)($input['port'] ?? 3306);
        $name = trim((string)($input['name'] ?? ''));
        $user = trim((string)($input['user'] ?? ''));
        $password = (string)($input['password'] ?? '');
        $socket = trim((string)($input['socket'] ?? ''));

        if ($name === '' || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new ApiException('VALIDATION_FAILED', '数据库名只能包含字母、数字和下划线', 422);
        }
        if ($user === '') {
            throw new ApiException('VALIDATION_FAILED', '请填写数据库用户名', 422);
        }
        if ($socket === '' && ($host === '' || $port < 1 || $port > 65535)) {
            throw new ApiException('VALIDATION_FAILED', '请填写正确的数据库主机和端口，或提供 Unix Socket', 422);
        }

        return [
            'host' => $host !== '' ? $host : '127.0.0.1',
            'port' => $port > 0 ? $port : 3306,
            'name' => $name,
            'user' => $user,
            'password' => $password,
            'socket' => $socket,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{username: string, password: string, display_name: string}
     */
    private function normalizeAdminInput(array $input): array
    {
        $username = trim((string)($input['username'] ?? ''));
        $password = (string)($input['password'] ?? '');
        $displayName = trim((string)($input['display_name'] ?? '系统管理员'));
        if ($username === '' || mb_strlen($username) > 64 || !preg_match('/^[A-Za-z0-9_\-\.]+$/', $username)) {
            throw new ApiException('VALIDATION_FAILED', '管理员用户名格式不正确', 422);
        }
        if (strlen($password) < 8) {
            throw new ApiException('VALIDATION_FAILED', '管理员密码至少 8 位', 422);
        }
        if ($displayName === '') {
            $displayName = '系统管理员';
        }
        return [
            'username' => $username,
            'password' => $password,
            'display_name' => mb_substr($displayName, 0, 191),
        ];
    }

    private function normalizeSiteUrl(string $siteUrl): string
    {
        $siteUrl = rtrim(trim($siteUrl), '/');
        if ($siteUrl === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $siteUrl)) {
            throw new ApiException('VALIDATION_FAILED', '站点地址需要以 http:// 或 https:// 开头', 422);
        }
        return $siteUrl;
    }

    /**
     * @param array{host: string, port: int, name: string, user: string, password: string, socket: string} $config
     */
    private function connect(array $config, bool $createDatabase): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
            $options[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = true;
        }

        if ($config['socket'] !== '') {
            $serverDsn = 'mysql:unix_socket=' . $config['socket'] . ';charset=utf8mb4';
            $dbDsn = 'mysql:unix_socket=' . $config['socket'] . ';dbname=' . $config['name'] . ';charset=utf8mb4';
        } else {
            $serverDsn = 'mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';charset=utf8mb4';
            $dbDsn = 'mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';dbname=' . $config['name'] . ';charset=utf8mb4';
        }

        if ($createDatabase) {
            $server = new PDO($serverDsn, $config['user'], $config['password'], $options);
            $server->exec(
                'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $config['name']) . '` '
                . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci'
            );
            $server = null;
        }

        return new PDO($dbDsn, $config['user'], $config['password'], $options);
    }

    private function assertDatabaseEmptyOrCompatible(PDO $pdo): void
    {
        $count = (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'TF\\_%'"
        )->fetchColumn();
        if ($count === 0) {
            return;
        }
        $users = (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'TF_users'"
        )->fetchColumn();
        if ($users > 0) {
            $userCount = (int)$pdo->query('SELECT COUNT(*) FROM TF_users')->fetchColumn();
            if ($userCount > 0) {
                throw new ApiException('INSTALL_DB_NOT_EMPTY', '目标数据库已有业务数据，请更换空库或先清理后再安装', 409);
            }
        }
    }

    private function importSchema(PDO $pdo): void
    {
        $sql = (string)file_get_contents($this->schemaPath());
        if (trim($sql) === '') {
            throw new ApiException('INSTALL_SCHEMA_MISSING', '找不到 database/schema.sql', 500);
        }
        $pdo->exec($sql);
    }

    /**
     * @param array{username: string, password: string, display_name: string} $admin
     */
    private function createAdministrator(PDO $pdo, array $admin): void
    {
        $now = date('Y-m-d H:i:s');
        $statement = $pdo->prepare(
            'INSERT INTO TF_users
             (username, password_hash, display_name, role, status, quota, password_migrated_at, created_at, updated_at)
             VALUES
             (:username, :password_hash, :display_name, :role, :status, 10, :password_migrated_at, :created_at, :updated_at)'
        );
        $statement->execute([
            'username' => $admin['username'],
            'password_hash' => password_hash($admin['password'], PASSWORD_DEFAULT),
            'display_name' => $admin['display_name'],
            'role' => 'admin',
            'status' => 'active',
            'password_migrated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string, string> $values
     */
    private function writeEnvFile(array $values): void
    {
        $lines = [];
        foreach ($values as $name => $value) {
            $lines[] = $name . '=' . $this->quoteEnv($value);
        }
        $content = implode("\n", $lines) . "\n";
        $this->writePrivateFile($this->envPath(), $content);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function writeLock(array $extra = []): void
    {
        $payload = array_merge([
            'installed_at' => gmdate('c'),
            'app' => 'tfsign',
        ], $extra);
        $directory = dirname($this->lockPath());
        if (!$this->ensureWritableDirectory($directory)) {
            throw new \RuntimeException('runtime directory is not writable');
        }
        $this->writePrivateFile(
            $this->lockPath(),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n"
        );
        self::$installedForProcess = true;
    }

    private function writePrivateFile(string $target, string $content): void
    {
        $temporary = $target . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $content, LOCK_EX) === false) {
            throw new \RuntimeException('unable to write temporary file');
        }
        chmod($temporary, 0600);
        if (!rename($temporary, $target)) {
            @unlink($temporary);
            throw new \RuntimeException('unable to install target file');
        }
        chmod($target, 0600);
    }

    private function quoteEnv(string $value): string
    {
        return '"' . str_replace(
            ["\\", '"', "\n", "\r"],
            ["\\\\", '\\"', '\\n', '\\r'],
            $value
        ) . '"';
    }

    private function ensureWritableDirectory(string $path): bool
    {
        if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
            return false;
        }
        return is_writable($path);
    }

    private function safeError(Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        if ($message === '') {
            return $exception::class;
        }
        return mb_substr($message, 0, 300);
    }
}
