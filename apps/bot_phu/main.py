from __future__ import annotations

import asyncio
import logging
import ssl
from datetime import datetime, timezone

import asyncpg
from aiohttp import web
from aiogram import Bot, Dispatcher
from aiogram.client.default import DefaultBotProperties
from aiogram.enums import ParseMode
from aiogram.types import BotCommand, MenuButtonWebApp, WebAppInfo
from aiogram.webhook.aiohttp_server import SimpleRequestHandler, setup_application

from apps.bot_phu.api_client import HendyApiClient
from apps.bot_phu.config import Settings
from apps.bot_phu.fsm_storage import PostgresEventIsolation, PostgresFSMStorage
from apps.bot_phu.middlewares import EnsureCustomerMiddleware
from apps.bot_phu.routers import (
    admin_router,
    automation_router,
    common_router,
    customer_router,
    error_router,
    support_router,
)

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(name)s %(message)s",
)
logger = logging.getLogger("hendy.aiogram")


class BotIdentityConflict(RuntimeError):
    """Raised when the pilot token belongs to the primary grammY bot."""


def database_ssl(settings: Settings) -> ssl.SSLContext | str | None:
    if settings.database_ssl_mode == "disable":
        return None
    if settings.database_ssl_mode == "require":
        return "require"
    context = ssl.create_default_context(cafile=settings.database_ssl_ca or None)
    context.check_hostname = True
    context.verify_mode = ssl.CERT_REQUIRED
    return context


async def create_database_pool(settings: Settings) -> asyncpg.Pool:
    return await asyncpg.create_pool(
        dsn=settings.database_url,
        ssl=database_ssl(settings),
        min_size=1,
        max_size=5,
        command_timeout=15,
        timeout=15,
        server_settings={"application_name": "hendydichvumang-aiogram"},
    )


async def on_startup(
    bot: Bot,
    api: HendyApiClient,
    settings: Settings,
    dispatcher: Dispatcher,
) -> None:
    # The aiogram pilot must never take over the primary grammY bot's webhook.
    for attempt in range(1, 9):
        try:
            health = await api.health()
            if health.get("status") != "ok" or health.get("database") != "ready":
                raise RuntimeError("HENDY API/PostgreSQL chưa sẵn sàng.")
            primary = await api.get_primary_bot_identity()
            me = await bot.get_me()
            if str(primary.get("telegram_id")) == str(me.id):
                raise BotIdentityConflict(
                    "AIOGRAM_BOT_TOKEN trùng Bot Token grammY chính. "
                    "Hãy cấp token khác cho bot thử nghiệm; webhook chính chưa bị thay đổi."
                )
            break
        except BotIdentityConflict:
            raise
        except Exception as exc:
            if attempt == 8:
                raise RuntimeError("Không thể kết nối HENDY API để khởi động aiogram.") from exc
            await asyncio.sleep(min(attempt * 2, 12))

    webhook_url = settings.webhook_base_url + settings.webhook_path
    await bot.set_webhook(
        url=webhook_url,
        secret_token=settings.webhook_secret,
        allowed_updates=dispatcher.resolve_used_update_types(),
        drop_pending_updates=False,
    )
    await bot.set_my_commands([
        BotCommand(command="start", description="Mở menu HENDY"),
        BotCommand(command="customer", description="Trung tâm khách hàng"),
        BotCommand(command="services", description="Xem danh sách dịch vụ"),
        BotCommand(command="balance", description="Kiểm tra số dư"),
        BotCommand(command="support", description="Tạo ticket hỗ trợ"),
        BotCommand(command="admin", description="Trung tâm quản trị (chỉ quản trị viên)"),
        BotCommand(command="automation", description="Quản lý tự động hóa (chỉ quản trị viên)"),
        BotCommand(command="cancel", description="Hủy thao tác đang nhập"),
        BotCommand(command="help", description="Hướng dẫn sử dụng"),
    ])
    await bot.set_chat_menu_button(
        menu_button=MenuButtonWebApp(
            text="Trung tâm KH",
            web_app=WebAppInfo(url=settings.public_app_url),
        )
    )
    runtime = dispatcher.get("runtime_status")
    if isinstance(runtime, dict):
        runtime.update({
            "webhook_ready": True,
            "bot_id": str(me.id),
            "bot_username": me.username,
            "bot_first_name": me.first_name,
        })
    logger.info("Aiogram webhook đã sẵn sàng tại %s; bot_id=%s", settings.webhook_path, me.id)


async def health(request: web.Request) -> web.Response:
    pool: asyncpg.Pool = request.app["db_pool"]
    try:
        async with pool.acquire() as connection:
            await connection.fetchval("SELECT 1")
    except Exception as exc:
        # Avoid logging DSNs, hostnames, or driver error details.
        logger.error("PostgreSQL health check failed: %s", type(exc).__name__)
        return web.json_response({"status": "error", "database": "unavailable"}, status=503)
    runtime = request.app["runtime_status"]
    if not runtime.get("webhook_ready"):
        return web.json_response(
            {"status": "starting", "database": "ready", "webhook": "not_ready"},
            status=503,
        )
    return web.json_response({
        "status": "ok",
        "database": "ready",
        "webhook": "ready",
        "bot_id": runtime.get("bot_id"),
        "bot_username": runtime.get("bot_username"),
        "framework": "aiogram",
        "timestamp": datetime.now(timezone.utc).isoformat(),
    })


async def close_resources(app: web.Application) -> None:
    api: HendyApiClient = app["hendy_api"]
    pool: asyncpg.Pool = app["db_pool"]
    bot: Bot = app["bot"]
    try:
        await api.close()
    finally:
        try:
            await bot.session.close()
        finally:
            await pool.close()


async def create_app(settings: Settings) -> web.Application:
    pool = await create_database_pool(settings)
    api = HendyApiClient(settings.api_base_url, settings.service_key)
    try:
        await api.open()
        storage = PostgresFSMStorage(pool, ttl_hours=settings.fsm_ttl_hours)
        isolation = PostgresEventIsolation(pool)
        dispatcher = Dispatcher(storage=storage, events_isolation=isolation)
        dispatcher["api"] = api
        dispatcher["settings"] = settings
        dispatcher["runtime_status"] = {"webhook_ready": False, "bot_id": None}

        # One profile sync per private message before the router tree is traversed.
        dispatcher.message.outer_middleware(EnsureCustomerMiddleware(api))

        dispatcher.include_routers(
            common_router,
            customer_router,
            support_router,
            admin_router,
            automation_router,
        )
        dispatcher.include_router(error_router)
        dispatcher.startup.register(on_startup)

        bot = Bot(
            token=settings.bot_token,
            default=DefaultBotProperties(parse_mode=ParseMode.HTML),
        )
        app = web.Application(client_max_size=1024 * 1024)
        app["db_pool"] = pool
        app["hendy_api"] = api
        app["dispatcher"] = dispatcher
        app["bot"] = bot
        app["runtime_status"] = dispatcher["runtime_status"]
        app.router.add_get("/healthz", health)

        SimpleRequestHandler(
            dispatcher=dispatcher,
            bot=bot,
            secret_token=settings.webhook_secret,
            handle_in_background=False,
            api=api,
            settings=settings,
        ).register(app, path=settings.webhook_path)
        setup_application(app, dispatcher, bot=bot, api=api, settings=settings)
        app.on_cleanup.append(close_resources)
        return app
    except Exception:
        await api.close()
        await pool.close()
        raise


def main() -> None:
    settings = Settings.from_env()
    web.run_app(
        create_app(settings),
        host=settings.host,
        port=settings.port,
        access_log_format='%a "%r" %s %Tf',
    )


if __name__ == "__main__":
    main()
