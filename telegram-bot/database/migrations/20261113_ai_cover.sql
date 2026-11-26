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


-- External inference worker id for dispatch idempotency and callback matching.
ALTER TABLE ai_cover_jobs ADD COLUMN IF NOT EXISTS provider_job_id VARCHAR(160) NOT NULL DEFAULT '';
