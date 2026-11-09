import { DotLottie } from './dotlottie-web.js';

const SRC = 'https://lottie.host/4f66f8cf-cda6-4720-a854-acb32c49aa59/314sJIwmSz.lottie';
const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function findMountPoint(alertEl) {
  const form = alertEl.closest('form') || document.querySelector('form');
  if (!form) return null;
  const button = form.querySelector('button[type="submit"], .btn-primary, button:not([type])');
  return button || form;
}

function mountUnderButton(alertEl) {
  if (reduced || !(alertEl instanceof HTMLElement)) return;
  if (document.querySelector('.login-fail-lottie-slot')) return;

  const anchor = findMountPoint(alertEl);
  if (!anchor) return;

  const slot = document.createElement('div');
  slot.className = 'login-fail-lottie-slot';
  slot.setAttribute('role', 'status');
  slot.setAttribute('aria-label', 'Connexion échouée');

  const canvas = document.createElement('canvas');
  canvas.className = 'login-fail-lottie';
  canvas.setAttribute('aria-hidden', 'true');
  slot.appendChild(canvas);

  if (anchor.tagName === 'BUTTON' || anchor.classList.contains('btn')) {
    anchor.insertAdjacentElement('afterend', slot);
  } else {
    anchor.appendChild(slot);
  }

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
  mountUnderButton(failAlert);
}
