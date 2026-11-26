-- Idempotent one-time browser session storage for the Admin / Customer portal.
-- Apply explicitly to the PostgreSQL database used by the Render Bot service.
CREATE TABLE IF NOT EXISTS website_session_secrets (
    bot_key TEXT PRIMARY KEY CHECK (bot_key IN ('primary','secondary')),
    secret_value VARCHAR(256) NOT NULL CHECK (
        char_length(secret_value) BETWEEN 32 AND 256
        AND secret_value !~ '[^A-Za-z0-9_-]'
    ),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS legacy_login_tickets (
    token_hash CHAR(64) PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    bot_key TEXT NOT NULL CHECK (bot_key IN ('primary','secondary')),
    expires_at TIMESTAMPTZ NOT NULL,
    consumed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_legacy_login_expiry
    ON legacy_login_tickets(expires_at);

GRANT SELECT, INSERT, UPDATE, DELETE
    ON website_session_secrets, legacy_login_tickets TO hendy_bot_app;
