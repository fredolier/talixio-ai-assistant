// Browser tests of the web app: a real Chromium drives the page against a local server with the "echo" model.
// Run: node tests/browser/run.mjs   (needs Playwright; prints the share of app.js the run executed)
import { spawn } from 'node:child_process';
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
import net from 'node:net';

const require = createRequire(import.meta.url);
let chromium;
try { ({ chromium } = require('playwright')); } catch { console.log('SKIP browser tests: Playwright is not installed'); process.exit(0); }

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const data = mkdtempSync(join(tmpdir(), 'talixio-browser-'));
mkdirSync(join(data, 'store')); // the server's shared store, as on a server run from the admin page
const port = await new Promise(r => { const s = net.createServer().listen(0, () => { const p = s.address().port; s.close(() => r(p)); }); });
const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', join(root, 'web/public'), join(root, 'web/router.php')],
  { env: { ...process.env, TALIXIO_DATA_DIR: data, TALIXIO_PROVIDER: 'echo', TALIXIO_WEB_PASSWORD: 'pw', TALIXIO_NOTIFY: 'none', TALIXIO_STORE: join(data, 'store') }, stdio: 'ignore' });
const base = `http://127.0.0.1:${port}`;
for (let i = 0; i < 50; i++) { try { await fetch(base + '/'); break; } catch { await new Promise(r => setTimeout(r, 100)); } }

let failed = 0, passed = 0;
const errors = [];
export const check = (name, ok, detail = '') => { if (ok) passed++; else { failed++; console.log(`FAIL ${name}${detail ? '  -> ' + detail : ''}`); } };

const browser = await chromium.launch(process.env.PLAYWRIGHT_BROWSERS_PATH ? {} : { executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 1280, height: 860 } });
page.on('pageerror', e => errors.push(`${location()}: ${e.message}`));
page.on('console', m => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(`${location()}: ${m.text()}`); });
const location = () => { try { return new URL(page.url()).hash || '/'; } catch { return '?'; } };
await page.coverage.startJSCoverage({ resetOnNavigation: false });

const ctx = { browser, page, base, check, errors };
const suites = ['smoke', 'flows'];
try {
  for (const s of suites) await (await import(`./${s}.mjs`)).default(ctx);
} catch (e) { failed++; console.log('FAIL ' + (e.stack || e)); }

check('no error in the page or its console', errors.length === 0, errors.slice(0, 5).join(' | '));
const cov = (await page.coverage.stopJSCoverage()).filter(c => /\/app\.js/.test(c.url));
for (const c of cov) {
  const src = c.source || ''; const used = new Uint8Array(src.length);
  // V8 block coverage: ranges are nested, the innermost wins (count 0 = not run)
  for (const f of c.functions) for (const r of f.ranges) used.fill(r.count > 0 ? 1 : 0, r.startOffset, r.endOffset);
  const lines = src.split('\n'), missed = []; let off = 0, run = 0, all = 0;
  lines.forEach((l, n) => { if (l.trim() && !l.trim().startsWith('//')) { all++; if (used.subarray(off, off + l.length).some((v, i) => v && l[i] !== ' ')) run++; else missed.push(n + 1); } off += l.length + 1; });
  // MISSED=<file> lists the lines no test ran, to see what to cover next
  if (process.env.MISSED) writeFileSync(process.env.MISSED, missed.join('\n'));
  console.log(`app.js: ${run}/${all} lines run (${(100 * run / all).toFixed(1)}%)`);
}
await browser.close(); server.kill(); rmSync(data, { recursive: true, force: true });
console.log(failed ? `${failed} FAILED, ${passed} passed` : `all passed (${passed})`);
process.exit(failed ? 1 : 0);
