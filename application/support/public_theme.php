<?php
declare(strict_types=1);

/**
 * Shared public dark/light theme (landing + admissions candidate pages).
 * Uses localStorage keys: landing-theme, webapp-theme.
 */

function public_theme_boot_script(): void
{
    ?>
<script>
(function () {
  var theme = 'dark';
  try {
    var saved = localStorage.getItem('landing-theme') || localStorage.getItem('webapp-theme');
    if (saved === 'light' || saved === 'dark') theme = saved;
  } catch (error) {}
  document.documentElement.setAttribute('data-theme', theme);
})();
</script>
    <?php
}

/** Same markup/classes as landing page theme toggle. */
function public_theme_toggle_button(string $labelLight = 'Mode clair', string $labelDark = 'Mode sombre'): void
{
    ?>
<button class="icon-btn theme-toggle" type="button" data-theme-toggle data-public-theme-toggle
  aria-pressed="false"
  data-label-light="<?= e($labelLight) ?>"
  data-label-dark="<?= e($labelDark) ?>"
  aria-label="<?= e($labelLight) ?>">
  <span class="theme-sun" aria-hidden="true">
    <svg class="icon" viewBox="0 0 24 24" focusable="false"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
  </span>
  <span class="theme-moon" aria-hidden="true">
    <svg class="icon" viewBox="0 0 24 24" focusable="false"><path d="M20.5 14.2A8.5 8.5 0 1 1 9.8 3.5a6.8 6.8 0 0 0 10.7 10.7z"/></svg>
  </span>
</button>
    <?php
}

function public_theme_atmosphere_markup(): void
{
    ?>
<div class="page-atmosphere" aria-hidden="true">
  <div class="page-grid"></div>
  <div class="page-light-pan">
    <div class="page-light" data-page-light></div>
  </div>
</div>
<div class="adm-bg-squares bg-squares" aria-hidden="true"></div>
    <?php
}

function public_theme_footer_script(): void
{
    ?>
<script>
(function () {
  var root = document.documentElement;
  var button = document.querySelector('[data-public-theme-toggle], [data-theme-toggle]');
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
  function paint() {
    if (!button) return;
    var light = root.getAttribute('data-theme') === 'light';
    button.setAttribute('aria-pressed', light ? 'true' : 'false');
    button.setAttribute('aria-label', light ? (button.dataset.labelDark || 'Mode sombre') : (button.dataset.labelLight || 'Mode clair'));
  }
  paint();
  if (button) {
    button.addEventListener('click', function () {
      var next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
      root.setAttribute('data-theme', next);
      try {
        localStorage.setItem('landing-theme', next);
        localStorage.setItem('webapp-theme', next);
      } catch (error) {}
      paint();
    });
  }

  var pageLight = document.querySelector('[data-page-light]');
  if (pageLight && !reduce.matches) {
    var aimX = 68;
    var aimY = 22;
    var curX = aimX;
    var curY = aimY;
    window.addEventListener('pointermove', function (event) {
      aimX = (event.clientX / Math.max(window.innerWidth, 1)) * 100;
      aimY = (event.clientY / Math.max(window.innerHeight, 1)) * 100;
    }, { passive: true });
    (function followLight() {
      curX += (aimX - curX) * 0.08;
      curY += (aimY - curY) * 0.08;
      pageLight.style.left = curX.toFixed(2) + '%';
      pageLight.style.top = curY.toFixed(2) + '%';
      window.requestAnimationFrame(followLight);
    })();
  }
})();
</script>
    <?php
}
