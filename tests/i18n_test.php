<?php
// Phase 14: the staff screens in Arabic. t() falls back to English, fills {values}; dates in Arabic; every text on the
// daily screens has Arabic; the Arabic keeps each {placeholder} and holds no HTML (it is printed as is); the stylesheet
// uses start/end (not left/right) so it mirrors for Arabic.
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../tools/lang_export.php';

const I18N_DAILY = ['pages/dashboard.php', 'pages/leads.php', 'pages/lead_view.php', 'pages/lead_add.php', 'pages/followups.php', 'pages/reminders.php',
    'pages/inbox.php', 'pages/notifications.php', 'pages/scheduler.php', 'pages/waiting_list.php', 'pages/visit_board.php', 'pages/patients.php', 'pages/patient.php',
    'pages/patient_edit.php', 'pages/appointment.php', 'pages/notes.php', 'pages/encounter.php', 'pages/invoices.php', 'pages/invoice.php', 'pages/receipts.php',
    'pages/receipt.php', 'pages/receipt_new.php', 'pages/day_close.php', 'pages/account.php', 'login.php', 'includes/header.php', 'includes/i18n.php'];

function test_t_translates_and_falls_back_to_english() {
    $_SESSION['ui_lang'] = 'en';
    check(t('Save') === 'Save' && uiDir() === 'ltr', 'English: the text as written, left to right');
    $_SESSION['ui_lang'] = 'ar';
    check(t('Save') === 'حفظ' && uiDir() === 'rtl' && uiLang() === 'ar', 'Arabic: translated, right to left');
    check(t('A sentence nobody has translated yet') === 'A sentence nobody has translated yet', 'no Arabic yet: shows in English, never blank');
    check(t('{n} new', ['n' => 7]) === '7 جديد', 'values are filled in: ' . t('{n} new', ['n' => 7]));
    $_SESSION['ui_lang'] = 'xx';
    check(uiLang() === 'en', 'anything else counts as English');
    unset($_SESSION['ui_lang']);
}

function test_dates_in_arabic() {
    $_SESSION['ui_lang'] = 'ar';
    check(uiDate('2026-10-19', true) === 'الاثنين، 19 أكتوبر 2026' && uiDate('2026-01-05') === '5 يناير 2026', 'Arabic day and month names: ' . uiDate('2026-10-19', true));
    $_SESSION['ui_lang'] = 'en';
    check(uiDate('2026-10-19', true) === 'Mon, 19 Oct 2026', 'English as before');
    unset($_SESSION['ui_lang']);
}

function test_every_text_on_the_daily_screens_has_arabic() {
    $ar = require __DIR__ . '/../lang/ar.php';
    $missing = [];
    foreach (langKeys(realpath(__DIR__ . '/..')) as $en => $where)
        if (array_intersect($where, I18N_DAILY) && ($ar[$en] ?? '') === '') $missing[] = "“$en” (" . implode(', ', array_intersect($where, I18N_DAILY)) . ')';
    check(!$missing, count($missing) . ' daily-screen texts without Arabic: ' . implode('; ', array_slice($missing, 0, 8)));
}

function test_arabic_keeps_placeholders_and_holds_no_html() {
    $bad = [];
    foreach (require __DIR__ . '/../lang/ar.php' as $en => $ar) {
        preg_match_all('/\{\w+\}|_[A-Z]+_/', $en, $a); preg_match_all('/\{\w+\}|_[A-Z]+_/', $ar, $b);
        sort($a[0]); sort($b[0]);
        if ($a[0] !== $b[0]) $bad[] = "placeholders differ: $en";
        if (preg_match('/[<>]/', $ar) && !preg_match('/[<>]/', $en)) $bad[] = "HTML in the Arabic: $en";
        if (trim($ar) === '') $bad[] = "empty: $en";
    }
    check(!$bad, implode('; ', array_slice($bad, 0, 8)));
}

function test_the_stylesheet_mirrors_for_arabic() {
    $css = file_get_contents(__DIR__ . '/../assets/css/app.css');
    // left/right only where it can't matter: centring (left: 50%) — everything else uses start/end
    preg_match_all('/(?<![-\w])(margin|padding|border)-(left|right)\s*:|(?<![-\w])(left|right)\s*:(?!\s*50%)|text-align\s*:\s*(left|right)/', $css, $m);
    check(!$m[0], 'physical left/right in app.css: ' . implode(', ', array_unique($m[0])));
    check(is_file(__DIR__ . '/../assets/vendor/bootstrap-5.3.2/bootstrap.rtl.min.css') && str_contains(file_get_contents(__DIR__ . '/../assets/vendor/bootstrap-5.3.2/bootstrap.rtl.min.css', false, null, 0, 200), 'v5.3.2'), 'Bootstrap’s right-to-left stylesheet, same version, self-hosted');
}

function test_the_import_refuses_a_dropped_placeholder() {
    require_once __DIR__ . '/../tools/lang_import.php';
    $f = tempnam(sys_get_temp_dir(), 'ar');
    file_put_contents($f, "\xEF\xBB\xBFEnglish,Arabic,Where\n\"{n} new\",\"جديد\",x\nSave,حفظ,x\n");
    checkThrows(fn() => langReadCsv($f), '{n}', 'a translation that loses {n} is refused');
    file_put_contents($f, "English,Arabic\n\"{n} new\",\"{n} جديد\"\nSave,\nCancel,إلغاء\n");
    check(langReadCsv($f) === ['{n} new' => '{n} جديد', 'Cancel' => 'إلغاء'], 'empty Arabic is left out (stays English)');
    unlink($f);
}
