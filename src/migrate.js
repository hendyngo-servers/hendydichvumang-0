import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { query, closePool } from './db.js';

const directory = path.dirname(fileURLToPath(import.meta.url));
const migrationsDir = path.resolve(directory, '../migrations');

async function main() {
  try {
    await query(`CREATE TABLE IF NOT EXISTS schema_migrations (
      version VARCHAR(120) PRIMARY KEY, applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )`);
    const files = ['001_init.sql', '002_seed_agents.sql'];
    for (const file of files) {
      const present = await query('SELECT 1 FROM schema_migrations WHERE version=$1', [file]);
      if (present.rowCount) {
        console.log(`[migrate] already applied: ${file}`);
        continue;
      }
      const sql = await readFile(path.join(migrationsDir, file), 'utf8');
      await query(sql);
      console.log(`[migrate] applied: ${file}`);
    }
    const status = await query('SELECT current_database() AS database');
    console.log(`[migrate] complete: ${status.rows[0].database}`);
  } finally {
    await closePool();
  }
}

main().catch((error) => {
  console.error(`[migrate] failed: ${error.message}`);
  process.exitCode = 1;
});
