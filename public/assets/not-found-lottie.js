import { DotLottie } from './dotlottie-web.js';

const SRC = 'https://lottie.host/6f3e1492-3774-4e6a-b00e-e4cdcb2669fa/493ZskMd5U.lottie';
const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const canvas = document.querySelector('[data-not-found-lottie]');

if (canvas instanceof HTMLCanvasElement && !reduced) {
  const player = new DotLottie({
    canvas,
    src: SRC,
    autoplay: true,
    loop: true,
    speed: 1,
    layout: { fit: 'contain', align: [0.5, 0.5] },
  });
  window.addEventListener('pagehide', function () {
    player.destroy();
  }, { once: true });
}
