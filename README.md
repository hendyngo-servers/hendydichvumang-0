# HENDY DỊCH VỤ MẠNG — Static Site + API + PostgreSQL

Mã nguồn được triển khai theo kiến trúc tách biệt: **Render Static Site** phân phối giao diện qua CDN, **Render Web Service** chạy API/Telegram webhook/tác vụ tự động, và **PostgreSQL** có thể đặt tại Render hoặc nhà cung cấp bên ngoài.

## 1. Kiến trúc

| Thành phần | Trách nhiệm |
|---|---|
| Render Static Site | Trang chủ giới thiệu HENDY tại `/`, Mini App khách hàng tại `/app`, Admin Dashboard tại `/admin`; HTML/CSS/JS thuần, responsive, tiếng Việt; phân phối CDN |
| Render Web Service | Node.js 22, Express 5, REST API, xác thực Telegram Mini App, Telegram webhook, đăng nhập Admin |
| PostgreSQL | Hồ sơ khách hàng, ví, sổ giao dịch, danh mục dịch vụ, ticket, session, audit log, cấu hình/lịch sử automation và kho module chờ duyệt |
| Telegram Bot | grammY là bot chính; aiogram 3.31.0 là bot thử nghiệm Python riêng với token khác; cả hai dùng Node API và PostgreSQL chung |
| Automation scheduler | Dọn session, báo ticket mới, nhắc ticket đang chờ; PostgreSQL advisory lock tránh chạy trùng |
| Aiogram Pilot | Bot Python riêng, Router/Filters/FSM, webhook có secret, PostgreSQL FSM; gọi Node API bằng service key |
| GitHub Actions | Kiểm tra Node.js + PostgreSQL 18, compile/test Python aiogram và build Static Site |

Static Site chỉ chứa tài nguyên frontend, không giữ secret và không kết nối database trực tiếp.

## 2. Cấu trúc chính

```text
.
├── apps/
│   ├── bot_chinh/        # grammY — bot Telegram production chính
│   ├── bot_phu/          # Aiogram — bot Telegram thử nghiệm/phụ
│   └── website/          # Nguồn trang chủ giới thiệu
├── .github/workflows/
│   ├── ci.yml
│   └── render-live-check.yml
├── migrations/
├── public/
│   ├── app.html          # Mini App khách hàng
│   ├── admin.html
│   ├── app.js
│   ├── admin.js
│   └── styles.css
├── scripts/
│   ├── build-static.js
│   ├── render-live-check.js
│   └── run-automations.js
├── src/                  # API, PostgreSQL, bảo mật và nghiệp vụ
├── test/
├── services/aiogram_bot/ # __init__.py tương thích import cũ
├── .env.example
├── package.json
└── render.yaml
```

Bot grammY chính được đặt trong `apps/bot_chinh/`; Aiogram phụ trong `apps/bot_phu/`. Hai bot có token riêng và gọi chung API nghiệp vụ Node.js. Thư mục `services/aiogram_bot/` chỉ còn package tương thích trong thời gian chuyển đổi, không còn chứa source bot.

## 3. Tự động hóa

Trong Admin Dashboard, mục **Điều phối tác vụ hệ thống** hỗ trợ bật/tắt từng tác vụ, chọn chu kỳ chạy, xem lần chạy gần nhất/trạng thái và bấm **Chạy ngay**.

| Tác vụ | Hành vi |
|---|---|
| Dọn phiên đăng nhập | Xóa session hết hạn và lịch sử automation cũ hơn 180 ngày |
| Thông báo ticket mới | Gửi ticket mới đến Telegram Admin khi có BOT1_TOKEN và ADMIN_TELEGRAM_ID |
| Nhắc ticket chờ | Nhắc ticket open/processing đã tồn tại hơn 60 phút; nhắc lại sau mỗi 6 giờ |

Các lần chạy được ghi vào `automation_runs`; cấu hình nằm trong `automation_settings`. Mỗi tác vụ có PostgreSQL advisory lock để tránh hai tiến trình thực thi cùng lúc. Chu kỳ có thể chọn từ 1 phút đến 7 ngày.

Không tự tạo số dư, đơn hàng hoặc giao dịch giả. Việc cộng tiền, hoàn tiền, xác nhận chuyển khoản, cấp OTP/Proxy hay thuê Tool chỉ được triển khai khi có API nhà cung cấp thật, xác minh webhook, chống giao dịch trùng và đối soát.

### Lưu ý gói Free

- Render Free Web Service tự spin down sau 15 phút không nhận HTTP/WebSocket traffic; lần truy cập kế tiếp cần chờ service khởi động lại (thường khoảng một phút). Bộ hẹn giờ trong API dừng theo process, nên các automation có thể chạy trễ.
- Render PostgreSQL Free giới hạn 1 GB, tự hết hạn sau 30 ngày và không có backup tự động. Không dùng database Free này để lưu ví, thanh toán hoặc dữ liệu khách hàng production lâu dài.
- Render Cron Job không có gói Free. Muốn chạy Cron trực tiếp trên Render cần chọn gói trả phí; lựa chọn khác là dùng scheduler bên ngoài gọi lệnh bên dưới.

Khi cần chạy định kỳ ổn định, cấu hình Render Cron Job (trả phí) hoặc scheduler bên ngoài để chạy:

```bash
npm run automation:run
```

Lệnh dùng cùng biến môi trường và PostgreSQL, thực hiện các tác vụ đến hạn rồi thoát. Cron của Render dùng UTC; ví dụ mỗi phút là `* * * * *`. Có thể chuyển sang service Always-On phù hợp nếu cần worker liên tục.

## 4. PostgreSQL tại Render hoặc bên ngoài

Blueprint **không tạo database mặc định** và không tự kết nối vào database hiện có. API nhận kết nối qua `DATABASE_URL` (hoặc `EXTERNAL_DATABASE_URL`).

1. Tạo database PostgreSQL riêng tại Render, Neon, Supabase, Railway hoặc nhà cung cấp tương thích.
2. Tạo database/user riêng cho HENDY; tránh dùng superuser hoặc database nghiệp vụ khác.
3. Đặt connection string trong biến môi trường bí mật `DATABASE_URL` của API Web Service.
4. Với PostgreSQL truy cập từ Internet, bật TLS: `PGSSLMODE=require`. Khi cần xác minh đầy đủ CA/hostname, dùng `PGSSLMODE=verify-full` cùng `PGSSL_CA_CERT`.
5. Đặt `PG_POOL_MAX=5` hoặc thấp hơn nếu nhà cung cấp giới hạn kết nối.
6. Dùng connection string trực tiếp hoặc pooler ở chế độ session. Không dùng transaction-pooling endpoint cho phiên bản này vì migration và khóa chống chạy automation trùng nhau sử dụng PostgreSQL session advisory lock.
7. Migrations tự động chạy khi API khởi động. Sao lưu database trước khi chạy migration production.

| Biến | Ý nghĩa |
|---|---|
| `DATABASE_URL` | PostgreSQL connection string (bắt buộc) |
| `PGSSLMODE` | `require` mặc định production; `verify-full` để xác minh certificate; `disable` chỉ dùng local/test phù hợp |
| `PGSSL_CA_CERT` | CA PEM nếu dùng `verify-full` |
| `PG_POOL_MAX` | Kích thước pool, mặc định 5, tối đa 20 |

Nếu database nằm trên Render cùng khu vực, có thể dùng internal URL và cấu hình SSL theo yêu cầu của database. Không đưa DATABASE_URL vào Static Site, HTML/JS hoặc trình duyệt.

## 5. Triển khai Render Blueprint

Mở [Render Blueprint](https://dashboard.render.com/blueprint/new?repo=https://github.com/hendyngo-servers/hendydichvumang-0), chọn repository `hendyngo-servers/hendydichvumang-0`, branch `main`, file `render.yaml`, rồi Apply.

Blueprint tạo ba service (không tự tạo database):

- `hendydichvumang-api`: Node.js Web Service, Node 22.
- `hendydichvumang-site`: Static Site, homepage `/`, Mini App `/app`, Admin `/admin`.
- `hendydichvumang-aiogram-bot`: Python Web Service, bot pilot tách biệt với grammY.

### Những giá trị Render tự sinh

Render Blueprint tự tạo secret ngẫu nhiên 256-bit (32 byte) cho:

- `ADMIN_PASSWORD`: mật khẩu Admin lần đầu; username mặc định là `admin`.
- `BOT_SERVICE_KEY`: khóa API ↔ Aiogram.
- `TELEGRAM_WEBHOOK_SECRET`: webhook grammY.
- `AIOGRAM_WEBHOOK_SECRET`: webhook Aiogram.

Các giá trị `generateValue: true` chỉ được sinh khi biến chưa tồn tại; sync sau giữ nguyên secret đã có. Lưu `ADMIN_PASSWORD` từ Render Environment sau lần tạo đầu tiên. Nếu một service cũ đang chứa placeholder, xóa placeholder một lần rồi sync Blueprint để Render sinh giá trị mới.

Session đăng nhập không dùng một secret tĩnh bổ sung: Node sinh token ngẫu nhiên 32 byte cho mỗi phiên và chỉ lưu SHA-256 hash trong PostgreSQL.

### Những thông tin cần chủ hệ thống cấp

1. `DATABASE_URL`: PostgreSQL thật mà HENDY sẽ sử dụng; không tự đổi sang database rỗng.
2. `BOT1_TOKEN`: token BotFather của bot grammY chính.
3. `BOT2_TOKEN`: token BotFather khác biệt nếu bật Aiogram.
4. `ADMIN_TELEGRAM_ID`: Telegram ID được chủ hệ thống xác nhận để nhận quyền/quản trị bot.
5. `RENDER_API_KEY`: chỉ cần khi sử dụng Render Operations Inventory / Live Verification; tự tạo tại Render Dashboard, không đưa vào source.

### Các giá trị được tự nhận diện và kết nối

Không nhập domain thủ công:

- API lấy domain website qua `fromService` từ Static Site.
- Static Site nhận API URL để build `config.js`.
- Mini App tự tạo URL `PUBLIC_APP_URL + /app`.
- Aiogram nhận API URL, DB URL, service key và Mini App URL từ API.
- Webhook mỗi bot sử dụng URL công khai riêng của service tương ứng.
- Runtime tự đọc Render service ID/name/type, hostname, branch và commit.
- Telegram bot username/ID được lấy từ Bot API `getMe()`.

### Trình tự triển khai

1. Cung cấp `DATABASE_URL`, `BOT1_TOKEN` và các thông tin bắt buộc của API.
2. Nếu dùng Aiogram, cung cấp thêm `BOT2_TOKEN` riêng; token này không được trùng bot chính.
3. Apply Blueprint và đợi API/PostgreSQL migrations sẵn sàng.
4. Kiểm tra API `/healthz`, Website `/`, `/app`, `/admin`.
5. Workflow Live Verification tự nhận diện domain/workspace/service, đợi deploy đúng commit LIVE và chạy smoke tests.
6. Nếu dùng custom domain, thêm domain tại Render và cấu hình DNS ở registrar. HENDY tự đọc trạng thái xác minh nhưng không tự tạo DNS record hoặc đổi canonical host.

Render service Free có thể sleep; đây là cấu hình pilot, không phải cam kết bot production 24/7.

| Biến | Nguồn |
|---|---|
| `DATABASE_URL` | Chủ hệ thống / PostgreSQL provider |
| `BOT1_TOKEN`, `BOT2_TOKEN` | BotFather |
| `ADMIN_TELEGRAM_ID` | Chủ hệ thống xác nhận |
| `ADMIN_PASSWORD` | Render sinh tự động |
| `BOT_SERVICE_KEY`, `TELEGRAM_WEBHOOK_SECRET`, `AIOGRAM_WEBHOOK_SECRET` | Render sinh tự động |
| `PUBLIC_APP_URL`, `PUBLIC_MINI_APP_URL`, `HENDY_API_BASE_URL` | Blueprint tự nối giữa services |
| `RENDER_WORKSPACE_ID`, service IDs, public URLs | Tự dò từ Render metadata; có thể override khi bị trùng |

## 6. Chạy local

Yêu cầu Node.js 22+ và PostgreSQL.

```bash
cp .env.example .env
# Sửa DATABASE_URL và các biến trong .env
npm ci
npm run verify
npm start
```

API local chạy tại `http://localhost:10000` (health check `/healthz`). Build Static Site để tạo trang giới thiệu tại `/`, Mini App tại `/app` và Admin tại `/admin` trong `dist-site`.

Build Static Site riêng:

```bash
STATIC_API_BASE_URL=https://<api-url> npm run build:static
```

Thư mục publish là `dist-site`. File `config.js` được sinh lúc build chỉ chứa public API origin, không chứa token hoặc database credentials.

## 7. Bảo mật

- Không commit `.env`, Bot Token, API key, mật khẩu hoặc PostgreSQL URL.
- Telegram initData được xác minh HMAC-SHA-256 và kiểm tra thời hạn, duplicate query key ở server.
- Session token ngẫu nhiên; chỉ SHA-256 hash được lưu trong PostgreSQL.
- Admin role được kiểm tra ở backend.
- Telegram webhook xác minh secret header.
- Helmet CSP, CORS allow-list, rate limit, JSON body limit và audit log được bật.
- Nếu một Bot Token thật từng được commit hoặc chia sẻ, hãy thu hồi qua BotFather, tạo token mới và chỉ lưu token mới trong Render Environment.

## 8. API chính

| Method | Path | Chức năng |
|---|---|---|
| GET | `/healthz` | Kiểm tra API và PostgreSQL |
| POST | `/telegram/webhook` | Telegram webhook |
| POST | `/api/telegram/auth` | Xác thực Telegram Mini App |
| GET | `/api/config` | Cấu hình giao diện công khai |
| GET | `/api/me` | Hồ sơ và số lượng ticket |
| GET | `/api/dashboard` | Tổng quan tài khoản |
| GET | `/api/services` | Danh mục dịch vụ công khai |
| GET/POST | `/api/tickets` | Xem/tạo ticket |
| POST | `/api/admin/login` | Đăng nhập Admin |
| GET | `/api/admin/stats` | Thống kê Admin |
| GET/POST/PATCH | `/api/admin/services` | Quản lý danh mục |
| PATCH | `/api/admin/tickets/:id` | Cập nhật trạng thái/ghi chú ticket |
| GET/PATCH | `/api/admin/automations` | Xem/cấu hình tác vụ |
| POST | `/api/admin/automations/:code/run` | Chạy tác vụ ngay |
| POST | `/api/logout` | Thu hồi session |

## 9. Kiểm tra và triển khai

GitHub Actions kiểm tra cú pháp Node.js, chạy test với PostgreSQL 18, cài aiogram 3.31.0, compile Python, chạy unit/integration test FSM và build thử Static Site.

Cập nhật source/Blueprint không đồng nghĩa với việc đã deploy lên Render. Sau khi Apply Blueprint, nhập database URL và secrets, hãy kiểm tra deploy cùng endpoint `/healthz` trước khi đưa hệ thống vào sử dụng. Gói Free có thể ngủ; không dùng database Free hết hạn/lưu trữ hạn chế cho ví hoặc thanh toán production lâu dài.

## Render Live Verification — tự nhận diện sau deploy

Workflow `.github/workflows/render-live-check.yml` chạy sau CI thành công trên `main`, theo lịch 15 phút và có thể chạy thủ công.

### GitHub Actions cấu hình

**Secret:**
- `RENDER_API_KEY`: Render API key do chủ tài khoản tự tạo trong Render Dashboard; chỉ dùng server-side để đọc services/deploy/custom domains.

**Variables:**
- `RENDER_LIVE_CHECK_ENABLED=true`
- `RENDER_WORKSPACE_ID`: tùy chọn override; mặc định tự suy ra từ ownerId của service đúng repository.
- `RENDER_STATIC_SERVICE_ID`, `RENDER_API_SERVICE_ID`, `RENDER_AIOGRAM_SERVICE_ID`: tùy chọn override.
- `RENDER_AIOGRAM_CHECK_ENABLED`: mặc định `auto`; đặt `false` để bỏ qua hoặc `true` để yêu cầu Aiogram phải có mặt.

Không cần cấu hình `STATIC_SITE_URL`, `API_BASE_URL`, `AIOGRAM_BASE_URL` hoặc service IDs trong trường hợp bình thường. Script tự dò bằng tên service + repository, đọc domain mặc định, custom-domain status, đợi đúng commit `LIVE`, rồi kiểm tra Website, Mini App, Admin, API, PostgreSQL và health webhook Aiogram nếu service tồn tại. Kết quả được ghi vào GitHub Actions Summary.

### Chạy thủ công

Yêu cầu Node.js 22+:

```bash
RENDER_API_KEY=... npm run verify:render-live
```

`RENDER_WORKSPACE_ID` có thể để trống nếu workspace được suy ra duy nhất từ metadata Render. Không ghi API key vào terminal history hoặc commit.

### Giới hạn

Live smoke test không thực hiện giao dịch tiền, không gửi OTP, không tạo Telegram Bot Token, không chuyển database và không sửa DNS. Custom domain được phát hiện/đọc trạng thái sau khi chủ hệ thống đã thêm domain và cấu hình DNS.
 
## Aiogram — bot Python thử nghiệm

Phân tích source upstream, quyết định module, kiến trúc tích hợp, bảo mật và hướng dẫn thử nghiệm nằm tại [docs/AIOGRAM-INTEGRATION.md](docs/AIOGRAM-INTEGRATION.md). Aiogram chạy thành dịch vụ Python riêng với token khác grammY; Node.js API và PostgreSQL vẫn quản lý dữ liệu nghiệp vụ. Không dùng chung Bot Token giữa hai framework.

Biến cần cấu hình cho dịch vụ Python: `BOT2_TOKEN` (token bot thử nghiệm riêng), `AIOGRAM_WEBHOOK_SECRET` (Render tự sinh), `HENDY_API_BASE_URL`, `HENDY_BOT_SERVICE_KEY`, `DATABASE_URL`, `PGSSLMODE`, `PUBLIC_APP_URL` và `AIOGRAM_FSM_TTL_HOURS`. Blueprint liên kết API key và PostgreSQL từ API Web Service; không đưa secrets vào frontend.




## 10. Kho module có kiểm duyệt (tích hợp chọn lọc từ HENDY-BOT-CYBERTECH)

Hệ thống hỗ trợ lưu trữ artifact/module trong PostgreSQL để quản trị viên gửi tệp, kiểm tra checksum SHA-256 và duyệt trước khi đưa vào quy trình phát hành. Đây là kho tệp không thực thi mã.

- Chỉ nhận JSON, Markdown, TXT và CSS UTF-8, tối đa 1 MB/tệp.
- Từ chối PHP, JavaScript, HTML, SVG, archive và mọi MIME không nằm trong allowlist.
- Tệp được lưu dạng BYTEA trong PostgreSQL với trạng thái pending_review; API không đưa nội dung tệp ra frontend công khai.
- Chỉ quản trị viên đã đăng nhập mới được liệt kê, tải nội dung hoặc duyệt.
- Duyệt chỉ đổi trạng thái thành approved hoặc rejected; không có chức năng chạy file, ghi đè source code hoặc tự deploy.
- Mọi thao tác upload/review đều có audit log và SHA-256 để đối chiếu.

API:
| Method | Path | Chức năng |
|---|---|---|
| GET | /api/admin/modules | Danh sách artifact |
| POST | /api/admin/modules/:moduleKey | Upload raw body application/octet-stream; tên file ở X-File-Name, MIME ở X-Artifact-Mime |
| PATCH | /api/admin/modules/:id/review | Duyệt hoặc từ chối artifact đang chờ |
| GET | /api/admin/modules/:id/content | Tải artifact nội bộ với quyền Admin |

Migration 006_module_artifacts.sql tạo bảng lưu trữ. Đây là phần có thể tái sử dụng an toàn từ ý tưởng ModuleRegistry của archive. Không sao chép PHP runtime hoặc chạy tệp upload trong Node/Python. Muốn phát hành code phải tạo pull request, qua review và CI rồi mới deploy.

### Những phần archive chưa được nhập nguyên xi

- PHP BotService.php / ApiController.php: không chép vào Node/grammY/Aiogram vì sẽ tạo backend nghiệp vụ thứ hai.
- Schema ví, đơn hàng, giao dịch D1/MySQL: không nhập vào PostgreSQL hiện tại và không dual-write.
- Ratchet WebSocket: ý tưởng event log + PostgreSQL NOTIFY có thể port riêng; không mang nguyên PHP process vào Render Node service.
- App Store / Google Play / Microsoft Store connectors: chưa có credential/tài khoản nhà phát hành và một số adapter chỉ là khung; không bật tính năng publish.
- Queue/Vietsub/payment: chỉ tích hợp khi có provider thật, chữ ký webhook, idempotency, retry và đối soát.


## Cấu trúc ứng dụng sau khi chuẩn hóa

- `apps/bot_chinh/`: mã nguồn grammY cho bot production `@HendyBot_Bot`.
- `apps/bot_phu/`: mã nguồn Python Aiogram cho bot pilot; dùng token riêng.
- `apps/website/`: nguồn trang chủ giới thiệu HENDY; build ra `dist-site/index.html`.
- `public/app.html`: giao diện Mini App hiện hữu; Render rewrite `/app` đến file này.
- `src/`: API, PostgreSQL, bảo mật, cấu hình và nghiệp vụ dùng chung.
- `services/aiogram_bot/__init__.py`: package tương thích cho các import cũ; source chính thức đã chuyển sang `apps/bot_phu/`.

### URL Mini App tách khỏi URL trang chủ

`PUBLIC_APP_URL` là origin website để API kiểm tra CORS. `PUBLIC_MINI_APP_URL` là URL đầy đủ của Telegram Mini App, kết thúc bằng `/app`. Khi đổi tên Static Site, đồng bộ lại Blueprint để Render cập nhật giá trị `fromService`. Với custom domain riêng, có thể override `PUBLIC_APP_URL` và `PUBLIC_MINI_APP_URL` trong Environment của API; nếu không, hệ thống dùng domain HTTPS mặc định Render.

### Đổi tên Static Site hiện hữu trên Render

Blueprint hiện tạm giữ tên resource `hendydichvumang-site` để khớp service đang chạy, tránh tạo một Static Site thứ hai. Hãy đổi tên resource hiện hữu trực tiếp trong Render Dashboard trước; sau khi service đã đổi tên tại chỗ, cập nhật trường `name` trong `render.yaml` thành `hendydichvumang` rồi mới đồng bộ Blueprint. Không đổi riêng tên trong Blueprint trước khi resource cũ được đổi tên.


## Tích hợp chọn lọc HENDY Cybertech

Node.js + PostgreSQL tiếp tục là nguồn dữ liệu chính. Menu và router được tổ chức theo namespace canonical: grammY tại `apps/bot_chinh/`, Aiogram tại `apps/bot_phu/`; code Aiogram mới sử dụng namespace `apps.bot_phu`.

Mini App hiện tại giữ nguyên xác thực Telegram và API Node.js. Giao diện bảy màu từ ZIP được chuyển thành CSS theme/component, không thay bằng trang PHP vốn phụ thuộc các API chưa tồn tại.

Migration `007_commerce_readiness.sql` chuẩn bị schema riêng cho thanh toán, đơn hàng, VIP, Voucher, Giftcode, referral, Video Jobs, ledger và đối soát. Tất cả module được khóa mặc định. Xem [điều kiện mở module](docs/COMMERCE-MODULES-READINESS.md).

Tên token chuẩn: `BOT1_TOKEN` cho grammY và `BOT2_TOKEN` cho Aiogram. `BOT_TOKEN` và `AIOGRAM_BOT_TOKEN` chỉ là bí danh tương thích chuyển tiếp. Không ghi token thật vào source code, log, URL hoặc frontend. Mật khẩu quản trị production yêu cầu tối thiểu 13 ký tự.


### Menu Bot chính grammY

Menu Bot 1 có 9 nút theo ảnh tham chiếu: Minigame Live, OTP & Proxy, Nạp tiền, Trung tâm VIP, Mua lì xì/Code màn/Code Tele, Trung tâm khách hàng, Nhóm chat, Kênh thông báo và Dịch vụ sắp triển khai.

Telegram Reply Keyboard hỗ trợ kiểu màu chuẩn `danger`, `success`, `primary`; màu/gradient tùy chỉnh khác do Telegram client kiểm soát. Hai liên kết nhóm/kênh được cấu hình bằng `TELEGRAM_GROUP_URL` và `TELEGRAM_CHANNEL_URL` (chỉ HTTPS `t.me` hoặc `telegram.me`).

Các nút nạp tiền, VIP, Minigame và OTP/Proxy hiện hiển thị thông báo đang hoàn thiện; không tạo giao dịch giả. Nút danh mục chỉ liệt kê dịch vụ được quản trị viên công khai, chưa thực hiện mua hàng.
