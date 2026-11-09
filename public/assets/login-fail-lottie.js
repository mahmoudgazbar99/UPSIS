import { DotLottie } from './dotlottie-web.js';

const SRC = 'https://lottie.host/4f66f8cf-cda6-4720-a854-acb32c49aa59/314sJIwmSz.lottie';
const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
let overlay = null;
let player = null;

function closeOverlay() {
  if (player) {
    player.destroy();
    player = null;
  }
  if (overlay) {
    overlay.remove();
    overlay = null;
  }
}

function openOverlay() {
  if (overlay || reduced) return;
  overlay = document.createElement('div');
  overlay.className = 'login-fail-lottie-overlay';
  overlay.setAttribute('role', 'status');
  overlay.setAttribute('aria-label', 'Connexion échouée');
  overlay.title = 'Cliquer pour fermer';
  const canvas = document.createElement('canvas');
  canvas.className = 'login-fail-lottie';
  canvas.setAttribute('aria-hidden', 'true');
  overlay.appendChild(canvas);
  document.body.appendChild(overlay);
  player = new DotLottie({
    canvas,
    src: SRC,
    autoplay: true,
    loop: true,
    speed: 1,
    layout: { fit: 'contain', align: [0.5, 0.5] },
  });
  overlay.addEventListener('click', closeOverlay);
  document.addEventListener('keydown', function onKey(event) {
    if (event.key === 'Escape') {
      document.removeEventListener('keydown', onKey);
      closeOverlay();
    }
  });
}

if (document.querySelector('[data-login-fail]')) {
  openOverlay();
}
