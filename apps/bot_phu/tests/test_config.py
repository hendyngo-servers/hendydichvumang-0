from __future__ import annotations

import unittest

from apps.bot_phu.config import Settings, normalize_webhook_secret


class SettingsTests(unittest.TestCase):
    def valid_env(self) -> dict[str, str]:
        return {
            "BOT2_TOKEN": "123456:aiogram-test-token",
            "AIOGRAM_WEBHOOK_SECRET": "render-generated-secret+/",
            "RENDER_EXTERNAL_URL": "https://aiogram.onrender.com",
            "HENDY_API_BASE_URL": "https://api-hendy.onrender.com",
            "HENDY_BOT_SERVICE_KEY": "a" * 48,
            "DATABASE_URL": "postgresql://user:pass@db.example.com:5432/hendy?sslmode=require",
            "PUBLIC_APP_URL": "https://hendy-site.onrender.com",
            "PGSSLMODE": "require",
        }

    def test_valid_settings_are_normalized(self) -> None:
        settings = Settings.from_env(self.valid_env())
        self.assertEqual(settings.webhook_base_url, "https://aiogram.onrender.com")
        self.assertEqual(settings.api_base_url, "https://api-hendy.onrender.com")
        self.assertEqual(settings.public_app_url, "https://hendy-site.onrender.com/app")
        self.assertEqual(settings.database_ssl_mode, "require")
        self.assertNotIn("sslmode", settings.database_url)
        self.assertEqual(settings.webhook_path, "/telegram/webhook")

    def test_mini_app_url_keeps_route_and_rejects_query(self) -> None:
        env = self.valid_env()
        env["PUBLIC_MINI_APP_URL"] = "https://hendy-site.onrender.com/app"
        settings = Settings.from_env(env)
        self.assertEqual(settings.public_app_url, "https://hendy-site.onrender.com/app")
        env["PUBLIC_MINI_APP_URL"] = "https://hendy-site.onrender.com/app?token=unsafe"
        with self.assertRaisesRegex(ValueError, "query"):
            Settings.from_env(env)

    def test_webhook_secret_is_url_safe(self) -> None:
        normalized = normalize_webhook_secret("render-value+/=")
        self.assertRegex(normalized, r"^[A-Za-z0-9_-]{43}$")

    def test_aiogram_token_must_differ_from_primary_grammy_token(self) -> None:
        env = self.valid_env()
        env["BOT1_TOKEN"] = env["BOT2_TOKEN"]
        with self.assertRaisesRegex(ValueError, "phải khác"):
            Settings.from_env(env)

    def test_legacy_aiogram_token_name_remains_supported(self) -> None:
        env = self.valid_env()
        env["AIOGRAM_BOT_TOKEN"] = env.pop("BOT2_TOKEN")
        self.assertEqual(Settings.from_env(env).bot_token, "123456:aiogram-test-token")

    def test_bot2_token_takes_precedence_over_legacy_alias(self) -> None:
        env = self.valid_env()
        env["AIOGRAM_BOT_TOKEN"] = "999:legacy-token"
        self.assertEqual(Settings.from_env(env).bot_token, "123456:aiogram-test-token")

    def test_api_and_mini_app_must_use_https(self) -> None:
        env = self.valid_env()
        env["HENDY_API_BASE_URL"] = "http://api.example.com"
        with self.assertRaisesRegex(ValueError, "HTTPS"):
            Settings.from_env(env)

    def test_legacy_url_rejects_static_token(self) -> None:
        env = self.valid_env()
        env["LEGACY_CUSTOMER_URL"] = "https://legacy.example.com/?token=static"
        with self.assertRaisesRegex(ValueError, "token"):
            Settings.from_env(env)

    def test_webhook_path_rejects_query_and_traversal(self) -> None:
        env = self.valid_env()
        env["AIOGRAM_WEBHOOK_PATH"] = "/telegram/webhook?token=secret"
        with self.assertRaisesRegex(ValueError, "đường dẫn"):
            Settings.from_env(env)
        env["AIOGRAM_WEBHOOK_PATH"] = "/telegram/../webhook"
        with self.assertRaisesRegex(ValueError, "đường dẫn"):
            Settings.from_env(env)
    def test_service_key_rejects_placeholder_and_weak_values(self) -> None:
        env = self.valid_env()
        env["HENDY_BOT_SERVICE_KEY"] = "replace-with-a-random-32-byte-secret"
        with self.assertRaisesRegex(ValueError, "không dùng placeholder"):
            Settings.from_env(env)

        env["HENDY_BOT_SERVICE_KEY"] = "too-short"
        with self.assertRaisesRegex(ValueError, "tối thiểu 32 byte"):
            Settings.from_env(env)

    def test_blank_mini_app_url_is_derived_from_detected_static_site_domain(self) -> None:
        env = self.valid_env()
        env["PUBLIC_MINI_APP_URL"] = ""
        settings = Settings.from_env(env)
        self.assertEqual(settings.public_app_url, "https://hendy-site.onrender.com/app")

    def test_invalid_fsm_ttl_is_rejected(self) -> None:
        env = self.valid_env()
        env["AIOGRAM_FSM_TTL_HOURS"] = "0"
        with self.assertRaisesRegex(ValueError, "1–168"):
            Settings.from_env(env)


if __name__ == "__main__":
    unittest.main()
