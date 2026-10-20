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
body{margin:0;padding:18px;background:var(--bg);color:var(--ink);font:15px/1.5 ui-sans-serif,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;display:flex;flex-direction:column;gap:14px}
h1{font-size:1.35rem;margin:0;letter-spacing:-.01em}h2{font-size:.8rem;margin:0 0 10px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);font-weight:700;display:flex;gap:8px;align-items:center}
a{color:var(--accent);text-decoration:none}a:hover{text-decoration:underline}
.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:space-between}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}
.card,section{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:16px;min-width:0;box-shadow:0 1px 2px rgb(0 0 0/.04)}
.muted{color:var(--muted)}.small{font-size:.85rem}
input,select,textarea,button{font:inherit;color:inherit;background:var(--surface);border:1px solid var(--line);border-radius:8px;padding:6px 10px}
button{cursor:pointer;background:var(--accent);border-color:var(--accent);color:#fff}button.ghost{background:none;color:var(--ink);border-color:var(--line)}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
.stat{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:14px 16px;min-width:0;border-top:3px solid var(--t,var(--accent));box-shadow:0 1px 2px rgb(0 0 0/.04)}
.stat b{display:block;font-size:1.9rem;line-height:1.2;font-variant-numeric:tabular-nums;letter-spacing:-.02em}.stat b.tk-zero{color:var(--muted);font-weight:600}.stat span{color:var(--muted);font-size:.85rem}
.tk-table{overflow-x:auto}table{border-collapse:collapse;width:100%;font-variant-numeric:tabular-nums}
th,td{text-align:left;padding:6px 10px;border-bottom:1px solid var(--line)}th{color:var(--muted);font-weight:600;cursor:pointer;white-space:nowrap}
td.n,th.n{text-align:right}
.tk-chart svg{width:100%;height:auto;display:block}.tk-chart text{fill:var(--muted);font-size:11px}
.tk-legend{display:flex;gap:12px;flex-wrap:wrap;font-size:.85rem;color:var(--muted)}.tk-legend i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:5px}
.tk-msg{color:var(--muted);padding:18px 8px;text-align:center;font-size:.9rem;margin:0}.tk-err{color:var(--danger)}
:root{--ok:#13795b;--warn:#b26a00;--bad:#b42318;--info:#2f6fb0}:root[data-theme=dark]{--ok:#3ccf91;--warn:#f0b13d;--bad:#f97066;--info:#6aa6e8}
.tk-dl{float:right;background:none;border:0;color:var(--muted);padding:0 4px;font-size:.85rem}.tk-dl:hover{color:var(--accent)}
.tk-badge{display:inline-block;padding:1px 8px;border-radius:999px;font-size:.78rem;font-weight:600;background:color-mix(in srgb,var(--t) 14%,transparent);color:var(--t)}
.tk-delta{font-size:.85rem;font-weight:600;margin-left:6px}.tk-up{color:var(--ok)}.tk-down{color:var(--bad)}
.tk-spark{width:90px;height:24px;vertical-align:middle}.stat .tk-spark{display:block;width:100%;margin-top:4px}
.tk-bars{display:flex;flex-direction:column;gap:8px}.tk-bar{display:grid;grid-template-columns:minmax(80px,30%) 1fr auto;gap:10px;align-items:center;font-size:.9rem}
.tk-bar .tk-track{height:10px;border-radius:999px;background:var(--line);overflow:hidden}.tk-bar .tk-fill{height:100%;border-radius:999px;background:var(--c1)}
.tk-bar span:first-child{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.tk-bar b{font-variant-numeric:tabular-nums;font-weight:600}
.tk-pie{display:flex;gap:16px;align-items:center;flex-wrap:wrap}.tk-pie svg{width:180px;height:180px;flex:none}
.tk-gauge{text-align:center}.tk-gauge svg{width:100%;max-width:240px}.tk-gauge b{font-size:1.6rem;display:block;margin-top:-34px}
.tk-heat{overflow-x:auto}.tk-heat svg{display:block}.tk-tl{list-style:none;margin:0;padding:0 0 0 14px;border-left:2px solid var(--line);display:flex;flex-direction:column;gap:12px}
.tk-tl li{position:relative}.tk-tl li::before{content:"";position:absolute;left:-20px;top:6px;width:10px;height:10px;border-radius:50%;background:var(--t,var(--accent))}
.tk-tabs{display:flex;gap:4px;border-bottom:1px solid var(--line);margin-bottom:12px;flex-wrap:wrap}.tk-tabs button{background:none;color:var(--muted);border:0;border-bottom:2px solid transparent;border-radius:0}
.tk-tabs button.on{color:var(--ink);border-color:var(--accent)}
.tk-list{list-style:none;margin:0 -8px;padding:0;display:flex;flex-direction:column}.tk-list li{display:flex;gap:10px;align-items:center;padding:8px;border-radius:9px;min-width:0}
.tk-list li.tk-go{cursor:pointer}.tk-list li.tk-go:hover{background:color-mix(in srgb,var(--accent) 8%,transparent)}.tk-list .tk-ic{flex:none;width:30px;height:30px;border-radius:8px;display:grid;place-items:center;background:color-mix(in srgb,var(--t,var(--accent)) 12%,transparent);color:var(--t,var(--accent));font-size:.95rem}
.tk-list .tk-tx{flex:1;min-width:0}.tk-list .tk-tx b{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;font-weight:600;font-size:.92rem}.tk-list .tk-tx div{color:var(--muted);font-size:.8rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tk-list .tk-meta{flex:none;color:var(--muted);font-size:.8rem;font-variant-numeric:tabular-nums}`;
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
  // an app that breaks shows why instead of a blank page (a syntax error stops its whole script)
  const fail = m => { const box = el('p', { class: 'tk-msg tk-err', text: m }); (document.body || document.documentElement).prepend(box); };
  window.addEventListener('error', e => fail(e.message || 'error'));
  window.addEventListener('unhandledrejection', e => fail((e.reason && e.reason.message) || String(e.reason)));
  // the app's code runs once the page is ready: a DOMContentLoaded listener it adds then still runs
  const addListener = document.addEventListener.bind(document);
  document.addEventListener = (type, fn, o) => type === 'DOMContentLoaded' && document.readyState !== 'loading' ? setTimeout(fn) : addListener(type, fn, o);
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
    const stacked = type === 'bar' && spec.stacked;
    if (stacked) { const tot = labels.map((_, i) => series.reduce((a, se) => a + (+se.values[i] || 0), 0)); hi = Math.max(...tot); lo = 0; }
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
        const bw = Math.max(2, step * 0.8 / (stacked ? 1 : series.length));
        se.values.forEach((v, i) => {
          if (typeof v !== 'number') return;
          const base = stacked ? series.slice(0, k).reduce((a, p) => a + (+p.values[i] || 0), 0) : 0;
          const r = svg('rect', { x: x(i) - step * 0.4 + (stacked ? 0 : bw * k), y: Math.min(y(base + v), y(base)), width: bw - 1, height: Math.abs(y(base + v) - y(base)), rx: 2, fill: col });
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
    t.append(dlButton('PNG', () => png(s, (spec.title || 'chart') + '.png')), s);
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
      t.innerHTML = ''; t.append(dlButton('CSV', () => download('table.csv', list.map(r => Object.fromEntries(cols.map(c => [c, r[c] ?? '']))))), tb);
    };
    draw();
  }

  /** Key figures: [{ label, value, note, delta, trend }]: delta shows an up or down arrow, trend a small line. */
  function stats(target, items) {
    const t = $(target); t.innerHTML = ''; t.classList.add('stats');
    t.append(...items.map(i => {
      const d = i.delta === undefined || i.delta === null || i.delta === '' ? '' : el('span', { class: 'tk-delta ' + (parseFloat(i.delta) >= 0 ? 'tk-up' : 'tk-down'), text: (parseFloat(i.delta) >= 0 ? '▲ ' : '▼ ') + String(i.delta).replace(/^[+-]/, '') });
      const box = el('div', { class: 'stat' }); if (i.tone) box.style.setProperty('--t', TONES[i.tone] || i.tone);
      box.append(el('b', { class: +i.value === 0 ? 'tk-zero' : '' }, String(i.value ?? '–'), d), el('span', { text: i.label }), i.note ? el('div', { class: 'muted small', text: i.note }) : '', i.trend ? sparkline(i.trend) : ''); return box;
    }));
  }

  /** A small line of numbers, to put anywhere (returns the element). */
  function sparkline(values, color = 'var(--c1)') {
    const v = (values || []).map(Number).filter(isFinite), s = svg('svg', { class: 'tk-spark', viewBox: '0 0 100 24', preserveAspectRatio: 'none' });
    if (v.length < 2) return s;
    const lo = Math.min(...v), hi = Math.max(...v) || lo + 1, y = x => 22 - (x - lo) / ((hi - lo) || 1) * 20;
    s.append(svg('polyline', { points: v.map((x, i) => `${i / (v.length - 1) * 100},${y(x)}`).join(' '), fill: 'none', stroke: color, 'stroke-width': 1.6, 'vector-effect': 'non-scaling-stroke' }));
    return s;
  }

  const TONES = { ok: 'var(--ok)', warn: 'var(--warn)', bad: 'var(--bad)', info: 'var(--info)' };
  const badge = (text, tone = 'info') => { const b = el('span', { class: 'tk-badge', text }); b.style.setProperty('--t', TONES[tone] || TONES.info); return b; };

  /** A pie or donut: { labels, values, donut, center }. */
  function pie(target, spec) {
    const t = $(target); t.innerHTML = ''; t.classList.add('tk-pie');
    const vals = (spec.values || []).map(v => Math.max(0, +v || 0)), total = vals.reduce((a, b) => a + b, 0);
    if (!total) { message(t, 'No data'); return; }
    const s = svg('svg', { viewBox: '-1 -1 2 2', role: 'img' }); let a0 = -Math.PI / 2;
    vals.forEach((v, i) => {
      const a1 = a0 + v / total * 2 * Math.PI, big = a1 - a0 > Math.PI ? 1 : 0, col = `var(--c${i % 5 + 1})`;
      const d = vals.filter(Boolean).length === 1 ? 'M1,0A1,1 0 1,1 -1,0A1,1 0 1,1 1,0Z' : `M0,0L${Math.cos(a0)},${Math.sin(a0)}A1,1 0 ${big},1 ${Math.cos(a1)},${Math.sin(a1)}Z`;
      const p = svg('path', { d, fill: col, stroke: 'var(--surface)', 'stroke-width': 0.02 }); const ti = svg('title'); ti.textContent = `${spec.labels[i]}: ${num(v, v % 1 ? 2 : 0)} (${num(v / total * 100, 0)} %)`; p.append(ti); s.append(p);
      a0 = a1;
    });
    if (spec.donut !== false) {
      s.append(svg('circle', { r: 0.6, fill: 'var(--surface)' }));
      if (spec.center !== undefined) { const tx = svg('text', { 'text-anchor': 'middle', y: 0.08, 'font-size': 0.24, 'font-weight': 700, fill: 'var(--ink)' }); tx.textContent = spec.center; s.append(tx); }
    }
    t.append(s, el('div', { class: 'tk-legend', style: 'flex-direction:column;gap:4px' }, vals.map((v, i) => el('span', {}, el('i', { style: `background:var(--c${i % 5 + 1})` }), `${spec.labels[i]} · ${num(v / total * 100, 0)} %`))));
  }

  /** A half-circle gauge: { value, min, max, label, unit, good: 'high'|'low', warn, bad }. */
  function gauge(target, g) {
    const t = $(target); t.innerHTML = ''; t.classList.add('tk-gauge');
    const min = +g.min || 0, max = +g.max || 100, v = Math.min(max, Math.max(min, +g.value || 0)), f = (v - min) / ((max - min) || 1);
    let tone = 'var(--accent)';
    if (g.bad !== undefined || g.warn !== undefined) {
      const past = lim => lim !== undefined && (g.good === 'low' ? v >= lim : v <= lim);
      tone = past(g.bad) ? 'var(--bad)' : past(g.warn) ? 'var(--warn)' : 'var(--ok)';
    }
    const arc = p => { const a = Math.PI * (1 - p); return `${50 + 40 * Math.cos(a)},${50 - 40 * Math.sin(a)}`; };
    const s = svg('svg', { viewBox: '0 0 100 56' });
    s.append(svg('path', { d: `M10,50A40,40 0 0,1 90,50`, fill: 'none', stroke: 'var(--line)', 'stroke-width': 9, 'stroke-linecap': 'round' }));
    if (f > 0) s.append(svg('path', { d: `M10,50A40,40 0 0,1 ${arc(f)}`, fill: 'none', stroke: tone, 'stroke-width': 9, 'stroke-linecap': 'round' }));
    t.append(s, el('b', { text: num(g.value, g.value % 1 ? 1 : 0) + (g.unit ? ' ' + g.unit : '') }), g.label ? el('div', { class: 'muted small', text: g.label }) : '');
  }

  /** Horizontal bars, largest first: [{ label, value, note }], { unit, max, sort: false }. */
  function bars(target, items, o = {}) {
    const t = $(target); t.innerHTML = ''; t.classList.add('tk-bars');
    const list = o.sort === false ? items : [...items].sort((a, b) => (+b.value || 0) - (+a.value || 0));
    const max = o.max || Math.max(...list.map(i => +i.value || 0)) || 1;
    t.append(...list.slice(0, o.limit || 30).map((i, k) => {
      const fill = el('div', { class: 'tk-fill' }); fill.style.width = Math.max(0, Math.min(100, (+i.value || 0) / max * 100)) + '%'; if (i.tone) fill.style.background = TONES[i.tone] || i.tone;
      return el('div', { class: 'tk-bar', title: i.note || '' }, el('span', { text: i.label }), el('div', { class: 'tk-track' }, fill), el('b', { text: num(i.value, i.value % 1 ? 1 : 0) + (o.unit ? ' ' + o.unit : '') }));
    }));
  }

  /** Progress toward goals: [{ label, value, max }]. */
  const progress = (target, items) => bars(target, items.map(i => ({ ...i, note: `${num(i.value)} / ${num(i.max)}`, value: Math.round((+i.value || 0) / (+i.max || 1) * 100), tone: (+i.value || 0) >= (+i.max || 1) ? 'ok' : undefined })), { unit: '%', max: 100, sort: false });

  /** A calendar heatmap, one square per day: { 'YYYY-MM-DD': number }, { weeks }. */
  function heatmap(target, days, o = {}) {
    const t = $(target); t.innerHTML = ''; t.classList.add('tk-heat');
    const weeks = o.weeks || 26, cell = 13, end = new Date(); end.setHours(12, 0, 0, 0);
    const start = new Date(end); start.setDate(end.getDate() - end.getDay() - (weeks - 1) * 7);
    const max = Math.max(1, ...Object.values(days || {}).map(Number).filter(isFinite));
    const s = svg('svg', { width: weeks * cell + 2, height: 7 * cell + 2 });
    for (let d = new Date(start), i = 0; d <= end; d.setDate(d.getDate() + 1), i++) {
      const k = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`, v = +(days || {})[k] || 0;
      const r = svg('rect', { x: Math.floor(i / 7) * cell, y: (i % 7) * cell, width: cell - 2, height: cell - 2, rx: 2, fill: v ? 'var(--c1)' : 'var(--line)', opacity: v ? 0.25 + 0.75 * v / max : 1 });
      const ti = svg('title'); ti.textContent = `${d.toLocaleDateString()}: ${num(v)}`; r.append(ti); s.append(r);
    }
    t.append(s);
  }

  /** Dated events, newest first as given: [{ date, title, note, tone }]. */
  function timeline(target, items) {
    const t = $(target); t.innerHTML = '';
    const ul = el('ul', { class: 'tk-tl' }, items.map(i => { const li = el('li', {}, el('div', { class: 'muted small', text: i.date ? new Date(i.date).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: String(i.date).length > 10 ? 'short' : undefined }) : '' }), el('div', {}, el('b', { text: i.title || '' })), i.note ? el('div', { class: 'small', text: i.note }) : ''); if (i.tone) li.style.setProperty('--t', TONES[i.tone] || i.tone); return li; }));
    t.append(ul);
  }

  /** Tabs: { name: async box => {...} }; each tab is drawn when first opened. */
  function tabs(target, spec) {
    const t = $(target); t.innerHTML = '';
    const bar = el('div', { class: 'tk-tabs', role: 'tablist' }), box = el('div'), names = Object.keys(spec), done = {};
    const show = n => { bar.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.textContent === n)); [...box.children].forEach(c => c.hidden = c.dataset.tab !== n);
      if (!done[n]) { done[n] = el('div'); done[n].dataset.tab = n; box.append(done[n]); Promise.resolve().then(() => spec[n](done[n])).catch(e => message(done[n], e.message, true)); } };
    names.forEach(n => { const b = el('button', { type: 'button', role: 'tab', text: n }); b.addEventListener('click', () => show(n)); bar.append(b); });
    t.append(bar, box); if (names.length) show(names[0]);
  }

  /** Open something in Talixio: a library file ({ id } or its number), a page like '#tasks', or a web address (asked first). */
  const fileLink = h => { const m = String(h).match(/(?:api\/files\/|#file\/|[?&]file=)(\d+)/); return m ? `#file/${m[1]}` : h; };
  const open = what => call('open', { href: typeof what === 'string' && !/^\d+$/.test(what) ? fileLink(what) : typeof what === 'number' || /^\d+$/.test(String(what)) ? `#file/${what}` : what && what.id ? `#file/${what.id}` : String(what || '') });
  // links in an app open in Talixio, not inside the sealed frame (where they would do nothing)
  document.addEventListener('click', e => { const a = e.target.closest && e.target.closest('a[href]'); if (!a) return; e.preventDefault(); open(a.dataset.file ? +a.dataset.file : a.getAttribute('href')).catch(err => console.warn(err.message)); });

  /** A tidy list: [{ title, note, meta, icon, tone, open }]; open is a file, a '#page' or an address. */
  function list(target, items, o = {}) {
    const t = $(target); t.innerHTML = '';
    if (!items || !items.length) { message(t, o.empty || 'Nothing yet'); return; }
    t.append(el('ul', { class: 'tk-list' }, items.slice(0, o.limit || 50).map(i => {
      const ic = i.icon ? el('span', { class: 'tk-ic', text: i.icon }) : '', li = el('li', { title: i.title || '' }, ic, el('div', { class: 'tk-tx' }, el('b', { text: i.title || '' }), i.note ? el('div', { text: i.note }) : ''), i.meta !== undefined && i.meta !== '' ? el('span', { class: 'tk-meta', text: String(i.meta) }) : '', i.badge ? badge(i.badge, i.tone) : '');
      if (i.tone) li.style.setProperty('--t', TONES[i.tone] || i.tone);
      if (i.open) { li.classList.add('tk-go'); li.tabIndex = 0; const go = () => open(i.open).catch(e => console.warn(e.message)); li.addEventListener('click', go); li.addEventListener('keydown', e => { if (e.key === 'Enter') go(); }); }
      return li;
    })));
  }

  /** Hand a file to the Talixio page, which offers it as a download (the frame itself cannot). Rows become CSV. */
  function download(name, data, mime) {
    let content = data;
    if (Array.isArray(data)) {
      const cols = [...new Set(data.flatMap(r => Object.keys(r || {})))], q = v => { const s = String(v ?? ''); return /[",;\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s; };
      content = [cols.map(q).join(','), ...data.map(r => cols.map(c => q(r[c])).join(','))].join('\n'); mime = mime || 'text/csv';
    } else if (typeof data !== 'string') { content = JSON.stringify(data, null, 2); mime = mime || 'application/json'; }
    return call('download', { name: String(name).replace(/[^\w. -]+/g, '_').slice(0, 80), content, mime: mime || 'text/plain' });
  }
  const dlButton = (label, fn) => { const b = el('button', { type: 'button', class: 'tk-dl', title: 'Download ' + label, text: '⤓ ' + label }); b.addEventListener('click', fn); return b; };

  /** An SVG drawn as a PNG (the theme's colors written in, so the picture looks the same outside the app). */
  function png(s, name) {
    const c = s.cloneNode(true), src = s.querySelectorAll('*'), dst = c.querySelectorAll('*');
    src.forEach((n, i) => { const cs = getComputedStyle(n); for (const k of ['fill', 'stroke', 'opacity', 'font-size', 'font-family']) dst[i].style.setProperty(k, cs.getPropertyValue(k)); });
    const r = s.getBoundingClientRect(), W = Math.round(r.width * 2) || 1280, H = Math.round(r.height * 2) || 520;
    c.setAttribute('width', W); c.setAttribute('height', H);
    const img = new Image();
    img.onload = () => { const cv = document.createElement('canvas'); cv.width = W; cv.height = H; const g = cv.getContext('2d'); g.fillStyle = getComputedStyle(document.body).backgroundColor; g.fillRect(0, 0, W, H); g.drawImage(img, 0, 0, W, H); call('download', { name, content: cv.toDataURL('image/png'), mime: 'image/png' }); };
    img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(new XMLSerializer().serializeToString(c));
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
    chart, table, stats, message, el, num, csv, ready, sparkline, badge, pie, gauge, bars, progress, heatmap, timeline, tabs, download, list, open,
    date: s => new Date(s).toLocaleDateString(),
    ago: s => { const m = Math.round((Date.now() - new Date(s)) / 60000); return m < 60 ? `${m} min` : m < 1440 ? `${Math.round(m / 60)} h` : `${Math.round(m / 1440)} d`; },
  });
})();
