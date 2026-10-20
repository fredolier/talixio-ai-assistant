// Sign in, then open every view: each one shows without an error.
export default async ({ page, base, check }) => {
  await page.goto(base + '/');
  await page.fill('#password', 'pw');
  await page.press('#password', 'Enter');
  await page.waitForSelector('#shell:not([hidden])', { timeout: 10000 });
  check('sign in: the app shows', await page.isVisible('#view-chat'));
  for (const v of ['library', 'board', 'tasks', 'mail', 'people', 'apps', 'jobs', 'memory', 'settings', 'privacy', 'notifications', 'activity', 'chat']) {
    await page.evaluate(h => { location.hash = h; }, '#' + v);
    await page.waitForTimeout(600);
    check(`view ${v} shows`, await page.isVisible('#view-' + v));
  }
};
