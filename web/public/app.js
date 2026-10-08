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

const CHANNEL = { web: null, cli: 'Terminal', telegram: 'Telegram', email: 'Email', signal: 'Signal', schedule: 'Scheduled', heartbeat: 'Heartbeat', job: 'Job result' };
const money = v => v == null ? '' : v < 0.01 ? '<$0.01' : '$' + v.toFixed(v < 1 ? 3 : 2);
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
  const chip = CHANNEL[m.channel] ? `<span class="chip ${m.channel}">${CHANNEL[m.channel]}</span>` : '';
  const mt = m.meta;
  const usage = mt ? `<span class="tier" title="${esc(mt.model)} · ${nf.format(mt.in)} in / ${nf.format(mt.out)} out tokens">${esc(shortModel(mt.model))} · ${ktok(mt.in + mt.out)} tok${mt.cost_known ? ' · ' + money(mt.cost) : ''}</span>` : '';
  el.innerHTML = `<div class="bubble">${m.role === 'user' ? esc(m.content) : md(m.content)}</div><div class="meta">${chip}<span>${fmtTime(m.ts)}</span>${usage}</div>`;
  return el;
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

// ---- jobs ----
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
      <div class="actions">${['queued', 'running'].includes(j.status) ? '<button class="btn small" data-a="cancel">Cancel</button>' : ''}</div>
      <details><summary>Instructions${j.result ? ' and report' : ''}</summary><p class="report"><b>Instructions:</b> ${esc(j.instructions)}</p>${j.result ? `<p class="report"><b>Report:</b> ${esc(j.result)}</p>` : ''}</details>`;
    el.querySelector('[data-a=cancel]')?.addEventListener('click', async () => {
      try { await post(`/api/jobs/${j.id}/cancel`); toast('Job cancelled'); loadJobs(); } catch (e) { toast(e.message); }
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
    const on = c.state === 'on', partial = c.state === 'notifications only';
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
async function loadActivity() {
  const { usage, tools, budget, by_tier, by_source, memory } = await api('/api/activity');
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
  $('#memstats').textContent = `Memory index: ${nf.format(memory.messages)} messages, ${nf.format(memory.summaries)} summaries, ${nf.format(memory.docs)} searchable items, ${nf.format(memory.embedded)} with embeddings${state.status?.embeddings === 'off' ? ' (embeddings off: keyword search only)' : ''}.`;
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
const VIEWS = { chat: null, tasks: loadTasks, jobs: loadJobs, memory: loadMemory, channels: loadChannels, activity: loadActivity };
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
    const b = s.budget, lim = b.daily_limit > 0 ? ` · $${b.today.toFixed(2)} of $${b.daily_limit.toFixed(2)} today` : '';
    $('#chat-sub').textContent = `${shortModel(s.model)} · ${ch}${lim}${b.state === 'low' ? ' · budget low' : b.state === 'blocked' ? ' · budget reached' : ''}`;
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
  pushSubscription().then(sub => { $('#notify-btn').hidden = !!sub || !('PushManager' in window) && !/iPhone|iPad/.test(navigator.userAgent); }).catch(() => {});
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
