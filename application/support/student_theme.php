<?php
declare(strict_types=1);

function student_theme_head(): void
{
    $owner = json_encode(app_theme_owner(), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    echo <<<HTML
<script>
(function () {
  var owner = {$owner};
  var skinKey = 'student-skin:' + owner;
  var themeKey = 'student-theme:' + owner;
  var skin = 'nursing';
  var theme = 'dark';
  try {
    var saved = localStorage.getItem(skinKey);
    if (saved === 'classic' || saved === 'nursing') skin = saved;
    var storedTheme = localStorage.getItem(themeKey);
    theme = storedTheme === 'light' || storedTheme === 'dark'
      ? storedTheme
      : (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
  } catch (error) {}
  document.documentElement.dataset.themeOwner = owner;
  document.documentElement.setAttribute('data-student-skin', skin);
  document.documentElement.setAttribute('data-theme', theme);
})();
</script>
HTML;
}

function student_theme_select(): void
{
    $owner = json_encode(app_theme_owner(), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    echo <<<HTML
<button type="button" class="portal-theme-toggle" data-student-theme-toggle aria-pressed="false" aria-label="Activer le mode clair">
  <svg data-theme-icon="sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2.5v2.2M12 19.3v2.2M2.5 12h2.2M19.3 12h2.2M5.1 5.1l1.6 1.6M17.3 17.3l1.6 1.6M18.9 5.1l-1.6 1.6M6.7 17.3l-1.6 1.6"/></svg>
  <svg data-theme-icon="moon" viewBox="0 0 24 24" aria-hidden="true" hidden><path d="M16 3.2A8.2 8.2 0 1 0 20.8 16 6.4 6.4 0 0 1 16 3.2z"/></svg>
</button>
<script>
(function () {
  var owner = {$owner};
  var skinKey = 'student-skin:' + owner;
  var themeKey = 'student-theme:' + owner;
  var button = document.currentScript.previousElementSibling;
  if (!button) return;
  var root = document.documentElement;
  var colors = { dark: '#121212', light: '#d9d9e1' };
  var wipe = document.createElement('div');
  wipe.className = 'theme-wipe';
  wipe.hidden = true;
  var blob = document.createElement('div');
  blob.className = 'theme-wipe-blob';
  wipe.appendChild(blob);
  document.body.appendChild(wipe);
  var busy = false;
  var wipeAnim = null;
  function lightOn() {
    return root.getAttribute('data-theme') === 'light';
  }
  function paintButton() {
    var light = lightOn();
    button.setAttribute('aria-pressed', light ? 'true' : 'false');
    button.setAttribute('aria-label', light ? 'Activer le mode sombre' : 'Activer le mode clair');
    var sun = button.querySelector('[data-theme-icon="sun"]');
    var moon = button.querySelector('[data-theme-icon="moon"]');
    if (sun) sun.hidden = light;
    if (moon) moon.hidden = !light;
  }
  function apply(theme) {
    root.setAttribute('data-student-skin', 'nursing');
    root.setAttribute('data-theme', theme);
    try {
      localStorage.setItem(skinKey, 'nursing');
      localStorage.setItem(themeKey, theme);
    } catch (error) {}
    paintButton();
  }
  function finishWipe() {
    if (!busy) return;
    busy = false;
    var anim = wipeAnim;
    wipeAnim = null;
    if (anim) {
      try { anim.cancel(); } catch (error) {}
    }
    wipe.hidden = true;
    blob.style.background = '';
    blob.style.boxShadow = '';
    blob.style.width = '';
    blob.style.height = '';
    blob.style.transform = '';
  }
  paintButton();
  button.addEventListener('click', function (event) {
    event.preventDefault();
    event.stopPropagation();
    if (busy) return;
    var next = lightOn() ? 'dark' : 'light';
    var previous = next === 'light' ? 'dark' : 'light';
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      apply(next);
      return;
    }
    var rect = button.getBoundingClientRect();
    var x = rect.left + rect.width / 2;
    var y = rect.top + rect.height / 2;
    var reach = Math.hypot(Math.max(x, window.innerWidth - x), Math.max(y, window.innerHeight - y)) + 8;
    var size = Math.ceil(reach * 2);
    var startScale = Math.min(0.18, Math.max(rect.width, rect.height) / size);
    var duration = 780;
    var from = 'translate3d(-50%, -50%, 0) scale(' + startScale + ')';
    var to = 'translate3d(-50%, -50%, 0) scale(1)';
    apply(next);
    busy = true;
    var spread = Math.ceil(reach * (1 / startScale - 1) + 32);
    blob.style.background = 'transparent';
    blob.style.border = '0';
    blob.style.boxShadow = '0 0 0 1px ' + colors[previous] + ', 0 0 0 ' + spread + 'px ' + colors[previous];
    blob.style.width = size + 'px';
    blob.style.height = size + 'px';
    blob.style.left = x + 'px';
    blob.style.top = y + 'px';
    blob.style.transform = from;
    wipe.hidden = false;
    if (typeof blob.animate === 'function') {
      wipeAnim = blob.animate(
        [{ transform: from }, { transform: to }],
        { duration: duration, easing: 'cubic-bezier(0.33, 0, 0.2, 1)', fill: 'forwards' }
      );
      wipeAnim.onfinish = finishWipe;
      wipeAnim.oncancel = finishWipe;
      return;
    }
    var start = performance.now();
    function frame(now) {
      var progress = Math.min(1, (now - start) / duration);
      var eased = 1 - Math.pow(1 - progress, 2.4);
      var scale = startScale + (1 - startScale) * eased;
      blob.style.transform = 'translate3d(-50%, -50%, 0) scale(' + scale + ')';
      if (progress < 1) {
        requestAnimationFrame(frame);
        return;
      }
      finishWipe();
    }
    requestAnimationFrame(frame);
  });
})();
</script>
HTML;
}
