# HENDY PostgreSQL production migration runbook (Ubuntu VPS)

This runbook applies to the existing PostgreSQL database used by the HENDY Bot on Ubuntu VPS. It is intentionally manual: production startup must keep RUN_MIGRATIONS_ON_START=false.

## Read-only database diagnostic

The Admin tab Dịch vụ & sản phẩm calls the protected, read-only GET /api/admin/database/readiness endpoint. It reports the database name and runtime role currently used by the Bot, whether the connection hostname is pooled, and whether these feature schemas exist:

- 20261113_product_supplier_readiness.sql: supplier/evidence columns on public.products.
- 20261113_provider_service_registry.sql: service_registry and service_providers.
- 20261120_multi_agent_control_center.sql: Multi-Agent registry, queue, run and event tables.

This inspection does not create tables, apply migrations, read/return connection strings or reveal database passwords/API tokens.

Run the doctor through the Ubuntu Compose app container:

    cd /opt/hendydichvumang-0/deploy/ubuntu
    sudo docker compose --env-file hendy.env exec -T hendy-app php bin/database-doctor.php

Record database name, role, host, direct/pooled status, base-schema status and the full pending/checksum inventory. Never infer the target database from the Neon project slug or repository name.

## Safe production procedure

1. Create/confirm a Neon restore point or equivalent PostgreSQL backup before any schema change.
2. Use the exact database name reported by the doctor. Verify the runtime DATABASE_URL is connected to the database that currently contains HENDY users, wallet ledger, transactions, deposits and orders.
3. Obtain a direct/unpooled owner or dedicated migration connection for the same database. Do not use the restricted runtime role.
4. In an SSH shell, enter the migration URL without echoing it and pass it only to the one-off Compose process. Do not add it to hendy.env, Git, Admin settings or chat:

    read -r -s -p "PostgreSQL direct owner URL: " MIGRATION_DATABASE_URL
    printf '\n'
    export MIGRATION_DATABASE_URL
    cd /opt/hendydichvumang-0/deploy/ubuntu
    sudo --preserve-env=MIGRATION_DATABASE_URL docker compose --env-file hendy.env run --rm -e MIGRATION_DATABASE_URL hendy-app php bin/database-doctor.php

5. Run the dry-run migration plan:

    sudo --preserve-env=MIGRATION_DATABASE_URL docker compose --env-file hendy.env run --rm -e MIGRATION_DATABASE_URL hendy-app php bin/migrate.php

   Review every pending migration in version order. The runner may apply all pending repository migrations, not just the feature files you intended to introduce. Stop if the target DB, role, hostname, base schema or pending list is unexpected. Stop on any checksum mismatch.
6. Only after the restore point, target database and full pending list are confirmed, apply using the exact database name printed by the doctor:

    sudo --preserve-env=MIGRATION_DATABASE_URL docker compose --env-file hendy.env run --rm -e MIGRATION_DATABASE_URL hendy-app php bin/migrate.php --apply --expect-database=EXACT_DATABASE_NAME

   Replace EXACT_DATABASE_NAME with the database name exactly as shown by the doctor.
7. Run the doctor again and verify pending=0 and checksum mismatch=0. Then clear the temporary environment variable:

    unset MIGRATION_DATABASE_URL

The migration runner rejects pooled Neon URLs, the restricted hendy_bot_app role, database-name mismatches, partial base schemas and checksum drift. Each additive migration runs in its own transaction and is recorded in schema_migrations.

## Data and feature safety

The supplier-readiness migration is additive. Existing products receive pending verification defaults; they are not silently authorized or made sellable. Product checkout remains fail-closed until source permission, supplier access, supplier identity/evidence and stock requirements pass.

The Provider Registry migration creates its registry tables and seeds missing service keys with ON CONFLICT DO NOTHING.

Neither migration deletes users, balances, wallet ledger entries, transactions, orders or payment history. Do not manually authorize a product unless an Admin has reviewed the source/ownership evidence and supplier access. A local configuration check is not a live provider API test.

The Multi-Agent migration adds its own queue/agent/run/event structures. It does not charge wallets or modify customer orders. Keep MULTI_AGENT_ENABLED=false and the Compose workers profile disabled until that migration is applied and OPENAI_API_KEY is configured privately.

## Stop conditions

Do not proceed with a migration if:
- The reported database name does not match the approved target.
- The connection is pooled or the role lacks the intended owner/migration privileges.
- The base schema is empty or partial unexpectedly.
- Any migration checksum differs.
- Unrelated migrations are pending and have not been reviewed.
- A Neon restore point/backup has not been confirmed.

Never use raw SQL as a shortcut around the migration runner and never reset production schema as part of a normal VPS deployment.
