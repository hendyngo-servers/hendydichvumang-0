#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/hendydichvumang-0}"
DEPLOY_DIR="$APP_DIR/deploy/ubuntu"
ENV_FILE="$DEPLOY_DIR/hendy.env"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Thiếu $ENV_FILE." >&2
  exit 1
fi
if ! command -v curl >/dev/null 2>&1; then
  echo "Cần curl để kiểm tra HTTPS." >&2
  exit 1
fi

read_env() {
  local key="$1"
  awk -v wanted="$key" '
    /^[[:space:]]*#/ || /^[[:space:]]*$/ { next }
    {
      line=$0
      sub(/^[[:space:]]*export[[:space:]]+/, "", line)
      separator=index(line, "=")
      if (separator < 1 || substr(line, 1, separator-1) != wanted) next
      value=substr(line, separator+1)
      sub(/^[[:space:]]+/, "", value)
      sub(/[[:space:]]+$/, "", value)
      quote=sprintf("%c", 39)
      if (length(value)>=2 && ((substr(value,1,1)==\"" && substr(value,length(value),1)==\"\") || (substr(value,1,1)==quote && substr(value,length(value),1)==quote))) value=substr(value,2,length(value)-2)
      print value
      exit
    }
  ' "$ENV_FILE"
}

domain="$(read_env HENDY_PUBLIC_DOMAIN)"
website_url="$(read_env WEBSITE_PUBLIC_URL)"
if [[ -z "$domain" || "$website_url" != "https://$domain" ]]; then
  echo "HENDY_PUBLIC_DOMAIN/WEBSITE_PUBLIC_URL chưa khớp cùng HTTPS origin." >&2
  exit 1
fi
base="https://$domain"

cd "$DEPLOY_DIR"
docker compose --env-file hendy.env ps
echo "Kiểm tra HTTPS và public Website..."
curl --fail --silent --show-error --max-time 20 "$base/healthz" >/dev/null
curl --fail --silent --show-error --max-time 20 "$base/" | grep -q "HENDY"
curl --fail --silent --show-error --max-time 20 "$base/app-store/" | grep -q "HENDY APP STORE"
curl --fail --silent --show-error --max-time 20 "$base/miniapp/" | grep -q "<!doctype html"

echo "Kiểm tra API health..."
curl --fail --silent --show-error --max-time 20 "$base/api/health" >/dev/null

echo "Kiểm tra PostgreSQL/migration inventory (read-only)..."
docker compose --env-file hendy.env exec -T hendy-app php bin/database-doctor.php

echo "HENDY Ubuntu VPS verification passed."
