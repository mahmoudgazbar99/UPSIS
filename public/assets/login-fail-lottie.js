import { DotLottie } from './dotlottie-web.js';

const SRC = 'https://lottie.host/4f66f8cf-cda6-4720-a854-acb32c49aa59/314sJIwmSz.lottie';
const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function findFirstUserField() {
  const form = document.querySelector('form');
  if (!form) return null;
  const candidates = form.querySelectorAll(
    '#cin:not([disabled]), #username:not([disabled]), input[name="cin"]:not([disabled]), input[name="username"]:not([disabled]), input:not([type="hidden"]):not([type="password"]):not([disabled])'
  );
  for (let i = 0; i < candidates.length; i += 1) {
    const field = candidates[i];
    if (field instanceof HTMLElement && !field.closest('[hidden]')) {
      return field;
    }
  }
  return form.querySelector('select#role, select[name="role"]');
}

function mountOnFirstUserField() {
  if (reduced) return;
  if (document.querySelector('.login-fail-lottie-slot')) return;

  const field = findFirstUserField();
  if (!(field instanceof HTMLElement)) return;

  const slot = document.createElement('div');
  slot.className = 'login-fail-lottie-slot';
  slot.setAttribute('role', 'status');
  slot.setAttribute('aria-label', 'Connexion échouée');

  const canvas = document.createElement('canvas');
  canvas.className = 'login-fail-lottie';
  canvas.setAttribute('aria-hidden', 'true');
  slot.appendChild(canvas);

  // Place directly under the first visible identity field (role / CIN / username).
  field.insertAdjacentElement('afterend', slot);

  new DotLottie({
    canvas,
    src: SRC,
    autoplay: true,
    loop: true,
    speed: 1,
    layout: { fit: 'contain', align: [0.5, 0.5] },
  });
}

if (document.querySelector('[data-login-fail]')) {
  mountOnFirstUserField();
}
