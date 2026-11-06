(function (root) {
  var colors = { dark: '#090b0f', light: '#f4f7f5' };
  var wipe = null;
  var blob = null;
  var busy = false;
  var anim = null;
  var raf = 0;
  var vtReady = false;

  function ensureVtStyle() {
    if (vtReady || document.getElementById('lw-theme-vt')) return;
    var style = document.createElement('style');
    style.id = 'lw-theme-vt';
    style.textContent = '::view-transition-old(root),::view-transition-new(root){animation:none;mix-blend-mode:normal;}';
    document.head.appendChild(style);
    vtReady = true;
  }

  function ensure() {
    if (wipe) return;
    wipe = document.createElement('div');
    wipe.className = 'app-theme-wipe';
    wipe.hidden = true;
    blob = document.createElement('div');
    blob.className = 'app-theme-wipe-blob';
    wipe.appendChild(blob);
    document.body.appendChild(wipe);
  }

  function finish() {
    busy = false;
    if (raf) {
      cancelAnimationFrame(raf);
      raf = 0;
    }
    if (anim) {
      try { anim.cancel(); } catch (error) {}
      anim = null;
    }
    if (!wipe || !blob) return;
    wipe.hidden = true;
    wipe.style.background = '';
    blob.style.background = '';
    blob.style.boxShadow = '';
    blob.style.width = '';
    blob.style.height = '';
    blob.style.transform = '';
    blob.style.opacity = '';
  }

  function easeInLate(t) {
    if (t <= 0) return 0;
    if (t >= 1) return 1;
    return 1 - Math.pow(1 - t, 1.65);
  }

  function scaleAt(startScale, t) {
    return startScale + (1 - startScale) * easeInLate(t);
  }

  function wipeFallback(toggle, previousTheme, applyNext) {
    ensure();
    var rect = toggle.getBoundingClientRect();
    var x = rect.left + rect.width / 2;
    var y = rect.top + rect.height / 2;
    var reach = Math.hypot(Math.max(x, window.innerWidth - x), Math.max(y, window.innerHeight - y)) + 24;
    var size = Math.ceil(reach * 2);
    var startPx = Math.max(rect.width, rect.height, 28);
    var startScale = Math.min(0.08, startPx / size);
    var spread = Math.ceil(reach * (1 / startScale - 1) + 64);
    var from = 'translate3d(-50%, -50%, 0) scale(' + startScale + ')';
    var duration = 720;
    var prev = colors[previousTheme] || colors.dark;

    wipe.style.background = prev;
    blob.style.opacity = '0';
    blob.style.background = 'transparent';
    blob.style.boxShadow = '0 0 0 1px ' + prev + ', 0 0 0 ' + spread + 'px ' + prev;
    blob.style.width = size + 'px';
    blob.style.height = size + 'px';
    blob.style.left = x + 'px';
    blob.style.top = y + 'px';
    blob.style.transform = from;
    wipe.hidden = false;

    function startHole() {
      applyNext();
      wipe.style.background = '';
      blob.style.opacity = '1';
      var start = performance.now();
      function frame(now) {
        var progress = Math.min(1, (now - start) / duration);
        blob.style.transform = 'translate3d(-50%, -50%, 0) scale(' + scaleAt(startScale, progress) + ')';
        if (progress < 1) {
          raf = requestAnimationFrame(frame);
          return;
        }
        finish();
      }
      raf = requestAnimationFrame(frame);
    }

    raf = requestAnimationFrame(function () {
      raf = requestAnimationFrame(startHole);
    });
  }

  function run(toggle, previousTheme, applyNext) {
    if (busy) return false;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      applyNext();
      return true;
    }

    busy = true;

    /* Lightswind toggle-theme: View Transition circle-spread */
    if (typeof document.startViewTransition === 'function') {
      ensureVtStyle();
      var rect = toggle.getBoundingClientRect();
      var x = rect.left + rect.width / 2;
      var y = rect.top + rect.height / 2;
      var maxRadius = Math.hypot(
        Math.max(x, window.innerWidth - x),
        Math.max(y, window.innerHeight - y)
      );
      var transition = document.startViewTransition(function () {
        applyNext();
      });
      transition.ready.then(function () {
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
      }).catch(function () {});
      transition.finished.then(function () {
        busy = false;
      }).catch(function () {
        busy = false;
        wipeFallback(toggle, previousTheme, function () {});
      });
      return true;
    }

    wipeFallback(toggle, previousTheme, applyNext);
    return true;
  }

  root.appThemeWipe = { run: run, busy: function () { return busy; } };
})(window);
