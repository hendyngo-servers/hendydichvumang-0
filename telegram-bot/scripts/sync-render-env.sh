#!/usr/bin/env bash
set -euo pipefail
set +x

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SCHEMA="$ROOT/config/env.schema.json"
MODE="${1:---dry-run}"

case "$MODE" in
  --dry-run|--apply) ;;
  *) echo "Dùng: scripts/sync-render-env.sh [--dry-run|--apply]" >&2; exit 2 ;;
esac

for command_name in php jq curl openssl; do
  command -v "$command_name" >/dev/null 2>&1 || {
    echo "Thiếu công cụ bắt buộc: $command_name" >&2
    exit 2
  }
done

# Defaults được định nghĩa tại env.schema.json; chỉ dùng cho biến thường.
while IFS=$'\t' read -r key default_value; do
  [[ -n "$key" && -n "$default_value" ]] || continue
  if [[ -z "${!key:-}" ]]; then
    printf -v "$key" '%s' "$default_value"
    export "$key"
  fi
done < <(jq -r '.variables | to_entries[] | select(.value.classification == "variable" and (.value | has("default"))) | [.key, .value.default] | @tsv' "$SCHEMA")

if [[ -z "${BOT1_TOKEN:-}" ]]; then
  echo "Thiếu cấu hình bắt buộc: BOT1_TOKEN" >&2
  exit 1
fi
if [[ -z "${ADMIN_ID:-}" && -z "${ADMIN_IDS:-}" ]]; then
  echo "Cần ít nhất một admin ID trong ADMIN_ID hoặc ADMIN_IDS." >&2
  exit 1
fi
if [[ -n "${ADMIN_ID:-}" && ! "$ADMIN_ID" =~ ^[0-9]{5,20}$ ]]; then
  echo "ADMIN_ID phải chỉ gồm 5–20 chữ số." >&2
  exit 1
fi
if [[ -n "${ADMIN_IDS:-}" ]]; then
  IFS=',' read -ra admin_list <<< "$ADMIN_IDS"
  for admin_id in "${admin_list[@]}"; do
    admin_id="$(echo "$admin_id" | xargs)"
    if [[ ! "$admin_id" =~ ^[0-9]{5,20}$ ]]; then
      echo "ADMIN_IDS phải gồm các Telegram user ID dạng số, phân tách bằng dấu phẩy." >&2
      exit 1
    fi
  done
fi
if [[ ! "$BOT1_TOKEN" =~ ^[0-9]{5,12}:[A-Za-z0-9_-]{30,}$ ]]; then
  echo "BOT1_TOKEN không đúng định dạng Telegram Bot Token." >&2
  exit 1
fi
if [[ -n "${BOT2_TOKEN:-}" ]]; then
  if [[ ! "$BOT2_TOKEN" =~ ^[0-9]{5,12}:[A-Za-z0-9_-]{30,}$ ]]; then
    echo "BOT2_TOKEN không đúng định dạng Telegram Bot Token." >&2
    exit 1
  fi
  if [[ "$BOT1_TOKEN" == "$BOT2_TOKEN" ]]; then
    echo "BOT1_TOKEN và BOT2_TOKEN phải là hai token khác nhau." >&2
    exit 1
  fi
fi

if [[ -z "${APP_URL:-}" ]]; then
  APP_URL="${RENDER_EXTERNAL_URL:-}"
  export APP_URL
fi

if [[ "${WEBHOOK_AUTO_REGISTER:-true}" == "true" ]]; then
  if [[ -z "${APP_URL:-}" ]]; then
    echo "APP_URL hoặc Render URL bắt buộc khi WEBHOOK_AUTO_REGISTER=true." >&2
    exit 1
  fi
  if ! php -r '$p=parse_url((string)getenv("APP_URL")); exit(is_array($p) && strtolower((string)($p["scheme"] ?? "")) === "https" && !empty($p["host"]) ? 0 : 1);'; then
    echo "APP_URL phải là URL HTTPS hợp lệ." >&2
    exit 1
  fi
fi

# Truyền khóa vừa sinh sang các step sau của cùng GitHub Actions job.
# File GITHUB_ENV không được in vào log và chỉ áp dụng cho step kế tiếp trở đi.
persist_for_next_step() {
  local name="$1"
  if [[ -n "${GITHUB_ENV:-}" ]]; then
    printf '%s=%s\n' "$name" "${!name}" >> "$GITHUB_ENV"
  fi
}

# Sinh khóa ngẫu nhiên ở runtime; không in giá trị ra log.
generate_secret() {
  local name="$1"
  local value="${!name:-}"
  if [[ -z "$value" ]]; then
    value="$(openssl rand -hex 32)"
    printf -v "$name" '%s' "$value"
    export "$name"
    echo "::add-mask::$value"
    persist_for_next_step "$name"
    GENERATED_SECRETS+=("$name")
  fi
}
GENERATED_SECRETS=()
generate_secret TELEGRAM_WEBHOOK_SECRET
if [[ -n "${BOT2_TOKEN:-}" ]]; then
  generate_secret TELEGRAM_SECOND_WEBHOOK_SECRET
  if [[ "${TELEGRAM_SECOND_WEBHOOK_SECRET:-}" == "${TELEGRAM_WEBHOOK_SECRET:-}" ]]; then
    TELEGRAM_SECOND_WEBHOOK_SECRET="$(openssl rand -hex 32)"
    export TELEGRAM_SECOND_WEBHOOK_SECRET
    echo "::add-mask::$TELEGRAM_SECOND_WEBHOOK_SECRET"
    persist_for_next_step TELEGRAM_SECOND_WEBHOOK_SECRET
    GENERATED_SECRETS+=("TELEGRAM_SECOND_WEBHOOK_SECRET")
    echo "TELEGRAM_SECOND_WEBHOOK_SECRET trùng secret bot chính; đã tạo khóa riêng mới."
  fi
fi
# BANK_WEBHOOK_SECRET is deliberately NOT generated or synchronized until a
# provider-specific signed callback adapter and sandbox tests exist.
generate_secret LEGACY_SESSION_SECRET

# DATABASE_URL do Render PostgreSQL/Blueprint quản lý; chuỗi này chỉ để
# kiểm tra cú pháp trong workflow nếu chưa đưa DB URL vào GitHub Environment.
if [[ -z "${DATABASE_URL:-}" ]]; then
  export DATABASE_URL='postgresql://schema-check:schema-check@127.0.0.1:5432/schema-check'
  echo "DATABASE_URL: giữ nguyên nguồn Render PostgreSQL/Blueprint (không ghi đè)."
fi

if [[ "${DATABASE_SSL:-false}" != "true" && "${DATABASE_SSL:-false}" != "false" ]]; then
  echo "DATABASE_SSL chỉ nhận true hoặc false." >&2
  exit 1
fi

echo "Kiểm tra cấu hình tĩnh:"
php "$ROOT/scripts/audit-env.php" --check
php "$ROOT/bin/validate-config.php"

echo "Phân loại biến theo manifest:"
jq -r '.variables | to_entries[] | select(.value.sync_to_render == true) | " - " + .key + " [" + (.value.classification | ascii_upcase) + "]"' "$SCHEMA"
for name in "${GENERATED_SECRETS[@]}"; do
  echo " - $name [SECRET — sẽ tạo khóa ngẫu nhiên khi áp dụng]"
done

if [[ "$MODE" == "--dry-run" ]]; then
  echo "DRY-RUN: không ghi GitHub Environment, không sửa Render, không deploy."
  echo "Để áp dụng, chạy workflow thủ công với apply_changes=true."
  exit 0
fi

: "${GH_TOKEN:?Cần GH_CONFIG_SYNC_TOKEN (fine-grained token Environments: write)}"
: "${GITHUB_REPOSITORY:?Thiếu GITHUB_REPOSITORY}"
: "${TARGET_ENVIRONMENT:?Thiếu TARGET_ENVIRONMENT}"
: "${RENDER_API_KEY:?Cần RENDER_API_KEY}"
: "${RENDER_SERVICE_ID:?Cần RENDER_SERVICE_ID}"

if ! command -v gh >/dev/null 2>&1; then
  echo "Thiếu GitHub CLI (gh)." >&2
  exit 2
fi

# Lưu các khóa nội bộ mới vào GitHub Environment để giữ nguyên giữa các lần deploy.
for name in "${GENERATED_SECRETS[@]}"; do
  value="${!name}"
  printf '%s' "$value" | gh secret set "$name" --env "$TARGET_ENVIRONMENT"
  echo "Đã lưu khóa nội bộ $name trong GitHub Environment $TARGET_ENVIRONMENT."
done

sync_render_key() {
  local key="$1"
  local classification="$2"
  local value="$3"

  if [[ -z "$value" ]]; then
    local delete_on_empty
    delete_on_empty="$(jq -r --arg key "$key" '.variables[$key].delete_on_empty // false' "$SCHEMA")"
    if [[ "$delete_on_empty" == "true" ]]; then
      local http_code
      http_code="$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' \
        --request DELETE \
        --url "https://api.render.com/v1/services/$RENDER_SERVICE_ID/env-vars/$key" \
        --header "Authorization: Bearer $RENDER_API_KEY" \
        --header "Accept: application/json")"
      case "$http_code" in
        204|404) echo "Đã tắt $key trên Render (biến đã xóa hoặc không tồn tại)." ;;
        *) echo "Không thể xóa $key khỏi Render (HTTP $http_code)." >&2; return 1 ;;
      esac
    fi
    return 0
  fi
  if [[ "$classification" == "secret" ]]; then
    echo "::add-mask::$value"
  fi

  # Endpoint per-key: không dùng PUT /env-vars (replace-all) vì endpoint đó
  # có thể xóa các biến không có trong request.
  printf '%s' "$value" | jq -Rs '{value: .}' | curl --fail-with-body --silent --show-error \
    --request PUT \
    --url "https://api.render.com/v1/services/$RENDER_SERVICE_ID/env-vars/$key" \
    --header "Authorization: Bearer $RENDER_API_KEY" \
    --header "Accept: application/json" \
    --header "Content-Type: application/json" \
    --data-binary @- >/dev/null

  if [[ "$classification" == "secret" ]]; then
    printf '%s' "$value" | gh secret set "$key" --env "$TARGET_ENVIRONMENT"
  else
    printf '%s' "$value" | gh variable set "$key" --env "$TARGET_ENVIRONMENT"
  fi
  echo "Đã đồng bộ $key [$classification] → GitHub Environment + Render."
}

while IFS=$'\t' read -r key classification; do
  [[ -n "$key" ]] || continue
  value="${!key:-}"
  sync_render_key "$key" "$classification" "$value"
done < <(jq -r '.variables | to_entries[] | select(.value.sync_to_render == true) | [.key, .value.classification] | @tsv' "$SCHEMA")

echo "Đồng bộ cấu hình hoàn tất. Chưa deploy; hãy bật deploy_after_sync sau khi kiểm tra log."
