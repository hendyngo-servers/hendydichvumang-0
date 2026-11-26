# Tích hợp Telegram Bot vào hendydichvumang

## Mô hình trong repository

- Website danh mục ở repository root, dùng PHP + MySQL và Dockerfile gốc.
- Telegram Bot tại `telegram-bot/`, dùng PHP + PostgreSQL, Dockerfile và runtime riêng.
- Root `render.yaml` chỉ khai báo service Bot mới; không khai báo lại hoặc thay cấu hình Website đang chạy.
- Production chỉ cấu hình một bot: BOT1. BOT2 để trống.

## Tạo Render Web Service cho Bot

Có thể tạo service từ Blueprint tại root `render.yaml` hoặc tạo Web Service thủ công:

| Thuộc tính | Giá trị |
|---|---|
| Repository | `hendyngo-servers/hendydichvumang` |
| Branch | `main` |
| Root Directory | `telegram-bot` |
| Runtime | Docker |
| Dockerfile Path | `./Dockerfile` |
| Docker Context | `.` |
| Health Check Path | `/healthz` |
| Region | Singapore |

Service Bot cần một PostgreSQL database riêng. Có thể dùng Render PostgreSQL hoặc nhà cung cấp PostgreSQL tương thích. Đưa connection URL vào `DATABASE_URL` trong Render Environment của Bot; bật TLS bằng `DATABASE_SSL=true` khi dùng database ngoài. Không dùng MySQL của Website hoặc database của dự án khác.

## Biến môi trường Bot

Bắt buộc:
- `BOT1_TOKEN` — secret từ BotFather.
- `DATABASE_URL` — secret URL PostgreSQL riêng của Bot.
- `ADMIN_ID` — Telegram ID quản trị, hoặc dùng `ADMIN_IDS` nếu cần danh sách.

Nên khai báo:
- `BOT1_PUBLIC_URL` — URL HTTPS dạng `https://t.me/ten_bot`.
- `TELEGRAM_WEBHOOK_SECRET` — Render Blueprint tự tạo; nếu không cấu hình, ứng dụng sinh khóa 256-bit và lưu trong PostgreSQL.
- `DATABASE_SSL=true` cho Neon/database ngoài.
- `WEBHOOK_AUTO_REGISTER=true` và `RUN_MIGRATIONS_ON_START=true`.
- `BANK_WEBHOOK_ENABLED=false` cho đến khi adapter chữ ký ngân hàng thực tế được xác minh.
- `WEBSOCKET_ENABLED=false` trong lần triển khai một service này; không bật nếu chưa có WSS service riêng.
- `HOMEPROXY_ENCRYPTION_KEY` — secret ngẫu nhiên riêng, cần có khi sử dụng tính năng mã hóa HomeProxy.

Không cấu hình `BOT2_TOKEN`. Không đặt Bot Token, DB URL, secret hoặc mật khẩu trong GitHub source, issue, action log hay frontend.

## Kết nối Website với Bot

Website đọc biến công khai `TELEGRAM_BOT_URL` (ví dụ `https://t.me/ten_bot`) để hiện liên kết Telegram Bot trên thanh điều hướng. Giá trị được kiểm tra HTTPS và host `t.me`/`telegram.me`; token Bot không đi qua Website hoặc trình duyệt.

Đặt `TELEGRAM_BOT_URL` ở Environment của Website. Đây là URL điều hướng công khai, không phải API credential.

## Tách biệt tài khoản và dữ liệu

Trong giai đoạn này:
- Website quản lý email/password và danh mục sản phẩm trong MySQL.
- Bot quản lý Telegram user, ví, giao dịch, đơn hàng và menu trong PostgreSQL.
- Không có migration tự động giữa hai database.
- Không tự đồng bộ số dư hoặc tạo bản sao ví.
- Không giả định email Website tương ứng với Telegram ID.

Muốn dùng một tài khoản chung, triển khai sau bằng luồng liên kết có xác minh hai phía: Website tạo mã dùng một lần, người dùng xác nhận mã trong Bot, server đổi mã lấy liên kết, lưu audit và cho phép thu hồi. Không cho phép liên kết chỉ bằng cách nhập Telegram ID hoặc email.

## Checklist kiểm tra sau deploy

1. Website: trang chủ, đăng nhập, xem chi tiết sản phẩm, Admin và upload ảnh.
2. Bot service: `GET /healthz` trả trạng thái khỏe; kiểm tra Render logs không có lỗi migration hoặc webhook.
3. Telegram: gửi `/start`, kiểm tra Inline Keyboard và callback.
4. Mini App: mở từ nút Bot, xác minh Telegram initData phía server.
5. Dashboard: mở `/admin` trên trình duyệt, xác minh quyền Admin phía backend.
6. Kiểm tra liên kết Bot công khai; không gọi Bot Token từ frontend.

Đây là checklist, không phải xác nhận rằng Render hoặc Telegram đã được triển khai. Không chạy migration trên production trước khi cấp đúng PostgreSQL database và sao lưu.
