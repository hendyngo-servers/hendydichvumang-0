-- Safe product/provider/source readiness. Additive only; existing products are
-- deliberately moved to pending verification and are not publicly sellable.
-- No wallet, order, payment, account or historical product rows are deleted.

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

DO $$
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
            CHECK (credential_env_key = '' OR credential_env_key ~ '^[A-Z][A-Z0-9_]{2,63}$');
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_products_provider_readiness
    ON products(category, is_active, source_permission_status, supplier_access_status, fulfillment_mode);

COMMENT ON COLUMN products.credential_env_key IS
    'Environment variable NAME only. Never store provider credentials or API tokens in PostgreSQL.';
COMMENT ON COLUMN products.source_url IS
    'HTTPS record for owned inventory, supplier contract, license, or permission proof; Admin attestation required.';
COMMENT ON COLUMN products.fulfillment_mode IS
    'API fulfillment remains unsellable until a reviewed provider adapter is implemented.';
