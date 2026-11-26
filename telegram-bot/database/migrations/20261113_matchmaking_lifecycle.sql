-- HENDY Matchmaking lifecycle: bilateral acceptance, score confirmation and admin dispute review.
-- Additive migration only. Match results award non-cash points and optional Elo; no wallet is touched.

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
