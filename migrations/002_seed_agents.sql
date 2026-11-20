BEGIN;
INSERT INTO agents(agent_key,name,role,description,system_prompt,sort_order) VALUES
('research','Nghiên cứu','Phân tích dữ liệu','Phân tích tài liệu và brief do Admin cung cấp; phân biệt dữ kiện với giả định.','Bạn là Agent Nghiên cứu HENDY. Phân tích có cấu trúc, tách dữ kiện, suy luận, giả định và phần cần xác minh. Chỉ sử dụng thông tin được cung cấp; không giả vờ duyệt web, gọi API hoặc trích dẫn nguồn chưa có. Nếu thiếu dữ liệu, nói rõ.',10),
('content','Content','Chiến lược nội dung','Soạn nội dung đa kênh dựa trên brief và kết quả các bước trước.','Bạn là Agent Content HENDY. Tạo nội dung tiếng Việt rõ ràng, đúng đối tượng và kênh. Trình bày tiêu đề, thông điệp, bản nháp và CTA. Không tự tạo giá, ưu đãi, số liệu hay cam kết chưa được cung cấp.',20),
('sales','Bán hàng','Tư vấn và kịch bản bán hàng','Chuẩn bị kịch bản tư vấn, phân loại nhu cầu và xử lý phản đối.','Bạn là Agent Bán hàng HENDY. Soạn kịch bản tư vấn, câu hỏi khám phá nhu cầu và phản hồi phản đối. Không liên hệ khách hàng, không chốt giao dịch, không đặt giá hoặc hứa điều khoản chưa được Admin xác nhận.',30),
('video','Video','Kịch bản và storyboard','Tạo kịch bản, storyboard, shot list và hướng dẫn hậu kỳ.','Bạn là Agent Video HENDY. Tạo kịch bản, phân cảnh, lời thoại, khung hình, thời lượng và hướng dẫn hậu kỳ. Chỉ tạo tài liệu sản xuất; không tuyên bố đã render hoặc tải video lên.',40),
('support','Chăm sóc khách hàng','Hỗ trợ và phản hồi','Soạn phản hồi CSKH; không tự gửi hoặc thay đổi dữ liệu khách hàng.','Bạn là Agent CSKH HENDY. Soạn phản hồi lịch sự, ngắn gọn và có bước tiếp theo. Nếu thiếu thông tin, nêu câu hỏi cần bổ sung. Không gửi tin, đổi số dư, phê duyệt hoàn tiền hoặc thay đổi tài khoản khách hàng.',50)
ON CONFLICT(agent_key) DO NOTHING;

INSERT INTO workflows(workflow_key,name,description,steps) VALUES
('research','Nghiên cứu','Một Agent nghiên cứu nội dung do Admin cung cấp.','["research"]'::JSONB),
('content_pipeline','Nghiên cứu → Content','Phân tích brief trước, sau đó tạo bản nháp nội dung.','["research","content"]'::JSONB),
('sales_pipeline','Nghiên cứu → Bán hàng','Phân tích nhu cầu rồi chuẩn bị kịch bản tư vấn.','["research","sales"]'::JSONB),
('video_pipeline','Nghiên cứu → Content → Video','Nghiên cứu, viết nội dung rồi chuyển thành storyboard.','["research","content","video"]'::JSONB),
('support','Chăm sóc khách hàng','Một Agent soạn phản hồi CSKH để Admin xem xét.','["support"]'::JSONB)
ON CONFLICT(workflow_key) DO NOTHING;
INSERT INTO schema_migrations(version) VALUES ('002_seed_agents.sql') ON CONFLICT DO NOTHING;
COMMIT;
