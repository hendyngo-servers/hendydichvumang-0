import { normalizeWebhookSecret } from './security.js';

function readPositiveInteger(value, fallback, name, max = 2_147_483_647) {
  if (value === undefined || value === '') return fallback;
  const parsed = Number(value);
  if (!Number.isSafeInteger(parsed) || parsed < 1 || parsed > max) {
    throw new Error(name + ' không hợp lệ.');
  }
  return parsed;
}

function normalizeBaseUrl(value, fallback, name) {
  const raw = String(value || fallback).trim().replace(/\/+$/, '');
  let url;
  try { url = new URL(raw); } catch { throw new Error(name + ' không phải URL hợp lệ.'); }
  if (!['http:', 'https:'].includes(url.protocol)) throw new Error(name + ' phải dùng HTTP hoặc HTTPS.');
  return url.origin;
}

function normalizeWebAppUrl(value, fallback, name) {
  const raw = String(value || fallback).trim();
  let url;
  try { url = new URL(raw); } catch { throw new Error(name + ' không phải URL hợp lệ.'); }
  if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || url.search || url.hash) {
    throw new Error(name + ' phải là URL HTTP(S), không chứa thông tin đăng nhập, query hoặc fragment.');
  }
  let pathname = url.pathname;
  while (pathname.length > 1 && pathname.endsWith('/')) pathname = pathname.slice(0, -1);
  return url.origin + (pathname === '/' ? '' : pathname);
}

function normalizeTelegramLink(value, name) {
  const raw = String(value || '').trim();
  if (!raw) return '';
  let url;
  try { url = new URL(raw); } catch { throw new Error(name + ' không phải URL hợp lệ.'); }
  const allowedHosts = new Set(['t.me', 'www.t.me', 'telegram.me', 'www.telegram.me']);
  if (url.protocol !== 'https:' || !allowedHosts.has(url.hostname.toLowerCase()) ||
      url.username || url.password || url.hash) {
    throw new Error(name + ' phải là liên kết HTTPS công khai từ t.me hoặc telegram.me.');
  }
  return url.toString();
}

export function loadConfig(env = process.env) {
  const isProduction = env.NODE_ENV === 'production';
  const databaseUrl = String(env.DATABASE_URL || env.EXTERNAL_DATABASE_URL || '').trim();
  if (!databaseUrl) throw new Error('Thiếu DATABASE_URL (có thể dùng PostgreSQL bên ngoài).');
  let dbUrl;
  try { dbUrl = new URL(databaseUrl); } catch { throw new Error('DATABASE_URL không hợp lệ.'); }
  if (!['postgres:', 'postgresql:'].includes(dbUrl.protocol) || !dbUrl.hostname) {
    throw new Error('DATABASE_URL phải là PostgreSQL URL hợp lệ.');
  }

  const port = readPositiveInteger(env.PORT, 10000, 'PORT', 65535);
  const botToken = String(env.BOT1_TOKEN || env.BOT_TOKEN || '').trim();
  const rawWebhookSecret = String(env.TELEGRAM_WEBHOOK_SECRET || '').trim();
  const adminUsername = String(env.ADMIN_USERNAME || 'admin').trim();
  const adminPassword = String(env.ADMIN_PASSWORD || '');
  const botServiceKey = String(env.BOT_SERVICE_KEY || '').trim();
  const sslMode = String(env.PGSSLMODE || (isProduction ? 'require' : 'disable')).trim().toLowerCase();

  if (botServiceKey && (
    Buffer.byteLength(botServiceKey, 'utf8') < 32 ||
    /^(replace-with|change-me|changeme|placeholder)/i.test(botServiceKey)
  )) {
    throw new Error('BOT_SERVICE_KEY phải là khóa bí mật ngẫu nhiên tối thiểu 32 byte; Render Blueprint có thể tự tạo khóa 256-bit.');
  }

  if (!['disable', 'require', 'verify-full'].includes(sslMode)) {
    throw new Error('PGSSLMODE chỉ nhận disable, require hoặc verify-full.');
  }
  if (isProduction && (
    adminPassword.length < 13 ||
    /^(replace-with|change-me|changeme|placeholder)/i.test(adminPassword)
  )) {
    throw new Error('ADMIN_PASSWORD phải là mật khẩu riêng tối thiểu 13 ký tự; Render Blueprint có thể tự sinh 256-bit.');
  }
  if (isProduction && !adminUsername) throw new Error('ADMIN_USERNAME không được để trống.');
  if (botToken && !rawWebhookSecret) throw new Error('Đã cấu hình BOT1_TOKEN (hoặc BOT_TOKEN cũ) nhưng thiếu TELEGRAM_WEBHOOK_SECRET.');

  const publicApiUrl = normalizeBaseUrl(
    env.PUBLIC_API_URL || env.RENDER_EXTERNAL_URL,
    'http://localhost:' + port,
    'PUBLIC_API_URL'
  );
  const publicAppUrl = normalizeBaseUrl(env.PUBLIC_APP_URL, publicApiUrl, 'PUBLIC_APP_URL');
  const publicMiniAppUrl = normalizeWebAppUrl(
    env.PUBLIC_MINI_APP_URL,
    publicAppUrl + '/app',
    'PUBLIC_MINI_APP_URL'
  );
  if (new URL(publicMiniAppUrl).origin !== publicAppUrl) {
    throw new Error('PUBLIC_MINI_APP_URL phải cùng origin với PUBLIC_APP_URL.');
  }
  if (isProduction && botToken && !publicApiUrl.startsWith('https://')) {
    throw new Error('PUBLIC_API_URL phải là HTTPS khi bật Telegram Bot.');
  }
  if (isProduction && botToken && !publicAppUrl.startsWith('https://')) {
    throw new Error('PUBLIC_APP_URL phải là HTTPS khi bật Telegram Mini App.');
  }
  if (isProduction && botToken && !publicMiniAppUrl.startsWith('https://')) {
    throw new Error('PUBLIC_MINI_APP_URL phải là HTTPS khi bật Telegram Mini App.');
  }

  const origins = String(env.ALLOWED_ORIGINS || '')
    .split(',').map((item) => item.trim().replace(/\/+$/, '')).filter(Boolean);
  const telegramGroupUrl = normalizeTelegramLink(env.TELEGRAM_GROUP_URL, 'TELEGRAM_GROUP_URL');
  const telegramChannelUrl = normalizeTelegramLink(env.TELEGRAM_CHANNEL_URL, 'TELEGRAM_CHANNEL_URL');
  const adminTelegramId = String(env.ADMIN_TELEGRAM_ID || '').trim();
  if (adminTelegramId && !/^-?\d{5,20}$/.test(adminTelegramId)) {
    throw new Error('ADMIN_TELEGRAM_ID không hợp lệ.');
  }

  const renderRuntime = Object.freeze({
    detected: String(env.RENDER || '').toLowerCase() === 'true' ||
      Boolean(env.RENDER_SERVICE_ID || env.RENDER_EXTERNAL_URL || env.RENDER_EXTERNAL_HOSTNAME),
    serviceId: String(env.RENDER_SERVICE_ID || '').trim(),
    serviceName: String(env.RENDER_SERVICE_NAME || '').trim(),
    serviceType: String(env.RENDER_SERVICE_TYPE || '').trim(),
    externalUrl: String(env.RENDER_EXTERNAL_URL || '').trim(),
    externalHostname: String(env.RENDER_EXTERNAL_HOSTNAME || '').trim(),
    gitBranch: String(env.RENDER_GIT_BRANCH || '').trim(),
    gitCommit: String(env.RENDER_GIT_COMMIT || '').trim()
  });

  return {
    nodeEnv: env.NODE_ENV || 'development',
    isProduction,
    port,
    databaseUrl,
    databaseSslMode: sslMode,
    databaseSslCa: String(env.PGSSL_CA_CERT || ''),
    databasePoolMax: readPositiveInteger(env.PG_POOL_MAX, 5, 'PG_POOL_MAX', 20),
    botToken,
    webhookSecret: normalizeWebhookSecret(rawWebhookSecret),
    adminUsername,
    adminPassword,
    adminTelegramId,
    botServiceKey,
    renderApiKey: String(env.RENDER_API_KEY || '').trim(),
    renderWorkspaceId: String(env.RENDER_WORKSPACE_ID || '').trim(),
    renderRuntime,
    publicApiUrl,
    publicAppUrl,
    publicMiniAppUrl,
    telegramGroupUrl,
    telegramChannelUrl,
    allowedOrigins: [...new Set([publicApiUrl, publicAppUrl, ...origins])],
    initDataMaxAgeSeconds: readPositiveInteger(env.TELEGRAM_INITDATA_MAX_AGE_SECONDS, 86400, 'TELEGRAM_INITDATA_MAX_AGE_SECONDS', 604800),
    sessionTtlSeconds: readPositiveInteger(env.SESSION_TTL_SECONDS, 604800, 'SESSION_TTL_SECONDS', 2_592_000)
  };
}
