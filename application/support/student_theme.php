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
<button type="button" class="portal-theme-toggle theme-toggle lw-theme-toggle" data-student-theme-toggle aria-pressed="false" aria-label="Activer le mode clair">
  <span class="lw-theme-sky" aria-hidden="true">
    <span class="lw-theme-cloud"></span>
    <span class="lw-theme-stars"><span></span><span></span><span></span></span>
  </span>
  <span class="lw-theme-thumb" aria-hidden="true">
    <svg class="lw-theme-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 3v2M12 19v2M5 12H3M21 12h-2M6.2 6.2l1.4 1.4M16.4 16.4l1.4 1.4M17.8 6.2l-1.4 1.4M7.6 16.4 6.2 17.8"/></svg>
    <svg class="lw-theme-moon" viewBox="0 0 24 24"><path d="M16.6 13.2A6.6 6.6 0 1 1 10.8 5.4 5.2 5.2 0 0 0 16.6 13.2z"/></svg>
  </span>
</button>
<script>
(function () {
  var owner = {$owner};
  var skinKey = 'student-skin:' + owner;
  var themeKey = 'student-theme:' + owner;
  var button = document.currentScript.previousElementSibling;
  if (!button) return;
  var root = document.documentElement;
  var colors = { dark: '#090b0f', light: '#f4f7f5' };
  var wipe = document.createElement('div');
  wipe.className = 'theme-wipe';
  wipe.hidden = true;
  var blob = document.createElement('div');
  blob.className = 'theme-wipe-blob';
  wipe.appendChild(blob);
  document.body.appendChild(wipe);
  var busy = false;
  var wipeAnim = null;
  var styleReady = false;

  function ensureVtStyle() {
    if (styleReady || document.getElementById('student-theme-vt')) return;
    var style = document.createElement('style');
    style.id = 'student-theme-vt';
    style.textContent = '::view-transition-old(root),::view-transition-new(root){animation:none;mix-blend-mode:normal;}';
    document.head.appendChild(style);
    styleReady = true;
  }

  function lightOn() {
    return root.getAttribute('data-theme') === 'light';
  }
  function paintButton() {
    var light = lightOn();
    button.setAttribute('aria-pressed', light ? 'true' : 'false');
    button.setAttribute('aria-label', light ? 'Activer le mode sombre' : 'Activer le mode clair');
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
    wipe.style.background = '';
    blob.style.background = '';
    blob.style.boxShadow = '';
    blob.style.width = '';
    blob.style.height = '';
    blob.style.transform = '';
    blob.style.opacity = '';
  }
  function wipeFallback(next, previous) {
    var rect = button.getBoundingClientRect();
    var x = rect.left + rect.width / 2;
    var y = rect.top + rect.height / 2;
    var reach = Math.hypot(Math.max(x, window.innerWidth - x), Math.max(y, window.innerHeight - y)) + 8;
    var size = Math.ceil(reach * 2);
    var startScale = Math.min(0.18, Math.max(rect.width, rect.height) / size);
    var duration = 620;
    var from = 'translate3d(-50%, -50%, 0) scale(' + startScale + ')';
    var to = 'translate3d(-50%, -50%, 0) scale(1)';
    var spread = Math.ceil(reach * (1 / startScale - 1) + 32);
    wipe.style.background = colors[previous];
    wipe.hidden = false;
    blob.style.opacity = '0';
    blob.style.background = 'transparent';
    blob.style.border = '0';
    blob.style.boxShadow = '0 0 0 1px ' + colors[previous] + ', 0 0 0 ' + spread + 'px ' + colors[previous];
    blob.style.width = size + 'px';
    blob.style.height = size + 'px';
    blob.style.left = x + 'px';
    blob.style.top = y + 'px';
    blob.style.transform = from;
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        apply(next);
        wipe.style.background = '';
        blob.style.opacity = '1';
        if (typeof blob.animate === 'function') {
          wipeAnim = blob.animate(
            [{ transform: from }, { transform: to }],
            { duration: duration, easing: 'ease-in-out', fill: 'forwards' }
          );
          wipeAnim.onfinish = finishWipe;
          wipeAnim.oncancel = finishWipe;
          return;
        }
        var start = performance.now();
        function frame(now) {
          var progress = Math.min(1, (now - start) / duration);
          var eased = 1 - Math.pow(1 - progress, 1.65);
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
    });
  }
  async function circleSpread(next) {
    ensureVtStyle();
    var rect = button.getBoundingClientRect();
    var x = rect.left + rect.width / 2;
    var y = rect.top + rect.height / 2;
    var maxRadius = Math.hypot(
      Math.max(x, window.innerWidth - x),
      Math.max(y, window.innerHeight - y)
    );
    var transition = document.startViewTransition(function () {
      apply(next);
    });
    await transition.ready;
    document.documentElement.animate(
      {
        clipPath: [
          'circle(0px at ' + x + 'px ' + y + 'px)',
          'circle(' + maxRadius + 'px at ' + x + 'px ' + y + 'px)'
        ]
      },
      {
        duration: 620,
        easing: 'ease-in-out',
        pseudoElement: '::view-transition-new(root)'
      }
    );
    await transition.finished;
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
    busy = true;
    if (typeof document.startViewTransition === 'function') {
      circleSpread(next).then(function () { busy = false; }).catch(function () {
        busy = false;
        wipeFallback(next, previous);
      });
      return;
    }
    wipeFallback(next, previous);
  });
})();
</script>
HTML;
}
