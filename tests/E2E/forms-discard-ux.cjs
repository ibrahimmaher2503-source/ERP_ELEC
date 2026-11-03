'use strict';

// Database-free Edge browser contract for dirty-form discard protection.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');
const manifestPath = path.join(root, 'public/build/manifest.json');
const edge = process.env.PLAYWRIGHT_EXECUTABLE_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
const appEntry = manifest['resources/js/app.js'];
const cssEntry = manifest['resources/css/app.css'];
if (!appEntry?.file || appEntry.file.includes('..')) throw new Error('Build manifest has no safe app.js entry. Run npm run build.');
if (!cssEntry?.file || cssEntry.file.includes('..')) throw new Error('Build manifest has no safe app.css entry. Run npm run build.');

const html = `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/build/${cssEntry.file}"><style>*{box-sizing:border-box}body{margin:0;font:16px sans-serif}main{padding:24px}label,button,input{font:inherit}.row{margin:16px 0}</style></head><body class="erp-shell">
<main id="discard-fixture" class="erp-main" wire:id="discard-fixture">
<button id="open" type="button">فتح التعديل</button><a id="leave" href="/next">مغادرة</a>
<dialog id="editor" aria-label="تعديل السجل">
  <form id="editor-form" class="space-y-4" wire:submit="save">
    <label for="name">الاسم</label><input id="name" name="form.name" wire:model="form.name" value="الأصلي">
    <button id="save" type="submit">حفظ</button>
    <button id="cancel-action" type="button" wire:click="cancel">إلغاء</button>
    <button id="edit-action" type="button" wire:click="edit">تعديل سجل آخر</button>
    <button id="new-action" type="button" wire:click="newRecord">سجل جديد</button>
    <button id="reset-action" type="button" wire:click="resetForm">إعادة النموذج</button>
  </form>
  <form method="dialog"><button id="close-x" type="submit" formmethod="dialog" data-flux-modal-close aria-label="إغلاق">×</button></form>
</dialog>
</main>
<script>
window.Livewire = {
  interceptAction(callback) { this.actionInterceptor = callback; },
  interceptMessage(callback) { this.messageInterceptor = callback; },
  async run(name, origin, hold = false) {
    const action = { name, params: [], component: { id: 'discard-fixture', el: document.getElementById('discard-fixture') }, origin: { el: origin, directive: { value: origin.type === 'submit' ? 'submit' : 'click' } }, cancelled: false, cancel() { this.cancelled = true; } };
    const hooks = {};
    this.actionInterceptor?.({ action, onSend(fn) { hooks.send = fn; }, onSuccess(fn) { hooks.success = fn; }, onError(fn) { hooks.error = fn; }, onFailure(fn) { hooks.failure = fn; }, onFinish(fn) { hooks.finish = fn; } });
    hooks.send?.();
    if (action.cancelled) return { cancelled: true };
    window.executedActions = window.executedActions || {};
    window.executedActions[name] = (window.executedActions[name] || 0) + 1;
    if (hold) { window.finishPending = () => { hooks.success?.(); hooks.finish?.(); }; return { pending: true }; }
    hooks.success?.();
    if (name === 'cancel' || name === 'resetForm' || name === 'newRecord' || name === 'edit') {
      document.querySelector('#name').value = name === 'edit' ? 'سجل آخر' : '';
      document.querySelector('#editor').close();
    }
    hooks.finish?.();
    return { cancelled: false };
  }
};
document.querySelector('#open').addEventListener('click', () => document.querySelector('#editor').showModal());
document.querySelector('#editor').addEventListener('click', event => {
  if (event.defaultPrevented || event.target !== event.currentTarget) return;
  const rect = event.currentTarget.getBoundingClientRect();
  if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) event.currentTarget.close();
}, true);
document.addEventListener('click', event => {
  const button = event.target.closest('button');
  if (button?.hasAttribute('wire:click')) { event.preventDefault(); window.Livewire.run(button.getAttribute('wire:click'), button); }
});
document.querySelector('#editor-form').addEventListener('submit', event => { event.preventDefault(); window.Livewire.run('save', event.submitter, window.holdSave === true); });
</script><script type="module" src="/build/${appEntry.file}"></script></body></html>`;

const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://127.0.0.1');
    if (url.pathname === '/' || url.pathname === '/next') { res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' }); res.end(html); return; }
    const relative = url.pathname.replace(/^\/+/, '').replaceAll('/', path.sep);
    const file = path.resolve(root, 'public', relative);
    if (!file.startsWith(path.resolve(root, 'public') + path.sep) || !fs.existsSync(file)) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'content-type': file.endsWith('.css') ? 'text/css' : 'text/javascript' }); fs.createReadStream(file).pipe(res);
});

(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const browser = await chromium.launch({ executablePath: edge, headless: true });
    let page;
    try {
        page = await browser.newPage({ viewport: { width: 390, height: 844 }, locale: 'ar-EG' });
        page.setDefaultTimeout(6000);
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(`http://127.0.0.1:${server.address().port}/`);
        await page.locator('#open').click();
        await page.locator('#name').fill('مسودة غير محفوظة');

        const confirms = [];
        let nextDecision = 'dismiss';
        page.on('dialog', async dialog => {
            const decision = nextDecision;
            nextDecision = 'dismiss';
            confirms.push({ type: dialog.type(), decision });
            if (dialog.type() === 'confirm') await dialog[decision]();
            else await dialog.dismiss();
        });
        await page.locator('#close-x').click();
        assert.equal(confirms.length, 1, 'closing a dirty native dialog asks before discarding');
        assert.deepEqual(confirms.shift(), { type: 'confirm', decision: 'dismiss' });
        assert.equal(await page.locator('#editor').evaluate(dialog => dialog.open), true, 'dismissing X confirmation preserves the open dialog');
        assert.equal(await page.locator('#name').inputValue(), 'مسودة غير محفوظة', 'dismissing X confirmation preserves input');
        nextDecision = 'accept';
        await page.locator('#close-x').click();
        assert.equal(confirms.length, 1, 'second X close asks once');
        assert.deepEqual(confirms.shift(), { type: 'confirm', decision: 'accept' });
        await page.waitForFunction(() => !document.querySelector('#editor').open);
        assert.equal(await page.locator('#editor-form').getAttribute('data-erp-dirty'), null, 'accepted X close clears dirty state');

        await page.locator('#open').click();
        await page.locator('#name').fill('مسودة قابلة للإلغاء');
        await page.locator('#cancel-action').click();
        assert.equal(confirms.length, 1, 'wire:click cancel asks before discarding');
        assert.deepEqual(confirms.shift(), { type: 'confirm', decision: 'dismiss' });
        assert.equal(await page.locator('#editor').evaluate(dialog => dialog.open), true, 'dismissing action confirmation preserves dialog');
        assert.equal(await page.locator('#name').inputValue(), 'مسودة قابلة للإلغاء', 'dismissing action confirmation preserves input');

        for (const [id, action] of [['edit-action', 'edit'], ['new-action', 'newRecord'], ['reset-action', 'resetForm']]) {
            await page.locator(`#${id}`).click();
            assert.equal(confirms.length, 1, `${id} confirms before replacing dirty form data`);
            assert.deepEqual(confirms.shift(), { type: 'confirm', decision: 'dismiss' });
            assert.equal(await page.locator('#editor').evaluate(dialog => dialog.open), true, `${id} dismissal leaves editor open`);
            assert.equal(await page.locator('#name').inputValue(), 'مسودة قابلة للإلغاء', `${id} dismissal keeps the draft`);
            const before = await page.evaluate(actionName => window.executedActions?.[actionName] || 0, action);
            nextDecision = 'accept';
            await page.locator(`#${id}`).click();
            assert.equal(confirms.length, 1, `${id} asks once before proceeding`);
            assert.deepEqual(confirms.shift(), { type: 'confirm', decision: 'accept' });
            await page.waitForFunction(() => !document.querySelector('#editor').open);
            assert.equal(await page.evaluate(actionName => window.executedActions?.[actionName] || 0, action), before + 1, `${id} runs once after confirmation`);
            assert.equal(await page.locator('#editor-form').getAttribute('data-erp-dirty'), null, `${id} clears dirty state after closing`);
            await page.locator('#open').click();
            await page.locator('#name').fill('مسودة قابلة للإلغاء');
        }

        await page.locator('#cancel-action').click();
        assert.equal(confirms.length, 1, 'cancel button asks before discarding');
        assert.deepEqual(confirms.shift(), { type: 'confirm', decision: 'dismiss' });
        assert.equal(await page.locator('#editor').evaluate(dialog => dialog.open), true, 'dismissing cancel-button confirmation leaves editor open');
        assert.equal(await page.locator('#name').inputValue(), 'مسودة قابلة للإلغاء', 'dismissing cancel-button confirmation preserves the draft');
        const cancelRuns = await page.evaluate(() => window.executedActions?.cancel || 0);
        nextDecision = 'accept';
        await page.locator('#cancel-action').click();
        assert.equal(confirms.length, 1, 'cancel asks once before proceeding');
        assert.deepEqual(confirms.shift(), { type: 'confirm', decision: 'accept' });
        await page.waitForFunction(() => !document.querySelector('#editor').open);
        assert.equal(await page.evaluate(() => window.executedActions?.cancel || 0), cancelRuns + 1, 'cancel action runs once after confirmation');
        assert.equal(await page.locator('#editor-form').getAttribute('data-erp-dirty'), null, 'accepted cancel closes and clears dirty state');
        assert.equal(await page.locator('#name').inputValue(), '', 'accepted cancel resets the discarded field');

        await page.locator('#open').click();
        assert.equal(await page.locator('#editor-form').getAttribute('data-erp-dirty'), null, 'reopening a reset form does not restore dirty state');
        await page.locator('#name').fill('حالة جديدة');
        nextDecision = 'dismiss';
        await page.keyboard.press('Escape');
        assert.equal(confirms.length, 1, 'Escape cancel asks before discarding');
        assert.deepEqual(confirms.shift(), { type: 'confirm', decision: 'dismiss' });
        assert.equal(await page.locator('#editor').evaluate(dialog => dialog.open), true, 'dismissing Escape confirmation keeps dialog open');
        assert.equal(await page.locator('#name').inputValue(), 'حالة جديدة', 'dismissing Escape confirmation keeps input');

        const rect = await page.locator('#editor').evaluate(dialog => { const r = dialog.getBoundingClientRect(); return { x: r.x, y: r.y, width: r.width, height: r.height }; });
        await page.mouse.click(rect.x + 2, rect.y + 2);
        assert.equal(confirms.length, 0, 'clicking dialog padding does not ask to discard');
        const backdropX = rect.x > 5 ? rect.x - 4 : Math.min(388, rect.x + rect.width + 4);
        await page.mouse.click(backdropX, Math.min(842, rect.y + rect.height / 2));
        assert.equal(confirms.length, 1, 'backdrop close asks before discarding');
        assert.deepEqual(confirms.shift(), { type: 'confirm', decision: 'dismiss' });
        assert.equal(await page.locator('#editor').evaluate(dialog => dialog.open), true, 'dismissing backdrop confirmation keeps dialog open');

        await page.locator('#save').click();
        await page.waitForFunction(() => document.querySelector('#editor-form').getAttribute('data-erp-dirty') === null);
        await page.locator('#editor-form').evaluate(form => form.querySelector('#name').value = 'pending draft');
        await page.locator('#name').dispatchEvent('input');
        await page.evaluate(() => window.holdSave = true);
        await page.locator('#save').click();
        assert.equal(await page.locator('#editor-form').getAttribute('aria-busy'), 'true', 'pending submit is marked busy');
        await page.locator('#cancel-action').click();
        assert.equal(confirms.length, 0, 'pending submit does not allow a discard confirmation before outcome is known');
        assert.equal(await page.locator('#editor').evaluate(dialog => dialog.open), true, 'pending submit keeps modal open');
        await page.evaluate(() => window.finishPending?.());

        await page.locator('#name').fill('navigate dirty');
        nextDecision = 'accept';
        await page.locator('#close-x').click();
        assert.equal(confirms.length, 1, 'X close asks before discarding navigation draft');
        assert.deepEqual(confirms.shift(), { type: 'confirm', decision: 'accept' });
        await page.waitForFunction(() => !document.querySelector('#editor').open);
        const dialogsBeforeNavigation = confirms.length;
        await page.locator('#leave').click();
        assert.equal(confirms.length, dialogsBeforeNavigation, 'closed and reset form does not leave a stale dirty navigation prompt');
        assert.deepEqual(errors, [], 'shared app bundle has no browser runtime errors');
        console.log('PASS dirty-form discard browser contract (Edge/Playwright; 390px RTL; no database).');
    } finally {
        if (page && !page.isClosed()) await page.close({ runBeforeUnload: false });
        await browser.close();
        server.closeAllConnections?.();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error.stack || error); process.exitCode = 1; });
