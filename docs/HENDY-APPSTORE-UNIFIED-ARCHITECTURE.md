# HENDY App Store — Unified Architecture

## Canonical runtime

The App Store is part of the existing HENDY monorepo at `hendyngo-servers/hendydichvumang`. The public storefront is served from `website/public/app-store/`; the admin release manager runs inside the Telegram-authenticated Admin dashboard; the PHP API remains the single application backend; product metadata stays in the existing PostgreSQL `products.metadata` JSONB field; release binaries stay in the private Neon Object Storage namespace `store-releases/`.

Do not introduce a second public catalog, a second user/order database, or a parallel Render Node service for the same App Store records.

## Source repositories reviewed

| Source repository | Reusable ideas | Not imported unchanged |
|---|---|---|
| `ngogiaidy56-eng/app-downloader-server` | Catalog search, cards, details/changelog, platform filtering | Its backend uses unrestricted CORS, a hard-coded `admin123` password and fixed admin token, unprotected upload/update/delete routes, unbounded Multer upload, JSON-file persistence and placeholder download binaries. |
| `ngogiaidy56-eng/hendy-server-app-store` | Render server-side Admin authentication, PostgreSQL adapter patterns, SemVer update checks, readiness/smoke-test approach, encrypted certificate handling | Its Express/Pages Functions runtime, D1 compatibility handlers and PostgreSQL-as-binary-blob storage are not mixed into the current PHP + Neon architecture. Its README also records that initial Render provisioning was blocked and no live URL was available at the time described there. |
| `hendyngo-servers/HendyAppStore` | Platform-aware UX, device detection, release channels, build matrix, GitHub Actions monitor, artifact SHA-256, safe release workflow concepts, tests and validation | Cloudflare Access, Workers, D1, KV, R2, queues and Cloudflare Telegram webhook are not copied as a parallel backend. Optional AI self-healing/source rewriting and signing jobs are not enabled without a separately reviewed threat model and platform signing secrets. |

## Integrated capability in this change

The Release Studio now queries the configured GitHub repository's Actions API through the existing server-side `HENDY_GITHUB_TOKEN`. Only an allow-listed summary of the latest runs is returned to an authenticated Admin session: workflow name, run number, event, branch, shortened commit SHA, state, conclusion, timestamps and validated GitHub run URL.

The endpoint is read-only:
- `GET /api/admin/source-control/workflows?limit=10`
- Admin auth is enforced by the existing `ApiController::handle()` / `requireAdmin()` path.
- The list is capped at 20 runs and the default is 10.
- Token values and raw GitHub responses are never returned.
- Configure `HENDY_GITHUB_TOKEN` in Render with repository metadata access and GitHub Actions read permission for monitoring.
- The Admin can dispatch only the fixed `hendy-monorepo-ci.yml` workflow on the configured base branch through `POST /api/admin/source-control/workflows/dispatch`; this requires Actions write permission. No user-supplied workflow filename/ref is accepted.
- GitHub's successful HTTP 204 dispatch response is handled without expecting a JSON body. The API response means a run request was accepted by GitHub; the subsequent workflow run must still be checked in CI / Build Monitor.
- The CI dispatch runs tests only. It does not trigger a Render deploy or publish a release binary.

## Release safety invariants

1. New binaries are private Neon objects and start as drafts.
2. Public release URLs are generated through short-lived Neon signatures by the existing release API.
3. Platform, extension, MIME and basic file-signature validation remain enforced by the PHP release controller.
4. SHA-256 supports integrity verification but does not prove that a binary is malware-free or publisher-signed.
5. Publishing remains gated by Supplier Readiness.
6. No schema migration is required for the GitHub Actions monitor.
7. No user, wallet, order or payment records are modified.
8. A successful GitHub Actions run is not proof of Render deployment, production environment configuration, storage credentials, binary malware scanning, code signing, notarization or App Store publication.

## Follow-up engineering boundaries

The imported projects contain native wrapper and platform-specific build definitions, but the canonical HENDY runtime does not currently contain corresponding native application source projects for every platform. Therefore the existing Release Studio continues to accept reviewed binaries rather than pretending that an Android/iOS/Windows/macOS matrix has built real products. A signed build matrix should only be added when each actual application source, reproducible build command, signing identity, protected CI environment and artifact provenance policy are supplied and tested.
