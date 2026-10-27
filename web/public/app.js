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
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

const INTENT_HREF = /^intent:\/\/#Intent;package=[a-z]\w*(?:\.[a-z]\w*)+;S\.browser_fallback_url=https%3A%2F%2Fplay\.google\.com%2F[\w%.-]*;end$/i;
const INTENT_MD = new RegExp(String.raw`\[([^\]]+)\]\((${INTENT_HREF.source.slice(1, -1)})\)`, 'gi');
const CITE_SVG = '<svg viewBox="0 0 24 24"><path d="M6 3h9l3 3v15H6z"/><path d="M9 12h6M9 16h6"/></svg>';
// Safe Markdown: escapes everything first, then adds formatting (headings, lists with nesting and
// checkboxes, tables, quotes, rules, code, links, citations, phone buttons).
function md(src) {
  const blocks = [];
  const keep = html => { blocks.push(html); return `\u0000${blocks.length - 1}\u0000`; };
  let s = esc(String(src).replace(/\r\n?/g, '\n').replace(/\t/g, '    '))
    .replace(/^ {0,3}```[\w+-]*\n([\s\S]*?)\n? {0,3}```[ ]*$/gm, (_, code) => '\n' + keep(`<pre><code>${code}</code></pre>`) + '\n');
  // links are made first and kept aside: the formatting after them never reaches inside an address or a tag
  const fmt = t => t
    .replace(/\*\*([^*\n]+?)\*\*|__([^_\n]+?)__/g, (_, a, b) => `<strong>${a ?? b}</strong>`)
    .replace(/(^|[^*\w])\*([^*\n]+?)\*(?!\w)/g, '$1<em>$2</em>')
    .replace(/~~([^~\n]+)~~/g, '<del>$1</del>');
  const link = (href, cls, t, blank) => keep(`<a href="${href}"${cls ? ` class="${cls}"` : ''}${blank ? ' target="_blank" rel="noopener"' : ''}>${fmt(t)}</a>`);
  const inline = t => fmt(t
    .replace(/`([^`\n]+)`/g, (_, c) => keep(`<code>${c}</code>`))
    .replace(/\\(&gt;|&amp;|[\\`*_\[\]()#+\-.!|~])/g, (_, c) => keep(c)) // \* is a plain star
    .replace(/\[([^\]]+)\]\(\/files\/(\d+)(?:#p(\d+))?\)/g, (_, t, id, pg) => keep(`<a href="#" class="cite" data-file="${id}"${pg ? ` data-page="${pg}"` : ''} title="Open the document${pg ? ' at page ' + pg : ''}">${CITE_SVG}${fmt(t)}</a>`))
    .replace(/\[([^\]]+)\]\((#library(?:\/[^\s)"]*)?)\)/g, (_, t, h) => keep(`<a href="${h}" class="cite folder">${FOLDER_SVG}${fmt(t)}</a>`)) // a library folder ("where is…?")
    // buttons that open an app on the phone (phone_action, Spotify): custom schemes only from this list
    .replace(/\[([^\]]+)\]\(((?:tel|sms|mailto|geo|spotify|deezer|tg|sgnl|uber|homeassistant):[^\s)"]+)\)/g, (_, t, h) => link(h, 'action', t.replace(/^▶\s*/, '')))
    .replace(INTENT_MD, (_, t, h) => link(h, 'action', t.replace(/^▶\s*/, ''))) // Android intents: only "open this app, or its Play Store page"
    .replace(/\[([^\]]+)\]\((\?connector=[^\s)"]+)\)/g, (_, t, h) => link(h, 'action connector', t.replace(/^▶\s*/, ''))) // "▶ Install" from the connector list
    .replace(/\[▶\s*([^\]]+)\]\((https?:\/\/[^\s)"]+)\)/g, (_, t, h) => link(h, 'action', t, true))
    .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)"]+)\)/g, (_, t, h) => link(h, '', t, true))
    .replace(/(^|[\s(])(https?:\/\/[^\s<)"\u0000]+)/g, (_, pre, h) => pre + link(h, '', h, true)));
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
  if (m.role === 'assistant' && m.channel === 'approval' && m.content.includes('\n\n')) { // a tool's raw result: folded, the chat stays for the conversation
    const i = m.content.indexOf('\n\n');
    el.className = 'jobcard';
    el.innerHTML = `<details><summary><svg viewBox="0 0 24 24"><path d="M5 12l4 4 10-10"/></svg><span>${esc(m.content.slice(0, i))} · ${fmtTime(m.ts)} · ${esc(t('show the result'))}</span></summary><div class="report doc">${md(m.content.slice(i + 2))}</div></details>`;
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
  if (!document.hidden && Date.now() - (state.statusAt || 0) > 30000) refreshStatus(); // new notifications, worker state
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
/** The composer's hint: the attached document to ask about, or the space and its confidentiality. */
function inputPlaceholder() {
  const sp = spaceOf(state.space), lvl = levelOf(sp);
  const doc = state.pending.length === 1 && state.pending[0].id ? state.pending[0].info.name : '';
  $('#input').placeholder = doc ? t('Ask about {a}…', { a: doc }) : lvl === 'secret' ? t('Secret · nothing leaves the server')
    : lvl === 'confidential' ? t('Confidential · only the AI model receives your messages')
    : !sp.id ? t('Message {a}…', { a: state.status?.name || 'Talixio' }) : t('Message in {a}…', { a: sp.name });
}
function renderSpaceHead() {
  const sp = spaceOf(state.space), general = !sp.id;
  $('#chat-title').textContent = general ? (state.status?.name || 'Talixio') : sp.name;
  const lvl = levelOf(sp);
  inputPlaceholder();
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
  await Promise.all([loadSpaces(), loadAiAccounts()]);
  const active = state.spaces.filter(x => !x.archived), archived = state.spaces.filter(x => x.archived);
  const item = x => `<button class="space-item${x.id === state.space ? ' current' : ''}" role="menuitem" type="button" data-id="${x.id}">
      <i style="background:${x.color || 'var(--muted)'}"></i><span class="name">${esc(x.id ? x.name : 'General')}${x.folder ? ` <span class="folder">${esc(x.folder)}</span>` : ''}</span>${x.unread ? `<span class="badge">${x.unread}</span>` : ''}</button>`;
  menu.innerHTML = active.map(item).join('')
    + '<hr>' + (state.space ? '<button class="space-item" type="button" data-act="edit">Edit this space…</button>'
      : `<label class="space-item gen-tier">${esc(t('Model for General'))} ${tierSelect(spaceOf(0)?.tier || 'standard', t('Model for General'))}</label>`
        + (state.aiAccounts?.length ? `<label class="space-item gen-tier gen-account">${esc(t('AI account'))} <select class="tier-select" aria-label="${esc(t('AI account'))}">${accountOptions(spaceOf(0)?.account || '')}</select></label>` : ''))
    + '<button class="space-item" type="button" data-act="new">+ New space</button>'
    + '<button class="space-item" type="button" data-act="import">Import a space…</button>'
    + (archived.length ? `<details><summary class="muted small" style="padding:6px 10px">Archived (${archived.length})</summary>${archived.map(item).join('')}</details>` : '');
  menu.querySelectorAll('[data-id]').forEach(b => b.onclick = () => { menu.hidden = true; switchSpace(+b.dataset.id); });
  menu.querySelector('[data-act=new]').onclick = () => { menu.hidden = true; openSpaceEditor(null); };
  menu.querySelector('[data-act=import]').onclick = () => { menu.hidden = true; $('#space-import').value = ''; $('#space-import').click(); };
  menu.querySelector('[data-act=edit]')?.addEventListener('click', () => { menu.hidden = true; openSpaceEditor(spaceOf(state.space)); });
  menu.querySelector('.gen-tier')?.addEventListener('click', e => e.stopPropagation());
  menu.querySelector('.gen-account select')?.addEventListener('change', async e => {
    try { await post('api/spaces/0/account', { account: e.target.value }); await loadSpaces(); toast(t('General now uses {account}.', { account: accountName(e.target.value) })); }
    catch (err) { toast(err.message); }
  });
  menu.querySelector('.gen-tier:not(.gen-account) select')?.addEventListener('change', async e => {
    try { await post('api/spaces/0/tier', { tier: e.target.value }); await loadSpaces(); toast(t('General now answers with the {tier} model.', { tier: t(e.target.value) })); }
    catch (err) { toast(err.message); }
  });
  menu.hidden = false; $('#space-btn').setAttribute('aria-expanded', 'true');
};
/** The AI accounts a space can use (Settings → AI model); quietly empty when they cannot be read. */
async function loadAiAccounts() {
  try { state.aiAccounts = (await api('api/accounts')).accounts; } catch { state.aiAccounts ||= []; }
  return state.aiAccounts;
}
const accountName = id => id ? (state.aiAccounts || []).find(a => a.id === id)?.name || id : t('the main settings');
function accountOptions(current) {
  return `<option value="">${esc(t('Main settings'))}</option>` + (state.aiAccounts || []).map(a =>
    `<option class="no-i18n" value="${esc(a.id)}"${a.id === current ? ' selected' : ''}>${esc(a.name)} · ${esc(t(a.label))}</option>`).join('');
}
document.addEventListener('click', e => { if (!e.target.closest('.space-head')) { $('#space-menu').hidden = true; $('#space-btn').setAttribute('aria-expanded', 'false'); } });

/** One line of a space's history: "archived 10/10 14:02 (app)". */
function spaceEvent(e) {
  const what = { created: t('space created'), renamed: t('renamed (was {name})', { name: e.detail || '' }), archived: t('archived'), restored: t('restored'),
    privacy: t('confidentiality: {level}', { level: t(LEVELS[e.detail]?.label || e.detail || '') }), folder: t('folder: {folder}', { folder: e.detail || '/' }),
    account: t('AI account: {name}', { name: e.detail === 'main settings' ? t('the main settings') : e.detail || '' }),
    moved: t('moved from {name}', { name: e.detail || '' }) }[e.what] || e.what;
  return `${what} ${new Date(e.ts * 1000).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' })} (${e.by === 'assistant' ? t('by the assistant') : t('in the app')})`;
}

/** The fleet-orchestrator connect/bootstrap panel in the space editor. The binding is one per assistant (shared by
 * every space); each space governs separately whether it may dispatch (shown by paintLevel). */
async function initOrchestratorPanel(prefix = 'sp', onRender) {
  const steps = $(`#${prefix}-orch-steps`), connect = $(`#${prefix}-orch-connect`), disc = $(`#${prefix}-orch-disconnect`);
  steps.hidden = true; steps.textContent = '';
  const ago = ts => { if (!ts) return 'never'; const d = Math.max(0, Date.now() / 1000 - ts); return d < 90 ? 'just now' : d < 3600 ? `${Math.floor(d / 60)} min ago` : d < 86400 ? `${Math.floor(d / 3600)} h ago` : `${Math.floor(d / 86400)} d ago`; };
  const render = info => {
    const on = !!info.connected;
    $(`#${prefix}-orch-status`).textContent = on
      ? `Connected${info.session ? ` (session ${info.session})` : ''}. Last sent ${ago(info.lastDispatch)}; last report ${ago(info.lastReport)}. It reports back to ${info.inbox}.`
      : 'Not connected. Run a Claude Code session with Remote Control on — on your own host is fine — and connect it here to delegate code work across your machines.';
    disc.hidden = !on;
    connect.textContent = on ? 'Re-pair…' : 'Connect…';
    onRender?.(info);
  };
  try { render(await api('api/conductor')); } catch { render({ connected: false, inbox: '' }); }
  connect.onclick = async () => {
    try { steps.textContent = (await post('api/conductor/setup', {})).instruction; steps.hidden = false; }
    catch (err) { toast(err.message); }
  };
  disc.onclick = async () => {
    if (disc.dataset.confirm !== '1') { disc.dataset.confirm = '1'; disc.textContent = t('Disconnect it?'); return; }
    disc.dataset.confirm = ''; disc.textContent = t('Disconnect');
    try { const r = await post('api/conductor/disconnect', {}); steps.hidden = true; render(r); toast(r.message || 'Disconnected.'); }
    catch (err) { toast(err.message); }
  };
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
  await loadAiAccounts();
  $('#sp-account').innerHTML = accountOptions(editing ? sp.account || '' : '');
  $('#sp-account-row').hidden = !state.aiAccounts.length && !(editing && sp.account);
  $('#sp-hub-row').hidden = !state.status?.fleet;
  $('#sp-hub-pull').checked = !!(editing && sp.hub_pull);
  $('#sp-hub-contribute').checked = !!(editing && sp.hub_contribute);
  initOrchestratorPanel();
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
    const note = $('#sp-orch-dispatch');
    note.hidden = false;
    note.textContent = level === 'internal'
      ? 'This space may dispatch work to the connected orchestrator.'
      : `This space is ${level}; it cannot dispatch outward (connect and dispatch from an internal space). The orchestrator connection itself is shared by the whole assistant.`;
  };
  paintLevel();
  $('#sp-archive').hidden = $('#sp-delete').hidden = $('#sp-move').hidden = !editing;
  $('#sp-move').onclick = () => { d.close(); openMoveDialog(sp); };
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
    if (!$('#sp-account-row').hidden) body.account = $('#sp-account').value;
    try {
      const { space } = await post(editing ? `api/spaces/${sp.id}` : 'api/spaces', body);
      if (!$('#sp-hub-row').hidden) {
        const pull = $('#sp-hub-pull').checked, contribute = $('#sp-hub-contribute').checked;
        if (pull !== !!(editing && sp.hub_pull) || contribute !== !!(editing && sp.hub_contribute))
          await post(`api/spaces/${space.id}/hub`, { pull, contribute });
      }
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

/** A space's package (.zip): everything about it, for another assistant. */
async function spacePackage(id) {
  const res = await fetch(`api/spaces/${id}/export`, { credentials: 'same-origin', headers: { 'X-Talixio': '1' } });
  if (res.status === 401) { showLogin(); throw new Error(t('Please sign in.')); }
  if (!res.ok) { const data = await res.json().catch(() => ({})); throw new Error(t(data.error || `Request failed (${res.status})`)); }
  const name = (res.headers.get('Content-Disposition') || '').match(/filename="?([^";]+)/)?.[1] || `space-${id}.zip`;
  return { blob: await res.blob(), name };
}
function saveBlob({ blob, name }) {
  const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name;
  document.body.append(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 60000);
}

/** Move a space to another of the person's assistants (sent from this page), or by a downloaded package. */
function openMoveDialog(sp) {
  const d = $('#move-dialog'), list = state.assistants || [];
  let pkg = null, done = false;
  const say = (msg, bad = false) => { $('#move-state').textContent = msg; $('#move-state').classList.toggle('danger', bad); };
  $('#move-title').textContent = t('Move “{name}”', { name: sp.name });
  $('#move-to').innerHTML = list.map(a => `<option class="no-i18n" value="${esc(a.id)}">${esc(a.name)}</option>`).join('');
  $('#move-to-row').hidden = $('#move-go').hidden = !list.length;
  $('#move-help').hidden = !!list.length;
  $('#move-open').hidden = $('#move-remove').hidden = true; $('#move-download').hidden = false; $('#move-go').disabled = false;
  $('#move-remove').dataset.confirm = ''; $('#move-remove').textContent = t('Remove it here');
  $('#move-close').textContent = t('Cancel');
  say('');
  const pack = async () => pkg ||= await spacePackage(sp.id);
  const finished = async () => {
    done = true;
    $('#move-go').hidden = $('#move-download').hidden = $('#move-remove').hidden = true; $('#move-to-row').hidden = true;
    $('#move-close').textContent = t('Close');
    await loadSpaces(); if (state.space === sp.id) await switchSpace(0);
  };
  $('#move-download').onclick = async () => {
    try { say(t('Packing the space…')); saveBlob(await pack()); say(t('Downloaded. Import it in the other assistant, then remove it here.')); $('#move-remove').hidden = false; }
    catch (err) { say(err.message, true); }
  };
  $('#move-remove').onclick = async e => {
    const b = e.currentTarget;
    if (b.dataset.confirm !== '1') { b.dataset.confirm = '1'; b.textContent = t('Is it in the other assistant? Remove it here'); return; }
    try { await post(`api/spaces/${sp.id}/moved`); await finished(); say(t('Removed here. Its folder is in the trash.')); }
    catch (err) { say(err.message, true); }
  };
  $('#move-form').onsubmit = async e => {
    e.preventDefault();
    const to = $('#move-to').value, name = list.find(a => a.id === to)?.name || to;
    $('#move-go').disabled = true;
    let got;
    try {
      say(t('Packing the space…')); await pack();
      say(t('Sending it to {name}…', { name }));
      const tk = await post(`api/spaces/${sp.id}/move-ticket`, { to });
      let res;
      try { res = await fetch(tk.url + '?t=' + encodeURIComponent(tk.t), { method: 'POST', headers: { 'X-Talixio': '1', 'Content-Type': 'application/zip' }, body: pkg.blob }); }
      catch { throw new Error(t('{name} could not be reached from this page. Download the package and import it there instead.', { name })); }
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(t(data.error || `Request failed (${res.status})`));
      got = data.space;
    } catch (err) { say(err.message, true); $('#move-go').disabled = false; $('#move-download').hidden = false; return; }
    try { await post(`api/spaces/${sp.id}/moved`); }
    catch (err) { await loadSpaces(); say(t('It is now in {name}, but could not be removed here: {error}. Delete it here yourself.', { name, error: err.message }), true); $('#move-go').hidden = true; return; }
    await finished();
    const m = got.moved || {};
    say(t('Moved to {name} as “{space}”: {messages} messages, {tasks} tasks, {files} documents.', { name, space: got.name, messages: m.messages || 0, tasks: m.tasks || 0, files: m.files || 0 })
      + (m.code_changed ? ' ' + t('Its task code is now {code} (the old codes still work there).', { code: got.code }) : '')
      + (m.folder_changed ? ' ' + t('Its folder is {folder}.', { folder: got.folder }) : ''));
    $('#move-open').hidden = false; $('#move-open').onclick = () => openAssistant(to);
  };
  $('#move-close').onclick = () => d.close();
  d.onclose = () => { if (!done) pkg = null; };
  d.showModal();
}
$('#space-import').onchange = async e => {
  const f = e.target.files[0]; if (!f) return;
  toast(t('Importing {name}…', { name: f.name }));
  try {
    const res = await fetch('api/spaces/import', { method: 'POST', credentials: 'same-origin', headers: { 'X-Talixio': '1', 'Content-Type': 'application/zip' }, body: f });
    const data = await res.json().catch(() => ({}));
    if (res.status === 401) { showLogin(); return; }
    if (!res.ok) throw new Error(t(data.error || `Request failed (${res.status})`));
    await loadSpaces(); location.hash = '#chat'; await switchSpace(data.space.id);
    toast(t('Space “{name}” imported: {messages} messages, {tasks} tasks, {files} documents.', { name: data.space.name, messages: data.space.moved?.messages || 0, tasks: data.space.moved?.tasks || 0, files: data.space.moved?.files || 0 }));
  } catch (err) { toast(err.message); }
};

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
    if (x.href === '#library/recent') { location.hash = '#library'; setTimeout(() => showLibRecent(), 300); return; }
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
  const activity = s => { const a = live.querySelector('.activity'); a.hidden = !s; a.innerHTML = s ? SPIN + esc(t(s)) : ''; thread.scrollTop = thread.scrollHeight; };
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
const siteOf = url => { try { return new URL(url).hostname.replace(/^www\./, ''); } catch { return ''; } };
async function loadApprovals() {
  let list = [];
  try { list = (await api('api/approvals')).approvals; } catch { return; }
  const box = $('#approvals'); box.hidden = !list.length; box.innerHTML = '';
  state.pendingApprovals = list.length; setBadge();
  for (const a of list) {
    const el = document.createElement('div'); el.className = 'approval';
    const site = ['fetch_url', 'browser_open'].includes(a.tool) ? siteOf(a.args_decoded?.url || '') : '';
    const always = site ? t('Always allow {site}', {site}) : a.tool === 'web_search' ? t('Always allow web search') : '';
    el.innerHTML = `<span class="label">Needs your approval · #${a.id}</span>
      <div class="actions"><button class="btn small" data-d="reject" type="button">Reject</button>${always ? `<button class="btn small" data-d="always" type="button">${esc(always)}</button>` : ''}<button class="btn small primary" data-d="approve" type="button">Approve</button></div>
      <div class="what">${esc(a.summary)}</div>`;
    el.querySelectorAll('button').forEach(b => b.onclick = async () => {
      el.querySelectorAll('button').forEach(x => x.disabled = true);
      try { const r = await post(`api/approvals/${a.id}/${b.dataset.d}`); appendMessages(r.messages); toast(b.dataset.d === 'reject' ? 'Rejected' : 'Approved'); }
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
  inputPlaceholder();
  saveDraft();
}
function uploadFile(file, name, folder, replace = false, out = {}) {
  return new Promise((resolve, reject) => {
    const fd = new FormData(); fd.append('file', file, name || file.name);
    if (folder) fd.append('folder', folder);
    if (replace) fd.append('replace', '1'); // the library: a name already there gets this as its new content (a version is kept)
    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'api/files'); xhr.setRequestHeader('X-Talixio', '1');
    xhr.onload = () => { try { const d = JSON.parse(xhr.responseText); out.replaced = !!d.replaced; xhr.status < 300 ? resolve(d.file) : reject(new Error(d.error || 'Upload failed')); } catch { reject(new Error(`Upload failed (HTTP ${xhr.status})`)); } };
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

// ---- dictation: speak into any text field; the recording is transcribed and never kept ----
let dictating = null;
async function toggleDictate(input, btn) {
  if (!input) return;
  if (dictating) {
    const d = dictating; dictating = null; d.btn.classList.remove('rec');
    d.media.onstop = async () => {
      d.stream.getTracks().forEach(t => t.stop());
      const blob = new Blob(d.chunks, { type: d.media.mimeType || 'audio/webm' });
      if (blob.size < 800) return;
      d.btn.classList.add('busy'); d.btn.disabled = true;
      try {
        const ext = blob.type.includes('mp4') ? 'm4a' : blob.type.includes('ogg') ? 'ogg' : 'webm';
        const info = await uploadFile(blob, `dictation-${Date.now()}.${ext}`, '');
        const { text } = await post('api/transcribe', { id: info.id });
        if (text) { input.value = (input.value.trim() ? input.value.replace(/\s*$/, ' ') : '') + text; input.dispatchEvent(new Event('input', { bubbles: true })); input.focus(); }
        else toast(t('Nothing was recognised.'));
      } catch (e) { toast(e.message); } finally { d.btn.classList.remove('busy'); d.btn.disabled = false; }
    };
    d.media.stop();
    return;
  }
  if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) { toast(t('Dictation needs a recent browser over HTTPS.')); return; }
  let stream;
  try { stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } }); }
  catch { toast(t('Microphone access was refused.')); return; }
  const type = ['audio/webm;codecs=opus', 'audio/mp4', 'audio/ogg;codecs=opus'].find(ty => MediaRecorder.isTypeSupported(ty)) || '';
  const media = new MediaRecorder(stream, type ? { mimeType: type, audioBitsPerSecond: 32000 } : undefined);
  const chunks = []; media.ondataavailable = e => e.data.size && chunks.push(e.data);
  dictating = { input, btn, media, chunks, stream };
  media.start(); btn.classList.add('rec'); input.focus();
}
document.querySelectorAll('.dictate-btn').forEach(b => b.onclick = () => toggleDictate(document.getElementById(b.dataset.dictateFor), b));

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

// ---- directives (the director's teams): shown as rows on the Jobs/Activity page, workers nested ----
const dirOpen = new Set(); // directive ids the user expanded (or '-id' = collapsed on purpose)
function renderDirectives(list, box = $('#directives'), refresh = loadJobs) {
  if (!box) return;
  if (box.id === 'directives' && jobsFilter.archived) { box.hidden = true; box.innerHTML = ''; return; }
  box.hidden = false; box.innerHTML = '';
  if (!list.length) { // no directive yet: a one-line hint so the director is discoverable instead of invisible until first use
    box.innerHTML = `<div class="placeholder dir-hint">🎬 ${esc(t('No directives yet.'))} ${esc(t('Ask in the chat to run a team, e.g.'))} <q>${esc(t('research our top 3 competitors and draft a one-pager on each'))}</q>. <span class="muted">${esc(t('The director runs a worker role for each part, in parallel — the roles must already exist.'))}</span></div>`;
    return;
  }
  const b = state.status?.budget;
  if (b && b.daily_limit > 0) { // the board's budget burn-down: how much of today's budget the running work is eating
    const pct = Math.min(100, Math.round(b.today / b.daily_limit * 100));
    const lvl = b.state === 'blocked' || pct >= 100 ? 'bad' : b.state === 'low' || pct >= 70 ? 'warn' : 'ok';
    const m = document.createElement('div'); m.className = 'dir-budget ' + lvl;
    m.innerHTML = `<div class="dir-budget-row"><b>${esc(t('Today’s budget'))}</b><span class="spacer"></span><span>$${b.today.toFixed(2)} ${esc(t('of'))} $${(+b.daily_limit).toFixed(2)}${lvl !== 'ok' ? ' · ' + esc(t(lvl === 'bad' ? 'budget reached' : 'budget low')) : ''}</span></div><div class="dir-budget-bar"><i style="width:${pct}%"></i></div>`;
    box.appendChild(m);
  }
  for (const d of list) box.appendChild(dirRow(d, refresh));
}
function dirRow(d, refresh = loadJobs) {
  const el = document.createElement('div');
  el.className = 'task dir';
  const total = d.workers.length;
  const done = d.workers.filter(w => ['done', 'failed', 'cancelled'].includes(w.status)).length;
  const running = d.workers.filter(w => ['queued', 'running'].includes(w.status)).length;
  const active = ['running', 'finalizing'].includes(d.status);
  const open = dirOpen.has(d.id) || (active && running > 0 && !dirOpen.has('-' + d.id));
  const pct = total ? Math.round(done / total * 100) : (d.status === 'done' ? 100 : 0);
  el.innerHTML = `<div class="dir-head">
      <button class="dir-exp" aria-label="${esc(t('Show the workers'))}">${open ? '▾' : '▸'}</button>
      <span class="pill p${d.priority}">P${d.priority}</span><span class="chip dir-chip">${esc(t('directive'))}</span>
      <h3>${esc(d.title)}</h3>
      <span class="muted small dir-meta">${total} ${esc(t('workers'))}${running ? ` · ${running} ${esc(t('running'))}` : ''}${done ? ` · ${done} ${esc(t('done'))}` : ''}</span>
      <span class="dir-prog" title="${done}/${total}"><i style="width:${pct}%"></i></span>
      <span class="muted small dir-cost">${money(+d.cost) || '$0'}</span>
      <span class="dir-actions"></span></div>
    <div class="dir-workers"${open ? '' : ' hidden'}>${d.workers.map(w => `<div class="dir-w"><b>${esc(w.role)}</b><span class="chip">${esc(w.tier)}</span><span class="pill ${w.status}">${w.status === 'running' ? '<span class="dot"></span>' : ''}${esc(w.status)}</span><span class="muted small">${money(+w.cost) || '$0'}</span>${w.note ? `<span class="muted small dir-w-note">${esc(w.note)}</span>` : ''}</div>`).join('') || `<div class="muted small">${esc(t('no workers yet'))}</div>`}</div>`;
  const wb = el.querySelector('.dir-workers'), exp = el.querySelector('.dir-exp');
  exp.onclick = () => { const o = wb.hidden; wb.hidden = !o; exp.textContent = o ? '▾' : '▸'; if (o) { dirOpen.add(d.id); dirOpen.delete('-' + d.id); } else { dirOpen.delete(d.id); dirOpen.add('-' + d.id); } };
  const act = el.querySelector('.dir-actions');
  const btn = (cls, label, title, fn) => { const b = document.createElement('button'); b.className = 'btn small ' + cls; b.textContent = label; if (title) b.title = title; b.onclick = fn; return b; };
  if (active) {
    if (d.priority > 1) act.appendChild(btn('ghost', '↑ P' + (d.priority - 1), t('Raise priority'), async () => { try { await post(`api/directives/${d.id}/steer`, { action: 'prioritize', priority: d.priority - 1 }); refresh(); } catch (e) { toast(e.message); } }));
    act.appendChild(btn('ghost', t('Cancel'), '', async () => { if (!confirm(t('Cancel this directive and stop its unfinished workers?'))) return; try { await post(`api/directives/${d.id}/steer`, { action: 'cancel' }); refresh(); } catch (e) { toast(e.message); } }));
  } else if (d.status === 'done') {
    act.appendChild(btn('', '👍 ' + t('Accept'), t('The roles learn from what you accept'), async () => { try { await post(`api/directives/${d.id}/rate`, { accepted: true }); toast(t('Recorded — the roles learn from it')); } catch (e) { toast(e.message); } }));
    act.appendChild(btn('ghost', t('Not useful'), '', async () => { try { await post(`api/directives/${d.id}/rate`, { accepted: false }); toast(t('Recorded')); } catch (e) { toast(e.message); } }));
  }
  if (d.result) { const det = document.createElement('details'); det.className = 'dir-result'; det.innerHTML = `<summary>${esc(t('Combined result'))}</summary><div class="doc">${md(d.result)}</div>`; el.appendChild(det); }
  return el;
}
function updateDirBar(list) {
  const bar = $('#dir-bar'); if (!bar) return;
  const acts = (list || []).filter(d => d.status ? ['running', 'finalizing'].includes(d.status) : true);
  if (!acts.length) { bar.hidden = true; return; }
  const workers = acts.reduce((n, d) => n + (d.running != null ? d.running : (d.workers || []).filter(w => ['queued', 'running'].includes(w.status)).length), 0);
  const b = state.status?.budget;
  const left = b && b.daily_limit > 0 ? ` · $${Math.max(0, b.daily_limit - b.today).toFixed(2)} ${t('left today')}` : '';
  bar.hidden = false;
  bar.innerHTML = `<span class="dir-bar-ic">🎬</span><span class="dir-bar-txt">${acts.length} ${esc(t(acts.length === 1 ? 'directive' : 'directives'))} · ${workers} ${esc(t('worker(s) running'))}${esc(left)}</span><span class="dir-bar-go">${esc(t('Open'))} ▸</span>`;
  bar.onclick = () => { location.hash = '#jobs'; };
}

async function loadJobs() {
  const q = new URLSearchParams();
  if (jobsFilter.space != null && jobsFilter.space !== '') q.set('space', jobsFilter.space);
  if (jobsFilter.tag) q.set('tag', jobsFilter.tag);
  if (jobsFilter.archived) q.set('archived', '1');
  if (jobsFilter.q) q.set('q', jobsFilter.q);
  const { jobs, directives, tags, archived_count, archive_days } = await api('api/jobs?' + q);
  renderDirectives(directives || []);
  updateDirBar(directives || []);
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
        if (!/^(https?:|\/files\/|tel:|sms:|mailto:|geo:|spotify:|deezer:|tg:|sgnl:|uber:|homeassistant:)/.test(href) && !INTENT_HREF.test(href)) return text;
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
      case 'H5': return '### ' + inline(n).replace(/\s+/g, ' ').trim();
      case 'H6': return '#### ' + inline(n).replace(/\s+/g, ' ').trim();
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

/** A Markdown editor that looks like the result. rte.value / rte.value = '...'; opts.source opens on the Markdown. */
function makeRte(box, opts = {}) {
  box.innerHTML = `<div class="rte-bar" role="toolbar" aria-label="Formatting">
      <button type="button" data-c="bold" title="Bold (Ctrl+B)"><b>B</b></button><button type="button" data-c="italic" title="Italic (Ctrl+I)"><i>I</i></button>
      <button type="button" data-c="strike" title="Strikethrough"><s>S</s></button><button type="button" data-c="h" title="Heading">H</button>
      <span class="sep"></span><button type="button" data-c="ul" title="List">•</button><button type="button" data-c="ol" title="Numbered list">1.</button>
      <button type="button" data-c="task" title="Checklist">☑</button><button type="button" data-c="quote" title="Quote">❝</button>
      <button type="button" data-c="code" title="Code">&lt;/&gt;</button><button type="button" data-c="link" title="Link">🔗</button>
      <span class="spacer"></span><button type="button" data-c="src" title="Edit the Markdown source" class="src">MD</button></div>
    <div class="rte-area doc" contenteditable="true" role="textbox" aria-multiline="true" aria-label="${opts.label ? 'Content' : 'Description'}" data-placeholder="${opts.label ? 'Write here…' : 'Details, steps, links… (checklists count as progress)'}"></div>
    <textarea class="rte-src" hidden aria-label="${opts.label ? 'Content (Markdown)' : 'Description (Markdown)'}" spellcheck="true"></textarea>`;
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
    set value(v) {
      srcMode = !!opts.source; area.hidden = srcMode; src.hidden = !srcMode; box.querySelector('.src').classList.toggle('on', srcMode);
      box.querySelectorAll('.rte-bar button:not(.src)').forEach(x => x.disabled = srcMode);
      if (srcMode) { src.value = v || ''; area.innerHTML = ''; } else load(v);
    },
    focus() { (srcMode ? src : area).focus(); },
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
  const mode = ['overall', 'calendar', 'today', 'week', 'list'].includes(boardPrefs.mode) ? boardPrefs.mode : 'board';
  document.querySelectorAll('#view-board .seg button[data-mode]').forEach(b => { b.classList.toggle('on', b.dataset.mode === mode); b.setAttribute('aria-selected', b.dataset.mode === mode); b.onclick = () => { boardPrefs.mode = b.dataset.mode; saveBoardPrefs(); loadBoard(); }; });
  bindBoardFilters(mode);
  $('#board-new').onclick = () => openCard(null, { space: boardPrefs.space === '' ? state.space : +boardPrefs.space });
  $('#board-trash').onclick = showBoardTrash;
  $('#board-changes').onclick = showBoardChanges;
  const sp = boardPrefs.space === '' ? '' : `space=${boardPrefs.space}`;
  if (mode === 'overall') await renderOverall(await api('api/board/portfolio')); // arbitration is across all spaces
  else if (mode === 'calendar') await renderCalendar();
  else if (mode === 'today') await renderToday();
  else if (mode === 'week') await renderWeek();
  else {
    const d = await api(`api/board?${sp}`);
    Object.assign(board, d);
    boardTags(d.tags || {});
    mode === 'list' ? renderTaskList() : renderBoard();
  }
  if (mode !== 'list') { listSel.clear(); $('#list-bulk')?.remove(); }
  sel.hidden = mode === 'overall';
  clearTimeout(loadBoard.t);
  loadBoard.t = setTimeout(() => { if (location.hash.startsWith('#board') && !listSel.size && !$('#card-dialog').open && !$('#move-sheet').open && !document.querySelector('.dragging, .qadd.typing, .qa-in:focus, #board.picking')) loadBoard().catch(() => {}); }, 30000);
}

/* Filters shared by the Today, Board, List, Week and Month views: words, one tag, a priority floor; the board can also be sorted. */
const boardFiltered = () => !!(boardPrefs.q || boardPrefs.tag || boardPrefs.prio);
function cardMatches(c) {
  const P = boardPrefs;
  if (P.tag && !(c.tags || []).includes(P.tag)) return false;
  if (P.prio && c.priority > +P.prio) return false;
  if (P.q) {
    const hay = fold([c.code, c.title, c.body, c.location, ...(c.tags || []).map(x => '#' + x)].join(' '));
    if (!fold(P.q).split(/\s+/).filter(Boolean).every(w => hay.includes(w))) return false;
  }
  return true;
}
/** Agenda events have no tag or priority: they show only while those filters are off, and must match the words. */
function eventMatches(e) {
  if (boardPrefs.tag || boardPrefs.prio) return false;
  const hay = fold(`${e.title} ${e.location || ''}`);
  return fold(boardPrefs.q).split(/\s+/).filter(Boolean).every(w => hay.includes(w));
}
/** Keeps the cards that pass the filters, and says how many were left out. */
function filterCards(cards) {
  const shown = cards.filter(cardMatches);
  const cnt = $('#board-count');
  cnt.textContent = boardFiltered() ? t('{a} of {b} tasks', { a: shown.length, b: cards.length }) : '';
  return shown;
}
function sortCards(cards) {
  const k = boardPrefs.bsort, due = c => (c.due_date || c.start_date || '9999') + (c.start_time || '99');
  if (k === 'prio') return [...cards].sort((a, b) => a.priority - b.priority || due(a).localeCompare(due(b)));
  if (k === 'due') return [...cards].sort((a, b) => due(a).localeCompare(due(b)) || a.priority - b.priority);
  if (k === 'title') return [...cards].sort((a, b) => a.title.localeCompare(b.title, LOCALE));
  return cards;
}
/** Tag picker: type to find among all the tags (most used first), plus the few most used as one-click chips. */
function boardTags(counts) {
  const names = Object.keys(counts).sort((a, b) => counts[b] - counts[a] || a.localeCompare(b));
  $('#board-tag-list').innerHTML = names.map(x => `<option value="${esc(x)}">${counts[x]}</option>`).join('');
  boardTags.known = new Set(names);
  const top = $('#board-tag-top');
  top.innerHTML = boardPrefs.tag ? `<button type="button" class="tag on" data-tag="" title="Remove this filter">#<span class="no-i18n">${esc(boardPrefs.tag)}</span> ✕</button>`
    : names.slice(0, 5).map(x => `<button type="button" class="tag" data-tag="${esc(x)}">#<span class="no-i18n">${esc(x)}</span> <small>${counts[x]}</small></button>`).join('');
  top.querySelectorAll('[data-tag]').forEach(b => b.onclick = () => setBoardTag(b.dataset.tag));
}
const tagCounts = cards => { const n = {}; for (const c of cards) for (const x of c.tags || []) n[x] = (n[x] || 0) + 1; return n; };
function setBoardTag(tag) { boardPrefs.tag = String(tag || '').replace(/^#+/, '').trim().toLowerCase(); $('#board-tag').value = ''; saveBoardPrefs(); loadBoard(); }
function bindBoardFilters(mode) {
  const P = boardPrefs, q = $('#board-q'), tag = $('#board-tag'), prio = $('#board-prio'), sort = $('#board-sort');
  if (P.lprio && !P.prio) { P.prio = P.lprio; } delete P.lprio; // the list's own priority filter moved here
  $('#board-filters').hidden = mode === 'overall';
  sort.hidden = mode !== 'board';
  if (document.activeElement !== q) q.value = P.q || '';
  q.oninput = () => { clearTimeout(loadBoard.qt); loadBoard.qt = setTimeout(() => { P.q = q.value.trim(); saveBoardPrefs(); loadBoard(); }, 250); };
  tag.placeholder = P.tag ? t('# Other tag') : t('# Tag');
  tag.oninput = () => { const v = tag.value.replace(/^#+/, '').trim().toLowerCase(); if (boardTags.known?.has(v)) setBoardTag(v); };
  tag.onkeydown = e => { if (e.key === 'Enter' && tag.value.trim()) { e.preventDefault(); setBoardTag(tag.value); } else if (e.key === 'Escape') tag.value = ''; };
  prio.value = P.prio || ''; prio.onchange = () => { P.prio = prio.value; saveBoardPrefs(); loadBoard(); };
  sort.value = P.bsort || ''; sort.onchange = () => { P.bsort = sort.value; saveBoardPrefs(); loadBoard(); };
  $('#board-clear').hidden = !boardFiltered();
  $('#board-clear').onclick = () => { P.q = ''; P.tag = ''; P.prio = ''; q.value = ''; saveBoardPrefs(); loadBoard(); };
  $('#board-count').textContent = '';
}

function cardHtml(c, opts = {}) {
  const sp = spaceOf(c.space), waits = c.depends_on.filter(d => board.refs[d] && board.refs[d].status !== 'done' && !board.refs[d].deleted_at);
  const warn = c.warnings.filter(w => w.kind !== 'waiting' && w.kind !== 'overdue');
  const bits = [];
  if (c.start_time) bits.push(`<span class="kb ${dueClass(c)}" title="${esc(c.start_time)}${c.end_time ? '–' + esc(c.end_time) : ''}">${BICON.due}${fmtDay(c.start_date || c.due_date)} ${esc(c.start_time)}</span>`);
  else if (c.due_date) bits.push(`<span class="kb ${dueClass(c)}" title="Due ${esc(c.due_date)}${c.start_date ? ', starts ' + esc(c.start_date) : ''}">${BICON.due}${fmtDay(c.due_date)}</span>`);
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
  const all = boardPrefs.space === '', shown = sortCards(filterCards(board.cards));
  if (all && board.cards.length) {
    // one row per space, so each space's work stays readable side by side
    root.className = 'board lanes';
    const ids = [...new Set(shown.map(c => c.space))].sort((x, y) => state.spaces.findIndex(s => s.id === x) - state.spaces.findIndex(s => s.id === y));
    const hidden = new Set(boardPrefs.lanesHidden || []);
    for (const sid of ids) {
      const sp = spaceOf(sid), cards = shown.filter(c => c.space === sid);
      const open = cards.filter(c => c.status !== 'done'), load = open.reduce((a, c) => a + Math.max(0, (c.estimate_hours || 0) - (c.spent_hours || 0)), 0);
      const lane = document.createElement('section'); lane.className = 'klane' + (hidden.has(sid) ? ' folded' : ''); lane.dataset.space = sid;
      lane.innerHTML = `<button type="button" class="klane-h" title="Fold or unfold this space" aria-expanded="${!hidden.has(sid)}"><span class="chev" aria-hidden="true">▾</span><i style="background:${esc(sp.color || 'var(--muted)')}"></i><b class="no-i18n">${esc(sp.name)}</b><span class="muted small">${t('{a} open', { a: open.length })}${load ? ' · ' + fmtH(Math.round(load * 4) / 4) : ''}</span></button><div class="klane-row"></div>`;
      lane.querySelector('.klane-h').onclick = () => { hidden.has(sid) ? hidden.delete(sid) : hidden.add(sid); boardPrefs.lanesHidden = [...hidden]; saveBoardPrefs(); lane.classList.toggle('folded'); lane.querySelector('.klane-h').setAttribute('aria-expanded', !hidden.has(sid)); };
      const row = lane.querySelector('.klane-row');
      for (const [st, label] of Object.entries(board.statuses)) row.append(boardCol(st, label, cards.filter(c => c.status === st), sid, root));
      root.append(lane);
    }
    if (!ids.length) root.append(Object.assign(document.createElement('p'), { className: 'muted', textContent: t('No task matches these filters.') }));
    return;
  }
  for (const [st, label] of Object.entries(board.statuses)) root.append(boardCol(st, label, shown.filter(c => c.status === st), all ? null : +boardPrefs.space, root));
  if (!board.cards.length) {
    $('#board-summary').hidden = false;
    $('#board-summary').innerHTML = '<p class="muted small">No tasks yet. Add one in a column, or ask in the chat: <q>add a task: repaint the kitchen before December, about 2 days</q>.</p>';
  }
}

/** One status column of the board (of one space's row when all spaces are shown). space: where tasks added or dropped here go. */
function boardCol(st, label, cards, space, root) {
  const load = cards.reduce((a, c) => a + (c.estimate_hours || 0), 0);
  const col = document.createElement('section');
  col.className = `kcol s-${st}`; col.dataset.status = st;
  col.innerHTML = `<header><b>${label}</b><span class="muted small">${cards.length}${load ? ' · ' + fmtH(load) : ''}</span></header><div class="kcards"></div>`;
  const list = col.querySelector('.kcards');
  for (const c of cards) {
    const el = document.createElement('article');
    el.className = `kcard p${c.priority}`; el.dataset.id = c.id; el.dataset.space = c.space; el.draggable = !coarse(); el.tabIndex = 0;
    el.innerHTML = cardHtml(c, { space: space == null }) + '<button type="button" class="icon-btn kmore" aria-label="More actions" title="More actions">⋯</button>';
    el.querySelector('.kmore').onclick = e => { e.stopPropagation(); openMoveSheet(c); };
    bindLongPress(el, () => openMoveSheet(c));
    el.onclick = () => openCard(c.id);
    el.onkeydown = e => { if (e.key === 'Enter' && e.target === el) openCard(c.id); };
    el.ondragstart = e => { el.classList.add('dragging'); e.dataTransfer.setData('text/plain', String(c.id)); e.dataTransfer.effectAllowed = 'move'; };
    el.ondragend = () => { el.classList.remove('dragging'); document.querySelectorAll('.kdrop').forEach(x => x.remove()); };
    list.append(el);
  }
  if (!cards.length) list.innerHTML = `<p class="kempty muted small">${st === 'idea' ? 'Ideas for later' : st === 'blocked' ? 'Nothing blocked' : st === 'done' ? 'Done in the last 2 weeks' : 'Nothing here'}</p>`;
  // drag and drop between and within columns (and between the rows of spaces)
  const marker = () => { let m = col.querySelector('.kdrop'); if (!m) { m = document.createElement('div'); m.className = 'kdrop'; } return m; };
  const beforeEl = y => [...list.querySelectorAll('.kcard:not(.dragging)')].find(k => { const r = k.getBoundingClientRect(); return y < r.top + r.height / 2; });
  col.ondragover = e => { e.preventDefault(); const b = beforeEl(e.clientY); const m = marker(); document.querySelectorAll('.kdrop').forEach(x => x !== m && x.remove()); b ? list.insertBefore(m, b) : list.append(m); };
  col.ondragleave = e => { if (!col.contains(e.relatedTarget)) col.querySelector('.kdrop')?.remove(); };
  col.ondrop = async e => {
    e.preventDefault();
    const id = +e.dataTransfer.getData('text/plain'); const b = beforeEl(e.clientY);
    col.querySelector('.kdrop')?.remove();
    if (!id) return;
    const c = board.cards.find(x => x.id === id);
    try {
      if (c && space != null && c.space !== space) { const r = await post(`api/board/${id}`, { space, version: c.version }); toast(t('{a} moved to {b}: now {c}', { a: c.code, b: spaceOf(space).name, c: r.card.code })); }
      await post(`api/board/${id}/move`, { status: st, before: b ? +b.dataset.id : null });
    } catch (err) { toast(err.message); }
    loadBoard();
  };
  if (space != null) col.append(quickAdd({ compact: true, placeholder: '+ Add a task', label: `Add a task to ${label}`, defaults: () => ({ space, status: st }),
    onAdded: () => loadBoard().then(() => root.querySelector(`${root.classList.contains('lanes') ? `.klane[data-space="${space}"] ` : ''}.kcol.s-${st} .qa-in`)?.focus()) }));
  return col;
}

/** List: every task as a row, with quick views, filters, grouping, and changes to several tasks at once. */
const listSel = new Set();
const LIST_VIEWS = { all: 'All', week: 'This week', urgent: 'Critical & high', waiting: 'Waiting', nodate: 'No date' };
const LIST_SORT = { code: 'Code', title: 'Title', space: 'Space', prio: 'Prio', due: 'Due', load: 'Load', status: 'Status' };
function listWaits(c) { return c.status === 'blocked' || c.depends_on.some(d => board.refs[d] && board.refs[d].status !== 'done' && !board.refs[d].deleted_at); }
function listDueGroup(c) {
  if (c.status === 'done') return 'Done';
  const d = c.due_date || c.start_date, t0 = today();
  if (!d) return 'No date';
  if (d < t0) return 'Overdue';
  if (d === t0) return 'Today';
  return d <= addDays(t0, 6) ? 'Next 7 days' : 'Later';
}
function renderTaskList() {
  const root = $('#board'); root.className = 'tlist'; root.innerHTML = '';
  $('#board-summary').hidden = true;
  const P = boardPrefs, all = P.space === '';
  P.lview = LIST_VIEWS[P.lview] ? P.lview : 'all'; P.lsort ||= 'due'; P.lgroup ||= 'due';
  const t0 = today(), week = addDays(t0, 6);
  const known = new Set(board.cards.map(c => c.id)); [...listSel].forEach(id => known.has(id) || listSel.delete(id));
  let cards = filterCards(board.cards).filter(c => {
    if (P.lstatus === 'open' || !P.lstatus) { if (c.status === 'done') return false; } else if (P.lstatus !== 'any' && c.status !== P.lstatus) return false;
    const d = c.due_date || c.start_date;
    return P.lview === 'week' ? c.status !== 'done' && d && d <= week
      : P.lview === 'urgent' ? c.priority <= 2 && c.status !== 'done'
      : P.lview === 'waiting' ? c.status !== 'done' && listWaits(c)
      : P.lview === 'nodate' ? !d && c.status !== 'done' : true;
  });
  const st = Object.keys(board.statuses), dir = P.ldesc ? -1 : 1;
  const key = {
    code: c => c.code, title: c => c.title.toLowerCase(), space: c => spaceOf(c.space).name.toLowerCase(), prio: c => c.priority,
    due: c => (c.due_date || c.start_date || '9999') + (c.start_time || '99'), load: c => c.estimate_hours ?? 1e9, status: c => st.indexOf(c.status),
  }[P.lsort] || (c => c.code);
  cards.sort((a, b) => { const x = key(a), y = key(b); return (x < y ? -1 : x > y ? 1 : a.priority - b.priority || a.id - b.id) * dir; });

  // toolbar: quick views, filters, grouping
  const bar = document.createElement('div'); bar.className = 'tl-bar';
  bar.innerHTML = `<div class="tl-views" role="group" aria-label="Quick views">${Object.entries(LIST_VIEWS).map(([k, v]) => `<button type="button" class="fchip${P.lview === k ? ' on' : ''}" data-v="${k}" aria-pressed="${P.lview === k}">${v}</button>`).join('')}</div>
    <div class="tl-filters">
      <label>Status <select data-f="lstatus"><option value="open">Not done</option><option value="any">All</option>${st.map(k => `<option value="${k}">${board.statuses[k]}</option>`).join('')}</select></label>
      <label>Group by <select data-f="lgroup"><option value="due">Due date</option><option value="status">Status</option><option value="space">Space</option><option value="prio">Priority</option><option value="none">Nothing</option></select></label>
    </div>`;
  bar.querySelectorAll('[data-v]').forEach(b => b.onclick = () => { P.lview = b.dataset.v; saveBoardPrefs(); renderTaskList(); });
  bar.querySelectorAll('select[data-f]').forEach(x => { x.value = P[x.dataset.f] || x.options[0].value; x.onchange = () => { P[x.dataset.f] = x.value; saveBoardPrefs(); renderTaskList(); }; });
  root.append(bar);
  root.append(quickAdd({ placeholder: 'Add a task: call Alex tomorrow 9am #sales', showSpace: all, defaults: () => ({ space: all ? state.space : +P.space }), onAdded: () => loadBoard().then(() => root.querySelector('.qa-in')?.focus()) }));

  const head = document.createElement('div'); head.className = 'tl-row tl-head' + (all ? '' : ' one');
  const allOn = cards.length && cards.every(c => listSel.has(c.id));
  head.innerHTML = `<span><input type="checkbox" aria-label="Select all shown"${allOn ? ' checked' : ''}></span>` + Object.entries(LIST_SORT).filter(([k]) => all || k !== 'space')
    .map(([k, v]) => `<button type="button" class="tl-sort c-${k}${P.lsort === k ? ' on' : ''}" data-s="${k}" aria-sort="${P.lsort === k ? (P.ldesc ? 'descending' : 'ascending') : 'none'}"><span>${v}</span>${P.lsort === k ? (P.ldesc ? ' ↓' : ' ↑') : ''}</button>`).join('');
  head.querySelector('input').onchange = e => { cards.forEach(c => e.target.checked ? listSel.add(c.id) : listSel.delete(c.id)); renderTaskList(); };
  head.querySelectorAll('[data-s]').forEach(b => b.onclick = () => { if (P.lsort === b.dataset.s) P.ldesc = !P.ldesc; else { P.lsort = b.dataset.s; P.ldesc = false; } saveBoardPrefs(); renderTaskList(); });
  const table = document.createElement('div'); table.className = 'tl-table'; table.setAttribute('role', 'list');
  table.append(head);

  const row = c => {
    const sp = spaceOf(c.space), el = document.createElement('div');
    el.className = `tl-row p${c.priority}${listSel.has(c.id) ? ' sel' : ''}${c.status === 'done' ? ' done' : ''}${all ? '' : ' one'}`; el.dataset.id = c.id; el.tabIndex = 0; el.setAttribute('role', 'listitem');
    const waits = listWaits(c), due = c.due_date || c.start_date;
    el.innerHTML = `<span><input type="checkbox" aria-label="Select ${esc(c.code)}"${listSel.has(c.id) ? ' checked' : ''}></span>
      <span class="c-code code">${esc(c.code)}</span>
      <span class="c-title"><b class="no-i18n">${esc(c.title)}</b>${waits && c.status !== 'blocked' ? `<span class="kb wait" title="Waits for another task">${BICON.lock}</span>` : ''}${c.tags.map(x => `<small class="no-i18n">#${esc(x)}</small>`).join('')}</span>
      ${all ? `<span class="c-space"><i style="background:${esc(sp.color || 'var(--muted)')}"></i><span class="no-i18n">${esc(sp.name)}</span></span>` : ''}
      <span class="c-prio"><span class="prio p${c.priority}" title="${PRIO[c.priority]}">P${c.priority}</span></span>
      <span class="c-due ${dueClass(c)}">${due ? fmtDay(due) + (c.start_time ? ' ' + esc(c.start_time) : '') : '<span class="muted">—</span>'}</span>
      <span class="c-load">${c.estimate_hours != null || c.spent_hours ? (c.spent_hours ? fmtH(c.spent_hours) + '/' : '') + (fmtH(c.estimate_hours) || '?') : ''}</span>
      <span class="c-status"><select aria-label="Status of ${esc(c.code)}">${st.map(k => `<option value="${k}"${k === c.status ? ' selected' : ''}>${board.statuses[k]}</option>`).join('')}</select></span>`;
    const box = el.querySelector('input');
    box.onclick = e => e.stopPropagation();
    box.onchange = () => { box.checked ? listSel.add(c.id) : listSel.delete(c.id); el.classList.toggle('sel', box.checked); listBulk(); };
    const sel = el.querySelector('select');
    sel.onclick = e => e.stopPropagation();
    sel.onchange = async () => { try { await post(`api/board/${c.id}/move`, { status: sel.value }); } catch (e) { toast(e.message); } loadBoard(); };
    el.onclick = () => openCard(c.id);
    el.onkeydown = e => {
      if (e.target !== el) return;
      if (e.key === 'Enter') openCard(c.id);
      else if (e.key === 'x' || e.key === ' ') { e.preventDefault(); box.click(); box.onchange(); }
      else if (e.key === 'ArrowDown' || e.key === 'j') { e.preventDefault(); el.nextElementSibling?.focus?.(); }
      else if (e.key === 'ArrowUp' || e.key === 'k') { e.preventDefault(); el.previousElementSibling?.matches('.tl-row:not(.tl-head)') && el.previousElementSibling.focus(); }
    };
    return el;
  };
  const groupOf = {
    due: listDueGroup, status: c => board.statuses[c.status], space: c => spaceOf(c.space).name, prio: c => `P${c.priority} · ${PRIO[c.priority]}`, none: () => '',
  }[P.lgroup] || (() => '');
  const order = P.lgroup === 'due' ? ['Overdue', 'Today', 'Next 7 days', 'Later', 'No date', 'Done'] : null;
  const groups = new Map();
  for (const c of cards) { const g = groupOf(c); if (!groups.has(g)) groups.set(g, []); groups.get(g).push(c); }
  const names = order ? order.filter(g => groups.has(g)) : P.lgroup === 'status' ? st.map(k => board.statuses[k]).filter(g => groups.has(g)) : P.lgroup === 'prio' ? [...groups.keys()].sort() : [...groups.keys()];
  for (const g of names) {
    const list = groups.get(g);
    if (g) {
      const h = document.createElement('div'); h.className = 'tl-group' + (g === 'Overdue' ? ' late' : '');
      const load = list.reduce((a, c) => a + Math.max(0, (c.estimate_hours || 0) - (c.spent_hours || 0)), 0);
      h.innerHTML = `<b${P.lgroup === 'space' ? ' class="no-i18n"' : ''}>${esc(g)}</b><span class="muted small">${list.length}${load ? ' · ' + fmtH(Math.round(load * 4) / 4) : ''}</span>`;
      table.append(h);
    }
    list.forEach(c => table.append(row(c)));
  }
  root.append(table);
  if (!cards.length) root.append(Object.assign(document.createElement('p'), { className: 'muted tl-empty', textContent: board.cards.length ? t('No task matches these filters.') : t('No tasks yet.') }));
  listBulk();
}

/** The bar of actions on the selected rows of the list. */
function listBulk() {
  let bar = $('#list-bulk');
  if (!listSel.size) { bar?.remove(); return; }
  const P = boardPrefs;
  if (!bar) {
    bar = document.createElement('div'); bar.id = 'list-bulk'; bar.className = 'tl-bulk'; bar.setAttribute('role', 'toolbar'); bar.setAttribute('aria-label', 'Selected tasks');
    $('#view-board').append(bar);
  }
  bar.innerHTML = `<b class="tl-n"></b>
    <select data-b="status" aria-label="Set status"><option value="">Set status…</option>${Object.entries(board.statuses).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}</select>
    <label class="tl-date"><span>Reschedule</span> <input type="date" data-b="due" aria-label="New due date"></label>
    <select data-b="priority" aria-label="Priority"><option value="">Priority…</option>${Object.entries(PRIO).map(([k, v]) => `<option value="${k}">P${k} · ${v}</option>`).join('')}</select>
    <select data-b="space" aria-label="Move to space"><option value="">Move to space…</option>${state.spaces.filter(x => !x.archived).map(x => `<option value="${x.id}">${esc(x.name)}</option>`).join('')}</select>
    <form class="tl-tag"><input data-b="tag" placeholder="Add a tag" aria-label="Add a tag" autocomplete="off" size="9"><button class="btn small" type="submit">Add</button></form>
    <button type="button" class="btn small ghost danger" data-b="trash">Move to trash</button>
    <button type="button" class="icon-btn" data-b="clear" aria-label="Clear the selection" title="Clear the selection (Esc)">✕</button>`;
  bar.querySelector('.tl-n').textContent = t('{a} selected', { a: listSel.size });
  const run = async (label, fn) => {
    const ids = [...listSel]; let ok = 0, err = '';
    bar.classList.add('busy');
    for (const id of ids) { const c = board.cards.find(x => x.id === id); if (!c) continue; try { await fn(c); ok++; } catch (e) { err = `${c.code}: ${e.message}`; } }
    bar.classList.remove('busy');
    if (err) toast(err); else toast(t(label, { a: ok }));
    return ids;
  };
  const upd = body => c => post(`api/board/${c.id}`, { ...body, version: c.version });
  const done = () => { listSel.clear(); loadBoard(); };
  bar.querySelector('[data-b=status]').onchange = async e => { const v = e.target.value; if (!v) return; await run('{a} task(s) updated', c => post(`api/board/${c.id}/move`, { status: v })); done(); };
  bar.querySelector('[data-b=priority]').onchange = async e => { const v = +e.target.value; if (!v) return; await run('{a} task(s) updated', upd({ priority: v })); done(); };
  bar.querySelector('[data-b=space]').onchange = async e => { const v = e.target.value; if (v === '') return; await run('{a} task(s) moved', upd({ space: +v })); done(); };
  bar.querySelector('[data-b=due]').onchange = async e => {
    const v = e.target.value; if (!v) return;
    await run('{a} task(s) rescheduled', c => upd({ due_date: v, ...(c.start_date && c.start_date > v ? { start_date: v } : {}) })(c)); done();
  };
  bar.querySelector('.tl-tag').onsubmit = async e => {
    e.preventDefault(); const v = bar.querySelector('[data-b=tag]').value.trim().replace(/^#/, ''); if (!v) return;
    await run('{a} task(s) updated', c => c.tags.includes(v) ? Promise.resolve() : upd({ tags: [...c.tags, v].join(', ') })(c)); done();
  };
  bar.querySelector('[data-b=trash]').onclick = async () => {
    const ids = await run('{a} task(s) moved to the trash', c => post(`api/board/${c.id}/delete`));
    listSel.clear(); loadBoard();
    toastAction(t('{a} task(s) moved to the trash', { a: ids.length }), 'Undo', async () => { for (const id of ids) { try { await post(`api/board/${id}/restore`); } catch {} } loadBoard(); });
  };
  bar.querySelector('[data-b=clear]').onclick = () => { listSel.clear(); renderTaskList(); };
}
document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && listSel.size && !document.querySelector('dialog[open]') && location.hash.startsWith('#board')) { listSel.clear(); if (boardPrefs.mode === 'list') renderTaskList(); else $('#list-bulk')?.remove(); }
});

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

// ---- today, quick add, move sheet ----
function toastAction(text, label, fn) {
  toast(text); const t = $('#toast'), b = document.createElement('button');
  b.type = 'button'; b.className = 'toast-act'; b.textContent = label; b.onclick = () => { t.hidden = true; fn(); };
  t.append(' ', b); clearTimeout(toast.timer); toast.timer = setTimeout(() => (t.hidden = true), 7000);
}
const isoDay = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const addDays = (k, n) => { const d = new Date(k + 'T12:00:00'); d.setDate(d.getDate() + n); return isoDay(d); };
const nextMonday = () => { const d = new Date(); d.setDate(d.getDate() + (((8 - d.getDay()) % 7) || 7)); return isoDay(d); };
const STATUS_LABEL = () => board.statuses && Object.keys(board.statuses).length ? board.statuses : { idea: 'Ideas', todo: 'To do', doing: 'In progress', blocked: 'Blocked', done: 'Done' };
const coarse = () => matchMedia('(pointer: coarse)').matches;

/** Move a card to day k (null: no date). A due date shifts, with its start; otherwise the start, or a new due date. */
async function rescheduleCard(c, k) {
  const body = { version: c.version };
  if (k === null) { body.due_date = null; body.start_date = null; body.start_time = null; body.end_time = null; }
  else if (c.due_date) {
    if (c.due_date === k) return false;
    body.due_date = k;
    if (c.start_date) body.start_date = addDays(c.start_date, Math.round((Date.parse(k) - Date.parse(c.due_date)) / 864e5));
  } else if (c.start_date) { if (c.start_date === k) return false; body.start_date = k; }
  else body.due_date = k;
  await post(`api/board/${c.id}`, body);
  toast(k === null ? `${c.code}: no date` : `${c.code}: ${c.due_date || !c.start_date ? 'due ' : ''}${fmtDay(k)}`);
  return true;
}

/** Read dates, times, workload, priority, #space and #tags out of a quick-add line. skip: kinds the user dismissed. */
function parseQuick(text, skip = new Set()) {
  const r = { title: text, chips: [] };
  const B = '(?<![\\p{L}\\d#@])', E = '(?![\\p{L}\\d])';
  const take = (re, fn) => { const m = r.title.match(new RegExp(B + re + E, 'iu')); if (!m) return false; if (fn(m) === false) return false; r.title = (r.title.slice(0, m.index) + ' ' + r.title.slice(m.index + m[0].length)); return true; };
  const t0 = today(), now = new Date();
  const WD = { sun: 0, sunday: 0, dimanche: 0, mon: 1, monday: 1, lundi: 1, tue: 2, tues: 2, tuesday: 2, mardi: 2, wed: 3, wednesday: 3, mercredi: 3, thu: 4, thur: 4, thurs: 4, thursday: 4, jeudi: 4, fri: 5, friday: 5, vendredi: 5, sat: 6, saturday: 6, samedi: 6 };
  const MO = { jan: 1, january: 1, janvier: 1, janv: 1, feb: 2, february: 2, fevrier: 2, février: 2, fev: 2, fév: 2, mar: 3, march: 3, mars: 3, apr: 4, april: 4, avril: 4, avr: 4, may: 5, mai: 5, jun: 6, june: 6, juin: 6, jul: 7, july: 7, juillet: 7, juil: 7, aug: 8, august: 8, aout: 8, août: 8, sep: 9, sept: 9, september: 9, septembre: 9, oct: 10, october: 10, octobre: 10, nov: 11, november: 11, novembre: 11, dec: 12, december: 12, decembre: 12, décembre: 12, déc: 12 };
  const wdRe = Object.keys(WD).sort((a, b) => b.length - a.length).join('|'), moRe = Object.keys(MO).sort((a, b) => b.length - a.length).join('|');
  const ymd = (y, m, d) => { const t = new Date(y, m - 1, d, 12); return t.getMonth() === m - 1 && t.getDate() === d ? isoDay(t) : null; };
  const dm = (d, m, y) => { if (y) return ymd(y < 100 ? 2000 + y : y, m, d); const k = ymd(now.getFullYear(), m, d); return k && k < t0 ? ymd(now.getFullYear() + 1, m, d) : k; };
  if (!skip.has('date')) {
    const set = k => { if (!k) return false; r.due_date = k; };
    take("(?:today|tod|aujourd'hui|aujourd’hui)", () => set(t0))
      || take('(?:après-demain|apres-demain|day after tomorrow)', () => set(addDays(t0, 2)))
      || take('(?:tomorrow|tmrw?|demain)', () => set(addDays(t0, 1)))
      || take('(?:in|dans)\\s+(\\d{1,3})\\s*(days?|jours?|weeks?|semaines?|d|j|w)', m => set(addDays(t0, +m[1] * (/^[ws]/i.test(m[2]) ? 7 : 1))))
      || take('(?:next week|semaine prochaine)', () => set(nextMonday()))
      || take('(\\d{4})-(\\d{2})-(\\d{2})', m => set(ymd(+m[1], +m[2], +m[3])))
      || take(`(\\d{1,2})(?:st|nd|rd|th|er)?\\s+(${moRe})\\.?(?:\\s+(\\d{4}))?`, m => set(dm(+m[1], MO[m[2].toLowerCase()], m[3] && +m[3])))
      || take(`(${moRe})\\.?\\s+(\\d{1,2})(?:st|nd|rd|th)?(?:,?\\s+(\\d{4}))?`, m => set(dm(+m[2], MO[m[1].toLowerCase()], m[3] && +m[3])))
      || take('(\\d{1,2})/(\\d{1,2})(?:/(\\d{2,4}))?', m => { const us = /^en-US/i.test(navigator.language || ''); return set(dm(+(us ? m[2] : m[1]), +(us ? m[1] : m[2]), m[3] && +m[3])); })
      || take(`(next\\s+|prochain\\s+)?(${wdRe})\\.?(\\s+prochain)?`, m => {
        const w = WD[m[2].toLowerCase()];
        if (m[2].toLowerCase() === 'mon' && /^fr/i.test(LOCALE)) return false; // "mon" is "my" in French
        return set(m[1] || m[3] ? addDays(nextMonday(), (w + 6) % 7) : addDays(t0, ((w - now.getDay()) + 7) % 7 || 7));
      });
  }
  const hm = (h, m, ap) => { h = +h; m = +(m || 0); if (ap) { ap = ap.toLowerCase(); if (h > 12 || h < 1) return null; if (ap === 'pm' && h < 12) h += 12; if (ap === 'am' && h === 12) h = 0; } return h < 24 && m < 60 ? `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}` : null; };
  if (!skip.has('time')) {
    const range = (a, b) => { if (!a || !b || b <= a) return false; r.start_time = a; r.end_time = b; };
    take('(?:(?:at|à|a|@)\\s*)?(\\d{1,2})(?::(\\d{2}))?\\s*(am|pm)?\\s*[-–]\\s*(\\d{1,2})(?::(\\d{2}))?\\s*(am|pm)', m => range(hm(m[1], m[2], m[3] || m[6]), hm(m[4], m[5], m[6])))
      || take('(?:(?:at|à|a|@)\\s*)?(\\d{1,2})(?::|h)(\\d{2})\\s*[-–]\\s*(\\d{1,2})(?::|h)(\\d{2})', m => range(hm(m[1], m[2]), hm(m[3], m[4])))
      || take('(?:(?:at|à|@)\\s*)?(\\d{1,2})(?::(\\d{2}))?\\s*(am|pm)', m => { const v = hm(m[1], m[2], m[3]); if (!v) return false; r.start_time = v; })
      || take('(?:(?:at|à|@)\\s*)?(\\d{1,2}):(\\d{2})', m => { const v = hm(m[1], m[2]); if (!v) return false; r.start_time = v; })
      || take('(?:at|à|@)\\s*(\\d{1,2})h(\\d{2})?', m => { const v = hm(m[1], m[2]); if (!v) return false; r.start_time = v; })
      || take('(\\d{1,2})h(\\d{2})', m => { if (+m[1] < 7) return false; const v = hm(m[1], m[2]); if (!v) return false; r.start_time = v; })
      || take('(?:at|à|@)\\s*(\\d{1,2})(?![:.,h\\d])', m => { const v = hm(m[1], 0); if (!v) return false; r.start_time = v; });
  }
  if (!skip.has('estimate')) {
    take('(\\d{1,2})h(\\d{2})', m => { r.estimate_hours = +m[1] + +m[2] / 60; })
      || take('(\\d+(?:[.,]\\d+)?)\\s*(h|hrs?|hours?|heures?)', m => { const v = parseFloat(m[1].replace(',', '.')); if (!(v > 0 && v <= 200)) return false; r.estimate_hours = v; })
      || take('(\\d{1,3})\\s*(m|min|mins|minutes?)', m => { if (!(+m[1] > 0)) return false; r.estimate_hours = Math.round(+m[1] / 60 * 100) / 100; })
      || take('(\\d+(?:[.,]\\d+)?)\\s*(d|days?|j|jours?)', m => { const v = parseFloat(m[1].replace(',', '.')); if (!(v > 0 && v <= 60)) return false; r.estimate_hours = v * 7; });
  }
  if (!skip.has('priority')) take('p([1-5])', m => { r.priority = +m[1]; });
  // #space or #tag
  const norm = s => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]/g, '');
  r.tags = [];
  r.title = r.title.replace(/(^|\s)#([\p{L}\d][\p{L}\d_-]*)/gu, (all, pre, w) => {
    if (r.space === undefined && !skip.has('space')) {
      const n = norm(w), live = state.spaces.filter(x => !x.archived);
      const hit = live.find(x => norm(x.name) === n || norm(x.slug || '') === n) || (n.length >= 3 ? (l => l.length === 1 ? l[0] : null)(live.filter(x => norm(x.name).startsWith(n))) : null);
      if (hit) { r.space = hit.id; return pre; }
    }
    if (skip.has('tags')) return all;
    r.tags.push(w.toLowerCase()); return pre;
  });
  if (r.start_time && !r.due_date && !skip.has('date')) r.due_date = t0;
  if (r.start_time && !r.end_time && r.estimate_hours && r.estimate_hours < 24) {
    const [h, m] = r.start_time.split(':').map(Number), e = h * 60 + m + Math.round(r.estimate_hours * 60);
    if (e < 24 * 60) r.end_time = `${String(Math.floor(e / 60)).padStart(2, '0')}:${String(e % 60).padStart(2, '0')}`;
  }
  r.title = r.title.replace(/\s{2,}/g, ' ').trim().replace(/(?:\s+(?:at|on|by|for|le|à|pour|,|-|–))+$/iu, '').replace(/^(?:(?:on|le|,|-|–)\s+)+/iu, '').trim();
  // chips
  if (r.due_date) { const d = new Date(r.due_date + 'T12:00:00'); r.chips.push({ kind: 'date', text: d.toLocaleDateString(LOCALE, { weekday: 'short', day: 'numeric', ...(r.due_date.slice(0, 7) !== t0.slice(0, 7) ? { month: 'short' } : {}) }) + (r.start_time ? ` · ${r.start_time}${r.end_time ? '–' + r.end_time : ''}` : '') }); }
  else if (r.start_time) r.chips.push({ kind: 'time', text: r.start_time });
  if (r.start_time && r.due_date) r.chips[r.chips.length - 1].kinds = ['date', 'time'];
  if (r.estimate_hours) r.chips.push({ kind: 'estimate', text: fmtH(r.estimate_hours) });
  if (r.priority) r.chips.push({ kind: 'priority', text: `P${r.priority} · ${t(PRIO[r.priority])}` });
  if (r.space !== undefined) r.chips.push({ kind: 'space', text: spaceOf(r.space).name });
  if (r.tags.length) r.chips.push({ kind: 'tags', text: r.tags.map(t => '#' + t).join(' ') });
  return r;
}

/** A quick-add line with live chips. opts: {defaults: () => ({space, status, due_date}), onAdded(card), placeholder, label} */
function quickAdd(opts) {
  const f = document.createElement('form'); f.className = 'qadd' + (opts.compact ? ' compact' : '');
  f.innerHTML = `<div class="qa-line"><input class="qa-in" maxlength="300" autocomplete="off" enterkeyhint="done" placeholder="${esc(opts.placeholder || 'Add a task: call Alex tomorrow 9am #sales')}" aria-label="${esc(opts.label || 'Add a task')}">
    <button type="submit" class="qa-go" aria-label="Add" title="Add">+</button></div><div class="qa-chips" aria-live="polite"></div>`;
  const inp = f.querySelector('.qa-in'), chips = f.querySelector('.qa-chips');
  let skip = new Set();
  const paint = () => {
    const v = inp.value.trim(); f.classList.toggle('typing', !!v);
    if (!v) { chips.innerHTML = ''; skip = new Set(); return; }
    const p = parseQuick(v, skip), d = opts.defaults ? opts.defaults() : {};
    const html = p.chips.map(c => `<button type="button" class="qa-chip" data-k="${(c.kinds || [c.kind]).join(',')}" title="Click to keep this as text"><span class="no-i18n">${esc(c.text)}</span> <span aria-hidden="true">×</span></button>`);
    if (!p.due_date && d.due_date) html.push(`<span class="qa-chip def">${d.due_date === today() ? 'Today' : esc(fmtDay(d.due_date))}</span>`);
    if (p.space === undefined && d.space != null && opts.showSpace) html.push(`<span class="qa-chip def no-i18n">${esc(spaceOf(d.space).name)}</span>`);
    chips.innerHTML = html.join('');
    chips.querySelectorAll('[data-k]').forEach(b => b.onclick = () => { b.dataset.k.split(',').forEach(k => skip.add(k)); paint(); inp.focus(); });
  };
  inp.oninput = paint;
  inp.onkeydown = e => { if (e.key === 'Escape' && inp.value) { e.preventDefault(); inp.value = ''; paint(); } };
  f.onsubmit = async e => {
    e.preventDefault();
    const v = inp.value.trim(); if (!v || f.classList.contains('busy')) return;
    const p = parseQuick(v, skip), d = opts.defaults ? opts.defaults() : {};
    const body = { title: p.title || v, space: p.space ?? d.space ?? state.space };
    if (d.status) body.status = d.status;
    for (const k of ['due_date', 'start_time', 'end_time', 'estimate_hours', 'priority']) if (p[k] != null) body[k] = p[k];
    if (!body.due_date && d.due_date) body.due_date = d.due_date;
    if (p.tags.length) body.tags = p.tags;
    f.classList.add('busy');
    try { const { card } = await post('api/board', body); inp.value = ''; paint(); toast(`${card.code} added`); await opts.onAdded?.(card); }
    catch (err) { toast(err.message); }
    finally { f.classList.remove('busy'); }
  };
  f.focusInput = () => inp.focus();
  return f;
}

/** Today: what is late, what is for today, and what comes next in the overall order; the day's agenda beside it. */
async function renderToday() {
  const t0 = today(), sp = boardPrefs.space === '' ? '' : `space=${boardPrefs.space}`;
  const [pf, cal] = await Promise.all([api(`api/board/portfolio?${sp}`), api(`api/board/calendar?from=${t0}&to=${t0}${sp ? '&' + sp : ''}`)]);
  board.refs = { ...(board.refs || {}), ...pf.refs };
  boardTags(tagCounts(pf.cards)); pf.cards = filterCards(pf.cards); cal.cards = cal.cards.filter(cardMatches);
  const byId = new Map();
  for (const c of [...pf.cards, ...cal.cards]) byId.set(c.id, c);
  const onDay = c => c.due_date === t0 || (c.start_time && (c.start_date || c.due_date) === t0);
  const overdue = pf.cards.filter(c => c.due_date && c.due_date < t0 && !onDay(c));
  const todays = [...byId.values()].filter(c => !overdue.includes(c) && (onDay(c) || c.status === 'doing'))
    .sort((a, b) => (a.status === 'done') - (b.status === 'done') || (a.start_time ? 0 : 1) - (b.start_time ? 0 : 1) || (a.start_time || '').localeCompare(b.start_time || '') || a.priority - b.priority);
  const shown = new Set([...overdue, ...todays].map(c => c.id));
  const ranked = pf.cards.some(c => c.global_rank != null);
  const next = pf.cards.filter(c => !shown.has(c.id) && c.status !== 'idea' && c.status !== 'blocked' && (!c.start_date || c.start_date <= t0)).slice(0, 5);
  const done = todays.filter(c => c.status === 'done').length;
  const planned = todays.filter(c => c.status !== 'done').reduce((a, c) => a + Math.max(0, (c.estimate_hours || 0) - (c.spent_hours || 0)), 0);
  const all = boardPrefs.space === '';

  const root = $('#board'); root.className = 'today'; root.innerHTML = '';
  $('#board-summary').hidden = true;
  const now = new Date();
  const main = document.createElement('div'); main.className = 'td-main';
  main.innerHTML = `<header class="td-head"><div><div class="muted small">${now.toLocaleDateString(LOCALE, { weekday: 'long' })}</div><h2>${now.toLocaleDateString(LOCALE, { day: 'numeric', month: 'long' })}</h2></div>
    <div class="td-progress"><div class="td-nums small muted"><span>${done} of ${todays.length} done</span>${planned ? `<span>${fmtH(Math.round(planned * 4) / 4)} planned</span>` : ''}</div>
    <div class="td-bar" role="progressbar" aria-valuemin="0" aria-valuemax="${todays.length}" aria-valuenow="${done}"><i style="width:${todays.length ? Math.round(done / todays.length * 100) : 0}%"></i></div></div></header>`;
  const qa = quickAdd({ defaults: () => ({ space: all ? state.space : +boardPrefs.space, due_date: t0 }), showSpace: all, onAdded: () => loadBoard().then(() => $('#board .qa-in')?.focus()) });
  main.append(qa);
  const meta = c => {
    const s = spaceOf(c.space), bits = [`<span class="code">${esc(c.code)}</span>`];
    if (all) bits.push(`<span class="kc-space"><i style="background:${esc(s.color || 'var(--muted)')}"></i>${esc(s.name)}</span>`);
    if (c.start_time && (c.start_date || c.due_date) === t0) bits.push(`<span>${esc(c.start_time)}${c.end_time ? '–' + esc(c.end_time) : ''}</span>`);
    else if (c.due_date && c.due_date !== t0) bits.push(`<span class="${dueClass(c)}">${c.due_date < t0 ? `due ${esc(fmtDay(c.due_date))}` : esc(fmtDay(c.due_date))}</span>`);
    if (c.estimate_hours) bits.push(`<span>${fmtH(c.estimate_hours)}</span>`);
    if (c.checklist?.total) bits.push(`<span>☑ ${c.checklist.done}/${c.checklist.total}</span>`);
    if (c.status === 'doing') bits.push(`<span class="td-doing">In progress</span>`);
    if (c.status === 'blocked') bits.push(`<span class="late">Blocked</span>`);
    return bits.join('<span aria-hidden="true">·</span>');
  };
  const row = (c, extra = '') => {
    const el = document.createElement('div'); el.className = 'drow' + (c.status === 'done' ? ' done' : ''); el.dataset.id = c.id; el.tabIndex = 0;
    el.innerHTML = `<button type="button" class="tck" aria-label="${c.status === 'done' ? 'Mark as not done' : 'Mark as done'}" title="${c.status === 'done' ? 'Mark as not done' : 'Mark as done'}"></button>
      <div class="dr-text"><div class="dr-title no-i18n">${esc(c.title)}</div><div class="dr-meta small muted">${meta(c)}</div></div>${extra}
      <span class="prio p${c.priority}" title="P${c.priority} · ${PRIO[c.priority]}">P${c.priority}</span><button type="button" class="icon-btn kmore" aria-label="More actions" title="More actions">⋯</button>`;
    el.querySelector('.tck').onclick = async e => {
      e.stopPropagation(); el.classList.toggle('done');
      try { await post(`api/board/${c.id}/move`, { status: c.status === 'done' ? 'todo' : 'done' }); if (c.status !== 'done') toast(`${c.code} done`); } catch (err) { toast(err.message); }
      loadBoard();
    };
    el.querySelectorAll('[data-day]').forEach(b => b.onclick = async e => { e.stopPropagation(); try { await rescheduleCard(c, b.dataset.day); } catch (err) { toast(err.message); } loadBoard(); });
    el.querySelector('.kmore').onclick = e => { e.stopPropagation(); openMoveSheet(c); };
    el.onclick = () => openCard(c.id);
    el.onkeydown = e => { if (e.key === 'Enter' && e.target === el) openCard(c.id); };
    bindLongPress(el, () => openMoveSheet(c));
    return el;
  };
  const section = (title, cls, cards, extra, empty) => {
    const s = document.createElement('section'); s.className = 'td-sec ' + cls;
    s.innerHTML = `<h3>${title}${cards.length ? ` <span class="muted">· ${cards.length}</span>` : ''}</h3>`;
    cards.forEach(c => s.append(row(c, extra ? extra(c) : '')));
    if (!cards.length && empty) s.insertAdjacentHTML('beforeend', `<p class="td-empty muted small">${empty}</p>`);
    main.append(s);
  };
  if (overdue.length) section('Overdue', 'late', overdue, () => `<span class="td-pills"><button type="button" class="dpill" data-day="${t0}">Today</button><button type="button" class="dpill" data-day="${addDays(t0, 1)}">Tomorrow</button></span>`);
  section('Today', '', todays, null, 'Nothing planned for today. Add a task above, or pick one from below.');
  if (next.length) section(ranked ? 'Next in your overall order' : 'Next up', 'next', next, () => `<span class="td-pills"><button type="button" class="dpill" data-day="${t0}">+ Today</button></span>`);
  root.append(main);

  // the day's agenda: events and timed tasks
  const ag = document.createElement('aside'); ag.className = 'td-agenda'; ag.innerHTML = '<h3>Agenda</h3><div class="td-tl"></div>';
  root.append(ag);
  const draw = events => {
    const items = [];
    for (const e of events || []) { if (e.all_day) { items.push({ all: true, title: e.title, ev: true }); continue; } const s = new Date(e.start * 1000), en = new Date((e.end || e.start + 1800) * 1000); if (isoDay(s) !== t0 && isoDay(en) !== t0) { items.push({ all: true, title: e.title, ev: true }); continue; } items.push({ s: isoDay(s) < t0 ? 0 : s.getHours() + s.getMinutes() / 60, e: isoDay(en) > t0 ? 24 : en.getHours() + en.getMinutes() / 60, title: e.title, sub: e.location || '', ev: true }); }
    for (const c of todays) {
      if (!c.start_time || (c.start_date || c.due_date) !== t0) continue;
      const [h, m] = c.start_time.split(':').map(Number), s = h + m / 60;
      let e = s + (c.estimate_hours && c.estimate_hours < 12 ? c.estimate_hours : 0.5);
      if (c.end_time) { const [h2, m2] = c.end_time.split(':').map(Number); e = h2 + m2 / 60; }
      items.push({ s, e: Math.max(e, s + 0.25), title: `${c.code} ${c.title}`, sub: c.start_time + (c.end_time ? '–' + c.end_time : ''), c });
    }
    const tl = ag.querySelector('.td-tl'), timed = items.filter(i => !i.all).sort((a, b) => a.s - b.s || b.e - a.e);
    const allDay = items.filter(i => i.all);
    const H = 44, from = Math.floor(Math.min(8, ...timed.map(i => i.s))), to = Math.ceil(Math.max(18, ...timed.map(i => i.e)));
    const lanes = []; for (const it of timed) { let l = lanes.findIndex(end => end <= it.s); if (l < 0) { l = lanes.length; lanes.push(0); } lanes[l] = it.e; it.lane = l; }
    // width per overlapping group
    for (const it of timed) it.n = Math.max(1, ...timed.filter(o => o.s < it.e && o.e > it.s).map(o => o.lane + 1));
    let html = allDay.map(i => `<div class="tl-all no-i18n">${esc(i.title)}</div>`).join('');
    html += `<div class="tl-grid" style="height:${(to - from) * H}px">`;
    for (let h = from; h <= to; h++) html += `<div class="tl-h" style="top:${(h - from) * H}px"><span>${String(h).padStart(2, '0')}:00</span></div>`;
    for (const it of timed) html += `<div class="tl-it${it.ev ? ' ev' : ''}${it.c ? ` p${it.c.priority}${it.c.status === 'done' ? ' done' : ''}` : ''}"${it.c ? ` data-id="${it.c.id}" tabindex="0"` : ''} style="top:${(it.s - from) * H + 1}px;height:${Math.max(18, (it.e - it.s) * H - 2)}px;left:calc(48px + (100% - 52px) * ${it.lane} / ${it.n});width:calc((100% - 52px) / ${it.n} - 3px)"><b class="no-i18n">${esc(it.title)}</b>${it.sub ? `<small class="no-i18n">${esc(it.sub)}</small>` : ''}</div>`;
    const n = now.getHours() + now.getMinutes() / 60;
    if (n >= from && n <= to) html += `<div class="tl-now" style="top:${(n - from) * H}px"></div>`;
    html += '</div>';
    if (!timed.length && !allDay.length) html = `<p class="muted small">${cal.calendar ? 'Nothing scheduled today.' : 'Nothing scheduled today. Give a task a time to see it here.'}</p>` + html;
    tl.innerHTML = html;
    tl.querySelectorAll('.tl-it[data-id]').forEach(el => { el.onclick = () => openCard(+el.dataset.id); el.onkeydown = e => { if (e.key === 'Enter') openCard(+el.dataset.id); }; });
    const nowEl = tl.querySelector('.tl-now'); if (nowEl && !matchMedia('(max-width: 760px)').matches) tl.scrollTop = Math.max(0, nowEl.offsetTop - 120);
  };
  draw([]);
  if (cal.calendar) api(`api/board/calendar?from=${t0}&to=${t0}&events=1${sp ? '&' + sp : ''}`).then(d => { if (root.isConnected && root.className === 'today') draw(boardFiltered() ? (d.events || []).filter(eventMatches) : d.events); }).catch(() => {});
}

const hhmm = x => { const m = Math.round(x * 60); return `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`; };
const hnum = s => { const [h, m] = s.split(':').map(Number); return h + (m || 0) / 60; };
const mondayOf = k => addDays(k, -((new Date(k + 'T12:00:00').getDay() + 6) % 7));
const wk = { drag: null, pick: null };
document.addEventListener('keydown', e => { if (e.key === 'Escape' && wk.pick && document.querySelector('#board.week')) { wk.pick = null; loadBoard(); } });
/** The time to book for a card: what is left of its workload (half an hour to 8 hours), else an hour. */
const bookHours = c => { const left = (c.estimate_hours || 0) - (c.spent_hours || 0); return left > 0 ? Math.min(8, Math.max(0.5, Math.round(left * 4) / 4)) : 1; };

/** Book a time for a card on day k from hour s; its due date moves to that day. s null: no time any more, the date stays. */
async function bookSlot(c, k, s, dur = bookHours(c)) {
  const old = { due_date: c.due_date, start_date: c.start_date, start_time: c.start_time, end_time: c.end_time };
  let body;
  if (s === null) { if (!c.start_time) return false; body = { start_time: null, end_time: null }; }
  else {
    dur = Math.min(dur, 23.75); s = Math.max(0, Math.min(s, 23.75 - dur));
    body = { due_date: k, start_date: null, start_time: hhmm(s), end_time: hhmm(s + dur) };
    if (old.due_date === k && !old.start_date && old.start_time === body.start_time && old.end_time === body.end_time) return false;
  }
  const { card } = await post(`api/board/${c.id}`, { version: c.version, ...body });
  const later = s !== null && old.due_date && old.due_date < k && c.status !== 'done';
  const msg = s === null ? `${c.code}: no time booked` : `${c.code}: ${fmtDay(k)}, ${body.start_time}–${body.end_time}` + (later ? ` (was due ${fmtDay(old.due_date)})` : '');
  toastAction(msg, 'Undo', async () => { try { await post(`api/board/${c.id}`, { version: card.version, ...old }); toast('Undone'); } catch (e) { toast(e.message); } loadBoard(); });
  return true;
}

/** Week: the week as an hour grid, with the tasks not booked yet beside it. Drag one onto the grid (or tap it, then a slot) to book time for it. */
async function renderWeek() {
  const t0 = today(), narrow = matchMedia('(max-width: 760px)').matches, H = narrow ? 52 : 48;
  if (!/^\d{4}-\d{2}-\d{2}$/.test(boardPrefs.wday || '')) boardPrefs.wday = t0;
  const n = narrow ? (boardPrefs.wdays === 3 ? 3 : 1) : 7;
  const mon = mondayOf(boardPrefs.wday), sun = addDays(mon, 6);
  const first = narrow ? boardPrefs.wday : mon, last = addDays(first, n - 1);
  const days = Array.from({ length: n }, (_, i) => addDays(first, i)), week = Array.from({ length: 7 }, (_, i) => addDays(mon, i));
  const all = boardPrefs.space === '', sp = all ? '' : `&space=${boardPrefs.space}`, to = last > sun ? last : sun;
  const [pf, cal] = await Promise.all([api(`api/board/portfolio?${sp.slice(1)}`), api(`api/board/calendar?from=${mon}&to=${to}${sp}`)]);
  board.refs = { ...(board.refs || {}), ...pf.refs };
  boardTags(tagCounts(pf.cards)); pf.cards = filterCards(pf.cards); cal.cards = cal.cards.filter(cardMatches);
  board.cards = pf.cards;
  const cap = Math.min(24, Math.max(0, +(boardPrefs.cap ?? 7)));
  const dl = (k, o) => new Date(k + 'T12:00:00').toLocaleDateString(LOCALE, o);
  const cap1 = s => s.charAt(0).toUpperCase() + s.slice(1);
  const weekend = k => new Date(k + 'T12:00:00').getDay() % 6 === 0;

  const root = $('#board'); root.className = 'week' + (wk.pick ? ' picking' : ''); root.innerHTML = '';
  $('#board-summary').hidden = true;
  const range = first === last ? dl(first, { weekday: 'long', day: 'numeric', month: 'long' })
    : new Intl.DateTimeFormat(LOCALE, { day: 'numeric', month: 'short', year: 'numeric' }).formatRange(new Date(first + 'T12:00:00'), new Date(last + 'T12:00:00'));
  const bar = document.createElement('div'); bar.className = 'cal-bar wk-bar';
  bar.innerHTML = `<button type="button" class="icon-btn" data-w="-1" aria-label="${narrow ? 'Earlier' : 'Previous week'}" title="${narrow ? 'Earlier' : 'Previous week'}">‹</button><button type="button" class="btn small ghost" data-w="0">Today</button><button type="button" class="icon-btn" data-w="1" aria-label="${narrow ? 'Later' : 'Next week'}" title="${narrow ? 'Later' : 'Next week'}">›</button>
    <b class="no-i18n">${esc(range)}</b><span class="spacer"></span>${narrow ? `<div class="seg" role="group" aria-label="Days shown"><button type="button" data-n="1"${n === 1 ? ' class="on" aria-pressed="true"' : ' aria-pressed="false"'}>Day</button><button type="button" data-n="3"${n === 3 ? ' class="on" aria-pressed="true"' : ' aria-pressed="false"'}>3 days</button></div>` : ''}`;
  bar.querySelectorAll('[data-w]').forEach(b => b.onclick = () => { const k = +b.dataset.w; boardPrefs.wday = k ? addDays(narrow ? first : mon, k * n) : t0; saveBoardPrefs(); loadBoard(); });
  bar.querySelectorAll('[data-n]').forEach(b => b.onclick = () => { boardPrefs.wdays = +b.dataset.n; saveBoardPrefs(); loadBoard(); });
  root.append(bar);
  const qa = quickAdd({ compact: true, defaults: () => ({ space: all ? state.space : +boardPrefs.space, due_date: days.includes(t0) ? t0 : first }), showSpace: all, onAdded: () => loadBoard().then(() => $('#board .qa-in')?.focus()) });
  root.append(qa);

  // not booked yet: due by the end of the week (or in progress), then the next undated ones in the overall order
  const open = pf.cards.filter(c => !c.start_time && !['idea', 'done', 'blocked'].includes(c.status));
  const dueBy = open.filter(c => (c.due_date && c.due_date <= to) || c.status === 'doing')
    .sort((a, b) => (a.due_date || '9').localeCompare(b.due_date || '9') || a.priority - b.priority);
  const undated = open.filter(c => !c.due_date && c.status !== 'doing' && (!c.start_date || c.start_date <= to)).slice(0, 6);
  const trayH = dueBy.reduce((a, c) => a + bookHours(c), 0);
  const body = document.createElement('div'); body.className = 'wk-body';
  const tray = document.createElement('aside'); tray.className = 'wk-tray';
  const item = c => {
    const s = spaceOf(c.space), late = c.due_date && c.due_date < t0;
    const bits = [`<span class="code">${esc(c.code)}</span>`];
    if (c.due_date) bits.push(`<span class="${late ? 'late' : ''}">${c.due_date === t0 ? 'today' : esc(dl(c.due_date, { weekday: 'short', day: 'numeric' }))}</span>`);
    bits.push(`<span>${fmtH(bookHours(c))}</span>`);
    return `<div class="wk-item${wk.pick?.c.id === c.id ? ' on' : ''}" data-id="${c.id}" draggable="true" tabindex="0" style="--sp:${esc(s.color || 'var(--muted)')}" title="${esc(c.code)} ${esc(c.title)}">
      <div class="wk-it-meta small muted"><i></i>${bits.join('<span aria-hidden="true">·</span>')}<span class="prio p${c.priority}">P${c.priority}</span></div>
      <div class="wk-it-title no-i18n">${esc(c.title)}</div>
      <button type="button" class="wk-book" data-book="${c.id}" aria-label="Book a time" title="Book a time">⏱</button></div>`;
  };
  tray.innerHTML = `<h3>Not planned yet${dueBy.length ? ` <span class="muted">· ${dueBy.length} · ${fmtH(trayH)}</span>` : ''}</h3>
    <p class="muted small wk-hint">${coarse() || narrow ? 'Tap a task, then tap a free slot to book time for it.' : 'Drag a task onto the week to book time for it.'}</p>
    <div class="wk-list">${dueBy.map(item).join('') || `<p class="muted small">Nothing due ${narrow && n < 7 ? 'by then' : 'this week'} is left to plan.</p>`}</div>
    ${undated.length ? `<h4>No date</h4><div class="wk-list">${undated.map(item).join('')}</div>` : ''}
    <div class="wk-load"></div>`;
  body.append(tray);
  const main = document.createElement('section'); main.className = 'wk-main';
  body.append(main);
  root.append(body);
  const cardsById = new Map([...pf.cards, ...cal.cards].map(c => [c.id, c]));

  const setPick = c => {
    wk.pick = c && wk.pick?.c.id !== c.id ? { c, dur: bookHours(c) } : null;
    root.classList.toggle('picking', !!wk.pick);
    tray.querySelectorAll('.wk-item').forEach(el => el.classList.toggle('on', +el.dataset.id === wk.pick?.c.id));
    const pb = main.querySelector('.wk-pickbar');
    pb.hidden = !wk.pick;
    if (wk.pick) { pb.querySelector('span').textContent = `Tap a free slot to place ${wk.pick.c.code}`; if (narrow) main.scrollIntoView({ block: 'start', behavior: 'smooth' }); }
  };
  tray.querySelectorAll('.wk-item').forEach(el => {
    const c = cardsById.get(+el.dataset.id);
    el.onclick = e => { if (e.target.closest('.wk-book') || coarse() || narrow) setPick(c); else openCard(c.id); };
    el.onkeydown = e => { if (e.key === 'Enter' && e.target === el) openCard(c.id); if (e.key === ' ' && e.target === el) { e.preventDefault(); setPick(c); } };
    el.ondragstart = e => { e.dataTransfer.setData('text/plain', String(c.id)); e.dataTransfer.effectAllowed = 'move'; wk.drag = { c, dur: bookHours(c), off: 0 }; el.classList.add('dragging'); };
    el.ondragend = () => { el.classList.remove('dragging'); wk.drag = null; main.querySelectorAll('.wk-ghost').forEach(g => g.remove()); };
    bindLongPress(el, () => openMoveSheet(c));
  });
  // a booked task dragged back to the tray loses its time
  tray.ondragover = e => { if (wk.drag?.c.start_time) { e.preventDefault(); tray.classList.add('over'); } };
  tray.ondragleave = e => { if (!tray.contains(e.relatedTarget)) tray.classList.remove('over'); };
  tray.ondrop = async e => { e.preventDefault(); tray.classList.remove('over'); const d = wk.drag; wk.drag = null; if (!d) return; try { if (!await bookSlot(d.c, null, null)) return; } catch (err) { toast(err.message); } loadBoard(); };

  const draw = events => {
    // what fills each day: timed tasks and events, in hours
    const per = {}, dueOn = {}, allDay = {};
    const add = (k, it) => (per[k] ||= []).push(it);
    for (const c of cal.cards) {
      if (c.start_time && (c.start_date || c.due_date)) {
        const k = c.start_date || c.due_date, s = hnum(c.start_time);
        let e = c.end_time && (c.due_date || k) === k ? hnum(c.end_time) : s + 1;
        if (e <= s) e = s + 0.5;
        add(k, { s, e, c });
      } else if (c.due_date) (dueOn[c.due_date] ||= []).push(c);
    }
    for (const ev of events || []) {
      const s = new Date(ev.start * 1000), en = new Date((ev.end || ev.start + 1800) * 1000 - (ev.all_day ? 1000 : 0));
      for (const d = new Date(s.getFullYear(), s.getMonth(), s.getDate(), 12); isoDay(d) <= isoDay(en); d.setDate(d.getDate() + 1)) {
        const k = isoDay(d);
        if (k < mon || k > to) continue;
        if (ev.all_day) { (allDay[k] ||= []).push(ev); continue; }
        add(k, { s: isoDay(s) < k ? 0 : s.getHours() + s.getMinutes() / 60, e: isoDay(en) > k ? 24 : Math.max(en.getHours() + en.getMinutes() / 60, 0.25), ev });
      }
    }
    const booked = k => (per[k] || []).reduce((a, it) => a + (it.e - it.s), 0);
    // the load: booked against what a working week holds
    const tot = week.reduce((a, k) => a + booked(k), 0), capW = cap * week.filter(k => !weekend(k)).length;
    const full = week.filter(k => !weekend(k) && cap && booked(k) >= cap);
    const pct = capW ? Math.min(100, Math.round(tot / capW * 100)) : 0;
    tray.querySelector('.wk-load').innerHTML = `<div class="wk-load-nums small"><span>Booked this week</span><b class="no-i18n">${+(Math.round(tot * 4) / 4).toFixed(2)}h / ${capW}h</b></div>
      <div class="td-bar${tot > capW ? ' over' : ''}" role="progressbar" aria-label="Booked this week" aria-valuemin="0" aria-valuemax="${capW}" aria-valuenow="${Math.round(tot * 4) / 4}"><i style="width:${pct}%"></i></div>
      ${full.map(k => { const b = Math.round(booked(k) * 4) / 4; return `<p class="small ${b > cap ? 'late' : 'warn'}">${b > cap ? `${esc(cap1(dl(k, { weekday: 'long' })))} is over: ${fmtH(b)} of ${fmtH(cap)}.` : `${esc(cap1(dl(k, { weekday: 'long' })))} is full: ${fmtH(b)} of ${fmtH(cap)}.`}</p>`; }).join('')}
      <label class="small muted wk-cap">Hours a day <input type="number" min="0" max="24" step="0.5" value="${cap}" aria-label="Hours a day"></label>
      <p class="muted small">Tasks and meetings with a time count.</p>`;
    const capIn = tray.querySelector('.wk-cap input');
    capIn.onchange = () => { const v = parseFloat(capIn.value); if (v >= 0 && v <= 24) { boardPrefs.cap = v; saveBoardPrefs(); draw(events); } };

    const timed = days.flatMap(k => per[k] || []);
    const from = Math.max(0, Math.floor(Math.min(8, ...timed.map(i => i.s)))), end = Math.min(24, Math.ceil(Math.max(18, ...timed.map(i => i.e))));
    let html = '';
    if (narrow) html += `<div class="wk-strip">${week.map(k => { const b = booked(k); return `<button type="button" class="${days.includes(k) ? 'on' : ''}${k === t0 ? ' today' : ''}" data-day="${k}"><small>${esc(dl(k, { weekday: 'narrow' }))}</small><b>${+k.slice(8)}</b><span class="${cap && !weekend(k) && b >= cap ? 'full' : ''}">${b ? fmtH(Math.round(b * 2) / 2) : '·'}</span></button>`; }).join('')}</div>`;
    html += `<div class="wk-pickbar"${wk.pick ? '' : ' hidden'}><span>${wk.pick ? `Tap a free slot to place ${esc(wk.pick.c.code)}` : ''}</span><button type="button" class="btn small ghost">Cancel</button></div>`;
    html += `<div class="wk-cal" style="--n:${n};--hh:${H}px"><div class="wk-head"><div></div>`;
    for (const k of days) {
      const due = (dueOn[k] || []).sort((a, b) => (a.status === 'done') - (b.status === 'done') || a.priority - b.priority);
      const b = booked(k);
      html += `<div class="wk-dh${k === t0 ? ' today' : ''}${weekend(k) ? ' weekend' : ''}"><div class="wk-dn"><span>${esc(dl(k, { weekday: 'short' }))}</span><b>${+k.slice(8)}</b>${b ? `<small class="${cap && !weekend(k) && b >= cap ? 'full' : ''}" title="Booked">${fmtH(Math.round(b * 4) / 4)}</small>` : ''}</div>
        ${due.map(c => `<button type="button" class="wk-due p${c.priority}${c.status === 'done' ? ' done' : ''}${k < t0 && c.status !== 'done' ? ' late' : ''}" data-id="${c.id}" draggable="${c.status !== 'done'}" title="${esc(c.code)} ${esc(c.title)}"><span>Due</span> · <b class="no-i18n">${esc(c.code)}</b></button>`).join('')}
        ${(allDay[k] || []).map(ev => `<div class="wk-allday no-i18n" title="${esc(ev.title)}">${esc(ev.title)}</div>`).join('')}</div>`;
    }
    html += `</div><div class="wk-scroll"><div class="wk-grid" style="height:${(end - from) * H}px"><div class="wk-hours">`;
    for (let h = from; h < end; h++) html += `<span style="top:${(h - from) * H}px">${String(h).padStart(2, '0')}:00</span>`;
    html += '</div>';
    const now = new Date(), nowH = now.getHours() + now.getMinutes() / 60;
    for (const k of days) {
      const items = (per[k] || []).sort((a, b) => a.s - b.s || b.e - a.e), lanes = [];
      for (const it of items) { let l = lanes.findIndex(e => e <= it.s); if (l < 0) { l = lanes.length; lanes.push(0); } lanes[l] = it.e; it.lane = l; }
      for (const it of items) it.n = Math.max(1, ...items.filter(o => o.s < it.e && o.e > it.s).map(o => o.lane + 1));
      html += `<div class="wk-col${k === t0 ? ' today' : ''}${weekend(k) ? ' weekend' : ''}" data-day="${k}">`;
      for (const it of items) {
        const top = (Math.max(it.s, from) - from) * H + 1, hgt = Math.max(16, (Math.min(it.e, end) - Math.max(it.s, from)) * H - 2), pos = `top:${top}px;height:${hgt}px;left:calc(${it.lane} * 100% / ${it.n} + 2px);width:calc(100% / ${it.n} - 4px)`;
        const sh = hgt < 40 ? ' short' : '';
        if (it.ev) html += `<div class="wk-blk ev${sh}" style="${pos}" title="${esc(it.ev.title)}${it.ev.location ? ' · ' + esc(it.ev.location) : ''}"><b class="no-i18n">${esc(it.ev.title)}</b><small><span class="no-i18n">${hhmm(it.s)}</span> · agenda</small></div>`;
        else { const c = it.c, s = spaceOf(c.space); html += `<div class="wk-blk p${c.priority}${c.status === 'done' ? ' done' : ''}${sh}" data-id="${c.id}" draggable="true" tabindex="0" style="${pos};--sp:${esc(s.color || 'var(--accent)')}" title="${esc(c.code)} ${esc(c.title)} · ${esc(c.start_time)}–${esc(c.end_time || hhmm(it.e))}"><b class="no-i18n">${esc(c.title)}</b><small class="no-i18n">${esc(c.code)} · ${esc(c.start_time)}–${esc(c.end_time || hhmm(it.e))}</small></div>`; }
      }
      if (k === t0 && nowH >= from && nowH <= end) html += `<div class="tl-now wk-now" style="top:${(nowH - from) * H}px"></div>`;
      html += '</div>';
    }
    html += '</div></div></div>';
    main.innerHTML = html;
    if (!timed.length && !days.some(k => dueOn[k]?.length || allDay[k]?.length)) main.insertAdjacentHTML('beforeend', `<p class="muted small wk-empty">${narrow ? 'Nothing booked yet.' : 'Nothing booked this week yet.'}</p>`);

    main.querySelector('.wk-pickbar button').onclick = () => setPick(null);
    main.querySelectorAll('.wk-strip [data-day]').forEach(b => b.onclick = () => { boardPrefs.wday = b.dataset.day; saveBoardPrefs(); loadBoard(); });
    const at = (col, y) => Math.max(from, Math.min(end - 0.5, from + Math.round((y - col.getBoundingClientRect().top) / H * 2) / 2));
    const ghost = (col, s, dur, code) => {
      let g = col.querySelector('.wk-ghost'); main.querySelectorAll('.wk-ghost').forEach(x => x !== g && x.remove());
      if (!g) { g = document.createElement('div'); g.className = 'wk-ghost'; col.append(g); }
      g.style.top = `${(s - from) * H}px`; g.style.height = `${Math.min(dur, end - s) * H}px`;
      g.textContent = `${code} · ${hhmm(s)}–${hhmm(Math.min(23.75, s + dur))}`;
    };
    main.querySelectorAll('.wk-col').forEach(col => {
      const k = col.dataset.day;
      col.ondragover = e => { if (!wk.drag) return; e.preventDefault(); ghost(col, at(col, e.clientY - wk.drag.off * H), wk.drag.dur, wk.drag.c.code); };
      col.ondragleave = e => { if (!col.contains(e.relatedTarget)) col.querySelector('.wk-ghost')?.remove(); };
      col.ondrop = async e => {
        e.preventDefault(); const d = wk.drag; wk.drag = null; if (!d) return;
        const s = at(col, e.clientY - d.off * H);
        try { if (!await bookSlot(d.c, k, s, d.dur)) { col.querySelector('.wk-ghost')?.remove(); return; } } catch (err) { toast(err.message); }
        loadBoard();
      };
      col.onmousemove = e => { if (wk.pick && !e.target.closest('.wk-blk')) ghost(col, at(col, e.clientY), wk.pick.dur, wk.pick.c.code); };
      col.onmouseleave = () => { if (wk.pick) col.querySelector('.wk-ghost')?.remove(); };
      col.onclick = async e => {
        if (!wk.pick || e.target.closest('.wk-blk:not(.ev)')) return;
        const p = wk.pick; setPick(null);
        try { if (!await bookSlot(p.c, k, at(col, e.clientY), p.dur)) return; } catch (err) { toast(err.message); }
        loadBoard();
      };
    });
    main.querySelectorAll('.wk-blk[data-id]').forEach(el => {
      const c = cardsById.get(+el.dataset.id), it = per[c.start_date || c.due_date]?.find(x => x.c === c);
      el.onclick = e => { if (wk.pick) return; e.stopPropagation(); openCard(c.id); };
      el.onkeydown = e => { if (e.key === 'Enter') openCard(c.id); };
      el.ondragstart = e => { e.dataTransfer.setData('text/plain', String(c.id)); e.dataTransfer.effectAllowed = 'move'; wk.drag = { c, dur: it ? it.e - it.s : 1, off: Math.round((e.clientY - el.getBoundingClientRect().top) / H * 2) / 2 }; setTimeout(() => el.classList.add('dragging')); };
      el.ondragend = () => { el.classList.remove('dragging'); wk.drag = null; main.querySelectorAll('.wk-ghost').forEach(g => g.remove()); };
      bindLongPress(el, () => openMoveSheet(c));
    });
    main.querySelectorAll('.wk-due').forEach(el => {
      const c = cardsById.get(+el.dataset.id);
      el.onclick = () => { if (c.status !== 'done' && (coarse() || narrow)) setPick(c); else openCard(c.id); };
      el.ondragstart = e => { e.dataTransfer.setData('text/plain', String(c.id)); wk.drag = { c, dur: bookHours(c), off: 0 }; };
      el.ondragend = () => { wk.drag = null; main.querySelectorAll('.wk-ghost').forEach(g => g.remove()); };
    });
    const sc = main.querySelector('.wk-scroll'), nowEl = main.querySelector('.wk-now');
    sc.scrollTop = nowEl ? Math.max(0, nowEl.offsetTop - 2 * H) : Math.max(0, (8 - from) * H);
  };
  draw([]);
  if (cal.calendar) api(`api/board/calendar?from=${mon}&to=${to}&events=1${sp}`).then(d => { if (root.isConnected && root.classList.contains('week')) { draw(boardFiltered() ? (d.events || []).filter(eventMatches) : d.events); if (d.events_error) toast('Your agenda could not be read: ' + d.events_error); } }).catch(() => {});
}

/** Long-press (touch) opens a menu; right-click too. The click that follows a long-press is swallowed. */
function bindLongPress(el, fn) {
  let timer = 0, x = 0, y = 0, fired = false;
  el.addEventListener('contextmenu', e => { if (e.target.closest('input, textarea, a')) return; e.preventDefault(); fired = false; clearTimeout(timer); fn(); });
  el.addEventListener('touchstart', e => { if (e.touches.length !== 1) return; fired = false; x = e.touches[0].clientX; y = e.touches[0].clientY; clearTimeout(timer); timer = setTimeout(() => { fired = true; navigator.vibrate?.(10); fn(); }, 500); }, { passive: true });
  el.addEventListener('touchmove', e => { if (Math.hypot(e.touches[0].clientX - x, e.touches[0].clientY - y) > 8) clearTimeout(timer); }, { passive: true });
  el.addEventListener('touchend', e => { clearTimeout(timer); if (fired) e.preventDefault(); }, { passive: false });
  el.addEventListener('touchcancel', () => clearTimeout(timer));
  el.addEventListener('click', e => { if (fired) { fired = false; e.preventDefault(); e.stopImmediatePropagation(); } }, true);
}

/** The move sheet: status, date, and a few actions, reachable without drag and drop. */
async function openMoveSheet(c) {
  const dlg = $('#move-sheet'), t0 = today(), labels = STATUS_LABEL();
  const doing = (board.cards || []).filter(x => x.status === 'doing' && x.space === c.space && x.id !== c.id).length;
  dlg.innerHTML = `<form method="dialog" class="ms">
    <div class="ms-grab" aria-hidden="true"></div>
    <div class="ms-head"><div><span class="muted small no-i18n">${esc(c.code)} · ${esc(spaceOf(c.space).name)}</span><strong class="no-i18n">${esc(c.title)}</strong></div><button type="submit" class="icon-btn" aria-label="Close" title="Close">✕</button></div>
    <h4>Move to</h4>
    <div class="ms-st">${Object.entries(labels).map(([k, v]) => `<button type="button" class="ms-opt s-${k}${k === c.status ? ' on' : ''}" data-st="${k}"${k === c.status ? ' aria-current="true"' : ''}><i></i><span>${esc(v)}</span>${k === c.status ? '<em>✓ current</em>' : k === 'doing' && doing >= 3 ? `<em class="warn">${doing} already in progress</em>` : ''}</button>`).join('')}</div>
    <h4>Reschedule</h4>
    <div class="ms-dates">${[['Today', t0], ['Tomorrow', addDays(t0, 1)], ['Next week', nextMonday()]].map(([l, k]) => `<button type="button" class="dpill${(c.due_date || c.start_date) === k ? ' on' : ''}" data-day="${k}">${l}</button>`).join('')}
      <label class="dpill ms-pick">Pick…<input type="date" value="${esc(c.due_date || c.start_date || '')}" aria-label="Pick a date"></label>${c.due_date || c.start_date ? '<button type="button" class="dpill" data-day="">No date</button>' : ''}</div>
    <div class="ms-acts"><button type="button" class="btn small ghost" data-a="open">Open</button><button type="button" class="btn small ghost" data-a="dup">Duplicate</button><span class="spacer"></span><button type="button" class="btn small ghost danger" data-a="trash">Trash</button></div>
  </form>`;
  const done = () => { dlg.close(); loadBoard(); };
  dlg.querySelectorAll('[data-st]').forEach(b => b.onclick = async () => {
    if (b.dataset.st === c.status) return dlg.close();
    try { await post(`api/board/${c.id}/move`, { status: b.dataset.st }); toast(`${c.code}: ${labels[b.dataset.st]}`); } catch (e) { toast(e.message); }
    done();
  });
  const day = async k => { try { await rescheduleCard(c, k); } catch (e) { toast(e.message); } done(); };
  dlg.querySelectorAll('[data-day]').forEach(b => b.onclick = () => day(b.dataset.day || null));
  const pick = dlg.querySelector('.ms-pick input');
  pick.onchange = () => { if (/^\d{4}-\d{2}-\d{2}$/.test(pick.value)) day(pick.value); };
  dlg.querySelector('.ms-pick').onclick = e => { if (e.target === pick) return; e.preventDefault(); try { pick.showPicker(); } catch { pick.focus(); } };
  dlg.querySelector('[data-a=open]').onclick = () => { dlg.close(); openCard(c.id); };
  dlg.querySelector('[data-a=dup]').onclick = async () => {
    try {
      const { card: f } = await api(`api/board/${c.id}`);
      const body = { space: f.space, title: `${f.title} ${t('(copy)')}`, body: f.body, priority: f.priority, tags: f.tags, status: f.status === 'done' ? 'todo' : f.status };
      for (const k of ['estimate_hours', 'start_date', 'due_date', 'start_time', 'end_time']) if (f[k] != null && f[k] !== '') body[k] = f[k];
      const { card } = await post('api/board', body); toast(`${card.code} added`);
    } catch (e) { toast(e.message); }
    done();
  };
  dlg.querySelector('[data-a=trash]').onclick = async () => {
    try { await post(`api/board/${c.id}/delete`); toastAction(`${c.code} moved to the trash`, 'Restore', async () => { try { await post(`api/board/${c.id}/restore`); toast('Restored'); loadBoard(); } catch (e) { toast(e.message); } }); } catch (e) { toast(e.message); }
    done();
  };
  dlg.onclick = e => { if (e.target === dlg) dlg.close(); };
  dlg.showModal();
  dlg.querySelector('.ms-opt.on, .ms-opt')?.focus();
}

let cardRte = null;
/** Open a task (id) or a new one (prefill: {space, status}). */
async function openCard(id, prefill = {}) {
  const dlg = $('#card-dialog');
  if (!cardRte) cardRte = makeRte($('#cd-rte'));
  let d = { card: { space: prefill.space ?? state.space, status: prefill.status || 'todo', priority: 3, title: prefill.title || '', body: prefill.body || '', due_date: prefill.due_date || null,
    tags: [], links: prefill.links || [], depends_on: [], blocks: [], in_calendar: 0 }, can_invite: true, history: [], time: [], depends_on: [], blocks: [] };
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
  $('#cd-from').value = c.start_time || ''; $('#cd-to').value = c.end_time || '';
  $('#cd-from').onchange = () => { // a time makes it a meeting: one hour by default, shown in the calendar
    const f = $('#cd-from').value; if (!f) return;
    if (!$('#cd-to').value) { const [h, m] = f.split(':').map(Number); $('#cd-to').value = `${String(Math.min(h + 1, 23)).padStart(2, '0')}:${String(h >= 23 ? 59 : m).padStart(2, '0')}`; }
    if (!c.start_time) $('#cd-cal').checked = true;
    if (!$('#cd-start').value && !$('#cd-due').value) $('#cd-due').value = today();
  };
  $('#cd-estimate').value = c.estimate_hours != null ? fmtH(c.estimate_hours) : '';
  $('#cd-cal').checked = !!c.in_calendar;
  $('#cd-tags').value = (c.tags || []).join(', ');
  $('#cd-guests').value = (c.attendees || []).join(', '); $('#cd-loc').value = c.location || '';
  // meeting invitations, by email (Outlook, Gmail, Apple Calendar... show Accept / Decline)
  const sent = c.invited?.to?.length ? c.invited : null;
  const paintInvite = () => {
    const guests = $('#cd-guests').value.trim();
    $('#cd-invite').hidden = !guests && !sent;
    const changed = sent && (c.invite_state === 'outdated' || guests !== (c.attendees || []).join(', ') || $('#cd-loc').value !== (c.location || '')
      || $('#cd-title').value !== c.title || ['start', 'due', 'from', 'to'].some(k => $('#cd-' + k).value !== (c[{ start: 'start_date', due: 'due_date', from: 'start_time', to: 'end_time' }[k]] || '')));
    $('#cd-invite-state').textContent = !d.can_invite ? t('To send invitations, connect your mailbox in Settings.')
      : !sent ? t('Not invited yet: they get an invitation to accept in their calendar (Outlook, Gmail, Apple…).')
      : changed ? t('Changed since the invitations: send them the update.')
      : t('Invitations sent on {a} to {b}.', { a: new Date(sent.at * 1000).toLocaleString(LOCALE, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }), b: sent.to.join(', ') });
    $('#cd-invite-send').textContent = !sent ? t('Send the invitations') : changed ? t('Send the update') : t('Send again');
    $('#cd-invite-send').disabled = !d.can_invite || !guests;
    $('#cd-invite-cancel').hidden = !sent || !d.can_invite;
  };
  ['cd-guests', 'cd-loc', 'cd-title', 'cd-start', 'cd-due', 'cd-from', 'cd-to'].forEach(i => $('#' + i).oninput = paintInvite);
  $('#cd-invite-send').onclick = async () => {
    if (!$('#cd-start').value && !$('#cd-due').value) { toast(t('An invitation needs a date.')); return; }
    try {
      let id = c.id;
      if (isNew) id = (await post('api/board', formBody())).card.id; // saved first: the guests get what is shown
      else await post(`api/board/${c.id}`, { ...formBody(), version: c.version });
      const r = await post(`api/board/${id}/invite`, {});
      toast(r.result); close(); loadBoard();
    } catch (err) { toast(err.message); }
  };
  $('#cd-invite-cancel').onclick = async () => {
    if (!confirm(t('Tell the {a} guest(s) that the meeting is cancelled?', { a: sent.to.length }))) return;
    try { const r = await post(`api/board/${c.id}/invite`, { cancel: true }); toast(r.result); close(); loadBoard(); } catch (err) { toast(err.message); }
  };
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
    if (sent && d.can_invite && confirm(t('Also tell the {a} guest(s) that the meeting is cancelled?', { a: sent.to.length }))) {
      try { await post(`api/board/${c.id}/invite`, { cancel: true }); } catch (err) { toast(err.message); return; }
    }
    try { const r = await post(`api/board/${c.id}/delete`); close(); toast(`${c.code} moved to the trash` + (r.waiting.length ? ` (${r.waiting.join(', ')} waited for it)` : '')); loadBoard(); } catch (err) { toast(err.message); }
  };
  const formBody = () => ({ title: $('#cd-title').value, body: cardRte.value, status: $('#cd-status').value, priority: +$('#cd-priority').value, start_date: $('#cd-start').value,
    due_date: $('#cd-due').value, start_time: $('#cd-from').value, end_time: $('#cd-from').value ? $('#cd-to').value : '', estimate_hours: $('#cd-estimate').value.trim(), in_calendar: $('#cd-cal').checked, tags: $('#cd-tags').value,
    attendees: $('#cd-guests').value, location: $('#cd-loc').value,
    space: +$('#cd-space').value, depends_on: deps.map(x => x.id), ...(isNew && c.links?.length ? { links: c.links } : {}) });
  $('#card-form').onsubmit = async e => {
    e.preventDefault();
    const body = formBody();
    try {
      if (isNew) { const r = await post('api/board', body); toast(`${r.card.code} added`); }
      else await post(`api/board/${c.id}`, { ...body, version: c.version });
      close(); loadBoard();
    } catch (err) {
      if (err.status === 409) { if (confirm(err.message + '\n\nReload it? (your changes in this window will be lost)')) { close(); openCard(c.id); } }
      else toast(err.message);
    }
  };
  paintInvite();
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
  boardTags(tagCounts(d.cards)); d.cards = filterCards(d.cards); if (boardFiltered()) d.events = (d.events || []).filter(eventMatches);
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
  bar.querySelector('#cal-link').onclick = () => showCalendarLink(d.google ? 'Google Calendar' : d.outlook ? 'Outlook' : '');
  root.append(bar);
  if (d.events_error) root.append(Object.assign(document.createElement('p'), { className: 'muted small', textContent: 'Your agenda could not be read: ' + d.events_error }));
  // what goes on each day
  const days = {};
  const put = (day, item) => (days[day] ||= []).push(item);
  for (const c of d.cards) {
    if (c.start_time) { put(c.start_date || c.due_date, { c, kind: 'slot', time: c.start_time }); if (c.due_date && c.start_date && c.due_date !== c.start_date) put(c.due_date, { c, kind: 'due' }); continue; }
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
    const c = it.c, s = spaceOf(c.space), late = it.kind !== 'start' && c.status !== 'done' && (c.due_date || c.start_date) < t0;
    const hours = c.start_time ? c.start_time + (c.end_time ? '–' + c.end_time : '') : '';
    return `<div class="cal-task p${c.priority}${c.status === 'done' ? ' done' : ''}${late ? ' late' : ''}${it.kind === 'start' ? ' start' : ''}" data-id="${c.id}" draggable="${it.kind !== 'start'}" style="--sp:${esc(s.color || 'var(--muted)')}"
      title="${esc(c.code)} ${esc(c.title)} · ${it.kind === 'start' ? 'starts' : it.kind === 'slot' ? esc(hours) : 'due'}${c.in_calendar ? ' · in your calendar' : ''}">${it.kind === 'start' ? '▸ ' : ''}${it.kind === 'slot' ? `<span>${esc(c.start_time)}</span> ` : ''}<b>${esc(c.code)}</b> ${esc(c.title)}</div>`;
  };
  const sortDay = list => list.sort((a, b) => (a.time ? 0 : 1) - (b.time ? 0 : 1) || (a.time || '').localeCompare(b.time || '') || (a.e ? 0 : 1) - (b.e ? 0 : 1) || (a.c && b.c ? a.c.priority - b.c.priority : 0));
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
        const id = +e.dataTransfer.getData('text/plain'); const c = d.cards.find(x => x.id === id); if (!c) return;
        try { if (!await rescheduleCard(c, k)) return; } catch (err) { toast(err.message); }
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
    ${google ? `<p class="small">${esc(google)} is connected: they are added there automatically and kept up to date.</p>` : ''}
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
// "2026-10-17 13:35 message": the message on its own line, so it is translated like any other text
const lastError = (e, what = 'Last error') => { const m = String(e).match(/^(\d{4}-\d\d-\d\d \d\d:\d\d) ([\s\S]*)$/);
  return `<p class="err">${what}${m ? ` (${esc(m[1])})` : ''}:</p><p class="err">${esc(m ? m[2] : e)}</p>`; };
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
      <p>${esc(c.detail)}</p>${c.error ? lastError(c.error) : ''}`;
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
  setInfo('channels', channels.filter(c => c.state === 'on').length);
}

// ---- updates ----
async function loadUpdate() {
  let d;
  try { d = await api('api/update'); }
  catch { if (state.updating) setTimeout(loadUpdate, 3000); return; } // the app restarts during an update
  const box = $('#update'); box.hidden = false; sectionTabs('system');
  if (!d.installed) { box.innerHTML = `<div class="row between"><h3>Updates</h3><span class="version">${esc(t('Version {v}', { v: d.version?.label || '?' }))}</span></div><p class="muted small">Updates from the app work on an installation made with <code>git clone</code> + <code>install.sh</code>.</p>`; return; }
  const v = d.version || {}, st = d.status || {};
  const busy = ['updating', 'checking'].includes(st.state);
  setInfo('update', st.state === 'available');
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
  return `<input id="${id}" type="${f.type === 'number' ? 'text' : f.type === 'url' ? 'url' : 'text'}" ${f.type === 'number' ? 'inputmode="decimal"' : ''} value="${esc(f.value)}" placeholder="${esc(f.default || '')}"${f.locked ? ' disabled' : ''}>`;
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
  if ((m.pull?.state === 'running' || idx < 100) && box.isConnected) loadMeaning.t = setTimeout(() => { if (box.isConnected && !box.closest('.set-sec')?.hidden) loadMeaning(box).catch(() => {}); }, 5000);
}
// ---- settings page: sections, one Save for everything, search ----
const SET_SECTIONS = [
  ['essentials', 'Essentials', 'M12 3l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.4 6.8 19.1l1-5.8L3.5 9.2l5.9-.9z'],
  ['model', 'Model and budget', 'M9 3v3M15 3v3M9 18v3M15 18v3M3 9h3M3 15h3M18 9h3M18 15h3M6 6h12v12H6zM10 10h4v4h-4z'],
  ['channels', 'Channels', 'M4 5h16v11H8l-4 4z'],
  ['accounts', 'Connected accounts', 'M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1'],
  ['library', 'Library and memory', 'M4 4h5v16H4zM10 4h5v16h-5zM16 5l4 1-3 14-4-1'],
  ['security', 'Security', 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z'],
  ['system', 'System', 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8zM12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M5 19l2-2M17 7l2-2'],
];
/** Old links (#settings/brain, #settings/memory…) and names used in messages: which section they mean. */
const SET_ALIAS = { brain: 'model', budget: 'model', memory: 'library', search: 'library', connectors: 'accounts', google: 'accounts', calendar: 'accounts',
  spotify: 'accounts', legifrance: 'accounts', mcp: 'accounts', mailbox: 'accounts', notify: 'channels', update: 'system', backups: 'system', totp: 'security' };
/** Fields only useful with a given choice; shown anyway once they hold a value. */
const SHOW_IF = {
  ANTHROPIC_API_KEY: ['TALIXIO_PROVIDER', ['anthropic']], CLAUDE_CODE_OAUTH_TOKEN: ['TALIXIO_PROVIDER', ['claude-code']],
  OPENAI_API_KEY: ['TALIXIO_PROVIDER', ['openai']], OPENAI_BASE_URL: ['TALIXIO_PROVIDER', ['openai']],
  TALIXIO_SOVEREIGN: ['TALIXIO_PROVIDER', ['sovereign']], TALIXIO_SOVEREIGN_KEY: ['TALIXIO_PROVIDER', ['sovereign']], TALIXIO_SOVEREIGN_URL: ['TALIXIO_PROVIDER', ['sovereign']],
  BRAVE_API_KEY: ['TALIXIO_SEARCH', ['brave']], TAVILY_API_KEY: ['TALIXIO_SEARCH', ['tavily']], SEARXNG_URL: ['TALIXIO_SEARCH', ['searxng']],
};
state.setInfo = {};
const setFields = () => (state.setGroups || []).flatMap(g => g.fields);
const fold = s => (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
function setSection() {
  const p = location.hash.split('/')[1] || '';
  const id = SET_ALIAS[p] || p;
  return SET_SECTIONS.some(s => s[0] === id) ? id : (state.setSection || 'essentials');
}
function showSection(id, scroll = true) {
  id = SET_ALIAS[id] || id;
  if (!SET_SECTIONS.some(s => s[0] === id)) id = 'essentials';
  state.setSection = id;
  if ($('#set-search').value) { $('#set-search').value = ''; setSearch(); }
  document.querySelectorAll('#view-settings .set-sec').forEach(s => { s.hidden = s.dataset.sec !== id; });
  sectionTabs(id);
  document.querySelectorAll('#set-nav a, #set-chips a').forEach(a => { a.classList.toggle('on', a.dataset.sec === id); a.setAttribute('aria-current', a.dataset.sec === id ? 'page' : 'false'); });
  $('#set-chips a.on')?.scrollIntoView({ block: 'nearest', inline: 'center' });
  if (location.hash !== '#settings/' + id) history.replaceState(null, '', '#settings/' + id);
  if (scroll) { const pg = $('#view-settings'); const top = $('#view-settings .set-layout').offsetTop; if (pg.scrollTop > top) pg.scrollTop = top - 8; }
}
/** The menu (computer) and chips (phone), each section with a word on its state. */
function renderSetNav() {
  const i = state.setInfo, s = state.status || {};
  const note = {
    model: s.model_problem ? ['w', t('to set up')] : i.budget ? ['w', t('budget reached')] : ['o', shortModel(s.model || '')],
    channels: i.channels != null ? [i.channels ? 'o' : 'w', t('{n} active', { n: i.channels })] : null,
    accounts: i.accounts != null ? [i.accounts ? 'o' : '', t('{n} connected', { n: i.accounts })] : null,
    security: i.totp != null ? (i.totp ? ['o', t('two-step on')] : ['w', t('two-step off')]) : null,
    system: i.update ? ['w', t('update available')] : null,
  };
  const html = (chip) => SET_SECTIONS.map(([id, title, d]) => {
    const n = note[id];
    return `<a href="#settings/${id}" data-sec="${id}" class="${id === state.setSection ? 'on' : ''}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="${d}"/></svg>
      <span class="sn-t">${esc(t(title))}${!chip && n?.[1] ? `<small>${esc(n[1])}</small>` : ''}</span>${n?.[0] ? `<i class="dot ${n[0]}" title="${esc(n[1] || '')}"></i>` : ''}</a>`;
  }).join('');
  $('#set-nav').innerHTML = html(false); $('#set-chips').innerHTML = html(true);
  document.querySelectorAll('#set-nav a, #set-chips a').forEach(a => a.onclick = e => { e.preventDefault(); showSection(a.dataset.sec); });
}
/** The band at the top: what needs a look, each point opening its section. */
function setHealth() {
  const s = state.status || {}, i = state.setInfo, pts = [];
  if (s.model_problem) pts.push(['model', t('The AI model is not set up')]);
  if (s.budget?.state === 'blocked') pts.push(['model', t('The budget is reached')]);
  if (state.status && !s.worker_online) pts.push(['system', t('The background worker is not running')]);
  if (i.update) pts.push(['system', t('An update is available')]);
  if (i.totp === false) pts.push(['security', t('Two-step sign-in is off')]);
  const b = $('#set-health');
  b.classList.toggle('warn', pts.length > 0); b.classList.toggle('ok', !pts.length && !!state.status);
  $('#sh-title').textContent = !state.status ? t('Checking…') : pts.length ? (pts.length === 1 ? t('1 point to review') : t('{n} points to review', { n: pts.length })) : t('Everything looks fine');
  $('#sh-list').innerHTML = pts.map(([sec, txt]) => `<a href="#settings/${sec}" data-sec="${sec}">${esc(txt)}</a>`).join(' · ');
  $('#sh-list').querySelectorAll('a').forEach(a => a.onclick = e => { e.preventDefault(); showSection(a.dataset.sec); });
  renderSetNav();
}
/** Connected accounts: Google, Spotify and the connectors that answer (the mailbox counts once set). */
function setAccounts() {
  const a = state.setAcc || {}, mb = setFields().some(f => f.key === 'TALIXIO_MAILBOX_USER' && (f.value || f.set));
  setInfo('accounts', (a.google ? 1 : 0) + (a.outlook ? 1 : 0) + (a.spotify ? 1 : 0) + (a.mcp || 0) + (mb ? 1 : 0));
}
function setInfo(k, v) { if (state.setInfo[k] === v) return; state.setInfo[k] = v; if (!$('#view-settings').hidden) setHealth(); }
function applyShowIf() {
  for (const [key, [dep, vals]] of Object.entries(SHOW_IF)) {
    const fe = document.querySelector(`#view-settings .field[data-key="${key}"]`), d = setFields().find(f => f.key === dep);
    if (!fe || !d || !document.getElementById('set-' + dep)) continue;
    const f = setFields().find(x => x.key === key);
    fe.classList.toggle('cond-off', !vals.includes(fieldValue(d)) && !f.set && !f.value && !fe.classList.contains('changed'));
  }
  euFields();
}
/** With a European provider, keys for providers outside Europe are kept but out of sight (they are not used). */
const FOREIGN = ['ANTHROPIC_API_KEY', 'CLAUDE_CODE_OAUTH_TOKEN', 'OPENAI_API_KEY', 'OPENAI_BASE_URL', 'ANTHROPIC_ADMIN_KEY', 'ANTHROPIC_WORKSPACE_ID', 'OPENAI_ADMIN_KEY', 'OPENAI_PROJECT_ID'];
function euFields() {
  const prov = setFields().find(f => f.key === 'TALIXIO_PROVIDER'), pe = document.querySelector('#view-settings .field[data-key="TALIXIO_PROVIDER"]');
  if (!prov || !pe || !document.getElementById('set-TALIXIO_PROVIDER')) return;
  const eu = fieldValue(prov) === 'sovereign', show = !eu || state.showForeign;
  // Gemini also serves transcription and search: hidden only when nothing can use it
  const gemini = eu && (state.eu?.only || !(state.eu?.outside || []).length);
  let hidden = 0;
  for (const key of [...FOREIGN, 'GEMINI_API_KEY', 'TALIXIO_GEMINI_PAID']) {
    const fe = document.querySelector(`#view-settings .field[data-key="${key}"]`); if (!fe) continue;
    const off = !show && (FOREIGN.includes(key) || gemini) && !fe.classList.contains('changed');
    fe.classList.toggle('eu-off', off); if (off) hidden++;
  }
  const cl = $('#cl-row'); if (cl) { cl.hidden = eu && !state.showForeign; if (cl.hidden) hidden++; }
  const help = pe.querySelector('.help');
  if (help) { help.dataset.orig ??= help.textContent; help.textContent = eu ? t('Set from the "Europe only" card above: everything goes to a European provider.') : help.dataset.orig; }
  let note = $('#eu-foreign');
  if (!note) { note = document.createElement('p'); note.id = 'eu-foreign'; note.className = 'small muted eu-foreign'; pe.after(note); }
  note.hidden = !eu || (!hidden && !state.showForeign);
  note.innerHTML = state.showForeign
    ? `${esc(t('Keys for providers outside Europe, not used while the European provider is on.'))} <button type="button" class="link">${esc(t('Hide them'))}</button>`
    : `${esc(t('Keys for providers outside Europe are kept but not used.'))} <button type="button" class="link">${esc(t('Show them'))}</button>`;
  note.querySelector('button').onclick = () => { state.showForeign = !state.showForeign; euFields(); };
}
/** Counts what differs from what is saved and shows the Save bar. */
function setDirty() {
  let n = 0;
  for (const f of setFields()) {
    const fe = document.querySelector(`#view-settings .field[data-key="${f.key}"]`); if (!fe) continue;
    const ch = fieldValue(f) !== initialValue(f); fe.classList.toggle('changed', ch); if (ch) n++;
  }
  applyShowIf();
  $('#set-save').hidden = !n;
  $('#set-save-n').textContent = n === 1 ? t('1 unsaved change') : t('{n} unsaved changes', { n });
  return n;
}
function fieldHtml(f) {
  return `<div class="field ${f.type === 'bool' ? 'bool' : ''}" data-key="${f.key}">
    ${f.type === 'bool' ? fieldInput(f) : ''}
    <div class="fbody">
      <div class="top"><label for="set-${f.key}">${esc(f.label)}</label>
        ${f.source === 'app' ? `<span class="src">set in the app · <button type="button" data-reset="${f.key}">reset</button></span>` : f.source === 'file' ? '<span class="src">from the config file</span>' : ''}</div>
      ${f.type !== 'bool' ? fieldInput(f) : ''}
      ${f.help ? `<p class="help">${esc(f.help)}</p>` : ''}<p class="err" hidden></p>
    </div></div>`;
}
function renderGroups(groups) {
  state.setGroups = groups;
  const pool = $('#set-pool');
  for (const id of ['google-card', 'microsoft-card', 'spotify-card']) pool.append($('#' + id));
  const openAdv = new Set([...document.querySelectorAll('#view-settings .set-groups details.adv[open]')].map(d => d.dataset.id));
  document.querySelectorAll('#view-settings .set-groups').forEach(b => { b.innerHTML = ''; });
  for (const g of groups) {
    const box = document.querySelector(`#view-settings .set-groups[data-groups="${g.section}"]`); if (!box) continue;
    const main = g.fields.filter(f => !f.advanced), adv = g.fields.filter(f => f.advanced);
    const el = document.createElement('div'); el.className = 'card set-card group-card'; el.dataset.id = g.id;
    el.innerHTML = `<h4>${esc(g.title)}</h4>${g.intro ? `<p class="muted small">${esc(g.intro)}</p>` : ''}
      <div class="fields">${main.map(fieldHtml).join('')}</div>
      ${adv.length ? `<details class="adv" data-id="${g.id}" ${openAdv.has(g.id) ? 'open' : ''}><summary>${esc(t('Advanced settings ({n})', { n: adv.length }))}</summary><div class="fields">${adv.map(fieldHtml).join('')}</div></details>` : ''}`;
    el.querySelectorAll('[data-reset]').forEach(b => b.onclick = () => saveSettings([b.dataset.reset]));
    if (g.id === 'brain' && state.status?.claude_code) { // the simplest way: the Claude plan, signed in from here
      const r = document.createElement('div'); r.className = 'row wrap'; r.id = 'cl-row';
      r.innerHTML = `<button class="btn" type="button">${esc(t('Sign in with Claude'))}</button><span class="muted small">${esc(t('Uses your Claude Pro or Max plan; no API key needed.'))}</span>`;
      r.querySelector('button').onclick = claudeSignIn;
      el.querySelector('.fields').prepend(r);
    }
    if (g.id === 'search') { const m = document.createElement('div'); m.className = 'meaning'; m.id = 'meaning'; el.querySelector('.fields').prepend(m); loadMeaning(m).catch(e => { m.textContent = e.message; }); }
    if (g.id === 'calendar') el.querySelector('.fields').before($('#google-card'), $('#microsoft-card'));
    if (g.id === 'spotify') el.querySelector('.fields').before($('#spotify-card'));
    for (const [gid, key] of [['email', 'TALIXIO_SMTP_AUTH'], ['mailbox', 'TALIXIO_MAILBOX_AUTH']]) {
      const f = g.id === gid && el.querySelector(`.field[data-key="${key}"]`);
      if (f) { f.after(msMailRow(gid)); f.querySelector('select')?.addEventListener('change', e => { if (e.target.value === 'microsoft') msServers(gid); showMsMail(); }); }
    }
    box.append(el);
  }
  const page = $('#view-settings');
  page.oninput = page.onchange = e => { if (e.target.closest('.set-groups')) setDirty(); };
  setDirty(); setAccounts(); showMsMail(); sectionTabs(state.setSection);
  if ($('#set-search').value) setSearch();
}
/** Each settings section: one tab per card, one card at a time (all of them while searching). */
function sectionTabs(id, pick) {
  const sec = $(`#view-settings .set-sec[data-sec="${id}"]`); if (!sec) return;
  const cards = [...sec.querySelectorAll(':scope > .set-card, .set-groups > .set-card')];
  const key = (c, i) => c.dataset.id || c.id || 'card' + i;
  let bar = sec.querySelector('.set-tabs');
  if (!bar) { bar = document.createElement('div'); bar.className = 'tabs set-tabs'; bar.setAttribute('role', 'tablist'); sec.querySelector('header').after(bar); }
  const shown = cards.filter(c => !c.hidden);
  bar.hidden = shown.length < 2;
  if (pick) try { localStorage.setItem('setTab:' + id, pick); } catch {}
  let cur = pick; if (!cur) try { cur = localStorage.getItem('setTab:' + id); } catch {}
  if (!shown.some(c => key(c, cards.indexOf(c)) === cur)) cur = shown[0] && key(shown[0], cards.indexOf(shown[0]));
  bar.innerHTML = '';
  cards.forEach((c, i) => {
    c.classList.toggle('tab-off', !bar.hidden && key(c, i) !== cur);
    if (c.hidden) return;
    const h = c.querySelector('h4')?.cloneNode(true); h?.querySelectorAll('span, button').forEach(x => x.remove());
    const b = document.createElement('button'); b.type = 'button'; b.className = 'tab'; b.setAttribute('role', 'tab');
    b.setAttribute('aria-selected', String(key(c, i) === cur)); b.textContent = (h?.textContent || '').trim() || t('More');
    b.onclick = () => sectionTabs(id, key(c, i)); bar.append(b);
  });
}
/** "Connect with Microsoft" under a mail group's Sign-in choice: Outlook / Microsoft 365 mail needs OAuth (XOAUTH2), not a password. */
function msMailRow(purpose) {
  const r = document.createElement('div'); r.className = 'row wrap ms-mail'; r.dataset.purpose = purpose; r.hidden = true;
  r.innerHTML = `<button class="btn primary" type="button" data-act="connect">Connect with Microsoft</button>
    <button class="btn ghost danger" type="button" data-act="disconnect" hidden>Disconnect</button><span class="muted small"></span><div class="ms-error small"></div>`;
  r.querySelector('[data-act=connect]').onclick = async () => { try { location.href = (await post('api/microsoft/connect', { purpose })).url; } catch (e) { toast(e.message); } };
  r.querySelector('[data-act=disconnect]').onclick = async () => { try { showMicrosoft(await post('api/microsoft/disconnect', { purpose })); toast('Microsoft account disconnected.'); } catch (e) { toast(e.message); } };
  return r;
}
// A Microsoft token only works with (and is only sent to) Microsoft's servers: choosing Microsoft fills them in.
function msServers(purpose) {
  const set = (k, v, ifSet) => { const el = document.getElementById('set-' + k); if (el && (!ifSet || el.value.trim())) el.value = v; };
  if (purpose === 'email') {
    set('TALIXIO_SMTP_HOST', 'smtp.office365.com'); set('TALIXIO_SMTP_PORT', '587'); set('TALIXIO_SMTP_SECURITY', 'starttls');
    set('TALIXIO_IMAP_HOST', 'outlook.office365.com', true); set('TALIXIO_IMAP_PORT', '993');
  } else {
    set('TALIXIO_MAILBOX_IMAP_HOST', 'outlook.office365.com'); set('TALIXIO_MAILBOX_IMAP_PORT', '993');
    set('TALIXIO_MAILBOX_SMTP_HOST', 'smtp.office365.com'); set('TALIXIO_MAILBOX_SMTP_PORT', '587');
  }
  setDirty(); toast('Servers set to Microsoft 365\'s. Save to apply.');
}
function showMsMail() {
  const g = state.microsoft;
  document.querySelectorAll('.ms-mail').forEach(r => {
    const sel = r.previousElementSibling?.querySelector('select'), m = g?.mail?.[r.dataset.purpose] || {};
    r.hidden = sel?.value !== 'microsoft';
    const c = r.querySelector('[data-act=connect]'), note = r.querySelector('span');
    c.hidden = !!m.connected; r.querySelector('[data-act=disconnect]').hidden = !m.connected;
    c.disabled = g ? !g.configured : false;
    note.innerHTML = m.connected ? `Connected as <span class="no-i18n">${esc(m.email || '?')}</span>. Leave the password empty.`
      : g && !g.configured ? 'First set up the Microsoft app under Calendar → Outlook / Microsoft 365 (steps 1 to 5).' : 'Save, then connect the mailbox\'s Microsoft account.';
    r.querySelector('.ms-error').innerHTML = !m.connected && m.error ? lastError(m.error, 'Last sign-in failed') : '';
  });
}
async function saveSettings(reset = []) {
  const changes = {};
  if (!reset.length) for (const f of setFields()) { if (!document.getElementById('set-' + f.key)) continue; const v = fieldValue(f); if (v !== initialValue(f)) changes[f.key] = v; }
  if (!reset.length && !Object.keys(changes).length) return;
  document.querySelectorAll('#view-settings .set-groups .err').forEach(e => { e.hidden = true; });
  const btn = $('#set-save-btn'); btn.disabled = true;
  try {
    const res = await fetch('api/settings', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Talixio': '1' }, body: JSON.stringify({ changes, reset }) });
    const d = await res.json();
    if (!res.ok) {
      let first = null;
      for (const [k, msg] of Object.entries(d.errors || {})) {
        const fe = document.querySelector(`#view-settings .field[data-key="${k}"]`); if (!fe) continue;
        const e = fe.querySelector('.err'); e.textContent = msg; e.hidden = false; first ||= fe;
      }
      if (first) { showSection(first.closest('.set-sec').dataset.sec, false); const a = first.closest('details.adv'); if (a) a.open = true; first.scrollIntoView({ block: 'center' }); }
      toast(d.error || t('Not saved.')); return;
    }
    renderGroups(d.groups); toast(reset.length ? 'Back to the config file value' : 'Saved. The background worker restarts to apply it.');
    refreshStatus().then(setHealth);
  } catch (e) { toast(e.message); }
  finally { btn.disabled = false; }
}
$('#set-save-btn').onclick = () => saveSettings();
$('#set-cancel').onclick = () => renderGroups(state.setGroups);
/** Finds a setting anywhere on the page: every section shows, only what matches stays. */
function setSearch() {
  const q = fold($('#set-search').value.trim()), page = $('#view-settings');
  page.classList.toggle('searching', !!q);
  if (!q) {
    page.querySelectorAll('.set-sec').forEach(s => { s.hidden = s.dataset.sec !== state.setSection; });
    page.querySelectorAll('.set-sec .set-card, .set-sec .field').forEach(e => e.classList.remove('nomatch'));
    page.querySelectorAll('details.adv[data-found]').forEach(d => { d.open = false; delete d.dataset.found; });
    $('#set-empty').hidden = true; return;
  }
  let any = false;
  page.querySelectorAll('.set-sec').forEach(sec => {
    let secHit = fold(sec.querySelector('header').textContent).includes(q);
    sec.querySelectorAll(':scope > .set-card, :scope > form.set-card, .set-groups > .set-card').forEach(card => {
      const fields = [...card.querySelectorAll('.field[data-key]')];
      const head = fold((card.querySelector('h4')?.textContent || '') + ' ' + (card.querySelector(':scope > p')?.textContent || ''));
      let hit = head.includes(q), fHit = false;
      if (fields.length) for (const fe of fields) {
        const m = fold(fe.textContent + ' ' + fe.dataset.key).includes(q);
        fe.classList.toggle('nomatch', !m && !hit); fHit ||= m;
        const a = fe.closest('details.adv'); if (m && a && !a.open) { a.open = true; a.dataset.found = '1'; }
      } else hit ||= fold(card.textContent).includes(q);
      card.classList.toggle('nomatch', !(hit || fHit)); secHit ||= hit || fHit;
    });
    sec.hidden = !secHit; any ||= secHit;
  });
  $('#set-empty').hidden = any;
}
$('#set-search').oninput = setSearch;
$('#set-search').onkeydown = e => { if (e.key === 'Escape') { e.target.value = ''; setSearch(); } };
async function loadSettings() {
  showSection(setSection(), false);
  setHealth();
  loadChannels().catch(e => toast(e.message));
  loadUpdate().catch(() => {});
  loadSecurity();
  loadDav();
  loadEu(); loadAccCard();
  renderAppearance();
  loadConnectors();
  loadGoogle(); loadMicrosoft();
  loadSpotify(); loadLegifrance();
  loadTransfer().catch(() => {});
  refreshStatus().then(setHealth);
  if (!state.setGroups || $('#set-save').hidden) renderGroups((await api('api/settings')).groups);
}
$('#doctor-run').onclick = async () => {
  const b = $('#doctor-run'); b.disabled = true; b.textContent = 'Checking…';
  const ul = $('#doctor'); ul.hidden = false; ul.innerHTML = '<li class="info">Running checks (this can take a few seconds)…</li>';
  try { const { results } = await post('api/doctor'); ul.innerHTML = results.map(r => r.status === 'eu'
    ? `<li class="eu"><span class="eu-seal">★ ${esc(t('EU · GDPR'))}</span><span>${esc(t('Everything stays in Europe: {provider}, under the GDPR.', { provider: r.provider }))}${r.locked ? ' ' + esc(t('Locked: nothing can be sent outside Europe.')) : ''}</span></li>`
    : `<li class="${r.status}">${esc(r.text)}</li>`).join(''); }
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
  state.setAcc = { ...state.setAcc, spotify: !!g.connected }; setAccounts();
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
async function loadLegifrance() {
  try { const g = await api('api/legifrance'); state.setAcc = { ...state.setAcc, legifrance: !!g.configured }; setAccounts();
    $('#legifrance-badge').textContent = g.configured ? t('· keys set') : t('· EU law ready, French law needs PISTE keys'); } catch {}
}
$('#legifrance-test').onclick = async () => {
  const b = $('#legifrance-test'), out = $('#legifrance-status'); b.disabled = true; out.textContent = t('Testing…'); out.className = 'small muted';
  try { const r = await post('api/legifrance/test'); out.textContent = '✓ ' + r.message; out.className = 'small'; }
  catch (e) { out.textContent = e.message; out.className = 'small error'; }
  b.disabled = false; loadLegifrance();
};
$('#spotify-copy').onclick = () => copyText($('#spotify-redirect').textContent);
$('#spotify-connect').onclick = async () => { try { location.href = (await post('api/spotify/connect')).url; } catch (e) { toast(e.message); } };
$('#spotify-disconnect').onclick = async () => { try { showSpotify(await post('api/spotify/disconnect')); toast('Spotify disconnected.'); } catch (e) { toast(e.message); } };

// ---- Google Calendar ----
function showGoogle(g) {
  state.setAcc = { ...state.setAcc, google: !!g.connected }; setAccounts();
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

// ---- Outlook (Microsoft Graph) ----
function showMicrosoft(g) {
  state.setAcc = { ...state.setAcc, outlook: !!g.connected }; setAccounts();
  state.microsoft = g; showMsMail();
  $('#microsoft-badge').textContent = g.connected ? `· connected${g.email ? ' as ' + g.email : ''}` : g.configured ? '· not connected' : '· not set up';
  $('#microsoft-redirect').textContent = g.redirect;
  $('#microsoft-status').innerHTML = g.connected ? '<p class="small">Reads your Outlook calendar; adds events to it after your approval.</p>' : g.error ? lastError(g.error, 'Last sign-in failed') : '';
  $('#microsoft-steps').hidden = g.connected;
  $('#microsoft-connect').hidden = g.connected; $('#microsoft-disconnect').hidden = !g.connected;
  $('#microsoft-connect').disabled = !g.configured; $('#microsoft-connect').title = g.configured ? '' : 'Fill in the application ID and secret first (step 5)';
}
async function loadMicrosoft() { try { showMicrosoft(await api('api/microsoft')); } catch {} }
$('#microsoft-copy').onclick = () => copyText($('#microsoft-redirect').textContent);
$('#microsoft-connect').onclick = async () => { try { location.href = (await post('api/microsoft/connect')).url; } catch (e) { toast(e.message); } };
$('#microsoft-disconnect').onclick = async () => { try { showMicrosoft(await post('api/microsoft/disconnect')); toast('Outlook disconnected.'); } catch (e) { toast(e.message); } };

// ---- connectors (MCP) and the sandbox ----
function showConnectors(d) {
  if (document.activeElement !== $('#mcp-raw')) $('#mcp-raw').value = d.raw;
  const ok = d.servers.filter(x => !x.error).length;
  state.setAcc = { ...state.setAcc, mcp: ok }; setAccounts();
  $('#mcp-badge').textContent = d.servers.length ? `· ${ok}/${d.servers.length} connected` : '· none';
  state.mcpData = d;
  storeConnectors();
  $('#mcp-servers').innerHTML = d.servers.map(mcpCard).join('');
  $('#mcp-servers').querySelectorAll('details.mcp-server').forEach(el => {
    el.ontoggle = () => { const n = el.dataset.srv; el.open ? state.mcpOpen.add(n) : state.mcpOpen.delete(n); if (el.open && !el.querySelector('.mcp-body')) { el.insertAdjacentHTML('beforeend', mcpBody(d.servers.find(x => x.name === n))); mcpWire(el); } };
    if (el.open) mcpWire(el);
  });
  $('#mcp-servers').querySelectorAll('[data-signin]').forEach(b => b.onclick = async ev => {
    ev.preventDefault(); b.disabled = true; $('#mcp-state').textContent = 'Opening the sign-in page…';
    try { location.href = (await post('api/mcp/oauth/start', { server: b.dataset.signin })).url; } catch (e) { $('#mcp-state').textContent = e.message; b.disabled = false; }
  });
  $('#mcp-servers').querySelectorAll('[data-signout]').forEach(b => b.onclick = ev => { ev.preventDefault(); mcpAction('api/mcp/oauth/forget', { server: b.dataset.signout }); });
  $('#shell-state').textContent = !d.shell.installed ? 'not installed (install.sh --with-shell).' : d.shell.mode === 'off' ? 'installed, off (Settings → Security → Sandboxed shell).' : d.shell.mode === 'ask' ? 'on, asks before each command.' : 'on, runs without asking.';
}
// A connector: folded to one line (what it is, how many tools, how many run without asking); open, its tools in a table by what they seem to do.
state.mcpOpen = new Set(); state.mcpQ = {};
const MCP_KINDS = [['reads', 'Seem to only read'], ['unknown', 'Not sure what they do'], ['changes', 'Can change or send things']];
function mcpStat(x) {
  const free = x.trust === 'all' ? x.tools.length : x.tools.filter(tl => tl.read_only).length;
  return `<span class="muted small" data-stat>${x.error ? '' : esc(t('{n} tool(s)', { n: x.tools.length })) + (x.tools.length ? ' · ' + esc(t('{n} run without asking', { n: free })) : '')}${x.at ? ' · ' + esc(t('checked')) + ' ' + fmtTime(x.at) : ''}</span>`;
}
function mcpCard(x) {
  const head = `<div class="row"><b>${esc(x.name)}</b><span class="chip">${esc(t(x.kind))}</span>${x.sandbox ? `<span class="chip">${esc(t('sandboxed'))}</span>` : ''}${x.trust === 'all' ? `<span class="chip">${esc(t('trusted: no approval'))}</span>` : ''}
      ${mcpStat(x)}
      ${x.auth === 'signed_in' ? `<span class="spacer"></span><span class="muted small">${esc(t('signed in'))} ${esc(x.signed_in_at || '')}</span><button type="button" class="btn small ghost" data-signout="${esc(x.name)}">${esc(t('Sign out'))}</button>`
        : x.auth === 'needed' ? `<span class="spacer"></span><button type="button" class="btn small primary" data-signin="${esc(x.name)}">${esc(t('Sign in'))}</button>` : ''}</div>
    ${x.about ? `<p class="small mcp-about">${esc(x.about)}</p>` : ''}`;
  const foot = x.sandbox === false && x.kind === 'local' ? `<p class="muted small">${esc(t('Runs without the sandbox: it can read everything this server can.'))}</p>` : '';
  if (!x.tools.length) return `<div class="mcp-server ${x.error && x.auth !== 'needed' ? 'bad' : ''}">${head}
    ${x.auth === 'needed' ? `<p class="small">${t('This service asks you to sign in with your account: press <b>Sign in</b>, accept, and you come back here.')}</p>` : x.error ? `<p class="error small">${esc(x.error)}</p>` : ''}${foot}</div>`;
  const open = state.mcpOpen.has(x.name);
  return `<details class="mcp-server" data-srv="${esc(x.name)}" ${open ? 'open' : ''}><summary>${head}</summary>${foot}${open ? mcpBody(x) : ''}</details>`;
}
function mcpBody(x) {
  const all = x.trust === 'all', q = state.mcpQ[x.name] || '';
  const groups = MCP_KINDS.map(([k, label]) => {
    const tl = x.tools.filter(tl => tl.kind === k);
    if (!tl.length) return '';
    return `<tbody data-kind="${k}"><tr class="mcp-group"><th colspan="3"><label><input type="checkbox" data-group="${k}" ${all ? 'checked disabled' : ''}> ${esc(t(label))} <span class="muted" data-count></span></label></th></tr>
      ${tl.map(tl => `<tr data-q="${esc((tl.tool + ' ' + tl.title + ' ' + tl.description).toLowerCase())}">
        <td><input type="checkbox" data-tool="${esc(tl.tool)}" ${all || tl.read_only ? 'checked' : ''} ${all ? 'disabled' : ''} aria-label="${esc(t('Runs without asking'))}"></td>
        <td><b>${esc(tl.title)}</b>${tl.title !== tl.tool ? `<div class="muted small mono">${esc(tl.tool)}</div>` : ''}${tl.says_read_only ? `<div class="muted small">${esc(t('the connector says it only reads'))}</div>` : ''}</td>
        <td class="small">${tl.description ? esc(tl.description) : `<span class="muted">${esc(t('No description from the connector.'))}</span>`}</td></tr>`).join('')}</tbody>`;
  }).join('');
  return `<div class="mcp-body">
    <p class="muted small">${esc(all ? t('This connector is trusted: all its tools run without asking. Remove "trust": "all" in the configuration below to choose tool by tool.')
      : t('Ticked: runs without asking you, also in background tasks. Unticked: asks for your approval each time. Tick only tools that read; the groups are a guess from the connector, check the description.'))}</p>
    ${x.tools.length > 8 ? `<input type="search" class="mcp-q" placeholder="${esc(t('Filter the tools'))}" value="${esc(q)}">` : ''}
    <div class="mcp-table"><table><colgroup><col class="c1"><col class="c2"><col></colgroup><thead><tr><th>${esc(t('No approval'))}</th><th>${esc(t('Tool'))}</th><th>${esc(t('What it does'))}</th></tr></thead>${groups}</table></div></div>`;
}
function mcpWire(el) {
  const name = el.dataset.srv, x = state.mcpData.servers.find(s => s.name === name);
  const shown = tr => !tr.hidden;
  const counts = () => el.querySelectorAll('tbody[data-kind]').forEach(tb => {
    const boxes = [...tb.querySelectorAll('[data-tool]')].filter(b => shown(b.closest('tr'))), on = boxes.filter(b => b.checked).length, g = tb.querySelector('[data-group]');
    tb.querySelector('[data-count]').textContent = `(${t('{n} of {m} without asking', { n: on, m: boxes.length })})`;
    tb.querySelector('.mcp-group').hidden = !boxes.length;
    if (!g.disabled) { g.checked = boxes.length > 0 && on === boxes.length; g.indeterminate = on > 0 && on < boxes.length; }
  });
  const filter = () => { const q = (state.mcpQ[name] || '').toLowerCase().trim(); el.querySelectorAll('tr[data-q]').forEach(tr => tr.hidden = !!q && !tr.dataset.q.includes(q)); counts(); };
  const save = async (tools, on) => {
    try { const d = await post('api/mcp/read-only', { server: name, tools, on }); state.mcpData = d; Object.assign(x, d.servers.find(s => s.name === name) || {}); el.querySelector('[data-stat]').outerHTML = mcpStat(x); }
    catch (e) { toast(e.message); loadConnectors(); }
  };
  el.oninput = el.onchange = e => e.stopPropagation(); // these switches save at once: not part of the Settings form
  const q = el.querySelector('.mcp-q');
  if (q) q.oninput = () => { state.mcpQ[name] = q.value; filter(); };
  el.querySelectorAll('[data-tool]').forEach(b => b.onchange = () => { counts(); save([b.dataset.tool], b.checked); });
  el.querySelectorAll('[data-group]').forEach(g => g.onchange = () => {
    const boxes = [...g.closest('tbody').querySelectorAll('[data-tool]')].filter(b => shown(b.closest('tr')));
    boxes.forEach(b => b.checked = g.checked); counts(); save(boxes.map(b => b.dataset.tool), g.checked);
  });
  filter();
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
  const preset = window.connName || ''; window.connName = '';
  const d = $('#conn-dialog'), state = $('#conn-state');
  state.textContent = ''; $('#conn-go').disabled = false;
  const fill = async () => {
    const { packages } = await api('api/connectors/packages');
    $('#conn-file').innerHTML = packages.length ? packages.map(p => `<option value="${p.id}">${esc(joinPath(p.folder, p.name))}</option>`).join('')
      : `<option value="">${esc(t('None yet: give a GitHub address above, or put a .zip in /Connecteurs.'))}</option>`;
    return packages.length;
  };
  const any = await fill();
  if (fileId) $('#conn-file').value = String(fileId);
  let info = null;
  const inspect = async () => {
    if (!$('#conn-file').value) { $('#conn-info').textContent = ''; $('#conn-env').innerHTML = ''; $('#conn-go').disabled = true; return; }
    $('#conn-info').textContent = t('Reading the package…'); $('#conn-env').innerHTML = '';
    try {
      info = await api('api/connectors/inspect?file=' + $('#conn-file').value);
      $('#conn-name').value = preset || info.name;
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
  $('#conn-gh').value = '';
  $('#conn-gh').onkeydown = e => { if (e.key === 'Enter') { e.preventDefault(); $('#conn-gh-go').click(); } }; // not the Install button
  $('#conn-gh-go').onclick = async () => {
    const url = $('#conn-gh').value.trim();
    if (!url) { $('#conn-gh').focus(); return; }
    $('#conn-gh-go').disabled = true; state.textContent = t('Fetching it from GitHub…');
    try {
      const r = await post('api/connectors/github', { url });
      await fill(); $('#conn-file').value = String(r.file);
      state.textContent = t('Saved in the library as {path}.', { path: joinPath('/Connecteurs', r.file_name) });
      await inspect();
    } catch (e) { state.textContent = e.message; }
    $('#conn-gh-go').disabled = false;
  };
  $('#conn-close').onclick = () => d.close();
  $('#conn-form').onsubmit = async e => {
    e.preventDefault();
    const env = {}; $('#conn-env').querySelectorAll('[data-k]').forEach(i => { env[i.dataset.k] = i.value; });
    $('#conn-go').disabled = true; state.textContent = t('Unpacking…');
    try {
      const r = await post('api/connectors/install', { file: +$('#conn-file').value, name: $('#conn-name').value.trim(), env });
      const poll = async () => {
        const s = await api('api/connectors/install');
        if (s.state === 'running') { state.textContent = t({ unpacked: 'Unpacked…', 'installing dependencies': 'Installing its dependencies (npm)…', 'installing the browser': 'Downloading the web browser it drives (about 100 MB, once)…', building: 'Building…', connecting: 'Starting it and listing its tools…' }[s.step] || 'Installing…'); setTimeout(poll, 2000); return; }
        if (s.state === 'done') { state.textContent = t('Ready: {n} tool(s) from “{name}”. The assistant can use them from the next message.', { n: s.tools, name: r.name }); loadConnectors(); }
        else { state.textContent = s.error || t('It did not work.'); $('#conn-go').disabled = false; loadConnectors(); }
      };
      setTimeout(poll, 1500);
    } catch (err) { state.textContent = err.message; $('#conn-go').disabled = false; }
  };
  d.showModal();
  if (any) await inspect(); else { $('#conn-info').textContent = ''; $('#conn-env').innerHTML = ''; $('#conn-go').disabled = true; $('#conn-gh').focus(); }
}
$('#conn-install').onclick = () => connectorDialog().catch(e => toast(e.message));
/**
 * "▶ Install" in the connector list (FAQ, or an answer): ?connector=<address>&name=<name>. A GitHub address
 * opens the install dialog and fetches it (the user then clicks Install); an online server is added after a confirmation.
 */
async function installFromLink(src, name, headers) {
  if (!/^https:\/\/[^\s/]+\.[^\s/]+/i.test(src || '')) { toast(t('Not a connector address: {src}', { src: src || '' })); return; }
  if (/^https:\/\/(www\.)?github\.com\//i.test(src)) {
    await connectorDialog();
    $('#conn-gh').value = src; $('#conn-gh-go').click();
    return;
  }
  name = (name || new URL(src).hostname.replace(/^(www|mcp|api)\./, '').split('.')[0]).toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '') || 'connector';
  const d = JSON.parse((await api('api/mcp')).raw); d.mcpServers ||= {};
  if (Object.values(d.mcpServers).some(x => x && x.url === src)) { toast(t('This connector is already there.')); showMcpCard(); return; }
  if (!confirm(t('Add the online connector “{name}” ({url})? Talixio will connect to it and list its tools.', { name, url: src }))) return;
  for (let i = 2, base = name; d.mcpServers[name]; i++) name = base + '-' + i;
  d.mcpServers[name] = { url: src, ...(headers?.length ? { headers: Object.fromEntries(headers.map(h => [h, ''])) } : {}) };
  toast(t('Connecting to {name}…', { name }));
  const r = await post('api/mcp', { raw: JSON.stringify(d, null, 2) });
  showConnectors(r);
  const srv = r.servers.find(x => x.name === name);
  toast(srv?.auth === 'needed' ? t('“{name}” is added: sign in to it in Settings → Connectors.', { name })
    : srv?.error ? srv.error : t('Ready: {n} tool(s) from “{name}”. The assistant can use them from the next message.', { n: srv?.tools.length || 0, name }));
  showMcpCard();
}
const STORE_RANGES = [['a', 'c'], ['d', 'f'], ['g', 'i'], ['j', 'l'], ['m', 'o'], ['p', 'r'], ['s', 'u'], ['v', 'z']];
const storeLetter = n => (fold(n).replace(/[^a-z0-9]/g, '')[0] || '#');
/** Settings → Connectors: the store catalog — connectors (MCP) and apps, with tabs, a category and an A–Z filter. */
async function storeConnectors() {
  const box = $('#store-conn');
  if (!box) return;
  const head = `<h4>${esc(t('From the store'))}</h4>`;
  let st;
  try { st = await api('api/store'); } catch (e) { box.innerHTML = head + `<p class="muted small error">${esc(t('The store could not be loaded: {err}', { err: e.message || 'error' }))}</p>`; box.hidden = false; return; }
  if (!st?.available) { box.innerHTML = head + storeOff(st); box.hidden = false; return; }
  try { renderStore(box, st); }
  catch (e) { box.innerHTML = head + `<p class="muted small error">${esc(t('The catalog could not be shown: {err}', { err: e.message || 'error' }))}</p>`; box.hidden = false; }
}
function renderStore(box, st) {
  const mine = (state.mcpData?.servers || []).map(x => x.name);
  const sent = (st.outbox || []).filter(x => x.kind === 'connector');
  st.connectors = Array.isArray(st.connectors) ? st.connectors : [];
  st.apps = Array.isArray(st.apps) ? st.apps : [];
  const cats = [...new Set(st.connectors.map(c => c.category || 'Other'))]; // themes, in the FAQ's order
  const hasApps = st.apps.length > 0;
  const long = st.connectors.length > 8 || hasApps;
  const connItem = c => `<div class="row wrap store-item" data-id="${esc(c.id)}" data-cat="${esc(c.category || 'Other')}" data-name="${esc(c.name)}"><b class="no-i18n">${esc(c.name)}</b>
      <span class="muted small no-i18n">${esc(c.description || c.github || c.url)}</span><span class="spacer"></span>
      ${c.installed ? `<span class="pill on">${esc(t('Installed'))}</span><button type="button" class="btn small ghost" data-conn-remove="${esc(c.installed)}">${esc(t('Uninstall'))}</button>`
        : `<button type="button" class="btn small" data-conn-install>${esc(t('Install'))}</button>`}</div>`;
  const appItem = a => `<div class="row wrap store-item" data-aid="${esc(a.id)}" data-name="${esc(a.name)}"><b class="no-i18n">${esc(a.icon || '🧩')} ${esc(a.name)}</b>
      <span class="muted small no-i18n">${esc(a.description || '')}</span><span class="spacer"></span>
      ${a.installed ? `<a class="btn small ghost" href="#apps/${esc(a.installed)}">${esc(t('Open'))}</a>${a.update ? `<button type="button" class="btn small" data-app-install>${esc(t('Update'))}</button>` : ''}`
        : `<button type="button" class="btn small" data-app-install>${esc(t('Install'))}</button>`}</div>`;
  const rangeRow = id => `<div class="tabs alpha" id="${id}"><button type="button" class="tab" data-range="all">${esc(t('All'))}</button>`
    + STORE_RANGES.map(r => `<button type="button" class="tab" data-range="${r[0]}${r[1]}">${r[0].toUpperCase()}–${r[1].toUpperCase()}</button>`).join('') + '</div>';
  box.innerHTML = `<h4>${esc(t('From the store'))}</h4>`
    + (hasApps ? `<div class="tabs" role="tablist" id="store-kind"><button type="button" class="tab" role="tab" data-kind="connectors">${esc(t('Connectors'))}</button><button type="button" class="tab" role="tab" data-kind="apps">${esc(t('Apps'))}</button></div>` : '')
    + (long ? `<input type="search" id="store-conn-filter" placeholder="${esc(t('Search the store'))}">` : '')
    + (cats.length > 1 ? `<div class="tabs" role="tablist" id="store-conn-tabs">${cats.map(k => `<button type="button" class="tab" role="tab" data-cat="${esc(k)}">${esc(t(k))}</button>`).join('')}</div>` : '')
    + (long ? rangeRow('store-alpha') : '')
    + `<div id="store-conn-list">` + (st.connectors.length ? st.connectors.map(connItem).join('')
      : `<p class="muted small">${esc(t('The store has no connector yet. The connectors listed in the FAQ arrive once the server is updated.'))}</p>`) + '</div>'
    + (hasApps ? `<div id="store-apps-list" hidden>${st.apps.map(appItem).join('')}</div>` : '')
    + (mine.length ? `<p class="muted small">${esc(t('Share one of your connectors with everyone on this server (the admin reviews it first):'))}</p><form class="row wrap" id="store-conn-send"><select id="store-conn-pick">${mine.map(n => `<option class="no-i18n">${esc(n)}</option>`).join('')}</select>
        <input id="store-conn-note" placeholder="${esc(t('A word for the admin (optional)'))}" maxlength="500"><button class="btn small">${esc(t('Send for review'))}</button></form>
        <p class="muted small">${esc(t('Shares how to install it (its GitHub repository or address), never your keys or header values.'))}</p>` : '')
    + sent.map(x => `<p class="small muted"><span class="no-i18n">${esc(x.name)}</span>: ${esc(storeStatus(x))}</p>`).join('');
  box.hidden = false;
  box.querySelectorAll('[data-conn-install]').forEach(b => b.onclick = () => {
    const c = st.connectors.find(x => x.id === b.closest('[data-id]').dataset.id);
    installFromLink(c.github || c.url, c.name, c.headers).catch(e => toast(e.message));
  });
  box.querySelectorAll('[data-conn-remove]').forEach(b => b.onclick = async () => {
    if (!confirm(t('Uninstall the connector “{name}”? Apps and the assistant lose its tools.', { name: b.dataset.connRemove }))) return;
    try { showConnectors(await post('api/mcp/remove', { server: b.dataset.connRemove })); } catch (e) { toast(e.message); }
  });
  box.querySelectorAll('[data-app-install]').forEach(b => b.onclick = async () => {
    try { const { app } = await post('api/store/install?space=' + (state.space || 0), { id: b.closest('[data-aid]').dataset.aid }); location.hash = '#apps/' + app.id; }
    catch (e) { toast(e.message); }
  });
  const q = $('#store-conn-filter'), kindTabs = $('#store-kind'), catTabs = $('#store-conn-tabs'), alpha = $('#store-alpha');
  let kind = 'connectors', cat, range = 'all';
  try { cat = localStorage.getItem('storeTab'); if (hasApps) kind = localStorage.getItem('storeKind') || 'connectors'; } catch {}
  if (!cats.includes(cat)) cat = cats[0];
  const show = () => {
    const v = q ? fold(q.value.trim()) : '';
    const apps = kind === 'apps';
    $('#store-conn-list').hidden = apps;
    const appsList = $('#store-apps-list'); if (appsList) appsList.hidden = !apps;
    if (catTabs) catTabs.hidden = apps || !!v; // categories only apply to connectors, and a search spans all
    kindTabs?.querySelectorAll('.tab').forEach(b => b.setAttribute('aria-selected', String(b.dataset.kind === kind)));
    catTabs?.querySelectorAll('.tab').forEach(b => b.setAttribute('aria-selected', String(b.dataset.cat === cat)));
    alpha?.querySelectorAll('.tab').forEach(b => b.setAttribute('aria-selected', String(b.dataset.range === range)));
    const [lo, hi] = range === 'all' ? ['', ''] : [range[0], range[1]];
    const rangeOk = n => range === 'all' || (storeLetter(n) >= lo && storeLetter(n) <= hi);
    (apps ? (appsList?.querySelectorAll('.store-item') || []) : $('#store-conn-list').querySelectorAll('.store-item')).forEach(r => {
      const name = r.dataset.name || '';
      r.hidden = v ? !fold(r.textContent).includes(v) : (!rangeOk(name) || (!apps && catTabs && r.dataset.cat !== cat));
    });
  };
  kindTabs?.querySelectorAll('.tab').forEach(b => b.onclick = () => { kind = b.dataset.kind; try { localStorage.setItem('storeKind', kind); } catch {} show(); });
  catTabs?.querySelectorAll('.tab').forEach(b => b.onclick = () => { cat = b.dataset.cat; try { localStorage.setItem('storeTab', cat); } catch {} show(); });
  alpha?.querySelectorAll('.tab').forEach(b => b.onclick = () => { range = b.dataset.range; show(); });
  if (q) q.oninput = show;
  show();
  const f = $('#store-conn-send');
  if (f) f.onsubmit = async e => {
    e.preventDefault();
    try { await post('api/store/submit', { connector: $('#store-conn-pick').value, note: $('#store-conn-note').value }); toast(t('Sent: the admin reviews it, then it shows in everyone’s store.')); storeConnectors(); }
    catch (err) { toast(err.message); }
  };
}
function showMcpCard() { location.hash = '#settings'; setTimeout(() => { showSection('accounts'); sectionTabs('accounts', 'mcp-card'); $('#mcp-card').scrollIntoView({ block: 'center' }); }, 600); }
document.addEventListener('click', e => {
  const a = e.target.closest('a.connector');
  if (!a || a.closest('[contenteditable=true]')) return;
  e.preventDefault();
  const q = new URL(a.getAttribute('href'), location.href).searchParams;
  installFromLink(q.get('connector'), q.get('name'), (q.get('headers') || '').split(',').filter(Boolean)).catch(err => toast(err.message));
});
$('#viewer-connector').onclick = () => connectorDialog(lib.file.id).catch(e => toast(e.message));
$('#viewer-contribute').onclick = async () => {
  const b = $('#viewer-contribute'); b.disabled = true;
  try {
    const r = await post(`api/files/${lib.file.id}/contribute`, {});
    moreActions(false);
    toast(t('Sent “{name}” to the company knowledge base for review.', { name: r.name }));
  } catch (e) { toast(e.message); }
  b.disabled = false;
};
$('#viewer-script').onclick = async () => {
  const b = $('#viewer-script'); b.disabled = true;
  try {
    const r = await post(`api/files/${lib.file.id}/as-script`);
    moreActions(false);
    // stays in the viewer (a toast is easy to miss): what happened, where it is, a way there
    $('#viewer-notice')?.remove();
    $('#viewer-body').insertAdjacentHTML('afterbegin', `<div id="viewer-notice" class="viewer-notice" role="status">✓ ${esc(t(r.replaced ? 'Script {name} updated' : 'Script {name} added', { name: r.name }))}
      — ${esc(r.enabled ? t('in Memory › Scripts. The assistant can now run it.') : t('in Memory › Scripts.') + ' ' + t('(it runs once the sandbox is on: install.sh --with-shell)'))}
      <button class="btn small" type="button">${esc(t('Show the script'))}</button></div>`);
    $('#viewer-notice button').onclick = () => showScript(r.name);
  } catch (e) { toast(e.message); }
  b.disabled = false;
};
/** Memory › Scripts, with that script open. */
function showScript(name) {
  closeViewer(); state.memSection = 'scripts';
  if (location.hash.split('/')[0] === '#memory') showMemSection('scripts'); else location.hash = '#memory/scripts';
  let tries = 0;
  const open = () => {
    const h = [...document.querySelectorAll('#scripts .skill h4')].find(x => x.textContent === name);
    if (!h) { if (++tries < 20) setTimeout(open, 150); return; }
    h.closest('.skill').querySelector('[data-a=edit]').click();
    $('#script-edit').scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  };
  open();
}
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
  setInfo('totp', !!on);
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
  try { await post('api/security/totp/enable', { code: $('#totp-code').value, password: $('#totp-pw').value }); $('#totp-code').value = $('#totp-pw').value = ''; showTotp(true); toast('Two-step sign-in is on.'); }
  catch (err) { $('#totp-state').textContent = err.message; }
};
$('#totp-disable').onsubmit = async e => {
  e.preventDefault();
  try { await post('api/security/totp/disable', { password: $('#totp-dis-pw').value, code: $('#totp-dis-code').value }); $('#totp-dis-pw').value = $('#totp-dis-code').value = ''; showTotp(false); toast('Two-step sign-in is off.'); }
  catch (err) { $('#totp-dis-state').textContent = err.message; }
};

// ---- network drive (WebDAV) ----
async function loadDav() {
  try { showDav(await api('api/dav')); } catch { /* shown elsewhere */ }
}
function showDav(d) {
  $('#dav-url').textContent = location.origin + location.pathname.replace(/\/[^/]*$/, '') + '/dav/'; // the address this page is open on always works
  $('#dav-user').textContent = d.user;
  $('#dav-mode').value = d.mode;
  $('#dav-mode').disabled = !d.password;
  $('#dav-blocked').hidden = !d.blocked;
  $('#dav-badge').textContent = '· ' + window.t(d.mode === 'off' ? 'off' : d.mode === 'read' ? 'read only' : 'read and write');
}
$('#dav-unblock').onclick = async () => {
  try { showDav(await post('api/dav/unblock', {})); toast('Devices can sign in to the drive again.'); } catch (e) { toast(e.message); }
};
$('#dav-copy').onclick = () => copyText($('#dav-url').textContent);
$('#dav-secret-copy').onclick = () => copyText($('#dav-secret').textContent);
$('#dav-mode').onchange = async () => {
  try { showDav(await post('api/dav', { mode: $('#dav-mode').value })); } catch (e) { toast(e.message); loadDav(); }
};
$('#dav-pw').onsubmit = async e => {
  e.preventDefault();
  try {
    $('#dav-new').hidden = true; $('#dav-state').textContent = '';
    const d = await post('api/dav/password', { current: $('#dav-current').value });
    $('#dav-current').value = ''; $('#dav-state').textContent = '';
    $('#dav-secret').textContent = d.password; $('#dav-new').hidden = false;
    showDav(d);
  } catch (err) { $('#dav-state').textContent = err.message; }
};

// ---- Europe only (a European AI provider for everything) ----
async function loadEu() {
  try { showEu(await api('api/sovereign')); } catch { /* shown elsewhere */ }
}
function showEu(d) {
  state.eu = d;
  const sel = $('#eu-service');
  if (!sel.options.length) sel.innerHTML = d.services.map(s => `<option value="${esc(s.id)}">${esc(window.t(s.name))} (${esc(window.t(s.country))})</option>`).join('');
  sel.value = d.service; $('#eu-url').value = d.url || '';
  $('#eu-only').checked = d.active ? d.only : true;
  $('#eu-badge').textContent = d.active ? '· ' + window.t(d.only ? 'on, locked' : 'on') : '';
  $('#eu-badge').className = d.active ? 'ok small' : 'muted small';
  const out = $('#eu-outside');
  out.innerHTML = d.outside.length
    ? `<p class="small"><b>${esc(window.t('Still outside Europe'))}</b></p><ul class="small">` + d.outside.map(o => `<li><a href="${esc(o.href)}">${esc(o.name.startsWith('Connector ') ? window.t('Connector') + ' ' + o.name.slice(10) : window.t(o.name))}</a> — <span class="muted">${esc(window.t(o.why))}</span></li>`).join('') + '</ul>'
    : (d.active ? `<p class="small ok">${esc(window.t('Nothing else leaves Europe.'))}</p>` : '');
  const on = d.on_server || [];
  $('#eu-light-row').hidden = !on.length;
  $('#eu-light-text').textContent = on.length === 2 ? t('Also move search by meaning and transcription from this server to the provider (a smaller server is enough)')
    : on[0] === 'embeddings' ? t('Also move search by meaning from this server (Ollama) to the provider (a smaller server is enough)')
    : t('Also move transcription from this server to the provider (a smaller server is enough)');
  const st = $('#eu-stamp'), svc = d.services.find(s => s.id === d.service);
  st.hidden = !d.active;
  st.classList.toggle('partial', d.outside.length > 0);
  st.textContent = d.outside.length ? t('Europe: {n} outside', { n: d.outside.length }) : '★ ' + t('100% Europe · GDPR');
  st.title = d.outside.length ? d.outside.map(o => o.name).join(', ')
    : t('Everything stays in Europe: {provider}, under the GDPR.', { provider: svc ? `${window.t(svc.name)} (${window.t(svc.country)})` : '' }) + (d.only ? ' ' + t('Locked: nothing can be sent outside Europe.') : '');
  euService(); euFields();
}
function euService() {
  const s = (state.eu?.services || []).find(x => x.id === $('#eu-service').value); if (!s) return;
  $('#eu-about').textContent = window.t(s.about) + (s.note ? ' ' + window.t(s.note) : '');
  $('#eu-keys').href = s.keys; $('#eu-terms').href = s.terms;
  $('#eu-url-row').hidden = s.id !== 'infomaniak' && !$('#eu-url').value;
  const saved = state.eu.has_key && s.id === state.eu.service;
  $('#eu-key').placeholder = window.t(saved ? 'saved (leave empty to keep it)' : s.key ? 'API key' : 'API key (optional to try it)');
  euTiers();
}
// the model behind each tier with its price, and what a message costs: from the last test, the live settings, or the usual picks
const EU_MSG = { in: 20000, out: 1000, perMonth: 900 }; // a message with its context and tools; 30 a day
function euTiers() {
  const d = state.eu, id = $('#eu-service').value, s = (d?.services || []).find(x => x.id === id); if (!s) return;
  const tested = state.euTest?.service === id ? state.euTest : null;
  const tiers = tested?.tiers || (d.active && id === d.service && Object.keys(d.tiers).length ? d.tiers : s.fallback || {});
  const prices = { ...(d.prices || {}), ...(tested?.prices || {}) };
  const usd = v => v == null ? '?' : '$' + (v < 0.01 ? v.toFixed(4) : v < 1 ? v.toFixed(3) : v.toFixed(2));
  const rows = ['fast', 'standard', 'deep'].filter(k => tiers[k]).map(k => {
    const p = prices[tiers[k]], msg = p ? (EU_MSG.in * p[0] + EU_MSG.out * p[1]) / 1e6 : null;
    return `<tr><td>${esc(window.t(k))}</td><td><code>${esc(tiers[k])}</code></td><td>${p ? usd(p[0]) : '?'}</td><td>${p ? usd(p[1]) : '?'}</td><td>${usd(msg)}</td><td>${msg == null ? '?' : usd(msg * EU_MSG.perMonth)}</td></tr>`;
  });
  $('#eu-tiers').innerHTML = rows.length ? `<table class="eu-prices"><thead><tr><th>${esc(t('Level'))}</th><th>${esc(t('Model'))}</th><th>${esc(t('Input'))}</th><th>${esc(t('Output'))}</th><th>${esc(t('A message'))}</th><th>${esc(t('A month'))}</th></tr></thead><tbody>${rows.join('')}</tbody></table>`
    + `<span class="muted">${esc(t('Price per million tokens. A message here: about 20,000 tokens read (instructions, history, tools) and 1,000 written; a month: 30 messages a day. Approximate list prices: check the provider\'s page.'))}`
    + (tested || (d.active && id === d.service) ? '' : ' ' + esc(t('The usual models: "Test" shows the ones your key gets.'))) + '</span>' : '';
}
$('#eu-service').onchange = euService;
const euBody = () => ({ service: $('#eu-service').value, key: $('#eu-key').value.trim(), url: $('#eu-url').value.trim(), only: $('#eu-only').checked, light: !$('#eu-light-row').hidden && $('#eu-light').checked });
$('#eu-test').onclick = async () => {
  const st = $('#eu-state'); st.className = 'muted small'; st.textContent = window.t('Checking…');
  try {
    const r = await post('api/sovereign/test', euBody());
    state.euTest = { ...r, service: $('#eu-service').value }; euTiers();
    st.className = 'ok small';
    st.textContent = window.t('It works: {n} models.').replace('{n}', r.models);
  } catch (e) { st.className = 'bad small'; st.textContent = e.message; }
};
$('#eu-form').onsubmit = async e => {
  e.preventDefault();
  const st = $('#eu-state'), b = $('#eu-apply'); st.className = 'muted small'; st.textContent = window.t('Checking…'); b.disabled = true;
  try {
    const r = await post('api/sovereign/apply', euBody());
    $('#eu-key').value = ''; st.className = 'ok small'; st.textContent = window.t('Done: everything the assistant sends to an AI now stays in Europe.');
    showEu(r);
    renderGroups((await api('api/settings')).groups);
    refreshStatus().then(setHealth);
  } catch (err) { st.className = 'bad small'; st.textContent = err.message; }
  finally { b.disabled = false; }
};

// ---- AI accounts: another provider or subscription for some spaces ----
async function loadAccCard() {
  let d; try { d = await api('api/accounts'); } catch { return; }
  if (!state.spaces.length) await loadSpaces();
  state.acc = d; state.aiAccounts = d.accounts;
  const sel = $('#acc-service');
  if (!sel.options.length) sel.innerHTML = d.services.map(s => `<option value="${esc(s.id)}">${esc(window.t(s.name))} (${esc(window.t(s.country))})</option>`).join('');
  $('#acc-badge').textContent = d.accounts.length ? '· ' + d.accounts.length : '';
  const spaceName = id => id === 0 ? t('General') : (state.spaces.find(x => x.id === id)?.name || '#' + id);
  $('#acc-list').innerHTML = d.accounts.map(a => `<div class="acc-row"><span class="acc-name no-i18n">${esc(a.name)}</span><span class="muted small">${esc(t(a.label))}</span>
    <span class="muted small no-i18n">${a.spaces.length ? esc(t('Used by: {spaces}', { spaces: a.spaces.map(spaceName).join(', ') })) : esc(t('No space uses it yet.'))}</span>
    <span class="spacer"></span><button class="btn ghost" type="button" data-acc="${esc(a.id)}">${esc(t('Edit'))}</button></div>`).join('');
  $('#acc-list').querySelectorAll('[data-acc]').forEach(b => b.onclick = () => accEdit(d.accounts.find(a => a.id === b.dataset.acc)));
}
function accEdit(a) {
  state.accEditing = a || null;
  $('#acc-form').hidden = false; $('#acc-add').hidden = true;
  $('#acc-name').value = a?.name || '';
  $('#acc-kind').value = a?.kind || 'sovereign';
  $('#acc-service').value = a?.service || state.acc.services[0]?.id || '';
  $('#acc-url').value = a?.url || '';
  $('#acc-key').value = $('#acc-token').value = '';
  $('#acc-paid').checked = !!a?.paid;
  $('#acc-admin').value = ''; $('#acc-admin-clear').checked = false; $('#acc-scope').value = a?.billing_scope || '';
  $('#acc-billing').open = !!a?.has_admin_key;
  for (const k of ['fast', 'standard', 'deep']) $('#acc-m-' + k).value = a?.models?.[k] || '';
  $('#acc-delete').hidden = !a; $('#acc-delete').dataset.confirm = ''; $('#acc-delete').textContent = t('Delete');
  $('#acc-state').textContent = '';
  accKind(); $('#acc-name').focus();
}
function accKind() {
  const k = $('#acc-kind').value, a = state.accEditing, svc = (state.acc?.services || []).find(x => x.id === $('#acc-service').value);
  const same = a && a.kind === k && (k !== 'sovereign' || a.service === svc?.id);
  $('#acc-service-row').hidden = k !== 'sovereign';
  $('#acc-token-row').hidden = k !== 'claude-code';
  $('#acc-no-cc').hidden = k !== 'claude-code' || !!state.acc?.claude_code;
  $('#acc-key-row').hidden = k === 'claude-code';
  $('#acc-url-row').hidden = !(k === 'openai' || k === 'anthropic' || (k === 'sovereign' && (svc?.url === '' || $('#acc-url').value)));
  $('#acc-url').placeholder = k === 'anthropic' ? 'https://api.anthropic.com' : k === 'openai' ? 'https://api.openai.com/v1' : 'https://…/v1';
  $('#acc-paid-row').hidden = k !== 'gemini';
  $('#acc-billing').hidden = k !== 'anthropic' && k !== 'openai';
  $('#acc-admin-clear-row').hidden = !(same && a.has_admin_key);
  $('#acc-admin').placeholder = window.t(same && a.has_admin_key ? 'saved (leave empty to keep it)' : k === 'openai' ? 'sk-admin-…' : 'sk-ant-admin…');
  $('#acc-about').textContent = svc ? window.t(svc.about) + (svc.note ? ' ' + window.t(svc.note) : '') : '';
  $('#acc-key').placeholder = window.t(same && a.has_key ? 'saved (leave empty to keep it)' : k === 'openai' ? 'API key (none for a local server)' : 'API key');
  $('#acc-token').placeholder = window.t(same && a.has_token ? 'saved (leave empty to keep it)' : 'Token from claude setup-token');
}
const accBody = () => ({ name: $('#acc-name').value.trim(), kind: $('#acc-kind').value, service: $('#acc-service').value, url: $('#acc-url').value.trim(),
  key: $('#acc-key').value.trim(), token: $('#acc-token').value.trim(), paid: $('#acc-paid').checked,
  admin_key: $('#acc-admin').value.trim(), admin_clear: $('#acc-admin-clear').checked, billing_scope: $('#acc-scope').value.trim(),
  models: { fast: $('#acc-m-fast').value.trim(), standard: $('#acc-m-standard').value.trim(), deep: $('#acc-m-deep').value.trim() } });
const accClose = () => { $('#acc-form').hidden = true; $('#acc-add').hidden = false; state.accEditing = null; $('#acc-key').value = $('#acc-token').value = $('#acc-admin').value = ''; };
$('#acc-add').onclick = () => accEdit(null);
$('#acc-cancel').onclick = accClose;
$('#acc-kind').onchange = $('#acc-service').onchange = accKind;
$('#acc-test').onclick = async () => {
  const st = $('#acc-state'); st.className = 'muted small'; st.textContent = window.t('Checking…');
  try {
    const r = await post('api/accounts/test', { ...accBody(), id: state.accEditing?.id || '' });
    st.className = 'ok small';
    st.textContent = r.test ? window.t('It works: {n} models.').replace('{n}', r.test.models) : t('It works. Fast model: {model}.', { model: r.tiers?.fast || '' });
  } catch (e) { st.className = 'bad small'; st.textContent = e.message; }
};
$('#acc-form').onsubmit = async e => {
  e.preventDefault();
  const st = $('#acc-state'), b = $('#acc-save'), id = state.accEditing?.id; b.disabled = true;
  try {
    await post(id ? `api/accounts/${encodeURIComponent(id)}` : 'api/accounts', accBody());
    accClose(); toast(t('Account saved. Choose it in a space\'s settings.')); await loadAccCard();
  } catch (err) { st.className = 'bad small'; st.textContent = err.message; }
  finally { b.disabled = false; }
};
$('#acc-delete').onclick = async e => {
  const b = e.currentTarget, a = state.accEditing; if (!a) return;
  if (b.dataset.confirm !== '1') { b.dataset.confirm = '1'; b.textContent = t('Delete it? Its spaces go back to the main settings'); return; }
  try { await post(`api/accounts/${encodeURIComponent(a.id)}/delete`); accClose(); toast(t('Account deleted.')); await Promise.all([loadAccCard(), loadSpaces()]); }
  catch (err) { $('#acc-state').className = 'bad small'; $('#acc-state').textContent = err.message; }
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
  state.memInfo.skills = skills.length; renderMemNav();
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

async function loadScripts() {
  const { enabled, scripts } = await api('api/scripts');
  Object.assign(state.memInfo, { scripts: scripts.length, scriptsOff: !enabled && !scripts.length, toApprove: scripts.filter(k => !k.approved).length });
  if (state.memInfo.scriptsOff && state.memSection === 'scripts') showMemSection('mind', false);
  renderMemNav();
  const box = $('#scripts'); box.innerHTML = '';
  if (!scripts.length) { box.innerHTML = '<div class="placeholder">No scripts yet. When it does the same mechanical work a second time, it saves the code as a script to reuse.</div>'; return; }
  for (const k of scripts) {
    const el = document.createElement('div'); el.className = 'skill';
    el.innerHTML = `<h4>${esc(k.name)}</h4><div class="actions"><button class="btn small" data-a="edit" type="button">Open</button><button class="btn small ghost" data-a="del" type="button">Delete</button></div>
      <p>${esc(k.description)}${k.usage ? ` · <code>${esc(k.usage)}</code>` : ''}${k.used ? ` · used ${k.used}×` : ''}${k.approved ? '' : ' · <span class="muted">not approved yet</span>'}</p>`;
    el.querySelector('[data-a=edit]').onclick = async () => {
      const { content } = await api('api/scripts/' + encodeURIComponent(k.name));
      state.script = k.name; $('#script-editor').value = content; $('#script-edit').hidden = false; $('#script-state').textContent = '';
      $('#script-editor').focus();
    };
    el.querySelector('[data-a=del]').onclick = async () => {
      const b = el.querySelector('[data-a=del]');
      if (b.dataset.confirm !== '1') { b.dataset.confirm = '1'; b.textContent = 'Confirm delete'; return; }
      try { await post(`api/scripts/${encodeURIComponent(k.name)}/delete`); toast('Script deleted'); loadScripts(); } catch (e) { toast(e.message); }
    };
    box.append(el);
  }
}

function showDigest(d) {
  const l = d.levels || {};
  const parts = [l[3] && `${l[3]} month(s)`, l[2] && `${l[2]} week(s)`, l[1] && `${l[1]} recent summary(ies)`].filter(Boolean);
  $('#digest-state').textContent = (parts.length ? 'Digest: ' + parts.join(', ') + '. ' : 'No digest yet. ') + `${d.waiting} message(s) word for word or waiting to be summarised.`;
  state.memInfo.digest = parts.length ? (l[3] ? t('{n} month(s)', { n: l[3] }) : l[2] ? t('{n} week(s)', { n: l[2] }) : t('{n} summary(ies)', { n: l[1] })) : t('not started'); renderMemNav();
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
  state.memInfo.mind = d.enabled ? d.beliefs.filter(b => b.status === 'established').length : false; renderMemNav();
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

// ---- memory page: sections like Settings ----
const MEM_SECTIONS = [
  ['mind', 'Character and values', 'M12 21s-7-4.4-9.3-9A5.3 5.3 0 0 1 12 6a5.3 5.3 0 0 1 9.3 6c-2.3 4.6-9.3 9-9.3 9z'],
  ['skills', 'Skills', 'M14 7l3-3 3 3-3 3M17 4v0M4 20l9-9M11 7l6 6'],
  ['scripts', 'Scripts', 'M8 8l-4 4 4 4M16 8l4 4-4 4M14 5l-4 14'],
  ['digest', 'Conversation digest', 'M4 5h16M4 10h12M4 15h8M4 20h5'],
  ['files', 'Memory files', 'M6 3h8l4 4v14H6zM14 3v4h4M9 12h6M9 16h6'],
];
state.memInfo = {};
const memIds = () => MEM_SECTIONS.map(s => s[0]).filter(id => id !== 'scripts' || !state.memInfo.scriptsOff);
function showMemSection(id, scroll = true) {
  if (!memIds().includes(id)) id = 'mind';
  state.memSection = id;
  if ($('#search-q').value || !$('#mem-results').hidden) memSearchClear();
  document.querySelectorAll('#view-memory .set-sec[data-sec]').forEach(s => { s.hidden = s.dataset.sec !== id; });
  document.querySelectorAll('#mem-nav a, #mem-chips a').forEach(a => { a.classList.toggle('on', a.dataset.sec === id); a.setAttribute('aria-current', a.dataset.sec === id ? 'page' : 'false'); });
  $('#mem-chips a.on')?.scrollIntoView({ block: 'nearest', inline: 'center' });
  if (location.hash.split('/')[0] === '#memory' && location.hash !== '#memory/' + id) history.replaceState(null, '', '#memory/' + id);
  if (scroll) { const pg = $('#view-memory'), top = $('#view-memory .set-layout').offsetTop; if (pg.scrollTop > top) pg.scrollTop = top - 8; }
}
/** The memory menu (computer) and chips (phone), each section with a word on what it holds. */
function renderMemNav() {
  const i = state.memInfo, n = (v, one, many) => t(v === 1 ? one : many, { n: v });
  const note = {
    mind: i.mind === false ? ['w', t('learning off')] : i.mind != null ? [i.mind ? 'o' : '', n(i.mind, '{n} established', '{n} established')] : null,
    skills: i.skills != null ? [i.skills ? 'o' : '', n(i.skills, '{n} skill', '{n} skills')] : null,
    scripts: i.toApprove ? ['w', t('{n} to approve', { n: i.toApprove })] : i.scripts != null ? [i.scripts ? 'o' : '', n(i.scripts, '{n} script', '{n} scripts')] : null,
    digest: i.digest ? ['', i.digest] : null,
    files: i.dirty ? ['w', t('unsaved changes')] : i.files != null ? ['', n(i.files, '{n} file', '{n} files')] : null,
  };
  const html = chip => MEM_SECTIONS.filter(s => memIds().includes(s[0])).map(([id, title, d]) => {
    const x = note[id];
    return `<a href="#memory/${id}" data-sec="${id}" class="${id === state.memSection ? 'on' : ''}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="${d}"/></svg>
      <span class="sn-t">${esc(t(title))}${!chip && x?.[1] ? `<small>${esc(x[1])}</small>` : ''}</span>${x?.[0] ? `<i class="dot ${x[0]}" title="${esc(x[1] || '')}"></i>` : ''}</a>`;
  }).join('');
  $('#mem-nav').innerHTML = html(false); $('#mem-chips').innerHTML = html(true);
  document.querySelectorAll('#mem-nav a, #mem-chips a').forEach(a => a.onclick = e => { e.preventDefault(); showMemSection(a.dataset.sec); });
}
function memSearchClear() {
  twoPassStop('memory'); $('#search-q').value = '';
  $('#mem-results').hidden = true; $('#view-memory').classList.remove('searching');
  document.querySelectorAll('#view-memory .set-sec[data-sec]').forEach(s => { s.hidden = s.dataset.sec !== state.memSection; });
}
async function loadMemory() {
  const p = location.hash.split('/')[1];
  showMemSection(MEM_SECTIONS.some(s => s[0] === p) ? p : state.memSection || 'mind', false);
  renderMemNav();
  loadMind().catch(e => toast(e.message));
  loadSkills().catch(e => toast(e.message));
  loadScripts().catch(e => toast(e.message));
  loadDigest().catch(() => {});
  state.memFiles = (await api('api/memory')).files;
  state.memInfo.files = Object.keys(state.memFiles).length; state.memInfo.dirty = false; renderMemNav();
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
  $('#mem-state').textContent = ''; $('#mem-revert').hidden = true;
  if (state.memInfo.dirty) { state.memInfo.dirty = false; renderMemNav(); }
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
  if (was !== undefined && was !== lib.folder && (libSearch.phase || libRegex.on)) libSearchClose(); // another folder: its content, not old results
  const [tree, list] = await Promise.all([api('api/library/tree'), api('api/library?folder=' + encodeURIComponent(lib.folder))]);
  lib.tree = tree.tree; lib.trash = tree.stats.trash || 0; lib.shared = list.shared;
  $('#lib-trash').textContent = lib.trash ? `${t('Trash')} (${lib.trash})` : t('Trash'); lib.level = list.level || 'internal';
  lib.folder = list.folder; lib.readonly = !!list.readonly;
  for (const b of ['#lib-upload', '#lib-newnote', '#lib-newfolder']) $(b).hidden = lib.readonly;
  const st = list.stats;
  if (st.files) {
    const full = `${nf.format(st.files)} files · ${fmtSize(st.bytes)}${st.failed ? ' ' + t('· {a} unreadable', { a: st.failed }) : ''}. ${st.pending ? t('Reading {a} new file{b} to index…', { a: st.pending, b: st.pending > 1 ? 's' : '' }) : t('Everything is searchable, by you and by the assistant.')}`;
    const short = t('{a} files · {b}', { a: nf.format(st.files), b: fmtSize(st.bytes) }) + (st.failed ? ' ' + t('· {a} unreadable', { a: st.failed }) : '') + (st.pending ? ' · ' + t('{a} reading…', { a: st.pending }) : '');
    $('#lib-stats').innerHTML = `<span class="stats-full">${esc(full)}</span><span class="stats-short">${esc(short)}</span>`;
    $('#lib-stats').setAttribute('aria-label', full);
  } else { $('#lib-stats').textContent = t('Your documents, notes and recordings. Everything is searchable, by you and by the assistant.'); $('#lib-stats').removeAttribute('aria-label'); }
  $('#folder-list').innerHTML = lib.tree.map(t => `<option value="${esc(t.path)}">`).join('');
  renderTree(); renderCrumbs(); renderList(list);
  if (!libSearch.phase && !libRegex.on) { $('#lib-results').hidden = true; $('#lib-list').hidden = false; } // no search: show the folder
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

// ---- recent changes: what was created or changed lately, and by whom ----
const RECENT_RANGES = [[1, 'Last hour'], [24, 'Today'], [48, '2 days'], [168, '7 days']];
const RECENT_BY = { you: 'you', assistant: 'the assistant', routine: 'a routine', webdav: 'the network drive', share: 'a share' };
let recentHours = 24;
async function showLibRecent(hours = recentHours) {
  const d = $('#recent-dialog');
  recentHours = hours;
  let r;
  try { r = await api('api/library/recent?hours=' + hours); } catch (e) { toast(e.message); return; }
  $('#recent-range').innerHTML = RECENT_RANGES.map(([h, l]) => `<button class="rchip${h === hours ? ' on' : ''}" type="button" data-h="${h}" aria-pressed="${h === hours}">${esc(t(l))}</button>`).join('');
  $('#recent-range').querySelectorAll('[data-h]').forEach(b => b.onclick = () => showLibRecent(+b.dataset.h));
  const muted = new Set(r.muted);
  $('#recent-list').innerHTML = r.items.length ? r.items.map((x, i) => {
    const what = t(x.change === 'created' ? 'created by {who}' : x.change === 'changed' ? 'changed by {who}' : x.change === 'moved' ? 'moved by {who}' : x.change === 'renamed' ? 'renamed by {who}' : 'restored by {who}', { who: t(RECENT_BY[x.changed_by] || x.changed_by) });
    return `<div class="trash-item recent-item${x.unseen ? ' unseen' : ''}">${CITE_SVG}<a href="#file/${x.id}" data-open="${i}"><b>${x.unseen ? '<span class="dot" title="' + esc(t('not opened since')) + '"></span>' : ''}${esc(x.name)}</b></a>
      <span class="row">${x.unseen ? `<button class="btn small" type="button" data-ack="${x.id}" title="${esc(t('Mark this change as seen'))}">${esc(t('Acknowledge'))}</button>` : ''}<button class="btn small ghost" type="button" data-mute="${esc(x.folder)}" title="${esc(t('Notifications for this folder'))}">${esc(t(muted.has(x.folder) ? 'Notify again' : 'Stop notifying'))}</button></span>
      <span class="muted small">${esc(x.folder)} · ${esc(what)} · ${esc(fmtTime(x.changed))}</span></div>`;
  }).join('') : `<p class="muted">${esc(t('Nothing created or changed in that time.'))}</p>`;
  $('#recent-list').querySelectorAll('[data-open]').forEach(a => a.onclick = e => { e.preventDefault(); d.close(); openCited(r.items[+a.dataset.open].id); });
  $('#recent-list').querySelectorAll('[data-ack]').forEach(b => b.onclick = async () => {
    try { await post('api/library/recent/seen', { id: +b.dataset.ack }); showLibRecent(); } catch (e) { toast(e.message); }
  });
  $('#recent-list').querySelectorAll('[data-mute]').forEach(b => b.onclick = async () => {
    const f = b.dataset.mute;
    try { await post('api/library/recent/settings', muted.has(f) ? { unmute: f } : { mute: f }); showLibRecent(); } catch (e) { toast(e.message); }
  });
  $('#recent-muted').textContent = r.muted.length ? t('No notifications for: {list}', { list: r.muted.join(', ') }) : '';
  const sel = $('#recent-notify'); sel.value = r.notify;
  sel.onchange = async () => { try { await post('api/library/recent/settings', { notify: sel.value }); toast(t('Saved')); } catch (e) { toast(e.message); } };
  $('#recent-seen').onclick = async () => { try { await post('api/library/recent/seen'); showLibRecent(); } catch (e) { toast(e.message); } };
  $('#recent-close').onclick = () => d.close();
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
$('#lib-recent').onclick = () => showLibRecent();
$('#viewer-share').onclick = () => lib.file && openShare({ file: lib.file.id }, lib.file.name);
const isVideo = f => (f.mime || '').startsWith('video/');
const Library_join = (folder, name) => (folder === '/' ? '' : folder) + '/' + name;

// A small action menu anchored under a row's "⋯" button (desktop). items: {label, run?, href?, download?, danger?, sep?}.
let rowMenuEl = null;
function closeRowMenu() { rowMenuEl?.remove(); rowMenuEl = null; }
function openRowMenu(btn, items) {
  const open = rowMenuEl && rowMenuEl._btn === btn; closeRowMenu();
  if (open) return; // a second click on the same button closes it
  const m = document.createElement('div'); m.className = 'row-menu'; m.setAttribute('role', 'menu'); m._btn = btn;
  for (const it of items) {
    if (it.sep) { const s = document.createElement('div'); s.className = 'row-menu-sep'; m.append(s); continue; }
    const a = document.createElement(it.href ? 'a' : 'button');
    if (it.href) { a.href = it.href; if (it.download) a.setAttribute('download', it.download); } else a.type = 'button';
    a.className = 'row-menu-item' + (it.danger ? ' danger' : ''); a.setAttribute('role', 'menuitem'); a.textContent = it.label;
    a.addEventListener('click', e => { e.stopPropagation(); if (!it.href) e.preventDefault(); closeRowMenu(); it.run?.(); });
    m.append(a);
  }
  document.body.append(m);
  const r = btn.getBoundingClientRect();
  m.style.top = Math.max(8, Math.min(r.bottom + 4, innerHeight - m.offsetHeight - 8)) + 'px';
  m.style.left = Math.max(8, Math.min(r.right - m.offsetWidth, innerWidth - m.offsetWidth - 8)) + 'px';
  rowMenuEl = m;
}
document.addEventListener('click', e => { if (!e.target.closest('.row-menu')) closeRowMenu(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape' && rowMenuEl) { e.stopPropagation(); closeRowMenu(); } }, true);
window.addEventListener('resize', closeRowMenu);
window.addEventListener('scroll', closeRowMenu, true);

// Redraw whatever the library is showing: the regex results when a name search is open, else the folder.
function libRefresh() { if (libRegex.on) { libRegexSearch(libRegex.q, libRegex.folder); loadLibrary().catch(() => {}); } else loadLibrary(); }
function libFileMenu(btn, f) {
  openRowMenu(btn, [
    { label: t('Rename'), run: () => libInline(f.name, t('New name'), async v => { if (await withReplace(r => post(`api/files/${f.id}/rename`, { name: v, ...r }))) { toast(t('Renamed')); libRefresh(); } }, false) },
    { label: t('Move to…'), run: () => libInline(f.folder, t('Folder'), async v => { if (await withReplace(r => post(`api/files/${f.id}/move`, { folder: v, ...r }))) { toast(t('Moved to {folder}', { folder: v })); libRefresh(); } }, true) },
    { label: t('Download'), href: `api/files/${f.id}/file/${encodeURIComponent(f.name)}?download=1`, download: f.name },
    { label: t('Share…'), run: () => openShare({ file: f.id }, f.name) },
    { sep: true },
    { label: t('Delete'), danger: true, run: async () => { try { await post(`api/files/${f.id}/delete`); toast(t('Moved to the trash')); libRefresh(); } catch (e) { toast(e.message); } } },
  ]);
}
function libFolderMenu(btn, folder, total) {
  const name = folder.split('/').pop();
  openRowMenu(btn, [
    { label: t('Open'), run: () => libGo(folder) },
    { label: t('New file here'), run: () => newNote(folder) },
    { label: t('New folder here'), run: () => libInline(folder + '/', t('New folder path'), async v => { const { folder: nf } = await post('api/library/folder', { path: v }); toast(t('Folder created')); libGo(nf); }, true) },
    { label: t('Rename or move'), run: () => libInline(folder, t('Folder path'), async v => { const { folder: nf } = await post('api/library/folder/move', { from: folder, to: v }); toast(t('Folder renamed')); libGo(nf); }, true) },
    { label: t('Download zip'), href: `api/library/zip?folder=${encodeURIComponent(folder)}`, download: name + '.zip' },
    { sep: true },
    { label: t('Delete'), danger: true, run: async () => { if (!confirm(t('Delete the folder “{name}”{n}?', { name, n: total ? t(' and its {n} files', { n: total }) : '' }))) return; try { const r = await post('api/library/folder/delete', { path: folder, recursive: true }); toast(r.deleted_files ? t('Folder moved to the trash') : t('Folder deleted')); libRefresh(); } catch (e) { toast(e.message); } } },
  ]);
}
const KEBAB_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg>';

function fileRow(f, sub) {
  const el = document.createElement('div'); el.className = 'lib-row'; el.draggable = true; el.tabIndex = 0; el.setAttribute('role', 'button');
  const ext = (f.name.match(/\.([a-z0-9]{1,4})$/i) || [])[1];
  const label = f.note ? 'NOTE' : isVideo(f) ? 'VID' : ['text', 'office'].includes(f.kind) && ext ? ext.toUpperCase() : KIND_LABEL[f.kind] || 'FILE';
  const ico = f.kind === 'image' || f.kind === 'pdf' ? `<img src="api/files/${f.id}/thumb" alt="" loading="lazy">` : label;
  const status = f.status === 'pending' || f.status === 'processing' ? '<span class="pill pending">reading…</span>' : f.status === 'failed' ? '<span class="pill failed">unreadable</span>' : '';
  el.innerHTML = `<span class="ico">${ico}</span><span class="name"><b>${esc(f.name)}</b><span class="${sub ? 'snippet' : ''}">${sub ? esc(sub) : `${fmtSize(f.size)} · ${fmtDate(f.created)}${f.duration ? ' · ' + Math.round(f.duration / 60) + ' min' : ''}`}</span></span>`
    + `<span class="row-end">${status}${lib.shared?.files?.includes(f.id) ? `<span class="shared-mark" title="${esc(t('Shared by link'))}">${LINK_SVG}</span>` : ''}<a class="btn ghost icon dl" href="api/files/${f.id}/file/${encodeURIComponent(f.name)}?download=1" download="${esc(f.name)}" aria-label="Download ${esc(f.name)}" title="Download">${DL_SVG}</a>`
    + (lib.readonly ? '' : `<button type="button" class="btn ghost icon kebab" aria-label="${esc(t('Actions'))}" aria-haspopup="menu" title="${esc(t('Actions'))}">${KEBAB_SVG}</button>`) + `</span>`;
  el.querySelector('.dl').onclick = e => e.stopPropagation();
  el.querySelector('.kebab')?.addEventListener('click', e => { e.stopPropagation(); libFileMenu(e.currentTarget, f); });
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
    el.innerHTML = `<span class="ico folder">${FOLDER_SVG}</span><span class="name"><b>${esc(t.name)}</b><span>${t.total} file${t.total === 1 ? '' : 's'}</span></span><span class="row-end">${lib.shared?.folders?.includes(t.path) ? `<span class="shared-mark" title="${esc(window.t('Shared by link'))}">${LINK_SVG}</span>` : ''}`
      + (lib.readonly ? '' : `<button type="button" class="btn ghost icon kebab" aria-label="${esc(window.t('Actions'))}" aria-haspopup="menu" title="${esc(window.t('Actions'))}">${KEBAB_SVG}</button>`) + `</span>`;
    el.onclick = e => { e.preventDefault(); libGo(t.path); };
    el.querySelector('.kebab')?.addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); libFolderMenu(e.currentTarget, t.path, t.total); });
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
    if (id) { try { if (await withReplace(r => post(`api/files/${id}/move`, { folder: path, ...r }))) { toast('Moved to ' + path); loadLibrary(); } } catch (err) { toast(err.message); } return; }
    libUpload([...(e.dataTransfer.files || [])], path);
  });
}

async function libUpload(files, folder = lib.folder) {
  if (!files.length) return;
  const target = folder === '/' ? '/Inbox' : folder;
  let ok = 0, replaced = 0, lastReplaced = '';
  for (const [i, f] of files.entries()) {
    $('#lib-stats').textContent = `Uploading ${i + 1} of ${files.length}: ${f.name}…`;
    const r = {};
    try { await uploadFile(f, f.name, target, true, r); ok++; if (r.replaced) { replaced++; lastReplaced = f.name; } } catch (e) { toast(`${f.name}: ${e.message}`); }
  }
  if (ok === 1 && replaced) toast(t('{name} was replaced; the earlier version is kept in Versions', { name: lastReplaced }));
  else if (ok) toast(`${ok} file${ok > 1 ? 's' : ''} added to ${target}` + (replaced ? ' · ' + t('{n} replaced (earlier versions kept)', { n: replaced }) : ''));
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
  openCited(+a.dataset.file, +a.dataset.page || 0);
});
/** Open a document of the library, at a page (a citation, or #file/12/p3 from a mail). */
async function openCited(id, page) {
  try {
    const { file } = await api(`api/files/${id}/text`);
    lib.folder = file.folder; location.hash = '#library' + (file.folder === '/' ? '' : file.folder);
    setTimeout(() => openFile(id, { page }), 150);
  } catch (err) { toast(err.message.includes('not found') ? t('That document is no longer in the library.') : err.message); }
}

async function openFile(id, opts = {}) {
  const v = $('#viewer'), body = $('#viewer-body');
  let d;
  try { d = await api(`api/files/${id}/text`); } catch (e) { toast(e.message); return; }
  const f = lib.file = d.file;
  v.hidden = false; fullViewer(false); document.body.classList.add('viewer-open'); $('#viewer-form').hidden = true;
  $('#viewer-name').textContent = f.name;
  moreActions(false);
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
  $('#viewer-script').hidden = !/\.py$/i.test(lib.file?.name || '');
  $('#viewer-contribute').hidden = !(state.status?.fleet && spaceOf(state.space)?.hub_contribute && (f.kind === 'image' || f.note || f.kind === 'text'));
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
  const wysiwyg = editable && /\.(md|markdown)$/i.test(f.name);
  if (editable) html += `<div id="viewer-edit-box" class="viewer-edit" data-pane="edit" ${rendered ? 'hidden' : ''}>${wysiwyg ? '<div id="viewer-rte"></div>' : `<textarea id="viewer-editor" class="editor" spellcheck="true" aria-label="Content">${esc(d.text)}</textarea>`}<div class="row"><button id="viewer-save" class="btn primary" type="button">Save</button><span id="viewer-state" class="muted small"></span><span class="spacer"></span>${wysiwyg ? '<button id="viewer-src" class="btn small ghost" type="button" aria-pressed="false" title="Edit the Markdown source">Markdown</button>' : ''}<button id="viewer-full" class="btn small ghost" type="button" aria-pressed="false" title="Full screen (Esc to leave)">Full screen</button></div></div>`;
  html += '<details id="viewer-versions" class="versions" hidden></details>';
  if (hasText) html += `<pre class="extracted" data-pane="text" hidden>${esc(d.text)}${d.truncated ? '\n…' : ''}</pre>`;
  if (!rendered) {
    if (f.status === 'pending' || f.status === 'processing') html += '<p class="muted">Reading this file… it will be searchable in a moment.</p>';
    else if (d.text && !hasText) html += `<h4>${f.kind === 'audio' ? 'Transcript' : f.kind === 'image' ? 'What the assistant sees' : 'Text'}</h4><pre class="extracted">${esc(d.text)}${d.truncated ? '\n…' : ''}</pre>`;
    else if (f.kind === 'other') html += '<p class="muted">This kind of file cannot be shown here; download it to open it.</p>';
  }
  viewerEd?.destroy(); viewerEd = null;
  body.innerHTML = html;
  const dirty = () => { const s = $('#viewer-state'); if (s) s.textContent = 'Unsaved changes'; };
  let rte = null;
  if (wysiwyg) {
    const source = /^---\n|<\/?[a-z][^>]*>|^ {0,3}```\w/im.test(d.text); // front matter, HTML, code with a language: start on the source
    rte = await makeMdEditor($('#viewer-rte'), d.text, { source, onChange: dirty });
    if (lib.file !== f) { rte.destroy(); return; } // another file was opened meanwhile
    viewerEd = rte;
    const sb = $('#viewer-src'); sb.hidden = !rte.toggleSource; sb.setAttribute('aria-pressed', String(rte.source));
    sb.onclick = async () => { sb.disabled = true; try { sb.setAttribute('aria-pressed', String(await rte.toggleSource())); } finally { sb.disabled = false; } };
  }
  const content = () => rte ? rte.value : $('#viewer-editor').value;
  if (opts.page && paged) { // open on the cited page
    const pg = body.querySelectorAll('.pdf-page')[opts.page - 1];
    if (pg) setTimeout(() => { pg.scrollIntoView({ block: 'start' }); pg.classList.add('flash'); }, 300);
  }
  body.classList.toggle('framed', rendered);
  if (rendered) fillPreview($('#viewer-frame'), f.id);
  showVersions(f, ro);
  const more = $('#pdf-more');
  if (more) more.onclick = () => {
    const from = +more.dataset.next, to = Math.min(d.pages, from + 9);
    for (let n = from; n <= to; n++) $('#pdf-pages').insertAdjacentHTML('beforeend', pdfPageHtml(f.id, n, d.pages));
    more.dataset.next = to + 1; more.textContent = `Show more pages (${d.pages - to} left)`; more.hidden = to >= d.pages;
  };
  body.querySelectorAll('[data-tab]').forEach(t => t.onclick = () => {
    body.querySelectorAll('[data-tab]').forEach(o => o.setAttribute('aria-selected', String(o === t)));
    body.querySelectorAll('[data-pane]').forEach(p => { p.hidden = p.dataset.pane !== t.dataset.tab; });
    if (t.dataset.tab === 'edit') rte ? rte.focus() : $('#viewer-editor').focus();
    if (t.dataset.tab !== 'edit') fullViewer(false);
  });
  $('#viewer-full')?.addEventListener('click', () => fullViewer(!v.classList.contains('full')));
  $('#viewer-save')?.addEventListener('click', async () => {
    try {
      await post('api/library/note', { id: f.id, folder: f.folder, name: f.name, content: content() });
      $('#viewer-state').textContent = 'Saved';
      if (rte) rte.saved();
      const fr = $('#viewer-frame'); if (fr) fillPreview(fr, f.id);
      loadLibrary();
    } catch (e) { toast(e.message); }
  });
  $('#viewer-edit-box')?.addEventListener('input', dirty);
  $('#viewer-rte .rte-bar')?.addEventListener('click', e => { if (e.target.closest('button:not(.src)')) dirty(); });
}
let viewerEd = null;

// ---- the library's Markdown editor: Milkdown (vendor/, loaded the first time it is needed) ----
const MILKDOWN = 'vendor/milkdown-7.22.3';
let milkdownLib = null;
function loadMilkdown() {
  if (!document.querySelector(`link[href="${MILKDOWN}.css"]`)) document.head.insertAdjacentHTML('beforeend', `<link rel="stylesheet" href="${MILKDOWN}.css">`);
  // from the page's address: app.js itself is served under v/<version>/
  return milkdownLib ||= import(new URL(MILKDOWN + '.js', document.baseURI).href).catch(e => { milkdownLib = null; throw e; });
}
/**
 * A Markdown file edited like a document, its source one click away (toggleSource). value is the file to save:
 * the text it was opened with as long as nothing changed, so a file is never reformatted just by opening it.
 * Without the editor (the script failed to load), the task editor (makeRte) does the job.
 */
async function makeMdEditor(box, text, { source = false, onChange = () => {} } = {}) {
  let M;
  try { M = await loadMilkdown(); } catch {
    box.className = 'rte';
    const r = makeRte(box, { label: true, source }); r.value = text; let loaded = text, base = r.value;
    return {
      get value() { return r.value === base ? loaded : r.value + '\n'; },
      saved() { loaded = r.value + '\n'; base = r.value; },
      focus: () => r.focus(), destroy() {}, source,
    };
  }
  const T = window.t || (x => x);
  box.className = 'md-editor';
  box.innerHTML = '<div class="md-visual"></div><textarea class="md-src editor" hidden spellcheck="true" aria-label="Content (Markdown)"></textarea>';
  const vis = box.querySelector('.md-visual'), src = box.querySelector('.md-src');
  let crepe = null, loaded = text, base = '', ready = false, srcMode = source;
  src.addEventListener('input', () => onChange());
  const item = l => ({ label: T(l) });
  const build = async markdown => {
    ready = false;
    await crepe?.destroy(); vis.innerHTML = '';
    crepe = new M.CrepeBuilder({ root: vis, defaultValue: markdown });
    crepe.editor.config(ctx => ctx.update(M.remarkStringifyOptionsCtx, o => ({ ...o, bullet: '-' })));
    crepe.addFeature(M.cursor).addFeature(M.listItem).addFeature(M.table).addFeature(M.toolbar)
      .addFeature(M.linkTooltip, { inputPlaceholder: T('Paste a link…') })
      .addFeature(M.placeholder, { text: T('Type / for headings, lists, tables…') })
      .addFeature(M.topBar, { headingOptions: [{ label: T('Paragraph'), level: null }, ...[1, 2, 3, 4, 5, 6].map(level => ({ label: T('Heading ' + level), level }))] })
      .addFeature(M.blockEdit, {
        textGroup: { label: T('Text'), text: item('Text'), h1: item('Heading 1'), h2: item('Heading 2'), h3: item('Heading 3'), h4: item('Heading 4'), h5: item('Heading 5'), h6: item('Heading 6'), quote: item('Quote'), divider: item('Divider') },
        listGroup: { label: T('Lists'), bulletList: item('Bullet list'), orderedList: item('Numbered list'), taskList: item('Checklist') },
        advancedGroup: { label: T('More'), image: null, math: null, codeBlock: item('Code'), table: item('Table') },
      });
    crepe.on(l => l.markdownUpdated((_, now) => { if (ready && now !== base) onChange(); }));
    await crepe.create();
    loaded = markdown; base = crepe.getMarkdown(); ready = true;
  };
  const visual = () => { const m = crepe.getMarkdown(); return m === base ? loaded : m.replace(/\n*$/, '\n'); };
  if (srcMode) { src.value = text; vis.hidden = true; src.hidden = false; } else await build(text);
  return {
    get value() { return srcMode ? src.value : visual(); },
    get source() { return srcMode; },
    saved() { if (srcMode) return; loaded = visual(); base = crepe.getMarkdown(); },
    async toggleSource() {
      if (srcMode) { await build(src.value); srcMode = false; } else { src.value = visual(); srcMode = true; }
      vis.hidden = srcMode; src.hidden = !srcMode; this.focus();
      return srcMode;
    },
    focus() { srcMode ? src.focus() : vis.querySelector('.ProseMirror')?.focus(); },
    destroy() { crepe?.destroy(); crepe = null; },
  };
}
function fullViewer(on) {
  const v = $('#viewer'), b = $('#viewer-full');
  v.classList.toggle('full', on);
  if (b) { b.textContent = on ? 'Exit full screen' : 'Full screen'; b.setAttribute('aria-pressed', String(on)); }
}
function closeViewer() { fullViewer(false); viewerEd?.destroy(); viewerEd = null; $('#viewer').hidden = true; lib.file = null; document.body.classList.remove('viewer-open'); }
function viewerForm(value, onOk, folders = false) {
  const form = $('#viewer-form'), input = $('#viewer-input');
  form.hidden = false; input.value = value; folderPicker(input, folders); input.focus(); input.select();
  form.onsubmit = async e => { e.preventDefault(); try { await onOk(input.value.trim()); form.hidden = true; } catch (err) { toast(err.message); } };
}
function pdfPageHtml(id, n, total) {
  return `<figure class="pdf-page"><img src="api/files/${id}/page/${n}" alt="Page ${n}" loading="lazy"><figcaption>Page ${n} of ${total}</figcaption></figure>`;
}
async function newNote(where) {
  const folder = (typeof where === 'string' ? where : lib.folder) === '/' ? '/Notes' : (typeof where === 'string' ? where : lib.folder);
  try { const { file } = await post('api/library/note', { folder, name: '', content: '# ' }); await loadLibrary(); await openFile(file.id); $('#viewer-body [data-tab=edit]')?.click(); }
  catch (e) { toast(e.message); }
}

// ---- mail to handle ----
const MAIL_LABELS = { reply: 'To answer', action: 'To do', fyi: 'To read', low: 'Low priority' };
async function loadMail() {
  const d = await api('api/mail' + ($('#mail-all').checked ? '?all=1' : ''));
  const box = $('#mail-list');
  if (!d.mailbox) { box.innerHTML = '<div class="placeholder">Connect your mailbox first: Settings → Connected accounts → Your mailbox.</div>'; return; }
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
  box.querySelector('[data-a=ask]')?.addEventListener('click', () => { location.hash = '#chat'; $('#input').value = t('About {a}:', { a: c.name }) + ' '; setTimeout(() => { autosize(); $('#input').focus(); }, 50); });
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
  const { usage, tools, budget, by_tier, by_source, memory, backup, calibration, billing } = await api('api/activity');
  const meter = (label, spent, limit) => {
    const pct = limit > 0 ? Math.min(100, spent / limit * 100) : 0;
    const cls = !limit ? '' : pct >= 100 ? 'blocked' : pct >= 80 ? 'low' : '';
    return `<div class="meter"><div class="row"><span class="muted small">${label}</span><span class="muted small">${limit > 0 ? 'limit $' + limit.toFixed(2) : 'no limit set'}</span></div>
      <b>$${spent.toFixed(2)}</b><div class="track ${cls}"><i style="width:${pct.toFixed(1)}%"></i></div></div>`;
  };
  $('#budget').innerHTML = meter('Spent today', budget.today, budget.daily_limit) + meter('Spent this month', budget.month, budget.monthly_limit);
  $('#calib-text').textContent = calibration.text;
  if (!$('#bill-month').value) $('#bill-month').value = calibration.last_month;
  renderBilling(billing);
  $('#bills tbody').innerHTML = calibration.reports.length ? calibration.reports.map(r => `<tr><td>${esc(r.from)} → ${esc(r.to)}</td><td>${r.amount.toFixed(2)} ${esc(r.currency)}</td><td>$${r.estimate.toFixed(2)}</td><td>${r.estimate >= 0.01 ? '×' + (r.usd / r.estimate).toFixed(2) : '–'}</td></tr>`).join('')
    : '<tr><td colspan="4" class="muted">No bill recorded yet.</td></tr>';
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
// Apps: small apps the assistant builds from the kit (appkit.js). Each runs in a sandboxed frame with an opaque origin and
// no network; it asks this page for data through postMessage, and this page answers only the frame it opened.
let appFrame = null;
async function loadApps() {
  const id = +(location.hash.split('/')[1] || 0);
  const sp = state.space ?? '';
  if (id) return openApp(id);
  appFrame = null;
  $('#app-open').hidden = true; $('#view-apps').classList.remove('app-wide'); $('#app-open').innerHTML = '';
  const { apps, examples } = await api('api/apps?space=' + sp);
  const box = $('#apps-list'); box.hidden = false;
  const have = new Set(apps.map(a => a.name));
  const spaceOf = id => id === 0 ? t('General') : (state.spaces.find(x => x.id === id)?.name || '#' + id);
  const ex = examples.filter(e => !have.has(e.name));
  box.innerHTML = (apps.length ? `<div class="apps-grid">${apps.map(a => `<a class="app-card card" href="#apps/${a.id}">
      <span class="app-icon">${esc(a.icon || '🧩')}</span><b>${esc(a.name)}</b>
      <span class="muted small">${esc(a.description || '')}</span>
      <span class="small muted no-i18n">${esc(spaceOf(a.space))}${a.status === 'pending' ? ` · <span class="app-pending">${esc(t('Waiting for approval'))}</span>` : ''}</span></a>`).join('')}</div>`
    : `<div class="empty"><p>No apps yet. Ask the assistant: “With what you know about me, could you suggest several apps you could build and add to the Apps menu?”</p></div>`)
    + (ex.length ? `<h3 class="apps-ex-title">Examples</h3><div class="row wrap">${ex.map(e => `<button type="button" class="btn ghost" data-ex="${esc(e.key)}" title="${esc(e.description)}">${esc(e.icon)} ${esc(e.name)}</button>`).join('')}</div>` : '')
    + `<p class="row wrap"><button type="button" class="btn small" id="apps-ask">Ask the assistant for app ideas</button>
      <label class="btn small ghost">Add an app from a file<input type="file" id="apps-import" accept=".json,application/json" hidden></label></p>`;
  storeApps(box, sp);
  $('#apps-import').onchange = async e => {
    const f = e.target.files[0]; if (!f) return;
    try { const { app } = await post('api/apps/import?space=' + sp, JSON.parse(await f.text())); location.hash = '#apps/' + app.id; }
    catch (err) { toast(err instanceof SyntaxError ? t('This is not a Talixio app (.talixio-app.json).') : err.message); }
  };
  box.querySelectorAll('[data-ex]').forEach(b => b.onclick = async () => {
    const { app } = await post('api/apps/example?space=' + sp, { key: b.dataset.ex }).catch(e => (toast(e.message), {}));
    if (app) location.hash = '#apps/' + app.id;
  });
  $('#apps-ask').onclick = () => { location.hash = '#chat'; $('#input').value = t('With what you know about me, could you suggest several apps you could build and add to the Apps menu?'); $('#input').focus(); };
}

/** The server's store (several people on one server): what the admin approved, to install here, update or remove. */
async function storeApps(box, sp) {
  const st = await api('api/store?space=' + sp).catch(() => null);
  const el = document.createElement('div'); el.className = 'store-box';
  if (!st?.available) { el.innerHTML = `<h3>${esc(t('From the store'))}</h3>${storeOff(st)}`; box.append(el); return; }
  state.store = st;
  el.innerHTML = `<h3>${esc(t('From the store'))}</h3><p class="muted small">${esc(t('Apps the server’s admin approved. An app you install still waits for your approval before it gets any data.'))}</p>`
    + (st.apps.length ? `<div class="store-items">${st.apps.map(a => `<div class="card store-item" data-id="${esc(a.id)}">
        <div class="row"><span class="app-icon">${esc(a.icon || '🧩')}</span><b class="no-i18n">${esc(a.name)}</b><span class="spacer"></span>
          ${a.installed ? `<a class="btn small" href="#apps/${a.installed}">${esc(t('Open'))}</a>${a.update ? `<button type="button" class="btn small primary" data-store-install>${esc(t('Update'))}</button>` : ''}<button type="button" class="btn small ghost" data-store-remove="${a.installed}">${esc(t('Uninstall'))}</button>`
            : `<button type="button" class="btn small primary" data-store-install>${esc(t('Install'))}</button>`}</div>
        <span class="muted small no-i18n">${esc(a.description || '')}</span>
        ${a.connectors?.length ? `<span class="small muted">${esc(t('Uses: {list}', { list: a.connectors.join(', ') }))}</span>` : ''}</div>`).join('')}</div>`
      : `<p class="muted">${esc(t('The store has no app yet.'))}</p>`);
  box.append(el);
  el.querySelectorAll('[data-store-install]').forEach(b => b.onclick = async () => {
    b.disabled = true;
    try { const { app } = await post('api/store/install?space=' + sp, { id: b.closest('[data-id]').dataset.id }); location.hash = '#apps/' + app.id; }
    catch (err) { toast(err.message); b.disabled = false; }
  });
  el.querySelectorAll('[data-store-remove]').forEach(b => b.onclick = async () => {
    if (!confirm(t('Uninstall this app? Its saved state goes with it.'))) return;
    try { await post(`api/apps/${b.dataset.storeRemove}/delete?space=${sp}`, {}); loadApps(); } catch (err) { toast(err.message); }
  });
}

/** Why there is no store here: on one's own server there is none; on a shared one, this assistant was made before it. */
const storeOff = st => `<p class="muted small">${esc(st?.shared
  ? t('The store is not set up on this assistant yet: it was made by an older version. Ask the admin to update the server again, or to choose “Rebuild the container” for this assistant on the admin page.')
  : t('The store is shared by the people of a server run from the admin page (install-stack.sh). This assistant runs on its own, so it has none: share apps as files instead.'))}</p>`;

/** Where something sent to the store stands. */
const storeStatus = it => it.status === 'approved' ? t('In the store') + (it.reason ? ' · ' + it.reason : '')
  : it.status === 'rejected' ? t('Not accepted') + (it.reason ? ': ' + it.reason : '') : t('Waiting for the admin’s review');

async function openApp(id) {
  const sp = state.space ?? '';
  const { app, needs = [] } = await api(`api/apps/${id}?space=${sp}`);
  $('#apps-list').hidden = true; $('#view-apps').classList.add('app-wide'); // an open app takes the whole page
  const box = $('#app-open'); box.hidden = false;
  const pending = app.status === 'pending';
  const src = s => ({ web: () => s.url, file: () => t('library file #{a}', { a: s.file }), script: () => t('script {a}', { a: s.script }),
    folder: () => t('the files of the folder {a}', { a: s.folder }), search: () => t('a search in the folder {a}', { a: s.folder }),
    mcp: () => t('the tool {a} of the connector {b}', { a: s.tool, b: s.server }), calendar: () => t('your calendar, {a} days ahead', { a: s.days }),
    contacts: () => t('your contacts'), tasks: () => t('the tasks of this space') }[s.type] || (() => s.type))();
  box.innerHTML = `<div class="row wrap app-bar"><a class="btn small ghost" href="#apps">← Apps</a>
      <h3 class="no-i18n">${esc(app.icon || '🧩')} ${esc(app.name)}</h3><span class="grow"></span>
      <button type="button" class="btn small ghost" id="app-share">Share</button>
      <button type="button" class="btn small ghost" id="app-to-store">Submit to the store</button>
      <button type="button" class="btn small ghost" id="app-edit">Change in the chat</button>
      <button type="button" class="btn small ghost danger" id="app-del">Delete</button></div>
    <div class="card app-review" id="app-share-box" hidden></div>
    ${appNeeds(app, needs)}
    ${pending ? `<div class="card app-review"><p><b>This app waits for your approval.</b> It gets no data until you approve it. It reads:</p>
      <ul>${(app.sources || []).map(s => `<li class="no-i18n"><code>${esc(s.name)}</code> — ${esc(src(s))}</li>`).join('') || `<li>${esc(t('no data source'))}</li>`}</ul>
      <details><summary>Show the code</summary><pre class="no-i18n">${esc(app.html)}</pre><pre class="no-i18n">${esc(app.script)}</pre></details>
      <p><button type="button" class="btn" id="app-approve">Approve</button></p></div>` : ''}
    <iframe class="app-frame" sandbox="allow-scripts allow-forms" referrerpolicy="no-referrer" title="${esc(app.name)}" src="api/apps/${id}/frame?space=${sp}"></iframe>`;
  appFrame = { id, el: box.querySelector('iframe') };
  appFrame.el.onload = () => appFrame?.el.contentWindow?.postMessage({ talixio: 1, theme: document.documentElement.dataset.theme === 'dark' || (!document.documentElement.dataset.theme && matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light' }, '*');
  $('#app-edit').onclick = () => { location.hash = '#chat'; $('#input').value = t('Change the app “{a}” (#{b}): ', { a: app.name, b: id }); $('#input').focus(); };
  $('#app-del').onclick = async () => {
    if (!confirm(t('Delete the app “{a}”?', { a: app.name }))) return;
    await post(`api/apps/${id}/delete?space=${sp}`).catch(e => toast(e.message));
    location.hash = '#apps';
  };
  box.querySelectorAll('[data-install]').forEach(b => b.onclick = () => {
    const n = needs.find(x => x.server === b.dataset.install), r = n.recipe || {};
    if (r.github) window.connName = n.server;
    installFromLink(r.github || r.url, n.server, r.headers).catch(e => toast(e.message));
  });
  box.querySelectorAll('[data-allow]').forEach(b => b.onclick = async () => {
    const n = needs.find(x => x.server === b.dataset.allow);
    try { await post('api/mcp/read-only', { server: n.server, tools: n.tools.map(x => x.tool), on: true }); openApp(id); } catch (e) { toast(e.message); }
  });
  $('#app-share').onclick = () => appShare(app, $('#app-share-box'));
  $('#app-to-store').onclick = () => appShare(app, $('#app-share-box'), true);
  if (pending) $('#app-approve').onclick = async () => { await post(`api/apps/${id}/approve?space=${sp}`).catch(e => toast(e.message)); openApp(id); };
}

/** What an app reads through connectors and is missing here: the connector (installed from what the package says) or the tick on its tools. */
function appNeeds(app, needs) {
  const o = app.origin || {}, dropped = o.dropped || [];
  const rows = needs.filter(n => !n.installed || n.tools.some(x => !x.allowed)).map(n => {
    const r = n.recipe || {}, tools = n.tools.map(x => x.tool).join(', ');
    if (n.installed) return `<li><span class="no-i18n"><b>${esc(n.server)}</b>: <code>${esc(tools)}</code></span> — ${esc(t('it reads with these tools; they must run without asking.'))}
      <button type="button" class="btn small" data-allow="${esc(n.server)}">Let them run without asking</button></li>`;
    return `<li><span class="no-i18n"><b>${esc(n.server)}</b>${r.description ? ' — ' + esc(r.description) : ''}</span> — ${esc(t('this connector is not installed here.'))}
      ${r.github || r.url ? `<button type="button" class="btn small" data-install="${esc(n.server)}">Install it</button>
        <span class="muted small">${esc(r.github ? t('from {a}', { a: r.github }) : t('at {a}; then sign in or fill its settings there', { a: r.url }))}${r.headers?.length ? ' (' + esc(r.headers.join(', ')) + ')' : ''}</span>`
        : `<span class="muted small">${esc(t('Install it by hand in Settings → Connectors, under this name.'))}</span>`}</li>`;
  });
  if (!rows.length && !dropped.length && !o.from) return '';
  return `<div class="card app-review">${o.from ? `<p class="muted small">${esc(t('Installed from {a}.', { a: o.from }))}</p>` : ''}
    ${rows.length ? `<p><b>Before it works here</b></p><ul>${rows.join('')}</ul>` : ''}
    ${dropped.length ? `<p class="small">${esc(t('Left out, not found in this library: {a}. Ask the assistant to point the app to another file.', { a: dropped.map(x => x.path || x.name).join(', ') }))}</p>` : ''}</div>`;
}

/** Share an app: to another of the person's assistants (sent from this page, like a space), or as a file for anyone. Never its data or keys. */
function appShare(app, el, storeOnly = false) {
  if (!el.hidden && el.dataset.storeOnly === String(storeOnly)) { el.hidden = true; return; }
  el.dataset.storeOnly = String(storeOnly);
  const list = state.assistants || [], sp = state.space ?? '';
  const file = pkg => ({ blob: new Blob([JSON.stringify(pkg, null, 2)], { type: 'application/json' }), name: (app.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'app') + '.talixio-app.json' });
  el.innerHTML = storeOnly ? `<div id="app-store-send"></div><p class="small" id="app-share-say" role="status"></p>` : `<p class="small">${esc(t('Shares the app’s code and what it reads, never its data, its saved state or a key. The other side approves it before it gets any data, and installs the connectors it uses with its own account.'))}</p>
    ${list.length ? `<form class="row wrap" id="app-send"><select id="app-send-to">${list.map(a => `<option value="${esc(a.id)}" class="no-i18n">${esc(a.name)}</option>`).join('')}</select>
      <button class="btn small">Copy to this assistant</button></form>` : ''}
    <p class="row wrap"><button type="button" class="btn small ghost" id="app-dl">Download it as a file</button>
      <span class="muted small">${esc(t('To give it to anyone: they add it with “Add an app from a file” in their Apps.'))}</span></p>
    <div id="app-store-send"></div>
    <p class="small" id="app-share-say" role="status"></p>`;
  el.hidden = false;
  api('api/store?space=' + sp).then(st => {
    const box = $('#app-store-send'); if (!box) return;
    if (!st.available) { box.innerHTML = `<p class="small"><b>${esc(t('Submit to the store'))}</b></p>${storeOff(st)}`; return; }
    const it = st.outbox.find(x => x.key === 'app-' + app.id);
    box.innerHTML = `<form class="row wrap" id="app-store-form"><input id="app-store-note" placeholder="${esc(t('A word for the admin (optional)'))}" maxlength="500">
      <button class="btn small">${esc(t(it ? 'Send this version to the store' : 'Submit to the store'))}</button></form>
      ${it ? `<p class="small muted">${esc(storeStatus(it))}</p>` : `<p class="small muted">${esc(t('Everyone on this server can install it once the admin approves it.'))}</p>`}`;
    $('#app-store-form').onsubmit = async e => {
      e.preventDefault();
      try { await post('api/store/submit?space=' + sp, { app: app.id, note: $('#app-store-note').value }); say(t('Sent: the admin reviews it, then it shows in everyone’s store.')); el.hidden = true; appShare(app, el, storeOnly); }
      catch (err) { say(err.message, true); }
    };
  }).catch(() => {});
  const say = (m, bad) => { const s = $('#app-share-say'); s.textContent = m; s.classList.toggle('bad', !!bad); };
  if (storeOnly) return;
  $('#app-dl').onclick = async () => { try { saveBlob(file((await api(`api/apps/${app.id}/package?space=${sp}`)).package)); } catch (e) { say(e.message, true); } };
  if (list.length) $('#app-send').onsubmit = async e => {
    e.preventDefault();
    const to = $('#app-send-to').value, name = list.find(a => a.id === to)?.name || to;
    try {
      say(t('Sending it to {name}…', { name }));
      const tk = await post(`api/apps/${app.id}/send-ticket?space=${sp}`, { to });
      let res;
      try { res = await fetch(tk.url + '?t=' + encodeURIComponent(tk.t), { method: 'POST', headers: { 'X-Talixio': '1', 'Content-Type': 'application/json' }, body: JSON.stringify(tk.package) }); }
      catch { throw new Error(t('{name} could not be reached from this page. Download the file and add it there instead.', { name })); }
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(t(data.error || `Request failed (${res.status})`));
      el.querySelector('#app-share-say').innerHTML = esc(t('Copied to {name}. Open it there to approve it and install what it needs.', { name })) + ` <button type="button" class="btn small" id="app-sent-open">${esc(t('Open {name}', { name }))}</button>`;
      $('#app-sent-open').onclick = () => openAssistant(to);
    } catch (err) { say(err.message, true); }
  };
}

window.addEventListener('message', async e => {
  const f = appFrame, m = e.data;
  if (!f || e.source !== f.el.contentWindow || !m || m.talixio !== 1 || !m.op) return;
  const reply = r => e.source.postMessage({ talixio: 1, id: m.id, ...r }, '*');
  const sp = state.space ?? '';
  if (m.op === 'open') { // a link in an app: a page of Talixio, or a web address the person agrees to open
    const h = String(m.href || '');
    if (/^#[a-z]+(\/[\w%.\-\/]*)?$/.test(h)) { location.hash = h; return reply({ result: true }); }
    let u = null; try { u = new URL(h); } catch {}
    if (!u || !/^https?:$/.test(u.protocol)) return reply({ error: 'only Talixio pages and web addresses can be opened' });
    if (!confirm(t('This app wants to open {host}. Open it?', { host: u.host }))) return reply({ result: false });
    window.open(u.href, '_blank', 'noopener,noreferrer'); return reply({ result: true });
  }
  if (m.op === 'download') { // a file the app made: offered here, as the sealed frame cannot download
    const name = String(m.name || 'download').replace(/[^\w. -]+/g, '_').slice(0, 80) || 'download', c = String(m.content || '');
    if (c.length > 20e6) return reply({ error: 'file too large' });
    const a = document.createElement('a');
    a.href = /^data:image\/png;base64,/.test(c) ? c : URL.createObjectURL(new Blob([c], { type: /^[\w.+-]+\/[\w.+-]+$/.test(m.mime || '') && !/html|xml|javascript/i.test(m.mime) ? m.mime : 'text/plain' }));
    a.download = name; document.body.append(a); a.click(); a.remove();
    return reply({ result: true });
  }
  try {
    const body = m.op === 'data' ? { name: String(m.name || ''), params: m.params || {} }
      : { key: String(m.key || ''), value: m.value ?? null, write: m.op === 'save' };
    const { result } = await post(`api/apps/${f.id}/${m.op === 'data' ? 'data' : 'state'}?space=${sp}`, body);
    reply({ result });
  } catch (err) { reply({ error: err.message }); }
});

// ---- Pilotage: the director's cockpit (agents · worksites · roles · knowledge) ----
const PILOT_TABS = ['agents', 'chantiers', 'roles', 'kb'];
function pilotTab() { try { const v = localStorage.getItem('pilotTab'); return PILOT_TABS.includes(v) ? v : 'agents'; } catch { return 'agents'; } }
function showPilotTab(tab) {
  if (!PILOT_TABS.includes(tab)) tab = 'agents';
  try { localStorage.setItem('pilotTab', tab); } catch {}
  document.querySelectorAll('#view-pilotage .ptab').forEach(b => b.setAttribute('aria-selected', b.dataset.ptab === tab ? 'true' : 'false'));
  document.querySelectorAll('#view-pilotage .pilot-panel').forEach(p => p.hidden = p.dataset.ppanel !== tab);
  ({ agents: pilotAgents, chantiers: pilotChantiers, roles: pilotRoles, kb: pilotKb }[tab])?.().catch(e => toast(e.message));
}
let pilotWired = false;
async function loadPilotage() {
  if (!pilotWired) {
    pilotWired = true;
    document.querySelectorAll('#view-pilotage .ptab').forEach(b => b.onclick = () => showPilotTab(b.dataset.ptab));
    const sendPilot = () => { // route the dictated directive through the ordinary chat send(), so the director uses its own fleet tools
      const src = $('#ptt-input'), text = src.value.trim();
      if (!text) { src.focus(); return; }
      $('#input').value = text; src.value = '';
      location.hash = '#chat';
      setTimeout(() => send(), 0);
    };
    $('#ptt-send').onclick = sendPilot;
    $('#ptt-input').addEventListener('keydown', e => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); sendPilot(); } });
  }
  showPilotTab(pilotTab());
}
async function pilotAgents() {
  const box = $('#pilot-agents');
  await initOrchestratorPanel('pilot', info => {
    const sum = (info.summary || '').trim();
    box.innerHTML = sum ? `<div class="doc">${md(sum)}</div>`
      : `<div class="placeholder">${esc(t('No fleet orchestrator is connected yet. Connect one above to run agents across your machines.'))}</div>`;
  });
}
async function pilotChantiers() {
  const box = $('#pilot-chantiers');
  try {
    const { directives } = await api('api/jobs');
    renderDirectives(directives || [], box, pilotChantiers);
  } catch (e) { box.innerHTML = `<div class="placeholder">${esc(e.message)}</div>`; }
}
const PILOT_TREND = { up: '↑', down: '↓', flat: '→', new: '·' };
function pRing(acc) { // acceptance 0..1 (or null) → a small donut showing the accept rate
  if (acc == null) return `<span class="pring none no-i18n" title="${esc(t('no rated runs yet'))}">—</span>`;
  const pct = Math.round(acc * 100), c = 2 * Math.PI * 15.9;
  return `<svg class="pring" viewBox="0 0 36 36" aria-hidden="true"><circle class="pring-bg" cx="18" cy="18" r="15.9"/>`
    + `<circle class="pring-fg" cx="18" cy="18" r="15.9" stroke-dasharray="${(pct / 100 * c).toFixed(1)} ${c.toFixed(1)}" transform="rotate(-90 18 18)"/>`
    + `<text class="pring-t no-i18n" x="18" y="22">${pct}</text></svg>`;
}
function pSpark(series) { // recent accepted (1) / rejected (0) runs, oldest→newest → a tiny bar strip
  if (!series || !series.length) return '';
  const w = 3, g = 1, h = 14, pts = series.slice(-24);
  const bars = pts.map((v, i) => `<rect x="${i * (w + g)}" y="${v ? 0 : h - 3}" width="${w}" height="${v ? h : 3}" class="${v ? 'sp1' : 'sp0'}"/>`).join('');
  const width = pts.length * (w + g) - g;
  return `<svg class="pspark" viewBox="0 0 ${width} ${h}" width="${width}" height="${h}" aria-hidden="true">${bars}</svg>`;
}
function proleCard(r) {
  const acc = r.acceptance == null ? t('no rated runs yet') : `${Math.round(r.acceptance * 100)}% ${t('accepted')}`;
  const chips = [];
  if (r.protected) chips.push(`<span class="chip proto">${esc(t('Protected'))}</span>`);
  if (r.published) chips.push(`<span class="chip schedule">${esc(t('Published'))}</span>`);
  chips.push(`<span class="chip tier no-i18n">${esc(r.tier)}</span>`);
  const trendTitle = { up: 'rising', down: 'falling', flat: 'steady', new: 'too new to tell' }[r.trend] || '';
  const trend = PILOT_TREND[r.trend] ? `<span class="ptrend t-${esc(r.trend)}" title="${esc(t(trendTitle))}">${PILOT_TREND[r.trend]}</span>` : '';
  const bits = [
    r.runs ? t('{n} runs', { n: r.runs }) : t('no runs yet'),
    r.cost_mean != null ? t('{c}/run', { c: money(r.cost_mean) }) : '',
    t('{n} cases', { n: r.cases }),
  ].filter(Boolean).join(' · ');
  const overlap = r.overlap && r.overlap.length
    ? `<p class="poverlap small">${esc(t('Overlaps {names}', { names: r.overlap.join(', ') }))}</p>` : '';
  const publish = r.protected ? '' : `<button class="btn ghost small" data-role-pub="${esc(r.name)}">${esc(t('Publish'))}</button>`;
  return `<div class="prole-card">
    <div class="prole-head">${pRing(r.acceptance)}<div class="prole-id"><b class="no-i18n">${esc(r.name)}</b> <span class="muted small no-i18n">v${r.version}</span><div class="prole-chips">${chips.join('')}</div></div></div>
    <p class="muted small prole-desc">${esc(r.description || '')}</p>
    <div class="prole-metrics small"><span>${esc(acc)}</span>${trend}<span class="muted">${esc(bits)}</span>${pSpark(r.series)}</div>
    ${overlap}
    <div class="prole-actions">
      <button class="btn ghost small" data-role-eval="${esc(r.name)}">${esc(t('Evaluate'))}</button>
      ${publish}
      <button class="btn ghost small" disabled title="${esc(t('Lands in a later slice.'))}">${esc(t('Improve'))}</button>
      <button class="btn ghost small" disabled title="${esc(t('Lands in a later slice.'))}">${esc(t('Archive'))}</button>
    </div>
  </div>`;
}
async function roleAction(btn, url) {
  btn.disabled = true;
  try { const r = await post(url, {}); toast(r.message || t('Done')); pilotRoles().catch(() => {}); }
  catch (e) { toast(e.message); }
  finally { btn.disabled = false; }
}
async function pilotRoles() {
  const box = $('#pilot-roles');
  const { roles } = await api('api/roles');
  if (!roles || !roles.length) { box.innerHTML = `<div class="placeholder">${esc(t('No roles yet. The director and the fleet seed roles as they work; they will appear here as a managed catalogue.'))}</div>`; return; }
  box.innerHTML = `<p class="muted small">${esc(t('The roles your assistant runs — the same registry the director and the fleet use, drawing the Assessor’s own numbers, so improving one improves everywhere.'))}</p>`
    + `<div class="prole-grid">${roles.map(proleCard).join('')}</div>`;
  box.querySelectorAll('[data-role-eval]').forEach(b => b.onclick = () => roleAction(b, `api/roles/${encodeURIComponent(b.dataset.roleEval)}/evaluate`));
  box.querySelectorAll('[data-role-pub]').forEach(b => b.onclick = () => {
    if (!confirm(t('Send this role to the store for review, so other assistants can install it?'))) return;
    roleAction(b, `api/roles/${encodeURIComponent(b.dataset.rolePub)}/publish`);
  });
}
async function pilotKb() {
  const box = $('#pilot-kb');
  const sp = state.spaces?.find(x => x.id === state.space);
  const folder = sp?.folder || '/';
  box.innerHTML = `<div class="card"><h3>${esc(t('Knowledge the director grounds on'))}</h3>`
    + `<p class="muted small">${esc(t('The space’s library is what the director and its roles read from. Deep edits stay in Library.'))}</p>`
    + `<p>${esc(t('Space'))}: <b class="no-i18n">${esc(sp?.name || '—')}</b> · ${esc(t('library root'))}: <code class="no-i18n">${esc(folder)}</code></p>`
    + `<p><a href="#library">${esc(t('Open the Library'))} ▸</a></p></div>`;
}

const VIEWS = { chat: null, notifications: loadNotifications, board: loadBoard, library: loadLibrary, tasks: loadTasks, mail: loadMail, people: loadPeople, jobs: loadJobs, apps: loadApps, memory: loadMemory, settings: loadSettings, activity: loadActivity, privacy: loadPrivacy, pilotage: loadPilotage };
function route() {
  const fm = location.hash.match(/^#file\/(\d+)(?:\/p(\d+))?$/); // a document link from a mail
  if (fm) { openCited(+fm[1], +fm[2] || 0); return; }
  const h = location.hash.slice(1) === 'channels' ? 'settings' : location.hash.slice(1).split('/')[0];
  const v = (h in VIEWS) ? h : 'chat';
  for (const k of Object.keys(VIEWS)) $('#view-' + k).hidden = k !== v;
  document.querySelectorAll('.rail a').forEach(a => a.classList.toggle('active', a.dataset.view === v));
  $('#more-btn').classList.toggle('active', ['notifications', 'jobs', 'apps', 'activity', 'settings', 'mail', 'people', 'tasks', 'privacy'].includes(v));
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

/** An assistant's badge: the initials of its identifier ("fred-pro" → FP) on a colour of its own, the same everywhere. */
function avatarOf(id) {
  const letters = String(id || '?').split(/[-_.\s]+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join('') || '?';
  let h = 0; for (const ch of String(id)) h = (h * 31 + ch.codePointAt(0)) % 360;
  return { letters, hue: h };
}
const avatarHtml = id => { const a = avatarOf(id); return `<span class="avatar no-i18n" style="--h:${a.hue}" aria-hidden="true">${esc(a.letters)}</span>`; };
const whoHtml = a => `<span class="who"><span class="name no-i18n">${esc(a.name)}</span><span class="aid no-i18n">${esc(a.id)}</span></span>`;
/** The person's other assistants (linked in the admin page): opened signed in, in this tab. The badge shows which one this is. */
function renderAssistants(list = [], me = {}) {
  const mark = $('#brand-mark');
  if (me.id) {
    const a = avatarOf(me.id); mark.classList.add('avatar'); mark.style.setProperty('--h', a.hue); mark.textContent = a.letters;
    mark.title = `${me.name} (${me.id})`;
  }
  mark.disabled = !list.length;
  if (list.length) mark.title = `${me.name || state.status?.name || ''}${me.id ? ` (${me.id})` : ''} · ${t('Switch assistant')}`;
  if (JSON.stringify(list) === JSON.stringify(state.assistants || [])) return;
  state.assistants = list;
  $('#assistant-btn').hidden = !list.length;
  const box = $('#more-assistants'); box.hidden = !list.length;
  box.innerHTML = list.map(a => `<button class="btn" type="button" data-to="${esc(a.id)}">${avatarHtml(a.id)}<span>${esc(t('Open'))} <span class="no-i18n">${esc(a.name)}</span> <span class="muted small no-i18n">${esc(a.id)}</span></span></button>`).join('');
  box.querySelectorAll('[data-to]').forEach(b => b.onclick = () => openAssistant(b.dataset.to));
}
async function openAssistant(id) {
  try { location.href = (await post('api/assistants/go', { to: id })).url; } catch (e) { toast(e.message); }
}
$('#assistant-btn').onclick = $('#brand-mark').onclick = e => {
  e.stopPropagation();
  const menu = $('#assistant-menu'), btn = $('#assistant-btn');
  if (!menu.hidden) { menu.hidden = true; btn.setAttribute('aria-expanded', 'false'); return; }
  const me = state.status?.assistant || {}, cur = { id: me.id || '', name: me.name || state.status?.name || '' };
  menu.innerHTML = `<button class="space-item current" role="menuitem" type="button" aria-current="true" disabled>${cur.id ? avatarHtml(cur.id) : ''}${whoHtml(cur)}</button>`
    + (state.assistants || []).map(a => `<button class="space-item" role="menuitem" type="button" data-to="${esc(a.id)}">${avatarHtml(a.id)}${whoHtml(a)}</button>`).join('');
  menu.querySelectorAll('[data-to]').forEach(b => b.onclick = () => { menu.hidden = true; openAssistant(b.dataset.to); });
  menu.hidden = false; btn.setAttribute('aria-expanded', 'true');
};
document.addEventListener('click', e => { if (!e.target.closest('.brand')) { $('#assistant-menu').hidden = true; $('#assistant-btn').setAttribute('aria-expanded', 'false'); } });

async function refreshStatus() {
  try {
    state.statusAt = Date.now();
    const s = state.status = await api('api/status');
    const bn = $('#brand-name'); bn.textContent = bn.title = s.name; bn.classList.toggle('long', s.name.length > 14); document.title = s.name;
    renderAssistants(s.assistants, s.assistant);
    const ch = Object.entries(s.channels).filter(([, on]) => on).map(([k]) => k[0].toUpperCase() + k.slice(1)).join(', ');
    const b = s.budget, lim = b.daily_limit > 0 ? ` · $${b.today.toFixed(2)} of $${b.daily_limit.toFixed(2)} today` : '';
    $('#chat-sub').textContent = `${shortModel(s.model)} · ${ch}${lim}${b.state === 'low' ? ' · budget low' : b.state === 'blocked' ? ' · budget reached' : ''}`;
    const st = $('#status'); st.className = 'status ' + (s.worker_online ? 'on' : 'off');
    st.querySelector('span').textContent = s.worker_online ? 'Running' : 'Worker offline';
    st.title = s.worker_online ? 'Background worker is running: schedules and chat apps work.' : 'Background worker is not running: schedules and Telegram are paused. Check: systemctl status talixio-worker';
    for (const el of [$('#app-version'), $('#more-version')]) { el.textContent = 'Talixio ' + s.version; el.title = s.version_label; }
    if (s.notifications_unread !== state.notifUnread) { state.notifUnread = s.notifications_unread; setBadge(); if (!$('#view-notifications').hidden) loadNotifications(); }
    $('#setup-model').hidden = !s.model_problem;
    $('#setup-claude').hidden = !s.claude_code;
    if (JSON.stringify(s.paused || null) !== $('#paused').dataset.p) { $('#paused').dataset.p = JSON.stringify(s.paused || null); renderPaused(s.paused); }
    updateDirBar(s.directives || []);
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
    const shown = Object.entries(results).filter(([, r]) => r === 'sent' || r.startsWith('error'));
    $('#test-result').innerHTML = shown.length ? shown.map(([k, r]) => `<div><span class="no-i18n">${esc(k)}:</span> <span>${esc(r === 'sent' ? 'sent' : r.replace(/^error: /, ''))}</span></div>`).join('')
      : esc('No notification channel is set up yet.');
    loadChannels();
  } catch (e) { $('#test-result').textContent = e.message; }
};
$('#search-form').onsubmit = e => {
  e.preventDefault();
  const q = $('#search-q').value.trim(); const box = $('#search-results');
  if (!q) { memSearchClear(); return; }
  $('#view-memory').classList.add('searching'); $('#mem-results').hidden = false;
  document.querySelectorAll('#view-memory .set-sec[data-sec]').forEach(s => { s.hidden = true; });
  const hit = r => `<div class="hit"><div class="meta"><span class="chip">${esc(r.label)}</span><span>${new Date(r.ts * 1000).toLocaleString(LOCALE, { dateStyle: 'medium', timeStyle: 'short' })}</span>${r.sim != null ? `<span>${Math.round(r.sim * 100)}% similar</span>` : ''}</div>${esc(r.text.slice(0, 600))}</div>`;
  twoPass('memory', 'api/search?q=' + encodeURIComponent(q), (results, phase, err) => {
    if (phase === 'error') { box.innerHTML = `<div class="placeholder">${esc(err.message)}</div>`; return; }
    if (!results) { box.innerHTML = searchWait('Searching…'); return; }
    box.innerHTML = (phase === 'meaning' ? searchWait('Searching by meaning…') : '') + results.map(hit).join('') + (phase === 'done' && !results.length ? '<div class="placeholder">Nothing found.</div>' : '');
  });
};
$('#search-q').addEventListener('input', e => { if (!e.target.value.trim() && !$('#mem-results').hidden) memSearchClear(); });
$('#search-q').addEventListener('keydown', e => { if (e.key === 'Escape') memSearchClear(); });
$('#search-clear').onclick = () => memSearchClear();
/** Real costs read from the providers' billing APIs (Admin keys): one line per source. */
function renderBilling(b) {
  const list = b?.sources || [];
  $('#billing').hidden = !list.length;
  $('#billing-list').innerHTML = list.map(s => `<li><b class="no-i18n">${esc(s.name)}</b>${s.scope ? ` <span class="muted no-i18n">(${esc(s.scope)})</span>` : ''}: `
    + (s.synced ? `$${s.month.toFixed(2)} ${esc(t('billed this month'))} · ${esc(t('read'))} ${fmtTime(s.synced)}` : esc(t('not read yet')))
    + (s.error ? ` · <span class="err">${esc(t('last try failed:'))} <span class="no-i18n">${esc(s.error)}</span></span>` : '') + '</li>').join('');
}
$('#billing-sync').onclick = async e => {
  e.target.disabled = true;
  try { renderBilling(await post('api/billing/sync', {})); loadActivity(); }
  catch (err) { toast(err.message); }
  finally { e.target.disabled = false; }
};
$('#bill-form').onsubmit = async e => {
  e.preventDefault();
  try { await post('api/bill', { month: $('#bill-month').value, amount: +$('#bill-amount').value, currency: $('#bill-currency').value }); $('#bill-amount').value = ''; loadActivity(); }
  catch (err) { toast(err.message); }
};
$('#skill-save').onclick = async () => {
  try { await post('api/skills/' + encodeURIComponent(state.skill), { content: $('#skill-editor').value }); $('#skill-state').textContent = 'Saved'; loadSkills(); }
  catch (e) { toast(e.message); }
};
$('#skill-cancel').onclick = () => { $('#skill-edit').hidden = true; };
$('#script-save').onclick = async () => {
  try {
    if (state.script) await post('api/scripts/' + encodeURIComponent(state.script), { content: $('#script-editor').value });
    else state.script = (await post('api/scripts', { content: $('#script-editor').value })).name; // a new one: named by its header
    $('#script-state').textContent = 'Saved'; loadScripts();
  } catch (e) { toast(e.message); }
};
$('#script-new').onclick = () => {
  state.script = null; $('#script-state').textContent = ''; $('#script-edit').hidden = false;
  $('#script-editor').value = `"""\nname: my-script\ndescription: ${t('What it does, so the assistant knows when to use it')}\nusage: my-script <in.csv> <out.csv>\n"""\nimport sys\n\n`;
  $('#script-editor').focus();
};
$('#script-upload').onchange = async e => {
  const f = e.target.files[0]; e.target.value = '';
  if (!f) return;
  try {
    const { name } = await post('api/scripts', { content: await f.text(), name: f.name.replace(/\.py$/i, '') });
    toast(t('Script {name} added', { name })); loadScripts();
  } catch (err) { toast(err.message); }
};
$('#script-cancel').onclick = () => { $('#script-edit').hidden = true; };
$('#mem-editor').addEventListener('input', () => {
  const dirty = $('#mem-editor').value !== (state.memFiles[state.memFile] ?? '');
  $('#mem-state').textContent = dirty ? 'Unsaved changes' : ''; $('#mem-revert').hidden = !dirty;
  if (state.memInfo.dirty !== dirty) { state.memInfo.dirty = dirty; renderMemNav(); }
});
$('#mem-revert').onclick = () => loadMemoryTab();
$('#mem-save').onclick = async () => {
  try {
    await post('api/memory', { file: state.memFile, content: $('#mem-editor').value });
    state.memFiles[state.memFile] = $('#mem-editor').value; $('#mem-state').textContent = 'Saved';
    $('#mem-revert').hidden = true; state.memInfo.dirty = false; renderMemNav();
  } catch (e) { toast(e.message); }
};
// tapping the Library tab while already in the library goes back to the top
document.querySelectorAll('.rail a[data-view=library]').forEach(a => a.addEventListener('click', e => {
  if (!$('#view-library').hidden) { e.preventDefault(); libGo('/'); }
}));
$('#lib-upload').onclick = () => $('#lib-upload-input').click();
$('#lib-upload-input').onchange = e => { libUpload([...e.target.files]); e.target.value = ''; };
$('#lib-newnote').onclick = newNote;
// mobile: one "+" opens every library action, so the toolbar is a single row
$('#lib-add').onclick = e => {
  e.stopPropagation();
  if (lib.readonly) return;
  openRowMenu(e.currentTarget, [
    { label: t('Upload'), run: () => $('#lib-upload-input').click() },
    { label: t('New note'), run: () => newNote() },
    { label: t('New folder'), run: () => $('#lib-newfolder').click() },
    { sep: true },
    { label: t('Shares'), run: () => openSharesManager() },
    { label: t('Recent changes'), run: () => showLibRecent() },
    { label: lib.trash ? t('Trash ({n})', { n: lib.trash }) : t('Trash'), run: () => showLibTrash() },
  ]);
};
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
  libRegex.on = false; libRegex.picked = new Set();
  $('#lib-results').hidden = true; $('#lib-results').innerHTML = ''; $('#lib-list').hidden = false;
}

// Power search by name with a regular expression; results carry checkboxes and bulk actions (move, delete, zip).
const libRegex = { on: false, q: '', folder: '/', files: [], folders: [], picked: new Set() };
async function libRegexSearch(q, folder) {
  libSearch.phase = ''; // not the keyword/meaning flow
  Object.assign(libRegex, { on: true, q, folder, files: [], folders: [], picked: new Set() });
  const box = $('#lib-results'); box.hidden = false; $('#lib-list').hidden = true;
  box.innerHTML = `<div class="search-head"><span class="small">${esc(t('Searching names for /{q}/…', { q }))}</span></div>`;
  try {
    const r = await api(`api/library/find-regex?q=${encodeURIComponent(q)}&folder=${encodeURIComponent(folder)}`);
    libRegex.files = r.files; libRegex.folders = r.folders; libRegexRender();
  } catch (err) { box.innerHTML = `<div class="search-head"><span class="small error">${esc(err.message)}</span><span class="spacer"></span><button class="btn small ghost" type="button" data-a="close">${esc(t('Close the search'))}</button></div>`; box.querySelector('[data-a=close]').onclick = libSearchClose; }
}
function libRegexRender() {
  const box = $('#lib-results'), { q, folder, files, folders, picked } = libRegex;
  box.hidden = false; $('#lib-list').hidden = true; box.innerHTML = '';
  const where = folder !== '/' ? ' ' + t('in {folder}', { folder }) : '';
  const n = files.length + folders.length;
  const head = document.createElement('div'); head.className = 'search-head';
  head.innerHTML = `<span class="small">${esc(n === 1 ? t('1 name matches /{q}/', { q }) : t('{n} names match /{q}/', { n, q }))}${esc(where)}</span><span class="spacer"></span>`
    + `<button class="btn small ghost" type="button" data-a="close">${esc(t('Close the search'))}</button>`;
  head.querySelector('[data-a=close]').onclick = libSearchClose;
  box.append(head);
  if (!n) { const p = document.createElement('div'); p.className = 'placeholder'; p.textContent = t('No name matches /{q}/', { q }); box.append(p); return; }
  // bulk action bar (shown when at least one file is ticked)
  const bar = document.createElement('div'); bar.className = 'bulk-bar'; bar.hidden = !picked.size;
  bar.innerHTML = `<label class="small"><input type="checkbox" id="rx-all"> ${esc(t('All'))}</label><span class="small bulk-n"></span><span class="spacer"></span>`
    + `<button class="btn small" type="button" data-a="move">${esc(t('Move to…'))}</button>`
    + `<a class="btn small" data-a="zip">${esc(t('Download zip'))}</a>`
    + `<button class="btn small ghost danger" type="button" data-a="del">${esc(t('Delete'))}</button>`;
  box.append(bar);
  const refreshBar = () => {
    bar.hidden = !picked.size; bar.querySelector('.bulk-n').textContent = picked.size ? t('{n} selected', { n: picked.size }) : '';
    const all = bar.querySelector('#rx-all'); all.checked = picked.size === files.length && files.length > 0; all.indeterminate = picked.size > 0 && picked.size < files.length;
    const zip = bar.querySelector('[data-a=zip]'); zip.href = `api/library/zip?folder=/&ids=${[...picked].join(',')}`; zip.setAttribute('download', 'library-selection.zip');
  };
  bar.querySelector('#rx-all').onchange = e => { picked.clear(); if (e.target.checked) for (const f of files) picked.add(f.id); box.querySelectorAll('.rx-pick').forEach(c => c.checked = e.target.checked); refreshBar(); };
  bar.querySelector('[data-a=move]').onclick = () => libInline('/', t('Move {n} files to which folder?', { n: picked.size }), async v => {
    const r = await post('api/files/bulk', { ids: [...picked], action: 'move', folder: v });
    toast(t('{n} moved', { n: r.done }) + (r.failed?.length ? ' · ' + r.failed.length + ' ' + t('failed') : '')); libRegexSearch(q, folder); loadLibrary();
  }, true);
  bar.querySelector('[data-a=del]').onclick = async ev => {
    const b = ev.currentTarget;
    if (b.dataset.c !== '1') { b.dataset.c = '1'; b.textContent = t('Delete {n}?', { n: picked.size }); return; }
    try { const r = await post('api/files/bulk', { ids: [...picked], action: 'delete' }); toast(t('{n} moved to the trash', { n: r.done })); libRegexSearch(q, folder); loadLibrary(); } catch (e) { toast(e.message); }
  };
  if (folders.length) {
    const sub = document.createElement('div'); sub.className = 'search-sub muted small'; sub.textContent = t('Folders'); box.append(sub);
    const list = document.createElement('div'); list.className = 'lib-list';
    for (const d of folders) {
      const el = document.createElement('a'); el.href = '#'; el.className = 'lib-row';
      el.innerHTML = `<span class="ico folder">${FOLDER_SVG}</span><span class="name"><b>${esc(d.path.split('/').pop())}</b><span class="snippet">${esc(d.path)} · ${esc(d.total === 1 ? t('1 file') : t('{n} files', { n: d.total }))}</span></span>`;
      el.onclick = e => { e.preventDefault(); libSearchClose(); libGo(d.path); };
      list.append(el);
    }
    box.append(list);
  }
  if (files.length) {
    const sub = document.createElement('div'); sub.className = 'search-sub muted small'; sub.textContent = t('Files'); box.append(sub);
    const list = document.createElement('div'); list.className = 'lib-list';
    for (const f of files) {
      const wrap = document.createElement('div'); wrap.className = 'rx-item';
      const cb = document.createElement('input'); cb.type = 'checkbox'; cb.className = 'rx-pick'; cb.checked = picked.has(f.id);
      cb.onclick = e => { e.stopPropagation(); cb.checked ? picked.add(f.id) : picked.delete(f.id); refreshBar(); };
      const row = fileRow({ ...f, created: 0, status: 'ready' }, joinPath(f.folder, f.name));
      wrap.append(cb, row); list.append(wrap);
    }
    box.append(list);
  }
  refreshBar();
}
$('#lib-search').onsubmit = async e => {
  e.preventDefault();
  libSearch.ctl?.abort(); libSearch.ctl = null; // a new search replaces the running one
  const q = $('#lib-q').value.trim();
  if (!q) return libSearchClose();
  const folder = $('#lib-scope').checked ? lib.folder : '/';
  if ($('#lib-regex').checked) return libRegexSearch(q, folder);
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
  if (e.key !== 'Escape' || (!libSearch.phase && !libRegex.on) || $('#view-library').hidden || !$('#viewer').hidden || document.querySelector('dialog[open]')) return;
  e.preventDefault(); libSearch.ctl ? libSearchStop() : libSearchClose();
});
$('#viewer-close').onclick = closeViewer;
// on a phone only the main actions show; "More" unfolds the rest
function moreActions(open) {
  $('.viewer-actions').classList.toggle('open', open);
  $('#viewer-more').setAttribute('aria-expanded', String(open));
  $('#viewer-more').textContent = open ? t('Less') : t('More') + ' ▾';
}
$('#viewer-more').onclick = () => moreActions(!$('.viewer-actions').classList.contains('open'));
// capture: seen before the Markdown editor closes its own menu, which is all Esc does then
const MD_MENUS = ['slash-menu', 'toolbar', 'link-edit', 'link-preview'].map(c => `#viewer .milkdown-${c}[data-show=true]`).join() + ',#viewer .top-bar-heading-dropdown';
let mdMenuOpen = false;
window.addEventListener('keydown', e => { mdMenuOpen = e.key === 'Escape' && !!$(MD_MENUS); }, true);
document.addEventListener('keydown', e => { if (e.key === 'Escape' && !$('#viewer').hidden && !mdMenuOpen) $('#viewer').classList.contains('full') ? fullViewer(false) : closeViewer(); });
$('#viewer-form-cancel').onclick = () => { $('#viewer-form').hidden = true; };
$('#viewer-rename').onclick = () => viewerForm(lib.file.name, async v => { const d = await withReplace(r => post(`api/files/${lib.file.id}/rename`, { name: v, ...r })); if (!d) return; await loadLibrary(); openFile(d.file.id); });
$('#viewer-move').onclick = () => viewerForm(lib.file.folder, async v => { const d = await withReplace(r => post(`api/files/${lib.file.id}/move`, { folder: v, ...r })); if (!d) return; toast('Moved to ' + d.file.folder); await loadLibrary(); openFile(d.file.id); }, true);
/**
 * A rename or move onto a name already used there: the server says so (409), the user chooses.
 * Replace: that file gets this content (what it held becomes a version). Keep both: this one is named "name (2)".
 * Resolves to the answer, or null when the user cancels.
 */
async function withReplace(send) {
  try { return await send({}); } catch (e) {
    if (e.status !== 409 || !e.data?.conflict) throw e;
    const choice = await chooseReplace();
    return choice === null ? null : send({ replace: choice });
  }
}
function chooseReplace() {
  return new Promise(resolve => {
    const d = document.createElement('dialog'); d.className = 'dialog'; d.setAttribute('aria-labelledby', 'rp-title');
    d.innerHTML = `<h3 id="rp-title">${esc(t('Replace or keep both?'))}</h3><p>${esc(t('Another file there already has this name.'))}</p>`
      + `<p class="muted small">${esc(t('Replace: that file gets this content, and what it held stays available in its Versions for 30 days. Keep both: this one gets a number, like “name (2)”.'))}</p>`
      + `<div class="row"><button class="btn primary" data-c="1" type="button">${esc(t('Replace'))}</button><button class="btn" data-c="0" type="button">${esc(t('Keep both'))}</button><span class="spacer"></span><button class="btn ghost" data-c="" type="button">${esc(t('Cancel'))}</button></div>`;
    let answer = null;
    d.querySelectorAll('[data-c]').forEach(b => b.onclick = () => { answer = b.dataset.c === '' ? null : b.dataset.c === '1'; d.close(); });
    d.addEventListener('close', () => { d.remove(); resolve(answer); });
    document.body.append(d); d.showModal();
  });
}
/** Earlier contents of the file open in the viewer, kept 30 days: download one, or bring it back. */
async function showVersions(f, ro) {
  const box = $('#viewer-versions'); if (!box) return;
  let list;
  try { ({ versions: list } = await api(`api/files/${f.id}/versions`)); } catch { return; }
  if (lib.file?.id !== f.id || !list.length) return;
  box.hidden = false;
  box.innerHTML = `<summary>${esc(t('Versions'))} (${list.length})</summary><p class="muted small">${esc(t('Earlier contents of this file, kept 30 days after it was replaced.'))}</p>`
    + list.map(v => `<div class="version-row"><span>${esc(new Date(v.created * 1000).toLocaleString(LOCALE, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }))} · ${fmtSize(v.size)}</span><span class="spacer"></span>`
      + `<a class="btn small ghost" href="api/files/${f.id}/versions/${v.id}/file" download>${esc(t('Download'))}</a>`
      + (ro ? '' : `<button class="btn small" data-v="${v.id}" type="button">${esc(t('Restore'))}</button>`) + '</div>').join('');
  box.querySelectorAll('[data-v]').forEach(b => b.onclick = async () => {
    try { await post(`api/files/${f.id}/versions/${b.dataset.v}/restore`); toast(t('Version restored; the content it replaced is now a version too')); await loadLibrary(); openFile(f.id); }
    catch (e) { toast(e.message); }
  });
}
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
  setTimeout(() => { autosize(); $('#input').focus(); }, 50); // the document is attached: the question is enough
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

/** Badges: unread notifications in the menu; on the app icon, those plus the approvals waiting. */
function setBadge() {
  const u = state.notifUnread || 0, n = u + (state.pendingApprovals || 0);
  for (const id of ['#notif-count', '#more-count', '#more-notif-count']) { const b = $(id); b.textContent = u > 99 ? '99+' : u; b.hidden = !u; }
  if (!('setAppBadge' in navigator)) return;
  (n > 0 ? navigator.setAppBadge(n) : navigator.clearAppBadge()).catch(() => {});
}

// ---- notifications: everything sent to the phone and the other channels, kept in the app ----
async function loadNotifications() {
  const d = await api('api/notifications');
  state.notifUnread = d.unread; setBadge();
  navigator.serviceWorker?.getRegistration().then(r => r?.getNotifications()).then(ns => (ns || []).forEach(n => n.close())).catch(() => {}); // seen here: off the lock screen
  const list = $('#notif-list'); list.innerHTML = '';
  $('#notif-empty').hidden = d.notifications.length > 0;
  $('#notif-all-read').disabled = !d.unread; $('#notif-clear').hidden = !d.notifications.length;
  for (const n of d.notifications) {
    const sp = n.space ? state.spaces.find(x => x.id === n.space) : null;
    const li = document.createElement('li'); li.className = 'notif' + (n.read_at ? '' : ' unread');
    li.innerHTML = `<a href="${esc(n.url)}"><div class="notif-head">${sp ? `<span class="notif-space no-i18n"><i style="background:${esc(sp.color || 'var(--muted)')}"></i>${esc(sp.name)}</span>` : ''}<b class="no-i18n">${esc(n.title)}</b><time datetime="${new Date(n.created * 1000).toISOString()}">${fmtTime(n.created)}</time></div><div class="notif-body md no-i18n">${md(n.body)}</div></a>`;
    li.querySelector('a').addEventListener('click', () => { if (!n.read_at) post('api/notifications/read', { id: n.id }).then(r => { state.notifUnread = r.unread; setBadge(); }).catch(() => {}); });
    list.append(li);
  }
}
$('#notif-all-read').onclick = async () => { try { const r = await post('api/notifications/read'); state.notifUnread = r.unread; await loadNotifications(); } catch (e) { toast(e.message); } };
$('#notif-clear').onclick = async () => {
  const b = $('#notif-clear');
  if (!b.dataset.sure) { b.dataset.sure = '1'; b.textContent = 'Clear all?'; setTimeout(() => { delete b.dataset.sure; b.textContent = 'Clear'; }, 4000); return; }
  delete b.dataset.sure; b.textContent = 'Clear';
  try { await post('api/notifications/clear'); state.notifUnread = 0; await loadNotifications(); } catch (e) { toast(e.message); }
};

/** Shared from another app, or launched from a shortcut. */
async function handleLaunch() {
  const params = new URLSearchParams(location.search);
  const token = params.get('share');
  if (params.has('google')) {
    const g = params.get('google');
    history.replaceState(null, '', './#settings');
    toast(g === 'connected' ? 'Google Calendar connected.' : g);
    if (g === 'connected') setTimeout(() => { showSection('accounts'); $('#google-card').scrollIntoView({ block: 'center' }); }, 600);
  }
  if (params.has('microsoft')) {
    const g = params.get('microsoft');
    history.replaceState(null, '', './#settings');
    const forMail = params.get('for');
    toast(g !== 'connected' ? g : forMail ? 'Microsoft account connected for mail.' : 'Outlook connected.');
    if (g === 'connected') setTimeout(() => {
      const group = state.setGroups?.find(x => x.id === forMail);
      showSection(group ? group.section : 'accounts');
      (document.querySelector(`.ms-mail[data-purpose="${forMail}"]`) || $('#microsoft-card')).scrollIntoView({ block: 'center' });
    }, 600);
  }
  if (params.has('mcp')) {
    const g = params.get('mcp');
    history.replaceState(null, '', './#settings');
    toast(g.startsWith('connected:') ? `Connected to ${g.slice(10)}: its tools are available from the next message.` : g);
    setTimeout(() => { showSection('accounts'); $('#mcp-card').scrollIntoView({ block: 'center' }); }, 600);
  }
  if (params.has('spotify')) {
    const g = params.get('spotify');
    history.replaceState(null, '', './#settings');
    toast(g === 'connected' ? 'Spotify connected.' : g);
    setTimeout(() => { showSection('accounts'); $('#spotify-card').scrollIntoView({ block: 'center' }); }, 600);
  }
  if (params.has('connector')) { // "▶ Install" in the FAQ connector list (opens here in a new tab)
    history.replaceState(null, '', location.pathname + location.hash);
    installFromLink(params.get('connector'), params.get('name'), (params.get('headers') || '').split(',').filter(Boolean)).catch(e => toast(e.message));
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
