<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Env.php';
require dirname(__DIR__) . '/app/Database.php';

use Hendy\Database;
use Hendy\Env;

Env::load(dirname(__DIR__) . '/.env');

/**
 * Classify connection failures without printing the URL or raw PDO message.
 */
function databaseFailureHint(?Throwable $error): string
{
    for ($current = $error; $current !== null; $current = $current->getPrevious()) {
        $message = strtolower($current->getMessage());
        if (
            str_contains($message, 'could not translate host name') ||
            str_contains($message, 'getaddrinfo') ||
            str_contains($message, 'name or service not known')
        ) {
            return 'Lỗi phân giải hostname: kiểm tra đúng Internal URL và cùng region.';
        }
        if (
            str_contains($message, 'ssl') ||
            str_contains($message, 'tls') ||
            str_contains($message, 'certificate')
        ) {
            return 'Lỗi SSL/TLS: Internal URL dùng DATABASE_SSL=false; External URL cần DATABASE_SSL=true và sslmode=require.';
        }
        if (
            str_contains($message, 'timed out') ||
            str_contains($message, 'could not connect') ||
            str_contains($message, 'connection refused') ||
            str_contains($message, 'network is unreachable')
        ) {
            return 'Không tới được PostgreSQL: kiểm tra URL, region và trạng thái database.';
        }
    }

    return 'Không xác định được nguyên nhân: kiểm tra DATABASE_URL trong Render Environment.';
}

$timeout = max(0, min(300, Env::int('DB_STARTUP_WAIT_SECONDS', 120)));
$deadline = microtime(true) + $timeout;
$attempt = 0;
$lastError = null;
$nextNotice = 0.0;

do {
    $attempt++;
    try {
        Database::check();
        fwrite(STDOUT, "PostgreSQL đã sẵn sàng sau {$attempt} lần thử.\n");
        exit(0);
    } catch (Throwable $error) {
        $lastError = $error;
    }

    $now = microtime(true);
    if ($now >= $deadline) {
        break;
    }

    if ($now >= $nextNotice) {
        fwrite(STDOUT, "Đang chờ PostgreSQL... (lần thử {$attempt}/, tối đa {$timeout}s)\n");
        $nextNotice = $now + 10;
    }

    sleep(2);
} while (true);

$errorType = $lastError !== null ? $lastError::class : 'UnknownError';
$hint = databaseFailureHint($lastError);
fwrite(
    STDERR,
    "Không thể kết nối PostgreSQL sau {$timeout}s ({$errorType}). {$hint} " .
    "Không ghi connection string hoặc thông tin bí mật vào log.\n"
);
exit(1);
