/**
 * Lightswind-style interactions for espace candidat (PHP port).
 * - glowing-cards cursor spotlight
 * - magic-card light tilt
 * - scroll reveal stagger
 */
(function () {
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
  if (reduce.matches) {
    document.querySelectorAll('.adm-ec-reveal').forEach(function (el) {
      el.classList.add('is-in');
    });
    return;
  }

  // Staggered reveal
  var reveals = Array.prototype.slice.call(document.querySelectorAll('.adm-ec-reveal'));
  reveals.forEach(function (el, i) {
    el.style.setProperty('--reveal-delay', Math.min(i * 70, 420) + 'ms');
  });
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-in');
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });
    reveals.forEach(function (el) { io.observe(el); });
  } else {
    reveals.forEach(function (el) { el.classList.add('is-in'); });
  }

  // Cursor glow + soft tilt (glowing-cards / magic-card)
  document.querySelectorAll('.adm-ec-card[data-glow], .adm-ec-hero[data-glow]').forEach(function (card) {
    var frame = 0;
    var targetX = 50;
    var targetY = 40;
    var curX = 50;
    var curY = 40;
    var hovering = false;

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
      var nx = (px / 50) - 1;
      var ny = (py / 50) - 1;
      card.style.transform =
        'perspective(900px) rotateY(' + (nx * 4).toFixed(2) + 'deg) rotateX(' + (-ny * 3.2).toFixed(2) + 'deg) translateY(-2px)';
      if (!frame) frame = window.requestAnimationFrame(paint);
    });

    card.addEventListener('pointerleave', function () {
      hovering = false;
      card.classList.remove('is-hot');
      targetX = 50;
      targetY = 40;
      card.style.transform = '';
      if (!frame) frame = window.requestAnimationFrame(paint);
    });
  });
})();
