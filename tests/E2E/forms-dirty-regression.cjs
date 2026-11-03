'use strict';

// Owner-approved lead-authored checks for the independent review findings.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require('playwright');
const root = path.resolve(__dirname, '../..');
const html = `<!doctype html><html lang="ar"><body class="erp-shell"><main wire:id="fixture" id="root">
<button id="tab" wire:click="$set('tab', 'overview')">Overview</button>
<form id="task" wire:submit="addTask"><input name="title" value=""><ui-checkbox wire:model="members" value="7" tabindex="0" role="checkbox" aria-checked="false" id="check">Member</ui-checkbox><ui-switch wire:model="active" tabindex="0" role="switch" aria-checked="false" id="switch">Active</ui-switch><button type="submit">Add task</button></form>
<script>window.Livewire={interceptAction(fn){this.action=fn},interceptMessage(){}};
for(const id of ['check','switch']) document.getElementById(id).onclick=function(){this.toggleAttribute('data-checked');this.setAttribute('aria-checked',String(this.hasAttribute('data-checked')));this.dispatchEvent(new Event('change',{bubbles:true}))};
document.getElementById('tab').addEventListener('click',()=>document.getElementById('task').remove());
</script><script type="module" src="/form-ux.js"></script></main></body></html>`;
const server = http.createServer((req, res) => {
    res.setHeader('content-type', req.url === '/form-ux.js' ? 'text/javascript' : 'text/html; charset=utf-8');
    res.end(req.url === '/form-ux.js' ? fs.readFileSync(path.join(root, 'resources/js/form-ux.js')) : html);
});
(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const browser = await chromium.launch({ channel: 'msedge', headless: true });
    const context = await browser.newContext();
    const page = await context.newPage();
    page.setDefaultTimeout(6000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    try {
        await page.goto(`http://127.0.0.1:${server.address().port}/`);
        await page.locator('#check').click();
        assert.equal(await page.locator('#task').getAttribute('data-erp-dirty'), '', 'Flux checkbox changes mark the form dirty');
        await page.locator('#check').click();
        assert.equal(await page.locator('#task').getAttribute('data-erp-dirty'), null, 'reverting a checkbox restores the original state');
        await page.locator('#switch').click();
        assert.equal(await page.locator('#task').getAttribute('data-erp-dirty'), '', 'Flux switch changes mark the form dirty');
        await page.evaluate(() => {
            const hooks = {};
            const form = document.getElementById('task');
            Livewire.action({ action: { name: 'addTask', params: [], component: { id: 'fixture', el: document.getElementById('root') }, origin: { el: form, directive: { value: 'submit' } }, cancel() {} },
                onSend: fn => hooks.send = fn, onSuccess: fn => hooks.success = fn, onError() {}, onFailure() {}, onFinish: fn => hooks.finish = fn });
            hooks.send(); hooks.success(); hooks.finish();
        });
        assert.equal(await page.locator('#task').getAttribute('data-erp-dirty'), null, 'a persisted addTask submission clears dirty state');
        await page.locator('input').fill('Unsaved note');
        let confirmations = 0;
        page.on('dialog', async dialog => { confirmations++; await dialog.dismiss(); });
        await page.locator('#tab').click();
        assert.equal(confirmations, 1, 'switching an in-component tab confirms before removing a dirty form');
        assert.equal(await page.locator('input').inputValue(), 'Unsaved note', 'declining the tab switch keeps the draft');
        assert.deepEqual(errors, []);
        console.log('PASS Flux control dirty tracking, persisted add action, and tab discard regression.');
    } finally {
        await page.close({ runBeforeUnload: false });
        await context.close();
        await browser.close();
        server.closeAllConnections();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error.stack); process.exitCode = 1; });
