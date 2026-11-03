'use strict';

// Database-free browser contract for the shared app.js form enhancements.
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

const html = `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/build/${cssEntry.file}"><style>
*{box-sizing:border-box}body{margin:0;font:16px sans-serif}.wrap{width:min(100%,480px);padding:16px}.row{margin:20px 0}select,input,button{font:inherit;max-width:100%}[data-erp-select-ui][hidden]{display:none}
</style></head><body class="erp-shell"><main class="wrap erp-main" wire:id="browser-fixture">
<form id="form" wire:submit="save"><button type="submit">حفظ</button><label for="city">المدينة</label><erp-select class="row"><select id="city" name="city" wire:model="form.city" aria-describedby="city-error"><option value="">اختر المدينة</option>${['Cairo', 'Alexandria', 'Giza', 'Mansoura', 'Tanta', 'Aswan', 'Luxor', 'Suez', 'Fayoum', 'Ismailia'].map(x => `<option value="${x.toLowerCase()}">${x}</option>`).join('')}<option value="cairo-ar">القاهرة</option></select><div data-erp-select-ui wire:ignore hidden></div><div id="city-error" data-erp-field-error data-error-field="form.city">المدينة مطلوبة</div></erp-select>
<label for="small">مصغر</label><erp-select><select id="small" name="small" wire:model="form.small"><option value="">اختر</option><option value="a">أ</option><option value="b">ب</option></select><div data-erp-select-ui wire:ignore hidden></div></erp-select>
<erp-select><select id="many" multiple wire:model="form.many"><option>واحد</option><option>اثنان</option><option>ثلاثة</option><option>أربعة</option><option>خمسة</option><option>ستة</option><option>سبعة</option><option>ثمانية</option></select><div data-erp-select-ui wire:ignore hidden></div></erp-select>
<erp-select><select id="locked" disabled wire:model="form.locked">${Array.from({ length: 9 }, (_, i) => `<option value="${i}">Option ${i}</option>`).join('')}</select><div data-erp-select-ui wire:ignore hidden></div></erp-select>
<div data-erp-validation role="alert" tabindex="-1"><button type="button" data-error-field="form.city">راجع المدينة</button></div><a id="leave" href="/">مغادرة</a></form></main>
<script>window.Livewire={interceptAction(callback){this.actionInterceptor=callback},interceptMessage(callback){this.messageInterceptor=callback}};</script><script type="module" src="/build/${appEntry.file}"></script></body></html>`;

const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://127.0.0.1');
    if (url.pathname === '/') { res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' }); res.end(html); return; }
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
        page = await browser.newPage({ viewport: { width: 390, height: 844 }, isMobile: true, locale: 'ar-EG' });
        page.setDefaultTimeout(6000);
        const errors = [];
        page.on('pageerror', e => errors.push(e.message));
        await page.goto(`http://127.0.0.1:${server.address().port}/`);
        const city = page.locator('erp-select').first();
        const trigger = city.locator('[data-erp-select-trigger]');
        await assert.doesNotReject(() => trigger.waitFor({ state: 'visible', timeout: 1500 }), 'large single select should be enhanced');
        assert.equal(await page.locator('#small').isVisible(), true, 'small select remains native');
        assert.equal(await page.locator('#many').isVisible(), true, 'multiple select remains native');
        assert.equal(await page.locator('#locked').isDisabled(), true, 'disabled native select remains disabled');
        assert.equal(await page.locator('erp-select').nth(3).locator('[data-erp-select-trigger]').isDisabled(), true, 'disabled enhanced select disables its trigger');

        await page.locator('#form').evaluate(form => {
            window.selectEvents = { input: 0, change: 0 };
            form.addEventListener('input', event => { if (event.target.id === 'city') window.selectEvents.input++; });
            form.addEventListener('change', event => { if (event.target.id === 'city') window.selectEvents.change++; });
        });
        await trigger.click();
        const popup = city.locator('[popover][role="dialog"]');
        assert.equal(await popup.count(), 1, 'enhanced select exposes a native popover dialog');
        const search = popup.locator('input.erp-select-search[role="combobox"]');
        await page.waitForFunction(() => document.querySelector('erp-select [popover]')?.matches(':popover-open'));
        assert.equal(await page.locator('#form').getAttribute('data-erp-dirty'), null, 'opening or searching does not dirty an unchanged business form');
        await search.fill('Cairo');
        const cairo = popup.locator('[role="option"][aria-selected="false"]', { hasText: 'Cairo' });
        await cairo.click();
        assert.equal(await page.locator('#city').inputValue(), 'cairo', 'selecting an enhanced option updates the native value');
        assert.deepEqual(await page.evaluate(() => window.selectEvents), { input: 1, change: 1 }, 'selection emits bubbling input and change events once');
        assert.equal(await page.locator('#city').getAttribute('aria-invalid'), null, 'valid selection does not mark native select invalid');
        await trigger.click();
        await search.fill('nomatch');
        assert.equal(await popup.locator('[role="status"]').isVisible(), true, 'unmatched search has a status message');
        await search.fill('القاهرة');
        const cairoArabic = popup.locator('[role="option"]', { hasText: 'القاهرة' });
        assert.equal(await cairoArabic.count(), 1, 'search matches Arabic option text');
        await cairoArabic.click();
        assert.equal(await page.locator('#city').inputValue(), 'cairo-ar', 'Arabic result selects its native value');

        await trigger.click();
        await search.fill('Alexandria');
        await search.press('ArrowDown');
        await search.press('ArrowUp');
        await search.press('Enter');
        assert.equal(await page.locator('#city').inputValue(), 'alexandria', 'keyboard can select a filtered option');
        await trigger.click();
        await page.waitForFunction(() => document.querySelector('erp-select [popover]')?.matches(':popover-open'));
        await search.fill('');
        await page.waitForFunction(() => document.querySelector('erp-select [role="listbox"]')?.children.length === 12);
        await search.press('Home');
        assert.equal(await search.getAttribute('aria-activedescendant'), await popup.locator('[role="option"]').first().getAttribute('id'), 'Home activates the first visible option');
        await search.press('End');
        assert.equal(await search.getAttribute('aria-activedescendant'), await popup.locator('[role="option"]').last().getAttribute('id'), 'End activates the final visible option');
        await search.press('Enter');
        assert.equal(await page.locator('#city').inputValue(), 'cairo-ar', 'End moves to the final option and Enter selects it');
        await trigger.click();
        await search.press('Escape');
        assert.equal(await popup.evaluate(el => el.matches(':popover-open')), false, 'Escape closes the popup');
        await trigger.click();
        await search.press('Tab');
        assert.equal(await popup.evaluate(el => el.matches(':popover-open')), false, 'Tab closes the popup');
        assert.notEqual(await search.evaluate(el => el === document.activeElement), true, 'Tab moves focus out of the search field');
        await trigger.click();
        await page.locator('body').click({ position: { x: 5, y: 5 } });
        assert.equal(await popup.evaluate(el => el.matches(':popover-open')), false, 'outside click closes the popup');
        const select = page.locator('#city');
        await select.evaluate(el => el.replaceChildren(...[...el.options].map(option => new Option(option.textContent, option.value, option.value === 'cairo', option.value === 'cairo'))));
        await page.waitForFunction(() => document.querySelector('#city').value === 'cairo');
        assert.match(await trigger.textContent(), /Cairo/, 'selected state refreshes after native option morph');
        await trigger.click();
        await page.locator('#city').evaluate(select => select.add(new Option('New Cairo', 'new-cairo')));
        const newOption = popup.locator('[role="option"]', { hasText: 'New Cairo' });
        await newOption.waitFor({ state: 'visible' });
        await newOption.click();
        assert.equal(await page.locator('#city').inputValue(), 'new-cairo', 'new native options are reflected after a DOM refresh');
        await page.locator('[data-erp-validation] [data-error-field="form.city"]').click();
        assert.equal(await page.locator('#city').getAttribute('aria-invalid'), 'true', 'summary marks its exact wire:model field invalid');
        assert.equal(await page.locator('#city').evaluate(el => el === document.activeElement || el.closest('erp-select').querySelector('[data-erp-select-trigger]') === document.activeElement), true, 'summary focuses the invalid control');
        assert.match(await page.locator('#city').getAttribute('aria-describedby'), /city-error/, 'invalid field keeps its inline error description');
        assert.equal(await page.locator('body').evaluate(el => el.scrollWidth <= innerWidth), true, 'RTL mobile fixture has no horizontal overflow');

        const lockedTrigger = page.locator('erp-select').nth(3).locator('[data-erp-select-trigger]');
        await page.locator('#locked').evaluate(el => el.disabled = false);
        await lockedTrigger.waitFor({ state: 'visible' });
        await page.waitForFunction(() => !document.querySelector('erp-select:nth-of-type(4) [data-erp-select-trigger]')?.disabled);
        await page.locator('#locked').evaluate(el => el.disabled = true);
        await page.waitForFunction(() => document.querySelector('#locked').closest('erp-select').querySelector('[data-erp-select-trigger]')?.disabled);
        assert.equal(await page.locator('#small').isVisible(), true, 'small select remains native');
        assert.equal(await page.locator('#many').isVisible(), true, 'multiple select remains native');
        assert.deepEqual(errors, [], 'app bundle has no browser runtime errors');

        const filterFailure = await page.evaluate(() => {
            const root = document.querySelector('[wire\\:id]');
            root.append(document.createElement('section'));
            root.lastElementChild.setAttribute('data-erp-filters', '');
            const hooks = {};
            Livewire.messageInterceptor({ message: { component: { el: root }, actions: new Set([{ name: '$commit' }]) },
                onSend: fn => hooks.send = fn, onSuccess() {}, onError: fn => hooks.error = fn, onFailure: fn => hooks.failure = fn });
            hooks.send();
            let handled = false;
            hooks.error({ response: { status: 500 }, body: '<b>private diagnostic</b>', preventDefault() { handled = true; } });
            return { handled, message: root.querySelector('[data-erp-request-error]')?.textContent };
        });
        assert(filterFailure.handled, 'Set-backed filter messages handle server errors safely');
        assert(!filterFailure.message.includes('private diagnostic'), 'filter errors never expose server diagnostics');

        for (const failure of [{ kind: 'http', status: 500, body: '<img src=x onerror=alert(1)>' }, { kind: 'network' }, { kind: 'http', status: 419, body: '{}' }]) {
            await page.evaluate(({ kind, status, body }) => {
                const action = { name: 'save', params: [], component: { id: 'fixture', el: document.querySelector('[wire\\:id]') }, origin: { el: document.querySelector('#form button[type="submit"]'), directive: { value: 'submit' } }, cancel() { this.cancelled = true; } };
                const hooks = {};
                const install = candidate => window.Livewire.actionInterceptor({ action: candidate, onSend: fn => hooks.send = fn, onSuccess: fn => hooks.success = fn, onError: fn => hooks.error = fn, onFailure: fn => hooks.failure = fn, onFinish: fn => hooks.finish = fn });
                window.duplicateCancelled = false;
                install(action);
                install({ ...action, cancel() { this.cancelled = true; window.duplicateCancelled = true; } });
                hooks.send?.();
                if (kind === 'http') hooks.error?.({ response: { status }, body, preventDefault() { window.failureHandled = true; } });
                else hooks.failure?.();
                hooks.finish?.();
                window.lastFailureState = { value: document.querySelector('#city').value, dirty: document.querySelector('#form').hasAttribute('data-erp-dirty'), handled: window.failureHandled, error: document.querySelector('[data-erp-request-error]')?.textContent, injected: !!document.querySelector('[data-erp-request-error] img') };
            }, failure);
            const result = await page.evaluate(() => window.lastFailureState);
            assert.equal(result.value, 'new-cairo', `${failure.kind} failure preserves selected user input`);
            assert.equal(result.dirty, true, `${failure.kind} failure retains dirty state`);
            assert.equal(result.injected, false, `${failure.kind} failure never inserts a response body as HTML`);
            assert.equal(await page.evaluate(() => window.duplicateCancelled), true, 'duplicate in-flight submit is cancelled');
            if (failure.status === 500) assert.equal(result.handled, true, 'HTTP failure is intercepted without automatic retry');
            if (failure.status === 419) assert.match(result.error, /انتهت الجلسة/);
        }

        await page.setViewportSize({ width: 1440, height: 900 });
        assert.equal(await page.locator('body').evaluate(el => el.scrollWidth <= innerWidth), true, 'desktop fixture has no horizontal overflow');
        let unloadPrompted = false;
        const prompt = new Promise(resolve => page.once('dialog', async dialog => { unloadPrompted = dialog.type() === 'beforeunload'; await dialog.dismiss(); resolve(); }));
        await page.locator('#leave').click({ noWaitAfter: true });
        await prompt;
        assert.equal(unloadPrompted, true, 'edited form warns before navigating away');
        console.log('PASS shared select browser contract (Edge/Playwright; 390px RTL + 1440px; no database).');
    } finally {
        if (page && !page.isClosed()) await page.close({ runBeforeUnload: false });
        await browser.close();
        server.closeAllConnections();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error.stack || error); process.exitCode = 1; });
