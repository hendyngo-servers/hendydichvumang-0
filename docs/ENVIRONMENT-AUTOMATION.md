# HENDY — Tự nhận diện cấu hình và tạo secrets an toàn

## Mục tiêu

Blueprint và ứng dụng tự điền mọi giá trị có thể suy ra từ Render hoặc tạo bằng bộ sinh ngẫu nhiên mật mã. Secret chỉ nằm trong Render Environment hoặc file \`.env\` local bị Git ignore; không ghi vào source, frontend, response API công khai hay log.

## 1. Render tự tạo secrets 256-bit

Các biến dưới đây khai báo \`generateValue: true\` trong \`render.yaml\`. Render tạo giá trị Base64 ngẫu nhiên 256-bit khi biến chưa tồn tại. Khi biến đã có giá trị, Render giữ giá trị hiện có khi sync Blueprint.

| Secret | Nơi lưu | Dùng cho |
|---|---|---|
| \`TELEGRAM_WEBHOOK_SECRET\` | API service | Xác minh header webhook grammY |
| \`BOT_SERVICE_KEY\` | API service | Khóa nội bộ Node.js API ↔ Aiogram |
| \`ADMIN_PASSWORD\` | API service | Đăng nhập Admin Dashboard |
| \`AIOGRAM_WEBHOOK_SECRET\` | Aiogram service | Xác minh webhook Aiogram |

\`ADMIN_USERNAME\` mặc định là \`admin\`; đây là tên tài khoản, không phải secret. Lưu mật khẩu Admin do Render tạo vào password manager. Ứng dụng không trả mật khẩu này về trình duyệt hay ghi vào log.

Nếu API service đã có \`ADMIN_PASSWORD\` riêng, \`generateValue: true\` không thay thế nó. Điều này tránh đổi mật khẩu ngoài ý muốn khi sync Blueprint.

## 2. Render tự nhận diện và nối giữa các service

| Giá trị | Cách tự động |
|---|---|
| Domain API | Render cấp \`RENDER_EXTERNAL_URL\`; Node.js dùng làm \`PUBLIC_API_URL\` |
| Domain website | API lấy \`RENDER_EXTERNAL_URL\` của Static Site bằng \`fromService\` → \`PUBLIC_APP_URL\` |
| Domain API trong Static Site | \`STATIC_API_BASE_URL\` lấy từ API qua \`fromService\`; build sinh \`public/config.js\` chỉ chứa public API origin |
| Mini App | Node.js ghép \`PUBLIC_APP_URL + /app\`; Aiogram dùng cùng URL |
| API Aiogram gọi | \`HENDY_API_BASE_URL\` lấy từ API \`RENDER_EXTERNAL_URL\` |
| Service key Aiogram | \`HENDY_BOT_SERVICE_KEY\` nhận đúng \`BOT_SERVICE_KEY\` của API |
| PostgreSQL và TLS | Aiogram nhận \`DATABASE_URL\` và \`PGSSLMODE\` từ API |
| Domain webhook Aiogram | Dùng \`RENDER_EXTERNAL_URL\` của chính service Aiogram |
| \`PORT\` | Render tự cấp; ứng dụng bind \`0.0.0.0\` |
| \`ALLOWED_ORIGINS\` | Node.js tự thêm API origin và Website origin; chỉ khai báo thêm khi có origin khác |

Render cập nhật tham chiếu \`fromService\` trong mỗi lần Blueprint sync; thay domain/service cần sync Blueprint để giá trị liên kết cập nhật.

## 3. Giá trị mặc định không phải secret

Blueprint/ứng dụng đặt sẵn \`NODE_ENV=production\`, Node.js 22, Python 3.12.10, \`PGSSLMODE=require\`, \`PG_POOL_MAX=5\`, \`AIOGRAM_FSM_TTL_HOURS=24\`, \`AIOGRAM_WEBHOOK_PATH=/telegram/webhook\`, \`TELEGRAM_INITDATA_MAX_AGE_SECONDS=86400\` và \`SESSION_TTL_SECONDS=604800\`.

Session token được tạo ngẫu nhiên bằng \`crypto.randomBytes(32)\` ở mỗi lần đăng nhập; chỉ SHA-256 hash được lưu trong PostgreSQL. Không cần một \`SESSION_SECRET\` tĩnh khác.

## 4. Thông tin không thể tự bịa hoặc tự lấy nếu thiếu quyền

| Giá trị | Vì sao chủ sở hữu phải cung cấp |
|---|---|
| \`DATABASE_URL\` | Stack đang dùng PostgreSQL hiện có/bên ngoài; cần connection string và quyền truy cập. Blueprint không thay database để tránh tạo DB mới hoặc mất dữ liệu |
| \`BOT1_TOKEN\` | Token grammY phải được cấp từ Telegram BotFather |
| \`BOT2_TOKEN\` | Token Aiogram pilot riêng, cũng phải cấp từ BotFather |
| \`RENDER_API_KEY\` | Phải tạo trong Render Account Settings; ứng dụng không thể tự cấp API key cho tài khoản |
| \`ADMIN_TELEGRAM_ID\` | ID Telegram chủ sở hữu để phân quyền và nhận thông báo |
| Telegram Group/Channel URL | Liên kết phải được chủ sở hữu xác nhận |
| \`LEGACY_CUSTOMER_URL\` | Chỉ cần nếu còn sử dụng website cũ |
| Custom domain | Cần domain/DNS thuộc quyền sở hữu và xác minh với Render |
| \`PGSSL_CA_CERT\` | Chỉ cần nếu nhà cung cấp PostgreSQL yêu cầu CA riêng với \`verify-full\` |

\`BOT1_TOKEN\` và \`BOT2_TOKEN\` không được dùng chung. Vì Blueprint hiện khai báo Aiogram pilot, cần cấp \`BOT2_TOKEN\` nếu triển khai đủ ba service. Không dùng token giả để làm health check xanh.

## 5. Workspace Render tự nhận diện

Workflow live-check chỉ yêu cầu \`RENDER_API_KEY\`. Nó gọi Render API \`GET /owners\`: nếu chỉ có một team workspace phù hợp, tự chọn workspace đó. Nếu API key có quyền vào nhiều team workspace, đặt GitHub Actions Variable \`RENDER_WORKSPACE_ID\` để chọn đúng workspace HENDY.

Các service ID và domain website/API/Aiogram được tìm tự động theo tên service và metadata Render; không cần chép URL vào GitHub Variables.

## 6. Tạo secrets cho môi trường local

Chạy:

\`\`\`bash
npm run secrets:generate
\`\`\`

Lệnh tạo 32 byte ngẫu nhiên cho các mục trống/placeholder trong \`.env\`: \`TELEGRAM_WEBHOOK_SECRET\`, \`BOT_SERVICE_KEY\`, \`ADMIN_PASSWORD\`, và \`AIOGRAM_WEBHOOK_SECRET\`. Nó đồng bộ \`HENDY_BOT_SERVICE_KEY\` với \`BOT_SERVICE_KEY\` nếu biến Aiogram còn trống.

Lệnh không in giá trị secret, không ghi đè secret đã có, và cố đặt quyền file local thành \`0600\`. Sau đó vẫn phải cấp các giá trị không thể tự sinh: PostgreSQL URL, BotFather tokens và Telegram Admin ID. Không commit \`.env\`.

## 7. Kiểm tra bảo mật

Node.js/Aiogram từ chối webhook/service secrets quá yếu hoặc có prefix placeholder. Production Admin password cũng bị từ chối nếu còn là placeholder hoặc ngắn hơn ngưỡng tối thiểu. Giao diện Admin chỉ nhận trạng thái đã cấu hình, không nhận giá trị secret.
