import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { generateLocalSecrets } from '../scripts/generate-local-secrets.js';

function parseEnv(content) {
  return Object.fromEntries(content.split(/\r?\n/).filter(Boolean).map((line) => {
    const index = line.indexOf('=');
    return [line.slice(0, index), line.slice(index + 1)];
  }));
}

test('local secret generator creates 256-bit secrets and links Aiogram to the same service key', async () => {
  const dir = await fs.mkdtemp(path.join(os.tmpdir(), 'hendy-env-'));
  const envPath = path.join(dir, '.env');
  const examplePath = path.join(dir, '.env.example');
  await fs.writeFile(examplePath, [
    'TELEGRAM_WEBHOOK_SECRET=',
    'BOT_SERVICE_KEY=replace-with-a-random-32-byte-secret',
    'ADMIN_PASSWORD=',
    'AIOGRAM_WEBHOOK_SECRET=',
    'HENDY_BOT_SERVICE_KEY=',
    'BOT1_TOKEN=',
    'DATABASE_URL=postgresql://placeholder'
  ].join('\n'));

  try {
    const result = await generateLocalSecrets({ envPath, examplePath });
    const content = await fs.readFile(envPath, 'utf8');
    const values = parseEnv(content);
    for (const key of ['TELEGRAM_WEBHOOK_SECRET', 'BOT_SERVICE_KEY', 'ADMIN_PASSWORD', 'AIOGRAM_WEBHOOK_SECRET']) {
      assert.equal(Buffer.from(values[key], 'base64').length, 32, key + ' must contain 32 random bytes');
    }
    assert.equal(values.HENDY_BOT_SERVICE_KEY, values.BOT_SERVICE_KEY);
    assert.equal(values.BOT1_TOKEN, '');
    assert.equal(values.DATABASE_URL, 'postgresql://placeholder');
    assert.ok(result.generated.includes('ADMIN_PASSWORD'));
  } finally {
    await fs.rm(dir, { recursive: true, force: true });
  }
});

test('local secret generator preserves existing values when run a second time', async () => {
  const dir = await fs.mkdtemp(path.join(os.tmpdir(), 'hendy-env-preserve-'));
  const envPath = path.join(dir, '.env');
  const examplePath = path.join(dir, '.env.example');
  await fs.writeFile(examplePath, [
    'TELEGRAM_WEBHOOK_SECRET=',
    'BOT_SERVICE_KEY=',
    'ADMIN_PASSWORD=',
    'AIOGRAM_WEBHOOK_SECRET=',
    'HENDY_BOT_SERVICE_KEY='
  ].join('\n'));

  try {
    await generateLocalSecrets({ envPath, examplePath });
    const first = parseEnv(await fs.readFile(envPath, 'utf8'));
    const result = await generateLocalSecrets({ envPath, examplePath });
    const second = parseEnv(await fs.readFile(envPath, 'utf8'));
    assert.deepEqual(result.generated, []);
    for (const key of ['TELEGRAM_WEBHOOK_SECRET', 'BOT_SERVICE_KEY', 'ADMIN_PASSWORD', 'AIOGRAM_WEBHOOK_SECRET', 'HENDY_BOT_SERVICE_KEY']) {
      assert.equal(first[key], second[key]);
    }
  } finally {
    await fs.rm(dir, { recursive: true, force: true });
  }
});
