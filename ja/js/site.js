import { VIDEO_URLS } from './config.js';

const menu = document.querySelector('.menu');
const nav = document.querySelector('#navigation');
if (menu && nav) {
  menu.hidden = false;
  nav.classList.add('enhanced');
  const close = (restore = false) => {
    nav.classList.remove('open');
    menu.setAttribute('aria-expanded', 'false');
    if (restore) menu.focus();
  };
  menu.addEventListener('click', () => {
    const open = nav.classList.toggle('open');
    menu.setAttribute('aria-expanded', String(open));
  });
  nav.addEventListener('click', event => {
    if (event.target.closest('a')) close();
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && nav.classList.contains('open')) close(true);
  });
  document.addEventListener('click', event => {
    if (!event.target.closest('.header')) close();
  });
  matchMedia('(min-width:761px)').addEventListener('change', () => close());
}

for (const button of document.querySelectorAll('[data-video]')) {
  const value = VIDEO_URLS[button.dataset.video];
  if (!value) continue;
  let url;
  try { url = new URL(value); } catch { continue; }
  if (url.protocol !== 'https:') continue;
  const link = document.createElement('a');
  link.href = url.href;
  for (const name of ['class', 'aria-label', 'title']) {
    if (button.hasAttribute(name)) link.setAttribute(name, button.getAttribute(name));
  }
  link.append(...button.childNodes);
  button.replaceWith(link);
}
