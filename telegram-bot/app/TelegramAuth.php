<?php
declare(strict_types=1);

namespace Hendy;

use RuntimeException;

final class TelegramAuth
{
    public static function token(string $botKey = 'primary'): string
    {
        // This deployment intentionally supports exactly one production bot.
        // Legacy BOT2_TOKEN values may remain in Render but are never read or used.
        if ($botKey !== 'primary') {
            return '';
        }
        return trim((string) Env::get('BOT1_TOKEN', ''));
    }

    /**
     * Return a persistent random 256-bit Telegram webhook secret.
     *
     * An explicitly configured secret remains supported as an operator override.
     * Otherwise one random secret is kept in PostgreSQL per bot. It is regenerated
     * automatically when that bot's token fingerprint changes. The raw secret is
     * only read by the backend for setWebhook() and request verification.
     */
    public static function webhookSecret(string $botKey = 'primary'): string
    {
        $key = $botKey === 'secondary' ? 'secondary' : 'primary';
        $token = self::token($key);
        if ($token === '') {
            return '';
        }

        $name = $key === 'secondary'
            ? 'TELEGRAM_SECOND_WEBHOOK_SECRET'
            : 'TELEGRAM_WEBHOOK_SECRET';
        $configured = trim((string) Env::get($name, ''));
        $overrideIsValid = $configured !== '' && preg_match('~^[A-Za-z0-9_-]{32,256}$~D', $configured) === 1;
        if ($configured !== '' && !$overrideIsValid) {
            error_log($name . ' không hợp lệ; backend sẽ tạo webhook secret ngẫu nhiên riêng cho bot.');
        }

        $fingerprint = hash('sha256', $token);
        $overrideFingerprint = $overrideIsValid ? hash('sha256', $configured) : '';
        // Use one atomic upsert instead of a multi-statement transaction. This
        // avoids carrying an aborted transaction on pooled PostgreSQL connections.
        // ON CONFLICT also serializes concurrent startups and preserves the first
        // valid secret whenever the bot token and override have not changed.
        $candidate = $overrideIsValid ? $configured : bin2hex(random_bytes(32));
        $secret = Database::value(
            'INSERT INTO telegram_webhook_secrets (bot_key, token_fingerprint, secret_value, override_fingerprint) ' .
            'VALUES (?, ?, ?, ?) ' .
            'ON CONFLICT (bot_key) DO UPDATE SET ' .
            'token_fingerprint = EXCLUDED.token_fingerprint, ' .
            'secret_value = CASE ' .
            'WHEN telegram_webhook_secrets.token_fingerprint = EXCLUDED.token_fingerprint ' .
            'AND telegram_webhook_secrets.override_fingerprint = EXCLUDED.override_fingerprint ' .
            'AND char_length(telegram_webhook_secrets.secret_value) BETWEEN 32 AND 256 AND telegram_webhook_secrets.secret_value !~ \'[^A-Za-z0-9_-]\' ' .
            'THEN telegram_webhook_secrets.secret_value ELSE EXCLUDED.secret_value END, ' .
            'override_fingerprint = EXCLUDED.override_fingerprint, updated_at = NOW() ' .
            'RETURNING secret_value',
            [$key, $fingerprint, $candidate, $overrideFingerprint]
        );

        if (!is_string($secret) || preg_match('~^[A-Za-z0-9_-]{32,256}$~D', $secret) !== 1) {
            throw new RuntimeException('Không thể tạo webhook secret an toàn cho ' . $key . '.');
        }
        return $secret;
    }

    /**
     * Return an independent persistent signing key for each bot's browser sessions.
     * LEGACY_SESSION_SECRET remains an optional operator override; when set, it is
     * domain-separated by bot key so the two bots never share the same derived key.
     */
    public static function websiteSessionSecret(string $botKey = 'primary'): string
    {
        $key = $botKey === 'secondary' ? 'secondary' : 'primary';
        $override = trim((string) Env::get('LEGACY_SESSION_SECRET', ''));
        if ($override !== '') {
            if (strlen($override) < 32) {
                throw new RuntimeException('LEGACY_SESSION_SECRET phải có ít nhất 32 ký tự.');
            }
            return hash_hmac('sha256', 'hendy-website-session:' . $key, $override);
        }

        // The signing key is read on every authenticated API request. Read the
        // already-provisioned key first so normal Admin traffic does not UPDATE
        // the same PostgreSQL row on every page/API request.
        $existing = Database::value(
            'SELECT secret_value FROM website_session_secrets WHERE bot_key = ?',
            [$key]
        );
        if (is_string($existing) && preg_match('~^[A-Za-z0-9_-]{32,256}$~D', $existing) === 1) {
            return $existing;
        }

        // First creation or repair remains atomic; concurrent workers converge on
        // one valid secret and never rotate a key that is already valid.
        $candidate = bin2hex(random_bytes(32));
        $stored = Database::value(
            'INSERT INTO website_session_secrets (bot_key, secret_value) VALUES (?, ?) ' .
            'ON CONFLICT (bot_key) DO UPDATE SET ' .
            'secret_value = CASE ' .
            'WHEN char_length(website_session_secrets.secret_value) BETWEEN 32 AND 256 AND website_session_secrets.secret_value !~ \'[^A-Za-z0-9_-]\' ' .
            'THEN website_session_secrets.secret_value ELSE EXCLUDED.secret_value END, ' .
            'updated_at = NOW() RETURNING secret_value',
            [$key, $candidate]
        );
        if (!is_string($stored) || preg_match('~^[A-Za-z0-9_-]{32,256}$~D', $stored) !== 1) {
            throw new RuntimeException('Không thể tạo khóa phiên website riêng cho bot ' . $key . '.');
        }
        return $stored;
    }

    public static function adminIds(): array
    {
        $configured = [
            trim((string) Env::get('ADMIN_ID', '')),
            ...array_map('trim', explode(',', (string) Env::get('ADMIN_IDS', ''))),
        ];
        $ids = [];
        foreach ($configured as $id) {
            if (preg_match('/^[0-9]{5,20}$/', $id) && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    public static function adminId(): string
    {
        return self::adminIds()[0] ?? '';
    }

    public static function isAdmin(string $telegramId): bool
    {
        return $telegramId !== '' && in_array($telegramId, self::adminIds(), true);
    }

    public static function safeEquals(string $left, string $right): bool
    {
        return $left !== '' && $right !== '' && hash_equals($right, $left);
    }

    public static function validateInitData(string $initData, string $botToken): array
    {
        if ($initData === '' || strlen($initData) > 8192 || $botToken === '') {
            throw new RuntimeException('Thiếu dữ liệu xác thực Telegram.');
        }

        parse_str($initData, $params);
        $hash = strtolower((string) ($params['hash'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new RuntimeException('Chữ ký Telegram không hợp lệ.');
        }

        $authDate = filter_var($params['auth_date'] ?? null, FILTER_VALIDATE_INT);
        $now = time();
        $maxAge = max(60, min(86400, Env::int('TELEGRAM_INIT_DATA_MAX_AGE_SECONDS', 3600)));
        if ($authDate === false || $authDate > $now + 60 || $now - $authDate > $maxAge) {
            throw new RuntimeException('Phiên Telegram đã hết hạn.');
        }

        unset($params['hash']);
        ksort($params, SORT_STRING);
        $pairs = [];
        foreach ($params as $key => $value) {
            if (is_scalar($value)) {
                $pairs[] = $key . '=' . (string) $value;
            }
        }
        $checkString = implode("\n", $pairs);

        $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $calculated = hash_hmac('sha256', $checkString, $secret);
        if (!hash_equals($calculated, $hash)) {
            throw new RuntimeException('Không thể xác minh chữ ký Telegram.');
        }

        $user = json_decode((string) ($params['user'] ?? ''), true);
        if (!is_array($user) || !preg_match('/^\d{5,20}$/', (string) ($user['id'] ?? ''))) {
            throw new RuntimeException('Không tìm thấy Telegram user ID hợp lệ.');
        }
        return $user;
    }

    private static function decodeWebsiteSession(string $token, string $requestedBotKey): array
    {
        $secret = self::websiteSessionSecret($requestedBotKey);
        if (strlen($token) > 4096) {
            throw new RuntimeException('Phiên website không hợp lệ.');
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Phiên website không hợp lệ.');
        }
        [$headerPart, $payloadPart, $signaturePart] = $parts;
        $decode = static function (string $value): string|false {
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $value)) return false;
            return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        };
        $headerJson = $decode($headerPart);
        $payloadJson = $decode($payloadPart);
        $signature = $decode($signaturePart);
        if ($headerJson === false || $payloadJson === false || $signature === false) {
            throw new RuntimeException('Chữ ký phiên website không hợp lệ.');
        }
        $header = json_decode($headerJson, true);
        $payload = json_decode($payloadJson, true);
        $audience = (string) ($payload['aud'] ?? '');
        if (!is_array($header) || !is_array($payload) || ($header['alg'] ?? '') !== 'HS256'
            || !in_array($audience, ['hendy-legacy-account', 'hendy-admin-dashboard'], true)) {
            throw new RuntimeException('Định dạng phiên website không hợp lệ.');
        }
        $expected = hash_hmac('sha256', $headerPart . '.' . $payloadPart, $secret, true);
        if (!hash_equals($expected, $signature)) {
            throw new RuntimeException('Chữ ký phiên website không hợp lệ.');
        }
        $userId = (string) ($payload['sub'] ?? '');
        $botKey = (string) ($payload['bot'] ?? '');
        $expires = (int) ($payload['exp'] ?? 0);
        if (!preg_match('/^[0-9]{5,20}$/', $userId) || $botKey !== 'primary'
            || $expires <= time() || $expires > time() + 43260 || $botKey !== $requestedBotKey) {
            throw new RuntimeException('Phiên website đã hết hạn hoặc không đúng bot.');
        }
        $member = Database::one('SELECT user_id, name, username FROM users WHERE user_id = ?', [$userId]);
        if ($member === null) {
            throw new RuntimeException('Không tìm thấy hồ sơ thành viên.');
        }
        $isAdmin = self::isAdmin($userId);
        if ($audience === 'hendy-admin-dashboard' && !$isAdmin) {
            throw new RuntimeException('Phiên Admin không còn được phép truy cập.');
        }
        return [
            'id' => (string) $member['user_id'],
            'first_name' => (string) ($member['name'] ?? ''),
            'username' => (string) ($member['username'] ?? ''),
            '_bot_key' => $botKey,
            '_is_admin' => $isAdmin,
            '_session_scope' => $audience === 'hendy-admin-dashboard' ? 'admin' : 'customer',
        ];
    }

    public static function authenticateRequest(): array
    {
        $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        $cookieSession = trim((string) ($_COOKIE['hendy_web_session'] ?? ''));

        if ($cookieSession !== '' && !str_starts_with($authorization, 'tma ')) {
            $requestedBotKey = trim((string) ($_SERVER['HTTP_X_TELEGRAM_BOT_KEY'] ?? 'primary'));
            if ($requestedBotKey !== 'primary') {
                throw new RuntimeException('Bot Key không hợp lệ.');
            }
            return self::decodeWebsiteSession($cookieSession, $requestedBotKey);
        }

        $requestedBotKey = trim((string) ($_SERVER['HTTP_X_TELEGRAM_BOT_KEY'] ?? 'primary'));
        if ($requestedBotKey !== 'primary') {
            throw new RuntimeException('Bot Key không hợp lệ.');
        }
        $initData = str_starts_with($authorization, 'tma ')
            ? substr($authorization, 4)
            : (string) ($_SERVER['HTTP_X_TELEGRAM_INIT_DATA'] ?? '');

        $token = self::token($requestedBotKey);
        if ($token === '') {
            throw new RuntimeException('Bot được yêu cầu chưa được cấu hình.');
        }

        $user = self::validateInitData($initData, $token);
        $user['_bot_key'] = $requestedBotKey;
        $user['_is_admin'] = self::isAdmin((string) ($user['id'] ?? ''));
        return $user;
    }
}
