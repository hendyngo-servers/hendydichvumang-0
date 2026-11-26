# HENDY DIGITAL ECOSYSTEM

Production monorepo for one Telegram Bot backend and one public Static Website.

## Ubuntu 26.04 VPS deployment target

This repository includes a Docker Compose + Caddy + systemd deployment for Ubuntu 26.04 LTS amd64. See `deploy/ubuntu/README.md`. The VPS path is prepared but is not live until the owner supplies the VPS domain/DNS and runtime secrets, verifies the existing PostgreSQL database, and performs the explicit webhook cutover. The initial VPS configuration keeps `WEBHOOK_AUTO_REGISTER=false` and `RUN_MIGRATIONS_ON_START=false`; it does not reset or migrate production data. Existing Render configuration is retained as a fallback/reference and is not changed by this source PR.

## Production architecture

- **Static Site:** Render service name `hendydichvumang`, source `website/public/`.
- **Current working Static Site URL:** `https://hendydichvumang-szad.onrender.com`.
- **Telegram Bot:** Render service `hendydichvumang-telegram-bot`, built from `telegram-bot/Dockerfile`.
- **Admin:** Browser-based Admin Dashboard is served from the authenticated Bot backend at `https://hendydichvumang-telegram-bot.onrender.com/admin`. Admin and customer UI files are stored in `website/telegram/`, outside the Static Site publish directory.
- **PostgreSQL:** The Bot connects to the database selected by the path component of its Render `DATABASE_URL`. Do not infer the database name from the Neon project slug or repository name. Run `php bin/database-doctor.php` in the Bot Shell to print the active database name, role, host and migration status without revealing credentials.

The clean hostname `https://hendydichvumang.onrender.com` currently resolves to the Bot API, not the Static Site. The Static Site therefore uses its verified Render subdomain with the `-szad` suffix until Render releases the old hostname. Do not point Website links or canonical metadata at the Bot API hostname.

## Website

Everything under `website/public/` is public static content: HTML, CSS, JavaScript, SVG, robots, sitemap and legal pages. It does not connect to PostgreSQL, process payments, or contain Bot credentials.

Public tabs: Trang chủ, App Store, Dịch vụ mạng, AI Studio, Code Telegram, Giới thiệu. The public navigation intentionally does not expose "Trung tâm khách hàng".

## Telegram Bot

- Exactly one production Bot is supported; only `BOT1_TOKEN` is read.
- The webhook bootstrap calls Telegram `getMe()`, registers and verifies the webhook at the Bot service's own Render URL, then stores the public Bot username in `app_settings.primary_bot_url`.
- `BOT1_PUBLIC_URL` is an optional override; referral links otherwise use the auto-detected Bot URL.
- One-time login tickets are short-lived, stored hashed, consumed atomically, and exchanged for a signed HttpOnly website-session cookie.
- Admin access requires the user's Telegram numeric ID to be in `ADMIN_ID` or `ADMIN_IDS`.

## Admin Control Center

The Admin Dashboard is a browser-first control plane protected by the Telegram Admin allowlist. It includes:
- Member accounts and linked accounts, manual wallet adjustments with audit entries, orders, deposit approvals, customer tickets and vouchers.
- Product/catalog editing, Giftcode, Bot menu labels/descriptions/styles, shared configuration and community links.
- Bot operations: normal mode, maintenance, upgrade window, scheduled end time and operator message. Admin accounts remain able to enter during maintenance.
- HomeProxy enable/disable, encrypted member key management, video/Code Telegram job operations, module review, AI Studio controls and AI Cover licensed-preset management.
- Audit log for administrative changes.

Do not use a manual wallet adjustment as a substitute for payment reconciliation. Deposit requests must be matched to bank evidence before approval.

## AI Features

The Telegram main menu groups creative tools under **🤖 TÍNH NĂNG AI**:
- **AI Cover:** licensed RVC voice presets, owner consent and license reference, private Neon Object Storage, verified worker and HMAC callback. At least one enabled preset must pass a recent worker check before member access can open.
- **Tạo âm nhạc AI:** prompt → PostgreSQL job queue → ElevenLabs Music worker → send audio back to the same Telegram user.
- **Chỉnh sửa ảnh AI:** prompt + private Telegram photo → PostgreSQL job queue → OpenAI image edit → send edited image to the user.

AI provider keys are secrets and must only be configured in Render Environment. The Admin Dashboard never displays these values. AI_MEDIA_QUEUE_ENABLED=false, AI_COVER_ENABLED=false and AI_COVER_WORKER_ENABLED=false are fail-closed defaults. After provider credentials and worker are configured, an Admin must enable AI Studio globally and then enable each tool separately. AI Cover additionally requires a working RVC worker, callback HMAC secret, Neon Object Storage and a verified licensed preset.

AI tools currently have a per-user daily job limit and one active job per user. Automatic AI wallet billing is not enabled; users are not charged for these tasks. Do not advertise paid AI packages until an explicit price/ledger flow has been implemented and tested.

The /ai command opens the AI submenu. The /cancel command clears the current prompt/photo input. Image and audio workflows are restricted to private Bot chats.

## Database reset and recovery

On 2026-11-13, records were cleared from the `public` schema of both `hendydichvumang` and `hendydichvumang_website`; tables and constraints were retained. Neon Auth database `neondb` was not changed.

Recovery branch retained in Neon:
`backup-before-full-reset-2026-11-13`.

The Bot base schema remains in `telegram-bot/database/schema.sql`; additive changes are versioned under `telegram-bot/database/migrations/`. Runtime uses `RUN_MIGRATIONS_ON_START=false`; the Bot runtime role performs DML only, while a separate direct database-owner/migration URL is used for reviewed schema changes.

## Product and provider activation

The Admin product form records supplier identity, supplier product reference, source/rights evidence, verification states and the Environment variable NAME only. Public product lists and both checkout paths fail closed until the source permission is authorized, supplier access is verified, evidence URLs are HTTPS, stock is available and manual fulfillment is selected. API fulfillment stays locked until a dedicated tested adapter exists.

Before deploying this readiness change to an existing Neon database, follow [the safe migration and provider onboarding checklist](docs/product-provider-onboarding.md) using the database owner/migration role. Production intentionally keeps `RUN_MIGRATIONS_ON_START=false`; CI success alone does not apply a production migration.

## Provider & Service Registry

The existing browser Admin at `/admin` now includes a Provider & Service Registry
for the service inventory, supplier records, adapter family, Environment variable
names, API-level service locks and maintenance messages. Provider credentials remain
in Render Environment; the dashboard never reads or returns their values. The
configuration check is local only and does not claim a live provider API test.

Before deploying the registry change, apply
`telegram-bot/database/migrations/20261113_provider_service_registry.sql` to Neon
using the database owner/migration role. Production keeps
`RUN_MIGRATIONS_ON_START=false`. See
[Provider & Service Registry rollout](docs/provider-service-registry.md) for the
migration, controls and provider activation checklist.

## Render environment

Set secrets only in Render Environment. Never commit live credentials.

Required Bot values:
- `BOT1_TOKEN`
- `ADMIN_ID` or `ADMIN_IDS`
- `DATABASE_URL` pointing to the explicitly selected PostgreSQL database for this Bot service (verify with `php bin/database-doctor.php`)
- `DATABASE_SSL=true`

Set `WEBSITE_PUBLIC_URL=https://hendydichvumang-szad.onrender.com`. Render injects `RENDER_EXTERNAL_URL` for the Bot; webhook registration uses that service-owned URL. Optional bank, AI, OCR and WebSocket integrations stay disabled until provider credentials and migrations are verified.

## Database diagnostics and migrations

Use the Render Bot service Shell to identify the database actually referenced by its environment:

```bash
cd /var/www/app
php bin/database-doctor.php
```

The doctor is read-only. It prints only the database name, role, host, connection-pool status and migration inventory; it never prints connection strings or secret values.

Before a migration, make a Neon restore point and obtain the direct/unpooled owner URL for the confirmed database. Set it only in the one-off Shell process as `MIGRATION_DATABASE_URL`, then run the dry-run:

```bash
export MIGRATION_DATABASE_URL='[direct owner URL supplied privately by the operator]'
php bin/migrate.php
```

Review the printed database name, role, host and pending migrations. Only after the target is confirmed, apply using its exact database name:

```bash
php bin/migrate.php --apply --expect-database='[confirmed database name]'
```

The migration runner rejects pooled Neon URLs, the restricted `hendy_bot_app` role, database-name mismatches, partial base schemas and migration checksum drift. Each additive migration runs in its own transaction and is recorded in `schema_migrations`. The command defaults to dry-run and does not print the URL.


## Ubuntu 26.04 VPS deployment (alternative)

The VPS option reuses the same PHP Bot/API, PostgreSQL data model, member wallet,
orders, Provider Registry and Supplier Readiness already present in this monorepo.
It does not create a second bot backend or a separate wallet database. See
[Ubuntu 26.04 deployment guide](deploy/ubuntu/README.md).

The VPS is an alternative to the current Render Bot service. Keep
`WEBHOOK_AUTO_REGISTER=false` during initial validation and follow the documented
single-webhook cutover before switching the production Telegram bot token.
Production schema migrations remain manual and must follow the existing database
owner runbooks; ordinary startup keeps `RUN_MIGRATIONS_ON_START=false`.

## Local development

1. Copy `telegram-bot/.env.example` to `telegram-bot/.env`.
2. Populate credentials locally; never commit `.env`.
3. Run `composer install` in `telegram-bot/`.
4. Run `composer validate-config`, `composer lint` and `composer test`.

## Multi-Agent Control Center

The authenticated Admin Dashboard now includes a PostgreSQL-backed Agent Registry, sequential Hub workflows, queue/run/event history, worker heartbeat and a human approval queue. Workflows include Research → Content, Research → Sales, Research → Content → Video, Support-only and a selected custom Agent. Results remain drafts and require Admin approval; the module does not publish, send Telegram messages, charge the wallet or modify customer records.

The worker is disabled by default. Apply the additive migration telegram-bot/database/migrations/20261120_multi_agent_control_center.sql with the existing owner-role migration runbook first. Then configure OPENAI_API_KEY and explicitly set MULTI_AGENT_ENABLED=true in the Bot Render Environment. See docs/MULTI-AGENT-CONTROL-CENTER.md. Keep RUN_MIGRATIONS_ON_START=false.
