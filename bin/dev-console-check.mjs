import { chromium } from '@playwright/test';
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const ctx = await browser.newContext({ storageState: 'tests/e2e/.auth/admin.json', viewport: { width: 390, height: 800 } });
const page = await ctx.newPage();
const errors = [];
page.on('console', m => { if (['error','warning'].includes(m.type()) && !/elementor|favicon|net::ERR|404/i.test(m.text())) errors.push(m.type() + ': ' + m.text()); });
page.on('pageerror', e => errors.push('pageerror: ' + e.message));
for (const url of ['http://127.0.0.1:8787/', 'http://127.0.0.1:8787/acf-demo/', 'http://127.0.0.1:8787/elementor-landing/']) {
  await page.goto(url);
  await page.waitForFunction(() => !!window.EditTrace);
  await page.evaluate(() => window.EditTrace.activate());
  const done = page.evaluate(() => new Promise(r => document.addEventListener('edittrace:result', e => r(e.detail), { once: true })));
  await page.mouse.move(100, 300); await page.mouse.move(120, 320);
  const t = page.locator('h1, h2').first(); await t.evaluate(el => el.scrollIntoView({block:'center'})); await t.click({ force: true, position: { x: 6, y: 6 } });
  await done;
  await page.keyboard.press('ArrowUp'); await page.waitForTimeout(300);
  await page.keyboard.press('ArrowDown'); await page.waitForTimeout(300);
  await page.keyboard.press('Escape'); await page.keyboard.press('Escape');
}
await page.goto('http://127.0.0.1:8787/'); await page.waitForFunction(() => !!window.EditTrace); await page.evaluate(() => window.EditTrace.activate());
const done2 = page.evaluate(() => new Promise(r => document.addEventListener('edittrace:result', e => r(e.detail), { once: true })));
const t2 = page.locator('.home-cta a'); await t2.evaluate(el => el.scrollIntoView({block:'center'})); await t2.click({ force: true, position: { x: 6, y: 6 } }); await done2; await page.waitForTimeout(300);
await page.screenshot({ path: '/tmp/claude-0/-home-user-edittrace/4fcfd608-18f7-5b1d-a5f6-5cfd8f6e474c/scratchpad/mobile.png' });
console.log('console issues:', JSON.stringify(errors, null, 1));
await browser.close();
