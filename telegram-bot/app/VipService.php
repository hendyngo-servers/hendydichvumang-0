<?php
declare(strict_types=1);

namespace Hendy;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** Daily VIP attendance; rewards are internal points, never cash. */
final class VipService
{
    private const LEVELS = [
        ['level' => 0, 'name' => 'Thành viên', 'threshold' => 0],
        ['level' => 1, 'name' => 'VIP 1', 'threshold' => 500000],
        ['level' => 2, 'name' => 'VIP 2', 'threshold' => 2000000],
        ['level' => 3, 'name' => 'VIP 3', 'threshold' => 5000000],
    ];

    public static function levels(): array
    {
        return self::LEVELS;
    }

    public static function checkin(string $userId): array
    {
        self::validateUserId($userId);
        $date = self::localDate();

        return Database::transaction(function () use ($userId, $date): array {
            $user = Database::one('SELECT point_old FROM users WHERE user_id = ? FOR UPDATE', [$userId]);
            if ($user === null) {
                throw new RuntimeException('Không tìm thấy tài khoản. Hãy mở Bot bằng /start trước.');
            }

            $existing = Database::one(
                'SELECT points_awarded, streak_count FROM vip_checkins WHERE user_id = ? AND checkin_date = ?',
                [$userId, $date]
            );
            if ($existing !== null) {
                return [
                    'alreadyCheckedIn' => true,
                    'checkinDate' => $date,
                    'pointsAwarded' => (int) $existing['points_awarded'],
                    'pointsTotal' => (int) $user['point_old'],
                    'streak' => (int) $existing['streak_count'],
                ];
            }

            $reward = self::configuredPoints();
            $last = Database::one(
                'SELECT checkin_date, streak_count FROM vip_checkins WHERE user_id = ? ORDER BY checkin_date DESC LIMIT 1',
                [$userId]
            );
            $yesterday = (new DateTimeImmutable('yesterday', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d');
            $streak = $last !== null && (string) $last['checkin_date'] === $yesterday
                ? min(36500, (int) $last['streak_count'] + 1)
                : 1;

            $record = Database::one(
                'INSERT INTO vip_checkins (user_id, checkin_date, points_awarded, streak_count) VALUES (?, ?, ?, ?) ' .
                'ON CONFLICT (user_id, checkin_date) DO NOTHING RETURNING checkin_date',
                [$userId, $date, $reward, $streak]
            );
            if ($record === null) {
                $existing = Database::one(
                    'SELECT points_awarded, streak_count FROM vip_checkins WHERE user_id = ? AND checkin_date = ?',
                    [$userId, $date]
                );
                return [
                    'alreadyCheckedIn' => true,
                    'checkinDate' => $date,
                    'pointsAwarded' => (int) ($existing['points_awarded'] ?? 0),
                    'pointsTotal' => (int) $user['point_old'],
                    'streak' => (int) ($existing['streak_count'] ?? 1),
                ];
            }

            $pointsTotal = (int) $user['point_old'];
            if ($reward > 0) {
                $updated = Database::one(
                    'UPDATE users SET point_old = point_old + ? WHERE user_id = ? RETURNING point_old',
                    [$reward, $userId]
                );
                if ($updated === null) {
                    throw new RuntimeException('Không thể cập nhật điểm thành viên.');
                }
                $pointsTotal = (int) $updated['point_old'];
                Database::run(
                    "INSERT INTO transactions (user_id, amount, type, reference_code, status, wallet_type) VALUES (?, ?, 'VIP_CHECKIN', ?, 'paid', 'POINT')",
                    [$userId, $reward, 'vip-checkin:' . $userId . ':' . $date]
                );
            }

            return [
                'alreadyCheckedIn' => false,
                'checkinDate' => $date,
                'pointsAwarded' => $reward,
                'pointsTotal' => $pointsTotal,
                'streak' => $streak,
            ];
        });
    }

    public static function status(string $userId): array
    {
        self::validateUserId($userId);
        $user = Database::one(
            'SELECT vip_level, total_deposited, point_old FROM users WHERE user_id = ?',
            [$userId]
        );
        if ($user === null) {
            throw new RuntimeException('Không tìm thấy tài khoản thành viên.');
        }

        $date = self::localDate();
        $checkin = Database::one(
            'SELECT points_awarded, streak_count FROM vip_checkins WHERE user_id = ? AND checkin_date = ?',
            [$userId, $date]
        );
        $level = max(0, (int) $user['vip_level']);
        $thresholds = array_column(self::LEVELS, 'threshold', 'level');
        $totalDeposited = (int) $user['total_deposited'];
        $currentThreshold = (int) ($thresholds[$level] ?? 0);
        $nextThreshold = $level >= 3 ? null : (int) ($thresholds[$level + 1] ?? 5000000);
        $progressPercent = $nextThreshold === null
            ? 100
            : (int) max(0, min(100, floor((($totalDeposited - $currentThreshold) / max(1, $nextThreshold - $currentThreshold)) * 100)));

        return [
            'currentLevel' => $level,
            'totalDeposited' => (int) $user['total_deposited'],
            'pointOld' => (int) $user['point_old'],
            'nextThreshold' => $nextThreshold,
            'progressPercent' => $progressPercent,
            'amountToNext' => $nextThreshold === null ? 0 : max(0, $nextThreshold - (int) $user['total_deposited']),
            'checkedInToday' => $checkin !== null,
            'checkinDate' => $date,
            'checkinPoints' => self::configuredPoints(),
            'todayPoints' => (int) ($checkin['points_awarded'] ?? 0),
            'streak' => (int) ($checkin['streak_count'] ?? 0),
            'levels' => self::LEVELS,
        ];
    }

    private static function configuredPoints(): int
    {
        $value = Database::value("SELECT value FROM app_settings WHERE key = 'vip_checkin_points'");
        return $value === null || $value === '' ? 1 : max(0, min(1000, (int) $value));
    }

    private static function localDate(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d');
    }

    private static function validateUserId(string $userId): void
    {
        if (!preg_match('/\\A[0-9]{5,20}\\z/', $userId)) {
            throw new RuntimeException('Telegram ID không hợp lệ.');
        }
    }
}
