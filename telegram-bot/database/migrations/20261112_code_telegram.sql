-- HENDY CODE TELEGRAM: allow-listed Telegram channel code ingestion.
-- Additive only. Existing wallet, order, user and product records are untouched.

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
    update_kind TEXT NOT NULL DEFAULT 'channel_post'
        CHECK (update_kind IN ('channel_post','edited_channel_post')),
    message_date TIMESTAMPTZ,
    message_text TEXT NOT NULL DEFAULT '',
    source_link TEXT NOT NULL DEFAULT '',
    media_kind TEXT NOT NULL DEFAULT 'text' CHECK (media_kind IN ('text','video')),
    media_file_id TEXT NOT NULL DEFAULT '',
    media_file_size BIGINT NOT NULL DEFAULT 0 CHECK (media_file_size >= 0),
    media_mime_type TEXT NOT NULL DEFAULT '',
    extracted_text TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'queued'
        CHECK (status IN ('queued','processing','completed','failed','ignored')),
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
    status TEXT NOT NULL DEFAULT 'pending_review'
        CHECK (status IN ('pending_review','active','used','rejected','expired')),
    reviewed_by TEXT NOT NULL DEFAULT '',
    reviewed_at TIMESTAMPTZ,
    expires_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (post_id, normalized_code)
);

CREATE INDEX IF NOT EXISTS idx_code_telegram_posts_queue
    ON code_telegram_posts(status, created_at ASC);
CREATE INDEX IF NOT EXISTS idx_code_telegram_posts_source
    ON code_telegram_posts(source_id, message_date DESC);
CREATE INDEX IF NOT EXISTS idx_code_telegram_codes_status
    ON code_telegram_codes(status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_code_telegram_codes_search
    ON code_telegram_codes(normalized_code);
CREATE INDEX IF NOT EXISTS idx_code_telegram_codes_category
    ON code_telegram_codes(category, status, created_at DESC);

GRANT SELECT, INSERT, UPDATE, DELETE ON code_telegram_sources, code_telegram_posts, code_telegram_codes TO hendy_bot_app;
GRANT USAGE, SELECT ON SEQUENCE code_telegram_sources_id_seq, code_telegram_posts_id_seq, code_telegram_codes_id_seq TO hendy_bot_app;
