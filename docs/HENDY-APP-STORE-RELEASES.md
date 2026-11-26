# HENDY App Store — Release Manager

This feature reuses the catalog, version/channel, platform filters, release notes,
download actions and update-check concepts found in:

- ngogiaidy56-eng/app-downloader-server
- hendyngo-servers/HendyAppStore

It does not copy the original Express demo server or Cloudflare D1/R2 backend.
The HENDY PHP/Render + PostgreSQL/Neon architecture remains the source of truth.

## What was reused and what was intentionally not copied

The app-downloader repository contains a useful product idea: app catalog
metadata, version/channel, changelog, file upload, downloads and an
/api/check-update route. Its implementation is not safe for production:
the source includes a default admin123 password, a hard-coded Admin token,
unauthenticated upload/edit/delete handlers, permissive cors(), and local
JSON-file persistence. Its two sample downloads in downloads/ are only
one byte each, so they are not usable installers. Its License Manager and
Crash Reports views contain static demo rows rather than persistent services.

HENDY reimplements the useful release workflow with Admin authentication,
existing product records, private Neon Object Storage and explicit publication.
It does not introduce dummy licenses, fake download counts or fake crash reports.

## Architecture

- App metadata and release history are stored in the existing
  products.metadata JSONB field. No new SQL table or migration is required
  for this release metadata.
- Product ownership, supplier evidence and sellability continue to use
  ProductReadinessService. A release cannot be published when the product
  is inactive or its rights/supplier/fulfillment checks are not satisfied.
- Binary files are uploaded to the existing private Neon Object Storage bucket
  under the isolated prefix store-releases/{productId}/{uuid}.{extension}.
  The PHP backend stores only the object key, SHA-256 and metadata in PostgreSQL.
- The Neon Storage Function signs a short-lived upload/download URL. Its S3
  credentials never enter the browser or PostgreSQL.
- Website release cards are built with safe DOM text nodes. URLs must be HTTPS;
  the platform and extension must match; SHA-256 must be a 64-character hex value.

## Admin workflow

1. Create or edit an existing HENDY product.
2. Complete supplier name/URL, source-rights URL, permission and access checks.
3. In HENDY APP STORE · RELEASE MANAGER, choose the platform, version,
   channel and release notes, then select the binary.
4. The backend verifies Admin authentication, product existence, file size
   (16 bytes–200 MB), filename, platform extension, MIME type and basic
   container/file signature.
5. The backend calculates SHA-256 and stores the binary in private Neon
   Object Storage. The new release is always saved with published=false.
6. Review the file hash, version, platform and release notes. Save the product
   form to choose Công khai sau kiểm tra. If product readiness is later
   withdrawn or the product is disabled, the current release is unpublished.

The previous release metadata/object key is retained in
metadata.storeReleaseHistory (up to ten entries). This release-history
metadata is currently retained for Admin audit/recovery; the public page lists
the current published release only.

## Supported platform formats

| Platform | Extensions | Notes |
|---|---|---|
| Android | APK | AAB is not offered as a direct install file. |
| Windows | EXE, MSI, MSIX, ZIP | Installer/archive format must match the selected platform. |
| macOS | DMG, PKG, ZIP | DMG footer marker is checked; code signing/notarization is not performed here. |
| iOS | IPA | OTA action is shown only when a HTTPS manifest URL is supplied. The IPA must already be signed with a compatible provisioning profile. |
| Linux | AppImage, DEB, RPM, TAR.GZ | Package is uploaded as provided by the Admin. |
| Web | ZIP | The ZIP is an artifact download; HENDY does not execute/deploy it. |

The format/MIME checks are basic file-integrity guards. They do not run an
antivirus or malware scanner, validate a publisher certificate, sign binaries,
notarize Apple packages, or guarantee that an installer is safe. Admin review
and a trusted build/signing pipeline remain required.

## Public Website

The Tải ứng dụng page consumes:

- GET /api/public/releases — published releases only.
- GET /api/public/releases/check?appId={slug-or-id}&currentVersion={version}&platform={platform}
  — version comparison for an app/client.

A public release response includes product name, platform, version, channel,
filename, size, SHA-256, release notes and a short-lived signed download URL.
It never exposes the private Neon object key, storage signing key, supplier
credentials or source-rights proof URL.

The update endpoint returns hasUpdate, latestVersion, downloadUrl,
sha256, filename and release notes. A client should calculate SHA-256
after download and compare it with the API response before opening/installing
the file. Do not auto-execute installers without an independent trust check.

## Required runtime configuration and deployment

The Render Bot service already declares NEON_STORAGE_FUNCTION_URL in
render.yaml; the .env.example and env schema document
NEON_STORAGE_FUNCTION_KEY. To use release upload, verify both are present
in the production Render Environment. The Function must also have its internal
HENDY_FUNCTION_API_KEY and the Neon S3 endpoint/access/secret/region
configuration. The key values must not be copied into Git, Admin UI or chat.

The Neon Function source was changed to accept only the new
store-releases/ object-key namespace on /store-release/presign. Deploy
the updated hendystorage Neon Function before expecting release uploads to
work. A green GitHub CI run does not deploy this Neon Function or the Render
service automatically unless an explicit deployment workflow does so.

No database migration is required for release metadata; the existing
products.metadata JSONB column is used. No wallet, user, order or payment
row is touched by upload/publish operations.

## Reimplemented update/download safety

- No default password or hard-coded Admin bearer token.
- Upload route requires the existing HENDY Admin session.
- Release binary is not placed in Render's public document root.
- Private Neon URL is short-lived and only returned for a published record.
- Object key must match the product ID + UUID + allow-listed extension.
- File format/MIME/size and SHA-256 are checked before storage.
- Newly uploaded release is always a draft; publication is a separate Admin
  action that rechecks product readiness.
- Product disable/readiness withdrawal removes the release from the public list.
- Public Website renders metadata using DOM text nodes, not raw release HTML.

## Current limits / future work

- No automated GitHub Actions matrix build dispatch or release signing workflow
  has been wired into the Admin UI yet. The page consumes binary artifacts
  uploaded by Admin.
- No license-key service or crash-report ingestion was copied from the
  app-downloader demo, because the source repository's corresponding tables
  and real APIs are not implemented there.
- A future build monitor can read GitHub Actions run statuses and link to logs,
  but CI success must remain distinct from signed-artifact publication and
  Render/Neon deployment.
