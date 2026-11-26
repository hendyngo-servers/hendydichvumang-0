<?php
declare(strict_types=1);

use Hendy\Database;
use Hendy\Env;
use Hendy\TelegramApi;
use Hendy\TelegramAuth;
use Hendy\TelegramLink;

require dirname(__DIR__) . '/app/Env.php';
require dirname(__DIR__) . '/app/Database.php';
require dirname(__DIR__) . '/app/TelegramApi.php';
require dirname(__DIR__) . '/app/TelegramLink.php';
require dirname(__DIR__) . '/app/TelegramAuth.php';

Env::load(dirname(__DIR__) . '/.env');

if (!Env::bool('WEBHOOK_AUTO_REGISTER', true)) {
    fwrite(STDOUT, "WEBHOOK_AUTO_REGISTER=false; bỏ qua đăng ký webhook Telegram.\n");
    exit(0);
}

$token = TelegramAuth::token('primary');
if ($token === '') {
    throw new RuntimeException('BOT1_TOKEN chưa được cấu hình; không thể đăng ký webhook.');
}

// Prefer Render's service-owned public domain so the bot webhook cannot
// accidentally point at the separate Website service through a stale APP_URL.
$renderUrl = trim((string) Env::get('RENDER_EXTERNAL_URL', ''));
$baseUrl = rtrim($renderUrl !== '' ? $renderUrl : trim((string) Env::get('APP_URL', '')), '/');
$parts = $baseUrl !== '' ? parse_url($baseUrl) : false;
if (
    !is_array($parts)
    || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
    || empty($parts['host'])
    || isset($parts['user'])
    || isset($parts['pass'])
    || isset($parts['query'])
    || isset($parts['fragment'])
    || !in_array((string) ($parts['path'] ?? ''), ['', '/'], true)
) {
    throw new RuntimeException('APP_URL hoặc RENDER_EXTERNAL_URL phải là HTTPS origin hợp lệ của Bot.');
}

$webhookUrl = $baseUrl . '/webhook/telegram';

try {
    Database::check();
    $secret = TelegramAuth::webhookSecret('primary');
    $telegram = new TelegramApi($token);

    $me = $telegram->call('getMe', [], 15, 5);
    $username = trim((string) ($me['username'] ?? ''));

    // The same persisted secret is used by the webhook route to authenticate updates.
    // drop_pending_updates=false preserves messages that Telegram has not delivered yet.
    $telegram->setWebhook($webhookUrl, $secret);

    $info = $telegram->call('getWebhookInfo', [], 15, 5);
    $registeredUrl = rtrim((string) ($info['url'] ?? ''), '/');
    if (!hash_equals($webhookUrl, $registeredUrl)) {
        throw new RuntimeException('Telegram chưa xác nhận đúng webhook URL của Bot.');
    }

    if ($username !== '' && preg_match('/^[A-Za-z0-9_]{5,32}$/', $username)) {
        $botUrl = TelegramLink::normalize('https://t.me/' . $username);
        if ($botUrl !== '') {
            Database::run(
                'INSERT INTO app_settings (key, value, is_public) VALUES (?, ?, TRUE) ' .
                'ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, is_public = TRUE, updated_at = NOW()',
                ['primary_bot_url', $botUrl]
            );
        }
    }

    $pending = max(0, (int) ($info['pending_update_count'] ?? 0));
    $identity = $username !== '' ? '@' . $username : 'Bot chính';
    fwrite(STDOUT, "Telegram webhook đã đăng ký và xác minh cho {$identity}; pending updates={$pending}.\n");
} catch (Throwable $error) {
    error_log('[telegram-webhook-registration] ' . $error::class . ': ' . $error->getMessage());
    fwrite(STDERR, "Không thể đăng ký webhook Telegram. Kiểm tra BOT1_TOKEN, PostgreSQL và URL HTTPS trong Render Environment; xem log để biết chi tiết.\n");
    exit(1);
}
