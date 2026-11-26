#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/hendydichvumang-0}"
DEPLOY_DIR="$APP_DIR/deploy/ubuntu"
ENV_FILE="$DEPLOY_DIR/hendy.env"
TARGET="${1:-}"

if [[ "$EUID" -ne 0 ]]; then
  echo "Chạy bằng sudo: sudo bash deploy/ubuntu/rollback.sh <commit-sha>" >&2
  exit 1
fi
if [[ ! "$TARGET" =~ ^[0-9a-f]{40}$ ]]; then
  echo "Cần commit SHA đầy đủ 40 ký tự để rollback." >&2
  exit 1
fi
if [[ ! -d "$APP_DIR/.git" || ! -f "$ENV_FILE" ]]; then
  echo "Không tìm thấy checkout HENDY hoặc hendy.env." >&2
  exit 1
fi
if [[ -n "$(git -C "$APP_DIR" status --porcelain)" ]]; then
  echo "Từ chối rollback: working tree có thay đổi ngoài file hendy.env." >&2
  exit 1
fi
git -C "$APP_DIR" cat-file -e "$TARGET^{commit}"
cd "$DEPLOY_DIR"

previous="$(git -C "$APP_DIR" rev-parse HEAD)"
echo "Rollback from $previous to $TARGET"
git -C "$APP_DIR" reset --hard "$TARGET"
if ! docker compose --env-file hendy.env build hendy-app; then
  echo "Build rollback thất bại; phục hồi checkout về $previous." >&2
  git -C "$APP_DIR" reset --hard "$previous"
  exit 1
fi
if ! docker compose --env-file hendy.env up -d --remove-orphans --wait --wait-timeout 180; then
  echo "Rollback target không qua health check; phục hồi commit $previous." >&2
  git -C "$APP_DIR" reset --hard "$previous"
  docker compose --env-file hendy.env build hendy-app
  docker compose --env-file hendy.env up -d --remove-orphans --wait --wait-timeout 180
  exit 1
fi
docker compose --env-file hendy.env ps
echo "Rollback completed. No database migration or restore was performed."
