'use strict';

// Isolated app interactions: every implemented filter view, both layouts, and reset failure recovery.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { chromium } = require('playwright');
const root = path.resolve(__dirname, '../..');
const output = path.join(root, '.tmp/filters-ux-20261102');
const originalPrivate = path.join(root, '.tmp/forms-ux-20261102/private');
const bootstrap = JSON.parse(fs.readFileSync(path.join(originalPrivate, 'bootstrap.json'), 'utf8'));
const marker = JSON.parse(fs.readFileSync(path.join(originalPrivate, '.codex-forms-ux-owned.json'), 'utf8'));
const actor = JSON.parse(fs.readFileSync(path.join(output, 'actor.json'), 'utf8'));
const contractFixture = JSON.parse(fs.readFileSync(path.join(output, 'contract.json'), 'utf8'));
assert(Number.isInteger(contractFixture.contract_id) && contractFixture.contract_id > 0);
assert.equal(bootstrap.database, 'erp_elec_new_test');
assert.equal(bootstrap.db_port, 55467);
const check = spawnSync(path.join(root, '.tools/postgresql/pgsql/bin/psql.exe'), ['-h','127.0.0.1','-p','55467','-U','postgres','-d','erp_elec_new_test','-Atqc', "SELECT current_database() || '|' || current_setting('port') || '|' || current_setting('data_directory') || '|' || (SELECT nonce FROM codex_test_guard.ownership_receipts WHERE database=current_database())"], { encoding:'utf8', windowsHide:true });
assert.equal(check.status, 0);
const [database, port, directory, nonce] = check.stdout.trim().split('|');
assert.equal(database, bootstrap.database);
assert.equal(Number(port), bootstrap.db_port);
assert.equal(path.resolve(directory), path.resolve(root, bootstrap.postgres_data_directory));
assert.equal(nonce, marker.nonce);
const base = 'http://127.0.0.1:8017';
const routes = process.env.FILTER_UX_ROUTES?.split(',') || [
  'organization/users','organization/branches','organization/employees','organization/roles',
  'products','products/categories','products/brands','products/units','customers','suppliers','suppliers/categories',
  'quotations','sales-orders','projects','projects/reports','tenders','tenders/dashboard','tenders/reports',
  'purchasing','purchasing/orders','purchasing/requisitions','purchasing/rfqs','purchasing/offers',
  'inventory','inventory/balances','inventory/movements','inventory/warehouses','inventory/serials','inventory/isolations','inventory/corrections',
  'maintenance/dashboard','maintenance/requests','maintenance/work-orders','maintenance/reports',
  'service-contracts','financial/ar','financial/ap','financial/aging','financial/customer-invoices','financial/supplier-invoices','financial/credit-notes',
  'accounting/accounts','accounting/journals','accounting/dashboard','accounting/ledger','accounting/trial-balance','accounting/income-statement','accounting/balance-sheet','accounting/ppv','accounting/grni','accounting/reconciliation',
  'workshops/dashboard','workshops/intakes','workshops/jobs','workshops/reports','treasury/statements',
  `service-contracts/${contractFixture.contract_id}/report`,
];
const report = { database, port:Number(port), assets:JSON.parse(fs.readFileSync(path.join(root,'public/build/manifest.json'),'utf8')), cases:[], interactions:[], errors:[] };
const screenshotDir = path.join(output, 'screenshots', new Date().toISOString().replace(/[:.]/g,'-'));
fs.mkdirSync(screenshotDir, { recursive:true });
const save = () => fs.writeFileSync(path.join(output, 'browser.json'), JSON.stringify(report,null,2));
async function main() {
  const browser = await chromium.launch({ channel:'msedge', headless:true });
  const context = await browser.newContext({ locale:'ar-EG', timezoneId:'Africa/Cairo' });
  const page = await context.newPage();
  page.setDefaultTimeout(12000);
  let errors = [];
  page.on('dialog', dialog => dialog.accept());
  page.on('pageerror', error => errors.push(error.stack || error.message));
  page.on('console', message => { if(message.type()==='error') errors.push(message.text().slice(0,250)); });
  try {
    await page.goto(base+'/login');
    await page.locator('input[type=email]').fill(actor.email);
    await page.locator('input[type=password]').fill(actor.password);
    await page.locator('button[type=submit]').click();
    await page.waitForURL(url => !url.pathname.endsWith('/login'));
    for (const route of routes) for (const width of [1440,390]) {
      errors = [];
      try {
        await page.setViewportSize({ width, height:width===390?844:950 });
        const response = await page.goto(base+'/'+route, {waitUntil:'domcontentloaded'});
        assert.equal(response.status(),200, route+' returns 200');
        await page.waitForFunction(() => window.Alpine && document.querySelector('[data-erp-filters]')?._x_dataStack);
        const snapshot = await page.evaluate(() => {
          const filters = [...document.querySelectorAll('[data-erp-filters]')];
          return {
            rtl:document.documentElement.dir==='rtl', overflow:document.documentElement.scrollWidth>innerWidth+1,
            filters:filters.map(el => {
              const data=Alpine.$data(el); const wire=Livewire.find(el.closest('[wire\\:id]').getAttribute('wire:id'));
              return { id:el.id, count:data.activeCount, keys:Object.keys(data.defaults), missing:Object.keys(data.defaults).filter(key => wire.$get(key)===undefined), reset:!!el.querySelector('[data-erp-filter-reset]') };
            }),
          };
        });
        assert(snapshot.rtl,route+' RTL');
        assert(!snapshot.overflow,route+' no page overflow');
        for (const filter of snapshot.filters) {
          assert(filter.reset, filter.id+' reset control');
          assert.deepEqual(filter.missing,[],filter.id+' bound real fields');
          assert.equal(filter.count,0,filter.id+' original defaults');
        }
        const section=page.locator('[data-erp-filters]').first();
        const toggle=section.locator('[data-erp-filter-toggle]');
        if(width===390 && await toggle.count()) {
          await toggle.focus(); await page.keyboard.press('Enter');
          assert.equal(await toggle.getAttribute('aria-expanded'),'true');
          assert(await section.locator('.erp-filter-body').isVisible());
          await page.keyboard.press('Enter');
          assert.equal(await toggle.getAttribute('aria-expanded'),'false');
        }
        // Use the actual search input where present; otherwise a real report/select filter.
        const search=page.locator('input[wire\\:model\\.live\\.debounce\\.300ms="search"],input[wire\\:model\\.live\\.debounce\\.350ms="search"]').first();
        if(await search.count()) {
          const changed=page.waitForResponse(r=>/\/livewire[^/]*\/update/.test(r.url()) && r.request().method()==='POST');
          await search.fill('UX_FILTER_NO_MATCH'); await changed;
        } else {
          await section.evaluate(async el => {
            const data=Alpine.$data(el);const wire=Livewire.find(el.closest('[wire\\:id]').getAttribute('wire:id'));
            const key=Object.keys(data.defaults).find(key=>el.querySelector(`[wire\\:model\\.live="${key}"]`)?.tagName==='SELECT') || Object.keys(data.defaults)[0];
            const select=el.querySelector(`[wire\\:model\\.live="${key}"]`);
            const value=select?.options?.[1]?.value || (['from','to'].includes(key)?'2020-01-01':actor.branch_id.toString());
            await wire.$set(key,value);
          });
        }
        await page.waitForFunction(() => [...document.querySelectorAll('[data-erp-filters]')].some(el=>Alpine.$data(el).activeCount>0));
        const active=page.locator('[data-erp-filter-reset]:visible').first();
        const resetResponse=page.waitForResponse(r=>/\/livewire[^/]*\/update/.test(r.url()) && r.request().method()==='POST');
        await active.click(); assert.equal((await resetResponse).status(),200);
        await page.waitForFunction(() => [...document.querySelectorAll('[data-erp-filters]')].every(el=>Alpine.$data(el).activeCount===0 && !Alpine.$data(el).clearing));
        const choices=await section.evaluate(el=>[...el.querySelectorAll('select')].map((select,index)=>({
          index,
          key:[...select.attributes].find(attr=>attr.name.startsWith('wire:model'))?.value,
          value:[...select.options].find(option=>option.value!=='' && !option.disabled)?.value,
        })).filter(choice=>choice.key && choice.value));
        if(width===390 && await toggle.count()) await toggle.click();
        for(const choice of choices) {
          const changed=page.waitForResponse(r=>/\/livewire[^/]*\/update/.test(r.url()) && r.request().method()==='POST');
          await section.locator('select').nth(choice.index).selectOption(choice.value,{force:true});
          assert.equal((await changed).status(),200);
        }
        for(const checkbox of await section.locator('ui-checkbox[wire\\:model\\.live]').all()) {
          const changed=page.waitForResponse(r=>/\/livewire[^/]*\/update/.test(r.url()) && r.request().method()==='POST');
          await checkbox.click(); assert.equal((await changed).status(),200);
        }
        if(await section.locator('[data-erp-filter-reset]').isVisible()) {
          await section.locator('[data-erp-filter-reset]').click();
          await page.waitForFunction(() => [...document.querySelectorAll('[data-erp-filters]')].every(el=>Alpine.$data(el).activeCount===0 && !Alpine.$data(el).clearing));
        }
        snapshot.testedSelects=choices.map(choice=>choice.key);
        assert.deepEqual(errors,[],route+' no JavaScript errors');
        if(['products','organization/roles','quotations','workshops/jobs','treasury/statements'].includes(route)) {
          await page.screenshot({path:path.join(screenshotDir,route.replaceAll('/','-')+'-'+width+'.png'),fullPage:true});
        }
        report.cases.push({route,width,...snapshot,status:'PASS'});
      } catch(error) {
        report.cases.push({route,width,status:'FAIL',message:error.message,errors:[...errors]});
        await page.screenshot({path:path.join(screenshotDir,route.replaceAll('/','-')+'-'+width+'-failure.png'),fullPage:true}).catch(()=>{});
      }
      save();
    }
    for (const route of ['quotations','sales-orders','purchasing','accounting/ledger']) {
      await page.goto(base+'/'+route);
      await page.locator('[data-erp-filters]').evaluate(async el => {
        const wire=Livewire.find(el.closest('[wire\\:id]').getAttribute('wire:id'));
        await wire.$set('from','not-a-date');
      });
      await page.locator('[data-erp-validation] [data-error-field="from"]').first().waitFor();
      assert.equal(await page.locator('[data-erp-filters]').evaluate(el=>Livewire.find(el.closest('[wire\\:id]').getAttribute('wire:id')).$get('from')),'not-a-date');
      await page.locator('[data-erp-filter-reset]').click();
      await page.waitForFunction(()=>!document.querySelector('[data-erp-validation] [data-error-field="from"]'));
      report.interactions.push({name:route+' invalid date inline and reset recovery',status:'PASS'});
    }
    await page.goto(base+'/organization/roles');
    const roleUrl=await page.locator('a[href*="/organization/roles/"]').first().getAttribute('href');
    await page.goto(roleUrl);
    const localFilter=page.locator('[data-erp-local-filters]');
    const permissionCount=await page.locator('[data-searchable]').count();
    assert(permissionCount>0);
    await localFilter.locator('input').fill('NO_MATCH_PERMISSION_FILTER');
    await page.waitForFunction(()=>[...document.querySelectorAll('[data-searchable]')].every(el=>!el.getClientRects().length));
    await localFilter.getByRole('status').waitFor({state:'visible'});
    await localFilter.getByRole('button',{name:'مسح الفلاتر'}).click();
    await page.waitForFunction(()=>[...document.querySelectorAll('[data-searchable]')].every(el=>el.getClientRects().length));
    assert.equal(await page.locator('[data-searchable]').count(),permissionCount);
    report.interactions.push({name:'local permission search, empty state and reset without changing grants',status:'PASS'});

    // A filter request must never discard the unsaved bank-import form.
    await page.setViewportSize({width:390,height:844});
    await page.goto(base+'/treasury/statements');
    await page.locator('[wire\\:model="form.external_reference"]').fill('UNSAVED-FILTER-REFERENCE');
    await page.locator('[data-erp-filter-toggle]').click();
    await page.locator('select[wire\\:model\\.live="branch"]').selectOption(String(actor.branch_id));
    await page.waitForFunction(()=>Alpine.$data(document.querySelector('[data-erp-filters]')).activeCount===1);
    await page.waitForTimeout(500);
    let requests=0;
    await page.route('**/livewire*/update',route=>{ requests++; return route.fulfill({status:500,contentType:'text/html',body:'PRIVATE SERVER DIAGNOSTIC'}); });
    await page.locator('[data-erp-filter-reset]').click();
    await page.locator('[data-erp-request-error]').waitFor();
    await page.waitForFunction(()=>!Alpine.$data(document.querySelector('[data-erp-filters]')).clearing);
    assert.equal(await page.locator('[wire\\:model="form.external_reference"]').inputValue(),'UNSAVED-FILTER-REFERENCE');
    assert.equal(await page.locator('select[wire\\:model\\.live="branch"]').inputValue(),String(actor.branch_id));
    assert.equal(requests,1,'no automatic retry');
    assert(!(await page.locator('body').innerText()).includes('PRIVATE SERVER DIAGNOSTIC'),'safe server error');
    await page.unroute('**/livewire*/update');
    await page.locator('[data-erp-filter-reset]').click();
    await page.waitForFunction(()=>Alpine.$data(document.querySelector('[data-erp-filters]')).activeCount===0);
    assert.equal(await page.locator('[wire\\:model="form.external_reference"]').inputValue(),'UNSAVED-FILTER-REFERENCE');
    report.interactions.push({name:'500 recovery, filter restoration, single request, unsaved bank form preserved',status:'PASS'});
    report.status=report.cases.every(row=>row.status==='PASS')?'PASS':'FAIL';
    save();
    assert.equal(report.status,'PASS',JSON.stringify(report.cases.filter(row=>row.status==='FAIL')));
    console.log(`PASS ${report.cases.length} real filter cases, keyboard toggles and failure/dirty preservation.`);
  } finally { save(); await page.close({runBeforeUnload:false}); await browser.close(); }
}
main().catch(error=>{report.status='FAIL';report.errors.push(error.message);save();console.error(error.message);process.exitCode=1;});
