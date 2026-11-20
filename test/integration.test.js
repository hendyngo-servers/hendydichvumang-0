import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import request from 'supertest';
import { createApp } from '../src/server.js';
import { loadConfig } from '../src/config.js';
import { query, closePool } from '../src/db.js';

const enabled = Boolean(process.env.TEST_DATABASE_URL);
const directory = path.dirname(fileURLToPath(import.meta.url));
let app;
let client;
let csrf = '';
let createdTaskId = '';

test('API auth, CSRF and task queue integration', { skip: !enabled }, async (t) => {
  await t.test('migrations and seed data exist', async () => {
    const init = await readFile(path.resolve(directory, '../migrations/001_init.sql'), 'utf8');
    const seed = await readFile(path.resolve(directory, '../migrations/002_seed_agents.sql'), 'utf8');
    await query(init);
    await query(seed);
    await query('DELETE FROM admin_sessions');
    await query("DELETE FROM tasks WHERE created_by='integration-test'");
    await query("DELETE FROM audit_log WHERE actor='integration-test'");
    await query("DELETE FROM workflows WHERE workflow_key='integration_workflow'");
    await query("DELETE FROM agents WHERE agent_key='agent_test'").catch(() => {});
    const agents = await query('SELECT COUNT(*)::int AS n FROM agents');
    assert.ok(agents.rows[0].n >= 5);
  });

  const config = loadConfig({
    ...process.env,
    NODE_ENV: 'test',
    ADMIN_USERNAME: 'integration-test',
    ADMIN_PASSWORD: 'integration-test-password-long',
    ADMIN_PASSWORD_SALT: 'integration-test-salt-long-enough',
    SESSION_SECRET: 'integration-test-session-secret-long-enough-123',
    MULTI_AGENT_ENABLED: 'false',
  });
  app = createApp({ config });
  client = request.agent(app);

  await t.test('protected route rejects anonymous request', async () => {
    const response = await request(app).get('/api/agents').expect(401);
    assert.match(response.body.error, /đăng nhập/i);
  });

  await t.test('admin login and session lookup return CSRF token', async () => {
    const login = await client.post('/api/auth/login').send({
      username: 'integration-test', password: 'integration-test-password-long',
    }).expect(200);
    csrf = login.body.csrfToken;
    assert.ok(csrf.length > 20);
    const session = await client.get('/api/auth/me').expect(200);
    assert.equal(session.body.username, 'integration-test');
    assert.equal(session.body.csrfToken, csrf);
  });

  await t.test('mutation is rejected without CSRF token', async () => {
    await client.post('/api/tasks').send({
      title: 'Integration task', prompt: 'Integration task prompt', workflowKey: 'research',
    }).expect(403);
  });

  await t.test('Admin can create and inspect a queued task', async () => {
    const created = await client.post('/api/tasks').set('X-CSRF-Token', csrf).send({
      title: 'Integration test task', prompt: 'Create a safe integration-test draft.',
      workflowKey: 'research', contextText: 'Synthetic test context', priority: 50,
    }).expect(201);
    createdTaskId = created.body.task.id;
    assert.equal(created.body.task.status, 'queued');
    const detail = await client.get(`/api/tasks/${createdTaskId}`).expect(200);
    assert.equal(detail.body.task.status, 'queued');
    assert.equal(detail.body.events[0].event_type, 'task.queued');
  });

  await t.test('Admin can create a custom Agent and a sequential workflow', async () => {
    await client.post('/api/agents').set('X-CSRF-Token', csrf).send({
      agentKey: 'agent_test', name: 'Agent kiểm thử', role: 'Test Agent', description: 'Agent only for CI tests',
      systemPrompt: 'Bạn là Agent kiểm thử. Hãy tạo bản nháp ngắn và không thực hiện tác vụ bên ngoài.',
      modelOverride: '', enabled: true, sortOrder: 90,
    }).expect(201);
    const workflow = await client.post('/api/workflows').set('X-CSRF-Token', csrf).send({
      workflowKey: 'integration_workflow', name: 'Integration workflow', description: 'Workflow used only by CI',
      steps: ['agent_test', 'research'], enabled: true,
    }).expect(201);
    assert.deepEqual(workflow.body.workflow.steps, ['agent_test', 'research']);
    const task = await client.post('/api/tasks').set('X-CSRF-Token', csrf).send({
      title: 'Custom agent flow', prompt: 'Test the first configured Agent.', workflowKey: 'integration_workflow',
    }).expect(201);
    assert.equal(task.body.task.agent_key, 'agent_test');
  });

  await t.test('Admin cannot approve a task before it reaches review state', async () => {
    await client.post(`/api/tasks/${createdTaskId}/approve`).set('X-CSRF-Token', csrf).send({ note: 'Not ready' }).expect(409);
  });

  await t.test('dashboard exposes only operational counts', async () => {
    const response = await client.get('/api/dashboard').expect(200);
    assert.ok(response.body.tasks.total >= 1);
    assert.equal(typeof response.body.workerOnline, 'boolean');
    assert.equal(typeof response.body.workerEnabled, 'boolean');
  });
});

after(async () => {
  if (enabled) {
    await query("DELETE FROM tasks WHERE created_by='integration-test'").catch(() => {});
    await query("DELETE FROM workflows WHERE workflow_key='integration_workflow'").catch(() => {});
    await query("DELETE FROM agents WHERE agent_key='agent_test'").catch(() => {});
    await closePool();
  }
});
