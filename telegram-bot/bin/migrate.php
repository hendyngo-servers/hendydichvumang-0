<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Env.php';
require dirname(__DIR__) . '/app/Database.php';

use Hendy\Database;
use Hendy\Env;

Env::load(dirname(__DIR__) . '/.env');

// A one-off owner/direct URL can be supplied to the current Shell process only.
// It is never printed, persisted or written to repository files.
$migrationUrl = Env::get('MIGRATION_DATABASE_URL');
if ($migrationUrl !== null) {
    putenv('DATABASE_URL=' . $migrationUrl);
    $_ENV['DATABASE_URL'] = $migrationUrl;
    $_SERVER['DATABASE_URL'] = $migrationUrl;
}

$apply = in_array('--apply', $argv, true);
$expectedDatabase = null;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--expect-database=')) {
        $expectedDatabase = trim(substr($argument, strlen('--expect-database=')));
        break;
    }
}

try {
    $plan = Database::migrationPlan();
    fwrite(STDOUT, "HENDY PostgreSQL migration plan\n");
    fwrite(STDOUT, "Database: {$plan['database']}\n");
    fwrite(STDOUT, "Role: {$plan['role']}\n");
    fwrite(STDOUT, "Host: {$plan['host']}\n");
    fwrite(STDOUT, "Connection: " . ($plan['pooled'] ? 'pooled (không dùng cho migration)' : 'direct/unpooled') . "\n");
    fwrite(STDOUT, "Base schema: " . ($plan['baseSchema']['ready'] ? 'ready' : ($plan['baseSchema']['empty'] ? 'empty' : 'partial / needs review')) . "\n");
    fwrite(STDOUT, "Migration tracking table: " . ($plan['trackingTableReady'] ? 'present' : 'not created yet') . "\n");

    foreach ($plan['migrations'] as $migration) {
        $status = match ($migration['status']) {
            'applied' => 'APPLIED',
            'checksum_mismatch' => 'CHECKSUM MISMATCH',
            default => 'PENDING',
        };
        fwrite(STDOUT, " - {$status} {$migration['version']}\n");
    }

    if (!$apply) {
        fwrite(STDOUT, "\nDRY RUN: không có dữ liệu hoặc schema nào bị thay đổi.\n");
        fwrite(STDOUT, "Để áp dụng, chạy lại với --apply --expect-database=<đúng_tên_database>.\n");
        fwrite(STDOUT, "Dùng MIGRATION_DATABASE_URL cho direct URL + database-owner role; không dùng URL có -pooler.\n");
        exit(0);
    }

    if ($expectedDatabase === null || $expectedDatabase === '') {
        throw new RuntimeException('Thiếu --expect-database. Migration phải khớp chính xác tên database đã xác nhận.');
    }

    $applied = Database::migrate($expectedDatabase);
    if ($applied === []) {
        fwrite(STDOUT, "Không có migration mới. Schema hiện tại đã đồng bộ với các file migration.\n");
    } else {
        fwrite(STDOUT, "Đã áp dụng " . count($applied) . " mục:\n- " . implode("\n- ", $applied) . "\n");
    }

    $after = Database::migrationPlan();
    fwrite(STDOUT, "Xác minh sau migration: database={$after['database']}, pending={$after['pendingCount']}, checksum_mismatch={$after['checksumMismatchCount']}.\n");
    if ($after['pendingCount'] !== 0 || $after['checksumMismatchCount'] !== 0) {
        throw new RuntimeException('Migration chưa hoàn tất đồng bộ; không triển khai Bot mới cho đến khi xử lý xong.');
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Migration không hoàn tất: " . $error->getMessage() . "\n");
    exit(1);
}
