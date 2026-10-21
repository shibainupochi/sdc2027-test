'use strict';
const $ = id => document.getElementById(id);
const form = $('contact-form');
const query = new URLSearchParams(location.search);
function updateInquiry() {
  const show = $('subject').value === 'entry-status';
  $('receipt-field').hidden = !show;
  $('entry-receipt').disabled = !show;
}
if (query.get('subject') === 'entry-status') {
  $('subject').value = 'entry-status';
  const receipt = query.get('receipt') || '';
  if (/^SDC27-[A-F0-9]{16}$/.test(receipt)) $('entry-receipt').value = receipt;
}
$('subject').addEventListener('change', updateInquiry);
updateInquiry();
if (query.has('receipt')) {
  const cleanUrl = new URL(location.href);
  cleanUrl.searchParams.delete('receipt');
  history.replaceState(null, '', cleanUrl.pathname + cleanUrl.search + cleanUrl.hash);
}
const config = {
  email: {label:'Email address / メールアドレス', type:'email', placeholder:'you@example.com', hint:'We’ll reply to this address. / このアドレスへ返信します。', autocomplete:'email'},
  whatsapp: {label:'WhatsApp phone number / WhatsApp電話番号', type:'tel', placeholder:'+ country code and number / 国番号＋電話番号', hint:'Include your country code, e.g. +65 8123 4567. / 国番号を含めてください。', autocomplete:'tel'},
  messenger: {label:'Facebook profile URL / FacebookプロフィールURL', type:'url', placeholder:'https://www.facebook.com/your.profile', hint:'Use the profile where you can receive messages. / メッセージを受け取れるプロフィールのURLです。', autocomplete:'url'}
};
const names = {email:'E-mail', whatsapp:'WhatsApp', messenger:'FB Messenger', none:'Not needed / 不要'};
const cache = {primary:{}, backup:{}};
const active = {primary:'email', backup:'none'};
function update(prefix, method) {
  const input = $(prefix+'-contact');
  cache[prefix][active[prefix]] = input.value;
  active[prefix] = method;
  if (method === 'none') { $('backup-field').hidden = true; input.disabled = true; input.required = false; input.value = ''; return; }
  if (prefix === 'backup') $('backup-field').hidden = false;
  input.disabled = false; input.required = true;
  const c = config[method];
  $(prefix+'-label').textContent = c.label+' *';
  input.type = c.type; input.placeholder = c.placeholder; input.autocomplete = c.autocomplete;
  $(prefix+'-hint').textContent = c.hint;
  input.value = cache[prefix][method] || ''; input.setCustomValidity('');
}
document.querySelectorAll('[name="method"]').forEach(el => el.addEventListener('change', () => update('primary',el.value)));
$('backup-method').addEventListener('change', e => update('backup',e.target.value));
let credentials = null;
let pending = null;
let busy = false;
const failure = 'Unable to confirm receipt. Keep this page and retry; the same message will not be registered twice. / 受付を確認できませんでした。この画面を閉じずに再送してください。同じ内容は重複登録されません。';
async function request(options) {
  const response = await fetch('submit.php', {...options, credentials:'same-origin', cache:'no-store'});
  const data = await response.json();
  if (!response.ok) { const error = new Error(data.message || failure); error.status = response.status; throw error; }
  return data;
}
async function prepare() {
  try {
    credentials = await request({method:'GET'});
    if (!credentials.open) throw new Error(credentials.message);
    $('review-button').disabled = false;
    $('status').hidden = true;
  } catch (e) {
    $('status').textContent = e.message || 'Submission is unavailable. / 現在送信できません。';
  }
}
form.addEventListener('submit', e => {
  e.preventDefault();
  for (const id of ['full-name','primary-contact','message',...($('backup-contact').disabled ? [] : ['backup-contact'])]) {
    const el = $(id); el.setCustomValidity(el.value.trim() ? '' : 'Please complete this field. / 入力してください。');
  }
  if (!credentials || !form.reportValidity()) return;
  const data = new FormData(form);
  const values = [['Topic / 件名', $('subject').selectedOptions[0].textContent], ['Name / 氏名', data.get('name')], ['Preferred reply / 返信先', names[data.get('method')]+' · '+data.get('contact')]];
  if (data.get('backup_method') !== 'none') values.push(['Backup reply / 予備連絡先', names[data.get('backup_method')]+' · '+data.get('backup_contact')]);
  if (data.get('subject') === 'entry-status') values.push(['Entry receipt / エントリー受付番号', data.get('receipt') || 'Not known / 不明']);
  values.push(['Message / お問い合わせ内容',data.get('message')]);
  $('summary').replaceChildren();
  for (const [key,value] of values) { const dt = document.createElement('dt'); const dd = document.createElement('dd'); dt.textContent = key; dd.textContent = value; $('summary').append(dt,dd); }
  pending = Object.fromEntries(data);
  form.hidden = true; $('review').hidden = false; $('review').focus();
});
form.addEventListener('input', e => { if (e.target.setCustomValidity) e.target.setCustomValidity(''); });
$('edit').addEventListener('click', () => {
  if (busy) return;
  $('review').hidden = true; form.hidden = false; $('subject').focus();
});
$('send').addEventListener('click', async () => {
  if (busy || !pending || !credentials) return;
  busy = true; $('send').disabled = true; $('edit').disabled = true;
  $('send-status').hidden = false;
  $('send-status').textContent = 'Sending… / 送信しています…';
  try {
    const saved = await request({method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({...pending,token:credentials.token,key:credentials.key})});
    $('contact-reference').textContent = saved.reference;
    $('review').hidden = true; $('success').hidden = false; $('success').focus();
  } catch (e) {
    $('send-status').textContent = e.message || failure;
    // After an uncertain POST, keep the payload and key unchanged for safe retry.
    $('edit').disabled = ![400,403,413,415,422,429].includes(e.status);
  } finally { busy = false; $('send').disabled = false; }
});
prepare();
