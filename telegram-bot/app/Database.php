<?php
declare(strict_types=1);

namespace Hendy;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $url = Env::get('DATABASE_URL');
        if ($url === null) {
            throw new RuntimeException('Thiếu DATABASE_URL. Hãy cấu hình PostgreSQL trong môi trường chạy.');
        }

        $parts = parse_url($url);
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['postgres', 'postgresql'], true)
            || empty($parts['host'])
            || empty($parts['path'])) {
            throw new RuntimeException('DATABASE_URL không đúng định dạng PostgreSQL URL.');
        }

        $host = (string) $parts['host'];
        $port = (int) ($parts['port'] ?? 5432);
        $database = rawurldecode(ltrim((string) $parts['path'], '/'));
        $username = rawurldecode((string) ($parts['user'] ?? ''));
        $password = rawurldecode((string) ($parts['pass'] ?? ''));

        if ($port < 1 || $port > 65535
            || !preg_match('/\A[A-Za-z0-9.-]+\z/D', $host)
            || !preg_match('/\A[A-Za-z0-9_.-]+\z/D', $database)
            || $username === '') {
            throw new RuntimeException('Thông tin host, port hoặc database trong DATABASE_URL không hợp lệ.');
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        $sslMode = Env::bool('DATABASE_SSL', true)
            ? (string) ($query['sslmode'] ?? 'require')
            : 'disable';
        $allowedSslModes = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];
        if (!in_array($sslMode, $allowedSslModes, true)) {
            $sslMode = 'require';
        }

        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $host, $port, $database, $sslMode);

        try {
            self::$connection = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 10,
                PDO::ATTR_PERSISTENT => false,
            ]);
            self::$connection->exec("SET TIME ZONE 'UTC'");
        } catch (PDOException $error) {
            throw new RuntimeException('Không thể kết nối PostgreSQL. Hãy kiểm tra DATABASE_URL và quyền truy cập.', 0, $error);
        }

        return self::$connection;
    }

    /**
     * Safe identity of the runtime PostgreSQL connection for Admin diagnostics.
     * This deliberately avoids reading the migration ledger because the runtime
     * role is DML-only and must not need owner privileges just to view status.
     */
    public static function connectionInfo(): array
    {
        $url = Env::get('DATABASE_URL');
        if ($url === null) {
            throw new RuntimeException('Thiếu DATABASE_URL.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
            throw new RuntimeException('DATABASE_URL không đúng định dạng PostgreSQL URL.');
        }

        $databaseName = (string) self::value('SELECT current_database()');
        $roleName = (string) self::value('SELECT current_user');
        if ($databaseName === '' || $roleName === '') {
            throw new RuntimeException('Không xác định được database hoặc role PostgreSQL hiện tại.');
        }

        $host = strtolower((string) $parts['host']);
        return [
            'database' => $databaseName,
            'role' => $roleName,
            'pooled' => (bool) preg_match('/-pooler(?:\.|$)/i', $host),
        ];
    }

    public static function run(string $sql, array $parameters = []): PDOStatement
    {
        $statement = self::connection()->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }

    public static function one(string $sql, array $parameters = []): ?array
    {
        $row = self::run($sql, $parameters)->fetch();
        return is_array($row) ? $row : null;
    }

    public static function all(string $sql, array $parameters = []): array
    {
        return self::run($sql, $parameters)->fetchAll();
    }

    public static function value(string $sql, array $parameters = []): mixed
    {
        $value = self::run($sql, $parameters)->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Read-only deployment/database diagnostics. Never returns DATABASE_URL,
     * username, password, query parameters or secret values.
     */
    public static function migrationPlan(): array
    {
        $url = Env::get('DATABASE_URL');
        if ($url === null) {
            throw new RuntimeException('Thiếu DATABASE_URL.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
            throw new RuntimeException('DATABASE_URL không đúng định dạng PostgreSQL URL.');
        }

        $pdo = self::connection();
        $databaseName = (string) self::value('SELECT current_database()');
        $roleName = (string) self::value('SELECT current_user');
        $baseTables = ['users', 'products', 'orders', 'app_settings'];
        $presentBaseTables = [];
        foreach ($baseTables as $table) {
            $exists = self::value(
                "SELECT to_regclass('public.' || ?) IS NOT NULL",
                [$table]
            );
            if (in_array($exists, [true, 1, '1', 't', 'true'], true)) {
                $presentBaseTables[] = $table;
            }
        }

        $trackingTableExists = in_array(self::value(
            "SELECT EXISTS (
                SELECT 1 FROM information_schema.tables
                WHERE table_schema = 'public' AND table_name = 'schema_migrations'
            )"
        ), [true, 1, '1', 't', 'true'], true);

        $applied = [];
        if ($trackingTableExists) {
            foreach (self::all('SELECT version, checksum, applied_at FROM public.schema_migrations ORDER BY version') as $row) {
                $applied[(string) $row['version']] = [
                    'checksum' => (string) $row['checksum'],
                    'appliedAt' => $row['applied_at'],
                ];
            }
        }

        $directory = dirname(__DIR__) . '/database/migrations';
        $files = glob($directory . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $migrations = [];
        foreach ($files as $file) {
            $version = basename($file);
            $checksum = hash_file('sha256', $file);
            if (!is_string($checksum)) {
                throw new RuntimeException('Không thể tính checksum migration: ' . $version);
            }
            $existing = $applied[$version] ?? null;
            $status = $existing === null
                ? 'pending'
                : (hash_equals($existing['checksum'], $checksum) ? 'applied' : 'checksum_mismatch');
            $migrations[] = [
                'version' => $version,
                'checksum' => $checksum,
                'status' => $status,
                'appliedAt' => $existing['appliedAt'] ?? null,
            ];
        }

        $host = strtolower((string) $parts['host']);
        return [
            'database' => $databaseName,
            'role' => $roleName,
            'host' => (string) $parts['host'],
            'pooled' => (bool) preg_match('/-pooler(?:\\.|$)/i', $host),
            'baseSchema' => [
                'requiredTables' => $baseTables,
                'presentTables' => $presentBaseTables,
                'ready' => count($presentBaseTables) === count($baseTables),
                'empty' => $presentBaseTables === [],
            ],
            'trackingTableReady' => $trackingTableExists,
            'migrations' => $migrations,
            'pendingCount' => count(array_filter($migrations, static fn(array $item): bool => $item['status'] === 'pending')),
            'checksumMismatchCount' => count(array_filter($migrations, static fn(array $item): bool => $item['status'] === 'checksum_mismatch')),
        ];
    }

    /**
     * Apply the base schema (only for an empty database) and each additive SQL
     * migration once. An explicit expected database name is mandatory.
     * The caller must use a direct Neon URL and a database-owner/migration role.
     *
     * @return list<string> Versions applied by this invocation.
     */
    public static function migrate(string $expectedDatabase): array
    {
        $expectedDatabase = trim($expectedDatabase);
        if ($expectedDatabase === '' || !preg_match('/\\A[A-Za-z0-9_.-]+\\z/D', $expectedDatabase)) {
            throw new RuntimeException('Phải cung cấp tên database kỳ vọng hợp lệ.');
        }

        $plan = self::migrationPlan();
        if (!hash_equals($expectedDatabase, (string) $plan['database'])) {
            throw new RuntimeException(
                'Sai database đích: kết nối hiện tại trỏ tới "' . $plan['database'] .
                '", khác database đã xác nhận "' . $expectedDatabase . '". Không có thay đổi nào được thực hiện.'
            );
        }
        if (($plan['pooled'] ?? false) === true) {
            throw new RuntimeException('Không chạy migration qua hostname Neon có hậu tố -pooler. Hãy dùng direct/unpooled migration URL.');
        }
        if (strcasecmp((string) $plan['role'], 'hendy_bot_app') === 0) {
            throw new RuntimeException('Role hendy_bot_app chỉ dành cho runtime DML. Hãy dùng database-owner/migration role.');
        }
        if ((int) $plan['checksumMismatchCount'] > 0) {
            throw new RuntimeException('Một migration đã áp dụng bị thay đổi checksum. Hãy khôi phục đúng file lịch sử hoặc tạo migration mới; không tự sửa migration cũ.');
        }

        $schemaFile = dirname(__DIR__) . '/database/schema.sql';
        if (!is_file($schemaFile) || !is_readable($schemaFile)) {
            throw new RuntimeException('Không tìm thấy database/schema.sql.');
        }

        $pdo = self::connection();
        $lockKey = 'hendy-database-migrations-v1';
        self::run('SELECT pg_advisory_lock(hashtext(?))', [$lockKey]);
        $appliedNow = [];
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS public.schema_migrations (' .
                'version VARCHAR(255) PRIMARY KEY, ' .
                'checksum CHAR(64) NOT NULL, ' .
                'applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW())'
            );

            // Re-read state after acquiring the advisory lock. Another migration
            // may have finished while this process waited; do not use stale state.
            $plan = self::migrationPlan();
            if (!hash_equals($expectedDatabase, (string) $plan['database'])) {
                throw new RuntimeException('Database đích thay đổi trong lúc chờ khóa migration; đã dừng an toàn.');
            }
            if (($plan['pooled'] ?? false) === true
                || strcasecmp((string) $plan['role'], 'hendy_bot_app') === 0
                || (int) $plan['checksumMismatchCount'] > 0) {
                throw new RuntimeException('Kết nối hoặc trạng thái migration không còn an toàn sau khi lấy khóa; đã dừng.');
            }

            $baseReady = (bool) $plan['baseSchema']['ready'];
            $baseEmpty = (bool) $plan['baseSchema']['empty'];
            if (!$baseReady && !$baseEmpty) {
                throw new RuntimeException(
                    'Base schema đang ở trạng thái dở dang (chỉ có một phần bảng lõi). ' .
                    'Hãy rà soát database trước khi tiếp tục; runner không tự bootstrap đè lên.'
                );
            }

            if (!$baseReady && $baseEmpty) {
                $schemaSql = file_get_contents($schemaFile);
                if (!is_string($schemaSql) || trim($schemaSql) === '') {
                    throw new RuntimeException('database/schema.sql rỗng hoặc không đọc được.');
                }
                $pdo->beginTransaction();
                try {
                    $pdo->exec($schemaSql);
                    $pdo->commit();
                    $appliedNow[] = 'schema.sql (base schema)';
                } catch (Throwable $error) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $error;
                }
            }

            $directory = dirname(__DIR__) . '/database/migrations';
            $files = glob($directory . '/*.sql') ?: [];
            sort($files, SORT_STRING);
            foreach ($files as $file) {
                $version = basename($file);
                $checksum = hash_file('sha256', $file);
                if (!is_string($checksum)) {
                    throw new RuntimeException('Không thể tính checksum migration: ' . $version);
                }

                $existing = self::one(
                    'SELECT checksum FROM public.schema_migrations WHERE version = ?',
                    [$version]
                );
                if ($existing !== null) {
                    if (!hash_equals((string) $existing['checksum'], $checksum)) {
                        throw new RuntimeException('Checksum migration không khớp: ' . $version . '. Hãy tạo migration mới thay vì sửa file cũ.');
                    }
                    continue;
                }

                $sql = file_get_contents($file);
                if (!is_string($sql) || trim($sql) === '') {
                    throw new RuntimeException('Migration rỗng hoặc không đọc được: ' . $version);
                }
                $pdo->beginTransaction();
                try {
                    $pdo->exec($sql);
                    $insert = $pdo->prepare(
                        'INSERT INTO public.schema_migrations (version, checksum) VALUES (?, ?)'
                    );
                    $insert->execute([$version, $checksum]);
                    $pdo->commit();
                    $appliedNow[] = $version;
                } catch (Throwable $error) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw new RuntimeException('Migration thất bại tại ' . $version . '. Đã rollback migration này.', 0, $error);
                }
            }
        } finally {
            self::run('SELECT pg_advisory_unlock(hashtext(?))', [$lockKey]);
        }

        return $appliedNow;
    }

    public static function check(): bool
    {
        return (int) self::value('SELECT 1') === 1;
    }

    public static function close(): void
    {
        self::$connection = null;
    }
}
