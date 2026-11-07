import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const html = await readFile(new URL('../apps/website/index.html', import.meta.url), 'utf8');

test('HENDY homepage uses the new space-inspired brand and preserves core entry points', () => {
  assert.match(html, /HENDY CYBERTECH/);
  assert.match(html, /HENDY BOT/);
  assert.match(html, /LINK BOT/);
  assert.match(html, /href="\/app"/);
  assert.match(html, /data-bot-link/);
});

test('HENDY homepage resolves public bot and Telegram community links from the API', () => {
  assert.match(html, /fetch\(apiBase \+ '\/api\/config'/);
  assert.match(html, /config\.botUsername/);
  assert.match(html, /config\.telegramGroupUrl/);
  assert.match(html, /config\.telegramChannelUrl/);
  assert.match(html, /allowedHosts/);
});

test('HENDY homepage supports mobile layout and reduced-motion preferences', () => {
  assert.match(html, /@media \(max-width: 600px\)/);
  assert.match(html, /prefers-reduced-motion: reduce/);
  assert.match(html, /aria-label=/);
});

test('HENDY homepage does not load third-party site identity or image assets', () => {
  assert.doesNotMatch(html, /lethituanem\.com|tuanem2027_bot|tuankem_bot|\/IMG\/[1-4]\.gif/i);
});
