import { loadConfig } from './config.js';
import { checkDatabase, closePool } from './db.js';
import { heartbeat, recoverStaleTasks, runOneTask } from './worker-core.js';

const config = loadConfig();
let stopping = false;

async function sleep(ms) {
  await new Promise((resolve) => setTimeout(resolve, ms));
}

async function main() {
  await checkDatabase();
  if (!config.workerEnabled) {
    console.log('[worker] disabled: set MULTI_AGENT_ENABLED=true and configure OPENAI_API_KEY in the service environment.');
    return;
  }
  const workerId = `${String(process.env.RENDER_SERVICE_ID || 'local').replace(/[^A-Za-z0-9_.:-]/g, '-')}-${process.pid}`.slice(0, 120);
  await heartbeat(workerId);
  const recovered = await recoverStaleTasks(config);
  console.log(`[worker] online; recovered ${recovered} stale task(s)`);
  while (!stopping) {
    const results = await Promise.all(Array.from({ length: config.MULTI_AGENT_CONCURRENCY }, () => runOneTask(config)));
    const worked = results.some(Boolean);
    if (!worked) await sleep(config.MULTI_AGENT_WORKER_POLL_SECONDS * 1000);
  }
}

async function shutdown() {
  stopping = true;
  await closePool();
  process.exit(0);
}

process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
main().catch(async (error) => {
  console.error(`[worker] startup failed: ${error.message}`);
  await closePool();
  process.exitCode = 1;
});
