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
  if (url.protocol !== 'https:' || url.username || url.password) {
    throw new Error(name + ' phải là HTTPS công khai và không chứa user/password.');
  }
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

export function normalizeCustomDomains(payload) {
  const entries = Array.isArray(payload) ? payload : Array.isArray(payload?.items) ? payload.items : [];
  return entries.map((entry) => entry?.customDomain || entry)
    .filter((domain) => typeof domain?.name === 'string' && domain.name.trim())
    .map((domain) => ({
      name: domain.name.trim().toLowerCase(),
      domainType: String(domain.domainType || ''),
      verificationStatus: String(domain.verificationStatus || 'unknown'),
      redirectForName: domain.redirectForName || null
    }))
    .sort((a, b) => {
      const verified = Number(b.verificationStatus === 'verified') - Number(a.verificationStatus === 'verified');
      return verified || a.name.localeCompare(b.name);
    });
}

export function findWorkspaceService(payload, expected) {
  const entries = Array.isArray(payload) ? payload : Array.isArray(payload?.services) ? payload.services : [];
  const services = entries.map((entry) => entry?.service || entry)
    .filter((service) => service && typeof service === 'object');
  const repository = normalizeRepository(expected.repository || '');

  const candidates = services.filter((service) =>
    service.name === expected.name &&
    service.type === expected.type &&
    (!expected.workspaceId || service.ownerId === expected.workspaceId) &&
    (!repository || !service.repo || normalizeRepository(service.repo) === repository)
  );

  if (candidates.length === 1) return candidates[0];
  if (candidates.length > 1) {
    throw new Error(
      'Tìm thấy nhiều service "' + expected.name +
      '" cùng repository. Hãy đặt RENDER_WORKSPACE_ID hoặc Service ID để chọn đúng.'
    );
  }

  const available = services
    .filter((service) => !expected.workspaceId || !service.ownerId || service.ownerId === expected.workspaceId)
    .map((service) => service.name + ' (' + service.type + ')');
  throw new Error(
    'Không tìm thấy service "' + expected.name + '" loại ' + expected.type +
    ' trong workspace/repository đã chọn. Các service hiện có: ' + (available.join(', ') || 'không có') + '.'
  );
}

export function validateService(service, expected) {
  if (service.id !== expected.id) throw new Error('Service ID không khớp cấu hình.');
  if (expected.workspaceId && service.ownerId && service.ownerId !== expected.workspaceId) {
    throw new Error('Service thuộc workspace khác.');
  }
  if (service.type !== expected.type) {
    throw new Error('Sai loại dịch vụ: mong đợi ' + expected.type + ', nhận ' + String(service.type || 'không rõ') + '.');
  }
  if (expected.name && service.name !== expected.name) throw new Error('Tên service không khớp.');
  if (service.suspended && service.suspended !== 'not_suspended') throw new Error('Service đang bị tạm ngưng trên Render.');
  if (service.branch && service.branch !== expected.branch) throw new Error('Service đang deploy nhánh ' + service.branch + ', không phải ' + expected.branch + '.');
  if (expected.repository && service.repo && normalizeRepository(service.repo) !== normalizeRepository(expected.repository)) {
    throw new Error('Service đang kết nối repository khác.');
  }
  return normalizeHttpsOrigin(service.serviceDetails?.url || service.url, 'URL của ' + expected.name);
}

export function validateDeploy(deploy, expectedCommit = '') {
  if (deploy.status !== 'live') {
    throw new Error('Deployment chưa Live: trạng thái hiện tại là ' + String(deploy.status || 'không rõ') + '.');
  }
  if (expectedCommit && deploy.commit?.id !== expectedCommit) {
    throw new Error(
      'Deployment chưa chạy commit yêu cầu. Mong đợi ' + expectedCommit +
      ', hiện tại ' + String(deploy.commit?.id || 'không rõ') + '.'
    );
  }
  return true;
}

export function validateAiogramHealth(payload) {
  if (!payload || payload.status !== 'ok') throw new Error('Aiogram service chưa sẵn sàng.');
  if (payload.database !== 'ready') throw new Error('Aiogram service chưa kết nối PostgreSQL.');
  if (payload.webhook !== 'ready') throw new Error('Aiogram webhook chưa được đăng ký thành công.');
  if (payload.framework !== 'aiogram') throw new Error('Health endpoint không xác nhận framework aiogram.');
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
    try { payload = JSON.parse(body); } catch {
      throw new Error('Phản hồi JSON không hợp lệ từ ' + new URL(url).pathname + '.');
    }
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
  return fetchJson(RENDER_API_BASE + path, {
    headers: { Authorization: 'Bearer ' + apiKey }
  });
}

async function waitForLiveDeploy(apiKey, serviceId, expectedCommit, env) {
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
    if (latest.status === 'live' && (!expectedCommit || latest.commit?.id === expectedCommit)) {
      return latest;
    }
    if (attempt < attempts) await new Promise((resolve) => setTimeout(resolve, intervalMs));
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
    headers: { Accept: 'text/html,application/json,*/*', 'User-Agent': 'HENDY-Render-Live-Check/2.0' }
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
  if (!/HENDY|Trung tâm quản trị/i.test(admin.body) || !/Đăng nhập quản trị/i.test(admin.body)) {
    throw new Error('Đường dẫn /admin không trả về trang đăng nhập quản trị.');
  }
  if (!config.body.includes(apiUrl)) {
    throw new Error('config.js trên Static Site chưa trỏ đến API URL đã cấu hình: ' + apiUrl);
  }
  return {
    home: home.response.status,
    miniApp: miniApp.response.status,
    admin: admin.response.status,
    config: config.response.status
  };
}

async function checkApi(apiUrl, siteUrl) {
  const health = await fetchPublic(apiUrl + '/healthz', 'API health check');
  let healthJson;
  try { healthJson = JSON.parse(health.body); } catch {
    throw new Error('/healthz không trả về JSON hợp lệ.');
  }
  if (healthJson.status !== 'ok' || healthJson.database !== 'ready') {
    throw new Error('API chưa sẵn sàng hoặc PostgreSQL chưa kết nối: ' + JSON.stringify(healthJson));
  }

  const configResult = await fetchPublic(apiUrl + '/api/config', 'API public config');
  let publicConfig;
  try { publicConfig = JSON.parse(configResult.body); } catch {
    throw new Error('/api/config không trả về JSON hợp lệ.');
  }
  const configuredApi = normalizeHttpsOrigin(publicConfig.apiBaseUrl, 'API public config');
  const configuredSite = normalizeHttpsOrigin(publicConfig.appUrl, 'Website public config');
  const miniAppUrl = new URL(String(publicConfig.miniAppUrl || ''));
  if (
    miniAppUrl.protocol !== 'https:' ||
    miniAppUrl.origin !== siteUrl ||
    miniAppUrl.pathname !== '/app' ||
    miniAppUrl.search ||
    miniAppUrl.hash
  ) {
    throw new Error('PUBLIC_MINI_APP_URL phải trỏ đến đúng đường dẫn HTTPS /app của Static Site.');
  }
  if (configuredApi !== apiUrl) throw new Error('PUBLIC_API_URL trong API không trùng URL service.');
  if (configuredSite !== siteUrl) throw new Error('PUBLIC_APP_URL trong API chưa trỏ đến Static Site.');
  return {
    health: health.response.status,
    database: healthJson.database,
    config: configResult.response.status,
    appUrl: configuredSite,
    miniAppUrl: miniAppUrl.toString(),
    botUsername: publicConfig.botUsername || null
  };
}

async function resolveService(apiKey, workspaceId, serviceId, expected) {
  if (serviceId) {
    return unwrapService(await renderGet(apiKey, '/services/' + encodeURIComponent(serviceId)));
  }
  const query = new URLSearchParams({ limit: '100', includePreviews: 'false' });
  if (workspaceId) query.set('ownerId', workspaceId);
  const page = await renderGet(apiKey, '/services?' + query.toString());
  return findWorkspaceService(page, {
    ...expected,
    workspaceId: workspaceId || undefined,
    repository: expected.repository || EXPECTED_REPOSITORY
  });
}

async function readCustomDomains(apiKey, serviceId) {
  try {
    const payload = await renderGet(
      apiKey,
      '/services/' + encodeURIComponent(serviceId) + '/custom-domains?limit=100'
    );
    return { available: true, domains: normalizeCustomDomains(payload) };
  } catch {
    // A permission issue or unsupported custom-domain endpoint does not hide the Render service URL.
    return { available: false, domains: [] };
  }
}

async function verifyOneService(apiKey, workspaceId, serviceId, expected, env, expectedCommit) {
  const service = await resolveService(apiKey, workspaceId, serviceId, expected);
  const url = validateService(service, {
    ...expected,
    id: service.id,
    workspaceId: workspaceId || undefined,
    branch: EXPECTED_BRANCH,
    repository: EXPECTED_REPOSITORY
  });
  const [deploy, domainResult] = await Promise.all([
    waitForLiveDeploy(apiKey, service.id, expectedCommit, env),
    readCustomDomains(apiKey, service.id)
  ]);
  validateDeploy(deploy, expectedCommit);
  return {
    name: service.name,
    id: service.id,
    ownerId: service.ownerId || null,
    type: service.type,
    url,
    externalHostname: new URL(url).hostname,
    customDomains: domainResult.domains,
    customDomainsAvailable: domainResult.available,
    deployId: deploy.id,
    deployStatus: deploy.status,
    commit: deploy.commit?.id || null,
    finishedAt: deploy.finishedAt || null
  };
}

async function checkAiogramService(apiKey, workspaceId, serviceId, env, expectedCommit) {
  const service = await resolveService(apiKey, workspaceId, serviceId, {
    type: 'web_service',
    name: 'hendydichvumang-aiogram-bot'
  });
  const url = validateService(service, {
    id: service.id,
    workspaceId: workspaceId || undefined,
    type: 'web_service',
    name: 'hendydichvumang-aiogram-bot',
    branch: EXPECTED_BRANCH,
    repository: EXPECTED_REPOSITORY
  });
  const [deploy, domainResult] = await Promise.all([
    waitForLiveDeploy(apiKey, service.id, expectedCommit, env),
    readCustomDomains(apiKey, service.id)
  ]);
  validateDeploy(deploy, expectedCommit);
  const health = await fetchPublic(url + '/healthz', 'Aiogram health check');
  let payload;
  try { payload = JSON.parse(health.body); } catch {
    throw new Error('Aiogram /healthz không trả JSON hợp lệ.');
  }
  validateAiogramHealth(payload);
  return {
    found: true,
    name: service.name,
    id: service.id,
    ownerId: service.ownerId || null,
    type: service.type,
    url,
    externalHostname: new URL(url).hostname,
    customDomains: domainResult.domains,
    customDomainsAvailable: domainResult.available,
    deployId: deploy.id,
    deployStatus: deploy.status,
    commit: deploy.commit?.id || null,
    finishedAt: deploy.finishedAt || null,
    smoke: {
      health: health.response.status,
      database: payload.database,
      webhook: payload.webhook,
      framework: payload.framework,
      botId: payload.bot_id || null,
      botUsername: payload.bot_username || null
    }
  };
}

export async function verifyRenderDeployment(env = process.env) {
  const apiKey = String(env.RENDER_API_KEY || '').trim();
  const workspaceOverride = String(env.RENDER_WORKSPACE_ID || '').trim();
  const staticServiceId = String(env.RENDER_STATIC_SERVICE_ID || '').trim();
  const apiServiceId = String(env.RENDER_API_SERVICE_ID || '').trim();
  const expectedCommit = String(env.EXPECTED_COMMIT_SHA || EXPECTED_COMMIT || '');
  if (!apiKey) {
    throw new Error('Cần RENDER_API_KEY. Workspace ID và Service ID có thể để trống vì script tự nhận diện từ Render metadata.');
  }

  // Resolve the Static Site first. Its ownerId is used to constrain subsequent discovery
  // when the workspace override is omitted.
  const staticService = await verifyOneService(
    apiKey,
    workspaceOverride,
    staticServiceId,
    { type: 'static_site', name: 'hendydichvumang-site' },
    env,
    expectedCommit
  );
  const workspaceId = workspaceOverride || staticService.ownerId || '';
  const apiService = await verifyOneService(
    apiKey,
    workspaceId,
    apiServiceId,
    { type: 'web_service', name: 'hendydichvumang-api' },
    env,
    expectedCommit
  );
  if (staticService.ownerId && apiService.ownerId && staticService.ownerId !== apiService.ownerId) {
    throw new Error('Static Site và API không thuộc cùng Render workspace.');
  }
  if (!workspaceId && !staticService.ownerId && !apiService.ownerId) {
    throw new Error('Không thể tự nhận diện workspace từ service metadata; hãy khai báo RENDER_WORKSPACE_ID.');
  }

  const staticUrl = normalizeHttpsOrigin(staticService.url, 'URL Static Site');
  const apiUrl = normalizeHttpsOrigin(apiService.url, 'URL API');
  const [staticSmoke, apiSmoke] = await Promise.all([
    checkStaticSite(staticUrl, apiUrl),
    checkApi(apiUrl, staticUrl)
  ]);
  const services = {
    staticSite: { ...staticService, smoke: staticSmoke },
    apiWebService: { ...apiService, smoke: apiSmoke }
  };

  const aiogramMode = String(env.RENDER_AIOGRAM_CHECK_ENABLED || 'auto').trim().toLowerCase();
  if (aiogramMode !== 'false' && aiogramMode !== '0') {
    const aiogramServiceId = String(env.RENDER_AIOGRAM_SERVICE_ID || '').trim();
    try {
      services.aiogramBot = await checkAiogramService(
        apiKey,
        workspaceId,
        aiogramServiceId,
        env,
        expectedCommit
      );
    } catch (error) {
      const notFound = String(error?.message || '').startsWith('Không tìm thấy service');
      if (!notFound || aiogramMode === 'true') throw error;
      services.aiogramBot = {
        found: false,
        name: 'hendydichvumang-aiogram-bot',
        status: 'not_found',
        message: 'Aiogram chưa được tạo trong workspace; bỏ qua kiểm tra bot phụ.'
      };
    }
  } else {
    services.aiogramBot = {
      found: false,
      status: 'skipped',
      message: 'Kiểm tra Aiogram được tắt bằng RENDER_AIOGRAM_CHECK_ENABLED=false.'
    };
  }

  return {
    result: 'LIVE',
    checkedAt: new Date().toISOString(),
    workspaceId: workspaceId || staticService.ownerId || apiService.ownerId || null,
    workspaceAutoDetected: !workspaceOverride,
    expectedCommit: expectedCommit || null,
    services
  };
}

function domainSummary(service) {
  const domains = Array.isArray(service?.customDomains) ? service.customDomains : [];
  if (!domains.length) return 'không có custom domain';
  return domains.map((domain) =>
    domain.name + ' (' + domain.verificationStatus + (domain.redirectForName ? ', redirect' : '') + ')'
  ).join(', ');
}

async function appendGithubSummary(result) {
  const summaryPath = String(process.env.GITHUB_STEP_SUMMARY || '').trim();
  if (!summaryPath) return;

  const lines = [
    '## HENDY — Render deployment đã xác minh',
    '',
    '| Thành phần | Domain Render tự nhận diện | Custom domain | Deploy |',
    '|---|---|---|---|',
    '| Website / Static Site | ' + result.services.staticSite.url + ' | ' + domainSummary(result.services.staticSite) + ' | ' + result.services.staticSite.deployStatus + ' |',
    '| API Node.js | ' + result.services.apiWebService.url + ' | ' + domainSummary(result.services.apiWebService) + ' | ' + result.services.apiWebService.deployStatus + ' |'
  ];
  if (result.services.aiogramBot?.found) {
    lines.push('| Aiogram | ' + result.services.aiogramBot.url + ' | ' + domainSummary(result.services.aiogramBot) + ' | ' + result.services.aiogramBot.deployStatus + ' |');
  } else if (result.services.aiogramBot) {
    lines.push('| Aiogram | chưa có service | — | ' + result.services.aiogramBot.status + ' |');
  }
  lines.push(
    '',
    'Mini App mặc định: ' + result.services.staticSite.url + '/app',
    'Workspace: ' + String(result.workspaceId || 'không xác định') +
      (result.workspaceAutoDetected ? ' (tự nhận diện)' : ' (đã cấu hình)')
  );
  await appendFile(summaryPath, lines.join('\n') + '\n', 'utf8');
}

async function main() {
  try {
    const result = await verifyRenderDeployment();
    await appendGithubSummary(result);
    console.log('\n✅ RENDER LIVE — DOMAIN, WORKSPACE VÀ CUSTOM DOMAIN ĐÃ ĐƯỢC TỰ NHẬN DIỆN\n');
    console.log(JSON.stringify(result, null, 2));
  } catch (error) {
    console.error('\n❌ CHƯA THỂ XÁC NHẬN RENDER DEPLOYMENT');
    console.error(error?.message || 'Lỗi không xác định.');
    process.exitCode = 1;
  }
}

if (process.argv[1] && new URL(import.meta.url).href === new URL('file://' + process.argv[1]).href) await main();
