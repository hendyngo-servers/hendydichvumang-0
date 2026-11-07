import 'dotenv/config';
import { appendFile } from 'node:fs/promises';

const RENDER_API_BASE = 'https://api.render.com/v1';
const EXPECTED_REPOSITORY = process.env.REPOSITORY_URL || 'https://github.com/hendyngo-servers/hendydichvumang-0';
const EXPECTED_BRANCH = process.env.EXPECTED_BRANCH || 'main';
const EXPECTED_COMMIT = process.env.EXPECTED_COMMIT_SHA || process.env.GITHUB_SHA || '';

export function normalizeRepository(value) {
  const raw = String(value || '').trim();
  if (!raw) return '';
  try {
    const url = new URL(raw);
    return (url.hostname.toLowerCase() + url.pathname.replace(/\/+$/, '').replace(/\.git$/i, '')).toLowerCase();
  } catch {
    return raw.replace(/\.git$/i, '').replace(/\/+$/, '').toLowerCase();
  }
}
export function normalizeHttpsOrigin(value, name = 'URL') {
  let url;
  try { url = new URL(String(value || '').trim()); } catch { throw new Error(name + ' không phải URL hợp lệ.'); }
  if (url.protocol !== 'https:' || url.username || url.password) throw new Error(name + ' phải là HTTPS công khai và không chứa user/password.');
  return url.origin;
}
export function unwrapService(payload) {
  if (!payload || typeof payload !== 'object') throw new Error('Render API trả về service không hợp lệ.');
  const service = payload.service || payload;
  if (!service || typeof service !== 'object' || !service.id) throw new Error('Render API không trả về thông tin service.');
  return service;
}
export function unwrapLatestDeploy(payload) {
  const items = Array.isArray(payload) ? payload : Array.isArray(payload?.items) ? payload.items : [];
  if (!items.length) throw new Error('Service chưa có deployment nào.');
  const deploy = items[0]?.deploy || items[0];
  if (!deploy || typeof deploy !== 'object' || !deploy.id) throw new Error('Render API không trả về deployment hợp lệ.');
  return deploy;
}
export function selectWorkspaceId(payload, requestedId = '') {
  const entries = Array.isArray(payload)
    ? payload
    : Array.isArray(payload?.owners)
      ? payload.owners
      : [];
  const owners = entries
    .map((entry) => entry?.owner || entry)
    .filter((owner) => owner && typeof owner === 'object' && typeof owner.id === 'string');

  if (requestedId) {
    if (owners.length && !owners.some((owner) => owner.id === requestedId)) {
      throw new Error('RENDER_WORKSPACE_ID không nằm trong danh sách workspace của Render API key.');
    }
    return requestedId;
  }

  const teams = owners.filter((owner) => owner.type === 'team');
  if (teams.length === 1) return teams[0].id;
  if (owners.length === 1) return owners[0].id;
  if (owners.length === 0) throw new Error('Render API key không trả về workspace nào.');
  throw new Error('Render API key có quyền vào nhiều workspace. Đặt RENDER_WORKSPACE_ID để chọn workspace HENDY.');
}

export function findWorkspaceService(payload, expected) {
  const entries = Array.isArray(payload) ? payload : Array.isArray(payload?.services) ? payload.services : [];
  const services = entries.map((entry) => entry?.service || entry).filter((service) => service && typeof service === 'object');
  const found = services.find((service) =>
    service.name === expected.name &&
    service.type === expected.type &&
    service.ownerId === expected.workspaceId
  );
  if (found) return found;
  const available = services
    .filter((service) => !service.ownerId || service.ownerId === expected.workspaceId)
    .map((service) => service.name + ' (' + service.type + ')');
  throw new Error(
    'Không tìm thấy service "' + expected.name + '" loại ' + expected.type +
    ' trong workspace Render. Các service hiện có: ' + (available.join(', ') || 'không có') + '.'
  );
}
export function validateService(service, expected) {
  if (service.id !== expected.id) throw new Error('Service ID không khớp cấu hình.');
  if (service.ownerId && service.ownerId !== expected.workspaceId) throw new Error('Service thuộc workspace khác.');
  if (service.type !== expected.type) throw new Error('Sai loại dịch vụ: mong đợi ' + expected.type + ', nhận ' + String(service.type || 'không rõ') + '.');
  if (expected.name && service.name !== expected.name) throw new Error('Tên service không khớp.');
  if (service.suspended && service.suspended !== 'not_suspended') throw new Error('Service đang bị tạm ngưng trên Render.');
  if (service.branch && service.branch !== expected.branch) throw new Error('Service đang deploy nhánh ' + service.branch + ', không phải ' + expected.branch + '.');
  if (service.repo && normalizeRepository(service.repo) !== normalizeRepository(expected.repository)) throw new Error('Service đang kết nối repository khác.');
  return normalizeHttpsOrigin(service.serviceDetails?.url || service.url, 'URL của ' + expected.name);
}
export function validateDeploy(deploy, expectedCommit = '') {
  if (deploy.status !== 'live') throw new Error('Deployment chưa Live: trạng thái hiện tại là ' + String(deploy.status || 'không rõ') + '.');
  if (expectedCommit && deploy.commit?.id !== expectedCommit) {
    throw new Error('Deployment chưa chạy commit yêu cầu. Mong đợi ' + expectedCommit + ', hiện tại ' + String(deploy.commit?.id || 'không rõ') + '.');
  }
  return true;
}
async function fetchJson(url, options = {}) {
  const response = await fetch(url, {
    ...options,
    signal: AbortSignal.timeout(20_000),
    headers: { Accept: 'application/json', ...(options.headers || {}) }
  });
  const body = await response.text();
  let payload = null;
  if (body) {
    try { payload = JSON.parse(body); } catch { throw new Error('Phản hồi JSON không hợp lệ từ ' + new URL(url).pathname + '.'); }
  }
  if (!response.ok) {
    const message = response.status === 401
      ? 'Render API key không hợp lệ hoặc không có quyền truy cập.'
      : response.status === 404
        ? 'Không tìm thấy tài nguyên trên Render (HTTP 404).'
        : 'Render API trả về HTTP ' + response.status + '.';
    throw new Error(message);
  }
  return payload;
}
async function renderGet(apiKey, path) {
  return fetchJson(RENDER_API_BASE + path, { headers: { Authorization: 'Bearer ' + apiKey } });
}

export async function fetchAllRenderOwners(apiKey, renderGetImpl = renderGet) {
  const owners = [];
  const seenCursors = new Set();
  let cursor = '';

  for (let page = 0; page < 20; page += 1) {
    const query = new URLSearchParams({ limit: '100' });
    if (cursor) query.set('cursor', cursor);
    const entries = await renderGetImpl(apiKey, '/owners?' + query.toString());
    if (!Array.isArray(entries)) {
      throw new Error('Render API không trả về danh sách workspace hợp lệ.');
    }
    owners.push(...entries);
    const nextCursor = String(entries.at(-1)?.cursor || '');
    if (!nextCursor || entries.length < 100) return owners;
    if (seenCursors.has(nextCursor)) throw new Error('Render API trả về cursor workspace lặp.');
    seenCursors.add(nextCursor);
    cursor = nextCursor;
  }

  throw new Error('Danh sách Render workspace vượt quá giới hạn tự nhận diện 2.000 mục.');
}

async function waitForLiveDeploy(apiKey, serviceId, expectedCommit = EXPECTED_COMMIT, env = process.env) {
  const attemptsValue = Number(env.RENDER_DEPLOY_WAIT_ATTEMPTS || 1);
  const intervalValue = Number(env.RENDER_DEPLOY_WAIT_INTERVAL_MS || 15000);
  const attempts = Number.isInteger(attemptsValue) ? Math.min(Math.max(attemptsValue, 1), 40) : 1;
  const intervalMs = Number.isInteger(intervalValue) ? Math.min(Math.max(intervalValue, 1000), 60000) : 15000;
  let latest = null;

  for (let attempt = 1; attempt <= attempts; attempt += 1) {
    latest = unwrapLatestDeploy(await renderGet(
      apiKey,
      '/services/' + encodeURIComponent(serviceId) + '/deploys?limit=1'
    ));
    if (
      latest.status === 'live' &&
      (!expectedCommit || latest.commit?.id === expectedCommit)
    ) {
      return latest;
    }
    if (attempt < attempts) {
      await new Promise((resolve) => setTimeout(resolve, intervalMs));
    }
  }

  throw new Error(
    'Deployment của service ' + serviceId + ' chưa LIVE với commit yêu cầu sau ' + attempts +
    ' lần kiểm tra. Trạng thái: ' + String(latest?.status || 'không rõ') +
    '; commit: ' + String(latest?.commit?.id || 'không rõ') +
    (expectedCommit ? '; mong đợi: ' + expectedCommit : '') + '.'
  );
}
async function fetchPublic(url, label) {
  const response = await fetch(url, {
    redirect: 'follow',
    signal: AbortSignal.timeout(20_000),
    headers: { Accept: 'text/html,application/json,*/*', 'User-Agent': 'HENDY-Render-Live-Check/1.0' }
  });
  if (!response.ok) throw new Error(label + ' trả về HTTP ' + response.status + '.');
  return { response, body: await response.text() };
}
async function checkStaticSite(siteUrl, apiUrl) {
  const [home, miniApp, admin, config] = await Promise.all([
    fetchPublic(siteUrl + '/', 'Static Site trang giới thiệu'),
    fetchPublic(siteUrl + '/app', 'Static Site Mini App'),
    fetchPublic(siteUrl + '/admin', 'Static Site trang Admin'),
    fetchPublic(siteUrl + '/config.js', 'Static Site config.js')
  ]);
  if (!/HENDY/i.test(home.body) || !/CÔNG NGHỆ KẾT NỐI|DỊCH VỤ MẠNG/i.test(home.body)) {
    throw new Error('Trang chủ chưa trả về nội dung giới thiệu HENDY.');
  }
  if (!/HENDY|Trung tâm khách hàng/i.test(miniApp.body) || !/telegram-web-app\.js/i.test(miniApp.body)) {
    throw new Error('Đường dẫn /app không trả về Telegram Mini App.');
  }
  if (!/HENDY|Trung tâm quản trị/i.test(admin.body) || !/Đăng nhập quản trị/i.test(admin.body)) throw new Error('Đường dẫn /admin không trả về trang đăng nhập quản trị.');
  if (!config.body.includes(apiUrl)) throw new Error('config.js trên Static Site chưa trỏ đến API URL đã cấu hình: ' + apiUrl);
  return { home: home.response.status, miniApp: miniApp.response.status, admin: admin.response.status, config: config.response.status };
}
async function checkApi(apiUrl, siteUrl) {
  const health = await fetchPublic(apiUrl + '/healthz', 'API health check');
  let healthJson;
  try { healthJson = JSON.parse(health.body); } catch { throw new Error('/healthz không trả về JSON hợp lệ.'); }
  if (healthJson.status !== 'ok' || healthJson.database !== 'ready') throw new Error('API chưa sẵn sàng hoặc PostgreSQL chưa kết nối: ' + JSON.stringify(healthJson));

  const configResult = await fetchPublic(apiUrl + '/api/config', 'API public config');
  let publicConfig;
  try { publicConfig = JSON.parse(configResult.body); } catch { throw new Error('/api/config không trả về JSON hợp lệ.'); }
  const configuredApi = normalizeHttpsOrigin(publicConfig.apiBaseUrl, 'API public config');
  const configuredSite = normalizeHttpsOrigin(publicConfig.appUrl, 'Website public config');
  const miniAppUrl = new URL(String(publicConfig.miniAppUrl || ''));
  if (miniAppUrl.protocol !== 'https:' || miniAppUrl.origin !== siteUrl || miniAppUrl.pathname !== '/app' || miniAppUrl.search || miniAppUrl.hash) {
    throw new Error('PUBLIC_MINI_APP_URL phải trỏ đến đúng đường dẫn HTTPS /app của Static Site.');
  }
  if (configuredApi !== apiUrl) throw new Error('PUBLIC_API_URL trong API không trùng URL service.');
  if (configuredSite !== siteUrl) throw new Error('PUBLIC_APP_URL trong API chưa trỏ đến Static Site.');
  return { health: health.response.status, database: healthJson.database, config: configResult.response.status, appUrl: configuredSite, miniAppUrl: miniAppUrl.toString() };
}
async function resolveService(apiKey, workspaceId, serviceId, expected) {
  if (serviceId) {
    return unwrapService(await renderGet(apiKey, '/services/' + encodeURIComponent(serviceId)));
  }
  const query = new URLSearchParams({ ownerId: workspaceId, limit: '100', includePreviews: 'false' });
  const page = await renderGet(apiKey, '/services?' + query.toString());
  return findWorkspaceService(page, { ...expected, workspaceId });
}

async function verifyOneService(apiKey, workspaceId, serviceId, expected, env = process.env, expectedCommit = EXPECTED_COMMIT) {
  const service = await resolveService(apiKey, workspaceId, serviceId, expected);
  const url = validateService(service, { ...expected, id: service.id, workspaceId, branch: EXPECTED_BRANCH, repository: EXPECTED_REPOSITORY });
  const deploy = await waitForLiveDeploy(apiKey, service.id, expectedCommit, env);
  validateDeploy(deploy, expectedCommit);
  return {
    name: service.name, id: service.id, type: service.type, url,
    deployId: deploy.id, deployStatus: deploy.status,
    commit: deploy.commit?.id || null, finishedAt: deploy.finishedAt || null
  };
}
export function validateAiogramHealth(payload) {
  if (!payload || payload.status !== 'ok') throw new Error('Aiogram service chưa sẵn sàng.');
  if (payload.database !== 'ready') throw new Error('Aiogram service chưa kết nối PostgreSQL.');
  if (payload.webhook !== 'ready') throw new Error('Aiogram webhook chưa được đăng ký thành công.');
  if (payload.framework !== 'aiogram') throw new Error('Health endpoint không xác nhận framework aiogram.');
  return true;
}

async function checkAiogramService(apiKey, workspaceId, serviceId, env = process.env, expectedCommit = EXPECTED_COMMIT) {
  const service = await resolveService(apiKey, workspaceId, serviceId, {
    type: 'web_service', name: 'hendydichvumang-aiogram-bot'
  });
  const url = validateService(service, {
    id: service.id, workspaceId, type: 'web_service',
    name: 'hendydichvumang-aiogram-bot',
    branch: EXPECTED_BRANCH, repository: EXPECTED_REPOSITORY
  });
  const configuredUrl = String(env.AIOGRAM_BASE_URL || '').trim()
    ? normalizeHttpsOrigin(env.AIOGRAM_BASE_URL, 'AIOGRAM_BASE_URL')
    : url;
  if (configuredUrl !== url) throw new Error('AIOGRAM_BASE_URL không trùng URL Render cấp cho service.');
  const deploy = await waitForLiveDeploy(apiKey, service.id, expectedCommit, env);
  validateDeploy(deploy, expectedCommit);
  const health = await fetchPublic(url + '/healthz', 'Aiogram health check');
  let payload;
  try { payload = JSON.parse(health.body); } catch { throw new Error('Aiogram /healthz không trả JSON hợp lệ.'); }
  validateAiogramHealth(payload);
  return {
    name: service.name, id: service.id, type: service.type, url,
    deployId: deploy.id, deployStatus: deploy.status, commit: deploy.commit?.id || null,
    finishedAt: deploy.finishedAt || null,
    smoke: { health: health.response.status, database: payload.database, webhook: payload.webhook, framework: payload.framework }
  };
}
export async function verifyRenderDeployment(env = process.env) {
  const apiKey = String(env.RENDER_API_KEY || '').trim();
  const requestedWorkspaceId = String(env.RENDER_WORKSPACE_ID || '').trim();
  const staticServiceId = String(env.RENDER_STATIC_SERVICE_ID || '').trim();
  const apiServiceId = String(env.RENDER_API_SERVICE_ID || '').trim();
  const expectedCommit = String(env.EXPECTED_COMMIT_SHA || EXPECTED_COMMIT || '');
  if (!apiKey) {
    throw new Error('Cần RENDER_API_KEY trong GitHub Actions Secret. RENDER_WORKSPACE_ID có thể tự nhận diện nếu API key chỉ thấy một workspace.');
  }
  const owners = await fetchAllRenderOwners(apiKey);
  const workspaceId = selectWorkspaceId(owners, requestedWorkspaceId);
  const [staticService, apiService] = await Promise.all([
    verifyOneService(apiKey, workspaceId, staticServiceId, { type: 'static_site', name: 'hendydichvumang-site' }, env, expectedCommit),
    verifyOneService(apiKey, workspaceId, apiServiceId, { type: 'web_service', name: 'hendydichvumang-api' }, env, expectedCommit)
  ]);
  const staticUrl = normalizeHttpsOrigin(env.STATIC_SITE_URL || staticService.url, 'STATIC_SITE_URL');
  const apiUrl = normalizeHttpsOrigin(env.API_BASE_URL || apiService.url, 'API_BASE_URL');
  if (staticUrl !== staticService.url) throw new Error('STATIC_SITE_URL không trùng URL Render cấp cho Static Site.');
  if (apiUrl !== apiService.url) throw new Error('API_BASE_URL không trùng URL Render cấp cho Web Service.');
  const [staticSmoke, apiSmoke] = await Promise.all([
    checkStaticSite(staticUrl, apiUrl),
    checkApi(apiUrl, staticUrl)
  ]);
  const services = {
    staticSite: { ...staticService, smoke: staticSmoke },
    apiWebService: { ...apiService, smoke: apiSmoke }
  };
  if (String(env.RENDER_AIOGRAM_CHECK_ENABLED || '').toLowerCase() === 'true') {
    const aiogramServiceId = String(env.RENDER_AIOGRAM_SERVICE_ID || '').trim();
    services.aiogramBot = await checkAiogramService(apiKey, workspaceId, aiogramServiceId, env, expectedCommit);
  }
  return {
    result: 'LIVE', checkedAt: new Date().toISOString(),
    expectedCommit: expectedCommit || null, services
  };
}
async function appendGithubSummary(result) {
  const summaryPath = String(process.env.GITHUB_STEP_SUMMARY || '').trim();
  if (!summaryPath) return;

  const lines = [
    '## HENDY — Render deployment đã xác minh',
    '',
    '| Thành phần | Domain tự nhận diện | Deploy |',
    '|---|---|---|',
    '| Website / Static Site | ' + result.services.staticSite.url + ' | ' + result.services.staticSite.deployStatus + ' |',
    '| API Node.js | ' + result.services.apiWebService.url + ' | ' + result.services.apiWebService.deployStatus + ' |'
  ];
  if (result.services.aiogramBot) {
    lines.push('| Aiogram | ' + result.services.aiogramBot.url + ' | ' + result.services.aiogramBot.deployStatus + ' |');
  }
  lines.push('', 'Mini App: ' + result.services.staticSite.url + '/app');
  await appendFile(summaryPath, lines.join('\n') + '\n', 'utf8');
}

async function main() {
  try {
    const result = await verifyRenderDeployment();
    await appendGithubSummary(result);
    console.log('\n✅ RENDER LIVE — CÁC DOMAIN ĐÃ ĐƯỢC TỰ NHẬN DIỆN VÀ KIỂM TRA\n');
    console.log(JSON.stringify(result, null, 2));
  } catch (error) {
    console.error('\n❌ CHƯA THỂ XÁC NHẬN RENDER DEPLOYMENT');
    console.error(error?.message || 'Lỗi không xác định.');
    process.exitCode = 1;
  }
}
if (process.argv[1] && new URL(import.meta.url).href === new URL('file://' + process.argv[1]).href) await main();
