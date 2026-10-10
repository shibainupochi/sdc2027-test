// PHP/SQLite HTTP integration plus responsive browser tests, using synthetic data only.
const fs=require('node:fs');const path=require('node:path');const os=require('node:os');
const {spawn,spawnSync}=require('node:child_process');const assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/user/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const root=path.resolve(__dirname,'..');
const php=process.env.PHP_BIN || path.join(root,'.implementation/php-runtime/php.exe');
const ext=path.join(path.dirname(php),'ext');
const temp=fs.mkdtempSync(path.join(os.tmpdir(),'sdc-entry-http-'));
const web=path.join(temp,'web'),privateDir=path.join(temp,'private'),configFile=path.join(privateDir,'config.php');
fs.mkdirSync(web);fs.mkdirSync(privateDir);fs.mkdirSync(path.join(privateDir,'sessions'));
for(const name of ['en','ja','assets'])fs.cpSync(path.join(root,name),path.join(web,name),{recursive:true});
fs.cpSync(path.join(root,'tools'),path.join(web,'tools'),{recursive:true});
const q=value=>"'"+value.replaceAll('\\','/').replaceAll("'","\\'")+"'";
function config(time='2027-01-31 23:59:59',enabled=true,storage=privateDir){
 fs.writeFileSync(configFile,`<?php return ['enabled'=>${enabled?'true':'false'},'storage_dir'=>${q(storage)},'notification_sender'=>'','test_time'=>${q(time)}];`);
}
config();
// Only this temporary deployment fixture has an injected clock. Production source has no test clock switch.
const fixture=path.join(web,'en/entry/submit.php');
fs.writeFileSync(fixture,fs.readFileSync(fixture,'utf8').replace('$now = entry_clock();',"$now = new DateTimeImmutable($cfg['test_time'], new DateTimeZone('Asia/Tokyo'));"));
const args=['-n','-d','opcache.enable=0','-d','extension_dir='+ext,'-d','extension=pdo_sqlite','-d','session.save_path='+path.join(privateDir,'sessions'),'-S','127.0.0.1:4185','-t',web];
const server=spawn(php,args,{env:{...process.env,SDC_ENTRY_CONFIG:configFile},stdio:['ignore','ignore','pipe']});
let stderr='';server.stderr.on('data',d=>stderr+=d.toString());
const base='http://127.0.0.1:4185';
function rows(){
 const code='$db=new PDO("sqlite:".$argv[1]."/entries.sqlite");echo json_encode($db->query("SELECT * FROM entries ORDER BY submitted,receipt")->fetchAll(PDO::FETCH_ASSOC));';
 const r=spawnSync(php,['-n','-d','extension_dir='+ext,'-d','extension=pdo_sqlite','-r',code,privateDir],{encoding:'utf8'});
 assert.equal(r.status,0,r.stderr);return JSON.parse(r.stdout);
}
const body={category:'open',team:'Synthetic Team',country:'Japan',representative:'Test Person',method:'line',contact:'test-line',backup_method:'none',hotel:'undecided',steerer:'yes',notes:'',ack:'on'};
(async()=>{
 let browser;
 try {
  for(let i=0;i<100;i++){try{await fetch(base+'/en/entry/');break;}catch{await new Promise(r=>setTimeout(r,100));}}
  browser=await chromium.launch({headless:true,channel:'msedge'});
  let ctx=await browser.newContext();
  const api=ctx.request;
  let r=await api.get(base+'/en/entry/submit.php');const issued=await r.json();assert(issued.open);
  assert.equal((await api.post(base+'/en/entry/submit.php',{data:{...body,token:'bad',key:issued.key}})).status(),403);
  assert.equal((await api.post(base+'/en/entry/submit.php',{data:{...body,token:issued.token,key:'b'.repeat(48)}})).status(),403);
  assert.equal((await api.post(base+'/en/entry/submit.php',{data:{...body,token:issued.token,key:issued.key,method:'email',contact:'invalid'}})).status(),422);
  r=await api.post(base+'/en/entry/submit.php',{data:{...body,token:issued.token,key:issued.key}});assert.equal(r.status(),200);const first=await r.json();
  assert.equal(first.fee,80000);assert(first.submitted.endsWith('+09:00'));assert.equal(first.application.contact,'test-line');
  config('2027-02-01 00:00:00');
  const retries=await Promise.all(Array.from({length:5},()=>api.post(base+'/en/entry/submit.php',{data:{...body,token:issued.token,key:issued.key}})));
  for(const response of retries){assert.equal(response.status(),200);assert.equal((await response.json()).receipt,first.receipt);}
  assert.equal(rows().length,1);assert.equal(Number(rows()[0].notified),0); // empty sender => failed notification, saved data remains
  assert.equal((await api.post(base+'/en/entry/submit.php',{data:{...body,team:'Changed',token:issued.token,key:issued.key}})).status(),409);
  const foreign=await browser.newContext();assert.equal((await foreign.request.get(base+'/en/entry/submit.php?key='+issued.key)).status(),403);await foreign.close();
  config('2027-03-01 00:00:00',false);
  assert.equal((await api.get(base+'/en/entry/submit.php?key='+issued.key)).status(),200);
  assert.equal((await api.post(base+'/en/entry/submit.php',{data:{...body,token:issued.token,key:issued.key}})).status(),200);
  const closed=await api.get(base+'/en/entry/submit.php');assert.equal((await closed.json()).open,false);
  for(const url of ['/private/config.php','/private/entries.sqlite','/entries.sqlite','/tools/export-entries.php','/tools/retry-entry-notifications.php'])assert.equal((await api.get(base+url)).status(),404,url);
  await ctx.close();
  config('2027-02-01 00:00:00');
  const errors=[];
  for(const width of [320,375,390,430,768,1024,1440]){
   ctx=await browser.newContext({viewport:{width,height:900}});const page=await ctx.newPage();page.on('pageerror',e=>errors.push(e.message));
   for(const route of ['/en/entry/','/en/contact/?subject=entry-status&receipt='+first.receipt,'/ja/','/en/']){
    await page.goto(base+route);await page.evaluate(()=>Promise.all([...document.images].map(i=>{i.loading='eager';return i.decode().catch(()=>{});})));
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),route+' '+width);
    if(route==='/en/entry/'){await page.waitForFunction(()=>!document.getElementById('review-button').disabled);}
    if(route.startsWith('/en/contact/')){assert.equal(await page.locator('#subject').inputValue(),'Entry Status Inquiry');assert.equal(await page.locator('#entry-receipt').inputValue(),first.receipt);assert(await page.locator('#receipt-field').isVisible());}
    if([390,1440].includes(width)&&route==='/en/entry/')await page.screenshot({path:path.join(root,`.implementation/entry-form-${width}.png`),fullPage:true});
   }
   await ctx.close();
  }
  for(const [method,value] of Object.entries({email:'test@example.invalid',whatsapp:'+65 8123 4567',messenger:'https://www.facebook.com/test',line:'test-line'})){
   ctx=await browser.newContext({viewport:{width:390,height:844}});const page=await ctx.newPage();page.on('pageerror',e=>errors.push(e.message));
   await page.goto(base+'/en/entry/');await page.waitForFunction(()=>!document.getElementById('review-button').disabled);
   await page.locator('#review-button').click();assert(!(await page.locator('#review').isVisible()));
   await page.locator('#category').selectOption('women');await page.locator('#team').fill('テストチーム');await page.locator('#country').fill('Japan');await page.locator('#representative').fill('Test Person');
   await page.locator('#method').selectOption(method);await page.locator('#contact').fill(value);
   await page.locator('#hotel').selectOption('undecided');await page.locator('#steerer').selectOption('no');
   await page.locator('#backup_method').selectOption('line');assert(await page.locator('#backup-wrap').isVisible());await page.locator('#backup_contact').fill('backup-line');
   await page.locator('#backup_method').selectOption('none');assert(await page.locator('#backup_contact').isDisabled());
   await page.locator('[name="ack"]').check();await page.locator('#review-button').click();assert(await page.locator('#review').isVisible());
   await page.locator('#edit').click();assert(await page.locator('#entry-form').isVisible());await page.locator('#review-button').click();
   if(method==='line'){
    await page.screenshot({path:path.join(root,'.implementation/entry-review-390.png'),fullPage:true});
    let lost=false;
    await page.route('**/entry/submit.php',async route=>{if(route.request().method()==='POST'&&!lost){lost=true;await route.fetch();await route.abort('failed');}else await route.continue();});
    await page.locator('#submit-entry').click();await page.waitForFunction(()=>!document.getElementById('submit-entry').disabled);
    assert(await page.locator('#edit').isDisabled());const before=rows().length;
    await page.locator('#submit-entry').click();await page.waitForSelector('#receipt:not([hidden])');assert.equal(rows().length,before);
   }else {await page.locator('#submit-entry').click();await page.waitForSelector('#receipt:not([hidden])');}
   const receipt=await page.locator('#receipt-number').innerText();assert(/^SDC27-[A-F0-9]{16}$/.test(receipt));assert((await page.locator('#receipt-fee').innerText()).includes('100,000'));
   if(method==='line')await page.screenshot({path:path.join(root,'.implementation/entry-receipt-390.png'),fullPage:true});
   await page.reload();await page.waitForSelector('#receipt:not([hidden])');assert.equal(await page.locator('#receipt-number').innerText(),receipt);
   await page.locator('#inquiry').click();assert.equal(await page.locator('#entry-receipt').inputValue(),receipt);assert.equal(await page.locator('#subject').inputValue(),'Entry Status Inquiry');
   await page.locator('#entry-receipt').fill('');await page.locator('#full-name').fill('Test');await page.locator('#primary-contact').fill('test@example.invalid');await page.locator('#message').fill('Synthetic inquiry');await page.locator('#review-button').click();assert(await page.locator('#review').isVisible());assert((await page.locator('#summary').innerText()).includes('Not known'));
   assert(await page.getByRole('button',{name:'Send message — coming soon'}).isDisabled());
   await ctx.close();
  }
  // Missing/non-private configuration and disabled/closed reception must never show success.
  config('2027-02-01 00:00:00',true,path.join(web,'en'));
  ctx=await browser.newContext();assert.equal((await ctx.request.get(base+'/en/entry/submit.php')).status(),503);await ctx.close();
  config('2027-02-01 00:00:00',false);
  ctx=await browser.newContext();assert.equal((await (await ctx.request.get(base+'/en/entry/submit.php')).json()).open,false);await ctx.close();
  assert.deepEqual(errors,[]);
  console.log('PASS: PHP HTTP CSRF/key/owner protection, 5 duplicate retries, changed-payload conflict, saved receipt after close, notification failure persistence, private URLs, 4 contact methods, optional fields/errors/review/edit, receipt recovery after lost response and reload, inquiry prefill/unknown number, disabled contact sending; 4 pages x 7 widths without overflow/errors. Real mail delivery not tested.');
  console.log('Synthetic fixture (outside repo/public root): '+temp);
 }finally{if(browser)await browser.close();server.kill();}
})().catch(e=>{console.error(e);console.error(stderr.slice(-2000));server.kill();process.exit(1);});
