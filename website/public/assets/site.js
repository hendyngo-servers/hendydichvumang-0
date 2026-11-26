(() => {
  'use strict';
  const apiBase = window.HENDY_WEB_CONFIG?.apiBaseUrl ?? '';
  const status = document.getElementById('server-status');
  const nav = document.getElementById('site-nav');
  const toggle = document.querySelector('.nav-toggle');
  const year = document.getElementById('year');
  if (year) year.textContent = String(new Date().getFullYear());
  toggle?.addEventListener('click', () => {
    const open = nav.classList.toggle('open');
    toggle.setAttribute('aria-expanded', String(open));
  });
  nav?.querySelectorAll('a').forEach(link => link.addEventListener('click', () => {
    nav.classList.remove('open');
    toggle?.setAttribute('aria-expanded', 'false');
  }));
  async function checkHealth() {
    if (!status) return;
    status.className = 'service-state';
    status.innerHTML = '<span class="status-dot"></span> Đang kiểm tra kết nối máy chủ';
    try {
      const response = await fetch(apiBase + '/api/health', {
        method: 'GET', headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'omit'
      });
      const data = await response.json();
      if (!response.ok || data.status !== 'ok' || data.database !== 'ok') throw new Error('Backend chưa sẵn sàng');
      status.className = 'service-state online';
      status.innerHTML = '<span class="status-dot"></span> API & cơ sở dữ liệu đang phản hồi';
    } catch (_) {
      status.className = 'service-state offline';
      status.innerHTML = '<span class="status-dot"></span> Chưa xác nhận được kết nối máy chủ';
    }
  }
  checkHealth();
  window.setInterval(checkHealth, 60000);
})();
