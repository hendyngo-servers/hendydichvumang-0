# Realtime, module lifecycle và cửa hàng ứng dụng

## WebSocket Admin

WebSocket Admin chạy như một Render Web Service độc lập bằng PHP Ratchet, từ cùng Docker image với API/bot. Ticket được cấp bởi API sau khi xác thực Telegram Mini App, lưu hash trong PostgreSQL, hết hạn sau 60 giây và chỉ dùng một lần. Không đưa BOT token, initData hoặc ticket lên URL.

Luồng event hiện tại:

1. API/Bot gọi `Realtime::publish()` để ghi event vào `realtime_events`.
2. Cùng câu lệnh PostgreSQL phát `NOTIFY hendy_realtime_events` với payload chỉ là ID event.
3. Worker đã `LISTEN` channel, kiểm tra notification trong event loop mỗi 100 ms và đọc lại event từ PostgreSQL theo cursor tăng dần.
4. Có truy vấn cursor dự phòng mỗi 5 giây để phục hồi khi notification bị lỡ; cleanup event/ticket chạy mỗi 60 giây, không chạy mỗi tick.
5. Client gửi ping ở tầng ứng dụng mỗi 25 giây; server đóng kết nối không hoạt động sau 90 giây. Reconnect dùng exponential backoff và jitter.

PostgreSQL vẫn là nguồn sự thật; NOTIFY chỉ là tín hiệu đánh thức worker, không mang dữ liệu nghiệp vụ. Event payload không được gửi đến client chưa xác thực. Origin HTTPS được kiểm tra bằng `WEBSOCKET_ALLOWED_ORIGINS`; ticket chỉ cấp cho ID nằm trong `ADMIN_ID` hoặc `ADMIN_IDS`.

### Cấu hình production

Tạo Web Service thứ hai trên Render, cùng repository/branch và Dockerfile với ứng dụng chính:

- Name: `hendy-bot-tele-realtime`
- Docker Command: `php bin/websocket.php`
- Region: Singapore, cùng region PostgreSQL
- Plan: Starter hoặc cao hơn để process WebSocket luôn chạy
- Port: `10000`
- Environment: `DATABASE_URL` lấy cùng PostgreSQL, `DATABASE_SSL`, `ADMIN_ID`, `ADMIN_IDS`, `WEBSOCKET_BIND=0.0.0.0`, `WEBSOCKET_PORT=10000`, `WEBSOCKET_ALLOWED_ORIGINS`
- Main Web Service: đặt `WEBSOCKET_URL=wss://hendy-bot-tele-realtime.onrender.com`

Ví dụ allowlist origin:
`https://hendy-bot-tele.onrender.com,https://hendy-web-admin.pages.dev,https://hendy-mini-app.pages.dev`

Không dùng URL `https://` cho `WEBSOCKET_URL`; phải dùng `wss://`. Chỉ mở origin thực tế đang phục vụ Admin. Nếu đổi domain, cập nhật allowlist rồi deploy lại realtime service.

**Trạng thái tài khoản Render tại lần kiểm tra 2026-11-03:** mới có service `Hendy-Bot-Tele`. Blueprint đã khai báo `hendy-bot-tele-realtime`, nhưng service này chưa được tạo/triển khai trên Render. Vì vậy mã nguồn WebSocket đã sẵn sàng hơn nhưng chưa được bật trong production. Tạo service riêng có thể phát sinh phí; không tự động tạo khi chưa xác nhận ngân sách.

### Latency và giới hạn

- Inline Keyboard: bot gọi `answerCallbackQuery` trước khi chạy nghiệp vụ để Telegram dừng spinner.
- Reply Keyboard: gửi chat action `typing` trong khi xử lý.
- Mini App: render loading state ngay khi click, haptic feedback nhẹ, khóa nút gửi lặp trong lúc request.
- WebSocket: PostgreSQL NOTIFY đánh thức worker mục tiêu trong khoảng 100 ms tại phía server, cộng độ trễ mạng và client. Đây là mục tiêu kiến trúc, chưa phải kết quả đo p50/p95 production.
- Các tác vụ nặng như video/broadcast phải tiếp tục đi qua queue/worker, không giữ callback hoặc request HTTP chờ xử lý lâu.

Đo thực tế bằng timestamp ở client và server; theo dõi p50/p95 cho `/api`, thời gian ACK callback, thời gian phát event đến client, reconnect count và số WebSocket clients. Không kết luận độ trễ đạt mục tiêu chỉ từ cấu hình.

## Vòng đời module/tệp

Upload -> vùng cách ly -> kiểm tra MIME/kích thước -> pending_review -> code review -> CI và phát hành có ký -> deploy -> module.activated event. Upload chỉ nhận JSON, Markdown, TXT, CSS, HTML và ảnh tối đa 2 MB. Từ chối PHP/JS thực thi, shell, archive và secret. Không giải nén archive không tin cậy. Không tự ghi đè mã production từ trình duyệt; thay đổi code phải qua PR và CI.

## App Store, Google Play, Microsoft Store

Các adapter khai báo cho App Store Connect, Google Play Developer API và Microsoft Store submission API. Đây là điểm mở rộng, chưa phải kết nối tài khoản nhà phát hành. Cần developer account, API permissions, key/certificate, bundle/package/product ID và metadata riêng. Secret nằm trong secret manager. Capability mặc định chỉ đọc metadata; upload binary, submit review và publish đều tắt cho tới khi adapter tương ứng được triển khai và kiểm thử theo API/chính sách hiện hành.

The main API service uses a persistent Render disk mounted at /var/www/app/storage for staged module files. Keep the disk attached when changing plans or replacing services; it is not a substitute for off-site backups.
