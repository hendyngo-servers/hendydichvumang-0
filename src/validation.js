import { z } from 'zod';
import { randomUUID } from 'node:crypto';

export const loginSchema = z.object({
  username: z.string().min(3).max(100),
  password: z.string().min(1).max(512),
}).strict();

export const createTaskSchema = z.object({
  title: z.string().trim().min(3).max(160),
  prompt: z.string().trim().min(5).max(12000),
  contextText: z.string().max(30000).default(''),
  workflowKey: z.string().regex(/^[a-z][a-z0-9_]{1,63}$/),
  priority: z.number().int().min(0).max(100).default(50),
}).strict();

export const createAgentSchema = z.object({
  agentKey: z.string().regex(/^[a-z][a-z0-9_]{1,63}$/),
  name: z.string().trim().min(2).max(100),
  role: z.string().trim().min(2).max(100),
  description: z.string().max(500).default(''),
  systemPrompt: z.string().trim().min(20).max(12000),
  modelOverride: z.string().max(100).regex(/^[A-Za-z0-9._-]*$/).default(''),
  enabled: z.boolean().default(true),
  sortOrder: z.number().int().min(0).max(10000).default(100),
}).strict();

export const agentUpdateSchema = z.object({
  name: z.string().trim().min(2).max(100).optional(),
  role: z.string().trim().min(2).max(100).optional(),
  description: z.string().max(500).optional(),
  systemPrompt: z.string().trim().min(20).max(12000).optional(),
  modelOverride: z.string().max(100).regex(/^[A-Za-z0-9._-]*$/).optional(),
  enabled: z.boolean().optional(),
  sortOrder: z.number().int().min(0).max(10000).optional(),
}).strict().refine((value) => Object.keys(value).length > 0, 'Không có trường nào để cập nhật.');


const workflowStepsSchema = z.array(z.string().regex(/^[a-z][a-z0-9_]{1,63}$/)).min(1).max(5)
  .refine((steps) => new Set(steps).size === steps.length, 'Không được lặp Agent trong cùng workflow.');

export const createWorkflowSchema = z.object({
  workflowKey: z.string().regex(/^[a-z][a-z0-9_]{1,63}$/),
  name: z.string().trim().min(2).max(120),
  description: z.string().max(500).default(''),
  steps: workflowStepsSchema,
  enabled: z.boolean().default(true),
}).strict();

export const workflowUpdateSchema = z.object({
  name: z.string().trim().min(2).max(120).optional(),
  description: z.string().max(500).optional(),
  steps: workflowStepsSchema.optional(),
  enabled: z.boolean().optional(),
}).strict().refine((value) => Object.keys(value).length > 0, 'Không có trường nào để cập nhật.');

export const approveSchema = z.object({ note: z.string().max(1000).default('') }).strict();
export const rejectSchema = z.object({ note: z.string().trim().min(3).max(1000) }).strict();

export function parseBody(schema, body) {
  const result = schema.safeParse(body);
  if (!result.success) {
    const error = new Error('Dữ liệu gửi lên không hợp lệ.');
    error.statusCode = 400;
    error.details = result.error.issues.map((issue) => ({ field: issue.path.join('.'), message: issue.message }));
    throw error;
  }
  return result.data;
}

export function makeTaskId() {
  return randomUUID();
}
