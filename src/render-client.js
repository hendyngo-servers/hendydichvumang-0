const API_ROOT = 'https://api.render.com/v1';
const EXPECTED_REPOSITORY = 'github.com/hendyngo-servers/hendydichvumang-0';

const SERVICE_DEFINITIONS = Object.freeze([
  {
    key: 'site',
    name: 'hendydichvumang-site',
    type: 'static_site',
    label: 'Mini App / Static Site',
    requiredEnv: ['STATIC_API_BASE_URL']
  },
  {
    key: 'api',
    name: 'hendydichvumang-api',
    type: 'web_service',
    label: 'Node.js API / grammY',
    requiredEnv: [
      'DATABASE_URL',
      'BOT1_TOKEN',
      'TELEGRAM_WEBHOOK_SECRET',
      'BOT_SERVICE_KEY',
      'ADMIN_PASSWORD',
      'ADMIN_TELEGRAM_ID',
      'PUBLIC_APP_URL',
      'PUBLIC_MINI_APP_URL'
    ]
  },
  {
    key: 'aiogram',
    name: 'hendydichvumang-aiogram-bot',
    type: 'web_service',
    label: 'Aiogram Telegram Bot',
    requiredEnv: [
      'BOT2_TOKEN',
      'AIOGRAM_WEBHOOK_SECRET',
      'HENDY_API_BASE_URL',
      'HENDY_BOT_SERVICE_KEY',
      'DATABASE_URL',
      'PGSSLMODE',
      'PUBLIC_APP_URL',
      'PUBLIC_MINI_APP_URL'
    ]
  }
]);

async function requestJson(url, apiKey, fetchImpl) {
  const response = await fetchImpl(url, {
    method: 'GET',
    headers: {
      Accept: 'application/json',
      Authorization: 'Bearer ' + apiKey,
      'User-Agent': 'HENDY-Operations-Center/1.0'
    },
    signal: AbortSignal.timeout(9000)
  });
  if (!response.ok) {
    // Never include response text or credentials in thrown errors or logs.
    throw new Error('render_http_' + response.status);
  }
  return response.json();
}

function unwrapService(item) {
  return item?.service && typeof item.service === 'object' ? item.service : item;
}

function unwrapDeploy(item) {
  return item?.deploy && typeof item.deploy === 'object' ? item.deploy : item;
}

function unwrapEnvVar(item) {
  return item?.envVar && typeof item.envVar === 'object' ? item.envVar : item;
}

function unwrapCustomDomain(item) {
  return item?.customDomain && typeof item.customDomain === 'object' ? item.customDomain : item;
}

function normalizeRepository(value) {
  const raw = String(value || '').trim();
  if (!raw) return '';
  try {
    const url = new URL(raw);
    return (url.hostname.toLowerCase() + url.pathname.replace(/\/+$/, '').replace(/\.git$/i, '')).toLowerCase();
  } catch {
    return raw.replace(/\.git$/i, '').replace(/\/+$/, '').toLowerCase();
  }
}

function findService(allServices, definition, workspaceId) {
  const candidates = allServices.filter((service) =>
    service.name === definition.name &&
    service.type === definition.type &&
    (!workspaceId || service.ownerId === workspaceId) &&
    (!service.repo || normalizeRepository(service.repo) === EXPECTED_REPOSITORY)
  );
  if (candidates.length > 1) {
    throw new Error(
      'Render có nhiều service cùng tên "' + definition.name +
      '". Hãy đặt RENDER_WORKSPACE_ID để chọn workspace đúng.'
    );
  }
  return candidates[0] || null;
}

function normalizeCustomDomains(payload) {
  const entries = Array.isArray(payload) ? payload : Array.isArray(payload?.items) ? payload.items : [];
  return entries.map(unwrapCustomDomain)
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

export function renderServiceDefinitions() {
  return SERVICE_DEFINITIONS.map(({ key, name, label, requiredEnv }) => ({
    key,
    name,
    label,
    requiredEnv: [...requiredEnv]
  }));
}

/**
 * Read-only Render inventory. Environment variable values are consumed only
 * to determine whether a key exists; no value is returned, logged, or persisted.
 * If workspaceId is absent, the matching service repository and owner metadata
 * are used to infer the correct workspace.
 */
export async function getRenderOverview({
  apiKey = '',
  workspaceId = '',
  fetchImpl = globalThis.fetch
} = {}) {
  const credential = String(apiKey || '').trim();
  if (!credential) {
    return {
      configured: false,
      available: false,
      message: 'Chưa cấu hình RENDER_API_KEY.',
      workspaceId: null,
      workspaceAutoDetected: false,
      services: []
    };
  }

  if (typeof fetchImpl !== 'function') {
    return {
      configured: true,
      available: false,
      message: 'Môi trường Node.js không hỗ trợ kết nối Render API.',
      workspaceId: null,
      workspaceAutoDetected: false,
      services: []
    };
  }

  try {
    const listUrl = new URL(API_ROOT + '/services');
    listUrl.searchParams.set('includePreviews', 'false');
    listUrl.searchParams.set('limit', '100');
    if (workspaceId) listUrl.searchParams.set('ownerId', String(workspaceId));

    const rawServices = await requestJson(listUrl, credential, fetchImpl);
    const allServices = Array.isArray(rawServices)
      ? rawServices.map(unwrapService).filter((service) => service && typeof service === 'object')
      : [];
    const services = [];
    let detectedWorkspaceId = String(workspaceId || '').trim();

    for (const definition of SERVICE_DEFINITIONS) {
      const service = findService(allServices, definition, detectedWorkspaceId);
      if (!service) {
        services.push({
          key: definition.key,
          name: definition.name,
          label: definition.label,
          found: false,
          status: 'not_found',
          requiredEnv: [...definition.requiredEnv],
          configuredEnv: [],
          missingEnv: [...definition.requiredEnv],
          envInventoryAvailable: false,
          customDomains: [],
          customDomainsAvailable: false,
          latestDeploy: null,
          dashboardUrl: null
        });
        continue;
      }

      if (!detectedWorkspaceId && service.ownerId) detectedWorkspaceId = String(service.ownerId);

      const [deployResult, envResult, domainsResult] = await Promise.allSettled([
        requestJson(
          API_ROOT + '/services/' + encodeURIComponent(String(service.id)) + '/deploys?limit=1',
          credential,
          fetchImpl
        ),
        requestJson(
          API_ROOT + '/services/' + encodeURIComponent(String(service.id)) + '/env-vars?limit=100',
          credential,
          fetchImpl
        ),
        requestJson(
          API_ROOT + '/services/' + encodeURIComponent(String(service.id)) + '/custom-domains?limit=100',
          credential,
          fetchImpl
        )
      ]);

      const deployRows = deployResult.status === 'fulfilled' && Array.isArray(deployResult.value)
        ? deployResult.value.map(unwrapDeploy)
        : [];
      const envRows = envResult.status === 'fulfilled' && Array.isArray(envResult.value)
        ? envResult.value.map(unwrapEnvVar)
        : [];
      const envKeys = new Set(
        envRows
          .map((item) => String(item?.key || '').trim())
          .filter((key) => /^[A-Z][A-Z0-9_]{0,99}$/.test(key))
      );
      const latest = deployRows[0] || null;
      const suspended = service.suspended === 'suspended' || service.suspended === true;
      const customDomains = domainsResult.status === 'fulfilled'
        ? normalizeCustomDomains(domainsResult.value)
        : [];
      const publicUrl = service.serviceDetails?.url || service.url || null;
      let externalHostname = null;
      try { externalHostname = publicUrl ? new URL(publicUrl).hostname : null; } catch { /* metadata remains unavailable */ }

      services.push({
        key: definition.key,
        name: service.name,
        id: String(service.id),
        label: definition.label,
        found: true,
        type: String(service.type || ''),
        branch: String(service.branch || ''),
        url: publicUrl,
        externalHostname,
        customDomains,
        customDomainsAvailable: domainsResult.status === 'fulfilled',
        status: suspended ? 'suspended' : (latest?.status || 'unknown'),
        autoDeploy: String(service.autoDeployTrigger || service.autoDeploy || 'unknown'),
        requiredEnv: [...definition.requiredEnv],
        configuredEnv: envResult.status === 'fulfilled'
          ? definition.requiredEnv.filter((key) => envKeys.has(key))
          : [],
        missingEnv: envResult.status === 'fulfilled'
          ? definition.requiredEnv.filter((key) => !envKeys.has(key))
          : [],
        envInventoryAvailable: envResult.status === 'fulfilled',
        latestDeploy: latest ? {
          status: String(latest.status || 'unknown'),
          createdAt: latest.createdAt || null,
          finishedAt: latest.finishedAt || null,
          commitId: latest.commit?.id || null,
          commitMessage: String(latest.commit?.message || '').slice(0, 180)
        } : null,
        dashboardUrl: typeof service.dashboardUrl === 'string' ? service.dashboardUrl : null
      });
    }

    return {
      configured: true,
      available: true,
      message: null,
      workspaceId: detectedWorkspaceId || null,
      workspaceAutoDetected: !String(workspaceId || '').trim() && Boolean(detectedWorkspaceId),
      services
    };
  } catch (error) {
    return {
      configured: true,
      available: false,
      message: error?.message?.startsWith('render_http_')
        ? 'Render API từ chối yêu cầu (' + error.message.slice('render_http_'.length) + ').'
        : 'Không thể kết nối Render API. Kiểm tra RENDER_API_KEY và quyền truy cập.',
      workspaceId: null,
      workspaceAutoDetected: false,
      services: []
    };
  }
}
