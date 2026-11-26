import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const surfaces = [
  ['website/public/assets/site.config.js', 'HENDY_WEB_CONFIG'],
  ['website/public/app-store/config.js', 'HENDY_STORE_CONFIG'],
  ['website/public/miniapp/config.js', 'HENDY_API_BASE'],
];
const deploymentSource = readFileSync('website/public/assets/deployment-config.js', 'utf8');

function load(path, hostname, apiBaseUrl) {
  const window = {};
  vm.runInNewContext(deploymentSource, { window }, { filename: 'deployment-config.js' });
  window.HENDY_RUNTIME_CONFIG = Object.freeze({ apiBaseUrl });
  vm.runInNewContext(readFileSync(path, 'utf8'), {
    window, location: { hostname }, URL,
  }, { filename: path });
  return window;
}
function apiOrigin(window, key) {
  return key === 'HENDY_API_BASE' ? window[key] : window[key].apiBaseUrl;
}

test('Website, App Store and Mini App share a validated HTTPS API override', () => {
  for (const [path, key] of surfaces) {
    assert.equal(apiOrigin(load(path, 'hendydichvumang-szad.onrender.com', 'https://bot.example.com'), key), 'https://bot.example.com');
  }
});
test('empty override preserves the existing Render API on its known Static Site hostname', () => {
  for (const [path, key] of surfaces) {
    assert.equal(apiOrigin(load(path, 'hendydichvumang-szad.onrender.com', ''), key), 'https://hendydichvumang-telegram-bot.onrender.com');
  }
});
test('invalid, credential-bearing or path-based API values fail closed to the Render fallback', () => {
  const invalid = [
    'http://bot.example.com',
    'https://user:password@bot.example.com',
    'https://bot.example.com/api',
    'https://bot.example.com/?token=secret',
  ];
  for (const value of invalid) for (const [path, key] of surfaces) {
    assert.equal(apiOrigin(load(path, 'hendydichvumang-szad.onrender.com', value), key), 'https://hendydichvumang-telegram-bot.onrender.com');
  }
});
test('unknown Website hosts do not guess a backend when override is empty', () => {
  for (const [path, key] of surfaces) {
    assert.equal(apiOrigin(load(path, 'www.example.com', ''), key), '');
  }
});
