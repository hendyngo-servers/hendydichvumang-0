# HENDY PostgreSQL production migration runbook

This runbook is for the existing Render Telegram Bot service. It is intentionally
manual: production startup must keep `RUN_MIGRATIONS_ON_START=false`.

## What the Admin diagnostic checks

The Admin tab **Dịch vụ & sản phẩm** calls the protected, read-only
`GET /api/admin/database/readiness` endpoint. It reports the database name and
runtime role currently used by the Bot, whether the connection hostname is
pooled, and whether these feature schemas exist:

- `20261113_product_supplier_readiness.sql`: ten supplier/evidence columns on
  `public.products`.
- `20261113_provider_service_registry.sql`: `service_registry` and
  `service_providers`.

This is an inspection only. It does not create tables, apply migrations, read
or return connection strings, or reveal database passwords/API tokens.

## Safe production procedure

1. Open the Render service **hendydichvumang-telegram-bot** and create/confirm a
   Neon restore point before making schema changes.
2. In the deployed container, open Render Shell. The Docker image uses
   `/var/www/app` as its application directory:

   ```sh
   cd /var/www/app
   ```

3. Supply a separate **direct/unpooled Neon URL with database-owner/migration
   privileges** to the current Shell process as `MIGRATION_DATABASE_URL`.
   Do not overwrite `DATABASE_URL`; that variable belongs to the running Bot
   and its restricted runtime role. Do not paste a connection string into the
   Admin form, GitHub, source files, or chat. A Shell prompt can avoid putting
   the value in the command itself:

   ```sh
   read -r -s -p "Neon direct owner URL: " MIGRATION_DATABASE_URL
   printf '\n'
   export MIGRATION_DATABASE_URL
   ```

4. Run the read-only doctor and migration plan:

   ```sh
   php bin/database-doctor.php
   php bin/migrate.php
   ```

   Confirm the reported database name matches the database name shown in the
   Admin diagnostic. Confirm the hostname is direct/unpooled, the role is the
   intended owner/migration role, the base schema is ready, and there is no
   checksum mismatch. Review **every** pending migration: this runner applies
   all pending repository migrations in order, not just the two feature files.
   Stop and investigate if an unrelated migration is pending or the target
   database is unexpected.

5. After confirming the backup/restore point, target database and all pending
   migrations, apply with the exact database name printed by the doctor:

   ```sh
   php bin/migrate.php --apply --expect-database=<EXACT_DATABASE_NAME>
   ```

   The runner refuses pooled Neon connections, the restricted
   `hendy_bot_app` role, a mismatched database name and checksum drift. Each
   migration has its own transaction and is rolled back on error. The runner
   uses an advisory lock to prevent concurrent migration processes.

6. Verify the result and remove the one-off owner URL from the Shell session:

   ```sh
   php bin/database-doctor.php
   unset MIGRATION_DATABASE_URL
   ```

   The doctor should report zero pending migrations and zero checksum
   mismatches. Reload the Admin **Dịch vụ & sản phẩm** tab. Product Supplier
   Readiness must report all ten columns present before the product save button
   is enabled. Provider Registry must report both tables present before its
   write controls can work.

## Data and sales safety

The supplier-readiness migration is additive. Existing products receive
`pending` verification defaults; they are not silently authorized or made
sellable. Product checkout remains fail-closed until the source permission,
supplier access, supplier identity/evidence and stock requirements pass.
The Provider Registry migration creates its two registry tables and seeds the
22 known service keys with `ON CONFLICT DO NOTHING`.

Neither migration deletes users, balances, wallet ledger entries, transactions,
orders or payment history. Do not manually mark an existing product as
authorized unless an Admin has actually reviewed the source/ownership evidence
and supplier access. A local “configuration ready” check is not a live supplier
API test.

If any step reports a different database, a pooled URL, an unexpected role,
partial base schema, checksum mismatch or unrelated pending migrations, stop.
Do not try raw SQL as a workaround; review the migration plan first.
