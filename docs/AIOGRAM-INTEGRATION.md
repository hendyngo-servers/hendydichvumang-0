# Phân tích mã nguồn aiogram và thiết kế tích hợp HENDY

**Ngày rà soát:** 05/11/2026  
**Upstream:** https://github.com/aiogram/aiogram  
**Tag đã pin:** v3.31.0 · **Branch đối chiếu:** dev-3.x  
**Telegram Bot API:** 10.3 · **Python:** 3.11+ · **License:** MIT

## 1. Kết luận kỹ thuật

Không nên chép toàn bộ repository aiogram vào HENDY hoặc chuyển Node.js API sang Python chỉ để dùng framework bot. Aiogram là framework Python, còn backend hiện hữu của HENDY là Node.js/Express + PostgreSQL + Static Site. Các lớp API Telegram đã được package chính thức cung cấp; sao chép các lớp đó sẽ tạo một bản fork phải tự bảo trì.

Thiết kế được áp dụng là hybrid có ranh giới rõ ràng:

- grammY tiếp tục là bot production hiện tại.
- aiogram chạy trong một Python Web Service riêng làm bot pilot, bắt buộc dùng Bot Token khác.
- Node.js API vẫn là nguồn dữ liệu nghiệp vụ duy nhất: hồ sơ khách hàng, ví, danh mục, ticket và audit log.
- PostgreSQL được chia sẻ; Node API quản lý nghiệp vụ, Python chỉ dùng bảng FSM riêng.
- Telegram webhook của bot pilot được nhận bởi aiohttp + SimpleRequestHandler; không chạy polling song song.
- Mini App/Static Site không thay đổi và không nhận secret server.

Cách này cho phép đánh giá aiogram mà không chiếm webhook của grammY, không nhân đôi logic ví/ticket và không cần chuyển toàn bộ hệ thống trong một lần.

## 2. Bản đồ source upstream

| Khu vực source | Trách nhiệm | Quyết định cho HENDY |
|---|---|---|
| `aiogram/client/` | Bot client, HTTP session, request/response Telegram API | Dùng Bot client chính thức |
| `aiogram/methods/` | Các method Bot API được sinh tự động | Không sao chép; cài package pin |
| `aiogram/types/`, `enums/` | Pydantic models và enum Bot API | Dùng trực tiếp từ package |
| `dispatcher/dispatcher.py` | Root Dispatcher, nhận update, workflow data, lifecycle và FSM middleware | Dùng Dispatcher riêng cho bot pilot |
| `dispatcher/router.py` | Cây Router, nested propagation, lifecycle events | Chia common/customer/support |
| `dispatcher/event/telegram.py` | Observer, filter chain, middleware và dừng propagation khi handler đầu tiên khớp | Dùng decorator handler và filter chain |
| `dispatcher/event/handler.py` | Phân tích tham số callback, filter result, inject context | Dùng dependency injection của aiogram |
| `filters/`, Magic Filter | Command, callback, text và field filters | Dùng `Command`, `CommandStart`, `F` |
| `dispatcher/middlewares/` | Middleware ở mức update/event và handler | Đồng bộ hồ sơ tại Dispatcher.message outer middleware |
| `fsm/` | State, FSMContext, strategy, storage, event isolation, scenes | Dùng StatesGroup và storage PostgreSQL |
| `webhook/aiohttp_server.py` | Simple/Token-based webhook handler và lifecycle app | Dùng SimpleRequestHandler + secret header |
| `utils/i18n/` | GNU Gettext/Babel và locale middleware | Hoãn; mặc định tiếng Việt |
| `handlers/` | Class-based handlers | Hoãn; function handlers hiện đủ gọn |
| `utils/` | HTML/Markdown, magic filter, backoff, deep links | Dùng helper HTML chính thức, không ghép HTML thô |

Source file quan trọng của tag v3.31.0:

- https://github.com/aiogram/aiogram/blob/v3.31.0/aiogram/dispatcher/dispatcher.py
- https://github.com/aiogram/aiogram/blob/v3.31.0/aiogram/dispatcher/router.py
- https://github.com/aiogram/aiogram/blob/v3.31.0/aiogram/dispatcher/event/telegram.py
- https://github.com/aiogram/aiogram/blob/v3.31.0/aiogram/dispatcher/event/handler.py
- https://github.com/aiogram/aiogram/blob/v3.31.0/aiogram/filters/command.py
- https://github.com/aiogram/aiogram/blob/v3.31.0/aiogram/fsm/storage/base.py
- https://github.com/aiogram/aiogram/blob/v3.31.0/aiogram/fsm/storage/memory.py
- https://github.com/aiogram/aiogram/blob/v3.31.0/aiogram/webhook/aiohttp_server.py
- https://github.com/aiogram/aiogram/blob/v3.31.0/aiogram/utils/i18n/middleware.py

## 3. Quyết định module quan trọng

### 3.1 Dispatcher, Router và Dependency Injection

Dispatcher là Router gốc. Router con có thể gắn lồng; mỗi event observer có filter và middleware riêng. Handler chỉ chạy khi filter chain khớp. Dispatcher đưa workflow data vào callback dựa theo tên tham số handler khai báo.

HENDY chia handler:
- `common.py`: /start, /help, /cancel, menu cố định và hiển thị mục quản trị khi API xác nhận quyền.
- `customer.py`: /customer, /balance, /services và Mini App.
- `support.py`: quy trình FSM tạo ticket hai bước.
- `admin.py`: thống kê, danh sách ticket, xem nội dung và cập nhật trạng thái ticket.
- `automation.py`: bật/tắt và chạy thủ công các tác vụ đã allowlist, có bước xác nhận.
- `errors.py`: lỗi update tập trung; log chỉ ghi loại exception để tránh lộ URL/secret.

Router được include theo thứ tự xác định. Handler khớp đầu tiên kết thúc propagation trừ khi chủ động yêu cầu skip.

### 3.2 Middleware

Aiogram phân biệt outer middleware (bao quanh event propagation) và inner middleware (sau khi handler/filter đã được chọn). Middleware HENDY đồng bộ hồ sơ Telegram trước khi xử lý tin nhắn riêng.

Middleware được gắn một lần tại `Dispatcher.message`, không gắn riêng lên từng child router. Việc này tránh cùng một update bị upsert hồ sơ nhiều lần khi lần lượt đi qua các router common/customer/support/admin/automation. Context data được aiogram inject vào handler.

### 3.3 Filters

- `CommandStart()` và `Command("customer")` phân tích lệnh cùng bot mention.
- `F.chat.type == "private"` giới hạn chức năng tài khoản ở chat riêng.
- `F.text` và `F.data` xử lý text/callback.
- Không dùng một handler tổng để so chuỗi mọi tin nhắn; tách router/filter giúp giảm va chạm khi thêm tính năng.

### 3.4 FSM và PostgreSQL storage

Upstream `StorageKey` gồm bot_id, chat_id, user_id, thread_id, business_connection_id và destiny. Không nên chỉ dùng Telegram user ID làm khóa vì có thể trộn bot, chat hoặc topic.

`MemoryStorage` dùng dict trong RAM và mất toàn bộ state khi process restart. Đây là rủi ro đối với ticket đang nhập dở khi Render restart/sleep.

HENDY triển khai:
- `PostgresFSMStorage(BaseStorage)`: lưu state + JSONB data với TTL.
- Key hash SHA-256 bao gồm toàn bộ StorageKey; không đưa raw chat/user id vào tên khóa.
- `PostgresEventIsolation(BaseEventIsolation)`: PostgreSQL advisory lock theo cùng key để hai update của cùng user/chat không sửa state đồng thời.
- `migrations/004_aiogram_fsm.sql`: bảng `bot_fsm_states` và index TTL.
- Automation `cleanup_sessions` xóa FSM row hết hạn; read path cũng xóa row hết hạn khi được truy cập.

State và data được ghi riêng; Dispatcher FSM middleware dùng event isolation để tuần tự hóa update cùng key. Nếu thay storage sau này, phải giữ contract BaseStorage và event isolation.

**Không dùng Redis ở giai đoạn này:** PostgreSQL đã là dependency cốt lõi. Thêm Redis chỉ để lưu FSM sẽ thêm secret, kết nối, giám sát và chi phí. RedisStorage phù hợp khi FSM tải lớn hoặc cần tách tài nguyên.

### 3.5 Webhook và bảo mật

Upstream có SimpleRequestHandler cho một Bot instance và TokenBasedRequestHandler cho nhiều bot qua URL. Source cảnh báo TokenBasedRequestHandler đưa token vào URL, có thể lọt vào access log.

HENDY dùng SimpleRequestHandler:
1. Bot pilot đăng ký webhook ở URL của Python Web Service.
2. Telegram gửi header `X-Telegram-Bot-Api-Secret-Token`.
3. Aiogram so sánh secret bằng `secrets.compare_digest`.
4. URL webhook không chứa Bot Token.
5. `handle_in_background=False` để webhook chỉ được xác nhận sau khi handler xử lý; PostgreSQL event isolation giữ thứ tự update cùng người dùng.

Startup gọi Node API lấy bot ID grammY chính và so sánh với bot ID của token pilot. Nếu ID giống nhau, service dừng trước `set_webhook`, tránh bot pilot chiếm webhook bot chính.

### 3.6 Error handling

Router lỗi tập trung ghi log phía server và trả thông báo tổng quát cho người dùng. Lỗi API nội bộ được chuyển thành HendyApiError; handler ticket có thể giữ FSM để khách thử lại. Không trả stack trace, connection string, service key hoặc token về Telegram.

### 3.7 I18n, Scenes và Payments

| Module | Quyết định | Lý do |
|---|---|---|
| GNU Gettext/Babel | Hoãn | Bot hiện mặc định tiếng Việt; thêm khi có yêu cầu đa ngôn ngữ |
| FSM Scenes | Hoãn | Ticket hai bước đủ với StatesGroup |
| Telegram Payments/Stars/pre-checkout | Chưa bật | Chưa có gateway, idempotency, chữ ký webhook và đối soát đầy đủ |
| Inline mode/business messages/paid media/games | Chưa bật | Không thuộc phạm vi Mini App/CSKH hiện tại |
| Polling | Không dùng trên service này | Bot pilot chạy webhook; không chạy polling cùng token |
| Class-based handlers | Chưa dùng | Function handlers dễ kiểm thử hơn cho nghiệp vụ hiện tại |

## 4. Luồng xử lý

1. Telegram gửi update vào webhook riêng của aiogram service.
2. SimpleRequestHandler xác thực webhook secret.
3. Dispatcher tạo context; FSM event isolation khóa StorageKey.
4. Middleware đồng bộ hồ sơ khách hàng với Node API một lần cho private message.
5. Router + filter chọn handler common/customer/support/admin/automation.
6. Handler gọi Node API qua HTTPS và `X-Hendy-Service-Key`.
7. Node API xác minh `ADMIN_TELEGRAM_ID` cho API quản trị; các thao tác ticket/automation được ghi audit log.
8. Node API là nguồn dữ liệu và bộ thực thi automation duy nhất; Aiogram không tự ghi trực tiếp bảng nghiệp vụ và không khởi chạy scheduler thứ hai.
9. FSM cập nhật state/JSONB trong PostgreSQL; khi ticket hoàn tất, Node API ghi ticket và FSM được clear.
10. Health endpoint kiểm tra PostgreSQL cùng trạng thái webhook đã đăng ký.

Node API bổ sung các route nội bộ:
- `GET /internal/bot/primary-identity`
- `POST /internal/bot/users/upsert`
- `GET /internal/bot/users/:telegramId/balance`
- `POST /internal/bot/tickets`
- `GET /internal/bot/admin/:telegramId/access`
- `GET /internal/bot/admin/:telegramId/stats`
- `GET /internal/bot/admin/:telegramId/tickets`
- `PATCH /internal/bot/admin/:telegramId/tickets/:ticketId`
- `GET /internal/bot/admin/:telegramId/automations`
- `PATCH /internal/bot/admin/:telegramId/automations/:code`
- `POST /internal/bot/admin/:telegramId/automations/:code/run`

Tất cả route nội bộ yêu cầu header `X-Hendy-Service-Key`. API xác minh Telegram ID quản trị từ `ADMIN_TELEGRAM_ID` phía server và chỉ nhận mã automation nằm trong danh sách cố định. Khóa tối thiểu 32 ký tự, chỉ lưu trong Render Environment, không đưa vào Static Site. Service Aiogram không gửi Bot Token đến API.

## 5. Các file tích hợp

```text
apps/bot_phu/
├── main.py
├── config.py
├── api_client.py
├── fsm_storage.py
├── middlewares.py
├── routers/
│   ├── common.py
│   ├── customer.py
│   ├── support.py
│   ├── admin.py
│   ├── automation.py
│   └── errors.py
├── tests/
└── requirements.txt
migrations/004_aiogram_fsm.sql
```

- `main.py`: aiohttp app, Dispatcher, Router wiring, webhook startup/health/shutdown.
- `config.py`: kiểm tra token khác grammY, HTTPS origins, PostgreSQL TLS, service key, FSM TTL, webhook path.
- `api_client.py`: aiohttp session dùng lại, timeout và xử lý lỗi Node API.
- `fsm_storage.py`: PostgreSQL BaseStorage và event isolation.
- `middlewares.py`: đồng bộ customer.
- `routers/admin.py`: panel quản trị, thống kê và cập nhật ticket qua API.
- `routers/automation.py`: điều khiển tác vụ allowlist ở Node API.
- `routers/`: lệnh bot và quy trình khách hàng.
- `tests/`: config, keyboard, storage key, PostgreSQL persistence, TTL và event isolation.

## 6. CI và triển khai Render

GitHub Actions dùng Python 3.12, cài aiogram pin 3.31.0, chạy `compileall` và các Python unit tests; cùng workflow chạy `npm run verify` cho Node API/migrations và build Static Site. Test FSM hiện kiểm tra StorageKey/event-isolation contract; cần chạy soak test với PostgreSQL thật sau khi deploy để xác nhận persistence và concurrency ở môi trường Render.

Blueprint khai báo `hendydichvumang-aiogram-bot` (Python 3.12.10). Phân loại cấu hình:
- `BOT2_TOKEN`: token bot pilot do chủ hệ thống lấy từ BotFather; bắt buộc khác `BOT1_TOKEN`.
- `AIOGRAM_WEBHOOK_SECRET`: Render tự sinh 256-bit bằng `generateValue: true`.
- `HENDY_API_BASE_URL`, `HENDY_BOT_SERVICE_KEY`: tự liên kết Node API qua `fromService`; key không chép vào source.
- `DATABASE_URL`, `PGSSLMODE`: tự liên kết từ service API; API vẫn cần PostgreSQL URL hợp lệ do chủ hệ thống cung cấp.
- `PUBLIC_APP_URL`, `PUBLIC_MINI_APP_URL`: lấy qua API và domain Static Site; Mini App tự ghép `/app`.
- Webhook base của Aiogram lấy từ Render `RENDER_EXTERNAL_URL`; không cần nhập host thủ công.
- `AIOGRAM_FSM_TTL_HOURS`: 1–168 giờ, mặc định 24.

Triển khai Node API/migrations trước, sau đó mới deploy Python service. Free Web Service có thể sleep; cấu hình Free chỉ phù hợp pilot, không phải bot production 24/7.

## 7. Quy trình kiểm thử pilot

1. Tạo token bot thử nghiệm riêng qua BotFather; không dùng token chính.
2. Deploy Node API + PostgreSQL và kiểm tra `/healthz`.
3. Cung cấp `BOT1_TOKEN` (và nếu bật pilot thì `BOT2_TOKEN` riêng) qua Render Environment.
4. Blueprint tự sinh webhook secrets, admin password và service key; Render chuyển service key/API URLs/DB URLs sang Aiogram qua `fromService`.
5. Kiểm tra Python `/healthz`: `status=ok`, `database=ready`, `webhook=ready`.
6. Thử `/start`, `/customer`, `/balance`, `/services` và menu Reply Keyboard.
7. Tạo ticket hai bước, kiểm tra ticket xuất hiện trong cùng Admin Dashboard và audit log.
8. Dùng Telegram ID quản trị đã cấu hình để thử `/admin`; tài khoản khác phải bị từ chối cả ở menu, callback và API.
9. Thử xem thống kê, đổi trạng thái ticket; xác nhận `updated_at` và audit log thay đổi.
10. Thử `/automation`, bật/tắt từng tác vụ, xác nhận chạy thủ công; mã ngoài allowlist phải bị từ chối.
11. Restart Python service giữa bước nhập ticket; state phải còn trong PostgreSQL đến TTL.
12. Gửi hai update liên tiếp cùng user để xác nhận event isolation.
13. Kiểm tra webhook info và xác nhận webhook grammY không bị thay đổi.

Chỉ cân nhắc chuyển bot production sang aiogram sau khi pilot chạy ổn định, có monitoring và soak test. Không thay grammY chính trong giai đoạn này.
