from __future__ import annotations

import base64
import hashlib
import os
import re
from dataclasses import dataclass
from typing import Mapping
from urllib.parse import parse_qsl, urlencode, urlsplit, urlunsplit


def _required(env: Mapping[str, str], key: str) -> str:
    value = str(env.get(key, "")).strip()
    if not value:
        raise ValueError(f"Thiếu biến môi trường bắt buộc: {key}")
    return value


def _https_origin(value: str, key: str) -> str:
    parsed = urlsplit(value.strip())
    if parsed.scheme != "https" or not parsed.hostname or parsed.username or parsed.password:
        raise ValueError(f"{key} phải là URL HTTPS công khai, không chứa thông tin đăng nhập.")
    return f"https://{parsed.netloc}"


def _legacy_url(value: str) -> str:
    raw = value.strip()
    if not raw:
        return ""
    parsed = urlsplit(raw)
    if parsed.scheme != "https" or not parsed.hostname or parsed.username or parsed.password:
        raise ValueError("LEGACY_CUSTOMER_URL phải là URL HTTPS công khai.")
    forbidden = {"token", "access_token", "api_key", "apikey", "password", "secret"}
    if any(key.lower() in forbidden for key, _ in parse_qsl(parsed.query, keep_blank_values=True)):
        raise ValueError("Không được nhúng token hoặc secret vào LEGACY_CUSTOMER_URL.")
    return urlunsplit((parsed.scheme, parsed.netloc, parsed.path, parsed.query, ""))


def _https_url(value: str, key: str) -> str:
    parsed = urlsplit(value.strip())
    if parsed.scheme != "https" or not parsed.hostname or parsed.username or parsed.password:
        raise ValueError(f"{key} phải là URL HTTPS công khai, không chứa thông tin đăng nhập.")
    if parsed.query or parsed.fragment:
        raise ValueError(f"{key} không được chứa query hoặc fragment.")
    path = parsed.path.rstrip("/")
    return f"https://{parsed.netloc}{path}"


def normalize_webhook_secret(value: str) -> str:
    digest = hashlib.sha256(value.encode("utf-8")).digest()
    return base64.urlsafe_b64encode(digest).decode("ascii").rstrip("=")


def normalize_database_dsn(value: str) -> str:
    parts = urlsplit(value)
    removable = {"sslmode", "sslrootcert", "sslcert", "sslkey"}
    query = [(key, item) for key, item in parse_qsl(parts.query, keep_blank_values=True) if key.lower() not in removable]
    return urlunsplit((parts.scheme, parts.netloc, parts.path, urlencode(query), parts.fragment))


@dataclass(frozen=True, slots=True)
class Settings:
    bot_token: str
    webhook_secret: str
    webhook_base_url: str
    webhook_path: str
    api_base_url: str
    service_key: str
    database_url: str
    database_ssl_mode: str
    database_ssl_ca: str
    public_app_url: str
    legacy_customer_url: str
    host: str
    port: int
    fsm_ttl_hours: int

    @classmethod
    def from_env(cls, env: Mapping[str, str] | None = None) -> "Settings":
        source = os.environ if env is None else env
        token = str(source.get("BOT2_TOKEN") or source.get("AIOGRAM_BOT_TOKEN") or "").strip()
        if not token:
            raise ValueError("Thiếu biến môi trường bắt buộc: BOT2_TOKEN")
        primary_token = str(source.get("BOT1_TOKEN") or source.get("BOT_TOKEN") or "").strip()
        if primary_token and token == primary_token:
            raise ValueError("BOT2_TOKEN phải khác BOT1_TOKEN của grammY chính.")

        database_url = _required(source, "DATABASE_URL")
        db_parts = urlsplit(database_url)
        if db_parts.scheme not in {"postgres", "postgresql"} or not db_parts.hostname:
            raise ValueError("DATABASE_URL phải là PostgreSQL connection string.")

        service_key = _required(source, "HENDY_BOT_SERVICE_KEY")
        if len(service_key.encode("utf-8")) < 32 or service_key.lower().startswith(
            ("replace-with", "change-me", "changeme", "placeholder")
        ):
            raise ValueError(
                "HENDY_BOT_SERVICE_KEY phải là khóa ngẫu nhiên tối thiểu 32 byte; "
                "không dùng placeholder."
            )

        api_url = _https_origin(_required(source, "HENDY_API_BASE_URL"), "HENDY_API_BASE_URL")
        public_origin = _https_origin(_required(source, "PUBLIC_APP_URL"), "PUBLIC_APP_URL")
        mini_app_value = str(source.get("PUBLIC_MINI_APP_URL", "")).strip() or (public_origin + "/app")
        app_url = _https_url(mini_app_value, "PUBLIC_MINI_APP_URL")
        if urlsplit(app_url).netloc != urlsplit(public_origin).netloc:
            raise ValueError("PUBLIC_MINI_APP_URL phải cùng domain với PUBLIC_APP_URL.")
        webhook_url = _https_origin(
            _required(source, "AIOGRAM_PUBLIC_URL") if source.get("AIOGRAM_PUBLIC_URL") else _required(source, "RENDER_EXTERNAL_URL"),
            "AIOGRAM_PUBLIC_URL/RENDER_EXTERNAL_URL",
        )

        raw_secret = _required(source, "AIOGRAM_WEBHOOK_SECRET")
        ssl_mode = str(source.get("PGSSLMODE", "require")).strip().lower()
        if ssl_mode not in {"disable", "require", "verify-full"}:
            raise ValueError("PGSSLMODE chỉ nhận disable, require hoặc verify-full.")

        try:
            port = int(source.get("PORT", "10000"))
            ttl_hours = int(source.get("AIOGRAM_FSM_TTL_HOURS", "24"))
        except ValueError as exc:
            raise ValueError("PORT hoặc AIOGRAM_FSM_TTL_HOURS không hợp lệ.") from exc
        if not 1 <= port <= 65535:
            raise ValueError("PORT phải nằm trong khoảng 1–65535.")
        if not 1 <= ttl_hours <= 168:
            raise ValueError("AIOGRAM_FSM_TTL_HOURS phải nằm trong khoảng 1–168.")

        webhook_path = str(source.get("AIOGRAM_WEBHOOK_PATH", "/telegram/webhook")).strip()
        if (
            not re.fullmatch(r"/[A-Za-z0-9/_-]{1,120}", webhook_path)
            or "//" in webhook_path
            or any(part in {".", ".."} for part in webhook_path.split("/"))
        ):
            raise ValueError("AIOGRAM_WEBHOOK_PATH phải là đường dẫn tuyệt đối đơn giản, không có query hoặc traversal.")

        return cls(
            bot_token=token,
            webhook_secret=normalize_webhook_secret(raw_secret),
            webhook_base_url=webhook_url,
            webhook_path=webhook_path,
            api_base_url=api_url,
            service_key=service_key,
            database_url=normalize_database_dsn(database_url),
            database_ssl_mode=ssl_mode,
            database_ssl_ca=str(source.get("PGSSL_CA_CERT", "")),
            public_app_url=app_url,
            legacy_customer_url=_legacy_url(str(source.get("LEGACY_CUSTOMER_URL", ""))),
            host="0.0.0.0",
            port=port,
            fsm_ttl_hours=ttl_hours,
        )
