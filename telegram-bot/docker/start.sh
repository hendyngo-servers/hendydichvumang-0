#!/usr/bin/env bash
set -euo pipefail

cd /var/www/app

# Caddy terminates TLS on Ubuntu VPS; Apache serves the private app/API network port.
port="${PORT:-10000}"
if ! [[ "$port" =~ ^[0-9]{1,5}$ ]] || (( port < 1 || port > 65535 )); then
    echo "PORT phải là số nguyên từ 1 đến 65535." >&2
    exit 1
fi

sed -i -E "s/^Listen [0-9]+$/Listen ${port}/" /etc/apache2/ports.conf
sed -i "s/:10000>/:${port}>/" /etc/apache2/sites-available/000-default.conf

mkdir -p storage/modules
chown -R www-data:www-data storage
chmod 750 storage storage/modules

php bin/validate-config.php
php bin/wait-for-database.php
if [[ "${RUN_MIGRATIONS_ON_START:-false}" == "true" ]]; then
    # Production never runs DDL migrations during normal service startup.
    # Use a one-off Render Shell with a direct Neon owner/migration URL instead.
    case "${APP_ENV:-production}" in
        local|development|test)
            ;;
        *)
            echo "TỪ CHỐI: Chỉ cho phép tự động migration trong môi trường local/development/test." >&2
            echo "Dùng php bin/database-doctor.php để xem database đích; sau đó chạy php bin/migrate.php --apply --expect-database=<tên_db_đã_xác_nhận> trong Render Shell với MIGRATION_DATABASE_URL direct/owner." >&2
            exit 1
            ;;
    esac
    expected_database="${MIGRATION_EXPECTED_DATABASE:-}"
    if [[ -z "$expected_database" ]]; then
        echo "RUN_MIGRATIONS_ON_START=true cần MIGRATION_EXPECTED_DATABASE; không tự đoán database đích." >&2
        exit 1
    fi
    php bin/migrate.php --apply --expect-database="$expected_database"
else
    echo "RUN_MIGRATIONS_ON_START=false; bỏ qua migration lúc khởi động."
fi

# Register only the production bot. The script persists/reuses the webhook secret,
# sets the HTTPS endpoint and verifies Telegram's getWebhookInfo response.
if [[ "${WEBHOOK_AUTO_REGISTER:-true}" == "true" ]]; then
    webhook_registered=false
    for attempt in 1 2 3 4 5; do
        if php bin/register-webhook.php; then
            webhook_registered=true
            break
        fi
        if (( attempt < 5 )); then
            delay=$((attempt * 3))
            echo "Telegram chưa phản hồi ở lần thử $attempt/5; thử lại sau ${delay}s."
            sleep "$delay"
        fi
    done
    if [[ "$webhook_registered" != "true" ]]; then
        # The service's existing webhook points to this stable Render origin.
        # A transient Telegram API/network outage must not crash the web service.
        echo "CẢNH BÁO: Chưa xác minh lại được webhook sau 5 lần thử; giữ dịch vụ hoạt động và để lần khởi động tiếp theo thử lại." >&2
    fi
else
    echo "WEBHOOK_AUTO_REGISTER=false; bỏ qua đăng ký webhook tự động."
fi

# Optional inline worker mode preserves the legacy single-container runtime.
# Ubuntu Compose sets HENDY_INLINE_WORKERS=false and runs a separate worker container.
background_pids=()
if [[ "${HENDY_INLINE_WORKERS:-true}" == "true" ]]; then
if [[ "${AI_MEDIA_QUEUE_ENABLED:-false}" == "true" ]]; then
    if [[ -z "${OPENAI_API_KEY:-}" && -z "${ELEVENLABS_API_KEY:-}" ]]; then
        echo "AI_MEDIA_QUEUE_ENABLED=true nhưng chưa có OPENAI_API_KEY hoặc ELEVENLABS_API_KEY." >&2
        exit 1
    fi
    php bin/ai-worker.php &
    background_pids+=("$!")
    echo "HENDY AI media worker đã khởi chạy."
fi

if [[ "${MULTI_AGENT_ENABLED:-false}" == "true" ]]; then
    # A misconfigured optional Agent worker must not prevent the Bot/API from starting.
    if php bin/multi-agent-preflight.php; then
        php bin/multi-agent-worker.php &
        background_pids+=("$!")
        echo "HENDY Multi-Agent worker đã khởi chạy."
    else
        echo "HENDY Multi-Agent worker chưa khởi chạy; Bot/API vẫn tiếp tục. Kiểm tra migration và OPENAI_API_KEY." >&2
    fi
fi

if [[ "${VIDEO_WORKER_ENABLED:-false}" == "true" ]]; then
    if [[ -z "${VIDEO_PROCESSOR_URL:-}" || -z "${VIDEO_PROCESSOR_SECRET:-}" ]]; then
        echo "VIDEO_WORKER_ENABLED=true nhưng VIDEO_PROCESSOR_URL hoặc VIDEO_PROCESSOR_SECRET chưa cấu hình." >&2
        exit 1
    fi
    if [[ -z "${APP_URL:-}" && -z "${RENDER_EXTERNAL_URL:-}" ]]; then
        echo "VIDEO_WORKER_ENABLED=true nhưng APP_URL/RENDER_EXTERNAL_URL HTTPS chưa sẵn sàng." >&2
        exit 1
    fi
    php bin/video-worker.php &
    background_pids+=("$!")
    echo "HENDY Video Worker đã khởi chạy."
fi

if [[ "${CODE_TELEGRAM_WORKER_ENABLED:-false}" == "true" ]]; then
    if [[ "${CODE_TELEGRAM_ENABLED:-false}" != "true" ]]; then
        echo "CODE_TELEGRAM_WORKER_ENABLED=true nhưng CODE_TELEGRAM_ENABLED=false." >&2
        exit 1
    fi
    php bin/code-telegram-worker.php &
    background_pids+=("$!")
    echo "HENDY Code Telegram OCR worker đã khởi chạy."
fi

if [[ "${AI_COVER_WORKER_ENABLED:-false}" == "true" ]]; then
    if [[ "${AI_COVER_ENABLED:-false}" != "true" || -z "${AI_COVER_WORKER_URL:-}" || -z "${AI_COVER_CALLBACK_SECRET:-}" ]]; then
        echo "AI_COVER_WORKER_ENABLED=true nhưng AI_COVER_ENABLED, AI_COVER_WORKER_URL hoặc AI_COVER_CALLBACK_SECRET chưa hợp lệ." >&2
        exit 1
    fi
    php bin/ai-cover-dispatcher.php &
    background_pids+=("$!")
    echo "HENDY AI Cover dispatcher đã khởi chạy."
fi

if [[ "${WEBSOCKET_ENABLED:-false}" == "true" ]]; then
    if [[ -z "${WEBSOCKET_ALLOWED_ORIGINS:-}" ]]; then
        echo "WEBSOCKET_ALLOWED_ORIGINS bắt buộc khi bật WebSocket." >&2
        exit 1
    fi
    php bin/websocket.php &
    background_pids+=("$!")
fi

else
    echo "HENDY_INLINE_WORKERS=false; queue workers are managed by the dedicated hendy-worker Compose service."
fi

apache2-foreground &
background_pids+=("$!")

cleanup() {
    for pid in "${background_pids[@]}"; do
        kill "$pid" 2>/dev/null || true
    done
    for pid in "${background_pids[@]}"; do
        wait "$pid" 2>/dev/null || true
    done
}
trap cleanup EXIT INT TERM

echo "HENDY services started; Apache port=$port; AI queue=${AI_MEDIA_QUEUE_ENABLED:-false}; AI Cover=${AI_COVER_WORKER_ENABLED:-false}; WebSocket=${WEBSOCKET_ENABLED:-false}."
set +e
wait -n "${background_pids[@]}"
status=$?
set -e
echo "Một tiến trình HENDY đã dừng (exit=$status); kết thúc container để Docker khởi động lại." >&2
exit "$status"
