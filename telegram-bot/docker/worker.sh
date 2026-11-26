#!/usr/bin/env bash
set -euo pipefail

cd /var/www/app

video_processor_secret="${VIDEO_PROCESSOR_SECRET:-}"
ai_cover_callback_secret="${AI_COVER_CALLBACK_SECRET:-}"
worker_pids=()
start_worker() {
    local label="$1"
    shift
    "$@" &
    worker_pids+=("$!")
    echo "HENDY worker started: $label"
}

if [[ "${MULTI_AGENT_ENABLED:-false}" == "true" ]]; then
    if [[ -z "${OPENAI_API_KEY:-}" ]]; then
        echo "MULTI_AGENT_ENABLED=true requires OPENAI_API_KEY in the VPS environment." >&2
        exit 1
    fi
    php bin/multi-agent-preflight.php
    start_worker "multi-agent" php bin/multi-agent-worker.php
fi

if [[ "${AI_MEDIA_QUEUE_ENABLED:-false}" == "true" ]]; then
    if [[ -z "${OPENAI_API_KEY:-}" && -z "${ELEVENLABS_API_KEY:-}" ]]; then
        echo "AI_MEDIA_QUEUE_ENABLED=true requires OPENAI_API_KEY or ELEVENLABS_API_KEY." >&2
        exit 1
    fi
    start_worker "ai-media" php bin/ai-worker.php
fi

if [[ "${VIDEO_WORKER_ENABLED:-false}" == "true" ]]; then
    if [[ -z "${VIDEO_PROCESSOR_URL:-}" || ${#video_processor_secret} -lt 32 || -z "${APP_URL:-}" ]]; then
        echo "VIDEO_WORKER_ENABLED=true requires HTTPS APP_URL, VIDEO_PROCESSOR_URL and a 32+ character VIDEO_PROCESSOR_SECRET." >&2
        exit 1
    fi
    start_worker "video" php bin/video-worker.php
fi

if [[ "${CODE_TELEGRAM_WORKER_ENABLED:-false}" == "true" ]]; then
    if [[ "${CODE_TELEGRAM_ENABLED:-false}" != "true" ]]; then
        echo "CODE_TELEGRAM_WORKER_ENABLED=true requires CODE_TELEGRAM_ENABLED=true." >&2
        exit 1
    fi
    start_worker "code-telegram" php bin/code-telegram-worker.php
fi

if [[ "${AI_COVER_WORKER_ENABLED:-false}" == "true" ]]; then
    if [[ "${AI_COVER_ENABLED:-false}" != "true" || -z "${AI_COVER_WORKER_URL:-}" || ${#ai_cover_callback_secret} -lt 32 ]]; then
        echo "AI_COVER_WORKER_ENABLED=true requires AI_COVER_ENABLED, AI_COVER_WORKER_URL and a 32+ character AI_COVER_CALLBACK_SECRET." >&2
        exit 1
    fi
    start_worker "ai-cover" php bin/ai-cover-dispatcher.php
fi

if [[ ${#worker_pids[@]} -eq 0 ]]; then
    echo "The workers profile is active, but no worker feature is enabled. Set the required feature flag(s) or remove COMPOSE_PROFILES=workers." >&2
    exit 2
fi

cleanup() {
    for pid in "${worker_pids[@]}"; do
        kill "$pid" 2>/dev/null || true
    done
    for pid in "${worker_pids[@]}"; do
        wait "$pid" 2>/dev/null || true
    done
}
trap cleanup EXIT INT TERM

echo "HENDY dedicated worker container ready; count=${#worker_pids[@]}."
set +e
wait -n "${worker_pids[@]}"
status=$?
set -e
echo "A HENDY worker exited (exit=$status); Docker will restart the worker container." >&2
exit "$status"
