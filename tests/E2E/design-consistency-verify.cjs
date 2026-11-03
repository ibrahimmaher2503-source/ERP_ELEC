// Read-only before/after design consistency capture using existing isolated fixtures.
const fs = require('fs');
const path = require('path');
const net = require('net');
const crypto = require('node:crypto');
const { spawn, spawnSync } = require('child_process');
const { chromium } = require('playwright');
const visual = require('./visual-audit.cjs');
const performance = require('./page-speed-audit.cjs');

const root = visual.root;
function currentBuildAssetsFromManifest(){const m=JSON.parse(fs.readFileSync(path.join(root,'public','build','manifest.json'),'utf8'));return m['resources/css/app.css']?.file?`/build/${m['resources/css/app.css'].file}`:null;}
const viewports = [
  { name:'desktop-1440', width:1440, height:900 },
  { name:'desktop-1366', width:1366, height:768 },
  { name:'tablet-768', width:768, height:1024 },
  { name:'mobile-390', width:390, height:844 },
  { name:'mobile-360', width:360, height:800 },
];
const wanted = [
  ['dashboard','default'], ['customers.show','default'], ['inventory.dashboard','default'],
  ['products.index','default'],
  ['purchasing.orders.index','default'], ['sales-orders.index','default'], ['financial.aging','default'],
  ['organization.users','default'],
  ['suppliers.index','default'],
];
const timingRouteNames = new Set(['dashboard','customers.show','financial.aging']);
const timingViewportNames = new Set(['desktop-1440','mobile-390']);
const primary = '#main-content h1, #main-content [role="heading"], main h1, main [role="heading"]';

function freePort(){return new Promise((resolve,reject)=>{const s=net.createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});s.on('error',reject);});}
function wait(ms){return new Promise(resolve=>setTimeout(resolve,ms));}
function clearOwnedBladeCache(env,port){
  const viewPath=path.resolve(env.VIEW_COMPILED_PATH||''),expectedRoot=port===55461?path.resolve(root,'.tmp','page-speed-audit','cache'):port===55462?path.resolve(root,'.tmp','ui-acceptance','compiled-views'):null;
  if(!expectedRoot||Number(env.DB_PORT)!==port||env.DB_DATABASE!=='erp_elec_new_test'||(viewPath!==expectedRoot&&!viewPath.startsWith(expectedRoot+path.sep)))throw new Error('Refusing Blade cache clear outside the marker-owned private view directory.');
  if(port===55461){const owned=visual.readOwnedBootstrap();if(Number(owned.dbPort)!==55461||owned.dbName!=='erp_elec_new_test'||env.E2E_CLUSTER_TOKEN!==owned.clusterToken||path.resolve(owned.env.VIEW_COMPILED_PATH)!==viewPath)throw new Error('Refusing Blade cache clear: 55461 ownership marker mismatch.');}
  if(port===55462){const b=JSON.parse(fs.readFileSync(path.join(root,'.tmp','ui-acceptance','bootstrap.json'),'utf8')),m=JSON.parse(fs.readFileSync(path.join(root,'.tmp','ui-acceptance','postgres-55462','codex-owned.json'),'utf8'));if(b.status!=='READY'||Number(b.db_port)!==55462||b.database!=='erp_elec_new_test'||m.kind!=='codex-ui-acceptance'||m.repo!==root||Number(m.port)!==55462||m.database!=='erp_elec_new_test'||m.token!==env.E2E_CLUSTER_TOKEN)throw new Error('Refusing Blade cache clear: 55462 ownership marker mismatch.');}
  const result=spawnSync('php',['artisan','view:clear'],{cwd:root,env:{...env,APP_ENV:'testing',APP_DEBUG:'false',DEBUGBAR_ENABLED:'false',PULSE_ENABLED:'false'},encoding:'utf8',windowsHide:true});
  if(result.error||result.status!==0)throw new Error(`Private Blade cache clear failed for owned test port ${port}.`);
  return path.relative(root,viewPath).replace(/\\/g,'/');
}
async function catalogMarkupPreflight(browser,base,state,routes,dir,evidence,cssAsset){
  const targets=routes.filter(route=>['products.index','customers.index','suppliers.index'].includes(route.name)),receipt={source_sha256:performance.sourceFingerprint(),css_asset:cssAsset,viewport:{width:360,height:800},routes:[]};
  for(const route of targets){
    const context=await browser.newContext({baseURL:base,storageState:state,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width:360,height:800}}),page=await context.newPage(),assets=[];page.on('response',response=>{if(/\/build\/assets\/.*\.css(?:\?|$)/i.test(response.url()))assets.push({url:response.url(),status:response.status()});});
    try{
      const response=await page.goto(`${base}${route.url}`,{waitUntil:'domcontentloaded',timeout:30000});await page.locator(primary).first().waitFor({state:'visible',timeout:12000});
      const dom=await page.evaluate(()=>({summary:!!document.querySelector('.erp-kpi-summary'),records:!!document.querySelector('.erp-catalog-records'),mobileMore:[...document.querySelectorAll('.erp-toolbar-overflow button')].some(button=>{const r=button.getBoundingClientRect(),s=getComputedStyle(button);return r.width>0&&r.height>0&&s.display!=='none'&&s.visibility!=='hidden'}),documentWidth:document.documentElement.scrollWidth,viewportWidth:innerWidth}));
      const row={route:route.name,url:route.url,http_status:response?.status()??null,dom,assets,checks:[]};check(row.checks,'fresh-catalog-summary-rendered',dom.summary,dom.summary);check(row.checks,'fresh-catalog-records-wrapper-rendered',dom.records,dom.records);check(row.checks,'mobile-more-action-visible',dom.mobileMore,dom.mobileMore);check(row.checks,'document-fits-360-viewport',dom.documentWidth<=dom.viewportWidth,{documentWidth:dom.documentWidth,viewportWidth:dom.viewportWidth});check(row.checks,'current-built-css-served',assets.some(asset=>asset.status===200&&asset.url.includes(cssAsset)),assets);check(row.checks,'catalog-route-http-200',row.http_status===200,row.http_status);receipt.routes.push(row);
    }finally{await context.close();}
  }
  receipt.pass=receipt.routes.length===targets.length&&receipt.routes.every(route=>route.checks.every(item=>item.result==='pass'));evidence.catalog_markup_preflight=receipt;fs.writeFileSync(path.join(dir,'catalog-markup-preflight.json'),JSON.stringify(receipt,null,2)+'\n');if(!receipt.pass)throw new Error('Catalog freshness preflight failed; screenshots were not accepted.');return receipt;
}
async function waitForServer(base){for(let i=0;i<80;i++){try{const r=await fetch(`${base}/login`);if(r.status<500)return;}catch{}await wait(250);}throw new Error('Design audit server did not start.');}
async function hasValidStoredAuth(browser,base,state){if(!fs.existsSync(state))return false;const context=await browser.newContext({baseURL:base,storageState:state,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width:1440,height:900}}),page=await context.newPage();try{const response=await page.goto(`${base}/dashboard`,{waitUntil:'domcontentloaded',timeout:12000});return response?.status()===200&&new URL(page.url()).pathname==='/dashboard'&&await page.locator('#main-content h1, #main-content [role="heading"], main h1, main [role="heading"]').first().isVisible().catch(()=>false);}catch{return false;}finally{await context.close();}}
function check(target,name,passed,details=null){target.push({name,result:passed?'pass':'fail',details});}
function routeCases(env,requestedNames=null,allowStaticMissing=false,allowGuestRoutes=false){
  const listed=require('child_process').spawnSync('php',['artisan','route:list','--json'],{cwd:root,env,encoding:'utf8'});
  if(listed.status!==0)throw new Error(`Current route registry failed: ${String(listed.stderr||listed.stdout).slice(-500)}`);
  const registry=JSON.parse(listed.stdout),pageRoutes=registry.filter(performance.isPageRoute),names=new Set(pageRoutes.map(r=>r.name));
  const manifest=JSON.parse(fs.readFileSync(visual.manifestPath,'utf8'));
  const requestedWanted=requestedNames?requestedNames.map(item=>Array.isArray(item)?item:typeof item==='object'?[item.name,item.variant||'default']:[item,'default']):wanted;
  return requestedWanted.map(([name,variant])=>{
    const route=pageRoutes.find(row=>row.name===name)|| (allowGuestRoutes?registry.find(row=>row.name===name&&String(row.method||'').includes('GET')&&(row.middleware||[]).some(m=>String(m).includes('RedirectIfAuthenticated')||String(m).includes('guest'))):null);
    if(!route)throw new Error(`Required named accessible GET route missing: ${name}`);
    const namedCases=(manifest.cases||[]).filter(row=>row.name===name&&row.url&&row.baseline_status===200);
    const c=namedCases.find(row=>row.variant===variant)||(variant==='default'?namedCases[0]:null);
    if(!c&&!allowStaticMissing)throw new Error(`No existing valid isolated fixture URL for ${name}|${variant}`);
    if(!c&&String(route.uri).includes('{'))throw new Error(`Route ${name} requires parameters and has no existing valid isolated fixture.`);
    return {name,variant:c?.variant||variant,module:c?.module||performance.moduleName(route),uri:route.uri,url:c?.url||`/${String(route.uri).replace(/^\/+/, '')}`,baseline_status:c?.baseline_status??null,guest_route:!names.has(name)};
  });
}
function median(values){const a=values.filter(Number.isFinite).sort((x,y)=>x-y);return a.length?+(a[Math.floor(a.length/2)]).toFixed(2):null;}
function generateComparisonGallery(afterDir,afterEvidence,beforeEvidencePath=null){
  const pageCaseManifest=JSON.parse(fs.readFileSync(visual.manifestPath,'utf8'));
  const beforeFiles=beforeEvidencePath?[path.resolve(root,beforeEvidencePath)]:[
    path.join(root,'docs','qa','design-consistency','2026-10-31T23-04-30-908Z','before','evidence.json'),
    path.join(root,'docs','qa','design-consistency','2026-10-31T23-07-12-188Z','before','evidence.json'),
  ];const beforeScreens=beforeFiles.flatMap(f=>fs.existsSync(f)?(JSON.parse(fs.readFileSync(f,'utf8')).screenshots||[]):[]);
  const strictPath=beforeEvidencePath?path.resolve(root,beforeEvidencePath):path.join(root,'docs','qa','design-consistency','2026-10-31T23-07-33-466Z','before','evidence.json');
  const beforeTiming=fs.existsSync(strictPath)?JSON.parse(fs.readFileSync(strictPath,'utf8')).timing_samples||[]:[];
  const afterTiming=afterEvidence.timing_samples||[];const routes=[...new Set(afterEvidence.routes.map(r=>r.name))];
  const image=(record)=>{if(!record?.screenshot)return '<span>لقطة غير متاحة</span>';const abs=path.resolve(root,record.screenshot);const rel=path.relative(afterDir,abs).replace(/\\/g,'/');return `<a href="${rel}"><img src="${rel}" alt="${record.route} ${record.viewport.name}" loading="lazy"></a>`;};
  const metricGroups=[];for(const route of ['dashboard','customers.show','financial.aging'])for(const viewport of ['desktop-1440','mobile-390']){
    const a=beforeTiming.filter(x=>x.route===route&&x.viewport===viewport),b=afterTiming.filter(x=>x.route===route&&x.viewport===viewport);
    metricGroups.push({route,viewport,before:a,after:b});
  }
  const timingTable=metricGroups.map(g=>{const metrics=['ui_ready_ms','ttfb_ms','dom_content_loaded_ms','load_ms'];return `<tr><th>${g.route}<br>${g.viewport}</th>${metrics.map(k=>`<td>${median(g.before.map(x=>x[k]))??'—'} → ${median(g.after.map(x=>x[k]))??'—'} ms</td>`).join('')}<td>${g.before.length}/${g.after.length} samples</td></tr>`;}).join('');
  const gallery=routes.map(route=>{const views=viewports.map(v=>v.name);return `<section><h2>${route}</h2><div class="pair">${views.map(v=>{const before=beforeScreens.find(x=>x.route===route&&x.viewport.name===v),after=(afterEvidence.screenshots||[]).find(x=>x.route===route&&x.variant==='default'&&x.viewport.name===v&&x.source_sha256===afterEvidence.source_sha256&&(x.build_assets?.css===afterEvidence.build_assets?.css||x.asset_requests?.some(a=>a.url.includes(afterEvidence.build_assets?.css||'__missing_css__'))));return `<article><h3>${v}</h3><div class="images"><figure><figcaption>قبل</figcaption>${image(before)}</figure><figure><figcaption>بعد</figcaption>${image(after)}</figure></div></article>`;}).join('')}</div></section>`;}).join('');
  const variantGallery=(afterEvidence.route_variant_cases||[]).map(c=>`<section><h2>${c.name} · ${c.variant}</h2><div class="pair">${viewports.map(v=>{const before=beforeScreens.find(x=>x.route===c.name&&x.viewport.name===v.name&&(x.variant===c.variant||pageCaseManifest.cases.some(m=>m.name===c.name&&m.variant===c.variant&&m.url===x.url))),after=(afterEvidence.screenshots||[]).find(x=>x.route===c.name&&x.variant===c.variant&&x.viewport.name===v.name&&x.source_sha256===afterEvidence.source_sha256);return `<article><h3>${v.name}</h3><div class="images"><figure><figcaption>قبل</figcaption>${image(before)}</figure><figure><figcaption>بعد</figcaption>${image(after)}</figure></div></article>`;}).join('')}</div></section>`).join('');
  const html=`<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>مقارنة اتساق الواجهة قبل وبعد</title><style>body{font:15px system-ui;margin:24px;background:#f4f7fb;color:#17233a}h1{color:#173b72}section,table{background:white;border:1px solid #dce4ef;border-radius:14px;padding:16px;margin:18px 0}table{border-collapse:collapse;width:100%}th,td{padding:10px;border:1px solid #dce4ef;text-align:start}.pair{display:grid;gap:12px}.images{display:grid;grid-template-columns:1fr 1fr;gap:12px}figure{margin:0}img{width:100%;border:1px solid #dce4ef;border-radius:8px}figcaption{font-weight:700;margin:6px}@media(max-width:650px){body{margin:10px}.images{grid-template-columns:1fr}}</style><h1>مقارنة الواجهة قبل وبعد</h1><p>القياسات warm route: الوسيط لثلاث عينات، لا يُستنتج P95 من هذه العينة الصغيرة. الأزمنة: UI readiness بمحتوى الصفحة الفعلي، TTFB، DOMContentLoaded، وload event.</p><table><thead><tr><th>المسار / العرض</th><th>UI جاهز</th><th>TTFB</th><th>DOMContentLoaded</th><th>load</th><th>العينات قبل/بعد</th></tr></thead><tbody>${timingTable}</tbody></table>${gallery}${variantGallery}</html>`;
  const out=path.join(afterDir,'comparison-gallery.html');fs.writeFileSync(out,html);return out;
}
function routeViewInventory(env){
  const listed=require('child_process').spawnSync('php',['artisan','route:list','--json'],{cwd:root,env,encoding:'utf8'});
  if(listed.status!==0)throw new Error(`Route inventory failed: ${String(listed.stderr||listed.stdout).slice(-500)}`);
  const registry=JSON.parse(listed.stdout),manifest=JSON.parse(fs.readFileSync(visual.manifestPath,'utf8'));
  const directViews=new Map();
  for(const file of fs.readdirSync(path.join(root,'routes')).filter(f=>f.endsWith('.php'))){const routeSource=fs.readFileSync(path.join(root,'routes',file),'utf8');
    for(const match of routeSource.matchAll(/Route::view\(\s*['"][^'"]+['"]\s*,\s*['"]([^'"]+)['"]\)[\s\S]{0,120}?->name\(\s*['"]([^'"]+)['"]\s*\)/g))directViews.set(match[2],match[1]);
    for(const match of routeSource.matchAll(/Route::livewire\(\s*['"][^'"]+['"]\s*,\s*['"]([^'"]+)['"]\)[\s\S]{0,180}?->name\(\s*['"]([^'"]+)['"]\s*\)/g))directViews.set(match[2],match[1]);
  }
  const eligible=registry.filter(performance.isPageRoute),excluded=registry.filter(r=>String(r.method||'').includes('GET')&&!performance.isPageRoute(r)).map(r=>({name:r.name||null,uri:r.uri,action:r.action,reason:performance.exclusionReason(r)}));
  const classify=r=>{const key=String(r.name||'').toLowerCase();if(/\.show$|\.details?$/.test(key))return'detail';if(/\.create$|\.edit$|\.import$|\.bulk/.test(key))return'form-or-import';if(/report|aging|statement|summary/.test(key))return'report-or-analysis';if(/dashboard|\.home$/.test(key))return'dashboard';if(/\.index$|catalog|accounts$|orders$/.test(key))return'list-or-catalog';if(/settings|profile|company$/.test(key))return'settings-or-profile';return'workflow-or-module-screen';};
  const routes=eligible.map(r=>{
    const action=String(r.action||''),className=action.split('@')[0],classPath=className.startsWith('App\\')?path.join(root,className.replace(/^App\\/,'app\\').replace(/\\/g,path.sep)+'.php'):null;
    let cursor=classPath,source='',componentChain=[];for(let depth=0;cursor&&depth<6&&fs.existsSync(cursor);depth++){const body=fs.readFileSync(cursor,'utf8');componentChain.push(path.relative(root,cursor).replace(/\\/g,'/'));source+=`\n${body}`;const parent=body.match(/\bclass\s+\w+\s+extends\s+(\w+)/);cursor=parent?path.join(path.dirname(cursor),parent[1]+'.php'):null;}
    const viewNames=[...(directViews.has(r.name)?[directViews.get(r.name)]:[]),...[...source.matchAll(/\bview\(\s*['"]([^'"]+)['"]/g)].map(m=>m[1])];
    for(const match of source.matchAll(/\bview\(\s*['"]([^'"]+)['"]\s*\.\s*static::([A-Z_]+)/g)){const constant=source.match(new RegExp(`(?:const|protected\\s+const|public\\s+const)\\s+${match[2]}\\s*=\\s*['"]([^'"]+)['"]`));if(constant)viewNames.push(match[1]+constant[1]);}
    const viewCandidates=[...new Set(viewNames)].map(name=>{const pageAlias=name.startsWith('pages::'),plain=pageAlias?name.slice('pages::'.length):name;return{name,file:path.join(root,'resources','views',...(pageAlias?['pages']:[]),...plain.split('.'))+'.blade.php'};});
    const fixture=(manifest.cases||[]).find(c=>c.name===r.name&&c.baseline_status===200&&c.url);
    const module=String(r.name||'').startsWith('password.')?'Authentication':performance.moduleName(r);
    return {name:r.name,uri:r.uri,method:r.method,module,archetype:classify(r),action,component_file:classPath&&fs.existsSync(classPath)?path.relative(root,classPath).replace(/\\/g,'/'):null,component_chain:componentChain,view_candidates:viewCandidates.map(v=>({...v,file:path.relative(root,v.file).replace(/\\/g,'/'),exists:fs.existsSync(v.file)})),middleware:r.middleware,fixture_status:fixture?.baseline_status??null,fixture_variant:fixture?.variant??null,fixture_url:fixture?.url??null,safe_parameterless_url:fixture?.url??(!String(r.uri).includes('{')?`/${String(r.uri).replace(/^\/+/, '')}`:null)};
  });
  const grouped={};for(const route of routes){const key=route.module;grouped[key]||={routes:0,archetypes:{},pages:[]};grouped[key].routes++;grouped[key].archetypes[route.archetype]=(grouped[key].archetypes[route.archetype]||0)+1;grouped[key].pages.push(route.name);}
  const priority=['dashboard','list-or-catalog','detail','form-or-import','report-or-analysis','settings-or-profile','workflow-or-module-screen'];
  const representatives=Object.entries(grouped).flatMap(([module,group])=>{
    const candidates=routes.filter(r=>r.module===module&&r.fixture_status===200),picked=[];
    for(const archetype of priority){if(picked.length>=5)break;const candidate=candidates.find(r=>r.archetype===archetype&&!picked.some(p=>p.name===r.name));if(candidate)picked.push(candidate);}
    return picked;
  }).map(({name,module,archetype,fixture_variant,fixture_url,view_candidates})=>({name,variant:fixture_variant||'default',module,archetype,url:fixture_url,view_candidates}));
  const parameterlessUncovered=routes.filter(r=>!r.fixture_status&&r.safe_parameterless_url).map(({name,uri,module,archetype,safe_parameterless_url,action})=>({name,uri,module,archetype,url:safe_parameterless_url,action,status_probe_required:true}));
  return {generated_at:new Date().toISOString(),source:'current `php artisan route:list --json`; action class inheritance and static Blade view references are resolved from route/component source; route-only aliases are recorded separately',counts:{registered_named_authenticated_get_pages:routes.length,other_get_routes_excluded:excluded.length,with_valid_fixture:routes.filter(r=>r.fixture_status===200).length,with_view_candidate:routes.filter(r=>r.view_candidates.length>0).length,with_existing_blade_view:routes.filter(r=>r.view_candidates.some(v=>fs.existsSync(v.file))).length,representative_count:representatives.length,parameterless_routes_without_fixture:parameterlessUncovered.length},grouped_modules:grouped,representatives,parameterless_uncovered:parameterlessUncovered,routes,excluded_get_routes:excluded};
}
async function inspect(page){return page.evaluate(()=>{
  const visible=el=>{if(!el)return false;const r=el.getBoundingClientRect(),s=getComputedStyle(el);return r.width>0&&r.height>0&&s.display!=='none'&&s.visibility!=='hidden';};
  const doc=document.documentElement,main=document.querySelector('#main-content,main'),side=document.querySelector('.erp-sidebar,aside'),top=document.querySelector('.erp-topbar,header');
  const h=[...document.querySelectorAll('h1,[role="heading"]')].find(visible);const hr=h?.getBoundingClientRect(),mr=main?.getBoundingClientRect(),sr=side?.getBoundingClientRect(),tr=top?.getBoundingClientRect();
  const fields=[...document.querySelectorAll('input,select,textarea')].filter(visible);
  const unlabeled=fields.filter(el=>!el.getAttribute('aria-label')&&!el.getAttribute('aria-labelledby')&&!el.labels?.length).map(el=>({tag:el.tagName.toLowerCase(),type:el.type||null,name:el.name||null})).slice(0,20);
  const broken=[...document.images].filter(img=>visible(img)&&img.complete&&img.naturalWidth===0).map(img=>img.currentSrc||img.src).slice(0,20);
  const box=r=>r?{x:r.x,y:r.y,width:r.width,height:r.height,right:r.right,bottom:r.bottom}:null;
  return {dir:doc.getAttribute('dir'),lang:doc.getAttribute('lang'),scroll_width:doc.scrollWidth,viewport_width:innerWidth,
    overflow:doc.scrollWidth>innerWidth+4,main:!!main,sidebar:!!side,heading:h?.innerText.trim()||null,
    heading_style:h?{font_size:getComputedStyle(h).fontSize,line_height:getComputedStyle(h).lineHeight,color:getComputedStyle(h).color}:null,
    geometry:{main:box(mr),sidebar:box(sr),topbar:box(tr),heading:box(hr)},visible_fields:fields.length,unlabeled_fields:unlabeled,broken_images:broken,
    visible_sidebar_links:side?[...side.querySelectorAll('a[href]')].filter(visible).length:0,
    page_archetype:document.querySelector('[data-erp-archetype]')?.getAttribute('data-erp-archetype')||null,
    primary_actions:[...document.querySelectorAll('#main-content button,#main-content a,main button,main a')].filter(visible).length,
    secondary_action_menu_count:document.querySelectorAll('.erp-secondary-actions').length,
    empty_secondary_action_menus:[...document.querySelectorAll('.erp-secondary-actions .erp-secondary-menu')].filter(menu=>!menu.querySelector('a,button,[role="menuitem"]')).length,
    visible_table_headers:[...document.querySelectorAll('th')].filter(visible).map(el=>el.innerText.trim()).filter(Boolean).slice(0,14),
    selected_module_tab:(()=>{const el=document.querySelector('.erp-module-nav [aria-current="page"]');if(!visible(el))return null;const shell=el.closest('.erp-shell')||document.querySelector('.erp-shell');const token=shell?getComputedStyle(shell).getPropertyValue('--color-accent-content').trim():'';return{text:el.innerText.trim(),color:getComputedStyle(el).color,accent_token:token};})()};
});}
async function capturePage(browser,base,state,route,vp,dir,checks,sourceTag=''){
  const context=await browser.newContext({baseURL:base,storageState:route.guest_route?undefined:state,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width:vp.width,height:vp.height}});
  const page=await context.newPage(),rec={route:route.name,variant:route.variant||'default',case_key:`${route.name}|${route.variant||'default'}`,module:route.module,url:route.url,viewport:vp,state:route.guest_route?'guest':'normal',http_status:null,checks:[],console_errors:[],failed_requests:[],asset_requests:[]};
  page.on('console',m=>{if(m.type()==='error')rec.console_errors.push(m.text().slice(0,300));});page.on('pageerror',e=>rec.console_errors.push(String(e.message||e).slice(0,300)));page.on('requestfailed',r=>rec.failed_requests.push(`${r.method()} ${r.url()} ${r.failure()?.errorText||''}`.slice(0,300)));page.on('response',r=>{if(/\/build\/assets\/.*\.(?:css|js)(?:\?|$)/i.test(r.url()))rec.asset_requests.push({url:r.url(),status:r.status()});});
  const variantTag=route.variant&&route.variant!=='default'?`-${visual.safeName(route.variant)}`:'';
  const file=path.join(dir,'screenshots',`${visual.safeName(route.name)}${variantTag}-${vp.name}${sourceTag?`-${sourceTag}`:''}.png`);
  try{
    let response;
    if(route.variant&&route.variant!=='default'&&!route.guest_route){const readiness=await performance.measurePage(page,base,route,vp,'warm',1,false);rec.route_readiness={readiness:readiness.readiness,measured:readiness.measured,healthy:readiness.healthy,ui_ready_ms:readiness.ui_ready_ms,livewire_ready:readiness.livewire_ready,not_measured_reason:readiness.not_measured_reason};rec.http_status=readiness.http_status;response={status:()=>readiness.http_status};check(rec.checks,'route-variant-content-and-livewire-ready',readiness.measured,rec.route_readiness);}
    else response=await page.goto(`${base}${route.url}`,{waitUntil:'domcontentloaded',timeout:45000});
    rec.http_status=rec.http_status??response?.status()??null;rec.landed_url=page.url();rec.landed_path=new URL(page.url()).pathname;
    if(!route.guest_route&&route.name!=='password.confirm'&&rec.landed_path==='/login'){
      rec.auth_redirect=true;rec.navigation_error='Authenticated page redirected to login; this response is not accepted as page evidence.';check(rec.checks,'authenticated-route-not-redirected',false,{status:rec.http_status,requested:route.url,landed:rec.landed_path});
      await page.screenshot({path:file,fullPage:false});rec.screenshot=path.relative(root,file).replace(/\\/g,'/');checks.push(rec);await context.close();return rec;
    }
    if(route.name==='security.edit'&&rec.landed_path==='/user/confirm-password'){
      rec.state='reauthentication-required';rec.auth_gate_redirect=true;rec.not_measured_reason='The authenticated actor is redirected to the password-confirmation gate; the security settings page content is not accessible without a fresh password confirmation.';
      check(rec.checks,'security-settings-requires-password-confirmation',rec.http_status===200,{status:rec.http_status,landed_path:rec.landed_path});
      await page.screenshot({path:file,fullPage:false});rec.screenshot=path.relative(root,file).replace(/\\/g,'/');checks.push(rec);await context.close();return rec;
    }
    if(route.name==='password.confirm')await page.locator('[data-test="confirm-password-button"]').waitFor({state:'visible',timeout:12000});
    else if(route.guest_route)await page.locator('form').first().waitFor({state:'visible',timeout:12000});
    else await page.locator(primary).first().waitFor({state:'visible',timeout:12000});
    const d=await inspect(page);rec.design=d;check(rec.checks,'http-200',rec.http_status===200,rec.http_status);check(rec.checks,'rtl-document',d.dir==='rtl',d.dir);check(rec.checks,route.name==='password.confirm'?'password-confirmation-title-visible':'primary-heading-visible',route.name==='password.confirm'?await page.getByText('تأكيد كلمة المرور',{exact:true}).isVisible().catch(()=>false):!!d.heading,d.heading);check(rec.checks,'no-horizontal-overflow',!d.overflow,{scroll_width:d.scroll_width,viewport_width:d.viewport_width});check(rec.checks,'visible-inputs-labeled',d.unlabeled_fields.length===0,d.unlabeled_fields);check(rec.checks,'no-broken-images',d.broken_images.length===0,d.broken_images);check(rec.checks,'critical-build-assets-200',rec.asset_requests.every(a=>a.status===200),rec.asset_requests);
    if(route.name==='password.confirm'||route.guest_route){
      const confirmation=page.locator('form[action*="confirm-password"] input[name="password"]'),submit=page.locator('[data-test="confirm-password-button"]');
      if(route.name==='password.confirm')check(rec.checks,'password-confirmation-form-visible',await confirmation.isVisible()&&await submit.isVisible(),{password_field:await confirmation.count(),submit:await submit.count()});
      else check(rec.checks,'guest-auth-form-visible',await page.locator('form').first().isVisible()&&d.visible_fields>0,{forms:await page.locator('form').count(),visible_fields:d.visible_fields});
      check(rec.checks,'auth-page-does-not-require-erp-sidebar',!d.sidebar||d.visible_sidebar_links>0,{sidebar:d.sidebar,visible_links:d.visible_sidebar_links});
    }else{
      check(rec.checks,'main-and-sidebar',d.main&&d.sidebar,{main:d.main,sidebar:d.sidebar});check(rec.checks,'authorized-sidebar-links-render',d.visible_sidebar_links>0,d.visible_sidebar_links);
      check(rec.checks,'secondary-actions-are-not-empty',d.empty_secondary_action_menus===0,{menus:d.secondary_action_menu_count,empty:d.empty_secondary_action_menus});
      if(rec.http_status===200&&!route.guest_route)check(rec.checks,'page-archetype-marker-rendered',!!d.page_archetype,d.page_archetype);
    }
    await page.screenshot({path:file,fullPage:false});rec.screenshot=path.relative(root,file).replace(/\\/g,'/');
  }catch(e){rec.navigation_error=String(e.message||e).slice(0,500);check(rec.checks,'page-readiness',false,rec.navigation_error);await page.screenshot({path:file,fullPage:false}).catch(()=>{});rec.screenshot=path.relative(root,file).replace(/\\/g,'/');}
  checks.push(rec);await context.close();return rec;
}
async function strictContentReady(page,route){
  if(route.name==='dashboard') await page.locator('.workspace-module:visible').first().waitFor({state:'visible',timeout:12000});
  else if(route.name==='customers.show') await page.locator('.party-profile-header').waitFor({state:'visible',timeout:12000});
  else if(route.name==='financial.aging') await page.locator('table tbody tr:visible, .financial-mobile-items .aging-card:visible, .financial-mobile-items [role="status"]:visible').first().waitFor({state:'visible',timeout:12000});
}
async function timingSample(page,base,route,vp,trial){
  const started=Date.now();let status=null,error=null,uiReadyAt=null,loadObserved=false;
  try{const response=await page.goto(`${base}${route.url}`,{waitUntil:'domcontentloaded',timeout:45000});status=response?.status()??null;await page.locator(primary).first().waitFor({state:'visible',timeout:12000});await strictContentReady(page,route);await page.evaluate(()=>performance.mark('design-ui-ready'));uiReadyAt=Date.now();await page.waitForLoadState('load',{timeout:15000});loadObserved=true;}
  catch(e){error=String(e.message||e).slice(0,500);}
  const metrics=await page.evaluate(()=>{const n=performance.getEntriesByType('navigation')[0];return n?{ttfb_ms:+(n.responseStart-n.requestStart).toFixed(2),dom_content_loaded_ms:+n.domContentLoadedEventEnd.toFixed(2),load_ms:+n.loadEventEnd.toFixed(2),transfer_bytes:n.transferSize??null,encoded_body_bytes:n.encodedBodySize??null,requests:performance.getEntriesByType('resource').length}:null;}).catch(()=>null);
  const ready=uiReadyAt===null?null:uiReadyAt-started;
  return {route:route.name,module:route.module,viewport:vp.name,trial,temperature:'warm',readiness:'primary heading plus route-specific visible content',http_status:status,ui_ready_ms:ready,load_event_observed:loadObserved,...metrics,error};
}
async function measureWarmRoutes(browser,base,state,routes,onSample=null,viewportList=viewports,existingSamples=[]){
  const selected=routes.filter(r=>timingRouteNames.has(r.name));const rows=[];
  for(const vp of viewportList.filter(v=>timingViewportNames.has(v.name))){
    for(const route of selected){
      const context=await browser.newContext({baseURL:base,storageState:state,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width:vp.width,height:vp.height}});const page=await context.newPage();
      await page.goto(`${base}${route.url}`,{waitUntil:'domcontentloaded',timeout:45000}).catch(()=>{});await page.locator(primary).first().waitFor({state:'visible',timeout:12000}).catch(()=>{});await strictContentReady(page,route).catch(()=>{});
      for(let trial=1;trial<=3;trial++){if(existingSamples.some(x=>x.route===route.name&&x.viewport===vp.name&&x.trial===trial))continue;const sample=await timingSample(page,base,route,vp,trial);rows.push(sample);if(onSample)onSample(sample);}
      await context.close();
    }
  }
  return rows;
}
async function livewireFilter(browser,base,state,route,dir){
  const context=await browser.newContext({baseURL:base,storageState:state,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width:1440,height:900}}),page=await context.newPage();
  const rec={route:route.name,viewport:'desktop-1440',checks:[],livewire_transitions:[]};const requestMarks=[];
  page.on('request',r=>{if(r.method()==='POST'&&livewireUpdateUrl(r.url()))requestMarks.push({at:Date.now(),path:new URL(r.url()).pathname});});
  try{
    await page.goto(`${base}${route.url}`,{waitUntil:'domcontentloaded',timeout:45000});await page.locator(primary).first().waitFor({state:'visible',timeout:12000});
    const input=page.getByLabel('البحث في المخزون');
    if(!(await input.count())){check(rec.checks,'inventory-livewire-filter',false,'Search input not found.');}
    else{
      const baselineRows=await page.locator('table tbody tr').count();let before=requestMarks.length;const t0=Date.now();await page.evaluate(()=>performance.mark('inventory-filter-start'));
      const response=page.waitForResponse(r=>livewireUpdateUrl(r.url())&&r.request().method()==='POST'&&r.status()<400,{timeout:15000});await input.fill('NO_MATCH_DESIGN_AUDIT');const livewireResponse=await response;
      await page.getByText('لا توجد سجلات ضمن النطاق الحالي').waitFor({state:'visible',timeout:12000});
      await page.evaluate(()=>performance.mark('inventory-filter-end'));const filterMeasured=await page.evaluate(()=>performance.measure('inventory-filter','inventory-filter-start','inventory-filter-end').duration);rec.livewire_transitions.push({action:'filter-empty',elapsed_ms:+filterMeasured.toFixed(2),response_path:new URL(livewireResponse.url()).pathname,request_count:requestMarks.length-before,ready:'empty state visible after successful update'});
      check(rec.checks,'inventory-livewire-filter',requestMarks.length>before&&await page.getByText('لا توجد سجلات ضمن النطاق الحالي').isVisible(),rec.livewire_transitions.at(-1));
      before=requestMarks.length;await page.evaluate(()=>performance.mark('inventory-reset-start'));const resetResponse=page.waitForResponse(r=>livewireUpdateUrl(r.url())&&r.request().method()==='POST'&&r.status()<400,{timeout:15000});await input.fill('');const resetResponseResult=await resetResponse;
      await page.waitForFunction(count=>document.querySelectorAll('table tbody tr').length===count,baselineRows,{timeout:12000});
      await page.evaluate(()=>performance.mark('inventory-reset-end'));const resetMeasured=await page.evaluate(()=>performance.measure('inventory-reset','inventory-reset-start','inventory-reset-end').duration);rec.livewire_transitions.push({action:'clear-filter',elapsed_ms:+resetMeasured.toFixed(2),response_path:new URL(resetResponseResult.url()).pathname,request_count:requestMarks.length-before,restored_rows:await page.locator('table tbody tr').count(),ready:'original row count restored'});
      check(rec.checks,'inventory-livewire-filter-resets',requestMarks.length>before&&await page.locator('table tbody tr').count()===baselineRows,rec.livewire_transitions.at(-1));
    }
  }catch(e){check(rec.checks,'inventory-livewire-filter-journey',false,String(e.message||e).slice(0,500));}
  fs.writeFileSync(path.join(dir,'livewire-filter.json'),JSON.stringify(rec,null,2)+'\n');await context.close();return rec;
}
const filteredRoutes = [
  ['sales-orders.index','البحث'],['purchasing.orders.index','البحث'],
  ['inventory.dashboard','البحث في المخزون'],['financial.aging','بحث'],
];
const livewireUpdateUrl = url => /\/livewire(?:-[^/]+)?\/update(?:\?|$)/i.test(url);
async function filterDisclosureChecks(browser,base,state,routes,dir){
  const rows=[];
  for(const width of [768,390,360])for(const [name,label] of filteredRoutes){
    const route=routes.find(r=>r.name===name);if(!route)continue;
    const context=await browser.newContext({baseURL:base,storageState:state,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width,height:width===768?1024:width===390?844:800}}),page=await context.newPage();
    const rec={route:name,viewport:width,checks:[],requests:[]};
    page.on('request',r=>{if(r.method()==='POST'&&livewireUpdateUrl(r.url()))rec.requests.push({method:r.method(),path:new URL(r.url()).pathname});});
    try{
      await page.goto(`${base}${route.url}`,{waitUntil:'domcontentloaded',timeout:45000});await page.locator(primary).first().waitFor({state:'visible',timeout:12000});await page.waitForFunction(()=>{const root=document.querySelector('[wire\\:id]');return !!root&&!!window.Livewire?.find?.(root.getAttribute('wire:id'));},null,{timeout:10000});
      const disclosure=page.locator('.erp-filter-disclosure').first(),toggle=disclosure.locator('[data-erp-filter-toggle]'),body=disclosure.locator('.erp-filter-body');
      await toggle.waitFor({state:'visible',timeout:8000});await page.waitForFunction(el=>el.dataset.open==='false',await disclosure.elementHandle(),{timeout:8000});
      check(rec.checks,'mobile-filters-start-collapsed',await disclosure.getAttribute('data-open')==='false'&&!(await body.isVisible()),await disclosure.getAttribute('data-open'));
      const searchBeforeExpand=disclosure.getByLabel(label,{exact:true}).first();check(rec.checks,'search-visible-before-expanding-filters',await searchBeforeExpand.isVisible().catch(()=>false),{label,location:await searchBeforeExpand.evaluate(el=>el.closest('.erp-filter-body')?'inside-collapsed-body':'outside-collapsed-body').catch(()=>null)});
      await toggle.click();await page.waitForFunction(el=>el.dataset.open==='true',await disclosure.elementHandle(),{timeout:5000});
      const input=disclosure.getByLabel(label,{exact:true}).first();await input.waitFor({state:'visible',timeout:8000});
      const filterValue=`NO_MATCH_QA_${name.replace(/\W/g,'').slice(0,8)}_${width}`;const initialRequestCount=rec.requests.length;
      const responseWait=page.waitForResponse(r=>livewireUpdateUrl(r.url())&&r.request().method()==='POST'&&r.status()<500,{timeout:15000});
      await input.fill(filterValue);const livewireResponse=await responseWait;
      await page.waitForFunction(()=>{const components=[...document.querySelectorAll('[wire\\:id]')];return components.length>0&&components.every(el=>window.Livewire?.find?.(el.getAttribute('wire:id')));},null,{timeout:10000});
      await page.waitForFunction(()=>{const h=document.querySelector('#main-content h1, main h1');const links=[...document.querySelectorAll('a[wire\\:navigate]')].filter(a=>{const r=a.getBoundingClientRect(),s=getComputedStyle(a);return r.width>0&&r.height>0&&s.display!=='none'&&s.visibility!=='hidden';});return !!h&&links.some(a=>a.href!==location.href);},null,{timeout:12000});
      await page.locator('.erp-filter-body').waitFor({state:'visible'});rec.livewire_transition={path:new URL(livewireResponse.url()).pathname,status:livewireResponse.status(),request_count:rec.requests.length-initialRequestCount,component_ready:true};
      await toggle.click();await page.waitForFunction(el=>el.dataset.open==='false',await disclosure.elementHandle(),{timeout:5000});
      await toggle.click();await page.waitForFunction(el=>el.dataset.open==='true',await disclosure.elementHandle(),{timeout:5000});
      const preserved=await input.inputValue();check(rec.checks,'filter-value-survives-collapse-reopen',preserved===filterValue,preserved);
      const resetWait=page.waitForResponse(r=>livewireUpdateUrl(r.url())&&r.request().method()==='POST'&&r.status()<500,{timeout:15000});await input.fill('');const resetResponse=await resetWait;
      rec.reset_transition={path:new URL(resetResponse.url()).pathname,status:resetResponse.status()};check(rec.checks,'filter-reset-accepted',resetResponse.status()<400,rec.reset_transition);
      if(name==='sales-orders.index'){
        const sort=page.locator('.erp-mobile-table-tools button:visible');const before=await page.locator('.erp-responsive-table tbody tr').allInnerTexts();
        if(await sort.count()){const sortResponse=page.waitForResponse(r=>livewireUpdateUrl(r.url())&&r.request().method()==='POST'&&r.status()<400,{timeout:15000});await sort.click();const response=await sortResponse;const after=await page.locator('.erp-responsive-table tbody tr').allInnerTexts();check(rec.checks,'mobile-date-sort-action-available',response.status()<400,{button:(await sort.first().innerText()).trim(),response_path:new URL(response.url()).pathname,before_first:before[0]||null,after_first:after[0]||null,rows:after.length});}
        else check(rec.checks,'mobile-date-sort-action-available',false,'No existing mobile sort button found.');
      }
    }catch(e){check(rec.checks,'filter-disclosure-journey',false,String(e.message||e).slice(0,450));}
    rows.push(rec);fs.writeFileSync(path.join(dir,'filter-disclosure-interactions.checkpoint.json'),JSON.stringify(rows,null,2)+'\n');await context.close();
  }
  fs.writeFileSync(path.join(dir,'filter-disclosure-interactions.json'),JSON.stringify(rows,null,2)+'\n');return rows;
}
async function responsiveDataChecks(browser,base,state,routes,dir){
  const output=[];
  for(const [name,expected] of [['sales-orders.index',10],['purchasing.orders.index',9]]){
    const route=routes.find(r=>r.name===name);if(!route)continue;const snapshots={};
    for(const width of [1440,390]){
      const context=await browser.newContext({baseURL:base,storageState:state,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width,height:width===1440?900:844}}),page=await context.newPage(),rec={route:name,viewport:width,checks:[]};
      try{await page.goto(`${base}${route.url}`,{waitUntil:'domcontentloaded',timeout:45000});await page.locator(primary).first().waitFor({state:'visible',timeout:12000});
        const result=await page.locator('.erp-responsive-table tbody tr').first().evaluate(row=>[...row.querySelectorAll('td[data-label]')].map(td=>({label:td.dataset.label,value:td.innerText.replace(/\s+/g,' ').trim()})));
        snapshots[width]=result;check(rec.checks,'responsive-row-has-all-contract-fields',result.length===expected,{expected,actual:result.length,labels:result.map(x=>x.label)});
        if(width===390){const cells=page.locator('.erp-responsive-table tbody tr').first().locator('td[data-label]:visible');const cellData=await cells.evaluateAll(items=>items.map(td=>({label:td.dataset.label,displayedLabel:getComputedStyle(td,'::before').content.replace(/^['"]|['"]$/g,''),text:td.innerText.replace(/\s+/g,' ').trim()})));check(rec.checks,'every-mobile-cell-visible',cellData.length===expected,{expected,visible:cellData.length});check(rec.checks,'every-mobile-cell-label-visible',cellData.every(c=>c.displayedLabel&&c.displayedLabel!=='none'),cellData.map(c=>({label:c.label,displayed:c.displayedLabel})));const focusableHeaderControls=await page.locator('.erp-responsive-table thead button,.erp-responsive-table thead a,.erp-responsive-table thead input,.erp-responsive-table thead select,.erp-responsive-table thead [tabindex]:not([tabindex="-1"])').evaluateAll(nodes=>nodes.filter(el=>el.tabIndex>=0&&getComputedStyle(el).display!=='none'&&getComputedStyle(el).visibility!=='hidden').map(el=>({text:(el.innerText||el.getAttribute('aria-label')||'').trim(),tabIndex:el.tabIndex})));check(rec.checks,'clipped-mobile-header-has-no-focusable-controls',focusableHeaderControls.length===0,focusableHeaderControls);}
        const shot=path.join(dir,'screenshots',`${visual.safeName(name)}-first-row-${width}.png`);await page.locator('.erp-responsive-table tbody tr').first().screenshot({path:shot,timeout:5000}).catch(()=>{});rec.sample_row_screenshot=path.relative(root,shot).replace(/\\/g,'/');
      }catch(e){check(rec.checks,'responsive-row-inspection',false,String(e.message||e).slice(0,350));}
      output.push(rec);fs.writeFileSync(path.join(dir,'responsive-data-checks.checkpoint.json'),JSON.stringify(output,null,2)+'\n');await context.close();
    }
    const desktop=snapshots[1440]||[],mobile=snapshots[390]||[];const same=JSON.stringify(desktop)===JSON.stringify(mobile);
    output.push({route:name,viewport:'desktop-1440 vs mobile-390',checks:[{name:'responsive-row-values-match',result:same?'pass':'fail',details:{desktop,mobile}}]});
  }
  const aging=routes.find(r=>r.name==='financial.aging');if(aging){const context=await browser.newContext({baseURL:base,storageState:state,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width:390,height:844}}),page=await context.newPage(),rec={route:aging.name,viewport:390,checks:[]};try{await page.goto(`${base}${aging.url}`,{waitUntil:'domcontentloaded'});await page.locator(primary).first().waitFor({state:'visible',timeout:12000});const cards=page.locator('.financial-mobile-items .aging-card:visible');const first=cards.first();const fields=first.locator('header .financial-party-type:visible, header strong:visible, dl dd:visible, footer strong:visible');const values=await fields.allInnerTexts();check(rec.checks,'aging-mobile-card-retains-nine-visible-values',values.length===9&&values.every(v=>v.trim()!==''),{expected:9,actual:values.length,values:values.map(v=>v.replace(/\\s+/g,' ').trim())});const shot=path.join(dir,'screenshots','financial.aging-first-card-390.png');await first.screenshot({path:shot,timeout:5000}).catch(()=>{});rec.sample_card_screenshot=path.relative(root,shot).replace(/\\/g,'/');}catch(e){check(rec.checks,'aging-mobile-card-inspection',false,String(e.message||e).slice(0,350));}output.push(rec);await context.close();}
  fs.writeFileSync(path.join(dir,'responsive-data-checks.json'),JSON.stringify(output,null,2)+'\n');return output;
}
async function spaNavigationCheck(browser,base,state,routes,dir){
  const route=routes.find(r=>r.name==='sales-orders.index');if(!route)return null;
  const context=await browser.newContext({baseURL:base,storageState:state,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width:1440,height:900}}),page=await context.newPage();const rec={route:route.name,checks:[],transitions:[]},marks=[];
  page.on('request',r=>{if(r.method()==='POST'&&livewireUpdateUrl(r.url()))marks.push({event:'request',path:new URL(r.url()).pathname,t:Date.now()});});
  try{
    await page.goto(`${base}${route.url}`,{waitUntil:'domcontentloaded'});await page.locator(primary).first().waitFor({state:'visible',timeout:12000});
    const link=page.locator('.erp-responsive-table tbody tr a[wire\\:navigate]').first();
    if(!(await link.count())){check(rec.checks,'read-only-spa-detail-link-available',false,'No existing authorized order row/link in fixture.');}
    else{
      const origin=page.url(),oldHeading=(await page.locator(primary).first().innerText()).trim();await page.evaluate(()=>{window.__designWireNavigated=0;window.addEventListener('livewire:navigated',()=>window.__designWireNavigated++);performance.mark('design-spa-start');});await link.click();
      await page.waitForFunction(prev=>location.href!==prev,origin,{timeout:15000});await page.waitForFunction(()=>window.__designWireNavigated>0,null,{timeout:15000});
      await page.waitForFunction(prev=>{const h=document.querySelector('#main-content h1,#main-content [role="heading"],main h1,main [role="heading"]');return !!h&&h.innerText.trim()!==prev;},oldHeading,{timeout:12000});
      await page.waitForFunction(()=>![...document.querySelectorAll('[wire\\:loading]')].some(el=>{const s=getComputedStyle(el),r=el.getBoundingClientRect();return s.display!=='none'&&s.visibility!=='hidden'&&r.width>0&&r.height>0;}),null,{timeout:12000});
      const detailHeading=(await page.locator(primary).first().innerText()).trim();await page.evaluate(()=>performance.mark('design-spa-end'));const elapsed=await page.evaluate(()=>performance.measure('design-spa','design-spa-start','design-spa-end').duration);
      rec.transitions.push({kind:'wire-navigate-to-read-only-detail',elapsed_ms:+elapsed.toFixed(2),from:new URL(origin).pathname,to:new URL(page.url()).pathname,previous_heading:oldHeading,detail_heading:detailHeading,wire_navigated_count:await page.evaluate(()=>window.__designWireNavigated),livewire_posts:marks.slice()});
      check(rec.checks,'read-only-spa-detail-content-ready',new URL(page.url()).pathname!==new URL(origin).pathname&&detailHeading!==oldHeading&&rec.transitions.at(-1).wire_navigated_count>0,rec.transitions.at(-1));
      await page.goBack({waitUntil:'domcontentloaded',timeout:15000}).catch(()=>{});await page.waitForFunction(prev=>location.pathname===new URL(prev).pathname,origin,{timeout:15000});await page.locator(primary).first().waitFor({state:'visible',timeout:12000});
      check(rec.checks,'spa-back-restores-list',new URL(page.url()).pathname===new URL(origin).pathname,{url:page.url()});
    }
  }catch(e){check(rec.checks,'spa-navigation-complete',false,String(e.message||e).slice(0,400));}
  await context.close();fs.writeFileSync(path.join(dir,'spa-navigation.json'),JSON.stringify(rec,null,2)+'\n');return rec;
}
async function primaryAppearanceChecks(browser,base,state,routes,dir){
  const output=[];
  for(const route of routes.filter(r=>['sales-orders.index','financial.aging','suppliers.index','customers.index'].includes(r.name))){
    const context=await browser.newContext({baseURL:base,storageState:state,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width:390,height:844}}),page=await context.newPage(),rec={route:route.name,checks:[]};
    try{await page.goto(`${base}${route.url}`,{waitUntil:'domcontentloaded'});await page.locator(primary).first().waitFor({state:'visible',timeout:12000});
      const appearance=await page.evaluate(()=>{const visible=e=>{if(!e)return false;const r=e.getBoundingClientRect(),s=getComputedStyle(e);return r.width>0&&r.height>0&&s.display!=='none'&&s.visibility!=='hidden';};const scope=document.querySelector('.party-ui')||document.querySelector('.erp-shell')||document.body;const probe=document.createElement('span');probe.style.cssText='position:absolute;visibility:hidden;background-color:var(--color-accent)';scope.append(probe);const token=getComputedStyle(probe).backgroundColor;probe.remove();const candidate=[...document.querySelectorAll('button,a,[role=button]')].find(e=>visible(e)&&String(e.className?.baseVal||e.className||'').includes('bg-[var(--color-accent)]'));const tab=document.querySelector('.erp-module-nav [aria-current="page"]');const labels=[...document.querySelectorAll('.erp-stat-card p:first-child')].map(e=>e.innerText.trim()).filter(Boolean);const color=token.match(/[0-9.]+/g)?.slice(0,3).map(Number)||[];return{accent_token_computed:token,accent_is_blue:color.length===3&&color[2]>color[0]+20&&color[2]>color[1],primary_button_bg:candidate?getComputedStyle(candidate).backgroundColor:null,primary_button_class:candidate?.className?.baseVal||candidate?.className||null,selected_tab:visible(tab)?{text:tab.innerText.trim(),color:getComputedStyle(tab).color}:null,tab_box:tab?(()=>{const r=tab.getBoundingClientRect();return{x:r.x,right:r.right,width:r.width}})():null,duplicate_stat_labels:[...new Set(labels.filter((v,i)=>labels.indexOf(v)!==i))],viewport:innerWidth};});
      check(rec.checks,'primary-button-uses-accent-token',appearance.primary_button_bg===appearance.accent_token_computed,appearance);if(route.name==='financial.aging')check(rec.checks,'selected-tab-visible',!!appearance.selected_tab&&appearance.tab_box.x>=-1&&appearance.tab_box.right<=appearance.viewport+1,appearance);
      if(['suppliers.index','customers.index'].includes(route.name))check(rec.checks,'party-primary-action-is-blue',appearance.accent_is_blue&&appearance.primary_button_bg===appearance.accent_token_computed,appearance);
      if(route.name==='sales-orders.index')check(rec.checks,'sales-stat-labels-not-duplicated',appearance.duplicate_stat_labels.length===0,appearance.duplicate_stat_labels);
    }catch(e){check(rec.checks,'primary-appearance-inspection',false,String(e.message||e).slice(0,350));}
    output.push(rec);await context.close();
  }
  fs.writeFileSync(path.join(dir,'primary-appearance.json'),JSON.stringify(output,null,2)+'\n');return output;
}
async function routeAvailabilityProbes(browser,base,state,routes,dir){
  const expectedStatus={'projects.dashboard':200,'password.confirm':200,'purchasing.reports':404,'purchasing.returns':404,'sales-returns.index':404};
  const checkpoint=path.join(dir,'route-availability-probes.checkpoint.json');const output=fs.existsSync(checkpoint)?JSON.parse(fs.readFileSync(checkpoint,'utf8')):[];for(const route of routes){if(output.some(row=>row.route===route.name))continue;const context=await browser.newContext({baseURL:base,storageState:state,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width:1440,height:900}}),page=await context.newPage(),rec={route:route.name,module:route.module,url:route.url,expected_status:expectedStatus[route.name]??null,checks:[]};
    try{const response=await page.goto(`${base}${route.url}`,{waitUntil:'domcontentloaded',timeout:30000});rec.http_status=response?.status()??null;rec.final_path=new URL(page.url()).pathname;rec.heading=await page.locator(primary).first().innerText({timeout:1000}).catch(()=>null);rec.livewire_component=await page.locator('[wire\\:id]').count();rec.classification=route.name==='purchasing.reports'||route.name==='purchasing.returns'||route.name==='sales-returns.index'?(rec.http_status===404?'expected-gated-404':'unexpected-gated-route-status'):rec.http_status===200?'available':'unavailable';
      if(rec.http_status===200){const screenshot=path.join(dir,'screenshots',`${visual.safeName(route.name)}-availability-1440.png`);await page.screenshot({path:screenshot,fullPage:false});rec.screenshot=path.relative(root,screenshot).replace(/\\/g,'/');}
      const pass=rec.expected_status===null||rec.http_status===rec.expected_status;check(rec.checks,'parameterless-route-availability-classified',pass,{status:rec.http_status,expected:rec.expected_status,classification:rec.classification,final_path:rec.final_path,heading:rec.heading});
    }catch(e){rec.error=String(e.message||e).slice(0,400);check(rec.checks,'parameterless-route-availability-classified',false,rec.error);}
    output.push(rec);fs.writeFileSync(checkpoint,JSON.stringify(output,null,2)+'\n');await context.close();}
  fs.writeFileSync(path.join(dir,'route-availability-probes.json'),JSON.stringify(output,null,2)+'\n');return output;
}
async function layoutGeometryDiagnostic(browser,base,state,routes,dir,viewportList=[viewports[2]]){
  const rows=[];for(const vp of viewportList)for(const route of routes){const context=await browser.newContext({baseURL:base,storageState:state,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width:vp.width,height:vp.height}}),page=await context.newPage(),rec={route:route.name,module:route.module,url:route.url,viewport:vp,checks:[]};
    try{const response=await page.goto(`${base}${route.url}`,{waitUntil:'domcontentloaded',timeout:30000});rec.http_status=response?.status()??null;await page.locator(primary).first().waitFor({state:'visible',timeout:10000});rec.geometry=await page.evaluate(()=>{const box=r=>({x:+r.x.toFixed(1),y:+r.y.toFixed(1),width:+r.width.toFixed(1),height:+r.height.toFixed(1),right:+r.right.toFixed(1),bottom:+r.bottom.toFixed(1)});const describe=el=>{const r=el.getBoundingClientRect(),s=getComputedStyle(el);return{tag:el.tagName.toLowerCase(),id:el.id||null,class:String(el.className?.baseVal||el.className||'').trim().slice(0,180),text:(el.innerText||'').replace(/\s+/g,' ').trim().slice(0,100),rect:box(r),scroll_width:el.scrollWidth,client_width:el.clientWidth,display:s.display,position:s.position,overflow_x:s.overflowX,white_space:s.whiteSpace,min_width:s.minWidth};};const main=document.querySelector('#main-content,main'),grid=main?.querySelector('.grid.items-start'),form=main?.querySelector('form.grid.items-start');const gridDetails=el=>el?{...describe(el),grid_template_columns:getComputedStyle(el).gridTemplateColumns,column_gap:getComputedStyle(el).columnGap,children:[...el.children].map(describe)}:null;const wideMain=[...(main?.querySelectorAll('*')||[])].filter(el=>{const r=el.getBoundingClientRect(),s=getComputedStyle(el);return r.width>0&&r.height>0&&r.right>innerWidth+3&&s.display!=='none'&&s.visibility!=='hidden'}).sort((a,b)=>b.getBoundingClientRect().right-a.getBoundingClientRect().right).slice(0,20);const wide=[...document.querySelectorAll('body *')].filter(el=>{const r=el.getBoundingClientRect(),s=getComputedStyle(el);return r.width>0&&r.height>0&&r.right>innerWidth+3&&s.display!=='none'&&s.visibility!=='hidden'}).sort((a,b)=>b.getBoundingClientRect().right-a.getBoundingClientRect().right).slice(0,25);return{document:{scroll_width:document.documentElement.scrollWidth,viewport_width:innerWidth},main:main?describe(main):null,main_children:main?[...main.children].map(describe):[],available_main_width:main?.getBoundingClientRect().width||null,outer_form:gridDetails(form),product_grid:gridDetails(grid),wide_main_elements:wideMain.map(describe),wide_elements:wide.map(describe),overflowing_subtrees:[...document.querySelectorAll('main *,#main-content *')].filter(el=>el.scrollWidth>el.clientWidth+12&&el.clientWidth>0).sort((a,b)=>(b.scrollWidth-b.clientWidth)-(a.scrollWidth-a.clientWidth)).slice(0,15).map(describe)};});}
    catch(e){rec.error=String(e.message||e).slice(0,350);}rows.push(rec);fs.writeFileSync(path.join(dir,'layout-geometry-diagnostic.json'),JSON.stringify(rows,null,2)+'\n');await context.close();}
  return rows;
}
async function navigationInteractions(browser,base,state,dir){
  const rows=[];
  for(const vp of [viewports[0],viewports[3]]){
    const context=await browser.newContext({baseURL:base,storageState:state,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width:vp.width,height:vp.height}}),page=await context.newPage(),rec={viewport:vp,checks:[],console_errors:[]};
    page.on('console',m=>{if(m.type()==='error')rec.console_errors.push(m.text().slice(0,300));});page.on('pageerror',e=>rec.console_errors.push(String(e.message||e).slice(0,300)));
    try{
      await page.goto(`${base}/dashboard`,{waitUntil:'domcontentloaded'});const nav=page.locator('.erp-sidebar');
      if(vp.width<=768){await page.locator('.erp-topbar button[aria-label="فتح القائمة"]').click();await nav.waitFor({state:'visible',timeout:5000});check(rec.checks,'mobile-drawer-opens',await nav.isVisible(),null);}
      const search=page.locator('#erp-navigation-search');await search.focus();await page.keyboard.type('المخزون');const results=page.locator('#erp-navigation-results');await results.waitFor({state:'visible',timeout:5000});const links=await results.locator('a:visible').allTextContents();check(rec.checks,'navigation-search-filters-authorized-links',links.some(t=>/مخزون/.test(t)),links.map(t=>t.trim()));
      await page.keyboard.press('Escape');await page.waitForFunction(()=>document.querySelector('#erp-navigation-search')?.value==='');check(rec.checks,'navigation-search-escape-resets',await search.inputValue()==='',null);
      await search.focus();const focus=await page.evaluate(()=>{const el=document.querySelector('#erp-navigation-search'),box=el.closest('.erp-nav-search'),s=getComputedStyle(box);return{focused:document.activeElement===el,outline:s.outlineStyle,border:s.borderColor};});check(rec.checks,'keyboard-focus-indicator',focus.focused&&focus.outline!=='none',focus);
      if(vp.width<=768){const navBox=await nav.boundingBox(),backdrop=page.locator('[data-flux-sidebar-backdrop]'),x=navBox&&navBox.x>20?5:vp.width-5;await backdrop.click({position:{x,y:Math.floor(vp.height/2)},timeout:5000});await page.waitForFunction(()=>document.querySelector('.erp-sidebar')?.hasAttribute('data-flux-sidebar-collapsed-mobile')??false,null,{timeout:5000});check(rec.checks,'mobile-drawer-closes',await nav.evaluate(el=>el.hasAttribute('data-flux-sidebar-collapsed-mobile')),null);}
    }catch(e){check(rec.checks,'navigation-interactions-complete',false,String(e.message||e).slice(0,400));}
    check(rec.checks,'no-navigation-js-errors',rec.console_errors.length===0,rec.console_errors);rows.push(rec);await context.close();
  }
  fs.writeFileSync(path.join(dir,'navigation-interactions.json'),JSON.stringify(rows,null,2)+'\n');return rows;
}
async function productPaginationChecks(browser,base,state,route,dir,env){
  const rows=[];if(!route)return rows;
  for(const width of [1440,390]){
    const context=await browser.newContext({baseURL:base,storageState:state,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width,height:width===1440?900:844}}),page=await context.newPage(),rec={route:route.name,viewport:width,checks:[],livewire_paths:[]};
    page.on('request',r=>{if(r.method()==='POST'&&livewireUpdateUrl(r.url()))rec.livewire_paths.push(new URL(r.url()).pathname);});
    try{
      await page.goto(`${base}${route.url}`,{waitUntil:'domcontentloaded',timeout:45000});await page.locator(primary).first().waitFor({state:'visible',timeout:12000});
      const keys=async()=>page.locator('.erp-responsive-table tbody tr[wire\\:key^="product-"]').evaluateAll(rs=>rs.map(r=>r.getAttribute('wire:key')));
      const pageNumber=async()=>page.locator('.erp-responsive-table tbody tr[wire\\:key^="product-"] td[data-label="الصف"]').filter({visible:true}).first().innerText().then(t=>t.trim().replace(/[٠-٩]/g,d=>'٠١٢٣٤٥٦٧٨٩'.indexOf(d))).catch(()=>null);
      const initial=await keys(),next=page.locator('button[wire\\:click*="nextPage"],button[aria-label*="التالي"],button[aria-label*="next" i]').filter({visible:true}).first();
      if(!(await next.count())||await next.isDisabled().catch(()=>true)){check(rec.checks,'product-next-page-readonly-data-available',false,{visible_next:await next.count(),disabled:await next.isDisabled().catch(()=>true),first_page_rows:initial.length,reason:'Existing authorized fixture has no enabled next-page control.'});}
      else{
        const responseWait=page.waitForResponse(r=>livewireUpdateUrl(r.url())&&r.request().method()==='POST'&&r.status()<400,{timeout:15000});await next.click();const response=await responseWait;
        await page.waitForFunction(prev=>{const rows=[...document.querySelectorAll('.erp-responsive-table tbody tr[wire\\:key^="product-"]')].map(r=>r.getAttribute('wire:key'));return rows.length>0&&rows.some((key,i)=>key!==prev[i]);},initial,{timeout:12000});
        const second=await keys();check(rec.checks,'product-pagination-changes-existing-records',second.length>0&&JSON.stringify(initial)!==JSON.stringify(second),{initial_count:initial.length,second_count:second.length,initial_first:initial[0]||null,second_first:second[0]||null,response_path:new URL(response.url()).pathname});
      }
      if(width===390){
        const disclosure=page.locator('.erp-filter-disclosure').first(),toggle=disclosure.locator('[data-erp-filter-toggle]');
        if(await disclosure.count()&&await disclosure.getAttribute('data-open')==='false')await toggle.click();
      }
      const search=page.getByLabel('البحث عن صنف',{exact:true}).first();
      if(await search.count()){
        const filterResponse=page.waitForResponse(r=>livewireUpdateUrl(r.url())&&r.request().method()==='POST'&&r.status()<400,{timeout:15000});await search.fill(`NO_MATCH_PAGINATION_${width}`);const filtered=await filterResponse;
        await page.getByText('لا توجد أصناف للعرض',{exact:false}).waitFor({state:'visible',timeout:10000}).catch(()=>{});
        const noProductRows=await page.locator('.erp-responsive-table tbody tr[wire\\:key^="product-"]').count()===0;check(rec.checks,'product-search-resets-pagination-to-empty-first-page',noProductRows,{response_path:new URL(filtered.url()).pathname,no_product_rows:noProductRows});
        const resetResponse=page.waitForResponse(r=>livewireUpdateUrl(r.url())&&r.request().method()==='POST'&&r.status()<400,{timeout:15000});await search.fill('');const reset=await resetResponse;
        await page.locator('.erp-responsive-table tbody tr[wire\\:key^="product-"]').first().waitFor({state:'visible',timeout:12000});check(rec.checks,'product-search-clear-restores-first-page',true,{response_path:new URL(reset.url()).pathname,rows:await keys()});
      }else check(rec.checks,'product-search-filter-is-available',false,'Exact labeled product search not found.');
      const nextAgain=page.locator('button[wire\\:click*="nextPage"],button[aria-label*="التالي"],button[aria-label*="next" i]').filter({visible:true}).first();
      if(await nextAgain.count()&&!(await nextAgain.isDisabled().catch(()=>true))){const pageTwoResponse=page.waitForResponse(r=>livewireUpdateUrl(r.url())&&r.request().method()==='POST'&&r.status()<400,{timeout:15000});const beforePageTwo=await keys();await nextAgain.click();const response=await pageTwoResponse;await page.waitForFunction(prev=>{const rows=[...document.querySelectorAll('.erp-responsive-table tbody tr[wire\\:key^="product-"]')].map(r=>r.getAttribute('wire:key'));return rows.length>0&&JSON.stringify(rows)!==JSON.stringify(prev);},beforePageTwo,{timeout:12000});rec.page_before_sort=await pageNumber();rec.page_two_before_sort_keys=await keys();rec.page_two_response_path=new URL(response.url()).pathname;}
      const sort=page.locator('.erp-mobile-table-tools button[wire\\:click*="sortBy(\'name\')"]:visible, thead button[wire\\:click*="sortBy(\'name\')"]:visible').first();
      if(await sort.count()){
        const beforeSort=await keys(),sortResponse=page.waitForResponse(r=>livewireUpdateUrl(r.url())&&r.request().method()==='POST'&&r.status()<400,{timeout:15000});await sort.click();const response=await sortResponse;await page.locator('.erp-responsive-table tbody tr[wire\\:key^="product-"]').first().waitFor({state:'visible',timeout:10000});
        const afterPage=await pageNumber();check(rec.checks,'product-sort-resets-to-first-page',rec.page_before_sort==='11'&&afterPage==='1',{response_path:new URL(response.url()).pathname,before_first_visible_row:rec.page_before_sort,after_first_visible_row:afterPage,before_first:beforeSort[0]||null,after_first:(await keys())[0]||null});
      }else check(rec.checks,'product-name-sort-control-available',false,'No visible read-only product name sort control.');
    }catch(e){check(rec.checks,'product-pagination-filter-sort-journey',false,String(e.message||e).slice(0,500));}
    rows.push(rec);await context.close();fs.writeFileSync(path.join(dir,'product-pagination-interactions.checkpoint.json'),JSON.stringify(rows,null,2)+'\n');
  }
  fs.writeFileSync(path.join(dir,'product-pagination-interactions.json'),JSON.stringify(rows,null,2)+'\n');return rows;
}
async function moreActionChecks(browser,base,state,routes,dir){
  const route=routes.find(r=>r.name==='sales-orders.index');if(!route)return[];const rows=[];
  for(const width of [390]){
    const context=await browser.newContext({baseURL:base,storageState:state,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width,height:width===1440?900:844}}),page=await context.newPage(),rec={route:route.name,viewport:width,checks:[]};
    try{await page.goto(`${base}${route.url}`,{waitUntil:'domcontentloaded',timeout:45000});await page.locator(primary).first().waitFor({state:'visible',timeout:12000});const actions=page.locator('.erp-secondary-actions'),toggle=actions.getByRole('button',{name:/المزيد/}),menu=actions.locator('.erp-secondary-menu');
      await toggle.waitFor({state:'visible',timeout:8000});check(rec.checks,'secondary-actions-menu-has-real-options',await menu.locator('a,button,[role="menuitem"]').count()>0,{options:await menu.locator('a,button,[role="menuitem"]').allTextContents()});
      await toggle.click();await page.waitForFunction(el=>el.dataset.open==='true',await menu.elementHandle(),{timeout:5000});const exportButton=menu.getByRole('button',{name:/تصدير Excel/});const exportLink=menu.getByRole('link',{name:/تصدير Excel/});const hasExport=await exportButton.isVisible().catch(()=>false)||await exportLink.isVisible().catch(()=>false);check(rec.checks,'secondary-actions-menu-opens-with-authorized-export',await menu.isVisible()&&hasExport,{hasExport,expanded:await toggle.getAttribute('aria-expanded')});
      await page.keyboard.press('Escape');await page.waitForFunction(el=>el.dataset.open==='false',await menu.elementHandle(),{timeout:5000});check(rec.checks,'secondary-actions-menu-closes-with-escape',await toggle.getAttribute('aria-expanded')==='false',await toggle.getAttribute('aria-expanded'));
    }catch(e){check(rec.checks,'secondary-actions-menu-interaction',false,String(e.message||e).slice(0,350));}rows.push(rec);await context.close();
  }
  fs.writeFileSync(path.join(dir,'secondary-action-interactions.json'),JSON.stringify(rows,null,2)+'\n');return rows;
}
async function catalogAcceptanceChecks(browser,base,state,routes,dir){
  const names=['products.index','customers.index','suppliers.index'],rows=[],desktop=new Map();
  for(const name of names){
    const route=routes.find(item=>item.name===name);if(!route)continue;
    const desktopContext=await browser.newContext({baseURL:base,storageState:state,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width:1440,height:900}}),desktopPage=await desktopContext.newPage(),desktopRec={route:name,viewport:'desktop-1440',checks:[],source_sha256:performance.sourceFingerprint()};
    try{
      const response=await desktopPage.goto(`${base}${route.url}`,{waitUntil:'domcontentloaded',timeout:30000});await desktopPage.locator(primary).first().waitFor({state:'visible',timeout:12000});const table=desktopPage.locator('.erp-catalog-records'),record=table.locator('tbody tr[role="row"]').first();await record.waitFor({state:'visible',timeout:12000});
      const snapshot=await record.evaluate(row=>Array.from(row.querySelectorAll('td[role="cell"]')).map(cell=>({label:cell.getAttribute('data-label')||'',text:(cell.textContent||'').replace(/\s+/g,' ').trim()})));desktop.set(name,snapshot);desktopRec.fields=snapshot.map(item=>item.label);desktopRec.cell_count=snapshot.length;
      check(desktopRec.checks,'catalog-desktop-route-has-real-record',response?.status()===200&&snapshot.length>0,{status:response?.status()??null,cells:snapshot.length});check(desktopRec.checks,'catalog-summary-and-responsive-wrapper-render',await desktopPage.locator('.erp-kpi-summary').count()>0&&await table.count()>0,{summary:await desktopPage.locator('.erp-kpi-summary').count(),records:await table.count()});
    }catch(error){check(desktopRec.checks,'catalog-desktop-readiness',false,String(error.message||error).slice(0,300));}finally{await desktopContext.close();}
    rows.push(desktopRec);
    for(const width of [390,360]){
      const vp={name:width===390?'mobile-390':'mobile-360',width,height:width===390?844:800},context=await browser.newContext({baseURL:base,storageState:state,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width,height:vp.height}}),page=await context.newPage(),rec={route:name,viewport:vp.name,width,checks:[],mutation_submitted:false,source_sha256:performance.sourceFingerprint()};
      try{
        const response=await page.goto(`${base}${route.url}`,{waitUntil:'domcontentloaded',timeout:30000});await page.locator(primary).first().waitFor({state:'visible',timeout:12000});const table=page.locator('.erp-catalog-records'),record=table.locator('tbody tr[role="row"]').first();await record.waitFor({state:'visible',timeout:12000});
        const data=await record.evaluate(row=>({cells:Array.from(row.querySelectorAll('td[role="cell"]')).map(cell=>({label:cell.getAttribute('data-label')||'',text:(cell.textContent||'').replace(/\s+/g,' ').trim(),visible:(()=>{const r=cell.getBoundingClientRect(),s=getComputedStyle(cell);return r.width>0&&r.height>0&&s.display!=='none'&&s.visibility!=='hidden';})(),rect:(()=>{const r=cell.getBoundingClientRect();return{x:r.x,y:r.y,width:r.width,height:r.height};})()})),rowRect:(()=>{const r=row.getBoundingClientRect();return{x:r.x,y:r.y,width:r.width,height:r.height};})()}));
        const expected=desktop.get(name)||[],actual=data.cells.map(({label,text})=>({label,text})),same=JSON.stringify(actual)===JSON.stringify(expected),allVisible=data.cells.length>0&&data.cells.every(cell=>cell.visible),overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth);
        rec.cell_count=data.cells.length;rec.visible_cell_count=data.cells.filter(cell=>cell.visible).length;rec.field_labels=data.cells.map(cell=>cell.label);rec.row_top=data.rowRect.y;rec.fields_match_desktop=same;
        check(rec.checks,'catalog-mobile-route-http-200',response?.status()===200,response?.status()??null);check(rec.checks,'catalog-mobile-preserves-every-desktop-cell-value',same,{desktop_cell_count:expected.length,mobile_cell_count:actual.length,desktop,mobile:actual});check(rec.checks,'catalog-mobile-all-record-cells-visible',allVisible,{visible:rec.visible_cell_count,total:rec.cell_count});check(rec.checks,'catalog-mobile-document-has-no-horizontal-overflow',!overflow,{viewport:width});check(rec.checks,'first-catalog-record-starts-within-initial-viewport',data.rowRect.y<vp.height,data.rowRect);
        const metadata=name==='suppliers.index'?['التحديد','الصف','الشعار']:name==='customers.index'?['التحديد','الشعار']:['التحديد','الصف'];
        const compactRects=metadata.map(label=>data.cells.find(cell=>cell.label===label)?.rect).filter(Boolean),compact=compactRects.length===metadata.length&&Math.max(...compactRects.map(rect=>rect.y))-Math.min(...compactRects.map(rect=>rect.y))<32;check(rec.checks,'catalog-selection-ordinal-logo-metadata-aligns',compact,{expected:metadata,rectangles:compactRects,actual_labels:data.cells.map(cell=>cell.label)});
        if(width===360){
          const more=page.getByRole('button',{name:'المزيد',exact:true}).last();await more.waitFor({state:'visible',timeout:8000});await more.click();const menu=page.getByRole('menu').last();await menu.waitFor({state:'visible',timeout:8000});const menuText=(await menu.innerText()).replace(/\s+/g,' ').trim();
          const importVisible=/استيراد/.test(menuText),exportVisible=/تصدير/.test(menuText);rec.more_menu_text=menuText;check(rec.checks,'catalog-mobile-more-opens-with-import-and-export',importVisible&&exportVisible,{importVisible,exportVisible});await page.keyboard.press('Escape');await menu.waitFor({state:'hidden',timeout:8000});check(rec.checks,'catalog-mobile-more-closes-with-escape',true,true);
          const before=(await record.innerText()).replace(/\s+/g,' ').trim(),checkbox=record.getByRole('checkbox').first();await checkbox.waitFor({state:'visible',timeout:8000});await checkbox.click();
          const selectionText=name==='products.index'?page.getByText(/1\s+صنف محدد/).first():page.locator('.party-selection').getByText(/تم تحديد\s*1/).first();await selectionText.waitFor({state:'visible',timeout:10000});
          let cancel;
          if(name==='products.index'){
            const direct=page.getByRole('button',{name:'مسح التحديد',exact:true});
            if(await direct.isVisible().catch(()=>false))cancel=direct;
            else{const more=page.getByRole('button',{name:'المزيد',exact:true}).last();await more.click();const menu=page.getByRole('menu').last();await menu.waitFor({state:'visible',timeout:8000});cancel=menu.getByRole('menuitem',{name:'مسح التحديد',exact:true});}
          }else cancel=page.locator('.party-selection').getByRole('button',{name:'إلغاء التحديد',exact:true});
          await cancel.waitFor({state:'visible',timeout:8000});await cancel.click();
          if(name==='products.index')await page.getByText(/0\s+صنف محدد/).first().waitFor({state:'visible',timeout:10000});else await page.locator('.party-selection').waitFor({state:'hidden',timeout:10000});
          await page.waitForFunction(el=>!el.checked,await checkbox.elementHandle(),{timeout:10000});
          const after=(await record.innerText()).replace(/\s+/g,' ').trim();rec.row_unchanged_after_cancel=before===after;check(rec.checks,'catalog-mobile-selection-can-be-cancelled-without-changing-record',rec.row_unchanged_after_cancel,{row_unchanged:rec.row_unchanged_after_cancel,mutation_submitted:false});
        }
      }catch(error){check(rec.checks,'catalog-mobile-acceptance-journey',false,String(error.message||error).slice(0,350));}
      await context.close();rows.push(rec);
    }
  }
  fs.writeFileSync(path.join(dir,'catalog-acceptance-interactions.json'),JSON.stringify(rows,null,2)+'\n');return rows;
}
async function inventoryModalCancelCheck(browser,base,state,route,dir){
  const rec={route:route?.name||'inventory.dashboard',checks:[],mutation_submitted:false};if(!route)return rec;
  const context=await browser.newContext({baseURL:base,storageState:state,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width:1440,height:900}}),page=await context.newPage();
  try{
    await page.goto(`${base}${route.url}`,{waitUntil:'domcontentloaded',timeout:45000});await page.locator(primary).first().waitFor({state:'visible',timeout:12000});
    const action=page.getByRole('button',{name:'تعديل حد التنبيه',exact:true}).first();
    if(!(await action.count()))check(rec.checks,'authorized-existing-inventory-row-has-minimum-editor',false,'No row with the actual permission-gated edit action is available.');
    else{
      const row=action.locator('xpath=ancestor::tr[1]'),before=(await row.innerText()).replace(/\s+/g,' ').trim();
      await action.click();const dialog=page.getByRole('dialog').filter({visible:true}).last();await dialog.waitFor({state:'visible',timeout:8000});
      const input=dialog.getByLabel('الحد الأدنى بالوحدة الأساسية',{exact:true});rec.before_value=(await input.inputValue()).trim();check(rec.checks,'inventory-minimum-edit-dialog-opens',await input.isVisible(),{label:await input.getAttribute('aria-label')});
      const close=dialog.getByRole('button',{name:'Close',exact:true});if(await close.count())await close.click();else await page.keyboard.press('Escape');
      await page.waitForFunction(()=>![...document.querySelectorAll('[role="dialog"]')].some(el=>{const r=el.getBoundingClientRect(),s=getComputedStyle(el);return r.width>0&&r.height>0&&s.display!=='none'&&s.visibility!=='hidden';}),null,{timeout:8000});
      const after=(await row.innerText()).replace(/\s+/g,' ').trim();rec.before_row_text=before;rec.after_row_text=after;check(rec.checks,'inventory-minimum-dialog-cancels-without-business-mutation',before===after,{row_unchanged:before===after,close_button_found:await close.count()>0,mutation_submitted:false,existing_minimum:rec.before_value});
      const visibleDialogs=await page.locator('[role="dialog"]').evaluateAll(els=>els.filter(el=>{const r=el.getBoundingClientRect(),s=getComputedStyle(el);return r.width>0&&r.height>0&&s.display!=='none'&&s.visibility!=='hidden';}).length);
      check(rec.checks,'inventory-minimum-edit-dialog-is-closed',visibleDialogs===0,{visible_dialogs:visibleDialogs});
    }
  }catch(e){check(rec.checks,'inventory-minimum-modal-cancel-journey',false,String(e.message||e).slice(0,400));}
  await context.close();fs.writeFileSync(path.join(dir,'inventory-modal-cancel.json'),JSON.stringify(rec,null,2)+'\n');return rec;
}
async function operationPreviewCheck(browser,base,state,route,dir,env,evidence){
  const rec={route:'operations.show',checks:[],preview_only:true,mutation_submitted:false};
  try{
    const {spawnSync}=require('node:child_process');
    const auth=spawnSync('php',[path.join(root,'tests','E2E','support','performance-auth-refresh.php')],{cwd:root,env,encoding:'utf8',windowsHide:true});
    if(auth.error||auth.status!==0)throw new Error(`Guarded actor refresh failed (${auth.status??'unknown'}).`);
    const actor=String(auth.stdout||'').trim().split(/\r?\n/).reverse().map(line=>{try{return JSON.parse(line);}catch{return null;}}).find(value=>value&&Number.isInteger(Number(value.id)));
    if(!actor?.email||!actor?.password)throw new Error('Guarded auth refresh did not return an in-memory authorized actor.');
    const authContext=await browser.newContext({baseURL:base,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width:1440,height:900}}),login=await authContext.newPage();
    await login.goto(`${base}/login`,{waitUntil:'domcontentloaded',timeout:20000});await login.locator('input[name="email"]').fill(actor.email);await login.locator('input[name="password"]').fill(actor.password);
    await Promise.all([login.waitForURL(/\/dashboard(?:\?|$)/,{waitUntil:'domcontentloaded',timeout:20000}),login.locator('[data-test="login-button"]').click()]);
    if(new URL(login.url()).pathname!=='/dashboard')throw new Error('Guarded actor did not reach dashboard.');
    rec.security_step_up={url:'/settings/security',status:null,final_path:null,confirmed_through_ui:false,screenshots:[]};
    await login.goto(`${base}/user/confirm-password`,{waitUntil:'domcontentloaded',timeout:20000});
    await login.locator('[data-test="confirm-password-button"]').waitFor({state:'visible',timeout:12000});
    await login.locator('input[name="password"]').fill(actor.password);
    await Promise.all([login.waitForNavigation({waitUntil:'domcontentloaded',timeout:20000}),login.locator('[data-test="confirm-password-button"]').click()]);
    const securityResponse=await login.goto(`${base}/settings/security`,{waitUntil:'domcontentloaded',timeout:20000});
    rec.security_step_up.status=securityResponse?.status()??null;rec.security_step_up.final_path=new URL(login.url()).pathname;
    rec.security_step_up.confirmed_through_ui=rec.security_step_up.status===200&&rec.security_step_up.final_path==='/settings/security'&&await login.locator(primary).first().isVisible().catch(()=>false);
    check(rec.checks,'security-settings-reached-after-real-password-confirmation',rec.security_step_up.confirmed_through_ui,{status:rec.security_step_up.status,final_path:rec.security_step_up.final_path});
    if(rec.security_step_up.confirmed_through_ui){
      await authContext.storageState({path:visual.storageState});
      for(const vp of viewports){
        const securityContext=await browser.newContext({baseURL:base,storageState:visual.storageState,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width:vp.width,height:vp.height}}),securityPage=await securityContext.newPage();
        try{
          const r=await securityPage.goto(`${base}/settings/security`,{waitUntil:'domcontentloaded',timeout:30000});await securityPage.locator(primary).first().waitFor({state:'visible',timeout:12000});
          const design=await inspect(securityPage),fileName=`security.edit-auth-confirmed-${vp.name}-${performance.sourceFingerprint().slice(0,8)}.png`,shot=path.join(dir,'screenshots',fileName);await securityPage.screenshot({path:shot,fullPage:false});
          const row={route:'security.edit',module:'M17 System',url:'/settings/security',variant:'authenticated-password-confirmed',viewport:vp,state:'authorized-password-confirmed',http_status:r?.status()??null,design,checks:[],console_errors:[],failed_requests:[],asset_requests:[],screenshot:path.relative(root,shot).replace(/\\/g,'/'),source_sha256:performance.sourceFingerprint(),build_assets:{css:currentBuildAssetsFromManifest()}};
          check(row.checks,'security-settings-http-200',row.http_status===200,row.http_status);check(row.checks,'security-settings-main-and-sidebar',design.main&&design.sidebar,{main:design.main,sidebar:design.sidebar});check(row.checks,'security-settings-rtl-no-overflow',design.dir==='rtl'&&!design.overflow,{dir:design.dir,scroll_width:design.scroll_width,viewport_width:design.viewport_width});check(row.checks,'security-settings-heading-visible',!!design.heading,design.heading);check(row.checks,'security-settings-fields-labeled',design.unlabeled_fields.length===0,design.unlabeled_fields);evidence.screenshots.push(row);rec.security_step_up.screenshots.push({viewport:vp.name,status:row.http_status,screenshot:row.screenshot});
        }catch(e){rec.security_step_up.screenshots.push({viewport:vp.name,error:String(e.message||e).slice(0,250)});}await securityContext.close();
      }
      const oldSecurity=evidence.screenshots.filter(s=>s.route==='security.edit'&&s.state!=='authorized-password-confirmed');
      if(oldSecurity.length){evidence.superseded_captures||=[];for(const old of oldSecurity)evidence.superseded_captures.push({route:old.route,viewport:old.viewport?.name||null,url:old.url||null,http_status:old.http_status,screenshot:old.screenshot||null,checks:old.checks||[],reason:'This attempt landed on Fortify password-confirmation instead of security settings. Replaced after authenticating the synthetic actor and completing password confirmation through the real UI.',replacement_url:'/settings/security',replacement_screenshots:rec.security_step_up.screenshots});evidence.screenshots=evidence.screenshots.filter(s=>!oldSecurity.includes(s));}
      evidence.not_measured_pages=(evidence.not_measured_pages||[]).filter(r=>r.route!=='security.edit');
    }
    await authContext.storageState({path:visual.storageState});
    await authContext.close();
    await authContext.storageState({path:visual.storageState});await authContext.close();
    const prepared=spawnSync('php',[path.join(root,'tests','E2E','support','performance-operation-preview.php'),String(actor.id)],{cwd:root,env,encoding:'utf8',windowsHide:true});
    if(prepared.error||prepared.status!==0)throw new Error(`Authorized preview fixture helper failed (${prepared.status??'unknown'}).`);
    const preview=String(prepared.stdout||'').trim().split(/\r?\n/).reverse().map(line=>{try{return JSON.parse(line);}catch{return null;}}).find(value=>value&&typeof value.run_id==='string');
    if(!preview||Number(preview.actor_id)!==Number(actor.id)||Number(preview.item_count)<1)throw new Error('Preview helper returned an invalid preview receipt.');
    rec.preview_run_id=preview.run_id;rec.actor_id=Number(actor.id);rec.item_count=Number(preview.item_count);
    const url=`/data-operations/${encodeURIComponent(preview.run_id)}`,context=await browser.newContext({baseURL:base,storageState:visual.storageState,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width:1440,height:900}}),page=await context.newPage();
    const pageErrors=[];page.on('pageerror',e=>pageErrors.push(String(e.message||e).slice(0,250)));page.on('console',m=>{if(m.type()==='error')pageErrors.push(m.text().slice(0,250));});
    const response=await page.goto(`${base}${url}`,{waitUntil:'domcontentloaded',timeout:30000});await page.locator(primary).first().waitFor({state:'visible',timeout:12000});
    const body=await page.locator('body').innerText(),identity=body.includes(`#${actor.id}`),previewMarker=body.includes('معاينة التغييرات')&&body.includes('معاينة فقط'),afterValue=body.includes('معاينة أداء فقط — دون تنفيذ');
    const confirm=page.getByRole('button',{name:/تأكيد وتطبيق/});const confirmationAvailable=await confirm.count()>0;
    rec.url=url;rec.status=response?.status()??null;rec.first_load_identity=identity;rec.first_load_preview=previewMarker;rec.first_load_has_planned_after_value=afterValue;rec.confirmation_button_visible=confirmationAvailable;
    check(rec.checks,'operation-preview-owner-identity',rec.status===200&&identity,{status:rec.status,actor_id:actor.id,url});check(rec.checks,'operation-remains-preview-and-not-executed',previewMarker&&confirmationAvailable,{preview_marker:previewMarker,confirmation_button_visible:confirmationAvailable});check(rec.checks,'operation-preview-retains-row-plan',afterValue,{item_count:rec.item_count,planned_after_value_visible:afterValue});
    rec.preview_screenshots=[];
    for(const vp of viewports){
      const vpContext=await browser.newContext({baseURL:base,storageState:visual.storageState,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width:vp.width,height:vp.height}}),vpPage=await vpContext.newPage(),vpErrors=[],vpFailed=[];
      vpPage.on('pageerror',e=>vpErrors.push(String(e.message||e).slice(0,250)));vpPage.on('console',m=>{if(m.type()==='error')vpErrors.push(m.text().slice(0,250));});vpPage.on('requestfailed',r=>vpFailed.push(`${r.method()} ${r.url()} ${r.failure()?.errorText||''}`.slice(0,250)));
      const vpAssets=[];vpPage.on('response',r=>{if(/\/build\/assets\/.*\.(?:css|js)(?:\?|$)/i.test(r.url()))vpAssets.push({url:r.url(),status:r.status()});});
      try{
        const vpResponse=await vpPage.goto(`${base}${url}`,{waitUntil:'domcontentloaded',timeout:30000});await vpPage.locator(primary).first().waitFor({state:'visible',timeout:12000});
        const vpBody=await vpPage.locator('body').innerText(),vpIdentity=vpBody.includes(`#${actor.id}`),vpPreview=vpBody.includes('معاينة التغييرات')&&vpBody.includes('معاينة فقط');
        const filename=`operations.show-owner-preview-${vp.name}-${performance.sourceFingerprint().slice(0,8)}.png`,screenshotPath=path.join(dir,'screenshots',filename);await vpPage.screenshot({path:screenshotPath,fullPage:false});
        const screen={route:'operations.show',module:route?.module||'M17 System',url,viewport:vp,state:'authorized-owner-preview',http_status:vpResponse?.status()??null,design:await inspect(vpPage),checks:[],console_errors:vpErrors,failed_requests:vpFailed,asset_requests:vpAssets,screenshot:path.relative(root,screenshotPath).replace(/\\/g,'/'),source_sha256:performance.sourceFingerprint(),build_assets:JSON.parse(fs.readFileSync(path.join(root,'public','build','manifest.json'),'utf8'))['resources/css/app.css']?.file?{css:`/build/${JSON.parse(fs.readFileSync(path.join(root,'public','build','manifest.json'),'utf8'))['resources/css/app.css'].file}`}:null};
        check(screen.checks,'owner-preview-http-200',screen.http_status===200,screen.http_status);check(screen.checks,'owner-preview-identity',vpIdentity,{actor_id:actor.id});check(screen.checks,'owner-preview-remains-unexecuted',vpPreview,{preview:vpPreview});check(screen.checks,'owner-preview-rtl-and-no-overflow',screen.design.dir==='rtl'&&!screen.design.overflow,{dir:screen.design.dir,scroll_width:screen.design.scroll_width,viewport_width:screen.design.viewport_width});check(screen.checks,'owner-preview-heading-visible',!!screen.design.heading,screen.design.heading);check(screen.checks,'owner-preview-assets-200',screen.asset_requests.every(a=>a.status===200),screen.asset_requests);check(screen.checks,'owner-preview-has-no-browser-errors',vpErrors.length===0,{errors:vpErrors,failed_requests:vpFailed});evidence.screenshots.push(screen);rec.preview_screenshots.push({viewport:vp.name,status:screen.http_status,identity:vpIdentity,preview:vpPreview,screenshot:screen.screenshot});
      }catch(e){rec.preview_screenshots.push({viewport:vp.name,error:String(e.message||e).slice(0,300)});}
      await vpContext.close();
    }
    const oldAttempts=(evidence.screenshots||[]).filter(s=>s.route==='operations.show'&&s.state!=='authorized-owner-preview'&&(s.http_status!==200||(s.checks||[]).some(c=>c.result==='fail')));
    if(oldAttempts.length){evidence.superseded_captures||=[];for(const old of oldAttempts)evidence.superseded_captures.push({route:old.route,viewport:old.viewport?.name||null,url:old.url||null,http_status:old.http_status,screenshot:old.screenshot||null,checks:old.checks||[],reason:'The initial manifest fixture URL was stale or inaccessible. Replaced by a verified actor-owned preview URL; the raw attempt is retained in this superseded record.',replacement_url:url,replacement_screenshots:rec.preview_screenshots});evidence.screenshots=evidence.screenshots.filter(s=>!oldAttempts.includes(s));}
    const operationRoute=(evidence.routes||[]).find(r=>r.name==='operations.show');if(operationRoute){operationRoute.url=url;operationRoute.variant='authorized-owner-preview';operationRoute.baseline_status=200;}
    const reload=await page.reload({waitUntil:'domcontentloaded',timeout:30000});await page.locator(primary).first().waitFor({state:'visible',timeout:12000});const afterReload=await page.locator('body').innerText();rec.reload_status=reload?.status()??null;rec.reload_identity=afterReload.includes(`#${actor.id}`);rec.reload_preview=afterReload.includes('معاينة التغييرات')&&afterReload.includes('معاينة فقط');
    check(rec.checks,'operation-preview-identity-history-survives-reload',rec.reload_status===200&&rec.reload_identity&&rec.reload_preview,{status:rec.reload_status,identity:rec.reload_identity,preview:rec.reload_preview});check(rec.checks,'operation-page-has-no-browser-errors',pageErrors.length===0,pageErrors);rec.browser_errors=pageErrors;await context.close();
  }catch(e){check(rec.checks,'authorized-operation-preview-view-and-reload',false,String(e.message||e).slice(0,500));}
  fs.writeFileSync(path.join(dir,'operation-preview-owner-check.json'),JSON.stringify(rec,null,2)+'\n');return rec;
}
async function reuseExistingOperationPreviewCheck(browser,base,dir,evidence){
  const rec={route:'operations.show',checks:[],preview_only:true,mutation_submitted:false,reused_existing_owner_fixture:true};
  const receiptPath=path.join(dir,'operation-preview-owner-check.json');
  const prior=fs.existsSync(receiptPath)?JSON.parse(fs.readFileSync(receiptPath,'utf8')):null;
  if(!prior?.actor_id||!prior?.preview_run_id||prior.preview_only!==true||prior.mutation_submitted!==false){
    check(rec.checks,'existing-owner-preview-receipt-available',false,'No previously verified, non-executed owner preview receipt is available.');
    return rec;
  }
  const actorId=Number(prior.actor_id),url=`/data-operations/${encodeURIComponent(prior.preview_run_id)}`;rec.actor_id=actorId;rec.preview_run_id=prior.preview_run_id;rec.url=url;rec.preview_screenshots=[];
  for(const vp of viewports){
    const context=await browser.newContext({baseURL:base,storageState:visual.storageState,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width:vp.width,height:vp.height}}),page=await context.newPage(),errors=[],failed=[];
    page.on('pageerror',e=>errors.push(String(e.message||e).slice(0,250)));page.on('console',m=>{if(m.type()==='error')errors.push(m.text().slice(0,250));});page.on('requestfailed',r=>failed.push(`${r.method()} ${r.url()} ${r.failure()?.errorText||''}`.slice(0,250)));
    const assets=[];page.on('response',r=>{if(/\/build\/assets\/.*\.(?:css|js)(?:\?|$)/i.test(r.url()))assets.push({url:r.url(),status:r.status()});});
    let response=null,landedPath=null;
    try{
      response=await page.goto(`${base}${url}`,{waitUntil:'domcontentloaded',timeout:30000});landedPath=new URL(page.url()).pathname;
      if(response?.status()!==200||landedPath!==url)throw new Error(`Stored browser state did not open the existing owner preview (HTTP ${response?.status()??'unknown'}, landed ${landedPath}).`);
      await page.locator(primary).first().waitFor({state:'visible',timeout:12000});
      const body=await page.locator('body').innerText(),identity=body.includes(`#${actorId}`),preview=body.includes('معاينة التغييرات')&&body.includes('معاينة فقط'),design=await inspect(page),filename=`operations.show-owner-preview-${vp.name}-${performance.sourceFingerprint().slice(0,8)}.png`,shot=path.join(dir,'screenshots',filename);await page.screenshot({path:shot,fullPage:false});
      const row={route:'operations.show',module:'M17 System',url,variant:'authorized-owner-preview',viewport:vp,state:'authorized-owner-preview',http_status:response?.status()??null,design,checks:[],console_errors:errors,failed_requests:failed,asset_requests:assets,screenshot:path.relative(root,shot).replace(/\\/g,'/'),source_sha256:performance.sourceFingerprint(),build_assets:{css:currentBuildAssetsFromManifest()}};
      check(row.checks,'owner-preview-http-200',row.http_status===200,row.http_status);check(row.checks,'owner-preview-identity',identity,{actor_id:actorId});check(row.checks,'owner-preview-remains-unexecuted',preview,{preview});check(row.checks,'owner-preview-rtl-and-no-overflow',design.dir==='rtl'&&!design.overflow,{dir:design.dir,scroll_width:design.scroll_width,viewport_width:design.viewport_width});check(row.checks,'owner-preview-heading-visible',!!design.heading,design.heading);check(row.checks,'owner-preview-assets-200',assets.every(a=>a.status===200),assets);check(row.checks,'owner-preview-has-no-browser-errors',errors.length===0,{errors,failed_requests:failed});
      evidence.screenshots.push(row);rec.preview_screenshots.push({viewport:vp.name,status:row.http_status,identity,preview,screenshot:row.screenshot});
      if(vp.name==='desktop-1440'){
        const reload=await page.reload({waitUntil:'domcontentloaded',timeout:30000});await page.locator(primary).first().waitFor({state:'visible',timeout:12000});const reloadText=await page.locator('body').innerText();
        rec.reload_status=reload?.status()??null;rec.reload_identity=reloadText.includes(`#${actorId}`);rec.reload_preview=reloadText.includes('معاينة التغييرات')&&reloadText.includes('معاينة فقط');
        check(rec.checks,'operation-preview-identity-history-survives-reload',rec.reload_status===200&&rec.reload_identity&&rec.reload_preview,{status:rec.reload_status,identity:rec.reload_identity,preview:rec.reload_preview});check(rec.checks,'operation-preview-remains-unexecuted-no-submit',await page.getByRole('button',{name:/تأكيد وتطبيق/}).count()>0,{mutation_submitted:false});
      }
    }catch(e){rec.preview_screenshots.push({viewport:vp.name,status:response?.status()??null,landed_path:landedPath,error:String(e.message||e).slice(0,300)});}
    await context.close();
  }
  const old=evidence.screenshots.filter(s=>s.route==='operations.show'&&s.state!=='authorized-owner-preview');
  if(old.length){evidence.superseded_captures||=[];for(const row of old)evidence.superseded_captures.push({route:row.route,viewport:row.viewport?.name||null,url:row.url||null,http_status:row.http_status,screenshot:row.screenshot||null,checks:row.checks||[],reason:'Replaced the stale manifest path using the already verified, actor-owned preview receipt; preview only, no business action submitted.',replacement_url:url,replacement_screenshots:rec.preview_screenshots});evidence.screenshots=evidence.screenshots.filter(s=>!old.includes(s));}
  const operationRoute=evidence.routes.find(r=>r.name==='operations.show');if(operationRoute){operationRoute.url=url;operationRoute.variant='authorized-owner-preview';operationRoute.baseline_status=200;}
  if(!rec.preview_screenshots.some(s=>s.status===200&&!s.error)){evidence.not_measured_pages||=[];evidence.not_measured_pages=evidence.not_measured_pages.filter(r=>r.route!=='operations.show');evidence.not_measured_pages.push({route:'operations.show',url,reason:'The previously verified actor-owned preview receipt exists, but the current saved browser session cannot access it. No new actor was created and no owner action was submitted; current-source full-viewport preview screenshots remain unverified.'});}
  fs.writeFileSync(path.join(dir,'operation-preview-reuse-check.json'),JSON.stringify(rec,null,2)+'\n');return rec;
}
function ordinaryAcceptanceEnv(base,sourceEnv){
  const work=path.join(root,'.tmp','ui-acceptance'),cluster=path.join(work,'postgres-55462'),bootstrapPath=path.join(work,'bootstrap.json'),markerPath=path.join(cluster,'codex-owned.json');
  if(!fs.existsSync(bootstrapPath)||!fs.existsSync(markerPath))throw new Error('Owned UI acceptance bootstrap or marker is missing.');
  const bootstrap=JSON.parse(fs.readFileSync(bootstrapPath,'utf8')),marker=JSON.parse(fs.readFileSync(markerPath,'utf8'));
  if(bootstrap.status!=='READY'||Number(bootstrap.db_port)!==55462||bootstrap.database!=='erp_elec_new_test'||path.resolve(root,bootstrap.postgres_data_directory)!==path.resolve(cluster)||marker.kind!=='codex-ui-acceptance'||marker.repo!==root||Number(marker.port)!==55462||marker.database!=='erp_elec_new_test'||typeof marker.token!=='string'||marker.token.length<48)throw new Error('UI acceptance marker/bootstrap identity is invalid.');
  const runtime=path.join(work,'runtime'),storage=path.join(work,'storage'),views=path.join(work,'compiled-views');for(const dir of [runtime,storage,views,path.join(storage,'framework','sessions')])fs.mkdirSync(dir,{recursive:true});
  const relative=p=>path.relative(root,p).replace(/\\/g,'/');
  return {...sourceEnv,APP_URL:base,APP_ENV:'testing',APP_DEBUG:'false',APP_KEY:`base64:${crypto.randomBytes(32).toString('base64')}`,DEBUGBAR_ENABLED:'false',PULSE_ENABLED:'false',DB_CONNECTION:'pgsql',DB_HOST:'127.0.0.1',DB_PORT:'55462',DB_DATABASE:'erp_elec_new_test',DB_PASSWORD:'',DB_URL:'',CACHE_STORE:'array',SESSION_DRIVER:'file',SESSION_FILES:path.join(storage,'framework','sessions'),QUEUE_CONNECTION:'sync',MAIL_MAILER:'array',BROADCAST_CONNECTION:'null',FILESYSTEM_DISK:'local',APP_CONFIG_CACHE:relative(path.join(runtime,'config.php')),APP_ROUTES_CACHE:relative(path.join(runtime,'routes.php')),APP_EVENTS_CACHE:relative(path.join(runtime,'events.php')),APP_SERVICES_CACHE:relative(path.join(runtime,'services.php')),APP_PACKAGES_CACHE:relative(path.join(runtime,'packages.php')),VIEW_COMPILED_PATH:views,LARAVEL_STORAGE_PATH:storage,E2E_APP_STORAGE:storage,E2E_DB_PORT:'55462',E2E_DB_DATABASE:'erp_elec_new_test',E2E_CLUSTER_MARKER:markerPath,E2E_CLUSTER_TOKEN:marker.token};
}
async function ordinarySecurityCheck(browser,sourceEnv,dir,evidence){
  const rec={route:'security.edit',variant:'least-privilege-ui-acceptance',checks:[],authorization_checks:[],operation_owner_screenshots:[],mutation_submitted:false};let server=null,logFd=null,ordinaryContext=null,ownerContext=null;
  const base=`http://127.0.0.1:${await freePort()}`,env=ordinaryAcceptanceEnv(base,sourceEnv),privateServerLog=path.join(root,'.tmp','design-consistency',evidence.run_id,'ordinary-security-server.log');rec.private_view_cache_clear=clearOwnedBladeCache(env,55462);fs.mkdirSync(path.dirname(privateServerLog),{recursive:true});logFd=fs.openSync(privateServerLog,'w');
  try{
    const helper=require('child_process').spawnSync('php',[path.join(root,'tests','E2E','support','ui-acceptance-actors.php')],{cwd:root,env,encoding:'utf8',windowsHide:true});if(helper.error||helper.status!==0)throw new Error(`Least-privilege browser fixture creation failed (${helper.status??'unknown'}).`);
    const fixtures=String(helper.stdout||'').trim().split(/\r?\n/).reverse().map(line=>{try{return JSON.parse(line);}catch{return null;}}).find(value=>value&&value.ordinary?.email&&value.owner?.email);
    const ordinary=fixtures?.ordinary,owner=fixtures?.owner,runId=fixtures?.preview_run_id,previewUrl=runId?`/data-operations/${encodeURIComponent(runId)}`:null;
    if(!ordinary?.password||!owner?.password||!runId||fixtures.preview_executed!==false||!fixtures.preview_item_count)throw new Error('The isolated ordinary/owner fixture receipt is incomplete.');
    rec.ordinary_actor_id=Number(ordinary.id);rec.owner_actor_id=Number(owner.id);rec.preview_run_id=runId;rec.customer_code=fixtures.customer_code;rec.customer_city_before=fixtures.customer_city_before;rec.customer_city_after=fixtures.customer_city_after;rec.customer_city_persisted=fixtures.customer_city_persisted;rec.preview_item_status=fixtures.preview_item_status;rec.preview_only=true;rec.permission_codes=owner.permissions;rec.ordinary_authority_rows=ordinary.authority_rows;rec.owner_role_count=owner.role_count;rec.owner_scope_count=owner.scope_count;
    check(rec.checks,'ordinary-fixture-has-no-roles-grants-or-scopes',Object.values(ordinary.authority_rows).every(count=>Number(count)===0),ordinary.authority_rows);
    check(rec.checks,'owner-fixture-has-only-required-customer-permissions',owner.permissions.join(',')==='M03.customer_bulk_update,M03.customer_update,M03.customer_view'&&owner.role_count===0&&owner.scope_count===0,{permissions:owner.permissions,role_count:owner.role_count,scope_count:owner.scope_count});
    check(rec.checks,'real-bulk-preview-keeps-customer-unchanged',fixtures.customer_city_before==='القاهرة'&&fixtures.customer_city_after==='الجيزة'&&fixtures.customer_city_persisted===fixtures.customer_city_before&&fixtures.preview_item_status==='valid',{before:fixtures.customer_city_before,planned_after:fixtures.customer_city_after,persisted_after_preview:fixtures.customer_city_persisted,item_status:fixtures.preview_item_status,preview_executed:fixtures.preview_executed});
    server=spawn('php',['-S',new URL(base).host,'-t','.',path.join(root,'tests','E2E','support','performance-router.php')],{cwd:path.join(root,'public'),env,stdio:['ignore',logFd,logFd],windowsHide:true});await waitForServer(base);
    ordinaryContext=await browser.newContext({baseURL:base,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width:1440,height:900}});const ordinaryLogin=await ordinaryContext.newPage();
    const loginResponse=await ordinaryLogin.goto(`${base}/login`,{waitUntil:'domcontentloaded',timeout:20000});await ordinaryLogin.locator('input[name="email"]').fill(ordinary.email);await ordinaryLogin.locator('input[name="password"]').fill(ordinary.password);
    await Promise.all([ordinaryLogin.waitForURL(/\/dashboard(?:\?|$)/,{waitUntil:'domcontentloaded',timeout:20000}),ordinaryLogin.locator('[data-test="login-button"]').click()]);
    const loggedIn=new URL(ordinaryLogin.url()).pathname==='/dashboard';check(rec.checks,'ordinary-user-genuine-login',loginResponse?.status()===200&&loggedIn,{status:loginResponse?.status()??null,landed_path:new URL(ordinaryLogin.url()).pathname});if(!loggedIn)throw new Error('The ordinary fixture actor did not reach the dashboard.');
    await ordinaryLogin.goto(`${base}/user/confirm-password`,{waitUntil:'domcontentloaded',timeout:20000});await ordinaryLogin.locator('[data-test="confirm-password-button"]').waitFor({state:'visible',timeout:12000});await ordinaryLogin.locator('input[name="password"]').fill(ordinary.password);
    await Promise.all([ordinaryLogin.waitForNavigation({waitUntil:'domcontentloaded',timeout:20000}),ordinaryLogin.locator('[data-test="confirm-password-button"]').click()]);
    const confirmedAtPath=new URL(ordinaryLogin.url()).pathname,securityResponse=await ordinaryLogin.goto(`${base}/settings/security`,{waitUntil:'domcontentloaded',timeout:20000});await ordinaryLogin.locator(primary).first().waitFor({state:'visible',timeout:12000});
    rec.password_confirmation={submitted_through_ui:true,mutation_submitted:false,post_confirm_path:confirmedAtPath,security_status:securityResponse?.status()??null,security_path:new URL(ordinaryLogin.url()).pathname};
    const securityReady=securityResponse?.status()===200&&rec.password_confirmation.security_path==='/settings/security';check(rec.checks,'ordinary-user-opens-security-after-real-password-confirmation',securityReady,rec.password_confirmation);
    const ordinaryState=await ordinaryContext.storageState();rec.security_screenshots=[];
    for(const vp of viewports){
      const context=await browser.newContext({baseURL:base,storageState:ordinaryState,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width:vp.width,height:vp.height}}),page=await context.newPage(),errors=[];page.on('pageerror',error=>errors.push(String(error.message||error).slice(0,200)));page.on('console',message=>{if(message.type()==='error')errors.push(message.text().slice(0,200));});
      try{
        const response=await page.goto(`${base}/settings/security`,{waitUntil:'domcontentloaded',timeout:30000});await page.locator(primary).first().waitFor({state:'visible',timeout:12000});const design=await inspect(page),file=`security.edit-ordinary-unprivileged-${vp.name}-${performance.sourceFingerprint().slice(0,8)}.png`,shot=path.join(dir,'screenshots',file);await page.screenshot({path:shot,fullPage:false});
        const screen={route:'security.edit',module:'M17 System',url:'/settings/security',variant:'ordinary-unprivileged-password-confirmed',viewport:vp,state:'ordinary-user-password-confirmed',http_status:response?.status()??null,database:{name:'erp_elec_new_test',port:55462},storage_dir:'.tmp/ui-acceptance/storage',actor:{id:Number(ordinary.id),kind:'synthetic-unprivileged',roles:0,direct_grants:0,branch_scopes:0,warehouse_scopes:0},credentials_stored:false,design,checks:[],console_errors:errors,failed_requests:[],asset_requests:[],screenshot:path.relative(root,shot).replace(/\\/g,'/'),source_sha256:performance.sourceFingerprint(),build_assets:{css:currentBuildAssetsFromManifest()}};
        check(screen.checks,'security-http-200',screen.http_status===200,screen.http_status);check(screen.checks,'security-main-and-heading-visible',design.main&&!!design.heading,{main:design.main,heading:design.heading});check(screen.checks,'security-rtl-and-no-overflow',design.dir==='rtl'&&!design.overflow,{dir:design.dir,scroll_width:design.scroll_width,viewport_width:design.viewport_width});check(screen.checks,'security-inputs-have-labels',design.unlabeled_fields.length===0,design.unlabeled_fields);check(screen.checks,'security-has-no-browser-errors',errors.length===0,errors);
        evidence.screenshots.push(screen);rec.security_screenshots.push({viewport:vp.name,status:screen.http_status,screenshot:screen.screenshot,overflow:design.overflow});
        const denied=await page.goto(`${base}/products`,{waitUntil:'domcontentloaded',timeout:20000}),deniedPath=new URL(page.url()).pathname;rec.authorization_checks.push({kind:'no-role-products-page',viewport:vp.name,status:denied?.status()??null,path:deniedPath,database_port:55462,actor_id:Number(ordinary.id),actor_kind:'synthetic-unprivileged'});
        const foreign=await page.goto(`${base}${previewUrl}`,{waitUntil:'domcontentloaded',timeout:20000}),foreignPath=new URL(page.url()).pathname;rec.authorization_checks.push({kind:'foreign-operation-preview-owner',viewport:vp.name,status:foreign?.status()??null,path:foreignPath,database_port:55462,actor_id:Number(ordinary.id),preview_owner_id:Number(owner.id)});
      }catch(error){rec.security_screenshots.push({viewport:vp.name,error:String(error.message||error).slice(0,300)});}await context.close();
    }
    const productsDenied=rec.authorization_checks.filter(item=>item.kind==='no-role-products-page').every(item=>item.status===403&&item.path==='/products');check(rec.checks,'ordinary-user-cannot-open-products-without-role-or-grant',productsDenied,rec.authorization_checks.filter(item=>item.kind==='no-role-products-page'));
    const foreignDenied=rec.authorization_checks.filter(item=>item.kind==='foreign-operation-preview-owner').every(item=>item.status===404);check(rec.checks,'ordinary-user-cannot-read-another-actors-preview',foreignDenied,rec.authorization_checks.filter(item=>item.kind==='foreign-operation-preview-owner'));
    ownerContext=await browser.newContext({baseURL:base,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width:1440,height:900}});const ownerLogin=await ownerContext.newPage();await ownerLogin.goto(`${base}/login`,{waitUntil:'domcontentloaded',timeout:20000});await ownerLogin.locator('input[name="email"]').fill(owner.email);await ownerLogin.locator('input[name="password"]').fill(owner.password);
    await Promise.all([ownerLogin.waitForURL(/\/dashboard(?:\?|$)/,{waitUntil:'domcontentloaded',timeout:20000}),ownerLogin.locator('[data-test="login-button"]').click()]);check(rec.checks,'least-privilege-owner-genuine-login',new URL(ownerLogin.url()).pathname==='/dashboard',new URL(ownerLogin.url()).pathname);
    const ownerState=await ownerContext.storageState();rec.operation_owner_screenshots=[];
    for(const vp of viewports){
      const context=await browser.newContext({baseURL:base,storageState:ownerState,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width:vp.width,height:vp.height}}),page=await context.newPage(),errors=[];page.on('pageerror',error=>errors.push(String(error.message||error).slice(0,200)));page.on('console',message=>{if(message.type()==='error')errors.push(message.text().slice(0,200));});
      try{
        const assetRequests=[];page.on('response',r=>{if(/\/build\/assets\/.*\.(?:css|js)(?:\?|$)/i.test(r.url()))assetRequests.push({url:r.url(),status:r.status()});});
        const response=await page.goto(`${base}${previewUrl}`,{waitUntil:'domcontentloaded',timeout:30000});await page.locator(primary).first().waitFor({state:'visible',timeout:12000});const body=await page.locator('body').innerText(),identity=body.includes(`#${owner.id}`),preview=body.includes('معاينة التغييرات')&&body.includes('معاينة فقط'),planned=body.includes(fixtures.customer_code)&&body.includes(fixtures.customer_city_after),confirmVisible=await page.getByRole('button',{name:/تأكيد وتطبيق/}).count()>0,design=await inspect(page);
        const file=`operations.show-least-privilege-preview-${vp.name}-${performance.sourceFingerprint().slice(0,8)}.png`,shot=path.join(dir,'screenshots',file);await page.screenshot({path:shot,fullPage:false});
        const screen={route:'operations.show',module:'M17 System',url:previewUrl,variant:'least-privilege-owner-preview',viewport:vp,state:'authorized-owner-preview',http_status:response?.status()??null,database:{name:'erp_elec_new_test',port:55462},storage_dir:'.tmp/ui-acceptance/storage',actor:{id:Number(owner.id),kind:'synthetic-least-privilege-preview-owner',permissions:owner.permissions,roles:0,branch_scopes:0,warehouse_scopes:0},preview_run_id:runId,preview_executed:false,design,checks:[],console_errors:errors,failed_requests:[],asset_requests:assetRequests,screenshot:path.relative(root,shot).replace(/\\/g,'/'),source_sha256:performance.sourceFingerprint(),build_assets:{css:currentBuildAssetsFromManifest()}};
        check(screen.checks,'owner-preview-http-200',screen.http_status===200,screen.http_status);check(screen.checks,'owner-preview-identity',identity,{actor_id:owner.id});check(screen.checks,'owner-preview-remains-unexecuted',preview&&confirmVisible,{preview,confirmation_visible:confirmVisible,mutation_submitted:false});check(screen.checks,'owner-preview-displays-synthetic-customer-plan',planned,{customer_code:fixtures.customer_code});check(screen.checks,'owner-preview-rtl-no-overflow',design.dir==='rtl'&&!design.overflow,{dir:design.dir,scroll_width:design.scroll_width,viewport_width:design.viewport_width});check(screen.checks,'owner-preview-has-no-browser-errors',errors.length===0,errors);
        evidence.screenshots.push(screen);rec.operation_owner_screenshots.push({viewport:vp.name,status:screen.http_status,identity,preview,planned_customer:planned,screenshot:screen.screenshot});
        if(vp.name==='desktop-1440'){const reload=await page.reload({waitUntil:'domcontentloaded',timeout:30000});await page.locator(primary).first().waitFor({state:'visible',timeout:12000});const reloaded=await page.locator('body').innerText();rec.owner_reload={status:reload?.status()??null,identity:reloaded.includes(`#${owner.id}`),preview:reloaded.includes('معاينة التغييرات')&&reloaded.includes('معاينة فقط')};}
      }catch(error){rec.operation_owner_screenshots.push({viewport:vp.name,error:String(error.message||error).slice(0,300)});}await context.close();
    }
    check(rec.checks,'owner-preview-details-survive-reload',rec.owner_reload?.status===200&&rec.owner_reload.identity&&rec.owner_reload.preview,rec.owner_reload||null);
    const previousSecurity=evidence.screenshots.filter(row=>row.route==='security.edit'&&row.state!=='ordinary-user-password-confirmed');if(previousSecurity.length){evidence.superseded_captures||=[];for(const row of previousSecurity)evidence.superseded_captures.push({route:row.route,viewport:row.viewport?.name||null,url:row.url||null,http_status:row.http_status,screenshot:row.screenshot||null,reason:'Replaced the previous password-confirmation gate capture with the actual security page after a genuine ordinary-user password confirmation.'});evidence.screenshots=evidence.screenshots.filter(row=>!previousSecurity.includes(row));}
    const previousOperation=evidence.screenshots.filter(row=>row.route==='operations.show'&&row.state!=='authorized-owner-preview');if(previousOperation.length){evidence.superseded_captures||=[];for(const row of previousOperation)evidence.superseded_captures.push({route:row.route,viewport:row.viewport?.name||null,url:row.url||null,http_status:row.http_status,screenshot:row.screenshot||null,reason:'Replaced the stale preview URL with a valid least-privilege actor-owned synthetic preview. Preview remained unexecuted.'});evidence.screenshots=evidence.screenshots.filter(row=>!previousOperation.includes(row));}
    for(const name of ['security.edit','operations.show'])evidence.not_measured_pages=(evidence.not_measured_pages||[]).filter(item=>item.route!==name);
    const operationRoute=evidence.routes.find(route=>route.name==='operations.show');if(operationRoute){operationRoute.url=previewUrl;operationRoute.variant='least-privilege-owner-preview';operationRoute.baseline_status=200;}
  }catch(error){check(rec.checks,'least-privilege-security-and-preview-journeys',false,String(error.message||error).slice(0,450));}
  finally{if(ordinaryContext)await ordinaryContext.close().catch(()=>{});if(ownerContext)await ownerContext.close().catch(()=>{});if(server&&server.exitCode===null)server.kill();if(logFd!==null)try{fs.closeSync(logFd);}catch{}}
  fs.writeFileSync(path.join(dir,'ordinary-security-and-owner-preview-check.json'),JSON.stringify(rec,null,2)+'\n');return rec;
}
async function run(){
  const summarizeArg=process.argv.find(a=>a.startsWith('--summarize-only='))?.slice('--summarize-only='.length);
  if(summarizeArg){
    const file=path.resolve(root,summarizeArg),evidence=JSON.parse(fs.readFileSync(file,'utf8')),sourceHash=evidence.source_sha256,currentCss=evidence.build_assets?.css||null;
    const keyFor=s=>`${s.source_sha256||''}|${s.build_assets?.css||currentCss||''}|${s.route}|${s.variant||'default'}|${s.viewport?.name||''}|${s.state||'normal'}`;
    const unique=new Map();for(const row of evidence.screenshots||[]){const key=keyFor(row),previous=unique.get(key);if(!previous||row.http_status===200)unique.set(key,row);}
    const current=[...unique.values()].filter(s=>s.source_sha256===sourceHash&&(!currentCss||s.build_assets?.css===currentCss||s.asset_requests?.some(a=>a.url.includes(currentCss))));
    const routeNames=[...new Set((evidence.routes||[]).map(r=>r.name))],vpNames=(evidence.viewports||viewports).map(v=>typeof v==='string'?v:v.name);
    const covered=routeNames.filter(route=>vpNames.every(vp=>current.some(s=>s.route===route&&s.viewport?.name===vp&&s.http_status===200&&!s.auth_redirect&&s.state!=='reauthentication-required')));
    const variantManifest=JSON.parse(fs.readFileSync(visual.manifestPath,'utf8')),validCases=variantManifest.cases.filter(c=>c.baseline_status===200&&c.url),caseKey=c=>`${c.name}|${c.variant||'default'}`;
    const fullyCoveredCases=validCases.filter(c=>vpNames.every(vp=>(evidence.screenshots||[]).some(s=>s.route===c.name&&(s.variant||variantManifest.cases.find(m=>m.name===s.route&&m.url===s.url)?.variant||'default')===(c.variant||'default')&&s.viewport?.name===vp&&s.http_status===200&&!s.auth_redirect&&s.state!=='reauthentication-required')));
    const failed=[...current.flatMap(s=>(s.checks||[]).filter(c=>c.result==='fail').map(c=>({route:s.route,viewport:s.viewport?.name||null,...c}))),...(evidence.interactions||[]).flatMap(s=>(s.checks||[]).filter(c=>c.result==='fail').map(c=>({route:s.route||null,viewport:s.viewport?.name||null,...c})))];
    const summary={run_id:evidence.run_id,phase:evidence.phase,registered_scope_routes:routeNames.length,unique_screenshot_slots:unique.size,raw_screenshot_records:(evidence.screenshots||[]).length,current_source_current_asset_slots:current.length,current_source_routes:[...new Set(current.map(s=>s.route))].length,complete_current_five_viewport_routes:covered.length,missing_current_routes:routeNames.filter(route=>!covered.includes(route)),valid_manifest_route_variant_cases:validCases.length,complete_five_viewport_manifest_cases:fullyCoveredCases.length,missing_five_viewport_manifest_cases:validCases.filter(c=>!fullyCoveredCases.includes(c)).map(c=>caseKey(c)),timing_samples:(evidence.timing_samples||[]).length,interaction_records:(evidence.interactions||[]).length,superseded_screenshot_records:(evidence.superseded_captures||[]).length,superseded_interaction_records:(evidence.superseded_interactions||[]).length,failed_checks:failed.length,failures:failed,evidence:file};
    if(evidence.phase==='after'&&evidence.routes?.length&&vpNames.length===viewports.length&&evidence.page_capture_scope)summary.comparison_gallery=generateComparisonGallery(path.dirname(file),evidence);
    fs.writeFileSync(path.join(path.dirname(file),'summary.json'),JSON.stringify(summary,null,2)+'\n');
    process.stdout.write(JSON.stringify(summary)+'\n');return;
  }
  const bootstrap=visual.readOwnedBootstrap(),runId=new Date().toISOString().replace(/[:.]/g,'-');
  const phase=process.argv.find(a=>a.startsWith('--phase='))?.slice('--phase='.length)||'before';
  const allPagesMode=process.argv.includes('--all-pages'),allPageVariantsMode=process.argv.includes('--all-page-variants');
  if(process.argv.includes('--inventory-only')){
    const inventory=routeViewInventory({...bootstrap.env,APP_ENV:'testing',APP_DEBUG:'false'}),target=path.join(root,'docs','qa','design-consistency','route-view-inventory.json');fs.mkdirSync(path.dirname(target),{recursive:true});fs.writeFileSync(target,JSON.stringify(inventory,null,2)+'\n');process.stdout.write(JSON.stringify({output:target,counts:inventory.counts,modules:Object.keys(inventory.grouped_modules).length})+'\n');return;
  }
  const resumeArg=process.argv.find(a=>a.startsWith('--resume='))?.slice('--resume='.length);
  const resumeCheckpoint=resumeArg?path.resolve(root,resumeArg):null;
  const acceptSourceChange=process.argv.includes('--accept-source-change');
  const screenshotsOnly=process.argv.includes('--screenshots-only');
  let prior=null;if(resumeCheckpoint){prior=JSON.parse(fs.readFileSync(resumeCheckpoint,'utf8').replace(/^\uFEFF/,''));const scopedRun=allPagesMode||allPageVariantsMode||process.argv.some(a=>a.startsWith('--only='));if(prior.source_sha256!==performance.sourceFingerprint()&&(!acceptSourceChange||!screenshotsOnly||!scopedRun))throw new Error('Resume aborted: source changed. Resume requires screenshot-only mode and an explicit route scope.');if(prior.phase!==phase)throw new Error('Resume phase must match checkpoint phase.');}
  const effectiveRunId=prior?.run_id||runId;
  const previousSourceHash=prior?.source_sha256||null;
  const previousBuildAssets=prior?.build_assets||null;
  const sourceHash=performance.sourceFingerprint();
  const dir=resumeCheckpoint?path.dirname(resumeCheckpoint):path.join(root,'docs','qa','design-consistency',effectiveRunId,phase);fs.mkdirSync(path.join(dir,'screenshots'),{recursive:true});
  const privateDir=path.join(root,'.tmp','design-consistency',effectiveRunId);fs.mkdirSync(privateDir,{recursive:true});
  const port=await freePort(),base=`http://127.0.0.1:${port}`,env={...bootstrap.env,APP_URL:base,APP_ENV:'testing',APP_DEBUG:'false',DEBUGBAR_ENABLED:'false',PULSE_ENABLED:'false'};
  const representativeMode=process.argv.includes('--representatives');
  const allPageVariantsFlag=allPageVariantsMode;
  const allowStaticRoute=process.argv.includes('--allow-static-route');
  const probeMode=process.argv.includes('--probe-routes');
  const layoutDiagnosticMode=process.argv.includes('--layout-diagnostic');
  const inventoryPath=path.join(root,'docs','qa','design-consistency','route-view-inventory.json');
  const pageCaseManifest=JSON.parse(fs.readFileSync(visual.manifestPath,'utf8'));
  const canonicalCaseKeys=new Set((fs.existsSync(inventoryPath)?JSON.parse(fs.readFileSync(inventoryPath,'utf8')).routes:[]).filter(r=>r.fixture_status===200).map(r=>`${r.name}|${r.fixture_variant||'default'}`));
  const representedCases=new Map();
  for(const shot of prior?.screenshots||[]){
    const matched=shot.variant?null:pageCaseManifest.cases.find(c=>c.name===shot.route&&c.url===shot.url);
    const variant=shot.variant||matched?.variant||'default',key=`${shot.route}|${variant}`;
    if(shot.http_status===200&&!shot.auth_redirect&&shot.state!=='reauthentication-required'){const views=representedCases.get(key)||new Set();views.add(shot.viewport?.name);representedCases.set(key,views);}
  }
  const selectedNames=representativeMode&&fs.existsSync(inventoryPath)?(()=>{const items=[...JSON.parse(fs.readFileSync(inventoryPath,'utf8')).representatives.map(r=>({name:r.name,variant:r.variant||'default'})),...wanted.map(([name,variant])=>({name,variant}))];return[...new Map(items.map(item=>[`${item.name}|${item.variant}`,item])).values()];})():null;
  const allPageNames=allPagesMode&&fs.existsSync(inventoryPath)?(()=>{const inventory=JSON.parse(fs.readFileSync(inventoryPath,'utf8'));return [...inventory.routes.filter(r=>r.fixture_status===200).map(r=>({name:r.name,variant:r.fixture_variant||'default'})),...inventory.parameterless_uncovered.filter(r=>['projects.dashboard','password.confirm'].includes(r.name)).map(r=>({name:r.name,variant:'default'}))];})():null;
  const allPageVariantNames=allPageVariantsFlag?pageCaseManifest.cases.filter(c=>c.baseline_status===200&&c.url&&!canonicalCaseKeys.has(`${c.name}|${c.variant||'default'}`)).map(c=>({name:c.name,variant:c.variant||'default'})):null;
  const probeNames=['projects.dashboard','purchasing.reports','purchasing.returns','sales-returns.index','password.confirm'];
  const requested=process.argv.find(a=>a.startsWith('--only='))?.slice('--only='.length).split(',').filter(Boolean)|| (probeMode?probeNames:allPageVariantsFlag?allPageVariantNames:allPageNames||selectedNames);
  const timingsOnly=process.argv.includes('--timings-only');
  const interactionsOnly=process.argv.includes('--interactions-only');
  const interactionFocus=process.argv.find(a=>a.startsWith('--interaction-focus='))?.slice('--interaction-focus='.length).split(',').filter(Boolean)||null;
  const refreshAssets=process.argv.includes('--refresh-assets');
  const refreshRoutes=process.argv.find(a=>a.startsWith('--refresh-routes='))?.slice('--refresh-routes='.length).split(',').filter(Boolean)||[];
  const skipRemaining=process.argv.find(a=>a.startsWith('--skip-remaining='))?.slice('--skip-remaining='.length).split(',').filter(Boolean)||[];
  const beforeEvidencePath=process.argv.find(a=>a.startsWith('--before='))?.slice('--before='.length)||null;
  const viewportNames=process.argv.find(a=>a.startsWith('--viewports='))?.slice('--viewports='.length).split(',').filter(Boolean);
  const selectedViewports=viewportNames?viewports.filter(v=>viewportNames.includes(v.name)):viewports;
  if(viewportNames&&selectedViewports.length!==viewportNames.length)throw new Error('Unknown viewport name in --viewports.');
  const routes=routeCases(env,requested,probeMode||allPagesMode||allowStaticRoute,allPageVariantsFlag),logFd=fs.openSync(path.join(privateDir,'server.log'),'w');
  if(requested&&routes.length!==requested.length)throw new Error(`Requested route not found in existing valid fixtures: ${requested.filter(n=>!routes.some(r=>r.name===(typeof n==='string'?n:n.name))).map(n=>typeof n==='string'?n:n.name).join(',')}`);
  const bladeClearReceipt=((previousSourceHash&&previousSourceHash!==sourceHash)||refreshRoutes.length)?{database_port:bootstrap.dbPort,view_cache:clearOwnedBladeCache(env,bootstrap.dbPort),reason:previousSourceHash&&previousSourceHash!==sourceHash?'source fingerprint changed':'route-specific freshness recapture'}:null;
  const server=spawn('php',['-S',`127.0.0.1:${port}`,'-t','.',path.join(root,'tests','E2E','support','performance-router.php')],{cwd:path.join(root,'public'),env,stdio:['ignore',logFd,logFd],windowsHide:true});
  const manifest=JSON.parse(fs.readFileSync(path.join(root,'public','build','manifest.json'),'utf8'));
  const currentBuildAssets={css:manifest['resources/css/app.css']?.file?`/build/${manifest['resources/css/app.css'].file}`:null,javascript:manifest['resources/js/app.js']?.file?`/build/${manifest['resources/js/app.js'].file}`:null};
  const assetTag=`${sourceHash.slice(0,8)}-${path.basename(manifest['resources/css/app.css']?.file||'asset').replace(/\.[^.]+$/,'').replace(/^app-/,'')}`;
  const evidence=prior||{run_id:effectiveRunId,phase,database:{name:'erp_elec_new_test',port:55461},browser:'Microsoft Edge via existing Playwright',route_registry_source:'php artisan route:list --json',source_sha256:sourceHash,build_assets:{css:manifest['resources/css/app.css']?.file?`/build/${manifest['resources/css/app.css'].file}`:null,javascript:manifest['resources/js/app.js']?.file?`/build/${manifest['resources/js/app.js'].file}`:null},routes,viewports:selectedViewports,screenshots:[],timing_samples:[],interactions:[],superseded_captures:[],excluded_checks:[{name:'no-authority route denial',reason:'existing authenticated fixture is the authorized audit actor; no separate safe no-authority actor fixture has been established, so no account or privileges were created'}],conditions:{isolated_existing_database:true,reset:false,reseed:false,business_submissions:false}};
  if(bladeClearReceipt)evidence.blade_view_cache_clear=bladeClearReceipt;
  for(const shot of evidence.screenshots){const match=pageCaseManifest.cases.find(c=>c.name===shot.route&&c.url===shot.url);shot.variant||=match?.variant||'default';shot.case_key||=`${shot.route}|${shot.variant}`;}
  if(interactionFocus){
    const prefixes=[];
    if(interactionFocus.includes('responsive'))prefixes.push('responsive-','aging-mobile-card-');
    if(interactionFocus.includes('more'))prefixes.push('secondary-actions-menu-');
    if(interactionFocus.includes('product'))prefixes.push('product-');
    if(interactionFocus.includes('modal'))prefixes.push('inventory-minimum-');
    evidence.superseded_interactions||=[];
    for(const record of evidence.interactions){
      const superseded=(record.checks||[]).filter(c=>prefixes.some(prefix=>c.name.startsWith(prefix)));
      if(superseded.length)evidence.superseded_interactions.push({route:record.route||null,viewport:record.viewport||null,checks:superseded,reason:'Replaced by a bounded rerun after correcting the browser-check selector/readiness logic.'});
      record.checks=(record.checks||[]).filter(c=>!prefixes.some(prefix=>c.name.startsWith(prefix)));
    }
    evidence.interactions=evidence.interactions.filter(record=>(record.checks||[]).length>0);
  }
  if(previousSourceHash&&previousSourceHash!==sourceHash){
    evidence.screenshot_batches||=[{source_sha256:previousSourceHash,screenshot_count:evidence.screenshots.length,run_id:evidence.run_id}];
    for(const row of evidence.screenshots)row.source_sha256||=previousSourceHash;
    for(const row of evidence.timing_samples)row.source_sha256||=previousSourceHash;
    for(const row of evidence.interactions)row.source_sha256||=previousSourceHash;
    evidence.screenshot_batches.push({source_sha256:sourceHash,created_at:new Date().toISOString(),selected_routes:routes.map(r=>r.name),selected_viewports:selectedViewports.map(v=>v.name),screenshots_only:true});
    evidence.source_sha256=sourceHash;
  }
  if(allPagesMode){
    evidence.page_capture_scope={named_get_routes:routes.length,modules:[...new Set(routes.map(r=>r.module))],selection:'All named GET page routes with an existing valid isolated HTTP-200 fixture, plus the two known parameterless routes without fixture entries; 3 other uncovered routes are status-probed separately.'};
    evidence.routes=routes;
    evidence.route_capture_batches||=[];
    evidence.route_capture_batches.push({source_sha256:sourceHash,created_at:new Date().toISOString(),route_count:routes.length,viewport_names:selectedViewports.map(v=>v.name),mode:'all-pages-resume-checkpoint'});
  }
  if(allPageVariantsFlag){
    evidence.route_variant_capture_scope={valid_manifest_cases:pageCaseManifest.cases.filter(c=>c.baseline_status===200&&c.url).length,selected_missing_cases:routes.map(r=>({name:r.name,variant:r.variant||'default',url:r.url,module:r.module,guest_route:!!r.guest_route})),viewports:selectedViewports.map(v=>v.name),readiness:'Existing page-speed measurePage() route-specific readiness and Livewire stabilization are applied to each extra variant before screenshot capture.'};
    evidence.route_variant_cases||=[];
    for(const route of routes){const item={name:route.name,variant:route.variant||'default',case_key:`${route.name}|${route.variant||'default'}`,module:route.module,url:route.url,guest_route:!!route.guest_route};if(!evidence.route_variant_cases.some(c=>c.case_key===item.case_key))evidence.route_variant_cases.push(item);}
  }
  evidence.build_assets={css:manifest['resources/css/app.css']?.file?`/build/${manifest['resources/css/app.css'].file}`:null,javascript:manifest['resources/js/app.js']?.file?`/build/${manifest['resources/js/app.js'].file}`:null};
  if(previousBuildAssets?.css&&previousBuildAssets.css!==currentBuildAssets.css){evidence.asset_batches||=[{build_assets:previousBuildAssets,source_sha256:previousSourceHash,existing_screenshots:evidence.screenshots.length}];evidence.asset_batches.push({build_assets:currentBuildAssets,source_sha256:sourceHash,created_at:new Date().toISOString()});}
  evidence.viewports=selectedViewports;
  const checkpointPath=path.join(dir,'checkpoint.json');const persistCheckpoint=()=>fs.writeFileSync(checkpointPath,JSON.stringify({...evidence,checkpoint:true,source_sha256:sourceHash,checkpoint_at:new Date().toISOString()},null,2)+'\n');persistCheckpoint();
  let browser,midRunAuthRefreshUsed=false;
  try{
    await waitForServer(base);browser=await chromium.launch({headless:true,executablePath:visual.browserExecutable()});const reuseAuthorizedState=process.argv.includes('--reuse-auth-state')&&await hasValidStoredAuth(browser,base,visual.storageState);if(!(interactionsOnly&&interactionFocus?.length===1&&['operation','operation-existing','ordinary-security'].includes(interactionFocus[0]))&&!reuseAuthorizedState)await visual.ensureAuth(browser,base,env);
    const targetedCatalogRoutes=routes.some(route=>['products.index','customers.index','suppliers.index'].includes(route.name));
    if(targetedCatalogRoutes&&(process.argv.includes('--catalog-markup-preflight')||refreshRoutes.some(name=>['products.index','customers.index','suppliers.index'].includes(name))||(previousSourceHash&&previousSourceHash!==sourceHash)))await catalogMarkupPreflight(browser,base,visual.storageState,routes,dir,evidence,currentBuildAssets.css);
    if(!interactionsOnly&&!probeMode&&!layoutDiagnosticMode&&!screenshotsOnly){await measureWarmRoutes(browser,base,visual.storageState,routes,sample=>{sample.source_sha256=sourceHash;if(!evidence.timing_samples.some(x=>x.route===sample.route&&x.viewport===sample.viewport&&x.trial===sample.trial&&x.source_sha256===sourceHash))evidence.timing_samples.push(sample);persistCheckpoint();},selectedViewports,evidence.timing_samples);}
    if(!timingsOnly&&!interactionsOnly&&!probeMode&&!layoutDiagnosticMode)for(const vp of selectedViewports)for(const route of routes){
      const expectedCss=manifest['resources/css/app.css']?.file?`/build/${manifest['resources/css/app.css'].file}`:null;
      const caseKey=`${route.name}|${route.variant||'default'}`;
      const exactVariantRows=evidence.screenshots.filter(s=>s.route===route.name&&(s.variant||'default')===(route.variant||'default')&&s.viewport.name===vp.name&&s.source_sha256===sourceHash);
      const existing=refreshRoutes.includes(route.name)?null:evidence.screenshots.find(s=>s.route===route.name&&(s.variant||'default')===(route.variant||'default')&&s.viewport.name===vp.name&&s.source_sha256===sourceHash&&(!refreshAssets||(s.build_assets?.css===expectedCss||s.asset_requests?.some(a=>a.url.includes(expectedCss||'__missing_css__')))));
      if(existing&&fs.existsSync(path.resolve(root,existing.screenshot)))continue;
      if(allPageVariantsFlag&&!refreshRoutes.includes(route.name)&&exactVariantRows.some(s=>s.http_status===200&&!s.auth_redirect))continue;
      const older=evidence.screenshots.filter(s=>s.route===route.name&&(s.variant||'default')===(route.variant||'default')&&s.viewport.name===vp.name&&(refreshRoutes.includes(route.name)||s.source_sha256!==sourceHash||(refreshAssets&&!s.asset_requests?.some(a=>a.url.includes(expectedCss||'__missing_css__')))));
      if(older.length&&(acceptSourceChange||refreshAssets||refreshRoutes.includes(route.name))){evidence.superseded_captures||=[];for(const old of older)evidence.superseded_captures.push({route:old.route,viewport:old.viewport.name,source_sha256:old.source_sha256,screenshot:old.screenshot,asset_requests:old.asset_requests||[],reason:refreshRoutes.includes(route.name)?'Replaced after correcting route-specific browser readiness criteria.':refreshAssets?'Replaced for this viewport with screenshots served by the current built asset.':'Replaced for this viewport by evidence from the current source fingerprint.'});evidence.screenshots=evidence.screenshots.filter(s=>!older.includes(s));}
      if(skipRemaining.includes(route.name)){evidence.skipped_captures||=[];if(!evidence.skipped_captures.some(s=>s.route===route.name&&s.viewport===vp.name))evidence.skipped_captures.push({route:route.name,viewport:vp.name,reason:'A previous persisted attempt recorded this parameterless fixture URL as HTTP 404; remaining viewports skipped to avoid repeating the same known failure.'});persistCheckpoint();continue;}
      const captureTag=refreshRoutes.includes(route.name)?`${assetTag}-recheck`:assetTag;
      const rec=await capturePage(browser,base,visual.storageState,route,vp,dir,evidence.screenshots,captureTag);rec.source_sha256=sourceHash;rec.build_assets=currentBuildAssets;if(rec.auth_gate_redirect){evidence.not_measured_pages||=[];if(!evidence.not_measured_pages.some(r=>r.route===route.name))evidence.not_measured_pages.push({route:route.name,url:route.url,reason:rec.not_measured_reason});}persistCheckpoint();
      if(rec.auth_redirect){const oldPath=path.resolve(root,rec.screenshot),archivePath=oldPath.replace(/\.png$/i,'.auth-expired.png');if(fs.existsSync(oldPath)&&!fs.existsSync(archivePath))fs.renameSync(oldPath,archivePath);evidence.screenshots=evidence.screenshots.filter(s=>s!==rec);evidence.superseded_captures||=[];evidence.superseded_captures.push({route:route.name,viewport:vp.name,http_status:rec.http_status,landed_path:rec.landed_path,screenshot:path.relative(root,archivePath).replace(/\\/g,'/'),source_sha256:sourceHash,reason:'Authentication redirect excluded from accepted page evidence.'});persistCheckpoint();if(!midRunAuthRefreshUsed){midRunAuthRefreshUsed=true;await visual.ensureAuth(browser,base,env);const retry=await capturePage(browser,base,visual.storageState,route,vp,dir,evidence.screenshots,assetTag);retry.source_sha256=sourceHash;retry.build_assets=currentBuildAssets;if(retry.auth_redirect){evidence.screenshots=evidence.screenshots.filter(s=>s!==retry);evidence.auth_refresh_failure={route:route.name,viewport:vp.name,reason:'Single guarded auth refresh retry still landed on login.'};}persistCheckpoint();}}
    }
    if(layoutDiagnosticMode){const geometryViewports=viewportNames?selectedViewports:[viewports[2]];evidence.interactions.push(...await layoutGeometryDiagnostic(browser,base,visual.storageState,routes,dir,geometryViewports));persistCheckpoint();}
    if(probeMode){evidence.interactions.push(...await routeAvailabilityProbes(browser,base,visual.storageState,routes,dir));persistCheckpoint();}
    if(interactionsOnly||(!timingsOnly&&!requested&&!probeMode)){
      if(phase!=='after'&&!interactionsOnly){const inventory=routes.find(r=>r.name==='inventory.dashboard');if(inventory)evidence.interactions.push(await livewireFilter(browser,base,visual.storageState,inventory,dir));}
      if(!interactionFocus)evidence.interactions.push(...await navigationInteractions(browser,base,visual.storageState,dir));
      if(phase==='after'){
        const wants=name=>!interactionFocus||interactionFocus.includes(name)||(name==='operation'&&interactionFocus.includes('operation-existing'));
        if(wants('filters'))evidence.interactions.push(...await filterDisclosureChecks(browser,base,visual.storageState,routes,dir));
        if(wants('responsive'))evidence.interactions.push(...await responsiveDataChecks(browser,base,visual.storageState,routes,dir));
        if(wants('spa')){const spa=await spaNavigationCheck(browser,base,visual.storageState,routes,dir);if(spa)evidence.interactions.push(spa);}
        if(wants('primary'))evidence.interactions.push(...await primaryAppearanceChecks(browser,base,visual.storageState,routes,dir));
        if(wants('more'))evidence.interactions.push(...await moreActionChecks(browser,base,visual.storageState,routes,dir));
        if(wants('catalog')){
          const catalogNames=new Set(['products.index','customers.index','suppliers.index']);
          const priorCatalog=evidence.interactions.filter(row=>catalogNames.has(row.route)&&(row.checks||[]).some(item=>item.name.startsWith('catalog-')||item.name.startsWith('first-catalog-')));
          if(priorCatalog.length){evidence.superseded_interactions||=[];for(const row of priorCatalog)evidence.superseded_interactions.push({route:row.route,viewport:row.viewport||null,source_sha256:row.source_sha256||null,failed_checks:(row.checks||[]).filter(item=>item.result==='fail').map(item=>item.name),reason:'Superseded by the corrected catalog acceptance runner; original raw receipt remains catalog-acceptance-interactions.json.'});evidence.interactions=evidence.interactions.filter(row=>!priorCatalog.includes(row));}
          const catalogRows=await catalogAcceptanceChecks(browser,base,visual.storageState,routes,dir);evidence.interactions.push(...catalogRows);
        }
        if(wants('product'))evidence.interactions.push(...await productPaginationChecks(browser,base,visual.storageState,routes.find(r=>r.name==='products.index'),dir,env));
        if(wants('modal'))evidence.interactions.push(await inventoryModalCancelCheck(browser,base,visual.storageState,routes.find(r=>r.name==='inventory.dashboard'),dir));
        if(wants('operation')){const operationPreview=interactionFocus?.includes('operation-existing')?await reuseExistingOperationPreviewCheck(browser,base,dir,evidence):await operationPreviewCheck(browser,base,visual.storageState,evidence.routes.find(r=>r.name==='operations.show'),dir,env,evidence);evidence.interactions.push(operationPreview);}
        if(wants('ordinary-security'))evidence.interactions.push(await ordinarySecurityCheck(browser,env,dir,evidence));
      }
    }
  }catch(e){evidence.run_error=String(e.stack||e).slice(0,1200);}
  finally{if(browser)await browser.close().catch(()=>{});if(server.exitCode===null)server.kill();try{fs.closeSync(logFd);}catch{}}
  persistCheckpoint();
  fs.writeFileSync(path.join(dir,'evidence.json'),JSON.stringify(evidence,null,2)+'\n');
  const failed=[...evidence.screenshots.flatMap(p=>(p.checks||[]).filter(c=>c.result==='fail').map(c=>({route:p.route,viewport:p.viewport?.name||null,...c}))),...evidence.interactions.flatMap(p=>(p.checks||[]).filter(c=>c.result==='fail').map(c=>({route:p.route||null,viewport:p.viewport?.name||null,...c})))];
  const captureComplete=evidence.screenshots.length+(evidence.skipped_captures||[]).length>=routes.length*selectedViewports.length;
  const galleryEligible=selectedViewports.length===viewports.length&&(!requested||representativeMode||allPagesMode||!!beforeEvidencePath);
  const gallery=phase==='after'&&!timingsOnly&&!interactionsOnly&&captureComplete&&galleryEligible?generateComparisonGallery(dir,evidence,beforeEvidencePath):null;
  const summary={run_id:evidence.run_id,phase,route_count:routes.length,viewport_count:selectedViewports.length,screenshots:evidence.screenshots.length,current_source_screenshots:evidence.screenshots.filter(s=>s.source_sha256===sourceHash).length,preserved_prior_source_screenshots:evidence.screenshots.filter(s=>s.source_sha256!==sourceHash).length,timing_route_names:[...timingRouteNames],timing_viewports:[...timingViewportNames],timing_samples:evidence.timing_samples.length,interaction_sessions:evidence.interactions.length,failed_checks:failed.length,failures:failed,evidence:path.join(dir,'evidence.json'),comparison_gallery:gallery};
  fs.writeFileSync(path.join(dir,'summary.json'),JSON.stringify(summary,null,2)+'\n');process.stdout.write(JSON.stringify(summary)+'\n');
}
if(require.main===module)run().catch(e=>{console.error(e.stack||e);process.exitCode=1;});
module.exports={routeCases,inspect,timingRouteNames};
