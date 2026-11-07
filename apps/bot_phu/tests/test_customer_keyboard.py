from __future__ import annotations

import unittest

from aiogram.types import InlineKeyboardButton, WebAppInfo
from services.aiogram_bot.config import Settings
from services.aiogram_bot.routers.customer import customer_center_keyboard

class CustomerKeyboardTests(unittest.TestCase):
    def test_mini_app_is_first_and_legacy_site_is_optional(self) -> None:
        settings = Settings.from_env({
            "AIOGRAM_BOT_TOKEN": "123456:test",
            "AIOGRAM_WEBHOOK_SECRET": "test-aiogram-webhook-secret-32bytes-minimum",
            "RENDER_EXTERNAL_URL": "https://bot.example.com",
            "HENDY_API_BASE_URL": "https://api.example.com",
            "HENDY_BOT_SERVICE_KEY": "x" * 40,
            "DATABASE_URL": "postgresql://user:pass@db.example.com/hendy",
            "PUBLIC_APP_URL": "https://app.example.com",
            "LEGACY_CUSTOMER_URL": "https://legacy.example.com/customer",
            "PGSSLMODE": "require",
        })
        keyboard = customer_center_keyboard(settings)
        self.assertEqual(keyboard.inline_keyboard[0][0].web_app.url, settings.public_app_url)
        self.assertEqual(keyboard.inline_keyboard[1][0].url, settings.legacy_customer_url)

    def test_no_secret_is_embedded_in_button_url(self) -> None:
        settings = Settings.from_env({
            "AIOGRAM_BOT_TOKEN": "123456:test",
            "AIOGRAM_WEBHOOK_SECRET": "test-aiogram-webhook-secret-32bytes-minimum",
            "RENDER_EXTERNAL_URL": "https://bot.example.com",
            "HENDY_API_BASE_URL": "https://api.example.com",
            "HENDY_BOT_SERVICE_KEY": "x" * 40,
            "DATABASE_URL": "postgresql://user:pass@db.example.com/hendy",
            "PUBLIC_APP_URL": "https://app.example.com",
        })
        keyboard = customer_center_keyboard(settings)
        for row in keyboard.inline_keyboard:
            for button in row:
                self.assertFalse(button.url and "token=" in button.url.lower())


    def test_return_button_has_callback_data(self) -> None:
        settings = Settings.from_env({
            "AIOGRAM_BOT_TOKEN": "123456:test",
            "AIOGRAM_WEBHOOK_SECRET": "test-aiogram-webhook-secret-32bytes-minimum",
            "RENDER_EXTERNAL_URL": "https://bot.example.com",
            "HENDY_API_BASE_URL": "https://api.example.com",
            "HENDY_BOT_SERVICE_KEY": "x" * 40,
            "DATABASE_URL": "postgresql://user:pass@db.example.com/hendy",
            "PUBLIC_APP_URL": "https://app.example.com",
            "PGSSLMODE": "require",
        })
        keyboard = customer_center_keyboard(settings)
        self.assertEqual(keyboard.inline_keyboard[-1][0].callback_data, "customer:home")


if __name__ == "__main__":
    unittest.main()
