#!/usr/bin/env bash
set -euo pipefail
APP_DIR="${APP_DIR:-/opt/hendydichvumang}"
DEPLOY_DIR="$APP_DIR/deploy/ubuntu"
ENV_FILE="$DEPLOY_DIR/hendy.env"
if [[ ! -f "$ENV_FILE" ]]; then echo "Thiếu $ENV_FILE." >&2; exit 1; fi
domain="$(awk -F= '/^[[:space:]]*HENDY_PUBLIC_DOMAIN[[:space:]]*=/ {sub(/^[^=]*=/, ""); gsub(/^[[:space:]]+|[[:space:]]+$/, ""); gsub(/^[\"\047]|[\"\047]$/, ""); print; exit}' "$ENV_FILE")"
if [[ -z "$domain" ]]; then echo "Chưa cấu hình HENDY_PUBLIC_DOMAIN." >&2; exit 1; fi
if ! command -v curl >/dev/null 2>&1; then echo "Cần curl để kiểm tra HTTPS." >&2; exit 1; fi
base="https://$domain"
echo "Kiểm tra HTTPS health endpoint..."
curl --fail --silent --show-error --max-time 20 "$base/healthz" >/dev/null
curl --fail --silent --show-error --max-time 20 "$base/api/health"
echo
echo "Kiểm tra PostgreSQL và migration inventory (read-only)..."
cd "$DEPLOY_DIR"
docker compose --env-file hendy.env exec -T hendy-app php bin/database-doctor.php
