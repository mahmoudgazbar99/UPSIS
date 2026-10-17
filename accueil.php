<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/landing_i18n.php';

$lang = landing_language();
$dir = LANDING_LANGUAGES[$lang]['dir'];
$strings = landing_strings($lang);

$school = institution_name_inline();
$schoolAr = trim(institution_setting('university_name_ar'));
$schoolLocal = ($lang === 'ar' && $schoolAr !== '') ? $schoolAr : $school;
$schoolLang = ($lang === 'ar' && $schoolAr === '') ? 'fr' : $lang;
$schoolDir = $schoolLang === 'ar' ? 'rtl' : 'ltr';
$lines = ($lang === 'ar' && $schoolAr !== '') ? [$schoolAr] : institution_name_lines();
$city = $lang === 'ar' && institution_city_ar() !== '' ? institution_city_ar() : institution_city();
$country = landing_country(institution_country(), $lang);
$address = institution_address();
$awardFr = institution_award_title();
$awardAr = institution_award_title_ar();
$award = ($lang === 'ar' && $awardAr !== '') ? $awardAr : $awardFr;

$programs = [];
$specialties = [];
try {
    foreach (academic_programs() as $program) {
        if ((int) ($program['active'] ?? 1) === 1) {
            $programs[] = $program;
        }
    }
    $pdo = db();
    if (db_table_exists($pdo, 'specialite')) {
        $columns = $pdo->query("SHOW COLUMNS FROM specialite LIKE 'active'")->fetch() ? ' WHERE active = 1' : '';
        $specialties = $pdo->query('SELECT nom_spec, nom_spec_ar FROM specialite' . $columns . ' ORDER BY id_spec')->fetchAll();
    }
} catch (Throwable $exception) {
    $programs = $programs ?: [];
}
$levels = study_levels();
$periods = [];
foreach ($levels as $level) {
    foreach (preg_split('/\s*,\s*/', (string) ($level['period_codes'] ?? '')) ?: [] as $code) {
        if ($code !== '') {
            $periods[$code] = true;
        }
    }
}
$yearCount = count($levels);
$periodCount = count($periods);
$programCount = count($programs);
$coreSpecialties = [];
$namedSpecialties = [];
foreach ($specialties as $specialty) {
    $isCore = in_array(mb_strtolower(trim((string) $specialty['nom_spec'])), ['commun', 'tronc commun'], true);
    if ($isCore) {
        $coreSpecialties[] = $specialty;
    } else {
        $namedSpecialties[] = $specialty;
    }
}
$specCount = count($namedSpecialties);

$vars = [
    'school' => $schoolLocal,
    'city' => $city,
    'country' => $country,
    'award' => $award,
    'years' => (string) $yearCount,
    'periods' => (string) $periodCount,
    'year' => date('Y'),
];

function t(string $key, array $extra = []): string
{
    global $strings, $vars;
    $text = $strings[$key] ?? $key;
    foreach ($extra + $vars as $name => $value) {
        $text = str_replace('{' . $name . '}', (string) $value, $text);
    }
    return e($text);
}

function landing_icon(string $name): string
{
    $paths = [
        'arrow' => '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>',
        'menu' => '<path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/>',
        'close' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon' => '<path d="M20.5 14.2A8.5 8.5 0 1 1 9.8 3.5a6.8 6.8 0 0 0 10.7 10.7z"/>',
        'book' => '<path d="M2 4h6a4 4 0 0 1 4 4v12a3 3 0 0 0-3-3H2z"/><path d="M22 4h-6a4 4 0 0 0-4 4v12a3 3 0 0 1 3-3h7z"/>',
        'microscope' => '<path d="M6 18h8"/><path d="M3 22h18"/><path d="M14 22a7 7 0 1 0 0-14h-1"/><path d="M9 14h2"/><path d="M9 12a2 2 0 0 1-2-2V6h6v4a2 2 0 0 1-2 2Z"/><path d="M12 6V3a1 1 0 0 0-1-1H9a1 1 0 0 0-1 1v3"/>',
        'stethoscope' => '<path d="M5 3H4a2 2 0 0 0-2 2v4a6 6 0 0 0 12 0V5a2 2 0 0 0-2-2h-1"/><path d="M8 15v1a6 6 0 0 0 12 0v-4"/><circle cx="20" cy="10" r="2"/>',
        'shield' => '<path d="M20 13c0 5-3.5 7.5-7.7 9a1 1 0 0 1-.6 0C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.2-2.7a1.2 1.2 0 0 1 1.6 0C14.5 3.8 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
        'heart' => '<path d="M19 14c1.5-1.5 3-3.2 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.8 0-3 .5-4.5 2-1.5-1.5-2.7-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4 3 5.5l7 7z"/>',
        'clipboard' => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/>',
        'cap' => '<path d="M21.4 10.9a1 1 0 0 0 0-1.8l-8.6-3.9a2 2 0 0 0-1.6 0L2.6 9.1a1 1 0 0 0 0 1.8l8.6 3.9a2 2 0 0 0 1.6 0z"/><path d="M22 10v6"/><path d="M6 12.5V16a6 3 0 0 0 12 0v-3.5"/>',
        'building' => '<path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4M10 10h4M10 14h4M10 18h4"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'pin' => '<path d="M20 10c0 5-5.5 10.2-7.4 11.8a1 1 0 0 1-1.2 0C9.5 20.2 4 15 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
        'megaphone' => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
        'lock' => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'bulb' => '<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/>',
        'activity' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        'file' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M16 13H8M16 17H8M10 9H8"/>',
        'wallet' => '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
        'board' => '<path d="M2 3h20"/><path d="M21 3v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V3"/><path d="m7 21 5-5 5 5"/>',
        'landmark' => '<path d="M3 22h18"/><path d="M6 18v-7M10 18v-7M14 18v-7M18 18v-7"/><path d="m12 2 8 5H4z"/>',
        'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
    ];
    $class = $name === 'arrow' ? 'icon icon-arrow' : 'icon';
    return '<svg class="' . $class . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . ($paths[$name] ?? '') . '</svg>';
}

function landing_picture(string $name, int $width, int $height, string $alt, string $sizes, bool $eager = false): string
{
    $base = 'images/landing/' . $name;
    $loading = $eager ? 'fetchpriority="high"' : 'loading="lazy" decoding="async"';
    return '<picture>'
        . '<source type="image/webp" srcset="' . $base . '-640.webp 640w, ' . $base . '.webp ' . $width . 'w" sizes="' . $sizes . '">'
        . '<img src="' . $base . '.jpg" srcset="' . $base . '-640.jpg 640w, ' . $base . '.jpg ' . $width . 'w" sizes="' . $sizes . '" width="' . $width . '" height="' . $height . '" alt="' . $alt . '" ' . $loading . '>'
        . '</picture>';
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
$navItems = [
    'accueil' => 'nav_home',
    'institut' => 'nav_institute',
    'formations' => 'nav_programs',
    'stages' => 'nav_clinical',
    'vie' => 'nav_life',
    'actualites' => 'nav_news',
    'contact' => 'nav_contact',
];
$days = explode('|', $strings['mock_days']);
?>
<!doctype html>
<html lang="<?= e($lang) ?>" dir="<?= e($dir) ?>" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <script>
    (function () {
      var theme = 'dark';
      try {
        var saved = localStorage.getItem('landing-theme');
        theme = saved === 'light' || saved === 'dark' ? saved : (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
      } catch (error) {}
      document.documentElement.setAttribute('data-theme', theme);
      document.documentElement.classList.add('js');
    })();
  </script>
  <title><?= t('meta_title') ?></title>
  <meta name="description" content="<?= t('meta_desc') ?>">
  <meta name="theme-color" content="#07111f" media="(prefers-color-scheme: dark)">
  <meta name="theme-color" content="#faf8f4" media="(prefers-color-scheme: light)">
  <link rel="canonical" href="<?= e($base . 'accueil.php?lang=' . $lang) ?>">
  <?php foreach (LANDING_LANGUAGES as $code => $info): ?>
  <link rel="alternate" hreflang="<?= e($code) ?>" href="<?= e($base . 'accueil.php?lang=' . $code) ?>">
  <?php endforeach; ?>
  <meta property="og:type" content="website">
  <meta property="og:title" content="<?= t('meta_title') ?>">
  <meta property="og:description" content="<?= t('meta_desc') ?>">
  <meta property="og:url" content="<?= e($base . 'accueil.php?lang=' . $lang) ?>">
  <meta property="og:image" content="<?= e($base . 'images/landing/skills-lab.jpg') ?>">
  <meta property="og:locale" content="<?= e(['fr' => 'fr_TN', 'ar' => 'ar_TN', 'en' => 'en_GB'][$lang]) ?>">
  <meta name="twitter:card" content="summary_large_image">
  <link rel="icon" href="<?= e(institution_logo_src()) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Newsreader:opsz,wght@6..72,500;6..72,600&family=Inter:wght@400;500;600;700&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="preload" as="image" type="image/webp" href="images/landing/skills-lab.webp" imagesrcset="images/landing/skills-lab-640.webp 640w, images/landing/skills-lab.webp 1152w" imagesizes="(min-width: 960px) 44vw, 92vw">
  <link href="assets/landing.css?v=22" rel="stylesheet">
</head>
<body>
  <a class="skip" href="#contenu"><?= t('skip') ?></a>

  <header class="site-header" data-header>
    <div class="header-inner">
      <a class="brand" href="#accueil">
        <img src="<?= e(institution_logo_src()) ?>" alt="" width="44" height="44">
        <span lang="<?= e($schoolLang) ?>" dir="<?= $schoolDir ?>"><?php foreach ($lines as $index => $line): ?><?= $index ? '<br>' : '' ?><?= e($line) ?><?php endforeach; ?></span>
      </a>

      <nav class="main-nav" aria-label="<?= t('nav_label') ?>">
        <?php foreach ($navItems as $anchor => $key): ?>
          <a href="#<?= e($anchor) ?>" data-nav-link><?= t($key) ?></a>
        <?php endforeach; ?>
      </nav>

      <div class="header-tools">
        <nav class="lang-switch" aria-label="<?= t('lang_label') ?>">
          <?php foreach (LANDING_LANGUAGES as $code => $info): ?>
            <a href="?lang=<?= e($code) ?>" lang="<?= e($code) ?>" hreflang="<?= e($code) ?>" title="<?= e($info['name']) ?>"<?= $code === $lang ? ' aria-current="true"' : '' ?>><span aria-hidden="true"><?= e($info['label']) ?></span><span class="sr-only"><?= e($info['name']) ?></span></a>
          <?php endforeach; ?>
        </nav>
        <button class="icon-btn theme-toggle" type="button" data-theme-toggle aria-pressed="false" data-label-light="<?= t('theme_light') ?>" data-label-dark="<?= t('theme_dark') ?>" aria-label="<?= t('theme_light') ?>">
          <span class="theme-sun"><?= landing_icon('sun') ?></span>
          <span class="theme-moon"><?= landing_icon('moon') ?></span>
        </button>
        <a class="btn btn-primary btn-sm header-portal" href="#portail"><?= landing_icon('lock') ?><span><?= t('portal') ?></span></a>
        <button class="icon-btn menu-toggle" type="button" data-menu-toggle aria-expanded="false" aria-controls="mobile-menu" data-label-open="<?= t('menu_open') ?>" data-label-close="<?= t('menu_close') ?>" aria-label="<?= t('menu_open') ?>">
          <span class="menu-icon-open"><?= landing_icon('menu') ?></span>
          <span class="menu-icon-close"><?= landing_icon('close') ?></span>
        </button>
      </div>
    </div>

    <div class="mobile-menu" id="mobile-menu" data-mobile-menu hidden>
      <nav aria-label="<?= t('nav_label') ?>">
        <?php foreach ($navItems as $anchor => $key): ?>
          <a href="#<?= e($anchor) ?>" data-menu-link><span><?= t($key) ?></span><?= landing_icon('arrow') ?></a>
        <?php endforeach; ?>
      </nav>
      <div class="mobile-menu-foot">
        <nav class="lang-switch" aria-label="<?= t('lang_label') ?>">
          <?php foreach (LANDING_LANGUAGES as $code => $info): ?>
            <a href="?lang=<?= e($code) ?>" lang="<?= e($code) ?>" hreflang="<?= e($code) ?>"<?= $code === $lang ? ' aria-current="true"' : '' ?>><?= e($info['name']) ?></a>
          <?php endforeach; ?>
        </nav>
        <a class="btn btn-primary" href="#portail" data-menu-link><?= landing_icon('lock') ?><span><?= t('portal') ?></span></a>
      </div>
    </div>
  </header>

  <main id="contenu" tabindex="-1">
    <section class="hero" id="accueil" aria-labelledby="hero-title">
      <div class="hero-bg" aria-hidden="true"></div>
      <div class="container hero-grid">
        <div class="hero-copy reveal">
          <p class="kicker"><?= t('hero_kicker') ?></p>
          <h1 id="hero-title"><?= t('hero_title') ?></h1>
          <p class="hero-text"><?= t('hero_text') ?></p>
          <div class="cta-row">
            <a class="btn btn-primary btn-lg" href="#formations"><span><?= t('hero_cta_programs') ?></span><?= landing_icon('arrow') ?></a>
            <a class="btn btn-ghost btn-lg" href="#portail"><?= landing_icon('lock') ?><span><?= t('hero_cta_portal') ?></span></a>
          </div>
        </div>

        <div class="hero-visual reveal" data-delay="1">
          <div class="hero-ring" aria-hidden="true"></div>
          <figure class="hero-main">
            <?= landing_picture('skills-lab', 1152, 864, t('alt_lab'), '(min-width: 960px) 44vw, 92vw', true) ?>
          </figure>
          <figure class="hero-inset">
            <?= landing_picture('academic-study', 1152, 864, t('alt_study'), '(min-width: 960px) 18vw, 40vw') ?>
          </figure>
          <ul class="hero-tags" aria-label="<?= t('facts_label') ?>">
            <li class="tag tag-1"><?= landing_icon('stethoscope') ?><?= t('tag_clinical') ?></li>
            <li class="tag tag-2"><?= landing_icon('shield') ?><?= t('tag_practice') ?></li>
            <li class="tag tag-3"><?= landing_icon('cap') ?><?= t('tag_academic') ?></li>
          </ul>
        </div>
      </div>

      <div class="container">
        <dl class="facts reveal" aria-label="<?= t('facts_label') ?>">
          <div><dt><?= max(1, $programCount) ?></dt><dd><?= t($programCount > 1 ? 'fact_program_many' : 'fact_program_one') ?></dd></div>
          <div><dt><?= $yearCount ?></dt><dd><?= t('fact_years') ?></dd></div>
          <div><dt><?= $periodCount ?></dt><dd><?= t('fact_periods') ?></dd></div>
          <?php if ($specCount > 0): ?><div><dt><?= $specCount ?></dt><dd><?= t($specCount > 1 ? 'fact_spec_many' : 'fact_spec_one') ?></dd></div><?php endif; ?>
        </dl>
      </div>
    </section>

    <section class="section about" id="institut" aria-labelledby="about-title">
      <div class="container about-grid">
        <figure class="about-media reveal">
          <?= landing_picture('academic-study', 1152, 864, t('alt_study'), '(min-width: 960px) 42vw, 92vw') ?>
          <figcaption lang="<?= e($schoolLang) ?>" dir="<?= $schoolDir ?>">
            <img src="<?= e(institution_logo_src()) ?>" alt="" width="40" height="40" loading="lazy">
            <span><?= e($schoolLocal) ?></span>
          </figcaption>
        </figure>
        <div class="about-copy reveal" data-delay="1">
          <p class="kicker"><?= t('about_kicker') ?></p>
          <h2 id="about-title"><?= t('about_title') ?></h2>
          <p class="lead"><?= t('about_p1') ?></p>
          <p><?= t('about_p2') ?></p>
          <ul class="pillars">
            <?php foreach (['science' => 'microscope', 'practice' => 'stethoscope', 'ethics' => 'shield', 'person' => 'heart'] as $key => $icon): ?>
              <li>
                <span class="pillar-icon"><?= landing_icon($icon) ?></span>
                <div><h3><?= t('pillar_' . $key) ?></h3><p><?= t('pillar_' . $key . '_d') ?></p></div>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    </section>

    <section class="section programs" id="formations" aria-labelledby="prog-title">
      <div class="container">
        <div class="section-head reveal">
          <div>
            <p class="kicker"><?= t('prog_kicker') ?></p>
            <h2 id="prog-title"><?= t('prog_title') ?></h2>
          </div>
          <div>
            <p><?= t('prog_intro') ?></p>
            <a class="link-arrow" href="#parcours"><span><?= t('prog_cta') ?></span><?= landing_icon('arrow') ?></a>
          </div>
        </div>

        <?php if ($programs): ?>
        <?php foreach ($programs as $programIndex => $program): ?>
          <?php
          $programName = $lang === 'ar' && trim((string) ($program['nom_ar'] ?? '')) !== '' ? (string) $program['nom_ar'] : (string) $program['nom'];
          $programAward = $lang === 'ar' && trim((string) ($program['award_title_ar'] ?? '')) !== '' ? (string) $program['award_title_ar'] : (string) $program['award_title'];
          $programLang = $lang === 'ar' && trim((string) ($program['nom_ar'] ?? '')) !== '' ? 'ar' : 'fr';
          $programLevels = study_levels((int) $program['id_program']);
          ?>
          <article class="program-card reveal"<?= $programIndex === 0 ? ' id="parcours"' : '' ?>>
            <div class="program-head">
              <p class="badge"><?= landing_icon('cap') ?><?= t('prog_badge') ?> · <?= e((string) $program['code']) ?></p>
              <h3 lang="<?= e($programLang) ?>"><?= e($programName) ?></h3>
              <?php if ($programAward !== $programName): ?><p lang="<?= e($programLang) ?>"><?= e($programAward) ?></p><?php endif; ?>
              <?php if ($lang !== 'ar' && trim((string) ($program['award_title_ar'] ?? '')) !== ''): ?><p class="program-ar" lang="ar" dir="rtl"><?= e((string) $program['award_title_ar']) ?></p><?php endif; ?>
              <p class="program-meta"><span><?= t('prog_years', ['years' => count($programLevels)]) ?></span><span><?= t('prog_periods') ?></span></p>
            </div>
            <div class="program-path">
              <h4><?= t('path_title') ?></h4>
              <ol class="timeline">
                <?php foreach ($programLevels as $level): ?>
                  <?php $levelCodes = array_filter(preg_split('/\s*,\s*/', (string) ($level['period_codes'] ?? '')) ?: []); ?>
                  <li class="<?= (int) ($level['is_final'] ?? 0) === 1 ? 'is-final' : '' ?>" tabindex="0">
                    <span class="timeline-dot" aria-hidden="true"><?= (int) $level['sequence'] ?></span>
                    <div>
                      <strong><?= ($lang === 'ar' && trim((string) ($level['label_ar'] ?? '')) !== '') ? e((string) $level['label_ar']) : t('year_n', ['n' => (int) $level['sequence']]) ?></strong>
                      <span class="chips"><?php foreach ($levelCodes as $code): ?><span dir="ltr"><?= e($code) ?></span><?php endforeach; ?></span>
                      <?php if ((int) ($level['is_final'] ?? 0) === 1): ?><em><?= t('final_year') ?></em><?php endif; ?>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ol>
            </div>
          </article>
        <?php endforeach; ?>
        <?php else: ?>
          <p class="empty-inline"><?= t('prog_empty') ?></p>
        <?php endif; ?>

        <?php if ($specialties): ?>
        <div class="subhead reveal">
          <h3><?= t('spec_title') ?></h3>
          <p><?= t('spec_intro') ?></p>
        </div>
        <ul class="spec-grid">
          <?php foreach (array_merge($coreSpecialties, $namedSpecialties) as $index => $specialty): ?>
            <?php
            $isCore = in_array($specialty, $coreSpecialties, true);
            $specName = $isCore ? t('common_core') : e($lang === 'ar' && trim((string) ($specialty['nom_spec_ar'] ?? '')) !== '' ? (string) $specialty['nom_spec_ar'] : (string) $specialty['nom_spec']);
            $specLang = $isCore || $lang === 'ar' ? $lang : 'fr';
            ?>
            <li class="spec-card reveal<?= $isCore ? ' is-core' : '' ?>" data-delay="<?= $index % 4 ?>">
              <span class="spec-index" aria-hidden="true"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
              <h4 lang="<?= e($specLang) ?>"><?= $specName ?></h4>
              <?php if ($lang !== 'ar' && trim((string) ($specialty['nom_spec_ar'] ?? '')) !== ''): ?><p lang="ar" dir="rtl"><?= e((string) $specialty['nom_spec_ar']) ?></p><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <div class="subhead reveal">
          <h3><?= t('peda_title') ?></h3>
        </div>
        <ul class="peda-grid">
          <?php foreach (['theory' => 'book', 'tp' => 'clipboard', 'skills' => 'stethoscope', 'eval' => 'activity', 'stage' => 'building'] as $index => $icon): ?>
            <li class="peda-card reveal">
              <span class="peda-icon"><?= landing_icon($icon) ?></span>
              <h4><?= t('peda_' . $index) ?></h4>
              <p><?= t('peda_' . $index . '_d') ?></p>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </section>

    <section class="section clinical" id="stages" aria-labelledby="clin-title">
      <div class="clinical-glow" aria-hidden="true"></div>
      <div class="container clinical-grid">
        <div class="clinical-media reveal">
          <figure>
            <?= landing_picture('clinical-practice', 864, 1152, t('alt_clinical'), '(min-width: 960px) 34vw, 92vw') ?>
          </figure>
          <blockquote><p><?= t('clin_quote') ?></p></blockquote>
        </div>
        <div class="clinical-copy">
          <p class="kicker reveal"><?= t('clin_kicker') ?></p>
          <h2 id="clin-title" class="reveal"><?= t('clin_title') ?></h2>
          <p class="lead reveal"><?= t('clin_lead') ?></p>
          <ol class="steps">
            <?php foreach (['understand', 'train', 'practice', 'progress'] as $index => $key): ?>
              <li class="reveal" data-delay="<?= $index ?>">
                <span class="step-num" aria-hidden="true"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                <div><h3><?= t('step_' . $key) ?></h3><p><?= t('step_' . $key . '_d') ?></p></div>
              </li>
            <?php endforeach; ?>
          </ol>
          <aside class="clinical-note reveal">
            <?= landing_icon('lock') ?>
            <div><strong><?= t('clin_note_t') ?></strong><p><?= t('clin_note') ?></p></div>
          </aside>
        </div>
      </div>
    </section>

    <section class="section digital" aria-labelledby="dig-title">
      <div class="container digital-grid">
        <div class="digital-copy">
          <p class="kicker reveal"><?= t('dig_kicker') ?></p>
          <h2 id="dig-title" class="reveal"><?= t('dig_title') ?></h2>
          <p class="reveal"><?= t('dig_intro') ?></p>
          <div class="service-cols">
            <div class="service-group reveal">
              <h3><?= landing_icon('cap') ?><?= t('dig_students') ?></h3>
              <ul>
                <?php foreach (['dig_s1', 'dig_s2', 'dig_s3', 'dig_s4', 'dig_s5'] as $key): ?><li><?= t($key) ?></li><?php endforeach; ?>
              </ul>
            </div>
            <div class="service-group reveal" data-delay="1">
              <h3><?= landing_icon('building') ?><?= t('dig_staff') ?></h3>
              <ul>
                <?php foreach (['dig_p1', 'dig_p2', 'dig_p3', 'dig_p4', 'dig_p5'] as $key): ?><li><?= t($key) ?></li><?php endforeach; ?>
              </ul>
            </div>
          </div>
          <a class="btn btn-primary btn-lg reveal" href="#portail"><?= landing_icon('lock') ?><span><?= t('dig_cta') ?></span></a>
        </div>

        <figure class="mockup reveal" data-delay="1">
          <div class="mockup-window" aria-hidden="true">
            <div class="mockup-bar"><i></i><i></i><i></i><span><?= t('mock_space') ?></span></div>
            <div class="mockup-body">
              <div class="mockup-head">
                <img src="<?= e(institution_logo_src()) ?>" alt="" width="34" height="34" loading="lazy">
                <div><b class="sk sk-w60"></b><b class="sk sk-w40"></b></div>
                <span class="mockup-bell"><?= landing_icon('mail') ?></span>
              </div>
              <div class="mockup-tiles">
                <?php foreach (['mock_profile' => 'users', 'mock_schedule' => 'calendar', 'mock_notes' => 'activity', 'mock_attest' => 'file'] as $index => $icon): ?>
                  <div class="mockup-tile<?= $index === 'mock_schedule' ? ' is-active' : '' ?>"><?= landing_icon($icon) ?><span><?= t($index) ?></span></div>
                <?php endforeach; ?>
              </div>
              <div class="mockup-panel">
                <p><?= t('mock_week') ?> · <span dir="ltr">S1</span></p>
                <div class="mockup-week">
                  <?php foreach ($days as $index => $day): ?>
                    <div class="mockup-day">
                      <small><?= e($day) ?></small>
                      <b class="slot slot-<?= ($index % 3) + 1 ?>"></b>
                      <?php if ($index !== 2): ?><b class="slot slot-<?= (($index + 1) % 3) + 1 ?>"></b><?php endif; ?>
                      <?php if ($index % 2 === 0): ?><b class="slot slot-<?= (($index + 2) % 3) + 1 ?>"></b><?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>
          <figcaption><?= t('mock_label') ?></figcaption>
        </figure>
      </div>
    </section>

    <section class="section life" id="vie" aria-labelledby="life-title">
      <div class="container">
        <div class="life-hero reveal">
          <?= landing_picture('student-life', 1280, 720, t('alt_life'), '(min-width: 1200px) 1200px, 94vw') ?>
          <div class="life-hero-copy">
            <p class="kicker"><?= t('life_kicker') ?></p>
            <h2 id="life-title"><?= t('life_title') ?></h2>
            <p><?= t('life_intro') ?></p>
          </div>
        </div>
        <ul class="life-grid">
          <?php foreach (['academic' => 'book', 'practice' => 'stethoscope', 'team' => 'users', 'ethics' => 'shield', 'science' => 'bulb'] as $key => $icon): ?>
            <li class="reveal">
              <?= landing_icon($icon) ?>
              <h3><?= t('life_' . $key) ?></h3>
              <p><?= t('life_' . $key . '_d') ?></p>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </section>

    <section class="section news" id="actualites" aria-labelledby="news-title">
      <div class="container">
        <div class="section-head reveal">
          <div>
            <p class="kicker"><?= t('news_kicker') ?></p>
            <h2 id="news-title"><?= t('news_title') ?></h2>
          </div>
        </div>
        <div class="news-empty reveal" role="status">
          <span class="news-icon"><?= landing_icon('megaphone') ?></span>
          <div>
            <h3><?= t('news_empty_t') ?></h3>
            <p><?= t('news_empty') ?></p>
          </div>
          <a class="btn btn-ghost" href="connexion_etudiant.php"><span><?= t('news_cta') ?></span><?= landing_icon('arrow') ?></a>
        </div>
      </div>
    </section>

    <section class="section portal" id="portail" aria-labelledby="portal-title">
      <div class="container">
        <div class="portal-shell reveal">
          <div class="portal-intro">
            <p class="kicker"><?= t('portal_kicker') ?></p>
            <h2 id="portal-title"><?= t('portal_title') ?></h2>
            <p><?= t('portal_intro') ?></p>
          </div>
          <div class="portal-grid">
            <article class="portal-card portal-student">
              <span class="portal-icon"><?= landing_icon('cap') ?></span>
              <h3><?= t('student_t') ?></h3>
              <p><?= t('student_d') ?></p>
              <ul class="feature-chips">
                <?php foreach (['mock_profile' => 'users', 'mock_schedule' => 'calendar', 'mock_notes' => 'activity', 'mock_attest' => 'file', 'mock_messages' => 'mail'] as $key => $icon): ?>
                  <li><?= landing_icon($icon) ?><?= t($key) ?></li>
                <?php endforeach; ?>
              </ul>
              <div class="cta-row">
                <a class="btn btn-primary" href="connexion_etudiant.php"><span><?= t('student_login') ?></span><?= landing_icon('arrow') ?></a>
                <a class="btn btn-ghost" href="creer_espace_etudiant.php"><?= t('student_activate') ?></a>
              </div>
              <small><?= t('student_hint') ?></small>
            </article>
            <article class="portal-card portal-staff">
              <span class="portal-icon"><?= landing_icon('building') ?></span>
              <h3><?= t('staff_t') ?></h3>
              <p><?= t('staff_d') ?></p>
              <ul class="role-list">
                <?php foreach (['admin' => ['role_admin', 'landmark'], 'scolarite' => ['role_scolarite', 'file'], 'enseignant' => ['role_teacher', 'board'], 'stage' => ['role_stage', 'stethoscope'], 'finance' => ['role_finance', 'wallet']] as $role => [$key, $icon]): ?>
                  <li>
                    <a href="login.php?role=<?= e($role) ?>">
                      <span class="role-icon"><?= landing_icon($icon) ?></span>
                      <span class="role-text"><strong><?= t($key) ?></strong><small><?= t($key . '_d') ?></small></span>
                      <?= landing_icon('arrow') ?>
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            </article>
          </div>
        </div>
      </div>
    </section>

    <section class="section contact" id="contact" aria-labelledby="contact-title">
      <div class="container contact-grid">
        <div class="reveal">
          <p class="kicker"><?= t('contact_kicker') ?></p>
          <h2 id="contact-title"><?= t('contact_title') ?></h2>
        </div>
        <dl class="contact-list reveal" data-delay="1">
          <div>
            <dt><?= landing_icon('pin') ?><?= t('contact_place') ?></dt>
            <dd><?php if ($address !== ''): ?><span lang="fr"><?= e($address) ?></span><br><?php endif; ?><?= e($city) ?>, <?= e($country) ?></dd>
          </div>
          <div>
            <dt><?= landing_icon('building') ?><?= t('contact_office') ?></dt>
            <dd><?= t('contact_office_d') ?></dd>
          </div>
        </dl>
      </div>
    </section>
  </main>

  <footer class="site-footer">
    <div class="container footer-grid">
      <div class="footer-brand">
        <img src="<?= e(institution_logo_src()) ?>" alt="" width="48" height="48" loading="lazy">
        <p lang="<?= e($schoolLang) ?>" dir="<?= $schoolDir ?>"><?= e($schoolLocal) ?></p>
      </div>
      <nav aria-label="<?= t('footer_nav') ?>">
        <?php foreach ($navItems as $anchor => $key): ?><a href="#<?= e($anchor) ?>"><?= t($key) ?></a><?php endforeach; ?>
      </nav>
      <div class="footer-meta">
        <p><?= t('footer_rights') ?></p>
        <p><?= t('footer_images') ?></p>
        <a class="link-arrow" href="#accueil"><span><?= t('back_top') ?></span></a>
      </div>
    </div>
  </footer>

  <script>
    (function () {
      var root = document.documentElement;
      var header = document.querySelector('[data-header]');
      var themeButton = document.querySelector('[data-theme-toggle]');
      var menuButton = document.querySelector('[data-menu-toggle]');
      var menu = document.querySelector('[data-mobile-menu]');
      var reduce = window.matchMedia('(prefers-reduced-motion: reduce)');

      function paintTheme() {
        var light = root.getAttribute('data-theme') === 'light';
        themeButton.setAttribute('aria-pressed', light ? 'true' : 'false');
        themeButton.setAttribute('aria-label', light ? themeButton.dataset.labelDark : themeButton.dataset.labelLight);
        var color = light ? '#faf8f4' : '#07111f';
        document.querySelectorAll('meta[name="theme-color"]').forEach(function (meta) { meta.setAttribute('content', color); });
      }
      paintTheme();
      themeButton.addEventListener('click', function () {
        var next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
        root.classList.add('theme-shift');
        root.setAttribute('data-theme', next);
        try { localStorage.setItem('landing-theme', next); } catch (error) {}
        paintTheme();
        window.setTimeout(function () { root.classList.remove('theme-shift'); }, 320);
      });

      function setMenu(open) {
        menuButton.setAttribute('aria-expanded', open ? 'true' : 'false');
        menuButton.setAttribute('aria-label', open ? menuButton.dataset.labelClose : menuButton.dataset.labelOpen);
        menu.hidden = !open;
        document.body.classList.toggle('menu-open', open);
        if (open) {
          var first = menu.querySelector('a');
          if (first) first.focus();
        }
      }
      menuButton.addEventListener('click', function () { setMenu(menu.hidden); });
      menu.querySelectorAll('[data-menu-link]').forEach(function (link) {
        link.addEventListener('click', function () { setMenu(false); });
      });
      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !menu.hidden) {
          setMenu(false);
          menuButton.focus();
        }
      });
      window.matchMedia('(min-width: 1180px)').addEventListener('change', function (event) {
        if (event.matches) setMenu(false);
      });

      var compact = function () { header.classList.toggle('is-compact', window.scrollY > 24); };
      compact();
      window.addEventListener('scroll', compact, { passive: true });

      var links = Array.prototype.slice.call(document.querySelectorAll('[data-nav-link]'));
      if ('IntersectionObserver' in window) {
        var sections = links.map(function (link) { return document.querySelector(link.getAttribute('href')); }).filter(Boolean);
        var spy = new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) {
            if (!entry.isIntersecting) return;
            links.forEach(function (link) {
              var active = link.getAttribute('href') === '#' + entry.target.id;
              if (active) link.setAttribute('aria-current', 'location'); else link.removeAttribute('aria-current');
            });
          });
        }, { rootMargin: '-45% 0px -50% 0px' });
        sections.forEach(function (section) { spy.observe(section); });

        if (!reduce.matches) {
          var reveal = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
              if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                reveal.unobserve(entry.target);
              }
            });
          }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
          document.querySelectorAll('.reveal').forEach(function (node) { reveal.observe(node); });
        } else {
          document.querySelectorAll('.reveal').forEach(function (node) { node.classList.add('is-visible'); });
        }
      } else {
        document.querySelectorAll('.reveal').forEach(function (node) { node.classList.add('is-visible'); });
      }
    })();
  </script>
</body>
</html>
