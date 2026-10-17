<?php
declare(strict_types=1);

function student_theme_head(): void
{
    echo <<<'HTML'
<script>
(function () {
  var skin = 'nursing';
  var theme = 'dark';
  try {
    var saved = localStorage.getItem('student-skin');
    if (saved === 'classic' || saved === 'nursing') skin = saved;
    var landing = localStorage.getItem('landing-theme');
    theme = landing === 'light' || landing === 'dark'
      ? landing
      : (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
  } catch (error) {}
  document.documentElement.setAttribute('data-student-skin', skin);
  document.documentElement.setAttribute('data-theme', theme);
})();
</script>
HTML;
}

function student_theme_select(): void
{
    echo <<<'HTML'
<label class="student-skin-select">
  <span class="visually-hidden">Thème</span>
  <select data-student-skin-select aria-label="Choisir le thème">
    <option value="classic">Thème actuel</option>
    <option value="nursing-dark">Nouveau thème sombre</option>
    <option value="nursing-light">Nouveau thème clair</option>
  </select>
</label>
<script>
(function () {
  var select = document.currentScript.previousElementSibling.querySelector('select');
  if (!select) return;
  var root = document.documentElement;
  function currentValue() {
    var skin = root.getAttribute('data-student-skin');
    var theme = root.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
    return skin === 'nursing' ? 'nursing-' + theme : 'classic';
  }
  select.value = currentValue();
  select.addEventListener('change', function () {
    if (select.value === 'classic') {
      root.setAttribute('data-student-skin', 'classic');
      try { localStorage.setItem('student-skin', 'classic'); } catch (error) {}
      return;
    }
    var theme = select.value === 'nursing-light' ? 'light' : 'dark';
    root.setAttribute('data-student-skin', 'nursing');
    root.setAttribute('data-theme', theme);
    try {
      localStorage.setItem('student-skin', 'nursing');
      localStorage.setItem('landing-theme', theme);
    } catch (error) {}
  });
})();
</script>
HTML;
}
