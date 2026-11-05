import { DotLottie } from './dotlottie-web.js';

const SRC = 'https://lottie.host/69d1a4fe-c413-48fa-8be2-1fabbf4a48e1/p1z2mVMkDn.lottie';
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
  overlay.className = 'success-lottie-overlay';
  overlay.setAttribute('role', 'status');
  overlay.setAttribute('aria-label', 'Action réussie');
  const canvas = document.createElement('canvas');
  canvas.className = 'success-lottie';
  canvas.setAttribute('aria-hidden', 'true');
  overlay.appendChild(canvas);
  document.body.appendChild(overlay);
  player = new DotLottie({
    canvas,
    src: SRC,
    autoplay: true,
    loop: false,
    speed: 2.8,
    layout: { fit: 'contain', align: [0.5, 0.5] },
  });
  player.addEventListener('complete', closeOverlay);
}

function watch(el) {
  if (!(el instanceof HTMLElement) || el.dataset.lottieReady === '1') return;
  el.dataset.lottieReady = '1';
  openOverlay();
}

function scan(root) {
  const scope = root && root.querySelectorAll ? root : document;
  scope.querySelectorAll('.alert-success, .student-space-alert.is-success').forEach(watch);
}

scan(document);
new MutationObserver(function (records) {
  records.forEach(function (record) {
    record.addedNodes.forEach(function (node) {
      if (!(node instanceof HTMLElement)) return;
      if (node.matches('.alert-success, .student-space-alert.is-success')) watch(node);
      scan(node);
    });
  });
}).observe(document.body, { childList: true, subtree: true });
