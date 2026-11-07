from __future__ import annotations

import unittest
from types import SimpleNamespace
from unittest.mock import AsyncMock

from aiogram.types import ReplyKeyboardMarkup

from apps.bot_phu.config import Settings
from apps.bot_phu.routers.common import command_cancel


class CancelCommandTests(unittest.IsolatedAsyncioTestCase):
    def settings(self) -> Settings:
        return Settings.from_env({
            "BOT2_TOKEN": "123456:aiogram-test-token",
            "AIOGRAM_WEBHOOK_SECRET": "test-aiogram-webhook-secret-32bytes-minimum",
            "RENDER_EXTERNAL_URL": "https://aiogram.example.com",
            "HENDY_API_BASE_URL": "https://api.example.com",
            "HENDY_BOT_SERVICE_KEY": "s" * 48,
            "DATABASE_URL": "postgresql://user:pass@db.example.com:5432/hendy",
            "PUBLIC_APP_URL": "https://site.example.com",
            "PGSSLMODE": "require",
        })

    async def test_cancel_clears_state_and_restores_hendy_menu(self) -> None:
        state = SimpleNamespace(clear=AsyncMock())
        message = SimpleNamespace(answer=AsyncMock())
        menu_config = {
            "buttonOrder": ["customer", "balance", "services", "support"],
            "menuColumns": 2,
        }

        await command_cancel(
            message,
            state,
            self.settings(),
            menu_config,
            hendy_is_admin=False,
        )

        state.clear.assert_awaited_once()
        message.answer.assert_awaited_once()
        self.assertIsInstance(
            message.answer.await_args.kwargs["reply_markup"],
            ReplyKeyboardMarkup,
        )
        self.assertIn(
            "Bạn có thể chọn chức năng khác",
            message.answer.await_args.args[0],
        )


if __name__ == "__main__":
    unittest.main()
