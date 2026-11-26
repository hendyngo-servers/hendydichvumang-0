# Customer Center và tích hợp schema MySQL cũ

## Hệ quản trị dữ liệu đang chạy

Ứng dụng hiện chạy PHP 8.3 với PostgreSQL thông qua `DATABASE_URL` và `Hendy\\Database`. Vì vậy không chạy trực tiếp các câu lệnh MySQL `CREATE DATABASE IF NOT EXISTS ... CHARACTER SET utf8mb4`, `USE telegram_bot_db` hoặc file kết nối `mysql:` trên Render. Không tạo database MySQL song song cho cùng bot: làm như vậy sẽ khiến số dư, đơn hàng và lịch sử nạp bị tách thành hai nguồn dữ liệu.

Schema mới được thêm dưới dạng migration PostgreSQL idempotent trong `database/schema.sql`; quá trình khởi động/migration hiện tại sẽ tự thêm cột và bảng còn thiếu. Giữ nguyên PostgreSQL và `DATABASE_URL` hiện có.

## Ánh xạ các cột cũ

| Schema MySQL được cung cấp | Trường PostgreSQL của Hendy Bot | Ghi chú |
|---|---|---|
| `users.telegram_id` | `users.user_id` | Lưu dạng TEXT để dùng thống nhất với API/Bot. |
| `users.fullname` | `users.name` | Lấy tên thật từ Telegram initData/update. |
| `users.username` | `users.username` | Đồng bộ từ Telegram. |
| `users.vip_level` | `users.vip_level` | Giữ nguyên. |
| `users.total_recharge` | `users.total_deposited` | Tổng tiền nạp đã được ghi có. |
| `users.main_wallet` | `users.balance` | Ví chính hiện tại; không tạo cột số dư thứ hai. |
| `users.nyc_wallet` | `users.nyc_wallet` | Ví phụ riêng; chỉ hiển thị, chưa tự phát sinh tín dụng. |
| `users.voucher_wallet` | `users.voucher_wallet` | Chỉ cộng khi Admin duyệt Voucher 72H. |
| `users.point_old` | `users.point_old` | Trường điểm cũ, được giữ lại. |
| `orders.telegram_id` | `orders.user_id` | Chủ sở hữu đơn hàng. |
| `orders.service_name` | `orders.product_name` | Tên dịch vụ/sản phẩm tại thời điểm mua. |
| `transactions.telegram_id` | `transactions.user_id` | Chủ sở hữu giao dịch. |

`orders.status` ở PostgreSQL là TEXT để tương thích các trạng thái nghiệp vụ đang dùng. Báo cáo Customer Center đếm riêng đang xử lý, hoàn tất, đã hủy và hoàn tiền; giao dịch hoàn tiền cũng được đối chiếu theo mã đơn trong bảng `transactions`.

## Tính năng đã tích hợp

- Mini App **Trung tâm khách hàng** hiển thị hồ sơ Telegram, VIP, tổng nạp, ví chính, ví NYC, ví Voucher và điểm cũ.
- Thống kê đơn hàng: tổng đơn, đang xử lý, hoàn tất, đã hủy và đã hoàn tiền; kèm 5 đơn gần nhất.
- Ticket CSKH hỗ trợ 2 nhóm: `dinh_lam` (Hỗ trợ dính lạm dụng 60%) và `support` (Ticket hỗ trợ chung). Admin xem loại ticket trong tab CSKH.
- **Voucher 72H** chỉ hiển thị đơn thuộc đúng người dùng, trạng thái `pending` hoặc `processing`, đã cũ ít nhất 72 giờ và chưa có yêu cầu voucher. Mỗi đơn chỉ tạo được một yêu cầu.
- Admin xử lý trong tab **Voucher 72H**. Khi duyệt, Admin phải nhập số tiền từ 1 đến 500.000.000 VNĐ. Tiền chỉ cộng vào `voucher_wallet`, đồng thời ghi `wallet_ledger.wallet_type='VOUCHER'` và `transactions.wallet_type='VOUCHER'`; không cộng ví chính.
- Tạo yêu cầu hoặc bị từ chối không làm thay đổi bất kỳ số dư nào. Cơ chế khóa hàng và unique order_id chặn yêu cầu/duyệt trùng.

## Xác thực

Không dùng Telegram ID mẫu hay giá trị hard-code trong PHP. Mini App gửi Telegram `initData`; backend xác minh HMAC bằng token bot và lấy ID từ payload hợp lệ. Không chấp nhận `?token=` tự khai báo hoặc Telegram ID do trình duyệt nhập tay.

## Nếu cần nhập dữ liệu đang ở MySQL cũ

Đây là mapping schema, không phải lệnh tự chuyển dữ liệu. Trước khi nhập dữ liệu thật cần export MySQL, sao lưu PostgreSQL, mapping ID sang TEXT, giữ mã đơn/mã giao dịch duy nhất, kiểm tra các khoản tiền, ví riêng, referral và lịch sử hoàn tiền. Chạy import trước trên staging; đối soát tổng số dư với tổng ledger và số đơn/giao dịch trước khi đưa vào production. Tuyệt đối không cộng lại các giao dịch đã được nhập hoặc ghi có trước đây.
