(() => {
  'use strict';
  const staticSite = location.hostname === 'hendydichvumang-szad.onrender.com';
  function normalizeApiOrigin(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    try {
      const url = new URL(raw);
      if (url.protocol !== 'https:' || !url.hostname || url.username || url.password ||
          (url.pathname && url.pathname !== '/') || url.search || url.hash) return '';
      return url.origin;
    } catch (_) { return ''; }
  }
  const override = normalizeApiOrigin(window.HENDY_RUNTIME_CONFIG?.apiBaseUrl);
  window.HENDY_API_BASE = override || (staticSite ? 'https://hendydichvumang-telegram-bot.onrender.com' : '');
})();
