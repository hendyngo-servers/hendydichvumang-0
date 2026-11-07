import test from 'node:test';
import assert from 'node:assert/strict';
import { getRenderOverview } from '../src/render-client.js';

function jsonResponse(payload, status = 200) {
  return new Response(JSON.stringify(payload), {
    status,
    headers: { 'content-type': 'application/json' }
  });
}

test('Render inventory is disabled safely without API key', async () => {
  const result = await getRenderOverview({ apiKey: '' });
  assert.equal(result.configured, false);
  assert.equal(result.available, false);
  assert.deepEqual(result.services, []);
});

test('Render inventory returns service/deploy state and never returns secret values', async () => {
  const secret = 'DO_NOT_EXPOSE_RENDER_SECRET';
  const calls = [];
  const fetchImpl = async (url) => {
    const value = String(url);
    calls.push(value);
    if (value.includes('/services?')) {
      return jsonResponse([
        { service: { id: 'srv-api', name: 'hendydichvumang-api', type: 'web_service', branch: 'main', dashboardUrl: 'https://dashboard.render.com/web/srv-api', suspended: 'not_suspended', autoDeployTrigger: 'commit' } },
        { service: { id: 'srv-aiogram', name: 'hendydichvumang-aiogram-bot', type: 'web_service', branch: 'main', dashboardUrl: 'https://dashboard.render.com/web/srv-aiogram', suspended: 'not_suspended' } }
      ]);
    }
    if (value.includes('/srv-api/deploys')) {
      return jsonResponse([{ deploy: { status: 'live', createdAt: '2026-11-05T10:00:00Z', commit: { id: 'abc123', message: 'deploy API' } } }]);
    }
    if (value.includes('/srv-api/env-vars')) {
      return jsonResponse([
        { envVar: { key: 'DATABASE_URL', value: secret } },
        { envVar: { key: 'BOT1_TOKEN', value: secret } },
        { envVar: { key: 'TELEGRAM_WEBHOOK_SECRET', value: secret } },
        { envVar: { key: 'BOT_SERVICE_KEY', value: secret } },
        { envVar: { key: 'ADMIN_PASSWORD', value: secret } },
        { envVar: { key: 'ADMIN_TELEGRAM_ID', value: '123456789' } },
        { envVar: { key: 'PUBLIC_APP_URL', value: 'https://example.com' } }
      ]);
    }
    if (value.includes('/srv-aiogram/deploys')) return jsonResponse([{ status: 'build_in_progress' }]);
    if (value.includes('/srv-aiogram/env-vars')) {
      return jsonResponse([
        { key: 'BOT2_TOKEN', value: secret },
        { key: 'AIOGRAM_WEBHOOK_SECRET', value: secret },
        { key: 'HENDY_API_BASE_URL', value: 'https://api.example.com' },
        { key: 'HENDY_BOT_SERVICE_KEY', value: secret },
        { key: 'DATABASE_URL', value: secret },
        { key: 'PGSSLMODE', value: 'require' }
      ]);
    }
    throw new Error('Unexpected Render URL');
  };

  const result = await getRenderOverview({ apiKey: 'render-key', workspaceId: 'owner-123', fetchImpl });
  assert.equal(result.available, true);
  const api = result.services.find((service) => service.key === 'api');
  const aiogram = result.services.find((service) => service.key === 'aiogram');
  const site = result.services.find((service) => service.key === 'site');
  assert.equal(api.status, 'live');
  assert.deepEqual(api.missingEnv, []);
  assert.equal(aiogram.status, 'build_in_progress');
  assert.deepEqual(aiogram.missingEnv, []);
  assert.equal(site.found, false);
  assert.ok(calls.every((url) => !url.includes(secret)));
  assert.equal(JSON.stringify(result).includes(secret), false);
  assert.equal(JSON.stringify(result).includes('render-key'), false);
});

test('Render API errors are reduced to a safe status message', async () => {
  const result = await getRenderOverview({
    apiKey: 'private-render-key',
    fetchImpl: async () => jsonResponse({ error: 'sensitive detail' }, 401)
  });
  assert.equal(result.configured, true);
  assert.equal(result.available, false);
  assert.match(result.message, /401/);
  assert.equal(JSON.stringify(result).includes('private-render-key'), false);
  assert.equal(JSON.stringify(result).includes('sensitive detail'), false);
});
