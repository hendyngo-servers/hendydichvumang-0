# HENDY trên Ubuntu 26.04 LTS (64-bit / amd64)

Bộ triển khai chạy **chính mã nguồn PHP hiện có trong `telegram-bot/`**. Nó không tạo bot Node.js thứ hai, không tạo database ví riêng và không thay thế logic đơn hàng, wallet ledger, Provider Registry hoặc Supplier Readiness.

## Kiến trúc

- Ubuntu 26.04 amd64: Docker Engine + Docker Compose.
- HENDY PHP Bot/API: build từ `telegram-bot/Dockerfile` tại repository root.
- Caddy: reverse proxy HTTPS tự động cho domain Bot/API.
- PostgreSQL: kết nối qua `DATABASE_URL`; dùng đúng database hiện tại khi cần giữ số dư, giao dịch, đơn hàng và hồ sơ provider/supplier.
- Website và Mini App tiếp tục dùng API contract hiện có; một file cấu hình công khai cho phép đổi API origin mà không đưa token vào trình duyệt.

Đây là phương án thay thế Bot service đang chạy trên Render. Không bật cùng lúc hai nơi tự đăng ký webhook cho cùng `BOT1_TOKEN`.

## 1. Chuẩn bị VPS

Yêu cầu:
- Ubuntu 26.04 LTS, kiến trúc amd64.
- Domain/subdomain riêng cho Bot API, ví dụ `bot.example.com`.
- DNS A record (và AAAA nếu VPS dùng IPv6) trỏ về VPS.
- Inbound TCP 80/443; UDP 443 tùy chọn cho HTTP/3.
- SSH với quyền sudo và Git.

Cài Git/curl và Docker:

```bash
sudo apt update
sudo apt install -y git curl
git clone https://github.com/hendyngo-servers/hendydichvumang.git /opt/hendydichvumang
cd /opt/hendydichvumang
sudo bash deploy/ubuntu/install-docker.sh
```

Script Docker kiểm tra đúng Ubuntu 26.04 amd64, cài Docker từ repository apt chính thức và **không tự gỡ** các gói Docker/containerd cũ nếu phát hiện xung đột.

## 2. Cấu hình secrets riêng trên VPS

```bash
cd /opt/hendydichvumang
cp deploy/ubuntu/hendy.env.example deploy/ubuntu/hendy.env
nano deploy/ubuntu/hendy.env
chmod 600 deploy/ubuntu/hendy.env
```

Bắt buộc điền:
- `HENDY_PUBLIC_DOMAIN`: hostname Bot/API, không có `https://` hoặc path.
- `BOT1_TOKEN`: token bot production hiện tại từ BotFather.
- `ADMIN_ID` hoặc `ADMIN_IDS`: Telegram numeric ID quản trị.
- `DATABASE_URL`: PostgreSQL URI của **đúng database HENDY đang sử dụng**.

Thiết lập TLS:
- Neon hoặc PostgreSQL bên ngoài có TLS: `DATABASE_SSL=true`.
- `ALLOWED_ORIGINS` phải liệt kê Website origin được phép gọi API, ví dụ `https://hendydichvumang-szad.onrender.com`.
- Không đặt database URL, password hoặc credential vào Website config, Git, issue hay log.

**Quan trọng về dữ liệu:** database trống sẽ không có ví và lịch sử đơn cũ. Muốn chuyển Bot từ Render sang VPS mà giữ dữ liệu, phải dùng cùng PostgreSQL database với Bot hiện tại. Không tự ý reset schema hoặc seed lại dữ liệu.

Mặc định an toàn:
- `RUN_MIGRATIONS_ON_START=false`
- `WEBHOOK_AUTO_REGISTER=false`
- Workers, bank callback và WebSocket tắt.

## 3. Cài systemd service và khởi chạy

```bash
sudo bash deploy/ubuntu/install-service.sh
sudo systemctl status hendy-telegram-bot
cd /opt/hendydichvumang/deploy/ubuntu
sudo docker compose --env-file hendy.env ps
sudo docker compose --env-file hendy.env logs -f hendy-app
```

Caddy chỉ khởi chạy sau khi Bot/API vượt health check. Khi DNS trỏ đúng và firewall mở 80/443, Caddy tự xin và gia hạn TLS certificate; không cần cài chứng chỉ thủ công.

Kiểm tra:

```bash
sudo bash /opt/hendydichvumang/deploy/ubuntu/verify.sh
```

Script kiểm tra `/healthz`, `/api/health` và chạy `php bin/database-doctor.php` trong container. Database doctor chỉ đọc và không xuất connection string.

## 4. Kết nối Website và Mini App sang VPS

Mặc định Website giữ nguyên API Render hiện tại. Sau khi VPS HTTPS và PostgreSQL health đã được xác minh, sửa:

`website/public/assets/deployment-config.js`

Đổi:

```js
apiBaseUrl: ''
```

thành:

```js
apiBaseUrl: 'https://bot.example.com'
```

Thay domain ví dụ bằng hostname thật trong `HENDY_PUBLIC_DOMAIN`, commit và deploy Static Site. File này chỉ chứa public HTTPS origin; không chứa Bot token, database URL hay provider API key.

Website, App Store và Mini App cùng đọc cấu hình này. Nếu để trống, hostname Static Site Render hiện tại dùng cấu hình API Render cũ. Đảm bảo Website origin có trong `ALLOWED_ORIGINS` của VPS.

## 5. Cutover webhook (chỉ thực hiện khi sẵn sàng)

Telegram chỉ có một webhook active cho một bot token tại một thời điểm. Không để Render và VPS cùng khởi động với `WEBHOOK_AUTO_REGISTER=true`.

1. Giữ VPS ở `WEBHOOK_AUTO_REGISTER=false`; xác minh HTTPS, API health và đúng database.
2. Trong Render Environment của Bot service, đặt `WEBHOOK_AUTO_REGISTER=false`, sau đó deploy/restart Render để cấu hình mới có hiệu lực. Webhook cũ vẫn có thể hoạt động ở Render trong giai đoạn này, nhưng Render không tự đăng ký lại webhook ở lần restart tiếp theo.
3. Trên VPS, đổi `WEBHOOK_AUTO_REGISTER=true` trong `deploy/ubuntu/hendy.env`, rồi chạy:

   ```bash
   sudo systemctl restart hendy-telegram-bot
   cd /opt/hendydichvumang/deploy/ubuntu
   sudo docker compose --env-file hendy.env exec -T hendy-app php bin/register-webhook.php
   ```

   Ứng dụng gọi Telegram `getMe`, đặt webhook vào domain VPS và kiểm tra `getWebhookInfo`.
4. Gửi `/start`, kiểm tra ví, lịch sử, danh sách đơn và API Website. Chỉ dừng Render Bot sau khi xác nhận VPS đang nhận update; Static Site Render vẫn hoạt động độc lập.
5. Chuyển `apiBaseUrl` trong `deployment-config.js` sang domain VPS và deploy Website nếu chưa thực hiện.

**Rollback:** đặt `WEBHOOK_AUTO_REGISTER=false` trên VPS và restart; sau đó bật `WEBHOOK_AUTO_REGISTER=true` cho Render Bot và deploy/restart Render để Bot cũ đăng ký lại webhook. Tránh hai bên cùng true.

## 6. Cập nhật mã nguồn

Sau khi PR được merge vào `main`:

```bash
sudo bash /opt/hendydichvumang/deploy/ubuntu/update.sh
```

Script chỉ cập nhật code và rebuild container; không chạy migration production. Log:

```bash
sudo journalctl -u hendy-telegram-bot -f
cd /opt/hendydichvumang/deploy/ubuntu
sudo docker compose --env-file hendy.env logs -f hendy-app caddy
```

## 7. Provider Registry và Supplier Readiness

Các chức năng này đã nằm trong mã nguồn hiện tại:
- Provider Registry quản lý metadata và **tên biến Environment**, không lưu credential value trong PostgreSQL.
- Supplier Readiness khóa bán hàng cho tới khi quyền nguồn được xác nhận, supplier access đã xác minh, hồ sơ HTTPS đầy đủ, còn hàng và fulfillment manual.
- Adapter API tổng quát chưa được coi là tích hợp production.

Chuyển runtime sang Ubuntu không tự động áp migration. Giữ `RUN_MIGRATIONS_ON_START=false` và làm theo:
- `docs/provider-service-registry.md`
- `docs/product-provider-onboarding.md`
- `docs/PRODUCTION-MIGRATION-RUNBOOK.md`

Chỉ migration sau khi xác nhận đúng database/role, tạo restore point và dùng direct owner/migration URL theo runbook. Không thay đổi wallet, order, ledger hoặc người dùng để triển khai Bot.

## Bảo mật

- Không commit `hendy.env`; tệp được loại khỏi Git bởi `.gitignore` riêng.
- Chỉ quản trị viên VPS được đọc file này; dùng `chmod 600`.
- Không bật bank webhook, workers, Provider API adapter hoặc fulfillment tự động nếu chưa có integration chuyên biệt và kiểm thử.
- Không chạy reset schema hay migration production tùy tiện.
