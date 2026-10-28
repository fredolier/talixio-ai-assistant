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
    await page.click('#lib-newfolder');
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
    await page.click('#lib-newnote');
    await page.waitForTimeout(1000);
    check('library: a new note opens', await page.isVisible('#viewer-body'));
    await page.keyboard.press('Escape');
    await go(page, 'library');
    await page.check('#lib-regex');
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
    await page.uncheck('#lib-regex');
    await page.waitForTimeout(300);
    await go(page, 'library');
    await page.fill('#lib-q', 'note');
    await page.waitForTimeout(800);
    for (const b of ['#lib-tree [data-trash]', '#lib-recent', '#lib-shares']) {
      await page.click(b); await page.waitForTimeout(500); await page.keyboard.press('Escape');
    }
    check('library: trash, recent and shares open', true);
    await page.setViewportSize({ width: 390, height: 780 }); await page.waitForTimeout(150);
    check('library mobile: toolbar is one + button', await page.isVisible('#lib-add') && !(await page.isVisible('#lib-upload')));
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
    await page.click('#set-save-btn'); await page.waitForTimeout(800);
    check('settings: saving works', true);
  },
  async markdown({ page, check }) {
    await go(page, 'chat');
    await page.fill('#input', '# Plan\n\n**bold** and `code`\n\n- one\n- two\n\n| a | b |\n|---|---|\n| 1 | 2 |\n\n```\nx = 1\n```');
    await page.click('#send');
    await page.waitForSelector('#thread table', { timeout: 10000 }).catch(() => {});
    check('markdown: lists, code and tables render', await page.evaluate(() => { const h = [...document.querySelectorAll('#thread .msg')].pop()?.innerHTML || ''; return ['<strong>', '<li>', '<table>', '<code>'].every(t => h.includes(t)); }));
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
    check('pilotage: Agents is the opening tab', await page.isVisible('.pilot-panel[data-ppanel=agents] .pilot-talk'));
    check('pilotage: a mic sits on every input', (await page.$$('#view-pilotage .dictate-btn')).length >= 2);
    await page.click('#pilot-orch-connect');
    await page.waitForSelector('#pilot-orch-steps:not([hidden])', { timeout: 4000 });
    check('pilotage: Connect reveals the orchestrator bootstrap steps', /"type":"pair"/.test(await page.textContent('#pilot-orch-steps')));
    // 1.1.0: the agents tab is the three mockup cards — status, talk, roster — with a per-agent thread dialog
    check('pilotage: the agents tab shows the status, talk and roster cards',
      await page.isVisible('.pilot-panel[data-ppanel=agents] .pilot-status')
      && await page.isVisible('.pilot-panel[data-ppanel=agents] .pilot-talk')
      && await page.isVisible('.pilot-panel[data-ppanel=agents] .pilot-agents-card'));
    check('pilotage: the status card names the autonomous pilot', /pilot/i.test(await page.textContent('#pilot-conn-title')));
    check('pilotage: with nothing paired, the roster invites pairing instead of listing agents',
      (await page.textContent('#pilot-agents')).trim().length > 0 && (await page.$$('#pilot-agents [data-discuss]')).length === 0);
    check('pilotage: a per-agent Discuter dialog is in the page', (await page.$$('#pilot-agent-dialog')).length === 1);
    check('pilotage: the Knowledge-base tab carries a source badge slot', (await page.$$('.ptab[data-ptab=kb] .ptab-src')).length === 1);
    // tab switching reveals the Worksites/Chantiers board (the Director's directives)
    await page.click('#view-pilotage .ptab[data-ptab=chantiers]'); await page.waitForTimeout(400);
    check('pilotage: Worksites tab opens', await page.isVisible('.pilot-panel[data-ppanel=chantiers]'));
    await page.click('#view-pilotage .ptab[data-ptab=roles]');
    await page.waitForSelector('#pilot-roles .prole-card', { timeout: 6000 });
    check('pilotage: Roles tab opens the metric catalogue (slice 2)', await page.isVisible('.pilot-panel[data-ppanel=roles]'));
    check('pilotage: each role is a metric card with an acceptance ring and an Evaluate action',
      (await page.$$('#pilot-roles .prole-card')).length >= 1
      && (await page.$$('#pilot-roles .prole-card .pring')).length >= 1
      && (await page.$$('#pilot-roles [data-role-eval]')).length >= 1);
    check('pilotage: the assessor (role #0) is in the catalogue, protected, so no Publish on it',
      (await page.textContent('#pilot-roles')).includes('assessor'));
    // slice 3: the catalogue is grouped under specialization headers, with a one-field editor per card
    check('pilotage: roles are grouped under specialization section headers',
      (await page.$$('#pilot-roles .pspec')).length >= 1);
    check('pilotage: each card carries an inline specialization editor',
      (await page.$$('#pilot-roles form[data-role-spec]')).length >= 1);
    // slice 3: setting a specialization regroups the catalogue under that heading
    const specForm = await page.$('#pilot-roles form[data-role-spec]');
    await specForm.$eval('.prole-spec-in', el => { el.value = 'QA'; });
    await specForm.evaluate(f => f.requestSubmit());
    await page.waitForSelector('#pilot-roles .pspec.no-i18n:text-is("QA")', { timeout: 6000 });
    check('pilotage: a saved specialization becomes a group header', await page.isVisible('#pilot-roles .pspec:text-is("QA")'));
    // slice 4: lifecycle actions on every non-protected card
    check('pilotage: non-protected cards carry Improve and Archive actions',
      (await page.$$('#pilot-roles [data-role-improve]')).length >= 1 && (await page.$$('#pilot-roles [data-role-archive]')).length >= 1);
    // slice 4: Archive moves a role into the Archived disclosure (history kept), and Reactivate brings it back
    const archName = await page.$eval('#pilot-roles [data-role-archive]', b => b.dataset.roleArchive);
    page.once('dialog', d => d.accept());
    await page.click(`#pilot-roles [data-role-archive="${archName}"]`);
    await page.waitForSelector('#pilot-roles .parchived', { timeout: 6000 });
    check('pilotage: archiving drops the role out of the grid and lists it under Archived with Reactivate',
      !(await page.$(`#pilot-roles .prole-card [data-role-archive="${archName}"]`))
      && (await page.$$(`#pilot-roles .parchived [data-role-reactivate="${archName}"]`)).length === 1);
    await page.$eval('#pilot-roles .parchived', d => { d.open = true; });
    await page.click(`#pilot-roles [data-role-reactivate="${archName}"]`);
    await page.waitForSelector(`#pilot-roles .prole-card [data-role-archive="${archName}"]`, { timeout: 6000 });
    check('pilotage: Reactivate returns the role to the catalogue', !!(await page.$(`#pilot-roles [data-role-archive="${archName}"]`)));
    // slice 4: Improve opens the candidate-brief editor that feeds the Assessor's A/B
    await page.click('#pilot-roles [data-role-improve]');
    await page.waitForSelector('#prole-improve:not([hidden]) .prole-imp-in', { timeout: 6000 });
    check('pilotage: Improve opens a candidate-brief editor over the Assessor A/B', await page.isVisible('#prole-improve .prole-imp-in'));
    // "Parler au pilote": a dictated directive is routed through the ordinary chat send()
    await page.click('#view-pilotage .ptab[data-ptab=agents]'); await page.waitForTimeout(300);
    await page.fill('#ptt-input', 'run the weekly competitor scan');
    await page.click('#ptt-send');
    await page.waitForSelector('#thread :text("run the weekly competitor scan")', { timeout: 10000 });
    check('pilotage: the pilot composer sends through the chat', (await page.textContent('#thread')).includes('run the weekly competitor scan'));
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
    // slice 6: Routines and Background jobs are one rail entry ("Automations") with [Scheduled · Background] subtabs
    check('automations: the two boards share a single rail entry', (await page.$$('.rail a[data-view=tasks]')).length === 1
      && (await page.$$('.rail a[data-view=jobs]')).length === 0);
    await go(page, 'tasks');
    check('automations: the Scheduled board shows with the rail entry and its subtab lit',
      await page.isVisible('#view-tasks .subtabs a[data-view=tasks].active')
      && await page.isVisible('.rail a[data-view=tasks].active'));
    await page.click('#view-tasks .subtabs a[data-view=jobs]');
    await page.waitForSelector('#view-jobs:not([hidden])', { timeout: 4000 });
    check('automations: the Background subtab switches to the jobs board, rail entry still lit',
      await page.isVisible('#view-jobs .subtabs a[data-view=jobs].active')
      && await page.isVisible('.rail a[data-view=tasks].active'));
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
