'use strict';
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('C:/Users/N/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const root = path.resolve(__dirname, '../..');
const port = 8017, baseUrl = `http://127.0.0.1:${port}`;
const edge = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const fixtureScript = path.join(__dirname, 'support/prepare-m05-m06-reopen.php');
const output = path.join(root, 'docs/qa/evidence/m05-m06-browser');
function env(temp) { return { PATH:process.env.PATH||'',SystemRoot:process.env.SystemRoot||'',WINDIR:process.env.WINDIR||'',TEMP:temp,TMP:temp,APP_ENV:'testing',APP_URL:baseUrl,APP_DEBUG:'false',DB_CONNECTION:'pgsql',DB_DATABASE:'erp_elec_new_test',DB_URL:'',SESSION_DRIVER:'database',SESSION_LIFETIME:'30',CACHE_STORE:'array',QUEUE_CONNECTION:'sync',MAIL_MAILER:'array',LOG_CHANNEL:'stderr',BROADCAST_CONNECTION:'null',BCRYPT_ROUNDS:'4',VIEW_COMPILED_PATH:path.join(temp,'views') }; }
function fixture(mode,input,temp) { const r=spawnSync('php',[fixtureScript,mode],{cwd:root,env:env(temp),input:JSON.stringify(input),encoding:'utf8',windowsHide:true}); if(r.error||r.status!==0) throw new Error('Guarded M05/M06 PostgreSQL fixture failed: '+(r.stderr||'').slice(-1000)); return JSON.parse(r.stdout); }
async function save(page) { const response=page.waitForResponse(r=>r.url().endsWith('/update')&&r.request().method()==='POST',{timeout:20000}); await page.locator('form button[type="submit"]').last().click(); const r=await response; assert.equal(r.status(),200,'Livewire save response succeeds'); await page.waitForFunction(()=>[...document.querySelectorAll('form button[type="submit"]')].every(button=>!button.disabled)); await page.waitForTimeout(250); }
async function login(page,email,password) { await page.goto(baseUrl+'/login'); await page.locator('input[type="email"]').fill(email); await page.locator('input[type="password"]').fill(password); await page.locator('form button[type="submit"]').click(); await page.waitForURL(url=>!url.pathname.includes('/login'),{timeout:15000}); }
async function main() {
  if(!fs.existsSync(edge)) throw new Error('Microsoft Edge executable unavailable.');
  const probe=http.createServer(); await new Promise((resolve,reject)=>{probe.once('error',reject);probe.listen(port,'127.0.0.1',()=>probe.close(resolve));});
  fs.mkdirSync(output,{recursive:true}); const temp=fs.mkdtempSync(path.join(os.tmpdir(),'erp-m05-m06-e2e-')); fs.mkdirSync(path.join(temp,'views'),{recursive:true});
  const password=crypto.randomBytes(36).toString('base64url'); const data=fixture('prepare',{password},temp); const serverLog=fs.openSync(path.join(temp,'server.log'),'w'); let server,browser; const run={result:'FAIL',database:'erp_elec_new_test',browser:'Microsoft Edge / Playwright',layout_coverage:'Arabic locale RTL plus synthetic document.dir LTR smoke (not English localization)',checks:[],errors:[]};
  try {
    server=spawn('php',['-S',`127.0.0.1:${port}`,'-t','public',path.join(__dirname,'support/laravel-static-router.php')],{cwd:root,env:env(temp),stdio:['ignore',serverLog,serverLog],windowsHide:true});
    let ready=false; for(let i=0;i<60;i++){if(server.exitCode!==null) throw new Error('Test server exited during startup.'); try{const r=await fetch(baseUrl+'/login'); if(r.status===200){ready=true;break;}}catch{} await new Promise(r=>setTimeout(r,500));} assert.ok(ready,'isolated test server ready');
    browser=await chromium.launch({executablePath:edge,headless:true}); const context=await browser.newContext({viewport:{width:1440,height:900},locale:'ar-EG',timezoneId:'Africa/Cairo'}); const page=await context.newPage();
    page.on('pageerror',e=>run.errors.push(String(e.message).slice(0,300))); page.on('console',m=>{if(m.type()==='error')run.errors.push(m.text().slice(0,300));});
    await login(page,data.email,password);
    for(const [label,viewport,dir] of [['desktop-rtl',{width:1440,height:900},'rtl'],['desktop-ltr',{width:1440,height:900},'ltr'],['mobile-rtl',{width:390,height:844},'rtl'],['mobile-ltr',{width:390,height:844},'ltr']]) {
      await page.setViewportSize(viewport);
      await page.goto(`${baseUrl}/purchasing/orders/${data.order_id}`); await page.locator('textarea[wire\\:model="form.notes"]').waitFor(); await page.evaluate(d=>document.documentElement.dir=d,dir);
      await page.locator('textarea[wire\\:model="form.notes"]').fill(`PO ${label} pass one`); await save(page);
      await page.locator('textarea[wire\\:model="form.notes"]').fill(`PO ${label} pass two`); await save(page);
      await page.evaluate(()=>window.scrollTo(0,0)); await page.screenshot({path:path.join(output,`${label}-purchase-order.png`),fullPage:true});
      await page.goto(`${baseUrl}/inventory/documents/count/${data.count_id}`); await page.locator('textarea[wire\\:model="form.reason"]').waitFor(); await page.evaluate(d=>document.documentElement.dir=d,dir);
      await page.locator('textarea[wire\\:model="form.reason"]').fill(`Count ${label} pass one`); await page.locator('input[wire\\:model="form.lines.0.quantity"]').fill('2.25000000'); await save(page);
      await page.locator('textarea[wire\\:model="form.reason"]').fill(`Count ${label} pass two`); await page.locator('input[wire\\:model="form.lines.0.quantity"]').fill('2.50000000'); await save(page);
      await page.evaluate(()=>window.scrollTo(0,0)); await page.screenshot({path:path.join(output,`${label}-inventory-count.png`),fullPage:true});
      run.checks.push(`${label}: persisted order and inventory count each reopened and saved twice`);
    }
    const exportUrl=`${baseUrl}/purchasing/export/orders?${new URLSearchParams({from:data.date,to:data.date,supplier:data.supplier,status:'draft'})}`;
    const downloadPromise=page.waitForEvent('download',{timeout:20000}); await page.evaluate(url=>{window.location.href=url;},exportUrl); const download=await downloadPromise; const exportPath=path.join(temp,'orders-filter.xlsx'); await download.saveAs(exportPath);
    const exportVerify=fixture('verify-export',{path:exportPath,order_id:data.order_id},temp); assert.equal(exportVerify.rows,1); assert.equal(exportVerify.ids[0],data.order_id); run.checks.push('authorized Purchasing XLSX export applies date, supplier, and draft-status filters to persisted orders');
    const persisted=fixture('verify',{order_id:data.order_id,count_id:data.count_id},temp); assert.equal(persisted.order.version,9); assert.equal(persisted.order.notes,'PO mobile-ltr pass two'); assert.equal(persisted.count.version,9); assert.equal(persisted.count.quantity,'2.50000000'); assert.equal(persisted.count.recorded,'0.00000000'); run.persisted=persisted;
    assert.deepEqual(run.errors,[],'browser reports no page or console errors'); run.result='PASS';
  } catch(e) { run.failure=String(e&&e.stack||e).slice(0,1800); }
  finally { if(browser) await browser.close(); if(server&&server.exitCode===null){server.kill();await new Promise(resolve=>{const t=setTimeout(resolve,1500);server.once('exit',()=>{clearTimeout(t);resolve();});});} try{run.server_log=fs.readFileSync(path.join(temp,'server.log'),'utf8').slice(-2000);}catch{} try{run.cleanup=fixture('cleanup',{email:data.email},temp);run.persistent_fixture_policy='Tagged draft business records remain in the dedicated test DB until reset; the temporary actor is disabled, unverified, and its sessions are revoked.';}catch(e){run.cleanup_failure=String(e.message||e).slice(0,400);run.result='FAIL';} fs.closeSync(serverLog); fs.rmSync(temp,{recursive:true,force:true,maxRetries:5,retryDelay:200}); fs.writeFileSync(path.join(output,'result.json'),JSON.stringify(run,null,2)+'\n'); console.log(JSON.stringify(run,null,2)); }
  if(run.result!=='PASS') process.exitCode=1;
}
main().catch(e=>{console.error(String(e&&e.stack||e));process.exitCode=1;});
