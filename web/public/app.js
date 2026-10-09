'use strict';
const nf = new Intl.NumberFormat();
const $ = s => document.querySelector(s);
const state = { lastId: 0, status: null, memFile: 'USER.md', memFiles: {}, sending: false, pollTimer: null };

async function api(path, opts = {}) {
  const res = await fetch(path, {
    method: opts.method || 'GET',
    headers: { 'Content-Type': 'application/json', 'X-Talixio': '1' },
    body: opts.body ? JSON.stringify(opts.body) : undefined,
    credentials: 'same-origin',
  });
  const raw = await res.text();
  let data;
  try { data = JSON.parse(raw); }
  catch { data = { error: `Server error (HTTP ${res.status})` + (raw ? ': ' + raw.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200) : '') }; }
  const fail = msg => Object.assign(new Error(msg), { data, status: res.status });
  if (res.status === 401) { showLogin(); throw fail(data.error || 'Please sign in.'); }
  if (!res.ok) throw fail(data.error || `Request failed (${res.status})`);
  return data;
}
const post = (path, body = {}) => api(path, { method: 'POST', body });

function toast(text) {
  const t = $('#toast'); t.textContent = text; t.hidden = false;
  clearTimeout(toast.timer); toast.timer = setTimeout(() => (t.hidden = true), 3500);
}
const esc = s => s.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

// Small, safe Markdown subset: escapes everything first, then adds formatting.
function md(src) {
  const blocks = [];
  let s = esc(src).replace(/```[\w-]*\n([\s\S]*?)```/g, (_, code) => { blocks.push(`<pre><code>${code}</code></pre>`); return `\u0000${blocks.length - 1}\u0000`; });
  const inline = t => t
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    .replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>')
    .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>')
    .replace(/(^|[\s(])(https?:\/\/[^\s<)]+)/g, '$1<a href="$2" target="_blank" rel="noopener">$2</a>');
  const out = [];
  const LI = /^\s*[-*•]\s+/, OL = /^\s*\d+[.)]\s+/;
  for (const para of s.split(/\n{2,}/)) {
    if (/^\u0000\d+\u0000$/.test(para.trim())) { out.push(para.trim()); continue; }
    // group consecutive lines: bullet list, numbered list, heading, or text
    let text = [], list = null, kind = null;
    const flushText = () => { if (text.length) out.push(`<p>${inline(text.join('<br>'))}</p>`); text = []; };
    const flushList = () => { if (list) out.push(`<${kind}>` + list.map(l => `<li>${inline(l)}</li>`).join('') + `</${kind}>`); list = null; kind = null; };
    for (const line of para.split('\n')) {
      const k = LI.test(line) ? 'ul' : OL.test(line) ? 'ol' : null;
      if (/^\u0000\d+\u0000$/.test(line.trim())) { flushText(); flushList(); out.push(line.trim()); continue; }
      if (k) {
        flushText();
        if (kind && kind !== k) flushList();
        kind = k; (list ||= []).push(line.replace(k === 'ul' ? LI : OL, ''));
      } else if (/^#{1,6}\s/.test(line)) {
        flushText(); flushList(); out.push(`<h4>${inline(line.replace(/^#+\s/, ''))}</h4>`);
      } else if (list && /^\s{2,}\S/.test(line)) {
        list[list.length - 1] += ' ' + line.trim(); // wrapped list item
      } else {
        flushList(); text.push(line);
      }
    }
    flushText(); flushList();
  }
  return out.join('').replace(/\u0000(\d+)\u0000/g, (_, i) => blocks[+i]);
}

const CHANNEL = { web: null, approval: 'Approval', learn: 'Learning', cli: 'Terminal', telegram: 'Telegram', email: 'Email', signal: 'Signal', schedule: 'Scheduled', heartbeat: 'Heartbeat', job: 'Job result' };
const money = v => v == null ? '' : v === 0 ? '$0' : v < 0.01 ? '<$0.01' : '$' + v.toFixed(v < 1 ? 3 : 2);
const ktok = n => n >= 1000 ? (n / 1000).toFixed(n >= 10000 ? 0 : 1) + 'k' : String(n);
const fmtTime = ts => new Date(ts * 1000).toLocaleString([], { weekday: 'short', hour: '2-digit', minute: '2-digit' });

function renderMessage(m) {
  const el = document.createElement('div');
  if (m.role === 'user' && m.channel === 'schedule') {
    const title = (m.content.match(/^\[Scheduled task "(.*?)"/) || [])[1] || 'Scheduled task';
    el.className = 'trigger';
    el.innerHTML = `<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/></svg><span>${esc(title)} · ${fmtTime(m.ts)}</span>`;
    return el;
  }
  if (m.role === 'user' && m.channel === 'job') {
    const [, num, title, status] = m.content.match(/^\[Background job #(\d+) "(.*?)" (\w+)/) || [];
    const report = m.content.replace(/^\[[^\]]*\]\s*/, '');
    el.className = 'jobcard';
    el.innerHTML = `<details><summary><svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h10M4 17h7"/><circle cx="18" cy="16" r="3"/></svg><span>Job ${status || 'finished'}: <b>${esc(title || 'background job')}</b> · ${fmtTime(m.ts)} · show the full report</span></summary><div class="report">${esc(report)}</div></details>`;
    return el;
  }
  el.className = `msg ${m.role}`;
  if (m.id) { el.dataset.id = m.id; el.dataset.channel = m.channel; }
  const chip = CHANNEL[m.channel] ? `<span class="chip ${m.channel}">${CHANNEL[m.channel]}</span>` : '';
  const atts = (m.files || []).map(f => f.kind === 'image'
      ? `<a href="/api/files/${f.id}" target="_blank" rel="noopener"><img src="/api/files/${f.id}" alt="${esc(f.name)}" loading="lazy"></a>`
      : f.kind === 'audio' ? `<audio controls preload="none" src="/api/files/${f.id}"></audio>`
      : `<a class="fchip" href="/api/files/${f.id}" target="_blank" rel="noopener">${f.kind === 'pdf' ? 'PDF' : 'File'} · ${esc(f.name)}</a>`).join('');
  const body = m.content.replace(/^\[attached [^\]]*\]$/gm, '').trim();
  const mt = m.meta;
  const usage = mt && (mt.in + mt.out) > 0 ? `<span class="tier" title="${esc(mt.model)} · ${nf.format(mt.in)} in / ${nf.format(mt.out)} out tokens">${esc(shortModel(mt.model))} · ${ktok(mt.in + mt.out)} tok${mt.cost_known ? ' · ' + money(mt.cost) : ''}</span>` : '';
  const acts = m.id && body ? `<span class="acts"><button type="button" class="act" data-act="copy" title="Copy" aria-label="Copy">${ICON.copy}</button>`
    + (m.role === 'assistant' ? `<button type="button" class="act" data-act="retry" title="New answer" aria-label="New answer">${ICON.retry}</button>`
      : `<button type="button" class="act" data-act="edit" title="Edit the message" aria-label="Edit the message">${ICON.edit}</button>`) + '</span>' : '';
  el.innerHTML = `${atts ? `<div class="attachments">${atts}</div>` : ''}${body ? `<div class="bubble">${m.role === 'user' ? esc(body) : md(body)}</div>` : ''}<div class="meta">${chip}<span>${fmtTime(m.ts)}</span>${usage}${acts}</div>`;
  el.querySelectorAll('.act').forEach(b => b.onclick = () => messageAction(b.dataset.act, m, el));
  return el;
}

const ICON = {
  copy: '<svg viewBox="0 0 24 24"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h8"/></svg>',
  retry: '<svg viewBox="0 0 24 24"><path d="M4 12a8 8 0 0 1 14-5.3L20 9M20 4v5h-5M20 12a8 8 0 0 1-14 5.3L4 15M4 20v-5h5"/></svg>',
  edit: '<svg viewBox="0 0 24 24"><path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M14 6l4 4"/></svg>',
  send: '<svg viewBox="0 0 24 24"><path d="M4 12l16-8-6 16-2-6z"/></svg>',
  stop: '<svg viewBox="0 0 24 24"><rect x="7" y="7" width="10" height="10" rx="1.5" style="fill:currentColor"/></svg>',
};

async function copyText(text) {
  try { await navigator.clipboard.writeText(text); }
  catch { const t = document.createElement('textarea'); t.value = text; document.body.append(t); t.select(); document.execCommand('copy'); t.remove(); }
  toast('Copied.');
}

/** Copy any message; get a new answer to, or edit, the latest exchange. */
async function messageAction(act, m, el) {
  const body = m.content.replace(/^\[attached [^\]]*\]$/gm, '').trim();
  if (act === 'copy') return copyText(body);
  if (state.sending) return toast('Wait for the answer to finish (or stop it) first.');
  const userEl = act === 'edit' ? el : [...$('#thread').querySelectorAll('.msg.user[data-id]')].filter(u => +u.dataset.id < m.id).pop();
  if (!userEl) return toast('Nothing to answer again.');
  if ($('#input').value.trim() && act === 'edit' && !confirm('Replace the message you are writing?')) return;
  try {
    const d = await post(`/api/messages/${userEl.dataset.id}/undo`);
    for (const id of d.removed) $(`#thread [data-id="${id}"]`)?.remove();
    state.lastId = Math.max(0, ...[...$('#thread').querySelectorAll('[data-id]')].map(x => +x.dataset.id));
    state.pending = d.files.map(f => ({ id: f.id, info: f })); renderPending();
    $('#input').value = d.text; autosize(); markLast();
    if (act === 'retry') send(); else { $('#input').focus(); saveDraft(); }
  } catch (e) { toast(e.message); }
}

/** Retry and edit only make sense on the latest exchange. */
function markLast() {
  const msgs = [...$('#thread').querySelectorAll('.msg[data-id]')];
  msgs.forEach(x => x.classList.remove('last-turn'));
  const lastUser = msgs.filter(x => x.classList.contains('user')).pop();
  if (!lastUser) return;
  const after = msgs.slice(msgs.indexOf(lastUser) + 1);
  if (after.every(x => x.classList.contains('assistant') && x.dataset.channel === lastUser.dataset.channel)) {
    lastUser.classList.add('last-turn'); after.forEach(x => x.classList.add('last-turn'));
  }
}

function shortModel(m) {
  const x = String(m).replace(/^claude-code:/, '');
  const fam = x.match(/(haiku|sonnet|opus)/i);
  return fam ? fam[1][0].toUpperCase() + fam[1].slice(1) : x;
}

function emptyState() {
  const n = state.status?.name || 'Talixio';
  const ideas = ['What can you do?', 'Remind me tomorrow at 9:00 to call the bank', 'Every weekday at 8:00, send me a short briefing', 'Research the 3 best-rated bakeries near me and compare them'];
  const el = document.createElement('div');
  el.className = 'empty'; el.id = 'empty';
  el.innerHTML = `<div class="mark" aria-hidden="true"></div><h3>Hi, I'm ${esc(n)}.</h3><p class="muted">I run on your server around the clock, remember what matters to you, and can message you first. Try one of these:</p><div class="suggest"></div>`;
  for (const t of ideas) {
    const b = document.createElement('button'); b.className = 'btn small'; b.type = 'button'; b.textContent = t;
    b.onclick = () => { $('#input').value = t; send(); };
    el.querySelector('.suggest').append(b);
  }
  return el;
}

function appendMessages(list, notify = false) {
  const thread = $('#thread');
  if (!list.length) { if (!thread.children.length) thread.append(emptyState()); return; }
  $('#empty')?.remove();
  const initial = !state.lastId;
  for (const m of list) {
    if (m.id <= state.lastId) continue;
    state.lastId = m.id;
    thread.append(renderMessage(m));
    if (!state.firstLoaded || m.id < state.firstLoaded) state.firstLoaded = m.id;
    if (notify && m.role === 'assistant' && (document.hidden || m.channel !== 'web')) notifyBrowser(m);
  }
  markLast(); earlierButton();
  if (initial) thread.style.scrollBehavior = 'auto'; // opening the app: straight to the latest message
  thread.scrollTop = thread.scrollHeight;
  thread.style.scrollBehavior = '';
}

/** Older messages, added above the ones shown (keeps the scroll position). */
async function loadEarlier() {
  if (!state.firstLoaded || state.firstLoaded <= state.firstId) return false;
  const thread = $('#thread');
  const { messages } = await api('/api/messages?before=' + state.firstLoaded);
  if (!messages.length) { state.firstId = state.firstLoaded; earlierButton(); return false; }
  const h = thread.scrollHeight, top = thread.scrollTop;
  const anchor = thread.querySelector('.msg[data-id], .trigger, .jobcard');
  const frag = document.createDocumentFragment();
  for (const m of messages) frag.append(renderMessage(m));
  thread.insertBefore(frag, $('#earlier')?.nextSibling || anchor);
  state.firstLoaded = messages[0].id;
  earlierButton(); markLast();
  thread.style.scrollBehavior = 'auto'; thread.scrollTop = top + thread.scrollHeight - h; thread.style.scrollBehavior = '';
  return true;
}
function earlierButton() {
  const more = state.firstLoaded && state.firstId && state.firstLoaded > state.firstId;
  let b = $('#earlier');
  if (!more) { b?.remove(); return; }
  if (!b) {
    b = document.createElement('button'); b.id = 'earlier'; b.type = 'button'; b.className = 'btn small ghost earlier'; b.textContent = 'Show earlier messages';
    b.onclick = () => loadEarlier().catch(e => toast(e.message));
  }
  $('#thread').prepend(b);
}

/** Scroll to a message, loading older ones if needed, and highlight it. */
async function jumpTo(id) {
  for (let i = 0; i < 100 && !$(`#thread [data-id="${id}"]`); i++) if (!(await loadEarlier())) break;
  const el = $(`#thread [data-id="${id}"]`);
  if (!el) return toast('That message is no longer in the conversation.');
  el.scrollIntoView({ block: 'center' });
  el.classList.remove('flash'); void el.offsetWidth; el.classList.add('flash');
}

function notifyBrowser(m) {
  if (!('Notification' in window) || Notification.permission !== 'granted' || !document.hidden) return;
  try { new Notification(state.status?.name || 'Talixio', { body: m.content.slice(0, 180), icon: '/icon.svg', tag: 'talixio-' + m.id }); } catch {}
}

async function poll() {
  if (!state.sending && state.status?.pending_approvals !== undefined) loadApprovals();
  if (state.sending || document.hidden && !('Notification' in window && Notification.permission === 'granted')) return;
  try { appendMessages((await api('/api/messages?after=' + state.lastId)).messages, true); } catch {}
}

const TOOL_LABEL = (name, input = {}) => {
  const host = u => { try { return new URL(u).host; } catch { return 'a page'; } };
  return ({
    web_search: `Searching the web${input.query ? ': ' + input.query : ''}`, WebSearch: `Searching the web${input.query ? ': ' + input.query : ''}`,
    fetch_url: `Reading ${host(input.url)}`, WebFetch: `Reading ${host(input.url)}`, Read: 'Looking at the image',
    search_memory: 'Searching past conversations', memory_read: 'Reading memory', memory_append: 'Saving to memory', memory_replace: 'Updating memory',
    schedule_task: 'Scheduling', list_tasks: 'Checking scheduled tasks', cancel_task: 'Cancelling a task',
    delegate: `Starting a background job${input.title ? ': ' + input.title : ''}`, list_jobs: 'Checking jobs', cancel_job: 'Cancelling a job',
    usage_report: 'Checking spending', file_read: 'Reading the attachment',
    skill_read: `Following a skill${input.name ? ': ' + input.name : ''}`, skill_save: 'Saving a skill', skill_delete: 'Removing a skill',
    calendar_events: 'Checking your calendar', calendar_add: 'Preparing a calendar event', mail_list: 'Looking through your email',
    mail_read: 'Reading an email', mail_draft: 'Writing a draft', mail_send: 'Preparing an email',
    browser_open: `Opening ${host(input.url)}`, browser_read: 'Reading the page', browser_click: 'Clicking', browser_type: 'Typing',
    browser_select: 'Choosing an option', browser_back: 'Going back', browser_screenshot: 'Looking at the page',
  })[name] || `Using ${name.replace(/_/g, ' ')}`;
};
// ---- find in the conversation ----
$('#chat-search-btn').onclick = () => {
  const p = $('#chat-search'); p.hidden = !p.hidden;
  if (!p.hidden) { $('#chat-search-q').focus(); $('#chat-search-q').select(); }
};
$('#chat-search-close').onclick = () => { $('#chat-search').hidden = true; };
$('#chat-search-q').addEventListener('keydown', e => { if (e.key === 'Escape') $('#chat-search').hidden = true; });
$('#chat-search-q').addEventListener('input', () => { clearTimeout(chatSearch.t); chatSearch.t = setTimeout(chatSearch, 300); });
async function chatSearch() {
  const q = $('#chat-search-q').value.trim(); const box = $('#chat-search-results');
  if (q.length < 2) { box.innerHTML = ''; return; }
  try {
    const { results } = await api('/api/messages/search?q=' + encodeURIComponent(q));
    if (q !== $('#chat-search-q').value.trim()) return;
    const mark = t => esc(t).replace(new RegExp(q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/\s+/g, '\\s+'), 'gi'), x => `<mark>${x}</mark>`);
    box.innerHTML = results.length ? '' : '<p class="muted small">Nothing found.</p>';
    for (const r of results) {
      const b = document.createElement('button'); b.type = 'button'; b.className = 'sres';
      b.innerHTML = `<span class="muted small">${r.role === 'user' ? 'You' : esc(state.status?.name || 'Assistant')} · ${new Date(r.ts * 1000).toLocaleString([], { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })}${r.exact ? '' : ' · similar'}</span><span>${mark(r.snippet)}</span>`;
      b.onclick = () => { jumpTo(r.id); if (matchMedia('(max-width: 760px)').matches) $('#chat-search').hidden = true; };
      box.append(b);
    }
  } catch (e) { box.innerHTML = `<p class="error small">${esc(e.message)}</p>`; }
}

const SPIN = '<svg viewBox="0 0 24 24"><path d="M12 3a9 9 0 1 0 9 9"/></svg>';

async function send(opts = {}) {
  const input = $('#input'); const text = input.value.trim();
  const files = opts.files || state.pending.filter(f => f.id).map(f => f.id);
  if ((!text && !files.length) || state.sending) return;
  if (state.pending.some(f => !f.id)) { toast('Wait for the upload to finish.'); return; }
  if (!navigator.onLine) { queueOffline(); return; }
  state.sending = true; sendMode(true);
  input.value = ''; autosize();
  const shownFiles = opts.shownFiles || state.pending.map(f => f.info);
  state.pending = []; renderPending();
  $('#empty')?.remove();
  const thread = $('#thread');
  const pending = renderMessage({ id: 0, role: 'user', channel: 'web', content: text, files: shownFiles, ts: Date.now() / 1000 });
  const live = document.createElement('div'); live.className = 'msg assistant typing';
  live.innerHTML = '<div class="bubble"><i></i><i></i><i></i></div><div class="activity" hidden></div>';
  thread.append(pending, live); thread.scrollTop = thread.scrollHeight;
  let textSoFar = '';
  const approvalsTimer = setInterval(loadApprovals, 4000);
  const activity = t => { const a = live.querySelector('.activity'); a.hidden = !t; a.innerHTML = t ? SPIN + esc(t) : ''; thread.scrollTop = thread.scrollHeight; };
  try {
    const res = await fetch('/api/chat', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Talixio': '1', Accept: 'text/event-stream' },
      body: JSON.stringify({ text, files, meeting: !!opts.meeting, stream: true }) });
    if (res.status === 401) { showLogin(); throw new Error('Please sign in.'); }
    if (!res.ok || !res.body) throw new Error(`Server error (HTTP ${res.status})`);
    const reader = res.body.getReader(); const dec = new TextDecoder(); let buf = ''; let done = null;
    while (true) {
      const { value, done: end } = await reader.read();
      if (end) break;
      buf += dec.decode(value, { stream: true });
      let i;
      while ((i = buf.indexOf('\n\n')) >= 0) {
        const block = buf.slice(0, i); buf = buf.slice(i + 2);
        const ev = (block.match(/^event: (.*)$/m) || [])[1]; const data = (block.match(/^data: (.*)$/m) || [])[1];
        if (!ev || !data) continue;
        const d = JSON.parse(data);
        if (ev === 'text') {
          textSoFar += d.text; live.classList.remove('typing');
          live.querySelector('.bubble').innerHTML = md(textSoFar); live.querySelector('.bubble').classList.add('cursor'); activity('');
          thread.scrollTop = thread.scrollHeight;
        } else if (ev === 'tool') { activity(TOOL_LABEL(d.name, d.input)); if (textSoFar) { textSoFar += '\n\n'; } }
        else if (ev === 'status') activity(d.text);
        else if (ev === 'done') done = d;
        else if (ev === 'fail') throw new Error(d.error);
      }
    }
    if (!done) throw new Error('The connection was interrupted. The answer will appear when ready.');
    pending.remove(); live.remove();
    appendMessages(done.messages);
    loadApprovals();
    clearDraft();
  } catch (e) {
    live.remove();
    if (!textSoFar) {
      pending.remove(); input.value = text; autosize();
      state.pending = shownFiles.map((info, i) => ({ id: files[i], info })).filter(f => f.id); renderPending();
      if (!navigator.onLine || e instanceof TypeError) { state.sending = false; queueOffline(); return; }
    }
    toast(e.message);
  } finally {
    clearInterval(approvalsTimer);
    state.sending = false; sendMode(false); if (!state.queued) input.focus();
  }
}

/** While an answer is being written, the send button stops it. */
function sendMode(busy) {
  const b = $('#send'); b.disabled = false; b.classList.toggle('stop', busy);
  b.innerHTML = busy ? ICON.stop : ICON.send;
  b.setAttribute('aria-label', busy ? 'Stop the answer' : 'Send'); b.title = busy ? 'Stop the answer' : '';
}
async function stopAnswer() {
  const b = $('#send'); b.disabled = true;
  try { await post('/api/chat/stop'); } catch (e) { toast(e.message); }
}

/** Offline: the message waits in the box and is sent as soon as the connection is back. */
function queueOffline() {
  state.queued = true; saveDraft();
  banner('Offline: your message will be sent as soon as you are back online.', 'Cancel', () => { state.queued = false; banner(''); }, 'offline');
}
window.addEventListener('online', () => {
  if (!state.queued) return;
  state.queued = false; banner('');
  setTimeout(() => send(), 800); // give the connection a moment
});

/** The message being written (and its attachments) survives closing the app. */
const DRAFT = 'talixio.draft';
function saveDraft() {
  try {
    const text = $('#input').value; const files = state.pending.filter(f => f.id).map(f => ({ id: f.id, info: f.info }));
    if (text.trim() || files.length) localStorage.setItem(DRAFT, JSON.stringify({ text, files, queued: !!state.queued }));
    else localStorage.removeItem(DRAFT);
  } catch {}
}
function clearDraft() { try { localStorage.removeItem(DRAFT); } catch {} }
function restoreDraft() {
  let d = null;
  try { d = JSON.parse(localStorage.getItem(DRAFT) || 'null'); } catch {}
  if (!d || $('#input').value.trim() || state.pending.length) return;
  $('#input').value = d.text || ''; autosize();
  state.pending = (d.files || []).filter(f => f.id); renderPending();
  if (d.queued) { if (navigator.onLine) send(); else queueOffline(); }
}

// ---- approvals ----
async function loadApprovals() {
  let list = [];
  try { list = (await api('/api/approvals')).approvals; } catch { return; }
  const box = $('#approvals'); box.hidden = !list.length; box.innerHTML = '';
  setBadge(list.length);
  for (const a of list) {
    const el = document.createElement('div'); el.className = 'approval';
    el.innerHTML = `<span class="label">Needs your approval · #${a.id}</span>
      <div class="actions"><button class="btn small" data-d="reject" type="button">Reject</button><button class="btn small primary" data-d="approve" type="button">Approve</button></div>
      <div class="what">${esc(a.summary)}</div>`;
    el.querySelectorAll('button').forEach(b => b.onclick = async () => {
      el.querySelectorAll('button').forEach(x => x.disabled = true);
      try { const r = await post(`/api/approvals/${a.id}/${b.dataset.d}`); appendMessages(r.messages); toast(b.dataset.d === 'approve' ? 'Approved' : 'Rejected'); }
      catch (e) { toast(e.message); }
      loadApprovals();
    });
    box.append(el);
  }
}

// ---- attachments ----
state.pending = [];
function renderPending() {
  const box = $('#pending-files'); box.hidden = !state.pending.length;
  box.innerHTML = '';
  state.pending.forEach((f, i) => {
    const c = document.createElement('span'); c.className = 'fchip' + (f.id ? '' : ' uploading');
    c.innerHTML = `${esc(f.info.name)}${f.id ? '' : ' · uploading…'} <button type="button" aria-label="Remove">×</button>`;
    c.querySelector('button').onclick = () => { state.pending.splice(i, 1); renderPending(); };
    box.append(c);
  });
  saveDraft();
}
function uploadFile(file, name, folder) {
  return new Promise((resolve, reject) => {
    const fd = new FormData(); fd.append('file', file, name || file.name);
    if (folder) fd.append('folder', folder);
    const xhr = new XMLHttpRequest();
    xhr.open('POST', '/api/files'); xhr.setRequestHeader('X-Talixio', '1');
    xhr.onload = () => { try { const d = JSON.parse(xhr.responseText); xhr.status < 300 ? resolve(d.file) : reject(new Error(d.error || 'Upload failed')); } catch { reject(new Error(`Upload failed (HTTP ${xhr.status})`)); } };
    xhr.onerror = () => reject(new Error('Upload failed: network error'));
    xhr.send(fd);
  });
}
async function addFiles(list) {
  for (const file of list) {
    const entry = { id: null, info: { name: file.name, kind: file.type.startsWith('image/') ? 'image' : 'file' } };
    state.pending.push(entry); renderPending();
    try { const info = await uploadFile(file); entry.id = info.id; entry.info = info; }
    catch (e) { state.pending.splice(state.pending.indexOf(entry), 1); toast(e.message); }
    renderPending();
  }
}

// ---- voice notes and meeting recordings ----
const rec = { media: null, chunks: [], start: 0, timer: null, wake: null };
async function startRecording() {
  if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) { toast('Recording needs a recent browser over HTTPS.'); return; }
  let stream;
  try { stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } }); }
  catch { toast('Microphone access was refused.'); return; }
  const type = ['audio/webm;codecs=opus', 'audio/mp4', 'audio/ogg;codecs=opus'].find(t => MediaRecorder.isTypeSupported(t)) || '';
  rec.media = new MediaRecorder(stream, type ? { mimeType: type, audioBitsPerSecond: 32000 } : undefined);
  rec.chunks = []; rec.media.ondataavailable = e => e.data.size && rec.chunks.push(e.data);
  rec.media.start(10000); rec.start = Date.now();
  try { rec.wake = await navigator.wakeLock?.request('screen'); } catch {}
  $('#recorder').hidden = false; $('#composer').hidden = true;
  const tick = () => { const s = Math.floor((Date.now() - rec.start) / 1000); $('#rec-time').textContent = `${Math.floor(s / 3600) ? Math.floor(s / 3600) + ':' : ''}${String(Math.floor(s / 60) % 60).padStart(Math.floor(s / 3600) ? 2 : 1, '0')}:${String(s % 60).padStart(2, '0')}`; };
  tick(); rec.timer = setInterval(tick, 1000);
}
function stopRecording() {
  return new Promise(resolve => {
    clearInterval(rec.timer); try { rec.wake?.release(); } catch {}
    $('#recorder').hidden = true; $('#composer').hidden = false;
    if (!rec.media) return resolve(null);
    rec.media.onstop = () => { rec.media.stream.getTracks().forEach(t => t.stop()); const b = new Blob(rec.chunks, { type: rec.media.mimeType || 'audio/webm' }); rec.media = null; resolve(b); };
    rec.media.stop();
  });
}
async function finishRecording(meeting) {
  const blob = await stopRecording();
  if (!blob || blob.size < 1000) { toast('Nothing was recorded.'); return; }
  const ext = blob.type.includes('mp4') ? 'm4a' : blob.type.includes('ogg') ? 'ogg' : 'webm';
  const name = `${meeting ? 'meeting' : 'voice-note'}-${new Date().toISOString().slice(0, 16).replace(/[T:]/g, '-')}.${ext}`;
  toast(meeting ? 'Uploading the recording…' : 'Sending your voice note…');
  try {
    const info = await uploadFile(blob, name);
    await send({ files: [info.id], shownFiles: [info], meeting });
  } catch (e) { toast(e.message); }
}

function autosize() { const i = $('#input'); i.style.height = 'auto'; i.style.height = Math.min(i.scrollHeight, 200) + 'px'; }

// ---- tasks ----
async function loadTasks() {
  const { tasks } = await api('/api/tasks');
  const box = $('#tasks'); box.innerHTML = '';
  if (!tasks.length) { box.innerHTML = '<div class="placeholder">No scheduled tasks yet.</div>'; return; }
  for (const t of tasks) {
    const el = document.createElement('div');
    el.className = 'task' + (t.enabled ? '' : ' paused');
    const when = t.cron ? `Repeats · <code>${esc(t.cron)}</code>` : 'Once';
    const next = t.enabled && t.next_text ? `Next: ${esc(t.next_text)}` : (t.last_run ? `Last ran ${fmtTime(t.last_run)}` : 'Paused');
    el.innerHTML = `<div><h3>${esc(t.title)}</h3><div class="when"><span class="chip">${when}</span><span>${next}</span></div></div>
      <div class="actions">${tierSelect(t.tier, 'Model that runs this task')}<button class="btn small" data-a="toggle">${t.enabled ? 'Pause' : 'Resume'}</button><button class="btn small ghost" data-a="delete">Delete</button></div>
      <p class="prompt">${esc(t.prompt)}</p>`;
    el.querySelector('select').onchange = async e => {
      try { await post(`/api/tasks/${t.id}/tier`, { tier: e.target.value }); toast(`"${t.title}" will run on the ${e.target.value} tier.`); }
      catch (err) { toast(err.message); e.target.value = t.tier; }
    };
    el.querySelectorAll('button').forEach(b => b.onclick = async () => {
      try { await post(`/api/tasks/${t.id}/${b.dataset.a}`); toast(b.dataset.a === 'delete' ? 'Task deleted' : (t.enabled ? 'Task paused' : 'Task resumed')); loadTasks(); }
      catch (e) { toast(e.message); }
    });
    box.append(el);
  }
}

// ---- jobs ----
/** fast / standard / deep, with the model behind each. */
function tierSelect(current, label) {
  const tiers = state.status?.tiers || {};
  return `<select class="tier-select" aria-label="${esc(label)}" title="${esc(label)}">${['fast', 'standard', 'deep'].map(n =>
    `<option value="${n}"${n === current ? ' selected' : ''}>${n}${tiers[n] ? ' · ' + esc(shortModel(tiers[n])) : ''}</option>`).join('')}</select>`;
}

async function loadJobs() {
  const { jobs } = await api('/api/jobs');
  const s = state.status;
  if (s) $('#jobs-help').textContent = `Sub-agents the assistant starts for research and multi-step work: up to ${s.max_parallel_jobs} at once, each capped at $${s.job_max_usd.toFixed(2)}. Results come back in the chat.`;
  const box = $('#jobs'); box.innerHTML = '';
  if (!jobs.length) { box.innerHTML = '<div class="placeholder">No jobs yet. Ask for research, e.g. <q>compare the three best e-bikes under €2000</q>.</div>'; return; }
  for (const j of jobs) {
    const el = document.createElement('div');
    el.className = 'task';
    const dur = j.started ? Math.max(1, Math.round(((j.finished || Date.now() / 1000) - j.started))) : null;
    const durText = dur == null ? 'waiting' : dur < 90 ? dur + ' s' : Math.round(dur / 60) + ' min';
    el.innerHTML = `<div><h3>${esc(j.title)}</h3><div class="when"><span class="pill ${j.status}">${j.status === 'running' ? '<span class="dot"></span>' : ''}${j.status}</span>
        <span class="chip">${esc(j.tier)}</span><span>${money(+j.cost_usd) || '$0'} of $${(+j.max_cost_usd).toFixed(2)}</span><span>${durText}</span><span>${fmtTime(j.created)}</span></div></div>
      <div class="actions">${['queued', 'running'].includes(j.status) ? '<button class="btn small" data-a="cancel">Cancel</button>'
        : j.kind !== 'reflect' ? `${tierSelect(j.tier, 'Model for running it again')}<button class="btn small" data-a="rerun">Run again</button>` : ''}</div>
      <details><summary>Instructions${j.result ? ' and report' : ''}</summary><p class="report"><b>Instructions:</b> ${esc(j.instructions)}</p>${j.result ? `<p class="report"><b>Report:</b> ${esc(j.result)}</p>` : ''}</details>`;
    el.querySelector('[data-a=cancel]')?.addEventListener('click', async () => {
      try { await post(`/api/jobs/${j.id}/cancel`); toast('Job cancelled'); loadJobs(); } catch (e) { toast(e.message); }
    });
    el.querySelector('[data-a=rerun]')?.addEventListener('click', async () => {
      const tier = el.querySelector('select').value;
      try { await post(`/api/jobs/${j.id}/rerun`, { tier }); toast(`Running again on the ${tier} tier: the report will come in the chat.`); loadJobs(); } catch (e) { toast(e.message); }
    });
    box.append(el);
  }
  clearTimeout(loadJobs.t);
  if (jobs.some(j => ['queued', 'running'].includes(j.status)) && location.hash === '#jobs') loadJobs.t = setTimeout(() => loadJobs().catch(() => {}), 4000);
}

// ---- channels ----
const b64ToBytes = s => { const b = atob((s + '='.repeat((4 - s.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/')); return Uint8Array.from(b, c => c.charCodeAt(0)); };
async function pushSubscription() {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) return null;
  const reg = await navigator.serviceWorker.getRegistration();
  return reg ? reg.pushManager.getSubscription() : null;
}
async function enablePush() {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
    throw new Error(/iPhone|iPad/.test(navigator.userAgent) ? 'On iPhone and iPad: Share → Add to Home Screen, open Talixio from there, then turn this on.' : 'This browser does not support push notifications. It needs HTTPS.');
  }
  if (await Notification.requestPermission() !== 'granted') throw new Error('Notifications are blocked for this site in your browser settings.');
  const reg = await navigator.serviceWorker.register('/sw.js');
  await navigator.serviceWorker.ready;
  const { key } = await api('/api/push/key');
  const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToBytes(key) });
  await post('/api/push/subscribe', sub.toJSON());
}
async function disablePush() {
  const sub = await pushSubscription();
  if (sub) { await post('/api/push/unsubscribe', { endpoint: sub.endpoint }); await sub.unsubscribe(); }
}
async function loadChannels() {
  const { channels, notify_on } = await api('/api/channels');
  const mySub = await pushSubscription().catch(() => null);
  const box = $('#channels'); box.innerHTML = '';
  for (const c of channels) {
    const on = c.state === 'on', partial = ['notifications only', 'read only'].includes(c.state);
    const el = document.createElement('div'); el.className = 'channel';
    const uses = [c.chat ? 'chat' : null, notify_on.includes(c.id) ? 'notifications' : null].filter(Boolean).join(' + ');
    el.innerHTML = `<h3>${esc(c.name)} <span class="pill ${on ? 'on' : partial ? 'partial' : 'off'}">${esc(c.state)}</span>${uses && (on || partial) ? `<span class="chip">${uses}</span>` : ''}</h3>
      <div class="actions"></div>
      <p>${esc(c.detail)}</p>${c.error ? `<p class="err">Last error: ${esc(c.error)}</p>` : ''}`;
    if (c.id === 'webpush') {
      const b = document.createElement('button'); b.className = 'btn small' + (mySub ? '' : ' primary'); b.type = 'button';
      b.textContent = mySub ? 'Turn off on this device' : 'Turn on for this device';
      b.onclick = async () => {
        b.disabled = true;
        try { if (mySub) { await disablePush(); toast('Push notifications off on this device'); } else { await enablePush(); toast('Push notifications on for this device'); $('#notify-btn').hidden = true; } loadChannels(); }
        catch (e) { toast(e.message); b.disabled = false; }
      };
      el.querySelector('.actions').append(b);
    }
    box.append(el);
  }
}

// ---- updates ----
async function loadUpdate() {
  let d;
  try { d = await api('/api/update'); }
  catch { if (state.updating) setTimeout(loadUpdate, 3000); return; } // the app restarts during an update
  const box = $('#update'); box.hidden = false;
  if (!d.installed) { box.innerHTML = '<h3>Updates</h3><p class="muted small">Updates from the app work on an installation made with <code>git clone</code> + <code>install.sh</code>.</p>'; return; }
  const v = d.version || {}, st = d.status || {};
  const busy = ['updating', 'checking'].includes(st.state);
  if (state.updating && !busy && state.updateFrom && v.sha !== state.updateFrom) { toast('Updated. Reloading…'); setTimeout(() => location.reload(), 1200); return; }
  if (state.updating && !busy && ['failed', 'done', 'rolled_back'].includes(st.state)) { state.updating = false; if (st.state !== 'failed') { setTimeout(() => location.reload(), 1200); } }
  const when = t => t ? new Date(t * 1000).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : 'never';
  box.innerHTML = `<div class="row between"><h3>Updates</h3><span class="version">${esc(v.short || '?')} · ${esc((v.date || '').slice(0, 10))}</span></div>
    <p class="small ${st.state === 'failed' ? 'error' : 'muted'}">${esc(st.message || 'Not checked yet.')} ${st.checked_at ? '· checked ' + when(st.checked_at) : ''}${d.auto ? ' · installs automatically at night' : ''}</p>
    ${st.state === 'available' && st.commits?.length ? `<ul>${st.commits.map(c => `<li>${esc(c.subject)} <span class="muted small">${esc(c.date)}</span></li>`).join('')}</ul>` : ''}
    ${busy || st.state === 'failed' ? `<div class="log">${esc((st.log || '').split('\n').slice(-14).join('\n'))}</div>` : ''}
    <div class="row" style="flex-wrap:wrap">
      ${st.state === 'available' ? '<button class="btn primary" data-u="update" type="button">Update now</button>' : ''}
      <button class="btn" data-u="check" type="button" ${busy ? 'disabled' : ''}>${busy ? (st.state === 'checking' ? 'Checking…' : 'Updating…') : 'Check now'}</button>
      ${st.can_rollback && !busy ? '<button class="btn ghost" data-u="rollback" type="button">Restore previous version</button>' : ''}
    </div>`;
  box.querySelectorAll('[data-u]').forEach(b => b.onclick = async () => {
    if (b.dataset.u !== 'check' && b.dataset.confirm !== '1') { b.dataset.confirm = '1'; b.textContent = b.dataset.u === 'update' ? 'Confirm: back up and update' : 'Confirm restore'; return; }
    try {
      await post('/api/update/' + b.dataset.u);
      if (b.dataset.u === 'check') state.checkPolls = 10; else { state.updating = true; state.updateFrom = v.sha; }
      toast(b.dataset.u === 'check' ? 'Checking…' : b.dataset.u === 'update' ? 'Updating: a backup is made first. The app restarts at the end.' : 'Restoring…');
    } catch (e) { toast(e.message); }
    setTimeout(loadUpdate, 1500);
  });
  if (busy || state.updating) setTimeout(loadUpdate, 3000);
  else if (state.checkPolls > 0) { state.checkPolls--; setTimeout(loadUpdate, 2000); }
}

// ---- settings ----
function fieldInput(f) {
  const id = 'set-' + f.key;
  const eff = f.value !== '' ? f.value : f.default;
  if (f.type === 'bool') return `<input id="${id}" type="checkbox" ${eff === '1' ? 'checked' : ''}>`;
  if (f.type === 'select') return `<select id="${id}">${Object.entries(f.options).map(([v, l]) => `<option value="${esc(v)}" ${v === eff ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>`;
  if (f.type === 'multi') {
    const on = (eff || '').split(',');
    return `<div class="checks" id="${id}">${Object.entries(f.options).map(([v, l]) => `<label><input type="checkbox" value="${esc(v)}" ${on.includes(v) ? 'checked' : ''}> ${esc(l)}</label>`).join('')}</div>`;
  }
  if (f.type === 'secret') return `<input id="${id}" type="password" autocomplete="new-password" placeholder="${f.set ? 'Set (' + esc(f.hint.trim()) + '). Type to replace.' : 'Not set'}">`;
  return `<input id="${id}" type="${f.type === 'number' ? 'text' : f.type === 'url' ? 'url' : 'text'}" ${f.type === 'number' ? 'inputmode="decimal"' : ''} value="${esc(f.value)}" placeholder="${esc(f.default || '')}">`;
}
function fieldValue(f) {
  const el = document.getElementById('set-' + f.key);
  if (f.type === 'bool') return el.checked ? '1' : '0';
  if (f.type === 'multi') return [...el.querySelectorAll('input:checked')].map(i => i.value).join(',');
  return el.value.trim();
}
function initialValue(f) {
  const eff = f.value !== '' ? f.value : f.default;
  if (f.type === 'bool') return eff === '1' ? '1' : '0';
  if (f.type === 'multi') return (eff || '').split(',').filter(Boolean).join(',');
  if (f.type === 'secret') return '';
  if (f.type === 'select') return eff;
  return f.value;
}
function renderGroups(groups) {
  const box = $('#settings-groups'); const open = new Set([...box.querySelectorAll('details[open]')].map(d => d.dataset.id)); box.innerHTML = '';
  for (const g of groups) {
    const el = document.createElement('details'); el.className = 'card group'; el.dataset.id = g.id; if (open.has(g.id)) el.open = true;
    const setCount = g.fields.filter(f => f.set).length;
    el.innerHTML = `<summary><b>${esc(g.title)}</b><span class="count">${setCount ? setCount + ' set' : ''}</span></summary>
      ${g.intro ? `<p class="muted small">${esc(g.intro)}</p>` : ''}
      <div class="fields">${g.fields.map(f => `<div class="field ${f.type === 'bool' ? 'bool' : ''}" data-key="${f.key}">
          ${f.type === 'bool' ? fieldInput(f) : ''}
          <div style="display:flex;flex-direction:column;gap:4px;min-width:0;flex:1">
            <div class="top"><label for="set-${f.key}">${esc(f.label)}</label>
              ${f.source === 'app' ? `<span class="src">set in the app · <button type="button" data-reset="${f.key}">reset</button></span>` : f.source === 'file' ? '<span class="src">from the config file</span>' : ''}
              ${f.type === 'secret' && f.set && f.source === 'app' ? '' : ''}</div>
            ${f.type !== 'bool' ? fieldInput(f) : ''}
            ${f.help ? `<p class="help">${esc(f.help)}</p>` : ''}<p class="err" hidden></p>
          </div></div>`).join('')}
        <div class="row"><button class="btn primary" type="button" data-save>Save</button><span class="muted small" data-state></span></div></div>`;
    el.querySelectorAll('.field').forEach(fe => {
      const f = g.fields.find(x => x.key === fe.dataset.key);
      const mark = () => fe.classList.toggle('changed', fieldValue(f) !== initialValue(f));
      fe.addEventListener('input', mark); fe.addEventListener('change', mark);
    });
    el.querySelectorAll('[data-reset]').forEach(b => b.onclick = () => saveSettings(el, g, [b.dataset.reset]));
    el.querySelector('[data-save]').onclick = () => saveSettings(el, g, []);
    box.append(el);
  }
}
async function saveSettings(el, g, reset) {
  const changes = {};
  if (!reset.length) for (const f of g.fields) { const v = fieldValue(f); if (v !== initialValue(f)) changes[f.key] = v; }
  const state = el.querySelector('[data-state]');
  if (!reset.length && !Object.keys(changes).length) { state.textContent = 'Nothing changed.'; return; }
  el.querySelectorAll('.err').forEach(e => { e.hidden = true; });
  try {
    const res = await fetch('/api/settings', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Talixio': '1' }, body: JSON.stringify({ changes, reset }) });
    const d = await res.json();
    if (!res.ok) {
      for (const [k, msg] of Object.entries(d.errors || {})) { const e = el.querySelector(`[data-key="${k}"] .err`); if (e) { e.textContent = msg; e.hidden = false; } }
      state.textContent = d.error || 'Not saved.'; return;
    }
    renderGroups(d.groups); toast(reset.length ? 'Back to the config file value' : 'Saved. The background worker restarts to apply it.');
    refreshStatus();
  } catch (e) { toast(e.message); }
}
async function loadSettings() {
  loadChannels().catch(e => toast(e.message));
  loadUpdate().catch(() => {});
  loadSecurity();
  renderGroups((await api('/api/settings')).groups);
}
$('#doctor-run').onclick = async () => {
  const b = $('#doctor-run'); b.disabled = true; b.textContent = 'Checking…';
  const ul = $('#doctor'); ul.hidden = false; ul.innerHTML = '<li class="info">Running checks (this can take a few seconds)…</li>';
  try { const { results } = await post('/api/doctor'); ul.innerHTML = results.map(r => `<li class="${r.status}">${esc(r.text)}</li>`).join(''); }
  catch (e) { ul.innerHTML = `<li class="fail">${esc(e.message)}</li>`; }
  b.disabled = false; b.textContent = 'Run checks';
};
$('#pw-form').onsubmit = async e => {
  e.preventDefault();
  try { await post('/api/settings/password', { current: $('#pw-current').value, new: $('#pw-new').value }); $('#pw-state').textContent = 'Password changed. Your other devices will ask for it again.'; $('#pw-current').value = $('#pw-new').value = ''; }
  catch (err) { $('#pw-state').textContent = err.message; }
};

// ---- two-step sign-in ----
async function loadSecurity() {
  try { showTotp((await api('/api/security')).totp); } catch { /* shown elsewhere */ }
}
function showTotp(on) {
  $('#totp-badge').textContent = on ? '· on' : '· off';
  $('#totp-off').hidden = on; $('#totp-disable').hidden = !on; $('#totp-enable').hidden = true;
}
function loadScript(src) {
  return new Promise((ok, ko) => {
    if (document.querySelector(`script[src="${src}"]`)) return ok();
    const s = document.createElement('script'); s.src = src; s.onload = ok; s.onerror = ko; document.head.append(s);
  });
}
$('#totp-setup').onclick = async () => {
  try {
    const d = await post('/api/security/totp/setup');
    $('#totp-secret').textContent = d.secret.replace(/(.{4})/g, '$1 ').trim();
    $('#totp-link').href = d.uri;
    $('#totp-off').hidden = true; $('#totp-enable').hidden = false; $('#totp-state').textContent = '';
    try {
      await loadScript('/vendor/qrcode.js');
      const qr = qrcode(0, 'M'); qr.addData(d.uri); qr.make();
      $('#totp-qr').innerHTML = qr.createSvgTag({ cellSize: 4, margin: 4, scalable: true });
    } catch { $('#totp-qr').textContent = ''; }
    $('#totp-code').focus();
  } catch (e) { toast(e.message); }
};
$('#totp-enable').onsubmit = async e => {
  e.preventDefault();
  try { await post('/api/security/totp/enable', { code: $('#totp-code').value }); $('#totp-code').value = ''; showTotp(true); toast('Two-step sign-in is on.'); }
  catch (err) { $('#totp-state').textContent = err.message; }
};
$('#totp-disable').onsubmit = async e => {
  e.preventDefault();
  try { await post('/api/security/totp/disable', { password: $('#totp-dis-pw').value, code: $('#totp-dis-code').value }); $('#totp-dis-pw').value = $('#totp-dis-code').value = ''; showTotp(false); toast('Two-step sign-in is off.'); }
  catch (err) { $('#totp-dis-state').textContent = err.message; }
};

// ---- memory ----
const MEM_HELP = {
  'USER.md': 'What the assistant knows about you.',
  'MEMORY.md': 'Notes, decisions and things to remember.',
  'SOUL.md': 'Personality and tone. Change this to change how it talks.',
  'HEARTBEAT.md': 'Checklist for periodic checks (needs TALIXIO_HEARTBEAT_MINUTES). It only messages you when something needs attention.',
};
async function loadSkills() {
  const { skills } = await api('/api/skills');
  const box = $('#skills'); box.innerHTML = '';
  if (!skills.length) { box.innerHTML = '<div class="placeholder">No skills yet. After multi-step tasks it writes down how it did them, so next time is faster and closer to what you want.</div>'; return; }
  for (const k of skills) {
    const el = document.createElement('div'); el.className = 'skill';
    el.innerHTML = `<h4>${esc(k.name)}</h4><div class="actions"><button class="btn small" data-a="edit" type="button">Open</button><button class="btn small ghost" data-a="del" type="button">Delete</button></div>
      <p>${esc(k.description)}${k.used ? ` · used ${k.used}×` : ''}</p>`;
    el.querySelector('[data-a=edit]').onclick = async () => {
      const { content } = await api('/api/skills/' + encodeURIComponent(k.name));
      state.skill = k.name; $('#skill-editor').value = content; $('#skill-edit').hidden = false; $('#skill-state').textContent = '';
      $('#skill-editor').focus();
    };
    el.querySelector('[data-a=del]').onclick = async () => {
      const b = el.querySelector('[data-a=del]');
      if (b.dataset.confirm !== '1') { b.dataset.confirm = '1'; b.textContent = 'Confirm delete'; return; }
      try { await post(`/api/skills/${encodeURIComponent(k.name)}/delete`); toast('Skill deleted'); loadSkills(); } catch (e) { toast(e.message); }
    };
    box.append(el);
  }
}

function showDigest(d) {
  const l = d.levels || {};
  const parts = [l[3] && `${l[3]} month(s)`, l[2] && `${l[2]} week(s)`, l[1] && `${l[1]} recent summary(ies)`].filter(Boolean);
  $('#digest-state').textContent = (parts.length ? 'Digest: ' + parts.join(', ') + '. ' : 'No digest yet. ') + `${d.waiting} message(s) word for word or waiting to be summarised.`;
  $('#digest').innerHTML = d.digest ? md(d.digest) : '<p class="muted">Nothing compacted yet: it starts once the conversation grows.</p>';
}
async function loadDigest() { showDigest(await api('/api/memory/digest')); }
$('#digest-compact').onclick = async () => {
  const b = $('#digest-compact'); b.disabled = true; b.textContent = 'Compacting…';
  try { const d = await post('/api/memory/compact'); showDigest(d); toast(d.done ? 'Conversation compacted.' : 'Nothing to compact yet.'); }
  catch (e) { toast(e.message); }
  b.disabled = false; b.textContent = 'Compact now';
};

async function loadMemory() {
  loadSkills().catch(e => toast(e.message));
  loadDigest().catch(() => {});
  state.memFiles = (await api('/api/memory')).files;
  const tabs = $('#mem-tabs'); tabs.innerHTML = '';
  for (const f of Object.keys(state.memFiles)) {
    const b = document.createElement('button');
    b.className = 'tab'; b.type = 'button'; b.role = 'tab'; b.textContent = f;
    b.setAttribute('aria-selected', String(f === state.memFile));
    b.onclick = () => { state.memFile = f; loadMemoryTab(); };
    tabs.append(b);
  }
  loadMemoryTab();
}
function loadMemoryTab() {
  document.querySelectorAll('#mem-tabs .tab').forEach(b => b.setAttribute('aria-selected', String(b.textContent === state.memFile)));
  $('#mem-editor').value = state.memFiles[state.memFile] ?? '';
  $('#mem-help').textContent = MEM_HELP[state.memFile] || '';
  $('#mem-state').textContent = '';
}


// ---- library ----
const KIND_LABEL = { pdf: 'PDF', office: 'DOC', text: 'TXT', audio: 'AUD', image: 'IMG', other: 'FILE' };
const FOLDER_SVG = '<svg viewBox="0 0 24 24"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>';
const fmtSize = b => b < 1024 ? b + ' B' : b < 1048576 ? Math.round(b / 1024) + ' KB' : (b / 1048576).toFixed(1) + ' MB';
const fmtDate = ts => new Date(ts * 1000).toLocaleDateString([], { day: 'numeric', month: 'short', year: 'numeric' });
const joinPath = (folder, name) => (folder === '/' ? '' : folder) + '/' + name;
const lib = { folder: '/', tree: [], timer: null, file: null, inline: null };

function libFolderFromHash() {
  const m = location.hash.match(/^#library(\/.*)?$/);
  return m ? (m[1] ? decodeURIComponent(m[1]) : '/') : null; // '#library' alone is the top of the library
}
function libGo(folder) { location.hash = '#library' + (folder === '/' ? '' : folder.split('/').map(encodeURIComponent).join('/')); }

async function loadLibrary() {
  lib.folder = libFolderFromHash() || '/';
  const [tree, list] = await Promise.all([api('/api/library/tree'), api('/api/library?folder=' + encodeURIComponent(lib.folder))]);
  lib.tree = tree.tree;
  lib.folder = list.folder;
  const st = list.stats;
  $('#lib-stats').textContent = st.files ? `${nf.format(st.files)} files · ${fmtSize(st.bytes)}${st.failed ? ` · ${st.failed} unreadable` : ''}. ${st.pending ? `Reading ${st.pending} new file${st.pending > 1 ? 's' : ''} to make ${st.pending > 1 ? 'them' : 'it'} searchable…` : 'Everything is searchable, by you and by the assistant.'}`
    : 'Your documents, notes and recordings. Everything is searchable, by you and by the assistant.';
  $('#folder-list').innerHTML = lib.tree.map(t => `<option value="${esc(t.path)}">`).join('');
  renderTree(); renderCrumbs(); renderList(list);
  // the Library tab reopens the last folder; the breadcrumb's "Library" goes to the top
  document.querySelectorAll('a[data-view=library]').forEach(a => { a.href = '#library' + (lib.folder === '/' ? '' : lib.folder.split('/').map(encodeURIComponent).join('/')); });
  $('#lib-scope-label').textContent = lib.folder === '/' ? 'this folder only' : `only in ${lib.folder.split('/').pop()}`;
  $('#lib-scope').closest('label').hidden = lib.folder === '/';
  clearTimeout(lib.timer);
  if (list.files.some(f => f.status === 'pending' || f.status === 'processing')) lib.timer = setTimeout(() => { if (!$('#view-library').hidden) loadLibrary().catch(() => {}); }, 4000);
}

function renderTree() {
  const nav = $('#lib-tree');
  const total = lib.tree.filter(t => t.depth === 1).reduce((a, t) => a + t.total, 0);
  const link = (path, name, depth, count) => `<a href="#" data-path="${esc(path)}" class="${path === lib.folder ? 'active' : ''}" style="padding-left:${8 + (depth - 1) * 14}px">${FOLDER_SVG}<span>${esc(name)}</span>${count ? `<small>${count}</small>` : ''}</a>`;
  nav.innerHTML = link('/', 'All files', 1, total) + lib.tree.map(t => link(t.path, t.name, t.depth + 1, t.total)).join('');
  nav.querySelectorAll('a').forEach(a => {
    a.onclick = e => { e.preventDefault(); libGo(a.dataset.path); };
    dropTarget(a, a.dataset.path);
  });
}

function renderCrumbs() {
  const parts = lib.folder === '/' ? [] : lib.folder.slice(1).split('/');
  const items = [['/', 'Library'], ...parts.map((p, i) => ['/' + parts.slice(0, i + 1).join('/'), p])];
  $('#lib-crumbs').innerHTML = items.map(([path, name]) => `<li><a href="#" data-path="${esc(path)}">${esc(name)}</a></li>`).join('');
  $('#lib-crumbs').querySelectorAll('a').forEach(a => { a.onclick = e => { e.preventDefault(); libGo(a.dataset.path); }; dropTarget(a, a.dataset.path); });
  const fa = $('#lib-folder-actions');
  const zipName = (lib.folder === '/' ? 'Library' : lib.folder.split('/').pop()) + '-' + new Date().toLocaleDateString('sv-SE') + '.zip';
  const zip = `<a class="btn small" href="/api/library/zip?folder=${encodeURIComponent(lib.folder)}" download="${esc(zipName)}" title="Download this folder and its subfolders as a ZIP">Download zip</a>`;
  fa.innerHTML = zip + (lib.folder === '/' ? '' : '<button class="btn small" data-a="rename" type="button" title="Rename or move this folder">Rename</button><button class="btn small ghost danger" data-a="delete" type="button" title="Delete this folder">Delete</button>');
  fa.querySelector('[data-a=rename]')?.addEventListener('click', () => libInline(lib.folder, 'Folder path', async v => { const { folder } = await post('/api/library/folder/move', { from: lib.folder, to: v }); toast('Folder renamed'); libGo(folder); }));
  fa.querySelector('[data-a=delete]')?.addEventListener('click', async e => {
    const b = e.currentTarget, node = lib.tree.find(t => t.path === lib.folder), n = node ? node.total : 0;
    if (b.dataset.confirm !== '1') { b.dataset.confirm = '1'; b.textContent = n ? `Delete with its ${n} file${n > 1 ? 's' : ''}?` : 'Confirm delete'; return; }
    try { await post('/api/library/folder/delete', { path: lib.folder, recursive: true }); toast('Folder deleted'); libGo(lib.folder.split('/').slice(0, -1).join('/') || '/'); } catch (err) { toast(err.message); }
  });
}

const DL_SVG = '<svg viewBox="0 0 24 24"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>';
const isVideo = f => (f.mime || '').startsWith('video/');
function fileRow(f, sub) {
  const el = document.createElement('div'); el.className = 'lib-row'; el.draggable = true; el.tabIndex = 0; el.setAttribute('role', 'button');
  const ext = (f.name.match(/\.([a-z0-9]{1,4})$/i) || [])[1];
  const label = f.note ? 'NOTE' : isVideo(f) ? 'VID' : ['text', 'office'].includes(f.kind) && ext ? ext.toUpperCase() : KIND_LABEL[f.kind] || 'FILE';
  const ico = f.kind === 'image' || f.kind === 'pdf' ? `<img src="/api/files/${f.id}/thumb" alt="" loading="lazy">` : label;
  const status = f.status === 'pending' || f.status === 'processing' ? '<span class="pill pending">reading…</span>' : f.status === 'failed' ? '<span class="pill failed">unreadable</span>' : '';
  el.innerHTML = `<span class="ico">${ico}</span><span class="name"><b>${esc(f.name)}</b><span class="${sub ? 'snippet' : ''}">${sub ? esc(sub) : `${fmtSize(f.size)} · ${fmtDate(f.created)}${f.duration ? ' · ' + Math.round(f.duration / 60) + ' min' : ''}`}</span></span>`
    + `<span class="row-end">${status}<a class="btn ghost icon dl" href="/api/files/${f.id}/file/${encodeURIComponent(f.name)}?download=1" download="${esc(f.name)}" aria-label="Download ${esc(f.name)}" title="Download">${DL_SVG}</a></span>`;
  el.querySelector('.dl').onclick = e => e.stopPropagation();
  const th = el.querySelector('.ico img'); if (th) th.onerror = () => th.replaceWith(label);
  el.onclick = () => openFile(f.id);
  el.onkeydown = e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openFile(f.id); } };
  el.addEventListener('dragstart', e => { e.dataTransfer.setData('text/x-talixio-file', String(f.id)); e.dataTransfer.effectAllowed = 'move'; });
  return el;
}

function renderList(list) {
  const box = $('#lib-list'); box.innerHTML = '';
  for (const t of list.folders) {
    const el = document.createElement('a'); el.href = '#'; el.className = 'lib-row';
    el.innerHTML = `<span class="ico folder">${FOLDER_SVG}</span><span class="name"><b>${esc(t.name)}</b><span>${t.total} file${t.total === 1 ? '' : 's'}</span></span><span></span>`;
    el.onclick = e => { e.preventDefault(); libGo(t.path); };
    dropTarget(el, t.path);
    box.append(el);
  }
  for (const f of list.files) box.append(fileRow(f));
  if (!list.folders.length && !list.files.length) box.innerHTML = `<div class="placeholder">${lib.folder === '/' ? 'Your library is empty. Upload documents, or attach them in the chat: they land in Inbox.' : 'This folder is empty. Drop files here or use Upload.'}</div>`;
}

/** Folders accept files dragged from the list (move) or from the computer (upload). */
function dropTarget(el, path) {
  el.addEventListener('dragover', e => { e.preventDefault(); e.stopPropagation(); el.classList.add('drop'); });
  el.addEventListener('dragleave', () => el.classList.remove('drop'));
  el.addEventListener('drop', async e => {
    e.preventDefault(); e.stopPropagation(); el.classList.remove('drop'); $('#lib-main').classList.remove('drag');
    const id = e.dataTransfer.getData('text/x-talixio-file');
    if (id) { try { await post(`/api/files/${id}/move`, { folder: path }); toast('Moved to ' + path); loadLibrary(); } catch (err) { toast(err.message); } return; }
    libUpload([...(e.dataTransfer.files || [])], path);
  });
}

async function libUpload(files, folder = lib.folder) {
  if (!files.length) return;
  const target = folder === '/' ? '/Inbox' : folder;
  let ok = 0;
  for (const [i, f] of files.entries()) {
    $('#lib-stats').textContent = `Uploading ${i + 1} of ${files.length}: ${f.name}…`;
    try { await uploadFile(f, f.name, target); ok++; } catch (e) { toast(`${f.name}: ${e.message}`); }
  }
  if (ok) toast(`${ok} file${ok > 1 ? 's' : ''} added to ${target}`);
  loadLibrary().catch(e => toast(e.message));
}

function libInline(value, placeholder, onOk) {
  const form = $('#lib-inline'), input = $('#lib-inline-input');
  form.hidden = false; input.value = value; input.placeholder = placeholder; input.setAttribute('list', 'folder-list'); input.focus(); input.select();
  form.onsubmit = async e => { e.preventDefault(); try { await onOk(input.value.trim()); form.hidden = true; } catch (err) { toast(err.message); } };
}

const fileUrl = f => `/api/files/${f.id}/file/${encodeURIComponent(f.name)}`;
const previewUrl = f => `/preview/${f.id}/${encodeURIComponent(f.name)}`;
/** Rendered documents are fetched as data and shown in a sandboxed srcdoc frame (no scripts, own origin). */
async function fillPreview(frame, id) {
  frame.srcdoc = '<p style="font:14px sans-serif;color:#5b6a66;padding:16px">Loading…</p>';
  try { frame.srcdoc = (await api(`/api/files/${id}/preview`)).html; }
  catch (e) { frame.srcdoc = `<p style="font:14px sans-serif;color:#b42318;padding:16px">${esc(e.message)}</p>`; }
}
const FRAME_SANDBOX = 'allow-popups allow-popups-to-escape-sandbox';
const RENDERED = ['text', 'office'];
async function openFile(id) {
  const v = $('#viewer'), body = $('#viewer-body');
  let d;
  try { d = await api(`/api/files/${id}/text`); } catch (e) { toast(e.message); return; }
  const f = lib.file = d.file;
  v.hidden = false; $('#viewer-form').hidden = true;
  $('#viewer-name').textContent = f.name;
  $('#viewer-path').textContent = `${f.folder} · ${fmtSize(f.size)} · ${fmtDate(f.created)} · #${f.id}`;
  const rendered = RENDERED.includes(f.kind);
  const opens = rendered || ['image', 'pdf', 'audio'].includes(f.kind);
  $('#viewer-open').href = rendered ? previewUrl(f) : fileUrl(f); $('#viewer-open').hidden = !opens;
  $('#viewer-download').href = fileUrl(f) + '?download=1'; $('#viewer-download').setAttribute('download', f.name);
  const del = $('#viewer-delete'); del.dataset.confirm = ''; del.textContent = 'Delete';
  const editable = f.note || (f.kind === 'text' && f.size < 2_000_000 && !/\.(html?|eml)$/i.test(f.name));
  const hasText = !editable && (f.kind === 'office' || /\.(html?|eml)$/i.test(f.name)) && d.text;
  const tabs = [rendered && ['view', 'View'], editable && ['edit', 'Edit'], hasText && ['text', 'Text only']].filter(Boolean);
  let html = '';
  if (f.kind === 'image') html += `<a href="${fileUrl(f)}" target="_blank" rel="noopener" title="Open full size"><img src="${fileUrl(f)}" alt="${esc(f.name)}"></a>`;
  if (f.kind === 'audio') html += isVideo(f) ? `<video controls playsinline preload="metadata" src="${fileUrl(f)}"></video>` : `<audio controls preload="metadata" src="${fileUrl(f)}"></audio>`;
  if (f.kind === 'pdf' && d.pages) {
    const first = Math.min(d.pages, 5);
    html += `<div class="pdf-pages" id="pdf-pages">${Array.from({ length: first }, (_, i) => pdfPageHtml(f.id, i + 1, d.pages)).join('')}</div>`
      + (d.pages > first ? `<button id="pdf-more" class="btn small" type="button" data-next="${first + 1}">Show more pages (${d.pages - first} left)</button>` : '');
  }
  if (tabs.length > 1) html += `<div class="tabs" role="tablist">${tabs.map(([k, l], i) => `<button class="tab" data-tab="${k}" type="button" role="tab" aria-selected="${i === 0}">${l}</button>`).join('')}</div>`;
  if (rendered) html += `<iframe id="viewer-frame" class="doc-frame" data-pane="view" title="${esc(f.name)}" sandbox="${FRAME_SANDBOX}" referrerpolicy="no-referrer"></iframe>`;
  if (editable) html += `<div id="viewer-edit-box" class="viewer-edit" data-pane="edit" ${rendered ? 'hidden' : ''}><textarea id="viewer-editor" class="editor" spellcheck="true" aria-label="Content">${esc(d.text)}</textarea><div class="row"><button id="viewer-save" class="btn primary" type="button">Save</button><span id="viewer-state" class="muted small"></span></div></div>`;
  if (hasText) html += `<pre class="extracted" data-pane="text" hidden>${esc(d.text)}${d.truncated ? '\n…' : ''}</pre>`;
  if (!rendered) {
    if (f.status === 'pending' || f.status === 'processing') html += '<p class="muted">Reading this file… it will be searchable in a moment.</p>';
    else if (d.text && f.kind !== 'pdf') html += `<h4>${f.kind === 'audio' ? 'Transcript' : f.kind === 'image' ? 'What the assistant sees' : 'Text'}</h4><pre class="extracted">${esc(d.text)}${d.truncated ? '\n…' : ''}</pre>`;
    else if (f.kind === 'other') html += '<p class="muted">This kind of file cannot be shown here; download it to open it.</p>';
  }
  body.innerHTML = html;
  body.classList.toggle('framed', rendered);
  if (rendered) fillPreview($('#viewer-frame'), f.id);
  const more = $('#pdf-more');
  if (more) more.onclick = () => {
    const from = +more.dataset.next, to = Math.min(d.pages, from + 9);
    for (let n = from; n <= to; n++) $('#pdf-pages').insertAdjacentHTML('beforeend', pdfPageHtml(f.id, n, d.pages));
    more.dataset.next = to + 1; more.textContent = `Show more pages (${d.pages - to} left)`; more.hidden = to >= d.pages;
  };
  body.querySelectorAll('[data-tab]').forEach(t => t.onclick = () => {
    body.querySelectorAll('[data-tab]').forEach(o => o.setAttribute('aria-selected', String(o === t)));
    body.querySelectorAll('[data-pane]').forEach(p => { p.hidden = p.dataset.pane !== t.dataset.tab; });
    if (t.dataset.tab === 'edit') $('#viewer-editor').focus();
  });
  $('#viewer-save')?.addEventListener('click', async () => {
    try {
      await post('/api/library/note', { id: f.id, folder: f.folder, name: f.name, content: $('#viewer-editor').value });
      $('#viewer-state').textContent = 'Saved';
      const fr = $('#viewer-frame'); if (fr) fillPreview(fr, f.id);
      loadLibrary();
    } catch (e) { toast(e.message); }
  });
  $('#viewer-editor')?.addEventListener('input', () => { $('#viewer-state').textContent = 'Unsaved changes'; });
}
function closeViewer() { $('#viewer').hidden = true; lib.file = null; }
function viewerForm(value, onOk) {
  const form = $('#viewer-form'), input = $('#viewer-input');
  form.hidden = false; input.value = value; input.focus(); input.select();
  form.onsubmit = async e => { e.preventDefault(); try { await onOk(input.value.trim()); form.hidden = true; } catch (err) { toast(err.message); } };
}
function pdfPageHtml(id, n, total) {
  return `<figure class="pdf-page"><img src="/api/files/${id}/page/${n}" alt="Page ${n}" loading="lazy"><figcaption>Page ${n} of ${total}</figcaption></figure>`;
}
async function newNote() {
  const folder = lib.folder === '/' ? '/Notes' : lib.folder;
  try { const { file } = await post('/api/library/note', { folder, name: '', content: '# ' }); await loadLibrary(); await openFile(file.id); $('#viewer-body [data-tab=edit]')?.click(); }
  catch (e) { toast(e.message); }
}

// ---- activity ----
async function loadHeartbeats(data) {
  const d = data || await api('/api/heartbeats');
  const name = state.status?.name || 'the assistant';
  $('#hb-info').textContent = !d.minutes ? `Off. Set "Periodic check (minutes)" in Settings → Notifications, and write what to check in Memory → HEARTBEAT.md.`
    : !d.checklist ? `Every ${d.minutes} min, but the checklist (Memory → HEARTBEAT.md) is empty, so nothing is checked.`
    : `Every ${d.minutes} min, ${name} reads its checklist (Memory → HEARTBEAT.md) and only messages you when something needs attention. Next check ${d.next ? fmtTime(d.next) : 'soon'}.`;
  const label = { quiet: 'nothing to report', sent: 'messaged you', skipped: 'skipped', error: 'failed' };
  $('#heartbeats').innerHTML = d.checks.length ? d.checks.map(c => `<div class="hb ${c.outcome}">
      <span class="pill ${c.outcome === 'sent' ? 'done' : c.outcome === 'error' ? 'failed' : 'queued'}">${label[c.outcome] || c.outcome}</span>
      <span class="muted small">${fmtTime(c.ts)}${c.cost != null ? ' · ' + (money(+c.cost) || '$0') : ''}</span>
      <p>${esc(c.note)}</p>${c.tools.length ? `<span class="muted small">Looked at: ${esc([...new Set(c.tools)].map(t => TOOL_LABEL(t).toLowerCase()).join(', '))}</span>` : ''}</div>`).join('')
    : '<p class="muted small">No checks yet.</p>';
}
$('#hb-run').onclick = async () => {
  const b = $('#hb-run'); b.disabled = true; b.textContent = 'Checking…';
  try { const d = await post('/api/heartbeat/run'); loadHeartbeats(d); toast(d.message ? 'Something needs your attention: see the chat.' : 'Nothing needs your attention.'); if (d.message) poll(); }
  catch (e) { toast(e.message); }
  b.disabled = false; b.textContent = 'Check now';
};

async function loadActivity() {
  loadHeartbeats().catch(() => {});
  const { usage, tools, budget, by_tier, by_source, memory, backup } = await api('/api/activity');
  const meter = (label, spent, limit) => {
    const pct = limit > 0 ? Math.min(100, spent / limit * 100) : 0;
    const cls = !limit ? '' : pct >= 100 ? 'blocked' : pct >= 80 ? 'low' : '';
    return `<div class="meter"><div class="row"><span class="muted small">${label}</span><span class="muted small">${limit > 0 ? 'limit $' + limit.toFixed(2) : 'no limit set'}</span></div>
      <b>$${spent.toFixed(2)}</b><div class="track ${cls}"><i style="width:${pct.toFixed(1)}%"></i></div></div>`;
  };
  $('#budget').innerHTML = meter('Spent today', budget.today, budget.daily_limit) + meter('Spent this month', budget.month, budget.monthly_limit);
  const rows = list => list.length ? list.map(r => `<tr><td>${esc(r.name || '-')}</td><td>${nf.format(r.calls)}</td><td>${ktok(+r.input + +r.output)}</td><td>${r.cost == null ? '?' : money(+r.cost) || '$0'}</td></tr>`).join('')
    : '<tr><td colspan="4" class="muted">Nothing yet.</td></tr>';
  $('#by-tier tbody').innerHTML = rows(by_tier);
  $('#by-source tbody').innerHTML = rows(by_source);
  const bk = backup || {};
  $('#memstats').textContent = (bk.last ? `Last backup ${fmtTime(bk.last)} (${bk.file}, keeping ${bk.keep}). ` : 'No backup yet (the worker makes one every night). ') + `Skills: ${memory.skills}. Memory index: ${nf.format(memory.messages)} messages, ${nf.format(memory.summaries)} summaries, ${nf.format(memory.docs)} searchable items, ${memory.embeddings_model ? `${nf.format(memory.embedded)} indexed by meaning with ${memory.embeddings_model}${memory.embedded < memory.docs ? ' (indexing in progress)' : ''}` : 'search by meaning off (keyword search only)'}.`;
  const sum = k => usage.reduce((a, d) => a + (+d[k] || 0), 0);
  const cost = usage.reduce((a, d) => a + (d.cost == null ? 0 : +d.cost), 0);
  const max = Math.max(1, ...usage.map(d => +d.input + +d.output));
  const days = [];
  for (let i = 29; i >= 0; i--) { const d = new Date(Date.now() - i * 864e5); days.push(d.toLocaleDateString('sv-SE')); }
  const byDay = Object.fromEntries(usage.map(d => [d.day, +d.input + +d.output]));
  const costDay = Object.fromEntries(usage.map(d => [d.day, d.cost == null ? null : +d.cost]));
  $('#usage').innerHTML = `
    <div class="stat"><b>${nf.format(sum('calls'))}</b><span>model calls</span></div>
    <div class="stat"><b>${nf.format(sum('input'))}</b><span>input tokens</span></div>
    <div class="stat"><b>${nf.format(sum('output'))}</b><span>output tokens</span></div>
    <div class="stat"><b>${cost ? '$' + cost.toFixed(2) : '–'}</b><span>${state.status?.provider === 'claude-code' ? 'API-equivalent cost (covered by your plan)' : 'reported cost'}</span></div>
    <div class="bars"><div class="small muted">Tokens per day</div>
      <div class="bars-plot" role="img" aria-label="Tokens per day, last 30 days">${days.map(d => `<div style="height:${((byDay[d] || 0) / max * 100).toFixed(1)}%" title="${d}: ${nf.format(byDay[d] || 0)} tokens${costDay[d] != null ? ', ' + money(costDay[d]) : ''}"></div>`).join('')}</div>
      <div class="bars-axis"><span>${days[0]}</span><span>max ${nf.format(max)}</span><span>today</span></div></div>`;
  $('#tools tbody').innerHTML = tools.length ? tools.map(t => `<tr><td>${fmtTime(t.ts)}</td><td>${esc(t.tool)}</td><td><code>${esc(t.args.slice(0, 200))}</code></td><td>${esc(t.result.slice(0, 220))}</td></tr>`).join('')
    : '<tr><td colspan="4" class="muted">No tool calls yet.</td></tr>';
}

// ---- shell ----
const VIEWS = { chat: null, library: loadLibrary, tasks: loadTasks, jobs: loadJobs, memory: loadMemory, settings: loadSettings, activity: loadActivity };
function route() {
  const h = location.hash.slice(1) === 'channels' ? 'settings' : location.hash.slice(1).split('/')[0];
  const v = (h in VIEWS) ? h : 'chat';
  for (const k of Object.keys(VIEWS)) $('#view-' + k).hidden = k !== v;
  document.querySelectorAll('.rail a').forEach(a => a.classList.toggle('active', a.dataset.view === v));
  $('#more-btn').classList.toggle('active', ['jobs', 'activity', 'settings'].includes(v));
  $('#more-sheet').hidden = true;
  if (v !== 'library') closeViewer();
  VIEWS[v]?.().catch(e => toast(e.message));
  if (v === 'chat') setTimeout(() => { $('#thread').scrollTop = $('#thread').scrollHeight; }, 0);
}

async function refreshStatus() {
  try {
    const s = state.status = await api('/api/status');
    $('#brand-name').textContent = s.name; document.title = s.name;
    $('#chat-title').textContent = s.name;
    const ch = Object.entries(s.channels).filter(([, on]) => on).map(([k]) => k[0].toUpperCase() + k.slice(1)).join(', ');
    const b = s.budget, lim = b.daily_limit > 0 ? ` · $${b.today.toFixed(2)} of $${b.daily_limit.toFixed(2)} today` : '';
    $('#chat-sub').textContent = `${shortModel(s.model)} · ${ch}${lim}${b.state === 'low' ? ' · budget low' : b.state === 'blocked' ? ' · budget reached' : ''}`;
    const st = $('#status'); st.className = 'status ' + (s.worker_online ? 'on' : 'off');
    st.querySelector('span').textContent = s.worker_online ? 'Running' : 'Worker offline';
    st.title = s.worker_online ? 'Background worker is running: schedules and chat apps work.' : 'Background worker is not running: schedules and Telegram are paused. Check: systemctl status talixio-worker';
  } catch {}
}

function showLogin() { $('#shell').hidden = true; $('#login').hidden = false; clearInterval(state.pollTimer); }

let start = startApp;
async function startApp() {
  $('#login').hidden = true; $('#shell').hidden = false;
  await refreshStatus();
  state.lastId = 0; state.firstLoaded = 0; $('#thread').innerHTML = '';
  const first = await api('/api/messages');
  state.firstId = first.first_id;
  appendMessages(first.messages);
  restoreDraft();
  loadApprovals();
  route();
  clearInterval(state.pollTimer);
  state.pollTimer = setInterval(() => { poll(); }, 4000);
  setInterval(refreshStatus, 60000);
  pushSubscription().then(sub => { $('#notify-btn').hidden = !!sub || !('PushManager' in window) && !/iPhone|iPad/.test(navigator.userAgent); }).catch(() => {});
}

$('#login-form').onsubmit = async e => {
  e.preventDefault(); $('#login-error').textContent = '';
  try {
    await post('/api/login', { password: $('#password').value, code: $('#login-code').value });
    $('#password').value = $('#login-code').value = ''; $('#code-row').hidden = true;
    await start(); if (!previewId) handleLaunch();
  } catch (err) {
    $('#login-error').textContent = err.message;
    if (err.data?.need_code) { $('#code-row').hidden = false; $('#login-code').value = ''; $('#login-code').focus(); }
  }
};
$('#logout').onclick = async () => { try { await post('/api/logout'); } catch {} showLogin(); };
$('#composer').onsubmit = e => { e.preventDefault(); if (state.sending) stopAnswer(); else send(); };
$('#attach').onclick = () => $('#file-input').click();
$('#file-input').onchange = e => { addFiles([...e.target.files]); e.target.value = ''; };
$('#mic').onclick = startRecording;
$('#rec-cancel').onclick = () => stopRecording();
$('#rec-voice').onclick = () => finishRecording(false);
$('#rec-meeting').onclick = () => finishRecording(true);
$('#input').addEventListener('paste', e => { const f = [...(e.clipboardData?.files || [])]; if (f.length) { e.preventDefault(); addFiles(f); } });
['dragover', 'dragenter'].forEach(t => $('#composer').addEventListener(t, e => { e.preventDefault(); $('#composer').classList.add('drag'); }));
['dragleave', 'drop'].forEach(t => $('#composer').addEventListener(t, () => $('#composer').classList.remove('drag')));
$('#composer').addEventListener('drop', e => { e.preventDefault(); addFiles([...(e.dataTransfer?.files || [])]); });
$('#input').addEventListener('input', () => { autosize(); clearTimeout(saveDraft.t); saveDraft.t = setTimeout(saveDraft, 400); });
$('#input').addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(); } });
$('#test-notify').onclick = async () => {
  $('#test-result').textContent = 'Sending…';
  try {
    const { results } = await post('/api/notify/test');
    $('#test-result').textContent = Object.entries(results).filter(([, r]) => r === 'sent' || r.startsWith('error')).map(([k, r]) => `${k}: ${r}`).join(' · ') || 'No notification channel is set up yet.';
    loadChannels();
  } catch (e) { $('#test-result').textContent = e.message; }
};
$('#search-form').onsubmit = async e => {
  e.preventDefault();
  const q = $('#search-q').value.trim(); const box = $('#search-results');
  if (!q) { box.hidden = true; return; }
  try {
    const { results } = await api('/api/search?q=' + encodeURIComponent(q));
    box.hidden = false;
    box.innerHTML = results.length ? results.map(r => `<div class="hit"><div class="meta"><span class="chip">${esc(r.label)}</span><span>${new Date(r.ts * 1000).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' })}</span>${r.sim != null ? `<span>${Math.round(r.sim * 100)}% similar</span>` : ''}</div>${esc(r.text.slice(0, 600))}</div>`).join('')
      : '<div class="placeholder">Nothing found.</div>';
  } catch (err) { toast(err.message); }
};
$('#skill-save').onclick = async () => {
  try { await post('/api/skills/' + encodeURIComponent(state.skill), { content: $('#skill-editor').value }); $('#skill-state').textContent = 'Saved'; loadSkills(); }
  catch (e) { toast(e.message); }
};
$('#skill-cancel').onclick = () => { $('#skill-edit').hidden = true; };
$('#mem-editor').addEventListener('input', () => { $('#mem-state').textContent = 'Unsaved changes'; });
$('#mem-save').onclick = async () => {
  try {
    await post('/api/memory', { file: state.memFile, content: $('#mem-editor').value });
    state.memFiles[state.memFile] = $('#mem-editor').value; $('#mem-state').textContent = 'Saved';
  } catch (e) { toast(e.message); }
};
// tapping the Library tab while already in the library goes back to the top
document.querySelectorAll('.rail a[data-view=library]').forEach(a => a.addEventListener('click', e => {
  if (!$('#view-library').hidden) { e.preventDefault(); libGo('/'); }
}));
$('#lib-upload').onclick = () => $('#lib-upload-input').click();
$('#lib-upload-input').onchange = e => { libUpload([...e.target.files]); e.target.value = ''; };
$('#lib-newnote').onclick = newNote;
$('#lib-newfolder').onclick = () => libInline(lib.folder === '/' ? '/' : lib.folder + '/', 'New folder path, e.g. /Clients/Alice', async v => {
  const { folder } = await post('/api/library/folder', { path: v }); toast('Folder created'); libGo(folder);
});
$('#lib-inline-cancel').onclick = () => { $('#lib-inline').hidden = true; };
['dragover', 'dragenter'].forEach(t => $('#lib-main').addEventListener(t, e => { if (e.dataTransfer?.types.includes('Files')) { e.preventDefault(); $('#lib-main').classList.add('drag'); } }));
$('#lib-main').addEventListener('dragleave', e => { if (!$('#lib-main').contains(e.relatedTarget)) $('#lib-main').classList.remove('drag'); });
$('#lib-main').addEventListener('drop', e => { e.preventDefault(); $('#lib-main').classList.remove('drag'); libUpload([...(e.dataTransfer?.files || [])]); });
$('#lib-search').onsubmit = async e => {
  e.preventDefault();
  const q = $('#lib-q').value.trim(), box = $('#lib-results');
  if (!q) { box.hidden = true; $('#lib-list').hidden = false; return; }
  const folder = $('#lib-scope').checked ? lib.folder : '/';
  try {
    const { results } = await api(`/api/library/search?q=${encodeURIComponent(q)}&folder=${encodeURIComponent(folder)}`);
    box.hidden = false; box.innerHTML = '';
    const head = document.createElement('div'); head.className = 'row between';
    head.innerHTML = `<span class="muted small">${results.length ? results.length + ' result' + (results.length > 1 ? 's' : '') : 'Nothing found'}${folder !== '/' ? ' in ' + esc(folder) : ''}</span><button class="btn small ghost" type="button">Clear search</button>`;
    head.querySelector('button').onclick = () => { $('#lib-q').value = ''; box.hidden = true; $('#lib-list').hidden = false; };
    const list = document.createElement('div'); list.className = 'lib-list';
    for (const r of results) list.append(fileRow({ ...r, size: 0, created: 0, status: 'ready' }, `${joinPath(r.folder, r.name)} · ${r.snippet}`));
    box.append(head); if (results.length) box.append(list);
    $('#lib-list').hidden = results.length > 0;
  } catch (err) { toast(err.message); }
};
$('#viewer-close').onclick = closeViewer;
document.addEventListener('keydown', e => { if (e.key === 'Escape' && !$('#viewer').hidden) closeViewer(); });
$('#viewer-form-cancel').onclick = () => { $('#viewer-form').hidden = true; };
$('#viewer-rename').onclick = () => viewerForm(lib.file.name, async v => { const { file } = await post(`/api/files/${lib.file.id}/rename`, { name: v }); await loadLibrary(); openFile(file.id); });
$('#viewer-move').onclick = () => viewerForm(lib.file.folder, async v => { const { file } = await post(`/api/files/${lib.file.id}/move`, { folder: v }); toast('Moved to ' + file.folder); await loadLibrary(); openFile(file.id); });
$('#viewer-reindex').onclick = async () => { try { await post(`/api/files/${lib.file.id}/reindex`); toast('It will be read again in a moment'); await loadLibrary(); openFile(lib.file.id); } catch (e) { toast(e.message); } };
$('#viewer-delete').onclick = async () => {
  const b = $('#viewer-delete');
  if (b.dataset.confirm !== '1') { b.dataset.confirm = '1'; b.textContent = 'Confirm delete'; return; }
  try { await post(`/api/files/${lib.file.id}/delete`); toast('Deleted'); closeViewer(); loadLibrary(); } catch (e) { toast(e.message); }
};
$('#viewer-ask').onclick = () => {
  const f = lib.file;
  state.pending.push({ id: f.id, info: f }); renderPending();
  closeViewer(); location.hash = '#chat';
  const i = $('#input'); if (!i.value) i.value = `About ${joinPath(f.folder, f.name)}: `; autosize(); i.focus();
};
$('#more-btn').onclick = () => { $('#more-sheet').hidden = false; $('#more-install').hidden = $('#install-btn').hidden; };
$('#more-sheet').onclick = e => { if (e.target === $('#more-sheet') || e.target.closest('a')) $('#more-sheet').hidden = true; };
$('#more-logout').onclick = () => $('#logout').click();
$('#more-install').onclick = () => { $('#more-sheet').hidden = true; $('#install-btn').click(); };
document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
window.addEventListener('hashchange', route);

// ---- installable app (PWA) ----
const pwa = { prompt: null, standalone: matchMedia('(display-mode: standalone)').matches || navigator.standalone === true };
function banner(text, action, onAction, kind = '') {
  const b = $('#banner'); b.className = 'banner ' + kind; b.hidden = !text; $('#banner-text').textContent = text || '';
  const a = $('#banner-action'); a.hidden = !action; a.textContent = action || ''; a.onclick = onAction || null;
}
function platform() {
  const ua = navigator.userAgent;
  if (/iPhone|iPad|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)) return 'ios';
  if (/Android/.test(ua)) return 'android';
  if (/Safari/.test(ua) && !/Chrome|Chromium|Edg/.test(ua)) return 'mac-safari';
  if (/Firefox/.test(ua)) return 'firefox';
  return 'desktop';
}
function installHelp() {
  const steps = {
    ios: '<ol><li>Open this page in <b>Safari</b>.</li><li>Tap the <b>Share</b> button (square with an arrow).</li><li>Choose <b>Add to Home Screen</b>, then <b>Add</b>.</li><li>Open Talixio from your home screen, then turn on notifications in Settings.</li></ol>',
    'mac-safari': '<ol><li>In Safari\'s menu bar choose <b>File → Add to Dock…</b></li><li>Click <b>Add</b>: Talixio opens in its own window from the Dock.</li></ol>',
    firefox: '<p>Firefox on computers can\'t install web apps. Use Chrome, Edge or Safari, or on Android use Firefox\'s menu → <b>Install</b>.</p>',
    android: '<ol><li>Open the browser menu (⋮).</li><li>Choose <b>Install app</b> or <b>Add to Home screen</b>.</li></ol>',
    desktop: '<ol><li>Click the install icon at the right of the address bar (a screen with an arrow), or open the menu (⋮).</li><li>Choose <b>Install Talixio</b>.</li></ol>',
  };
  $('#install-steps').innerHTML = steps[platform()] || steps.desktop;
  $('#install-help').hidden = false;
}
async function install() {
  if (pwa.prompt) { pwa.prompt.prompt(); const { outcome } = await pwa.prompt.userChoice; pwa.prompt = null; if (outcome === 'accepted') toast('Installing Talixio…'); updateInstallUi(); }
  else installHelp();
}
function updateInstallUi() {
  const can = !pwa.standalone;
  $('#install-btn').hidden = !(can && pwa.prompt);
  $('#install-card').hidden = !can;
}
window.addEventListener('beforeinstallprompt', e => { e.preventDefault(); pwa.prompt = e; updateInstallUi(); });
window.addEventListener('appinstalled', () => { pwa.prompt = null; toast('Talixio is installed.'); updateInstallUi(); });
$('#install-btn').onclick = install;
$('#install-card-btn').onclick = install;
$('#install-close').onclick = () => { $('#install-help').hidden = true; };
updateInstallUi();

async function registerServiceWorker() {
  if (!('serviceWorker' in navigator) || !(location.protocol === 'https:' || ['localhost', '127.0.0.1'].includes(location.hostname))) return;
  try {
    const reg = await navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' });
    const offerUpdate = w => banner('A new version of Talixio is ready.', 'Reload', () => { state.updateAccepted = true; w.postMessage('update'); });
    if (reg.waiting && navigator.serviceWorker.controller) offerUpdate(reg.waiting);
    reg.addEventListener('updatefound', () => {
      const w = reg.installing;
      w?.addEventListener('statechange', () => { if (w.state === 'installed' && navigator.serviceWorker.controller) offerUpdate(w); });
    });
    let reloading = false;
    // reload only when the user accepted an update (the first install also changes the controller)
    navigator.serviceWorker.addEventListener('controllerchange', () => { if (!reloading && state.updateAccepted) { reloading = true; location.reload(); } });
    setInterval(() => reg.update().catch(() => {}), 60 * 60 * 1000); // installed apps stay open for days
  } catch {}
}
function onlineState() {
  if (navigator.onLine) { if ($('#banner').classList.contains('offline') && !state.queued) banner(''); }
  else { banner('You are offline: you can read the conversation; sending works again once you are back online.', '', null, 'offline'); }
}
window.addEventListener('online', () => { onlineState(); poll(); refreshStatus(); });
window.addEventListener('offline', onlineState);
if (!navigator.onLine) onlineState();

/** Badge on the app icon: pending approvals. */
function setBadge(n) {
  if (!('setAppBadge' in navigator)) return;
  (n > 0 ? navigator.setAppBadge(n) : navigator.clearAppBadge()).catch(() => {});
}

/** Shared from another app, or launched from a shortcut. */
async function handleLaunch() {
  const params = new URLSearchParams(location.search);
  const token = params.get('share');
  if (params.has('share') || params.has('action') || params.has('source')) history.replaceState(null, '', '/' + location.hash);
  if (token) {
    try {
      const d = await post('/api/share/claim', { token });
      if (d.text) { $('#input').value = d.text; autosize(); }
      for (const f of d.files) { state.pending.push({ id: f.id, info: f }); }
      renderPending();
      location.hash = '#chat';
      toast(d.skipped ? `${d.skipped} file(s) were too large to share this way (max ${d.max_mb} MB): attach them with the paperclip.` : 'Shared content ready: add a message if you like, then send.');
      $('#input').focus();
    } catch (e) { toast(e.message); }
  }
  if (params.get('action') === 'record') {
    location.hash = '#chat';
    banner('Ready to record a meeting.', 'Start recording', () => { banner(''); startRecording(); });
  }
}

/** /preview/ID/name: one document full screen ("Open in a new tab"); the address keeps the file name. */
const previewId = (location.pathname.match(/^\/preview\/(\d+)/) || [])[1];
async function startPreview() {
  $('#login').hidden = true; $('#shell').hidden = true; $('#preview-page').hidden = false;
  try {
    const { file: f } = await api(`/api/files/${previewId}/text`);
    document.title = f.name;
    $('#pp-name').textContent = f.name; $('#pp-path').textContent = f.folder;
    $('#pp-download').href = fileUrl(f) + '?download=1'; $('#pp-download').setAttribute('download', f.name);
    $('#pp-library').href = '/#library' + (f.folder === '/' ? '' : f.folder.split('/').map(encodeURIComponent).join('/'));
    if (RENDERED.includes(f.kind)) { $('#pp-frame').hidden = false; await fillPreview($('#pp-frame'), f.id); }
    else location.replace(fileUrl(f)); // photos, PDFs, audio: the browser shows them itself
  } catch (e) { $('#pp-name').textContent = e.message; }
}
if (previewId) start = startPreview; // after sign-in, come back to the document

registerServiceWorker();
api('/api/status').then(async () => { await start(); if (!previewId) handleLaunch(); }).catch(() => showLogin());
