'use strict';

// Database-free Edge browser regression for required enhanced-select validation feedback.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'public/build/manifest.json'), 'utf8'));
const appEntry = manifest['resources/js/app.js'];
const cssEntry = manifest['resources/css/app.css'];
const edge = process.env.PLAYWRIGHT_EXECUTABLE_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
if (!appEntry?.file || appEntry.file.includes('..') || !cssEntry?.file || cssEntry.file.includes('..')) throw new Error('Safe app.js/app.css build entries are required.');

const options = ['Cairo', 'Alexandria', 'Giza', 'Mansoura', 'Tanta', 'Aswan', 'Luxor', 'Suez'];
const html = `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/build/${cssEntry.file}"><style>*{box-sizing:border-box}body{margin:0;font:16px sans-serif}.wrap{padding:24px;width:min(100%,500px)}[data-erp-select-ui][hidden]{display:none}</style></head><body class="erp-shell"><main class="wrap erp-main" wire:id="required-select-fixture"><form wire:submit="save"><label for="city">المدينة</label><erp-select><select id="city" required wire:model="form.city" aria-invalid="true" aria-describedby="city-server-error"><option value="">اختر المدينة</option>${options.map((name,index)=>`<option value="${index+1}">${name}</option>`).join('')}</select><div data-erp-select-ui wire:ignore hidden></div></erp-select><p id="city-server-error">تعذر حفظ المدينة من الخادم.</p><div id="plain-field"><label for="plain-city">مدينة أخرى</label><erp-select><select id="plain-city" required wire:model="form.plainCity"><option value="">اختر المدينة</option>${options.map((name,index)=>`<option value="${index+1}">${name}</option>`).join('')}</select><div data-erp-select-ui wire:ignore hidden></div></erp-select></div></form></main><script>window.Livewire={interceptAction(){},interceptMessage(){}};</script><script type="module" src="/build/${appEntry.file}"></script></body></html>`;

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
        page = await browser.newPage({ viewport: { width: 390, height: 844 }, locale: 'ar-EG' });
        page.setDefaultTimeout(6000);
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(`http://127.0.0.1:${server.address().port}/`);
        const select = page.locator('#city');
        const trigger = page.locator('erp-select').first().locator('[data-erp-select-trigger]');
        await trigger.waitFor({ state: 'visible' });
        assert.equal(await select.evaluate(element => element.reportValidity()), false, 'empty required native select fails browser validity');
        const clientError = page.locator('[data-erp-select-error]');
        assert.equal(await clientError.isVisible(), true, 'native invalid event creates a visible inline explanation');
        assert.match(await trigger.getAttribute('aria-describedby'), new RegExp(await clientError.getAttribute('id')));
        assert.equal(await trigger.getAttribute('aria-invalid'), 'true', 'enhanced trigger exposes invalid state');

        await trigger.click();
        const popup = page.locator('erp-select [popover]');
        await popup.locator('[role="option"]', { hasText: 'Cairo' }).click();
        await page.waitForFunction(() => document.querySelector('#city').value === '1');
        assert.equal(await clientError.count(), 0, 'valid selection clears the client-side required message');
        assert.match(await trigger.getAttribute('aria-describedby'), /city-server-error/, 'server validation description remains attached');
        assert.equal(await trigger.getAttribute('aria-invalid'), 'true', 'clearing native client feedback does not clear server invalid state');

        const plain = page.locator('#plain-city');
        const plainTrigger = page.locator('#plain-field [data-erp-select-trigger]');
        assert.equal(await plain.evaluate(element => element.reportValidity()), false);
        assert.equal(await page.locator('#plain-field [data-erp-select-error]').count(), 1, 'first invalid event creates one explanation');
        assert.equal(await plain.evaluate(element => element.reportValidity()), false);
        assert.equal(await page.locator('#plain-field [data-erp-select-error]').count(), 1, 'repeated invalid event does not duplicate the explanation');
        await plainTrigger.click();
        await page.locator('#plain-field [popover] [role="option"]', { hasText: 'Cairo' }).click();
        await page.waitForFunction(() => document.querySelector('#plain-city').value === '1');
        assert.equal(await page.locator('#plain-field [data-erp-select-error]').count(), 0, 'valid choice removes the client-only message');
        assert.equal(await plainTrigger.getAttribute('aria-invalid'), null, 'valid choice clears only client invalid state');

        await plainTrigger.click();
        await page.waitForFunction(() => document.querySelector('#plain-field [popover]')?.matches(':popover-open'));
        await page.locator('#plain-field').evaluate(field => field.remove());
        await page.waitForFunction(() => !document.querySelector('#plain-field'));
        assert.deepEqual(errors, [], 'removing an open select wrapper does not throw');
        console.log('PASS required enhanced-select feedback (Edge/Playwright; 390px RTL; no database).');
    } finally {
        if (page && !page.isClosed()) await page.close({ runBeforeUnload: false });
        await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error.stack || error); process.exitCode = 1; });
