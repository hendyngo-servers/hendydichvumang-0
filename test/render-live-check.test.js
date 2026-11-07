import test from 'node:test';
import assert from 'node:assert/strict';
import {
  normalizeRepository, normalizeHttpsOrigin, unwrapService, unwrapLatestDeploy,
  validateService, validateDeploy, findWorkspaceService, selectWorkspaceId, fetchAllRenderOwners, validateAiogramHealth
} from '../scripts/render-live-check.js';

test('repository URL normalization removes .git and trailing slash', () => {
  assert.equal(normalizeRepository('https://github.com/hendyngo-servers/hendydichvumang.git/'), 'github.com/hendyngo-servers/hendydichvumang');
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
test('service metadata must match ID, workspace, type, repo, and branch', () => {
  const service = {
    id: 'srv-123', ownerId: 'tea-123', name: 'hendydichvumang-site',
    type: 'static_site', branch: 'main',
    repo: 'https://github.com/hendyngo-servers/hendydichvumang.git',
    suspended: 'not_suspended', serviceDetails: { url: 'https://site.onrender.com' }
  };
  const expected = {
    id: 'srv-123', workspaceId: 'tea-123', name: 'hendydichvumang-site',
    type: 'static_site', branch: 'main',
    repository: 'https://github.com/hendyngo-servers/hendydichvumang'
  };
  assert.equal(validateService(service, expected), 'https://site.onrender.com');
  assert.throws(() => validateService({ ...service, branch: 'dev' }, expected), /nhánh/);
  assert.throws(() => validateService({ ...service, ownerId: 'tea-other' }, expected), /workspace/);
});
test('deployment must be Live and match the requested commit', () => {
  assert.equal(validateDeploy({ status: 'live', commit: { id: 'abc123' } }, 'abc123'), true);
  assert.throws(() => validateDeploy({ status: 'build_in_progress' }), /chưa Live/);
  assert.throws(() => validateDeploy({ status: 'live', commit: { id: 'old' } }, 'new'), /chưa chạy commit/);
});

test('service discovery finds the expected service inside the selected workspace', () => {
  const payload = [
    { service: { id: 'srv-site', name: 'hendydichvumang-site', type: 'static_site', ownerId: 'tea-1' }, cursor: 'c1' },
    { service: { id: 'srv-api', name: 'hendydichvumang-api', type: 'web_service', ownerId: 'tea-1' }, cursor: 'c2' },
    { service: { id: 'srv-other', name: 'other-api', type: 'web_service', ownerId: 'tea-2' }, cursor: 'c3' }
  ];
  const found = findWorkspaceService(payload, { name: 'hendydichvumang-api', type: 'web_service', workspaceId: 'tea-1' });
  assert.equal(found.id, 'srv-api');
  assert.throws(
    () => findWorkspaceService(payload, { name: 'missing-api', type: 'web_service', workspaceId: 'tea-1' }),
    /Không tìm thấy service.*hendydichvumang-api/i
  );
});

test('aiogram health is accepted only after database and webhook are ready', () => {
  assert.equal(validateAiogramHealth({ status: 'ok', database: 'ready', webhook: 'ready', framework: 'aiogram', bot_id: '900001' }), true);
  assert.throws(() => validateAiogramHealth({ status: 'starting', database: 'ready', webhook: 'not_ready', framework: 'aiogram' }), /chưa sẵn sàng/);
  assert.throws(() => validateAiogramHealth({ status: 'ok', database: 'unavailable', webhook: 'ready', framework: 'aiogram' }), /PostgreSQL/);
  assert.throws(() => validateAiogramHealth({ status: 'ok', database: 'ready', webhook: 'not_ready', framework: 'aiogram' }), /webhook/);
});


test('Render workspace ID is auto-detected when exactly one team workspace is accessible', () => {
  const owners = [
    { owner: { id: 'own-personal', type: 'user', name: 'Personal' }, cursor: 'next' },
    { owner: { id: 'tea-hendy', type: 'team', name: 'HENDY Workspace' }, cursor: null }
  ];
  assert.equal(selectWorkspaceId(owners), 'tea-hendy');
  assert.equal(selectWorkspaceId([{ owner: { id: 'own-only', type: 'user' } }]), 'own-only');
  assert.equal(selectWorkspaceId(owners, 'tea-hendy'), 'tea-hendy');
  assert.throws(() => selectWorkspaceId([
    { owner: { id: 'tea-a', type: 'team' } },
    { owner: { id: 'tea-b', type: 'team' } }
  ]), /nhiều workspace/);
  assert.throws(() => selectWorkspaceId(owners, 'tea-other'), /không nằm trong danh sách/);
});


test('Render workspace discovery reads all owner pages and stops at the final cursor', async () => {
  const firstPage = Array.from({ length: 100 }, (_, index) => ({
    owner: { id: 'owner-' + index, type: index === 99 ? 'team' : 'user' },
    cursor: index === 99 ? 'cursor-page-2' : 'cursor-' + index
  }));
  const secondPage = [
    { owner: { id: 'tea-hendy', type: 'team' }, cursor: null }
  ];
  const calls = [];
  const owners = await fetchAllRenderOwners('test-api-key', async (_apiKey, path) => {
    calls.push(path);
    return path.includes('cursor=') ? secondPage : firstPage;
  });

  assert.equal(owners.length, 101);
  assert.equal(selectWorkspaceId(owners), 'tea-hendy');
  assert.equal(calls.length, 2);
  assert.match(calls[0], /limit=100/);
  assert.match(calls[1], /cursor=cursor-page-2/);
});
