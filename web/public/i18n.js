/*
 * Interface translations. The code and the pages are written in English; each language is a dictionary
 * (i18n/<lang>.js: English text → translation), loaded before this file. Everything the page shows is looked up
 * as it appears, then everything the app adds or changes later (a MutationObserver): text, placeholders, titles,
 * labels. A key is either the exact text (spaces collapsed), a whole element's inner HTML when it only holds
 * inline formatting (<b>, <code>, <a>…), or a pattern with {placeholders} for text built with values
 * ("Job #{a} queued"), whose captured values are translated too when they are known words.
 * Never translated: messages, documents and anything the user typed (.bubble, .doc, inputs, editors, code).
 * t(text, vars) is for text that never goes through the page (confirm(), prompt(), document.title).
 */
(() => {
  const I18N = window.I18N || (window.I18N = {});
  // The interface language can be pinned from the account menu, kept per instance in this browser (like the theme);
  // without one it follows document.documentElement.dataset.lang, then the browser.
  const pinned = () => {
    try { const b = new URL(document.baseURI).pathname.replace(/\/$/, ''); return localStorage.getItem((b ? b.slice(1) + ':' : '') + 'talixio.lang') || ''; } catch { return ''; }
  };
  const pick = () => {
    const want = (pinned() || document.documentElement.dataset.lang || 'auto').toLowerCase();
    const langs = want !== 'auto' ? [want] : (navigator.languages || [navigator.language || 'en']);
    for (const l of langs) { const k = String(l).toLowerCase().slice(0, 2); if (k === 'en' || I18N[k]) return k; }
    return 'en';
  };
  const LANG = pick();
  const dict = LANG === 'en' ? null : I18N[LANG];
  const collect = window.I18N_COLLECT; // development: a Set that receives every text with no translation
  window.LANG = LANG;
  window.LOCALE = LANG === 'en' ? [] : [LANG]; // dates and numbers follow the interface language
  document.documentElement.lang = LANG;

  const norm = s => s.replace(/\s+/g, ' ').trim();
  const exact = new Map(), patterns = [], templates = new Map(); // templates: every key as written, for t()
  // every text this file wrote: never translated again ("Message {a}…" → "Message à {a}…" would match its own pattern
  // forever, and the page observer would loop on it)
  const produced = new Set();
  if (dict) {
    for (const [k, v] of Object.entries(dict)) {
      if (typeof v !== 'string' || v === '') continue;
      const key = norm(k);
      templates.set(key, v);
      if (/\{[a-z0-9_]+\}/i.test(key)) {
        const names = [];
        const re = key.split(/(\{[a-z0-9_]+\})/i).map(p => {
          const m = p.match(/^\{([a-z0-9_]+)\}$/i);
          if (m) { names.push(m[1]); return '(.+?)'; }
          return p.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }).join('');
        const literal = key.replace(/\{[a-z0-9_]+\}/gi, '').length; // try the most specific patterns first
        patterns.push({ re: new RegExp('^' + re + '$', 's'), names, out: v, literal });
      } else exact.set(key, v);
    }
    patterns.sort((a, b) => b.literal - a.literal);
  }

  /** The translation of a text, or null. Keeps the text's surrounding spaces. */
  function lookup(text) {
    if ((!dict && !collect) || !text || !/[A-Za-z]{2}/.test(text)) return null;
    const key = norm(text);
    if (produced.has(key)) return null;
    let out = exact.get(key);
    if (out === undefined) {
      for (const p of patterns) {
        const m = p.re.exec(key);
        if (!m || m.slice(1).some(v => v.includes('<'))) continue; // a value never spans markup ("{a} of {b}" must not swallow two links)
        out = p.out;
        p.names.forEach((n, i) => { const v = m[i + 1]; out = out.split('{' + n + '}').join(exact.get(norm(v)) ?? v); });
        break;
      }
    }
    if (out === undefined) { collect?.add(key); return null; }
    produced.add(norm(out));
    const lead = text.match(/^\s*/)[0], trail = text.match(/\s*$/)[0];
    return lead + out + trail;
  }

  /** For code: translate a text and fill its {placeholders}. */
  window.t = (text, vars) => {
    let out = (dict && (templates.get(norm(text)) ?? (vars ? null : lookup(text)))) || text;
    if (vars) for (const [k, v] of Object.entries(vars)) out = out.split('{' + k + '}').join(v);
    return out;
  };
  if (!dict && !collect) { window.i18nApply = () => {}; return; }

  const SKIP = '.bubble, .doc, pre, code, textarea, script, style, [contenteditable], .no-i18n, .rte-area';
  const ATTRS = ['placeholder', 'title', 'aria-label', 'alt', 'data-placeholder'];
  const INLINE = new Set(['B', 'STRONG', 'EM', 'I', 'CODE', 'A', 'BR', 'KBD', 'SMALL', 'SPAN', 'SUP', 'SUB', 'U']);
  const BLOCKISH = new Set(['P', 'LI', 'LABEL', 'SMALL', 'SUMMARY', 'SPAN', 'DIV', 'H1', 'H2', 'H3', 'H4', 'H5', 'TD', 'TH', 'DT', 'DD', 'LEGEND', 'FIGCAPTION', 'BUTTON', 'A', 'B', 'STRONG', 'EM', 'OPTION']);
  const skipped = el => el.closest?.(SKIP);
  const EDIT = 'textarea, [contenteditable], .rte-area'; // what the user types is kept; the field's own hints are translated
  const ownHints = el => el.matches?.(EDIT) && !el.parentElement?.closest(SKIP);

  /** An element holding text with only inline formatting, whose children carry nothing the code relies on. */
  function inlineOnly(el) {
    if (!BLOCKISH.has(el.tagName) || !el.firstElementChild) return false;
    for (const c of el.querySelectorAll('*')) {
      if (!INLINE.has(c.tagName) || c.id || c.attributes.length > (c.tagName === 'A' ? 3 : 1)) return false;
      for (const a of c.attributes) if (a.name.startsWith('data-') || a.name.startsWith('on')) return false;
    }
    return true;
  }

  function attrs(el) {
    for (const a of ATTRS) {
      const v = el.getAttribute(a);
      if (v) { const tr = lookup(v); if (tr !== null && tr !== v) el.setAttribute(a, tr); }
    }
  }

  function apply(node) {
    if (node.nodeType === 3) {
      const p = node.parentElement;
      if (!p || skipped(p)) return;
      if (inlineOnly(p) && applyHtml(p)) return;
      const tr = lookup(node.nodeValue);
      if (tr !== null && tr !== node.nodeValue) node.nodeValue = tr;
      return;
    }
    if (node.nodeType !== 1) return;
    if (ownHints(node)) { attrs(node); return; }
    if (skipped(node)) return;
    attrs(node);
    if (inlineOnly(node) && applyHtml(node)) return;
    for (const c of [...node.childNodes]) apply(c);
  }

  function applyHtml(el) {
    const html = el.innerHTML, tr = lookup(html);
    if (tr === null) return false;
    if (tr !== html) el.innerHTML = tr;
    return true;
  }

  window.i18nApply = apply;
  const start = () => {
    apply(document.body);
    if (document.title) document.title = t(document.title);
    new MutationObserver(list => {
      for (const m of list) {
        if (m.type === 'childList') m.addedNodes.forEach(apply);
        else if (m.type === 'characterData') apply(m.target);
        else if (m.type === 'attributes' && (!skipped(m.target) || ownHints(m.target))) attrs(m.target);
      }
    }).observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ATTRS });
  };
  if (document.body) start(); else document.addEventListener('DOMContentLoaded', start);
})();
