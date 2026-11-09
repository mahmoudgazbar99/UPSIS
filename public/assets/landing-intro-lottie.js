import { DotLottie } from './dotlottie-web.js';

const SRC = new URL('./landing-heartbeat.lottie', import.meta.url).href;
const PLAYS = 2;
const FAILSAFE_MS = 12000;
const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const root = document.documentElement;
const shell = document.querySelector('[data-landing-intro]');
const canvas = document.querySelector('[data-landing-intro-lottie]');

let finished = false;
let player = null;
let playsDone = 0;
let failsafe = 0;

function finish() {
  if (finished) return;
  finished = true;
  if (failsafe) {
    window.clearTimeout(failsafe);
    failsafe = 0;
  }
  if (player) {
    try {
      player.destroy();
    } catch (error) {}
    player = null;
  }
  root.classList.add('landing-intro-reveal');
  root.classList.remove('landing-intro-pending');
  if (shell) {
    shell.classList.add('is-done');
    window.setTimeout(function () {
      shell.remove();
    }, 800);
  }
}

if (reduced || !(shell instanceof HTMLElement) || !(canvas instanceof HTMLCanvasElement)) {
  finish();
} else {
  failsafe = window.setTimeout(finish, FAILSAFE_MS);
  player = new DotLottie({
    canvas,
    src: SRC,
    autoplay: true,
    loop: false,
    speed: 0.85,
    layout: { fit: 'contain', align: [0.5, 0.5] },
  });

  player.addEventListener('complete', function () {
    playsDone += 1;
    if (playsDone >= PLAYS) {
      finish();
      return;
    }
    try {
      player.stop();
      player.play();
    } catch (error) {
      finish();
    }
  });

  player.addEventListener('loadError', finish);
  window.addEventListener('pagehide', function () {
    if (player) {
      try {
        player.destroy();
      } catch (error) {}
      player = null;
    }
  }, { once: true });
}
