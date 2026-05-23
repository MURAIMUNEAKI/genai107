/* ===== genai107 レイアウト — ヘッダー上部ナビ方式 (genai-web v1.0.7 風) ===== */

/**
 * initLayout({ activePage: 'chat' })
 * — ヘッダー（ロゴ + pillナビ + アカウントメニュー）+ モバイルdialogを注入
 * — サイドバーは注入しない
 */
function initLayout(opts) {
  var activePage = (opts && opts.activePage) || '';
  var basePath   = (opts && opts.basePath) || '../app/';
  var rootPath   = (opts && opts.rootPath) || '../';

  /* ---------- Nav Items ---------- */
  var navItems = [
    { id: 'chat', label: 'チャット', href: basePath + 'chat.htm',
      icon: '<path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>' },
    { id: 'apps', label: 'AIアプリ', href: basePath + 'apps.htm',
      icon: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>' }
  ];

  /* ---------- Mobile Menu Items (full list) ---------- */
  var mobileItems = [
    { id: 'main',       label: 'ホーム',                  href: basePath + 'main.htm',
      icon: '<path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>' },
    { id: 'chat',       label: 'チャット',                 href: basePath + 'chat.htm',
      icon: '<path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>' },
    { id: 'generate',   label: '文章を生成',               href: basePath + 'generate.htm',
      icon: '<path d="M17 3a2.83 2.83 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z"/>' },
    { id: 'translate',  label: '翻訳',                     href: basePath + 'translate.htm',
      icon: '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10A15.3 15.3 0 0112 2z"/>' },
    { id: 'image',      label: '画像を生成',               href: basePath + 'image.htm',
      icon: '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>' },
    { id: 'diagram',    label: 'ダイアグラムを生成',       href: basePath + 'diagram.htm',
      icon: '<rect x="8" y="2" width="8" height="4" rx="1"/><rect x="2" y="18" width="8" height="4" rx="1"/><rect x="14" y="18" width="8" height="4" rx="1"/><path d="M12 6v6M12 12H6v6M12 12h6v6"/>' },
    { id: 'transcribe', label: '文字起こし',               href: basePath + 'transcribe.htm',
      icon: '<path d="M12 1a3 3 0 00-3 3v8a3 3 0 006 0V4a3 3 0 00-3-3z"/><path d="M19 10v2a7 7 0 01-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/>' },
    { id: 'rag',        label: '行政実務用RAG',            href: basePath + 'rag.htm',
      icon: '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><path d="M11 8v6M8 11h6"/>' },
    { id: 'lawsy',      label: '法令AI Lawsy',             href: basePath + 'lawsy.htm',
      icon: '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>' },
    { id: 'apps',       label: 'AIアプリ',                 href: basePath + 'apps.htm',
      icon: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>' }
  ];

  /* ---------- SVG helper ---------- */
  function svgIcon(iconPath, size) {
    size = size || 20;
    return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + iconPath + '</svg>';
  }

  /* ---------- Account icon ---------- */
  var accountIconSvg = '<svg class="account-icon" viewBox="0 -960 960 960" aria-hidden="true"><path d="M240.92-268.31q51-37.84 111.12-59.77Q412.15-350 480-350t127.96 21.92q60.12 21.93 111.12 59.77 37.3-41 59.11-94.92Q800-417.15 800-480q0-133-93.5-226.5T480-800q-133 0-226.5 93.5T160-480q0 62.85 21.81 116.77 21.81 53.92 59.11 94.92ZM480.01-450q-54.78 0-92.39-37.6Q350-525.21 350-579.99t37.6-92.39Q425.21-710 479.99-710t92.39 37.6Q610-634.79 610-580.01t-37.6 92.39Q534.79-450 480.01-450ZM480-100q-79.15 0-148.5-29.77t-120.65-81.08q-51.31-51.3-81.08-120.65Q100-400.85 100-480t29.77-148.5q29.77-69.35 81.08-120.65 51.3-51.31 120.65-81.08Q400.85-860 480-860t148.5 29.77q69.35 29.77 120.65 81.08 51.31 51.3 81.08 120.65Q860-559.15 860-480t-29.77 148.5q-29.77 69.35-81.08 120.65-51.3 51.31-120.65 81.08Q559.15-100 480-100Zm0-60q54.15 0 104.42-17.42 50.27-17.43 89.27-48.73-39-30.16-88.11-47Q536.46-290 480-290t-105.77 16.65q-49.31 16.66-87.92 47.2 39 31.3 89.27 48.73Q425.85-160 480-160Zm0-350q29.85 0 49.92-20.08Q550-550.15 550-580t-20.08-49.92Q509.85-650 480-650t-49.92 20.08Q410-609.85 410-580t20.08 49.92Q450.15-510 480-510Zm0-70Zm0 355Z"/></svg>';

  /* ---------- Build Header ---------- */
  var headerEl = document.createElement('header');
  headerEl.className = 'app-header';

  var headerInner = '<div class="header-inner">';

  // Hamburger (mobile)
  headerInner +=
    '<button class="hamburger-btn" id="gn107-hamburger" aria-label="メニュー">' +
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/></svg>' +
      '<span>メニュー</span>' +
    '</button>';

  // Logo
  headerInner += '<a href="' + basePath + 'main.htm" class="app-logo">源内AI</a>';

  // Desktop pill nav
  headerInner += '<nav class="header-nav" aria-label="メインナビゲーション">';
  for (var i = 0; i < navItems.length; i++) {
    var n = navItems[i];
    var pillCls = 'nav-pill' + (n.id === activePage ? ' active' : '');
    headerInner += '<a href="' + n.href + '" class="' + pillCls + '">' + svgIcon(n.icon, 18) + n.label + '</a>';
  }
  headerInner += '</nav>';

  // Account area
  headerInner +=
    '<div class="account-area">' +
      '<button class="account-btn" id="gn107-account-btn">' +
        accountIconSvg +
        '<span class="account-label">アカウント</span>' +
        '<svg class="account-chevron" id="gn107-chevron" viewBox="0 0 16 16" width="10" height="10" fill="none" aria-hidden="true"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="2"/></svg>' +
      '</button>' +
      '<div class="account-dropdown" id="gn107-dropdown">' +
        '<a class="dropdown-item" href="' + basePath + 'history.htm">利用履歴</a>' +
        '<button class="dropdown-item" id="gn107-signout">サインアウト</button>' +
      '</div>' +
    '</div>';

  headerInner += '</div>';
  headerEl.innerHTML = headerInner;

  /* ---------- Mobile Dialog ---------- */
  var mobileEl = document.createElement('div');
  mobileEl.className = 'mobile-dialog';
  mobileEl.id = 'gn107-mobile-dialog';

  var mobileHtml =
    '<div class="mobile-dialog-header">' +
      '<a href="' + basePath + 'main.htm" class="app-logo" style="font-size:1.125rem">源内AI</a>' +
      '<button class="mobile-close-btn" id="gn107-mobile-close" aria-label="閉じる">' +
        '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>' +
      '</button>' +
    '</div>' +
    '<nav class="mobile-nav">';

  for (var j = 0; j < mobileItems.length; j++) {
    var m = mobileItems[j];
    var mCls = 'mobile-nav-item' + (m.id === activePage ? ' active' : '');
    mobileHtml += '<a href="' + m.href + '" class="' + mCls + '">' + svgIcon(m.icon, 20) + m.label + '</a>';
  }

  mobileHtml +=
    '</nav>' +
    '<hr class="mobile-divider">' +
    '<div class="mobile-account-section">' +
      '<div class="mobile-account-title">アカウント</div>' +
      '<a href="' + basePath + 'history.htm" class="mobile-nav-item">' +
        svgIcon('<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>', 20) +
        '利用履歴</a>' +
      '<button class="mobile-nav-item" id="gn107-mobile-signout" style="width:100%;border:none;background:none;cursor:pointer;font-family:inherit;font-size:1rem;text-align:left;">' +
        svgIcon('<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>', 20) +
        'サインアウト</button>' +
    '</div>';

  mobileEl.innerHTML = mobileHtml;

  /* ---------- Inject into DOM ---------- */
  var shell = document.querySelector('.app-shell');
  if (!shell) return;
  shell.insertBefore(headerEl, shell.firstChild);
  document.body.appendChild(mobileEl);

  /* ---------- Event Listeners ---------- */
  // Hamburger → open dialog
  document.getElementById('gn107-hamburger').addEventListener('click', function() {
    mobileEl.classList.add('open');
  });

  // Close dialog
  document.getElementById('gn107-mobile-close').addEventListener('click', function() {
    mobileEl.classList.remove('open');
  });

  // Account dropdown
  var accBtn  = document.getElementById('gn107-account-btn');
  var accDrop = document.getElementById('gn107-dropdown');
  var accChev = document.getElementById('gn107-chevron');
  accBtn.addEventListener('click', function(e) {
    e.stopPropagation();
    var isOpen = accDrop.classList.toggle('open');
    accChev.classList.toggle('open', isOpen);
  });
  document.addEventListener('click', function() {
    accDrop.classList.remove('open');
    accChev.classList.remove('open');
  });

  // Sign out (desktop)
  document.getElementById('gn107-signout').addEventListener('click', function() {
    doSignoutAction();
  });

  // Sign out (mobile)
  document.getElementById('gn107-mobile-signout').addEventListener('click', function() {
    doSignoutAction();
  });

  function doSignoutAction() {
    if (typeof doSignout === 'function') {
      doSignout();
    } else {
      sessionStorage.removeItem('gennai_auth');
      window.location.href = rootPath + 'index.htm';
    }
  }
}
