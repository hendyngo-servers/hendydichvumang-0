# HENDY App Store storefront

This page is a production-oriented adaptation of the user-facing interface ideas in the
app-downloader-server repository and the HendyAppStore repository.

## Reused design and behaviour

- App-downloader: dark VIP storefront concept, search, category/platform filters,
  application cards, release detail modal, changelog and download actions.
- HendyAppStore: responsive Desktop/Mobile layout approach, device detection,
  platform-specific Android/Windows/macOS/iOS/Linux/Web download rules, HTTPS
  validation, screenshot gallery and update-check API documentation.

## HENDY integration

- The page calls the existing HENDY backend GET /api/public/releases.
- It displays only published releases returned by PostgreSQL/Neon.
- It uses existing product icon URLs and up to eight HTTPS screenshots stored
  in products.metadata.
- Download links are signed, time-limited URLs returned by the PHP API.
- No credentials, Admin session, tokens, source proof URLs or Neon object keys
  are embedded in the static page.
- No fake star rating, download counts, comments, license records or crash rows
  are displayed because the current HENDY backend does not provide those metrics.

## Deployment

Render Static Site publishes the website/public directory. After deployment,
the public page is available at /app-store/. The API origin is configured in
config.js and must be listed in the Bot service ALLOWED_ORIGINS value.

Static assets are data-only. The Admin remains on the Bot service and uses the
existing Telegram WebApp authentication and Admin allowlist.
