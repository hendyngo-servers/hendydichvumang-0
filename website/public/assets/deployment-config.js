(() => {
  'use strict';
  // Public HTTPS origin only. Never put Bot tokens or private credentials here.
  // Leave empty to keep the currently configured Render Bot API endpoint.
  window.HENDY_RUNTIME_CONFIG = Object.freeze({
    apiBaseUrl: ''
  });
})();
