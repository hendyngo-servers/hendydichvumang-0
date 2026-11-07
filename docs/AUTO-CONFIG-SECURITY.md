# HENDY — ma trận tự nhận diện và tạo cấu hình an toàn

## Nguyên tắc

- Tự động đọc metadata đáng tin cậy từ Render và Telegram, không suy đoán dựa trên tên ứng dụng.
- Chỉ tự sinh secret thuộc quyền quản lý của HENDY/Render.
- Không tự sinh hoặc giả lập credentials của nhà cung cấp bên ngoài.
- Không bao giờ ghi secret vào Git, static assets, PostgreSQL business tables, audit log hoặc browser.
- Khi phát hiện mâu thuẫn (hai service cùng tên ở nhiều workspace, hai Bot Token trùng nhau, URL sai origin), dừng rõ ràng thay vì tự chọn bừa.

## Những gì tự nhận diện

| Thông tin | Nguồn phát hiện | Cách dùng |
|---|---|---|
| API domain | `RENDER_EXTERNAL_URL` của Node Web Service | Public API, webhook grammY |
| Website domain | `RENDER_EXTERNAL_URL` của Static Site qua `fromService` | Homepage, Mini App, Admin |
| Aiogram webhook host | `RENDER_EXTERNAL_URL` của Python Web Service | Webhook Aiogram |
| Render hostname | `RENDER_EXTERNAL_HOSTNAME` | Diagnostic/runtime inventory |
| Service ID, name, type | `RENDER_SERVICE_ID`, `RENDER_SERVICE_NAME`, `RENDER_SERVICE_TYPE` | Nhận diện loại service và dashboard |
| Git branch/commit | `RENDER_GIT_BRANCH`, `RENDER_GIT_COMMIT` | Xác minh deployment đúng source |
| Workspace | Render service `ownerId` trong API | Tự giới hạn inventory trong cùng owner/workspace khi duy nhất |
| Custom domain | Render API `/services/{id}/custom-domains` | Đọc domain name và verification status |
| grammY username/ID | Telegram Bot API `getMe()` | Hiển thị tên bot thật, không cần nhập username |
| Aiogram username/ID | Aiogram `get_me()` | Health endpoint và chẩn đoán |
| API/DB/key liên dịch vụ | Render Blueprint `fromService` / `fromDatabase` | Không sao chép giá trị thủ công |

Render runtime metadata được đọc từ các biến platform được tài liệu hóa. HENDY không phụ thuộc vào các biến `RENDER_*` nội bộ không được công bố.

## Những secret được tự tạo

| Secret | Nguồn sinh | Độ mạnh / vòng đời |
|---|---|---|
| `ADMIN_PASSWORD` | Render Blueprint `generateValue: true` | 256-bit khi biến chưa tồn tại; giữ nguyên qua sync sau |
| `BOT_SERVICE_KEY` | Render Blueprint `generateValue: true` | 256-bit; API -> Aiogram bằng `fromService` |
| `TELEGRAM_WEBHOOK_SECRET` | Render Blueprint `generateValue: true` | 256-bit; webhook grammY |
| `AIOGRAM_WEBHOOK_SECRET` | Render Blueprint `generateValue: true` | 256-bit; webhook Aiogram |
| Session token | Node `crypto.randomBytes(32)` mỗi lần đăng nhập | 256-bit, token riêng từng phiên; chỉ SHA-256 hash lưu trong PostgreSQL |

Render chỉ sinh giá trị khi biến chưa có. Không để placeholder trong Environment vì Render sẽ giữ giá trị cũ đó. Nếu service cũ đang giữ placeholder, xóa nó một lần trong Render Environment rồi sync Blueprint.

## Những gì phải do chủ hệ thống cấp/xác nhận

| Thông tin | Vì sao không thể tự sinh |
|---|---|
| `DATABASE_URL` | Phải trỏ tới PostgreSQL thật có dữ liệu, quyền truy cập và chính sách backup đúng; tự tạo DB rỗng có thể khiến hệ thống chạy nhầm database |
| `BOT1_TOKEN`, `BOT2_TOKEN` | BotFather/Telegram cấp token cho bot sở hữu; random string không phải token hợp lệ |
| `ADMIN_TELEGRAM_ID` | Chủ hệ thống phải xác nhận tài khoản nào được quyền quản trị; không tự gán quyền cho user đầu tiên |
| `RENDER_API_KEY` | API key phải được tạo trong Render Dashboard với quyền thích hợp; HENDY không thể tự cấp quyền tài khoản Render |
| Custom domain + DNS | Cần domain thuộc quyền sở hữu của người dùng và thay đổi DNS tại registrar; HENDY chỉ đọc trạng thái domain đã thêm vào Render |
| Ngân hàng, số tài khoản, chủ tài khoản, VietQR/provider | Là thông tin tài chính của chủ hệ thống; không đoán hoặc tự tạo |
| Telegram group/channel URL | Phải là group/channel do chủ hệ thống tạo hoặc chọn |

## Luồng sau deploy

1. Render cấp runtime metadata cho từng service.
2. Blueprint nối URL API/Static Site/Aiogram bằng `fromService`; API tự ghép Mini App URL `/app`.
3. Render sinh các secret được khai báo `generateValue: true`.
4. Aiogram nhận service key, API URL, DB URL và Mini App URL từ API service.
5. Node gọi `getMe()` để nhận username/ID bot chính; Aiogram gọi `get_me()` cho bot phụ.
6. Live verification tìm service trong đúng repository, suy ra workspace từ owner metadata, đọc domain/deploy/custom domain và chạy health/smoke tests.
7. Nếu domain, owner, Bot Token hoặc commit không rõ/không khớp, quá trình báo lỗi thay vì tự chọn một nguồn không xác định.

## Không tự động làm

HENDY không tự tạo DNS record, không chuyển database, không xoay secret đang dùng mà không có kế hoạch, không xóa session/backup, không cấp admin cho người dùng chưa xác nhận, không tự tạo BotFather bot, không phát sinh giao dịch hoặc bật module thanh toán khi chưa có đối soát.
