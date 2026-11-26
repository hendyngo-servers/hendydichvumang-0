<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Env.php';
require dirname(__DIR__) . '/app/Database.php';
require dirname(__DIR__) . '/app/TelegramApi.php';
require dirname(__DIR__) . '/app/TelegramAuth.php';

Hendy\Env::load(dirname(__DIR__) . '/.env');

$autoRegister = Hendy\Env::bool('WEBHOOK_AUTO_REGISTER', true);
if (!$autoRegister) {
    fwrite(STDOUT, "WEBHOOK_AUTO_REGISTER=false; bỏ qua đăng ký webhook.\n");
    exit(0);
}

$baseUrl = rtrim((string) (Hendy\Env::get('APP_URL') ?: Hendy\Env::get('RENDER_EXTERNAL_URL', '')), '/');
$baseParts = $baseUrl !== '' ? parse_url($baseUrl) : false;
$validBaseUrl = is_array($baseParts)
    && strtolower((string) ($baseParts['scheme'] ?? '')) === 'https'
    && !empty($baseParts['host'])
    && !isset($baseParts['user'])
    && !isset($baseParts['pass']);

if (!$validBaseUrl) {
    fwrite(STDERR, "APP_URL/RENDER_EXTERNAL_URL cần HTTPS hợp lệ để đăng ký webhook.\n");
    exit(0);
}

$bots = [
    'primary' => [
        'token' => Hendy\TelegramAuth::token('primary'),
        'secret' => Hendy\TelegramAuth::webhookSecret('primary'),
        'path' => '/webhook/telegram',
        'label' => 'bot chính',
    ],
];

$failed = [];
foreach ($bots as $key => $config) {
    if ($config['token'] === '') {
        $failed[] = 'BOT1_TOKEN đang trống; chưa thể đăng ký webhook bot chính.';
        continue;
    }

    if (!preg_match('~^[A-Za-z0-9_-]{32,256}$~D', $config['secret'])) {
        $failed[] = 'Thiếu webhook secret hợp lệ cho ' . $config['label'] . '.';
        continue;
    }

    try {
        $api = new Hendy\TelegramApi($config['token']);
        $identity = $api->call('getMe', [], 8, 4);
        if ($key === 'primary') {
            $username = trim((string) ($identity['username'] ?? ''));
            if (preg_match('/\\A[A-Za-z0-9_]{5,32}\\z/D', $username) === 1) {
                $publicUrl = 'https://t.me/' . $username;
                Hendy\Database::run(
                    'INSERT INTO app_settings (key, value, is_public) VALUES (?, ?, TRUE) ' .
                    'ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, is_public = TRUE, updated_at = NOW()',
                    ['primary_bot_url', $publicUrl]
                );
                fwrite(STDOUT, "Telegram getMe(): đã cập nhật public Bot URL https://t.me/{$username}.\n");
            } else {
                fwrite(STDERR, "Telegram getMe() không trả về username công khai hợp lệ.\n");
            }
        }
        $api->setWebhook($baseUrl . $config['path'], $config['secret']);
        fwrite(STDOUT, "Đã đăng ký webhook {$config['path']} cho {$config['label']}.\n");
    } catch (Throwable $error) {
        $message = $error->getMessage();
        if (preg_match('/(?:Telegram API lỗi )?401\b|Unauthorized/i', $message)) {
            $failed[] = $config['label'] . ': Telegram từ chối token (401 Unauthorized). Hãy kiểm tra biến ' .
                'BOT1_TOKEN' . ' trong Render Environment.';
        } else {
            $failed[] = $config['label'] . ': không đăng ký được webhook: ' . $message;
        }
    }
}

if ($failed !== []) {
    foreach ($failed as $warning) {
        fwrite(STDERR, "CẢNH BÁO BOT: " . $warning . "\n");
    }
    fwrite(STDERR, "Web Service vẫn tiếp tục khởi động. Kiểm tra token và webhook secrets trong Render Environment.\n");
}
