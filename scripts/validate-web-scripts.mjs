import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const files = [
  'website/telegram/admin-dashboard/index.html',
  'website/telegram/admin-dashboard/legacy-login.html',
  'website/public/index.html',
  'website/public/app-store/index.html',
  'website/public/miniapp/index.html',
];

let checked = 0;
const failures = [];
for (const file of files) {
  const html = readFileSync(file, 'utf8');
  const scripts = [...html.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script\s*>/gi)];
  for (let index = 0; index < scripts.length; index += 1) {
    const attributes = scripts[index][1] || '';
    const source = scripts[index][2] || '';
    if (/\bsrc\s*=|type\s*=\s*["']application\/(?:json|ld\+json)["']/i.test(attributes)) continue;
    if (/type\s*=\s*["']module["']/i.test(attributes)) {
      failures.push(file + ': inline module script requires a dedicated module parser');
      continue;
    }
    if (!source.trim()) continue;
    checked += 1;
    try {
      new vm.Script(source, { filename: file + '#inline-script-' + (index + 1) });
    } catch (error) {
      failures.push(file + '#inline-script-' + (index + 1) + ': ' + (error.stack || error.message));
    }
  }
}
const externalScripts = [
  'website/public/assets/site.js',
  'website/public/assets/deployment-config.js',
  'website/public/assets/site.config.js',
  'website/public/app-store/config.js',
  'website/public/app-store/app.js',
  'website/public/miniapp/config.js',
  'website/public/miniapp/app.js',
];
for (const file of externalScripts) {
  try {
    const source = readFileSync(file, 'utf8');
    if (!source.trim()) throw new Error('File is empty.');
    checked += 1;
    new vm.Script(source, { filename:file });
  } catch (error) {
    failures.push(file + ': ' + (error.stack || error.message));
  }
}
const styleFiles = [
  'website/public/assets/site.css',
  'website/public/app-store/style.css',
  'website/public/miniapp/style.css',
];
for (const file of styleFiles) {
  try {
    const source = readFileSync(file, 'utf8');
    if (!source.trim()) throw new Error('File is empty.');
  } catch (error) {
    failures.push(file + ': ' + (error.stack || error.message));
  }
}
if (checked === 0) failures.push('No inline browser scripts were checked.');
if (failures.length) {
  for (const failure of failures) process.stderr.write('FAIL ' + failure + '\n');
  process.exit(1);
}
process.stdout.write('Đã kiểm tra cú pháp ' + checked + ' browser script(s) trên ' + files.length + ' trang.\n');
