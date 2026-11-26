-- CODE TELEGRAM service catalog and username intake queue.
-- Prices are stored in PostgreSQL; no wallet is debited until an Admin marks
-- an individual account as successfully processed with a verified code.

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
    brand = EXCLUDED.brand,
    display_name = EXCLUDED.display_name,
    code_type = EXCLUDED.code_type,
    price_min = EXCLUDED.price_min,
    price_max = EXCLUDED.price_max,
    daily_limit = EXCLUDED.daily_limit,
    max_accounts = EXCLUDED.max_accounts,
    sort_order = EXCLUDED.sort_order,
    updated_at = NOW();

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
    status TEXT NOT NULL DEFAULT 'queued'
        CHECK (status IN ('queued','processing','completed','partial','cancelled')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS code_telegram_request_accounts (
    id BIGSERIAL PRIMARY KEY,
    request_id BIGINT NOT NULL REFERENCES code_telegram_requests(id) ON DELETE CASCADE,
    username VARCHAR(64) NOT NULL,
    username_normalized VARCHAR(64) NOT NULL,
    status TEXT NOT NULL DEFAULT 'queued'
        CHECK (status IN ('queued','processing','completed','failed')),
    code_value VARCHAR(64) NOT NULL DEFAULT '',
    charged_amount BIGINT NOT NULL DEFAULT 0 CHECK (charged_amount >= 0),
    result_note VARCHAR(500) NOT NULL DEFAULT '',
    processed_by TEXT NOT NULL DEFAULT '',
    processed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (request_id, username_normalized)
);

CREATE INDEX IF NOT EXISTS idx_code_telegram_services_active
    ON code_telegram_services(is_active, sort_order);
CREATE INDEX IF NOT EXISTS idx_code_telegram_requests_queue
    ON code_telegram_requests(status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_code_telegram_requests_user
    ON code_telegram_requests(user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_code_telegram_request_accounts_status
    ON code_telegram_request_accounts(status, created_at ASC);
CREATE INDEX IF NOT EXISTS idx_code_telegram_request_accounts_daily
    ON code_telegram_request_accounts(username_normalized, created_at DESC);

GRANT SELECT ON code_telegram_services TO hendy_bot_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON code_telegram_requests, code_telegram_request_accounts TO hendy_bot_app;
GRANT USAGE, SELECT ON SEQUENCE code_telegram_services_id_seq, code_telegram_requests_id_seq, code_telegram_request_accounts_id_seq TO hendy_bot_app;

-- Keep all stored menu configurations on the canonical display label.
UPDATE bot_menu_configs
SET button_labels = COALESCE(button_labels, '{}'::jsonb) || jsonb_build_object('codes', '📡 CODE TELEGRAM')
WHERE bot_key IN ('primary','secondary');
