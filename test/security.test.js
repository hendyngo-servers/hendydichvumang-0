import test from 'node:test';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import {
  safeEqual, verifyTelegramInitData, createSessionToken, hashSessionToken,
  verifyWebhookSecret, normalizeWebhookSecret
} from '../src/security.js';
import { loadConfig } from '../src/config.js';

function buildInitData(botToken, user, authDate) {
  const values = new URLSearchParams({
    auth_date: String(authDate),
    query_id: 'AAEAAAE',
    user: JSON.stringify(user)
  });
  const dataCheck = [...values.entries()]
    .sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))
    .map(([key, value]) => key + '=' + value).join('\n');
  const secret = crypto.createHmac('sha256', 'WebAppData').update(botToken).digest();
  values.set('hash', crypto.createHmac('sha256', secret).update(dataCheck).digest('hex'));
  return values.toString();
}

test('safeEqual compares values without accepting different lengths', () => {
  assert.equal(safeEqual('abc', 'abc'), true);
  assert.equal(safeEqual('abc', 'abd'), false);
  assert.equal(safeEqual('abc', 'abcd'), false);
});

test('Telegram initData accepts a valid HMAC and user', () => {
  const token = '123456:TEST_TOKEN';
  const user = { id: 123456, first_name: 'Hendy', username: 'hendy_user' };
  const result = verifyTelegramInitData(buildInitData(token, user, 1800000000), token, 3600, 1800000100);
  assert.equal(result.ok, true);
  assert.equal(result.user.id, user.id);
});

test('Telegram initData rejects altered user data', () => {
  const token = '123456:TEST_TOKEN';
  const payload = new URLSearchParams(buildInitData(token, { id: 321, first_name: 'User' }, 1800000000));
  payload.set('user', JSON.stringify({ id: 321, first_name: 'Admin' }));
  assert.equal(verifyTelegramInitData(payload.toString(), token, 3600, 1800000100).ok, false);
});

test('Telegram initData rejects duplicate keys', () => {
  const token = '123456:TEST_TOKEN';
  const valid = buildInitData(token, { id: 321, first_name: 'User' }, 1800000000);
  assert.equal(verifyTelegramInitData(valid + '&auth_date=1800000000', token, 3600, 1800000100).reason, 'duplicate');
});

test('Telegram initData rejects expired, future, malformed and unsafe user ids', () => {
  const token = '123456:TEST_TOKEN';
  assert.equal(verifyTelegramInitData(buildInitData(token, { id: 321, first_name: 'User' }, 1700000000), token, 60, 1800000000).reason, 'expired');
  assert.equal(verifyTelegramInitData(buildInitData(token, { id: 321, first_name: 'User' }, 1800000500), token, 3600, 1800000000).reason, 'expired');
  assert.equal(verifyTelegramInitData(buildInitData(token, { id: '321', first_name: 'User' }, 1800000000), token, 3600, 1800000100).reason, 'user');
});

test('session tokens are random and only their hashes are stored', () => {
  const a = createSessionToken();
  const b = createSessionToken();
  assert.notEqual(a, b);
  assert.match(a, /^[A-Za-z0-9_-]{43}$/);
  assert.match(hashSessionToken(a), /^[a-f0-9]{64}$/);
  assert.equal(hashSessionToken(a), hashSessionToken(a));
});

test('webhook secret is normalized to Telegram-safe URL-safe characters', () => {
  const normalized = normalizeWebhookSecret('base64+/=render-generated-secret');
  assert.match(normalized, /^[A-Za-z0-9_-]{43}$/);
  assert.equal(normalizeWebhookSecret(''), '');
  assert.equal(verifyWebhookSecret(normalized, normalized), true);
  assert.equal(verifyWebhookSecret(normalized, 'wrong'), false);
  assert.equal(verifyWebhookSecret('', normalized), false);
});

test('production config requires secure admin password and database URL', () => {
  assert.throws(() => loadConfig({ NODE_ENV: 'production', DATABASE_URL: 'postgres://user:pass@host/db' }), /ADMIN_PASSWORD/);
  assert.throws(() => loadConfig({ NODE_ENV: 'production', ADMIN_PASSWORD: 'a'.repeat(16) }), /DATABASE_URL/);
});

test('production config accepts valid Render settings and normalizes origins', () => {
  const config = loadConfig({
    NODE_ENV: 'production',
    DATABASE_URL: 'postgresql://user:pass@db.example.com/app',
    ADMIN_USERNAME: 'owner',
    ADMIN_PASSWORD: 'a'.repeat(20),
    BOT1_TOKEN: '123456:token',
    TELEGRAM_WEBHOOK_SECRET: 'render-generated-webhook-secret-32bytes-minimum',
    RENDER_EXTERNAL_URL: 'https://hendy.onrender.com',
    ALLOWED_ORIGINS: 'https://hendy.onrender.com, https://shop.example.com/'
  });
  assert.equal(config.publicAppUrl, 'https://hendy.onrender.com');
  assert.deepEqual(config.allowedOrigins, ['https://hendy.onrender.com', 'https://shop.example.com']);
  assert.equal(config.isProduction, true);
});
