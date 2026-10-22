<?php
// includes/i18n.php — the staff screens in English or Arabic. The English text itself is the key:
//   t('Save')                   → "Save", or "حفظ" for staff who chose Arabic (echo it in templates)
//   t('{n} new leads', ['n' => 3])
// lang/ar.php maps English → Arabic; lang/en.php can override English wording. Text with no Arabic yet shows in English,
// so a screen is never broken by a missing translation. t() returns plain text: wrap it in sanitize() when a {value}
// comes from users. The word list for reviewers: php tools/lang_export.php (CSV), loaded back with tools/lang_import.php.

/** The signed-in person's screen language: 'en' or 'ar'. */
function uiLang(): string { return ($_SESSION['ui_lang'] ?? $_COOKIE['ui_lang'] ?? 'en') === 'ar' ? 'ar' : 'en'; }

/** Remembers the language in this browser (the sign-in page uses it before anyone is signed in). */
function uiRememberLang(string $lang): void {
    $lang = $lang === 'ar' ? 'ar' : 'en';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    if (!headers_sent()) setcookie('ui_lang', $lang, ['expires' => time() + 365 * 86400, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    $_COOKIE['ui_lang'] = $lang;
}

/** 'rtl' for Arabic, 'ltr' otherwise. */
function uiDir(): string { return uiLang() === 'ar' ? 'rtl' : 'ltr'; }

/** The words for one language (cached). */
function uiStrings(string $lang): array {
    static $cache = [];
    if (!isset($cache[$lang])) {
        $f = __DIR__ . "/../lang/$lang.php";
        $cache[$lang] = is_file($f) ? (array)require $f : [];
    }
    return $cache[$lang];
}

function t(string $s, array $vars = []): string {
    $out = uiStrings(uiLang())[$s] ?? $s;
    if ($vars) $out = strtr($out, array_combine(array_map(fn($k) => '{' . $k . '}', array_keys($vars)), array_map('strval', $vars)));
    return $out;
}

const UI_AR_MONTHS = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
const UI_AR_DAYS = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

/** A date for the screen: "Mon, 19 Oct 2026" or "الاثنين، 19 أكتوبر 2026" (Western digits, as UAE clinics use). */
function uiDate(string $date, bool $weekday = false): string {
    $ts = strtotime($date);
    if ($ts === false) return $date;
    if (uiLang() !== 'ar') return date($weekday ? 'D, d M Y' : 'd M Y', $ts);
    return ($weekday ? UI_AR_DAYS[(int)date('w', $ts)] . '، ' : '') . date('j', $ts) . ' ' . UI_AR_MONTHS[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
}

/** Texts the page scripts show (assets/js/app.js), passed to the browser as window.UI_T for Arabic screens. */
function uiJsStrings(): array {
    if (uiLang() === 'en') return [];
    return [
        'Filter this list…' => t('Filter this list…'), 'Show _MENU_' => t('Show _MENU_'), '_START_–_END_ of _TOTAL_' => t('_START_–_END_ of _TOTAL_'),
        'No records' => t('No records'), '(filtered from _MAX_)' => t('(filtered from _MAX_)'), 'Nothing here yet' => t('Nothing here yet'),
        'No matching records' => t('No matching records'), 'Are you sure?' => t('Are you sure?'), 'Loading…' => t('Loading…'), 'Show more' => t('Show more'),
        'Could not load more leads.' => t('Could not load more leads.'), 'Move' => t('Move'), 'Just move it' => t('Just move it'),
        'Products short, low or expired' => t('Products short, low or expired'), 'Your session may have expired. Refresh the page.' => t('Your session may have expired. Refresh the page.'),
        'Only the assigned staff member can move this lead.' => t('Only the assigned staff member can move this lead.'),
    ];
}
