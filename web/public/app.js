'use strict';
// The app can live under a prefix (assistant.example.com/fred/): the server sets <base>, every address here is relative.
const BASE = new URL(document.baseURI).pathname.replace(/\/$/, ''); // '' at the root, '/fred' under a prefix
const LSK = BASE ? BASE.slice(1) + ':' : ''; // this browser's preferences, kept apart per instance
const nf = new Intl.NumberFormat(LOCALE);
const $ = s => document.querySelector(s);
const state = { lastId: 0, status: null, memFile: 'USER.md', memFiles: {}, sending: false, pollTimer: null };

async function api(path, opts = {}) {
  const res = await fetch(path, {
    method: opts.method || 'GET',
    headers: { 'Content-Type': 'application/json', 'X-Talixio': '1' },
    body: opts.body ? JSON.stringify(opts.body) : undefined,
    credentials: 'same-origin',
    signal: opts.signal,
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

const CITE_SVG = '<svg viewBox="0 0 24 24"><path d="M6 3h9l3 3v15H6z"/><path d="M9 12h6M9 16h6"/></svg>';
// Safe Markdown: escapes everything first, then adds formatting (headings, lists with nesting and
// checkboxes, tables, quotes, rules, code, links, citations, phone buttons).
function md(src) {
  const blocks = [];
  const keep = html => { blocks.push(html); return `\u0000${blocks.length - 1}\u0000`; };
  let s = esc(String(src).replace(/\r\n?/g, '\n').replace(/\t/g, '    '))
    .replace(/^ {0,3}```[\w+-]*\n([\s\S]*?)\n? {0,3}```[ ]*$/gm, (_, code) => '\n' + keep(`<pre><code>${code}</code></pre>`) + '\n');
  const inline = t => t
    .replace(/`([^`\n]+)`/g, (_, c) => keep(`<code>${c}</code>`))
    .replace(/\\(&gt;|&amp;|[\\`*_\[\]()#+\-.!|~])/g, (_, c) => keep(c)) // \* is a plain star
    .replace(/\*\*([^*\n]+?)\*\*|__([^_\n]+?)__/g, (_, a, b) => `<strong>${a ?? b}</strong>`)
    .replace(/(^|[^*\w])\*([^*\n]+?)\*(?!\w)/g, '$1<em>$2</em>')
    .replace(/~~([^~\n]+)~~/g, '<del>$1</del>')
    .replace(/\[([^\]]+)\]\(\/files\/(\d+)(?:#p(\d+))?\)/g, (_, t, id, pg) => `<a href="#" class="cite" data-file="${id}"${pg ? ` data-page="${pg}"` : ''} title="Open the document${pg ? ' at page ' + pg : ''}">${CITE_SVG}${t}</a>`)
    .replace(/\[([^\]]+)\]\((#library(?:\/[^\s)]*)?)\)/g, (_, t, h) => `<a href="${h}" class="cite folder">${FOLDER_SVG}${t}</a>`) // a library folder ("where is…?")
    // buttons that open an app on the phone (phone_action, Spotify): custom schemes only from this list
    .replace(/\[([^\]]+)\]\(((?:tel|sms|mailto|geo|spotify|intent|deezer|tg|sgnl|uber|homeassistant):[^\s)]+)\)/g, (_, t, h) => `<a href="${h}" class="action">${t.replace(/^▶\s*/, '')}</a>`)
    .replace(/\[▶\s*([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" class="action" target="_blank" rel="noopener">$1</a>')
    .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>')
    .replace(/(^|[\s(])(https?:\/\/[^\s<)]+)/g, '$1<a href="$2" target="_blank" rel="noopener">$2</a>');
  const lines = s.split('\n'), n = lines.length, out = [];
  const LIST = /^( *)([-*+•]|\d{1,3}[.)])\s+(.*)$/, HR = /^ {0,3}([-*_])( *\1){2,} *$/, HEAD = /^ {0,3}(#{1,6})\s+(.*?)\s*#*\s*$/;
  const SEP = /^ *\|? *:?-{2,}:? *(\| *:?-{2,}:? *)*\|? *$/, QUOTE = /^ {0,3}&gt; ?/;
  const ph = l => /^\s*\u0000\d+\u0000\s*$/.test(l);
  const isTable = i => lines[i].includes('|') && i + 1 < n && SEP.test(lines[i + 1]);
  const startsBlock = i => HEAD.test(lines[i]) || HR.test(lines[i]) || LIST.test(lines[i]) || QUOTE.test(lines[i]) || ph(lines[i]) || isTable(i);
  const cells = l => l.trim().replace(/^\|/, '').replace(/(?<!\\)\|$/, '').split(/(?<!\\)\|/).map(c => c.trim());
  const item = t => { const m = t.match(/^\[([ xX])\]\s+(.*)$/); return m ? `<span class="check${m[1] === ' ' ? '' : ' done'}">${m[1] === ' ' ? '☐' : '☑'}</span> ${inline(m[2])}` : inline(t); };
  let i = 0;
  const list = () => {
    const first = lines[i].match(LIST), indent = first[1].length, ordered = /\d/.test(first[2]);
    const items = [];
    while (i < n) {
      const l = lines[i];
      if (!l.trim()) { // a blank line inside a list: go on if the list does
        let j = i + 1; while (j < n && !lines[j].trim()) j++;
        const m = j < n && lines[j].match(LIST);
        if (m && m[1].length >= indent && (m[1].length > indent || /\d/.test(m[2]) === ordered)) { i = j; continue; }
        if (j < n && items.length && /^ {2,}\S/.test(lines[j]) && !LIST.test(lines[j])) { items[items.length - 1] += '<br>'; i = j; continue; }
        break;
      }
      const m = l.match(LIST);
      if (m) {
        const ind = m[1].length;
        if (ind < indent) break;
        if (ind >= indent + 2 && items.length) { items[items.length - 1] += list(); continue; }
        if (/\d/.test(m[2]) !== ordered) break;
        items.push(item(m[3])); i++; continue;
      }
      if (items.length && (/^ {2,}\S/.test(l) || !startsBlock(i))) { items[items.length - 1] += (/^ {2,}/.test(l) ? ' ' : ' ') + inline(l.trim()); i++; continue; }
      break;
    }
    const start = ordered ? parseInt(first[2], 10) : 1;
    return ordered ? `<ol${start !== 1 ? ` start="${start}"` : ''}>${items.map(x => `<li>${x}</li>`).join('')}</ol>` : `<ul>${items.map(x => `<li>${x}</li>`).join('')}</ul>`;
  };
  while (i < n) {
    const l = lines[i];
    let m;
    if (!l.trim()) { i++; continue; }
    if (ph(l)) { out.push(l.trim()); i++; continue; }
    if ((m = l.match(HEAD))) { const h = Math.min(6, m[1].length + 2); out.push(`<h${h}>${inline(m[2])}</h${h}>`); i++; continue; }
    if (HR.test(l)) { out.push('<hr>'); i++; continue; }
    if (isTable(i)) {
      const head = cells(l), align = cells(lines[i + 1]).map(c => c.startsWith(':') && c.endsWith(':') ? 'center' : c.endsWith(':') ? 'right' : '');
      const td = (tag, c, k) => `<${tag}${align[k] ? ` style="text-align:${align[k]}"` : ''}>${inline(c)}</${tag}>`;
      i += 2;
      const rows = [];
      while (i < n && lines[i].trim() && lines[i].includes('|')) { rows.push(cells(lines[i])); i++; }
      out.push(`<div class="table"><table><thead><tr>${head.map((c, k) => td('th', c, k)).join('')}</tr></thead><tbody>${rows.map(r => `<tr>${head.map((_, k) => td('td', r[k] ?? '', k)).join('')}</tr>`).join('')}</tbody></table></div>`);
      continue;
    }
    if (QUOTE.test(l)) {
      const q = [];
      while (i < n && QUOTE.test(lines[i])) { q.push(lines[i].replace(QUOTE, '')); i++; }
      out.push(`<blockquote>${md(q.join('\n').replace(/&gt;/g, '>').replace(/&lt;/g, '<').replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&'))}</blockquote>`);
      continue;
    }
    if (LIST.test(l)) { out.push(list()); continue; }
    const para = [];
    while (i < n && lines[i].trim() && (!para.length || !startsBlock(i))) { para.push(lines[i].trim()); i++; }
    out.push(`<p>${inline(para.join('<br>'))}</p>`);
  }
  let html = out.join('');
  for (let k = 0; k < 3 && html.includes('\u0000'); k++) html = html.replace(/\u0000(\d+)\u0000/g, (_, j) => blocks[+j]); // inline code inside blocks
  return html;
}

const CHANNEL = { web: null, approval: 'Approval', learn: 'Learning', cli: 'Terminal', telegram: 'Telegram', email: 'Email', signal: 'Signal', schedule: 'Scheduled', heartbeat: 'Heartbeat', job: 'Job result' };
const money = v => v == null ? '' : v === 0 ? '$0' : v < 0.01 ? '<$0.01' : '$' + v.toFixed(v < 1 ? 3 : 2);
const ktok = n => n >= 1000 ? (n / 1000).toFixed(n >= 10000 ? 0 : 1) + 'k' : String(n);
const fmtTime = ts => new Date(ts * 1000).toLocaleString(LOCALE, { weekday: 'short', hour: '2-digit', minute: '2-digit' });

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
    el.innerHTML = `<details><summary><svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h10M4 17h7"/><circle cx="18" cy="16" r="3"/></svg><span>Job ${status || 'finished'}: <b>${esc(title || 'background job')}</b> · ${fmtTime(m.ts)} · show the full report</span></summary><div class="report doc">${md(report)}</div></details>`;
    return el;
  }
  el.className = `msg ${m.role}`;
  if (m.id) { el.dataset.id = m.id; el.dataset.channel = m.channel; }
  const chip = CHANNEL[m.channel] ? `<span class="chip ${m.channel}">${CHANNEL[m.channel]}</span>` : '';
  const atts = (m.files || []).map(f => f.kind === 'image'
      ? `<a href="api/files/${f.id}" target="_blank" rel="noopener"><img src="api/files/${f.id}" alt="${esc(f.name)}" loading="lazy"></a>`
      : f.kind === 'audio' ? `<audio controls preload="none" src="api/files/${f.id}"></audio>`
      : `<a class="fchip" href="api/files/${f.id}" target="_blank" rel="noopener">${f.kind === 'pdf' ? 'PDF' : 'File'} · ${esc(f.name)}</a>`).join('');
  const body = m.content.replace(/^\[attached [^\]]*\]$/gm, '').trim();
  const mt = m.meta;
  const usage = mt && (mt.in + mt.out) > 0 ? `<span class="tier" title="${esc(mt.model)} · ${nf.format(mt.in)} in / ${nf.format(mt.out)} out tokens">${esc(shortModel(mt.model))} · ${ktok(mt.in + mt.out)} tok${mt.cost_known ? ' · ' + money(mt.cost) : ''}</span>` : '';
  const acts = m.id && body ? `<span class="acts"><button type="button" class="act" data-act="copy" title="Copy" aria-label="Copy">${ICON.copy}</button>`
    + (m.role === 'assistant' ? `<button type="button" class="act" data-act="retry" title="New answer" aria-label="New answer">${ICON.retry}</button>`
      : `<button type="button" class="act" data-act="edit" title="Edit the message" aria-label="Edit the message">${ICON.edit}</button>`) + '</span>' : '';
  const tr = mt && mt.trace;
  const trace = tr ? `<button type="button" class="trace-btn" aria-expanded="false" title="What left the server for this answer">${traceLine(tr)}</button>` : '';
  el.innerHTML = `${atts ? `<div class="attachments">${atts}</div>` : ''}${body ? `<div class="bubble">${m.role === 'user' ? esc(body) : md(body)}</div>` : ''}<div class="meta">${chip}<span>${fmtTime(m.ts)}</span>${usage}${trace}${acts}</div>${tr ? `<div class="trace-detail" hidden>${traceDetail(tr)}</div>` : ''}`;
  if (mt?.routine && m.id && body) routineCards(el, m);
  el.querySelectorAll('.act').forEach(b => b.onclick = () => messageAction(b.dataset.act, m, el));
  el.querySelector('.trace-btn')?.addEventListener('click', e => { const dt = el.querySelector('.trace-detail'); dt.hidden = !dt.hidden; e.currentTarget.setAttribute('aria-expanded', String(!dt.hidden)); });
  return el;
}

/** A routine's message (morning briefing, weekly review...): each line is a card the user can rate 👍/👎; its next runs follow it. */
const feedbackItem = text => Array.from(text.replace(/[ \t\r\n]+/g, ' ').trim()).slice(0, 300).join('');
function routineCards(el, m) {
  const bubble = el.querySelector('.bubble');
  const lists = [...bubble.children].filter(x => x.tagName === 'UL' || x.tagName === 'OL');
  const items = lists.length ? lists.flatMap(l => [...l.children].filter(x => x.tagName === 'LI')) : [];
  const votes = m.meta.votes || {};
  const buttons = item => `<span class="rate" data-item="${esc(item)}"><button type="button" data-v="1" class="${votes[item] === 1 ? 'on' : ''}" title="${esc(t('Useful'))}" aria-label="${esc(t('Useful'))}">👍</button>`
    + `<button type="button" data-v="-1" class="${votes[item] === -1 ? 'on' : ''}" title="${esc(t('Not useful'))}" aria-label="${esc(t('Not useful'))}">👎</button></span>`;
  if (items.length) {
    bubble.classList.add('routine');
    items.forEach(li => { const item = feedbackItem(li.textContent); if (item) li.insertAdjacentHTML('beforeend', buttons(item)); });
  } else el.querySelector('.meta').insertAdjacentHTML('beforeend', buttons(feedbackItem(m.content.split('\n').find(l => l.trim()) || '').slice(0, 120) || 'message'));
  el.querySelectorAll('.rate button').forEach(b => b.onclick = async () => {
    const box = b.parentElement, v = b.classList.contains('on') ? 0 : +b.dataset.v; // clicking again removes the vote
    try {
      await post('api/feedback', { task: m.meta.routine, message: m.id, item: box.dataset.item, vote: v });
      box.querySelectorAll('button').forEach(x => x.classList.toggle('on', +x.dataset.v === v));
      if (v) { m.meta.votes = { ...(m.meta.votes || {}), [box.dataset.item]: v }; toast(t(v > 0 ? 'Noted: more like this.' : 'Noted: less of this next time.')); }
      else if (m.meta.votes) delete m.meta.votes[box.dataset.item];
    } catch (e) { toast(e.message); }
  });
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
    const d = await post(`api/messages/${userEl.dataset.id}/undo`);
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
  const { messages } = await api(`api/messages?space=${state.space}&before=${state.firstLoaded}`);
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
  try { new Notification(state.status?.name || 'Talixio', { body: m.content.slice(0, 180), icon: 'icon.svg', tag: 'talixio-' + m.id }); } catch {}
}

async function poll() {
  if (!state.sending && state.status?.pending_approvals !== undefined) loadApprovals();
  if (state.sending || document.hidden && !('Notification' in window && Notification.permission === 'granted')) return;
  try {
    const looking = !document.hidden && location.hash.split('/')[0] !== '#' && $('#view-chat').hidden === false;
    const d = await api(`api/messages?space=${state.space}&after=${state.lastId}${looking ? '&read=1' : ''}`);
    appendMessages(d.messages, true); showOtherUnread(d.unread);
  } catch {}
}

const TOOL_LABEL = (name, input = {}) => {
  const host = u => { try { return new URL(u).host; } catch { return 'a page'; } };
  return ({
    web_search: `Searching the web${input.query ? ': ' + input.query : ''}`, WebSearch: `Searching the web${input.query ? ': ' + input.query : ''}`,
    fetch_url: `Reading ${host(input.url)}`, WebFetch: `Reading ${host(input.url)}`, Read: 'Looking at the image',
    search_memory: 'Searching past conversations', memory_read: 'Reading memory', memory_append: 'Saving to memory', memory_replace: 'Updating memory',
    schedule_task: 'Scheduling', list_tasks: 'Checking scheduled tasks', cancel_task: 'Cancelling a task',
    delegate: `Starting a background job${input.title ? ': ' + input.title : ''}`, list_jobs: 'Checking jobs', cancel_job: 'Cancelling a job', archive_job: 'Archiving jobs', schedule_job: 'Scheduling a job',
    usage_report: 'Checking spending', file_read: 'Reading the attachment',
    skill_read: `Following a skill${input.name ? ': ' + input.name : ''}`, skill_save: 'Saving a skill', skill_delete: 'Removing a skill',
    calendar_events: 'Checking your calendar', calendar_add: 'Preparing a calendar event', mail_list: 'Looking through your email',
    mail_read: 'Reading an email', mail_draft: 'Writing a draft', mail_send: 'Preparing an email',
    browser_open: `Opening ${host(input.url)}`, browser_read: 'Reading the page', browser_click: 'Clicking', browser_type: 'Typing',
    browser_select: 'Choosing an option', browser_back: 'Going back', browser_screenshot: 'Looking at the page',
  })[name] || `Using ${name.replace(/_/g, ' ')}`;
};
// ---- spaces: separate conversations ----
state.space = 0; state.spaces = [];
const spaceOf = id => state.spaces.find(x => x.id === id) || state.spaces[0] || { id: 0, name: 'General', slug: 'general', folder: '', color: '' };
async function loadSpaces() {
  try { const d = await api('api/spaces'); state.spaces = d.spaces; state.spaceColors = d.colors; } catch { state.spaces = state.spaces.length ? state.spaces : [{ id: 0, name: 'General', slug: 'general', folder: '', color: '', unread: 0 }]; }
  renderSpaceHead();
}
function renderSpaceHead() {
  const sp = spaceOf(state.space), general = !sp.id;
  $('#chat-title').textContent = general ? (state.status?.name || 'Talixio') : sp.name;
  const lvl = levelOf(sp);
  $('#input').placeholder = lvl === 'secret' ? 'Secret · nothing leaves the server' : lvl === 'confidential' ? 'Confidential · only the AI model receives your messages'
    : general ? `Message ${state.status?.name || 'Talixio'}…` : `Message in ${sp.name}…`;
  const pb = $('#privacy-badge'); pb.className = 'privacy-badge ' + lvl;
  pb.querySelector('span').textContent = lvl === 'internal' ? '' : LEVELS[lvl].label;
  pb.title = t('Confidentiality: {level}', { level: t(LEVELS[lvl].label) }); pb.setAttribute('aria-label', pb.title);
  $('#composer').dataset.level = lvl;
  document.title = general ? (state.status?.name || 'Talixio') : `${sp.name} · ${state.status?.name || 'Talixio'}`;
  applyTint();
  $('#archived-note').hidden = !sp?.archived;
  $('#archived-restore').onclick = async () => {
    try { await post(`api/spaces/${sp.id}`, { ...sp, archived: false }); await loadSpaces(); renderSpaceHead(); toast('Space restored.'); } catch (err) { toast(err.message); }
  };
  showOtherUnread(state.spaces.filter(x => x.id !== state.space).reduce((a, x) => a + (x.unread || 0), 0));
}
function showOtherUnread(n) { const b = $('#space-unread'); b.hidden = !n; b.textContent = n > 99 ? '99+' : n; b.title = n ? `${n} new answer(s) in other spaces` : ''; }
async function switchSpace(id, opts = {}) {
  state.space = id;
  try { localStorage.setItem(LSK + 'talixio.space', String(id)); } catch {}
  const sp = spaceOf(id);
  if (!opts.keepHash && location.hash.split('/')[0] === '#chat') history.replaceState(null, '', id ? `#chat/${sp.slug}` : '#chat');
  renderSpaceHead();
  state.lastId = 0; state.firstLoaded = 0; $('#thread').innerHTML = '';
  $('#input').value = ''; state.pending = []; renderPending(); autosize();
  const first = await api(`api/messages?space=${id}&read=1`);
  state.firstId = first.first_id;
  appendMessages(first.messages);
  restoreDraft();
  const s = state.spaces.find(x => x.id === id); if (s) s.unread = 0;
  showOtherUnread(first.unread);
}
$('#space-btn').onclick = async e => {
  e.stopPropagation();
  const menu = $('#space-menu');
  if (!menu.hidden) { menu.hidden = true; $('#space-btn').setAttribute('aria-expanded', 'false'); return; }
  await loadSpaces();
  const active = state.spaces.filter(x => !x.archived), archived = state.spaces.filter(x => x.archived);
  const item = x => `<button class="space-item${x.id === state.space ? ' current' : ''}" role="menuitem" type="button" data-id="${x.id}">
      <i style="background:${x.color || 'var(--muted)'}"></i><span class="name">${esc(x.id ? x.name : 'General')}${x.folder ? ` <span class="folder">${esc(x.folder)}</span>` : ''}</span>${x.unread ? `<span class="badge">${x.unread}</span>` : ''}</button>`;
  menu.innerHTML = active.map(item).join('')
    + '<hr>' + (state.space ? '<button class="space-item" type="button" data-act="edit">Edit this space…</button>' : '')
    + '<button class="space-item" type="button" data-act="new">+ New space</button>'
    + (archived.length ? `<details><summary class="muted small" style="padding:6px 10px">Archived (${archived.length})</summary>${archived.map(item).join('')}</details>` : '');
  menu.querySelectorAll('[data-id]').forEach(b => b.onclick = () => { menu.hidden = true; switchSpace(+b.dataset.id); });
  menu.querySelector('[data-act=new]').onclick = () => { menu.hidden = true; openSpaceEditor(null); };
  menu.querySelector('[data-act=edit]')?.addEventListener('click', () => { menu.hidden = true; openSpaceEditor(spaceOf(state.space)); });
  menu.hidden = false; $('#space-btn').setAttribute('aria-expanded', 'true');
};
document.addEventListener('click', e => { if (!e.target.closest('.space-head')) { $('#space-menu').hidden = true; $('#space-btn').setAttribute('aria-expanded', 'false'); } });

/** One line of a space's history: "archived 10/10 14:02 (app)". */
function spaceEvent(e) {
  const what = { created: t('space created'), renamed: t('renamed (was {name})', { name: e.detail || '' }), archived: t('archived'), restored: t('restored'),
    privacy: t('confidentiality: {level}', { level: t(LEVELS[e.detail]?.label || e.detail || '') }), folder: t('folder: {folder}', { folder: e.detail || '/' }) }[e.what] || e.what;
  return `${what} ${new Date(e.ts * 1000).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' })} (${e.by === 'assistant' ? t('by the assistant') : t('in the app')})`;
}

/** Create or edit a space. prefill: {folder} when started from a library folder. */
async function openSpaceEditor(sp, prefill = {}) {
  const d = $('#space-dialog'), editing = !!(sp && sp.id);
  if (!state.spaceColors) await loadSpaces();
  $('#space-dialog-title').textContent = editing ? `Edit “${sp.name}”` : 'New space';
  $('#sp-name').value = editing ? sp.name : (prefill.name || '');
  $('#sp-folder').value = editing ? sp.folder : (prefill.folder || '');
  folderPicker($('#sp-folder'));
  $('#sp-tier').value = editing ? sp.tier : 'standard';
  $('#sp-code').value = editing ? (sp.code || '') : '';
  $('#sp-instructions').value = editing ? sp.instructions : '';
  $('#sp-notes').value = editing ? sp.notes : ''; $('#sp-notes').closest('form').querySelector('label[for=sp-notes]').hidden = $('#sp-notes').hidden = !editing;
  let color = editing && sp.color ? sp.color : state.spaceColors[state.spaces.length % state.spaceColors.length];
  const paint = () => { $('#sp-colors').innerHTML = state.spaceColors.map(c => `<button type="button" class="swatch" role="radio" aria-checked="${c === color}" aria-label="${c}" style="background:${c}" data-c="${c}"></button>`).join('');
    $('#sp-colors').querySelectorAll('.swatch').forEach(b => b.onclick = () => { color = b.dataset.c; paint(); }); };
  paint();
  let level = editing ? levelOf(sp) : 'internal';
  const paintLevel = () => {
    $('#sp-privacy').innerHTML = Object.entries(LEVELS).map(([k, v]) => `<button type="button" class="lvl-card ${k}" role="radio" aria-checked="${k === level}" data-l="${k}">${SHIELD_SVG}<span class="lvl-text"><b>${v.label}</b><small>${v.text}</small></span></button>`).join('');
    $('#sp-privacy').querySelectorAll('[data-l]').forEach(b => b.onclick = () => { level = b.dataset.l; paintLevel(); });
  };
  paintLevel();
  $('#sp-archive').hidden = $('#sp-delete').hidden = !editing;
  $('#sp-archive').textContent = editing && sp.archived ? t('Restore') : t('Archive'); $('#sp-archive').dataset.confirm = '';
  const hist = editing ? (sp.history || []) : [];
  $('#sp-history').hidden = !hist.length;
  $('#sp-history').textContent = hist.length ? t('History') + ' : ' + hist.slice(0, 4).map(spaceEvent).join(' · ') : '';
  $('#sp-delete').dataset.confirm = ''; $('#sp-delete').textContent = 'Delete';
  $('#sp-cancel').onclick = () => d.close();
  $('#space-form').onsubmit = async e => {
    e.preventDefault();
    const body = { name: $('#sp-name').value, folder: $('#sp-folder').value, tier: $('#sp-tier').value, color, instructions: $('#sp-instructions').value, code: $('#sp-code').value, privacy: level };
    if (editing) body.notes = $('#sp-notes').value;
    try {
      const { space } = await post(editing ? `api/spaces/${sp.id}` : 'api/spaces', body);
      d.close(); await loadSpaces(); toast(editing ? 'Space saved.' : `Space “${space.name}” created.`);
      if (!editing || location.hash.startsWith('#chat')) { if (location.hash.split('/')[0] !== '#chat') location.hash = '#chat'; await switchSpace(space.id); }
    } catch (err) { toast(err.message); }
  };
  $('#sp-archive').onclick = async e => {
    const b = e.currentTarget;
    if (!sp.archived && b.dataset.confirm !== '1') { b.dataset.confirm = '1'; b.textContent = t('Archive it? It leaves the list'); return; }
    try { await post(`api/spaces/${sp.id}`, { ...sp, archived: !sp.archived }); d.close(); await loadSpaces(); if (!sp.archived) await switchSpace(0); toast(sp.archived ? 'Space restored.' : 'Space archived: its conversation is kept.'); }
    catch (err) { toast(err.message); }
  };
  $('#sp-delete').onclick = async e => {
    const b = e.currentTarget;
    if (b.dataset.confirm !== '1') { b.dataset.confirm = '1'; b.textContent = 'Delete its conversation?'; return; }
    try { await post(`api/spaces/${sp.id}/delete`); d.close(); await loadSpaces(); await switchSpace(0); toast('Space deleted. Its documents are still in the library.'); }
    catch (err) { toast(err.message); }
  };
  loadFolderList();
  d.showModal();
  if (prefill.privacy) $('#sp-privacy').scrollIntoView({ block: 'center' }); else $('#sp-name').focus();
}

// ---- confidentiality: what leaves the server ----
const SHIELD_SVG = '<svg viewBox="0 0 24 24"><path d="M12 3l7 3v5c0 4.5-3 8.3-7 10-4-1.7-7-5.5-7-10V6z"/></svg>';
const CLOUD_SVG = '<svg viewBox="0 0 24 24"><path d="M7 18h10a4 4 0 0 0 .5-8A6 6 0 0 0 6 9.5 4.3 4.3 0 0 0 7 18z"/></svg>';
const SERVER_SVG = '<svg viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="7" rx="1.5"/><rect x="4" y="13" width="16" height="7" rx="1.5"/></svg>';
const LOCK_SVG = '<svg viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>';
const LEVELS = {
  internal: { label: 'Internal', text: 'As configured: the services in your settings are used normally.' },
  confidential: { label: 'Confidential', text: 'Only a model under business terms reads the content. Transcription and search by meaning stay on the server, the web needs your approval each time, notifications say nothing, shared links need a password and end within 30 days.' },
  secret: { label: 'Secret', text: 'Nothing leaves the server: no model outside, no web, no shared links. The library and tasks still work.' },
};
const levelOf = sp => (sp && LEVELS[sp.privacy]) ? sp.privacy : 'internal';
const PURPOSE = { model: 'answer', vision: 'reading images', transcription: 'transcription', embeddings: 'search by meaning', search: 'web search', web: 'web page',
  notify: 'notification', connector: 'connector', calendar: 'calendar', chat: 'chat app', music: 'music', other: 'other' };
const shortService = n => n.replace(/\s*\(.*\)$/, '');
const kchars = n => n >= 1000 ? (n / 1000).toLocaleString(LOCALE, { maximumFractionDigits: 1 }) + 'k' : String(n);
$('#privacy-badge').onclick = () => { if (state.space) openSpaceEditor(spaceOf(state.space), { privacy: true }); else location.hash = '#privacy'; };

/** Under an answer: where it went (pills), and on tap what exactly left the server. */
function traceLine(tr) {
  const names = [...new Set((tr.out || []).map(o => shortService(o.service)))];
  const docs = (tr.docs || []).length, local = (tr.local || []).length, blocked = (tr.blocked || []).length;
  const n = (k1, kn, v) => esc(t(v === 1 ? k1 : kn, { n: v }));
  let pills = names.length ? `<span class="tp out">${CLOUD_SVG}<span class="no-i18n">${esc(names[0])}${names.length > 1 ? ' +' + (names.length - 1) : ''}${docs ? ' · ' + n('1 document', '{n} documents', docs) : ''}</span></span>`
    : '<span class="tp local">' + SERVER_SVG + '<span>Stayed on the server</span></span>';
  if (local) pills += `<span class="tp local">${SERVER_SVG}<span class="no-i18n">${n('1 local', '{n} local', local)}</span></span>`;
  if (blocked) pills += `<span class="tp blocked">${LOCK_SVG}<span class="no-i18n">${n('1 blocked', '{n} blocked', blocked)}</span></span>`;
  return pills;
}
function traceDetail(tr) {
  const sec = (title, cls, rows) => rows.length ? `<div class="td-sec ${cls}"><b>${title}</b><ul>${rows.join('')}</ul></div>` : '';
  return sec('Sent out', 'out', (tr.out || []).map(o => `<li class="no-i18n">${esc(o.service)} <span class="muted">· ${esc(t(PURPOSE[o.purpose] || o.purpose))} · ${esc(t(o.calls === 1 ? '1 request' : '{n} requests', { n: o.calls }))} · ${kchars(o.bytes)} ${esc(t('bytes'))}</span></li>`))
    + sec('Documents given to the model', 'docs', (tr.docs || []).map(d => `<li class="no-i18n">${esc(d.name)} <span class="muted">· ${kchars(d.chars)} ${esc(t('characters'))}</span></li>`))
    + sec('Stayed on the server', 'local', [...new Set(tr.local || [])].map(l => `<li>${esc(l)}</li>`))
    + sec('Blocked', 'blocked', (tr.blocked || []).map(l => `<li>${esc(l)}</li>`));
}

async function loadPrivacy() {
  const d = await api('api/privacy');
  const L = { local: ['Local', 'Nothing leaves your server.'], business: ['Business', 'Only services under business terms, which do not train on your data, receive anything.'],
    personal: ['Personal', 'Some services may use your data or read it: fine for personal use, not for clients\' data.'] };
  const idx = ['local', 'business', 'personal'].indexOf(d.level);
  $('#pv-level').innerHTML = `<div class="pv-gauge">${['local', 'business', 'personal'].map((k, i) => `<div class="g ${k}${i === idx ? ' on' : ''}"><i></i><span>${L[k][0]}</span></div>`).join('')}</div>
    <h3 class="pv-title">${d.level === 'local' ? 'Level: Local' : d.level === 'business' ? 'Level: Business' : 'Level: Personal'}</h3><p>${L[d.level][1]}</p>
    ${d.issues ? `<p class="muted small">${esc(t(d.issues === 1 ? '1 point to look at below.' : '{n} points to look at below.', { n: d.issues }))}</p>` : ''}`;
  $('#pv-spaces').innerHTML = d.spaces.map(s => `<div class="pv-space"><i class="space-dot" style="background:${esc(s.color || 'var(--muted)')}"></i><span class="no-i18n">${esc(s.id ? s.name : t('General'))}</span>
      <span class="privacy-badge ${s.level}">${SHIELD_SVG}<span>${LEVELS[s.level].label}</span></span>${s.id ? `<button class="btn small ghost" type="button" data-sp="${s.id}">Change</button>` : ''}</div>`).join('');
  $('#pv-spaces').querySelectorAll('[data-sp]').forEach(b => b.onclick = async () => { await loadSpaces(); openSpaceEditor(spaceOf(+b.dataset.sp), { privacy: true }); });
  const locals = d.flows.filter(f => f.status === 'local').map(f => f.name);
  $('#pv-local').innerHTML = ['Library', 'Memory', 'Tasks', ...locals].map(n => `<span>${esc(n)}</span>`).join('');
  const fixBtn = f => f.fix.map((x, i) => `<button class="btn small ${f.status === 'bad' ? 'primary' : ''}" type="button" data-fix="${i}">${esc(x.label)}</button>`).join('');
  $('#pv-flows').innerHTML = d.flows.filter(f => f.status !== 'local').map((f, n) => `<div class="pv-flow ${f.status}" data-n="${n}"><i class="dot"></i><div>
      <b>${esc(f.name)}</b><p>${esc(f.what)}</p><p class="why">${esc(f.why)}</p>
      <div class="row wrap">${fixBtn(f)}${f.terms ? `<a class="small" href="${esc(f.terms)}" target="_blank" rel="noopener">Terms</a>` : ''}</div></div></div>`).join('')
    || '<p class="muted">Nothing leaves your server.</p>';
  const outside = d.flows.filter(f => f.status !== 'local');
  $('#pv-flows').querySelectorAll('[data-fix]').forEach(b => b.onclick = async () => {
    const f = outside[+b.closest('[data-n]').dataset.n], x = f.fix[+b.dataset.fix];
    if (x.href === '#library/shares') { location.hash = '#library'; setTimeout(() => openSharesManager(), 300); return; }
    if (x.href) { location.hash = x.href.split('/')[0]; return; }
    try { await post('api/settings', { changes: x.set }); toast('Saved.'); loadPrivacy(); } catch (e) { toast(e.message); }
  });
  $('#pv-security').innerHTML = d.security.map(s => `<div class="pv-sec ${s.ok ? 'ok' : 'warn'}"><i class="dot"></i><span>${esc(s.label)}</span><span class="muted small">${s.ok ? 'Active' : 'Not set up'}</span></div>`).join('');
  const j = d.journal, max = Math.max(1, ...j.today.map(r => +r.calls));
  const names = Object.fromEntries(d.spaces.map(s => [s.id, s.id ? s.name : t('General')]));
  const req = v => esc(t(+v === 1 ? '1 request' : '{n} requests', { n: v })), blk = v => esc(t(+v === 1 ? '1 blocked' : '{n} blocked', { n: v }));
  $('#pv-journal').innerHTML = `<h4>Today</h4>` + (j.today.length ? `<div class="pv-bars">${j.today.map(r => `<span class="no-i18n">${esc(shortService(r.name))} <span class="muted small">· ${esc(t(PURPOSE[r.purpose] || r.purpose))}</span></span>
      <i style="--w:${Math.max(4, Math.round(100 * r.calls / max))}%" class="${+r.blocked ? 'blocked' : ''}"></i><span class="no-i18n">${r.calls}${+r.blocked ? ` <span class="muted small">(${blk(r.blocked)})</span>` : ''}</span>`).join('')}</div>` : '<p class="muted small">Nothing went out today.</p>')
    + (j.spaces.length ? `<h4>Last 7 days, per space</h4><ul class="pv-perspace no-i18n">${j.spaces.map(s => `<li>${esc(names[s.space] || '#' + s.space)} <span class="muted small">· ${req(s.calls)}${+s.blocked ? ' · ' + blk(s.blocked) : ''}</span></li>`).join('')}</ul>` : '');
}

/**
 * A folder chooser under a text input: the whole library tree, indented, shown on focus and filtered as you
 * type (arrows + Enter, or click). Typing a path that does not exist yet still works: it is created on save.
 */
function folderPicker(input, on = true) {
  input.removeAttribute('list');
  if (!input._fp) {
    const wrap = document.createElement('span'); wrap.className = 'fp-wrap';
    input.replaceWith(wrap); wrap.append(input);
    const box = document.createElement('div'); box.className = 'folder-pick'; box.hidden = true; box.setAttribute('role', 'listbox'); wrap.append(box);
    const fp = input._fp = { on: true, tree: [], start: '', active: -1, box };
    const items = () => [...box.querySelectorAll('button')];
    const choose = path => { input.value = path; box.hidden = true; input.focus(); input.dispatchEvent(new Event('change')); };
    fp.render = () => {
      if (!fp.on) { box.hidden = true; return; }
      const v = input.value.trim(), lv = v.toLowerCase();
      const list = !v || v === fp.start ? fp.tree : fp.tree.filter(f => f.path.toLowerCase().includes(lv.replace(/\/+$/, '')));
      const fresh = v.startsWith('/') && v !== '/' && !fp.tree.some(f => f.path.toLowerCase() === lv.replace(/\/+$/, ''));
      box.innerHTML = (fp.tree.length ? '' : `<div class="muted small fp-note">${esc(t('Loading folders…'))}</div>`)
        + list.map(f => `<button type="button" role="option" data-p="${esc(f.path)}" class="${f.path === fp.start ? 'cur' : ''}" style="padding-left:${10 + 14 * (f.depth - 1)}px"${f.readonly ? ' disabled' : ''}>`
          + `<span class="no-i18n">📁 ${esc(f.name)}</span>${f.total ? `<span class="muted small">${f.total}</span>` : ''}</button>`).join('')
        + (fresh ? `<div class="muted small fp-note">${esc(t('New folder, created on save: {path}', { path: v }))}</div>` : '')
        + (fp.tree.length && !list.length && !fresh ? `<div class="muted small fp-note">${esc(t('No folder matches'))}</div>` : '');
      fp.active = -1; box.hidden = false;
      box.querySelector('.cur')?.scrollIntoView({ block: 'nearest' });
    };
    box.addEventListener('mousedown', e => e.preventDefault()); // keep the focus in the input
    box.addEventListener('click', e => { const b = e.target.closest('button[data-p]'); if (b) choose(b.dataset.p); });
    input.addEventListener('focus', () => fp.render());
    input.addEventListener('input', () => fp.render());
    input.addEventListener('blur', () => setTimeout(() => { box.hidden = true; }, 150));
    input.addEventListener('keydown', e => {
      if (box.hidden) return;
      const bs = items().filter(b => !b.disabled);
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault(); if (!bs.length) return;
        fp.active = (fp.active + (e.key === 'ArrowDown' ? 1 : bs.length - 1 + (fp.active < 0 ? 1 : 0))) % bs.length;
        bs.forEach((b, i) => b.classList.toggle('active', i === fp.active)); bs[fp.active].scrollIntoView({ block: 'nearest' });
      } else if (e.key === 'Enter' && fp.active >= 0) { e.preventDefault(); choose(bs[fp.active].dataset.p); }
      else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); box.hidden = true; }
    });
  }
  const fp = input._fp;
  fp.on = on; fp.start = input.value.trim();
  if (!on) { fp.box.hidden = true; return; }
  api('api/library/tree').then(({ tree }) => { fp.tree = tree; if (document.activeElement === input) fp.render(); }).catch(() => {});
}

async function loadFolderList() {
  try { const { tree } = await api('api/library/tree'); $('#folder-list').innerHTML = tree.map(t => `<option value="${esc(t.path)}">`).join(''); } catch {}
}

// ---- appearance: light / dark / automatic, accent colour (per device) ----
function themePref() { try { return JSON.parse(localStorage.getItem(LSK + 'talixio.theme') || '{}'); } catch { return {}; } }
function applyTheme() {
  const t = themePref();
  if (t.mode === 'light' || t.mode === 'dark') document.documentElement.dataset.theme = t.mode; else delete document.documentElement.dataset.theme;
  applyTint();
}
function applyTint() {
  const sp = state.spaces?.length ? spaceOf(state.space) : null;
  const tint = (sp && sp.id && sp.color) || themePref().accent || '';
  document.documentElement.classList.toggle('tinted', !!tint);
  if (tint) document.documentElement.style.setProperty('--tint', tint); else document.documentElement.style.removeProperty('--tint');
  const bg = getComputedStyle(document.documentElement).getPropertyValue('--bg').trim();
  document.querySelectorAll('meta[name=theme-color]').forEach(m => m.content = bg);
}
function renderAppearance() {
  const t = themePref();
  $('#theme-mode').value = t.mode || '';
  const colors = ['', ...(state.spaceColors || [])];
  $('#theme-colors').innerHTML = colors.map(c => `<button type="button" class="swatch" role="radio" aria-checked="${(t.accent || '') === c}" aria-label="${c || 'default'}" title="${c || 'Default green'}" style="background:${c || '#0e7c6b'}" data-c="${c}"></button>`).join('');
  $('#theme-colors').querySelectorAll('.swatch').forEach(b => b.onclick = () => saveTheme({ ...themePref(), accent: b.dataset.c }));
}
function saveTheme(t) { try { localStorage.setItem(LSK + 'talixio.theme', JSON.stringify(t)); } catch {} applyTheme(); renderAppearance(); }
$('#theme-mode').onchange = e => saveTheme({ ...themePref(), mode: e.target.value });
applyTheme();
matchMedia('(prefers-color-scheme: dark)').addEventListener?.('change', applyTint);

// ---- find in the conversation ----
$('#chat-search-btn').onclick = () => {
  const p = $('#chat-search'); p.hidden = !p.hidden;
  if (!p.hidden) { $('#chat-search-q').focus(); $('#chat-search-q').select(); }
};
$('#chat-search-close').onclick = () => { $('#chat-search').hidden = true; twoPassStop('chat'); };
$('#chat-search-q').addEventListener('keydown', e => { if (e.key === 'Escape') { $('#chat-search').hidden = true; twoPassStop('chat'); } });
$('#chat-search-q').addEventListener('input', () => { clearTimeout(chatSearch.t); chatSearch.t = setTimeout(chatSearch, 300); });

/**
 * A search in two passes: the words at once, then by meaning (a few seconds), which completes the list.
 * render(results, phase): phase 'wait' (nothing yet), 'meaning' (first results, more coming), 'done', 'error'.
 * A newer search with the same key cancels the running one: late answers are dropped.
 */
const twoPassCtl = {};
function twoPassStop(key) { twoPassCtl[key]?.abort(); delete twoPassCtl[key]; }
async function twoPass(key, url, render) {
  twoPassStop(key);
  const ctl = twoPassCtl[key] = new AbortController();
  render(null, 'wait');
  try {
    const quick = await api(url + '&mode=quick', { signal: ctl.signal });
    render(quick.results, quick.more ? 'meaning' : 'done');
    if (quick.more) render((await api(url, { signal: ctl.signal })).results, 'done');
  } catch (e) { if (!ctl.signal.aborted) render(null, 'error', e); }
  finally { if (twoPassCtl[key] === ctl) delete twoPassCtl[key]; }
}
const searchWait = text => `<div class="search-wait" role="status">${SPIN_SVG}<span>${esc(t(text))}</span></div>`;

function chatSearch() {
  const q = $('#chat-search-q').value.trim(); const box = $('#chat-search-results');
  if (q.length < 2) { twoPassStop('chat'); box.innerHTML = ''; return; }
  const mark = x => esc(x).replace(new RegExp(q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/\s+/g, '\\s+'), 'gi'), m => `<mark>${m}</mark>`);
  twoPass('chat', `api/messages/search?space=${state.space}&q=` + encodeURIComponent(q), (results, phase, err) => {
    if (phase === 'error') { box.innerHTML = `<p class="error small">${esc(err.message)}</p>`; return; }
    if (!results) { box.innerHTML = searchWait('Searching…'); return; }
    box.innerHTML = results.length || phase !== 'done' ? '' : '<p class="muted small">Nothing found.</p>';
    for (const r of results) {
      const b = document.createElement('button'); b.type = 'button'; b.className = 'sres';
      b.innerHTML = `<span class="muted small">${r.role === 'user' ? 'You' : esc(state.status?.name || 'Assistant')} · ${new Date(r.ts * 1000).toLocaleString(LOCALE, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })}${r.exact ? '' : ' · similar'}</span><span>${mark(r.snippet)}</span>`;
      b.onclick = () => { jumpTo(r.id); if (matchMedia('(max-width: 760px)').matches) $('#chat-search').hidden = true; };
      box.append(b);
    }
    if (phase === 'meaning') box.insertAdjacentHTML('afterbegin', searchWait('Looking for similar messages…'));
  });
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
  const livePill = d => { // what is leaving the server right now (or staying here)
    let box = live.querySelector('.live-pills');
    if (!box) { box = document.createElement('div'); box.className = 'live-pills'; live.append(box); }
    const key = (d.local ? 'l:' : 'o:') + d.label;
    if (box.querySelector(`[data-k="${CSS.escape(key)}"]`)) return;
    const s = document.createElement('span'); s.dataset.k = key; s.className = 'tp ' + (d.local ? 'local' : 'out live');
    s.textContent = (d.local ? '● ' : '→ ') + (d.local ? d.label : shortService(d.label)); box.append(s);
  };
  try {
    const res = await fetch('api/chat', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Talixio': '1', Accept: 'text/event-stream' },
      body: JSON.stringify({ text, files, meeting: !!opts.meeting, stream: true, space: state.space }) });
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
        else if (ev === 'privacy') livePill(d);
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
  try { await post('api/chat/stop'); } catch (e) { toast(e.message); }
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
const draftKey = () => LSK + 'talixio.draft' + (state.space ? '.' + state.space : ''); // one draft per space
function saveDraft() {
  try {
    const text = $('#input').value; const files = state.pending.filter(f => f.id).map(f => ({ id: f.id, info: f.info }));
    if (text.trim() || files.length) localStorage.setItem(draftKey(), JSON.stringify({ text, files, queued: !!state.queued }));
    else localStorage.removeItem(draftKey());
  } catch {}
}
function clearDraft() { try { localStorage.removeItem(draftKey()); } catch {} }
function restoreDraft() {
  let d = null;
  try { d = JSON.parse(localStorage.getItem(draftKey()) || 'null'); } catch {}
  if (!d || $('#input').value.trim() || state.pending.length) return;
  $('#input').value = d.text || ''; autosize();
  state.pending = (d.files || []).filter(f => f.id); renderPending();
  if (d.queued) { if (navigator.onLine) send(); else queueOffline(); }
}

// ---- approvals ----
async function loadApprovals() {
  let list = [];
  try { list = (await api('api/approvals')).approvals; } catch { return; }
  const box = $('#approvals'); box.hidden = !list.length; box.innerHTML = '';
  setBadge(list.length);
  for (const a of list) {
    const el = document.createElement('div'); el.className = 'approval';
    el.innerHTML = `<span class="label">Needs your approval · #${a.id}</span>
      <div class="actions"><button class="btn small" data-d="reject" type="button">Reject</button><button class="btn small primary" data-d="approve" type="button">Approve</button></div>
      <div class="what">${esc(a.summary)}</div>`;
    el.querySelectorAll('button').forEach(b => b.onclick = async () => {
      el.querySelectorAll('button').forEach(x => x.disabled = true);
      try { const r = await post(`api/approvals/${a.id}/${b.dataset.d}`); appendMessages(r.messages); toast(b.dataset.d === 'approve' ? 'Approved' : 'Rejected'); }
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
    xhr.open('POST', 'api/files'); xhr.setRequestHeader('X-Talixio', '1');
    xhr.onload = () => { try { const d = JSON.parse(xhr.responseText); xhr.status < 300 ? resolve(d.file) : reject(new Error(d.error || 'Upload failed')); } catch { reject(new Error(`Upload failed (HTTP ${xhr.status})`)); } };
    xhr.onerror = () => reject(new Error('Upload failed: network error'));
    xhr.send(fd);
  });
}
async function addFiles(list) {
  for (const file of list) {
    const entry = { id: null, info: { name: file.name, kind: file.type.startsWith('image/') ? 'image' : 'file' } };
    state.pending.push(entry); renderPending();
    try { const info = await uploadFile(file, file.name, spaceOf(state.space).folder || ''); entry.id = info.id; entry.info = info; }
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
    const info = await uploadFile(blob, name, spaceOf(state.space).folder || '');
    await send({ files: [info.id], shownFiles: [info], meeting });
  } catch (e) { toast(e.message); }
}

function autosize() { const i = $('#input'); i.style.height = 'auto'; i.style.height = Math.min(i.scrollHeight, 200) + 'px'; }

// ---- tasks ----
async function runTaskNow(t, b) {
  b.disabled = true; b.textContent = 'Running…';
  try { const r = await post(`api/tasks/${t.id}/run`); toast('Done: the result is in the chat.'); if (r.space === state.space) poll(); }
  catch (e) { toast(e.message); }
  b.disabled = false; b.textContent = window.t(t.title === 'Morning briefing' ? 'Send it now' : 'Run now');
}
$('#brief-save').onclick = async () => {
  try { await post('api/tasks/briefing', { time: $('#brief-time').value, days: $('#brief-days').value }); toast('Morning briefing set up.'); loadTasks(); }
  catch (e) { toast(e.message); }
};
$('#weekly-save').onclick = async () => {
  try { await post('api/tasks/weekly', { day: +$('#weekly-day').value, time: $('#weekly-time').value, folder: $('#weekly-folder').value.trim() }); toast(t('Weekly review set up.')); loadTasks(); }
  catch (e) { toast(e.message); }
};
folderPicker($('#weekly-folder')); folderPicker($('#watch-folder'));
$('#watch-every').onchange = () => { $('#watch-day').hidden = $('#watch-every').value === 'daily'; };
$('#watch-name').oninput = () => { $('#watch-folder').placeholder = '/Drafts/' + ($('#watch-name').value.trim() || '…'); };
function fillWatch(w) {
  $('#watch-id').value = w?.id ?? '';
  for (const k of ['name', 'topics', 'sources', 'audience', 'format', 'every', 'time', 'folder']) $('#watch-' + k).value = w ? (w[k] ?? '') : ({ name: 'Fil IA', every: 'weekly', time: '08:00' }[k] ?? '');
  $('#watch-day').value = String(w?.day ?? 1);
  $('#watch-every').onchange(); $('#watch-name').oninput();
  $('#watch-save').textContent = t(w ? 'Update' : 'Set up');
  $('#watch-new').hidden = !w;
  $('#watch-state').textContent = w ? t('Editing "{name}"', { name: w.name }) : '';
}
$('#watch-new').onclick = () => fillWatch(null);
$('#watch-form').onsubmit = async e => {
  e.preventDefault();
  const b = { id: $('#watch-id').value || null, day: +$('#watch-day').value };
  for (const k of ['name', 'topics', 'sources', 'audience', 'format', 'every', 'time', 'folder']) b[k] = $('#watch-' + k).value.trim();
  try { const { task } = await post('api/tasks/watch', b); toast(t('"{name}" set up: its drafts go to {folder}.', { name: task.title, folder: b.folder || '/Drafts/' + b.name })); state.watches = null; await loadTasks(); fillWatch((state.watches || []).find(w => w.id === task.id)); }
  catch (err) { toast(err.message); }
};
/** What the user rated on a routine's messages, with a way to remove a vote. */
async function showFeedback(task, box) {
  if (!box.hidden) { box.hidden = true; return; }
  try {
    const { feedback } = await api(`api/tasks/${task.id}/feedback`);
    box.innerHTML = feedback.length ? `<ul class="plain">${feedback.map(f => `<li>${f.vote > 0 ? '👍' : '👎'} <span>${esc(f.item)}</span>${f.note ? ` <span class="muted">(${esc(f.note)})</span>` : ''} <span class="muted small">${fmtTime(f.ts)}</span> <button class="btn small ghost" type="button" data-id="${f.id}">${esc(t('Remove'))}</button></li>`).join('')}</ul>`
      : `<p class="muted small">${esc(t('No feedback yet: rate the lines of its messages with 👍/👎 in the chat.'))}</p>`;
    box.querySelectorAll('button[data-id]').forEach(b => b.onclick = async () => { try { await post(`api/feedback/${b.dataset.id}/delete`); b.closest('li').remove(); } catch (e) { toast(e.message); } });
    box.hidden = false;
  } catch (e) { toast(e.message); }
}
async function loadTasks() {
  const [{ tasks }, { watches }] = await Promise.all([api('api/tasks'), api('api/tasks/watches')]);
  state.watches = watches;
  const weekly = tasks.find(t => t.title === 'Weekly review');
  if (weekly) {
    const [mm, hh, , , day] = weekly.cron.split(' ');
    $('#weekly-time').value = `${hh.padStart(2, '0')}:${mm.padStart(2, '0')}`; $('#weekly-day').value = day;
    $('#weekly-folder').value = (weekly.prompt.match(/kb_save_note \(folder "([^"]+)"/) || [])[1] || '';
    $('#weekly-save').textContent = t('Update');
    $('#weekly-run').hidden = false; $('#weekly-run').onclick = () => runTaskNow(weekly, $('#weekly-run'));
  }
  const brief = tasks.find(t => t.title === 'Morning briefing');
  if (brief) {
    const [mm, hh, , , days] = brief.cron.split(' ');
    $('#brief-time').value = `${hh.padStart(2, '0')}:${mm.padStart(2, '0')}`; $('#brief-days').value = days;
    $('#brief-save').textContent = 'Update';
    $('#brief-run').hidden = false; $('#brief-run').onclick = () => runTaskNow(brief, $('#brief-run'));
  }
  const box = $('#tasks'); box.innerHTML = '';
  if (!tasks.length) { box.innerHTML = '<div class="placeholder">No scheduled tasks yet.</div>'; return; }
  for (const t of tasks) {
    const el = document.createElement('div');
    el.className = 'task' + (t.enabled ? '' : ' paused');
    const when = t.cron ? `Repeats · <code>${esc(t.cron)}</code>` : 'Once';
    const next = t.enabled && t.next_text ? `Next: ${esc(t.next_text)}` : (t.last_run ? `Last ran ${fmtTime(t.last_run)}` : 'Paused');
    el.innerHTML = `<div><h3>${esc(t.title)}</h3><div class="when"><span class="chip">${when}</span>${t.space_name ? `<span class="chip" title="Answers in this space">${esc(t.space_name)}</span>` : ''}<span>${next}</span></div></div>
      <div class="actions">${tierSelect(t.tier, 'Model that runs this task')}${watches.some(w => w.id === t.id) ? '<button class="btn small" data-a="watch">Settings</button>' : ''}${t.feedback ? `<button class="btn small ghost" data-a="feedback" title="${esc(window.t('What you rated on its messages'))}">👍👎 ${t.feedback}</button>` : ''}<button class="btn small" data-a="run" title="Run it now">Run now</button><button class="btn small" data-a="toggle">${t.enabled ? 'Pause' : 'Resume'}</button><button class="btn small ghost" data-a="delete">Delete</button></div>
      <p class="prompt">${esc(t.prompt)}</p><div class="feedback-list" hidden></div>`;
    el.querySelector('select').onchange = async e => {
      try { await post(`api/tasks/${t.id}/tier`, { tier: e.target.value }); toast(`"${t.title}" will run on the ${e.target.value} tier.`); }
      catch (err) { toast(err.message); e.target.value = t.tier; }
    };
    el.querySelectorAll('button').forEach(b => b.onclick = async () => {
      if (b.dataset.a === 'run') return runTaskNow(t, b);
      if (b.dataset.a === 'feedback') return showFeedback(t, el.querySelector('.feedback-list'));
      if (b.dataset.a === 'watch') { fillWatch(watches.find(w => w.id === t.id)); $('#watch-card').open = true; $('#watch-card').scrollIntoView({ behavior: 'smooth', block: 'start' }); return; }
      try { await post(`api/tasks/${t.id}/${b.dataset.a}`); toast(b.dataset.a === 'delete' ? 'Task deleted' : (t.enabled ? 'Task paused' : 'Task resumed')); loadTasks(); }
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

const EFFORTS = { low: 'low', medium: 'medium', high: 'high', xhigh: 'very high', max: 'max' };
function effortSelect(current) {
  return `<select class="effort-select" aria-label="Effort" title="Effort: deeper thinking and more steps, longer and more expensive">${Object.entries(EFFORTS).map(([v, l]) =>
    `<option value="${v}"${v === current ? ' selected' : ''}>effort ${l}</option>`).join('')}</select>`;
}

const jobsFilter = (() => { try { return JSON.parse(localStorage.getItem(LSK + 'talixio.jobs') || '{}'); } catch { return {}; } })();
function saveJobsFilter() { try { localStorage.setItem(LSK + 'talixio.jobs', JSON.stringify(jobsFilter)); } catch {} }
const spaceChip = id => { const sp = spaceOf(+id); return `<span class="chip space-chip" data-space="${sp.id}" title="Show only the jobs of this space"><i class="space-dot" style="background:${esc(sp.color || 'var(--muted)')}"></i>${esc(sp.name)}</span>`; };
const tagsOf = j => (j.tags || '').split(',').filter(Boolean);

async function loadJobs() {
  const q = new URLSearchParams();
  if (jobsFilter.space != null && jobsFilter.space !== '') q.set('space', jobsFilter.space);
  if (jobsFilter.tag) q.set('tag', jobsFilter.tag);
  if (jobsFilter.archived) q.set('archived', '1');
  if (jobsFilter.q) q.set('q', jobsFilter.q);
  const { jobs, tags, archived_count, archive_days } = await api('api/jobs?' + q);
  const s = state.status;
  if (s) $('#jobs-help').textContent = `Sub-agents the assistant starts for research and multi-step work: up to ${s.max_parallel_jobs} at once, each capped at $${s.job_max_usd.toFixed(2)} unless you give a budget (up to $${(+s.job_max_usd_limit).toFixed(2)}, Settings → Budget). Results come back in the chat of the space that started them; a job stopped by its cap can Continue from where it stopped.`;
  // filters: space, tags
  const sel = $('#jobs-space');
  sel.innerHTML = `<option value="">All spaces</option>` + state.spaces.filter(x => !x.archived || x.id === +jobsFilter.space).map(x => `<option value="${x.id}">${esc(x.name)}</option>`).join('');
  sel.value = jobsFilter.space ?? '';
  sel.onchange = () => { jobsFilter.space = sel.value; saveJobsFilter(); loadJobs(); };
  const arch = $('#jobs-archived');
  arch.options[1].textContent = (archived_count ? t('Archived ({n})', { n: archived_count }) : t('Archived'));
  arch.title = t('Finished jobs go to the archive on their own after {n} days.', { n: archive_days });
  arch.value = jobsFilter.archived ? '1' : '';
  arch.onchange = () => { jobsFilter.archived = !!arch.value; saveJobsFilter(); loadJobs(); };
  const search = $('#jobs-search');
  if (document.activeElement !== search) search.value = jobsFilter.q || '';
  search.oninput = () => { clearTimeout(search.t); search.t = setTimeout(() => { jobsFilter.q = search.value.trim(); saveJobsFilter(); loadJobs(); }, 300); };
  const all = $('#jobs-archive-all');
  all.hidden = !!jobsFilter.archived || !jobs.some(j => !['queued', 'running'].includes(j.status));
  all.onclick = async () => {
    if (!confirm(t('Archive every finished job of this view? Their reports stay in the library, and you find them again under Archived.'))) return;
    try { const r = await post('api/jobs/archive-finished', { space: jobsFilter.space ?? '', tag: jobsFilter.tag || '' }); toast(t('{n} job(s) archived', { n: r.count })); loadJobs(); } catch (e) { toast(e.message); }
  };
  const names = Object.keys(tags || {});
  if (jobsFilter.tag && !names.includes(jobsFilter.tag)) names.unshift(jobsFilter.tag);
  $('#jobs-tags').innerHTML = names.length ? names.map(t => `<button type="button" class="tag${t === jobsFilter.tag ? ' on' : ''}" data-tag="${esc(t)}">#${esc(t)}${tags?.[t] ? ` <small>${tags[t]}</small>` : ''}</button>`).join('') : '<span class="muted small">No tags yet: the assistant tags the jobs it starts, and you can add yours.</span>';
  $('#jobs-tags').querySelectorAll('.tag').forEach(b => b.onclick = () => { jobsFilter.tag = jobsFilter.tag === b.dataset.tag ? '' : b.dataset.tag; saveJobsFilter(); loadJobs(); });
  const box = $('#jobs'); box.innerHTML = '';
  const filtered = (jobsFilter.space ?? '') !== '' || jobsFilter.tag || jobsFilter.q || jobsFilter.archived;
  if (!jobs.length) {
    box.innerHTML = filtered ? '<div class="placeholder">No job matches these filters. <a href="#jobs" data-a="clear">Show them all</a></div>'
      : '<div class="placeholder">No jobs yet. Ask for research, e.g. <q>compare the three best e-bikes under €2000</q>.</div>';
    box.querySelector('[data-a=clear]')?.addEventListener('click', e => { e.preventDefault(); jobsFilter.space = ''; jobsFilter.tag = ''; jobsFilter.q = ''; jobsFilter.archived = false; saveJobsFilter(); loadJobs(); });
    return;
  }
  for (const j of jobs) {
    const el = document.createElement('div');
    el.className = 'task job' + (+j.archived ? ' archived' : '');
    const busy = ['queued', 'running'].includes(j.status);
    const reportHtml = j.report ? (j.report.deleted ? `<span class="muted">${esc(t('Report note deleted from the library'))}</span>`
      : `${md(`[${j.report.name.replace(/[[\]]/g, '')}](/files/${j.report.id})`)}<span class="muted">${esc(j.report.folder)}</span>`) : '';
    const routineHtml = (j.routines || []).map(r => `<a class="chip" href="#tasks" title="${esc(t('A routine runs this job again'))}">⏰ ${r.cron ? esc(t('scheduled')) : esc(t('once'))}${+r.enabled ? '' : ' · ' + esc(t('paused'))}</a>`).join('');
    const moreHtml = busy ? '' : `<div class="more">${j.kind === 'agent' ? `<button class="btn small ghost" data-a="schedule" title="${esc(t('Run it again on a schedule, as a routine'))}">${esc(t('Schedule…'))}</button>` : ''}`
      + `<button class="btn small ghost" data-a="archive">${esc(t(+j.archived ? 'Unarchive' : 'Archive'))}</button><button class="btn small ghost" data-a="delete">${esc(t('Delete'))}</button></div>`;
    const dur = j.started ? Math.max(1, Math.round(((j.finished || Date.now() / 1000) - j.started))) : null;
    const durText = dur == null ? 'waiting' : dur < 90 ? dur + ' s' : Math.round(dur / 60) + ' min';
    const tagHtml = () => tagsOf(j).map(t => `<span class="tag small" data-tag="${esc(t)}">#${esc(t)}</span>`).join('') + '<button type="button" class="tag small add" data-a="tags" title="Edit the tags">＋ tag</button>';
    el.innerHTML = `<div><h3>${esc(j.title)}</h3><div class="when"><span class="pill ${j.status}">${j.status === 'running' ? '<span class="dot"></span>' : ''}${j.status}</span>
        ${spaceChip(j.space)}<span class="chip">${esc(j.tier)}</span>${j.kind === 'agent' ? `<span class="chip" title="Effort">${esc(EFFORTS[j.effort || state.status?.job_effort] || j.effort || '')}</span>` : ''}<span>${money(+j.cost_usd) || '$0'} of ${+j.max_cost_usd > 0 ? '$' + (+j.max_cost_usd).toFixed(2) : 'no cap'}</span><span>${durText}</span><span>${fmtTime(j.created)}</span>${j.parent ? `<span class="chip" title="Carries on from job #${j.parent}">continues #${j.parent}</span>` : ''}</div>
        <div class="jtags">${tagHtml()}</div>${reportHtml || routineHtml ? `<div class="jreport">${reportHtml ? '📄 ' + reportHtml : ''}${routineHtml}</div>` : ''}</div>
      <div class="actions">${['queued', 'running'].includes(j.status) ? '<button class="btn small" data-a="cancel">Cancel</button>'
        : j.kind !== 'reflect' ? `${tierSelect(j.tier, 'Model for running it again')}${j.kind === 'agent' ? effortSelect(j.effort || state.status?.job_effort || 'medium') : ''}<label class="cap" title="Cost cap for running it again (up to $${(+(state.status?.job_max_usd_limit ?? 10)).toFixed(2)})">$<input type="number" min="0.05" step="0.5" value="${(+j.max_cost_usd > 0 ? (j.status === 'failed' && /cost cap/.test(j.result || '') ? Math.min(+j.max_cost_usd * 2, +(state.status?.job_max_usd_limit ?? 10) || +j.max_cost_usd * 2) : +j.max_cost_usd) : (+(state.status?.job_max_usd) || 2)).toFixed(2)}" aria-label="Cost cap ($)"></label>${j.kind === 'agent' ? `<button class="btn small${j.status === 'failed' && /cost cap/.test(j.result || '') ? ' primary' : ''}" data-a="continue" title="Carry on from where it stopped, with the cap and effort chosen here">Continue</button>` : ''}<button class="btn small" data-a="rerun" title="Start over with the same instructions">Run again</button>` : ''}${moreHtml}</div>
      <details><summary>${j.result ? 'Report and instructions' : 'Instructions'}</summary>
        ${j.result ? `<div class="doc-label">Report</div><div class="doc">${md(j.result)}</div>` : ''}
        <details class="instr"${j.result ? '' : ' open'}><summary class="doc-label">Instructions</summary><div class="doc">${md(j.instructions)}</div></details></details>`;
    el.querySelector('.space-chip').addEventListener('click', () => { jobsFilter.space = String(j.space); saveJobsFilter(); loadJobs(); });
    const wireTags = () => {
      el.querySelectorAll('.jtags .tag[data-tag]').forEach(t => t.onclick = () => { jobsFilter.tag = t.dataset.tag; saveJobsFilter(); loadJobs(); });
      el.querySelector('[data-a=tags]').onclick = () => {
        const box = el.querySelector('.jtags');
        box.innerHTML = `<input class="tag-input" type="text" value="${esc(tagsOf(j).join(', '))}" placeholder="tags, separated by commas" aria-label="Tags">`;
        const inp = box.querySelector('input'); inp.focus(); inp.select();
        let done = false;
        const save = async keep => {
          if (done) return; done = true;
          if (keep) { try { const r = await post(`api/jobs/${j.id}/tags`, { tags: inp.value }); j.tags = r.job.tags; } catch (e) { toast(e.message); } }
          box.innerHTML = tagHtml(); wireTags();
          if (keep) loadJobs();
        };
        inp.onkeydown = e => { if (e.key === 'Enter') save(true); if (e.key === 'Escape') save(false); };
        inp.onblur = () => save(true);
      };
    };
    wireTags();
    el.querySelector('[data-a=cancel]')?.addEventListener('click', async () => {
      try { await post(`api/jobs/${j.id}/cancel`); toast('Job cancelled'); loadJobs(); } catch (e) { toast(e.message); }
    });
    el.querySelector('[data-a=archive]')?.addEventListener('click', async () => {
      try { await post(`api/jobs/${j.id}/archive`, { archived: !+j.archived }); toast(t(+j.archived ? 'Back in the list' : 'Archived: find it again under Archived')); loadJobs(); } catch (e) { toast(e.message); }
    });
    el.querySelector('[data-a=delete]')?.addEventListener('click', async () => {
      if (!confirm(t('Delete job "{title}" for good? Its report note stays in the library.', { title: j.title }))) return;
      try { await post(`api/jobs/${j.id}/delete`); toast(t('Job deleted')); loadJobs(); } catch (e) { toast(e.message); }
    });
    el.querySelector('[data-a=schedule]')?.addEventListener('click', () => {
      if (el.querySelector('.jsched')) return el.querySelector('.jsched').remove();
      const p = document.createElement('div'); p.className = 'jsched';
      const days = [1, 2, 3, 4, 5, 6, 0].map(d => `<option value="${d}">${esc(new Date(2024, 0, 7 + d).toLocaleDateString(undefined, { weekday: 'long' }))}</option>`).join('');
      p.innerHTML = `<select data-f="rep"><option value="week">${esc(t('Every week'))}</option><option value="day">${esc(t('Every day'))}</option><option value="weekdays">${esc(t('Every weekday'))}</option><option value="month">${esc(t('Every month, on the 1st'))}</option><option value="once">${esc(t('Once'))}</option></select>`
        + `<select data-f="dow">${days}</select><input data-f="date" type="date" hidden><input data-f="time" type="time" value="08:00">`
        + `<button class="btn small primary" data-f="ok">${esc(t('Schedule'))}</button><span class="muted">${esc(t('Same instructions, model, effort and cap; the report comes in the chat and in the library.'))}</span>`;
      const f = n => p.querySelector(`[data-f=${n}]`);
      const sync = () => { f('dow').hidden = f('rep').value !== 'week'; f('date').hidden = f('rep').value !== 'once'; };
      f('rep').onchange = sync; sync();
      f('date').value = new Date(Date.now() + 864e5).toISOString().slice(0, 10);
      f('ok').onclick = async () => {
        const [hh, mm] = (f('time').value || '08:00').split(':').map(Number), rep = f('rep').value;
        const body = rep === 'once' ? { at: `${f('date').value} ${f('time').value || '08:00'}` }
          : { cron: `${mm} ${hh} ${rep === 'month' ? 1 : '*'} * ${rep === 'week' ? f('dow').value : rep === 'weekdays' ? '1-5' : '*'}` };
        try { const r = await post(`api/jobs/${j.id}/schedule`, body); toast(t('Routine made: next run {when}. Pause or delete it in Routines.', { when: new Date(r.task.next_run * 1000).toLocaleString(LOCALE, { dateStyle: 'medium', timeStyle: 'short' }) })); loadJobs(); } catch (e) { toast(e.message); }
      };
      el.append(p);
    });
    el.querySelector('[data-a=continue]')?.addEventListener('click', async () => {
      const effort = el.querySelector('.effort-select')?.value || '', max_usd = parseFloat(el.querySelector('.cap input').value);
      try {
        const r = await post(`api/jobs/${j.id}/continue`, { effort, max_usd: isNaN(max_usd) ? null : max_usd });
        toast(`Job #${r.id} carries on from where #${j.id} stopped: the report will come in the chat.`); loadJobs();
      } catch (e) { toast(e.message); }
    });
    el.querySelector('[data-a=rerun]')?.addEventListener('click', async () => {
      const tier = el.querySelector('.tier-select').value, effort = el.querySelector('.effort-select')?.value || '', max_usd = parseFloat(el.querySelector('.cap input').value);
      try {
        const r = await post(`api/jobs/${j.id}/rerun`, { tier, effort, max_usd: isNaN(max_usd) ? null : max_usd });
        toast(`Running again (job #${r.id}, ${tier} tier${effort ? ', effort ' + (EFFORTS[effort] || effort) : ''}): the report will come in the chat.`); loadJobs();
      } catch (e) { toast(e.message); }
    });
    box.append(el);
  }
  clearTimeout(loadJobs.t);
  if (jobs.some(j => ['queued', 'running'].includes(j.status)) && location.hash === '#jobs') loadJobs.t = setTimeout(() => { if (!document.querySelector('.tag-input')) loadJobs().catch(() => {}); }, 4000);
}

// ---- rich text editor: edits like a document, stores Markdown ----
const RTE_BLOCKS = new Set(['P', 'DIV', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'UL', 'OL', 'BLOCKQUOTE', 'PRE', 'HR', 'TABLE', 'SECTION', 'ARTICLE', 'HEADER', 'FOOTER', 'FIGURE', 'MAIN', 'ASIDE', 'NAV', 'DL']);
/** HTML (the editor, or something pasted) → Markdown that md() renders back the same way. */
function htmlToMd(root) {
  const escText = t => t.replace(/\\/g, '\\\\').replace(/([*`[\]]|~~)/g, '\\$1');
  const wrap = (mark, s) => { const m = s.match(/^(\s*)([\s\S]*?)(\s*)$/); return m[2] ? `${m[1]}${mark}${m[2]}${mark}${m[3]}` : s; };
  const inline = node => { let s = ''; for (const n of node.childNodes) s += inl(n); return s; };
  const inl = n => {
    if (n.nodeType === 3) return escText(n.nodeValue.replace(/ /g, ' ').replace(/[ \t\r\n]+/g, ' '));
    if (n.nodeType !== 1) return '';
    switch (n.tagName) {
      case 'B': case 'STRONG': return wrap('**', inline(n));
      case 'I': case 'EM': return wrap('*', inline(n));
      case 'S': case 'STRIKE': case 'DEL': return wrap('~~', inline(n));
      case 'CODE': case 'KBD': case 'TT': return '`' + n.textContent.replace(/`/g, "'") + '`';
      case 'BR': return '\n';
      case 'A': {
        const href = n.classList.contains('cite') ? `/files/${n.dataset.file}${n.dataset.page ? '#p' + n.dataset.page : ''}` : n.getAttribute('href') || '';
        const text = inline(n).trim() || href;
        if (!/^(https?:|\/files\/|tel:|sms:|mailto:|geo:|spotify:|intent:|deezer:|tg:|sgnl:|uber:|homeassistant:)/.test(href)) return text;
        return `[${n.classList.contains('action') ? '▶ ' : ''}${text.replace(/[[\]]/g, '')}](${href.replace(/ /g, '%20').replace(/\)/g, '%29')})`;
      }
      case 'SPAN': return n.classList.contains('check') ? '' : inline(n);
      case 'IMG': case 'SVG': case 'svg': case 'STYLE': case 'SCRIPT': case 'BUTTON': return '';
      default: return RTE_BLOCKS.has(n.tagName) || n.tagName === 'LI' ? '\n' + block(n) + '\n' : inline(n);
    }
  };
  const para = s => s.split('\n').map(l => l.trim()).filter((l, i, a) => l || (i > 0 && i < a.length - 1))
    .map(l => l.replace(/^(#|[-+*]\s|&gt;|>|\d+[.)]\s)/, m => '\\' + m)).join('\n').replace(/\n{2,}/g, '\n');
  const blocks = node => {
    const parts = []; let text = '';
    const flush = () => { const p = para(text); if (p.trim()) parts.push(p); text = ''; };
    for (const n of node.childNodes) {
      if (n.nodeType === 1 && (RTE_BLOCKS.has(n.tagName) || n.tagName === 'LI')) { flush(); const b = block(n); if (b.trim()) parts.push(b); }
      else text += inl(n);
    }
    flush();
    return parts.join('\n\n');
  };
  const list = (n, depth) => {
    const ordered = n.tagName === 'OL'; let i = +(n.getAttribute('start') || 1); const lines = [];
    for (const li of n.children) {
      if (li.tagName === 'UL' || li.tagName === 'OL') { lines.push(list(li, depth + 1)); continue; }
      if (li.tagName !== 'LI') continue;
      let text = ''; const subs = [];
      for (const c of li.childNodes) {
        if (c.nodeType === 1 && (c.tagName === 'UL' || c.tagName === 'OL')) subs.push(list(c, depth + 1));
        else if (c.nodeType === 1 && RTE_BLOCKS.has(c.tagName)) text += ' ' + inline(c);
        else text += inl(c);
      }
      const task = li.classList.contains('chk') ? (li.dataset.done === '1' ? '[x] ' : '[ ] ') : '';
      lines.push('   '.repeat(depth) + (ordered ? `${i++}. ` : '- ') + task + text.replace(/\s*\n\s*/g, ' ').trim());
      lines.push(...subs);
    }
    return lines.join('\n');
  };
  const block = n => {
    switch (n.tagName) {
      case 'H1': case 'H2': case 'H3': return '# ' + inline(n).replace(/\s+/g, ' ').trim();
      case 'H4': return '## ' + inline(n).replace(/\s+/g, ' ').trim();
      case 'H5': case 'H6': return '### ' + inline(n).replace(/\s+/g, ' ').trim();
      case 'UL': case 'OL': return list(n, 0);
      case 'LI': return '- ' + inline(n).trim();
      case 'BLOCKQUOTE': return blocks(n).split('\n').map(l => l ? '> ' + l : '>').join('\n');
      case 'PRE': return '```\n' + n.textContent.replace(/\n$/, '') + '\n```';
      case 'HR': return '---';
      case 'TABLE': {
        const rows = [...n.querySelectorAll('tr')].map(tr => [...tr.children].map(td => inline(td).replace(/\s+/g, ' ').trim().replace(/\|/g, '\\|')));
        if (!rows.length) return '';
        const w = Math.max(...rows.map(r => r.length));
        const line = r => '| ' + Array.from({ length: w }, (_, k) => r[k] ?? '').join(' | ') + ' |';
        return [line(rows[0]), '|' + ' --- |'.repeat(w), ...rows.slice(1).map(line)].join('\n');
      }
      default: return blocks(n);
    }
  };
  return blocks(root).replace(/\n{3,}/g, '\n\n').trim();
}

/** A Markdown editor that looks like the result. rte.value / rte.value = '...' */
function makeRte(box) {
  box.innerHTML = `<div class="rte-bar" role="toolbar" aria-label="Formatting">
      <button type="button" data-c="bold" title="Bold (Ctrl+B)"><b>B</b></button><button type="button" data-c="italic" title="Italic (Ctrl+I)"><i>I</i></button>
      <button type="button" data-c="strike" title="Strikethrough"><s>S</s></button><button type="button" data-c="h" title="Heading">H</button>
      <span class="sep"></span><button type="button" data-c="ul" title="List">•</button><button type="button" data-c="ol" title="Numbered list">1.</button>
      <button type="button" data-c="task" title="Checklist">☑</button><button type="button" data-c="quote" title="Quote">❝</button>
      <button type="button" data-c="code" title="Code">&lt;/&gt;</button><button type="button" data-c="link" title="Link">🔗</button>
      <span class="spacer"></span><button type="button" data-c="src" title="Edit the Markdown source" class="src">MD</button></div>
    <div class="rte-area doc" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Description" data-placeholder="Details, steps, links… (checklists count as progress)"></div>
    <textarea class="rte-src" hidden aria-label="Description (Markdown)"></textarea>`;
  const area = box.querySelector('.rte-area'), src = box.querySelector('.rte-src');
  let srcMode = false;
  const load = markdown => {
    area.innerHTML = md(markdown || '');
    area.querySelectorAll('li > span.check:first-child').forEach(s => {
      const li = s.parentElement; li.classList.add('chk'); li.dataset.done = s.classList.contains('done') ? '1' : '0';
      if (s.nextSibling?.nodeType === 3) s.nextSibling.nodeValue = s.nextSibling.nodeValue.replace(/^\s+/, '');
      s.remove();
    });
    area.querySelectorAll('a').forEach(a => a.setAttribute('contenteditable', 'false'));
    box.classList.toggle('empty', !markdown);
  };
  const exec = (c, v = null) => { area.focus(); document.execCommand(c, false, v); };
  const blockOf = () => { const n = getSelection().anchorNode; const el = n && (n.nodeType === 1 ? n : n.parentElement); return el && area.contains(el) ? el : null; };
  try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch {}
  box.querySelector('.rte-bar').addEventListener('mousedown', e => { if (e.target.closest('button') && !e.target.closest('.src')) e.preventDefault(); }); // keep the selection
  box.querySelector('.rte-bar').addEventListener('click', e => {
    const b = e.target.closest('button'); if (!b) return;
    const el = blockOf();
    switch (b.dataset.c) {
      case 'bold': exec('bold'); break;
      case 'italic': exec('italic'); break;
      case 'strike': exec('strikeThrough'); break;
      case 'h': exec('formatBlock', el?.closest('h3,h4,h5') ? 'p' : 'h3'); break;
      case 'ul': exec('insertUnorderedList'); break;
      case 'ol': exec('insertOrderedList'); break;
      case 'quote': exec('formatBlock', el?.closest('blockquote') ? 'p' : 'blockquote'); break;
      case 'task': {
        let li = el?.closest('li');
        if (!li) { exec('insertUnorderedList'); li = blockOf()?.closest('li'); if (li) { li.classList.add('chk'); li.dataset.done = '0'; } }
        else { const on = !li.classList.contains('chk'); li.parentElement.querySelectorAll(':scope > li').forEach(x => { x.classList.toggle('chk', on); x.dataset.done = x.dataset.done || '0'; }); }
        break;
      }
      case 'code': { const t = getSelection().toString(); if (t.includes('\n')) exec('insertHTML', `<pre><code>${esc(t)}</code></pre><p><br></p>`); else exec('insertHTML', `<code>${esc(t || 'code')}</code>&nbsp;`); break; }
      case 'link': {
        const url = prompt('Link address (https://…)', 'https://');
        if (url && /^(https?:|mailto:|tel:)/i.test(url.trim())) { if (getSelection().isCollapsed) exec('insertHTML', `<a href="${esc(url.trim())}">${esc(url.trim())}</a>&nbsp;`); else exec('createLink', url.trim()); }
        break;
      }
      case 'src':
        srcMode = !srcMode;
        if (srcMode) { src.value = htmlToMd(area); src.style.height = Math.max(area.offsetHeight, 140) + 'px'; } else load(src.value);
        area.hidden = srcMode; src.hidden = !srcMode; b.classList.toggle('on', srcMode);
        box.querySelectorAll('.rte-bar button:not(.src)').forEach(x => x.disabled = srcMode);
        (srcMode ? src : area).focus();
        break;
    }
    box.classList.remove('empty');
  });
  area.addEventListener('click', e => { // tick a checklist item
    const li = e.target.closest('li.chk');
    if (li && e.target === li && e.offsetX < 22) { li.dataset.done = li.dataset.done === '1' ? '0' : '1'; }
  });
  area.addEventListener('keyup', e => {
    if (e.key === 'Enter') { const li = blockOf()?.closest('li.chk'); if (li && !li.textContent.trim()) li.dataset.done = '0'; }
  });
  area.addEventListener('input', () => box.classList.toggle('empty', !area.textContent.trim() && !area.querySelector('li,hr,table')));
  area.addEventListener('paste', e => { // keep the structure of what is pasted, never its scripts or styles
    e.preventDefault();
    const html = e.clipboardData.getData('text/html'), text = e.clipboardData.getData('text/plain');
    if (html) {
      const doc = new DOMParser().parseFromString(html, 'text/html');
      exec('insertHTML', md(htmlToMd(doc.body)));
    } else exec('insertText', text);
  });
  return {
    get value() { return srcMode ? src.value.trim() : htmlToMd(area); },
    set value(v) { srcMode = false; area.hidden = false; src.hidden = true; box.querySelector('.src').classList.remove('on'); box.querySelectorAll('.rte-bar button').forEach(x => x.disabled = false); load(v); },
  };
}

// ---- tasks board ----
const PRIO = { 1: 'Critical', 2: 'High', 3: 'Normal', 4: 'Low', 5: 'Someday' };
const BICON = {
  due: '<svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M9 3v4M15 3v4"/></svg>',
  load: '<svg viewBox="0 0 24 24"><circle cx="12" cy="13" r="7"/><path d="M12 10v3l2 1M10 3h4"/></svg>',
  check: '<svg viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="3"/><path d="M8 12l3 3 5-6"/></svg>',
  lock: '<svg viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>',
  warn: '<svg viewBox="0 0 24 24"><path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17v.5"/></svg>',
  cal: '<svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M8 14h3"/></svg>',
};
const boardPrefs = (() => { try { return JSON.parse(localStorage.getItem(LSK + 'talixio.board') || '{}'); } catch { return {}; } })();
const saveBoardPrefs = () => { try { localStorage.setItem(LSK + 'talixio.board', JSON.stringify(boardPrefs)); } catch {} };
const fmtH = h => h == null ? '' : h === 0 ? '0h' : h >= 7 && h % 7 === 0 ? `${h / 7}d` : h < 1 ? `${Math.round(h * 60)}m` : `${+h.toFixed(2)}h`;
const fmtDay = d => { if (!d) return ''; const t = new Date(d + 'T12:00:00'); return t.toLocaleDateString(LOCALE, { day: 'numeric', month: 'short', ...(t.getFullYear() !== new Date().getFullYear() ? { year: 'numeric' } : {}) }); };
const today = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };
const dueClass = c => { if (!c.due_date || c.status === 'done') return ''; const t = today(); if (c.due_date < t) return 'late'; const soon = new Date(); soon.setDate(soon.getDate() + 3); return c.due_date <= soon.toISOString().slice(0, 10) ? 'soon' : ''; };
const board = { cards: [], refs: {}, statuses: {} };

async function loadBoard() {
  if (!state.spaces.length) await loadSpaces();
  if (boardPrefs.space === undefined) boardPrefs.space = String(state.space);
  const sel = $('#board-space');
  sel.innerHTML = `<option value="">All spaces</option>` + state.spaces.filter(x => !x.archived || String(x.id) === boardPrefs.space).map(x => `<option value="${x.id}">${esc(x.name)}</option>`).join('');
  if (![...sel.options].some(o => o.value === boardPrefs.space)) boardPrefs.space = '0';
  sel.value = boardPrefs.space;
  sel.onchange = () => { boardPrefs.space = sel.value; boardPrefs.tag = ''; saveBoardPrefs(); loadBoard(); };
  const mode = ['overall', 'calendar'].includes(boardPrefs.mode) ? boardPrefs.mode : 'board';
  document.querySelectorAll('#view-board .seg button').forEach(b => { b.classList.toggle('on', b.dataset.mode === mode); b.setAttribute('aria-selected', b.dataset.mode === mode); b.onclick = () => { boardPrefs.mode = b.dataset.mode; saveBoardPrefs(); loadBoard(); }; });
  $('#board-q').value = boardPrefs.q || '';
  $('#board-q').oninput = () => { clearTimeout(loadBoard.qt); loadBoard.qt = setTimeout(() => { boardPrefs.q = $('#board-q').value.trim(); saveBoardPrefs(); loadBoard(); }, 300); };
  $('#board-new').onclick = () => openCard(null, { space: boardPrefs.space === '' ? state.space : +boardPrefs.space });
  $('#board-trash').onclick = showBoardTrash;
  $('#board-changes').onclick = showBoardChanges;
  const sp = boardPrefs.space === '' ? '' : `space=${boardPrefs.space}&`;
  const q = new URLSearchParams(); if (boardPrefs.tag) q.set('tag', boardPrefs.tag); if (boardPrefs.q) q.set('q', boardPrefs.q);
  if (mode === 'overall') await renderOverall(await api('api/board/portfolio')); // arbitration is across all spaces
  else if (mode === 'calendar') await renderCalendar();
  else {
    const d = await api(`api/board?${sp}${q}`);
    Object.assign(board, d);
    const names = Object.keys(d.tags || {});
    if (boardPrefs.tag && !names.includes(boardPrefs.tag)) names.unshift(boardPrefs.tag);
    $('#board-tags').innerHTML = names.map(t => `<button type="button" class="tag${t === boardPrefs.tag ? ' on' : ''}" data-tag="${esc(t)}">#${esc(t)} <small>${d.tags[t] || 0}</small></button>`).join('');
    $('#board-tags').querySelectorAll('.tag').forEach(b => b.onclick = () => { boardPrefs.tag = boardPrefs.tag === b.dataset.tag ? '' : b.dataset.tag; saveBoardPrefs(); loadBoard(); });
    renderBoard();
  }
  $('#board-tags').hidden = mode !== 'board'; sel.hidden = mode === 'overall';
  $('#board-q').hidden = mode !== 'board';
  clearTimeout(loadBoard.t);
  loadBoard.t = setTimeout(() => { if (location.hash.startsWith('#board') && !$('#card-dialog').open && !document.querySelector('.kcard.dragging, .kquick:focus')) loadBoard().catch(() => {}); }, 30000);
}

function cardHtml(c, opts = {}) {
  const sp = spaceOf(c.space), waits = c.depends_on.filter(d => board.refs[d] && board.refs[d].status !== 'done' && !board.refs[d].deleted_at);
  const warn = c.warnings.filter(w => w.kind !== 'waiting' && w.kind !== 'overdue');
  const bits = [];
  if (c.due_date) bits.push(`<span class="kb ${dueClass(c)}" title="Due ${esc(c.due_date)}${c.start_date ? ', starts ' + esc(c.start_date) : ''}">${BICON.due}${fmtDay(c.due_date)}</span>`);
  if (c.estimate_hours != null || c.spent_hours) bits.push(`<span class="kb${c.estimate_hours && c.spent_hours > c.estimate_hours ? ' late' : ''}" title="Workload${c.spent_hours ? ': ' + fmtH(c.spent_hours) + ' spent' : ''}${c.estimate_hours != null ? ' of ' + fmtH(c.estimate_hours) + ' planned' : ''}">${BICON.load}${c.spent_hours ? fmtH(c.spent_hours) + '/' : ''}${fmtH(c.estimate_hours) || '?'}</span>`);
  if (c.checklist.total) bits.push(`<span class="kb${c.checklist.done === c.checklist.total ? ' ok' : ''}" title="Checklist">${BICON.check}${c.checklist.done}/${c.checklist.total}</span>`);
  if (waits.length) bits.push(`<span class="kb wait" title="Waits for ${esc(waits.map(d => board.refs[d].code + ' ' + board.refs[d].title).join(', '))}">${BICON.lock}${esc(waits.map(d => board.refs[d].code).join(', '))}</span>`);
  if (warn.length) bits.push(`<span class="kb late" title="${esc(warn.map(w => w.text).join('\n'))}">${BICON.warn}</span>`);
  if (c.in_calendar) bits.push(`<span class="kb" title="Shown in your calendar">${BICON.cal}</span>`);
  return `<div class="kc-top"><span class="code">${esc(c.code)}</span>${opts.space ? `<span class="kc-space"><i style="background:${esc(sp.color || 'var(--muted)')}"></i>${esc(sp.name)}</span>` : ''}<span class="prio p${c.priority}" title="P${c.priority} · ${PRIO[c.priority]}">P${c.priority}</span></div>
    <div class="kc-title">${esc(c.title)}</div>${bits.length ? `<div class="kc-meta">${bits.join('')}</div>` : ''}${c.tags.length ? `<div class="kc-tags">${c.tags.map(t => `<span>#${esc(t)}</span>`).join('')}</div>` : ''}`;
}

function renderBoard() {
  const root = $('#board'); root.className = 'board'; root.innerHTML = '';
  $('#board-summary').hidden = true;
  const all = boardPrefs.space === '';
  for (const [st, label] of Object.entries(board.statuses)) {
    const cards = board.cards.filter(c => c.status === st);
    const load = cards.reduce((a, c) => a + (c.estimate_hours || 0), 0);
    const col = document.createElement('section');
    col.className = `kcol s-${st}`; col.dataset.status = st;
    col.innerHTML = `<header><b>${label}</b><span class="muted small">${cards.length}${load ? ' · ' + fmtH(load) : ''}</span></header><div class="kcards"></div>`
      + (all ? '' : `<input class="kquick" placeholder="+ Add a task" aria-label="Add a task to ${esc(label)}" maxlength="200">`);
    const list = col.querySelector('.kcards');
    for (const c of cards) {
      const el = document.createElement('article');
      el.className = `kcard p${c.priority}`; el.dataset.id = c.id; el.draggable = true; el.tabIndex = 0;
      el.innerHTML = cardHtml(c, { space: all });
      el.onclick = () => openCard(c.id);
      el.onkeydown = e => { if (e.key === 'Enter') openCard(c.id); };
      el.ondragstart = e => { el.classList.add('dragging'); e.dataTransfer.setData('text/plain', String(c.id)); e.dataTransfer.effectAllowed = 'move'; };
      el.ondragend = () => { el.classList.remove('dragging'); document.querySelectorAll('.kdrop').forEach(x => x.remove()); };
      list.append(el);
    }
    if (!cards.length) list.innerHTML = `<p class="kempty muted small">${st === 'idea' ? 'Ideas for later' : st === 'blocked' ? 'Nothing blocked' : st === 'done' ? 'Done in the last 2 weeks' : 'Nothing here'}</p>`;
    // drag and drop between and within columns
    const marker = () => { let m = col.querySelector('.kdrop'); if (!m) { m = document.createElement('div'); m.className = 'kdrop'; } return m; };
    const beforeEl = y => [...list.querySelectorAll('.kcard:not(.dragging)')].find(k => { const r = k.getBoundingClientRect(); return y < r.top + r.height / 2; });
    col.ondragover = e => { e.preventDefault(); const b = beforeEl(e.clientY); const m = marker(); document.querySelectorAll('.kdrop').forEach(x => x !== m && x.remove()); b ? list.insertBefore(m, b) : list.append(m); };
    col.ondragleave = e => { if (!col.contains(e.relatedTarget)) col.querySelector('.kdrop')?.remove(); };
    col.ondrop = async e => {
      e.preventDefault();
      const id = +e.dataTransfer.getData('text/plain'); const b = beforeEl(e.clientY);
      col.querySelector('.kdrop')?.remove();
      if (!id) return;
      try { await post(`api/board/${id}/move`, { status: st, before: b ? +b.dataset.id : null }); } catch (err) { toast(err.message); }
      loadBoard();
    };
    const quick = col.querySelector('.kquick');
    if (quick) quick.onkeydown = async e => {
      if (e.key !== 'Enter' || !quick.value.trim()) return;
      try { const { card } = await post('api/board', { space: +boardPrefs.space, title: quick.value.trim(), status: st }); quick.value = ''; toast(`${card.code} added`); await loadBoard(); root.querySelector(`.kcol.s-${st} .kquick`)?.focus(); }
      catch (err) { toast(err.message); }
    };
    root.append(col);
  }
  if (!board.cards.length && !boardPrefs.q && !boardPrefs.tag) {
    $('#board-summary').hidden = false;
    $('#board-summary').innerHTML = '<p class="muted small">No tasks yet. Add one in a column, or ask in the chat: <q>add a task: repaint the kitchen before December, about 2 days</q>.</p>';
  }
}

/** General's view: every open task, in the overall order you set; workload per space. */
async function renderOverall(d) {
  board.refs = d.refs;
  const root = $('#board'); root.className = 'overall'; root.innerHTML = '';
  const ranked = d.cards.filter(c => c.global_rank != null), rest = d.cards.filter(c => c.global_rank == null);
  const per = {};
  for (const c of d.cards) { const p = per[c.space] ||= { n: 0, h: 0, late: 0 }; p.n++; p.h += Math.max(0, (c.estimate_hours || 0) - (c.spent_hours || 0)); if (dueClass(c) === 'late') p.late++; }
  const sum = $('#board-summary'); sum.hidden = !d.cards.length;
  sum.innerHTML = Object.entries(per).map(([s, p]) => { const sp = spaceOf(+s); return `<span class="chip"><i class="space-dot" style="background:${esc(sp.color || 'var(--muted)')}"></i>${esc(sp.name)}: ${p.n} open${p.h ? ' · ' + fmtH(Math.round(p.h * 4) / 4) + ' left' : ''}${p.late ? ` · <b class="late">${p.late} late</b>` : ''}</span>`; }).join('');
  const save = async ids => { try { await post('api/board/order', { ids }); } catch (e) { toast(e.message); } loadBoard(); };
  const row = (c, i) => {
    const el = document.createElement('div'); el.className = 'orow' + (i == null ? ' unranked' : ''); el.dataset.id = c.id;
    el.draggable = i != null;
    el.innerHTML = `${i != null ? `<span class="grip" title="Drag to reorder">⋮⋮</span><b class="rank">${i + 1}</b>` : ''}<div class="ocard kcard p${c.priority}">${cardHtml(c, { space: true })}</div>
      <span class="oacts">${i != null ? `<button type="button" class="icon-btn" data-a="up" title="Up" aria-label="Up">↑</button><button type="button" class="icon-btn" data-a="down" title="Down" aria-label="Down">↓</button><button type="button" class="icon-btn" data-a="out" title="Take out of the order" aria-label="Take out of the order">×</button>`
        : '<button type="button" class="btn small" data-a="in">Add to the order</button>'}</span>`;
    el.querySelector('.ocard').onclick = () => openCard(c.id);
    const ids = ranked.map(x => x.id);
    el.querySelector('[data-a=up]')?.addEventListener('click', () => { if (i > 0) { [ids[i - 1], ids[i]] = [ids[i], ids[i - 1]]; save(ids); } });
    el.querySelector('[data-a=down]')?.addEventListener('click', () => { if (i < ids.length - 1) { [ids[i + 1], ids[i]] = [ids[i], ids[i + 1]]; save(ids); } });
    el.querySelector('[data-a=out]')?.addEventListener('click', async () => { try { await post(`api/board/${c.id}/unrank`); } catch (e) { toast(e.message); } loadBoard(); });
    el.querySelector('[data-a=in]')?.addEventListener('click', () => save([...ids, c.id]));
    if (i != null) {
      el.ondragstart = e => { el.classList.add('dragging'); e.dataTransfer.setData('text/plain', String(c.id)); };
      el.ondragend = () => el.classList.remove('dragging');
      el.ondragover = e => { e.preventDefault(); el.classList.add('over'); };
      el.ondragleave = () => el.classList.remove('over');
      el.ondrop = e => { e.preventDefault(); const from = +e.dataTransfer.getData('text/plain'); const next = ids.filter(x => x !== from); next.splice(next.indexOf(c.id), 0, from); save(next); };
    }
    return el;
  };
  const h = (t, p) => { const x = document.createElement('div'); x.className = 'ohead'; x.innerHTML = `<b>${t}</b><span class="muted small">${p}</span>`; return x; };
  const ask = document.createElement('button'); ask.type = 'button'; ask.className = 'btn small'; ask.textContent = 'Ask the assistant for a proposal';
  ask.onclick = async () => { location.hash = '#chat'; if (state.space !== 0) await switchSpace(0); $('#input').value = 'Look at all my open tasks across spaces and propose an overall order (deadlines, dependencies, priorities, workload). Explain briefly, and set it only once I agree.'; autosize(); $('#input').focus(); };
  root.append(ask);
  root.append(h('Overall order', ranked.length ? 'What to do first, across your spaces. Drag, or use the arrows. The assistant in General can propose an order: ask it to arbitrate.' : 'Nothing ordered yet. Add tasks from below, or ask the assistant in General: <q>arbitrate my tasks across spaces</q>.'));
  ranked.forEach((c, i) => root.append(row(c, i)));
  if (rest.length) { root.append(h('Not in the order', `${rest.length} open tasks, by priority and due date`)); rest.forEach(c => root.append(row(c, null))); }
  if (!d.cards.length) root.append(Object.assign(document.createElement('p'), { className: 'muted', textContent: 'No open tasks.' }));
}

let cardRte = null;
/** Open a task (id) or a new one (prefill: {space, status}). */
async function openCard(id, prefill = {}) {
  const dlg = $('#card-dialog');
  if (!cardRte) cardRte = makeRte($('#cd-rte'));
  let d = { card: { space: prefill.space ?? state.space, status: prefill.status || 'todo', priority: 3, title: prefill.title || '', body: prefill.body || '', due_date: prefill.due_date || null,
    tags: [], links: prefill.links || [], depends_on: [], blocks: [], in_calendar: 0 }, history: [], time: [], depends_on: [], blocks: [] };
  if (id) { try { d = await api(`api/board/${id}`); } catch (e) { toast(e.message); return; } }
  const c = d.card, isNew = !id;
  let deps = d.depends_on.slice();
  $('#cd-code').textContent = isNew ? 'New task' : c.code;
  $('#cd-space').innerHTML = state.spaces.filter(x => !x.archived || x.id === c.space).map(x => `<option value="${x.id}">${esc(x.name)}</option>`).join('');
  $('#cd-space').value = String(c.space);
  $('#cd-space').title = 'Space';
  $('#cd-title').value = c.title;
  $('#cd-status').innerHTML = Object.entries(board.statuses && Object.keys(board.statuses).length ? board.statuses : { idea: 'Ideas', todo: 'To do', doing: 'In progress', blocked: 'Blocked', done: 'Done' }).map(([k, v]) => `<option value="${k}">${v}</option>`).join('');
  $('#cd-status').value = c.status; $('#cd-priority').value = String(c.priority);
  $('#cd-start').value = c.start_date || ''; $('#cd-due').value = c.due_date || '';
  $('#cd-estimate').value = c.estimate_hours != null ? fmtH(c.estimate_hours) : '';
  $('#cd-cal').checked = !!c.in_calendar;
  $('#cd-tags').value = (c.tags || []).join(', ');
  cardRte.value = c.body || '';
  const warn = (c.warnings || []);
  $('#cd-warn').hidden = !warn.length; $('#cd-warn').innerHTML = warn.map(w => `<div>${BICON.warn}<span>${esc(w.text)}</span></div>`).join('');
  const paintDeps = () => {
    $('#cd-deps').innerHTML = deps.map(x => { const sp = spaceOf(x.space); return `<span class="dep${x.status === 'done' ? ' done' : ''}" title="${esc(sp.name)} · ${esc(board.statuses?.[x.status] || x.status)}"><i style="background:${esc(sp.color || 'var(--muted)')}"></i><b>${esc(x.code)}</b> ${esc(x.title)}${x.status === 'done' ? ' ✓' : ''}<button type="button" data-id="${x.id}" aria-label="Remove">×</button></span>`; }).join('') || '<span class="muted small">Nothing: it can start any time.</span>';
    $('#cd-deps').querySelectorAll('button').forEach(b => b.onclick = () => { deps = deps.filter(x => x.id !== +b.dataset.id); paintDeps(); });
  };
  paintDeps();
  $('#cd-blocks').innerHTML = (d.blocks.length ? 'Blocks: ' + d.blocks.map(x => `<b>${esc(x.code)}</b> ${esc(x.title)}`).join(', ') : '')
    + (c.links?.length ? `<div class="cd-links">${c.links.map(l => l.type === 'email' ? `<a href="#mail">✉ ${esc(l.label || 'email')}</a>` : l.type === 'file' ? `<a href="#" class="cite" data-file="${+l.ref}">${CITE_SVG}${esc(l.label || 'document')}</a>` : `<span>${esc(l.label || '')}</span>`).join('')}</div>` : '');
  $('#cd-blocks').querySelector('a[href="#mail"]')?.addEventListener('click', () => dlg.close());
  // dependency search
  const q = $('#cd-dep-q'), hits = $('#cd-dep-hits'); q.value = ''; hits.hidden = true;
  q.oninput = () => { clearTimeout(q.t); q.t = setTimeout(async () => {
    const term = q.value.trim(); if (!term) { hits.hidden = true; return; }
    try {
      const { cards } = await api('api/board/find?q=' + encodeURIComponent(term));
      const list = cards.filter(x => x.id !== c.id && !deps.some(y => y.id === x.id) && !x.deleted_at);
      hits.innerHTML = list.length ? list.map(x => `<button type="button" data-id="${x.id}"><b>${esc(x.code)}</b> ${esc(x.title)} <span class="muted small">${esc(spaceOf(x.space).name)}</span></button>`).join('') : '<p class="muted small">No task found.</p>';
      hits.hidden = false;
      hits.querySelectorAll('button').forEach(b => b.onclick = () => { deps.push(list.find(x => x.id === +b.dataset.id)); paintDeps(); q.value = ''; hits.hidden = true; q.focus(); });
    } catch {}
  }, 200); };
  q.onkeydown = e => { if (e.key === 'Enter') { e.preventDefault(); hits.querySelector('button')?.click(); } if (e.key === 'Escape') { e.stopPropagation(); hits.hidden = true; } };
  // time and history (existing tasks)
  $('#cd-time').hidden = $('#cd-history').hidden = $('#cd-delete').hidden = isNew;
  const paintTime = (card, entries) => {
    $('#cd-time-sum').textContent = card.spent_hours || card.estimate_hours ? `· ${fmtH(card.spent_hours) || '0h'}${card.estimate_hours ? ' of ' + fmtH(card.estimate_hours) : ''}` : '';
    $('#cd-time-list').innerHTML = entries.map(t => `<div class="trow"><span>${fmtDay(t.day)}</span><b>${fmtH(+t.hours)}</b><span class="muted">${esc(t.note)}</span><button type="button" class="icon-btn" data-t="${t.id}" aria-label="Remove">×</button></div>`).join('');
    $('#cd-time-list').querySelectorAll('[data-t]').forEach(b => b.onclick = async () => { await post(`api/board/time/${b.dataset.t}/delete`); const r = await api(`api/board/${c.id}`); paintTime(r.card, r.time); });
  };
  if (!isNew) {
    paintTime(c, d.time);
    $('#cd-time-day').value = today(); $('#cd-time-h').value = ''; $('#cd-time-note').value = '';
    $('#cd-time-add').onclick = async () => {
      try { await post(`api/board/${c.id}/time`, { hours: $('#cd-time-h').value, day: $('#cd-time-day').value, note: $('#cd-time-note').value }); const r = await api(`api/board/${c.id}`); paintTime(r.card, r.time); $('#cd-time-h').value = $('#cd-time-note').value = ''; c.version = r.card.version; }
      catch (e) { toast(e.message); }
    };
    $('#cd-history-list').innerHTML = d.history.map(h => `<div class="hrow"><span class="muted small">${new Date(h.ts * 1000).toLocaleString(LOCALE, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</span><span>${esc(h.what)}</span><span class="muted small">${esc(h.who)}</span>${h.can_undo ? `<button type="button" class="btn small ghost" data-h="${h.id}">Undo</button>` : '<span></span>'}</div>`).join('');
    $('#cd-history-list').querySelectorAll('[data-h]').forEach(b => b.onclick = async () => { try { await post(`api/board/undo/${b.dataset.h}`); toast('Undone'); dlg.close(); loadBoard(); openCard(c.id); } catch (e) { toast(e.message); } });
  }
  $('#cd-time').open = false; $('#cd-history').open = false;
  const close = () => { dlg.close(); };
  $('#cd-close').onclick = $('#cd-cancel').onclick = close;
  $('#cd-delete').dataset.confirm = ''; $('#cd-delete').textContent = 'Move to trash';
  $('#cd-delete').onclick = async e => {
    const b = e.currentTarget;
    if (b.dataset.confirm !== '1' && c.blocks?.length) { b.dataset.confirm = '1'; b.textContent = `${c.blocks.length} task(s) wait for it: delete anyway?`; return; }
    try { const r = await post(`api/board/${c.id}/delete`); close(); toast(`${c.code} moved to the trash` + (r.waiting.length ? ` (${r.waiting.join(', ')} waited for it)` : '')); loadBoard(); } catch (err) { toast(err.message); }
  };
  $('#card-form').onsubmit = async e => {
    e.preventDefault();
    const body = { title: $('#cd-title').value, body: cardRte.value, status: $('#cd-status').value, priority: +$('#cd-priority').value, start_date: $('#cd-start').value,
      due_date: $('#cd-due').value, estimate_hours: $('#cd-estimate').value.trim(), in_calendar: $('#cd-cal').checked, tags: $('#cd-tags').value,
      space: +$('#cd-space').value, depends_on: deps.map(x => x.id), ...(isNew && c.links?.length ? { links: c.links } : {}) };
    try {
      if (isNew) { const r = await post('api/board', body); toast(`${r.card.code} added`); }
      else await post(`api/board/${c.id}`, { ...body, version: c.version });
      close(); loadBoard();
    } catch (err) {
      if (err.status === 409) { if (confirm(err.message + '\n\nReload it? (your changes in this window will be lost)')) { close(); openCard(c.id); } }
      else toast(err.message);
    }
  };
  dlg.showModal();
  if (isNew) $('#cd-title').focus(); else dlg.focus(); // no keyboard popping up on phones
}

/** Month grid (a list of days on phones): tasks on their due date (and start), your calendar's events if wanted. */
async function renderCalendar() {
  const iso = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  const now = new Date();
  if (!/^\d{4}-\d{2}$/.test(boardPrefs.month || '')) boardPrefs.month = iso(now).slice(0, 7);
  const [y, mo] = boardPrefs.month.split('-').map(Number);
  const first = new Date(y, mo - 1, 1), last = new Date(y, mo, 0);
  const gridStart = new Date(first); gridStart.setDate(1 - ((first.getDay() + 6) % 7));
  const gridEnd = new Date(last); gridEnd.setDate(last.getDate() + (7 - ((last.getDay() + 6) % 7) - 1));
  const sp = boardPrefs.space === '' ? '' : `&space=${boardPrefs.space}`;
  const d = await api(`api/board/calendar?from=${iso(gridStart)}&to=${iso(gridEnd)}${sp}${boardPrefs.agenda ? '&events=1' : ''}`);
  board.refs = board.refs || {};
  const root = $('#board'); root.className = 'calendar'; root.innerHTML = '';
  $('#board-summary').hidden = true;
  const bar = document.createElement('div'); bar.className = 'cal-bar';
  bar.innerHTML = `<button type="button" class="icon-btn" data-m="-1" aria-label="Previous month">‹</button><b>${first.toLocaleDateString(LOCALE, { month: 'long', year: 'numeric' })}</b>
    <button type="button" class="icon-btn" data-m="1" aria-label="Next month">›</button><button type="button" class="btn small ghost" data-m="0">Today</button>
    <label class="small"><input type="checkbox" id="cal-agenda"${boardPrefs.agenda ? ' checked' : ''}${d.calendar ? '' : ' disabled'}> Show my agenda</label>
    <span class="spacer"></span><button type="button" class="btn small" id="cal-link">${BICON.cal} Calendar link</button>`;
  bar.querySelectorAll('[data-m]').forEach(b => b.onclick = () => {
    const k = +b.dataset.m; const t = k ? new Date(y, mo - 1 + k, 1) : now; boardPrefs.month = iso(t).slice(0, 7); saveBoardPrefs(); loadBoard();
  });
  bar.querySelector('#cal-agenda').onchange = e => { boardPrefs.agenda = e.target.checked; saveBoardPrefs(); loadBoard(); };
  bar.querySelector('#cal-link').onclick = () => showCalendarLink(d.google);
  root.append(bar);
  if (d.events_error) root.append(Object.assign(document.createElement('p'), { className: 'muted small', textContent: 'Your agenda could not be read: ' + d.events_error }));
  // what goes on each day
  const days = {};
  const put = (day, item) => (days[day] ||= []).push(item);
  for (const c of d.cards) {
    if (c.due_date) put(c.due_date, { c, kind: 'due' });
    if (c.start_date && c.start_date !== c.due_date) put(c.start_date, { c, kind: 'start' });
  }
  for (const e of d.events) {
    const s = new Date(e.start * 1000), en = new Date((e.end || e.start) * 1000 - (e.all_day ? 1000 : 0));
    for (const t = new Date(s.getFullYear(), s.getMonth(), s.getDate()); t <= en; t.setDate(t.getDate() + 1)) put(iso(t), { e, time: e.all_day ? '' : s.toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' }) });
  }
  const t0 = iso(now);
  const chip = it => {
    if (it.e) return `<div class="cal-ev" title="${esc(it.e.title)}${it.e.location ? ' · ' + esc(it.e.location) : ''}">${it.time ? `<span>${it.time}</span> ` : ''}${esc(it.e.title)}</div>`;
    const c = it.c, s = spaceOf(c.space), late = it.kind === 'due' && c.status !== 'done' && c.due_date < t0;
    return `<div class="cal-task p${c.priority}${c.status === 'done' ? ' done' : ''}${late ? ' late' : ''}${it.kind === 'start' ? ' start' : ''}" data-id="${c.id}" draggable="${it.kind === 'due'}" style="--sp:${esc(s.color || 'var(--muted)')}"
      title="${esc(c.code)} ${esc(c.title)} · ${it.kind === 'start' ? 'starts' : 'due'}${c.in_calendar ? ' · in your calendar' : ''}">${it.kind === 'start' ? '▸ ' : ''}<b>${esc(c.code)}</b> ${esc(c.title)}</div>`;
  };
  const sortDay = list => list.sort((a, b) => (a.e ? 0 : 1) - (b.e ? 0 : 1) || (a.c && b.c ? a.c.priority - b.c.priority : 0));
  const narrow = matchMedia('(max-width: 760px)').matches;
  if (narrow) { // agenda: days with something, from the start of the month (or today)
    const list = document.createElement('div'); list.className = 'cal-list';
    const late = d.cards.filter(c => c.due_date && c.due_date < t0 && c.status !== 'done');
    if (late.length && boardPrefs.month === t0.slice(0, 7)) list.innerHTML = `<h4 class="late">Overdue</h4>` + late.map(c => chip({ c, kind: 'due' })).join('');
    for (const t = new Date(first); t <= last; t.setDate(t.getDate() + 1)) {
      const k = iso(t); if (!days[k] || (k < t0 && boardPrefs.month === t0.slice(0, 7))) continue;
      list.innerHTML += `<h4${k === t0 ? ' class="today"' : ''}>${t.toLocaleDateString(LOCALE, { weekday: 'long', day: 'numeric', month: 'long' })}</h4>` + sortDay(days[k]).map(chip).join('');
    }
    if (!list.innerHTML) list.innerHTML = '<p class="muted">Nothing dated this month.</p>';
    root.append(list);
  } else {
    const grid = document.createElement('div'); grid.className = 'cal-grid';
    grid.innerHTML = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map(n => `<div class="cal-dow">${n}</div>`).join('');
    for (const t = new Date(gridStart); t <= gridEnd; t.setDate(t.getDate() + 1)) {
      const k = iso(t), cell = document.createElement('div');
      cell.className = 'cal-day' + (t.getMonth() !== mo - 1 ? ' other' : '') + (k === t0 ? ' today' : '') + ((t.getDay() + 6) % 7 > 4 ? ' weekend' : '');
      cell.dataset.day = k;
      cell.innerHTML = `<span class="cal-num">${t.getDate()}</span>` + sortDay(days[k] || []).map(chip).join('');
      cell.ondragover = e => { e.preventDefault(); cell.classList.add('over'); };
      cell.ondragleave = () => cell.classList.remove('over');
      cell.ondrop = async e => {
        e.preventDefault(); cell.classList.remove('over');
        const id = +e.dataTransfer.getData('text/plain'); const c = d.cards.find(x => x.id === id); if (!c || c.due_date === k) return;
        const shift = (Date.parse(k) - Date.parse(c.due_date)) / 864e5; const body = { due_date: k, version: c.version };
        if (c.start_date) { const s = new Date(c.start_date + 'T12:00:00'); s.setDate(s.getDate() + shift); body.start_date = iso(s); }
        try { await post(`api/board/${id}`, body); toast(`${c.code}: due ${fmtDay(k)}`); } catch (err) { toast(err.message); }
        loadBoard();
      };
      cell.onclick = e => { if (e.target === cell || e.target.classList.contains('cal-num')) openCard(null, { space: boardPrefs.space === '' ? state.space : +boardPrefs.space, due_date: k }); };
      grid.append(cell);
    }
    root.append(grid);
  }
  root.querySelectorAll('.cal-task').forEach(el => {
    el.onclick = () => openCard(+el.dataset.id);
    el.ondragstart = e => e.dataTransfer.setData('text/plain', el.dataset.id);
  });
}

async function showCalendarLink(google) {
  const { url } = await api('api/board/ics');
  $('#bs-title').textContent = 'Tasks in your calendar';
  $('#bs-list').innerHTML = `<p class="small">Tasks with <b>Show in my calendar</b> ticked appear as all-day events, from their start to their due date. The others stay here.</p>
    ${google ? '<p class="small">Google Calendar is connected: they are added there automatically and kept up to date.</p>' : ''}
    <p class="small">${google ? 'For another calendar app' : 'To see them in your calendar app'} (Google, Apple, Outlook…), subscribe to this private link. Anyone with it can read those tasks: keep it to yourself.</p>
    <div class="row"><input id="ics-url" readonly value="${esc(url)}" style="flex:1;min-width:0"><button type="button" class="btn small" id="ics-copy">Copy</button></div>
    <p class="muted small">Google Calendar: Other calendars → + → From URL. Subscribed calendars refresh every few hours. <button type="button" class="btn small ghost" id="ics-reset">Make a new link</button> (the old one stops working)</p>`;
  $('#ics-copy').onclick = () => copyText($('#ics-url').value);
  $('#ics-reset').onclick = async () => { if (!confirm('Make a new link? Calendars subscribed to the old one will stop updating.')) return; await post('api/board/ics'); showCalendarLink(google); };
  $('#bs-close').onclick = () => $('#board-side').close();
  if (!$('#board-side').open) $('#board-side').showModal();
}

async function showBoardTrash() {
  const { cards } = await api('api/board/trash');
  $('#bs-title').textContent = 'Trash';
  $('#bs-list').innerHTML = cards.length ? '<p class="muted small">Deleted tasks stay here for 30 days.</p>' + cards.map(c => `<div class="hrow"><b>${esc(c.code)}</b><span>${esc(c.title)}</span><span class="muted small">${new Date(c.deleted_at * 1000).toLocaleDateString(LOCALE)}</span><button type="button" class="btn small" data-id="${c.id}">Restore</button></div>`).join('')
    : '<p class="muted">The trash is empty.</p>';
  $('#bs-list').querySelectorAll('[data-id]').forEach(b => b.onclick = async () => { try { await post(`api/board/${b.dataset.id}/restore`); toast('Restored'); showBoardTrash(); loadBoard(); } catch (e) { toast(e.message); } });
  $('#bs-close').onclick = () => $('#board-side').close();
  if (!$('#board-side').open) $('#board-side').showModal();
}

async function showBoardChanges() {
  const { changes } = await api('api/board/recent' + (boardPrefs.space !== '' ? '?space=' + boardPrefs.space : ''));
  $('#bs-title').textContent = 'Recent changes';
  $('#bs-list').innerHTML = changes.length ? changes.map(h => `<div class="hrow"><span class="muted small">${new Date(h.ts * 1000).toLocaleString(LOCALE, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</span><span><a href="#board" data-id="${h.card}"><b>${esc(h.code)}</b></a> ${esc(h.what)}</span><span class="muted small">${esc(h.who)}</span><span></span></div>`).join('')
    : '<p class="muted">No changes yet.</p>';
  $('#bs-list').querySelectorAll('a[data-id]').forEach(a => a.onclick = e => { e.preventDefault(); $('#board-side').close(); openCard(+a.dataset.id); });
  $('#bs-close').onclick = () => $('#board-side').close();
  if (!$('#board-side').open) $('#board-side').showModal();
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
  const reg = await navigator.serviceWorker.register('sw.js', { scope: './' });
  await navigator.serviceWorker.ready;
  const { key } = await api('api/push/key');
  const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToBytes(key) });
  await post('api/push/subscribe', sub.toJSON());
}
async function disablePush() {
  const sub = await pushSubscription();
  if (sub) { await post('api/push/unsubscribe', { endpoint: sub.endpoint }); await sub.unsubscribe(); }
}
async function loadChannels() {
  const { channels, notify_on } = await api('api/channels');
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
  try { d = await api('api/update'); }
  catch { if (state.updating) setTimeout(loadUpdate, 3000); return; } // the app restarts during an update
  const box = $('#update'); box.hidden = false;
  if (!d.installed) { box.innerHTML = `<div class="row between"><h3>Updates</h3><span class="version">${esc(t('Version {v}', { v: d.version?.label || '?' }))}</span></div><p class="muted small">Updates from the app work on an installation made with <code>git clone</code> + <code>install.sh</code>.</p>`; return; }
  const v = d.version || {}, st = d.status || {};
  const busy = ['updating', 'checking'].includes(st.state);
  if (state.updating && !busy && state.updateFrom && v.sha !== state.updateFrom) { toast('Updated. Reloading…'); setTimeout(() => location.reload(), 1200); return; }
  if (state.updating && !busy && ['failed', 'done', 'rolled_back'].includes(st.state)) { state.updating = false; if (st.state !== 'failed') { setTimeout(() => location.reload(), 1200); } }
  const when = t => t ? new Date(t * 1000).toLocaleString(LOCALE, { dateStyle: 'medium', timeStyle: 'short' }) : 'never';
  box.innerHTML = `<div class="row between"><h3>Updates</h3><span class="version">${esc(t('Version {v}', { v: v.number || '?' }))} · ${esc(v.short || '?')} · ${esc((v.date || '').slice(0, 10))}</span></div>
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
      await post('api/update/' + b.dataset.u);
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
    if (g.id === 'brain' && state.status?.claude_code) { // the simplest way: the Claude plan, signed in from here
      const r = document.createElement('div'); r.className = 'row wrap';
      r.innerHTML = `<button class="btn" type="button">${esc(t('Sign in with Claude'))}</button><span class="muted small">${esc(t('Uses your Claude Pro or Max plan; no API key needed.'))}</span>`;
      r.querySelector('button').onclick = claudeSignIn;
      el.querySelector('.fields').prepend(r);
    }
    if (g.id === 'search') { const m = document.createElement('div'); m.className = 'meaning'; m.id = 'meaning'; el.querySelector('.fields').prepend(m); loadMeaning(m).catch(e => { m.textContent = e.message; }); }
    if (g.id === 'brain' && location.hash === '#settings/brain') { el.open = true; setTimeout(() => el.scrollIntoView({ block: 'start' }), 50); }
    box.append(el);
  }
}
/** Settings → Search by meaning: pick who computes the vectors, see the index and what it costs here. */
async function loadMeaning(box = $('#meaning')) {
  if (!box) return;
  const m = await api('api/meaning');
  const problem = p => ({ 'no-key': t('needs its key'), 'no-ollama': m.shared ? t('the server\'s Ollama is off: ask the administrator') : t('Ollama is not installed (sudo ./install.sh --with-ollama)'),
    'not-downloaded': m.shared ? t('not downloaded on the server: ask the administrator') : t('downloaded when chosen') }[p] || '');
  const mem = p => p.local ? t('≈ {gb} GB while it computes', { gb: new Intl.NumberFormat(document.documentElement.lang || undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(p.memory_mb / 1024) }) : '';
  const idx = m.backend !== 'off' && m.index.total ? Math.round(100 * m.index.done / m.index.total) : 100;
  const pull = m.pull && m.pull.state === 'running' ? t('Downloading {model}…', { model: m.pull.model }) : m.pull?.state === 'failed' ? t('Download of {model} failed: {error}', { model: m.pull.model, error: m.pull.error || '' }) : '';
  const ms = m.measure;
  box.innerHTML = `<b>${esc(t('Search by meaning'))}</b>
    <p class="muted small">${esc(t('Finds documents and past conversations by what they mean, not only their words. Who turns texts into vectors:'))}</p>
    <div class="meaning-list" role="radiogroup">${m.presets.map(p => `<button type="button" class="meaning-opt${p.id === m.current ? ' on' : ''}" role="radio" aria-checked="${p.id === m.current}" data-p="${p.id}" ${p.problem && (p.problem !== 'not-downloaded' || m.shared) && p.id !== m.current ? 'aria-disabled="true"' : ''}>
        <span class="mo-head"><b>${esc(t(p.label))}</b>${p.local ? `<span class="pill">${esc(t('private'))}</span>` : p.backend !== 'off' ? `<span class="pill out">${esc(t('online'))}</span>` : ''}</span>
        <small>${esc(t(p.about))}</small>
        <small class="muted">${esc([mem(p), p.problem ? problem(p.problem) : ''].filter(Boolean).join(' · '))}</small></button>`).join('')}</div>
    ${m.current === 'custom' ? `<p class="small">${esc(t('Now: {backend} {model} (set below).', { backend: m.backend, model: m.model }))}</p>` : ''}
    ${m.backend !== 'off' ? `<p class="small">${esc(idx < 100 ? t('Index: {done} of {total} passages ready ({pct} %); the rest is done in the background.', { done: nf.format(m.index.done), total: nf.format(m.index.total), pct: idx }) : t('Index: everything is ready ({total} passages).', { total: nf.format(m.index.total) }))}</p>` : ''}
    ${pull ? `<p class="small">${esc(pull)}</p>` : ''}
    <div class="row wrap"><button class="btn small" type="button" data-a="measure" ${m.backend === 'off' ? 'disabled' : ''}>${esc(t('Measure on this server'))}</button>
      <span class="muted small" data-a="out">${ms ? esc(t('A search: {s} ms (question {q} ms + comparing {n} vectors {c} ms, {engine}). Indexing: {p} ms per passage. First call after a pause: {f} ms.', { s: nf.format(ms.search_ms), q: nf.format(ms.query_ms), n: nf.format(ms.vectors), c: nf.format(ms.scan_ms), engine: ms.engine, p: nf.format(ms.passage_ms), f: nf.format(ms.first_ms) })) : ''}</span></div>
    <p class="muted small">${esc(t('Confidential and Secret spaces are only indexed by a model on this server; with an online service they are searched by words.'))} ${esc(t('Comparison engine: {engine}.', { engine: m.engine === 'php' ? t('PHP (slower; sqlite-vec is in the Docker image)') : m.engine }))}</p>`;
  box.querySelectorAll('[data-p]').forEach(b => b.onclick = async () => {
    if (b.dataset.p === m.current) return;
    if (b.getAttribute('aria-disabled') === 'true') { toast(problem(m.presets.find(p => p.id === b.dataset.p).problem)); return; }
    if (b.dataset.confirm !== '1') { box.querySelectorAll('[data-confirm]').forEach(x => { delete x.dataset.confirm; x.classList.remove('ask'); }); b.dataset.confirm = '1'; b.classList.add('ask'); toast(t('Click again to switch: everything is indexed again in the background.')); return; }
    try { const r = await post('api/meaning/choose', { preset: b.dataset.p }); toast(r.pulling ? t('Chosen. The model is downloading; indexing starts after.') : t('Chosen. Indexing again in the background.')); loadMeaning(box); refreshStatus(); }
    catch (e) { toast(e.message); }
  });
  box.querySelector('[data-a=measure]').onclick = async e => {
    const b = e.currentTarget; b.disabled = true; box.querySelector('[data-a=out]').textContent = t('Measuring… (up to a minute if the model has to load)');
    try { await post('api/meaning/measure'); await loadMeaning(box); } catch (err) { box.querySelector('[data-a=out]').textContent = err.message; b.disabled = false; }
  };
  clearTimeout(loadMeaning.t);
  if ((m.pull?.state === 'running' || idx < 100) && box.isConnected) loadMeaning.t = setTimeout(() => { if (box.isConnected && box.closest('details')?.open) loadMeaning(box).catch(() => {}); }, 5000);
}
async function saveSettings(el, g, reset) {
  const changes = {};
  if (!reset.length) for (const f of g.fields) { const v = fieldValue(f); if (v !== initialValue(f)) changes[f.key] = v; }
  const state = el.querySelector('[data-state]');
  if (!reset.length && !Object.keys(changes).length) { state.textContent = 'Nothing changed.'; return; }
  el.querySelectorAll('.err').forEach(e => { e.hidden = true; });
  try {
    const res = await fetch('api/settings', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Talixio': '1' }, body: JSON.stringify({ changes, reset }) });
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
  renderAppearance();
  loadConnectors();
  loadGoogle();
  loadSpotify();
  loadTransfer().catch(() => {});
  renderGroups((await api('api/settings')).groups);
}
$('#doctor-run').onclick = async () => {
  const b = $('#doctor-run'); b.disabled = true; b.textContent = 'Checking…';
  const ul = $('#doctor'); ul.hidden = false; ul.innerHTML = '<li class="info">Running checks (this can take a few seconds)…</li>';
  try { const { results } = await post('api/doctor'); ul.innerHTML = results.map(r => `<li class="${r.status}">${esc(r.text)}</li>`).join(''); }
  catch (e) { ul.innerHTML = `<li class="fail">${esc(e.message)}</li>`; }
  b.disabled = false; b.textContent = 'Run checks';
};
$('#pw-form').onsubmit = async e => {
  e.preventDefault();
  try { await post('api/settings/password', { current: $('#pw-current').value, new: $('#pw-new').value }); $('#pw-state').textContent = 'Password changed. Your other devices will ask for it again.'; $('#pw-current').value = $('#pw-new').value = ''; }
  catch (err) { $('#pw-state').textContent = err.message; }
};

// ---- signing in with Claude (Claude Code's own sign-in, driven from here) ----
async function claudeSignIn() {
  const d = $('#claude-dialog');
  $('#cl-link').hidden = true; $('#cl-wait').hidden = false; $('#cl-wait').textContent = t('Preparing the link…');
  $('#cl-state').textContent = ''; $('#cl-code').value = ''; $('#cl-form').querySelector('button').disabled = false;
  $('#cl-close').onclick = () => d.close();
  let ready = false;
  $('#cl-form').onsubmit = async e => {
    e.preventDefault();
    if (!ready) return;
    const b = $('#cl-form').querySelector('button'); b.disabled = true; $('#cl-state').textContent = t('Checking with Claude…');
    try {
      await post('api/claude-login/code', { code: $('#cl-code').value.trim() });
      d.close(); toast('Connected: the assistant now uses your Claude plan.'); refreshStatus();
      if (!$('#view-settings').hidden) loadSettings();
    } catch (err) { $('#cl-state').textContent = err.message; b.disabled = false; }
  };
  d.showModal();
  try { const { url } = await post('api/claude-login/start'); $('#cl-link').href = url; $('#cl-link').hidden = false; $('#cl-wait').hidden = true; ready = true; }
  catch (e) { $('#cl-wait').textContent = e.message; }
}
$('#setup-claude').onclick = claudeSignIn;

// ---- moving to another server ----
async function loadTransfer() {
  const t = await api('api/transfer'), box = $('#transfer-state');
  const last = t.last_export ? `<p class="muted">${esc(window.t(t.last_export.final ? 'Last copy (final) on {date}' : 'Last copy on {date}', { date: fmtTime(t.last_export.ts) }))}${t.last_export.ok ? '' : ' · ' + esc(window.t('interrupted'))}</p>` : '';
  box.innerHTML = (t.code_active ? `<p>${esc(window.t('A transfer code is active until {date}.', { date: fmtTime(t.code_expires) }))} <button class="btn small ghost danger" data-a="cancel" type="button">Cancel it</button></p>` : '') + last;
  box.querySelector('[data-a=cancel]')?.addEventListener('click', async () => { await post('api/transfer/cancel'); loadTransfer(); });
}
$('#transfer-code').onclick = async () => {
  try {
    const r = await post('api/transfer/code');
    $('#transfer-state').innerHTML = `<p>${esc(t('Give these two to the new server (the code is shown only now):'))}</p>
      <p>${esc(t('Address'))}: <code>${esc(r.url || location.origin + location.pathname.replace(/\/$/, ''))}</code></p>
      <p>${esc(t('Code'))}: <code>${esc(r.code)}</code> <button class="btn small" data-a="copy" type="button">Copy</button></p>`;
    $('#transfer-state [data-a=copy]').onclick = () => copyText(r.code);
  } catch (e) { toast(e.message); }
};
/** A copy on its way here, or the old server after a move: say so above the conversation. */
function renderPaused(p) {
  const el = $('#paused');
  el.hidden = !p;
  if (!p) return;
  el.innerHTML = p.reason === 'moved'
    ? `<b>${esc(t('This assistant has moved'))}</b> <span>${esc(t('to {url}. Here it no longer answers channels or runs routines.', { url: p.url || '?' }))}</span>`
      + (p.url ? ` <a class="btn small primary" href="${esc(p.url)}">${esc(t('Open the new one'))}</a>` : '')
    : `<b>${esc(t('Copy from {url}', { url: p.url || '?' }))}</b> <span>${esc(t('Paused: no channels, no routines, so the two never answer the same messages.'))}</span> <button class="btn small" data-a="resume" type="button">Use this one</button>`;
  el.querySelector('[data-a=resume]')?.addEventListener('click', async e => {
    const b = e.currentTarget;
    if (b.dataset.confirm !== '1') { b.dataset.confirm = '1'; b.textContent = t('Sure? Pause the old one first'); return; }
    try { await post('api/transfer/resume'); toast('This assistant is active.'); refreshStatus(); } catch (err) { toast(err.message); }
  });
}

// ---- Spotify ----
function showSpotify(g) {
  $('#spotify-badge').textContent = g.connected ? `· connected${g.name ? ' as ' + g.name : ''}` : g.configured ? '· not connected' : '· not set up';
  $('#spotify-redirect').textContent = g.redirect;
  $('#spotify-status').innerHTML = g.connected
    ? `<p class="small">Connected${g.name ? ' as <b>' + esc(g.name) + '</b>' : ''}.${g.premium === false ? ' <b>This account is not Premium</b>: Spotify only lets Premium accounts be controlled; the assistant will give you buttons to open Spotify instead.' : ''}</p>`
      + (g.error ? `<p class="small error">${esc(g.error)}</p>` : `<p class="muted small">${g.devices.length ? 'Devices available now: ' + g.devices.map(esc).join(', ') : 'No device right now: open Spotify on your phone and it will appear.'}</p>`)
    : '';
  $('#spotify-steps').hidden = g.connected;
  $('#spotify-connect').hidden = g.connected; $('#spotify-disconnect').hidden = !g.connected;
  $('#spotify-connect').disabled = !g.configured; $('#spotify-connect').title = g.configured ? '' : 'Fill in the client ID and secret first (step 3)';
}
async function loadSpotify() { try { showSpotify(await api('api/spotify')); } catch {} }
$('#spotify-copy').onclick = () => copyText($('#spotify-redirect').textContent);
$('#spotify-connect').onclick = async () => { try { location.href = (await post('api/spotify/connect')).url; } catch (e) { toast(e.message); } };
$('#spotify-disconnect').onclick = async () => { try { showSpotify(await post('api/spotify/disconnect')); toast('Spotify disconnected.'); } catch (e) { toast(e.message); } };

// ---- Google Calendar ----
function showGoogle(g) {
  $('#google-badge').textContent = g.connected ? `· connected${g.email ? ' as ' + g.email : ''}` : g.configured ? '· not connected' : '· not set up';
  $('#google-redirect').textContent = g.redirect;
  $('#google-status').innerHTML = g.connected
    ? `<p class="small">Reads ${g.calendars.length ? esc(g.calendars.join(', ')) : 'your calendars'}; adds events to your main calendar after your approval.</p>${g.error ? `<p class="error small">${esc(g.error)}</p>` : ''}`
    : '';
  $('#google-steps').hidden = g.connected;
  $('#google-connect').hidden = g.connected; $('#google-disconnect').hidden = !g.connected;
  $('#google-connect').disabled = !g.configured; $('#google-connect').title = g.configured ? '' : 'Fill in the client ID and secret first (step 5)';
}
async function loadGoogle() { try { showGoogle(await api('api/google')); } catch {} }
$('#google-copy').onclick = () => copyText($('#google-redirect').textContent);
$('#google-connect').onclick = async () => { try { location.href = (await post('api/google/connect')).url; } catch (e) { toast(e.message); } };
$('#google-disconnect').onclick = async () => { try { showGoogle(await post('api/google/disconnect')); toast('Google Calendar disconnected.'); } catch (e) { toast(e.message); } };

// ---- connectors (MCP) and the sandbox ----
function showConnectors(d) {
  if (document.activeElement !== $('#mcp-raw')) $('#mcp-raw').value = d.raw;
  const ok = d.servers.filter(x => !x.error).length;
  $('#mcp-badge').textContent = d.servers.length ? `· ${ok}/${d.servers.length} connected` : '· none';
  $('#mcp-servers').innerHTML = d.servers.map(x => `<div class="mcp-server ${x.error && x.auth !== 'needed' ? 'bad' : ''}">
      <div class="row"><b>${esc(x.name)}</b><span class="chip">${x.kind}</span>${x.trust === 'all' ? '<span class="chip">trusted: no approval</span>' : ''}
        <span class="muted small">${x.error ? '' : x.tools.length + ' tool(s)'}${x.at ? ' · checked ' + fmtTime(x.at) : ''}</span>
        ${x.auth === 'signed_in' ? `<span class="spacer"></span><span class="muted small">signed in ${esc(x.signed_in_at || '')}</span><button type="button" class="btn small ghost" data-signout="${esc(x.name)}">Sign out</button>`
          : x.auth === 'needed' ? `<span class="spacer"></span><button type="button" class="btn small primary" data-signin="${esc(x.name)}">Sign in</button>` : ''}</div>
      ${x.auth === 'needed' ? `<p class="small">This service asks you to sign in with your account: press <b>Sign in</b>, accept, and you come back here.</p>` : x.error ? `<p class="error small">${esc(x.error)}</p>` : `<div class="tools">${x.tools.map(t => `<span class="chip" title="${esc(t.name)}">${esc(t.title)}${t.read_only ? '' : ' ✋'}</span>`).join('')}</div>`}
    </div>`).join('') + (d.servers.some(x => x.tools.some(t => !t.read_only)) ? '<p class="muted small">✋ asks for your approval before running.</p>' : '');
  $('#mcp-servers').querySelectorAll('[data-signin]').forEach(b => b.onclick = async () => {
    b.disabled = true; $('#mcp-state').textContent = 'Opening the sign-in page…';
    try { location.href = (await post('api/mcp/oauth/start', { server: b.dataset.signin })).url; } catch (e) { $('#mcp-state').textContent = e.message; b.disabled = false; }
  });
  $('#mcp-servers').querySelectorAll('[data-signout]').forEach(b => b.onclick = () => mcpAction('api/mcp/oauth/forget', { server: b.dataset.signout }));
  $('#shell-state').textContent = !d.shell.installed ? 'not installed (install.sh --with-shell).' : d.shell.mode === 'off' ? 'installed, off (Settings → Sandboxed shell).' : d.shell.mode === 'ask' ? 'on, asks before each command.' : 'on, runs without asking.';
}
async function loadConnectors() { try { showConnectors(await api('api/mcp')); } catch {} }
const mcpAction = async (path, body) => {
  $('#mcp-state').textContent = 'Connecting… (local servers can take a minute the first time)';
  try { showConnectors(await post(path, body)); $('#mcp-state').textContent = 'Done. New tools are available from the next message.'; }
  catch (e) { $('#mcp-state').textContent = e.message; }
};
$('#mcp-save').onclick = () => mcpAction('api/mcp', { raw: $('#mcp-raw').value });

/** A connector from a .zip in the library: pick it, fill in what it reads (address, account…), install. */
async function connectorDialog(fileId) {
  const d = $('#conn-dialog'), state = $('#conn-state');
  state.textContent = ''; $('#conn-go').disabled = false;
  const { packages } = await api('api/connectors/packages');
  if (!packages.length) { toast(t('Put the connector\'s folder as a .zip in the library first (e.g. in /Connecteurs).')); return; }
  $('#conn-file').innerHTML = packages.map(p => `<option value="${p.id}">${esc(joinPath(p.folder, p.name))}</option>`).join('');
  if (fileId) $('#conn-file').value = String(fileId);
  let info = null;
  const inspect = async () => {
    $('#conn-info').textContent = t('Reading the package…'); $('#conn-env').innerHTML = '';
    try {
      info = await api('api/connectors/inspect?file=' + $('#conn-file').value);
      $('#conn-name').value = info.name;
      $('#conn-info').textContent = info.entry
        ? t('Starts with {cmd} {entry}', { cmd: info.runtime === 'python' ? 'python3' : 'node', entry: info.entry }) + (info.deps ? ' · ' + t('its dependencies will be installed') : '') + (info.installed ? ' · ' + t('already installed: this replaces it') : '')
        : info.build ? t('Will be built (npm run build) before it starts.') : t('No server file found in this package.');
      const secret = k => /PASS|SECRET|TOKEN|KEY/.test(k);
      $('#conn-env').innerHTML = info.env.length ? `<p class="muted small">${esc(t('Settings it reads (leave empty what you do not need):'))}</p>` + info.env.map(k => `<label for="conn-env-${k}">${esc(k)}</label>
        <input id="conn-env-${k}" data-k="${esc(k)}" ${secret(k) ? `type="password" autocomplete="new-password" placeholder="${esc(info.installed && info.values[k] === '' ? t('unchanged') : '')}"` : 'type="text"'} value="${esc(info.values[k] || '')}">`).join('') : '';
      $('#conn-go').disabled = !info.entry && !info.build;
    } catch (e) { $('#conn-info').textContent = e.message; $('#conn-go').disabled = true; }
  };
  $('#conn-file').onchange = inspect;
  $('#conn-close').onclick = () => d.close();
  $('#conn-form').onsubmit = async e => {
    e.preventDefault();
    const env = {}; $('#conn-env').querySelectorAll('[data-k]').forEach(i => { env[i.dataset.k] = i.value; });
    $('#conn-go').disabled = true; state.textContent = t('Unpacking…');
    try {
      const r = await post('api/connectors/install', { file: +$('#conn-file').value, name: $('#conn-name').value.trim(), env });
      const poll = async () => {
        const s = await api('api/connectors/install');
        if (s.state === 'running') { state.textContent = t({ unpacked: 'Unpacked…', 'installing dependencies': 'Installing its dependencies (npm)…', building: 'Building…', connecting: 'Starting it and listing its tools…' }[s.step] || 'Installing…'); setTimeout(poll, 2000); return; }
        if (s.state === 'done') { state.textContent = t('Ready: {n} tool(s) from “{name}”. The assistant can use them from the next message.', { n: s.tools, name: r.name }); loadConnectors(); }
        else { state.textContent = s.error || t('It did not work.'); $('#conn-go').disabled = false; loadConnectors(); }
      };
      setTimeout(poll, 1500);
    } catch (err) { state.textContent = err.message; $('#conn-go').disabled = false; }
  };
  d.showModal();
  await inspect();
}
$('#conn-install').onclick = () => connectorDialog().catch(e => toast(e.message));
$('#viewer-connector').onclick = () => connectorDialog(lib.file.id).catch(e => toast(e.message));
$('#mcp-refresh').onclick = () => mcpAction('api/mcp/refresh');
$('#shell-test').onclick = async () => {
  const b = $('#shell-test'); b.disabled = true;
  try { const r = await post('api/shell/check'); toast(r.ok ? 'Sandbox works: ' + r.text.split('\n').pop() : r.text); } catch (e) { toast(e.message); }
  b.disabled = false;
};

// ---- two-step sign-in ----
async function loadSecurity() {
  try { showTotp((await api('api/security')).totp); } catch { /* shown elsewhere */ }
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
    const d = await post('api/security/totp/setup');
    $('#totp-secret').textContent = d.secret.replace(/(.{4})/g, '$1 ').trim();
    $('#totp-link').href = d.uri;
    $('#totp-off').hidden = true; $('#totp-enable').hidden = false; $('#totp-state').textContent = '';
    try {
      await loadScript('vendor/qrcode.js');
      const qr = qrcode(0, 'M'); qr.addData(d.uri); qr.make();
      $('#totp-qr').innerHTML = qr.createSvgTag({ cellSize: 4, margin: 4, scalable: true });
    } catch { $('#totp-qr').textContent = ''; }
    $('#totp-code').focus();
  } catch (e) { toast(e.message); }
};
$('#totp-enable').onsubmit = async e => {
  e.preventDefault();
  try { await post('api/security/totp/enable', { code: $('#totp-code').value }); $('#totp-code').value = ''; showTotp(true); toast('Two-step sign-in is on.'); }
  catch (err) { $('#totp-state').textContent = err.message; }
};
$('#totp-disable').onsubmit = async e => {
  e.preventDefault();
  try { await post('api/security/totp/disable', { password: $('#totp-dis-pw').value, code: $('#totp-dis-code').value }); $('#totp-dis-pw').value = $('#totp-dis-code').value = ''; showTotp(false); toast('Two-step sign-in is off.'); }
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
  const { skills } = await api('api/skills');
  const box = $('#skills'); box.innerHTML = '';
  if (!skills.length) { box.innerHTML = '<div class="placeholder">No skills yet. After multi-step tasks it writes down how it did them, so next time is faster and closer to what you want.</div>'; return; }
  for (const k of skills) {
    const el = document.createElement('div'); el.className = 'skill';
    el.innerHTML = `<h4>${esc(k.name)}</h4><div class="actions"><button class="btn small" data-a="edit" type="button">Open</button><button class="btn small ghost" data-a="del" type="button">Delete</button></div>
      <p>${esc(k.description)}${k.used ? ` · used ${k.used}×` : ''}</p>`;
    el.querySelector('[data-a=edit]').onclick = async () => {
      const { content } = await api('api/skills/' + encodeURIComponent(k.name));
      state.skill = k.name; $('#skill-editor').value = content; $('#skill-edit').hidden = false; $('#skill-state').textContent = '';
      $('#skill-editor').focus();
    };
    el.querySelector('[data-a=del]').onclick = async () => {
      const b = el.querySelector('[data-a=del]');
      if (b.dataset.confirm !== '1') { b.dataset.confirm = '1'; b.textContent = 'Confirm delete'; return; }
      try { await post(`api/skills/${encodeURIComponent(k.name)}/delete`); toast('Skill deleted'); loadSkills(); } catch (e) { toast(e.message); }
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
async function loadDigest() { showDigest(await api('api/memory/digest')); }
$('#digest-compact').onclick = async () => {
  const b = $('#digest-compact'); b.disabled = true; b.textContent = 'Compacting…';
  try { const d = await post('api/memory/compact'); showDigest(d); toast(d.done ? 'Conversation compacted.' : 'Nothing to compact yet.'); }
  catch (e) { toast(e.message); }
  b.disabled = false; b.textContent = 'Compact now';
};

const MIND_KIND = { value: 'What matters to you', pattern: 'How you work and decide', self: 'What it learned about itself' };
async function loadMind() { showMind(await api('api/mind')); }
function showMind(d) {
  const box = $('#mind');
  if (!d.enabled) { box.innerHTML = '<div class="placeholder">Learning is off (Settings → Learning, backups, updates → Character and values).</div>'; return; }
  const est = d.beliefs.filter(b => b.status === 'established'), emerging = d.beliefs.filter(b => b.status !== 'established');
  const item = b => `<li class="belief" data-id="${b.id}"><div class="b-text">${esc(b.text)}${b.space_name ? ` <span class="chip small">${esc(b.space_name)}</span>` : ''}</div>
      <div class="b-meta"><span class="conf" title="Confidence ${Math.round(b.confidence * 100)}%"><i style="width:${Math.round(b.confidence * 100)}%"></i></span>
      <span>${t(b.days > 1 ? 'seen {n}× over {d} days' : 'seen {n}× on {d} day', { n: b.support, d: b.days })}</span>${b.last_seen ? `<span>${t('last {date}', { date: fmtDate(b.last_seen) })}</span>` : ''}
      <button class="linkish" data-a="why" type="button">Why?</button><button class="linkish danger" data-a="forget" type="button">Forget</button></div><div class="b-why" hidden></div></li>`;
  const group = (list, empty) => Object.entries(MIND_KIND).map(([k, label]) => {
    const l = list.filter(b => b.kind === k);
    return l.length ? `<h4>${label}</h4><ul class="beliefs">${l.map(item).join('')}</ul>` : '';
  }).join('') || `<p class="muted small">${empty}</p>`;
  const status = d.learning_history ? t('Learning from your past conversations…') : [t(d.observations === 1 ? '{n} observation so far' : '{n} observations so far', { n: nf.format(d.observations) }),
    d.pending ? t('{n} not yet thought over', { n: d.pending }) : '', d.consolidated ? t('last thought over {when}', { when: fmtTime(d.consolidated) }) : ''].filter(Boolean).join(' · ');
  box.innerHTML = `<div class="card portrait"><div class="row"><h4>Who it has become</h4><span class="muted small">${d.reflected ? t('Portrait of {date}', { date: fmtDate(d.reflected) }) : ''}</span></div>
      ${d.portrait ? `<div class="doc">${md(d.portrait)}</div>` : '<p class="muted">No portrait yet: it writes one once it has learned enough, then updates it every week.</p>'}
      ${(d.spaces || []).map(sp => `<details class="space-portrait"><summary><i class="space-dot" style="background:${esc(sp.color || 'var(--muted)')}"></i>${t('In {name}', { name: esc(sp.name) })}</summary><div class="doc">${md(sp.portrait)}</div></details>`).join('')}</div>
    <div class="row"><span class="muted small">${status}</span><button id="mind-reflect" class="btn" type="button">Think it over now</button></div>
    ${group(est, 'Nothing established yet: beliefs need to be confirmed on several days.')}
    ${emerging.length ? `<details class="card emerging"><summary>Emerging (${emerging.length}): not sure yet, not used in its choices</summary>${group(emerging, '')}</details>` : ''}
    ${d.journal.length ? `<details class="card"><summary>Journal</summary><ul class="journal">${d.journal.map(j => `<li><span class="muted small">${fmtTime(j.ts)}${j.space_name ? ' · ' + esc(j.space_name) : ''}</span><div class="doc">${md(j.text)}</div></li>`).join('')}</ul></details>` : ''}`;
  box.querySelectorAll('.belief').forEach(li => {
    const id = li.dataset.id;
    li.querySelector('[data-a=why]').onclick = async () => {
      const w = li.querySelector('.b-why');
      if (!w.hidden) { w.hidden = true; return; }
      const { evidence } = await api('api/mind/' + id);
      w.innerHTML = `<ul>${evidence.map(e => `<li class="${e.polarity < 0 ? 'against' : ''}"><span class="muted small">${fmtDate(e.ts)} · ${esc(e.space_name)}${e.polarity < 0 ? ' · against' : ''}${e.source === 'noted' ? ' · noted during a conversation' : e.source === 'history' ? ' · from older history' : ''}</span> ${esc(e.text)}</li>`).join('')}</ul>`;
      w.hidden = false;
    };
    li.querySelector('[data-a=forget]').onclick = async e => {
      const b = e.currentTarget;
      if (b.dataset.confirm !== '1') { b.dataset.confirm = '1'; b.textContent = 'Forget for good?'; return; }
      try { await post(`api/mind/${id}/forget`); toast('Forgotten: it will not learn it again.'); loadMind(); } catch (err) { toast(err.message); }
    };
  });
  $('#mind-reflect').onclick = async e => {
    const b = e.currentTarget; b.disabled = true; b.textContent = 'Thinking…';
    try { const r = await post('api/mind/reflect'); showMind(r); toast(r.done ? 'Done: see what changed in the journal.' : 'Nothing new to think over yet.'); }
    catch (err) { toast(err.message); b.disabled = false; b.textContent = 'Think it over now'; }
  };
}

async function loadMemory() {
  loadMind().catch(e => toast(e.message));
  loadSkills().catch(e => toast(e.message));
  loadDigest().catch(() => {});
  state.memFiles = (await api('api/memory')).files;
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
const fmtDate = ts => new Date(ts * 1000).toLocaleDateString(LOCALE, { day: 'numeric', month: 'short', year: 'numeric' });
const joinPath = (folder, name) => (folder === '/' ? '' : folder) + '/' + name;
const lib = { folder: '/', tree: [], timer: null, file: null, inline: null };
const libSearch = { ctl: null, q: '', folder: '/', results: null, phase: '' };

function libFolderFromHash() {
  const m = location.hash.match(/^#library(\/.*)?$/);
  return m ? (m[1] ? decodeURIComponent(m[1]) : '/') : null; // '#library' alone is the top of the library
}
/** /FAQ and below: the notes about Talixio, kept up to date by each version. */
const isReadonly = p => p === '/FAQ' || (p || '').startsWith('/FAQ/');
function libGo(folder) { location.hash = '#library' + (folder === '/' ? '' : folder.split('/').map(encodeURIComponent).join('/')); }

async function loadLibrary() {
  const was = lib.folder;
  lib.folder = libFolderFromHash() || '/';
  if (was !== undefined && was !== lib.folder && libSearch.phase) libSearchClose(); // another folder: its content, not old results
  const [tree, list] = await Promise.all([api('api/library/tree'), api('api/library?folder=' + encodeURIComponent(lib.folder))]);
  lib.tree = tree.tree; lib.trash = tree.stats.trash || 0; lib.shared = list.shared;
  $('#lib-trash').textContent = lib.trash ? `${t('Trash')} (${lib.trash})` : t('Trash'); lib.level = list.level || 'internal';
  lib.folder = list.folder; lib.readonly = !!list.readonly;
  for (const b of ['#lib-upload', '#lib-newnote', '#lib-newfolder']) $(b).hidden = lib.readonly;
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

/** Folders opened in the tree (this browser); the current folder's parents are always open. */
const treeOpen = (() => { try { return new Set(JSON.parse(localStorage.getItem(LSK + 'talixio.tree.open') || '[]')); } catch { return new Set(); } })();
const saveTreeOpen = () => { try { localStorage.setItem(LSK + 'talixio.tree.open', JSON.stringify([...treeOpen])); } catch {} };
const CHEVRON_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>';

function renderTree() {
  const nav = $('#lib-tree');
  const total = lib.tree.filter(t => t.depth === 1).reduce((a, t) => a + t.total, 0);
  const parentOf = p => p.slice(0, p.lastIndexOf('/')) || '/';
  const hasKids = new Set(lib.tree.map(t => parentOf(t.path)));
  for (let p = lib.folder; p !== '/'; p = parentOf(p)) if (p !== lib.folder) treeOpen.add(p); // the way to the current folder
  const shown = t => { for (let p = parentOf(t.path); p !== '/'; p = parentOf(p)) if (!treeOpen.has(p)) return false; return true; };
  const row = (path, name, depth, count, kids) => `<div class="tree-row${path === lib.folder ? ' active' : ''}" style="padding-left:${(depth - 1) * 14}px">`
    + (kids ? `<button class="tw${treeOpen.has(path) ? ' open' : ''}" type="button" data-toggle="${esc(path)}" aria-expanded="${treeOpen.has(path)}" aria-label="${esc(t(treeOpen.has(path) ? 'Fold {name}' : 'Unfold {name}', { name }))}">${CHEVRON_SVG}</button>` : '<span class="tw"></span>')
    + `<a href="#" data-path="${esc(path)}" class="${path === lib.folder ? 'active' : ''}">${FOLDER_SVG}<span>${esc(name)}</span>${count ? `<small>${count}</small>` : ''}</a></div>`;
  const anyOpen = lib.tree.some(t => treeOpen.has(t.path) && hasKids.has(t.path));
  nav.innerHTML = `<div class="tree-head"><a href="#" data-path="/" class="${lib.folder === '/' ? 'active' : ''}">${FOLDER_SVG}<span>${esc(t('All files'))}</span>${total ? `<small>${total}</small>` : ''}</a>`
    + (anyOpen ? `<button class="tw fold-all" type="button" data-fold-all title="${esc(t('Fold every folder'))}" aria-label="${esc(t('Fold every folder'))}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 11l5-5 5 5M7 18l5-5 5 5"/></svg></button>` : '') + '</div>'
    + lib.tree.filter(shown).map(x => row(x.path, x.name, x.depth, x.total, hasKids.has(x.path))).join('')
    + `<a href="#" class="tree-trash" data-trash title="${esc(t('Deleted files, kept {n} days', { n: 30 }))}">${TRASH_SVG}<span>${esc(t('Trash'))}</span>${lib.trash ? `<small>${lib.trash}</small>` : ''}</a>`;
  nav.querySelector('[data-trash]').onclick = e => { e.preventDefault(); showLibTrash(); };
  nav.querySelectorAll('a[data-path]').forEach(a => {
    a.onclick = e => { e.preventDefault(); libGo(a.dataset.path); };
    dropTarget(a, a.dataset.path);
  });
  nav.querySelectorAll('[data-toggle]').forEach(b => {
    b.onclick = () => { const p = b.dataset.toggle; if (treeOpen.has(p)) { for (const x of [...treeOpen]) if (x === p || x.startsWith(p + '/')) treeOpen.delete(x); } else treeOpen.add(p); saveTreeOpen(); renderTree(); };
    let timer; // dragging a file over a folded folder opens it
    b.parentElement.addEventListener('dragenter', () => { if (!treeOpen.has(b.dataset.toggle)) timer = setTimeout(() => { treeOpen.add(b.dataset.toggle); saveTreeOpen(); renderTree(); }, 700); });
    b.parentElement.addEventListener('dragleave', () => clearTimeout(timer));
  });
  nav.querySelector('[data-fold-all]')?.addEventListener('click', () => { treeOpen.clear(); saveTreeOpen(); renderTree(); });
}

function renderCrumbs() {
  const parts = lib.folder === '/' ? [] : lib.folder.slice(1).split('/');
  const items = [['/', 'Library'], ...parts.map((p, i) => ['/' + parts.slice(0, i + 1).join('/'), p])];
  $('#lib-crumbs').innerHTML = items.map(([path, name]) => `<li><a href="#" data-path="${esc(path)}">${esc(name)}</a></li>`).join('');
  $('#lib-crumbs').querySelectorAll('a').forEach(a => { a.onclick = e => { e.preventDefault(); libGo(a.dataset.path); }; dropTarget(a, a.dataset.path); });
  if (lib.level && lib.level !== 'internal') $('#lib-crumbs').insertAdjacentHTML('beforeend', `<li class="lvl-crumb"><span class="privacy-badge ${lib.level}">${SHIELD_SVG}<span>${LEVELS[lib.level].label}</span></span></li>`);
  const fa = $('#lib-folder-actions');
  const zipName = (lib.folder === '/' ? 'Library' : lib.folder.split('/').pop()) + '-' + new Date().toLocaleDateString('sv-SE') + '.zip';
  const zip = `<a class="btn small" href="api/library/zip?folder=${encodeURIComponent(lib.folder)}" download="${esc(zipName)}" title="Download this folder and its subfolders as a ZIP">Download zip</a>`;
  const ask = `<button class="btn small primary" data-a="ask" type="button" title="Ask the assistant about the documents in this folder">Ask about ${lib.folder === '/' ? 'my library' : 'this folder'}</button>`;
  const owner = lib.folder !== '/' && state.spaces.find(x => x.id && x.folder === lib.folder);
  const spaceBtn = lib.folder === '/' ? '' : owner ? `<a class="btn small" href="#chat/${esc(owner.slug)}" title="Open the conversation of this folder's space"><i class="space-dot" style="background:${esc(owner.color)};width:9px;height:9px;display:inline-block;margin-right:6px"></i>Open space</a>`
    : '<button class="btn small" data-a="space" type="button" title="A separate conversation about this folder">New space here</button>';
  if (lib.readonly) { // read-only: ask and download, nothing that changes it
    fa.innerHTML = `<span class="pill" title="${esc(t('Kept up to date with each version of Talixio.'))}">${esc(t('Read-only'))}</span>` + ask + zip;
    fa.querySelector('[data-a=ask]').addEventListener('click', () => { location.hash = '#chat'; const i = $('#input'); i.value = `From my documents in ${lib.folder}: `; autosize(); i.focus(); saveDraft(); });
    return;
  }
  fa.innerHTML = ask + spaceBtn + zip + (lib.folder === '/' ? '' : '<button class="btn small" data-a="share" type="button" title="A link to this folder for people outside">Share</button><button class="btn small" data-a="rename" type="button" title="Rename or move this folder">Rename</button><button class="btn small ghost danger" data-a="delete" type="button" title="Delete this folder">Delete</button>');
  fa.querySelector('[data-a=share]')?.addEventListener('click', () => openShare({ folder: lib.folder }, lib.folder.split('/').pop()));
  fa.querySelector('[data-a=space]')?.addEventListener('click', () => openSpaceEditor(null, { folder: lib.folder, name: lib.folder.split('/').pop() }));
  fa.querySelector('[data-a=ask]').addEventListener('click', () => {
    location.hash = '#chat';
    const i = $('#input'); i.value = lib.folder === '/' ? 'From my library: ' : `From my documents in ${lib.folder}: `; autosize(); i.focus(); saveDraft();
  });
  fa.querySelector('[data-a=rename]')?.addEventListener('click', () => libInline(lib.folder, 'Folder path', async v => { const { folder } = await post('api/library/folder/move', { from: lib.folder, to: v }); toast('Folder renamed'); libGo(folder); }));
  fa.querySelector('[data-a=delete]')?.addEventListener('click', async e => {
    const b = e.currentTarget, node = lib.tree.find(t => t.path === lib.folder), n = node ? node.total : 0;
    if (b.dataset.confirm !== '1') { b.dataset.confirm = '1'; b.textContent = n ? `Delete with its ${n} file${n > 1 ? 's' : ''}?` : 'Confirm delete'; return; }
    try { const r = await post('api/library/folder/delete', { path: lib.folder, recursive: true }); toast(r.deleted_files ? t('Folder moved to the trash: restorable for {n} days', { n: 30 }) : 'Folder deleted'); libGo(lib.folder.split('/').slice(0, -1).join('/') || '/'); } catch (err) { toast(err.message); }
  });
}

const TRASH_SVG = '<svg viewBox="0 0 24 24"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>';

/** The library's trash: files and folders deleted in the last 30 days, restorable where they were. */
async function showLibTrash() {
  const d = $('#trash-dialog');
  const { items, days } = await api('api/library/trash');
  const left = until => Math.max(0, Math.ceil((until - Date.now() / 1000) / 86400));
  $('#trash-note').textContent = items.length ? t('Deleted files stay here {n} days, then are deleted for good. They are not searched meanwhile.', { n: days }) : '';
  $('#trash-list').innerHTML = items.length ? items.map((x, i) => {
    const when = t('deleted {date}, {n} days left', { date: fmtDate(x.deleted_at), n: left(x.until) });
    return x.type === 'file'
      ? `<div class="trash-item">${CITE_SVG}<b>${esc(x.name)}</b><button class="btn small" type="button" data-i="${i}">${esc(t('Restore'))}</button>
          <span class="muted small">${esc(t('from {folder}', { folder: x.folder }))} · ${esc(fmtSize(x.size))} · ${esc(when)}</span></div>`
      : `<div class="trash-item">${FOLDER_SVG}<b>${esc(x.path)}</b><button class="btn small" type="button" data-i="${i}">${esc(t('Restore'))}</button>
          <span class="muted small">${esc(t(x.files === 1 ? 'folder with 1 file' : 'folder with {n} files', { n: x.files }))} (${esc(x.names.join(', '))}${x.files > x.names.length ? ', …' : ''}) · ${esc(fmtSize(x.size))} · ${esc(when)}</span></div>`;
  }).join('') : `<p class="muted">${esc(t('The trash is empty.'))}</p>`;
  $('#trash-list').querySelectorAll('[data-i]').forEach(b => b.onclick = async () => {
    const x = items[+b.dataset.i];
    try {
      const r = await post('api/library/trash/restore', x.type === 'file' ? { id: x.id } : { folder: x.path, at: x.deleted_at });
      toast(t('Restored to {folder}', { folder: r.folder })); showLibTrash(); loadLibrary();
    } catch (e) { toast(e.message); }
  });
  const empty = $('#trash-empty');
  empty.hidden = !items.length; empty.dataset.confirm = ''; empty.textContent = t('Empty the trash');
  empty.onclick = async () => {
    if (empty.dataset.confirm !== '1') { empty.dataset.confirm = '1'; empty.textContent = t('Delete them for good?'); return; }
    try { const r = await post('api/library/trash/empty'); toast(t('{n} files deleted for good', { n: r.deleted })); showLibTrash(); loadLibrary(); } catch (e) { toast(e.message); }
  };
  $('#trash-close').onclick = () => d.close();
  if (!d.open) d.showModal();
}

const DL_SVG = '<svg viewBox="0 0 24 24"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>';
const LINK_SVG = '<svg viewBox="0 0 24 24"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></svg>';

// ---- shared links (documents for people outside) ----
const SHARE_STATE = { active: 'active', expired: 'expired', revoked: 'revoked', missing: 'document deleted' };
function shareLine(s, opts = {}) {
  const until = s.expires ? t('until {date}', { date: fmtDate(s.expires) }) : t('no end date');
  const seen = s.views ? t(s.views === 1 ? 'opened once, last {when}' : 'opened {n} times, last {when}', { n: s.views, when: fmtTime(s.last_access) }) : t('not opened yet');
  const el = document.createElement('div'); el.className = 'share-line ' + s.state;
  el.innerHTML = `<div class="sl-main">${opts.withName ? `<b>${s.file_id ? '' : '📁 '}${esc(s.path || s.name)}</b>` : ''}
      <div>${s.label ? `<span class="sl-for">${esc(t('For {who}', { who: s.label }))}</span>` : `<span class="muted">${esc(t('Link'))} #${s.id}</span>`}
      <span class="pill ${s.state === 'active' ? 'on' : 'off'}">${esc(SHARE_STATE[s.state] || s.state)}</span></div>
      <div class="muted small"><span>${esc(until)}</span> · <span>${esc(seen)}</span>${s.password ? ` · <span>${esc(t('password'))}</span>` : ''}${s.download ? '' : ` · <span>${esc(t('view only'))}</span>`}${s.created_by === 'assistant' ? ` · <span>${esc(t('made by the assistant'))}</span>` : ''}</div>
      <div class="sl-log" hidden></div></div>
    <div class="sl-acts">${s.state === 'active' ? `<button class="btn small primary" data-a="copy" type="button">Copy link</button>
      <a class="btn small" data-a="mail" href="mailto:?subject=${encodeURIComponent(s.name)}&body=${encodeURIComponent((s.message ? s.message + '\n\n' : '') + s.url)}">Email</a>
      <button class="btn small" data-a="extend" type="button" title="30 more days from today">+30 days</button>` : ''}
      <button class="btn small ghost" data-a="log" type="button">Who opened it</button>
      ${s.state === 'active' ? '<button class="btn small ghost danger" data-a="revoke" type="button">Revoke</button>' : ''}</div>`;
  const q = a => el.querySelector(`[data-a=${a}]`);
  q('copy')?.addEventListener('click', () => copyText(s.url));
  q('extend')?.addEventListener('click', async () => { try { await post(`api/shares/${s.id}`, { days: 30 }); toast('Extended by 30 days from today.'); opts.refresh?.(); } catch (e) { toast(e.message); } });
  q('revoke')?.addEventListener('click', async e => {
    const b = e.currentTarget;
    if (b.dataset.confirm !== '1') { b.dataset.confirm = '1'; b.textContent = 'Revoke now?'; return; }
    try { await post(`api/shares/${s.id}/revoke`); toast('Link revoked: it no longer opens.'); opts.refresh?.(); } catch (err) { toast(err.message); }
  });
  q('log').addEventListener('click', async () => {
    const box = el.querySelector('.sl-log');
    if (!box.hidden) { box.hidden = true; return; }
    const { log } = await api(`api/shares/${s.id}/log`);
    const what = { open: 'opened the page', view: 'viewed', download: 'downloaded', zip: 'downloaded everything' };
    box.innerHTML = log.length ? `<ul>${log.map(l => `<li><span class="muted small">${esc(fmtTime(l.ts))}</span> ${esc(t(what[l.action] || l.action))}${l.name ? ' ' + esc(l.name) : ''} <span class="muted small" title="${esc(l.agent)}">${esc(l.ip)}</span></li>`).join('')}</ul>` : `<p class="muted small">${esc(t('Nobody has opened it yet.'))}</p>`;
    box.hidden = false;
  });
  return el;
}

/** A Confidential space's documents: password required, 30 days at most. A Secret one's: not shared. */
function shareLevel(level) {
  const note = $('#sh-level'), days = $('#sh-days');
  note.hidden = level === 'internal'; note.className = 'sh-level ' + level;
  note.innerHTML = level === 'secret' ? SHIELD_SVG + '<span>Secret space: nothing from it can be shared outside.</span>'
    : level === 'confidential' ? SHIELD_SVG + '<span>Confidential space: a password is required, and the link ends within 30 days.</span>' : '';
  $('#sh-password').required = level === 'confidential';
  $('#sh-password').placeholder = level === 'confidential' ? 'Required' : 'Optional';
  for (const o of days.options) o.hidden = level !== 'internal' && (o.value === '0' || +o.value > 30);
  if (level !== 'internal' && (days.value === '0' || +days.value > 30)) days.value = '7';
  $('#sh-form').querySelector('[type=submit]').disabled = level === 'secret';
}

/** Share one file ({file: id}) or a folder ({folder: path}): its links, and a form for a new one. */
async function openShare(what, name) {
  const dlg = $('#share-dialog');
  $('#sh-title').textContent = t('Share "{name}"', { name });
  $('#sh-what').textContent = what.folder ? t('The folder {path} and its subfolders, as they are when the link is opened.', { path: what.folder }) : t('This document only.');
  const refresh = async () => {
    const { shares, base, level } = await api('api/shares?scope=active&' + (what.file ? 'file=' + what.file : 'folder=' + encodeURIComponent(what.folder)));
    shareLevel(level || 'internal');
    const box = $('#sh-existing'); box.innerHTML = '';
    if (!base) box.innerHTML = `<p class="small error">${esc(t('The app does not know its public address yet: set it in Settings → Assistant → Public address.'))}</p>`;
    if (shares.length) { const h = document.createElement('h4'); h.textContent = t('Active links'); box.append(h); }
    for (const s of shares) box.append(shareLine(s, { refresh }));
  };
  $('#sh-form').reset();
  $('#sh-form').onsubmit = async e => {
    e.preventDefault();
    try {
      const { share } = await post('api/shares', { ...what, label: $('#sh-label').value, message: $('#sh-message').value, days: +$('#sh-days').value,
        password: $('#sh-password').value, download: $('#sh-download').checked, notify: $('#sh-notify').checked });
      copyText(share.url);
      toast('Link created and copied.');
      $('#sh-form').reset(); refresh(); loadLibrary().catch(() => {});
    } catch (err) { toast(err.message); }
  };
  $('#sh-close').onclick = () => dlg.close();
  await refresh().catch(e => toast(e.message));
  dlg.showModal();
}

async function openSharesManager() {
  const dlg = $('#shares-dialog');
  const refresh = async () => {
    const { shares } = await api('api/shares?scope=' + ($('#shs-all').checked ? 'all' : 'active'));
    const box = $('#shs-list'); box.innerHTML = '';
    if (!shares.length) box.innerHTML = `<div class="placeholder">${esc(t('No shared links. Open a document or a folder and press Share.'))}</div>`;
    for (const s of shares) box.append(shareLine(s, { withName: true, refresh }));
  };
  $('#shs-all').onchange = () => refresh().catch(e => toast(e.message));
  $('#shs-close').onclick = () => dlg.close();
  await refresh().catch(e => toast(e.message));
  dlg.showModal();
}
$('#lib-shares').onclick = () => openSharesManager();
$('#lib-trash').onclick = () => showLibTrash();
$('#viewer-share').onclick = () => lib.file && openShare({ file: lib.file.id }, lib.file.name);
const isVideo = f => (f.mime || '').startsWith('video/');
const Library_join = (folder, name) => (folder === '/' ? '' : folder) + '/' + name;
function fileRow(f, sub) {
  const el = document.createElement('div'); el.className = 'lib-row'; el.draggable = true; el.tabIndex = 0; el.setAttribute('role', 'button');
  const ext = (f.name.match(/\.([a-z0-9]{1,4})$/i) || [])[1];
  const label = f.note ? 'NOTE' : isVideo(f) ? 'VID' : ['text', 'office'].includes(f.kind) && ext ? ext.toUpperCase() : KIND_LABEL[f.kind] || 'FILE';
  const ico = f.kind === 'image' || f.kind === 'pdf' ? `<img src="api/files/${f.id}/thumb" alt="" loading="lazy">` : label;
  const status = f.status === 'pending' || f.status === 'processing' ? '<span class="pill pending">reading…</span>' : f.status === 'failed' ? '<span class="pill failed">unreadable</span>' : '';
  el.innerHTML = `<span class="ico">${ico}</span><span class="name"><b>${esc(f.name)}</b><span class="${sub ? 'snippet' : ''}">${sub ? esc(sub) : `${fmtSize(f.size)} · ${fmtDate(f.created)}${f.duration ? ' · ' + Math.round(f.duration / 60) + ' min' : ''}`}</span></span>`
    + `<span class="row-end">${status}${lib.shared?.files?.includes(f.id) ? `<span class="shared-mark" title="${esc(t('Shared by link'))}">${LINK_SVG}</span>` : ''}<a class="btn ghost icon dl" href="api/files/${f.id}/file/${encodeURIComponent(f.name)}?download=1" download="${esc(f.name)}" aria-label="Download ${esc(f.name)}" title="Download">${DL_SVG}</a></span>`;
  el.querySelector('.dl').onclick = e => e.stopPropagation();
  const sg = f.suggestion;
  if (sg) { // where it would go: one click to file it, or edit, or dismiss
    const target = (sg.folder !== f.folder ? sg.folder + '/' : '') + (sg.name !== f.name ? sg.name : (sg.folder !== f.folder ? '' : sg.name));
    const box = document.createElement('div'); box.className = 'suggest-file';
    box.innerHTML = `<span class="muted small" title="${esc(sg.why || '')}">${sg.by === 'similar' ? 'Like your other documents' : 'Suggested'}${sg.confidence === 'high' ? '' : ' (not sure)'}: </span>`
      + `<b class="small">→ ${esc(target.replace(/\/$/, ''))}</b>`
      + `<span class="acts"><button class="btn small primary" data-s="ok" type="button">File it</button><button class="btn small" data-s="edit" type="button">Change…</button><button class="btn small ghost" data-s="no" type="button" aria-label="Dismiss" title="Leave it here">✕</button></span>`;
    box.onclick = e => e.stopPropagation();
    box.querySelector('[data-s=ok]').onclick = async () => { try { await post(`api/files/${f.id}/file-it`); toast(`Filed in ${sg.folder}`); loadLibrary(); } catch (e) { toast(e.message); } };
    box.querySelector('[data-s=no]').onclick = async () => { try { await post(`api/files/${f.id}/dismiss`); loadLibrary(); } catch (e) { toast(e.message); } };
    box.querySelector('[data-s=edit]').onclick = () => libInline(Library_join(sg.folder, sg.name), 'Folder and name', async v => {
      const i = v.lastIndexOf('/'); await post(`api/files/${f.id}/file-it`, { folder: v.slice(0, i) || '/', name: v.slice(i + 1) }); toast('Filed'); loadLibrary();
    }, false);
    el.classList.add('has-suggestion'); el.append(box);
  }
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
    el.innerHTML = `<span class="ico folder">${FOLDER_SVG}</span><span class="name"><b>${esc(t.name)}</b><span>${t.total} file${t.total === 1 ? '' : 's'}</span></span><span class="row-end">${lib.shared?.folders?.includes(t.path) ? `<span class="shared-mark" title="${esc(window.t('Shared by link'))}">${LINK_SVG}</span>` : ''}</span>`;
    el.onclick = e => { e.preventDefault(); libGo(t.path); };
    dropTarget(el, t.path);
    box.append(el);
  }
  const ready = list.files.filter(f => f.suggestion);
  if (lib.folder === '/Inbox' && ready.length > 1) {
    const bar = document.createElement('div'); bar.className = 'suggest-bar';
    bar.innerHTML = `<span>${ready.length} documents have a suggested place.</span><button class="btn small primary" type="button">File them all</button>`;
    bar.querySelector('button').onclick = async () => { try { const r = await post('api/library/file-all'); toast(`${r.filed} documents filed`); loadLibrary(); } catch (e) { toast(e.message); } };
    box.append(bar);
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
    if (id) { try { await post(`api/files/${id}/move`, { folder: path }); toast('Moved to ' + path); loadLibrary(); } catch (err) { toast(err.message); } return; }
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

function libInline(value, placeholder, onOk, folders = true) {
  const form = $('#lib-inline'), input = $('#lib-inline-input');
  form.hidden = false; input.value = value; input.placeholder = placeholder; folderPicker(input, folders); input.focus(); input.select();
  form.onsubmit = async e => { e.preventDefault(); try { await onOk(input.value.trim()); form.hidden = true; } catch (err) { toast(err.message); } };
}

const fileUrl = f => `api/files/${f.id}/file/${encodeURIComponent(f.name)}`;
const previewUrl = f => `preview/${f.id}/${encodeURIComponent(f.name)}`;
/** Rendered documents are fetched as data and shown in a sandboxed srcdoc frame (no scripts, own origin). */
async function fillPreview(frame, id) {
  frame.srcdoc = '<p style="font:14px sans-serif;color:#5b6a66;padding:16px">Loading…</p>';
  try { frame.srcdoc = (await api(`api/files/${id}/preview`)).html; }
  catch (e) { frame.srcdoc = `<p style="font:14px sans-serif;color:#b42318;padding:16px">${esc(e.message)}</p>`; }
}
const FRAME_SANDBOX = 'allow-popups allow-popups-to-escape-sandbox';
const RENDERED = ['text', 'office'];
/** A citation in an answer: open the document in the library, at its page. */
document.addEventListener('click', async e => {
  const a = e.target.closest('a.cite');
  if (!a || a.closest('[contenteditable=true]') || !a.dataset.file) return; // a folder link is a plain #library link
  e.preventDefault();
  a.closest('dialog[open]')?.close();
  try {
    const { file } = await api(`api/files/${a.dataset.file}/text`);
    lib.folder = file.folder; location.hash = '#library' + (file.folder === '/' ? '' : file.folder);
    setTimeout(() => openFile(+a.dataset.file, { page: +a.dataset.page || 0 }), 150);
  } catch (err) { toast(err.message.includes('not found') ? 'That document is no longer in the library.' : err.message); }
});

async function openFile(id, opts = {}) {
  const v = $('#viewer'), body = $('#viewer-body');
  let d;
  try { d = await api(`api/files/${id}/text`); } catch (e) { toast(e.message); return; }
  const f = lib.file = d.file;
  v.hidden = false; $('#viewer-form').hidden = true;
  $('#viewer-name').textContent = f.name;
  $('#viewer-path').textContent = `${f.folder} · ${fmtSize(f.size)} · ${fmtDate(f.created)} · #${f.id}`;
  const officePages = f.kind === 'office' && d.pages; // LibreOffice installed: the real pages
  const rendered = RENDERED.includes(f.kind) && !officePages;
  const opens = rendered || officePages || ['image', 'pdf', 'audio'].includes(f.kind);
  $('#viewer-open').href = officePages ? `api/files/${f.id}/pdf/${encodeURIComponent(f.name.replace(/\.[^.]+$/, ''))}.pdf` : rendered ? previewUrl(f) : fileUrl(f);
  $('#viewer-open').hidden = !opens;
  $('#viewer-download').href = fileUrl(f) + '?download=1'; $('#viewer-download').setAttribute('download', f.name);
  const del = $('#viewer-delete'); del.dataset.confirm = ''; del.textContent = 'Delete';
  const ro = isReadonly(f.folder);
  for (const b of ['#viewer-rename', '#viewer-move', '#viewer-reindex', '#viewer-delete']) $(b).hidden = ro;
  $('#viewer-connector').hidden = !/\.zip$/i.test(lib.file?.name || '');
  const editable = !ro && f.note || !ro && (f.kind === 'text' && f.size < 2_000_000 && !/\.(html?|eml)$/i.test(f.name));
  const hasText = !editable && (f.kind === 'office' || f.kind === 'pdf' || /\.(html?|eml)$/i.test(f.name)) && d.text && !/^\(No text found/.test(d.text);
  const paged = officePages || (f.kind === 'pdf' && d.pages);
  const tabs = [rendered && ['view', 'View'], paged && ['pages', 'Pages'], editable && ['edit', 'Edit'], hasText && ['text', 'Text only']].filter(Boolean);
  let html = '';
  if (f.kind === 'image') html += `<a href="${fileUrl(f)}" target="_blank" rel="noopener" title="Open full size"><img src="${fileUrl(f)}" alt="${esc(f.name)}"></a>`;
  if (f.kind === 'audio') html += isVideo(f) ? `<video controls playsinline preload="metadata" src="${fileUrl(f)}"></video>` : `<audio controls preload="metadata" src="${fileUrl(f)}"></audio>`;
  const pagesHtml = () => {
    const first = Math.min(d.pages, Math.max(5, opts.page || 0)); // a citation's page is loaded too
    return `<div class="pdf-pages" id="pdf-pages">${Array.from({ length: first }, (_, i) => pdfPageHtml(f.id, i + 1, d.pages)).join('')}</div>`
      + (d.pages > first ? `<button id="pdf-more" class="btn small" type="button" data-next="${first + 1}">Show more pages (${d.pages - first} left)</button>` : '');
  };
  if (tabs.length > 1) html += `<div class="tabs" role="tablist">${tabs.map(([k, l], i) => `<button class="tab" data-tab="${k}" type="button" role="tab" aria-selected="${i === 0}">${l}</button>`).join('')}</div>`;
  if (paged) html += `<div data-pane="pages">${pagesHtml()}</div>`;
  if (rendered) html += `<iframe id="viewer-frame" class="doc-frame" data-pane="view" title="${esc(f.name)}" sandbox="${FRAME_SANDBOX}" referrerpolicy="no-referrer"></iframe>`;
  if (editable) html += `<div id="viewer-edit-box" class="viewer-edit" data-pane="edit" ${rendered ? 'hidden' : ''}><textarea id="viewer-editor" class="editor" spellcheck="true" aria-label="Content">${esc(d.text)}</textarea><div class="row"><button id="viewer-save" class="btn primary" type="button">Save</button><span id="viewer-state" class="muted small"></span></div></div>`;
  if (hasText) html += `<pre class="extracted" data-pane="text" hidden>${esc(d.text)}${d.truncated ? '\n…' : ''}</pre>`;
  if (!rendered) {
    if (f.status === 'pending' || f.status === 'processing') html += '<p class="muted">Reading this file… it will be searchable in a moment.</p>';
    else if (d.text && !hasText) html += `<h4>${f.kind === 'audio' ? 'Transcript' : f.kind === 'image' ? 'What the assistant sees' : 'Text'}</h4><pre class="extracted">${esc(d.text)}${d.truncated ? '\n…' : ''}</pre>`;
    else if (f.kind === 'other') html += '<p class="muted">This kind of file cannot be shown here; download it to open it.</p>';
  }
  body.innerHTML = html;
  if (opts.page && paged) { // open on the cited page
    const pg = body.querySelectorAll('.pdf-page')[opts.page - 1];
    if (pg) setTimeout(() => { pg.scrollIntoView({ block: 'start' }); pg.classList.add('flash'); }, 300);
  }
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
      await post('api/library/note', { id: f.id, folder: f.folder, name: f.name, content: $('#viewer-editor').value });
      $('#viewer-state').textContent = 'Saved';
      const fr = $('#viewer-frame'); if (fr) fillPreview(fr, f.id);
      loadLibrary();
    } catch (e) { toast(e.message); }
  });
  $('#viewer-editor')?.addEventListener('input', () => { $('#viewer-state').textContent = 'Unsaved changes'; });
}
function closeViewer() { $('#viewer').hidden = true; lib.file = null; }
function viewerForm(value, onOk, folders = false) {
  const form = $('#viewer-form'), input = $('#viewer-input');
  form.hidden = false; input.value = value; folderPicker(input, folders); input.focus(); input.select();
  form.onsubmit = async e => { e.preventDefault(); try { await onOk(input.value.trim()); form.hidden = true; } catch (err) { toast(err.message); } };
}
function pdfPageHtml(id, n, total) {
  return `<figure class="pdf-page"><img src="api/files/${id}/page/${n}" alt="Page ${n}" loading="lazy"><figcaption>Page ${n} of ${total}</figcaption></figure>`;
}
async function newNote() {
  const folder = lib.folder === '/' ? '/Notes' : lib.folder;
  try { const { file } = await post('api/library/note', { folder, name: '', content: '# ' }); await loadLibrary(); await openFile(file.id); $('#viewer-body [data-tab=edit]')?.click(); }
  catch (e) { toast(e.message); }
}

// ---- mail to handle ----
const MAIL_LABELS = { reply: 'To answer', action: 'To do', fyi: 'To read', low: 'Low priority' };
async function loadMail() {
  const d = await api('api/mail' + ($('#mail-all').checked ? '?all=1' : ''));
  const box = $('#mail-list');
  if (!d.mailbox) { box.innerHTML = '<div class="placeholder">Connect your mailbox first: Settings → Configuration → Your mailbox.</div>'; return; }
  if (!d.enabled) { box.innerHTML = '<div class="placeholder">Email sorting is off: Settings → Your mailbox → Sort new emails.</div>'; return; }
  if (!d.emails.length) { box.innerHTML = `<div class="placeholder">${d.last_run ? 'Nothing waiting. 🎉' : 'Not checked yet: press "Check now" (it then runs every 15 minutes).'}</div>`; return; }
  box.innerHTML = '';
  for (const [k, title] of Object.entries(MAIL_LABELS)) {
    const list = d.emails.filter(m => m.label === k);
    if (!list.length) continue;
    const sec = document.createElement('section'); sec.className = 'mail-group';
    sec.innerHTML = `<h3>${title} <span class="muted small">${list.length}</span></h3>`;
    for (const m of list) {
      const el = document.createElement('div'); el.className = 'mail-row' + (m.status === 'done' ? ' done' : '');
      el.innerHTML = `<div class="mail-main"><div class="row"><b>${esc(m.sender)}</b><span class="muted small">${fmtDate(m.ts)}</span>${m.due ? `<span class="pill failed">due ${esc(m.due)}</span>` : ''}${m.by === 'you' ? '' : `<span class="muted small" title="${m.by === 'similar' ? 'Like emails you sorted before' : m.by === 'list' ? 'A mailing list' : 'Sorted by the model'}">·</span>`}</div>
          <div>${esc(m.subject)}</div>${m.summary ? `<div class="muted small">${esc(m.summary)}</div>` : `<div class="muted small snippet">${esc(m.snippet.slice(0, 160))}</div>`}</div>
        <div class="mail-acts"><select aria-label="Group">${Object.entries(MAIL_LABELS).map(([v, l]) => `<option value="${v}"${v === m.label ? ' selected' : ''}>${l}</option>`).join('')}</select>
          ${m.label === 'reply' && m.status !== 'done' ? '<button class="btn small" data-a="draft" type="button">Prepare a reply</button>' : ''}
          ${m.status !== 'done' && m.label !== 'low' ? '<button class="btn small" data-a="task" type="button" title="Make it a task on the board">→ Task</button>' : ''}
          <button class="btn small ${m.status === 'done' ? '' : 'primary'}" data-a="${m.status === 'done' ? 'open' : 'done'}" type="button">${m.status === 'done' ? 'Reopen' : 'Done'}</button></div>`;
      el.querySelector('select').onchange = async e => { try { await post(`api/mail/${m.uid}/label`, { label: e.target.value }); toast('Moved: it will remember.'); loadMail(); } catch (err) { toast(err.message); } };
      el.querySelector('[data-a=done], [data-a=open]').onclick = async e => { try { await post(`api/mail/${m.uid}/${e.currentTarget.dataset.a}`); loadMail(); } catch (err) { toast(err.message); } };
      el.querySelector('[data-a=task]')?.addEventListener('click', () => openCard(null, { space: state.space, title: m.subject, due_date: m.due || '',
        body: `From ${m.sender} <${m.email}>, ${new Date(m.ts * 1000).toLocaleDateString(LOCALE)}${m.summary ? '\n\n' + m.summary : ''}`, links: [{ type: 'email', ref: m.uid, label: m.subject }] }));
      el.querySelector('[data-a=draft]')?.addEventListener('click', async () => {
        location.hash = '#chat'; if (state.space) await switchSpace(0);
        $('#input').value = `Prepare a reply to the email from ${m.sender} "${m.subject}" (uid ${m.uid}) and save it as a draft. `; autosize(); $('#input').focus();
      });
      sec.append(el);
    }
    box.append(sec);
  }
}
$('#mail-all').onchange = () => loadMail().catch(e => toast(e.message));
$('#mail-run').onclick = async () => {
  const b = $('#mail-run'); b.disabled = true; $('#mail-state').textContent = 'Checking…';
  try { const r = await post('api/mail/run'); $('#mail-state').textContent = r.sorted ? `${r.sorted} new email(s) sorted.` : 'No new email.'; loadMail(); }
  catch (e) { $('#mail-state').textContent = e.message; }
  b.disabled = false;
};

// ---- people ----
async function loadPeople() {
  const { contacts } = await api('api/contacts?q=' + encodeURIComponent($('#people-q').value.trim()));
  const box = $('#people-list'); box.innerHTML = '';
  if (!contacts.length) { box.innerHTML = '<div class="placeholder">No one yet. People you get emails from appear here, and the assistant adds what it learns.</div>'; return; }
  for (const c of contacts) {
    const el = document.createElement('a'); el.href = '#'; el.className = 'lib-row';
    el.innerHTML = `<span class="ico avatar">${esc((c.name || '?').trim()[0].toUpperCase())}</span><span class="name"><b>${esc(c.name)}</b><span>${esc([c.org, c.email].filter(Boolean).join(' · '))}</span></span><span class="muted small">${c.last_ts ? fmtDate(c.last_ts) : ''}</span>`;
    el.onclick = e => { e.preventDefault(); openPerson(c.id); };
    box.append(el);
  }
}
$('#people-search').onsubmit = e => { e.preventDefault(); loadPeople(); };
$('#people-q').addEventListener('input', () => { clearTimeout(loadPeople.t); loadPeople.t = setTimeout(() => loadPeople().catch(() => {}), 300); });
$('#people-new').onclick = () => showPerson({ contact: { id: 0, name: '', email: '', phone: '', org: '', notes: '' }, emails: [], documents: [], conversations: [] });
async function openPerson(id) { try { showPerson(await api(`api/contacts/${id}`)); } catch (e) { toast(e.message); } }
function showPerson(p) {
  const c = p.contact, box = $('#person'); box.hidden = false;
  box.innerHTML = `<div class="row"><h3>${esc(c.name || 'New contact')}</h3><span class="spacer"></span><button class="btn ghost icon" data-a="close" type="button" aria-label="Close">✕</button></div>
    <form class="fields person-form">
      <label>Name<input name="name" value="${esc(c.name)}" required></label>
      <label>Email<input name="email" type="email" value="${esc(c.email)}"></label>
      <label>Phone<input name="phone" value="${esc(c.phone)}"></label>
      <label>Company<input name="org" value="${esc(c.org)}"></label>
      <label>What you know<textarea name="notes" rows="5">${esc(c.notes)}</textarea></label>
      <div class="row wrap"><button class="btn primary small" type="submit">Save</button>${c.id ? '<button class="btn small" data-a="ask" type="button">Ask about this person</button><span class="spacer"></span><button class="btn ghost danger small" data-a="delete" type="button">Delete</button>' : ''}</div>
    </form>
    ${p.emails.length ? `<h4>Recent emails</h4><ul class="plain">${p.emails.map(m => `<li><span class="muted small">${fmtDate(m.ts)}</span> ${esc(m.subject)} <span class="chip">${MAIL_LABELS[m.label] || m.label}</span></li>`).join('')}</ul>` : ''}
    ${p.documents.length ? `<h4>In your documents</h4><ul class="plain">${p.documents.map(d => `<li>${md(`[${d.name.replace(/[\[\]]/g, '')}${d.page ? ' p. ' + d.page : ''}](/files/${d.id}${d.page ? '#p' + d.page : ''})`)}</li>`).join('')}</ul>` : ''}
    ${p.conversations.length ? `<h4>In your conversations</h4><ul class="plain">${p.conversations.map(t => `<li><span class="muted small">${fmtDate(t.ts)}</span> ${esc(t.text)}</li>`).join('')}</ul>` : ''}`;
  box.querySelector('[data-a=close]').onclick = () => { box.hidden = true; };
  box.querySelector('form').onsubmit = async e => {
    e.preventDefault();
    const body = Object.fromEntries(new FormData(e.target));
    try { const r = await post(c.id ? `api/contacts/${c.id}` : 'api/contacts', body); toast('Saved.'); loadPeople(); openPerson(r.contact.id); } catch (err) { toast(err.message); }
  };
  box.querySelector('[data-a=ask]')?.addEventListener('click', () => { location.hash = '#chat'; $('#input').value = `About ${c.name}: `; autosize(); $('#input').focus(); });
  box.querySelector('[data-a=delete]')?.addEventListener('click', async e => {
    const b = e.currentTarget; if (b.dataset.confirm !== '1') { b.dataset.confirm = '1'; b.textContent = 'Confirm delete'; return; }
    try { await post(`api/contacts/${c.id}/delete`); box.hidden = true; loadPeople(); } catch (err) { toast(err.message); }
  });
}

// ---- activity ----
async function loadHeartbeats(data) {
  const d = data || await api('api/heartbeats');
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
  try { const d = await post('api/heartbeat/run'); loadHeartbeats(d); toast(d.message ? 'Something needs your attention: see the chat.' : 'Nothing needs your attention.'); if (d.message) poll(); }
  catch (e) { toast(e.message); }
  b.disabled = false; b.textContent = 'Check now';
};

/** Bars with a value axis (round steps), a few dates underneath and a tooltip per day. */
function tokenChart(days, byDay, costDay) {
  const max = Math.max(0, ...days.map(d => byDay[d] || 0));
  const step = niceStep(max / 4); const top = Math.max(step, Math.ceil(max / step) * step);
  const ticks = []; for (let v = 0; v <= top + 1e-9; v += step) ticks.push(v);
  const short = d => new Date(d + 'T12:00').toLocaleDateString(LOCALE, { day: 'numeric', month: 'short' });
  return `<div class="chart" role="img" aria-label="Tokens per day over the last 30 days, up to ${nf.format(max)} a day">
    <div class="y-axis">${ticks.map(v => `<span style="bottom:${(v / top * 100).toFixed(2)}%">${ktok(v)}</span>`).join('')}</div>
    <div class="plot">${ticks.map(v => `<i class="grid" style="bottom:${(v / top * 100).toFixed(2)}%"></i>`).join('')}
      <div class="cols">${days.map((d, i) => `<div class="col" data-tip="<b>${short(d)}</b><br>${nf.format(byDay[d] || 0)} tokens${costDay[d] != null ? '<br>' + (money(costDay[d]) || '$0') : ''}">
        <div class="bar${i === days.length - 1 ? ' today' : ''}" style="height:${((byDay[d] || 0) / top * 100).toFixed(2)}%"></div></div>`).join('')}</div>
      <div class="tip" hidden></div></div>
    <div class="x-axis">${days.map((d, i) => `<span>${(days.length - 1 - i) % 7 === 0 ? (i === days.length - 1 ? 'Today' : short(d)) : ''}</span>`).join('')}</div>
  </div>`;
}
function niceStep(raw) {
  if (raw <= 0) return 1000;
  const p = 10 ** Math.floor(Math.log10(raw)), f = raw / p;
  return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * p;
}
function chartHover(chart) {
  if (!chart) return;
  const tip = chart.querySelector('.tip'), plot = chart.querySelector('.plot');
  const show = col => {
    chart.querySelectorAll('.col.on').forEach(c => c.classList.remove('on'));
    if (!col) { tip.hidden = true; return; }
    col.classList.add('on'); tip.innerHTML = col.dataset.tip; tip.hidden = false;
    const pr = plot.getBoundingClientRect(), cr = col.getBoundingClientRect();
    // beside the hovered day, at the top of the plot, so it never hides the bar it describes
    const right = cr.right - pr.left + 6, left = cr.left - pr.left - 6 - tip.offsetWidth;
    tip.style.left = (right + tip.offsetWidth <= pr.width || left < 0 ? Math.min(right, pr.width - tip.offsetWidth) : left) + 'px';
  };
  plot.addEventListener('pointermove', e => show(e.target.closest('.col')));
  plot.addEventListener('pointerleave', () => show(null));
}

async function loadActivity() {
  loadHeartbeats().catch(() => {});
  const { usage, tools, budget, by_tier, by_source, memory, backup } = await api('api/activity');
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
  const days = [];
  for (let i = 29; i >= 0; i--) { const d = new Date(Date.now() - i * 864e5); days.push(d.toLocaleDateString('sv-SE')); }
  const byDay = Object.fromEntries(usage.map(d => [d.day, +d.input + +d.output]));
  const costDay = Object.fromEntries(usage.map(d => [d.day, d.cost == null ? null : +d.cost]));
  $('#usage').innerHTML = `
    <div class="stat"><b>${nf.format(sum('calls'))}</b><span>model calls</span></div>
    <div class="stat"><b>${nf.format(sum('input'))}</b><span>input tokens</span></div>
    <div class="stat"><b>${nf.format(sum('output'))}</b><span>output tokens</span></div>
    <div class="stat"><b>${cost ? '$' + cost.toFixed(2) : '–'}</b><span>${state.status?.provider === 'claude-code' ? 'API-equivalent cost (covered by your plan)' : 'reported cost'}</span></div>
    <div class="bars"><div class="bars-title">Tokens per day <span class="muted small">· last 30 days</span></div>${tokenChart(days, byDay, costDay)}</div>`;
  chartHover($('#usage .chart'));
  $('#tools tbody').innerHTML = tools.length ? tools.map(t => `<tr><td>${fmtTime(t.ts)}</td><td>${esc(t.tool)}</td><td><code>${esc(t.args.slice(0, 200))}</code></td><td>${esc(t.result.slice(0, 220))}</td></tr>`).join('')
    : '<tr><td colspan="4" class="muted">No tool calls yet.</td></tr>';
}

// ---- shell ----
const VIEWS = { chat: null, board: loadBoard, library: loadLibrary, tasks: loadTasks, mail: loadMail, people: loadPeople, jobs: loadJobs, memory: loadMemory, settings: loadSettings, activity: loadActivity, privacy: loadPrivacy };
function route() {
  const h = location.hash.slice(1) === 'channels' ? 'settings' : location.hash.slice(1).split('/')[0];
  const v = (h in VIEWS) ? h : 'chat';
  for (const k of Object.keys(VIEWS)) $('#view-' + k).hidden = k !== v;
  document.querySelectorAll('.rail a').forEach(a => a.classList.toggle('active', a.dataset.view === v));
  $('#more-btn').classList.toggle('active', ['jobs', 'activity', 'settings', 'mail', 'people', 'tasks', 'privacy'].includes(v));
  $('#more-sheet').hidden = true;
  if (v !== 'library') closeViewer();
  if (v !== 'board') { clearTimeout(loadBoard.t); }
  VIEWS[v]?.().catch(e => toast(e.message));
  if (v === 'chat') {
    const slug = (location.hash.match(/^#chat\/([\w-]+)/) || [])[1];
    const sp = slug && state.spaces.find(x => x.slug === slug);
    if (sp && sp.id !== state.space) switchSpace(sp.id, { keepHash: true });
    setTimeout(() => { $('#thread').scrollTop = $('#thread').scrollHeight; }, 0);
  }
}

async function refreshStatus() {
  try {
    const s = state.status = await api('api/status');
    $('#brand-name').textContent = s.name; document.title = s.name;
    const ch = Object.entries(s.channels).filter(([, on]) => on).map(([k]) => k[0].toUpperCase() + k.slice(1)).join(', ');
    const b = s.budget, lim = b.daily_limit > 0 ? ` · $${b.today.toFixed(2)} of $${b.daily_limit.toFixed(2)} today` : '';
    $('#chat-sub').textContent = `${shortModel(s.model)} · ${ch}${lim}${b.state === 'low' ? ' · budget low' : b.state === 'blocked' ? ' · budget reached' : ''}`;
    const st = $('#status'); st.className = 'status ' + (s.worker_online ? 'on' : 'off');
    st.querySelector('span').textContent = s.worker_online ? 'Running' : 'Worker offline';
    st.title = s.worker_online ? 'Background worker is running: schedules and chat apps work.' : 'Background worker is not running: schedules and Telegram are paused. Check: systemctl status talixio-worker';
    for (const el of [$('#app-version'), $('#more-version')]) { el.textContent = 'Talixio ' + s.version; el.title = s.version_label; }
    $('#setup-model').hidden = !s.model_problem;
    $('#setup-claude').hidden = !s.claude_code;
    if (JSON.stringify(s.paused || null) !== $('#paused').dataset.p) { $('#paused').dataset.p = JSON.stringify(s.paused || null); renderPaused(s.paused); }
  } catch {}
}

function showLogin() { $('#shell').hidden = true; $('#login').hidden = false; clearInterval(state.pollTimer); }

// ---- the menu on the left folds down to its icons ----
function railCollapsed() {
  let v = null; try { v = localStorage.getItem(LSK + 'talixio.rail'); } catch {}
  return v ? v === 'icons' : innerWidth < 1100; // narrow desktop windows start folded
}
function applyRail(collapsed) {
  $('#shell').classList.toggle('collapsed', collapsed);
  const t = $('#rail-toggle'); const label = collapsed ? 'Expand the menu' : 'Collapse the menu';
  t.title = label; t.setAttribute('aria-label', label); t.setAttribute('aria-expanded', String(!collapsed));
  // names stay reachable as tooltips and for screen readers
  document.querySelectorAll('.rail a, #logout').forEach(a => { const n = a.querySelector('span')?.textContent || ''; if (collapsed) a.title = n; else a.removeAttribute('title'); a.setAttribute('aria-label', n); });
}
$('#rail-toggle').onclick = () => {
  const c = !$('#shell').classList.contains('collapsed');
  try { localStorage.setItem(LSK + 'talixio.rail', c ? 'icons' : 'full'); } catch {}
  applyRail(c);
};
applyRail(railCollapsed());

let start = startApp;
async function startApp() {
  $('#login').hidden = true; $('#shell').hidden = false;
  await refreshStatus();
  await loadSpaces();
  const fromHash = location.hash.match(/^#chat\/([\w-]+)/);
  let sp = fromHash ? state.spaces.find(x => x.slug === fromHash[1]) : null;
  if (!sp) { let saved = 0; try { saved = +(localStorage.getItem(LSK + 'talixio.space') || 0); } catch {} sp = state.spaces.find(x => x.id === saved && !x.archived); }
  await switchSpace(sp ? sp.id : 0, { keepHash: !fromHash });
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
    await post('api/login', { password: $('#password').value, code: $('#login-code').value });
    $('#password').value = $('#login-code').value = ''; $('#code-row').hidden = true;
    await start(); if (!previewId) handleLaunch();
  } catch (err) {
    $('#login-error').textContent = err.message;
    if (err.data?.need_code) { $('#code-row').hidden = false; $('#login-code').value = ''; $('#login-code').focus(); }
  }
};
$('#logout').onclick = async () => { try { await post('api/logout'); } catch {} showLogin(); };
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
    const { results } = await post('api/notify/test');
    $('#test-result').textContent = Object.entries(results).filter(([, r]) => r === 'sent' || r.startsWith('error')).map(([k, r]) => `${k}: ${r}`).join(' · ') || 'No notification channel is set up yet.';
    loadChannels();
  } catch (e) { $('#test-result').textContent = e.message; }
};
$('#search-form').onsubmit = e => {
  e.preventDefault();
  const q = $('#search-q').value.trim(); const box = $('#search-results');
  if (!q) { twoPassStop('memory'); box.hidden = true; return; }
  box.hidden = false;
  const hit = r => `<div class="hit"><div class="meta"><span class="chip">${esc(r.label)}</span><span>${new Date(r.ts * 1000).toLocaleString(LOCALE, { dateStyle: 'medium', timeStyle: 'short' })}</span>${r.sim != null ? `<span>${Math.round(r.sim * 100)}% similar</span>` : ''}</div>${esc(r.text.slice(0, 600))}</div>`;
  twoPass('memory', 'api/search?q=' + encodeURIComponent(q), (results, phase, err) => {
    if (phase === 'error') { box.innerHTML = `<div class="placeholder">${esc(err.message)}</div>`; return; }
    if (!results) { box.innerHTML = searchWait('Searching…'); return; }
    box.innerHTML = (phase === 'meaning' ? searchWait('Searching by meaning…') : '') + results.map(hit).join('') + (phase === 'done' && !results.length ? '<div class="placeholder">Nothing found.</div>' : '');
  });
};
$('#skill-save').onclick = async () => {
  try { await post('api/skills/' + encodeURIComponent(state.skill), { content: $('#skill-editor').value }); $('#skill-state').textContent = 'Saved'; loadSkills(); }
  catch (e) { toast(e.message); }
};
$('#skill-cancel').onclick = () => { $('#skill-edit').hidden = true; };
$('#mem-editor').addEventListener('input', () => { $('#mem-state').textContent = 'Unsaved changes'; });
$('#mem-save').onclick = async () => {
  try {
    await post('api/memory', { file: state.memFile, content: $('#mem-editor').value });
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
  const { folder } = await post('api/library/folder', { path: v }); toast('Folder created'); libGo(folder);
});
$('#lib-inline-cancel').onclick = () => { $('#lib-inline').hidden = true; };
['dragover', 'dragenter'].forEach(t => $('#lib-main').addEventListener(t, e => { if (e.dataTransfer?.types.includes('Files')) { e.preventDefault(); $('#lib-main').classList.add('drag'); } }));
$('#lib-main').addEventListener('dragleave', e => { if (!$('#lib-main').contains(e.relatedTarget)) $('#lib-main').classList.remove('drag'); });
$('#lib-main').addEventListener('drop', e => { e.preventDefault(); $('#lib-main').classList.remove('drag'); libUpload([...(e.dataTransfer?.files || [])]); });
/**
 * Library search: keywords first (instant), then by meaning (slower: the local model), which completes the list.
 * One search at a time: a new one, another folder or Cancel stops it, and an answer that comes late is dropped.
 * While results are shown, the folder's list is hidden: never both.
 */
const SPIN_SVG = '<svg class="spin" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a9 9 0 1 0 9 9"/></svg>';
function libSearchRender() {
  const box = $('#lib-results'), { q, folder, phase } = libSearch;
  box.hidden = false; $('#lib-list').hidden = true; box.innerHTML = '';
  const where = folder !== '/' ? ' ' + t('in {folder}', { folder }) : '';
  const head = document.createElement('div'); head.className = 'search-head';
  const names = libSearch.names || { files: [], folders: [] };
  const byName = new Set(names.files.map(f => f.id));
  const results = libSearch.results?.filter(r => !byName.has(r.id)); // a file found by its name is listed once
  const n = (results?.length || 0) + names.files.length + names.folders.length;
  const found = () => n === 1 ? t('1 result for “{q}”', { q }) : t('{n} results for “{q}”', { n, q });
  const status = phase === 'quick' ? t('Searching for “{q}”…', { q }) + where
    : phase === 'meaning' ? (n ? found() : t('No word match for “{q}”', { q })) + where + ' · ' + t('looking by meaning…')
    : (n ? found() : t('Nothing found for “{q}”', { q })) + where + (phase === 'stopped' ? ' · ' + t('word matches only (stopped)') : '');
  head.innerHTML = `${phase === 'quick' || phase === 'meaning' ? SPIN_SVG : ''}<span class="small">${esc(status)}</span><span class="spacer"></span>`
    + (phase === 'quick' || phase === 'meaning' ? `<button class="btn small" type="button" data-a="stop">${esc(t('Cancel'))}</button>` : '')
    + `<button class="btn small ghost" type="button" data-a="close">${esc(t('Close the search'))}</button>`;
  head.querySelector('[data-a=stop]')?.addEventListener('click', () => libSearchStop());
  head.querySelector('[data-a=close]').onclick = () => libSearchClose();
  box.append(head);
  if (names.files.length || names.folders.length) { // "where is…": matching names first
    const sub = document.createElement('div'); sub.className = 'search-sub muted small'; sub.textContent = t('By name'); box.append(sub);
    const list = document.createElement('div'); list.className = 'lib-list';
    for (const d of names.folders) {
      const el = document.createElement('a'); el.href = '#'; el.className = 'lib-row';
      el.innerHTML = `<span class="ico folder">${FOLDER_SVG}</span><span class="name"><b>${esc(d.path.split('/').pop())}</b><span class="snippet">${esc(d.path)} · ${esc(d.total === 1 ? t('1 file') : t('{n} files', { n: d.total }))}</span></span>`;
      el.onclick = e => { e.preventDefault(); libSearchClose(); libGo(d.path); };
      list.append(el);
    }
    for (const f of names.files) list.append(fileRow({ ...f, size: 0, created: 0, status: 'ready' }, f.folder));
    box.append(list);
    if (results?.length) { const s2 = document.createElement('div'); s2.className = 'search-sub muted small'; s2.textContent = t('In the content'); box.append(s2); }
  }
  if (results?.length) {
    const list = document.createElement('div'); list.className = 'lib-list';
    for (const r of results) list.append(fileRow({ ...r, size: 0, created: 0, status: 'ready' }, `${joinPath(r.folder, r.name)} · ${r.snippet}`));
    box.append(list);
  }
}
/** Stop the running search; what was found so far stays (nothing yet: back to the folder). */
function libSearchStop() {
  if (!libSearch.ctl) return;
  libSearch.ctl.abort(); libSearch.ctl = null;
  if (libSearch.phase === 'quick') return libSearchClose();
  libSearch.phase = 'stopped'; libSearchRender();
}
/** Back to the folder's list. */
function libSearchClose() {
  libSearch.ctl?.abort(); libSearch.ctl = null; libSearch.phase = ''; libSearch.results = null; libSearch.names = null;
  $('#lib-results').hidden = true; $('#lib-results').innerHTML = ''; $('#lib-list').hidden = false;
}
$('#lib-search').onsubmit = async e => {
  e.preventDefault();
  libSearch.ctl?.abort(); libSearch.ctl = null; // a new search replaces the running one
  const q = $('#lib-q').value.trim();
  if (!q) return libSearchClose();
  const folder = $('#lib-scope').checked ? lib.folder : '/';
  const ctl = new AbortController();
  Object.assign(libSearch, { ctl, q, folder, results: null, names: null, phase: 'quick' });
  libSearchRender();
  const url = `api/library/search?q=${encodeURIComponent(q)}&folder=${encodeURIComponent(folder)}`;
  try {
    const quick = await api(url + '&mode=quick', { signal: ctl.signal });
    if (libSearch.ctl !== ctl) return; // stopped, or another search started
    libSearch.results = quick.results; libSearch.names = quick.names;
    if (!quick.more) { libSearch.ctl = null; libSearch.phase = 'done'; return libSearchRender(); }
    libSearch.phase = 'meaning'; libSearchRender();
    const full = await api(url, { signal: ctl.signal });
    if (libSearch.ctl !== ctl) return;
    libSearch.ctl = null; libSearch.results = full.results; libSearch.phase = 'done'; libSearchRender();
  } catch (err) {
    if (err.name === 'AbortError' || libSearch.ctl !== ctl) return;
    libSearch.ctl = null;
    if (libSearch.phase === 'meaning') { libSearch.phase = 'stopped'; libSearchRender(); toast(err.message); }
    else { libSearchClose(); toast(err.message); }
  }
};
document.addEventListener('keydown', e => { // Esc: stops a running search (then closes it), unless a document or a dialog is open
  if (e.key !== 'Escape' || !libSearch.phase || $('#view-library').hidden || !$('#viewer').hidden || document.querySelector('dialog[open]')) return;
  e.preventDefault(); libSearch.ctl ? libSearchStop() : libSearchClose();
});
$('#viewer-close').onclick = closeViewer;
document.addEventListener('keydown', e => { if (e.key === 'Escape' && !$('#viewer').hidden) closeViewer(); });
$('#viewer-form-cancel').onclick = () => { $('#viewer-form').hidden = true; };
$('#viewer-rename').onclick = () => viewerForm(lib.file.name, async v => { const { file } = await post(`api/files/${lib.file.id}/rename`, { name: v }); await loadLibrary(); openFile(file.id); });
$('#viewer-move').onclick = () => viewerForm(lib.file.folder, async v => { const { file } = await post(`api/files/${lib.file.id}/move`, { folder: v }); toast('Moved to ' + file.folder); await loadLibrary(); openFile(file.id); }, true);
$('#viewer-reindex').onclick = async () => { try { await post(`api/files/${lib.file.id}/reindex`); toast('It will be read again in a moment'); await loadLibrary(); openFile(lib.file.id); } catch (e) { toast(e.message); } };
$('#viewer-delete').onclick = async () => {
  const b = $('#viewer-delete');
  if (b.dataset.confirm !== '1') { b.dataset.confirm = '1'; b.textContent = 'Confirm delete'; return; }
  try { await post(`api/files/${lib.file.id}/delete`); toast(t('Moved to the trash: restorable for {n} days', { n: 30 })); closeViewer(); loadLibrary(); } catch (e) { toast(e.message); }
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
    const reg = await navigator.serviceWorker.register('sw.js', { scope: './', updateViaCache: 'none' });
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
  if (params.has('google')) {
    const g = params.get('google');
    history.replaceState(null, '', './#settings');
    toast(g === 'connected' ? 'Google Calendar connected.' : g);
    if (g === 'connected') setTimeout(() => { $('#google-card').open = true; $('#google-card').scrollIntoView({ block: 'center' }); }, 600);
  }
  if (params.has('mcp')) {
    const g = params.get('mcp');
    history.replaceState(null, '', './#settings');
    toast(g.startsWith('connected:') ? `Connected to ${g.slice(10)}: its tools are available from the next message.` : g);
    setTimeout(() => { $('#mcp-card').open = true; $('#mcp-card').scrollIntoView({ block: 'center' }); }, 600);
  }
  if (params.has('spotify')) {
    const g = params.get('spotify');
    history.replaceState(null, '', './#settings');
    toast(g === 'connected' ? 'Spotify connected.' : g);
    setTimeout(() => { $('#spotify-card').open = true; $('#spotify-card').scrollIntoView({ block: 'center' }); }, 600);
  }
  if (params.has('share') || params.has('action') || params.has('source')) history.replaceState(null, '', '/' + location.hash);
  if (token) {
    try {
      const d = await post('api/share/claim', { token });
      if (d.text) { $('#input').value = d.text; autosize(); }
      for (const f of d.files) { state.pending.push({ id: f.id, info: f }); }
      renderPending();
      location.hash = '#chat';
      toast(d.skipped ? `${d.skipped} file(s) were too large to share this way (max ${d.max_mb} MB): attach them with the paperclip.` : 'Shared content ready: add a message if you like, then send.');
      $('#input').focus();
    } catch (e) { toast(e.message); }
  }
  if (params.get('action') === 'capture') { location.hash = '#chat'; openCapture(); }
  if (params.get('action') === 'record') {
    location.hash = '#chat';
    banner('Ready to record a meeting.', 'Start recording', () => { banner(''); startRecording(); });
  }
}

/** Quick capture: a task on the board or a note in the library in one step, without the assistant ("t: …" / "n: …" in the chat does the same). */
let captureKind = 'task';
function openCapture(kind) {
  const d = $('#capture-dialog');
  setCaptureKind(kind || captureKind);
  if (!d.open) d.showModal();
  $('#cap-text').focus();
}
function setCaptureKind(kind) {
  captureKind = kind;
  document.querySelectorAll('#capture-dialog .seg button').forEach(b => b.classList.toggle('on', b.dataset.kind === kind));
  const sp = spaceOf(state.space);
  $('#cap-text').placeholder = t(kind === 'task' ? 'Call the bank about the loan !2' : 'Idea for the newsletter: …');
  $('#cap-hint').textContent = kind === 'task'
    ? t('First line: the title; the next lines: the description. !1 to !5 sets the priority. Goes to the board of {space}.', { space: sp.name })
    : t('Saved as a note in {folder}. The first line gives its name.', { folder: sp.folder || '/Inbox' });
}
document.querySelectorAll('#capture-dialog .seg button').forEach(b => b.onclick = () => { setCaptureKind(b.dataset.kind); $('#cap-text').focus(); });
$('#capture-btn').onclick = () => openCapture();
$('#cap-close').onclick = () => $('#capture-dialog').close();
$('#cap-text').addEventListener('keydown', e => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); $('#capture-form').requestSubmit(); } });
$('#capture-form').onsubmit = async e => {
  e.preventDefault();
  const text = $('#cap-text').value.trim();
  if (!text) return;
  $('#cap-save').disabled = true;
  try {
    const r = await post('api/capture', { kind: captureKind, text, space: state.space });
    $('#cap-text').value = '';
    $('#capture-dialog').close();
    toast(r.reply.replace(/\[([^\]]+)\]\([^)]*\)/g, '$1'));
    if (location.hash.startsWith('#board') && typeof loadBoard === 'function') loadBoard();
  } catch (err) { toast(err.message); }
  finally { $('#cap-save').disabled = false; }
};

/** /preview/ID/name: one document full screen ("Open in a new tab"); the address keeps the file name. */
const previewId = (location.pathname.slice(BASE.length).match(/^\/preview\/(\d+)/) || [])[1];
async function startPreview() {
  $('#login').hidden = true; $('#shell').hidden = true; $('#preview-page').hidden = false;
  try {
    const { file: f } = await api(`api/files/${previewId}/text`);
    document.title = f.name;
    $('#pp-name').textContent = f.name; $('#pp-path').textContent = f.folder;
    $('#pp-download').href = fileUrl(f) + '?download=1'; $('#pp-download').setAttribute('download', f.name);
    $('#pp-library').href = './#library' + (f.folder === '/' ? '' : f.folder.split('/').map(encodeURIComponent).join('/'));
    if (RENDERED.includes(f.kind)) { $('#pp-frame').hidden = false; await fillPreview($('#pp-frame'), f.id); }
    else location.replace(fileUrl(f)); // photos, PDFs, audio: the browser shows them itself
  } catch (e) { $('#pp-name').textContent = e.message; }
}
if (previewId) start = startPreview; // after sign-in, come back to the document

registerServiceWorker();
api('api/status').then(async () => { await start(); if (!previewId) handleLaunch(); }).catch(() => showLogin());
