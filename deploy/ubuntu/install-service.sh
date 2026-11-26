#!/usr/bin/env bash
set -euo pipefail

if [[ "$EUID" -ne 0 ]]; then
  echo "Chạy bằng sudo: sudo bash deploy/ubuntu/install-service.sh" >&2
  exit 1
fi

APP_DIR="${APP_DIR:-/opt/hendydichvumang-0}"
if [[ ! "$APP_DIR" =~ ^/[A-Za-z0-9_./-]+$ ]]; then
  echo "APP_DIR phải là đường dẫn tuyệt đối đơn giản, không có khoảng trắng." >&2
  exit 1
fi
DEPLOY_DIR="$APP_DIR/deploy/ubuntu"
ENV_FILE="$DEPLOY_DIR/hendy.env"
UNIT_SOURCE="$DEPLOY_DIR/systemd/hendy-telegram-bot.service"

if [[ ! -d "$APP_DIR/.git" || ! -f "$DEPLOY_DIR/compose.yaml" || ! -f "$UNIT_SOURCE" ]]; then
  echo "Không tìm thấy repository HENDY hoặc deploy/ubuntu tại $APP_DIR." >&2
  exit 1
fi
if [[ ! -f "$ENV_FILE" ]]; then
  echo "Thiếu $ENV_FILE. Copy hendy.env.example thành hendy.env và cấu hình." >&2
  exit 1
fi
if [[ ! -x /usr/bin/docker ]]; then
  echo "Chưa cài Docker Engine. Chạy install-docker.sh trước." >&2
  exit 1
fi
/usr/bin/docker info >/dev/null
/usr/bin/docker compose version >/dev/null
chown root:root "$ENV_FILE"
chmod 600 "$ENV_FILE"

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
allowed_origins="$(read_env ALLOWED_ORIGINS)"
bot_token="$(read_env BOT1_TOKEN)"
admin_id="$(read_env ADMIN_ID)"
admin_ids="$(read_env ADMIN_IDS)"
database_url="$(read_env DATABASE_URL)"
webhook_auto="$(read_env WEBHOOK_AUTO_REGISTER)"
inline_workers="$(read_env HENDY_INLINE_WORKERS)"

if [[ ! "$domain" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$ || "$domain" == *..* ]]; then
  echo "HENDY_PUBLIC_DOMAIN phải là hostname DNS, không có https://, port hoặc path." >&2
  exit 1
fi
if [[ "$website_url" != "https://$domain" ]]; then
  echo "WEBSITE_PUBLIC_URL phải mặc định cùng origin https://$domain trên bản triển khai một-domain. Nếu dùng Website domain riêng, cấu hình lại Caddy và ALLOWED_ORIGINS theo hướng dẫn." >&2
  exit 1
fi
if [[ -z "$allowed_origins" ]]; then
  echo "ALLOWED_ORIGINS phải có ít nhất Website origin HTTPS." >&2
  exit 1
fi
if [[ -z "$bot_token" ]]; then echo "BOT1_TOKEN đang trống." >&2; exit 1; fi
if [[ -z "$admin_id" && -z "$admin_ids" ]]; then echo "Cần ADMIN_ID hoặc ADMIN_IDS." >&2; exit 1; fi
if [[ ! "$database_url" =~ ^postgres(ql)?:// ]]; then echo "DATABASE_URL phải là PostgreSQL URI; giá trị được giữ kín." >&2; exit 1; fi
if [[ "${webhook_auto,,}" == "true" ]]; then
  echo "Từ chối cài đặt lần đầu khi WEBHOOK_AUTO_REGISTER=true." >&2
  echo "Giữ false để kiểm tra VPS trước; chỉ bật true theo quy trình cutover sau khi Render đã ngừng tự đăng ký webhook." >&2
  exit 1
fi
if [[ "${inline_workers,,}" == "true" ]]; then
  echo "Ubuntu VPS bắt buộc HENDY_INLINE_WORKERS=false; workers phải chạy qua Compose profile riêng." >&2
  exit 1
fi

cd "$DEPLOY_DIR"
/usr/bin/docker compose --env-file hendy.env config --quiet
echo "Build image HENDY từ mã nguồn hiện tại..."
/usr/bin/docker compose --env-file hendy.env build --pull hendy-app

unit_tmp="$(mktemp)"
trap 'rm -f "$unit_tmp"' EXIT
sed "s|__HENDY_REPO_DIR__|$APP_DIR|g" "$UNIT_SOURCE" > "$unit_tmp"
install -o root -g root -m 0644 "$unit_tmp" /etc/systemd/system/hendy-telegram-bot.service
systemctl daemon-reload
systemctl enable --now hendy-telegram-bot.service

echo "Đã cài systemd service HENDY. Domain: $domain"
echo "Webhook chưa bị VPS chiếm quyền; DATABASE migrations chưa được chạy."
echo "Kiểm tra: sudo bash $APP_DIR/deploy/ubuntu/verify.sh"
