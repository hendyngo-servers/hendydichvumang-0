<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Env.php';
require dirname(__DIR__) . '/app/TelegramAuth.php';
require dirname(__DIR__) . '/app/TelegramLink.php';

Hendy\Env::load(dirname(__DIR__) . '/.env');

$errors = [];
$warnings = [];
$bot1 = Hendy\TelegramAuth::token('primary');
$legacyBot2Configured = trim((string) Hendy\Env::get('BOT2_TOKEN', '')) !== '';
$legacyBot2UrlConfigured = trim((string) Hendy\Env::get('BOT2_PUBLIC_URL', '')) !== '';
$admin = trim((string) Hendy\Env::get('ADMIN_ID', ''));
$adminList = trim((string) Hendy\Env::get('ADMIN_IDS', ''));

$bot1PublicUrl = trim((string) Hendy\Env::get('BOT1_PUBLIC_URL', ''));
if ($bot1PublicUrl !== '' && Hendy\TelegramLink::normalize($bot1PublicUrl) === '') {
    $errors[] = 'BOT1_PUBLIC_URL phải là liên kết HTTPS Telegram hợp lệ dạng https://t.me/<bot_username>.';
}
if ($legacyBot2Configured || $legacyBot2UrlConfigured) {
    $warnings[] = 'Phát hiện biến BOT2 cũ nhưng bản triển khai chỉ sử dụng BOT1_TOKEN; BOT2 không được khởi chạy.';
}

$botTokenPattern = '~\\A[0-9]{5,12}:[A-Za-z0-9_-]{30,}\\z~D';
$adminPattern = '~\A[0-9]{5,20}\z~D';
$secretPattern = '~\A[A-Za-z0-9_-]{32,256}\z~D';

// Use explicit ASCII ranges and ~ delimiters. This avoids shell/YAML escaping
// ambiguities and the PCRE shorthand escape differences users encountered.
if (preg_match($botTokenPattern, $bot1) !== 1) {
    $errors[] = 'BOT1_TOKEN chưa có hoặc không đúng định dạng.';
}
if ($admin !== '' && preg_match($adminPattern, $admin) !== 1) {
    $errors[] = 'ADMIN_ID phải là Telegram user ID dạng số.';
}
$extraAdmins = array_filter(array_map('trim', explode(',', $adminList)));
foreach ($extraAdmins as $extraAdmin) {
    if (preg_match($adminPattern, $extraAdmin) !== 1) {
        $errors[] = 'Từng giá trị trong ADMIN_IDS phải là Telegram user ID dạng số, phân tách bằng dấu phẩy.';
        break;
    }
}
if (Hendy\TelegramAuth::adminIds() === []) {
    $errors[] = 'Cần cấu hình ít nhất một Telegram admin trong ADMIN_ID hoặc ADMIN_IDS.';
}

$dbUrl = Hendy\Env::get('DATABASE_URL');
$dbParts = false;
if ($dbUrl !== null) {
    try {
        $dbParts = parse_url($dbUrl);
    } catch (Throwable) {
        $dbParts = false;
    }
}
$dbScheme = is_array($dbParts) ? strtolower((string) ($dbParts['scheme'] ?? '')) : '';
$dbPath = is_array($dbParts) ? (string) ($dbParts['path'] ?? '') : '';
if (
    !is_array($dbParts) ||
    !in_array($dbScheme, ['postgres', 'postgresql'], true) ||
    empty($dbParts['host']) ||
    trim($dbPath, '/') === ''
) {
    $errors[] = 'DATABASE_URL phải là URI PostgreSQL dạng postgresql://USER:PASSWORD@HOST:5432/DB. Không dán câu lệnh PGPASSWORD=... psql -h ... vào biến này.';
}

$sslValue = (string) Hendy\Env::get('DATABASE_SSL', 'true');
if (!in_array(strtolower($sslValue), ['true', 'false', '1', '0', 'yes', 'no', 'on', 'off'], true)) {
    $errors[] = 'DATABASE_SSL chỉ nhận true hoặc false.';
}

$autoWebhook = Hendy\Env::bool('WEBHOOK_AUTO_REGISTER', true);
if ($autoWebhook) {
    $appUrl = trim((string) (Hendy\Env::get('APP_URL') ?: Hendy\Env::get('RENDER_EXTERNAL_URL', '')));
    $appParts = $appUrl !== '' ? parse_url($appUrl) : false;
    if (!is_array($appParts) || strtolower((string) ($appParts['scheme'] ?? '')) !== 'https' || empty($appParts['host'])) {
        $errors[] = 'APP_URL hoặc RENDER_EXTERNAL_URL phải là URL HTTPS khi bật WEBHOOK_AUTO_REGISTER.';
    }

    // Explicit environment overrides are optional. If omitted or invalid,
    // TelegramAuth creates a persistent random secret after PostgreSQL migrations.
    $primaryOverride = trim((string) Hendy\Env::get('TELEGRAM_WEBHOOK_SECRET', ''));
    $secondaryOverride = trim((string) Hendy\Env::get('TELEGRAM_SECOND_WEBHOOK_SECRET', ''));
    foreach ([
        'TELEGRAM_WEBHOOK_SECRET' => $primaryOverride,
        'TELEGRAM_SECOND_WEBHOOK_SECRET' => $secondaryOverride,
    ] as $secretName => $configuredSecret) {
        if ($configuredSecret !== '' && preg_match($secretPattern, $configuredSecret) !== 1) {
            $warnings[] = $secretName . ' không hợp lệ; backend sẽ tự tạo secret ngẫu nhiên riêng trong PostgreSQL.';
        }
    }
    if ($bot2 !== '' && $primaryOverride !== '' && $secondaryOverride !== ''
        && hash_equals($primaryOverride, $secondaryOverride)) {
        $errors[] = 'TELEGRAM_WEBHOOK_SECRET và TELEGRAM_SECOND_WEBHOOK_SECRET phải khác nhau.';
    }
}

$videoProcessorUrl = trim((string) Hendy\Env::get('VIDEO_PROCESSOR_URL', ''));
$videoProcessorSecret = (string) Hendy\Env::get('VIDEO_PROCESSOR_SECRET', '');
if (($videoProcessorUrl === '') !== ($videoProcessorSecret === '')) {
    $errors[] = 'VIDEO_PROCESSOR_URL và VIDEO_PROCESSOR_SECRET phải được cấu hình cùng nhau hoặc để trống cả hai.';
}
if ($videoProcessorUrl !== '') {
    $processorParts = parse_url($videoProcessorUrl);
    if (!is_array($processorParts) || strtolower((string) ($processorParts['scheme'] ?? '')) !== 'https' || empty($processorParts['host'])) {
        $errors[] = 'VIDEO_PROCESSOR_URL phải là URL HTTPS hợp lệ.';
    }
    if (strlen($videoProcessorSecret) < 32) {
        $errors[] = 'VIDEO_PROCESSOR_SECRET phải có ít nhất 32 ký tự.';
    }
    $callbackBase = trim((string) (Hendy\Env::get('APP_URL') ?: Hendy\Env::get('RENDER_EXTERNAL_URL', '')));
    $callbackParts = $callbackBase !== '' ? parse_url($callbackBase) : false;
    if (!is_array($callbackParts) || strtolower((string) ($callbackParts['scheme'] ?? '')) !== 'https' || empty($callbackParts['host'])) {
        $errors[] = 'Cần APP_URL HTTPS hợp lệ để nhận callback video.';
    }
}

$websiteSessionTtl = Hendy\Env::int('WEBSITE_SESSION_TTL_SECONDS', 43200);
if ($websiteSessionTtl < 900 || $websiteSessionTtl > 43200) {
    $errors[] = 'WEBSITE_SESSION_TTL_SECONDS phải nằm trong khoảng 900–43200 giây.';
}

if (Hendy\Env::bool('WEBSOCKET_ENABLED', false)) {
    $socketUrl = trim((string) Hendy\Env::get('WEBSOCKET_URL', ''));
    $socketParts = $socketUrl !== '' ? parse_url($socketUrl) : false;
    if (!is_array($socketParts) || strtolower((string) ($socketParts['scheme'] ?? '')) !== 'wss' || empty($socketParts['host'])) {
        $errors[] = 'WEBSOCKET_URL phải là URL WSS HTTPS hợp lệ khi bật WebSocket.';
    }
    $origins = array_values(array_filter(array_map('trim', explode(',', (string) Hendy\Env::get('WEBSOCKET_ALLOWED_ORIGINS', '')))));
    if ($origins === []) {
        $errors[] = 'WEBSOCKET_ALLOWED_ORIGINS phải có ít nhất một HTTPS origin khi bật WebSocket.';
    }
    foreach ($origins as $origin) {
        $originParts = parse_url($origin);
        if (!is_array($originParts) || strtolower((string) ($originParts['scheme'] ?? '')) !== 'https'
            || empty($originParts['host']) || isset($originParts['path']) || isset($originParts['query']) || isset($originParts['fragment'])) {
            $errors[] = 'Mỗi WEBSOCKET_ALLOWED_ORIGINS phải là HTTPS origin, không bao gồm path/query.';
            break;
        }
    }
    $socketPort = Hendy\Env::int('WEBSOCKET_PORT', 10001);
    if ($socketPort < 1 || $socketPort > 65535 || $socketPort === Hendy\Env::int('PORT', 10000)) {
        $errors[] = 'WEBSOCKET_PORT phải là cổng nội bộ hợp lệ và khác PORT public của Apache.';
    }
    if (strlen((string) Hendy\Env::get('LEGACY_SESSION_SECRET', '')) < 32) {
        $errors[] = 'LEGACY_SESSION_SECRET phải có ít nhất 32 ký tự để ký cookie website.';
    }
}

// The bank callback remains disabled until a provider-specific signature
// adapter is installed and verified. A shared secret by itself is not enough
// to enable real-money deposits.
$bankEnabled = Hendy\Env::bool('BANK_WEBHOOK_ENABLED', false);
if ($bankEnabled) {
    $errors[] = 'BANK_WEBHOOK_ENABLED=true bị chặn: chưa cấu hình adapter chữ ký callback theo nhà cung cấp.';
    $bankSecret = (string) Hendy\Env::get('BANK_WEBHOOK_SECRET', '');
    if (preg_match($secretPattern, $bankSecret) !== 1) {
        $errors[] = 'BANK_WEBHOOK_SECRET phải có 32–256 ký tự khi tích hợp adapter hợp lệ.';
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Cấu hình chưa hợp lệ:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}
foreach ($warnings as $warning) {
    fwrite(STDERR, "CẢNH BÁO: " . $warning . "\n");
}

echo "Cấu hình hợp lệ. Telegram: một bot chính (BOT1_TOKEN)" .
    "; Telegram webhook: " . ($autoWebhook ? 'đã bật kiểm tra' : 'đang tắt') .
    "; bank webhook: " . ($bankEnabled ? 'đã bật' : 'đã khóa an toàn') . ".\n";
