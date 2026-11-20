import test from 'node:test';
import assert from 'node:assert/strict';
import { loadConfig } from '../src/config.js';
import { createTaskSchema, agentUpdateSchema, parseBody, makeTaskId } from '../src/validation.js';
import { generateWithOpenAI } from '../src/provider.js';

const baseEnv = {
  NODE_ENV: 'test',
  DATABASE_URL: 'postgresql://test:test@localhost:5432/test',
  ADMIN_USERNAME: 'admin',
  ADMIN_PASSWORD: 'a-test-only-password-long-enough',
  ADMIN_PASSWORD_SALT: 'a-test-only-salt-long-enough',
  SESSION_SECRET: 'a-test-session-secret-that-is-longer-than-thirty-two-characters',
  PGSSLMODE: 'disable',
};

test('config parses defaults and keeps worker disabled until explicitly enabled with a key', () => {
  const config = loadConfig(baseEnv);
  assert.equal(config.MULTI_AGENT_ENABLED, false);
  assert.equal(config.workerEnabled, false);
  assert.equal(config.PORT, 10000);
  const enabled = loadConfig({ ...baseEnv, MULTI_AGENT_ENABLED: 'true', OPENAI_API_KEY: 'test-key' });
  assert.equal(enabled.workerEnabled, true);
});

test('config rejects weak or missing required admin secrets', () => {
  assert.throws(() => loadConfig({ ...baseEnv, SESSION_SECRET: 'short' }), /Invalid environment configuration/);
  assert.throws(() => loadConfig({ ...baseEnv, DATABASE_URL: '' }), /Invalid environment configuration/);
});

test('task and agent schemas enforce limits and strict fields', () => {
  const task = parseBody(createTaskSchema, {
    title: 'Viết kế hoạch nội dung', prompt: 'Lập kế hoạch cho tháng tới', workflowKey: 'content_pipeline',
  });
  assert.equal(task.priority, 50);
  assert.equal(task.contextText, '');
  assert.throws(() => parseBody(createTaskSchema, {
    title: 'OK', prompt: 'short', workflowKey: 'bad key', extra: true,
  }), /Dữ liệu gửi lên không hợp lệ/);
  assert.throws(() => parseBody(agentUpdateSchema, {}), /Dữ liệu gửi lên không hợp lệ/);
});

test('task identifiers are UUIDs', () => {
  assert.match(makeTaskId(), /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i);
});

test('provider adapter parses a successful Responses API response without exposing the key', async () => {
  let requestOptions;
  const fakeFetch = async (url, options) => {
    requestOptions = { url, options };
    return new Response(JSON.stringify({
      model: 'test-model',
      output_text: '  Draft output  ',
      usage: { input_tokens: 12, output_tokens: 34 },
    }), { status: 200, headers: { 'content-type': 'application/json' } });
  };
  const config = {
    OPENAI_API_KEY: 'never-return-this-key', OPENAI_MODEL: 'test-model',
    OPENAI_BASE_URL: 'https://provider.example/v1', OPENAI_TIMEOUT_MS: 1000,
  };
  const result = await generateWithOpenAI({ systemPrompt: 'system', input: 'brief', config, fetchImpl: fakeFetch });
  assert.equal(result.output, 'Draft output');
  assert.equal(result.inputTokens, 12);
  assert.equal(result.outputTokens, 34);
  assert.equal(requestOptions.url, 'https://provider.example/v1/responses');
  assert.equal(requestOptions.options.headers.Authorization, 'Bearer never-return-this-key');
  assert.equal(JSON.stringify(result).includes('never-return-this-key'), false);
});

test('provider adapter fails closed when the API key is missing', async () => {
  await assert.rejects(() => generateWithOpenAI({
    systemPrompt: 'system', input: 'brief',
    config: { OPENAI_API_KEY: '', OPENAI_MODEL: 'test', OPENAI_BASE_URL: 'https://example.com/v1', OPENAI_TIMEOUT_MS: 1000 },
  }), /MODEL_NOT_CONFIGURED/);
});
