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
<label class="student-skin-select">
  <span class="visually-hidden">Thème</span>
  <select data-student-skin-select aria-label="Choisir le thème">
    <option value="classic">Thème actuel</option>
    <option value="nursing-dark">Institut sombre</option>
    <option value="nursing-light">Institut clair</option>
  </select>
</label>
<script>
(function () {
  var owner = {$owner};
  var skinKey = 'student-skin:' + owner;
  var themeKey = 'student-theme:' + owner;
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
      try { localStorage.setItem(skinKey, 'classic'); } catch (error) {}
      return;
    }
    var theme = select.value === 'nursing-light' ? 'light' : 'dark';
    root.setAttribute('data-student-skin', 'nursing');
    root.setAttribute('data-theme', theme);
    try {
      localStorage.setItem(skinKey, 'nursing');
      localStorage.setItem(themeKey, theme);
    } catch (error) {}
  });
})();
</script>
HTML;
}
