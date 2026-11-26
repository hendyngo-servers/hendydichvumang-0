# Provider & Service Registry

Provider & Service Registry is the shared Admin control plane inside the existing
browser-based dashboard at `/admin`. It does not create a second dashboard or store
provider passwords/API keys.

## What it manages

The registry includes the HENDY service inventory: Telegram Bot, Website/App Store,
product catalog and orders, Mini Game account links, Tool rental, OTP, Proxy/HomeProxy,
Code Telegram, OCR, wallet, deposits, VIP, referrals, customer support, vouchers,
giftcodes, matchmaking, AI Music, AI Image, AI Cover/RVC, Vietsub/Video and modules.

For each service, an Admin can set:
- Registry state (enabled or API-locked).
- Maintenance mode and the member-facing maintenance message.
- Provider records (provider name/key, adapter family, HTTPS endpoint, credential
  Environment variable NAME, priority order, operational notes and enabled state).
  Lower priority numbers are displayed first for operational selection; failover is
  not automatically executed by the Registry.
- Local configuration-check history.

The registry-level maintenance/lock flags are enforced for the API paths explicitly
listed in `ProviderRegistryService::SERVICES`. Bot callback flows and worker runtime
switches continue to use their existing module-specific settings (AI Studio, AI Cover,
HomeProxy, Video Worker, Code Telegram and the global Bot maintenance controls).
This is intentional: a registry entry must not silently override a specialised
worker's own safety gate.

## Provider data and secrets

The database stores provider metadata only. The `credential_env_key` column contains
a variable name such as `OTP_PROVIDER_API_KEY`, never the value. Secret values must
be set in Render Environment and must not be pasted into provider notes, endpoint URLs,
chat messages, issues or committed files.

Provider endpoints must use HTTPS, cannot contain user/password information, query
strings or fragments, and cannot be an IP/localhost URL. Query strings are rejected
because they frequently contain credentials.

The “Kiểm tra cấu hình” action is deliberately a local configuration check. It checks
whether the configured ENV name is present and whether an adapter family exists in
the code allow-list. It does **not** make an external API request, validate the API key
against a provider, prove supplier ownership, test fulfillment, or mark an integration
as production-verified. A custom API provider remains marked as not supported for
automatic execution until a provider-specific adapter, auth/signature validation,
timeouts, idempotency, error mapping, webhook verification and tests are implemented.

## Apply the database migration safely

The Bot runtime deliberately keeps `RUN_MIGRATIONS_ON_START=false`. Never give
`hendy_bot_app` DDL privileges and never run migrations from normal production
container startup.

The actual database is determined by the database name in the path part of the
Render Bot service's `DATABASE_URL`. Do not infer it from the Neon project slug,
repository name, a previous database inspection or an example in this document.

1. Open the Render Bot service Shell and run the read-only diagnostic:

   ```bash
   cd /var/www/app
   php bin/database-doctor.php
   ```

   Record the reported database name, role, host, direct/pooled status and migration
   inventory. The command never prints the connection string or credential values.
2. In Render Environment, use or obtain a one-off `MIGRATION_DATABASE_URL` that
   points to the exact same database reported by the doctor, using a direct (not
   `-pooler`) Neon URL and a database-owner/dedicated migration role. Do not put
   this URL in Git, `.env.example`, a Blueprint or a browser-facing setting.
3. Take/verify a Neon restore point. Run the dry-run first:

   ```bash
   cd /var/www/app
   php bin/migrate.php
   ```

   Verify the database name, role, host and every pending/checksum status. This
   command does not change schema or data.
4. Only after the exact target name is confirmed, apply the migration set in
   version order:

   ```bash
   php bin/migrate.php --apply --expect-database='<exact database name from doctor>'
   ```

   The runner rejects a database-name mismatch, Neon pooled hostnames, the restricted
   `hendy_bot_app` role, partial base schemas and modified checksums. Each additive
   migration runs in its own transaction and is written to `schema_migrations`.
5. Run the doctor again and verify `pending=0`, no checksum mismatches, both registry
   tables exist, 22 default service rows are present, and `hendy_bot_app` has
   SELECT/INSERT/UPDATE/DELETE on both tables plus sequence usage. Then deploy the
   Bot code and open `/admin` → **Provider & Service Registry**.

If the actual Render database name differs from a previously inspected Neon database,
stop and use the name shown by Render-connected `database-doctor.php`. Never apply
these changes to an assumed database.

The Provider Registry migration only creates new tables/indexes, seeds missing
registry keys and grants runtime DML permissions. It does not delete or rewrite users,
products, orders, transactions, wallet ledgers, deposits or support tickets. The
Product Supplier Readiness migration adds columns, constraints and an index; existing
products default to pending verification and existing order/wallet history is retained.

## Admin API

All routes below are behind the existing Telegram Admin allowlist:

- `GET /api/admin/provider-registry`
- `POST /api/admin/provider-registry/providers`
- `PATCH /api/admin/provider-registry/providers/{id}`
- `DELETE /api/admin/provider-registry/providers/{id}`
- `POST /api/admin/provider-registry/providers/{id}/check`
- `PATCH /api/admin/provider-registry/services/{serviceKey}`

Every create/update/delete/check and service-state change is written to
`admin_audit`. The snapshot returns Environment variable names and boolean presence
only; it never returns secret values.

## Activation checklist

Before opening any new service to members, confirm supplier identity and resale rights,
record HTTPS evidence, agree stock/SLA/refunds, configure secrets in Render, implement
or select a reviewed adapter, run sandbox tests, and then enable the relevant existing
module switch. For manual products, both source permission and supplier access must
also pass the product readiness gate. A green CI run or a local configuration check is
not a substitute for provider-side verification.
