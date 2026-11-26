-- HENDY Multi-Agent Control Center. Additive only.
CREATE TABLE IF NOT EXISTS multi_agent_agents (
 agent_key VARCHAR(64) PRIMARY KEY,name VARCHAR(100) NOT NULL,role VARCHAR(100) NOT NULL,
 description VARCHAR(300) NOT NULL DEFAULT '',system_prompt TEXT NOT NULL,enabled BOOLEAN NOT NULL DEFAULT TRUE,
 model_override VARCHAR(80) NOT NULL DEFAULT '',sort_order INTEGER NOT NULL DEFAULT 100,
 created_by TEXT NOT NULL DEFAULT '',updated_by TEXT NOT NULL DEFAULT '',
 created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
 CHECK(agent_key ~ '^[a-z][a-z0-9_]{1,63}$'),
 CHECK(model_override='' OR model_override ~ '^[A-Za-z0-9][A-Za-z0-9._-]{1,79}$')
);
CREATE TABLE IF NOT EXISTS multi_agent_tasks (
 id UUID PRIMARY KEY,title VARCHAR(120) NOT NULL,
 task_type VARCHAR(20) NOT NULL CHECK(task_type IN ('research','content','sales','video','support','custom')),
 prompt TEXT NOT NULL,context_text TEXT NOT NULL DEFAULT '',workflow_steps JSONB NOT NULL DEFAULT '[]'::JSONB,
 current_step SMALLINT NOT NULL DEFAULT 0 CHECK(current_step>=0),
 agent_key VARCHAR(64) NOT NULL REFERENCES multi_agent_agents(agent_key) ON UPDATE CASCADE ON DELETE RESTRICT,
 status VARCHAR(24) NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','running','awaiting_approval','approved','rejected','failed')),
 priority SMALLINT NOT NULL DEFAULT 50 CHECK(priority BETWEEN 0 AND 100),
 attempts SMALLINT NOT NULL DEFAULT 0 CHECK(attempts BETWEEN 0 AND 5),claimed_at TIMESTAMPTZ,started_at TIMESTAMPTZ,
 result_text TEXT NOT NULL DEFAULT '',error_message VARCHAR(1000) NOT NULL DEFAULT '',created_by TEXT NOT NULL,
 approved_by TEXT NOT NULL DEFAULT '',approval_note VARCHAR(1000) NOT NULL DEFAULT '',
 created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),completed_at TIMESTAMPTZ
);
CREATE TABLE IF NOT EXISTS multi_agent_runs (
 id UUID PRIMARY KEY,task_id UUID NOT NULL REFERENCES multi_agent_tasks(id) ON DELETE CASCADE,
 agent_key VARCHAR(64) NOT NULL REFERENCES multi_agent_agents(agent_key) ON UPDATE CASCADE ON DELETE RESTRICT,
 status VARCHAR(16) NOT NULL CHECK(status IN ('running','completed','failed')),model VARCHAR(80) NOT NULL,
 input_tokens INTEGER NOT NULL DEFAULT 0 CHECK(input_tokens>=0),output_tokens INTEGER NOT NULL DEFAULT 0 CHECK(output_tokens>=0),
 output_text TEXT NOT NULL DEFAULT '',error_message VARCHAR(1000) NOT NULL DEFAULT '',
 created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),finished_at TIMESTAMPTZ
);
CREATE TABLE IF NOT EXISTS multi_agent_events (
 id UUID PRIMARY KEY,task_id UUID REFERENCES multi_agent_tasks(id) ON DELETE CASCADE,
 agent_key VARCHAR(64) REFERENCES multi_agent_agents(agent_key) ON UPDATE CASCADE ON DELETE SET NULL,
 event_type VARCHAR(80) NOT NULL,details JSONB NOT NULL DEFAULT '{}'::JSONB,actor_id TEXT,
 created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS multi_agent_workers (
 worker_id VARCHAR(120) PRIMARY KEY,started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
 last_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),metadata JSONB NOT NULL DEFAULT '{}'::JSONB
);
CREATE INDEX IF NOT EXISTS idx_multi_agent_tasks_queue ON multi_agent_tasks(status,priority DESC,created_at ASC);
CREATE INDEX IF NOT EXISTS idx_multi_agent_tasks_created ON multi_agent_tasks(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_multi_agent_tasks_agent_status ON multi_agent_tasks(agent_key,status,created_at DESC);
CREATE INDEX IF NOT EXISTS idx_multi_agent_runs_task_created ON multi_agent_runs(task_id,created_at DESC);
CREATE INDEX IF NOT EXISTS idx_multi_agent_events_task_created ON multi_agent_events(task_id,created_at DESC);
CREATE INDEX IF NOT EXISTS idx_multi_agent_workers_seen ON multi_agent_workers(last_seen_at DESC);
INSERT INTO multi_agent_agents(agent_key,name,role,description,system_prompt,sort_order) VALUES
('research','Nghiên cứu','Phân tích dữ liệu','Phân tích tài liệu do Admin cung cấp; nêu rõ phần chưa xác minh.','Bạn là Agent Nghiên cứu HENDY. Phân tích có cấu trúc, tách dữ kiện, suy luận và giả định. Chỉ sử dụng thông tin được cung cấp. Không giả vờ duyệt web hoặc trích dẫn nguồn chưa có. Kết thúc bằng các điểm cần xác minh.',10),
('content','Content','Chiến lược nội dung','Soạn nội dung đa kênh dựa trên brief và kết quả nghiên cứu.','Bạn là Agent Content HENDY. Tạo nội dung tiếng Việt rõ ràng, đúng đối tượng và kênh. Đưa ra tiêu đề, thông điệp, bản nháp và CTA. Không tự tạo khuyến mãi, giá, cam kết hoặc số liệu chưa cung cấp.',20),
('sales','Bán hàng','Tư vấn và bán hàng','Chuẩn bị kịch bản tư vấn và nội dung bán hàng.','Bạn là Agent Bán hàng HENDY. Soạn kịch bản tư vấn, phân loại nhu cầu và phản hồi phản đối. Không liên hệ khách hàng, không chốt giao dịch, không tự đặt giá hoặc hứa điều khoản chưa được Admin xác nhận.',30),
('video','Video','Kịch bản và sản xuất video','Tạo kịch bản, storyboard, shot list và brief dựng video.','Bạn là Agent Video HENDY. Tạo kịch bản, phân cảnh, lời thoại, khung hình, thời lượng và hướng dẫn hậu kỳ. Chỉ tạo tài liệu sản xuất; không tuyên bố đã render hoặc tải video lên.',40),
('support','Chăm sóc khách hàng','Hỗ trợ và phản hồi','Soạn phản hồi CSKH; không tự gửi hoặc thay đổi dữ liệu khách hàng.','Bạn là Agent CSKH HENDY. Soạn phản hồi lịch sự, ngắn gọn và có bước tiếp theo. Nếu thiếu mã đơn hoặc bằng chứng, hãy hỏi bổ sung. Không tự gửi tin, đổi số dư hoặc phê duyệt hoàn tiền.',50)
ON CONFLICT(agent_key) DO NOTHING;
GRANT SELECT,INSERT,UPDATE,DELETE ON multi_agent_agents,multi_agent_tasks,multi_agent_runs,multi_agent_events,multi_agent_workers TO hendy_bot_app;
