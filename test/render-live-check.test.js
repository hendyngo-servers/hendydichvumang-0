import test from 'node:test';
import assert from 'node:assert/strict';
import {
  normalizeRepository,
  normalizeHttpsOrigin,
  unwrapService,
  unwrapLatestDeploy,
  normalizeCustomDomains,
  validateService,
  validateDeploy,
  findWorkspaceService,
  validateAiogramHealth
} from '../scripts/render-live-check.js';

const repository = 'https://github.com/hendyngo-servers/hendydichvumang-0';

test('repository URL normalization removes .git and trailing slash', () => {
  assert.equal(normalizeRepository(repository + '.git/'), 'github.com/hendyngo-servers/hendydichvumang-0');
});

test('public URLs must use HTTPS and must not contain credentials', () => {
  assert.equal(normalizeHttpsOrigin('https://site.example.com/path/'), 'https://site.example.com');
  assert.throws(() => normalizeHttpsOrigin('http://site.example.com'), /HTTPS/);
  assert.throws(() => normalizeHttpsOrigin('https://user:pass@site.example.com'), /HTTPS/);
});

test('Render API service response accepts direct and wrapped service objects', () => {
  const service = { id: 'srv-123', type: 'static_site' };
  assert.deepEqual(unwrapService(service), service);
  assert.deepEqual(unwrapService({ service }), service);
  assert.throws(() => unwrapService({}), /service/);
});

test('Render API deploy response accepts wrapped list items', () => {
  const deploy = { id: 'dep-123', status: 'live' };
  assert.deepEqual(unwrapLatestDeploy([{ deploy, cursor: 'next' }]), deploy);
  assert.deepEqual(unwrapLatestDeploy([deploy]), deploy);
  assert.throws(() => unwrapLatestDeploy([]), /chưa có deployment/i);
});

test('service metadata validates ID, workspace, type, repo and branch', () => {
  const service = {
    id: 'srv-123',
    ownerId: 'tea-123',
    name: 'hendydichvumang-site',
    type: 'static_site',
    branch: 'main',
    repo: repository + '.git',
    suspended: 'not_suspended',
    serviceDetails: { url: 'https://site.onrender.com' }
  };
  const expected = {
    id: 'srv-123',
    workspaceId: 'tea-123',
    name: 'hendydichvumang-site',
    type: 'static_site',
    branch: 'main',
    repository
  };
  assert.equal(validateService(service, expected), 'https://site.onrender.com');
  assert.equal(validateService(service, { ...expected, workspaceId: undefined }), 'https://site.onrender.com');
  assert.throws(() => validateService({ ...service, branch: 'dev' }, expected), /nhánh/);
  assert.throws(() => validateService({ ...service, ownerId: 'tea-other' }, expected), /workspace/);
  assert.throws(() => validateService({ ...service, repo: 'https://github.com/other/repo' }, expected), /repository/);
});

test('service discovery infers workspace from unique repository-matched service metadata', () => {
  const payload = [
    {
      service: {
        id: 'srv-wrong',
        name: 'hendydichvumang-site',
        type: 'static_site',
        ownerId: 'tea-other',
        repo: 'https://github.com/someone/another-project'
      }
    },
    {
      service: {
        id: 'srv-site',
        name: 'hendydichvumang-site',
        type: 'static_site',
        ownerId: 'tea-hendy',
        repo: repository
      }
    },
    {
      service: {
        id: 'srv-api',
        name: 'hendydichvumang-api',
        type: 'web_service',
        ownerId: 'tea-hendy',
        repo: repository
      }
    }
  ];
  const found = findWorkspaceService(payload, {
    name: 'hendydichvumang-site',
    type: 'static_site',
    repository
  });
  assert.equal(found.id, 'srv-site');
  assert.equal(found.ownerId, 'tea-hendy');
  assert.equal(findWorkspaceService(payload, {
    name: 'hendydichvumang-api',
    type: 'web_service',
    workspaceId: found.ownerId,
    repository
  }).id, 'srv-api');
});

test('service discovery refuses ambiguous same-repository services across workspaces', () => {
  const service = {
    name: 'hendydichvumang-api',
    type: 'web_service',
    repo: repository
  };
  assert.throws(() => findWorkspaceService([
    { service: { ...service, id: 'srv-1', ownerId: 'tea-1' } },
    { service: { ...service, id: 'srv-2', ownerId: 'tea-2' } }
  ], {
    name: service.name,
    type: service.type,
    repository
  }), /RENDER_WORKSPACE_ID/);
});

test('custom domain discovery normalizes Render domain records and orders verified names first', () => {
  const domains = normalizeCustomDomains([
    {
      customDomain: {
        name: 'www.hendy.example',
        domainType: 'subdomain',
        verificationStatus: 'unverified',
        redirectForName: null
      }
    },
    {
      customDomain: {
        name: 'hendy.example',
        domainType: 'apex',
        verificationStatus: 'verified',
        redirectForName: null
      }
    }
  ]);
  assert.equal(domains[0].name, 'hendy.example');
  assert.equal(domains[0].verificationStatus, 'verified');
  assert.equal(domains[1].name, 'www.hendy.example');
});

test('deployment must be Live and match the requested commit', () => {
  assert.equal(validateDeploy({ status: 'live', commit: { id: 'abc123' } }, 'abc123'), true);
  assert.throws(() => validateDeploy({ status: 'build_in_progress' }), /chưa Live/);
  assert.throws(() => validateDeploy({ status: 'live', commit: { id: 'old' } }, 'new'), /chưa chạy commit/);
});

test('Aiogram health is accepted only after database and webhook are ready', () => {
  assert.equal(validateAiogramHealth({
    status: 'ok',
    database: 'ready',
    webhook: 'ready',
    framework: 'aiogram',
    bot_id: '900001'
  }), true);
  assert.throws(() => validateAiogramHealth({
    status: 'starting',
    database: 'ready',
    webhook: 'not_ready',
    framework: 'aiogram'
  }), /chưa sẵn sàng/);
  assert.throws(() => validateAiogramHealth({
    status: 'ok',
    database: 'unavailable',
    webhook: 'ready',
    framework: 'aiogram'
  }), /PostgreSQL/);
  assert.throws(() => validateAiogramHealth({
    status: 'ok',
    database: 'ready',
    webhook: 'not_ready',
    framework: 'aiogram'
  }), /webhook/);
});
