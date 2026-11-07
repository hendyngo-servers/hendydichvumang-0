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

1. Trong API Web Service, đặt `DATABASE_URL` là connection string PostgreSQL riêng vừa tạo.
2. Giữ `PGSSLMODE=require` cho PostgreSQL bên ngoài.
3. Tài khoản Admin mặc định là `admin`; Render tự tạo `ADMIN_PASSWORD` ngẫu nhiên 256-bit nếu biến chưa tồn tại. Lưu mật khẩu này vào password manager. Nếu service đã có mật khẩu riêng, Render giữ nguyên.
4. Cung cấp `BOT1_TOKEN` lấy từ BotFather cho grammY. Nếu triển khai Aiogram pilot, cung cấp `BOT2_TOKEN` khác hoàn toàn. Hai token này không thể tự sinh ngoài Telegram.
5. Không cần nhập domain API/Website/Mini App: Blueprint tự nối Static Site ↔ API ↔ Aiogram. Node.js tự ghép Mini App URL thành `PUBLIC_APP_URL/app`.
6. Render tự tạo `TELEGRAM_WEBHOOK_SECRET`, `BOT_SERVICE_KEY`, `AIOGRAM_WEBHOOK_SECRET` và `ADMIN_PASSWORD` dưới dạng secret 256-bit. Khi API có HTTPS và BOT1_TOKEN, webhook tự đăng ký vào API URL; Mini App sử dụng URL Static Site.
7. Kiểm tra `https://<api-url>/healthz`, cần có `status: ok` và `database: ready`.
8. Mở `https://<static-site-url>/` để xem trang chủ giới thiệu, `https://<static-site-url>/app` để mở Mini App từ Telegram, và `https://<static-site-url>/admin` để vào trang quản trị. Đặt URL `.../app` làm Mini App URL trong BotFather.
9. Khi bật Aiogram, triển khai service Python sau khi API và migration PostgreSQL đã Live; Render tự cấp webhook secret, domain, database URL và service key cho Aiogram.

- `ADMIN_PASSWORD`: mật khẩu Admin lần đầu; username mặc định là `admin`.
- `BOT_SERVICE_KEY`: khóa API ↔ Aiogram.
- `TELEGRAM_WEBHOOK_SECRET`: webhook grammY.
- `AIOGRAM_WEBHOOK_SECRET`: webhook Aiogram.

| Key | Bắt buộc | Ghi chú |
|---|---|---|
| `NODE_ENV` | Có | `production` |
| `DATABASE_URL` | Chủ sở hữu cung cấp | PostgreSQL hiện có/bên ngoài; không tự thay database để tránh tạo DB khác hoặc mất dữ liệu |
| `PGSSLMODE` | Có | Mặc định `require` |
| `ADMIN_USERNAME` | Tự động | Mặc định `admin`; có thể đổi trong Render Environment |
| `ADMIN_PASSWORD` | Tự tạo | Render tạo secret ngẫu nhiên 256-bit nếu chưa có giá trị; lưu vào password manager |
| `PUBLIC_APP_URL` | Tự động | Lấy domain HTTPS của Static Site qua `fromService`; chỉ override khi dùng custom domain |
| `PUBLIC_MINI_APP_URL` | Tự động | API tự ghép `PUBLIC_APP_URL + /app`; không nhập tay nếu không cần override |
| `BOT1_TOKEN` | Chủ sở hữu cung cấp | Token BotFather của grammY chính |
| `BOT2_TOKEN` | Chủ sở hữu cung cấp | Token BotFather riêng cho Aiogram pilot |
| `BOT_SERVICE_KEY` | Tự tạo | Render tạo secret ngẫu nhiên 256-bit; Aiogram nhận cùng giá trị qua `fromService` |
| `TELEGRAM_WEBHOOK_SECRET` | Tự tạo | Render tạo secret ngẫu nhiên 256-bit |
| `AIOGRAM_WEBHOOK_SECRET` | Tự tạo | Render tạo secret ngẫu nhiên 256-bit |
| `ADMIN_TELEGRAM_ID` | Chủ sở hữu cung cấp | Telegram ID để phân quyền Admin; để trống nếu chưa bật phân quyền/thông báo Telegram |
| `LEGACY_CUSTOMER_URL` | Tùy chọn | URL HTTPS trang khách hàng PHP cũ, không kèm token/secret |
| `PGSSL_CA_CERT` | Tùy chọn | CA PEM nếu dùng `verify-full` |
| `ALLOWED_ORIGINS` | Tự động | API/Website origin được tự thêm; chỉ cấu hình nếu có origin ngoài |
| `RENDER_API_KEY` | Chủ sở hữu cung cấp | Chỉ cần cho Operations Center/Render live check; tạo trong Render Account Settings |

## 6. Chạy local

Yêu cầu Node.js 22+ và PostgreSQL.

```bash
cp .env.example .env
npm run secrets:generate
# Nhập DATABASE_URL, BOT1_TOKEN/BOT2_TOKEN và ADMIN_TELEGRAM_ID nếu dùng
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
- RENDER_LIVE_CHECK_ENABLED = true
- RENDER_WORKSPACE_ID = ID workspace Render (tùy chọn; tự dò và phân trang khi API key chỉ thấy một workspace phù hợp).
- RENDER_STATIC_SERVICE_ID = ID Static Site (tùy chọn, script tự dò nếu bỏ trống).
- RENDER_API_SERVICE_ID = ID Web Service (tùy chọn, script tự dò nếu bỏ trống).
- Không cần khai báo `STATIC_SITE_URL` hoặc `API_BASE_URL`: script tự lấy domain từ Render API.
- RENDER_AIOGRAM_CHECK_ENABLED = `true` để bật xác minh bot pilot (mặc định tắt).
- RENDER_AIOGRAM_SERVICE_ID = ID dịch vụ Python (tùy chọn, script tự dò theo tên).

Không cần cấu hình `STATIC_SITE_URL`, `API_BASE_URL`, `AIOGRAM_BASE_URL` hoặc service IDs trong trường hợp bình thường. Script tự dò bằng tên service + repository, đọc domain mặc định, custom-domain status, đợi đúng commit `LIVE`, rồi kiểm tra Website, Mini App, Admin, API, PostgreSQL và health webhook Aiogram nếu service tồn tại. Kết quả được ghi vào GitHub Actions Summary.

### Chạy thủ công

Yêu cầu Node.js 22+:

```bash
RENDER_API_KEY=... \
npm run verify:render-live
```

`RENDER_API_KEY` là secret duy nhất bắt buộc cho live-check. Workflow tự tìm workspace nếu API key chỉ thấy một workspace; nếu API key có nhiều team workspace thì đặt `RENDER_WORKSPACE_ID` trong GitHub Actions Variables. Không cần nhập service ID hoặc domain. Không in/commit giá trị Render API key.

### Giới hạn kiểm tra

Trạng thái deployment live chỉ là một điều kiện. Lệnh còn gọi endpoint thật và kiểm tra PostgreSQL, nhưng không thực hiện giao dịch tiền, không gửi OTP và không giả lập thao tác mua hàng. Chỉ kết quả thành công mới xác nhận hai dịch vụ đang Live và endpoint cơ bản hoạt động tại thời điểm kiểm tra.

### Giới hạn

Live smoke test không thực hiện giao dịch tiền, không gửi OTP, không tạo Telegram Bot Token, không chuyển database và không sửa DNS. Custom domain được phát hiện/đọc trạng thái sau khi chủ hệ thống đã thêm domain và cấu hình DNS.
 
## Aiogram — bot Python thử nghiệm

Bảng phân loại cấu hình tự động, secret được tạo, giá trị cần chủ sở hữu cung cấp và lệnh tạo local secrets nằm tại [docs/ENVIRONMENT-AUTOMATION.md](docs/ENVIRONMENT-AUTOMATION.md). Phân tích source upstream và kiến trúc Aiogram nằm tại [docs/AIOGRAM-INTEGRATION.md](docs/AIOGRAM-INTEGRATION.md). Aiogram chạy thành dịch vụ Python riêng với token khác grammY; Node.js API và PostgreSQL vẫn quản lý dữ liệu nghiệp vụ. Không dùng chung Bot Token giữa hai framework.

Khi bật Aiogram, chủ hệ thống cung cấp `BOT2_TOKEN` riêng. Render tự sinh `AIOGRAM_WEBHOOK_SECRET`; Blueprint tự liên kết `HENDY_API_BASE_URL`, `HENDY_BOT_SERVICE_KEY`, `DATABASE_URL`, `PGSSLMODE`, `PUBLIC_APP_URL` và `PUBLIC_MINI_APP_URL` từ API. Không đưa secrets vào frontend.




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
