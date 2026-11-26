# HENDY DIGITAL ECOSYSTEM

Production monorepo for one Telegram Bot backend and one public Website.

## Primary deployment: Ubuntu 26.04 LTS VPS

The production deployment target is Ubuntu 26.04 LTS (64-bit AMD64) using Docker Compose, Caddy HTTPS and systemd. The one-domain VPS setup serves the public Website, App Store, Mini App, Admin and API from the same HTTPS origin.

- Website public assets: website/public/
- PHP 8.3 Telegram Bot and API: telegram-bot/
- Authenticated Admin UI: /admin
- Customer portal and Telegram Mini App: /customer, /miniapp
- Reverse proxy and automatic TLS: deploy/ubuntu/Caddyfile
- Optional dedicated queue workers: Compose profile workers
- Persistent local files/certificates: named Docker volumes; database remains PostgreSQL.

See the complete [Ubuntu 26.04 VPS deployment guide](deploy/ubuntu/README.md).

**Deployment status:** source code and CI checks are prepared for Ubuntu VPS. A real VPS is not considered deployed until the owner supplies DNS/domain and private runtime settings, verifies the exact PostgreSQL database, runs the installation, checks HTTPS/API/database health and explicitly transfers the Telegram webhook. This repository change does not connect to a VPS, change Render, apply production migrations or touch production data.

## Production architecture and data safety

- Exactly one production Telegram Bot is supported: BOT1_TOKEN.
- The PHP Bot/API is the only backend for member accounts, wallets, wallet ledger, deposits, orders, vouchers, tickets, provider records and supplier readiness.
- PostgreSQL is the single source of truth. On the VPS setup, configure DATABASE_URL to the existing HENDY PostgreSQL database (for example the current Neon database) when preserving user balances and order history. The Compose stack does not create a parallel wallet database or run a local Postgres by default.
- Runtime DB role is DML-only. Production startup keeps RUN_MIGRATIONS_ON_START=false; database migrations require a reviewed, one-off direct/unpooled owner connection and exact database confirmation.
- Caddy routes the same HTTPS hostname to the PHP application. When Website and API share that hostname, the backend serves bundled public Website files locally; it must not redirect the homepage back to itself.
- Existing render.yaml and Render-specific environment variables remain for legacy/fallback reference. Ubuntu VPS is the deployment target documented here.

## Website and member surfaces

Everything under website/public/ is public static content: HTML, CSS, JavaScript, SVG, favicon, robots, sitemap and legal pages. It does not connect directly to PostgreSQL and never receives Bot/database/provider secrets.

Public pages include Trang chủ, App Store, Dịch vụ mạng, AI Studio, Code Telegram, privacy and terms. The public navigation intentionally does not expose the Admin dashboard.

Member/Admin routes are served by the authenticated PHP backend:
- /: public Website homepage.
- /app-store/: public App Store catalogue.
- /miniapp/ and /customer: member Mini App / customer centre.
- /admin: Admin Dashboard.
- /privacy/ and /terms/: legal pages.
- /api/v1: API discovery.
- /healthz: web health check; /api/health: application/API health check.

The Website runtime configuration contains public origin information only. It must never contain a Bot token, database URL, session secret or provider credential.

## Telegram Bot and Admin

The Bot uses PHP 8.3, Telegram webhook handling and PostgreSQL. It includes member profiles, wallet and ledger, deposits, orders, vouchers, VIP, referral, tickets, Code Telegram, Proxy/HomeProxy, Mini Game/Tool modules and existing Admin controls.

Admin access is checked server-side against ADMIN_ID / ADMIN_IDS. The Admin Dashboard includes member/order/deposit management, catalog and product configuration, provider registry, supplier readiness, Bot operations/maintenance, tickets, voucher/giftcode, release/module review and audit log.

Do not use a manual wallet adjustment as a substitute for payment reconciliation. Bank callbacks remain disabled until a provider-specific signature adapter has been implemented and verified.

## Provider Registry and Supplier Readiness

The Admin Provider & Service Registry stores provider metadata and **Environment variable names**, not secret values. Supplier Readiness blocks public catalogue/checkout access until the source permission, supplier access, HTTPS evidence, stock and approved fulfillment mode pass the checks.

A local configuration check only confirms the variable name/presence and registered adapter family. It is not a live provider API test and does not prove a supplier account, resale right or fulfillment. Generic API adapters are not considered production integrations until their authentication/signature, timeouts, idempotency, error handling, webhook verification and tests exist.

See [Provider & Service Registry](docs/provider-service-registry.md) and [product/provider onboarding](docs/product-provider-onboarding.md).

## Multi-Agent Control Center

Multi-Agent remains integrated into the existing PHP/Admin/PostgreSQL application; it is not a second Node.js service or a separate data store.

It includes the Agent Registry, sequential workflows, PostgreSQL queue/run/event history, heartbeat/stale-task recovery and an Admin approval queue. Outputs remain drafts until Admin review. The module does not publish content, message customers, charge wallets, approve deposits or mutate customer orders.

The dedicated worker is opt-in. Apply telegram-bot/database/migrations/20261120_multi_agent_control_center.sql using the migration runbook, configure OPENAI_API_KEY privately, set MULTI_AGENT_ENABLED=true and activate the Compose workers profile. Do not enable it before the migration and provider credentials are verified.

See [Multi-Agent rollout](docs/MULTI-AGENT-CONTROL-CENTER.md).

## Background workers

The Ubuntu Compose web container does not run queue workers inline. The optional hendy-worker service runs as the unprivileged www-data user under the workers profile. Workers remain disabled by default and are activated only after their respective migration, provider credentials, callback and Admin module flags are verified.

Supported optional worker processes include Multi-Agent, AI media, video dispatch, Code Telegram OCR and AI Cover dispatch. WebSocket, bank callback and automatic provider fulfillment remain disabled unless a dedicated, reviewed deployment path is configured.

## Secrets and environment

For Ubuntu VPS, copy deploy/ubuntu/hendy.env.example to deploy/ubuntu/hendy.env, set file permissions to 600 and keep the file out of Git. Do not paste live credentials into source files, the Admin source editor, GitHub issues or chat.

Required:
- HENDY_PUBLIC_DOMAIN: public Bot/Website hostname without scheme/path.
- WEBSITE_PUBLIC_URL: same-origin HTTPS URL, for example https://bot.example.com.
- ALLOWED_ORIGINS: comma-separated allowed Website origins; same-origin is sufficient for a one-domain deployment.
- BOT1_TOKEN and ADMIN_ID or ADMIN_IDS.
- DATABASE_URL pointing to the exact existing HENDY PostgreSQL database.
- DATABASE_SSL=true for Neon/external TLS PostgreSQL.

Provider/API tokens such as OPENAI_API_KEY, ELEVENLABS_API_KEY, NEON_STORAGE_FUNCTION_KEY, VIDEO_PROCESSOR_SECRET, AI_COVER_CALLBACK_SECRET and HENDY_GITHUB_TOKEN belong only in the private VPS environment file and remain disabled until their modules are reviewed.

## Database migrations

The Bot base schema is telegram-bot/database/schema.sql; additive changes live under telegram-bot/database/migrations/. Never run schema migrations automatically during container startup.

Before each production migration:
1. Create/confirm a Neon restore point or equivalent PostgreSQL backup.
2. Run php bin/database-doctor.php inside the VPS container and record exact database, role, host and pending migration inventory.
3. Supply MIGRATION_DATABASE_URL only to a one-off command/container using a direct/unpooled owner/migration connection.
4. Run the migration dry-run first. Apply only after verifying the exact database name and every pending migration.
5. Run the read-only doctor again and verify the expected schema. Do not reset the database or rewrite users, wallet ledger, orders or payment history as part of a deployment.

Detailed steps: [Production PostgreSQL migration runbook](docs/PRODUCTION-MIGRATION-RUNBOOK.md).

## Legacy Render reference

render.yaml is retained for the previously configured Render deployment and rollback/reference purposes. It is not the primary Ubuntu deployment path. Do not leave Render and the VPS both auto-registering the same Telegram webhook. Perform the documented one-at-a-time webhook cutover only after the VPS passes HTTPS, database, API and member-flow verification.

## Local development and tests

Requirements: PHP 8.3+, Composer, PostgreSQL, PHP extensions curl, mbstring, pdo_pgsql; Node.js 24+ for Website/Neon tests.

- Copy telegram-bot/.env.example to telegram-bot/.env.
- Populate local credentials; never commit .env.
- Run composer install in telegram-bot/.
- Run composer validate, composer lint, composer test and php scripts/secret-scan.php.

GitHub Actions validates Website assets/config, PHP Bot lint/tests and secret scan, Neon Function build/tests, Ubuntu shell scripts and Docker Compose configuration.
