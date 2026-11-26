(() => {
  'use strict';
  const API_BASE = String(window.HENDY_STORE_CONFIG?.apiBaseUrl ?? '').replace(/\/+$/, '');
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
  const state = { releases: [], category: 'all', platform: 'all', previousFocus: null };
  const platformNames = { android:'Android', windows:'Windows', macos:'macOS', ios:'iOS', linux:'Linux', web:'Web' };
  const platformIcons = { android:'fa-brands fa-android', windows:'fa-brands fa-windows', macos:'fa-brands fa-apple', ios:'fa-brands fa-apple', linux:'fa-brands fa-linux', web:'fa-solid fa-globe' };
  const extensions = { android:['apk'], windows:['exe','msi','msix','zip'], macos:['dmg','pkg','zip'], ios:['ipa'], linux:['appimage','deb','rpm','tar.gz'], web:['zip'] };

  function safeHttps(value) {
    const raw = String(value || '').trim();
    if (!raw || /[\u0000-\u0020\u007f\\]/.test(raw) || raw.startsWith('//')) return '';
    try {
      const url = new URL(raw);
      if (url.protocol !== 'https:' || !url.hostname || url.username || url.password) return '';
      return url.href;
    } catch (_) { return ''; }
  }
  function fileExtension(filename) {
    const name = String(filename || '').toLowerCase();
    return name.endsWith('.tar.gz') ? 'tar.gz' : name.split('.').pop();
  }
  function validRelease(item) {
    const platform = String(item?.platform || '').toLowerCase();
    const extension = fileExtension(item?.filename);
    return Boolean(item && typeof item === 'object' &&
      Object.prototype.hasOwnProperty.call(extensions, platform) &&
      extensions[platform].includes(extension) &&
      safeHttps(item.downloadUrl) &&
      /^[a-f0-9]{64}$/i.test(String(item.sha256 || '')) &&
      String(item.version || '').trim());
  }
  function formatBytes(value) {
    const bytes = Number(value);
    if (!Number.isFinite(bytes) || bytes < 0) return '—';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    if (bytes < 1024 ** 3) return (bytes / 1024 ** 2).toFixed(2) + ' MB';
    return (bytes / 1024 ** 3).toFixed(2) + ' GB';
  }
  function detectDevice() {
    const platform = String(navigator.userAgentData?.platform || navigator.platform || navigator.userAgent || '').toLowerCase();
    const ua = String(navigator.userAgent || '').toLowerCase();
    if (/android/.test(ua)) return { os:'android', mobile:true };
    if (/iphone|ipad|ipod/.test(ua) || (/mac/.test(platform) && navigator.maxTouchPoints > 1)) return { os:'ios', mobile:true };
    if (/windows/.test(platform) || /windows/.test(ua)) return { os:'windows', mobile:false };
    if (/mac/.test(platform) || /macintosh/.test(ua)) return { os:'macos', mobile:false };
    if (/linux/.test(platform) || /linux/.test(ua)) return { os:'linux', mobile:false };
    return { os:'web', mobile: matchMedia('(max-width: 700px)').matches };
  }
  function setApiStatus(text, status) {
    const node = $('#api-status');
    if (!node) return;
    node.className = 'api-status ' + status;
    node.replaceChildren();
    const dot = document.createElement('i');
    dot.className = 'fa-solid fa-circle';
    const label = document.createElement('span');
    label.textContent = text;
    node.append(dot, label);
  }
  function categoryName(value) {
    return ({tools:'Công cụ',codes:'Code',vip:'VIP',minigame:'Mini Game',otp:'OTP',proxy:'Proxy'})[value] || 'Ứng dụng';
  }
  function createEmptyState(title, detail, iconName = 'fa-box-open') {
    const node = document.createElement('div');
    node.className = 'empty-state';
    const glyph = document.createElement('i');
    glyph.className = 'fa-solid ' + iconName;
    const heading = document.createElement('b');
    heading.textContent = title;
    const paragraph = document.createElement('span');
    paragraph.textContent = detail;
    node.append(glyph, heading, paragraph);
    return node;
  }
  function makeIcon(item, className = 'app-icon') {
    const wrapper = document.createElement('div');
    wrapper.className = className;
    const imageUrl = safeHttps(item.imageUrl);
    if (imageUrl) {
      const image = document.createElement('img');
      image.src = imageUrl;
      image.alt = '';
      image.loading = 'lazy';
      image.referrerPolicy = 'no-referrer';
      image.addEventListener('error', () => {
        wrapper.replaceChildren();
        const icon = document.createElement('i');
        icon.className = platformIcons[item.platform] || 'fa-solid fa-cube';
        wrapper.append(icon);
      }, { once:true });
      wrapper.append(image);
    } else {
      const icon = document.createElement('i');
      icon.className = platformIcons[item.platform] || 'fa-solid fa-cube';
      wrapper.append(icon);
    }
    return wrapper;
  }
  function makeCard(item) {
    const article = document.createElement('article');
    article.className = 'app-card';
    const top = document.createElement('div');
    top.className = 'card-top';
    top.append(makeIcon(item));
    const heading = document.createElement('div');
    heading.className = 'app-heading';
    const name = document.createElement('h3');
    name.textContent = item.name || 'Ứng dụng HENDY';
    const version = document.createElement('p');
    version.textContent = (item.version || '—') + ' · ' + (platformNames[item.platform] || item.platform || '—');
    heading.append(name, version);
    top.append(heading);
    article.append(top);
    const badges = document.createElement('div');
    badges.className = 'release-badges';
    [categoryName(item.category), String(item.channel || 'stable').toUpperCase(), item.filename || ''].filter(Boolean).forEach(label => {
      const badge = document.createElement('span');
      badge.textContent = label;
      badges.append(badge);
    });
    article.append(badges);
    const description = document.createElement('p');
    description.className = 'release-description';
    description.textContent = item.description || item.releaseNotes || 'Bản phát hành do HENDY quản lý.';
    article.append(description);
    const footer = document.createElement('div');
    footer.className = 'card-footer';
    const platform = document.createElement('span');
    platform.className = 'card-platform';
    const platformIcon = document.createElement('i');
    platformIcon.className = platformIcons[item.platform] || 'fa-solid fa-cube';
    const platformLabel = document.createElement('span');
    platformLabel.textContent = platformNames[item.platform] || item.platform || '—';
    platform.append(platformIcon, platformLabel);
    const open = document.createElement('button');
    open.className = 'card-open';
    open.type = 'button';
    open.textContent = 'Chi tiết & tải xuống';
    open.addEventListener('click', () => showRelease(item));
    footer.append(platform, open);
    article.append(footer);
    return article;
  }
  function filteredReleases() {
    const term = String($('#search-input')?.value || '').trim().toLocaleLowerCase('vi');
    const items = state.releases.filter(item => {
      const categoryMatch = state.category === 'all' || String(item.category || '') === state.category;
      const platformMatch = state.platform === 'all' || String(item.platform || '') === state.platform;
      const searchable = [item.name,item.description,item.version,item.platform,item.category,item.channel,item.filename,item.releaseNotes]
        .map(value => String(value || '').toLocaleLowerCase('vi')).join(' ');
      return categoryMatch && platformMatch && (!term || searchable.includes(term));
    });
    const sort = String($('#sort-select')?.value || 'default');
    const collator = new Intl.Collator('vi', {numeric:true, sensitivity:'base'});
    if (sort === 'name') items.sort((left,right) => collator.compare(String(left.name || ''), String(right.name || '')));
    if (sort === 'version') items.sort((left,right) => collator.compare(String(right.version || ''), String(left.version || '')));
    if (sort === 'platform') items.sort((left,right) => collator.compare(
      String(platformNames[left.platform] || left.platform || ''),
      String(platformNames[right.platform] || right.platform || '')
    ));
    return items;
  }
  function renderCatalog() {
    const grid = $('#app-grid');
    if (!grid) return;
    const items = filteredReleases();
    grid.replaceChildren(...(items.length ? items.map(makeCard) : [createEmptyState(
      state.releases.length ? 'Không tìm thấy ứng dụng phù hợp' : 'Chưa có bản phát hành công khai',
      state.releases.length ? 'Hãy đổi từ khóa hoặc bộ lọc nền tảng/danh mục.' : 'Bản phát hành sẽ xuất hiện tại đây sau khi hồ sơ sản phẩm, tệp cài đặt và kiểm duyệt đã hoàn tất.'
    )]));
    const count = $('#catalog-count');
    if (count) count.textContent = 'Đang hiển thị ' + items.length + ' / ' + state.releases.length + ' bản phát hành';
  }
  function createAction(label, href, className = 'primary-action') {
    const link = document.createElement('a');
    link.className = className;
    link.href = href;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    link.textContent = label;
    return link;
  }
  function buildDownloadActions(item) {
    const actions = document.createElement('div');
    actions.className = 'detail-actions';
    const href = safeHttps(item.downloadUrl);
    const platform = String(item.platform || '').toLowerCase();
    const extension = fileExtension(item.filename);
    if (!href || !validRelease(item)) {
      const unavailable = document.createElement('span');
      unavailable.className = 'disabled-note';
      unavailable.textContent = 'Bản tải xuống chưa đạt điều kiện xác minh.';
      actions.append(unavailable);
      return actions;
    }
    let label = 'Tải ' + (platformNames[platform] || platform) + ' · ' + extension.toUpperCase();
    if (platform === 'android') label = 'Tải APK cho Android';
    if (platform === 'windows') label = 'Tải bộ cài Windows · ' + extension.toUpperCase();
    if (platform === 'macos') label = 'Tải bộ cài macOS · ' + extension.toUpperCase();
    if (platform === 'ios') label = 'Tải gói iOS · IPA';
    if (platform === 'linux') label = 'Tải gói Linux · ' + extension.toUpperCase();
    if (platform === 'web') label = 'Tải gói Web · ZIP';
    actions.append(createAction(label, href));
    if (platform === 'ios' && item.otaAvailable === true) {
      const manifest = safeHttps(item.manifestUrl);
      if (manifest) {
        const ota = document.createElement('a');
        ota.className = 'secondary-action';
        ota.href = 'itms-services://?action=download-manifest&url=' + encodeURIComponent(manifest);
        ota.textContent = 'Cài đặt iOS qua OTA';
        actions.append(ota);
      }
    }
    return actions;
  }
  function openModal() {
    const modal = $('#release-modal');
    state.previousFocus = document.activeElement;
    modal.hidden = false;
    document.body.classList.add('modal-open');
    $('.modal-close', modal)?.focus();
  }
  function closeModal() {
    const modal = $('#release-modal');
    modal.hidden = true;
    document.body.classList.remove('modal-open');
    if (state.previousFocus instanceof HTMLElement) state.previousFocus.focus();
  }
  function showRelease(item) {
    const content = $('#modal-content');
    content.replaceChildren();
    const header = document.createElement('div');
    header.className = 'detail-header';
    header.append(makeIcon(item, 'app-icon'));
    const titleBlock = document.createElement('div');
    titleBlock.className = 'detail-title';
    const title = document.createElement('h2');
    title.id = 'modal-title';
    title.textContent = item.name || 'Ứng dụng HENDY';
    const subtitle = document.createElement('p');
    subtitle.textContent = (item.version || '—') + ' · ' + (platformNames[item.platform] || item.platform) + ' · ' + String(item.channel || 'stable').toUpperCase();
    titleBlock.append(title, subtitle);
    header.append(titleBlock);
    content.append(header);
    const meta = document.createElement('div');
    meta.className = 'detail-meta';
    [categoryName(item.category),item.filename || '',formatBytes(item.sizeBytes)].filter(Boolean).forEach(value => {
      const badge = document.createElement('span');
      badge.textContent = value;
      meta.append(badge);
    });
    content.append(meta);
    const description = document.createElement('p');
    description.className = 'detail-copy';
    description.textContent = item.description || '';
    content.append(description);
    if (Array.isArray(item.screenshots) && item.screenshots.length) {
      const screenshots = document.createElement('div');
      screenshots.className = 'screenshot-strip';
      item.screenshots.slice(0, 8).forEach((raw, index) => {
        const value = typeof raw === 'string' ? raw : raw?.url;
        const href = safeHttps(value);
        if (!href) return;
        const image = document.createElement('img');
        image.src = href;
        image.alt = (item.name || 'Ứng dụng HENDY') + ' · ảnh ' + (index + 1);
        image.loading = 'lazy';
        image.referrerPolicy = 'no-referrer';
        screenshots.append(image);
      });
      if (screenshots.childElementCount) content.append(screenshots);
    }
    const changelogHeading = document.createElement('h3');
    changelogHeading.className = 'detail-section-title';
    changelogHeading.textContent = 'Ghi chú phát hành';
    const changelog = document.createElement('p');
    changelog.className = 'detail-copy';
    changelog.textContent = item.releaseNotes || 'Bản phát hành chưa có ghi chú.';
    content.append(changelogHeading, changelog, buildDownloadActions(item));
    const checksum = document.createElement('div');
    checksum.className = 'detail-checksum';
    const checksumLabel = document.createElement('small');
    checksumLabel.textContent = 'SHA-256 CHECKSUM';
    const checksumValue = document.createElement('code');
    checksumValue.textContent = item.sha256 || '—';
    const copyButton = document.createElement('button');
    copyButton.type = 'button';
    copyButton.textContent = 'Sao chép SHA-256';
    copyButton.addEventListener('click', async () => {
      try { await navigator.clipboard.writeText(String(item.sha256 || '')); copyButton.textContent = 'Đã sao chép'; }
      catch (_) { copyButton.textContent = 'Không thể sao chép'; }
    });
    checksum.append(checksumLabel, checksumValue, copyButton);
    content.append(checksum);
    openModal();
  }
  async function loadReleases() {
    const refresh = $('#refresh-catalog');
    if (refresh) refresh.disabled = true;
    setApiStatus('Đang kết nối API', 'pending');
    try {
      const response = await fetch(API_BASE + '/api/public/releases', {
        method:'GET', mode:'cors', credentials:'omit',
        headers:{Accept:'application/json'}, cache:'no-store'
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || payload.ok !== true || !Array.isArray(payload.releases)) {
        throw new Error(payload.error || 'Không thể đọc danh mục phát hành từ HENDY API.');
      }
      state.releases = payload.releases.filter(validRelease);
      setApiStatus('API đã kết nối', 'online');
      renderCatalog();
    } catch (_) {
      state.releases = [];
      setApiStatus('Chưa kết nối được API', 'offline');
      const grid = $('#app-grid');
      if (grid) grid.replaceChildren(createEmptyState(
        'Kho ứng dụng tạm thời chưa kết nối',
        'Website không có dữ liệu mẫu thay thế. Kiểm tra Backend Render, CORS và kết nối PostgreSQL rồi thử tải lại.',
        'fa-triangle-exclamation'
      ));
      const count = $('#catalog-count');
      if (count) count.textContent = 'Không đọc được danh mục';
    } finally { if (refresh) refresh.disabled = false; }
  }
  function setFilter(group, value) {
    state[group] = value;
    const attribute = group === 'category' ? '[data-category]' : '[data-platform]';
    $$(attribute).forEach(button => button.classList.toggle('active', button.dataset[group] === value));
    renderCatalog();
  }
  const device = detectDevice();
  const deviceHint = $('#device-hint');
  if (deviceHint) deviceHint.textContent = 'Đã nhận diện: ' + (platformNames[device.os] || 'trình duyệt') + ' · ' + (device.mobile ? 'thiết bị di động' : 'thiết bị máy tính') + '. Bạn vẫn có thể chọn nền tảng khác.';
  $('#year').textContent = String(new Date().getFullYear());
  $('#search-input')?.addEventListener('input', renderCatalog);
  $('#sort-select')?.addEventListener('change', renderCatalog);
  $$('[data-category]').forEach(button => button.addEventListener('click', () => setFilter('category', button.dataset.category || 'all')));
  $$('[data-platform]').forEach(button => button.addEventListener('click', () => setFilter('platform', button.dataset.platform || 'all')));
  $('#refresh-catalog')?.addEventListener('click', loadReleases);
  $('#copy-api-example')?.addEventListener('click', async event => {
    const text = $('#api-example')?.textContent || '';
    const button = event.currentTarget;
    try { await navigator.clipboard.writeText(text); button.textContent = 'Đã sao chép'; }
    catch (_) { button.textContent = 'Không thể sao chép'; }
  });
  $('#mobile-menu-toggle')?.addEventListener('click', event => {
    const nav = $('.top-nav');
    const open = nav.classList.toggle('open');
    event.currentTarget.setAttribute('aria-expanded', String(open));
  });
  $$('[data-close-modal]').forEach(button => button.addEventListener('click', closeModal));
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && !$('#release-modal').hidden) closeModal();
    if (event.key === '/' && !['INPUT','TEXTAREA'].includes(document.activeElement?.tagName)) {
      event.preventDefault();
      $('#search-input')?.focus();
    }
  });
  loadReleases();
})();
