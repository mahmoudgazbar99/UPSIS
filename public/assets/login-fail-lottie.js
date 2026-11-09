import { DotLottie } from './dotlottie-web.js';

const SRC = 'https://lottie.host/4f66f8cf-cda6-4720-a854-acb32c49aa59/314sJIwmSz.lottie';
const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function mountBetweenAlertAndFirstField(alertEl) {
  if (reduced || !(alertEl instanceof HTMLElement)) return;
  if (document.querySelector('.login-fail-lottie-slot')) return;

  const slot = document.createElement('div');
  slot.className = 'login-fail-lottie-slot';
  slot.setAttribute('role', 'status');
  slot.setAttribute('aria-label', 'Connexion échouée');

  const canvas = document.createElement('canvas');
  canvas.className = 'login-fail-lottie';
  canvas.setAttribute('aria-hidden', 'true');
  slot.appendChild(canvas);

  // Exactly between the error banner and the first identity label/field.
  alertEl.insertAdjacentElement('afterend', slot);

  new DotLottie({
    canvas,
    src: SRC,
    autoplay: true,
    loop: true,
    speed: 1,
    layout: { fit: 'contain', align: [0.5, 0.5] },
  });
}

const failAlert = document.querySelector('[data-login-fail]');
if (failAlert) {
  mountBetweenAlertAndFirstField(failAlert);
}
