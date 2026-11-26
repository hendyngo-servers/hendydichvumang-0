# HENDY Multi-Agent Control Center

## Integrated functionality

This module extends the existing PHP 8.3 + PostgreSQL Telegram Bot backend and authenticated Admin Dashboard. It is not a separate app, Node.js bot or database.

- PostgreSQL Agent Registry: create custom Agents, edit instructions/model override and enable/disable each Agent.
- Sequential Hub workflows: Research; Research → Content; Research → Sales; Research → Content → Video; Support-only; or one selected custom Agent.
- PostgreSQL queue with row locking, per-step execution history, token counts, event records, worker heartbeat and stale-task recovery.
- Final outputs stop at Chờ duyệt. Admin approval records a decision only; it does not publish, send Telegram messages, charge a wallet, approve payments or modify customer data.

## Scope and limits

Research analyzes only the text/context provided by Admin; it does not browse the web or cite external sources. Video produces scripts/storyboards, not rendered video. Sales prepares scripts, not customer outreach or transactions. Support drafts replies, but does not send them.

## Safe Ubuntu VPS rollout

1. Review and merge the feature code only after GitHub CI passes.
2. Confirm a Neon restore point or equivalent backup for the exact PostgreSQL database used by the Bot.
3. In an SSH shell, run the read-only doctor through the current app container:

       cd /opt/hendydichvumang-0/deploy/ubuntu
       sudo docker compose --env-file hendy.env exec -T hendy-app php bin/database-doctor.php

4. Supply a one-off direct/unpooled owner connection as MIGRATION_DATABASE_URL. Do not store it in hendy.env or Git:

       read -r -s -p "PostgreSQL direct owner URL: " MIGRATION_DATABASE_URL
       printf '\n'
       export MIGRATION_DATABASE_URL
       sudo --preserve-env=MIGRATION_DATABASE_URL docker compose --env-file hendy.env run --rm -e MIGRATION_DATABASE_URL hendy-app php bin/migrate.php

5. Review all pending migrations and exact database/role/host. Only after approval apply with the exact database name:

       sudo --preserve-env=MIGRATION_DATABASE_URL docker compose --env-file hendy.env run --rm -e MIGRATION_DATABASE_URL hendy-app php bin/migrate.php --apply --expect-database=EXACT_DATABASE_NAME

6. Run database-doctor.php again, verify the five Multi-Agent tables and zero pending migrations, then unset MIGRATION_DATABASE_URL.
7. In the private Ubuntu environment file, configure OPENAI_API_KEY and set:
   - MULTI_AGENT_ENABLED=true
   - MULTI_AGENT_WORKER_POLL_SECONDS=5
   - MULTI_AGENT_OPENAI_MODEL to a model enabled for the API project
   - COMPOSE_PROFILES=workers
8. Restart HENDY and review the dedicated worker:

       sudo systemctl restart hendy-telegram-bot
       cd /opt/hendydichvumang-0/deploy/ubuntu
       sudo docker compose --env-file hendy.env logs -f hendy-worker

9. In Admin → Phòng điều hành Multi-Agent, verify schema readiness, API-key presence and worker heartbeat before creating tasks.

Keep RUN_MIGRATIONS_ON_START=false. The worker runs separately as unprivileged www-data. Do not enable MULTI_AGENT_ENABLED or COMPOSE_PROFILES=workers before the migration and provider credentials are ready. Never paste a database URL or API key into GitHub, the Admin editor or chat.
