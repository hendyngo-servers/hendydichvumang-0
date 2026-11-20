const $ = (selector, root = document) => root.querySelector(selector);
const state = { csrf: '', user: '', view: 'dashboard', workflows: [], agents: [], taskFilter: '', editingAgent: null, editingWorkflow: null, workflowSelected: [] };

const labels = {
  queued: 'Đang chờ', running: 'Đang chạy', awaiting_approval: 'Chờ duyệt',
  approved: 'Đã duyệt', rejected: 'Đã từ chối', failed: 'Thất bại',
};

function esc(value) {
  return String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
}
function date(value) {
  if (!value) return '—';
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? '—' : parsed.toLocaleString('vi-VN', { dateStyle: 'short', timeStyle: 'short' });
}
function statusBadge(status) {
  return `<span class="badge ${esc(status)}">${esc(labels[status] || status)}</span>`;
}
function notify(message, error = false) {
  const element = $('#notice');
  element.textContent = message;
  element.classList.toggle('error', error);
  element.classList.remove('hidden');
  window.clearTimeout(notify.timeout);
  notify.timeout = window.setTimeout(() => element.classList.add('hidden'), 5000);
}

async function api(path, options = {}) {
  const method = (options.method || 'GET').toUpperCase();
  const headers = { ...(options.headers || {}) };
  if (options.body && typeof options.body !== 'string') {
    headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(options.body);
  }
  if (!['GET', 'HEAD', 'OPTIONS'].includes(method) && state.csrf) headers['X-CSRF-Token'] = state.csrf;
  const response = await fetch(path, { ...options, method, headers, credentials: 'same-origin' });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    if (response.status === 401 && path !== '/api/auth/login') showLogin();
    const error = new Error(payload.error || `Yêu cầu thất bại (${response.status}).`);
    error.status = response.status;
    error.details = payload.details;
    throw error;
  }
  return payload;
}

function showLogin() {
  state.csrf = '';
  $('#app-view').classList.add('hidden');
  $('#login-view').classList.remove('hidden');
}
function showApp() {
  $('#login-view').classList.add('hidden');
  $('#app-view').classList.remove('hidden');
  $('#current-user').textContent = state.user || 'Admin';
}

async function bootstrap() {
  try {
    const session = await api('/api/auth/me');
    state.csrf = session.csrfToken;
    state.user = session.username;
    showApp();
    await loadInitialData();
  } catch (error) {
    showLogin();
  }
}

async function loadInitialData() {
  await loadAgents();
  await loadWorkflows();
  await loadDashboard();
  if (state.view === 'tasks') await loadTasks();
}

function setView(view) {
  state.view = view;
  const titles = { dashboard: 'Tổng quan', tasks: 'Công việc', agents: 'Đội ngũ Agent', workflows: 'Quy trình', activity: 'Nhật ký hệ thống' };
  $('#page-title').textContent = titles[view] || 'HENDY';
  document.querySelectorAll('.view-section').forEach((section) => section.classList.toggle('hidden', section.id !== `view-${view}`));
  document.querySelectorAll('.nav-item').forEach((button) => button.classList.toggle('active', button.dataset.view === view));
  if (view === 'tasks') loadTasks();
  if (view === 'agents') loadAgents();
  if (view === 'workflows') loadWorkflows();
  if (view === 'activity') loadAudit();
}

async function loadDashboard() {
  const result = await api('/api/dashboard');
  const counts = result.tasks;
  const stats = [
    ['Tổng công việc', counts.total, 'Tất cả yêu cầu'],
    ['Đang chờ', counts.queued, 'Trong hàng đợi'],
    ['Đang chạy', counts.running, 'Đang xử lý'],
    ['Chờ duyệt', counts.awaitingApproval, 'Cần Admin xem'],
    ['Thất bại', counts.failed, 'Cần kiểm tra'],
  ];
  $('#stat-grid').innerHTML = stats.map(([name, value, hint]) => `<article class="stat-card"><span>${esc(name)}</span><strong>${Number(value || 0)}</strong><small>${esc(hint)}</small></article>`).join('');
  $('#worker-status').textContent = result.workerOnline ? '● Worker trực tuyến' : '● Worker ngoại tuyến';
  $('#worker-status').className = `status-pill ${result.workerOnline ? 'ok' : 'warn'}`;
  $('#worker-card').innerHTML = `<div class="worker-details">
    <div class="worker-line"><span>Trạng thái</span><strong>${result.workerOnline ? 'Đang hoạt động' : 'Chưa có heartbeat'}</strong></div>
    <div class="worker-line"><span>Worker được bật</span><strong>${result.workerEnabled ? 'Có' : 'Không'}</strong></div>
    <div class="worker-line"><span>Đang chờ</span><strong>${counts.queued}</strong></div>
    <div class="worker-line"><span>Đang xử lý</span><strong>${counts.running}</strong></div>
    <p class="fine-print">Worker chỉ chạy khi MULTI_AGENT_ENABLED=true và OPENAI_API_KEY đã được cấu hình trong môi trường máy chủ.</p>
  </div>`;
  renderTaskTable('#recent-tasks', result.recentTasks, true);
}

function renderTaskTable(selector, tasks, compact = false) {
  const target = $(selector);
  if (!tasks?.length) {
    target.innerHTML = '<div class="empty-state">Chưa có công việc nào.</div>';
    return;
  }
  target.innerHTML = `<table class="data-table"><thead><tr><th>Công việc</th><th>Agent</th><th>Trạng thái</th><th>${compact ? 'Ngày tạo' : 'Cập nhật'}</th></tr></thead><tbody>${tasks.map((task) => `
    <tr><td><button class="table-action row-title" data-task="${esc(task.id)}">${esc(task.title)}</button><span class="row-sub">${esc(task.workflow_key || task.task_type || '')}</span></td>
    <td>${esc(task.agent_name || task.agent_key || '')}</td><td>${statusBadge(task.status)}</td><td>${date(compact ? task.created_at : task.updated_at)}</td></tr>`).join('')}</tbody></table>`;
}

async function loadTasks() {
  const query = state.taskFilter ? `?status=${encodeURIComponent(state.taskFilter)}` : '';
  const result = await api(`/api/tasks${query}`);
  renderTaskTable('#tasks-list', result.tasks);
}

async function loadAgents() {
  const result = await api('/api/agents');
  state.agents = result.agents;
  $('#agents-grid').innerHTML = result.agents.length ? result.agents.map((agent) => `
    <article class="agent-card">
      <div class="agent-top"><div class="agent-avatar">${esc(agent.name.slice(0, 1).toUpperCase())}</div>${agent.enabled ? '<span class="badge approved">Đang bật</span>' : '<span class="badge rejected">Đã tắt</span>'}</div>
      <h3>${esc(agent.name)}</h3><div class="muted">${esc(agent.role)}</div><p>${esc(agent.description)}</p>
      <div class="agent-meta"><small>${esc(agent.agent_key)} · ${esc(agent.model_override || 'Model mặc định')}</small><button data-edit-agent="${esc(agent.agent_key)}">Chỉnh sửa</button></div>
    </article>`).join('') : '<div class="empty-state">Chưa có Agent.</div>';
}

async function loadWorkflows() {
  const result = await api('/api/workflows');
  state.workflows = result.workflows;
  const enabled = result.workflows.filter((workflow) => workflow.enabled);
  $('#workflow-select').innerHTML = enabled.map((workflow) => `<option value="${esc(workflow.workflow_key)}">${esc(workflow.name)}</option>`).join('');
  $('#workflows-grid').innerHTML = result.workflows.length ? result.workflows.map((workflow) => `
    <article class="workflow-card"><div class="agent-top"><span class="eyebrow">${esc(workflow.workflow_key)}</span>${workflow.enabled ? '<span class="badge approved">Đang bật</span>' : '<span class="badge rejected">Đã tắt</span>'}</div><h3>${esc(workflow.name)}</h3><p>${esc(workflow.description)}</p>
      <div class="workflow-steps">${(workflow.steps || []).map((step, index) => `${index ? '<span class="step-arrow">→</span>' : ''}<span class="step-chip">${esc(state.agents.find((agent) => agent.agent_key === step)?.name || step)}</span>`).join('')}</div>
      <div class="workflow-card-actions"><small>${(workflow.steps || []).length} bước</small><button data-edit-workflow="${esc(workflow.workflow_key)}">Chỉnh sửa</button></div>
    </article>`).join('') : '<div class="empty-state">Chưa có Workflow.</div>';
}

async function loadAudit() {
  const result = await api('/api/audit');
  $('#audit-list').innerHTML = result.entries.length ? result.entries.map((entry) => `
    <div class="audit-item"><div><strong>${esc(entry.action)}</strong><small>${esc(entry.actor)} · ${esc(entry.target_type)} ${esc(entry.target_id)}</small></div><time>${date(entry.created_at)}</time></div>`).join('') : '<div class="empty-state">Chưa có hoạt động được ghi nhận.</div>';
}

function openTaskDialog() {
  $('#task-form').reset();
  $('#priority-value').textContent = '50';
  $('#task-form-error').textContent = '';
  $('#task-dialog').showModal();
}

function openAgentDialog(agent = null) {
  const form = $('#agent-form');
  form.reset();
  state.editingAgent = agent?.agent_key || null;
  $('#agent-dialog-title').textContent = agent ? `Chỉnh sửa ${agent.name}` : 'Thêm Agent mới';
  form.elements.agentKey.disabled = Boolean(agent);
  form.elements.agentKey.value = agent?.agent_key || '';
  form.elements.name.value = agent?.name || '';
  form.elements.role.value = agent?.role || '';
  form.elements.description.value = agent?.description || '';
  form.elements.systemPrompt.value = agent?.system_prompt || '';
  form.elements.modelOverride.value = agent?.model_override || '';
  form.elements.enabled.checked = agent?.enabled ?? true;
  form.elements.sortOrder.value = agent?.sort_order ?? 100;
  $('#agent-form-error').textContent = '';
  $('#agent-dialog').showModal();
}

function renderWorkflowAgentOptions() {
  const host = $('#workflow-agent-options');
  const allAgents = state.agents;
  if (!allAgents.length) {
    host.innerHTML = '<p class="muted">Cần bật ít nhất một Agent trước khi tạo Workflow.</p>';
    return;
  }
  host.innerHTML = allAgents.map((agent) => {
    const order = state.workflowSelected.indexOf(agent.agent_key);
    const disabled = !agent.enabled && order < 0;
    return `<label class="workflow-agent-option"><input type="checkbox" data-workflow-agent="${esc(agent.agent_key)}" ${order >= 0 ? 'checked' : ''} ${disabled ? 'disabled' : ''}><span><strong>${esc(agent.name)}</strong><small>${esc(agent.role)}${agent.enabled ? '' : ' · Đang tắt'}</small></span>${order >= 0 ? `<b>${order + 1}</b>` : '<b>＋</b>'}</label>`;
  }).join('');
}

function openWorkflowDialog(workflow = null) {
  const form = $('#workflow-form');
  form.reset();
  state.editingWorkflow = workflow?.workflow_key || null;
  state.workflowSelected = [...(workflow?.steps || [])];
  $('#workflow-dialog-title').textContent = workflow ? `Chỉnh sửa ${workflow.name}` : 'Tạo Workflow';
  form.elements.workflowKey.disabled = Boolean(workflow);
  form.elements.workflowKey.value = workflow?.workflow_key || '';
  form.elements.name.value = workflow?.name || '';
  form.elements.description.value = workflow?.description || '';
  form.elements.enabled.checked = workflow?.enabled ?? true;
  $('#workflow-form-error').textContent = '';
  renderWorkflowAgentOptions();
  $('#workflow-dialog').showModal();
}

async function openTaskDetail(id) {
  const data = await api(`/api/tasks/${encodeURIComponent(id)}`);
  const { task, runs, events } = data;
  $('#detail-title').textContent = task.title;
  $('#task-detail-content').innerHTML = `
    <div class="detail-grid">
      <div class="detail-box"><small>Trạng thái</small><p>${statusBadge(task.status)}</p></div>
      <div class="detail-box"><small>Quy trình</small><p>${esc(task.workflow_key || task.task_type)}</p></div>
      <div class="detail-box"><small>Người tạo</small><p>${esc(task.created_by)}</p></div>
      <div class="detail-box"><small>Ngày tạo</small><p>${date(task.created_at)}</p></div>
    </div>
    <div class="detail-section"><h3>Yêu cầu ban đầu</h3><div class="output-text">${esc(task.prompt)}</div></div>
    ${task.context_text ? `<div class="detail-section"><h3>Ngữ cảnh bổ sung</h3><div class="output-text">${esc(task.context_text)}</div></div>` : ''}
    ${task.error_message ? `<div class="notice error">${esc(task.error_message)}</div>` : ''}
    ${task.result_text ? `<div class="detail-section"><h3>Đầu ra hiện tại</h3><div class="output-text">${esc(task.result_text)}</div></div>` : ''}
    <div class="detail-section"><h3>Lịch sử Agent (${runs.length})</h3>${runs.length ? runs.map((run, index) => `<article class="detail-box run-card"><div class="event-item"><strong>${esc(state.agents.find((a) => a.agent_key === run.agent_key)?.name || run.agent_key)} · Bước ${index + 1}</strong>${statusBadge(run.status)}</div><small>${esc(run.model)} · ${Number(run.input_tokens)} input / ${Number(run.output_tokens)} output tokens · ${date(run.started_at)}</small>${run.output_text ? `<div class="output-text run-output">${esc(run.output_text)}</div>` : ''}${run.error_message ? `<p class="form-error">${esc(run.error_message)}</p>` : ''}</article>`).join('') : '<p class="muted">Chưa có lượt chạy.</p>'}</div>
    <div class="detail-section"><h3>Sự kiện hệ thống</h3><div class="event-list">${events.map((event) => `<div class="event-item"><div><strong>${esc(event.event_type)}</strong><span> · ${esc(event.actor || event.agent_key || 'Worker')}</span></div><time>${date(event.created_at)}</time></div>`).join('')}</div></div>`;
  const actions = $('#task-detail-actions');
  actions.innerHTML = '';
  if (task.status === 'awaiting_approval') {
    const reject = document.createElement('button'); reject.className = 'danger-button'; reject.textContent = 'Từ chối';
    reject.addEventListener('click', () => decideTask(task.id, 'reject'));
    const approve = document.createElement('button'); approve.className = 'good-button'; approve.textContent = 'Duyệt đầu ra';
    approve.addEventListener('click', () => decideTask(task.id, 'approve'));
    actions.append(reject, approve);
  } else if (task.status === 'failed') {
    const retry = document.createElement('button'); retry.className = 'primary'; retry.textContent = 'Chạy lại công việc';
    retry.addEventListener('click', () => retryTask(task.id));
    actions.append(retry);
  }
  $('#detail-dialog').showModal();
}

async function decideTask(id, action) {
  const note = window.prompt(action === 'approve' ? 'Ghi chú phê duyệt (không bắt buộc):' : 'Lý do từ chối:');
  if (note === null) return;
  if (action === 'reject' && note.trim().length < 3) return notify('Lý do từ chối cần ít nhất 3 ký tự.', true);
  await api(`/api/tasks/${id}/${action}`, { method: 'POST', body: { note: note.trim() } });
  $('#detail-dialog').close();
  notify(action === 'approve' ? 'Đã ghi nhận quyết định duyệt.' : 'Đã ghi nhận quyết định từ chối.');
  await loadDashboard();
  if (state.view === 'tasks') await loadTasks();
}

async function retryTask(id) {
  await api(`/api/tasks/${id}/retry`, { method: 'POST', body: {} });
  $('#detail-dialog').close();
  notify('Đã đưa công việc trở lại hàng đợi.');
  await loadDashboard();
  if (state.view === 'tasks') await loadTasks();
}

$('#login-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  $('#login-error').textContent = '';
  const form = new FormData(event.currentTarget);
  try {
    const result = await api('/api/auth/login', { method: 'POST', body: { username: form.get('username'), password: form.get('password') } });
    state.csrf = result.csrfToken;
    state.user = result.username;
    showApp();
    await loadInitialData();
  } catch (error) { $('#login-error').textContent = error.message; }
});

$('#logout-button').addEventListener('click', async () => {
  try { await api('/api/auth/logout', { method: 'POST', body: {} }); } catch { /* session may already have expired */ }
  showLogin();
});
$('#main-nav').addEventListener('click', (event) => {
  const button = event.target.closest('[data-view]');
  if (button) setView(button.dataset.view);
});
document.addEventListener('click', async (event) => {
  const taskButton = event.target.closest('[data-task]');
  const goto = event.target.closest('[data-goto]');
  const close = event.target.closest('[data-close]');
  const edit = event.target.closest('[data-edit-agent]');
  try {
    if (taskButton) await openTaskDetail(taskButton.dataset.task);
    if (goto) setView(goto.dataset.goto);
    if (close) $(`#${close.dataset.close}`).close();
    if (edit) openAgentDialog(state.agents.find((agent) => agent.agent_key === edit.dataset.editAgent));
    if (editWorkflow) openWorkflowDialog(state.workflows.find((workflow) => workflow.workflow_key === editWorkflow.dataset.editWorkflow));
  } catch (error) { notify(error.message, true); }
});

$('#new-task-top').addEventListener('click', openTaskDialog);
$('#new-task-button').addEventListener('click', openTaskDialog);
$('#create-agent-button').addEventListener('click', () => openAgentDialog());
$('#create-workflow-button').addEventListener('click', () => openWorkflowDialog());
$('#refresh-dashboard').addEventListener('click', () => loadDashboard().catch((error) => notify(error.message, true)));
$('#refresh-tasks').addEventListener('click', () => loadTasks().catch((error) => notify(error.message, true)));
$('#refresh-audit').addEventListener('click', () => loadAudit().catch((error) => notify(error.message, true)));
$('#task-filter').addEventListener('change', (event) => { state.taskFilter = event.target.value; loadTasks().catch((error) => notify(error.message, true)); });
$('#priority-input').addEventListener('input', (event) => { $('#priority-value').textContent = event.target.value; });

$('#task-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  $('#task-form-error').textContent = '';
  const form = new FormData(event.currentTarget);
  try {
    await api('/api/tasks', { method: 'POST', body: {
      title: form.get('title'), workflowKey: form.get('workflowKey'), prompt: form.get('prompt'),
      contextText: form.get('contextText'), priority: Number(form.get('priority')),
    } });
    $('#task-dialog').close();
    notify('Đã tạo công việc và đưa vào hàng đợi.');
    await loadDashboard();
    if (state.view === 'tasks') await loadTasks();
  } catch (error) { $('#task-form-error').textContent = error.message; }
});

$('#agent-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  $('#agent-form-error').textContent = '';
  const form = new FormData(event.currentTarget);
  const body = {
    name: form.get('name'), role: form.get('role'), description: form.get('description'),
    systemPrompt: form.get('systemPrompt'), modelOverride: form.get('modelOverride'),
    enabled: form.get('enabled') === 'on', sortOrder: Number(form.get('sortOrder')),
  };
  try {
    if (state.editingAgent) {
      await api(`/api/agents/${state.editingAgent}`, { method: 'PATCH', body });
    } else {
      body.agentKey = form.get('agentKey');
      await api('/api/agents', { method: 'POST', body });
    }
    $('#agent-dialog').close();
    notify('Đã lưu cấu hình Agent.');
    await loadAgents();
    await loadWorkflows();
  } catch (error) { $('#agent-form-error').textContent = error.message; }
});


$('#workflow-agent-options').addEventListener('change', (event) => {
  const checkbox = event.target.closest('[data-workflow-agent]');
  if (!checkbox) return;
  const key = checkbox.dataset.workflowAgent;
  if (checkbox.checked) {
    if (state.workflowSelected.length >= 5) {
      checkbox.checked = false;
      return notify('Mỗi Workflow tối đa 5 Agent.', true);
    }
    if (!state.workflowSelected.includes(key)) state.workflowSelected.push(key);
  } else {
    state.workflowSelected = state.workflowSelected.filter((item) => item !== key);
  }
  renderWorkflowAgentOptions();
});

$('#workflow-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  $('#workflow-form-error').textContent = '';
  const form = new FormData(event.currentTarget);
  if (!state.workflowSelected.length) {
    $('#workflow-form-error').textContent = 'Chọn ít nhất một Agent. Hãy tích chọn theo đúng thứ tự chạy.';
    return;
  }
  const body = {
    name: form.get('name'), description: form.get('description'),
    steps: [...state.workflowSelected], enabled: form.get('enabled') === 'on',
  };
  try {
    if (state.editingWorkflow) {
      await api(`/api/workflows/${state.editingWorkflow}`, { method: 'PATCH', body });
    } else {
      body.workflowKey = form.get('workflowKey');
      await api('/api/workflows', { method: 'POST', body });
    }
    $('#workflow-dialog').close();
    notify('Đã lưu Workflow.');
    await loadWorkflows();
  } catch (error) { $('#workflow-form-error').textContent = error.message; }
});

window.addEventListener('focus', () => {
  if (!$('#app-view').classList.contains('hidden') && state.view === 'dashboard') loadDashboard().catch(() => {});
});
window.setInterval(() => {
  if ($('#app-view').classList.contains('hidden')) return;
  if (state.view === 'dashboard') loadDashboard().catch(() => {});
  if (state.view === 'tasks') loadTasks().catch(() => {});
}, 15000);

bootstrap();
