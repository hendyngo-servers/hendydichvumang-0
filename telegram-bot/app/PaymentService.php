<?php
declare(strict_types=1);

namespace Hendy;

use RuntimeException;

/**
 * Manual bank-transfer requests. Creating a request never credits a wallet.
 * Only an authenticated administrator can approve it after checking the bank
 * statement. A unique ledger reference makes the credit idempotent.
 */
final class PaymentService
{
    public static function create(
        string $userId,
        int $amount,
        string $botKey,
        string $bankId,
        string $accountNumber,
        string $accountHolder
    ): array {
        if (!preg_match('~\A[0-9]{5,20}\z~', $userId) || $amount < 10000 || $amount > 500000000) {
            throw new RuntimeException('Yêu cầu nạp tiền không hợp lệ.');
        }
        if (!in_array($botKey, ['primary', 'secondary'], true)) {
            throw new RuntimeException('Bot tạo yêu cầu không hợp lệ.');
        }
        if ($bankId === '' || $accountNumber === '' || $accountHolder === '') {
            throw new RuntimeException('Chưa cấu hình thông tin tài khoản nhận tiền.');
        }

        return Database::transaction(function () use ($userId, $amount, $botKey, $bankId, $accountNumber, $accountHolder): array {
            Database::run('INSERT INTO users (user_id) VALUES (?) ON CONFLICT (user_id) DO NOTHING', [$userId]);
            $user = Database::one('SELECT user_id FROM users WHERE user_id = ? FOR UPDATE', [$userId]);
            if ($user === null) {
                throw new RuntimeException('Không tìm thấy tài khoản. Hãy gửi /start trước khi tạo yêu cầu nạp.');
            }
            $pending = (int) Database::value(
                "SELECT COUNT(*) FROM payment_requests WHERE user_id = ? AND status = 'pending'",
                [$userId]
            );
            if ($pending > 0) {
                throw new RuntimeException('Bạn đang có yêu cầu nạp chờ duyệt. Hãy chờ Admin xử lý trước khi tạo yêu cầu mới.');
            }
            $recent = (int) Database::value(
                "SELECT COUNT(*) FROM payment_requests WHERE user_id = ? AND created_at >= NOW() - INTERVAL '1 hour'",
                [$userId]
            );
            if ($recent >= 5) {
                throw new RuntimeException('Bạn đã tạo quá nhiều yêu cầu nạp trong 1 giờ. Vui lòng thử lại sau.');
            }

            $paymentId = 'HD' . strtoupper(bin2hex(random_bytes(5)));
            $transferContent = 'NAP ' . $paymentId;
            $row = Database::one(
                "INSERT INTO payment_requests (payment_id, user_id, amount, bot_key, transfer_content, bank_id, account_number, account_holder) " .
                "VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING payment_id, user_id, amount, status, transfer_content, bank_id, account_number, account_holder, created_at",
                [$paymentId, $userId, $amount, $botKey, $transferContent, $bankId, $accountNumber, $accountHolder]
            );
            if ($row === null) {
                throw new RuntimeException('Không thể tạo yêu cầu nạp tiền.');
            }
            return $row;
        });
    }

    public static function pending(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        return Database::all(
            "SELECT p.payment_id, p.user_id, p.amount, p.bot_key, p.transfer_content, p.bank_id, p.account_number, p.account_holder, p.created_at, " .
            "u.name, u.username FROM payment_requests p LEFT JOIN users u ON u.user_id = p.user_id " .
            "WHERE p.status = 'pending' ORDER BY p.created_at ASC LIMIT " . $limit
        );
    }

    public static function recent(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        return Database::all(
            "SELECT payment_id, user_id, amount, status, transfer_content, admin_note, created_at, reviewed_at " .
            "FROM payment_requests ORDER BY created_at DESC LIMIT " . $limit
        );
    }

    public static function forUser(string $userId, int $limit = 10): array
    {
        $limit = max(1, min(30, $limit));
        return Database::all(
            'SELECT payment_id, amount, status, bot_key, transfer_content, bank_id, account_number, account_holder, admin_note, created_at, reviewed_at FROM payment_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT ' . $limit,
            [$userId]
        );
    }

    public static function stats(): array
    {
        return [
            'pending' => (int) Database::value("SELECT COUNT(*) FROM payment_requests WHERE status = 'pending'"),
            'approvedToday' => (int) Database::value("SELECT COUNT(*) FROM payment_requests WHERE status = 'approved' AND reviewed_at >= CURRENT_DATE"),
            'approvedAmountToday' => (int) Database::value("SELECT COALESCE(SUM(amount), 0) FROM payment_requests WHERE status = 'approved' AND reviewed_at >= CURRENT_DATE"),
        ];
    }

    public static function approve(string $paymentId, string $adminId): array
    {
        return Database::transaction(function () use ($paymentId, $adminId): array {
            $request = Database::one(
                'SELECT * FROM payment_requests WHERE payment_id = ? FOR UPDATE',
                [$paymentId]
            );
            if ($request === null) {
                throw new RuntimeException('Không tìm thấy yêu cầu nạp tiền.');
            }
            if ($request['status'] !== 'pending') {
                throw new RuntimeException('Yêu cầu này đã được xử lý: ' . $request['status'] . '.');
            }

            $userId = (string) $request['user_id'];
            $amount = (int) $request['amount'];
            $ledgerReference = 'payment:' . $paymentId;
            $insert = Database::run(
                "INSERT INTO wallet_ledger (reference_code, user_id, amount, transaction_type, description) " .
                "VALUES (?, ?, ?, 'DEPOSIT', ?) ON CONFLICT (reference_code) DO NOTHING RETURNING id",
                [$ledgerReference, $userId, $amount, 'Admin xác nhận chuyển khoản ' . $paymentId]
            );
            if ($insert->rowCount() !== 1) {
                throw new RuntimeException('Giao dịch đã được ghi sổ trước đó; yêu cầu không được cộng ví lần hai.');
            }

            $user = Database::one(
                'UPDATE users SET balance = balance + ?, total_deposited = total_deposited + ?, ' .
                'vip_level = CASE WHEN total_deposited + ? >= 5000000 THEN 3 WHEN total_deposited + ? >= 2000000 THEN 2 WHEN total_deposited + ? >= 500000 THEN 1 ELSE 0 END ' .
                'WHERE user_id = ? RETURNING balance, total_deposited, vip_level, ref_by',
                [$amount, $amount, $amount, $amount, $amount, $userId]
            );
            if ($user === null) {
                throw new RuntimeException('Không tìm thấy ví của thành viên.');
            }

            Database::run(
                "INSERT INTO transactions (user_id, amount, type, reference_code, status) VALUES (?, ?, 'DEPOSIT', ?, 'paid')",
                [$userId, $amount, $paymentId]
            );

            $commissionRate = max(0.0, min(30.0, (float) (Database::value("SELECT value FROM app_settings WHERE key = 'referral_commission_percent'") ?? 0)));
            $referrer = (string) ($user['ref_by'] ?? '');
            $reward = (int) floor($amount * $commissionRate / 100);
            if ($referrer !== '' && $reward > 0) {
                $referralLedger = 'referral:' . $paymentId;
                $referrerUser = Database::one('SELECT user_id FROM users WHERE user_id = ? FOR UPDATE', [$referrer]);
                if ($referrerUser !== null) {
                    $referralInsert = Database::run(
                        "INSERT INTO wallet_ledger (reference_code, user_id, amount, transaction_type, description) VALUES (?, ?, ?, 'REFERRAL_REWARD', ?) ON CONFLICT (reference_code) DO NOTHING RETURNING id",
                        [$referralLedger, $referrer, $reward, 'Hoa hồng từ giao dịch ' . $paymentId]
                    );
                    if ($referralInsert->rowCount() === 1) {
                        Database::run('UPDATE users SET balance = balance + ? WHERE user_id = ?', [$reward, $referrer]);
                        Database::run(
                            "INSERT INTO transactions (user_id, amount, type, reference_code, status) VALUES (?, ?, 'REFERRAL_REWARD', ?, 'paid')",
                            [$referrer, $reward, $paymentId]
                        );
                    }
                }
            }

            Database::run(
                "UPDATE payment_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW() WHERE payment_id = ?",
                [$adminId, $paymentId]
            );

            return [
                'paymentId' => $paymentId,
                'telegramId' => $userId,
                'botKey' => (string) $request['bot_key'],
                'amountVnd' => $amount,
                'newBalance' => (int) $user['balance'],
                'vipLevel' => (int) $user['vip_level'],
                'referralReward' => $reward,
            ];
        });
    }

    public static function reject(string $paymentId, string $adminId, string $note = ''): array
    {
        return Database::transaction(function () use ($paymentId, $adminId, $note): array {
            $request = Database::one('SELECT * FROM payment_requests WHERE payment_id = ? FOR UPDATE', [$paymentId]);
            if ($request === null) {
                throw new RuntimeException('Không tìm thấy yêu cầu nạp tiền.');
            }
            if ($request['status'] !== 'pending') {
                throw new RuntimeException('Yêu cầu này đã được xử lý: ' . $request['status'] . '.');
            }
            Database::run(
                "UPDATE payment_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), admin_note = ?, updated_at = NOW() WHERE payment_id = ?",
                [$adminId, mb_substr(trim($note), 0, 500), $paymentId]
            );
            return [
                'paymentId' => $paymentId,
                'telegramId' => (string) $request['user_id'],
                'botKey' => (string) $request['bot_key'],
                'amountVnd' => (int) $request['amount'],
                'status' => 'rejected',
            ];
        });
    }
}
