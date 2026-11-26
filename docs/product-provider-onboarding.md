# Product, source and supplier readiness

A product record is not enough to activate a service. Customer-facing catalogs and
checkout paths require all of the following:

- is_active = true and stock is available.
- source_permission_status = authorized.
- supplier_access_status = verified.
- Supplier name, supplier HTTPS URL and source / permission HTTPS URL are present.
- fulfillment_mode = manual. The generic API mode is deliberately blocked
  until a provider-specific adapter, authentication, timeout, idempotency and
  webhook verification have been implemented and tested.

## Admin product form

The product form records supplier name, supplier page, supplier SKU/reference,
source/contract/ownership evidence URL, permission status, access status,
fulfillment mode and an optional Environment variable name. Never paste API
keys, passwords, session cookies, OTP seeds or other secret values into this form
or the database. Configure secret values only in Render Environment, with the
name matching the stored credentialEnvKey after a provider adapter exists.

New and existing products default to pending verification after migration.
Existing orders and wallet history are not changed. An Admin must review evidence
and explicitly set both permission and supplier access statuses before a product
can be listed or purchased.

## Provider onboarding checklist

1. Confirm the supplier identity and the right to resell / distribute the item.
2. Record the supplier product reference and HTTPS source evidence.
3. Verify that the HENDY account has access to the exact supplier service.
4. Confirm actual stock, delivery SLA, refund/incident handling and pricing.
5. For automation, implement a dedicated allow-listed adapter in code, keep
   credentials in Render Environment, validate signatures, set timeouts and
   idempotency, and pass tests before enabling API fulfillment.
6. Test with a non-cash/non-production sandbox or an explicitly designated test
   item before activating customer sales.

OTP, AI, video, Proxy and Code Telegram providers may have their own distinct
access controls. A supplier URL alone does not prove ownership, authorization or
working API access. Keep each feature locked until its own readiness checks pass.


## Apply the database change safely

The additive columns and constraints are included in `telegram-bot/database/schema.sql`
and tracked in `telegram-bot/database/migrations/20261113_product_supplier_readiness.sql`.
Production keeps `RUN_MIGRATIONS_ON_START=false`; the Bot runtime role must not receive
DDL privileges just to make this feature work.

The target database is the database name in the path portion of the Render Bot service's
`DATABASE_URL`. Do not assume it is named after the Neon project or repository. First
identify the actual target from the running Bot service Shell:

```bash
cd /var/www/app
php bin/database-doctor.php
```

This read-only command prints the current database name, role, hostname, direct/pooled
status and pending migration inventory, without exposing any username, password or
connection string. Confirm the reported database name before continuing.

Before applying a migration, take/verify a Neon restore point. Use a one-off
`MIGRATION_DATABASE_URL` that targets that exact database using a direct (non-pooled)
Neon endpoint and a database-owner/dedicated migration role. Keep it in the Render
Shell session only; never put the owner URL in the repository or browser UI.

Run the dry-run first:

```bash
php bin/migrate.php
```

Check the target database, role and pending migrations. The dry-run does not modify
schema or data. Only after confirming the exact target name, run:

```bash
php bin/migrate.php --apply --expect-database='<exact database name from doctor>'
```

The versioned runner rejects database-name mismatches, pooled Neon URLs, the restricted
`hendy_bot_app` runtime role, partial base schemas and migration checksum drift. It
applies each additive migration in its own transaction and records its checksum in
`schema_migrations`. The Bot does not run DDL during its ordinary Render startup.

After applying, run `php bin/database-doctor.php` again and verify there are no pending
migrations or checksum mismatches. Verify the ten Supplier Readiness columns and four
CHECK constraints exist, and that `hendy_bot_app` has SELECT/UPDATE permissions on
`products`. Existing product rows are retained and default to pending verification;
existing orders, wallet ledgers and user records are not deleted or rewritten.

After the schema is present, use the Admin product form to record the supplier and
evidence, then review both permission statuses. A green CI run does not prove that a
production database migration or external provider account has been applied.

## Vietsub worker

The Telegram Bot can accept a valid video link and create a queued job without starting a processor.
To actually dispatch it, configure `VIDEO_PROCESSOR_URL` (HTTPS), `VIDEO_PROCESSOR_SECRET` (at least
32 random characters), and set `VIDEO_WORKER_ENABLED=true` in Render Environment. Set
`APP_URL` or allow Render's `RENDER_EXTERNAL_URL` to provide the HTTPS callback origin. The worker
fails closed when any requirement is absent. Never paste the processor secret into the Admin UI.
