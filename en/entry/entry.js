const $ = id => document.getElementById(id);
const form = $('entry-form');
const storageKey = 'sdc2027-entry-request';
let token = '', key = '', payload, sentOnce = false;
const methods = {
  email: {type:'email', placeholder:'you@example.com', hint:'Your email address.'},
  whatsapp: {type:'tel', placeholder:'+65 8123 4567', hint:'Include + and your country code.'},
  messenger: {type:'url', placeholder:'https://www.facebook.com/your.profile', hint:'Facebook profile URL where you can receive messages.'},
  line: {type:'text', placeholder:'Your LINE ID or profile link', hint:'Your LINE ID or profile link where you can receive messages.'}
};
const labels = {category:'Race category',team:'Team name',country:'Country / region',representative:'Representative name',method:'Preferred contact method',contact:'Contact details',backup_method:'Backup contact method',backup_contact:'Backup contact',hotel:'Official hotel',steerer:'Organizer-provided steerer',notes:'Questions or requests'};
const choices = {
  category:{open:'Taiko Tenka Cup (Open)',women:'Hagoromo Tennyo Cup (Women)',senior:'Fuku Ebisu Cup (Senior B)'},
  method:{email:'E-mail',whatsapp:'WhatsApp',messenger:'FB Messenger',line:'LINE'},
  hotel:{yes:'Use the official hotel',no:'Arrange our own accommodation',undecided:'Undecided'},
  steerer:{yes:'Required',no:'Not required',undecided:'Undecided'}
};
choices.backup_method = {...choices.method,none:'Not needed'};
function readKey() {try {return sessionStorage.getItem(storageKey) || '';} catch {return key;} }
function storeKey(value) {try {value ? sessionStorage.setItem(storageKey,value) : sessionStorage.removeItem(storageKey);} catch { /* in-memory retry remains available */ } }
function contact(prefix = '') {
  const method = $(prefix+'method').value, input = $(prefix+'contact');
  input.setCustomValidity('');
  const none = method === 'none';
  if (prefix) $('backup-wrap').hidden = none;
  input.disabled = none;
  input.required = !none;
  if (none) {input.value='';return;}
  input.type = methods[method].type;
  input.placeholder = methods[method].placeholder;
  if (!prefix) $('contact-hint').textContent = methods[method].hint;
}
function details(target, application) {
  target.replaceChildren();
  for (const name of Object.keys(labels)) {
    const value = application[name];
    if (!value || value === 'none') continue;
    const dt = document.createElement('dt'), dd = document.createElement('dd');
    dt.textContent = labels[name]; dd.textContent = choices[name]?.[value] || value;
    target.append(dt,dd);
  }
}
function showReceipt(saved) {
  if (!/^SDC27-[A-F0-9]{16}$/.test(saved.receipt) || !saved.application || ![80000,100000].includes(saved.fee)) throw new Error('The saved receipt could not be read. Please retry.');
  form.hidden = true; $('review').hidden = true; $('receipt').hidden = false;
  $('receipt-number').textContent = saved.receipt;
  $('receipt-time').textContent = saved.submitted+' · Japan Standard Time';
  $('receipt-fee').textContent = `Entry fee: ¥${saved.fee.toLocaleString('en-US')} per team / per category. Please pay by the due date on your Wise invoice. Payment has not been confirmed by this receipt.`;
  details($('receipt-details'),saved.application);
  $('inquiry').href = '../contact/?subject=entry-status&receipt='+encodeURIComponent(saved.receipt);
  $('status').textContent = 'Your application has been saved. This is an application receipt; your race entry is confirmed only after payment is received.';
  $('receipt').focus(); $('receipt').scrollIntoView({block:'start'});
}
async function responseJSON(response) {
  let data;
  try {data = await response.json();} catch {throw new Error('The server response could not be read.');}
  if (!response.ok) {const error=new Error(data.message || 'Entry submission is unavailable.');error.status=response.status;throw error;}
  return data;
}
async function initialize() {
  $('review-button').disabled = true;
  const previous = readKey();
  try {
    const data = await responseJSON(await fetch('submit.php'+(previous ? '?key='+encodeURIComponent(previous) : ''),{credentials:'same-origin',cache:'no-store'}));
    if (data.saved) {showReceipt(data.saved);return;}
    token = data.token; key = data.key; storeKey(key);
    $('status').textContent = data.message;
    $('review-button').disabled = !data.open;
  } catch {
    $('status').textContent = 'Online entry is not currently available. If you already submitted, keep this browser session and reload to recover your receipt. For help, use Contact us below.';
  }
}
$('method').addEventListener('change',()=>contact());
$('backup_method').addEventListener('change',()=>contact('backup_'));
contact();contact('backup_');
form.addEventListener('input',event=>event.target.setCustomValidity?.(''));
form.addEventListener('submit',event=>{
  event.preventDefault();
  if (!token || !key || sentOnce) return;
  for (const field of ['team','country','representative','contact']) $(field).setCustomValidity($(field).value.trim() ? '' : 'Please complete this field.');
  for (const prefix of ['','backup_']) {
    const method=$(prefix+'method').value,input=$(prefix+'contact');
    if (method==='none') continue;
    if (method==='whatsapp' && !/^\+[1-9][0-9]{6,14}$/.test(input.value.replace(/[ ()-]/g,''))) input.setCustomValidity('Include + and a valid country code and phone number.');
    if (method==='line' && !input.value.trim()) input.setCustomValidity('Please enter your LINE ID or profile link.');
  }
  if (!form.reportValidity()) return;
  payload=Object.fromEntries(new FormData(form));
  details($('summary'),payload); form.hidden=true; $('review').hidden=false;
  $('review').focus();$('review').scrollIntoView({block:'start'});
});
$('edit').addEventListener('click',()=>{
  if (sentOnce) return;
  $('review').hidden=true;form.hidden=false;$('category').focus();
});
$('submit-entry').addEventListener('click',async()=>{
  const button=$('submit-entry'); if (button.disabled) return;
  sentOnce=true;button.disabled=true;$('edit').disabled=true;$('status').textContent='Saving your application…';
  try {
    const saved=await responseJSON(await fetch('submit.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({...payload,token,key})}));
    showReceipt(saved);
  } catch (error) {
    if (error.status===422) {
      sentOnce=false;$('edit').disabled=false;button.disabled=false;
      $('status').textContent=error.message+' Choose Edit details to correct your application.';
      return;
    }
    $('status').textContent=error.message+' Retry the same submission to recover its receipt. To prevent duplicate applications, details cannot be changed until this attempt is resolved.';
    button.disabled=false;button.textContent='Retry the same submission →';
    // No editing after an uncertain response: the server may already have committed the application.
  }
});
$('print-receipt').addEventListener('click',()=>window.print());
$('another-entry').addEventListener('click',()=>{
  storeKey('');token='';key='';payload=undefined;sentOnce=false;
  form.reset();contact();contact('backup_');$('receipt').hidden=true;form.hidden=false;
  $('edit').disabled=false;$('submit-entry').disabled=false;$('submit-entry').textContent='Submit entry →';
  initialize();$('category').focus();
});
initialize();
