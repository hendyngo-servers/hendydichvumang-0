<?php
declare(strict_types=1);

namespace Hendy;

use RuntimeException;

final class VoucherService
{
    public static function eligibleOrders(string $userId, int $limit = 100): array
    {
        $limit = max(1, min(100, $limit));
        return Database::all(
            "SELECT o.id, o.order_code, o.product_name, o.amount, o.status, o.created_at
             FROM orders o
             WHERE o.user_id = ?
               AND o.status IN ('pending', 'processing')
               AND o.created_at <= NOW() - INTERVAL '72 hours'
               AND NOT EXISTS (SELECT 1 FROM voucher_requests v WHERE v.order_id = o.id)
             ORDER BY o.created_at ASC
             LIMIT {$limit}",
            [$userId]
        );
    }

    public static function forUser(string $userId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        return Database::all(
            "SELECT v.id, v.order_id, v.order_code, v.reason, v.status, v.reward_amount,
                    v.admin_note, v.created_at, v.reviewed_at, o.product_name, o.amount AS order_amount
             FROM voucher_requests v
             JOIN orders o ON o.id = v.order_id
             WHERE v.user_id = ?
             ORDER BY v.created_at DESC
             LIMIT {$limit}",
            [$userId]
        );
    }

    public static function pending(int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        return Database::all(
            "SELECT v.*, o.product_name, o.amount AS order_amount, u.name, u.username
             FROM voucher_requests v
             JOIN orders o ON o.id = v.order_id
             JOIN users u ON u.user_id = v.user_id
             WHERE v.status = 'pending'
             ORDER BY v.created_at ASC
             LIMIT {$limit}"
        );
    }

    public static function create(string $userId, int $orderId, string $reason): array
    {
        if (!preg_match('/^\\d{5,20}$/', $userId) || $orderId < 1) {
            throw new RuntimeException('Thông tin đơn hàng không hợp lệ.');
        }
        return Database::transaction(function () use ($userId, $orderId, $reason): array {
            $order = Database::one(
                "SELECT id, order_code, product_name, amount, status
                 FROM orders
                 WHERE id = ? AND user_id = ?
                   AND status IN ('pending', 'processing')
                   AND created_at <= NOW() - INTERVAL '72 hours'
                 FOR UPDATE",
                [$orderId, $userId]
            );
            if ($order === null) {
                throw new RuntimeException('Đơn hàng chưa đủ 72 giờ, đã hoàn tất/hủy hoặc không thuộc tài khoản của bạn.');
            }
            $request = Database::one(
                "INSERT INTO voucher_requests (order_id, user_id, order_code, reason)
                 VALUES (?, ?, ?, ?)
                 ON CONFLICT (order_id) DO NOTHING
                 RETURNING *",
                [$orderId, $userId, $order['order_code'], $reason]
            );
            if ($request === null) {
                throw new RuntimeException('Đơn hàng này đã có yêu cầu voucher.');
            }
            return [...$request, 'product_name' => $order['product_name'], 'order_amount' => $order['amount']];
        });
    }

    public static function review(
        int $requestId,
        string $action,
        string $adminId,
        int $rewardAmount = 0,
        string $adminNote = ''
    ): array {
        if ($requestId < 1 || !in_array($action, ['approve', 'reject'], true)) {
            throw new RuntimeException('Thao tác duyệt voucher không hợp lệ.');
        }
        if ($action === 'approve' && ($rewardAmount < 1 || $rewardAmount > 500000000)) {
            throw new RuntimeException('Số voucher duyệt phải từ 1 đến 500.000.000 VNĐ.');
        }
        if ($action === 'reject') {
            $rewardAmount = 0;
        }

        return Database::transaction(function () use ($requestId, $action, $adminId, $rewardAmount, $adminNote): array {
            $current = Database::one('SELECT * FROM voucher_requests WHERE id = ? FOR UPDATE', [$requestId]);
            if ($current === null) {
                throw new RuntimeException('Không tìm thấy yêu cầu voucher.');
            }
            if ($current['status'] !== 'pending') {
                throw new RuntimeException('Yêu cầu voucher đã được xử lý trước đó.');
            }

            $status = $action === 'approve' ? 'approved' : 'rejected';
            $request = Database::one(
                "UPDATE voucher_requests
                 SET status = ?, reward_amount = ?, reviewed_by = ?, reviewed_at = NOW(),
                     admin_note = ?, updated_at = NOW()
                 WHERE id = ? AND status = 'pending'
                 RETURNING *",
                [$status, $rewardAmount, $adminId, $adminNote, $requestId]
            );
            if ($request === null) {
                throw new RuntimeException('Yêu cầu voucher vừa được xử lý bởi quản trị viên khác.');
            }

            $voucherBalance = null;
            if ($action === 'approve') {
                $updated = Database::one(
                    'UPDATE users SET voucher_wallet = voucher_wallet + ? WHERE user_id = ? RETURNING voucher_wallet',
                    [$rewardAmount, $current['user_id']]
                );
                if ($updated === null) {
                    throw new RuntimeException('Không tìm thấy tài khoản nhận voucher.');
                }
                $reference = 'voucher-request:' . $requestId;
                Database::run(
                    "INSERT INTO wallet_ledger (reference_code, user_id, amount, transaction_type, description, wallet_type)
                     VALUES (?, ?, ?, 'VOUCHER_CREDIT', ?, 'VOUCHER')",
                    [$reference, $current['user_id'], $rewardAmount, 'Voucher bồi hoàn đơn ' . $current['order_code']]
                );
                Database::run(
                    "INSERT INTO transactions (user_id, amount, type, reference_code, status, wallet_type)
                     VALUES (?, ?, 'VOUCHER_CREDIT', ?, 'paid', 'VOUCHER')",
                    [$current['user_id'], $rewardAmount, $reference]
                );
                $voucherBalance = (int) $updated['voucher_wallet'];
            }

            return [
                'request' => $request,
                'userId' => (string) $current['user_id'],
                'status' => $status,
                'voucherBalance' => $voucherBalance,
            ];
        });
    }
}
