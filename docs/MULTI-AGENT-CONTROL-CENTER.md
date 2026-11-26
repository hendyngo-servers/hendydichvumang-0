# HENDY Multi-Agent Control Center

## Integrated functionality

This module extends the existing PHP 8.3 + PostgreSQL Telegram Bot backend and authenticated Admin Dashboard. It is not a separate app.

- PostgreSQL Agent Registry: create custom Agents, edit instructions/model override and enable/disable each Agent.
- Sequential Hub workflows: Research; Research → Content; Research → Sales; Research → Content → Video; Support-only; or one selected custom Agent.
- PostgreSQL queue with row locking, per-step execution history, token counts, event records, worker heartbeat and stale-task recovery.
- Final outputs stop at **Chờ duyệt**. Admin approval records a decision only; it does not publish, send Telegram messages, charge a wallet, approve payments or modify customer data.
- The worker uses the existing OPENAI_API_KEY secret from Render Environment. Provider keys are not stored in PostgreSQL or sent to the browser.

## Current scope and limits

Research analyzes only the text/context provided by Admin; it does not browse the web or cite external sources. Video produces scripts/storyboards, not rendered video. Sales prepares scripts, not customer outreach or transactions. Support drafts replies, but does not send them. These boundaries prevent the dashboard from presenting unimplemented actions as completed work.

## Safe rollout

1. Review and merge the feature PR after GitHub CI passes.
2. Create or confirm a Neon restore point for the exact PostgreSQL database used by the Bot service.
3. Open the Bot service Render Shell. Use the direct/unpooled owner connection as MIGRATION_DATABASE_URL. Run php bin/database-doctor.php and php bin/migrate.php first; verify the active database name and review the pending migration list.
4. Apply only after confirming the intended database:
   php bin/migrate.php --apply --expect-database=<EXACT_DATABASE_NAME>
5. Run php bin/database-doctor.php again and verify the five multi_agent_* tables are present.
6. In the Bot Render Environment, configure OPENAI_API_KEY as a secret and set:
   - MULTI_AGENT_ENABLED=true
   - MULTI_AGENT_WORKER_POLL_SECONDS=5
   - MULTI_AGENT_OPENAI_MODEL to a model enabled for the API project (default: gpt-6.2-astra)
   - optionally MULTI_AGENT_MAX_ATTEMPTS=3 and MULTI_AGENT_STALE_MINUTES=15
7. Redeploy the Bot service. In Admin → Phòng điều hành Multi-Agent, verify schema readiness, API-key configuration and Worker online before creating tasks.

Keep RUN_MIGRATIONS_ON_START=false. The migration is additive and must be applied manually with the owner role. Never paste a database URL or API key into GitHub, the Admin editor or chat.
