CREATE TABLE IF NOT EXISTS users (
    user_id TEXT PRIMARY KEY,
    name TEXT NOT NULL DEFAULT '',
    username TEXT NOT NULL DEFAULT '',
    balance NUMERIC(18, 0) NOT NULL DEFAULT 0,
    total_deposited NUMERIC(18, 0) NOT NULL DEFAULT 0,
    vip_level INTEGER NOT NULL DEFAULT 0,
    ref_by TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
ALTER TABLE users ADD COLUMN IF NOT EXISTS name TEXT NOT NULL DEFAULT '';
ALTER TABLE users ADD COLUMN IF NOT EXISTS username TEXT NOT NULL DEFAULT '';
ALTER TABLE users ADD COLUMN IF NOT EXISTS balance NUMERIC(18, 0) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS total_deposited NUMERIC(18, 0) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS vip_level INTEGER NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS ref_by TEXT;
ALTER TABLE users ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW();
ALTER TABLE users ADD COLUMN IF NOT EXISTS nyc_wallet NUMERIC(18, 0) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS voucher_wallet NUMERIC(18, 0) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS point_old BIGINT NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_blocked BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE users ADD COLUMN IF NOT EXISTS blocked_reason TEXT NOT NULL DEFAULT '';
ALTER TABLE users ADD COLUMN IF NOT EXISTS blocked_at TIMESTAMPTZ;
ALTER TABLE users ADD COLUMN IF NOT EXISTS blocked_by TEXT NOT NULL DEFAULT '';



-- Daily VIP check-in. Each member can check in once per Vietnam-local calendar day.
CREATE TABLE IF NOT EXISTS vip_checkins (
    id BIGSERIAL PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    checkin_date DATE NOT NULL,
    points_awarded INTEGER NOT NULL DEFAULT 0 CHECK (points_awarded BETWEEN 0 AND 1000),
    streak_count INTEGER NOT NULL DEFAULT 1 CHECK (streak_count >= 1),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (user_id, checkin_date)
);
CREATE INDEX IF NOT EXISTS idx_vip_checkins_user_date ON vip_checkins(user_id, checkin_date DESC);

CREATE TABLE IF NOT EXISTS transactions (
    id BIGSERIAL PRIMARY KEY,
    user_id TEXT NOT NULL,
    amount NUMERIC(18, 0) NOT NULL,
    type TEXT NOT NULL,
    reference_code TEXT,
    status TEXT NOT NULL DEFAULT 'paid',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS wallet_type TEXT NOT NULL DEFAULT 'MAIN';
CREATE INDEX IF NOT EXISTS idx_transactions_user_created ON transactions (user_id, created_at DESC);

CREATE TABLE IF NOT EXISTS deposit_events (
    reference_code TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    amount NUMERIC(18, 0) NOT NULL CHECK (amount > 0),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Telegram bank-transfer requests are manually reviewed. A request never
-- credits a wallet until an administrator approves it.
CREATE TABLE IF NOT EXISTS payment_requests (
    payment_id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    amount NUMERIC(18, 0) NOT NULL CHECK (amount BETWEEN 10000 AND 500000000),
    bot_key TEXT NOT NULL DEFAULT 'primary' CHECK (bot_key IN ('primary','secondary')),
    transfer_content TEXT NOT NULL UNIQUE,
    bank_id TEXT NOT NULL,
    account_number TEXT NOT NULL,
    account_holder TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected','cancelled')),
    reviewed_by TEXT,
    reviewed_at TIMESTAMPTZ,
    admin_note TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_payment_requests_status_created
    ON payment_requests(status, created_at ASC);
CREATE INDEX IF NOT EXISTS idx_payment_requests_user_created
    ON payment_requests(user_id, created_at DESC);

CREATE TABLE IF NOT EXISTS products (
    id BIGSERIAL PRIMARY KEY,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    category TEXT NOT NULL CHECK (category IN ('minigame','tools','codes','otp','proxy','vip')),
    description TEXT NOT NULL DEFAULT '',
    price NUMERIC(18, 0) NOT NULL CHECK (price >= 0),
    duration_days INTEGER NOT NULL DEFAULT 0,
    stock INTEGER,
    vip_level INTEGER NOT NULL DEFAULT 0,
    image_url TEXT,
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Supplier, source-rights and fulfillment readiness. Existing rows are preserved
-- and default to pending, so old catalog entries cannot become sellable by accident.
ALTER TABLE products
    ADD COLUMN IF NOT EXISTS supplier_name TEXT NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS supplier_url TEXT NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS supplier_product_ref TEXT NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS source_url TEXT NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS source_permission_status TEXT NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS supplier_access_status TEXT NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS fulfillment_mode TEXT NOT NULL DEFAULT 'manual',
    ADD COLUMN IF NOT EXISTS credential_env_key TEXT NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS verified_by TEXT NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS verified_at TIMESTAMPTZ;

DO $
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='products_source_permission_status_check') THEN
        ALTER TABLE products ADD CONSTRAINT products_source_permission_status_check
            CHECK (source_permission_status IN ('pending','authorized','rejected'));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='products_supplier_access_status_check') THEN
        ALTER TABLE products ADD CONSTRAINT products_supplier_access_status_check
            CHECK (supplier_access_status IN ('pending','verified','revoked'));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='products_fulfillment_mode_check') THEN
        ALTER TABLE products ADD CONSTRAINT products_fulfillment_mode_check
            CHECK (fulfillment_mode IN ('manual','api'));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='products_credential_env_key_check') THEN
        ALTER TABLE products ADD CONSTRAINT products_credential_env_key_check
            CHECK (credential_env_key = '' OR credential_env_key ~ '^[A-Z][A-Z0-9_]{2,63}
    id BIGSERIAL PRIMARY KEY,
    order_code TEXT NOT NULL UNIQUE,
    user_id TEXT NOT NULL,
    product_id BIGINT REFERENCES products(id) ON DELETE SET NULL,
    product_name TEXT NOT NULL,
    category TEXT NOT NULL,
    amount NUMERIC(18, 0) NOT NULL CHECK (amount >= 0),
    status TEXT NOT NULL DEFAULT 'pending',
    admin_note TEXT NOT NULL DEFAULT '',
    delivery_text TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS wallet_ledger (
    id BIGSERIAL PRIMARY KEY,
    reference_code TEXT UNIQUE,
    user_id TEXT NOT NULL,
    amount NUMERIC(18, 0) NOT NULL,
    transaction_type TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE wallet_ledger ADD COLUMN IF NOT EXISTS wallet_type TEXT NOT NULL DEFAULT 'MAIN';

CREATE TABLE IF NOT EXISTS support_tickets (
    id BIGSERIAL PRIMARY KEY,
    user_id TEXT NOT NULL,
    subject TEXT NOT NULL,
    message TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'open',
    priority TEXT NOT NULL DEFAULT 'normal',
    admin_reply TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS ticket_type TEXT NOT NULL DEFAULT 'support';
CREATE INDEX IF NOT EXISTS idx_support_tickets_type_created ON support_tickets(ticket_type, created_at DESC);

CREATE TABLE IF NOT EXISTS voucher_requests (
    id BIGSERIAL PRIMARY KEY,
    order_id BIGINT NOT NULL UNIQUE REFERENCES orders(id) ON DELETE CASCADE,
    user_id TEXT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    order_code TEXT NOT NULL,
    reason TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected')),
    reward_amount NUMERIC(18, 0) NOT NULL DEFAULT 0 CHECK (reward_amount >= 0),
    reviewed_by TEXT,
    reviewed_at TIMESTAMPTZ,
    admin_note TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_voucher_requests_status_created ON voucher_requests(status, created_at ASC);
CREATE INDEX IF NOT EXISTS idx_voucher_requests_user_created ON voucher_requests(user_id, created_at DESC);

-- PostgreSQL-backed queue for video subtitle jobs. The optional worker
-- dispatches jobs to a configured provider; without a provider they stay queued.
CREATE TABLE IF NOT EXISTS video_jobs (
    id UUID PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    bot_key TEXT NOT NULL DEFAULT 'primary' CHECK (bot_key IN ('primary','secondary')),
    source_url TEXT NOT NULL,
    provider_job_id TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','processing','completed','failed')),
    progress SMALLINT NOT NULL DEFAULT 0 CHECK (progress BETWEEN 0 AND 100),
    output_url TEXT NOT NULL DEFAULT '',
    error_message TEXT NOT NULL DEFAULT '',
    attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    claimed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    completed_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_video_jobs_queue
    ON video_jobs(status, created_at ASC);
CREATE INDEX IF NOT EXISTS idx_video_jobs_user_created
    ON video_jobs(user_id, created_at DESC);

-- Queue for asynchronous AI music generation and image editing.
-- Workers are only started when AI_MEDIA_QUEUE_ENABLED=true and provider keys are present.
CREATE TABLE IF NOT EXISTS ai_media_jobs (
    id UUID PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    bot_key TEXT NOT NULL DEFAULT 'primary' CHECK (bot_key = 'primary'),
    job_type TEXT NOT NULL CHECK (job_type IN ('music','image')),
    prompt TEXT NOT NULL,
    source_file_id TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','processing','completed','failed')),
    attempts SMALLINT NOT NULL DEFAULT 0 CHECK (attempts BETWEEN 0 AND 3),
    claimed_at TIMESTAMPTZ,
    error_message TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    completed_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_ai_media_jobs_queue
    ON ai_media_jobs(status, created_at ASC);
CREATE INDEX IF NOT EXISTS idx_ai_media_jobs_user_created
    ON ai_media_jobs(user_id, created_at DESC);
GRANT SELECT, INSERT, UPDATE, DELETE ON ai_media_jobs TO hendy_bot_app;

CREATE TABLE IF NOT EXISTS giftcodes (
    code TEXT PRIMARY KEY,
    reward_amount NUMERIC(18, 0) NOT NULL DEFAULT 0 CHECK (reward_amount >= 0),
    max_redemptions INTEGER NOT NULL DEFAULT 1 CHECK (max_redemptions > 0),
    redeemed_count INTEGER NOT NULL DEFAULT 0,
    expires_at TIMESTAMPTZ,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS giftcode_redemptions (
    code TEXT NOT NULL REFERENCES giftcodes(code) ON DELETE CASCADE,
    user_id TEXT NOT NULL,
    reward_amount NUMERIC(18, 0) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (code, user_id)
);

CREATE TABLE IF NOT EXISTS app_settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT '',
    is_public BOOLEAN NOT NULL DEFAULT TRUE,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Default official website URL shown in the supplied Telegram welcome-screen reference.
-- Admin can change it from the application settings page; preserve later edits.
INSERT INTO app_settings (key, value, is_public)
VALUES ('website_url', 'https://hendydichvumang-szad.onrender.com', TRUE)
ON CONFLICT (key) DO NOTHING;

INSERT INTO app_settings (key, value, is_public)
VALUES ('vip_checkin_points', '1', TRUE)
ON CONFLICT (key) DO NOTHING;

-- Default welcome-screen links. ON CONFLICT preserves any administrator customization.
UPDATE app_settings
SET value = 'https://hendydichvumang-szad.onrender.com', updated_at = NOW()
WHERE key = 'website_url'
  AND value IN (
      'https://lethituanem.com',
      'https://hendy-bot-tele.onrender.com',
      'https://hendy-bot-cybertech.onrender.com',
      'https://hendy-bot-cybertech-1.onrender.com',
      'https://hendy-bot-cybertech-1.onrender.com/',
      'https://hendydichvumang-telegram-bot.onrender.com/',
      'https://hendydichvumang.onrender.com',
      'https://hendydichvumang.onrender.com/',
      'https://hendydichvumang-static-site.onrender.com',
      'https://hendydichvumang-static-site.onrender.com/',
      'https://hendydichvumang-website.onrender.com',
      'https://hendydichvumang-website.onrender.com/'
  );

INSERT INTO app_settings (key, value, is_public)
VALUES
    ('GROUP_URL', 'https://t.me/Hendy_Support_Group', TRUE)
ON CONFLICT (key) DO NOTHING;

-- Legacy editable bot-name settings are retired; public Telegram URLs now come from deployment environment.
DELETE FROM app_settings WHERE key IN ('bot_username', 'bot_display_name');

-- Migrate only the old bundled defaults; preserve any administrator customization.
UPDATE app_settings
SET value = 'https://t.me/Hendy_Support_Group', updated_at = NOW()
WHERE key = 'GROUP_URL' AND value = 'https://t.me/lethituanem_nhomchat';

CREATE TABLE IF NOT EXISTS bot_menu_configs (
    bot_key TEXT PRIMARY KEY CHECK (bot_key IN ('primary','secondary')),
    menu_mode TEXT NOT NULL DEFAULT 'inline_keyboard' CHECK (menu_mode IN ('reply_keyboard','inline_keyboard')),
    intro_text TEXT NOT NULL DEFAULT '',
    menu_columns SMALLINT NOT NULL DEFAULT 2 CHECK (menu_columns BETWEEN 1 AND 3),
    persistent_keyboard BOOLEAN NOT NULL DEFAULT TRUE,
    resize_keyboard BOOLEAN NOT NULL DEFAULT TRUE,
    button_labels JSONB NOT NULL DEFAULT '{}'::jsonb,
    button_descriptions JSONB NOT NULL DEFAULT '{}'::jsonb,
    button_styles JSONB NOT NULL DEFAULT '{}'::jsonb,
    button_icons JSONB NOT NULL DEFAULT '{}'::jsonb,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE bot_menu_configs ADD COLUMN IF NOT EXISTS button_icons JSONB NOT NULL DEFAULT '{}'::jsonb;

-- Private Telegram webhook secrets: 256-bit random secret per bot, never exposed by any API.
-- Token fingerprint changes rotate only the corresponding bot's secret on the next startup.
CREATE TABLE IF NOT EXISTS telegram_webhook_secrets (
    bot_key TEXT PRIMARY KEY CHECK (bot_key IN ('primary','secondary')),
    token_fingerprint CHAR(64) NOT NULL CHECK (token_fingerprint ~ '^[a-f0-9]{64}$'),
    secret_value VARCHAR(256) NOT NULL CHECK (char_length(secret_value) BETWEEN 32 AND 256 AND secret_value !~ '[^A-Za-z0-9_-]'),
    override_fingerprint CHAR(64) NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
-- Compatibility for an earlier provisioned table version.
ALTER TABLE telegram_webhook_secrets
    ADD COLUMN IF NOT EXISTS override_fingerprint CHAR(64) NOT NULL DEFAULT '';
ALTER TABLE telegram_webhook_secrets
    ALTER COLUMN secret_value TYPE VARCHAR(256);
ALTER TABLE telegram_webhook_secrets
    DROP CONSTRAINT IF EXISTS telegram_webhook_secrets_secret_value_check;
ALTER TABLE telegram_webhook_secrets
    ADD CONSTRAINT telegram_webhook_secrets_secret_value_check
    CHECK (char_length(secret_value) BETWEEN 32 AND 256 AND secret_value !~ '[^A-Za-z0-9_-]');

-- Per-bot website session signing keys used by TelegramAuth::websiteSessionSecret().
CREATE TABLE IF NOT EXISTS website_session_secrets (
    bot_key TEXT PRIMARY KEY CHECK (bot_key IN ('primary','secondary')),
    secret_value VARCHAR(256) NOT NULL CHECK (
        char_length(secret_value) BETWEEN 32 AND 256
        AND secret_value !~ '[^A-Za-z0-9_-]'
    ),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Apply the approved persistent keyboard layout while preserving unrelated settings.
UPDATE bot_menu_configs
SET menu_mode = 'reply_keyboard',
    menu_columns = 2,
    persistent_keyboard = TRUE,
    resize_keyboard = TRUE,
    button_labels = COALESCE(button_labels, '{}'::jsonb) || '{
        "live": "🎮 MINI GAME LIVE",
        "tools": "🧰 THUÊ TOOL (NEW)",
        "codes": "🎁 MUA LÌ XÌ - CODE MÀN - CODE TELE",
        "wallet": "💳 NẠP TIỀN",
        "otp": "🛡️ OTP & PROXY",
        "viptools": "👑 KHO CÔNG CỤ ĐỘC QUYỀN",
        "customer": "💠 TRUNG TÂM KHÁCH HÀNG HENDY",
        "matchmaking": "⚔️ HỆ THỐNG GHÉP ĐỐI ĐẤU",
        "ref": "🎊 GIỚI THIỆU REF",
        "vip": "👑 TRUNG TÂM VIP",
        "group": "👥 NHÓM CHAT ↗",
        "news": "📢 KÊNH THÔNG BÁO ↗",
        "gift": "🎟️ NHẬP GIFTCODE",
        "support": "🧑🏻‍💻 LIÊN HỆ CSKH ↗"
    }'::jsonb,
    button_styles = COALESCE(button_styles, '{}'::jsonb) || '{
        "live": "danger",
        "tools": "primary",
        "codes": "success",
        "wallet": "danger",
        "otp": "primary",
        "viptools": "success",
        "customer": "primary",
        "matchmaking": "danger",
        "ref": "primary",
        "vip": "success",
        "group": "primary",
        "news": "primary",
        "gift": "success",
        "support": "primary"
    }'::jsonb,
    updated_at = NOW();

UPDATE bot_menu_configs SET menu_mode = 'inline_keyboard' WHERE menu_mode IS DISTINCT FROM 'inline_keyboard';

CREATE TABLE IF NOT EXISTS admin_audit (
    id BIGSERIAL PRIMARY KEY,
    admin_user_id TEXT NOT NULL,
    action TEXT NOT NULL,
    target TEXT NOT NULL DEFAULT '',
    details JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS legacy_login_tickets (
    token_hash CHAR(64) PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    bot_key TEXT NOT NULL CHECK (bot_key IN ('primary','secondary')),
    expires_at TIMESTAMPTZ NOT NULL,
    consumed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS linked_accounts (
    id BIGSERIAL PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    brand TEXT NOT NULL,
    username TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(user_id, brand, username)
);
CREATE INDEX IF NOT EXISTS idx_linked_accounts_user ON linked_accounts(user_id, created_at DESC);

CREATE TABLE IF NOT EXISTS matchmaking_matches (
    id BIGSERIAL PRIMARY KEY,
    player_one TEXT NOT NULL,
    player_two TEXT NOT NULL,
    mode TEXT NOT NULL DEFAULT 'quick',
    status TEXT NOT NULL DEFAULT 'matched',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    finished_at TIMESTAMPTZ
);

CREATE TABLE IF NOT EXISTS matchmaking_queue (
    id BIGSERIAL PRIMARY KEY,
    user_id TEXT NOT NULL,
    mode TEXT NOT NULL DEFAULT 'quick',
    status TEXT NOT NULL DEFAULT 'waiting',
    matched_user_id TEXT,
    match_id BIGINT REFERENCES matchmaking_matches(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_matchmaking_one_waiting
    ON matchmaking_queue(user_id) WHERE status = 'waiting';
CREATE INDEX IF NOT EXISTS idx_matchmaking_mode_waiting
    ON matchmaking_queue(mode, status, created_at);

-- Matchmaking progression: Elo rating, non-cash points and bilateral result review.
CREATE TABLE IF NOT EXISTS matchmaking_player_stats (
    user_id TEXT PRIMARY KEY REFERENCES users(user_id) ON DELETE CASCADE,
    rating INTEGER NOT NULL DEFAULT 1000,
    points BIGINT NOT NULL DEFAULT 0 CHECK (points >= 0),
    matches_played INTEGER NOT NULL DEFAULT 0 CHECK (matches_played >= 0),
    wins INTEGER NOT NULL DEFAULT 0 CHECK (wins >= 0),
    losses INTEGER NOT NULL DEFAULT 0 CHECK (losses >= 0),
    draws INTEGER NOT NULL DEFAULT 0 CHECK (draws >= 0),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_matchmaking_stats_leaderboard
    ON matchmaking_player_stats(rating DESC, points DESC, matches_played ASC);

CREATE TABLE IF NOT EXISTS matchmaking_results (
    id BIGSERIAL PRIMARY KEY,
    match_id BIGINT NOT NULL UNIQUE REFERENCES matchmaking_matches(id) ON DELETE CASCADE,
    reported_by TEXT NOT NULL REFERENCES users(user_id),
    score_one INTEGER NOT NULL CHECK (score_one BETWEEN 0 AND 999),
    score_two INTEGER NOT NULL CHECK (score_two BETWEEN 0 AND 999),
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'confirmed', 'disputed')),
    confirmed_by TEXT REFERENCES users(user_id),
    rating_delta_one INTEGER NOT NULL DEFAULT 0,
    rating_delta_two INTEGER NOT NULL DEFAULT 0,
    points_awarded_one INTEGER NOT NULL DEFAULT 0,
    points_awarded_two INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    confirmed_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_matchmaking_results_status
    ON matchmaking_results(status, created_at DESC);

CREATE TABLE IF NOT EXISTS bot_pending_inputs (
    user_id TEXT NOT NULL,
    bot_key TEXT NOT NULL CHECK (bot_key IN ('primary','secondary')),
    input_type TEXT NOT NULL,
    payload JSONB NOT NULL DEFAULT '{}'::jsonb,
    expires_at TIMESTAMPTZ NOT NULL,
    PRIMARY KEY (user_id, bot_key)
);

CREATE TABLE IF NOT EXISTS telegram_updates (
    bot_key TEXT NOT NULL CHECK (bot_key IN ('primary','secondary')),
    update_id BIGINT NOT NULL,
    received_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (bot_key, update_id)
);

CREATE INDEX IF NOT EXISTS idx_products_category_active ON products(category, is_active);
CREATE INDEX IF NOT EXISTS idx_orders_user_created ON orders(user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_orders_status_created ON orders(status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_wallet_user_created ON wallet_ledger(user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_tickets_user_created ON support_tickets(user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_legacy_login_expiry ON legacy_login_tickets(expires_at);

INSERT INTO app_settings (key, value, is_public) VALUES
    ('homepage_title', 'Hendy Bot', TRUE),
    ('announcement', '', TRUE),
    ('VIETQR_BANK_ID', '', TRUE),
    ('VIETQR_ACCOUNT_NO', '', TRUE),
    ('VIETQR_ACCOUNT_NAME', '', TRUE),
    ('GROUP_URL', '', TRUE),
    ('CHANNEL_URL', '', TRUE),
    ('referral_commission_percent', '0', TRUE),
    ('support_message', 'Đội ngũ CSKH sẽ phản hồi trong thời gian sớm nhất.', TRUE)
ON CONFLICT (key) DO NOTHING;

INSERT INTO bot_menu_configs (bot_key, menu_mode, intro_text, menu_columns, persistent_keyboard, resize_keyboard)
VALUES
    ('primary', 'reply_keyboard', 'Chào mừng bạn đến với HENDY BOT. Chọn dịch vụ ở bàn phím phía dưới để bắt đầu.', 2, TRUE, TRUE),
    ('secondary', 'inline_keyboard', 'Chào mừng bạn đến với HENDY BOT. Chọn một chức năng để tiếp tục.', 2, TRUE, TRUE)
ON CONFLICT (bot_key) DO NOTHING;

-- Re-apply the selected layout after seeding so a fresh database receives it too.
UPDATE bot_menu_configs
SET menu_mode = 'reply_keyboard',
    menu_columns = 2,
    persistent_keyboard = TRUE,
    resize_keyboard = TRUE,
    button_labels = COALESCE(button_labels, '{}'::jsonb) || '{
        "live": "🚀 MINIGAME LIVE",
        "otp": "📲 OTP & PROXY",
        "wallet": "💳 NẠP TIỀN",
        "vip": "👑 TRUNG TÂM VIP",
        "codes": "🎁 MUA LÌ XÌ - CODE MÀN - CODE TELE",
        "customer": "💠 TRUNG TÂM KHÁCH HÀNG",
        "group": "🗣️ NHÓM CHAT ↗",
        "news": "📢 KÊNH THÔNG BÁO ↗",
        "vip-coming-soon": "👑 VIP DỊCH VỤ SẮP TRIỂN KHAI"
    }'::jsonb,
    button_styles = COALESCE(button_styles, '{}'::jsonb) || '{
        "live": "danger",
        "otp": "primary",
        "wallet": "danger",
        "vip": "primary",
        "codes": "success",
        "customer": "primary",
        "group": "primary",
        "news": "primary",
        "vip-coming-soon": "primary"
    }'::jsonb,
    updated_at = NOW();


-- Durable event stream and one-time WebSocket authentication tickets.
CREATE TABLE IF NOT EXISTS realtime_events (
    id BIGSERIAL PRIMARY KEY,
    event_type TEXT NOT NULL,
    audience TEXT NOT NULL DEFAULT 'admin' CHECK (audience IN ('admin','users','all')),
    payload JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_realtime_events_created ON realtime_events(created_at);
CREATE TABLE IF NOT EXISTS realtime_tickets (
    ticket_hash CHAR(64) PRIMARY KEY,
    admin_user_id TEXT NOT NULL,
    audience TEXT NOT NULL DEFAULT 'admin' CHECK (audience IN ('admin','users')),
    expires_at TIMESTAMPTZ NOT NULL,
    consumed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
ALTER TABLE realtime_tickets ADD COLUMN IF NOT EXISTS audience TEXT NOT NULL DEFAULT 'admin'
    CHECK (audience IN ('admin','users'));
CREATE INDEX IF NOT EXISTS idx_realtime_tickets_expiry ON realtime_tickets(expires_at);
CREATE TABLE IF NOT EXISTS module_artifacts (
    id BIGSERIAL PRIMARY KEY,
    module_key TEXT NOT NULL,
    original_name TEXT NOT NULL,
    stored_name TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    sha256 CHAR(64) NOT NULL,
    uploaded_by TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending_review' CHECK (status IN ('pending_review','approved','rejected','released')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_module_artifacts_status ON module_artifacts(status, created_at DESC);
CREATE TABLE IF NOT EXISTS store_integrations (
    provider TEXT PRIMARY KEY CHECK (provider IN ('app_store_connect','google_play','microsoft_store')),
    config_public JSONB NOT NULL DEFAULT '{}'::jsonb,
    secret_refs JSONB NOT NULL DEFAULT '{}'::jsonb,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);


-- Bank account/QR details are private runtime settings. Keep them available
-- to BotService/admin, but never include them in public bootstrap settings.
UPDATE app_settings
SET is_public = FALSE, updated_at = NOW()
WHERE key IN ('VIETQR_BANK_ID', 'VIETQR_ACCOUNT_NO', 'VIETQR_ACCOUNT_NAME');


-- HENDY operations controls for independent primary/secondary bot maintenance.
INSERT INTO app_settings (key,value,is_public) VALUES
 ('ops_primary_mode','off',FALSE),
 ('ops_primary_message','HENDY BOT đang bảo trì. Vui lòng quay lại sau.',FALSE),
 ('ops_primary_until','',FALSE),
 ('ops_secondary_mode','off',FALSE),
 ('ops_secondary_message','HENDY BOT thử nghiệm đang bảo trì. Vui lòng quay lại sau.',FALSE),
 ('ops_secondary_until','',FALSE),
 ('homeproxy_enabled','0',FALSE)
ON CONFLICT (key) DO NOTHING;

-- Per-member HomeProxy API keys are stored only as AES-256-GCM ciphertext.
-- The encryption key lives in Render Environment, never in PostgreSQL.
CREATE TABLE IF NOT EXISTS homeproxy_accounts (
 user_id TEXT PRIMARY KEY REFERENCES users(user_id) ON DELETE CASCADE,
 token_ciphertext TEXT NOT NULL,
 token_nonce TEXT NOT NULL,
 token_tag TEXT NOT NULL,
 last_action TEXT,
 last_status TEXT,
 last_action_at TIMESTAMPTZ,
 created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
 updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS homeproxy_logs (
 id BIGSERIAL PRIMARY KEY,
 user_id TEXT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
 action TEXT NOT NULL CHECK (action IN ('check','rotate')),
 status TEXT NOT NULL CHECK (status IN ('success','failed')),
 provider_message TEXT NOT NULL DEFAULT '',
 created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_homeproxy_logs_created ON homeproxy_logs(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_homeproxy_logs_user_created ON homeproxy_logs(user_id,created_at DESC);


-- HENDY CODE TELEGRAM: additive schema for allow-listed channel ingestion and OCR.
CREATE TABLE IF NOT EXISTS code_telegram_sources (
    id BIGSERIAL PRIMARY KEY,
    chat_id TEXT NOT NULL UNIQUE,
    username TEXT NOT NULL DEFAULT '',
    title TEXT NOT NULL,
    added_by TEXT NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    auto_publish BOOLEAN NOT NULL DEFAULT FALSE,
    last_update_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CHECK (char_length(chat_id) BETWEEN 3 AND 32),
    CHECK (char_length(title) BETWEEN 1 AND 160)
);
CREATE TABLE IF NOT EXISTS code_telegram_posts (
    id BIGSERIAL PRIMARY KEY,
    source_id BIGINT NOT NULL REFERENCES code_telegram_sources(id) ON DELETE CASCADE,
    telegram_message_id BIGINT NOT NULL CHECK (telegram_message_id > 0),
    update_kind TEXT NOT NULL DEFAULT 'channel_post' CHECK (update_kind IN ('channel_post','edited_channel_post')),
    message_date TIMESTAMPTZ,
    message_text TEXT NOT NULL DEFAULT '',
    source_link TEXT NOT NULL DEFAULT '',
    media_kind TEXT NOT NULL DEFAULT 'text' CHECK (media_kind IN ('text','video')),
    media_file_id TEXT NOT NULL DEFAULT '',
    media_file_size BIGINT NOT NULL DEFAULT 0 CHECK (media_file_size >= 0),
    media_mime_type TEXT NOT NULL DEFAULT '',
    extracted_text TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','processing','completed','failed','ignored')),
    attempts SMALLINT NOT NULL DEFAULT 0 CHECK (attempts BETWEEN 0 AND 10),
    extracted_count SMALLINT NOT NULL DEFAULT 0 CHECK (extracted_count BETWEEN 0 AND 200),
    error_message TEXT NOT NULL DEFAULT '',
    started_at TIMESTAMPTZ,
    processed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (source_id, telegram_message_id)
);
CREATE TABLE IF NOT EXISTS code_telegram_codes (
    id BIGSERIAL PRIMARY KEY,
    post_id BIGINT NOT NULL REFERENCES code_telegram_posts(id) ON DELETE CASCADE,
    source_id BIGINT NOT NULL REFERENCES code_telegram_sources(id) ON DELETE CASCADE,
    code_value VARCHAR(64) NOT NULL,
    normalized_code VARCHAR(64) NOT NULL,
    category TEXT NOT NULL CHECK (category IN ('promo','game','software','telegram','screen')),
    context_text VARCHAR(500) NOT NULL DEFAULT '',
    confidence NUMERIC(4,3) NOT NULL DEFAULT 0.900 CHECK (confidence BETWEEN 0 AND 1),
    status TEXT NOT NULL DEFAULT 'pending_review' CHECK (status IN ('pending_review','active','used','rejected','expired')),
    reviewed_by TEXT NOT NULL DEFAULT '',
    reviewed_at TIMESTAMPTZ,
    expires_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (post_id, normalized_code)
);
CREATE INDEX IF NOT EXISTS idx_code_telegram_posts_queue ON code_telegram_posts(status, created_at ASC);
CREATE INDEX IF NOT EXISTS idx_code_telegram_posts_source ON code_telegram_posts(source_id, message_date DESC);
CREATE INDEX IF NOT EXISTS idx_code_telegram_codes_status ON code_telegram_codes(status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_code_telegram_codes_search ON code_telegram_codes(normalized_code);
CREATE INDEX IF NOT EXISTS idx_code_telegram_codes_category ON code_telegram_codes(category, status, created_at DESC);
GRANT SELECT, INSERT, UPDATE, DELETE ON code_telegram_sources, code_telegram_posts, code_telegram_codes TO hendy_bot_app;
GRANT USAGE, SELECT ON SEQUENCE code_telegram_sources_id_seq, code_telegram_posts_id_seq, code_telegram_codes_id_seq TO hendy_bot_app;


-- CODE TELEGRAM service catalog and username intake queue.
CREATE TABLE IF NOT EXISTS code_telegram_services (
    id BIGSERIAL PRIMARY KEY,
    service_key VARCHAR(48) NOT NULL UNIQUE,
    brand VARCHAR(24) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    code_type TEXT NOT NULL CHECK (code_type IN ('video','text')),
    price_min BIGINT NOT NULL CHECK (price_min >= 0),
    price_max BIGINT NOT NULL CHECK (price_max >= price_min),
    daily_limit SMALLINT NOT NULL DEFAULT 1 CHECK (daily_limit BETWEEN 1 AND 20),
    max_accounts SMALLINT NOT NULL DEFAULT 20 CHECK (max_accounts BETWEEN 1 AND 20),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order SMALLINT NOT NULL DEFAULT 100,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
INSERT INTO code_telegram_services
    (service_key, brand, display_name, code_type, price_min, price_max, daily_limit, max_accounts, sort_order)
VALUES
    ('xx88_video', 'XX88', '🔴 XX88', 'video', 5000, 5000, 1, 20, 10),
    ('llwin_video', 'LLWIN', '🟢 LLWIN', 'video', 5000, 5000, 1, 20, 20),
    ('78win_video', '78WIN', '🟣 78WIN', 'video', 10000, 10000, 1, 20, 30),
    ('xx8_video', 'XX8', '🎁 XX8', 'video', 5000, 5000, 1, 20, 40),
    ('mb66_video', 'MB66', '🟠 MB66', 'video', 10000, 10000, 1, 20, 50),
    ('on68_video', 'ON68', '🔵 ON68', 'video', 5000, 20000, 1, 20, 60),
    ('new88_video', 'NEW88', '🟡 NEW88', 'video', 5000, 20000, 1, 20, 70),
    ('xx88_vip_text', 'XX88', '💎 Code VIP XX88', 'text', 20000, 20000, 1, 20, 80),
    ('llwin_vip_text', 'LLWIN', '💎 Code VIP LLWIN', 'text', 20000, 20000, 1, 20, 90)
ON CONFLICT (service_key) DO UPDATE SET
    brand = EXCLUDED.brand, display_name = EXCLUDED.display_name, code_type = EXCLUDED.code_type,
    price_min = EXCLUDED.price_min, price_max = EXCLUDED.price_max,
    daily_limit = EXCLUDED.daily_limit, max_accounts = EXCLUDED.max_accounts,
    sort_order = EXCLUDED.sort_order, updated_at = NOW();
CREATE TABLE IF NOT EXISTS code_telegram_requests (
    id BIGSERIAL PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    service_id BIGINT NOT NULL REFERENCES code_telegram_services(id),
    service_key_snapshot VARCHAR(48) NOT NULL,
    service_name_snapshot VARCHAR(100) NOT NULL,
    brand_snapshot VARCHAR(24) NOT NULL,
    code_type_snapshot TEXT NOT NULL CHECK (code_type_snapshot IN ('video','text')),
    price_min_snapshot BIGINT NOT NULL,
    price_max_snapshot BIGINT NOT NULL,
    account_count SMALLINT NOT NULL CHECK (account_count BETWEEN 1 AND 20),
    status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','processing','completed','partial','cancelled')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS code_telegram_request_accounts (
    id BIGSERIAL PRIMARY KEY,
    request_id BIGINT NOT NULL REFERENCES code_telegram_requests(id) ON DELETE CASCADE,
    username VARCHAR(64) NOT NULL,
    username_normalized VARCHAR(64) NOT NULL,
    status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','processing','completed','failed')),
    code_value VARCHAR(64) NOT NULL DEFAULT '',
    charged_amount BIGINT NOT NULL DEFAULT 0 CHECK (charged_amount >= 0),
    result_note VARCHAR(500) NOT NULL DEFAULT '',
    processed_by TEXT NOT NULL DEFAULT '',
    processed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (request_id, username_normalized)
);
CREATE INDEX IF NOT EXISTS idx_code_telegram_services_active ON code_telegram_services(is_active, sort_order);
CREATE INDEX IF NOT EXISTS idx_code_telegram_requests_queue ON code_telegram_requests(status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_code_telegram_requests_user ON code_telegram_requests(user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_code_telegram_request_accounts_status ON code_telegram_request_accounts(status, created_at ASC);
CREATE INDEX IF NOT EXISTS idx_code_telegram_request_accounts_daily ON code_telegram_request_accounts(username_normalized, created_at DESC);
GRANT SELECT ON code_telegram_services TO hendy_bot_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON code_telegram_requests, code_telegram_request_accounts TO hendy_bot_app;
GRANT USAGE, SELECT ON SEQUENCE code_telegram_services_id_seq, code_telegram_requests_id_seq, code_telegram_request_accounts_id_seq TO hendy_bot_app;
UPDATE bot_menu_configs
SET button_labels = COALESCE(button_labels, '{}'::jsonb) || jsonb_build_object('codes', '📡 CODE TELEGRAM')
WHERE bot_key IN ('primary','secondary');


-- AI Cover: licensed voice conversion queue
-- HENDY AI Cover: single-bot audio conversion queue.
-- Presets are visible only after explicit voice-owner permission and worker verification.
-- Source/result/model objects remain private in Neon Object Storage.

CREATE TABLE IF NOT EXISTS ai_cover_sessions (
    telegram_user_id TEXT PRIMARY KEY REFERENCES users(user_id) ON DELETE CASCADE,
    chat_id TEXT NOT NULL,
    selected_voice_id TEXT,
    terms_version VARCHAR(24) NOT NULL DEFAULT '',
    terms_accepted_at TIMESTAMPTZ,
    last_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CHECK (telegram_user_id ~ '^[0-9]{5,20}$')
);

CREATE TABLE IF NOT EXISTS ai_cover_voices (
    id VARCHAR(48) PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    description VARCHAR(300) NOT NULL DEFAULT '',
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    model_key TEXT NOT NULL UNIQUE,
    index_key TEXT UNIQUE,
    speaker_id SMALLINT NOT NULL DEFAULT 0 CHECK (speaker_id BETWEEN 0 AND 109),
    pitch SMALLINT NOT NULL DEFAULT 0 CHECK (pitch BETWEEN -12 AND 12),
    index_rate NUMERIC(4,3) NOT NULL DEFAULT 0.650 CHECK (index_rate BETWEEN 0 AND 1),
    f0_method TEXT NOT NULL DEFAULT 'rmvpe' CHECK (f0_method IN ('rmvpe','pm')),
    rights_holder VARCHAR(160) NOT NULL,
    license_name VARCHAR(160) NOT NULL,
    authorization_reference VARCHAR(200) NOT NULL,
    voice_owner_consent BOOLEAN NOT NULL DEFAULT FALSE,
    commercial_use_allowed BOOLEAN NOT NULL DEFAULT FALSE,
    consent_reviewed_by TEXT NOT NULL DEFAULT '',
    consent_reviewed_at TIMESTAMPTZ,
    available BOOLEAN NOT NULL DEFAULT FALSE,
    checked_at TIMESTAMPTZ,
    last_error VARCHAR(500) NOT NULL DEFAULT '',
    created_by TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CHECK (id ~ '^[a-z0-9][a-z0-9_-]{1,47}$'),
    CHECK (length(trim(name)) BETWEEN 1 AND 50),
    CHECK (length(trim(rights_holder)) > 0),
    CHECK (length(trim(license_name)) > 0),
    CHECK (length(trim(authorization_reference)) > 0),
    CHECK (NOT enabled OR voice_owner_consent = TRUE)
);

CREATE TABLE IF NOT EXISTS ai_cover_jobs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    telegram_user_id TEXT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    chat_id TEXT NOT NULL,
    telegram_update_id BIGINT NOT NULL UNIQUE,
    telegram_file_unique_id TEXT,
    source_key TEXT NOT NULL,
    source_filename VARCHAR(120) NOT NULL DEFAULT 'audio-upload',
    source_mime_type VARCHAR(100) NOT NULL DEFAULT 'application/octet-stream',
    voice_id VARCHAR(48) NOT NULL REFERENCES ai_cover_voices(id) ON DELETE RESTRICT,
    voice_name VARCHAR(50) NOT NULL,
    status TEXT NOT NULL DEFAULT 'queued'
        CHECK (status IN ('queued','processing','completed','failed','cancelled')),
    attempts SMALLINT NOT NULL DEFAULT 0 CHECK (attempts BETWEEN 0 AND 10),
    max_attempts SMALLINT NOT NULL DEFAULT 3 CHECK (max_attempts BETWEEN 1 AND 10),
    available_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    result_key TEXT,
    duration_seconds NUMERIC(8,2),
    output_size_bytes BIGINT CHECK (output_size_bytes IS NULL OR output_size_bytes > 0),
    last_error VARCHAR(1500),
    worker_id VARCHAR(100),
    provider_job_id VARCHAR(160) NOT NULL DEFAULT '',
    started_at TIMESTAMPTZ,
    completed_at TIMESTAMPTZ,
    delivery_sent_at TIMESTAMPTZ,
    delivery_claimed_at TIMESTAMPTZ,
    delivery_attempts SMALLINT NOT NULL DEFAULT 0 CHECK (delivery_attempts BETWEEN 0 AND 20),
    delivery_retry_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CHECK (telegram_user_id ~ '^[0-9]{5,20}$')
);

CREATE INDEX IF NOT EXISTS idx_ai_cover_jobs_queue
    ON ai_cover_jobs(status, available_at, created_at)
    WHERE status IN ('queued','processing');
CREATE INDEX IF NOT EXISTS idx_ai_cover_jobs_user_created
    ON ai_cover_jobs(telegram_user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_ai_cover_jobs_stale
    ON ai_cover_jobs(updated_at)
    WHERE status = 'processing';
CREATE INDEX IF NOT EXISTS idx_ai_cover_jobs_delivery
    ON ai_cover_jobs(status, delivery_retry_at)
    WHERE status IN ('completed','failed') AND delivery_sent_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_ai_cover_voices_available
    ON ai_cover_voices(enabled, available, checked_at DESC);

CREATE TABLE IF NOT EXISTS ai_cover_events (
    id BIGSERIAL PRIMARY KEY,
    job_id UUID NOT NULL REFERENCES ai_cover_jobs(id) ON DELETE CASCADE,
    event_type VARCHAR(40) NOT NULL,
    details JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_ai_cover_events_job_created
    ON ai_cover_events(job_id, created_at DESC);

GRANT SELECT, INSERT, UPDATE, DELETE ON ai_cover_sessions, ai_cover_voices, ai_cover_jobs, ai_cover_events TO hendy_bot_app;
GRANT USAGE, SELECT ON SEQUENCE ai_cover_events_id_seq TO hendy_bot_app;

 
-- HENDY Matchmaking lifecycle: bilateral acceptance, score confirmation and admin dispute review.
ALTER TABLE matchmaking_matches
    ADD COLUMN IF NOT EXISTS player_one_accepted_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS player_two_accepted_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS expires_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS started_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS cancelled_by TEXT,
    ADD COLUMN IF NOT EXISTS cancel_reason TEXT NOT NULL DEFAULT '';
ALTER TABLE matchmaking_results
    ADD COLUMN IF NOT EXISTS resolved_by TEXT,
    ADD COLUMN IF NOT EXISTS resolution_note TEXT NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW();
CREATE INDEX IF NOT EXISTS idx_matchmaking_matches_status_created
    ON matchmaking_matches(status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_matchmaking_matches_player_one
    ON matchmaking_matches(player_one, status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_matchmaking_matches_player_two
    ON matchmaking_matches(player_two, status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_matchmaking_queue_matched
    ON matchmaking_queue(match_id, status)
    WHERE match_id IS NOT NULL;
GRANT SELECT, INSERT, UPDATE, DELETE ON matchmaking_matches, matchmaking_queue,
    matchmaking_player_stats, matchmaking_results TO hendy_bot_app;
GRANT USAGE, SELECT ON SEQUENCE matchmaking_matches_id_seq, matchmaking_queue_id_seq,
    matchmaking_results_id_seq TO hendy_bot_app;
);
    END IF;
END $;

CREATE INDEX IF NOT EXISTS idx_products_provider_readiness
    ON products(category, is_active, source_permission_status, supplier_access_status, fulfillment_mode);

CREATE TABLE IF NOT EXISTS orders (
    id BIGSERIAL PRIMARY KEY,
    order_code TEXT NOT NULL UNIQUE,
    user_id TEXT NOT NULL,
    product_id BIGINT REFERENCES products(id) ON DELETE SET NULL,
    product_name TEXT NOT NULL,
    category TEXT NOT NULL,
    amount NUMERIC(18, 0) NOT NULL CHECK (amount >= 0),
    status TEXT NOT NULL DEFAULT 'pending',
    admin_note TEXT NOT NULL DEFAULT '',
    delivery_text TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS wallet_ledger (
    id BIGSERIAL PRIMARY KEY,
    reference_code TEXT UNIQUE,
    user_id TEXT NOT NULL,
    amount NUMERIC(18, 0) NOT NULL,
    transaction_type TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE wallet_ledger ADD COLUMN IF NOT EXISTS wallet_type TEXT NOT NULL DEFAULT 'MAIN';

CREATE TABLE IF NOT EXISTS support_tickets (
    id BIGSERIAL PRIMARY KEY,
    user_id TEXT NOT NULL,
    subject TEXT NOT NULL,
    message TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'open',
    priority TEXT NOT NULL DEFAULT 'normal',
    admin_reply TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS ticket_type TEXT NOT NULL DEFAULT 'support';
CREATE INDEX IF NOT EXISTS idx_support_tickets_type_created ON support_tickets(ticket_type, created_at DESC);

CREATE TABLE IF NOT EXISTS voucher_requests (
    id BIGSERIAL PRIMARY KEY,
    order_id BIGINT NOT NULL UNIQUE REFERENCES orders(id) ON DELETE CASCADE,
    user_id TEXT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    order_code TEXT NOT NULL,
    reason TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected')),
    reward_amount NUMERIC(18, 0) NOT NULL DEFAULT 0 CHECK (reward_amount >= 0),
    reviewed_by TEXT,
    reviewed_at TIMESTAMPTZ,
    admin_note TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_voucher_requests_status_created ON voucher_requests(status, created_at ASC);
CREATE INDEX IF NOT EXISTS idx_voucher_requests_user_created ON voucher_requests(user_id, created_at DESC);

-- PostgreSQL-backed queue for video subtitle jobs. The optional worker
-- dispatches jobs to a configured provider; without a provider they stay queued.
CREATE TABLE IF NOT EXISTS video_jobs (
    id UUID PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    bot_key TEXT NOT NULL DEFAULT 'primary' CHECK (bot_key IN ('primary','secondary')),
    source_url TEXT NOT NULL,
    provider_job_id TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','processing','completed','failed')),
    progress SMALLINT NOT NULL DEFAULT 0 CHECK (progress BETWEEN 0 AND 100),
    output_url TEXT NOT NULL DEFAULT '',
    error_message TEXT NOT NULL DEFAULT '',
    attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    claimed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    completed_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_video_jobs_queue
    ON video_jobs(status, created_at ASC);
CREATE INDEX IF NOT EXISTS idx_video_jobs_user_created
    ON video_jobs(user_id, created_at DESC);

-- Queue for asynchronous AI music generation and image editing.
-- Workers are only started when AI_MEDIA_QUEUE_ENABLED=true and provider keys are present.
CREATE TABLE IF NOT EXISTS ai_media_jobs (
    id UUID PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    bot_key TEXT NOT NULL DEFAULT 'primary' CHECK (bot_key = 'primary'),
    job_type TEXT NOT NULL CHECK (job_type IN ('music','image')),
    prompt TEXT NOT NULL,
    source_file_id TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','processing','completed','failed')),
    attempts SMALLINT NOT NULL DEFAULT 0 CHECK (attempts BETWEEN 0 AND 3),
    claimed_at TIMESTAMPTZ,
    error_message TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    completed_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_ai_media_jobs_queue
    ON ai_media_jobs(status, created_at ASC);
CREATE INDEX IF NOT EXISTS idx_ai_media_jobs_user_created
    ON ai_media_jobs(user_id, created_at DESC);
GRANT SELECT, INSERT, UPDATE, DELETE ON ai_media_jobs TO hendy_bot_app;

CREATE TABLE IF NOT EXISTS giftcodes (
    code TEXT PRIMARY KEY,
    reward_amount NUMERIC(18, 0) NOT NULL DEFAULT 0 CHECK (reward_amount >= 0),
    max_redemptions INTEGER NOT NULL DEFAULT 1 CHECK (max_redemptions > 0),
    redeemed_count INTEGER NOT NULL DEFAULT 0,
    expires_at TIMESTAMPTZ,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS giftcode_redemptions (
    code TEXT NOT NULL REFERENCES giftcodes(code) ON DELETE CASCADE,
    user_id TEXT NOT NULL,
    reward_amount NUMERIC(18, 0) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (code, user_id)
);

CREATE TABLE IF NOT EXISTS app_settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT '',
    is_public BOOLEAN NOT NULL DEFAULT TRUE,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Default official website URL shown in the supplied Telegram welcome-screen reference.
-- Admin can change it from the application settings page; preserve later edits.
INSERT INTO app_settings (key, value, is_public)
VALUES ('website_url', 'https://hendydichvumang-szad.onrender.com', TRUE)
ON CONFLICT (key) DO NOTHING;

INSERT INTO app_settings (key, value, is_public)
VALUES ('vip_checkin_points', '1', TRUE)
ON CONFLICT (key) DO NOTHING;

-- Default welcome-screen links. ON CONFLICT preserves any administrator customization.
UPDATE app_settings
SET value = 'https://hendydichvumang-szad.onrender.com', updated_at = NOW()
WHERE key = 'website_url'
  AND value IN (
      'https://lethituanem.com',
      'https://hendy-bot-tele.onrender.com',
      'https://hendy-bot-cybertech.onrender.com',
      'https://hendy-bot-cybertech-1.onrender.com',
      'https://hendy-bot-cybertech-1.onrender.com/',
      'https://hendydichvumang-telegram-bot.onrender.com/',
      'https://hendydichvumang.onrender.com',
      'https://hendydichvumang.onrender.com/',
      'https://hendydichvumang-static-site.onrender.com',
      'https://hendydichvumang-static-site.onrender.com/',
      'https://hendydichvumang-website.onrender.com',
      'https://hendydichvumang-website.onrender.com/'
  );

INSERT INTO app_settings (key, value, is_public)
VALUES
    ('GROUP_URL', 'https://t.me/Hendy_Support_Group', TRUE)
ON CONFLICT (key) DO NOTHING;

-- Legacy editable bot-name settings are retired; public Telegram URLs now come from deployment environment.
DELETE FROM app_settings WHERE key IN ('bot_username', 'bot_display_name');

-- Migrate only the old bundled defaults; preserve any administrator customization.
UPDATE app_settings
SET value = 'https://t.me/Hendy_Support_Group', updated_at = NOW()
WHERE key = 'GROUP_URL' AND value = 'https://t.me/lethituanem_nhomchat';

CREATE TABLE IF NOT EXISTS bot_menu_configs (
    bot_key TEXT PRIMARY KEY CHECK (bot_key IN ('primary','secondary')),
    menu_mode TEXT NOT NULL DEFAULT 'inline_keyboard' CHECK (menu_mode IN ('reply_keyboard','inline_keyboard')),
    intro_text TEXT NOT NULL DEFAULT '',
    menu_columns SMALLINT NOT NULL DEFAULT 2 CHECK (menu_columns BETWEEN 1 AND 3),
    persistent_keyboard BOOLEAN NOT NULL DEFAULT TRUE,
    resize_keyboard BOOLEAN NOT NULL DEFAULT TRUE,
    button_labels JSONB NOT NULL DEFAULT '{}'::jsonb,
    button_descriptions JSONB NOT NULL DEFAULT '{}'::jsonb,
    button_styles JSONB NOT NULL DEFAULT '{}'::jsonb,
    button_icons JSONB NOT NULL DEFAULT '{}'::jsonb,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE bot_menu_configs ADD COLUMN IF NOT EXISTS button_icons JSONB NOT NULL DEFAULT '{}'::jsonb;

-- Private Telegram webhook secrets: 256-bit random secret per bot, never exposed by any API.
-- Token fingerprint changes rotate only the corresponding bot's secret on the next startup.
CREATE TABLE IF NOT EXISTS telegram_webhook_secrets (
    bot_key TEXT PRIMARY KEY CHECK (bot_key IN ('primary','secondary')),
    token_fingerprint CHAR(64) NOT NULL CHECK (token_fingerprint ~ '^[a-f0-9]{64}$'),
    secret_value VARCHAR(256) NOT NULL CHECK (char_length(secret_value) BETWEEN 32 AND 256 AND secret_value !~ '[^A-Za-z0-9_-]'),
    override_fingerprint CHAR(64) NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
-- Compatibility for an earlier provisioned table version.
ALTER TABLE telegram_webhook_secrets
    ADD COLUMN IF NOT EXISTS override_fingerprint CHAR(64) NOT NULL DEFAULT '';
ALTER TABLE telegram_webhook_secrets
    ALTER COLUMN secret_value TYPE VARCHAR(256);
ALTER TABLE telegram_webhook_secrets
    DROP CONSTRAINT IF EXISTS telegram_webhook_secrets_secret_value_check;
ALTER TABLE telegram_webhook_secrets
    ADD CONSTRAINT telegram_webhook_secrets_secret_value_check
    CHECK (char_length(secret_value) BETWEEN 32 AND 256 AND secret_value !~ '[^A-Za-z0-9_-]');

-- Per-bot website session signing keys used by TelegramAuth::websiteSessionSecret().
CREATE TABLE IF NOT EXISTS website_session_secrets (
    bot_key TEXT PRIMARY KEY CHECK (bot_key IN ('primary','secondary')),
    secret_value VARCHAR(256) NOT NULL CHECK (
        char_length(secret_value) BETWEEN 32 AND 256
        AND secret_value !~ '[^A-Za-z0-9_-]'
    ),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Apply the approved persistent keyboard layout while preserving unrelated settings.
UPDATE bot_menu_configs
SET menu_mode = 'reply_keyboard',
    menu_columns = 2,
    persistent_keyboard = TRUE,
    resize_keyboard = TRUE,
    button_labels = COALESCE(button_labels, '{}'::jsonb) || '{
        "live": "🎮 MINI GAME LIVE",
        "tools": "🧰 THUÊ TOOL (NEW)",
        "codes": "🎁 MUA LÌ XÌ - CODE MÀN - CODE TELE",
        "wallet": "💳 NẠP TIỀN",
        "otp": "🛡️ OTP & PROXY",
        "viptools": "👑 KHO CÔNG CỤ ĐỘC QUYỀN",
        "customer": "💠 TRUNG TÂM KHÁCH HÀNG HENDY",
        "matchmaking": "⚔️ HỆ THỐNG GHÉP ĐỐI ĐẤU",
        "ref": "🎊 GIỚI THIỆU REF",
        "vip": "👑 TRUNG TÂM VIP",
        "group": "👥 NHÓM CHAT ↗",
        "news": "📢 KÊNH THÔNG BÁO ↗",
        "gift": "🎟️ NHẬP GIFTCODE",
        "support": "🧑🏻‍💻 LIÊN HỆ CSKH ↗"
    }'::jsonb,
    button_styles = COALESCE(button_styles, '{}'::jsonb) || '{
        "live": "danger",
        "tools": "primary",
        "codes": "success",
        "wallet": "danger",
        "otp": "primary",
        "viptools": "success",
        "customer": "primary",
        "matchmaking": "danger",
        "ref": "primary",
        "vip": "success",
        "group": "primary",
        "news": "primary",
        "gift": "success",
        "support": "primary"
    }'::jsonb,
    updated_at = NOW();

UPDATE bot_menu_configs SET menu_mode = 'inline_keyboard' WHERE menu_mode IS DISTINCT FROM 'inline_keyboard';

CREATE TABLE IF NOT EXISTS admin_audit (
    id BIGSERIAL PRIMARY KEY,
    admin_user_id TEXT NOT NULL,
    action TEXT NOT NULL,
    target TEXT NOT NULL DEFAULT '',
    details JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS legacy_login_tickets (
    token_hash CHAR(64) PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    bot_key TEXT NOT NULL CHECK (bot_key IN ('primary','secondary')),
    expires_at TIMESTAMPTZ NOT NULL,
    consumed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS linked_accounts (
    id BIGSERIAL PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    brand TEXT NOT NULL,
    username TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(user_id, brand, username)
);
CREATE INDEX IF NOT EXISTS idx_linked_accounts_user ON linked_accounts(user_id, created_at DESC);

CREATE TABLE IF NOT EXISTS matchmaking_matches (
    id BIGSERIAL PRIMARY KEY,
    player_one TEXT NOT NULL,
    player_two TEXT NOT NULL,
    mode TEXT NOT NULL DEFAULT 'quick',
    status TEXT NOT NULL DEFAULT 'matched',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    finished_at TIMESTAMPTZ
);

CREATE TABLE IF NOT EXISTS matchmaking_queue (
    id BIGSERIAL PRIMARY KEY,
    user_id TEXT NOT NULL,
    mode TEXT NOT NULL DEFAULT 'quick',
    status TEXT NOT NULL DEFAULT 'waiting',
    matched_user_id TEXT,
    match_id BIGINT REFERENCES matchmaking_matches(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_matchmaking_one_waiting
    ON matchmaking_queue(user_id) WHERE status = 'waiting';
CREATE INDEX IF NOT EXISTS idx_matchmaking_mode_waiting
    ON matchmaking_queue(mode, status, created_at);

-- Matchmaking progression: Elo rating, non-cash points and bilateral result review.
CREATE TABLE IF NOT EXISTS matchmaking_player_stats (
    user_id TEXT PRIMARY KEY REFERENCES users(user_id) ON DELETE CASCADE,
    rating INTEGER NOT NULL DEFAULT 1000,
    points BIGINT NOT NULL DEFAULT 0 CHECK (points >= 0),
    matches_played INTEGER NOT NULL DEFAULT 0 CHECK (matches_played >= 0),
    wins INTEGER NOT NULL DEFAULT 0 CHECK (wins >= 0),
    losses INTEGER NOT NULL DEFAULT 0 CHECK (losses >= 0),
    draws INTEGER NOT NULL DEFAULT 0 CHECK (draws >= 0),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_matchmaking_stats_leaderboard
    ON matchmaking_player_stats(rating DESC, points DESC, matches_played ASC);

CREATE TABLE IF NOT EXISTS matchmaking_results (
    id BIGSERIAL PRIMARY KEY,
    match_id BIGINT NOT NULL UNIQUE REFERENCES matchmaking_matches(id) ON DELETE CASCADE,
    reported_by TEXT NOT NULL REFERENCES users(user_id),
    score_one INTEGER NOT NULL CHECK (score_one BETWEEN 0 AND 999),
    score_two INTEGER NOT NULL CHECK (score_two BETWEEN 0 AND 999),
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'confirmed', 'disputed')),
    confirmed_by TEXT REFERENCES users(user_id),
    rating_delta_one INTEGER NOT NULL DEFAULT 0,
    rating_delta_two INTEGER NOT NULL DEFAULT 0,
    points_awarded_one INTEGER NOT NULL DEFAULT 0,
    points_awarded_two INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    confirmed_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_matchmaking_results_status
    ON matchmaking_results(status, created_at DESC);

CREATE TABLE IF NOT EXISTS bot_pending_inputs (
    user_id TEXT NOT NULL,
    bot_key TEXT NOT NULL CHECK (bot_key IN ('primary','secondary')),
    input_type TEXT NOT NULL,
    payload JSONB NOT NULL DEFAULT '{}'::jsonb,
    expires_at TIMESTAMPTZ NOT NULL,
    PRIMARY KEY (user_id, bot_key)
);

CREATE TABLE IF NOT EXISTS telegram_updates (
    bot_key TEXT NOT NULL CHECK (bot_key IN ('primary','secondary')),
    update_id BIGINT NOT NULL,
    received_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (bot_key, update_id)
);

CREATE INDEX IF NOT EXISTS idx_products_category_active ON products(category, is_active);
CREATE INDEX IF NOT EXISTS idx_orders_user_created ON orders(user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_orders_status_created ON orders(status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_wallet_user_created ON wallet_ledger(user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_tickets_user_created ON support_tickets(user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_legacy_login_expiry ON legacy_login_tickets(expires_at);

INSERT INTO app_settings (key, value, is_public) VALUES
    ('homepage_title', 'Hendy Bot', TRUE),
    ('announcement', '', TRUE),
    ('VIETQR_BANK_ID', '', TRUE),
    ('VIETQR_ACCOUNT_NO', '', TRUE),
    ('VIETQR_ACCOUNT_NAME', '', TRUE),
    ('GROUP_URL', '', TRUE),
    ('CHANNEL_URL', '', TRUE),
    ('referral_commission_percent', '0', TRUE),
    ('support_message', 'Đội ngũ CSKH sẽ phản hồi trong thời gian sớm nhất.', TRUE)
ON CONFLICT (key) DO NOTHING;

INSERT INTO bot_menu_configs (bot_key, menu_mode, intro_text, menu_columns, persistent_keyboard, resize_keyboard)
VALUES
    ('primary', 'reply_keyboard', 'Chào mừng bạn đến với HENDY BOT. Chọn dịch vụ ở bàn phím phía dưới để bắt đầu.', 2, TRUE, TRUE),
    ('secondary', 'inline_keyboard', 'Chào mừng bạn đến với HENDY BOT. Chọn một chức năng để tiếp tục.', 2, TRUE, TRUE)
ON CONFLICT (bot_key) DO NOTHING;

-- Re-apply the selected layout after seeding so a fresh database receives it too.
UPDATE bot_menu_configs
SET menu_mode = 'reply_keyboard',
    menu_columns = 2,
    persistent_keyboard = TRUE,
    resize_keyboard = TRUE,
    button_labels = COALESCE(button_labels, '{}'::jsonb) || '{
        "live": "🚀 MINIGAME LIVE",
        "otp": "📲 OTP & PROXY",
        "wallet": "💳 NẠP TIỀN",
        "vip": "👑 TRUNG TÂM VIP",
        "codes": "🎁 MUA LÌ XÌ - CODE MÀN - CODE TELE",
        "customer": "💠 TRUNG TÂM KHÁCH HÀNG",
        "group": "🗣️ NHÓM CHAT ↗",
        "news": "📢 KÊNH THÔNG BÁO ↗",
        "vip-coming-soon": "👑 VIP DỊCH VỤ SẮP TRIỂN KHAI"
    }'::jsonb,
    button_styles = COALESCE(button_styles, '{}'::jsonb) || '{
        "live": "danger",
        "otp": "primary",
        "wallet": "danger",
        "vip": "primary",
        "codes": "success",
        "customer": "primary",
        "group": "primary",
        "news": "primary",
        "vip-coming-soon": "primary"
    }'::jsonb,
    updated_at = NOW();


-- Durable event stream and one-time WebSocket authentication tickets.
CREATE TABLE IF NOT EXISTS realtime_events (
    id BIGSERIAL PRIMARY KEY,
    event_type TEXT NOT NULL,
    audience TEXT NOT NULL DEFAULT 'admin' CHECK (audience IN ('admin','users','all')),
    payload JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_realtime_events_created ON realtime_events(created_at);
CREATE TABLE IF NOT EXISTS realtime_tickets (
    ticket_hash CHAR(64) PRIMARY KEY,
    admin_user_id TEXT NOT NULL,
    audience TEXT NOT NULL DEFAULT 'admin' CHECK (audience IN ('admin','users')),
    expires_at TIMESTAMPTZ NOT NULL,
    consumed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
ALTER TABLE realtime_tickets ADD COLUMN IF NOT EXISTS audience TEXT NOT NULL DEFAULT 'admin'
    CHECK (audience IN ('admin','users'));
CREATE INDEX IF NOT EXISTS idx_realtime_tickets_expiry ON realtime_tickets(expires_at);
CREATE TABLE IF NOT EXISTS module_artifacts (
    id BIGSERIAL PRIMARY KEY,
    module_key TEXT NOT NULL,
    original_name TEXT NOT NULL,
    stored_name TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    sha256 CHAR(64) NOT NULL,
    uploaded_by TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending_review' CHECK (status IN ('pending_review','approved','rejected','released')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_module_artifacts_status ON module_artifacts(status, created_at DESC);
CREATE TABLE IF NOT EXISTS store_integrations (
    provider TEXT PRIMARY KEY CHECK (provider IN ('app_store_connect','google_play','microsoft_store')),
    config_public JSONB NOT NULL DEFAULT '{}'::jsonb,
    secret_refs JSONB NOT NULL DEFAULT '{}'::jsonb,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);


-- Bank account/QR details are private runtime settings. Keep them available
-- to BotService/admin, but never include them in public bootstrap settings.
UPDATE app_settings
SET is_public = FALSE, updated_at = NOW()
WHERE key IN ('VIETQR_BANK_ID', 'VIETQR_ACCOUNT_NO', 'VIETQR_ACCOUNT_NAME');


-- HENDY operations controls for independent primary/secondary bot maintenance.
INSERT INTO app_settings (key,value,is_public) VALUES
 ('ops_primary_mode','off',FALSE),
 ('ops_primary_message','HENDY BOT đang bảo trì. Vui lòng quay lại sau.',FALSE),
 ('ops_primary_until','',FALSE),
 ('ops_secondary_mode','off',FALSE),
 ('ops_secondary_message','HENDY BOT thử nghiệm đang bảo trì. Vui lòng quay lại sau.',FALSE),
 ('ops_secondary_until','',FALSE),
 ('homeproxy_enabled','0',FALSE)
ON CONFLICT (key) DO NOTHING;

-- Per-member HomeProxy API keys are stored only as AES-256-GCM ciphertext.
-- The encryption key lives in Render Environment, never in PostgreSQL.
CREATE TABLE IF NOT EXISTS homeproxy_accounts (
 user_id TEXT PRIMARY KEY REFERENCES users(user_id) ON DELETE CASCADE,
 token_ciphertext TEXT NOT NULL,
 token_nonce TEXT NOT NULL,
 token_tag TEXT NOT NULL,
 last_action TEXT,
 last_status TEXT,
 last_action_at TIMESTAMPTZ,
 created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
 updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS homeproxy_logs (
 id BIGSERIAL PRIMARY KEY,
 user_id TEXT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
 action TEXT NOT NULL CHECK (action IN ('check','rotate')),
 status TEXT NOT NULL CHECK (status IN ('success','failed')),
 provider_message TEXT NOT NULL DEFAULT '',
 created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_homeproxy_logs_created ON homeproxy_logs(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_homeproxy_logs_user_created ON homeproxy_logs(user_id,created_at DESC);


-- HENDY CODE TELEGRAM: additive schema for allow-listed channel ingestion and OCR.
CREATE TABLE IF NOT EXISTS code_telegram_sources (
    id BIGSERIAL PRIMARY KEY,
    chat_id TEXT NOT NULL UNIQUE,
    username TEXT NOT NULL DEFAULT '',
    title TEXT NOT NULL,
    added_by TEXT NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    auto_publish BOOLEAN NOT NULL DEFAULT FALSE,
    last_update_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CHECK (char_length(chat_id) BETWEEN 3 AND 32),
    CHECK (char_length(title) BETWEEN 1 AND 160)
);
CREATE TABLE IF NOT EXISTS code_telegram_posts (
    id BIGSERIAL PRIMARY KEY,
    source_id BIGINT NOT NULL REFERENCES code_telegram_sources(id) ON DELETE CASCADE,
    telegram_message_id BIGINT NOT NULL CHECK (telegram_message_id > 0),
    update_kind TEXT NOT NULL DEFAULT 'channel_post' CHECK (update_kind IN ('channel_post','edited_channel_post')),
    message_date TIMESTAMPTZ,
    message_text TEXT NOT NULL DEFAULT '',
    source_link TEXT NOT NULL DEFAULT '',
    media_kind TEXT NOT NULL DEFAULT 'text' CHECK (media_kind IN ('text','video')),
    media_file_id TEXT NOT NULL DEFAULT '',
    media_file_size BIGINT NOT NULL DEFAULT 0 CHECK (media_file_size >= 0),
    media_mime_type TEXT NOT NULL DEFAULT '',
    extracted_text TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','processing','completed','failed','ignored')),
    attempts SMALLINT NOT NULL DEFAULT 0 CHECK (attempts BETWEEN 0 AND 10),
    extracted_count SMALLINT NOT NULL DEFAULT 0 CHECK (extracted_count BETWEEN 0 AND 200),
    error_message TEXT NOT NULL DEFAULT '',
    started_at TIMESTAMPTZ,
    processed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (source_id, telegram_message_id)
);
CREATE TABLE IF NOT EXISTS code_telegram_codes (
    id BIGSERIAL PRIMARY KEY,
    post_id BIGINT NOT NULL REFERENCES code_telegram_posts(id) ON DELETE CASCADE,
    source_id BIGINT NOT NULL REFERENCES code_telegram_sources(id) ON DELETE CASCADE,
    code_value VARCHAR(64) NOT NULL,
    normalized_code VARCHAR(64) NOT NULL,
    category TEXT NOT NULL CHECK (category IN ('promo','game','software','telegram','screen')),
    context_text VARCHAR(500) NOT NULL DEFAULT '',
    confidence NUMERIC(4,3) NOT NULL DEFAULT 0.900 CHECK (confidence BETWEEN 0 AND 1),
    status TEXT NOT NULL DEFAULT 'pending_review' CHECK (status IN ('pending_review','active','used','rejected','expired')),
    reviewed_by TEXT NOT NULL DEFAULT '',
    reviewed_at TIMESTAMPTZ,
    expires_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (post_id, normalized_code)
);
CREATE INDEX IF NOT EXISTS idx_code_telegram_posts_queue ON code_telegram_posts(status, created_at ASC);
CREATE INDEX IF NOT EXISTS idx_code_telegram_posts_source ON code_telegram_posts(source_id, message_date DESC);
CREATE INDEX IF NOT EXISTS idx_code_telegram_codes_status ON code_telegram_codes(status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_code_telegram_codes_search ON code_telegram_codes(normalized_code);
CREATE INDEX IF NOT EXISTS idx_code_telegram_codes_category ON code_telegram_codes(category, status, created_at DESC);
GRANT SELECT, INSERT, UPDATE, DELETE ON code_telegram_sources, code_telegram_posts, code_telegram_codes TO hendy_bot_app;
GRANT USAGE, SELECT ON SEQUENCE code_telegram_sources_id_seq, code_telegram_posts_id_seq, code_telegram_codes_id_seq TO hendy_bot_app;


-- CODE TELEGRAM service catalog and username intake queue.
CREATE TABLE IF NOT EXISTS code_telegram_services (
    id BIGSERIAL PRIMARY KEY,
    service_key VARCHAR(48) NOT NULL UNIQUE,
    brand VARCHAR(24) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    code_type TEXT NOT NULL CHECK (code_type IN ('video','text')),
    price_min BIGINT NOT NULL CHECK (price_min >= 0),
    price_max BIGINT NOT NULL CHECK (price_max >= price_min),
    daily_limit SMALLINT NOT NULL DEFAULT 1 CHECK (daily_limit BETWEEN 1 AND 20),
    max_accounts SMALLINT NOT NULL DEFAULT 20 CHECK (max_accounts BETWEEN 1 AND 20),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order SMALLINT NOT NULL DEFAULT 100,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
INSERT INTO code_telegram_services
    (service_key, brand, display_name, code_type, price_min, price_max, daily_limit, max_accounts, sort_order)
VALUES
    ('xx88_video', 'XX88', '🔴 XX88', 'video', 5000, 5000, 1, 20, 10),
    ('llwin_video', 'LLWIN', '🟢 LLWIN', 'video', 5000, 5000, 1, 20, 20),
    ('78win_video', '78WIN', '🟣 78WIN', 'video', 10000, 10000, 1, 20, 30),
    ('xx8_video', 'XX8', '🎁 XX8', 'video', 5000, 5000, 1, 20, 40),
    ('mb66_video', 'MB66', '🟠 MB66', 'video', 10000, 10000, 1, 20, 50),
    ('on68_video', 'ON68', '🔵 ON68', 'video', 5000, 20000, 1, 20, 60),
    ('new88_video', 'NEW88', '🟡 NEW88', 'video', 5000, 20000, 1, 20, 70),
    ('xx88_vip_text', 'XX88', '💎 Code VIP XX88', 'text', 20000, 20000, 1, 20, 80),
    ('llwin_vip_text', 'LLWIN', '💎 Code VIP LLWIN', 'text', 20000, 20000, 1, 20, 90)
ON CONFLICT (service_key) DO UPDATE SET
    brand = EXCLUDED.brand, display_name = EXCLUDED.display_name, code_type = EXCLUDED.code_type,
    price_min = EXCLUDED.price_min, price_max = EXCLUDED.price_max,
    daily_limit = EXCLUDED.daily_limit, max_accounts = EXCLUDED.max_accounts,
    sort_order = EXCLUDED.sort_order, updated_at = NOW();
CREATE TABLE IF NOT EXISTS code_telegram_requests (
    id BIGSERIAL PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    service_id BIGINT NOT NULL REFERENCES code_telegram_services(id),
    service_key_snapshot VARCHAR(48) NOT NULL,
    service_name_snapshot VARCHAR(100) NOT NULL,
    brand_snapshot VARCHAR(24) NOT NULL,
    code_type_snapshot TEXT NOT NULL CHECK (code_type_snapshot IN ('video','text')),
    price_min_snapshot BIGINT NOT NULL,
    price_max_snapshot BIGINT NOT NULL,
    account_count SMALLINT NOT NULL CHECK (account_count BETWEEN 1 AND 20),
    status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','processing','completed','partial','cancelled')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS code_telegram_request_accounts (
    id BIGSERIAL PRIMARY KEY,
    request_id BIGINT NOT NULL REFERENCES code_telegram_requests(id) ON DELETE CASCADE,
    username VARCHAR(64) NOT NULL,
    username_normalized VARCHAR(64) NOT NULL,
    status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','processing','completed','failed')),
    code_value VARCHAR(64) NOT NULL DEFAULT '',
    charged_amount BIGINT NOT NULL DEFAULT 0 CHECK (charged_amount >= 0),
    result_note VARCHAR(500) NOT NULL DEFAULT '',
    processed_by TEXT NOT NULL DEFAULT '',
    processed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (request_id, username_normalized)
);
CREATE INDEX IF NOT EXISTS idx_code_telegram_services_active ON code_telegram_services(is_active, sort_order);
CREATE INDEX IF NOT EXISTS idx_code_telegram_requests_queue ON code_telegram_requests(status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_code_telegram_requests_user ON code_telegram_requests(user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_code_telegram_request_accounts_status ON code_telegram_request_accounts(status, created_at ASC);
CREATE INDEX IF NOT EXISTS idx_code_telegram_request_accounts_daily ON code_telegram_request_accounts(username_normalized, created_at DESC);
GRANT SELECT ON code_telegram_services TO hendy_bot_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON code_telegram_requests, code_telegram_request_accounts TO hendy_bot_app;
GRANT USAGE, SELECT ON SEQUENCE code_telegram_services_id_seq, code_telegram_requests_id_seq, code_telegram_request_accounts_id_seq TO hendy_bot_app;
UPDATE bot_menu_configs
SET button_labels = COALESCE(button_labels, '{}'::jsonb) || jsonb_build_object('codes', '📡 CODE TELEGRAM')
WHERE bot_key IN ('primary','secondary');


-- AI Cover: licensed voice conversion queue
-- HENDY AI Cover: single-bot audio conversion queue.
-- Presets are visible only after explicit voice-owner permission and worker verification.
-- Source/result/model objects remain private in Neon Object Storage.

CREATE TABLE IF NOT EXISTS ai_cover_sessions (
    telegram_user_id TEXT PRIMARY KEY REFERENCES users(user_id) ON DELETE CASCADE,
    chat_id TEXT NOT NULL,
    selected_voice_id TEXT,
    terms_version VARCHAR(24) NOT NULL DEFAULT '',
    terms_accepted_at TIMESTAMPTZ,
    last_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CHECK (telegram_user_id ~ '^[0-9]{5,20}$')
);

CREATE TABLE IF NOT EXISTS ai_cover_voices (
    id VARCHAR(48) PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    description VARCHAR(300) NOT NULL DEFAULT '',
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    model_key TEXT NOT NULL UNIQUE,
    index_key TEXT UNIQUE,
    speaker_id SMALLINT NOT NULL DEFAULT 0 CHECK (speaker_id BETWEEN 0 AND 109),
    pitch SMALLINT NOT NULL DEFAULT 0 CHECK (pitch BETWEEN -12 AND 12),
    index_rate NUMERIC(4,3) NOT NULL DEFAULT 0.650 CHECK (index_rate BETWEEN 0 AND 1),
    f0_method TEXT NOT NULL DEFAULT 'rmvpe' CHECK (f0_method IN ('rmvpe','pm')),
    rights_holder VARCHAR(160) NOT NULL,
    license_name VARCHAR(160) NOT NULL,
    authorization_reference VARCHAR(200) NOT NULL,
    voice_owner_consent BOOLEAN NOT NULL DEFAULT FALSE,
    commercial_use_allowed BOOLEAN NOT NULL DEFAULT FALSE,
    consent_reviewed_by TEXT NOT NULL DEFAULT '',
    consent_reviewed_at TIMESTAMPTZ,
    available BOOLEAN NOT NULL DEFAULT FALSE,
    checked_at TIMESTAMPTZ,
    last_error VARCHAR(500) NOT NULL DEFAULT '',
    created_by TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CHECK (id ~ '^[a-z0-9][a-z0-9_-]{1,47}$'),
    CHECK (length(trim(name)) BETWEEN 1 AND 50),
    CHECK (length(trim(rights_holder)) > 0),
    CHECK (length(trim(license_name)) > 0),
    CHECK (length(trim(authorization_reference)) > 0),
    CHECK (NOT enabled OR voice_owner_consent = TRUE)
);

CREATE TABLE IF NOT EXISTS ai_cover_jobs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    telegram_user_id TEXT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    chat_id TEXT NOT NULL,
    telegram_update_id BIGINT NOT NULL UNIQUE,
    telegram_file_unique_id TEXT,
    source_key TEXT NOT NULL,
    source_filename VARCHAR(120) NOT NULL DEFAULT 'audio-upload',
    source_mime_type VARCHAR(100) NOT NULL DEFAULT 'application/octet-stream',
    voice_id VARCHAR(48) NOT NULL REFERENCES ai_cover_voices(id) ON DELETE RESTRICT,
    voice_name VARCHAR(50) NOT NULL,
    status TEXT NOT NULL DEFAULT 'queued'
        CHECK (status IN ('queued','processing','completed','failed','cancelled')),
    attempts SMALLINT NOT NULL DEFAULT 0 CHECK (attempts BETWEEN 0 AND 10),
    max_attempts SMALLINT NOT NULL DEFAULT 3 CHECK (max_attempts BETWEEN 1 AND 10),
    available_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    result_key TEXT,
    duration_seconds NUMERIC(8,2),
    output_size_bytes BIGINT CHECK (output_size_bytes IS NULL OR output_size_bytes > 0),
    last_error VARCHAR(1500),
    worker_id VARCHAR(100),
    provider_job_id VARCHAR(160) NOT NULL DEFAULT '',
    started_at TIMESTAMPTZ,
    completed_at TIMESTAMPTZ,
    delivery_sent_at TIMESTAMPTZ,
    delivery_claimed_at TIMESTAMPTZ,
    delivery_attempts SMALLINT NOT NULL DEFAULT 0 CHECK (delivery_attempts BETWEEN 0 AND 20),
    delivery_retry_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CHECK (telegram_user_id ~ '^[0-9]{5,20}$')
);

CREATE INDEX IF NOT EXISTS idx_ai_cover_jobs_queue
    ON ai_cover_jobs(status, available_at, created_at)
    WHERE status IN ('queued','processing');
CREATE INDEX IF NOT EXISTS idx_ai_cover_jobs_user_created
    ON ai_cover_jobs(telegram_user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_ai_cover_jobs_stale
    ON ai_cover_jobs(updated_at)
    WHERE status = 'processing';
CREATE INDEX IF NOT EXISTS idx_ai_cover_jobs_delivery
    ON ai_cover_jobs(status, delivery_retry_at)
    WHERE status IN ('completed','failed') AND delivery_sent_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_ai_cover_voices_available
    ON ai_cover_voices(enabled, available, checked_at DESC);

CREATE TABLE IF NOT EXISTS ai_cover_events (
    id BIGSERIAL PRIMARY KEY,
    job_id UUID NOT NULL REFERENCES ai_cover_jobs(id) ON DELETE CASCADE,
    event_type VARCHAR(40) NOT NULL,
    details JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_ai_cover_events_job_created
    ON ai_cover_events(job_id, created_at DESC);

GRANT SELECT, INSERT, UPDATE, DELETE ON ai_cover_sessions, ai_cover_voices, ai_cover_jobs, ai_cover_events TO hendy_bot_app;
GRANT USAGE, SELECT ON SEQUENCE ai_cover_events_id_seq TO hendy_bot_app;

 
-- HENDY Matchmaking lifecycle: bilateral acceptance, score confirmation and admin dispute review.
ALTER TABLE matchmaking_matches
    ADD COLUMN IF NOT EXISTS player_one_accepted_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS player_two_accepted_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS expires_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS started_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS cancelled_by TEXT,
    ADD COLUMN IF NOT EXISTS cancel_reason TEXT NOT NULL DEFAULT '';
ALTER TABLE matchmaking_results
    ADD COLUMN IF NOT EXISTS resolved_by TEXT,
    ADD COLUMN IF NOT EXISTS resolution_note TEXT NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW();
CREATE INDEX IF NOT EXISTS idx_matchmaking_matches_status_created
    ON matchmaking_matches(status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_matchmaking_matches_player_one
    ON matchmaking_matches(player_one, status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_matchmaking_matches_player_two
    ON matchmaking_matches(player_two, status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_matchmaking_queue_matched
    ON matchmaking_queue(match_id, status)
    WHERE match_id IS NOT NULL;
GRANT SELECT, INSERT, UPDATE, DELETE ON matchmaking_matches, matchmaking_queue,
    matchmaking_player_stats, matchmaking_results TO hendy_bot_app;
GRANT USAGE, SELECT ON SEQUENCE matchmaking_matches_id_seq, matchmaking_queue_id_seq,
    matchmaking_results_id_seq TO hendy_bot_app;

 
-- HENDY Provider & Service Registry. Provider metadata only; secret values stay in Render Environment.
CREATE TABLE IF NOT EXISTS service_registry (
    service_key VARCHAR(64) PRIMARY KEY,
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    maintenance_mode BOOLEAN NOT NULL DEFAULT FALSE,
    maintenance_message VARCHAR(500) NOT NULL DEFAULT '',
    updated_by TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CHECK (service_key ~ '^[a-z][a-z0-9_]{1,63}$')
);

CREATE TABLE IF NOT EXISTS service_providers (
    id BIGSERIAL PRIMARY KEY,
    service_key VARCHAR(64) NOT NULL REFERENCES service_registry(service_key) ON UPDATE CASCADE ON DELETE RESTRICT,
    provider_key VARCHAR(48) NOT NULL,
    provider_name VARCHAR(120) NOT NULL,
    adapter_key VARCHAR(40) NOT NULL DEFAULT 'manual',
    endpoint_url TEXT NOT NULL DEFAULT '',
    credential_env_key VARCHAR(64) NOT NULL DEFAULT '',
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    priority INTEGER NOT NULL DEFAULT 100 CHECK (priority BETWEEN 0 AND 10000),
    notes VARCHAR(1200) NOT NULL DEFAULT '',
    last_check_at TIMESTAMPTZ,
    last_check_status VARCHAR(40) NOT NULL DEFAULT 'not_checked'
        CHECK (last_check_status IN ('not_checked','configuration_ready','missing_environment','missing_endpoint','adapter_unavailable','disabled')),
    last_check_message VARCHAR(1000) NOT NULL DEFAULT '',
    created_by TEXT NOT NULL DEFAULT '',
    updated_by TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (service_key, provider_key),
    CHECK (provider_key ~ '^[a-z][a-z0-9_-]{1,47}$'),
    CHECK (adapter_key IN ('manual','homeproxy','ai_media','ai_cover_worker','video_worker','code_telegram','custom_api_pending')),
    CHECK (credential_env_key = '' OR credential_env_key ~ '^[A-Z][A-Z0-9_]{2,63}$'),
    CHECK (endpoint_url = '' OR endpoint_url ~* '^https://[^[:space:]]+$')
);

CREATE INDEX IF NOT EXISTS idx_service_providers_service_enabled
    ON service_providers(service_key, enabled, priority, id);
CREATE INDEX IF NOT EXISTS idx_service_registry_enabled
    ON service_registry(is_enabled, maintenance_mode);

INSERT INTO service_registry (service_key) VALUES
    ('telegram_bot'), ('website'), ('products'), ('minigame'), ('tools'), ('otp'),
    ('proxy'), ('code_telegram'), ('code_telegram_ocr'), ('wallet'), ('payments'),
    ('vip'), ('referrals'), ('support'), ('vouchers'), ('giftcodes'), ('matchmaking'),
    ('ai_music'), ('ai_image'), ('ai_cover'), ('vietsub'), ('modules')
ON CONFLICT (service_key) DO NOTHING;

GRANT SELECT, INSERT, UPDATE, DELETE ON service_registry, service_providers TO hendy_bot_app;
GRANT USAGE, SELECT ON SEQUENCE service_providers_id_seq TO hendy_bot_app;

-- Runtime access for browser login exchange and signed website sessions.
GRANT SELECT, INSERT, UPDATE, DELETE
    ON website_session_secrets, legacy_login_tickets TO hendy_bot_app;
