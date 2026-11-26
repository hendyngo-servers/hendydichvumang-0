<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Env.php';
require dirname(__DIR__) . '/app/Database.php';
require dirname(__DIR__) . '/app/NeonStorage.php';
require dirname(__DIR__) . '/app/AiCoverService.php';

use Hendy\AiCoverService;
use Hendy\Env;

Env::load(dirname(__DIR__) . '/.env');

if (!Env::bool('AI_COVER_ENABLED', false) || !Env::bool('AI_COVER_WORKER_ENABLED', false)) {
    fwrite(STDERR, "AI_COVER_ENABLED/AI_COVER_WORKER_ENABLED=false; AI Cover dispatcher không khởi chạy.\n");
    exit(2);
}
if (trim((string) Env::get('AI_COVER_WORKER_URL', '')) === ''
    || strlen(trim((string) Env::get('AI_COVER_CALLBACK_SECRET', ''))) < 32) {
    fwrite(STDERR, "AI Cover cần AI_COVER_WORKER_URL HTTPS và AI_COVER_CALLBACK_SECRET tối thiểu 32 ký tự.\n");
    exit(2);
}

$pollSeconds = max(2, min(30, Env::int('AI_COVER_WORKER_POLL_SECONDS', 5)));
fwrite(STDOUT, "HENDY AI Cover dispatcher started; poll={$pollSeconds}s.\n");

while (true) {
    try {
        $processed = AiCoverService::dispatchOnce();
        if (!$processed) sleep($pollSeconds);
    } catch (Throwable $error) {
        error_log('[ai-cover-dispatcher] ' . $error::class . ': ' . $error->getMessage());
        sleep(max(5, $pollSeconds));
    }
}
