-- Provider & Service Registry. Additive migration: no customer/order/wallet data is changed.
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
