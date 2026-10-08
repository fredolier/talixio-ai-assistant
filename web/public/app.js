'use strict';
const $ = s => document.querySelector(s);
const state = { lastId: 0, status: null, memFile: 'USER.md', memFiles: {}, sending: false, pollTimer: null };

async function api(path, opts = {}) {
  const res = await fetch(path, {
    method: opts.method || 'GET',
    headers: { 'Content-Type': 'application/json', 'X-Talixio': '1' },
    body: opts.body ? JSON.stringify(opts.body) : undefined,
    credentials: 'same-origin',
  });
  const data = await res.json().catch(() => ({ error: `Server error (${res.status})` }));
  if (res.status === 401) { showLogin(); throw new Error(data.error || 'Please sign in.'); }
  if (!res.ok) throw new Error(data.error || `Request failed (${res.status})`);
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
  for (const para of s.split(/\n{2,}/)) {
    const lines = para.split('\n');
    if (/^\u0000\d+\u0000$/.test(para.trim())) { out.push(para.trim()); continue; }
    if (lines.every(l => /^\s*[-*•]\s+/.test(l))) { out.push('<ul>' + lines.map(l => `<li>${inline(l.replace(/^\s*[-*•]\s+/, ''))}</li>`).join('') + '</ul>'); continue; }
    if (lines.every(l => /^\s*\d+[.)]\s+/.test(l))) { out.push('<ol>' + lines.map(l => `<li>${inline(l.replace(/^\s*\d+[.)]\s+/, ''))}</li>`).join('') + '</ol>'); continue; }
    if (/^#{1,6}\s/.test(lines[0])) { out.push(`<h4>${inline(lines[0].replace(/^#+\s/, ''))}</h4>` + (lines.length > 1 ? `<p>${inline(lines.slice(1).join('<br>'))}</p>` : '')); continue; }
    out.push(`<p>${inline(lines.join('<br>'))}</p>`);
  }
  return out.join('').replace(/\u0000(\d+)\u0000/g, (_, i) => blocks[+i]);
}

const CHANNEL = { web: null, cli: 'Terminal', telegram: 'Telegram', schedule: 'Scheduled', heartbeat: 'Heartbeat' };
const fmtTime = ts => new Date(ts * 1000).toLocaleString([], { weekday: 'short', hour: '2-digit', minute: '2-digit' });

function renderMessage(m) {
  const el = document.createElement('div');
  if (m.role === 'user' && m.channel === 'schedule') {
    const title = (m.content.match(/^\[Scheduled task "(.*?)"/) || [])[1] || 'Scheduled task';
    el.className = 'trigger';
    el.innerHTML = `<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/></svg><span>${esc(title)} · ${fmtTime(m.ts)}</span>`;
    return el;
  }
  el.className = `msg ${m.role}`;
  const chip = CHANNEL[m.channel] ? `<span class="chip ${m.channel}">${CHANNEL[m.channel]}</span>` : '';
  el.innerHTML = `<div class="bubble">${m.role === 'user' ? esc(m.content) : md(m.content)}</div><div class="meta">${chip}<span>${fmtTime(m.ts)}</span></div>`;
  return el;
}

function emptyState() {
  const n = state.status?.name || 'Talixio';
  const ideas = ['What can you do?', 'Remind me tomorrow at 9:00 to call the bank', 'Every weekday at 8:00, send me a short briefing', 'Remember that I prefer short answers'];
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
  for (const m of list) {
    if (m.id <= state.lastId) continue;
    state.lastId = m.id;
    thread.append(renderMessage(m));
    if (notify && m.role === 'assistant' && (document.hidden || m.channel !== 'web')) notifyBrowser(m);
  }
  thread.scrollTop = thread.scrollHeight;
}

function notifyBrowser(m) {
  if (!('Notification' in window) || Notification.permission !== 'granted' || !document.hidden) return;
  try { new Notification(state.status?.name || 'Talixio', { body: m.content.slice(0, 180), icon: '/icon.svg', tag: 'talixio-' + m.id }); } catch {}
}

async function poll() {
  if (state.sending || document.hidden && !('Notification' in window && Notification.permission === 'granted')) return;
  try { appendMessages((await api('/api/messages?after=' + state.lastId)).messages, true); } catch {}
}

async function send() {
  const input = $('#input'); const text = input.value.trim();
  if (!text || state.sending) return;
  state.sending = true; $('#send').disabled = true;
  input.value = ''; autosize();
  $('#empty')?.remove();
  const thread = $('#thread');
  const pending = renderMessage({ id: 0, role: 'user', channel: 'web', content: text, ts: Date.now() / 1000 });
  const typing = document.createElement('div'); typing.className = 'msg assistant typing'; typing.innerHTML = '<div class="bubble"><i></i><i></i><i></i></div>';
  thread.append(pending, typing); thread.scrollTop = thread.scrollHeight;
  try {
    const { messages } = await post('/api/chat', { text });
    pending.remove(); typing.remove();
    appendMessages(messages);
  } catch (e) {
    typing.remove(); toast(e.message); input.value = text;
  } finally {
    state.sending = false; $('#send').disabled = false; input.focus();
  }
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
      <div class="actions"><button class="btn small" data-a="toggle">${t.enabled ? 'Pause' : 'Resume'}</button><button class="btn small ghost" data-a="delete">Delete</button></div>
      <p class="prompt">${esc(t.prompt)}</p>`;
    el.querySelectorAll('button').forEach(b => b.onclick = async () => {
      try { await post(`/api/tasks/${t.id}/${b.dataset.a}`); toast(b.dataset.a === 'delete' ? 'Task deleted' : (t.enabled ? 'Task paused' : 'Task resumed')); loadTasks(); }
      catch (e) { toast(e.message); }
    });
    box.append(el);
  }
}

// ---- memory ----
const MEM_HELP = {
  'USER.md': 'What the assistant knows about you.',
  'MEMORY.md': 'Notes, decisions and things to remember.',
  'SOUL.md': 'Personality and tone. Change this to change how it talks.',
  'HEARTBEAT.md': 'Checklist for periodic checks (needs TALIXIO_HEARTBEAT_MINUTES). It only messages you when something needs attention.',
};
async function loadMemory() {
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

// ---- activity ----
const nf = new Intl.NumberFormat();
async function loadActivity() {
  const { usage, tools } = await api('/api/activity');
  const sum = k => usage.reduce((a, d) => a + (+d[k] || 0), 0);
  const cost = usage.reduce((a, d) => a + (d.cost == null ? 0 : +d.cost), 0);
  const max = Math.max(1, ...usage.map(d => +d.input + +d.output));
  const days = [];
  for (let i = 29; i >= 0; i--) { const d = new Date(Date.now() - i * 864e5); days.push(d.toLocaleDateString('sv-SE')); }
  const byDay = Object.fromEntries(usage.map(d => [d.day, +d.input + +d.output]));
  $('#usage').innerHTML = `
    <div class="stat"><b>${nf.format(sum('calls'))}</b><span>model calls</span></div>
    <div class="stat"><b>${nf.format(sum('input'))}</b><span>input tokens</span></div>
    <div class="stat"><b>${nf.format(sum('output'))}</b><span>output tokens</span></div>
    <div class="stat"><b>${cost ? '$' + cost.toFixed(2) : '–'}</b><span>${state.status?.provider === 'claude-code' ? 'API-equivalent cost (covered by your plan)' : 'reported cost'}</span></div>
    <div class="bars"><div class="small muted">Tokens per day</div>
      <div class="bars-plot" role="img" aria-label="Tokens per day, last 30 days">${days.map(d => `<div style="height:${((byDay[d] || 0) / max * 100).toFixed(1)}%" title="${d}: ${nf.format(byDay[d] || 0)} tokens"></div>`).join('')}</div>
      <div class="bars-axis"><span>${days[0]}</span><span>max ${nf.format(max)}</span><span>today</span></div></div>`;
  $('#tools tbody').innerHTML = tools.length ? tools.map(t => `<tr><td>${fmtTime(t.ts)}</td><td>${esc(t.tool)}</td><td><code>${esc(t.args.slice(0, 200))}</code></td><td>${esc(t.result.slice(0, 220))}</td></tr>`).join('')
    : '<tr><td colspan="4" class="muted">No tool calls yet.</td></tr>';
}

// ---- shell ----
const VIEWS = { chat: null, tasks: loadTasks, memory: loadMemory, activity: loadActivity };
function route() {
  const v = (location.hash.slice(1) in VIEWS) ? location.hash.slice(1) : 'chat';
  for (const k of Object.keys(VIEWS)) $('#view-' + k).hidden = k !== v;
  document.querySelectorAll('.rail a').forEach(a => a.classList.toggle('active', a.dataset.view === v));
  VIEWS[v]?.().catch(e => toast(e.message));
  if (v === 'chat') setTimeout(() => { $('#thread').scrollTop = $('#thread').scrollHeight; }, 0);
}

async function refreshStatus() {
  try {
    const s = state.status = await api('/api/status');
    $('#brand-name').textContent = s.name; document.title = s.name;
    $('#chat-title').textContent = s.name;
    const ch = Object.entries(s.channels).filter(([, on]) => on).map(([k]) => k[0].toUpperCase() + k.slice(1)).join(', ');
    $('#chat-sub').textContent = `${s.provider}${s.model !== 'default' ? ' · ' + s.model : ''} · ${ch}`;
    const st = $('#status'); st.className = 'status ' + (s.worker_online ? 'on' : 'off');
    st.querySelector('span').textContent = s.worker_online ? 'Running' : 'Worker offline';
    st.title = s.worker_online ? 'Background worker is running: schedules and chat apps work.' : 'Background worker is not running: schedules and Telegram are paused. Check: systemctl status talixio-worker';
  } catch {}
}

function showLogin() { $('#shell').hidden = true; $('#login').hidden = false; clearInterval(state.pollTimer); }

async function start() {
  $('#login').hidden = true; $('#shell').hidden = false;
  await refreshStatus();
  state.lastId = 0; $('#thread').innerHTML = '';
  appendMessages((await api('/api/messages')).messages);
  route();
  clearInterval(state.pollTimer);
  state.pollTimer = setInterval(() => { poll(); }, 4000);
  setInterval(refreshStatus, 60000);
  if ('Notification' in window && Notification.permission === 'default') $('#notify-btn').hidden = false;
}

$('#login-form').onsubmit = async e => {
  e.preventDefault(); $('#login-error').textContent = '';
  try { await post('/api/login', { password: $('#password').value }); $('#password').value = ''; start(); }
  catch (err) { $('#login-error').textContent = err.message; }
};
$('#logout').onclick = async () => { try { await post('/api/logout'); } catch {} showLogin(); };
$('#composer').onsubmit = e => { e.preventDefault(); send(); };
$('#input').addEventListener('input', autosize);
$('#input').addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(); } });
$('#notify-btn').onclick = async () => {
  const p = await Notification.requestPermission();
  $('#notify-btn').hidden = true;
  toast(p === 'granted' ? 'Notifications on while this tab is open. For phone push, set up ntfy.' : 'Notifications blocked.');
};
$('#mem-editor').addEventListener('input', () => { $('#mem-state').textContent = 'Unsaved changes'; });
$('#mem-save').onclick = async () => {
  try {
    await post('/api/memory', { file: state.memFile, content: $('#mem-editor').value });
    state.memFiles[state.memFile] = $('#mem-editor').value; $('#mem-state').textContent = 'Saved';
  } catch (e) { toast(e.message); }
};
document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
window.addEventListener('hashchange', route);
if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) navigator.serviceWorker.register('/sw.js').catch(() => {});

api('/api/status').then(start).catch(() => showLogin());
