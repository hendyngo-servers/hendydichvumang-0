(() => {
    'use strict';
    const tg = window.Telegram && window.Telegram.WebApp ? window.Telegram.WebApp : null;
    const params = new URLSearchParams(location.search);
    let savedBotKey = '';
    try { savedBotKey = params.has('bot') ? '' : (localStorage.getItem('hendy_bot_key') || ''); } catch (_) {}
    const botKey = params.get('bot') === 'secondary' || (!params.has('bot') && savedBotKey === 'secondary') ? 'secondary' : 'primary';
    const MINI_APP_ROUTE = /^\/(?:miniapp|mini-app|app)\/?$/i.test(location.pathname);
    const state = {boot:null,center:null,orders:[],accounts:[],payments:[],tickets:[],vouchers:{eligibleOrders:[],requests:[]},vip:{},codeTelegram:{enabled:false,services:[],requests:[]},tab:'active',supportType:'support'};
    const $ = (selector,root=document) => root.querySelector(selector);
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const money = value => new Intl.NumberFormat('vi-VN').format(Number(value || 0)) + 'đ';
    const fmtDate = value => { const d = new Date(value); return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString('vi-VN'); };
    function toast(message,kind='success'){
      const el=$('#toast');if(!el)return;el.textContent=message;el.style.borderColor=kind==='error'?'#b52c4d':'#64243a';el.classList.add('show');
      if(tg?.HapticFeedback?.notificationOccurred){try{tg.HapticFeedback.notificationOccurred(kind==='error'?'error':'success');}catch(_){}}
      window.clearTimeout(toast.timer);toast.timer=window.setTimeout(()=>el.classList.remove('show'),2800);
    }
    function setTelegramViewport(){
      if(!tg)return;
      const safe=tg.safeAreaInset||{},content=tg.contentSafeAreaInset||{};
      const pairs=[['--safe-top',Number(safe.top||0)+Number(content.top||0)],['--safe-bottom',Number(safe.bottom||0)+Number(content.bottom||0)]];
      pairs.forEach(([key,val])=>{if(Number.isFinite(val)&&val>=0)document.documentElement.style.setProperty(key,val+'px');});
      const h=Number(tg.viewportStableHeight||tg.viewportHeight||0);
      if(h>200)document.documentElement.style.setProperty('--tg-height',h+'px');
    }
    function initTelegramMiniApp(){
      if(!tg?.initData)return;
      try{tg.ready();tg.expand();tg.setHeaderColor?.('#050509');tg.setBackgroundColor?.('#050509');}catch(_){}
      setTelegramViewport();
      tg.onEvent?.('viewportChanged',setTelegramViewport);
      tg.onEvent?.('safeAreaChanged',setTelegramViewport);
      tg.onEvent?.('contentSafeAreaChanged',setTelegramViewport);
      tg.onEvent?.('themeChanged',()=>{try{tg.setHeaderColor?.('#050509');tg.setBackgroundColor?.('#050509');}catch(_){}});
      if(tg.BackButton && typeof tg.BackButton.onClick==='function'){
        tg.BackButton.onClick(()=>{
          if($('#modal-root .cc-modal')){closeModal();return;}
          state.tab='active';renderDashboard();updateMiniAppButton();
        });
      }
    }
    const apiBase = String(window.HENDY_API_BASE || '').replace(/\/+$/, '');
    async function api(path,options={}){
      const auth = tg?.initData ? {'Authorization':'tma '+tg.initData} : {};
      const headers = {...auth,'X-Telegram-Bot-Key':botKey,...(options.body?{'Content-Type':'application/json'}:{}),...(options.headers||{})};
      const response=await fetch(apiBase + path,{method:options.method||'GET',headers,credentials:'same-origin',cache:'no-store',body:options.body?JSON.stringify(options.body):undefined});
      let data={};try{data=await response.json();}catch(_){throw new Error('Máy chủ trả về dữ liệu không hợp lệ.');}
      if(!response.ok){const error=new Error(data.error||data.message||('HTTP '+response.status));error.status=response.status;throw error;}
      return data;
    }
    async function fetchPublicConfig(){
      try{const r=await fetch('/api/v1/public/config',{headers:{Accept:'application/json'},cache:'no-store'});if(r.ok)return await r.json();}catch(_){}
      return {};
    }
    function loginView(config){
      if(MINI_APP_ROUTE){
        $('#connection-label').textContent='MỞ TRONG TELEGRAM';
        $('#app').innerHTML='<section class="login-card"><div class="cc-notice warning">Mini App cần được mở bằng nút <b>📱 MỞ MINI APP HENDY</b> trong cuộc trò chuyện Telegram để Telegram cấp phiên xác thực tự động.</div></section>';
        return;
      }
      const bot=botKey==='secondary'?(config.secondaryBotUrl||''):(config.botUrl||'');
      const other=botKey==='secondary'?(config.botUrl||''):(config.secondaryBotUrl||'');
      const makeLink=url=>url?url+(url.includes('?')?'&':'?')+'start=web_login':'';
      const link=makeLink(bot),otherLink=makeLink(other);
      $('#connection-label').textContent='CHƯA XÁC THỰC';
      $('#app').innerHTML='<section class="login-card"><div class="cc-page-eyebrow"><span></span> HENDY CYBERTECH · SECURE ACCESS</div><h1>TRUNG TÂM KHÁCH HÀNG</h1><p>Đây là khu vực quản lý hồ sơ, ví tiền, VIP, đơn hàng và yêu cầu hỗ trợ. Vui lòng đăng nhập bằng tài khoản Telegram để tải dữ liệu thành viên của bạn.</p>'+(link?'<a class="cc-primary" href="'+esc(link)+'" target="_blank" rel="noopener noreferrer">🔐 ĐĂNG NHẬP QUA TELEGRAM</a>':'<div class="cc-notice warning">Bot Telegram chưa được cấu hình liên kết công khai. Vui lòng liên hệ CSKH.</div>')+(otherLink?'<a class="cc-secondary" href="'+esc(otherLink)+'" target="_blank" rel="noopener noreferrer" style="display:block;text-align:center;margin-top:8px">↗ ĐĂNG NHẬP QUA BOT CÒN LẠI</a>':'')+'<div class="cc-notice">Mở bot HENDY → gửi /start → yêu cầu liên kết đăng nhập Website. Liên kết có thời hạn ngắn, chỉ dùng một lần và được xác minh ở máy chủ. Không nhập mật khẩu Telegram, OTP hoặc PIN tại website.</div></section>';
    }
    function statusLabel(status){
      const map={pending:'Chờ xác nhận',queued:'Chờ xử lý',processing:'Đang xử lý',partial:'Hoàn tất một phần',completed:'Hoàn tất',failed:'Thất bại',cancelled:'Đã hủy',refunded:'Đã hoàn tiền',rejected:'Từ chối',approved:'Đã duyệt',open:'Mới tạo'};
      return map[String(status||'').toLowerCase()]||String(status||'Đang cập nhật');
    }
    function initials(user){
      const name=String(user.name||user.username||'H').replace(/^@/,'').trim();
      return esc(name.slice(0,1).toUpperCase()||'H');
    }
    function currentOrderList(){
      const selected=state.tab;
      if(selected==='active')return state.orders.filter(x=>['pending','processing'].includes(String(x.status)));
      return state.orders;
    }
    function renderTabs(){
      const selected=state.tab;
      let body='';
      if(selected==='active'||selected==='orders'){
        const list=currentOrderList();
        body='<div class="cc-list-head"><h3>'+(selected==='active'?'🚀 ĐANG CHẠY & CHỜ XÁC NHẬN':'📜 LỊCH SỬ ĐƠN HÀNG')+'</h3><span>'+list.length+' đơn</span></div>'+
          (list.length?list.map(order=>'<article class="cc-order"><div class="cc-order-top"><div><b>'+esc(order.product_name||'Đơn hàng HENDY')+'</b><small>'+esc(order.order_code||'')+' · '+fmtDate(order.created_at)+'</small></div><span class="cc-status">'+esc(statusLabel(order.status))+'</span></div><div class="cc-order-foot"><span>Giá trị đơn: <b>'+money(order.amount)+'</b></span><button class="cc-detail-btn" type="button" data-order-detail="'+esc(order.order_code||'')+'">CHI TIẾT</button></div></article>').join(''):'<div class="cc-empty"><span>📭</span><b>'+(selected==='active'?'Bạn không có đơn hàng nào đang chạy lúc này.':'Chưa có lịch sử đơn hàng.')+'</b><small>Đơn phát sinh sẽ được cập nhật tại đây.</small></div>');
      }else if(selected==='code-telegram'){
        const catalog=state.codeTelegram||{enabled:false,services:[],requests:[]};
        const services=Array.isArray(catalog.services)?catalog.services:[];
        const requests=Array.isArray(catalog.requests)?catalog.requests:[];
        const options=services.map(service=>{
          const min=Number(service.price_min||0),max=Number(service.price_max||min);
          const price=min===max?money(min):money(min)+' – '+money(max);
          const type=service.code_type==='text'?'VIP · mã tin chữ':'Thường · mã video';
          return '<option value="'+Number(service.id)+'">'+esc(service.display_name||service.brand)+' · '+esc(type)+' · '+price+'/tài khoản thành công</option>';
        }).join('');
        const orderForm=catalog.enabled&&services.length
          ? '<form id="code-telegram-order-form" class="cc-form-card"><label class="cc-form-label" for="ct-service">Chọn dịch vụ CODE TELEGRAM</label><select id="ct-service" class="cc-field" required>'+options+'</select><label class="cc-form-label" for="ct-usernames">Tên TK CODE TELEGRAM (mỗi tên một dòng)</label><textarea id="ct-usernames" class="cc-field" rows="5" maxlength="1400" required placeholder="username01&#10;username02&#10;username03"></textarea><small style="color:#9aa4b6;font-size:10px;line-height:1.7;display:block;margin:8px 0">Chỉ nhập tên đăng nhập dài 3–64 ký tự. Không gửi mật khẩu, PIN, mã OTP, cookie hoặc thông tin đăng nhập bí mật. Tối đa theo giới hạn của từng dịch vụ.</small><button class="cc-primary" type="submit">📡 ĐẶT ĐƠN CODE TELEGRAM</button></form>'
          : '<div class="cc-notice warning">'+(catalog.enabled?'Hiện chưa có gói CODE TELEGRAM nào được mở bán.':'CODE TELEGRAM đang tạm ngưng nhận đơn. Vui lòng thử lại sau hoặc liên hệ CSKH.')+'</div>';
        body='<div class="cc-list-head"><h3>📡 ĐẶT ĐƠN CODE TELEGRAM</h3><span>'+services.length+' dịch vụ</span></div><div class="cc-notice">Chọn gói, sau đó nhập tên TK CODE TELEGRAM. HENDY chỉ tạo yêu cầu xử lý; chưa trừ tiền khi đặt đơn. Phí chỉ được thu cho từng tài khoản sau khi Admin xác nhận mã đã xử lý thành công.</div>'+orderForm+
          '<div class="cc-list-head" style="margin-top:18px"><h3>📋 ĐƠN CODE TELEGRAM CỦA BẠN</h3><span>'+requests.length+' đơn</span></div>'+
          (requests.length?requests.map(request=>{
            const accounts=Array.isArray(request.accounts)?request.accounts:[];
            return '<article class="cc-record"><div class="cc-record-top"><div><b>CT'+Number(request.request_id)+' · '+esc(request.service_name_snapshot||request.brand_snapshot||'CODE TELEGRAM')+'</b><small>'+Number(request.account_count||accounts.length)+' tài khoản · '+fmtDate(request.created_at)+'</small></div><span class="cc-status">'+esc(statusLabel(request.status))+'</span></div>'+
              (accounts.length?accounts.map(account=>'<div class="cc-record-foot"><span>👤 <code>'+esc(account.username)+'</code> · '+esc(statusLabel(account.status))+'</span><b>'+(Number(account.charged_amount||0)>0?money(account.charged_amount):'Chưa thu phí')+'</b></div>'+(account.code_value?'<div class="cc-notice">Mã đã xác nhận: <code>'+esc(account.code_value)+'</code></div>':'')+(account.result_note?'<small style="color:#9aa4b6">'+esc(account.result_note)+'</small>':'')).join(''):'<small>Chưa có thông tin tài khoản trong đơn.</small>')+'</article>';
          }).join(''):'<div class="cc-empty"><span>📡</span><b>Bạn chưa có đơn CODE TELEGRAM</b><small>Đơn mới sẽ hiển thị tại đây để bạn theo dõi từng tên tài khoản.</small></div>');
      }else if(selected==='deposit'){
        body='<div class="cc-list-head"><h3>💳 NẠP TIỀN</h3><span>'+state.payments.length+' yêu cầu</span></div><div class="cc-deposit-form"><label class="cc-form-label" for="deposit-amount">Số tiền muốn nạp (VNĐ)</label><input id="deposit-amount" class="cc-field" type="number" min="10000" max="500000000" step="1000" value="50000"><button class="cc-primary" type="button" data-action="deposit">TẠO YÊU CẦU VIETQR</button><small style="color:#9aa4b6;font-size:10px">Ví chỉ được cộng sau khi Admin xác minh giao dịch ngân hàng.</small><div id="payment-result"></div></div>'+
          (state.payments.length?state.payments.map(item=>'<article class="cc-record"><div class="cc-record-top"><div><b>'+money(item.amount)+'</b><small>'+esc(item.payment_id||'')+' · '+esc(item.transfer_content||'')+'</small></div><span class="cc-status">'+esc(statusLabel(item.status))+'</span></div><div class="cc-record-foot"><span>'+esc(item.bank_id||'')+'</span><span>'+fmtDate(item.created_at)+'</span></div></article>').join(''):'<div class="cc-empty"><span>💳</span><b>Chưa có yêu cầu nạp tiền</b><small>Tạo yêu cầu để nhận mã VietQR riêng.</small></div>');
      }else{
        const ledger=state.center?.walletHistory||[],recent=state.center?.recentTransactions||[];
        body='<div class="cc-list-head"><h3>📒 GIAO DỊCH VÀ BIẾN ĐỘNG VÍ</h3><span>'+ledger.length+' biến động</span></div>'+
          (ledger.length?ledger.map(item=>'<article class="cc-record"><div class="cc-record-top"><div><b>'+esc(item.description||item.transaction_type||'Giao dịch')+'</b><small>'+esc(item.reference_code||'')+' · '+esc(item.wallet_type||'MAIN')+'</small></div><b class="'+(Number(item.amount)>=0?'cc-positive':'cc-negative')+'">'+(Number(item.amount)>0?'+':'')+money(item.amount)+'</b></div><div class="cc-record-foot"><span>'+esc(item.transaction_type||'')+'</span><span>'+fmtDate(item.created_at)+'</span></div></article>').join(''):'<div class="cc-empty"><span>📒</span><b>Chưa có biến động ví</b><small>Lịch sử sẽ xuất hiện khi tài khoản phát sinh giao dịch.</small></div>')+
          (recent.length?'<div class="cc-list-head" style="margin-top:16px"><h3>GIAO DỊCH GẦN ĐÂY</h3></div>'+recent.map(item=>'<article class="cc-record"><div class="cc-record-top"><div><b>'+esc(item.type||'Giao dịch')+'</b><small>'+esc(item.reference_code||'')+'</small></div><b>'+money(item.amount)+'</b></div><div class="cc-record-foot"><span>'+esc(item.status||'')+'</span><span>'+fmtDate(item.created_at)+'</span></div></article>').join(''):'');
      }
      return body;
    }
    function renderDashboard(){
      const center=state.center||{},u=center.user||state.boot.user||{},stats=center.statistics||{},ticketCounts=center.tickets||{},vip=state.vip||{};
      const name=u.name||u.username||'HENDY MEMBER';
      const level=Number(vip.currentLevel??u.vipLevel??0);
      const total=Number(vip.totalDeposited??u.totalDeposited??0);
      const progress=Math.max(0,Math.min(100,Number(vip.progressPercent||0)));
      const next=vip.nextThreshold===null?'ĐÃ ĐẠT VIP TỐI ĐA':(vip.nextThreshold!==undefined?money(vip.nextThreshold):'');
      const operations=state.boot.operations||{};
      const operationNotice=operations.active
        ? '<div class="cc-notice warning"><b>'+(operations.mode==='upgrade'?'🚀 HENDY ĐANG NÂNG CẤP':'🛠️ HENDY ĐANG BẢO TRÌ')+'</b><br>'+esc(operations.message||'Một số thao tác đang tạm khóa trong thời gian bảo trì.')+(operations.until?'<br><small>Dự kiến kết thúc: '+fmtDate(operations.until)+'</small>':'')+'<br>Trong thời gian này, bạn vẫn có thể xem lịch sử; các thao tác tạo yêu cầu hoặc thay đổi dữ liệu sẽ tạm dừng.</div>'
        : '';
      const replayUrl=String(state.boot.settings?.replay_live_url||'').trim();
      let replayValid=false;try{const url=new URL(replayUrl);replayValid=url.protocol==='https:'&&Boolean(url.hostname);}catch(_){}
      const eligible=(state.vouchers.eligibleOrders||[]).length;
      const requests=(state.vouchers.requests||[]).length;
      const accountCount=state.accounts.length;
      const pageHeading=MINI_APP_ROUTE?'':'<div class="cc-page-heading"><div><div class="cc-page-eyebrow"><span></span> HENDY CYBERTECH · MEMBER PORTAL</div><h1>TRUNG TÂM KHÁCH HÀNG</h1><p>Quản lý hồ sơ, ví tiền, đơn hàng và yêu cầu hỗ trợ trong một nơi.</p></div><span class="cc-page-chip">💠 KHU VỰC THÀNH VIÊN</span></div>';
      const accountAndStats=MINI_APP_ROUTE?'':'<section class="cc-summary"><div class="cc-summary-card"><div class="cc-summary-head">▰ THỐNG KÊ ĐƠN HÀNG</div><div class="cc-order-stats"><div class="cc-stat"><small>TỔNG ĐƠN</small><b>'+Number(stats.totalOrders||0)+'</b></div><div class="cc-stat cyan"><small>ĐANG XỬ LÝ</small><b>'+Number(stats.processingOrders||0)+'</b></div><div class="cc-stat green"><small>HOÀN TẤT</small><b>'+Number(stats.completedOrders||0)+'</b></div><div class="cc-stat"><small>ĐÃ HỦY</small><b>'+Number(stats.cancelledOrders||0)+'</b></div><div class="cc-stat yellow"><small>HOÀN TIỀN</small><b>'+Number(stats.refundedOrders||0)+'</b></div></div></div><div class="cc-summary-card"><div class="cc-summary-head green">▢ THỐNG KÊ TÀI KHOẢN</div><div class="cc-account-stats"><div class="cc-account-stat main"><small>TỔNG NẠP (VIP)</small><b>'+money(total)+'</b></div><div class="cc-account-stat"><small>POINT (CŨ)</small><b>'+Number(vip.pointOld??u.pointOld??0).toLocaleString('vi-VN')+'</b></div><div class="cc-account-stat nyc"><small>VÍ NYC</small><b>'+money(u.nycWallet)+'</b></div><div class="cc-account-stat voucher"><small>NẠP VOUCHER</small><b>'+money(u.voucherWallet)+'</b></div></div></div></section><div class="cc-section-title"><span>🗂️ QUẢN LÝ TÀI KHOẢN & HOẠT ĐỘNG</span></div><section class="cc-linked"><div><b>🔗 TÀI KHOẢN MINI GAME ĐÃ LIÊN KẾT</b><small>'+accountCount+' tài khoản được lưu · chỉ lưu username, không lưu mật khẩu</small></div><button class="cc-btn" type="button" data-action="manage-accounts">QUẢN LÝ</button></section>';
      $('#app').innerHTML='<div class="cc-dashboard">'+pageHeading+operationNotice+
        '<section class="cc-profile"><div class="cc-profile-main"><div class="cc-profile-head"><div class="cc-avatar">'+initials(u)+'</div><div class="cc-identity"><h2>'+esc(name)+'</h2><span class="cc-id">🆔 TELEGRAM ID: '+esc(u.id||'')+'</span><small>'+(u.username?'@'+esc(String(u.username).replace(/^@/,'')):'HENDY MEMBER')+' · '+(botKey==='secondary'?'BOT THỬ NGHIỆM':'BOT CHÍNH')+'</small></div></div><div class="cc-vipline"><span class="cc-vip">💎 '+(level>0?'VIP '+level:'VIP 0 · THÀNH VIÊN')+'</span><span class="cc-bot-chip">HENDY CYBERTECH MEMBER</span></div><div class="cc-progress"><div class="cc-progress-label"><span>TỔNG NẠP TÍCH LŨY</span><strong>'+money(total)+'</strong></div><div class="cc-progress-track"><span style="width:'+progress+'%"></span></div><div class="cc-progress-label" style="margin-top:6px"><span>'+(vip.nextThreshold===null?'ĐÃ ĐẠT VIP TỐI ĐA':money(total)+' / '+next)+'</span><strong>'+progress+'%</strong></div></div></div><div class="cc-wallets"><div class="cc-wallet"><small>▣ VÍ CHÍNH (NẠP)</small><b>'+money(u.balance)+'</b></div><div class="cc-wallet voucher"><small>▣ VÍ VOUCHER</small><b>'+money(u.voucherWallet)+'</b></div></div></section>'+
        '<div class="cc-section-title"><span>⚡ KHU VỰC HỖ TRỢ & TÍNH NĂNG</span><small>'+Number(ticketCounts.total||0)+' ticket · '+requests+' yêu cầu voucher</small></div>'+
        '<section class="cc-support-grid"><button class="cc-support-card abuse" type="button" data-action="support-abuse"><span class="cc-number">1</span><b>HỖ TRỢ DÍNH LẠM (60%)</b><small>Gửi mã đơn đã hoàn thành để CSKH kiểm tra trường hợp chưa nhận được điểm. Không gửi mật khẩu, mã OTP hoặc PIN.</small></button><button class="cc-support-card ticket" type="button" data-action="support-general"><span class="cc-number">2</span><b>TICKET HỖ TRỢ</b><small>Tạo yêu cầu, khiếu nại và theo dõi phản hồi của đội ngũ chăm sóc khách hàng HENDY.</small></button><button class="cc-support-card voucher" type="button" data-action="open-vouchers"><span class="cc-number">3</span><b>VOUCHER 72H</b><small>'+(eligible?'Có '+eligible+' đơn đủ điều kiện để gửi yêu cầu.':'Kiểm tra đơn chờ xử lý quá 72 giờ. Voucher chỉ được cộng sau khi Admin duyệt.')+'</small></button></section>'+
        '<a class="cc-replay '+(replayValid?'':'disabled')+'" '+(replayValid?'href="'+esc(replayUrl)+'" target="_blank" rel="noopener noreferrer"':'aria-disabled="true"')+'><span class="cc-replay-icon">▶</span><div><b>KIỂM TRA REPLAY LIVE</b><small>'+(replayValid?'Xem lại video live các đơn hàng đã hoàn tất.':'Nguồn Replay Live chưa được cấu hình; tính năng đang được phát triển.')+'</small></div></a>'+
        accountAndStats+
        '<div class="cc-tabs" role="tablist" aria-label="Lịch sử khách hàng"><button class="'+(state.tab==='active'?'active':'')+'" data-tab="active" data-customer-tab="active">🚀 ĐANG CHẠY & CHỜ XN</button><button class="'+(state.tab==='orders'?'active':'')+'" data-tab="orders" data-customer-tab="orders">📜 LỊCH SỬ ĐƠN HÀNG</button><button class="'+(state.tab==='deposit'?'active':'')+'" data-tab="deposit" data-customer-tab="deposit">💳 NẠP TIỀN</button><button class="'+(state.tab==='transactions'?'active':'')+'" data-tab="transactions" data-customer-tab="transactions">🛒 GD ĐƠN HÀNG</button><button class="'+(state.tab==='code-telegram'?'active':'')+'" data-tab="code-telegram" data-customer-tab="code-telegram">📡 CODE TELEGRAM</button></div>'+
        '<section class="cc-tab">'+renderTabs()+'</section></div>';
      updateMiniAppButton();
    }
    function updateMiniAppButton(){
      if(!tg?.initData)return;
      if(tg.BackButton){
        if(state.tab!=='active')tg.BackButton.show?.();
        else tg.BackButton.hide?.();
      }
      if(!tg.MainButton)return;
      if(state.tab==='deposit'){tg.MainButton.setText('TẠO QR VIETQR');tg.MainButton.show();tg.MainButton.enable?.();}
      else tg.MainButton.hide();
    }
    async function reloadData(){
      $('#connection-label').textContent='ĐANG ĐỒNG BỘ';
      const linkedAccountsRequest=MINI_APP_ROUTE?Promise.resolve({accounts:[]}):api('/api/linked-accounts');
      const codeTelegramRequest=api('/api/code-telegram/portal').catch(()=>({enabled:false,services:[],requests:[]}));
      const values=await Promise.all([
        api('/api/bootstrap'),api('/api/customer-center'),linkedAccountsRequest,api('/api/orders'),
        api('/api/wallet/payment-requests'),api('/api/support/tickets'),api('/api/vouchers'),api('/api/vip'),codeTelegramRequest
      ]);
      const [boot,center,accounts,orders,payments,tickets,vouchers,vip,codeTelegram]=values;
      if(boot.botKey!==botKey)throw new Error('Phiên đăng nhập thuộc bot khác. Hãy mở đúng liên kết của HENDY.');
      state.boot=boot;state.center=center;state.accounts=accounts.accounts||[];state.orders=orders.orders||[];
      state.payments=payments.requests||[];state.tickets=tickets.tickets||[];state.vouchers=vouchers;state.vip=vip;state.codeTelegram=codeTelegram||{enabled:false,services:[],requests:[]};
      try{localStorage.setItem('hendy_bot_key',botKey);}catch(_){}
      $('#connection-label').textContent='THÀNH VIÊN ĐÃ XÁC THỰC';
      renderDashboard();
    }
    function showModal(title,body){
      const root=$('#modal-root');
      root.innerHTML='<div id="cc-order-modal" class="cc-modal show" role="dialog" aria-modal="true"><section class="cc-modal-card"><div class="cc-modal-head"><h3>'+esc(title)+'</h3><button class="cc-modal-close" type="button" data-close-modal>ĐÓNG ✕</button></div><div class="cc-modal-body">'+body+'</div></section></div>';
      root.querySelector('.cc-modal').addEventListener('click',e=>{if(e.target.classList.contains('cc-modal'))closeModal();});
    }
    function closeModal(){$('#modal-root').innerHTML='';}
    function supportModal(type){
      state.supportType=type;
      const abuse=type==='dinh_lam';
      showModal(abuse?'⚠️ HỖ TRỢ DÍNH LẠM (60%)':'🎧 TICKET HỖ TRỢ CSKH',
        '<div class="cc-notice '+(abuse?'warning':'')+'">'+(abuse?'Gửi mã đơn đã hoàn thành và mô tả tình trạng chưa nhận điểm. CSKH sẽ kiểm tra điều kiện và phản hồi. Không nhập mật khẩu game, mã OTP hoặc PIN.':'Mô tả vấn đề, mã đơn liên quan và cách liên hệ để đội ngũ CSKH kiểm tra.')+'</div>'+
        '<form id="support-form" class="cc-form-card"><label class="cc-form-label" for="support-subject">Tiêu đề yêu cầu</label><input id="support-subject" class="cc-field" maxlength="120" required placeholder="Ví dụ: Đơn hàng chưa nhận điểm"><label class="cc-form-label" for="support-message">Nội dung chi tiết</label><textarea id="support-message" class="cc-field" rows="4" maxlength="3000" required placeholder="Nhập mã đơn hàng và mô tả tình trạng..."></textarea><button class="cc-primary" type="submit">GỬI YÊU CẦU CSKH</button></form>'+
        (state.tickets.length?'<div class="cc-list-head"><h3>LỊCH SỬ YÊU CẦU</h3><span>'+state.tickets.length+' ticket</span></div>'+state.tickets.map(t=>'<article class="cc-record"><div class="cc-record-top"><div><b>#'+Number(t.id)+' · '+esc(t.subject)+'</b><small>'+esc(statusLabel(t.status))+' · '+fmtDate(t.created_at)+'</small></div></div><p style="color:#aab3c3;font-size:11px;line-height:1.5">'+esc(t.message)+'</p>'+(t.admin_reply?'<div class="cc-notice"><b>Phản hồi CSKH</b><br>'+esc(t.admin_reply)+'</div>':'')+'</article>').join(''):''));
    }
    function voucherModal(){
      const eligible=state.vouchers.eligibleOrders||[],requests=state.vouchers.requests||[];
      showModal('🎟️ VOUCHER 72H',
        '<div class="cc-notice">Chỉ đơn đang chờ xử lý tối thiểu 72 giờ mới đủ điều kiện. Việc gửi yêu cầu không tự cộng tiền; Admin phải kiểm tra và duyệt.</div>'+
        (eligible.length?'<form id="voucher-form" class="cc-form-card"><label class="cc-form-label" for="voucher-order">Chọn đơn hàng đủ điều kiện</label><select id="voucher-order" class="cc-field">'+eligible.map(o=>'<option value="'+Number(o.id)+'">'+esc(o.order_code)+' · '+esc(o.product_name)+' · '+money(o.amount)+'</option>').join('')+'</select><label class="cc-form-label" for="voucher-reason">Lý do yêu cầu</label><textarea id="voucher-reason" class="cc-field" rows="3" maxlength="1000" required placeholder="Mô tả vấn đề của đơn hàng..."></textarea><button class="cc-primary" type="submit" data-action="submit-voucher">GỬI YÊU CẦU VOUCHER</button></form>':'<div class="cc-empty"><span>🎟️</span><b>Chưa có đơn đủ điều kiện</b><small>Đơn chờ tối thiểu 72 giờ sẽ hiển thị tại đây.</small></div>')+
        (requests.length?'<div class="cc-list-head"><h3>YÊU CẦU ĐÃ GỬI</h3><span>'+requests.length+'</span></div>'+requests.map(v=>'<article class="cc-record"><div class="cc-record-top"><div><b>'+esc(v.order_code)+' · '+esc(v.product_name||'Đơn hàng')+'</b><small>'+fmtDate(v.created_at)+'</small></div><span class="cc-status">'+esc(statusLabel(v.status))+'</span></div><small style="color:#9ca7b8">Phần thưởng: '+money(v.reward_amount||0)+'</small></article>').join(''):''));
    }
    function accountsModal(){
      const brands=['QQ88','HI88','78WIN','RR88','MM88','GG88','ABCVIP','XX8','U888','J88','ABC8','88CLB','AD88','SC88','C168','CM88','F8BET'];
      showModal('🔗 TÀI KHOẢN MINI GAME',
        '<div class="cc-notice">Chỉ lưu thương hiệu và username. Không nhập mật khẩu, mã OTP hoặc PIN của tài khoản bên thứ ba.</div><form id="account-form" class="cc-form-card"><label class="cc-form-label" for="account-brand">Thương hiệu</label><select id="account-brand" class="cc-field">'+brands.map(b=>'<option>'+b+'</option>').join('')+'</select><label class="cc-form-label" for="account-username">Tên đăng nhập</label><input id="account-username" class="cc-field" maxlength="64" required minlength="3" placeholder="Username tài khoản"><button class="cc-primary" type="submit">LIÊN KẾT TÀI KHOẢN</button></form>'+
        (state.accounts.length?state.accounts.map(a=>'<article class="cc-record"><div class="cc-record-top"><div><b>'+esc(a.brand)+'</b><small>'+esc(a.username)+' · '+fmtDate(a.created_at)+'</small></div><button class="cc-detail-btn" type="button" data-delete-account="'+Number(a.id)+'">XÓA</button></div></article>').join(''):'<div class="cc-empty"><span>🔗</span><b>Chưa liên kết tài khoản</b></div>'));
    }
    function orderModal(orderCode){
      const item=state.orders.find(o=>String(o.order_code)===String(orderCode));if(!item)return;
      showModal('🧾 CHI TIẾT ĐƠN HÀNG',
        '<article class="cc-record"><div class="cc-record-top"><div><b>'+esc(item.product_name)+'</b><small>'+esc(item.order_code)+'</small></div><span class="cc-status">'+esc(statusLabel(item.status))+'</span></div>'+
        '<div class="cc-record-foot"><span>Giá trị</span><b>'+money(item.amount)+'</b></div><div class="cc-record-foot"><span>Ngày tạo</span><span>'+fmtDate(item.created_at)+'</span></div>'+
        (item.admin_note?'<p style="color:#aab3c3;font-size:11px">Ghi chú CSKH: '+esc(item.admin_note)+'</p>':'')+
        (item.delivery_text?'<div class="cc-notice"><b>Thông tin giao hàng</b><br>'+esc(item.delivery_text)+'</div>':'')+'</article>');
    }
    async function createDeposit(){
      const amount=Number($('#deposit-amount')?.value||0),result=$('#payment-result');
      if(!Number.isInteger(amount)||amount<10000||amount>500000000)throw new Error('Số tiền nạp phải từ 10.000 đến 500.000.000 VNĐ.');
      if(result)result.innerHTML='<p style="color:#aab3c3;font-size:11px">Đang tạo yêu cầu VietQR...</p>';
      const data=await api('/api/wallet/payment-requests',{method:'POST',body:{amount}});
      const r=data.request;
      if(result)result.innerHTML='<div class="cc-notice">Đã tạo yêu cầu <b>'+esc(r.payment_id)+'</b>. Chuyển đúng số tiền và nội dung để Admin đối soát.</div><img class="cc-qr" alt="Mã QR VietQR" src="'+esc(data.qrUrl)+'"><div class="cc-record"><div class="cc-record-foot"><span>Ngân hàng</span><b>'+esc(r.bank_id||'')+'</b></div><div class="cc-record-foot"><span>Số tài khoản</span><b>'+esc(r.account_number||'')+'</b></div><div class="cc-record-foot"><span>Chủ tài khoản</span><b>'+esc(r.account_holder||'')+'</b></div><div class="cc-record-foot"><span>Số tiền</span><b>'+money(r.amount)+'</b></div><div class="cc-record-foot"><span>Nội dung chuyển khoản</span><b>'+esc(r.transfer_content)+'</b></div></div>';
      state.payments.unshift(r);toast(data.message||'Đã tạo yêu cầu nạp.'); 
    }
    async function submitForm(event){
      const form=event.target;
      if(form.id==='code-telegram-order-form'){
        event.preventDefault();
        const serviceId=Number($('#ct-service')?.value||0);
        const usernames=String($('#ct-usernames')?.value||'').split(String.fromCharCode(10)).map(value=>value.trim()).filter(Boolean);
        if(!serviceId)throw new Error('Vui lòng chọn dịch vụ CODE TELEGRAM.');
        if(!usernames.length)throw new Error('Vui lòng nhập ít nhất một tên TK CODE TELEGRAM.');
        const data=await api('/api/code-telegram/requests',{method:'POST',body:{serviceId,usernames}});
        toast(data.message||('Đã tiếp nhận đơn '+(data.request?.orderCode||'')));
        state.tab='code-telegram';
        await reloadData();
        return;
      }
      if(form.id==='support-form'){
        event.preventDefault();
        const subject=$('#support-subject').value.trim(),message=$('#support-message').value.trim();
        if(subject.length<3||message.length<5)throw new Error('Vui lòng nhập tiêu đề và nội dung ít nhất 5 ký tự.');
        const data=await api('/api/support/tickets',{method:'POST',body:{ticketType:state.supportType,subject,message}});
        toast(data.message||'Đã gửi ticket.');closeModal();await reloadData();return;
      }
      if(form.id==='voucher-form'){
        event.preventDefault();const orderId=Number($('#voucher-order').value),reason=$('#voucher-reason').value.trim();
        if(!orderId||reason.length<5)throw new Error('Chọn đơn hàng và nhập lý do ít nhất 5 ký tự.');
        const data=await api('/api/vouchers',{method:'POST',body:{orderId,reason}});
        toast(data.message||'Đã gửi yêu cầu voucher.');closeModal();await reloadData();return;
      }
      if(form.id==='account-form'){
        event.preventDefault();const brand=$('#account-brand').value,username=$('#account-username').value.trim();
        if(username.length<3||username.length>64)throw new Error('Username phải từ 3 đến 64 ký tự.');
        await api('/api/linked-accounts',{method:'POST',body:{brand,username}});
        toast('Đã liên kết tài khoản.');await reloadData();accountsModal();return;
      }
    }
    async function handleClick(event){
      const tab=event.target.closest('[data-tab]');
      if(tab){state.tab=tab.dataset.tab;renderDashboard();return;}
      const detail=event.target.closest('[data-order-detail]');
      if(detail){orderModal(detail.dataset.orderDetail);return;}
      if(event.target.closest('[data-close-modal]')){closeModal();return;}
      const del=event.target.closest('[data-delete-account]');
      if(del){await api('/api/linked-accounts/'+encodeURIComponent(del.dataset.deleteAccount),{method:'DELETE'});toast('Đã xóa liên kết.');await reloadData();accountsModal();return;}
      const action=event.target.closest('[data-action]')?.dataset.action;
      if(!action)return;
      if(action==='support-abuse'){supportModal('dinh_lam');return;}
      if(action==='support-general'){supportModal('support');return;}
      if(action==='open-vouchers'){voucherModal();return;}
      if(action==='manage-accounts'){accountsModal();return;}
      if(action==='deposit'){await createDeposit();return;}
    }
    $('#app').addEventListener('click',event=>{handleClick(event).catch(error=>toast(error.message||'Không thể xử lý yêu cầu.','error'));});
    $('#app').addEventListener('submit',event=>{submitForm(event).catch(error=>toast(error.message||'Không thể gửi yêu cầu.','error'));});
    $('#modal-root').addEventListener('click',event=>{handleClick(event).catch(error=>toast(error.message||'Không thể xử lý yêu cầu.','error'));});
    $('#modal-root').addEventListener('submit',event=>{submitForm(event).catch(error=>toast(error.message||'Không thể gửi yêu cầu.','error'));});
    document.addEventListener('click',event=>{if(event.target.closest('[data-close-modal]'))closeModal();});
    async function start(){
      initTelegramMiniApp();
      try{await reloadData();}
      catch(error){
        if(error.status===401||error.status===403){loginView(await fetchPublicConfig());return;}
        $('#connection-label').textContent='LỖI KẾT NỐI';
        $('#app').innerHTML='<section class="error-state"><h2>Không thể tải Trung tâm khách hàng</h2><p style="color:#aab3c3;line-height:1.6">'+esc(error.message||'Lỗi hệ thống')+'</p><button class="cc-primary" type="button" id="retry-load">THỬ LẠI</button></section>';
        $('#retry-load')?.addEventListener('click',()=>start());
      }
      if(tg?.MainButton){
        tg.MainButton.onClick(()=>createDeposit().catch(e=>toast(e.message,'error')));
      }
    }
    start();
  })();