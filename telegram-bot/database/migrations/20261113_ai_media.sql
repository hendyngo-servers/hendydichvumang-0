-- HENDY AI media queue: image editing and music generation.
-- Additive only; safe to run more than once. Worker remains disabled until
-- a provider key and AI_MEDIA_QUEUE_ENABLED are configured in Render.

CREATE TABLE IF NOT EXISTS ai_media_jobs (
    id UUID PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    bot_key TEXT NOT NULL DEFAULT 'primary' CHECK (bot_key = 'primary'),
    job_type TEXT NOT NULL CHECK (job_type IN ('music','image')),
    prompt TEXT NOT NULL,
    source_file_id TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'queued'
        CHECK (status IN ('queued','processing','completed','failed')),
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
