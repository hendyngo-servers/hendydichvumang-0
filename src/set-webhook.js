import 'dotenv/config';
import { Bot } from 'grammy';
import { normalizeWebhookSecret } from './security.js';

const token = String(process.env.BOT1_TOKEN || process.env.BOT_TOKEN || '').trim();
const rawSecret = String(process.env.TELEGRAM_WEBHOOK_SECRET || '').trim();
const secret = normalizeWebhookSecret(rawSecret);
const base = String(process.env.PUBLIC_API_URL || process.env.RENDER_EXTERNAL_URL || '').trim().replace(/\/+$/, '');
const appOrigin = String(process.env.PUBLIC_APP_URL || '').trim().replace(/\/+$/, '');
const appUrl = String(process.env.PUBLIC_MINI_APP_URL || (appOrigin ? appOrigin + '/app' : '')).trim().replace(/\/+$/, '');
const weakSecret = Buffer.byteLength(rawSecret, 'utf8') < 32 ||
  /^(replace-with|change-me|changeme|placeholder)/i.test(rawSecret);

if (!token || !secret || weakSecret || !base.startsWith('https://') || !appUrl.startsWith('https://')) {
  console.error('Cần BOT1_TOKEN, TELEGRAM_WEBHOOK_SECRET ngẫu nhiên tối thiểu 32 byte, PUBLIC_API_URL và Mini App URL HTTPS.');
  process.exit(1);
}

const bot = new Bot(token);
await bot.api.setWebhook(base + '/telegram/webhook', {
  secret_token: secret,
  allowed_updates: ['message', 'callback_query']
});
await bot.api.setMyCommands([
  { command: 'start', description: 'Mở menu HENDY' },
  { command: 'customer', description: 'Trung tâm khách hàng' },
  { command: 'services', description: 'Xem danh sách dịch vụ' },
  { command: 'balance', description: 'Kiểm tra số dư' },
  { command: 'support', description: 'Trung tâm hỗ trợ' },
  { command: 'help', description: 'Hướng dẫn sử dụng' }
]);
await bot.api.setChatMenuButton({
  menu_button: {
    type: 'web_app',
    text: 'Trung tâm KH',
    web_app: { url: appUrl }
  }
});
console.log('Đã đăng ký webhook API và nút Mini App:', appUrl);
