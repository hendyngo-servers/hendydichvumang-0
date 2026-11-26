# HENDY trên Ubuntu 26.04 LTS (64-bit / amd64)

Hướng dẫn triển khai HENDY lên Ubuntu VPS bằng Docker Compose, Caddy HTTPS và systemd. Đây là đường triển khai chính của repository.

Ứng dụng chạy cùng mã PHP 8.3 hiện có trong telegram-bot/. Website, App Store, Mini App, Admin và API dùng chung một HTTPS hostname. Không tạo Bot Node.js thứ hai, không tạo ví/đơn hàng thứ hai.

## 1. Kiến trúc triển khai

- hendy-app: PHP 8.3 + Apache, Telegram Bot, REST API, Website và Admin.
- caddy: HTTPS tự động, proxy công khai cổng 80/443 tới hendy-app.
- hendy-worker: worker queue riêng, chạy bằng user www-data; chỉ chạy khi bật Compose profile workers.
- PostgreSQL: kết nối tới database HENDY hiện tại qua DATABASE_URL (ví dụ Neon). Không tạo database local mới và không đổi database trong lần cài đặt này.
- hendy_storage, caddy_data, caddy_config: Docker named volumes để giữ file nội bộ và chứng chỉ Caddy qua lần restart/rebuild.

Không mở trực tiếp cổng Apache 10000 ra Internet. Chỉ Caddy expose 80/443.

## 2. Chuẩn bị VPS

Yêu cầu:
- Ubuntu 26.04 LTS, kiến trúc amd64.
- SSH với quyền sudo.
- Một hostname công khai, ví dụ bot.example.com. Trong cấu hình một-domain, Website và API dùng cùng hostname.
- DNS A record trỏ về IPv4 VPS. Chỉ tạo AAAA nếu IPv6 đã hoạt động và firewall cho phép.
- Inbound TCP 22 (SSH), 80 và 443. UDP 443 là tùy chọn cho HTTP/3.

Nếu sử dụng UFW, cho phép SSH trước khi bật firewall:

    sudo ufw allow OpenSSH
    sudo ufw allow 80/tcp
    sudo ufw allow 443/tcp
    sudo ufw allow 443/udp
    sudo ufw status

Chỉ bật UFW sau khi đã xác nhận SSH rule phù hợp với cổng SSH thực tế của VPS.

## 3. Lấy mã nguồn đúng repository và cài Docker

Repository đích là hendyngo-servers/hendydichvumang-0:

    sudo apt update
    sudo apt install -y git curl
    sudo mkdir -p /opt
    sudo git clone https://github.com/hendyngo-servers/hendydichvumang-0.git /opt/hendydichvumang-0
    cd /opt/hendydichvumang-0
    sudo bash deploy/ubuntu/install-docker.sh

Script cài Docker chỉ chạy trên Ubuntu 26.04 amd64. Nếu phát hiện các gói Docker/containerd xung đột, script dừng để người quản trị kiểm tra; nó không tự gỡ gói hiện có.

Kiểm tra:

    docker --version
    docker compose version
    sudo systemctl status docker

## 4. Cấu hình private environment

Tạo file cấu hình riêng:

    cd /opt/hendydichvumang-0
    cp deploy/ubuntu/hendy.env.example deploy/ubuntu/hendy.env
    sudo chown root:root deploy/ubuntu/hendy.env
    sudo chmod 600 deploy/ubuntu/hendy.env
    sudo nano deploy/ubuntu/hendy.env

Bắt buộc cập nhật các giá trị sau:

- HENDY_PUBLIC_DOMAIN: hostname DNS, ví dụ bot.example.com; không có https://, port hoặc path.
- WEBSITE_PUBLIC_URL: cùng origin, ví dụ https://bot.example.com.
- ALLOWED_ORIGINS: origin được phép gọi API; một-domain có thể đặt https://bot.example.com.
- BOT1_TOKEN: token production lấy từ BotFather.
- ADMIN_ID hoặc ADMIN_IDS: Telegram numeric ID của quản trị viên.
- DATABASE_URL: URI của đúng PostgreSQL database HENDY đang giữ users, wallet, ledger, deposits và orders.
- DATABASE_SSL=true khi sử dụng Neon hoặc PostgreSQL bên ngoài có TLS.

Không thay DATABASE_URL bằng database trống hoặc database của dự án khác nếu mục tiêu là giữ số dư và đơn hàng hiện tại. VPS app dùng lại database hiện tại; không tạo bản sao ví riêng.

Các giá trị an toàn ban đầu phải giữ:
- RUN_MIGRATIONS_ON_START=false
- WEBHOOK_AUTO_REGISTER=false
- HENDY_INLINE_WORKERS=false
- COMPOSE_PROFILES để trống
- BANK_WEBHOOK_ENABLED=false
- WEBSOCKET_ENABLED=false
- AI_MEDIA_QUEUE_ENABLED=false
- AI_COVER_ENABLED=false
- AI_COVER_WORKER_ENABLED=false
- MULTI_AGENT_ENABLED=false
- VIDEO_WORKER_ENABLED=false
- CODE_TELEGRAM_WORKER_ENABLED=false

Không đặt giá trị thật trong .env.example, Git, Website JavaScript, README, issue hoặc commit. HENDY GitHub Source Editor token và các provider API keys chỉ để trong private hendy.env khi đã thực sự cần dùng.

## 5. Kiểm tra file cấu hình và khởi chạy lần đầu

Cấu hình Docker Compose trước khi khởi động:

    cd /opt/hendydichvumang-0/deploy/ubuntu
    sudo docker compose --env-file hendy.env config --quiet

Cài systemd service và build image:

    sudo bash /opt/hendydichvumang-0/deploy/ubuntu/install-service.sh

Kiểm tra trạng thái:

    sudo systemctl status hendy-telegram-bot
    sudo docker compose --env-file hendy.env ps
    sudo docker compose --env-file hendy.env logs --tail=200 hendy-app caddy

Caddy chỉ nhận traffic sau khi hendy-app vượt health check. Khi DNS trỏ đúng VPS và firewall mở 80/443, Caddy tự xin/gia hạn chứng chỉ TLS. Không cần chép private key/chứng chỉ vào repository.

Kiểm tra ứng dụng:

    sudo bash /opt/hendydichvumang-0/deploy/ubuntu/verify.sh

Verification kiểm tra HTTPS, Website homepage, App Store, Mini App, API health và chạy database-doctor.php ở chế độ read-only. Doctor không in DATABASE_URL hoặc secret.

Sau kiểm tra, truy cập:
- https://bot.example.com/
- https://bot.example.com/app-store/
- https://bot.example.com/miniapp/
- https://bot.example.com/admin
- https://bot.example.com/healthz

Thay bot.example.com bằng hostname đã cấu hình thật.

## 6. Worker nền (tùy chọn, mặc định tắt)

Ubuntu tách worker khỏi tiến trình Web/API. Worker container chạy bằng user www-data, chia sẻ volume storage có kiểm soát và chỉ được bật qua Compose profile.

Chỉ bật worker sau khi:
1. Migration cần thiết đã được duyệt và áp dụng vào đúng database.
2. Provider API key/callback secret được cấu hình trong private hendy.env.
3. Module tương ứng được bật trong Admin và đã qua kiểm tra.
4. Đã xác nhận worker thực sự cần chạy trên VPS.

Ví dụ bật Multi-Agent:
- Đặt COMPOSE_PROFILES=workers.
- Đặt MULTI_AGENT_ENABLED=true.
- Cấu hình OPENAI_API_KEY.
- Xác nhận migration 20261120_multi_agent_control_center.sql đã được áp dụng.

Sau khi thay đổi hendy.env:

    sudo chmod 600 /opt/hendydichvumang-0/deploy/ubuntu/hendy.env
    sudo systemctl restart hendy-telegram-bot
    sudo docker compose --env-file /opt/hendydichvumang-0/deploy/ubuntu/hendy.env logs -f hendy-worker

Worker startup chạy preflight cho Multi-Agent và dừng nếu thiếu migration/provider key. Các worker AI Media, Video, Code Telegram và AI Cover cũng có điều kiện kiểm tra riêng. Không bật tất cả chỉ để thử nghiệm.

## 7. Migration production an toàn

Không tự động migration khi khởi động VPS. Chỉ chạy khi đã có restore point/backup và xác minh đúng database.

Trước tiên, kiểm tra database đang được runtime sử dụng:

    cd /opt/hendydichvumang-0/deploy/ubuntu
    sudo docker compose --env-file hendy.env exec -T hendy-app php bin/database-doctor.php

Ghi lại database name, role, host, direct/pooled và migration inventory. Không suy luận tên database từ Neon project slug.

Để chạy migration:
1. Tạo/kiểm tra restore point trên Neon hoặc backup tương đương.
2. Lấy direct/unpooled owner/migration URL của đúng database.
3. Trong SSH shell, nhập URL không echo và chỉ truyền vào một container chạy một lần:

    read -r -s -p "Neon direct owner URL: " MIGRATION_DATABASE_URL
    printf '\n'
    export MIGRATION_DATABASE_URL
    cd /opt/hendydichvumang-0/deploy/ubuntu
    sudo --preserve-env=MIGRATION_DATABASE_URL docker compose --env-file hendy.env run --rm -e MIGRATION_DATABASE_URL hendy-app php bin/migrate.php

Lệnh trên là dry-run; không áp schema. Kiểm tra database name, role, host và toàn bộ pending migrations. Migration runner có thể áp dụng tất cả migration còn pending theo thứ tự; không giả định chỉ chạy một file.

Chỉ khi xác nhận exact database, role, restore point và danh sách migration mới apply với tên database lấy từ doctor:

    sudo --preserve-env=MIGRATION_DATABASE_URL docker compose --env-file hendy.env run --rm -e MIGRATION_DATABASE_URL hendy-app php bin/migrate.php --apply --expect-database=TEN_DATABASE_DA_XAC_NHAN

Thay TEN_DATABASE_DA_XAC_NHAN bằng chính xác database name. Sau đó chạy database-doctor.php lần nữa và xác nhận pending=0, checksum mismatch=0. Kết thúc shell bằng:

    unset MIGRATION_DATABASE_URL

Không đưa owner URL vào hendy.env, Git hoặc log. Không reset schema, không seed lại ví và không xóa users/orders/ledger.

## 8. Chuyển webhook Telegram (chỉ khi VPS đã sẵn sàng)

Telegram chỉ có một webhook active cho một token tại một thời điểm.

1. Giữ WEBHOOK_AUTO_REGISTER=false trên VPS trong suốt giai đoạn cài đặt/kiểm tra.
2. Nếu cùng BOT1_TOKEN đang chạy trên backend cũ, đảm bảo backend cũ không tự đăng ký lại webhook sau khi cutover. Không để hai nơi đồng thời tự đăng ký.
3. Sau khi VPS qua verify, database đúng, HTTPS tốt và /start được kiểm tra, đổi WEBHOOK_AUTO_REGISTER=true trong private hendy.env.
4. Chạy sudo systemctl restart hendy-telegram-bot. Container startup sẽ gọi getMe/setWebhook và xác minh getWebhookInfo.
5. Có thể kiểm tra lại bằng lệnh php bin/register-webhook.php từ hendy-app; lệnh này thực hiện setWebhook và verify, vì vậy chỉ chạy khi chủ động xác nhận muốn VPS sở hữu webhook.
6. Gửi /start, kiểm tra hồ sơ, ví, lịch sử giao dịch, đơn hàng, ticket và API Website trước khi dừng backend cũ.

Rollback webhook: đặt WEBHOOK_AUTO_REGISTER=false trên VPS và restart. Chỉ sau đó bật đăng ký webhook ở backend cũ. Không để hai máy cùng tự đăng ký.

## 9. Cập nhật và rollback mã nguồn

Sau khi PR đã được review/merge vào main, cập nhật trên VPS:

    sudo bash /opt/hendydichvumang-0/deploy/ubuntu/update.sh

Script kiểm tra working tree sạch, fetch main, chỉ fast-forward, build image rồi chờ health check. Nếu build/health check mới thất bại, script trả Git checkout về commit trước và dựng lại image trước. Script không chạy migration.

Xem các commit để chọn rollback target:

    git -C /opt/hendydichvumang-0 log --oneline -10

Rollback bằng commit SHA đầy đủ 40 ký tự:

    sudo bash /opt/hendydichvumang-0/deploy/ubuntu/rollback.sh COMMIT_SHA_40_KY_TU

Rollback source code không tự rollback PostgreSQL schema/data. Migration rollback phải theo kế hoạch database riêng và có restore point.

## 10. Logs và vận hành

    sudo systemctl status hendy-telegram-bot
    sudo journalctl -u hendy-telegram-bot -f
    cd /opt/hendydichvumang-0/deploy/ubuntu
    sudo docker compose --env-file hendy.env ps
    sudo docker compose --env-file hendy.env logs -f hendy-app caddy
    sudo docker compose --env-file hendy.env logs -f hendy-worker

Docker json logs được giới hạn dung lượng/tệp trong Compose. Named volumes giữ storage và Caddy cert qua rebuild. Không xóa volume bằng docker compose down -v hoặc docker volume prune nếu chưa có backup và xác nhận nội dung.

## 11. Ranh giới triển khai

- Ubuntu VPS là nơi chạy Website + Bot/API + Admin; PostgreSQL vẫn là database hiện hữu (Neon hoặc PostgreSQL endpoint đã được xác minh), không tạo database ví thứ hai.
- Render Blueprint được giữ làm legacy reference/fallback, không dùng làm đích deploy chính trong hướng dẫn này.
- Provider Registry lưu metadata/tên biến ENV, không lưu secret value.
- Supplier Readiness vẫn khóa bán hàng nếu quyền nguồn, supplier access, evidence và stock chưa đạt.
- Adapter API tổng quát không đồng nghĩa đã tích hợp production.
- Bank callback, fulfillment tự động, AI workers, Code Telegram workers và WebSocket giữ tắt cho tới khi có credential, migration, callback verification và test riêng.
- Không chạy migration hoặc chuyển webhook chỉ vì GitHub CI xanh.
