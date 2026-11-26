<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Env.php';
require dirname(__DIR__) . '/app/Database.php';
require dirname(__DIR__) . '/app/PaymentService.php';
require dirname(__DIR__) . '/app/VideoJobService.php';
require dirname(__DIR__) . '/app/VoucherService.php';
require dirname(__DIR__) . '/app/VipService.php';
require dirname(__DIR__) . '/app/TelegramAuth.php';
require dirname(__DIR__) . '/app/Realtime.php';

use Hendy\Database;
use Hendy\PaymentService;
use Hendy\VideoJobService;
use Hendy\VoucherService;
use Hendy\VipService;
use Hendy\TelegramAuth;
use Hendy\Realtime;
use Hendy\Env;

Env::load(dirname(__DIR__) . '/.env');
Database::migrate();

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException('Database smoke test thất bại: ' . $message);
    }
};

$assert(Database::check(), 'PostgreSQL connection');


// Webhook secrets are generated randomly per bot, persist across calls, and rotate
// independently on token changes. Explicit overrides are supported but cannot block
// automatic rotation when the bot token changes.
$previousBot1Token = getenv('BOT1_TOKEN');
$previousBot2Token = getenv('BOT2_TOKEN');
$previousPrimaryWebhookSecret = getenv('TELEGRAM_WEBHOOK_SECRET');
$previousSecondaryWebhookSecret = getenv('TELEGRAM_SECOND_WEBHOOK_SECRET');
$testBot1Token = '123456789:' . bin2hex(random_bytes(24));
$testBot2Token = '987654321:' . bin2hex(random_bytes(24));
putenv('BOT1_TOKEN=' . $testBot1Token);
putenv('BOT2_TOKEN=' . $testBot2Token);
putenv('TELEGRAM_WEBHOOK_SECRET');
putenv('TELEGRAM_SECOND_WEBHOOK_SECRET');

$primarySecret = TelegramAuth::webhookSecret('primary');
$secondarySecret = TelegramAuth::webhookSecret('secondary');
$assert((bool) preg_match('/^[a-f0-9]{64}$/D', $primarySecret), 'primary random webhook secret is 256-bit hex');
$assert((bool) preg_match('/^[a-f0-9]{64}$/D', $secondarySecret), 'secondary random webhook secret is 256-bit hex');
$assert(!hash_equals($primarySecret, $secondarySecret), 'each bot receives a different random webhook secret');
$assert(TelegramAuth::webhookSecret('primary') === $primarySecret, 'primary webhook secret persists across calls');
$assert(TelegramAuth::webhookSecret('secondary') === $secondarySecret, 'secondary webhook secret persists across calls');

// A stale explicit override must not prevent token-bound random rotation.
$manualOverride = 'manual-primary-override-012345678901234567890123456789';
putenv('TELEGRAM_WEBHOOK_SECRET=' . $manualOverride);
$appliedOverride = TelegramAuth::webhookSecret('primary');
$assert($appliedOverride === $manualOverride, 'a newly configured primary override is applied');
$assert(TelegramAuth::webhookSecret('primary') === $manualOverride, 'primary override stays stable across calls');
putenv('BOT1_TOKEN=123456789:' . bin2hex(random_bytes(24)));
$rotatedPrimarySecret = TelegramAuth::webhookSecret('primary');
$assert($rotatedPrimarySecret !== $manualOverride, 'an unchanged old override cannot block automatic primary token rotation');
$assert((bool) preg_match('/^[a-f0-9]{64}$/D', $rotatedPrimarySecret), 'rotated primary secret is independently random');
$assert(TelegramAuth::webhookSecret('primary') === $rotatedPrimarySecret, 'rotated primary secret persists after restart-style re-read');
$assert(TelegramAuth::webhookSecret('secondary') === $secondarySecret, 'rotating primary does not change secondary secret');

// Changing the override intentionally rotates the secret; clearing it falls back to a random value.
$newManualOverride = 'manual-primary-override-abcdefghijklmnopqrstuvwxyz0123456789';
putenv('TELEGRAM_WEBHOOK_SECRET=' . $newManualOverride);
$assert(TelegramAuth::webhookSecret('primary') === $newManualOverride, 'changing the explicit override intentionally rotates the primary secret');
putenv('TELEGRAM_WEBHOOK_SECRET');
$clearedOverrideSecret = TelegramAuth::webhookSecret('primary');
$assert($clearedOverrideSecret !== $newManualOverride && (bool) preg_match('/^[a-f0-9]{64}$/D', $clearedOverrideSecret), 'removing an explicit override rotates back to a random secret');
$assert(TelegramAuth::webhookSecret('primary') === $clearedOverrideSecret, 'random secret persists after override removal');

Database::run('DELETE FROM telegram_webhook_secrets WHERE bot_key IN (?, ?)', ['primary', 'secondary']);
if ($previousBot1Token === false) putenv('BOT1_TOKEN'); else putenv('BOT1_TOKEN=' . $previousBot1Token);
if ($previousBot2Token === false) putenv('BOT2_TOKEN'); else putenv('BOT2_TOKEN=' . $previousBot2Token);
if ($previousPrimaryWebhookSecret === false) putenv('TELEGRAM_WEBHOOK_SECRET'); else putenv('TELEGRAM_WEBHOOK_SECRET=' . $previousPrimaryWebhookSecret);
if ($previousSecondaryWebhookSecret === false) putenv('TELEGRAM_SECOND_WEBHOOK_SECRET'); else putenv('TELEGRAM_SECOND_WEBHOOK_SECRET=' . $previousSecondaryWebhookSecret);

$previousAdminId = getenv('ADMIN_ID');
$previousAdminIds = getenv('ADMIN_IDS');
$realtimeAdminOne = '9500000001';
$realtimeAdminTwo = '9500000002';
putenv('ADMIN_ID=' . $realtimeAdminOne);
putenv('ADMIN_IDS=' . $realtimeAdminTwo);
$realtimeTicket = Realtime::issueAdminTicket($realtimeAdminTwo);
$assert(Realtime::consumeAdminTicket($realtimeTicket), 'one-time WebSocket ticket accepts any configured admin');
$assert(!Realtime::consumeAdminTicket($realtimeTicket), 'WebSocket ticket cannot be reused');
$realtimeUserId = '9500000003';
$memberTicket = Realtime::issueTicket($realtimeUserId, 'users');
$memberIdentity = Realtime::consumeTicket($memberTicket);
$assert($memberIdentity !== null && $memberIdentity['userId'] === $realtimeUserId && $memberIdentity['audience'] === 'users', 'member WebSocket ticket is bound to the authenticated user audience');
$assert(Realtime::consumeTicket($memberTicket) === null, 'member WebSocket ticket cannot be reused');
if ($previousAdminId === false) putenv('ADMIN_ID'); else putenv('ADMIN_ID=' . $previousAdminId);
if ($previousAdminIds === false) putenv('ADMIN_IDS'); else putenv('ADMIN_IDS=' . $previousAdminIds);

Realtime::listen();
Realtime::publish('admin.realtime.smoke', ['kind' => 'postgres-notify']);
$notificationCount = Realtime::drainNotifications();
$latestRealtimeEvent = Database::one(
    "SELECT event_type, audience, payload FROM realtime_events ORDER BY id DESC LIMIT 1"
);
$assert($notificationCount >= 1, 'PostgreSQL LISTEN/NOTIFY wakes the realtime worker');
$assert($latestRealtimeEvent !== null && $latestRealtimeEvent['event_type'] === 'admin.realtime.smoke' && $latestRealtimeEvent['audience'] === 'admin', 'realtime publish persists event before notification');
Realtime::publish('user.data.changed', ['kind' => 'wallet'], 'users');
$userRealtimeEvent = Database::one(
    "SELECT event_type, audience, payload FROM realtime_events WHERE event_type = 'user.data.changed' ORDER BY id DESC LIMIT 1"
);
$assert($userRealtimeEvent !== null && $userRealtimeEvent['audience'] === 'users', 'member data change is broadcast only to authenticated website sessions');


$accountUser = '95' . str_pad((string) random_int(1, 999999999999), 12, '0', STR_PAD_LEFT);
Database::run('INSERT INTO users (user_id, name) VALUES (?, ?) ON CONFLICT (user_id) DO NOTHING', [$accountUser, 'Account Inventory Smoke']);
foreach (['RR88', 'MM88', 'GG88', 'AD88'] as $platform) {
    Database::run(
        'INSERT INTO linked_accounts (user_id, brand, username) VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
        [$accountUser, $platform, 'test_' . strtolower($platform)]
    );
}
$accountPlatforms = Database::all(
    'SELECT brand FROM linked_accounts WHERE user_id = ? ORDER BY brand',
    [$accountUser]
);
$accountPlatformNames = array_column($accountPlatforms, 'brand');
$assert(count(array_intersect(['RR88', 'MM88', 'GG88', 'AD88'], $accountPlatformNames)) === 4, 'legacy minigame platforms map to the existing linked account inventory');

$abuseTicket = Database::one(
    "INSERT INTO support_tickets (user_id, ticket_type, subject, message) VALUES (?, 'dinh_lam', ?, ?) RETURNING id, ticket_type",
    [$accountUser, 'Hỗ trợ dính lạm dụng (60%) · Đơn TEST', 'Kiểm tra quy trình ticket dính lạm dụng smoke test.']
);
$assert($abuseTicket !== null && $abuseTicket['ticket_type'] === 'dinh_lam', 'Telegram customer centre can store the abuse-support ticket type');


$vipUserId = (string) (900000000000 + random_int(100000, 999999));
Database::run('INSERT INTO users (user_id, name, username) VALUES (?, ?, ?)', [$vipUserId, 'VIP Checkin Smoke', 'vip_checkin_smoke']);
$vipBefore = (int) Database::value('SELECT point_old FROM users WHERE user_id = ?', [$vipUserId]);
$vipCheckin = VipService::checkin($vipUserId);
$vipAfter = (int) Database::value('SELECT point_old FROM users WHERE user_id = ?', [$vipUserId]);
$assert(!$vipCheckin['alreadyCheckedIn'] && $vipAfter === $vipBefore + 1, 'VIP daily check-in credits the configured internal point');
$vipDuplicate = VipService::checkin($vipUserId);
$vipAfterDuplicate = (int) Database::value('SELECT point_old FROM users WHERE user_id = ?', [$vipUserId]);
$assert($vipDuplicate['alreadyCheckedIn'] && $vipAfterDuplicate === $vipAfter, 'VIP check-in is idempotent for the same Vietnam-local day');
$vipStatus = VipService::status($vipUserId);
$assert($vipStatus['checkedInToday'] && $vipStatus['pointOld'] === $vipAfter, 'VIP status reports daily check-in and point balance');
$userId = '987654321012';
Database::run(
    'INSERT INTO users (user_id, name, username) VALUES (?, ?, ?) ON CONFLICT (user_id) DO UPDATE SET name = EXCLUDED.name, username = EXCLUDED.username',
    [$userId, 'PHP Smoke Test', 'php_smoke']
);
$user = Database::one('SELECT user_id, name, balance, vip_level FROM users WHERE user_id = ?', [$userId]);
$assert($user !== null && $user['name'] === 'PHP Smoke Test', 'create/read users');

// Website browser sessions are signed, bot-bound, cookie-only, and resolve their identity from PostgreSQL.
$previousWebsiteSecret = getenv('LEGACY_SESSION_SECRET');
$previousAdminId = getenv('ADMIN_ID');
$previousAdminIds = getenv('ADMIN_IDS');
$previousCookie = $_COOKIE['hendy_web_session'] ?? null;
$previousBotHeader = $_SERVER['HTTP_X_TELEGRAM_BOT_KEY'] ?? null;
$websiteSecret = str_repeat('hendy-website-session-test-', 2);
putenv('LEGACY_SESSION_SECRET=' . $websiteSecret);
$base64Url = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
$websiteHeader = $base64Url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
$websitePayload = $base64Url(json_encode([
    'sub' => $userId,
    'bot' => 'secondary',
    'aud' => 'hendy-legacy-account',
    'iat' => time(),
    'exp' => time() + 3600,
]));
$websiteUnsigned = $websiteHeader . '.' . $websitePayload;
$websiteSigningKey = hash_hmac('sha256', 'hendy-website-session:secondary', $websiteSecret);
$websiteSignature = $base64Url(hash_hmac('sha256', $websiteUnsigned, $websiteSigningKey, true));
$_COOKIE['hendy_web_session'] = $websiteUnsigned . '.' . $websiteSignature;
$_SERVER['HTTP_X_TELEGRAM_BOT_KEY'] = 'secondary';
$websiteIdentity = TelegramAuth::authenticateRequest();
$assert($websiteIdentity['id'] === $userId && $websiteIdentity['_bot_key'] === 'secondary', 'website cookie resolves PostgreSQL identity and originating bot');
$_SERVER['HTTP_X_TELEGRAM_BOT_KEY'] = 'primary';
$crossBotCookieRejected = false;
try {
    TelegramAuth::authenticateRequest();
} catch (Throwable) {
    $crossBotCookieRejected = true;
}
$assert($crossBotCookieRejected, 'website cookie cannot authenticate the other bot flow');

// Admin browser sessions use a distinct signed audience and are re-checked against the live allowlist.
putenv('ADMIN_ID=' . $userId);
putenv('ADMIN_IDS=');
$adminPayload = $base64Url(json_encode([
    'sub' => $userId,
    'bot' => 'secondary',
    'aud' => 'hendy-admin-dashboard',
    'iat' => time(),
    'exp' => time() + 3600,
]));
$adminUnsigned = $websiteHeader . '.' . $adminPayload;
$adminSignature = $base64Url(hash_hmac('sha256', $adminUnsigned, $websiteSigningKey, true));
$_COOKIE['hendy_web_session'] = $adminUnsigned . '.' . $adminSignature;
$_SERVER['HTTP_X_TELEGRAM_BOT_KEY'] = 'secondary';
$adminIdentity = TelegramAuth::authenticateRequest();
$assert($adminIdentity['_is_admin'] === true && $adminIdentity['_session_scope'] === 'admin', 'Admin browser session authenticates with admin-only audience');
putenv('ADMIN_ID=123456789999');
putenv('ADMIN_IDS=');
$revokedAdminSessionRejected = false;
try {
    TelegramAuth::authenticateRequest();
} catch (Throwable) {
    $revokedAdminSessionRejected = true;
}
$assert($revokedAdminSessionRejected, 'Admin browser session stops working after removal from the live admin allowlist');

if ($previousWebsiteSecret === false) putenv('LEGACY_SESSION_SECRET'); else putenv('LEGACY_SESSION_SECRET=' . $previousWebsiteSecret);
if ($previousAdminId === false) putenv('ADMIN_ID'); else putenv('ADMIN_ID=' . $previousAdminId);
if ($previousAdminIds === false) putenv('ADMIN_IDS'); else putenv('ADMIN_IDS=' . $previousAdminIds);
if ($previousCookie === null) unset($_COOKIE['hendy_web_session']); else $_COOKIE['hendy_web_session'] = $previousCookie;
if ($previousBotHeader === null) unset($_SERVER['HTTP_X_TELEGRAM_BOT_KEY']); else $_SERVER['HTTP_X_TELEGRAM_BOT_KEY'] = $previousBotHeader;

$rollbackId = '987654321013';
try {
    Database::transaction(function () use ($rollbackId): void {
        Database::run('INSERT INTO users (user_id, name) VALUES (?, ?)', [$rollbackId, 'Rollback Test']);
        throw new RuntimeException('expected rollback');
    });
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'expected rollback') {
        throw $error;
    }
}
$assert(Database::one('SELECT user_id FROM users WHERE user_id = ?', [$rollbackId]) === null, 'rollback transaction');

$product = Database::one(
    "INSERT INTO products (slug, name, category, description, price, stock, is_active) VALUES (?, ?, 'tools', ?, ?, ?, TRUE) RETURNING id",
    ['ci-smoke-' . bin2hex(random_bytes(3)), 'PHP Smoke Tool', 'Test product', 15000, 4]
);
$assert($product !== null, 'create product');
$orderCode = 'CI' . strtoupper(bin2hex(random_bytes(5)));
$order = Database::one(
    "INSERT INTO orders (order_code, user_id, product_id, product_name, category, amount, status) VALUES (?, ?, ?, ?, 'tools', 15000, 'pending') RETURNING id",
    [$orderCode, $userId, $product['id'], 'PHP Smoke Tool']
);
$assert($order !== null, 'create order');

// Customer center: legacy-compatible wallet fields and 72-hour voucher eligibility.
Database::run("UPDATE orders SET status = 'processing', created_at = NOW() - INTERVAL '73 hours' WHERE id = ?", [$order['id']]);
$eligible = VoucherService::eligibleOrders($userId);
$assert(count(array_filter($eligible, static fn (array $row): bool => (int) $row['id'] === (int) $order['id'])) === 1, 'only processing orders older than 72 hours are voucher eligible');
$voucher = VoucherService::create($userId, (int) $order['id'], 'Đơn hàng đã quá 72 giờ chưa hoàn tất.');
$assert($voucher['status'] === 'pending' && $voucher['order_code'] === $orderCode, 'create pending voucher request for eligible order');
$duplicateVoucherRejected = false;
try {
    VoucherService::create($userId, (int) $order['id'], 'Gửi trùng yêu cầu voucher.');
} catch (RuntimeException) {
    $duplicateVoucherRejected = true;
}
$assert($duplicateVoucherRejected, 'one voucher request per order');
$mainBalanceBeforeVoucher = (int) Database::value('SELECT balance FROM users WHERE user_id = ?', [$userId]);
$voucherBalanceBefore = (int) Database::value('SELECT voucher_wallet FROM users WHERE user_id = ?', [$userId]);
$reviewedVoucher = VoucherService::review((int) $voucher['id'], 'approve', '99887766', 25000, 'Đã đối chiếu đơn hàng.');
$mainBalanceAfterVoucher = (int) Database::value('SELECT balance FROM users WHERE user_id = ?', [$userId]);
$voucherBalanceAfter = (int) Database::value('SELECT voucher_wallet FROM users WHERE user_id = ?', [$userId]);
$assert($reviewedVoucher['status'] === 'approved' && $voucherBalanceAfter === $voucherBalanceBefore + 25000, 'voucher approval credits voucher wallet');
$assert($mainBalanceAfterVoucher === $mainBalanceBeforeVoucher, 'voucher approval never credits main wallet');
$voucherLedger = Database::one("SELECT wallet_type, amount FROM wallet_ledger WHERE reference_code = ?", ['voucher-request:' . $voucher['id']]);
$assert($voucherLedger !== null && $voucherLedger['wallet_type'] === 'VOUCHER' && (int) $voucherLedger['amount'] === 25000, 'voucher credit has a separate idempotent ledger entry');
$secondVoucherApprovalRejected = false;
try {
    VoucherService::review((int) $voucher['id'], 'approve', '99887766', 25000);
} catch (RuntimeException) {
    $secondVoucherApprovalRejected = true;
}
$assert($secondVoucherApprovalRejected, 'approved voucher cannot be credited twice');

$rejectOrderCode = 'CIVR' . strtoupper(bin2hex(random_bytes(4)));
$rejectOrder = Database::one(
    "INSERT INTO orders (order_code, user_id, product_id, product_name, category, amount, status, created_at) VALUES (?, ?, ?, ?, 'tools', 15000, 'pending', NOW() - INTERVAL '80 hours') RETURNING id",
    [$rejectOrderCode, $userId, $product['id'], 'Voucher rejection smoke']
);
$rejectVoucher = VoucherService::create($userId, (int) $rejectOrder['id'], 'Kiểm tra quy trình từ chối voucher.');
$rejectedVoucher = VoucherService::review((int) $rejectVoucher['id'], 'reject', '99887766', 0, 'Không đủ điều kiện.');
$assert($rejectedVoucher['status'] === 'rejected' && (int) Database::value('SELECT voucher_wallet FROM users WHERE user_id = ?', [$userId]) === $voucherBalanceAfter, 'rejecting voucher request never changes voucher wallet');


$reference = 'ci-ledger-' . bin2hex(random_bytes(5));
Database::run(
    "INSERT INTO wallet_ledger (reference_code, user_id, amount, transaction_type, description) VALUES (?, ?, ?, 'TEST', 'CI smoke') ON CONFLICT (reference_code) DO NOTHING",
    [$reference, $userId, 1000]
);
Database::run(
    "INSERT INTO wallet_ledger (reference_code, user_id, amount, transaction_type, description) VALUES (?, ?, ?, 'TEST', 'CI duplicate') ON CONFLICT (reference_code) DO NOTHING",
    [$reference, $userId, 1000]
);
$count = (int) Database::value('SELECT COUNT(*) FROM wallet_ledger WHERE reference_code = ?', [$reference]);
$assert($count === 1, 'idempotent wallet ledger reference');

$giftCode = 'CI' . strtoupper(bin2hex(random_bytes(4)));
Database::run('INSERT INTO giftcodes (code, reward_amount, max_redemptions) VALUES (?, 5000, 1)', [$giftCode]);
Database::run('INSERT INTO giftcode_redemptions (code, user_id, reward_amount) VALUES (?, ?, 5000)', [$giftCode, $userId]);
$duplicate = Database::run(
    'INSERT INTO giftcode_redemptions (code, user_id, reward_amount) VALUES (?, ?, 5000) ON CONFLICT DO NOTHING RETURNING code',
    [$giftCode, $userId]
);
$assert($duplicate->rowCount() === 0, 'giftcode can only be redeemed once per user');

Database::run(
    "INSERT INTO bot_menu_configs (bot_key, menu_mode, button_labels) VALUES ('primary', 'inline_keyboard', ?::jsonb) ON CONFLICT (bot_key) DO UPDATE SET menu_mode = EXCLUDED.menu_mode",
    [json_encode(['wallet' => 'NẠP TIỀN'], JSON_UNESCAPED_UNICODE)]
);
$menu = Database::one('SELECT menu_mode FROM bot_menu_configs WHERE bot_key = ?', ['primary']);
$assert($menu !== null && $menu['menu_mode'] === 'inline_keyboard', 'bot inline menu config');

Database::run(
    "INSERT INTO support_tickets (user_id, subject, message) VALUES (?, ?, ?)",
    [$userId, 'CI smoke ticket', 'Testing support ticket schema']
);
$ticketCount = (int) Database::value('SELECT COUNT(*) FROM support_tickets WHERE user_id = ?', [$userId]);
$assert($ticketCount === 1, 'support ticket schema');

// Telegram payment request: request creation does not credit wallet; admin approval is idempotent.
$balanceBeforePayment = (int) Database::value('SELECT balance FROM users WHERE user_id = ?', [$userId]);
$payment = PaymentService::create($userId, 50000, 'secondary', 'MB', '123456789', 'HENDY TEST');
$assert($payment['status'] === 'pending' && $payment['transfer_content'] !== '', 'payment request is created in pending state');
$assert((int) Database::value('SELECT balance FROM users WHERE user_id = ?', [$userId]) === $balanceBeforePayment, 'creating a payment request never credits the wallet');
$approvedPayment = PaymentService::approve((string) $payment['payment_id'], '99887766');
$balanceAfterPayment = (int) Database::value('SELECT balance FROM users WHERE user_id = ?', [$userId]);
$assert($approvedPayment['newBalance'] === $balanceBeforePayment + 50000 && $balanceAfterPayment === $balanceBeforePayment + 50000, 'admin payment approval credits the exact amount');
$assert($approvedPayment['botKey'] === 'secondary', 'payment approval retains originating Telegram bot');
$duplicateApprovalRejected = false;
try {
    PaymentService::approve((string) $payment['payment_id'], '99887766');
} catch (RuntimeException) {
    $duplicateApprovalRejected = true;
}
$assert($duplicateApprovalRejected, 'payment approval cannot credit wallet twice');
$rejectedRequest = PaymentService::create($userId, 10000, 'primary', 'MB', '123456789', 'HENDY TEST');
$rejected = PaymentService::reject((string) $rejectedRequest['payment_id'], '99887766', 'CI reject');
$assert($rejected['botKey'] === 'primary', 'payment rejection retains originating Telegram bot');
$assert((int) Database::value('SELECT balance FROM users WHERE user_id = ?', [$userId]) === $balanceAfterPayment, 'rejecting payment never changes wallet balance');

$videoJob = VideoJobService::create($userId, 'https://youtu.be/dQw4w9WgXcQ', 'primary');
$assert($videoJob['status'] === 'queued' && $videoJob['progress'] === 0, 'video job enters PostgreSQL queue');
$processingJob = VideoJobService::updateFromCallback(['jobId' => $videoJob['id'], 'status' => 'processing', 'progress' => 55]);
$assert($processingJob['status'] === 'processing' && (int) $processingJob['progress'] === 55, 'signed callback state is reflected as processing');
$completedJob = VideoJobService::updateFromCallback(['jobId' => $videoJob['id'], 'status' => 'completed', 'progress' => 100, 'outputUrl' => 'https://cdn.example.com/subtitles/job.vtt']);
$assert($completedJob['status'] === 'completed' && $completedJob['output_url'] === 'https://cdn.example.com/subtitles/job.vtt', 'completed video job requires an HTTPS output URL');
$failedJob = VideoJobService::create($userId, 'https://youtu.be/abcDEF12345', 'secondary');
VideoJobService::markFailed((string) $failedJob['id'], 'Provider error');
Database::run('UPDATE video_jobs SET attempts = 3 WHERE id = ?', [$failedJob['id']]);
$retriedJob = VideoJobService::retry((string) $failedJob['id']);
$assert($retriedJob['status'] === 'queued' && (int) $retriedJob['progress'] === 0 && (int) $retriedJob['attempts'] === 0, 'admin retry starts a fresh dispatch attempt cycle');

$staleRequeueJob = VideoJobService::create($userId, 'https://youtu.be/staleAAA123', 'primary');
Database::run(
    "UPDATE video_jobs SET status = 'processing', progress = 40, attempts = 1, claimed_at = NOW() - INTERVAL '120 minutes' WHERE id = ?",
    [$staleRequeueJob['id']]
);
$recovery = VideoJobService::recoverStale(60);
$staleRequeued = Database::one('SELECT status, progress, attempts FROM video_jobs WHERE id = ?', [$staleRequeueJob['id']]);
$assert($recovery['requeued'] >= 1 && $staleRequeued['status'] === 'queued' && (int) $staleRequeued['progress'] === 0, 'stale video job is requeued after worker restart');

$staleFailedJob = VideoJobService::create($userId, 'https://youtu.be/staleBBB123', 'secondary');
Database::run(
    "UPDATE video_jobs SET status = 'processing', progress = 80, attempts = 3, claimed_at = NOW() - INTERVAL '120 minutes' WHERE id = ?",
    [$staleFailedJob['id']]
);
$failedRecovery = VideoJobService::recoverStale(60);
$staleFailed = Database::one('SELECT status, error_message FROM video_jobs WHERE id = ?', [$staleFailedJob['id']]);
$assert($failedRecovery['failed'] >= 1 && $staleFailed['status'] === 'failed', 'stale video job reaches failed state after maximum attempts');

echo "Đã chạy {$checks} PostgreSQL smoke checks.\n";
