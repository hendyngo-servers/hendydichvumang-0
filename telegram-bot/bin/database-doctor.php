<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Env.php';
require dirname(__DIR__) . '/app/Database.php';

use Hendy\Database;
use Hendy\Env;

Env::load(dirname(__DIR__) . '/.env');
$migrationUrl = Env::get('MIGRATION_DATABASE_URL');
if ($migrationUrl !== null) {
    putenv('DATABASE_URL=' . $migrationUrl);
    $_ENV['DATABASE_URL'] = $migrationUrl;
    $_SERVER['DATABASE_URL'] = $migrationUrl;
}

try {
    $plan = Database::migrationPlan();
    fwrite(STDOUT, "HENDY DATABASE DOCTOR (READ ONLY)\n");
    fwrite(STDOUT, "Database name : {$plan['database']}\n");
    fwrite(STDOUT, "Database role : {$plan['role']}\n");
    fwrite(STDOUT, "Database host : {$plan['host']}\n");
    fwrite(STDOUT, "Connection    : " . ($plan['pooled'] ? 'POOLED' : 'DIRECT / UNPOOLED') . "\n");
    fwrite(STDOUT, "Base schema   : " . ($plan['baseSchema']['ready'] ? 'READY' : ($plan['baseSchema']['empty'] ? 'EMPTY' : 'PARTIAL')) . "\n");
    fwrite(STDOUT, "Migration log : " . ($plan['trackingTableReady'] ? 'READY' : 'NOT INITIALIZED') . "\n");
    fwrite(STDOUT, "Migrations    : " . count($plan['migrations']) . " file(s), {$plan['pendingCount']} pending, {$plan['checksumMismatchCount']} checksum mismatch(es)\n");
    fwrite(STDOUT, "Secrets       : not displayed\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Database doctor failed: " . $error->getMessage() . "\n");
    exit(1);
}
