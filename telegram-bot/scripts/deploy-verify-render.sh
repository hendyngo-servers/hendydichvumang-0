#!/usr/bin/env bash
set -euo pipefail
set +x

: "${RENDER_API_KEY:?Thiếu RENDER_API_KEY}"
: "${RENDER_SERVICE_ID:?Thiếu RENDER_SERVICE_ID}"
: "${APP_URL:?Thiếu APP_URL (URL HTTPS của Render Bot service)}"
: "${BOT1_TOKEN:?Thiếu BOT1_TOKEN}"
: "${GITHUB_SHA:?Thiếu GITHUB_SHA}"

base_url="${APP_URL%/}"
api_base="https://api.render.com/v1/services/$RENDER_SERVICE_ID"
api_headers=(-H "Authorization: Bearer $RENDER_API_KEY" -H "Accept: application/json")

# Environment updates through the API are saved but must be consumed by a
# subsequent deploy. Do not reuse an older live deploy for the same commit.
# Wait for any auto-deploy of this commit to finish first, then create a fresh
# deploy so the new runtime configuration is definitely applied.
echo "Kiểm tra deploy đang chạy của commit $GITHUB_SHA ..."
deploy_list="$(curl --fail-with-body --silent --show-error \
  --url "$api_base/deploys?limit=20" "${api_headers[@]}")"
existing_id="$(jq -r --arg sha "$GITHUB_SHA" '
  [.[] | (.deploy // .) | select(.commit.id == $sha) |
    select(.status == "created" or .status == "queued" or .status == "build_in_progress" or .status == "update_in_progress" or .status == "pre_deploy_in_progress)] |
  last | .id // empty
' <<<"$deploy_list")"

if [[ -n "$existing_id" ]]; then
  echo "Render đang triển khai commit này; chờ deploy hiện tại kết thúc trước khi áp dụng cấu hình vừa đồng bộ."
  existing_status=""
  for attempt in $(seq 1 120); do
    existing_response="$(curl --fail-with-body --silent --show-error \
      --url "$api_base/deploys/$existing_id" "${api_headers[@]}")"
    existing_status="$(jq -r '.status // .deploy.status // "unknown"' <<<"$existing_response")"
    case "$existing_status" in
      created|queued|build_in_progress|update_in_progress|pre_deploy_in_progress) sleep 10 ;;
      *) break ;;
    esac
  done
  case "$existing_status" in
    created|queued|build_in_progress|update_in_progress|pre_deploy_in_progress)
      echo "Deploy đang chạy không kết thúc trong thời hạn chờ." >&2
      exit 1
      ;;
  esac
fi

echo "Yêu cầu một deploy mới để áp dụng environment vừa đồng bộ cho commit $GITHUB_SHA ..."
deploy_body="$(jq -n --arg commitId "$GITHUB_SHA" '{commitId: $commitId, clearCache: "do_not_clear"}')"
deploy_response="$(curl --fail-with-body --silent --show-error \
  --request POST --url "$api_base/deploys" "${api_headers[@]}" \
  -H "Content-Type: application/json" --data "$deploy_body")"
deploy_id="$(jq -r '.deploy.id // .id // empty' <<<"$deploy_response")"

if [[ -z "$deploy_id" ]]; then
  echo "Render không trả về Deploy ID cho deploy mới." >&2
  exit 1
fi

echo "Đang theo dõi trạng thái deploy (ID được ẩn trong log vận hành)."
status=""
for attempt in $(seq 1 120); do
  response="$(curl --fail-with-body --silent --show-error \
    --url "$api_base/deploys/$deploy_id" "${api_headers[@]}")"
  status="$(jq -r '.status // .deploy.status // "unknown"' <<<"$response")"
  case "$status" in
    live) break ;;
    build_failed|update_failed|pre_deploy_failed|canceled|deactivated)
      echo "Render deploy thất bại với trạng thái: $status" >&2
      exit 1
      ;;
  esac
  sleep 10
done
if [[ "$status" != "live" ]]; then
  echo "Hết thời gian chờ Render deploy; trạng thái cuối: $status" >&2
  exit 1
fi

echo "Deploy đã live. Kiểm tra health endpoint..."
health_ok=false
for attempt in $(seq 1 12); do
  if health="$(curl --fail --silent --show-error --max-time 10 "$base_url/api/health" 2>/dev/null)" &&
     jq -e '.status == "ok"' >/dev/null 2>&1 <<<"$health"; then
    health_ok=true
    break
  fi
  sleep 5
done
if [[ "$health_ok" != true ]]; then
  echo "Health check không xác nhận status=ok. Kiểm tra database và log Render." >&2
  exit 1
fi

telegram_call() {
  local token="$1"
  local method="$2"
  curl --fail --silent --show-error --max-time 20 \
    --config <(printf 'url = "https://api.telegram.org/bot%s/%s"\n' "$token" "$method")
}

verify_bot() {
  local label="$1"
  local token="$2"
  local expected_url="$3"

  local info actual_url
  info="$(telegram_call "$token" getWebhookInfo)"
  if [[ "$(jq -r '.ok // false' <<<"$info")" != true ]]; then
    echo "$label: Telegram từ chối token hoặc không thể đọc webhook info. Kiểm tra credential trong Render Environment." >&2
    return 1
  fi
  actual_url="$(jq -r '.result.url // ""' <<<"$info")"
  if [[ "$(jq -r '.ok // false' <<<"$info")" != true || "$actual_url" != "$expected_url" ]]; then
    echo "$label: webhook chưa trỏ đúng endpoint dự kiến." >&2
    return 1
  fi
  echo "$label: token hợp lệ và Telegram webhook đã trỏ đúng endpoint."
}

if [[ "${WEBHOOK_AUTO_REGISTER:-true}" != "true" ]]; then
  echo "WEBHOOK_AUTO_REGISTER=false; không thể xác minh webhook production." >&2
  exit 1
fi

verify_bot "Bot chính" "$BOT1_TOKEN" "$base_url/webhook/telegram"
if [[ -n "${BOT2_TOKEN:-}" ]]; then
  verify_bot "Bot phụ" "$BOT2_TOKEN" "$base_url/webhook/telegram/secondary"
fi

echo "Hoàn tất: Render live, PostgreSQL health OK, Telegram token + webhook đã xác minh."
