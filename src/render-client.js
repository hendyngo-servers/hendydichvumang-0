const API_ROOT = 'https://api.render.com/v1';

const SERVICE_DEFINITIONS = Object.freeze([
  {
    key: 'site',
    name: 'hendydichvumang-site',
    label: 'Mini App / Static Site',
    requiredEnv: ['STATIC_API_BASE_URL']
  },
  {
    key: 'api',
    name: 'hendydichvumang-api',
    label: 'Node.js API / grammY',
    requiredEnv: [
      'DATABASE_URL',
      'BOT1_TOKEN',
      'TELEGRAM_WEBHOOK_SECRET',
      'BOT_SERVICE_KEY',
      'ADMIN_PASSWORD',
      'PUBLIC_APP_URL'
    ]
  },
  {
    key: 'aiogram',
    name: 'hendydichvumang-aiogram-bot',
    label: 'Aiogram Telegram Bot',
    requiredEnv: [
      'BOT2_TOKEN',
      'AIOGRAM_WEBHOOK_SECRET',
      'HENDY_API_BASE_URL',
      'HENDY_BOT_SERVICE_KEY',
      'DATABASE_URL',
      'PGSSLMODE'
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
 * in this server-side function to determine whether a key exists; no value is
 * ever returned to the caller, logged, or persisted.
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
      services: []
    };
  }

  if (typeof fetchImpl !== 'function') {
    return {
      configured: true,
      available: false,
      message: 'Môi trường Node.js không hỗ trợ kết nối Render API.',
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

    for (const definition of SERVICE_DEFINITIONS) {
      const service = allServices.find((item) => item.name === definition.name);
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
          latestDeploy: null,
          dashboardUrl: null
        });
        continue;
      }

      const [deployResult, envResult] = await Promise.allSettled([
        requestJson(
          API_ROOT + '/services/' + encodeURIComponent(String(service.id)) + '/deploys?limit=1',
          credential,
          fetchImpl
        ),
        requestJson(
          API_ROOT + '/services/' + encodeURIComponent(String(service.id)) + '/env-vars?limit=100',
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

      services.push({
        key: definition.key,
        name: definition.name,
        label: definition.label,
        found: true,
        type: String(service.type || ''),
        branch: String(service.branch || ''),
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
      services
    };
  } catch (error) {
    return {
      configured: true,
      available: false,
      message: error?.message?.startsWith('render_http_')
        ? 'Render API từ chối yêu cầu (' + error.message.slice('render_http_'.length) + ').'
        : 'Không thể kết nối Render API. Kiểm tra RENDER_API_KEY và quyền truy cập.',
      services: []
    };
  }
}
