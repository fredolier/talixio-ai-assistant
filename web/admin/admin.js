'use strict';
// Talixio admin page: people's assistants (containers), updates, shared settings. Never their content.
const $ = s => document.querySelector(s);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
async function api(path, body) {
  const r = await fetch(path, { method: body ? 'POST' : 'GET', headers: { 'Content-Type': 'application/json', 'X-Talixio': '1' }, body: body ? JSON.stringify(body) : undefined, credentials: 'same-origin' });
  let d = {}; try { d = await r.json(); } catch {}
  if (r.status === 401 && !path.endsWith('login') && !path.endsWith('setup')) { showGate(); }
  if (!r.ok) throw Object.assign(new Error(d.error || `Error ${r.status}`), { status: r.status });
  return d;
}
function toast(t) { const el = $('#toast'); el.textContent = t; el.hidden = false; clearTimeout(toast.t); toast.t = setTimeout(() => el.hidden = true, 4000); }
const ago = ts => { if (!ts) return '—'; const s = Date.now() / 1000 - ts; return s < 90 ? t('just now') : s < 5400 ? t('{n} min ago', { n: Math.round(s / 60) }) : s < 129600 ? t('{n} h ago', { n: Math.round(s / 3600) }) : t('{n} days ago', { n: Math.round(s / 86400) }); };
const when = ts => new Date(ts * 1000).toLocaleString(LOCALE, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

/** A small dialog. fields: [{id, label, type, value, placeholder}], or html. Resolves with values or null. */
function ask(title, { html = '', fields = [], ok = 'OK', danger = false, cancel = true } = {}) {
  const d = $('#dlg');
  $('#dlg-body').innerHTML = `<h3>${esc(title)}</h3>${html}` + fields.map(f => f.type === 'checkbox'
    ? `<label class="check"><input id="f-${f.id}" type="checkbox"${f.value ? ' checked' : ''}> <span>${f.label}</span></label>`
    : `<label>${f.label}<input id="f-${f.id}" type="${f.type || 'text'}" value="${esc(f.value ?? '')}" placeholder="${esc(f.placeholder ?? '')}"${f.attrs || ''}></label>`).join('');
  $('#dlg-ok').textContent = ok; $('#dlg-ok').className = 'btn ' + (danger ? 'danger' : 'primary'); $('#dlg-cancel').hidden = !cancel;
  return new Promise(res => {
    $('#dlg-cancel').onclick = () => { d.close(); res(null); };
    $('#dlg-form').onsubmit = e => { e.preventDefault(); const v = {}; for (const f of fields) { const el = $('#f-' + f.id); v[f.id] = f.type === 'checkbox' ? el.checked : el.value.trim(); } d.close(); res(v); };
    d.showModal(); d.querySelector('input')?.focus();
  });
}

// ---- sign in / first setup ----
async function showGate() {
  $('#app').hidden = true; $('#gate').hidden = false;
  const me = await api('api/me');
  if (me.signed_in) return start();
  $('#login').hidden = !me.configured; $('#setup').hidden = me.configured;
  (me.configured ? $('#l-pw') : $('#s-code')).focus();
}
$('#login').onsubmit = async e => {
  e.preventDefault(); $('#l-err').textContent = '';
  try { await api('api/login', { password: $('#l-pw').value, code: $('#l-code').value }); $('#l-pw').value = $('#l-code').value = ''; start(); }
  catch (err) { $('#l-err').textContent = err.message; }
};
$('#setup').onsubmit = async e => {
  e.preventDefault(); $('#s-err').textContent = '';
  try {
    if ($('#s-step2').hidden) {
      const d = await api('api/setup', { setup_code: $('#s-code').value });
      $('#s-secret').textContent = d.secret.replace(/(.{4})/g, '$1 ').trim();
      const qr = qrcode(0, 'M'); qr.addData(d.uri); qr.make(); $('#s-qr').innerHTML = qr.createSvgTag({ cellSize: 4, margin: 4, scalable: true });
      $('#s-step2').hidden = false; $('#s-btn').textContent = 'Finish'; $('#s-pw').focus();
    } else {
      await api('api/setup', { setup_code: $('#s-code').value, password: $('#s-pw').value, code: $('#s-totp').value });
      start();
    }
  } catch (err) { $('#s-err').textContent = err.message; }
};
$('#logout').onclick = async () => { await api('api/logout', {}); location.reload(); };

// ---- main ----
let state = null;
document.querySelectorAll('.tabs button').forEach(b => b.onclick = () => {
  document.querySelectorAll('.tabs button').forEach(x => x.classList.toggle('on', x === b));
  document.querySelectorAll('.tab').forEach(t => t.hidden = t.id !== 'tab-' + b.dataset.tab);
  if (b.dataset.tab === 'activity') loadAudit();
});

async function start() {
  $('#gate').hidden = true; $('#app').hidden = false;
  await load();
  clearInterval(start.t); start.t = setInterval(() => load().catch(() => {}), 20000);
}

async function load() {
  state = await api('api/state');
  const s = state.server, m = s.memory_mb || {};
  $('#server').innerHTML = `Memory ${m.MemTotal ? `${((m.MemTotal - m.MemAvailable) / 1024).toFixed(1)} / ${(m.MemTotal / 1024).toFixed(1)} GB used` : '?'}`
    + (s.disk ? ` · Disk ${s.disk.free_gb} GB free of ${s.disk.total_gb}` : '');
  renderPeople(); renderSettings(); renderJob(state.job);
  const img = s.image || {};
  $('#version').textContent = img.built ? `Running ${img.built} (branch ${img.branch || '?'}, built ${when(img.built_at)}).` : 'Running the image built at installation.';
  $('#rollback-btn').disabled = !img.previous;
  $('#ips-list').value = (state.allow_ips || []).join('\n'); $('#my-ip').textContent = state.my_ip || '';
}

function renderPeople() {
  const box = $('#people'), list = state.instances;
  if (!list.length) { box.innerHTML = '<p class="muted">No one yet. Add the first person.</p>'; return; }
  box.innerHTML = '';
  for (const p of list) {
    const el = document.createElement('div'); el.className = 'person';
    const ok = p.state === 'running' && p.health !== 'unhealthy';
    el.innerHTML = `<div class="who"><b>${esc(p.name || p.id)}</b> <span class="muted">${esc(p.id)}${p.email ? ' · ' + esc(p.email) : ''}</span><br>
        <a href="${esc(p.url)}" target="_blank" rel="noopener" class="small">${esc(p.url)}</a></div>
      <div class="st"><span class="pill ${ok ? 'on' : p.state === 'exited' ? 'off' : 'warn'}">${esc(p.state === 'running' ? (p.health || 'running') : p.state)}</span></div>
      <div class="small"><div>${p.mem_used_mb != null ? p.mem_used_mb + ' MB' : '—'} <span class="muted">/ ${p.memory_mb} MB · ${p.cpus} CPU</span></div><div class="muted">${p.last_activity ? 'active ' + ago(p.last_activity) : (p.state === 'running' ? 'not used for hours' : '')}</div></div>
      <div class="acts"><select aria-label="Action"><option value="">Actions…</option>
        ${p.state === 'running' ? '<option value="restart">Restart</option><option value="stop">Stop</option>' : '<option value="start">Start</option>'}
        <option value="reset-password">New password (forgotten)</option><option value="reset-totp">Turn off two-step sign-in (lost phone)</option>
        <option value="limits">Memory and CPU…</option><option value="doctor">Check (doctor)</option><option value="backup">Back up now</option>
        <option value="recreate">Rebuild the container</option><option value="remove">Remove…</option></select></div>`;
    el.querySelector('select').onchange = e => { const a = e.target.value; e.target.value = ''; if (a) act(p, a); };
    box.append(el);
  }
}

async function act(p, a) {
  const run = async (body = {}) => { toast('Working…'); return api(`api/instances/${p.id}/${a}`, body); };
  try {
    if (a === 'reset-password') {
      if (!await ask(`New password for ${p.name || p.id}?`, { html: '<p>Their assistant restarts with a new password; every device signs in again. Their data is untouched.</p>', ok: 'Make a new password' })) return;
      const r = await run();
      await ask('New password', { html: `<p>Give it to ${esc(p.name || p.id)} (shown only now):</p><p class="secret"><code>${esc(r.password)}</code></p><p class="muted small">They can change it in Settings.</p>`, cancel: false });
    } else if (a === 'reset-totp') {
      if (!await ask('Turn off two-step sign-in?', { html: '<p>For a lost phone: they sign in with their password only, then turn it on again in Settings.</p>', ok: 'Turn it off' })) return;
      await run(); toast('Two-step sign-in is off for ' + p.id);
    } else if (a === 'limits') {
      const v = await ask(`Limits for ${p.id}`, { fields: [{ id: 'memory_mb', label: 'Memory (MB)', type: 'number', value: p.memory_mb }, { id: 'cpus', label: 'CPUs', type: 'number', value: p.cpus, attrs: ' step="0.25"' }], ok: 'Apply (restarts it)' });
      if (!v) return; await run({ memory_mb: +v.memory_mb, cpus: +v.cpus }); toast('Applied');
    } else if (a === 'doctor' || a === 'backup') {
      const r = await run();
      await ask(a === 'doctor' ? `Check of ${p.id}` : 'Backup', { html: `<pre class="out">${esc(r.text)}</pre>`, cancel: false });
    } else if (a === 'remove') {
      const v = await ask(`Remove ${p.name || p.id}?`, { danger: true, ok: 'Remove',
        html: '<p>Their assistant stops and disappears from the server.</p>',
        fields: [{ id: 'delete_data', label: '<b>Also delete their data</b> (conversations, library, memory, keys). Cannot be undone. Unticked, the data stays on the server and the person can be added back with the same identifier.', type: 'checkbox' },
                 { id: 'confirm', label: `Type <b>${esc(p.id)}</b> to confirm`, placeholder: p.id }] });
      if (!v) return; await run(v); toast(p.id + ' removed');
    } else if (a === 'stop' || a === 'recreate') {
      if (!await ask(a === 'stop' ? `Stop ${p.id}?` : `Rebuild ${p.id}'s container?`, { html: a === 'stop' ? '<p>Their assistant will be unreachable (and its reminders paused) until you start it again.</p>' : '<p>Made again from the current image and settings. Their data is kept.</p>' })) return;
      await run(); toast('Done');
    } else { await run(); toast('Done'); }
  } catch (e) { toast(e.message); }
  load().catch(() => {});
}

$('#new-btn').onclick = async () => {
  const st = state.settings;
  const v = await ask('New person', { ok: 'Create', fields: [
    { id: 'id', label: 'Identifier (in their address: lower case, e.g. alice)', placeholder: 'alice', attrs: ' pattern="[a-z][a-z0-9-]{1,29}" required' },
    { id: 'name', label: 'Name', placeholder: 'Alice Martin' }, { id: 'email', label: 'Email (for you)', type: 'email' },
    { id: 'memory_mb', label: 'Memory (MB)', type: 'number', value: st.memory_mb }, { id: 'cpus', label: 'CPUs', type: 'number', value: st.cpus, attrs: ' step="0.25"' }] });
  if (!v) return;
  try {
    toast('Creating… (a few seconds)');
    const r = await api('api/instances', { ...v, memory_mb: +v.memory_mb, cpus: +v.cpus });
    await ask(`${v.name || v.id}'s assistant is ready`, { cancel: false, html: `<p>Send them:</p><p>Address: <a href="${esc(r.url)}" target="_blank">${esc(r.url)}</a></p><p class="secret">Password: <code>${esc(r.password)}</code></p>
      <p class="muted small">Shown only now. They add their own API keys in Settings (their costs are theirs), and can change the password and turn on two-step sign-in there.</p>` });
    load();
  } catch (e) { toast(e.message); }
};

// ---- updates ----
function renderJob(j) {
  const box = $('#job');
  if (!j || j.state === 'none') { box.hidden = true; return; }
  box.hidden = false;
  box.className = 'job ' + j.state;
  box.innerHTML = `<b>${esc({ update: 'Update', rollback: 'Back to the previous version', 'pull-model': 'Model download' }[j.kind] || j.kind)}: ${esc(j.state === 'running' ? 'in progress' : j.state)}</b><p>${esc(j.message || '')}</p>`
    + (j.log?.length ? `<pre class="out">${esc(j.log.join('\n'))}</pre>` : '');
  $('#update-btn').disabled = $('#rollback-btn').disabled = j.state === 'running';
  clearTimeout(renderJob.t);
  if (j.state === 'running') renderJob.t = setTimeout(async () => { try { renderJob(await api('api/job')); } catch {} }, 3000);
}
$('#update-btn').onclick = async () => {
  if (!await ask('Update everyone?', { html: `<p>Builds branch <b>${esc($('#branch').value)}</b> (several minutes), then restarts each assistant on it, one by one.</p>`, ok: 'Update' })) return;
  try { await api('api/update', { branch: $('#branch').value.trim() || 'main' }); renderJob(await api('api/job')); } catch (e) { toast(e.message); }
};
$('#rollback-btn').onclick = async () => {
  if (!await ask('Go back to the previous version?', { ok: 'Go back' })) return;
  try { await api('api/rollback', {}); renderJob(await api('api/job')); } catch (e) { toast(e.message); }
};

// ---- settings ----
function renderSettings() {
  const s = state.settings;
  if (document.activeElement?.closest('#settings')) return; // don't overwrite what is being typed
  $('#st-domain').value = s.domain; document.querySelectorAll('[name=mode]').forEach(r => r.checked = r.value === s.mode);
  $('#st-mem').value = s.memory_mb; $('#st-cpus').value = s.cpus; $('#st-ollama').checked = !!s.ollama; $('#st-model').value = s.ollama_model;
  $('#st-gid').value = s.google_client_id; $('#st-apparmor').checked = !!s.apparmor_unconfined;
  $('#st-redirects').textContent = state.instances.map(p => p.url + 'api/google/callback').join('\n') || '(add people first)';
}
$('#settings').onsubmit = async e => {
  e.preventDefault();
  const body = { domain: $('#st-domain').value.trim(), mode: document.querySelector('[name=mode]:checked')?.value, memory_mb: +$('#st-mem').value, cpus: +$('#st-cpus').value,
    ollama: $('#st-ollama').checked, ollama_model: $('#st-model').value.trim(), google_client_id: $('#st-gid').value.trim(), apparmor_unconfined: $('#st-apparmor').checked };
  if ($('#st-gsecret').value) body.google_client_secret = $('#st-gsecret').value;
  $('#st-msg').textContent = 'Saving…';
  try { const r = await api('api/settings', body); $('#st-msg').textContent = r.recreated ? 'Saved; the assistants were restarted.' : 'Saved.'; $('#st-gsecret').value = ''; document.activeElement.blur(); load(); }
  catch (err) { $('#st-msg').textContent = err.message; }
};
$('#pull-btn').onclick = async () => { try { await api('api/pull-model', {}); document.querySelector('[data-tab=update]').click(); renderJob(await api('api/job')); } catch (e) { toast(e.message); } };

// ---- security ----
$('#ips').onsubmit = async e => { e.preventDefault(); try { await api('api/allow-ips', { list: $('#ips-list').value }); $('#ips-msg').textContent = 'Saved.'; } catch (err) { $('#ips-msg').textContent = err.message; } };
$('#pw').onsubmit = async e => {
  e.preventDefault();
  try { await api('api/password', { current: $('#pw-cur').value, new: $('#pw-new').value, code: $('#pw-code').value }); $('#pw-msg').textContent = 'Changed: sign in again.'; setTimeout(() => location.reload(), 1500); }
  catch (err) { $('#pw-msg').textContent = err.message; }
};
async function loadAudit() {
  const { entries } = await api('api/audit');
  $('#audit').innerHTML = entries.length ? entries.map(a => `<div><span class="muted">${when(a.ts)}</span> <b>${esc(a.what)}</b> ${esc(a.id || '')} <span class="muted small">${esc(a.ip)}</span></div>`).join('') : '<p class="muted">Nothing yet.</p>';
}

showGate().catch(e => { document.body.textContent = e.message; });
