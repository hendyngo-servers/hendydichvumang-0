# Checklist chuyển từ Cloudflare sang Render + PostgreSQL

Phạm vi: thay runtime/backend/dashboard sang repository Hendy Bot Tele và PostgreSQL Managed trên Render.

## Trước khi cutover
- [ ] Export toàn bộ D1 database và lưu bản sao ở nơi riêng tư.
- [ ] Lập inventory các Worker bindings, secrets, routes, scheduled triggers và Pages domains đang dùng.
- [ ] Chụp lại biến môi trường production hiện có nhưng không đưa giá trị secret vào ticket/commit.
- [ ] Thu hồi và cấp lại Bot Token/DB credential từng xuất hiện trong repository công khai.

## Dữ liệu
- [ ] Tạo PostgreSQL trên Render trong cùng region với Web Service.
- [ ] Mapping D1 tables sang schema PostgreSQL được tạo bởi `apps/backend-api/src/database.js`.
- [ ] Viết/kiểm tra script import riêng; không chạy import trực tiếp lên database production lần đầu.
- [ ] Đối soát user count, balance totals, wallet ledger, transactions, orders, referrals, giftcodes và tickets.
- [ ] Lưu biên bản đối soát và phương án rollback.

## Cutover
- [ ] Kết nối Render Blueprint và nhập secrets trong Render Environment.
- [ ] Verify `/healthz`, `/api/health`, hai Telegram webhook và WebSocket `/ws`.
- [ ] Chạy smoke test menu Reply Keyboard, Inline Keyboard, Dashboard và các luồng tài chính.
- [ ] Chuyển webhook/bot traffic sang Render sau khi xác nhận DB đã đồng bộ.
- [ ] Giữ Cloudflare resources ở trạng thái có thể rollback cho tới khi hết thời gian theo dõi.

**Lưu ý:** Blueprint chỉ dựng ứng dụng và database mới; không tự động di chuyển dữ liệu D1.