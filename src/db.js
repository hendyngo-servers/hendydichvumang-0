import pg from 'pg';
import { loadConfig } from './config.js';

const { Pool } = pg;
let pool;

export function getPool() {
  if (pool) return pool;
  const config = loadConfig();
  const ssl = config.PGSSLMODE === 'disable'
    ? false
    : { rejectUnauthorized: config.PGSSLMODE === 'verify-full' };
  pool = new Pool({
    connectionString: config.DATABASE_URL,
    max: config.PG_POOL_MAX,
    idleTimeoutMillis: 30000,
    connectionTimeoutMillis: 10000,
    statement_timeout: 30000,
    application_name: 'hendy-multi-agent',
    ssl,
  });
  pool.on('error', (error) => console.error('[db] idle client error:', error.message));
  return pool;
}

export async function query(text, params = []) {
  return getPool().query(text, params);
}

export async function transaction(callback) {
  const client = await getPool().connect();
  try {
    await client.query('BEGIN');
    const result = await callback(client);
    await client.query('COMMIT');
    return result;
  } catch (error) {
    await client.query('ROLLBACK').catch(() => {});
    throw error;
  } finally {
    client.release();
  }
}

export async function closePool() {
  if (pool) {
    const current = pool;
    pool = undefined;
    await current.end();
  }
}

export async function checkDatabase() {
  const result = await query('SELECT current_database() AS database, NOW() AS checked_at');
  return result.rows[0];
}
