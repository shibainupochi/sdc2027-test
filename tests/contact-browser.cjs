const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),assert=require('node:assert/strict');
const {spawn,spawnSync}=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'C:/Users/user/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const root=path.resolve(__dirname,'..'),php=process.env.PHP_BIN||path.join(root,'.implementation/php-runtime/php.exe'),ext=path.join(path.dirname(php),'ext');
const temp=fs.mkdtempSync(path.join(os.tmpdir(),'sdc-contact-http-')),web=path.join(temp,'web'),privateDir=path.join(temp,'private');
fs.mkdirSync(web);fs.mkdirSync(privateDir);fs.mkdirSync(path.join(privateDir,'sessions'));
for(const name of ['en','ja','assets','contact','tools','index.html','style.css'])fs.cpSync(path.join(root,name),path.join(web,name),{recursive:true});
const cfg=path.join(privateDir,'config.php');
function config(enabled=true){fs.writeFileSync(cfg,`<?php return ['enabled'=>false,'contact_enabled'=>${enabled?'true':'false'},'storage_dir'=>'${privateDir.replaceAll('\\','/')}','notification_sender'=>'local-test@example.invalid'];`);}
config();
// Local temporary fixture always fails notification; never send real mail from tests.
const submit=path.join(web,'contact/submit.php');fs.writeFileSync(submit,fs.readFileSync(submit,'utf8').replace('contact_notify($db,$result[\'row\'],$cfg)','contact_notify($db,$result[\'row\'],$cfg,fn()=>false)'));
const server=spawn(php,['-n','-d','opcache.enable=0','-d','extension_dir='+ext,'-d','extension=pdo_sqlite','-d','session.save_path='+path.join(privateDir,'sessions'),'-S','127.0.0.1:4187','-t',web],{env:{...process.env,SDC_ENTRY_CONFIG:cfg},stdio:['ignore','ignore','pipe']});
let log='';server.stderr.on('data',d=>log+=d);
const base='http://127.0.0.1:4187';
function dbRows(){const r=spawnSync(php,['-n','-d','extension_dir='+ext,'-d','extension=pdo_sqlite','-r','$db=new PDO("sqlite:".$argv[1]."/contacts.sqlite");echo json_encode($db->query("SELECT * FROM contacts")->fetchAll(PDO::FETCH_ASSOC));',privateDir],{encoding:'utf8'});assert.equal(r.status,0,r.stderr);return JSON.parse(r.stdout);}
function clearRate(){const r=spawnSync(php,['-n','-d','extension_dir='+ext,'-d','extension=pdo_sqlite','-r','$db=new PDO("sqlite:".$argv[1]."/contacts.sqlite");$db->exec("UPDATE contacts SET created=created-120");',privateDir]);assert.equal(r.status,0);}
(async()=>{let browser;try{
 for(let i=0;i<100;i++){try{await fetch(base+'/contact/');break;}catch{await new Promise(r=>setTimeout(r,100));}}
 browser=await chromium.launch({headless:true,channel:'msedge'});let ctx=await browser.newContext(),api=ctx.request;
 assert.equal((await(await api.get(base+'/en/entry/submit.php')).json()).open,false);
 const issued=await(await api.get(base+'/contact/submit.php')).json();assert(issued.open);
 const payload={subject:'entry-status',receipt:'',name:'ローカル動作確認',method:'whatsapp',contact:'+65 8123 4567',backup_method:'none',message:'合成データ / Synthetic test',website:'',token:issued.token,key:issued.key};
 assert.equal((await api.post(base+'/contact/submit.php',{data:{...payload,token:'bad'}})).status(),403);
 assert.equal((await api.post(base+'/contact/submit.php',{data:{...payload,key:'a'.repeat(48)}})).status(),403);
 assert.equal((await api.post(base+'/contact/submit.php',{data:{...payload,method:'email',contact:'invalid'}})).status(),422);
 const first=await api.post(base+'/contact/submit.php',{data:payload});assert.equal(first.status(),200);const saved=await first.json();assert(/^SDC-C-[A-F0-9]{16}$/.test(saved.reference));
 for(const response of await Promise.all(Array.from({length:5},()=>api.post(base+'/contact/submit.php',{data:payload})))){assert.equal(response.status(),200);assert.equal((await response.json()).reference,saved.reference);}
 assert.equal(dbRows().length,1);assert.equal(Number(dbRows()[0].notified),0);
 assert.equal((await api.post(base+'/contact/submit.php',{data:{...payload,message:'Changed'}})).status(),409);
 const other=await(await api.get(base+'/contact/submit.php')).json();assert.equal((await api.post(base+'/contact/submit.php',{data:{...payload,token:other.token,key:other.key}})).status(),429);
 config(false);assert.equal((await(await api.get(base+'/contact/submit.php')).json()).open,false);assert.equal((await api.post(base+'/contact/submit.php',{data:payload})).status(),200);config();
 // PHP's development server falls back to the root index for missing paths.
 for(const url of ['/contacts.sqlite','/private/config.php']){const response=await api.get(base+url);assert((await response.text()).includes('<!DOCTYPE html>'));assert(!(await response.body()).includes(Buffer.from('SQLite format 3')));}
 await ctx.close();
 const errors=[];
 for(const width of [320,375,390,430,768,1024,1440]){ctx=await browser.newContext({viewport:{width,height:900}});const page=await ctx.newPage();page.on('pageerror',e=>errors.push(e.message));
  for(const route of ['/','/contact/','/ja/','/en/']){await page.goto(base+route);await page.evaluate(()=>Promise.all([...document.images].map(i=>{i.loading='eager';return i.decode().catch(()=>{});})));assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),route+' '+width);assert(await page.evaluate(()=>[...document.images].every(i=>i.naturalWidth>0)));
   if(route==='/'){assert.equal(await page.locator('a.site-choice[href="/ja/"]').count(),1);assert.equal(await page.locator('a.site-choice[href="/en/"]').count(),1);assert.equal(await page.locator('.launch-schedule').count(),0);assert.equal(await page.locator('.contact-link').getAttribute('href'),'contact/');}
   if(route==='/en/')assert.equal(await page.locator('#entry-link').getAttribute('href'),null);
   if(route==='/contact/'&&[390,1440].includes(width)){await page.waitForFunction(()=>!document.getElementById('review-button').disabled);await page.screenshot({path:path.join(root,`.implementation/contact-form-${width}.png`),fullPage:true});}
  }await ctx.close();}
 const receipt='SDC27-1234567890ABCDEF';
 for(const [method,value]of Object.entries({email:'test@example.invalid',whatsapp:'+65 8123 4567',messenger:'https://www.facebook.com/test'})){clearRate();ctx=await browser.newContext({viewport:{width:390,height:844}});const page=await ctx.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base+'/en/contact/?subject=entry-status&receipt='+receipt);await page.waitForURL('**/contact/?subject=entry-status');await page.waitForFunction(()=>!document.getElementById('review-button').disabled);
  assert.equal(await page.locator('#entry-receipt').inputValue(),receipt);await page.locator('#entry-receipt').fill('');await page.locator('#review-button').click();assert(!(await page.locator('#review').isVisible()));
  await page.locator('#full-name').fill('合成テスト');await page.locator('[name="method"][value="'+method+'"]').check();await page.locator('#primary-contact').fill(value);await page.locator('#message').fill('Local test only / 日本語とEnglish');
  await page.locator('#backup-method').selectOption('email');assert(await page.locator('#backup-field').isVisible());await page.locator('#backup-contact').fill('backup@example.invalid');await page.locator('#backup-method').selectOption('none');assert(await page.locator('#backup-contact').isDisabled());
  await page.locator('#review-button').click();assert(await page.locator('#review').isVisible());assert((await page.locator('#summary').innerText()).includes('不明'));await page.locator('#edit').click();await page.locator('#review-button').click();
  if(method==='messenger'){await page.screenshot({path:path.join(root,'.implementation/contact-review-390.png'),fullPage:true});let lost=false;await page.route('**/contact/submit.php',async r=>{if(r.request().method()==='POST'&&!lost){lost=true;await r.fetch();await r.abort('failed');}else await r.continue();});await page.locator('#send').click();await page.waitForFunction(()=>!document.getElementById('send').disabled);assert(await page.locator('#edit').isDisabled());const count=dbRows().length;await page.locator('#send').click();await page.waitForSelector('#success:not([hidden])');assert.equal(dbRows().length,count);}else{await page.locator('#send').click();await page.waitForSelector('#success:not([hidden])');}
  assert(/^SDC-C-[A-F0-9]{16}$/.test(await page.locator('#contact-reference').innerText()));if(method==='messenger')await page.screenshot({path:path.join(root,'.implementation/contact-success-390.png'),fullPage:true});await ctx.close();
 }
 assert.deepEqual(errors,[]);console.log('PASS: contact works with entry disabled; PHP HTTP save, CSRF/issued keys, validation, 5 duplicate retries, rate limit, notification failure persistence, 3 contact methods, unknown receipt/redirect, optional contacts, review/edit/submit/success, lost-response retry; 4 pages x 7 widths, no overflow/errors. Real mail not sent.');
}finally{if(browser)await browser.close();server.kill();}})().catch(e=>{console.error(e);console.error(log.slice(-1500));server.kill();process.exit(1);});
