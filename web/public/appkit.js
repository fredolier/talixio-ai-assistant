/* The kit every app in the Apps menu is built with. It runs inside the app's sandboxed frame (no network, no session):
   data comes only through talixio.data(), answered by the Talixio page around it from the sources the app declared. */
(() => {
  'use strict';
  const css = `
:root{--bg:#f2f5f4;--surface:#fff;--ink:#14201d;--muted:#5b6a66;--line:#d9e2df;--accent:#0e7c6b;--danger:#b42318;
  --c1:#0e7c6b;--c2:#c27c0e;--c3:#2f6fb0;--c4:#a8457a;--c5:#6b7a76;color-scheme:light}
:root[data-theme=dark]{--bg:#0d1412;--surface:#141e1b;--ink:#e4ebe8;--muted:#94a49f;--line:#24322e;--accent:#3cc2a8;--danger:#f97066;
  --c1:#3cc2a8;--c2:#e9a23b;--c3:#6aa6e8;--c4:#e07ab0;--c5:#94a49f;color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;padding:16px;background:var(--bg);color:var(--ink);font:15px/1.5 ui-sans-serif,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;display:flex;flex-direction:column;gap:14px}
h1{font-size:1.35rem;margin:0}h2{font-size:1.05rem;margin:0 0 6px}
.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:space-between}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}
.card,section{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:14px;min-width:0}
.muted{color:var(--muted)}.small{font-size:.85rem}
input,select,textarea,button{font:inherit;color:inherit;background:var(--surface);border:1px solid var(--line);border-radius:8px;padding:6px 10px}
button{cursor:pointer;background:var(--accent);border-color:var(--accent);color:#fff}button.ghost{background:none;color:var(--ink);border-color:var(--line)}
.stats{display:flex;gap:12px;flex-wrap:wrap}
.stat{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:10px 14px;min-width:130px}
.stat b{display:block;font-size:1.5rem;font-variant-numeric:tabular-nums}.stat span{color:var(--muted);font-size:.85rem}
.tk-table{overflow-x:auto}table{border-collapse:collapse;width:100%;font-variant-numeric:tabular-nums}
th,td{text-align:left;padding:6px 10px;border-bottom:1px solid var(--line)}th{color:var(--muted);font-weight:600;cursor:pointer;white-space:nowrap}
td.n,th.n{text-align:right}
.tk-chart svg{width:100%;height:auto;display:block}.tk-chart text{fill:var(--muted);font-size:11px}
.tk-legend{display:flex;gap:12px;flex-wrap:wrap;font-size:.85rem;color:var(--muted)}.tk-legend i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:5px}
.tk-msg{color:var(--muted);padding:12px 0}.tk-err{color:var(--danger)}`;
  const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

  const $ = s => typeof s === 'string' ? document.querySelector(s) : s;
  const $$ = s => [...document.querySelectorAll(s)];
  const NS = 'http://www.w3.org/2000/svg';
  const svg = (tag, attrs = {}) => { const e = document.createElementNS(NS, tag); for (const k in attrs) e.setAttribute(k, attrs[k]); return e; };

  // the bridge to the Talixio page: one message per call, answered by id
  let seq = 0; const waiting = new Map();
  window.addEventListener('message', e => {
    if (e.source !== window.parent || !e.data || e.data.talixio !== 1) return;
    if (e.data.theme) document.documentElement.dataset.theme = e.data.theme;
    const w = waiting.get(e.data.id); if (!w) return;
    waiting.delete(e.data.id);
    e.data.error ? w.reject(new Error(e.data.error)) : w.resolve(e.data.result);
  });
  const call = (op, args) => new Promise((resolve, reject) => {
    const id = ++seq; waiting.set(id, { resolve, reject });
    window.parent.postMessage({ talixio: 1, id, op, ...args }, '*');
  });

  const el = (tag, props = {}, ...kids) => {
    const e = document.createElement(tag);
    for (const k in props) k === 'class' ? (e.className = props[k]) : k === 'text' ? (e.textContent = props[k]) : e.setAttribute(k, props[k]);
    for (const c of kids.flat()) e.append(c instanceof Node ? c : String(c));
    return e;
  };
  const num = (x, d = 0) => (x === null || x === undefined || isNaN(x)) ? '–' : Number(x).toLocaleString(undefined, { minimumFractionDigits: d, maximumFractionDigits: d });
  const short = x => Math.abs(x) >= 1e9 ? num(x / 1e9, 1) + 'B' : Math.abs(x) >= 1e6 ? num(x / 1e6, 1) + 'M' : Math.abs(x) >= 1e4 ? num(x / 1e3, 0) + 'k' : num(x, Math.abs(x) < 10 && x % 1 ? 2 : 0);
  const message = (target, text, error = false) => { const t = $(target); t.innerHTML = ''; t.append(el('p', { class: 'tk-msg' + (error ? ' tk-err' : ''), text })); };

  /** A line, area or bar chart: { type, labels: [...], series: [{ name, values: [...] }] }. */
  function chart(target, spec) {
    const t = $(target); t.innerHTML = ''; t.classList.add('tk-chart');
    const labels = spec.labels || [], series = (spec.series || []).slice(0, 5), type = spec.type || 'line';
    const all = series.flatMap(s => s.values).filter(v => typeof v === 'number' && isFinite(v));
    if (!labels.length || !all.length) { message(t, 'No data'); return; }
    const W = 640, H = spec.height || 260, L = 48, R = 12, T = 10, B = 28, w = W - L - R, h = H - T - B;
    let lo = Math.min(...all), hi = Math.max(...all);
    if (type === 'bar' || spec.zero) lo = Math.min(0, lo);
    if (lo === hi) { hi += 1; lo -= type === 'bar' ? 0 : 1; }
    const pad = (hi - lo) * 0.06; if (type !== 'bar' && !spec.zero) lo -= pad; hi += pad;
    const y = v => T + h - (v - lo) / (hi - lo) * h;
    const n = labels.length, step = w / n, x = i => L + step * (i + 0.5);
    const s = svg('svg', { viewBox: `0 0 ${W} ${H}`, role: 'img', 'aria-label': spec.title || series.map(x => x.name).join(', ') });
    for (let k = 0; k <= 4; k++) {
      const v = lo + (hi - lo) * k / 4;
      s.append(svg('line', { x1: L, x2: W - R, y1: y(v), y2: y(v), stroke: 'var(--line)' }));
      const tx = svg('text', { x: L - 6, y: y(v) + 4, 'text-anchor': 'end' }); tx.textContent = short(v); s.append(tx);
    }
    const every = Math.ceil(n / 8);
    labels.forEach((lb, i) => { if (i % every) return; const tx = svg('text', { x: x(i), y: H - 8, 'text-anchor': 'middle' }); tx.textContent = String(lb).slice(0, 12); s.append(tx); });
    series.forEach((se, k) => {
      const col = `var(--c${k + 1})`;
      if (type === 'bar') {
        const bw = Math.max(2, step * 0.8 / series.length);
        se.values.forEach((v, i) => {
          if (typeof v !== 'number') return;
          const r = svg('rect', { x: x(i) - step * 0.4 + bw * k, y: Math.min(y(v), y(0)), width: bw - 1, height: Math.abs(y(v) - y(0)), rx: 2, fill: col });
          const ti = svg('title'); ti.textContent = `${labels[i]} · ${se.name}: ${num(v, v % 1 ? 2 : 0)}`; r.append(ti); s.append(r);
        });
        return;
      }
      const pts = se.values.map((v, i) => typeof v === 'number' ? [x(i), y(v)] : null).filter(Boolean);
      if (type === 'area' && pts.length) s.append(svg('path', { d: `M${pts[0][0]},${y(lo)} ` + pts.map(p => `L${p[0]},${p[1]}`).join(' ') + ` L${pts[pts.length - 1][0]},${y(lo)}Z`, fill: col, opacity: 0.15 }));
      s.append(svg('polyline', { points: pts.map(p => p.join(',')).join(' '), fill: 'none', stroke: col, 'stroke-width': 2, 'stroke-linejoin': 'round' }));
      se.values.forEach((v, i) => {
        if (typeof v !== 'number') return;
        const c = svg('circle', { cx: x(i), cy: y(v), r: n > 40 ? 0 : 3, fill: col });
        const hit = svg('rect', { x: x(i) - step / 2, y: T, width: step, height: h, fill: 'transparent' });
        const ti = svg('title'); ti.textContent = `${labels[i]} · ${se.name}: ${num(v, v % 1 ? 2 : 0)}`; hit.append(ti);
        s.append(c, hit);
      });
    });
    t.append(s);
    if (series.length > 1) t.append(el('div', { class: 'tk-legend' }, series.map((se, k) => el('span', {}, el('i', { style: `background:var(--c${k + 1})` }), se.name))));
  }

  /** A table of rows (objects); click a heading to sort. columns: which keys, in order (default: the first row's). */
  function table(target, rows, columns) {
    const t = $(target); t.innerHTML = ''; t.classList.add('tk-table');
    if (!rows || !rows.length) { message(t, 'Nothing to show'); return; }
    const cols = columns || Object.keys(rows[0]);
    const isNum = c => rows.every(r => r[c] === '' || r[c] === null || r[c] === undefined || !isNaN(r[c]));
    let by = null, dir = 1;
    const draw = () => {
      const list = by === null ? rows : [...rows].sort((a, b) => (isNum(by) ? (+a[by] || 0) - (+b[by] || 0) : String(a[by] ?? '').localeCompare(String(b[by] ?? ''))) * dir);
      const tb = el('table', {}, el('thead', {}, el('tr', {}, cols.map(c => {
        const th = el('th', { class: isNum(c) ? 'n' : '', text: c + (by === c ? (dir > 0 ? ' ▲' : ' ▼') : '') });
        th.addEventListener('click', () => { dir = by === c ? -dir : 1; by = c; draw(); }); return th;
      }))), el('tbody', {}, list.slice(0, 2000).map(r => el('tr', {}, cols.map(c => el('td', { class: isNum(c) ? 'n' : '', text: r[c] ?? '' }))))));
      t.innerHTML = ''; t.append(tb);
    };
    draw();
  }

  /** Key figures: [{ label, value, note }]. */
  function stats(target, items) {
    const t = $(target); t.innerHTML = ''; t.classList.add('stats');
    t.append(...items.map(i => el('div', { class: 'stat' }, el('b', { text: i.value }), el('span', { text: i.label }), i.note ? el('div', { class: 'muted small', text: i.note }) : '')));
  }

  function csv(text) {
    const lines = String(text).trim().split(/\r?\n/); if (!lines[0]) return [];
    const sep = (lines[0].match(/;/g) || []).length > (lines[0].match(/,/g) || []).length ? ';' : lines[0].includes('\t') ? '\t' : ',';
    const split = l => { const out = []; let cur = '', q = false; for (const ch of l) { if (ch === '"') q = !q; else if (ch === sep && !q) { out.push(cur); cur = ''; } else cur += ch; } out.push(cur); return out; };
    const head = split(lines.shift());
    return lines.filter(l => l.trim()).map(l => { const v = split(l); return Object.fromEntries(head.map((h, i) => [h, v[i] ?? ''])); });
  }

  const ready = fn => {
    const go = () => Promise.resolve().then(fn).catch(e => { const box = el('p', { class: 'tk-msg tk-err', text: e.message || String(e) }); document.body.prepend(box); });
    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', go) : go();
  };

  window.$ = $; window.$$ = $$;
  window.talixio = Object.freeze({
    data: (name, params = {}) => call('data', { name, params }),
    load: key => call('load', { key }),
    save: (key, value) => call('save', { key, value }),
    chart, table, stats, message, el, num, csv, ready,
    date: s => new Date(s).toLocaleDateString(),
  });
})();
