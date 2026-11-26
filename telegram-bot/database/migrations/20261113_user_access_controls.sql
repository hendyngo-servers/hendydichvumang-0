-- Reversible Admin member access controls.
-- Existing accounts, balances, transactions and orders are preserved.
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_blocked BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE users ADD COLUMN IF NOT EXISTS blocked_reason TEXT NOT NULL DEFAULT '';
ALTER TABLE users ADD COLUMN IF NOT EXISTS blocked_at TIMESTAMPTZ;
ALTER TABLE users ADD COLUMN IF NOT EXISTS blocked_by TEXT NOT NULL DEFAULT '';

CREATE INDEX IF NOT EXISTS idx_users_is_blocked_created
    ON users(is_blocked, created_at DESC);
