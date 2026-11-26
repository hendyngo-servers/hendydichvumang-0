# HENDY BOT CYBERTECH

Telegram Bot backend dùng PHP 8.3 + PostgreSQL, đặt trong `telegram-bot/` của monorepo `hendyngo-servers/hendydichvumang`. Website và Bot là hai Render Web Service riêng, dùng hai Neon database/role độc lập.

## Thành phần

- Một Telegram bot production duy nhất, dùng `BOT1_TOKEN`.
- Telegram webhook, menu Inline Keyboard, xác thực Telegram Web App và API.
- Trang giới thiệu công khai, Trung tâm khách hàng và Dashboard Admin.
- Ví, yêu cầu nạp tiền, giao dịch, sản phẩm/đơn hàng, voucher, VIP, referral, ticket CSKH.
- Các phân hệ Mini Game, Tool, Code, OTP, Proxy và dịch vụ VIP.
- Video job worker, callback HMAC, tùy chọn WebSocket realtime.
- PostgreSQL schema/migration, Docker và Render Blueprint.

Không có token, mật khẩu database hoặc khóa signing thật trong source. Tất cả credential phải cấu hình bằng Render Environment/GitHub Secrets.

## URL

| Mục đích | Đường dẫn |
|---|---|
| Trang chủ giới thiệu | `/` |
| Dashboard Admin | `/admin` |
| Trung tâm khách hàng | `/customer` |
| Telegram Mini App | `/miniapp` |
| API discovery | `/api/v1` |
| Public config | `/api/v1/public/config` |
| Health check | `/health` hoặc `/healthz` |
| Chính sách bảo mật | `/privacy` |
| Điều khoản | `/terms` |
| Webhook Telegram duy nhất | `/webhook/telegram` |
| Video callback | `/webhook/video` |
| Bank callback (mặc định khóa) | `/webhook/bank` |

Trang chủ công khai dùng `apps/website/landing.html` với CSS/JS tự host trong `public/assets/`. Trung tâm khách hàng dùng `apps/website/index.html`; Admin dùng `apps/admin-dashboard/index.html`. Trang `apps/admin-dashboard/legacy-login.html` là trang trao đổi vé đăng nhập website một lần, vẫn được route `/legacy-login` sử dụng.

## Cấu hình

Các biến quan trọng cấu hình tại Render Environment:

- `BOT1_TOKEN`: token của bot Telegram duy nhất.
- `ADMIN_ID` hoặc `ADMIN_IDS`: Telegram ID của quản trị viên.
- `DATABASE_URL`: Neon PostgreSQL database `hendydichvumang`, role `hendy_bot_app`.
- `DATABASE_SSL=true` cho Neon.
- `WEBHOOK_AUTO_REGISTER=true`; Render cung cấp `RENDER_EXTERNAL_URL`.
- `TELEGRAM_WEBHOOK_SECRET` là tùy chọn; để trống, backend tạo secret ngẫu nhiên 256-bit và lưu trong PostgreSQL.
- Khi container khởi động, `bin/register-webhook.php` gọi `getMe`, đăng ký duy nhất `https://<Render Bot domain>/webhook/telegram` bằng `setWebhook`, rồi xác minh bằng `getWebhookInfo`; `drop_pending_updates=false` để không xóa update đang chờ.
- `BANK_WEBHOOK_ENABLED=false` cho đến khi có adapter kiểm tra chữ ký theo ngân hàng.
- `NEON_STORAGE_FUNCTION_URL` và `NEON_STORAGE_FUNCTION_KEY` chỉ đặt nếu bật Object Storage.

Runtime chỉ chấp nhận khóa bot `primary`, chỉ đọc `BOT1_TOKEN`, chỉ đăng ký webhook `/webhook/telegram`; các biến Bot thứ hai cũ nếu còn tồn tại trong Render sẽ không được sử dụng. Không đưa token Bot hoặc secret vào URL, HTML, JavaScript, log hoặc commit.

Liên kết Bot công khai được lấy từ Telegram `getMe()` trong bước đăng ký webhook và lưu trong `app_settings`. Không cần chép username Bot thủ công.

## Chạy local

Yêu cầu PHP 8.3+, Composer, PostgreSQL, extensions `curl`, `mbstring`, `pdo_pgsql`.

```bash
cp .env.example .env
composer install
php bin/validate-config.php
php -S 127.0.0.1:8080 -t public
```

Điền credential local của riêng bạn trong file `.env`; file này bị ignore. Schema production được quản lý bằng migration riêng; `RUN_MIGRATIONS_ON_START=false` để tránh tài khoản runtime tự chạy DDL.

## Render / Docker

Blueprint ở repository root: `render.yaml`.

- Bot build dùng `dockerfilePath: ./telegram-bot/Dockerfile`.
- Docker context: `./telegram-bot`.
- Render service: `hendydichvumang-telegram-bot`.
- Health check: `/healthz`.
- Database: Neon `hendydichvumang`, không dùng chung database Website.

Khi đã adopt Blueprint, các Git push trên `main` tự sync/deploy theo `autoDeployTrigger: checksPass`. Không tạo thêm Web Service thủ công trùng tên.

## Dashboard Admin

Admin UI hiện có các phân hệ tổng quan, thành viên, đơn hàng, nạp tiền, ticket CSKH, voucher, giftcode, menu Bot, runtime, module upload, video jobs, operations, HomeProxy và audit log. Mọi API `/api/admin/*` kiểm tra allowlist Admin ở backend; xác thực Telegram không thay thế phân quyền server-side.

## Kiểm tra

```bash
composer validate --no-check-publish
composer lint
composer test
php bin/validate-config.php
php scripts/secret-scan.php
```

GitHub Actions chạy lint/test Bot, lint PHP Website và test Neon Function.

## Bảo mật và ranh giới dữ liệu

- Bot Token chỉ đọc từ environment; không có Bot phụ.
- Webhook xác thực `X-Telegram-Bot-Api-Secret-Token` với so sánh an toàn.
- Telegram Mini App initData được xác minh HMAC ở backend.
- SQL dùng PDO prepared statements; giao dịch ví dùng transaction/idempotency.
- Bank callback để tắt cho đến khi xác minh chữ ký nhà cung cấp.
- File upload được đưa vào vùng kiểm duyệt, không tự thực thi.
- Website và Bot dùng hai Neon database/role riêng. Không tự đồng bộ số dư, đơn hàng hoặc hồ sơ tài khoản giữa hai database.

Nếu Bot Token cũ từng bị commit, hãy thu hồi token tại BotFather, tạo token mới và cập nhật `BOT1_TOKEN` trong Render; chỉ xóa file khỏi commit mới không xóa token khỏi Git history.


## AI Media (tùy chọn, mặc định tắt)

Hai mục menu **TẠO ÂM NHẠC AI** và **CHỈNH SỬA ẢNH AI** dùng hàng đợi PostgreSQL và worker riêng để webhook Telegram không chờ tác vụ tạo media.

- Âm nhạc: ElevenLabs Music API, mặc định model `music_v2_5`, clip thử nghiệm 30 giây.
- Chỉnh ảnh: OpenAI Images Edit API, mặc định model `gpt-image-2.5-sunburst`; thành viên gửi ảnh rồi mô tả chỉnh sửa.
- Giới hạn mặc định: tối đa 1 tác vụ đang chạy và 3 tác vụ mỗi tài khoản trong ngày Việt Nam. Không trừ ví trong bản thử nghiệm này.
- Tệp ảnh đầu vào chỉ được tải về tạm thời từ Telegram, kiểm tra loại ảnh/kích thước rồi xóa sau khi xử lý. Tệp kết quả cũng được xóa sau khi gửi.
- Bảng `ai_media_jobs` là migration bổ sung; không tự tạo bảng ở runtime. Worker chỉ chạy khi `AI_MEDIA_QUEUE_ENABLED=true`, migration đã được áp dụng và API key tương ứng đã có.

Cấu hình các biến trong Render Environment của Bot:
- `AI_MEDIA_QUEUE_ENABLED=false` (chỉ bật sau migration và khi đã cấu hình provider).
- `OPENAI_API_KEY`, `ELEVENLABS_API_KEY`: secrets; không commit và không nhập qua Telegram.
- `OPENAI_IMAGE_MODEL=gpt-image-2.5-sunburst`, `ELEVENLABS_MUSIC_MODEL=music_v2_5`.
- `AI_MEDIA_MAX_PER_DAY=3`, `AI_MEDIA_WORKER_POLL_SECONDS=5`.

Music API của ElevenLabs yêu cầu tài khoản có quyền API phù hợp; việc tạo media có thể phát sinh chi phí tại nhà cung cấp. HENDY không tự khấu trừ ví thành viên trong luồng thử nghiệm.


## Code Telegram HENDY (tùy chọn, mặc định tắt)

Phân hệ này chỉ đọc bài đăng từ Telegram channel đã được Admin kết nối và Bot chính đã xác minh quyền quản trị. Bot nhận `channel_post` và `edited_channel_post`; tin chữ được phân tích trực tiếp, video được đưa vào hàng đợi PostgreSQL để worker tải clip và OCR bằng FFmpeg/Tesseract (tiếng Việt + tiếng Anh).

- Hỗ trợ nhận diện mã khuyến mãi, code game, code phần mềm, Code Tele và code màn.
- Trùng mã đã có trong trạng thái chờ duyệt/đang phát hành/đã dùng sẽ bị bỏ qua ở nguồn khác.
- Mã từ video luôn chờ Admin duyệt. Mã từ tin chữ cũng chờ duyệt theo mặc định; Admin có thể bật tự xuất bản riêng cho từng nguồn đã xác minh.
- Chỉ kết quả trạng thái `active` mới xuất hiện cho thành viên qua lệnh `/code từ khóa`; mục menu thành viên hiển thị tên chuẩn **📡 CODE TELEGRAM**.
- Menu Code Telegram đọc danh sách dịch vụ và giá trực tiếp từ PostgreSQL: XX88, LLWIN, 78WIN, XX8, MB66, ON68, NEW88, Code VIP XX88 và Code VIP LLWIN. Giá mặc định được seed theo bảng dịch vụ; Admin quản lý yêu cầu username tại cùng tab Code Telegram.
- Khi chọn dịch vụ, thành viên gửi username (mỗi dòng một tài khoản, tối đa 20). Chỉ thu thập username; không yêu cầu mật khẩu, PIN hoặc OTP. Mỗi tài khoản giới hạn một yêu cầu/ngày theo giờ Việt Nam.
- Yêu cầu vào hàng chờ Admin; không trừ ví khi tiếp nhận. Admin chỉ ghi nhận hoàn tất khi có mã đã áp dụng thành công, nhập mã kết quả và phí trong phạm vi giá; hệ thống mới trừ Ví Chính trong transaction và gửi thông báo cho thành viên.
- Video tải qua Bot API hosted bị giới hạn tối đa 20 MB; worker trích tối đa 24 khung hình trong 120 giây đầu, xóa tệp tạm sau OCR.
- Admin: tab **Code Telegram** để kết nối nguồn, bật/tắt nguồn, duyệt mã OCR, xem yêu cầu username, chuyển trạng thái, xác nhận thành công/thu phí hoặc đánh dấu thất bại (không trừ tiền).

### Migration và kết nối kênh

1. Chạy cả hai migration additive `database/migrations/20261112_code_telegram.sql` và `database/migrations/20261112_code_telegram_services.sql` trên database Bot `hendydichvumang`.
2. Thêm Bot chính làm **quản trị viên** của từng channel nguồn. Chỉ cấp quyền tối thiểu cần thiết; không cần quyền xóa bài hoặc mời thành viên.
3. Render Environment của Telegram Bot cần `CODE_TELEGRAM_ENABLED=true` và `CODE_TELEGRAM_WORKER_ENABLED=true`. Giữ `CODE_TELEGRAM_MAX_VIDEO_BYTES=20971520` khi dùng Telegram Bot API hosted.
4. Mở Website Admin → **Code Telegram** → kết nối channel bằng `@username` hoặc channel ID. Mặc định mã trích từ cả tin chữ/video đều chờ Admin duyệt; chỉ Admin mới bật tự xuất bản mã chữ cho nguồn đáng tin cậy.

Lưu ý: gói dịch vụ và luồng gửi username đã được tích hợp, nhưng thao tác đổi/redeem mã trên từng nhà cái vẫn cần API đối tác được ủy quyền hoặc thao tác vận hành có xác nhận của Admin. HENDY không tự đăng nhập vào tài khoản game, không lưu mật khẩu, và không tuyên bố đã nhập mã vào nhà cái khi chưa có xác nhận thành công. Không dùng user session/MTProto để vượt quyền riêng tư, không tự động tham gia kênh, và không thu thập mật khẩu, OTP đăng nhập hoặc dữ liệu cá nhân.


## Multi-Agent Control Center

The Admin Dashboard provides a PostgreSQL-backed Agent registry and Hub. Tasks flow through specialist agents and the final output waits for Admin approval. See ../docs/MULTI-AGENT-CONTROL-CENTER.md before enabling the worker.
