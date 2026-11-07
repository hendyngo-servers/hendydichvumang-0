import test from 'node:test';
import assert from 'node:assert/strict';
import { getRenderOverview, renderServiceDefinitions } from '../src/render-client.js';

function jsonResponse(payload, status = 200) {
  return new Response(JSON.stringify(payload), {
    status,
    headers: { 'content-type': 'application/json' }
  });
}

const repo = 'https://github.com/hendyngo-servers/hendydichvumang-0';

test('Render inventory is disabled safely without API key', async () => {
  const result = await getRenderOverview({ apiKey: '' });
  assert.equal(result.configured, false);
  assert.equal(result.available, false);
  assert.deepEqual(result.services, []);
});

test('service definitions use the current bot token variable names', () => {
  const definitions = renderServiceDefinitions();
  const api = definitions.find((service) => service.key === 'api');
  const aiogram = definitions.find((service) => service.key === 'aiogram');
  assert.ok(api.requiredEnv.includes('BOT1_TOKEN'));
  assert.ok(!api.requiredEnv.includes('BOT_TOKEN'));
  assert.ok(aiogram.requiredEnv.includes('BOT2_TOKEN'));
  assert.ok(!aiogram.requiredEnv.includes('AIOGRAM_BOT_TOKEN'));
});

test('Render inventory auto-detects workspace and custom domains without exposing secret values', async () => {
  const secret = 'DO_NOT_EXPOSE_RENDER_SECRET';
  const calls = [];
  const services = [
    { id: 'srv-other', name: 'hendydichvumang-api', type: 'web_service', ownerId: 'tea-other', repo: 'https://github.com/other/repo' },
    { id: 'srv-site', name: 'hendydichvumang-site', type: 'static_site', ownerId: 'tea-hendy', repo, serviceDetails: { url: 'https://hendy-site.onrender.com' }, dashboardUrl: 'https://dashboard.render.com/static/srv-site', suspended: 'not_suspended' },
    { id: 'srv-api', name: 'hendydichvumang-api', type: 'web_service', ownerId: 'tea-hendy', repo, serviceDetails: { url: 'https://hendy-api.onrender.com' }, dashboardUrl: 'https://dashboard.render.com/web/srv-api', suspended: 'not_suspended', autoDeployTrigger: 'commit' },
    { id: 'srv-aiogram', name: 'hendydichvumang-aiogram-bot', type: 'web_service', ownerId: 'tea-hendy', repo, serviceDetails: { url: 'https://hendy-aiogram.onrender.com' }, dashboardUrl: 'https://dashboard.render.com/web/srv-aiogram', suspended: 'not_suspended' }
  ];
  const fetchImpl = async (url) => {
    const value = String(url);
    calls.push(value);
    if (value.includes('/services?')) return jsonResponse(services.map((service) => ({ service })));
    if (value.includes('/custom-domains')) {
      if (value.includes('/srv-site/')) return jsonResponse([
        { customDomain: { name: 'hendy.example', domainType: 'apex', verificationStatus: 'verified' } }
      ]);
      return jsonResponse([]);
    }
    if (value.includes('/deploys')) {
      const id = value.includes('/srv-site/') ? 'site' : value.includes('/srv-api/') ? 'api' : 'aiogram';
      return jsonResponse([{ deploy: { status: id === 'aiogram' ? 'build_in_progress' : 'live', createdAt: '2026-11-05T10:00:00Z', commit: { id: 'abc123', message: 'deploy ' + id } } }]);
    }
    if (value.includes('/env-vars')) {
      const apiKeys = [
        'DATABASE_URL', 'BOT1_TOKEN', 'TELEGRAM_WEBHOOK_SECRET', 'BOT_SERVICE_KEY',
        'ADMIN_PASSWORD', 'ADMIN_TELEGRAM_ID', 'PUBLIC_APP_URL', 'PUBLIC_MINI_APP_URL'
      ];
      const aiogramKeys = [
        'BOT2_TOKEN', 'AIOGRAM_WEBHOOK_SECRET', 'HENDY_API_BASE_URL',
        'HENDY_BOT_SERVICE_KEY', 'DATABASE_URL', 'PGSSLMODE',
        'PUBLIC_APP_URL', 'PUBLIC_MINI_APP_URL'
      ];
      const keys = value.includes('/srv-api/') ? apiKeys : value.includes('/srv-aiogram/') ? aiogramKeys : ['STATIC_API_BASE_URL'];
      return jsonResponse(keys.map((key) => ({ envVar: { key, value: secret } })));
    }
    throw new Error('Unexpected Render URL');
  };

  const result = await getRenderOverview({ apiKey: 'render-key', fetchImpl });
  assert.equal(result.available, true);
  assert.equal(result.workspaceId, 'tea-hendy');
  assert.equal(result.workspaceAutoDetected, true);
  const api = result.services.find((service) => service.key === 'api');
  const aiogram = result.services.find((service) => service.key === 'aiogram');
  const site = result.services.find((service) => service.key === 'site');
  assert.equal(api.status, 'live');
  assert.deepEqual(api.missingEnv, []);
  assert.equal(aiogram.status, 'build_in_progress');
  assert.deepEqual(aiogram.missingEnv, []);
  assert.equal(site.url, 'https://hendy-site.onrender.com');
  assert.deepEqual(site.customDomains.map((domain) => domain.name), ['hendy.example']);
  assert.ok(calls.some((url) => url.includes('/custom-domains')));
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
