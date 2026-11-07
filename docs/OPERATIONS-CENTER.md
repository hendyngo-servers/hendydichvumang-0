# HENDY Operations Center

## Mục tiêu

Trang `/admin` là điểm điều hành tập trung của HENDY. Nó ghép trạng thái API, PostgreSQL, grammY, Aiogram, Render, cấu hình runtime, module, automation và audit logs vào giao diện quản trị hiện tại.

## Những phần đã tích hợp

- Tổng quan runtime API và PostgreSQL: uptime, phiên bản Node.js, kiểm tra DB, migration mới nhất và số liệu nghiệp vụ.
- Hai Telegram bot: grammY là bot chính; Aiogram chạy ở service riêng. Aiogram dùng token khác và xác minh danh tính grammY trước khi thiết lập webhook.
- Runtime nhận diện từ biến Render được tài liệu hóa: `RENDER`, service ID/name/type, external URL/hostname, git branch và commit.
- Telegram identity: API gọi `getMe()` sau khi chủ hệ thống cung cấp token, lấy username/ID để hiển thị và xác minh; không đoán username bằng tên biến.
- Cấu hình runtime: chỉ hiển thị tên biến, trạng thái đã khai báo/chưa khai báo và nguồn sinh/cấp; không trả secret value cho trình duyệt.
- Render inventory: tự tìm service theo tên + repository, suy ra workspace từ owner metadata nếu duy nhất, đọc deploy state, domain mặc định và custom-domain status.
- Nhật ký: audit events và lịch sử automation từ PostgreSQL.
- Giữ nguyên các vùng sẵn có: cấu hình menu grammY/Aiogram, automation, kho module chờ duyệt, dịch vụ và ticket CSKH.

## API mới

Các API dưới đây yêu cầu admin session qua header `Authorization: Bearer <session>`:

- `GET /api/admin/operations/overview`
- `GET /api/admin/operations/config`
- `GET /api/admin/operations/render`
- `GET /api/admin/operations/logs?limit=30`

Không thêm migration, không drop/rename bảng và không reset dữ liệu.

## Kết nối Render (tùy chọn)

Chỉ cần thêm một biến vào Environment của API khi muốn Operations Center đọc inventory/deploy/custom domain:

- `RENDER_API_KEY`: API key do chủ tài khoản tạo tại Render Dashboard. Dùng server-side với quyền đọc tối thiểu cần thiết.
- `RENDER_WORKSPACE_ID`: optional override để chọn workspace khi API key có quyền nhiều workspace hoặc phát hiện trùng lặp. Nếu bỏ trống, adapter lấy ownerId từ service thuộc đúng repository để tự suy ra workspace.

Không đặt Render API key trong Static Site, Aiogram, Mini App hoặc mã nguồn. Không gửi key xuống trình duyệt.

Render adapter chỉ dùng giá trị env-var để xác nhận key đã được khai báo; không trả secret value, ghi log hoặc lưu DB. Adapter đọc tên custom domain và verification status, không sửa domain, không thay DNS và không tự chọn custom domain làm canonical host.

Các biến được kiểm kê:

| Service | Biến chính |
| --- | --- |
| Static Site | `STATIC_API_BASE_URL` |
| Node API | `DATABASE_URL`, `BOT1_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`, `BOT_SERVICE_KEY`, `ADMIN_PASSWORD`, `ADMIN_TELEGRAM_ID`, `PUBLIC_APP_URL`, `PUBLIC_MINI_APP_URL` |
| Aiogram | `BOT2_TOKEN`, `AIOGRAM_WEBHOOK_SECRET`, `HENDY_API_BASE_URL`, `HENDY_BOT_SERVICE_KEY`, `DATABASE_URL`, `PGSSLMODE`, `PUBLIC_APP_URL`, `PUBLIC_MINI_APP_URL` |

## Secret inventory

| Secret / credential | Nguồn | Chính sách |
|---|---|---|
| `BOT_SERVICE_KEY` | Render `generateValue: true` | 256-bit; truyền sang Aiogram bằng `fromService` |
| `TELEGRAM_WEBHOOK_SECRET` | Render `generateValue: true` | 256-bit; chỉ API dùng |
| `AIOGRAM_WEBHOOK_SECRET` | Render `generateValue: true` | 256-bit; chỉ Aiogram dùng |
| `ADMIN_PASSWORD` | Render `generateValue: true` | 256-bit; chỉ lưu ở Render Environment; username mặc định `admin` |
| Session token | Node `crypto.randomBytes(32)` | 256-bit mới mỗi lần đăng nhập; PostgreSQL chỉ lưu SHA-256 hash |
| `DATABASE_URL` | PostgreSQL provider | Chủ hệ thống cung cấp; không thể suy ra từ domain |
| `BOT1_TOKEN`, `BOT2_TOKEN` | BotFather | Chủ hệ thống tạo; không thể tự mint Telegram Bot Token |
| `ADMIN_TELEGRAM_ID` | Chủ hệ thống xác nhận | Không tự gán quyền admin cho user đầu tiên |
| `RENDER_API_KEY` | Render Dashboard | Optional, chủ tài khoản tạo; chỉ cần để gọi Render API |

Render giữ giá trị `generateValue` đã có ở các lần sync sau. Nếu một service cũ đang lưu placeholder cho một biến được generate, xóa placeholder đó một lần rồi sync Blueprint.

## Nguyên tắc bảo mật và phạm vi

- Bot Token của grammY và Aiogram phải là hai token độc lập.
- Bot Token, Render API key, service key, webhook secret, mật khẩu và session token không được gửi về browser.
- Không lưu credentials vào PostgreSQL, audit log hoặc static assets.
- Render integration hiện chỉ đọc trạng thái; không tự đổi env vars, restart, trigger deploy hoặc sửa DNS.
- Module upload vẫn chỉ là artifact chờ kiểm duyệt, không được thực thi tự động.
- Mọi thao tác điều hành tiếp tục đi qua admin authentication và API hiện có.

## Deploy

1. Push commit lên nhánh `main`; Render Blueprint tự triển khai các service đã được liên kết với repository.
2. `RENDER_API_KEY` có thể để trống; inventory sẽ báo chưa kết nối Render, còn runtime và DB overview vẫn hoạt động.
3. Khi đã thêm Render API key, mở `/admin`, đăng nhập và nhấn **Đồng bộ trạng thái**.
4. Nếu một service chưa được tạo trong Render, dashboard hiển thị chưa tìm thấy thay vì giả lập healthy.
