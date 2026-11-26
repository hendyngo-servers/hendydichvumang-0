import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const compose = readFileSync('deploy/ubuntu/compose.yaml', 'utf8');
const envExample = readFileSync('deploy/ubuntu/hendy.env.example', 'utf8');
const appEntry = readFileSync('telegram-bot/public/index.php', 'utf8');
const webStart = readFileSync('telegram-bot/docker/start.sh', 'utf8');
const workerStart = readFileSync('telegram-bot/docker/worker.sh', 'utf8');
const dockerfile = readFileSync('telegram-bot/Dockerfile', 'utf8');
const systemd = readFileSync('deploy/ubuntu/systemd/hendy-telegram-bot.service', 'utf8');
const installer = readFileSync('deploy/ubuntu/install-service.sh', 'utf8');
const updater = readFileSync('deploy/ubuntu/update.sh', 'utf8');
const rollback = readFileSync('deploy/ubuntu/rollback.sh', 'utf8');
const guide = readFileSync('deploy/ubuntu/README.md', 'utf8');
const envSchema = JSON.parse(readFileSync('telegram-bot/config/env.schema.json', 'utf8'));

test('Ubuntu Compose serves a single-origin application and does not publish Apache directly', () => {
  assert.match(compose, /hendy-app:/);
  assert.match(compose, /caddy:/);
  assert.match(compose, /80:80/);
  assert.match(compose, /443:443/);
  assert.match(compose, /expose:[\s\S]*?10000/);
  assert.doesNotMatch(compose, /ports:[\s\S]*?10000:/);
  assert.match(compose, /WEBSITE_PUBLIC_URL:/);
  assert.match(compose, /HENDY_INLINE_WORKERS: "false"/);
  assert.match(compose, /header_up Host \{http.request.host\}/) === false; // Caddy config is validated in its dedicated assertion below.
});

test('Caddy preserves the public Website hostname and configures HTTPS hardening', () => {
  const caddy = readFileSync('deploy/ubuntu/Caddyfile', 'utf8');
  assert.match(caddy, /reverse_proxy hendy-app:10000/);
  assert.match(caddy, /header_up Host \{http.request.host\}/);
  assert.match(caddy, /Strict-Transport-Security/);
  assert.match(caddy, /-Server/);
});

test('queue workers are separate, opt-in and run without root', () => {
  assert.match(compose, /hendy-worker:/);
  assert.match(compose, /profiles:\s*\["workers"\]/);
  assert.match(compose, /user:\s*"www-data"/);
  assert.match(compose, /docker\/worker\.sh/);
  assert.match(dockerfile, /chmod \+x \/var\/www\/app\/docker\/start\.sh \/var\/www\/app\/docker\/worker\.sh/);
  assert.match(webStart, /HENDY_INLINE_WORKERS/);
  assert.match(workerStart, /multi-agent-worker\.php/);
  assert.match(workerStart, /ai-worker\.php/);
  assert.match(workerStart, /video-worker\.php/);
  assert.match(workerStart, /code-telegram-worker\.php/);
});

test('production VPS env is safe by default and shares one HTTPS origin', () => {
  assert.match(envExample, /^HENDY_PUBLIC_DOMAIN=bot\.example\.com$/m);
  assert.match(envExample, /^WEBSITE_PUBLIC_URL=https:\/\/bot\.example\.com$/m);
  assert.match(envExample, /^ALLOWED_ORIGINS=https:\/\/bot\.example\.com$/m);
  assert.match(envExample, /^RUN_MIGRATIONS_ON_START=false$/m);
  assert.match(envExample, /^WEBHOOK_AUTO_REGISTER=false$/m);
  assert.match(envExample, /^HENDY_INLINE_WORKERS=false$/m);
  assert.match(envExample, /^COMPOSE_PROFILES=$/m);
  assert.match(envExample, /^BANK_WEBHOOK_ENABLED=false$/m);
  assert.match(envExample, /^MULTI_AGENT_ENABLED=false$/m);
  assert.match(envExample, /^DATABASE_URL=$/m);
  assert.ok(envSchema.variables.APP_URL);
  assert.ok(envSchema.variables.WEBSITE_PUBLIC_URL);
  assert.ok(envSchema.variables.MULTI_AGENT_ENABLED);
  assert.doesNotMatch(envExample, /\b[0-9]{8,12}:[A-Za-z0-9_-]{30,}\b/);
});

test('PHP serves local Website assets on the same VPS origin without a redirect loop', () => {
  assert.match(appEntry, /sameWebsiteHost/);
  assert.match(appEntry, /website\/public/);
  assert.match(appEntry, /str_starts_with\(\$candidate, \$rootPrefix\)/);
  assert.match(appEntry, /\$reservedPrefixes/);
  assert.match(appEntry, /str_contains\(\$relativePath, "\\0"\)/);
  assert.match(appEntry, /websiteUrl/);
});

test('systemd and operator scripts use the target repository and safe update/rollback paths', () => {
  assert.match(installer, /\/opt\/hendydichvumang-0/);
  assert.match(installer, /WEBHOOK_AUTO_REGISTER/);
  assert.match(installer, /HENDY_INLINE_WORKERS/);
  assert.match(systemd, /docker compose --env-file hendy\.env up -d --remove-orphans/);
  assert.match(updater, /merge --ff-only origin\/main/);
  assert.match(updater, /--wait-timeout 180/);
  assert.match(rollback, /reset --hard "\$TARGET"/);
  assert.match(guide, /hendyngo-servers\/hendydichvumang-0\.git/);
  assert.match(guide, /rollback\.sh/);
});

test('VPS guide explicitly protects the existing PostgreSQL data and webhook ownership', () => {
  assert.match(guide, /đúng PostgreSQL database HENDY đang sử dụng/i);
  assert.match(guide, /RUN_MIGRATIONS_ON_START=false/);
  assert.match(guide, /WEBHOOK_AUTO_REGISTER=false/);
  assert.match(guide, /không tạo database ví\/đơn hàng thứ hai/i);
});
