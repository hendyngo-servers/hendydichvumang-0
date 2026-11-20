import express from 'express';
import helmet from 'helmet';
import { rateLimit } from 'express-rate-limit';
import { randomUUID } from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadConfig } from './config.js';
import { query, closePool, checkDatabase } from './db.js';
import { login, requireAuth, requireCsrf, logout, currentSession } from './auth.js';
import { recordAudit, recordTaskEvent } from './audit.js';
import {
  loginSchema, createTaskSchema, createAgentSchema, agentUpdateSchema,
  approveSchema, rejectSchema, createWorkflowSchema, workflowUpdateSchema, parseBody,
} from './validation.js';

const directory = path.dirname(fileURLToPath(import.meta.url));
const publicDir = path.resolve(directory, '../public');
const allowedStatuses = new Set(['queued', 'running', 'awaiting_approval', 'approved', 'rejected', 'failed']);

function sendError(res, error) {
  const status = Number.isInteger(error.statusCode) ? error.statusCode : 500;
  const payload = { error: status >= 500 ? 'Có lỗi máy chủ. Vui lòng thử lại sau.' : error.message };
  if (error.details) payload.details = error.details;
  return res.status(status).json(payload);
}

function asyncRoute(handler) {
  return (req, res, next) => Promise.resolve(handler(req, res, next)).catch(next);
}

function validate(schema) {
  return (req, res, next) => {
    try {
      req.validatedBody = parseBody(schema, req.body);
      next();
    } catch (error) {
      next(error);
    }
  };
}

function agentKeyParam(req, res, next) {
  if (!/^[a-z][a-z0-9_]{1,63}$/.test(req.params.key)) {
    return res.status(400).json({ error: 'Mã Agent không hợp lệ.' });
  }
  return next();
}

function taskIdParam(req, res, next) {
  if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(req.params.id)) {
    return res.status(400).json({ error: 'Mã công việc không hợp lệ.' });
  }
  return next();
}

function originMiddleware(config) {
  return (req, res, next) => {
    const origin = req.get('origin');
    if (origin) {
      if (config.corsOrigins.length && !config.corsOrigins.includes(origin)) {
        return res.status(403).json({ error: 'Origin không được phép.' });
      }
      if (config.corsOrigins.length) {
        res.setHeader('Access-Control-Allow-Origin', origin);
        res.setHeader('Vary', 'Origin');
        res.setHeader('Access-Control-Allow-Credentials', 'true');
        res.setHeader('Access-Control-Allow-Headers', 'Content-Type, X-CSRF-Token');
        res.setHeader('Access-Control-Allow-Methods', 'GET, POST, PATCH, OPTIONS');
      }
    }
    if (req.method === 'OPTIONS') return res.sendStatus(204);
    return next();
  };
}

export function createApp(overrides = {}) {
  const config = overrides.config || loadConfig();
  const app = express();
  app.disable('x-powered-by');
  app.set('trust proxy', config.NODE_ENV === 'production' ? 1 : false);
  app.use(helmet({
    contentSecurityPolicy: {
      directives: {
        defaultSrc: ["'self'"],
        scriptSrc: ["'self'"],
        styleSrc: ["'self'"],
        imgSrc: ["'self'", 'data:'],
        connectSrc: ["'self'"],
        objectSrc: ["'none'"],
        frameAncestors: ["'none'"],
        upgradeInsecureRequests: config.NODE_ENV === 'production' ? [] : null,
      },
    },
    crossOriginEmbedderPolicy: false,
  }));
  app.use(originMiddleware(config));
  app.use(express.json({ limit: '64kb', strict: true }));
  app.use((req, res, next) => {
    const started = Date.now();
    res.on('finish', () => {
      if (config.LOG_LEVEL === 'debug') {
        console.log(JSON.stringify({ level: 'debug', method: req.method, path: req.path, status: res.statusCode, durationMs: Date.now() - started }));
      }
    });
    next();
  });

  const apiLimiter = rateLimit({ windowMs: 60_000, limit: 180, standardHeaders: 'draft-8', legacyHeaders: false });
  const loginLimiter = rateLimit({ windowMs: 15 * 60_000, limit: 8, standardHeaders: 'draft-8', legacyHeaders: false, message: { error: 'Quá nhiều lần đăng nhập. Hãy thử lại sau 15 phút.' } });
  app.use('/api', apiLimiter);
  app.use('/api', (_req, res, next) => { res.setHeader('Cache-Control', 'no-store'); next(); });

  app.get('/healthz', asyncRoute(async (_req, res) => {
    await checkDatabase();
    res.json({ status: 'ok', database: 'ready', service: 'hendy-multi-agent' });
  }));

  app.post('/api/auth/login', loginLimiter, validate(loginSchema), asyncRoute(async (req, res) => {
    await login(req, res, config);
  }));

  app.use('/api', (req, res, next) => {
    if (req.path.startsWith('/auth/login')) return next();
    return requireAuth(req, res, next, config);
  });

  app.get('/api/auth/me', asyncRoute(currentSession));
  app.post('/api/auth/logout', requireCsrf, asyncRoute((req, res) => logout(req, res, config)));

  app.get('/api/dashboard', asyncRoute(async (_req, res) => {
    const [counts, worker, recent] = await Promise.all([
      query(`SELECT status, COUNT(*)::int AS count FROM tasks GROUP BY status`),
      query(`SELECT COUNT(*)::int AS online FROM workers WHERE last_seen_at > NOW() - INTERVAL '45 seconds'`),
      query(`SELECT id,title,task_type,status,agent_key,created_by,created_at,updated_at
             FROM tasks ORDER BY created_at DESC LIMIT 8`),
    ]);
    const taskCounts = Object.fromEntries(counts.rows.map((r) => [r.status, r.count]));
    res.json({
      tasks: { total: Object.values(taskCounts).reduce((a, b) => a + b, 0), queued: taskCounts.queued || 0, running: taskCounts.running || 0, awaitingApproval: taskCounts.awaiting_approval || 0, failed: taskCounts.failed || 0, approved: taskCounts.approved || 0, rejected: taskCounts.rejected || 0 },
      workerOnline: worker.rows[0].online > 0,
      recentTasks: recent.rows,
      workerEnabled: config.workerEnabled,
    });
  }));

  app.get('/api/agents', asyncRoute(async (_req, res) => {
    const result = await query(`SELECT agent_key,name,role,description,system_prompt,model_override,enabled,sort_order,created_at,updated_at
                                FROM agents ORDER BY sort_order, name`);
    res.json({ agents: result.rows });
  }));

  app.post('/api/agents', requireCsrf, validate(createAgentSchema), asyncRoute(async (req, res) => {
    const input = req.validatedBody;
    const result = await query(
      `INSERT INTO agents(agent_key,name,role,description,system_prompt,model_override,enabled,sort_order,created_by,updated_by)
       VALUES($1,$2,$3,$4,$5,$6,$7,$8,$9,$9)
       RETURNING agent_key,name,role,description,system_prompt,model_override,enabled,sort_order,created_at,updated_at`,
      [input.agentKey,input.name,input.role,input.description,input.systemPrompt,input.modelOverride,input.enabled,input.sortOrder,req.admin.username],
    );
    await recordAudit(req.admin.username, 'agent.created', 'agent', input.agentKey, { enabled: input.enabled });
    res.status(201).json({ agent: result.rows[0] });
  }));

  app.patch('/api/agents/:key', agentKeyParam, requireCsrf, validate(agentUpdateSchema), asyncRoute(async (req, res) => {
    const input = req.validatedBody;
    if (input.enabled === false) {
      const refs = await query(
        `SELECT workflow_key AS ref FROM workflows WHERE enabled=TRUE AND steps @> $1::jsonb
         UNION ALL
         SELECT id::text AS ref FROM tasks WHERE status IN ('queued','running') AND workflow_steps @> $1::jsonb
         LIMIT 1`,
        [JSON.stringify([req.params.key])],
      );
      if (refs.rowCount) return res.status(409).json({ error: 'Agent đang được Workflow hoạt động hoặc công việc đang chờ sử dụng. Hãy xử lý Workflow/công việc trước khi tắt Agent.' });
    }
    const mapping = { name: 'name', role: 'role', description: 'description', systemPrompt: 'system_prompt', modelOverride: 'model_override', enabled: 'enabled', sortOrder: 'sort_order' };
    const values = [];
    const setters = [];
    for (const [key, column] of Object.entries(mapping)) {
      if (Object.hasOwn(input, key)) {
        values.push(input[key]);
        setters.push(`${column} = $${values.length}`);
      }
    }
    values.push(req.admin.username);
    setters.push(`updated_by = $${values.length}`, 'updated_at = NOW()');
    values.push(req.params.key);
    const result = await query(
      `UPDATE agents SET ${setters.join(', ')} WHERE agent_key = $${values.length}
       RETURNING agent_key,name,role,description,system_prompt,model_override,enabled,sort_order,created_at,updated_at`,
      values,
    );
    if (!result.rowCount) return res.status(404).json({ error: 'Không tìm thấy Agent.' });
    await recordAudit(req.admin.username, 'agent.updated', 'agent', req.params.key, { fields: Object.keys(input) });
    res.json({ agent: result.rows[0] });
  }));

  app.get('/api/workflows', asyncRoute(async (_req, res) => {
    const result = await query(`SELECT workflow_key,name,description,steps,enabled FROM workflows ORDER BY workflow_key`);
    res.json({ workflows: result.rows });
  }));

  app.post('/api/workflows', requireCsrf, validate(createWorkflowSchema), asyncRoute(async (req, res) => {
    const input = req.validatedBody;
    const active = await query('SELECT agent_key FROM agents WHERE agent_key = ANY($1::text[]) AND enabled=TRUE', [input.steps]);
    if (active.rowCount !== input.steps.length) return res.status(409).json({ error: 'Workflow chỉ được sử dụng các Agent đang bật.' });
    const result = await query(
      `INSERT INTO workflows(workflow_key,name,description,steps,enabled)
       VALUES($1,$2,$3,$4::jsonb,$5)
       RETURNING workflow_key,name,description,steps,enabled`,
      [input.workflowKey,input.name,input.description,JSON.stringify(input.steps),input.enabled],
    );
    await recordAudit(req.admin.username, 'workflow.created', 'workflow', input.workflowKey, { steps: input.steps });
    res.status(201).json({ workflow: result.rows[0] });
  }));

  app.patch('/api/workflows/:key', agentKeyParam, requireCsrf, validate(workflowUpdateSchema), asyncRoute(async (req, res) => {
    const input = req.validatedBody;
    if (input.steps) {
      const active = await query('SELECT agent_key FROM agents WHERE agent_key = ANY($1::text[]) AND enabled=TRUE', [input.steps]);
      if (active.rowCount !== input.steps.length) return res.status(409).json({ error: 'Workflow chỉ được sử dụng các Agent đang bật.' });
    }
    const current = await query('SELECT * FROM workflows WHERE workflow_key=$1', [req.params.key]);
    if (!current.rowCount) return res.status(404).json({ error: 'Không tìm thấy Workflow.' });
    const previous = current.rows[0];
    const result = await query(
      `UPDATE workflows SET name=$1,description=$2,steps=$3::jsonb,enabled=$4,updated_at=NOW()
       WHERE workflow_key=$5 RETURNING workflow_key,name,description,steps,enabled`,
      [input.name ?? previous.name, input.description ?? previous.description, JSON.stringify(input.steps ?? previous.steps), input.enabled ?? previous.enabled, req.params.key],
    );
    await recordAudit(req.admin.username, 'workflow.updated', 'workflow', req.params.key, { fields: Object.keys(input) });
    res.json({ workflow: result.rows[0] });
  }));

  app.get('/api/tasks', asyncRoute(async (req, res) => {
    const status = String(req.query.status || '');
    const limit = Math.max(1, Math.min(100, Number.parseInt(String(req.query.limit || '40'), 10) || 40));
    const offset = Math.max(0, Math.min(10000, Number.parseInt(String(req.query.offset || '0'), 10) || 0));
    if (status && !allowedStatuses.has(status)) return res.status(400).json({ error: 'Bộ lọc trạng thái không hợp lệ.' });
    const result = await query(
      `SELECT t.id,t.title,t.task_type,t.prompt,t.workflow_key,t.agent_key,t.status,t.priority,t.attempts,t.current_step,
              t.created_by,t.approved_by,t.approval_note,t.error_message,t.created_at,t.updated_at,t.completed_at,
              a.name AS agent_name, jsonb_array_length(t.workflow_steps) AS step_count
       FROM tasks t JOIN agents a ON a.agent_key=t.agent_key
       WHERE ($1 = '' OR t.status = $1)
       ORDER BY CASE WHEN t.status='queued' THEN 0 WHEN t.status='running' THEN 1 WHEN t.status='awaiting_approval' THEN 2 ELSE 3 END,
                t.priority DESC,t.created_at DESC
       LIMIT $2 OFFSET $3`,
      [status, limit, offset],
    );
    res.json({ tasks: result.rows, limit, offset });
  }));

  app.post('/api/tasks', requireCsrf, validate(createTaskSchema), asyncRoute(async (req, res) => {
    const input = req.validatedBody;
    const workflowResult = await query('SELECT * FROM workflows WHERE workflow_key=$1 AND enabled=TRUE', [input.workflowKey]);
    if (!workflowResult.rowCount) return res.status(400).json({ error: 'Workflow không tồn tại hoặc đang tắt.' });
    const steps = workflowResult.rows[0].steps;
    if (!Array.isArray(steps) || !steps.length || steps.length > 5) return res.status(409).json({ error: 'Workflow chưa có danh sách Agent hợp lệ.' });
    const activeAgents = await query('SELECT agent_key FROM agents WHERE agent_key = ANY($1::text[]) AND enabled=TRUE', [steps]);
    if (activeAgents.rowCount !== steps.length) return res.status(409).json({ error: 'Một hoặc nhiều Agent trong Workflow đang bị tắt.' });
    const id = randomUUID();
    const result = await query(
      `INSERT INTO tasks(id,title,task_type,prompt,context_text,workflow_key,workflow_steps,current_step,agent_key,priority,created_by)
       VALUES($1,$2,$3,$4,$5,$6,$7::jsonb,0,$8,$9,$10)
       RETURNING id,title,status,agent_key,workflow_key,priority,created_at`,
      [id,input.title,'workflow',input.prompt,input.contextText,input.workflowKey,JSON.stringify(steps),steps[0],input.priority,req.admin.username],
    );
    await recordTaskEvent(id, steps[0], 'task.queued', { workflow: input.workflowKey, steps }, req.admin.username);
    await recordAudit(req.admin.username, 'task.created', 'task', id, { workflow: input.workflowKey, priority: input.priority });
    res.status(201).json({ task: result.rows[0] });
  }));

  app.get('/api/tasks/:id', taskIdParam, asyncRoute(async (req, res) => {
    const [task, runs, events] = await Promise.all([
      query(`SELECT t.*,a.name AS agent_name FROM tasks t JOIN agents a ON a.agent_key=t.agent_key WHERE t.id=$1`, [req.params.id]),
      query(`SELECT id,agent_key,status,model,input_tokens,output_tokens,input_text,output_text,error_message,started_at,finished_at
             FROM task_runs WHERE task_id=$1 ORDER BY started_at`, [req.params.id]),
      query(`SELECT id,agent_key,event_type,details,actor,created_at FROM task_events WHERE task_id=$1 ORDER BY created_at DESC LIMIT 100`, [req.params.id]),
    ]);
    if (!task.rowCount) return res.status(404).json({ error: 'Không tìm thấy công việc.' });
    res.json({ task: task.rows[0], runs: runs.rows, events: events.rows });
  }));

  app.post('/api/tasks/:id/approve', taskIdParam, requireCsrf, validate(approveSchema), asyncRoute(async (req, res) => {
    const { note } = req.validatedBody;
    const result = await query(
      `UPDATE tasks SET status='approved',approved_by=$2,approval_note=$3,updated_at=NOW()
       WHERE id=$1 AND status='awaiting_approval' RETURNING id,status,approved_by,approval_note`,
      [req.params.id, req.admin.username, note],
    );
    if (!result.rowCount) return res.status(409).json({ error: 'Chỉ có thể duyệt công việc đang chờ duyệt.' });
    await recordTaskEvent(req.params.id, null, 'task.approved', { note }, req.admin.username);
    await recordAudit(req.admin.username, 'task.approved', 'task', req.params.id, { note });
    res.json({ task: result.rows[0], message: 'Đã ghi nhận quyết định duyệt. Hệ thống không tự xuất bản hoặc gửi nội dung.' });
  }));

  app.post('/api/tasks/:id/reject', taskIdParam, requireCsrf, validate(rejectSchema), asyncRoute(async (req, res) => {
    const { note } = req.validatedBody;
    const result = await query(
      `UPDATE tasks SET status='rejected',approved_by=$2,approval_note=$3,updated_at=NOW()
       WHERE id=$1 AND status='awaiting_approval' RETURNING id,status,approved_by,approval_note`,
      [req.params.id, req.admin.username, note],
    );
    if (!result.rowCount) return res.status(409).json({ error: 'Chỉ có thể từ chối công việc đang chờ duyệt.' });
    await recordTaskEvent(req.params.id, null, 'task.rejected', { note }, req.admin.username);
    await recordAudit(req.admin.username, 'task.rejected', 'task', req.params.id, { note });
    res.json({ task: result.rows[0] });
  }));

  app.post('/api/tasks/:id/retry', taskIdParam, requireCsrf, asyncRoute(async (req, res) => {
    const result = await query(
      `UPDATE tasks SET status='queued',attempts=0,claimed_at=NULL,completed_at=NULL,error_message='',updated_at=NOW()
       WHERE id=$1 AND status='failed' RETURNING id,status,agent_key,current_step`,
      [req.params.id],
    );
    if (!result.rowCount) return res.status(409).json({ error: 'Chỉ có thể chạy lại công việc đang thất bại.' });
    await recordTaskEvent(req.params.id, result.rows[0].agent_key, 'task.requeued', {}, req.admin.username);
    await recordAudit(req.admin.username, 'task.retried', 'task', req.params.id, {});
    res.json({ task: result.rows[0] });
  }));

  app.get('/api/audit', asyncRoute(async (req, res) => {
    const limit = Math.max(1, Math.min(100, Number.parseInt(String(req.query.limit || '50'), 10) || 50));
    const result = await query(`SELECT id,actor,action,target_type,target_id,details,created_at FROM audit_log ORDER BY created_at DESC LIMIT $1`, [limit]);
    res.json({ entries: result.rows });
  }));

  app.use(express.static(publicDir, { index: 'index.html', maxAge: config.NODE_ENV === 'production' ? '1h' : 0, etag: true }));
  app.get('/admin', (_req, res) => res.sendFile(path.join(publicDir, 'index.html')));
  app.get(/^\/.*$/, (req, res, next) => {
    if (req.path.startsWith('/api/')) return res.status(404).json({ error: 'API endpoint không tồn tại.' });
    return res.sendFile(path.join(publicDir, 'index.html'), (error) => error && next(error));
  });

  app.use((error, _req, res, _next) => {
    if (res.headersSent) return;
    if (error?.type === 'entity.parse.failed') return res.status(400).json({ error: 'JSON không hợp lệ.' });
    if (error?.code === '23505') return res.status(409).json({ error: 'Dữ liệu đã tồn tại.' });
    if (error?.code === '23503') return res.status(409).json({ error: 'Dữ liệu đang được tham chiếu; không thể thực hiện.' });
    if (config.NODE_ENV !== 'production') console.error('[api]', error);
    else console.error('[api]', error?.name || 'Error', error?.message || 'unknown');
    return sendError(res, error);
  });

  return app;
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const config = loadConfig();
  const app = createApp({ config });
  const server = app.listen(config.PORT, '0.0.0.0', () => {
    console.log(`[api] HENDY Multi-Agent listening on ${config.PORT}`);
  });
  const shutdown = async () => {
    server.close(async () => {
      await closePool();
      process.exit(0);
    });
    setTimeout(() => process.exit(1), 10000).unref();
  };
  process.on('SIGTERM', shutdown);
  process.on('SIGINT', shutdown);
}
