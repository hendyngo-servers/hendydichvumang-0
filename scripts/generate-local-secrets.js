import { randomBytes } from 'node:crypto';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SECRET_KEYS = [
  'TELEGRAM_WEBHOOK_SECRET',
  'BOT_SERVICE_KEY',
  'ADMIN_PASSWORD',
  'AIOGRAM_WEBHOOK_SECRET'
];
const PLACEHOLDER_PREFIXES = ['replace-with', 'change-me', 'changeme', 'placeholder'];

function readEnvValue(lines, key) {
  const line = lines.find((entry) => new RegExp('^\\s*' + key + '\\s*=').test(entry));
  if (line === undefined) return '';
  const value = line.slice(line.indexOf('=') + 1).trim();
  return value.replace(/^(['"])(.*)\1$/, '$2').trim();
}

function isBlankOrPlaceholder(value) {
  const normalized = String(value || '').trim().toLowerCase();
  return !normalized || PLACEHOLDER_PREFIXES.some((prefix) => normalized.startsWith(prefix));
}

function setEnvValue(lines, key, value) {
  const pattern = new RegExp('^\\s*' + key + '\\s*=.*$');
  const index = lines.findIndex((entry) => pattern.test(entry));
  if (index >= 0) lines[index] = key + '=' + value;
  else lines.push(key + '=' + value);
}

export async function generateLocalSecrets({
  envPath = path.join(ROOT, '.env'),
  examplePath = path.join(ROOT, '.env.example')
} = {}) {
  let content;
  try {
    content = await fs.readFile(envPath, 'utf8');
  } catch (error) {
    if (error?.code !== 'ENOENT') throw error;
    content = await fs.readFile(examplePath, 'utf8');
  }

  const lines = content.split(/\r?\n/);
  const generated = [];
  for (const key of SECRET_KEYS) {
    const current = readEnvValue(lines, key);
    if (!isBlankOrPlaceholder(current)) continue;
    setEnvValue(lines, key, randomBytes(32).toString('base64'));
    generated.push(key);
  }

  const serviceKey = readEnvValue(lines, 'BOT_SERVICE_KEY');
  const aiogramServiceKey = readEnvValue(lines, 'HENDY_BOT_SERVICE_KEY');
  if (isBlankOrPlaceholder(aiogramServiceKey) && serviceKey) {
    setEnvValue(lines, 'HENDY_BOT_SERVICE_KEY', serviceKey);
    generated.push('HENDY_BOT_SERVICE_KEY');
  }

  const output = lines.join('\n').replace(/\n*$/, '\n');
  await fs.mkdir(path.dirname(envPath), { recursive: true });
  await fs.writeFile(envPath, output, { encoding: 'utf8', mode: 0o600 });
  try {
    await fs.chmod(envPath, 0o600);
  } catch {
    // Some Windows filesystems do not expose POSIX file modes.
  }

  return { generated: [...new Set(generated)], envPath };
}

async function main() {
  try {
    const result = await generateLocalSecrets();
    if (!result.generated.length) {
      console.log('Các secret local đã có giá trị; không thay đổi hoặc in secret ra màn hình.');
      return;
    }
    console.log('Đã tạo/cập nhật các biến trong .env: ' + result.generated.join(', '));
    console.log('Giá trị secret không được in ra. Hãy giữ .env ngoài Git và lưu ADMIN_PASSWORD vào password manager.');
  } catch (error) {
    console.error('Không thể tạo local secrets: ' + (error?.message || 'lỗi không xác định'));
    process.exitCode = 1;
  }
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  await main();
}
