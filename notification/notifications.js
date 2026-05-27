/* ============================================================================
   NOTIFICATION CENTER — render engine
   Pure vanilla JS. Server provides window.NOTIFICATIONS; everything else
   (grouping, filtering, search, sort, read state) happens client-side so the
   experience is instant and the PHP backend stays untouched.
   ========================================================================== */
(function () {
  'use strict';

  const RAW = (window.NOTIFICATIONS || []).map(n => ({
    ...n,
    ts: typeof n.ts === 'string' ? +new Date(n.ts) : +n.ts
  }));
  const LS_READ = 'nc_read_v1';
  const LS_DISMISS = 'nc_dismissed_v1';
  const LS_THEME = 'nc_theme';

  /* ---------- persisted state ---------- */
  const readSet = new Set(load(LS_READ));
  const dismissSet = new Set(load(LS_DISMISS));
  function load(k){ try { return JSON.parse(localStorage.getItem(k)) || []; } catch { return []; } }
  function save(k,set){ try { localStorage.setItem(k, JSON.stringify([...set])); } catch {} }

  /* ---------- UI state ---------- */
  const state = { tab: 'all', filter: 'all', sort: 'newest', query: '' };

  /* ---------- elements ---------- */
  const feed = document.getElementById('feed');
  const tabsEl = document.getElementById('tabs');
  const tabPill = document.getElementById('tabPill');
  const searchInput = document.getElementById('searchInput');
  const searchClear = document.getElementById('searchClear');
  const sortSelect = document.getElementById('sortSelect');
  const bellBadge = document.getElementById('bellBadge');
  const subtitle = document.getElementById('ncSubtitle');
  const emptyTpl = document.getElementById('emptyTpl');

  /* ---------- icons ---------- */
  const ICON = {
    ontime: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
    late:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
    absent: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m17 8 5 5M22 8l-5 5"/></svg>',
    failed: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
    book:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></svg>',
    user:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
    teacher:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>',
    cpu:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="5" width="14" height="14" rx="2"/><path d="M9 9h6v6H9zM9 1v3M15 1v3M9 20v3M15 20v3M1 9h3M1 15h3M20 9h3M20 15h3"/></svg>',
    mail:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>',
    sms:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
    bellOff:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M8.7 3A6 6 0 0 1 18 8c0 3 .6 4.8 1.3 6M17 17H3s3-2 3-9M10.3 21a1.94 1.94 0 0 0 3.4 0M2 2l20 20"/></svg>',
    check:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
    x:      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
  };

  /* ---------- helpers ---------- */
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const isUnread = n => !readSet.has(n.id);
  const isImportant = n => n.type === 'late' || n.type === 'absent' || n.delivery === 'failed';

  function titleFor(n){
    if (n.type === 'absent') return 'Marked Absent';
    if (n.type === 'late')   return 'Late Arrival';
    return 'On-Time Arrival';
  }
  function senderLabel(s){
    switch ((s||'').toLowerCase()) {
      case 'system_auto': return 'Auto-marked by system';
      case 'teacher':
      case 'teacher_terminal': return 'Recorded by teacher';
      case 'student':
      case 'student_terminal': return 'Terminal scan';
      default: return 'Terminal scan';
    }
  }
  function isTeacherSource(s){ return (s||'').toLowerCase().startsWith('teacher'); }

  function timeAgo(ts){
    const s = Math.max(1, Math.floor((Date.now() - ts) / 1000));
    if (s < 60) return 'just now';
    const m = Math.floor(s/60); if (m < 60) return m + 'm ago';
    const h = Math.floor(m/60); if (h < 24) return h + 'h ago';
    const d = Math.floor(h/24); if (d < 7) return d + 'd ago';
    return new Date(ts).toLocaleDateString(undefined,{month:'short',day:'numeric'});
  }
  // Prefer the server-rendered school-local time; fall back to viewer locale.
  function clockTime(n){
    if (n && n.timeLabel) return n.timeLabel;
    const ts = (n && typeof n === 'object') ? n.ts : n;
    return new Date(ts).toLocaleTimeString(undefined,{hour:'numeric',minute:'2-digit'});
  }

  const BUCKET_ORDER = { today:0, yesterday:1, week:2, month:3, older:4 };
  const BUCKET_LABEL = { today:'Today', yesterday:'Yesterday', week:'This Week', month:'Earlier This Month', older:'Older' };
  function dateBucket(n){
    if (n && n.bucket && BUCKET_ORDER[n.bucket] != null) {
      return { k:n.bucket, label:n.bucketLabel || BUCKET_LABEL[n.bucket], order:BUCKET_ORDER[n.bucket] };
    }
    const ts = (n && typeof n === 'object') ? n.ts : n;
    const d = new Date(ts), now = new Date();
    const day = x => new Date(x.getFullYear(),x.getMonth(),x.getDate()).getTime();
    const diff = (day(now) - day(d)) / 86400e3;
    if (diff <= 0) return {k:'today', label:'Today', order:0};
    if (diff === 1) return {k:'yesterday', label:'Yesterday', order:1};
    if (diff < 7) return {k:'week', label:'This Week', order:2};
    if (diff < 30) return {k:'month', label:'Earlier This Month', order:3};
    return {k:'older', label:'Older', order:4};
  }
  function timeBucket(n){
    const h = (n && n.hour != null) ? n.hour : new Date((n && typeof n==='object') ? n.ts : n).getHours();
    if (h < 12) return {k:'morning', label:'Morning · before 12 PM', order:0};
    if (h < 17) return {k:'afternoon', label:'Afternoon · 12–5 PM', order:1};
    if (h < 21) return {k:'evening', label:'Evening · 5–9 PM', order:2};
    return {k:'night', label:'Night · after 9 PM', order:3};
  }

  /* ---------- pipeline ---------- */
  function visible(){
    return RAW.filter(n => !dismissSet.has(n.id));
  }
  function applyFilters(list){
    let out = list;
    if (state.tab === 'unread')    out = out.filter(isUnread);
    if (state.tab === 'important') out = out.filter(isImportant);
    if (state.filter !== 'all')    out = out.filter(n => n.type === state.filter);
    if (state.query) {
      const q = state.query.toLowerCase();
      out = out.filter(n =>
        (n.student||'').toLowerCase().includes(q) ||
        (n.message||'').toLowerCase().includes(q) ||
        (n.subject||'').toLowerCase().includes(q) ||
        (n.parent||'').toLowerCase().includes(q) ||
        titleFor(n).toLowerCase().includes(q));
    }
    const prio = { absent:0, late:1, ontime:2 };
    out = [...out].sort((a,b) => {
      if (state.sort === 'oldest') return a.ts - b.ts;
      if (state.sort === 'priority') return (prio[a.type]-prio[b.type]) || (b.ts-a.ts);
      return b.ts - a.ts;
    });
    return out;
  }

  function group(list){
    const groups = new Map();
    const bucketer =
      state.tab === 'subjects' ? (n => { const s = n.subject || 'General'; return {k:s,label:s,order:0,subtitle:n.section||''}; }) :
      state.tab === 'schedule' ? (n => timeBucket(n)) :
      (n => dateBucket(n));
    for (const n of list){
      const b = bucketer(n);
      if (!groups.has(b.k)) groups.set(b.k, { ...b, items: [] });
      groups.get(b.k).items.push(n);
    }
    let arr = [...groups.values()];
    if (state.tab === 'subjects') arr.sort((a,b)=> b.items.length - a.items.length || a.label.localeCompare(b.label));
    else arr.sort((a,b)=> a.order - b.order);
    return arr;
  }

  /* ---------- render ---------- */
  function deliveryTag(n){
    const ch = (n.channel === 'email') ? 'Email' : 'SMS';
    const icon = (n.channel === 'email') ? ICON.mail : ICON.sms;
    if (n.delivery === 'sent')   return `<span class="nc-tag deliv-sent">${icon}${ch} sent</span>`;
    if (n.delivery === 'failed') return `<span class="nc-tag deliv-failed">${ICON.failed}${ch} failed</span>`;
    return `<span class="nc-tag deliv-none">${ICON.bellOff}Not notified</span>`;
  }

  function cardHTML(n){
    const unread = isUnread(n);
    const sub = n.subject ? esc(n.subject) + (n.section ? ' · '+esc(n.section) : '') : 'General';
    const senderIcon = n.sender==='system_auto' ? ICON.cpu : (isTeacherSource(n.sender) ? ICON.teacher : ICON.user);
    const teacherTag = n.instructor ? `<span class="nc-tag">${ICON.teacher}${esc(n.instructor)}</span>` : '';
    return `
    <article class="nc-card ${unread?'unread':'read'}" data-type="${esc(n.type)}" data-id="${esc(n.id)}" tabindex="0">
      <div class="nc-avatar ${esc(n.type)}">${ICON[n.type]||ICON.ontime}</div>
      <div class="nc-body">
        <div class="nc-card-top">
          <div class="nc-card-title">
            ${unread?'<span class="nc-unread-dot"></span>':''}${esc(titleFor(n))}
          </div>
          <time class="nc-time" title="${esc(new Date(n.ts).toLocaleString())}">${timeAgo(n.ts)}</time>
        </div>
        <div class="nc-student">${esc(n.student)}${n.studentNo?` · <span class="mono" style="font-family:var(--font-mono);font-size:11px">${esc(n.studentNo)}</span>`:''}</div>
        <p class="nc-message">${esc(n.message)}</p>
        <div class="nc-meta">
          <span class="nc-tag subject">${ICON.book}${sub}</span>
          ${deliveryTag(n)}
          <span class="nc-tag">${senderIcon}${esc(senderLabel(n.sender))}</span>
          ${teacherTag}
          <span class="nc-tag"><span class="mono">${clockTime(n)}</span></span>
        </div>
      </div>
      <div class="nc-actions">
        <button class="nc-act js-read" title="${unread?'Mark as read':'Mark as unread'}">${ICON.check}</button>
        <button class="nc-act danger js-dismiss" title="Dismiss">${ICON.x}</button>
      </div>
    </article>`;
  }

  function render(){
    const list = applyFilters(visible());
    feed.innerHTML = '';

    if (!list.length){ feed.appendChild(emptyState()); updateChrome(); return; }

    const groups = group(list);
    let delay = 0;
    for (const g of groups){
      const sec = document.createElement('section');
      sec.className = 'nc-group';
      const subt = g.subtitle ? ` <span style="font-weight:600;color:var(--text-3)">${esc(g.subtitle)}</span>` : '';
      sec.innerHTML = `
        <div class="nc-group-head">
          <span class="nc-group-label">${esc(g.label)}${subt}</span>
          <span class="nc-group-rule"></span>
          <span class="nc-group-count">${g.items.length}</span>
        </div>
        <div class="nc-stack">${g.items.map(cardHTML).join('')}</div>`;
      feed.appendChild(sec);
      // stagger entrance
      sec.querySelectorAll('.nc-card').forEach(c => { c.style.animationDelay = (delay += 35) + 'ms'; });
    }
    wireCards();
    updateChrome();
  }

  function emptyState(){
    const node = emptyTpl.content.cloneNode(true);
    const map = {
      unread:    ['You\u2019re all caught up', 'No unread notifications. Every alert has been reviewed.'],
      important: ['Nothing urgent', 'No late arrivals, absences, or failed notifications right now.'],
      subjects:  ['No subject activity', 'Notifications grouped by class will appear here.'],
      schedule:  ['No scheduled activity', 'Notifications grouped by time of day will appear here.'],
      all:       ['No notifications yet', 'New attendance alerts will show up here as they arrive.'],
    };
    const [t, x] = map[state.tab] || (state.query ? ['No matches', `Nothing matches \u201c${state.query}\u201d. Try a different search.`] : map.all);
    node.querySelector('.nc-empty-title').textContent = state.query ? 'No matches' : t;
    node.querySelector('.nc-empty-text').textContent = state.query ? `Nothing matches \u201c${state.query}\u201d.` : x;
    return node;
  }

  /* ---------- chrome: counts, badge, subtitle, pill ---------- */
  function updateChrome(){
    const all = visible();
    const counts = {
      all: all.length,
      unread: all.filter(isUnread).length,
      important: all.filter(isImportant).length,
      subjects: new Set(all.map(n => n.subject || 'General')).size,
      schedule: new Set(all.map(n => timeBucket(n).k)).size,
    };
    document.querySelectorAll('.nc-tab-count').forEach(el => {
      el.textContent = counts[el.dataset.count] ?? 0;
    });
    const unread = counts.unread;
    if (unread > 0){
      bellBadge.hidden = false; bellBadge.textContent = unread > 99 ? '99+' : unread;
      bellBadge.classList.add('pulse');
    } else { bellBadge.hidden = true; bellBadge.classList.remove('pulse'); }
    subtitle.textContent = unread > 0
      ? `${unread} unread · ${counts.all} total`
      : `All caught up · ${counts.all} total`;
    movePill();
  }

  function movePill(){
    const active = tabsEl.querySelector('.nc-tab.active');
    if (!active) return;
    tabPill.style.left = active.offsetLeft + 'px';
    tabPill.style.width = active.offsetWidth + 'px';
  }

  /* ---------- card interactions ---------- */
  function wireCards(){
    feed.querySelectorAll('.nc-card').forEach(card => {
      const id = card.dataset.id;
      const open = (e) => {
        if (e.target.closest('.nc-act')) return;
        markRead(id, true, card);
      };
      card.addEventListener('click', open);
      card.addEventListener('keydown', e => { if (e.key==='Enter'||e.key===' ') { e.preventDefault(); open(e); } });
      card.querySelector('.js-read').addEventListener('click', e => {
        e.stopPropagation(); toggleRead(id, card);
      });
      card.querySelector('.js-dismiss').addEventListener('click', e => {
        e.stopPropagation(); dismiss(id, card);
      });
    });
  }

  function markRead(id, val, card){
    const was = readSet.has(id);
    if (val && !was){ readSet.add(id); save(LS_READ, readSet); paintRead(card, true); }
    if (!val && was){ readSet.delete(id); save(LS_READ, readSet); paintRead(card, false); }
    if (state.tab === 'unread' && val){ removeCard(card); }
    updateChrome();
  }
  function toggleRead(id, card){ markRead(id, !readSet.has(id), card); }

  function paintRead(card, read){
    card.classList.toggle('read', read);
    card.classList.toggle('unread', !read);
    const dot = card.querySelector('.nc-unread-dot');
    if (read && dot) dot.remove();
    if (!read && !dot){
      const t = card.querySelector('.nc-card-title');
      t.insertAdjacentHTML('afterbegin','<span class="nc-unread-dot"></span>');
    }
    if (read){ card.classList.add('markread-flash'); setTimeout(()=>card.classList.remove('markread-flash'),500); }
  }

  function dismiss(id, card){
    card.classList.add('dismissing');
    setTimeout(() => { dismissSet.add(id); save(LS_DISMISS, dismissSet); render(); }, 320);
  }
  function removeCard(card){
    card.classList.add('dismissing');
    setTimeout(()=>render(), 320);
  }

  /* ---------- top controls ---------- */
  tabsEl.addEventListener('click', e => {
    const t = e.target.closest('.nc-tab'); if (!t) return;
    tabsEl.querySelectorAll('.nc-tab').forEach(x=>x.classList.remove('active'));
    t.classList.add('active'); state.tab = t.dataset.tab; render();
  });

  document.getElementById('quickFilters').addEventListener('click', e => {
    const c = e.target.closest('.nc-chip'); if (!c) return;
    document.querySelectorAll('#quickFilters .nc-chip').forEach(x=>x.classList.remove('active'));
    c.classList.add('active'); state.filter = c.dataset.filter; render();
  });

  sortSelect.addEventListener('change', () => { state.sort = sortSelect.value; render(); });

  let searchT;
  searchInput.addEventListener('input', () => {
    searchClear.hidden = !searchInput.value;
    clearTimeout(searchT);
    searchT = setTimeout(() => { state.query = searchInput.value.trim(); render(); }, 120);
  });
  searchClear.addEventListener('click', () => {
    searchInput.value=''; searchClear.hidden=true; state.query=''; render(); searchInput.focus();
  });

  document.getElementById('markAllBtn').addEventListener('click', () => {
    applyFilters(visible()).forEach(n => readSet.add(n.id));
    save(LS_READ, readSet); render();
  });

  /* ---------- theme ---------- */
  const themeToggle = document.getElementById('themeToggle');
  function setTheme(t){ document.documentElement.dataset.theme = t; try{localStorage.setItem(LS_THEME,t);}catch{} }
  (function initTheme(){
    let t; try { t = localStorage.getItem(LS_THEME); } catch {}
    if (!t) t = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    setTheme(t);
  })();
  themeToggle.addEventListener('click', () => {
    setTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark');
  });

  window.addEventListener('resize', movePill);

  /* ---------- go ---------- */
  render();
  window.addEventListener('load', movePill);
})();
