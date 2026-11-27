/**
 * Lightswind interactions for activated student portal.
 */
(function () {
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
  if (reduce.matches) {
    document.querySelectorAll('.student-ec-reveal').forEach(function (el) {
      el.classList.add('is-in');
    });
    return;
  }

  var reveals = Array.prototype.slice.call(document.querySelectorAll('.student-ec-reveal'));
  reveals.forEach(function (el, i) {
    el.style.setProperty('--reveal-delay', Math.min(i * 55, 380) + 'ms');
  });
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-in');
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.1, rootMargin: '0px 0px -6% 0px' });
    reveals.forEach(function (el) { io.observe(el); });
  } else {
    reveals.forEach(function (el) { el.classList.add('is-in'); });
  }

  function bindGlow(card) {
    var frame = 0;
    var targetX = 50;
    var targetY = 40;
    var curX = 50;
    var curY = 40;
    var hovering = false;
    var tilt = card.hasAttribute('data-tilt');

    function paint() {
      curX += (targetX - curX) * 0.12;
      curY += (targetY - curY) * 0.12;
      card.style.setProperty('--pointer-x', curX.toFixed(2) + '%');
      card.style.setProperty('--pointer-y', curY.toFixed(2) + '%');
      if (hovering || Math.abs(targetX - curX) > 0.2 || Math.abs(targetY - curY) > 0.2) {
        frame = window.requestAnimationFrame(paint);
      } else {
        frame = 0;
      }
    }

    card.addEventListener('pointerenter', function () {
      hovering = true;
      card.classList.add('is-hot');
      if (!frame) frame = window.requestAnimationFrame(paint);
    });
    card.addEventListener('pointermove', function (event) {
      var rect = card.getBoundingClientRect();
      var px = ((event.clientX - rect.left) / Math.max(rect.width, 1)) * 100;
      var py = ((event.clientY - rect.top) / Math.max(rect.height, 1)) * 100;
      targetX = px;
      targetY = py;
      if (tilt) {
        var nx = (px / 50) - 1;
        var ny = (py / 50) - 1;
        card.style.transform =
          'perspective(900px) rotateY(' + (nx * 3.5).toFixed(2) + 'deg) rotateX(' + (-ny * 2.8).toFixed(2) + 'deg) translateY(-2px)';
      }
      if (!frame) frame = window.requestAnimationFrame(paint);
    });
    card.addEventListener('pointerleave', function () {
      hovering = false;
      card.classList.remove('is-hot');
      targetX = 50;
      targetY = 40;
      if (tilt) card.style.transform = '';
      if (!frame) frame = window.requestAnimationFrame(paint);
    });
  }

  document.querySelectorAll('.student-ec-hero[data-glow], .student-space-cards button[data-glow], .student-space-sheet[data-glow]').forEach(bindGlow);

  // Cursor light for atmosphere
  var pageLight = document.querySelector('[data-student-page-light]');
  if (pageLight) {
    var aimX = 72;
    var aimY = 18;
    var curLX = aimX;
    var curLY = aimY;
    window.addEventListener('pointermove', function (event) {
      aimX = (event.clientX / Math.max(window.innerWidth, 1)) * 100;
      aimY = (event.clientY / Math.max(window.innerHeight, 1)) * 100;
    }, { passive: true });
    (function follow() {
      curLX += (aimX - curLX) * 0.08;
      curLY += (aimY - curLY) * 0.08;
      pageLight.style.left = curLX.toFixed(2) + '%';
      pageLight.style.top = curLY.toFixed(2) + '%';
      window.requestAnimationFrame(follow);
    })();
  }
})();
