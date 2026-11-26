# HENDY Web Service Platform

HENDY chạy các Web Service trên cùng nền PHP 8.3 và PostgreSQL, tách khỏi vòng đời Telegram bot. Bot 1 và Bot 2 gọi chung backend service nhưng mỗi Mini App xác thực bằng token của bot tương ứng (header X-Telegram-Bot-Key: primary|secondary).

## Cấu trúc mở rộng

- app/WebServiceContract.php: hợp đồng HTTP chung cho service.
- app/WebServiceKernel.php: router allow-list, chỉ thực thi lớp service được đăng ký trong mã nguồn.
- app/SystemWebService.php: service hệ thống cho health/capabilities.
- services/: tài liệu, mã nguồn tích hợp và cấu hình theo từng service nghiệp vụ.

## Endpoint có sẵn

- GET /api/services/system/health
- GET /api/services/system/capabilities

Hai endpoint đi qua xác thực Telegram Mini App giống các API nghiệp vụ còn lại. health trả trạng thái database và bot key đã xác thực; capabilities liệt kê service đã được đăng ký.

## Thêm một Web Service mới

1. Tạo class PHP trong app/WebServices/, implement Hendy\WebServiceContract.
2. Viết các action GET/POST/PATCH/DELETE rõ ràng; validate payload, giới hạn kích thước, kiểm tra quyền và dùng prepared statements.
3. Thêm class vào allow-list WebServiceKernel::SERVICES.
4. Thêm unit test cho action thành công, lỗi quyền, payload sai và trường hợp dữ liệu trùng.
5. Cập nhật route/documentation, chạy composer lint, composer test và CI PostgreSQL.
6. Push lên main; Render triển khai web service cùng phiên bản với bot và Mini App.

## Quy tắc an toàn

- Không nạp hoặc thực thi PHP/JavaScript từ tệp upload hay cấu hình database.
- Không nhận bot token, secret hoặc credential trong payload của người dùng.
- Service gọi nhà cung cấp bên ngoài phải dùng biến Environment riêng, timeout, allowlist host, giới hạn tốc độ và xác thực chữ ký webhook.
- Tách dữ liệu dùng chung khỏi dữ liệu riêng từng bot; giữ botKey trong audit/event khi cần truy vết.
- Chỉ expose route cần thiết; không mở một endpoint proxy tùy ý để gọi URL do client cung cấp.

services/ là không gian phát triển và tài liệu tích hợp. Việc phát hành service mới luôn yêu cầu thêm code đã review vào allow-list, kiểm thử rồi triển khai qua CI/CD.
