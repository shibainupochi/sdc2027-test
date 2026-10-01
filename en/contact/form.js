const form = document.querySelector('#contact-form');
const method = document.querySelector('#reply-method');
const email = document.querySelector('#reply-email');
const reply = document.querySelector('#reply-contact');

function updateReplyFields() {
  const useEmail = method.value === 'email';
  document.querySelector('#email-field').hidden = !useEmail;
  document.querySelector('#reply-field').hidden = useEmail;
  email.disabled = !useEmail;
  email.required = useEmail;
  reply.disabled = useEmail;
  reply.required = !useEmail;
}
method.addEventListener('change', updateReplyFields);
updateReplyFields();
// UI only: no endpoint, storage, network request or simulated success.
form.addEventListener('submit', event => event.preventDefault());
