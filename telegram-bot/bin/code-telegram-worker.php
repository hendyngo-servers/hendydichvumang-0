<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Env.php';
require dirname(__DIR__) . '/app/Database.php';
require dirname(__DIR__) . '/app/TelegramApi.php';
require dirname(__DIR__) . '/app/TelegramAuth.php';
require dirname(__DIR__) . '/app/CodeTelegramService.php';

use Hendy\CodeTelegramService;
use Hendy\Env;

Env::load(dirname(__DIR__) . '/.env');

if (!Env::bool('CODE_TELEGRAM_ENABLED', false)) {
    fwrite(STDERR, "CODE_TELEGRAM_ENABLED=false; Code Telegram worker không khởi chạy.\n");
    exit(2);
}

$once = in_array('--once', $argv, true);
$pollSeconds = max(3, min(30, Env::int('CODE_TELEGRAM_WORKER_POLL_SECONDS', 5)));
fwrite(STDOUT, "HENDY Code Telegram worker started; OCR tools: ffmpeg + tesseract; poll={$pollSeconds}s.\n");

do {
    try {
        $processed = CodeTelegramService::runOnce();
        if (!$processed) {
            if ($once) exit(0);
            sleep($pollSeconds);
        }
    } catch (Throwable $error) {
        error_log('[code-telegram-worker] ' . $error::class . ': ' . $error->getMessage());
        if ($once) exit(1);
        sleep(max(5, $pollSeconds));
    }
    if ($once) exit(0);
} while (true);
