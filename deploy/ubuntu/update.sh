#!/usr/bin/env bash
set -euo pipefail
APP_DIR="${APP_DIR:-/opt/hendydichvumang}"
DEPLOY_DIR="$APP_DIR/deploy/ubuntu"
if [[ "$EUID" -ne 0 ]]; then echo "Chạy bằng sudo: sudo bash deploy/ubuntu/update.sh" >&2; exit 1; fi
if [[ ! -d "$APP_DIR/.git" || ! -f "$DEPLOY_DIR/hendy.env" ]]; then echo "Không tìm thấy checkout HENDY hoặc hendy.env tại $APP_DIR." >&2; exit 1; fi
if [[ ! -f /etc/systemd/system/hendy-telegram-bot.service ]]; then echo "Chưa cài systemd service." >&2; exit 1; fi
git -C "$APP_DIR" fetch origin
git -C "$APP_DIR" pull --ff-only origin main
cd "$DEPLOY_DIR"
docker compose --env-file hendy.env build --pull hendy-app
docker compose --env-file hendy.env up -d --remove-orphans
docker compose --env-file hendy.env ps
