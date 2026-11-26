# Neon setup — HENDY Bot

This repository contains two independent applications:

- Website at repository root: keep its existing MySQL connection and uploads unchanged.
- Telegram Bot under `telegram-bot/`: use Neon PostgreSQL database `hendydichvumang`.

## Production Neon resources

- Project: `calm-forest-79251169`
- Branch: `production`
- Region: `aws-ap-southeast-1`
- Database: `hendydichvumang`
- Private object bucket: `uploads`
- Neon Function slug: `hendystorage`
- Realtime: enabled on the production branch

## Local tooling

Install Node 20+ and the project dependencies:

```bash
npm install
npm run build
npm test
```

PostgreSQL is native to every Neon project. The existing bucket and Function are declared in `neon.ts`; Realtime is enabled with `preview.realtime`:

```bash
npx neon@latest init -y --org-id org-silent-waterfall-43882566 --project-id calm-forest-79251169 --branch production --services object-storage,functions
npx neon@latest config plan
npx neon@latest deploy --env .env.local
```

Set `HENDY_FUNCTION_API_KEY` in the ignored local `.env.local` before deploying the Function. Never commit `.env.local`, `.neon`, S3 credentials, or the internal Function key.

## PostgreSQL migration and runtime role

The application schema is maintained in `telegram-bot/database/schema.sql`. Apply it as a reviewed Neon migration using an owner/migration role. The Render runtime uses the restricted `hendy_bot_app` role and `RUN_MIGRATIONS_ON_START=false`; do not grant the web service an owner role just to run DDL on each restart.

Set the Bot Render service:

- `DATABASE_URL`: Neon connection string for database `hendydichvumang`, role `hendy_bot_app`.
- `DATABASE_SSL=true`
- `RUN_MIGRATIONS_ON_START=false`
- `NEON_STORAGE_FUNCTION_URL`: deployed `hendystorage` invocation URL.
- `NEON_STORAGE_FUNCTION_KEY`: same 256-bit key deployed to the Function.

The website's MySQL environment is independent and must not be changed.

## Private file uploads

Admin module uploads go through the Neon Function. The Function authenticates the PHP service with a constant-time bearer-key check and issues short-lived SigV4 URLs (maximum 15 minutes) for the private `uploads` bucket. PHP uploads over HTTPS and stores only the object key and SHA-256 metadata in PostgreSQL. An admin-only endpoint returns short-lived download URLs.

The Function's S3 credentials remain in its Neon Function environment and are never sent to the browser or stored in GitHub.

## Realtime

Neon Realtime is enabled on the Neon branch. The Bot already has a durable PostgreSQL `LISTEN/NOTIFY` + WebSocket channel. Keep it as the current event transport until a reviewed client-side Neon Realtime SDK integration replaces it; do not run competing event publishers.
