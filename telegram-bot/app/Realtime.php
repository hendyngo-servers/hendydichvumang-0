<?php
declare(strict_types=1);

namespace Hendy;

use PDO;

final class Realtime
{
    private const CHANNEL = 'hendy_realtime_events';

    public static function publish(string $type, array $payload = [], string $audience = 'admin'): void
    {
        $type = preg_replace('/[^a-z0-9._-]/i', '', $type) ?: 'system.event';
        $encoded = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        // Commit the event and enqueue a PostgreSQL notification in the same statement.
        // The payload contains only the row id; event data remains in PostgreSQL.
        Database::run(
            "WITH inserted AS (
                INSERT INTO realtime_events (event_type, audience, payload, created_at)
                VALUES (?, ?, ?::jsonb, NOW())
                RETURNING id
            )
            SELECT pg_notify('hendy_realtime_events', id::text) FROM inserted",
            [substr($type, 0, 80), substr($audience, 0, 32), $encoded]
        );
    }

    public static function listen(): void
    {
        Database::run('LISTEN ' . self::CHANNEL);
    }

    /**
     * Drain pending PostgreSQL notifications without running a SQL query.
     * The WebSocket worker uses this to wake its event loop quickly.
     */
    public static function drainNotifications(int $limit = 100): int
    {
        $pdo = Database::connection();
        if (!method_exists($pdo, 'pgsqlGetNotify')) {
            return 0;
        }

        $limit = max(1, min(1000, $limit));
        $received = 0;
        while ($received < $limit) {
            $notification = $pdo->pgsqlGetNotify(PDO::FETCH_ASSOC, 0);
            if ($notification === false) {
                break;
            }
            if (is_array($notification) && ($notification['message'] ?? '') !== '') {
                $received++;
            }
        }
        return $received;
    }

    public static function issueTicket(string $userId, string $audience = 'users'): string
    {
        if (!preg_match('/^[0-9]{5,20}$/', $userId) || !in_array($audience, ['admin', 'users'], true)) {
            throw new RuntimeException('Realtime ticket không hợp lệ.');
        }
        if ($audience === 'admin' && !TelegramAuth::isAdmin($userId)) {
            throw new RuntimeException('Tài khoản không có quyền quản trị realtime.');
        }

        $ticket = bin2hex(random_bytes(32));
        Database::run(
            "INSERT INTO realtime_tickets (ticket_hash, admin_user_id, audience, expires_at)
             VALUES (?, ?, ?, NOW() + INTERVAL '60 seconds')",
            [hash('sha256', $ticket), $userId, $audience]
        );
        return $ticket;
    }

    public static function issueAdminTicket(string $adminId): string
    {
        return self::issueTicket($adminId, 'admin');
    }

    /**
     * Consume a one-time ticket. Returned identity is established by the
     * authenticated HTTP API, never by fields supplied in a WebSocket message.
     */
    public static function consumeTicket(string $ticket): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $ticket)) {
            return null;
        }

        $row = Database::one(
            'UPDATE realtime_tickets
             SET consumed_at = NOW()
             WHERE ticket_hash = ? AND consumed_at IS NULL AND expires_at > NOW()
             RETURNING admin_user_id, audience',
            [hash('sha256', $ticket)]
        );
        if ($row === null) {
            return null;
        }

        $userId = (string) $row['admin_user_id'];
        $audience = (string) ($row['audience'] ?? 'admin');
        if (!preg_match('/^[0-9]{5,20}$/', $userId)
            || !in_array($audience, ['admin', 'users'], true)
            || ($audience === 'admin' && !TelegramAuth::isAdmin($userId))) {
            return null;
        }
        return ['userId' => $userId, 'audience' => $audience];
    }

    public static function consumeAdminTicket(string $ticket): bool
    {
        $identity = self::consumeTicket($ticket);
        return $identity !== null
            && $identity['audience'] === 'admin'
            && TelegramAuth::isAdmin($identity['userId']);
    }
}
