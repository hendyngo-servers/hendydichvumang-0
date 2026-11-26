#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/hendydichvumang-0}"
DEPLOY_DIR="$APP_DIR/deploy/ubuntu"
ENV_FILE="$DEPLOY_DIR/hendy.env"

if [[ "$EUID" -ne 0 ]]; then
  echo "Chạy bằng sudo: sudo bash deploy/ubuntu/update.sh" >&2
  exit 1
fi
if [[ ! -d "$APP_DIR/.git" || ! -f "$ENV_FILE" ]]; then
  echo "Không tìm thấy checkout HENDY hoặc hendy.env tại $APP_DIR." >&2
  exit 1
fi
if [[ ! -f /etc/systemd/system/hendy-telegram-bot.service ]]; then
  echo "Chưa cài systemd service HENDY." >&2
  exit 1
fi

if [[ -n "$(git -C "$APP_DIR" status --porcelain)" ]]; then
  echo "Từ chối cập nhật: working tree có thay đổi ngoài file hendy.env. Hãy commit hoặc cất thay đổi trước." >&2
  exit 1
fi

cd "$DEPLOY_DIR"
docker compose --env-file hendy.env config --quiet
previous="$(git -C "$APP_DIR" rev-parse HEAD)"
echo "Current HENDY commit: $previous"

git -C "$APP_DIR" fetch --prune origin main
git -C "$APP_DIR" merge --ff-only origin/main

if ! docker compose --env-file hendy.env build --pull hendy-app; then
  echo "Build mới thất bại; đưa Git checkout về commit trước." >&2
  git -C "$APP_DIR" reset --hard "$previous"
  exit 1
fi

if ! docker compose --env-file hendy.env up -d --remove-orphans --wait --wait-timeout 180; then
  echo "Ứng dụng mới không qua health check; khôi phục code/image trước đó." >&2
  git -C "$APP_DIR" reset --hard "$previous"
  docker compose --env-file hendy.env build hendy-app
  docker compose --env-file hendy.env up -d --remove-orphans --wait --wait-timeout 180
  exit 1
fi

docker compose --env-file hendy.env ps
echo "Update hoàn tất. Production database migrations không được chạy."
echo "Kiểm tra HTTPS và PostgreSQL: sudo bash $DEPLOY_DIR/verify.sh"
