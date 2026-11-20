import 'dotenv/config';
import { z } from 'zod';

const bool = z.string().optional().transform((v) => v === 'true');
const positiveInt = (fallback, max) => z.coerce.number().int().min(1).max(max).default(fallback);

const schema = z.object({
  NODE_ENV: z.enum(['development', 'test', 'production']).default('development'),
  PORT: positiveInt(10000, 65535),
  LOG_LEVEL: z.enum(['debug', 'info', 'warn', 'error']).default('info'),
  DATABASE_URL: z.string().min(1),
  PGSSLMODE: z.enum(['disable', 'require', 'verify-full']).default('require'),
  PG_POOL_MAX: positiveInt(5, 20),
  ADMIN_USERNAME: z.string().min(3).max(100).default('admin'),
  ADMIN_PASSWORD: z.string().min(16),
  ADMIN_PASSWORD_SALT: z.string().min(16),
  SESSION_SECRET: z.string().min(32),
  SESSION_TTL_HOURS: positiveInt(12, 168),
  OPENAI_API_KEY: z.string().optional().default(''),
  OPENAI_MODEL: z.string().min(1).max(100).default('gpt-6.2-astra'),
  OPENAI_BASE_URL: z.string().url().default('https://api.openai.com/v1'),
  OPENAI_TIMEOUT_MS: positiveInt(90000, 300000),
  MULTI_AGENT_ENABLED: bool,
  MULTI_AGENT_WORKER_POLL_SECONDS: positiveInt(5, 60),
  MULTI_AGENT_MAX_ATTEMPTS: positiveInt(3, 5),
  MULTI_AGENT_STALE_MINUTES: positiveInt(15, 120),
  MULTI_AGENT_CONCURRENCY: positiveInt(1, 5),
  CORS_ORIGINS: z.string().optional().default(''),
});

export function loadConfig(env = process.env) {
  const parsed = schema.safeParse(env);
  if (!parsed.success) {
    const issues = parsed.error.issues.map((item) => `${item.path.join('.')}: ${item.message}`);
    throw new Error(`Invalid environment configuration:\n- ${issues.join('\n- ')}`);
  }
  const value = parsed.data;
  return Object.freeze({
    ...value,
    corsOrigins: value.CORS_ORIGINS.split(',').map((s) => s.trim()).filter(Boolean),
    cookieSecure: value.NODE_ENV === 'production',
    workerEnabled: value.MULTI_AGENT_ENABLED && Boolean(value.OPENAI_API_KEY),
  });
}
