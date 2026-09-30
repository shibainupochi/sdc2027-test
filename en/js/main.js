import { ENTRY_URL, BULLETIN_READY, BULLETIN_URL } from './config.js';
import { updates } from './updates-data.js';
import { quickHelpData } from './quick-help-data.js';

const menu = document.querySelector('.menu-toggle');
const nav = document.querySelector('#navigation');
nav.classList.add('is-enhanced');
menu.hidden = false;
function closeMenu(restoreFocus = false) {
  nav.classList.remove('is-open');
  menu.setAttribute('aria-expanded', 'false');
  menu.setAttribute('aria-label', 'Open menu');
  if (restoreFocus) menu.focus();
}
menu.addEventListener('click', () => {
  const open = nav.classList.toggle('is-open');
  menu.setAttribute('aria-expanded', String(open));
  menu.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
});
nav.addEventListener('click', event => {
  const link = event.target.closest('a');
  if (!link) return;
  closeMenu();
  if (link.hash) {
    const target = document.querySelector(link.hash);
    target?.setAttribute('tabindex', '-1');
    target?.focus({ preventScroll: true });
  }
});
document.addEventListener('keydown', event => {
  if (event.key === 'Escape' && nav.classList.contains('is-open')) closeMenu(true);
});
matchMedia('(min-width: 761px)').addEventListener('change', () => closeMenu());

function enableLink(id, url, statusId, status) {
  const link = document.getElementById(id);
  link.href = url;
  link.removeAttribute('aria-disabled');
  link.classList.remove('disabled');
  document.getElementById(statusId).textContent = status;
}
if (/^https:\/\//.test(ENTRY_URL)) {
  enableLink('entry-link', ENTRY_URL, 'entry-status', 'Continue to the external entry form.');
}
if (BULLETIN_READY) {
  enableLink('bulletin-link', BULLETIN_URL, 'bulletin-status', 'Read Bulletin 01 for the official entry conditions.');
  document.querySelector('#bulletin-link').setAttribute('download', 'SDC2027_Bulletin01.pdf');
}

const latest = [...updates].sort((a, b) => b.date.localeCompare(a.date)).slice(0, 3);
const list = document.querySelector('#updates-list');
for (const update of latest) {
  const item = document.createElement('li');
  const time = document.createElement('time');
  time.dateTime = update.date;
  time.textContent = update.date;
  const title = document.createElement(update.url ? 'a' : 'span');
  title.textContent = update.title;
  if (update.url && /^(https:\/\/|#|\.?\.?\/)/.test(update.url)) title.href = update.url;
  item.append(time, title);
  list.append(item);
}
document.querySelector('.updates').hidden = latest.length === 0;

const help = document.querySelector('#quick-help');
const launcher = document.querySelector('.help-launcher');
const content = document.querySelector('#help-content');
for (const group of quickHelpData) {
  const topic = document.createElement('details');
  topic.className = 'help-topic';
  const heading = document.createElement('summary');
  heading.textContent = group.title;
  topic.append(heading);
  for (const item of group.items) {
    const question = document.createElement('details');
    question.className = 'help-answer';
    const label = document.createElement('summary');
    label.textContent = item.question;
    const answer = document.createElement('p');
    answer.textContent = item.answer;
    question.append(label, answer);
    topic.append(question);
  }
  content.append(topic);
}
if (typeof help.showModal === 'function') {
  launcher.hidden = false;
  launcher.addEventListener('click', () => {
    closeMenu();
    help.showModal();
    document.body.classList.add('modal-open');
  });
  document.querySelector('.help-close').addEventListener('click', () => help.close());
  help.addEventListener('keydown', event => {
    if (event.key !== 'Tab') return;
    const focusable = [...help.querySelectorAll('button, a[href], summary')].filter(element => element.checkVisibility());
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });
  help.addEventListener('click', event => {
    const rect = help.getBoundingClientRect();
    if (event.target === help && (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom)) help.close();
  });
  help.addEventListener('close', () => {
    document.body.classList.remove('modal-open');
    launcher.focus();
  });
}
