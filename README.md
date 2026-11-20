# HENDY Multi-Agent Control Center

Nền tảng điều phối AI Agent độc lập, dùng Node.js 22, Express 5, PostgreSQL và OpenAI Responses API. Ứng dụng có API, Admin Dashboard, Agent Registry, workflow tuần tự, PostgreSQL job queue và worker riêng.

## Tính năng

- **Agent Registry:** tạo, chỉnh sửa system prompt, model override, thứ tự và bật/tắt Agent.
- **Workflow Hub:** Research; Research → Content; Research → Sales; Research → Content → Video; Support. Mỗi workflow được lưu trong PostgreSQL.
- **Task Queue:** hàng đợi PostgreSQL dùng `FOR UPDATE SKIP LOCKED`, tránh hai worker nhận cùng một công việc.
- **Worker nền:** heartbeat, số lần thử, khôi phục task treo, lịch sử từng lượt chạy, token usage và event log.
- **Admin Review:** bản nháp hoàn tất dừng ở `Chờ duyệt`. Duyệt/từ chối chỉ lưu quyết định quản trị.
- **Audit Log:** ghi thao tác tạo/cập nhật Agent, tạo công việc và quyết định duyệt.
- **Bảo mật:** cookie phiên HttpOnly, CSRF double-submit token, mật khẩu so sánh bằng scrypt, rate limit đăng nhập, Helmet CSP, giới hạn JSON body, SQL parameter binding.

## Giới hạn tính năng hiện tại

Đây là hệ thống điều phối và tạo bản nháp, không phải tác nhân tự động được cấp quyền làm việc ngoài hệ thống. Agent Research chỉ sử dụng tài liệu/ngữ cảnh Admin cung cấp, chưa có web search connector. Agent Video chỉ tạo kịch bản/storyboard, chưa render video. Sales không tự liên hệ khách; Support không gửi tin. Không có thanh toán, cộng/trừ số dư, hoàn tiền, phát OTP, cấp proxy, đăng bài hoặc cập nhật tài khoản thành viên.

## Cấu trúc

```text
.
├── .github/workflows/ci.yml
├── migrations/                 # SQL migrations chạy thủ công
├── public/                     # Admin Dashboard responsive
├── src/
│   ├── auth.js                  # Session + CSRF
│   ├── config.js                # Environment validation
│   ├── db.js                    # PostgreSQL pool
│   ├── migrate.js               # Migration runner
│   ├── provider.js              # LLM provider adapter (OpenAI Responses API)
│   ├── server.js                # REST API + Dashboard
│   ├── worker-core.js           # Queue/Agent execution
│   └── worker.js                # Background worker process
├── test/
├── Dockerfile
├── render.yaml
└── package.json
```

## Chạy local

Yêu cầu Node.js 22+ và PostgreSQL 14+.

```bash
cp .env.example .env
# Chỉnh DATABASE_URL, ADMIN_PASSWORD, ADMIN_PASSWORD_SALT, SESSION_SECRET
npm ci
npm run migrate
npm test
npm start
```

Mở `http://localhost:10000`. Mặc định tên đăng nhập là `admin`; mật khẩu lấy từ `ADMIN_PASSWORD`.

Sinh secret an toàn bằng Node.js:

```bash
node -e "console.log(require('node:crypto').randomBytes(32).toString('base64url'))"
```

Chạy lệnh riêng cho từng biến `ADMIN_PASSWORD`, `ADMIN_PASSWORD_SALT` và `SESSION_SECRET`; không dùng chung giá trị, không commit `.env`. Provider sử dụng endpoint `/v1/responses`; model mặc định có thể thay bằng model được bật trong dự án API.

## Cấu hình worker và model

Worker mặc định tắt. Sau khi API và database sẵn sàng, đặt trong môi trường server:

```env
OPENAI_API_KEY=<secret-do-chu-so-huu-cap>
OPENAI_MODEL=gpt-6.2-astra
MULTI_AGENT_ENABLED=true
MULTI_AGENT_WORKER_POLL_SECONDS=5
MULTI_AGENT_MAX_ATTEMPTS=3
MULTI_AGENT_STALE_MINUTES=15
MULTI_AGENT_CONCURRENCY=1
```

API key chỉ được đọc bởi worker phía máy chủ; không lưu trong PostgreSQL, không gửi xuống browser và không đặt trong GitHub source. Model có thể chỉnh trong `OPENAI_MODEL` hoặc `model_override` của từng Agent. Nếu dùng endpoint tương thích OpenAI khác, chỉnh `OPENAI_BASE_URL`.

## Deploy Render

Blueprint `render.yaml` tạo một Web Service và một Background Worker dùng chung các cấu hình không nhạy cảm qua Environment Group. Cả hai dùng cùng image Docker. Blueprint không tạo hoặc thay thế database.

1. Tạo PostgreSQL database riêng (hoặc dùng database đã được chuẩn bị cho ứng dụng mới) và lưu connection string trong biến bí mật `DATABASE_URL`.
2. Tạo Blueprint từ repository này và kiểm tra kỹ hai service trước khi Apply.
3. Khi Render hỏi giá trị cho từng service, nhập cùng một `DATABASE_URL`, `ADMIN_PASSWORD` và `OPENAI_API_KEY` cho cả API lẫn Worker. Các biến bí mật này được khai báo riêng trong từng service; không nhập chúng vào GitHub hoặc file source. `ADMIN_PASSWORD_SALT` và `SESSION_SECRET` được sinh tự động trong Environment Group và được dùng chung.
4. Sau khi API đã deploy, mở Shell của Web Service và chạy `npm run migrate` đúng một lần. Không đặt `RUN_MIGRATIONS_ON_START=true`; migration không tự chạy lúc boot.
5. Mở `https://<api-domain>/healthz`, xác nhận `status=ok` và `database=ready`.
6. Đăng nhập Admin và kiểm tra Agent/Workflow.
7. Chỉ khi đã sẵn sàng mới bật `MULTI_AGENT_ENABLED=true` trong Environment Group và redeploy cả API lẫn Worker. Worker sẽ cần `OPENAI_API_KEY` hợp lệ.
8. Xác nhận Worker có heartbeat và thử một task không nhạy cảm.

Render Background Worker cần instance plan hỗ trợ Worker. Blueprint bỏ trống `plan` để Render áp dụng compute plan mặc định cho service; Worker là background service riêng và cần compute plan được Render hỗ trợ. Kiểm tra plan hiển thị trong Blueprint trước khi Apply.

## API quản trị

| Method | Path | Mục đích |
|---|---|---|
| GET | `/healthz` | Health check + PostgreSQL |
| POST | `/api/auth/login` | Đăng nhập Admin |
| GET | `/api/auth/me` | Kiểm tra session + lấy CSRF token |
| POST | `/api/auth/logout` | Đăng xuất |
| GET | `/api/dashboard` | Thống kê công việc, Worker |
| GET/POST | `/api/agents` | Liệt kê/tạo Agent |
| PATCH | `/api/agents/:key` | Cập nhật Agent |
| GET/POST | `/api/workflows` | Liệt kê/tạo workflow |
| PATCH | `/api/workflows/:key` | Cập nhật workflow |
| GET/POST | `/api/tasks` | Liệt kê/tạo công việc |
| GET | `/api/tasks/:id` | Chi tiết, runs, events |
| POST | `/api/tasks/:id/approve` | Ghi nhận duyệt |
| POST | `/api/tasks/:id/reject` | Ghi nhận từ chối |
| POST | `/api/tasks/:id/retry` | Đưa task lỗi về hàng đợi |
| GET | `/api/audit` | Nhật ký quản trị |

Mọi route quản trị yêu cầu session. Các request POST/PATCH cần gửi header `X-CSRF-Token` tương ứng cookie CSRF.

## Database và dữ liệu

Migration `001_init.sql` tạo schema mới, migration `002_seed_agents.sql` seed Agent/Workflow mẫu. Các migration chạy tường minh qua `npm run migrate`, không tự áp dụng khi API khởi động. Không trỏ ứng dụng mới vào database nghiệp vụ hiện có của Bot/Website nếu chưa có kế hoạch backup và tách dữ liệu.

## Kiểm thử

```bash
npm ci
npm test
```

GitHub Actions chạy Node.js 22 và PostgreSQL test riêng, áp migrations vào database CI rồi chạy kiểm thử API, auth, CSRF và task flow. CI không dùng database production hay secrets production.
