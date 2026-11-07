import test from 'node:test';
import assert from 'node:assert/strict';
import { loadConfig } from '../src/config.js';

test('production config requires an admin password', () => {
  assert.throws(() => loadConfig({ NODE_ENV: 'production', DATABASE_URL: 'postgres://db' }), /ADMIN_PASSWORD/);
});

test('production config requires webhook secret when a bot token is configured', () => {
  assert.throws(() => loadConfig({
    NODE_ENV: 'production',
    DATABASE_URL: 'postgres://db',
    ADMIN_PASSWORD: 'long-strong-password',
    BOT1_TOKEN: '123:token',
    RENDER_EXTERNAL_URL: 'https://hendy.onrender.com'
  }), /TELEGRAM_WEBHOOK_SECRET/);
});

test('production config separates API URL and Static Site URL', () => {
  const config = loadConfig({
    NODE_ENV: 'production',
    DATABASE_URL: 'postgres://db',
    ADMIN_USERNAME: 'ops',
    ADMIN_PASSWORD: 'long-strong-password',
    BOT1_TOKEN: '123:token',
    TELEGRAM_WEBHOOK_SECRET: 'render-generated+/=secret',
    RENDER_EXTERNAL_URL: 'https://api-hendy.onrender.com',
    PUBLIC_APP_URL: 'https://hendy-customer.onrender.com'
  });
  assert.equal(config.publicApiUrl, 'https://api-hendy.onrender.com');
  assert.equal(config.publicAppUrl, 'https://hendy-customer.onrender.com');
  assert.equal(config.publicMiniAppUrl, 'https://hendy-customer.onrender.com/app');
  assert.deepEqual(config.allowedOrigins, ['https://api-hendy.onrender.com', 'https://hendy-customer.onrender.com']);
  assert.match(config.webhookSecret, /^[A-Za-z0-9_-]{43}$/);
  assert.equal(config.databaseSslMode, 'require');
});

test('external PostgreSQL URL and TLS verification options are accepted', () => {
  const config = loadConfig({
    NODE_ENV: 'production',
    EXTERNAL_DATABASE_URL: 'postgresql://user:password@db.example.com:5432/app',
    PGSSLMODE: 'verify-full',
    PGSSL_CA_CERT: '-----BEGIN CERTIFICATE-----test',
    PG_POOL_MAX: '10',
    ADMIN_USERNAME: 'owner',
    ADMIN_PASSWORD: 'long-unique-password-123',
    PUBLIC_API_URL: 'https://api.example.com',
    PUBLIC_APP_URL: 'https://app.example.com',
    ALLOWED_ORIGINS: 'https://custom.example.com/'
  });
  assert.equal(config.databaseUrl, 'postgresql://user:password@db.example.com:5432/app');
  assert.equal(config.databaseSslMode, 'verify-full');
  assert.equal(config.databaseSslCa, '-----BEGIN CERTIFICATE-----test');
  assert.equal(config.databasePoolMax, 10);
  assert.deepEqual(config.allowedOrigins, ['https://api.example.com', 'https://app.example.com', 'https://custom.example.com']);
});

test('invalid SSL mode and invalid external database URLs are rejected', () => {
  assert.throws(() => loadConfig({ DATABASE_URL: 'postgresql://localhost/app', PGSSLMODE: 'disabled' }), /PGSSLMODE/);
  assert.throws(() => loadConfig({ DATABASE_URL: 'https://example.com/db' }), /PostgreSQL URL/);
});

test('development config defaults to local URL and does not require admin secret', () => {
  const config = loadConfig({ DATABASE_URL: 'postgres://localhost/db' });
  assert.equal(config.publicApiUrl, 'http://localhost:10000');
  assert.equal(config.publicAppUrl, 'http://localhost:10000');
  assert.equal(config.publicMiniAppUrl, 'http://localhost:10000/app');
  assert.deepEqual(config.allowedOrigins, ['http://localhost:10000']);
  assert.equal(config.databaseSslMode, 'disable');
});

test('production Mini App URL must use HTTPS when Telegram Bot is enabled', () => {
  assert.throws(() => loadConfig({
    NODE_ENV: 'production',
    DATABASE_URL: 'postgres://db',
    ADMIN_USERNAME: 'owner',
    ADMIN_PASSWORD: 'long-unique-password-123',
    BOT1_TOKEN: '123:token',
    TELEGRAM_WEBHOOK_SECRET: 'render-generated-secret',
    PUBLIC_API_URL: 'https://api.example.com',
    PUBLIC_APP_URL: 'http://customer.example.com'
  }), /PUBLIC_APP_URL.*HTTPS/);
});

test('BOT_SERVICE_KEY remains optional for grammY-only mode but rejects placeholders and weak values', () => {
  const base = {
    NODE_ENV: 'production',
    DATABASE_URL: 'postgres://db',
    ADMIN_USERNAME: 'owner',
    ADMIN_PASSWORD: 'long-unique-password-123',
    PUBLIC_API_URL: 'https://api.example.com',
    PUBLIC_APP_URL: 'https://app.example.com'
  };
  assert.equal(loadConfig(base).botServiceKey, '');
  assert.equal(loadConfig({ ...base, BOT_SERVICE_KEY: 'x'.repeat(48) }).botServiceKey, 'x'.repeat(48));
  assert.throws(() => loadConfig({ ...base, BOT_SERVICE_KEY: 'replace-with-a-random-32-byte-secret' }), /BOT_SERVICE_KEY.*ngẫu nhiên/);
  assert.throws(() => loadConfig({ ...base, BOT_SERVICE_KEY: 'too-short' }), /BOT_SERVICE_KEY.*ngẫu nhiên/);
});

test('blank PUBLIC_MINI_APP_URL is derived from the detected Static Site origin', () => {
  const config = loadConfig({
    NODE_ENV: 'production',
    DATABASE_URL: 'postgres://db',
    ADMIN_USERNAME: 'owner',
    ADMIN_PASSWORD: 'long-unique-password-123',
    RENDER_EXTERNAL_URL: 'https://hendy-api.onrender.com',
    PUBLIC_APP_URL: 'https://hendy-site.onrender.com',
    PUBLIC_MINI_APP_URL: ''
  });
  assert.equal(config.publicApiUrl, 'https://hendy-api.onrender.com');
  assert.equal(config.publicAppUrl, 'https://hendy-site.onrender.com');
  assert.equal(config.publicMiniAppUrl, 'https://hendy-site.onrender.com/app');
});

test('Mini App URL must remain on the configured Static Site origin', () => {
  assert.throws(() => loadConfig({
    DATABASE_URL: 'postgres://localhost/db',
    PUBLIC_APP_URL: 'https://hendy-site.onrender.com',
    PUBLIC_MINI_APP_URL: 'https://other-site.onrender.com/app'
  }), /cùng origin/);
  assert.throws(() => loadConfig({
    DATABASE_URL: 'postgres://localhost/db',
    PUBLIC_APP_URL: 'https://hendy-site.onrender.com',
    PUBLIC_MINI_APP_URL: 'https://hendy-site.onrender.com/app?token=secret'
  }), /query/);
});

test('production accepts an ADMIN_PASSWORD of exactly 13 characters', () => {
  const config = loadConfig({
    NODE_ENV: 'production',
    DATABASE_URL: 'postgres://db',
    ADMIN_USERNAME: 'hendyngo',
    ADMIN_PASSWORD: 'a'.repeat(13)
  });
  assert.equal(config.adminPassword.length, 13);
});

test('production rejects an ADMIN_PASSWORD shorter than 13 characters', () => {
  assert.throws(() => loadConfig({
    NODE_ENV: 'production',
    DATABASE_URL: 'postgres://db',
    ADMIN_PASSWORD: 'a'.repeat(12)
  }), /ít nhất 13 ký tự/);
});

test('BOT1_TOKEN takes precedence and BOT_TOKEN remains a temporary alias', () => {
  const preferred = loadConfig({
    DATABASE_URL: 'postgres://localhost/db',
    BOT1_TOKEN: '111:primary-new',
    BOT_TOKEN: '222:primary-legacy',
    TELEGRAM_WEBHOOK_SECRET: 'test-webhook-secret'
  });
  assert.equal(preferred.botToken, '111:primary-new');
  const legacy = loadConfig({
    DATABASE_URL: 'postgres://localhost/db',
    BOT_TOKEN: '222:primary-legacy',
    TELEGRAM_WEBHOOK_SECRET: 'test-webhook-secret'
  });
  assert.equal(legacy.botToken, '222:primary-legacy');
});

test('Telegram group and channel links must be HTTPS t.me links', () => {
  const config = loadConfig({
    DATABASE_URL: 'postgres://localhost/db',
    TELEGRAM_GROUP_URL: 'https://t.me/hendy_group',
    TELEGRAM_CHANNEL_URL: 'https://t.me/hendy_channel'
  });
  assert.equal(config.telegramGroupUrl, 'https://t.me/hendy_group');
  assert.equal(config.telegramChannelUrl, 'https://t.me/hendy_channel');
  assert.throws(() => loadConfig({
    DATABASE_URL: 'postgres://localhost/db',
    TELEGRAM_GROUP_URL: 'https://evil.example/group'
  }), /t.me hoặc telegram.me/);
});
