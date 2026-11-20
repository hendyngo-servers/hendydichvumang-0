import { query } from './db.js';

export async function recordAudit(actor, action, targetType, targetId = '', details = {}) {
  await query(
    `INSERT INTO audit_log(actor, action, target_type, target_id, details)
     VALUES ($1, $2, $3, $4, $5::jsonb)`,
    [actor, action, targetType, targetId, JSON.stringify(details)],
  );
}

export async function recordTaskEvent(taskId, agentKey, eventType, details = {}, actor = null, client = null) {
  const db = client || { query };
  await db.query(
    `INSERT INTO task_events(task_id, agent_key, event_type, details, actor)
     VALUES ($1, $2, $3, $4::jsonb, $5)`,
    [taskId, agentKey, eventType, JSON.stringify(details), actor],
  );
}
