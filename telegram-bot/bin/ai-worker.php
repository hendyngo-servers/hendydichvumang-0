<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Env.php';
require dirname(__DIR__) . '/app/Database.php';
require dirname(__DIR__) . '/app/TelegramApi.php';
require dirname(__DIR__) . '/app/TelegramAuth.php';
require dirname(__DIR__) . '/app/AiMediaService.php';

use Hendy\AiMediaService;
use Hendy\Env;

Env::load(dirname(__DIR__) . '/.env');
$pollSeconds = max(3, min(30, Env::int('AI_MEDIA_WORKER_POLL_SECONDS', 5)));
fwrite(STDOUT, "HENDY AI Media Worker started. Queue polling: {$pollSeconds}s.\n");

while (true) {
    try {
        $processed = AiMediaService::runOnce();
        if (!$processed) sleep($pollSeconds);
    } catch (Throwable $error) {
        error_log('HENDY AI Media Worker error: ' . $error::class . ' - ' . $error->getMessage());
        sleep(max(5, $pollSeconds));
    }
}
