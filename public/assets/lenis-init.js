/* Lenis smooth scroll — site-wide. Pauses for staff drawer / scroll locks. */
(function () {
  'use strict';

  if (typeof Lenis !== 'function') return;
  if (window.__upsisLenis) return;

  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduce) return;

  var lenis = new Lenis({
    autoRaf: true,
    autoToggle: true,
    anchors: true,
    allowNestedScroll: true,
    naiveDimensions: true,
    stopInertiaOnNavigate: true,
    respectReducedMotion: true
  });

  window.__upsisLenis = lenis;

  function shouldStop() {
    var body = document.body;
    var html = document.documentElement;
    if (!body) return false;
    return body.classList.contains('is-staff-drawer-open')
      || body.classList.contains('is-staff-scroll-locked')
      || html.classList.contains('is-staff-scroll-locked')
      || body.classList.contains('modal-open')
      || !!document.querySelector('.modal.show');
  }

  function syncStop() {
    if (!lenis) return;
    if (shouldStop()) {
      if (!lenis.isStopped) lenis.stop();
    } else if (lenis.isStopped) {
      lenis.start();
    }
  }

  syncStop();

  var observer = new MutationObserver(syncStop);
  observer.observe(document.documentElement, {
    attributes: true,
    attributeFilter: ['class']
  });
  if (document.body) {
    observer.observe(document.body, {
      attributes: true,
      attributeFilter: ['class']
    });
  } else {
    document.addEventListener('DOMContentLoaded', function () {
      observer.observe(document.body, {
        attributes: true,
        attributeFilter: ['class']
      });
      syncStop();
    });
  }

  document.addEventListener('shown.bs.modal', syncStop);
  document.addEventListener('hidden.bs.modal', syncStop);
})();
