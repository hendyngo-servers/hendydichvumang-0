# Chuẩn bị chuyển dữ liệu MySQL sang PostgreSQL

## Trạng thái

Ứng dụng production hiện sử dụng PostgreSQL qua `DATABASE_URL`. Migration cấu trúc đã có trong `database/schema.sql`, nhưng **chưa nhập dữ liệu từ MySQL**. Không thay `DATABASE_URL`, không tạo database mới và không chạy file MySQL trực tiếp trên PostgreSQL.

Việc chuyển dữ liệu chỉ được thực hiện sau khi có đủ:
1. File export MySQL của database `telegram_bot_db` (`.sql` hoặc `.sql.gz`) có cả cấu trúc lẫn dữ liệu.
2. Bản backup PostgreSQL production tạo ngay trước khi import (`.dump` custom format hoặc `.backup`).
3. Kết quả kiểm tra có thể đọc danh sách object trong backup PostgreSQL; nên thử restore backup vào một PostgreSQL staging riêng trước khi production migration.

Các ảnh Render và file log đã được chia sẻ trước đó không phải SQL dump hay PostgreSQL backup.

## 1. Export MySQL

Trên máy đang có MySQL, chạy:

```bash
mysqldump \
  --single-transaction \
  --quick \
  --skip-lock-tables \
  --default-character-set=utf8mb4 \
  --hex-blob \
  --no-create-db \
  telegram_bot_db > telegram_bot_db.sql

gzip -9 telegram_bot_db.sql
sha256sum telegram_bot_db.sql.gz > telegram_bot_db.sql.gz.sha256
```

Lệnh trên chỉ export một database và không ghi lệnh tạo database/use database vào đầu file. Nếu MySQL cũ dùng MyISAM hoặc bảng không hỗ trợ transaction, cần đặt hệ thống ở chế độ bảo trì/ngừng ghi trong lúc export để tránh snapshot không nhất quán.

Trước khi gửi file, kiểm tra dump có đúng bảng và dữ liệu cần chuyển:

```bash
zgrep -nE 'CREATE TABLE.*(users|orders|transactions)|INSERT INTO.*(users|orders|transactions)' telegram_bot_db.sql.gz | head -80
```

Không gửi file `.env`, bot token, database password, webhook secret, private key hoặc file cấu hình có credential.

## 2. Backup PostgreSQL hiện tại

Lấy Internal/External Database URL từ Render PostgreSQL → Connect. Không dán URL có mật khẩu vào chat. Trên máy được phép truy cập database:

```bash
pg_dump --format=custom --no-owner --no-acl \
  --file=hendy-postgres-pre-migration.dump \
  "$DATABASE_URL"

pg_restore --list hendy-postgres-pre-migration.dump > hendy-postgres-pre-migration.list
sha256sum hendy-postgres-pre-migration.dump > hendy-postgres-pre-migration.dump.sha256
```

Kiểm tra tệp backup có kích thước khác 0, lệnh `pg_restore --list` chạy thành công và checksum đã được ghi. Tốt nhất restore thử vào database staging riêng để kiểm tra khả năng phục hồi.

Không dùng `pg_dump --data-only` cho backup trước migration; cần full backup có schema và dữ liệu.

## 3. Ánh xạ dữ liệu

| MySQL cũ | PostgreSQL hiện tại | Quy tắc |
|---|---|---|
| `users.telegram_id` | `users.user_id` | Chuyển thành TEXT, giữ nguyên Telegram ID. |
| `users.username` | `users.username` | Giữ username, có thể rỗng. |
| `users.fullname` | `users.name` | Giữ tên hiển thị. |
| `users.vip_level` | `users.vip_level` | Giữ cấp VIP. |
| `users.total_recharge` | `users.total_deposited` | Giữ tổng nạp lịch sử; không cộng lại từ transactions. |
| `users.main_wallet` | `users.balance` | Số dư ví chính; không cộng transaction thêm lần nữa. |
| `users.nyc_wallet` | `users.nyc_wallet` | Ví NYC tách riêng. |
| `users.voucher_wallet` | `users.voucher_wallet` | Ví Voucher tách riêng. |
| `users.point_old` | `users.point_old` | Điểm cũ, không đổi sang tiền. |
| `orders.telegram_id` | `orders.user_id` | Giữ liên kết chủ sở hữu. |
| `orders.service_name` | `orders.product_name` | Lưu tên dịch vụ từ đơn cũ. |
| `transactions.telegram_id` | `transactions.user_id` | Giữ liên kết chủ sở hữu. |

### Quy tắc bảo toàn số dư

- Số dư đích phải khớp cột `main_wallet` của nguồn sau khi xử lý xung đột user ID.
- Không cộng tổng `transactions` vào `balance` nếu đã chuyển `main_wallet`; làm như vậy sẽ ghi có tiền hai lần.
- `total_recharge` được ánh xạ sang tổng nạp lịch sử, không cộng vào số dư hiện tại.
- Ví NYC và Voucher không được cộng chung vào ví chính.
- Không tạo giao dịch ledger mới có tác động số dư chỉ để mô phỏng lịch sử. Lịch sử cũ phải được gắn nhãn `legacy` và không được phát sinh lại số dư.
- Nếu user ID đã tồn tại ở PostgreSQL, không được ghi đè số dư production một cách tự động. Phải đưa bản ghi đó vào báo cáo xung đột để đối chiếu giữa MySQL, PostgreSQL và ledger.
- Giữ nguyên đơn/giao dịch trùng mã bằng mã tham chiếu nguồn có namespace `legacy-mysql`; không dùng các ID tự sinh có thể trùng dữ liệu mới.
- Cần xác nhận múi giờ của `created_at` nguồn trước khi đổi sang `TIMESTAMPTZ`. Không tự suy đoán UTC nếu MySQL đã ghi giờ địa phương.

## 4. Quy trình import an toàn

1. Xác minh SHA-256 của cả hai file, giải nén dump MySQL và kiểm tra đúng database/tables.
2. Restore dump MySQL vào một MySQL staging tạm; không restore lên PostgreSQL.
3. Chạy công cụ chuyển đổi ở chế độ dry-run để lập báo cáo: số user, order, transaction, dòng lỗi, user ID xung đột, tổng số dư ba ví và tổng nạp.
4. Đối chiếu báo cáo với file export và số liệu PostgreSQL trước khi import.
5. Restore backup PostgreSQL lên staging và thử import trên staging trước.
6. Đặt production vào chế độ bảo trì/ngừng ghi giao dịch, tạo một bản backup PostgreSQL mới ngay trước lần chạy thật.
7. Chỉ thực hiện import production sau khi có báo cáo đã được kiểm tra và có phương án rollback. Chạy một lần, ghi audit, không chạy lại một cách mù quáng.
8. Sau import, đối chiếu tổng user, đơn theo trạng thái, transaction theo loại, ba ví và tổng nạp. Kiểm tra một số Telegram ID đại diện và các đơn/giao dịch cũ.
9. Mở lại hệ thống khi đối soát đạt yêu cầu. Giữ backup trước migration theo chính sách lưu trữ.

Không xóa database PostgreSQL production để thay bằng database khác. Rollback phải khôi phục full backup vào một database đích được xác định rõ, rồi mới chuyển lại kết nối sau khi xác minh.

## 5. File cần gửi để bắt đầu

- `telegram_bot_db.sql.gz` (hoặc `.sql`) và checksum.
- `hendy-postgres-pre-migration.dump` (hoặc `.backup`) và checksum.
- Nếu có, tài liệu về múi giờ MySQL và trạng thái/ý nghĩa của từng loại giao dịch cũ.

Đây là dữ liệu ví và giao dịch thật; chỉ chia sẻ export/backup đã kiểm tra, không kèm credential. Khi nhận được các file này, bước đầu tiên là kiểm tra cấu trúc và lập báo cáo dry-run; không tự động ghi lên production ngay.
