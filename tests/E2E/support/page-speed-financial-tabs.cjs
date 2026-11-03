'use strict';

const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('playwright');
const audit = require('../page-speed-audit.cjs');
const { root, workDir, reportDir, storageState } = audit.paths;
const bootstrap = JSON.parse(fs.readFileSync(path.join(workDir, 'bootstrap.json'), 'utf8'));
const progress = JSON.parse(fs.readFileSync(path.join(workDir, 'progress.json'), 'utf8'));
const output = path.join(workDir, 'financial-tab-results.json');
const variants = [
  { name: 'customers.show', module: 'M03 Parties', uri: 'customers/{customer}', selector: '[data-party-financial] [data-financial-summary]' },
  { name: 'suppliers.show', module: 'M03 Parties', uri: 'suppliers/{supplier}', selector: '[data-party-financial] [data-financial-summary]' },
];

function freePort() { return new Promise((resolve,reject) => { const s=net.createServer();s.once('error',reject);s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));}); }); }

async function main() {
  if (bootstrap.dbName !== 'erp_elec_new_test' || bootstrap.dbPort !== 55461) throw new Error('Refusing nonperformance database.');
  const baseSamples = progress.results;
  const cases = variants.map(route => {
    const row = baseSamples.find(item => item.route_name === route.name && item.temperature === 'cold' && item.viewport === 'desktop' && item.url);
    let url = row?.url;
    if (!url) {
      const model = route.name === 'customers.show' ? 'Customer' : 'Supplier';
      const isolatedEnv = { ...process.env, ...bootstrap.env };
      const expression = `echo \\App\\Modules\\Parties\\Domain\\${model}::query()->orderBy('id')->value('id');`;
      let query = spawnSync('php', ['artisan','tinker','--execute',expression], { cwd: root, env: isolatedEnv, encoding: 'utf8', windowsHide: true });
      if (query.status !== 0) throw new Error(`Could not look up isolated ${model} fixture ID.`);
      let id = String(query.stdout || '').match(/(?:^|\n)(\d+)\s*$/)?.[1];
      if (!id && model === 'Supplier') {
        const create = `$s=\\App\\Modules\\Parties\\Domain\\Supplier::query()->where('code','DEMO-EG-PERF-FIN-TABS')->first(); if(!$s) $s=\\App\\Modules\\Parties\\Domain\\Supplier::factory()->create(['code'=>'DEMO-EG-PERF-FIN-TABS','name'=>'مورد تجريبي لقياس ملخص الطرف المالي']); echo $s->id;`;
        query = spawnSync('php', ['artisan','tinker','--execute',create], { cwd: root, env: isolatedEnv, encoding: 'utf8', windowsHide: true, maxBuffer: 4 * 1024 * 1024 });
        if (query.status !== 0) throw new Error(`Could not create a synthetic supplier through its factory: ${String(query.stderr || query.stdout).slice(0,800)}`);
        id = String(query.stdout || '').match(/(?:^|\n)(\d+)\s*$/)?.[1];
      }
      if (!id) throw new Error(`No valid isolated ${model} fixture ID exists; leave ${route.name} tab unmeasured.`);
      url = `/${route.name.split('.')[0]}/${id}`;
    }
    return { ...route, url: `${url}${url.includes('?') ? '&' : '?'}tab=financial` };
  });
  const port = await freePort(), baseUrl = `http://127.0.0.1:${port}`;
  const env = { ...bootstrap.env, APP_URL: baseUrl, AUDIT_QUERY_LOG: '' };
  const log = fs.openSync(path.join(bootstrap.logs, `financial-tabs-${progress.run_id}.log`), 'w');
  const server = spawn('php',['-S',`127.0.0.1:${port}`,'-t','.',path.join(root,'tests/E2E/support/performance-router.php')],{cwd:path.join(root,'public'),env,stdio:['ignore',log,log],windowsHide:true});
  let browser; const results=[];
  try {
    await audit.waitForServer(baseUrl);
    browser=await chromium.launch({headless:true,executablePath:audit.browserExecutable()});
    for (const viewport of [{name:'desktop',width:1440,height:900},{name:'mobile',width:390,height:844}]) for (const route of cases) {
      const sampleRoute={name:route.name,route_variant:'financial-tab',variant:'financial-tab',module:route.module,uri:route.uri,url:route.url};
      const cold=await browser.newContext({baseURL:baseUrl,storageState,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport});
      const coldPage=await cold.newPage();
      let row=await audit.measurePage(coldPage,baseUrl,sampleRoute,viewport,'cold',0);
      const ready=await coldPage.locator(route.selector).first().isVisible().catch(()=>false);
      row.financial_tab_ready=ready;
      if(!ready){row.measured=false;row.healthy=false;row.not_measured_reason='Financial tab markers did not become visible';}
      results.push(row); await cold.close();

      const warm=await browser.newContext({baseURL:baseUrl,storageState,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport});
      const warmPage=await warm.newPage();
      await warmPage.goto(`${baseUrl}${route.url}`,{waitUntil:'domcontentloaded',timeout:45000});
      await warm.storageState({path:storageState});
      for(let trial=1;trial<=3;trial++){
        row=await audit.measurePage(warmPage,baseUrl,sampleRoute,viewport,'warm',trial);
        const tabReady=await warmPage.locator(route.selector).first().isVisible().catch(()=>false);
        row.financial_tab_ready=tabReady;
        if(!tabReady){row.measured=false;row.healthy=false;row.not_measured_reason='Financial tab markers did not become visible';}
        results.push(row); await warm.storageState({path:storageState});
      }
      await warm.close();
      fs.writeFileSync(output,JSON.stringify({run_id:progress.run_id,updated_at:new Date().toISOString(),results},null,2)+'\n');
    }
    process.stdout.write(JSON.stringify({status:'complete',samples:results.length,measured:results.filter(x=>x.measured).length,financial_tabs:cases.map(x=>x.name),output})+'\n');
  } finally { if(browser) await browser.close().catch(()=>{}); if(server.exitCode===null) server.kill(); try{fs.closeSync(log);}catch{} }
}
main().catch(error=>{process.stderr.write(`${error.stack||error}\n`);process.exitCode=1;});
