/* One press, then one content reveal. Never restart an entrance that is already playing. */
(function () {
  'use strict';

  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduce) return;
  document.documentElement.classList.add('motion-on');

  var played = false;

  function stage() {
    if (played || document.body.classList.contains('student-space')) return;
    played = true;
    var node = document.querySelector(
      '.student-gate-card, .login-card, .macos-window-body, main.app-sheet'
    );
    if (!node || node.classList.contains('motion-stage')) return;
    node.classList.add('motion-stage');
  }

  function press(target) {
    var control = target && target.closest
      ? target.closest('a, button, summary, .macos-dock-item, .dashboard-menu-item, .student-space-cards > button')
      : null;
    if (!control || control.disabled || control.classList.contains('motion-stage')) return;
    control.classList.remove('motion-press');
    void control.offsetWidth;
    control.classList.add('motion-press');
  }

  function start() {
    stage();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }

  document.addEventListener('click', function (event) {
    press(event.target);
  }, true);
})();
