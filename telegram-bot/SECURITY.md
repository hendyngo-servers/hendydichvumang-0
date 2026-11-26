# Security Policy

## Báo cáo lỗ hổng
Không đăng token, mật khẩu, database URL hoặc dữ liệu cá nhân vào issue công khai. Hãy liên hệ maintainer qua kênh riêng của repository.

## Credential handling
- Chỉ lưu secret trong Render Environment hoặc GitHub Secrets.
- Không commit .env, token Telegram, khóa webhook, database URL có mật khẩu, private key hoặc backup chứa dữ liệu người dùng.
- Mỗi bot phải có token và webhook secret riêng.
- Thu hồi ngay credential có nguy cơ bị lộ; xóa khỏi file mới không xóa được lịch sử Git.

## Production safeguards
- Webhook phải sử dụng HTTPS và secret header.
- Admin phải nằm trong ADMIN_ID/ADMIN_IDS được kiểm tra phía máy chủ.
- Bank callback mặc định tắt cho tới khi có chữ ký provider được xác minh.
- Không chạy module tải lên như mã thực thi.
- Sao lưu PostgreSQL trước khi thay schema hoặc migration.
