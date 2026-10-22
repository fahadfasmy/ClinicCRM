<?php
// Phase 8: document layouts. The schema refuses what isn't allowed (unknown blocks and settings, HTML and script,
// unknown merge fields, oversize layouts); required elements can't be deleted, hidden or put behind a condition;
// every starter layout publishes; rendering in English, Arabic and both, on A4 and an 80 mm roll, a 60-line invoice
// with the header row repeating, escaped values; versions never change and old invoices print as issued;
// re-renders are extra copies; amounts in words. Scratch database clinic_crm_test_layouts.
require_once __DIR__ . '/../includes/doc_layouts.php';

function layDb(): PDO {
    static $done = false;
    $db = testDb('clinic_crm_test_layouts', !$done);
    if (!$done) {
        migrateApply($db, __DIR__ . '/../migrations/tenant', fn($l) => null);
        $db->exec("UPDATE branches SET invoice_prefix = 'LAY', trn = '100200300400003' WHERE id = 1");
        $db->exec("INSERT INTO staff (id, name, email, password, role) VALUES (601, 'Ada Admin', 'ada.l@example.test', 'x', 'admin')");
        $db->exec("INSERT INTO services (id, name, price, vat_rate, duration_min) VALUES (31, 'Peel', 400, 5, 30)");
        $db->exec("INSERT INTO patients (id, mrn, first_name, last_name, name_ar, status, branch_id) VALUES (1, 'L-1', 'Lina', '<b>Lay</b>', 'لينا', 'active', 1)");
        $done = true;
    }
    $_SESSION['staff_id'] = 601; $_SESSION['role'] = 'admin'; staffPermissionOverrides(601, true);
    return $db;
}
function starter(string $key): array { [, , $page, $h, $b, $f] = starterLayouts()[$key]; return ['page' => $page, 'header' => $h, 'body' => $b, 'footer' => $f]; }
function blocksOf(array $L, string $type): array { $o = []; foreach (['header', 'body', 'footer'] as $a) foreach ($L[$a] as $ri => $r) foreach ($r['blocks'] as $bi => $b) if ($b['type'] === $type) $o[] = [$a, $ri, $bi]; return $o; }

function test_schema_refuses_what_is_not_allowed() {
    $L = starter('classic_invoice');
    checkThrows(fn() => validateLayout(array_merge($L, ['script' => 'x'])), 'Unknown layout setting', 'unknown top-level setting');
    $bad = $L; $bad['body'][] = ['blocks' => [['type' => 'iframe', 'w' => 4]]];
    checkThrows(fn() => validateLayout($bad), 'Unknown block type', 'unknown block type');
    $bad = $L; $bad['body'][] = ['blocks' => [['type' => 'text', 'w' => 6, 'onclick' => 'x']]];
    checkThrows(fn() => validateLayout($bad), 'unknown setting onclick', 'unknown block setting');
    $bad = $L; $bad['body'][] = ['blocks' => [['type' => 'text', 'w' => 8], ['type' => 'text', 'w' => 6]]];
    checkThrows(fn() => validateLayout($bad), 'wider than the page', 'a row can’t be wider than 12 columns');
    $bad = $L; $bad['body'][] = ['blocks' => [['type' => 'text', 'w' => 6, 'text' => ['en' => 'Hi {{patient.password}}']]]];
    checkThrows(fn() => validateLayout($bad), '{{patient.password}}', 'unknown merge field');
    $bad = $L; $bad['body'][] = ['blocks' => [['type' => 'text', 'w' => 6, 'style' => ['color' => 'red;background:url(x)']]]];
    checkThrows(fn() => validateLayout($bad), 'colour', 'colours must be hex');
    $big = $L; $big['body'][] = ['blocks' => [['type' => 'text', 'w' => 12, 'text' => ['en' => str_repeat('a', 3990)]]]];
    for ($i = 0; $i < 60; $i++) $big['body'][] = $big['body'][count($big['body']) - 1];
    checkThrows(fn() => validateLayout($big), '200 KB', 'layouts over 200 KB are refused');
    check(count(validateLayout($L)['header']) === 2, 'the Classic starter passes');
}

function test_free_text_is_sanitised() {
    $s = layoutSanitizeText('<b>Bold</b> <script>alert(1)</script><img src=x onerror=alert(1)><span style="color:#ff0000;background:url(javascript:x)" onclick="x">red</span><a href="javascript:x">l</a><p class="x">p</p>');
    check(str_contains($s, '<b>Bold</b>') && str_contains($s, '<span style="color:#ff0000">red</span>') && str_contains($s, '<p>p</p>'), 'allowed tags kept, unsafe styles and attributes removed');
    check(!str_contains($s, '<script') && !str_contains($s, '<img') && !str_contains($s, '<a ') && !str_contains($s, 'onclick') && !str_contains($s, 'javascript:x)"'), 'script, img, links and handlers become plain text');
    check(layoutSanitizeText($s) === $s, 'sanitising twice changes nothing');
}

function test_required_elements_can_not_be_removed_or_hidden() {
    $L = validateLayout(starter('classic_invoice'));
    check(layoutRequiredProblems($L, 'invoice') === [], 'Classic invoice has everything');
    $t = $L; [$a, $r, $b] = blocksOf($t, 'title')[0]; unset($t[$a][$r]['blocks'][$b]);
    check(str_contains(implode(' ', layoutRequiredProblems($t, 'invoice')), 'Tax Invoice'), 'deleting “Tax Invoice” is caught');
    $t = $L; [$a, $r, $b] = blocksOf($t, 'title')[0]; $t[$a][$r]['blocks'][$b]['text']['en'] = 'Invoice';
    check(implode(' ', layoutRequiredProblems($t, 'invoice')) === 'The document title must say “Tax Invoice”.', 'renaming it to “Invoice” is caught, in plain words');
    $t = $L; [$a, $r, $b] = blocksOf($t, 'clinic')[0]; $t[$a][$r]['blocks'][$b]['show']['trn'] = false;
    check(str_contains(implode(' ', layoutRequiredProblems($t, 'invoice')), 'TRN'), 'hiding the TRN is caught');
    $t = $L; [$a, $r, $b] = blocksOf($t, 'clinic')[0]; $t[$a][$r]['blocks'][$b]['cond'] = 'insurance';
    check(str_contains(implode(' ', layoutRequiredProblems($t, 'invoice')), 'condition'), 'a condition that could hide the clinic block is caught');
    $t = $L; [$a, $r, $b] = blocksOf($t, 'lines')[0]; foreach ($t[$a][$r]['blocks'][$b]['columns'] as &$c) if ($c['key'] === 'vat_amount') $c['on'] = false; unset($c);
    check(str_contains(implode(' ', layoutRequiredProblems($t, 'invoice')), 'required column'), 'hiding the VAT amount column is caught');
    $t = $L; [$a, $r, $b] = blocksOf($t, 'lines')[0]; foreach ($t[$a][$r]['blocks'][$b]['columns'] as &$c) if ($c['key'] === 'no') $c['on'] = false; unset($c);
    check(layoutRequiredProblems($t, 'invoice') === [], 'hiding the # column is fine');
    $db = layDb();
    $id = createLayout($db, 'invoice', 'Broken', json_encode(array_merge(starter('classic_invoice'), ['header' => []])));
    checkThrows(fn() => publishLayout($db, $id), 'can’t be published', 'publishing a layout without its header is refused');
    check(findLayout($db, $id)['current_version_id'] === null, 'and nothing was published');
}

function test_every_starter_layout_publishes() {
    $db = layDb();
    ensureStarterLayouts($db);
    $n = (int)$db->query("SELECT COUNT(*) FROM document_layouts WHERE starter IS NOT NULL AND status = 'published'")->fetchColumn();
    check($n === count(starterLayouts()), "all $n starter layouts published");
    foreach (['invoice', 'receipt', 'credit_note'] as $t) check(str_contains(chooseLayoutVersion($db, $t, 1)['name'], 'Classic'), "Classic is the default $t layout");
    ensureStarterLayouts($db);
    check((int)$db->query("SELECT COUNT(*) FROM document_layouts WHERE starter IS NOT NULL")->fetchColumn() === $n, 'starters are made only once');
}

function test_rendering_languages_paper_and_page_breaks() {
    $L = validateLayout(starter('classic_invoice'));
    $d = sampleDocumentData('invoice', 60);
    $d['lines'][0]['description'] = '<script>alert("x")</script>';
    $d['patient']['name'] = '"><img src=x onerror=alert(1)>';
    $en = renderDocument($L, $d, 'en');
    check(substr_count($en, '<tr><td style="text-align:start">') >= 60 && str_contains($en, '<table class="lines borders"><thead>') && str_contains($en, 'table.lines thead { display: table-header-group; }'), '60 lines, items header in <thead> (repeats on each page)');
    check(preg_match('/<table class="doc">\s*<thead><tr><td>/', $en) && str_contains($en, '<tfoot><tr><td'), 'page header and footer areas repeat on each page');
    check(!str_contains($en, '<script>alert') && str_contains($en, '&lt;script&gt;') && !str_contains($en, '<img src=x'), 'values are escaped (no script or image injection)');
    check(str_contains($en, 'dir="ltr"') && str_contains($en, 'Tax Invoice') && !str_contains($en, 'فاتورة ضريبية') && str_contains($en, 'size: 210mm 297mm'), 'English, A4');
    $ar = renderDocument($L, $d, 'ar');
    check(str_contains($ar, 'dir="rtl"') && str_contains($ar, 'lang="ar"') && str_contains($ar, 'فاتورة ضريبية') && str_contains($ar, 'الإجمالي'), 'Arabic: right to left with Arabic labels');
    $both = renderDocument($L, $d, 'both');
    check(str_contains($both, 'Description / الوصف') && str_contains($both, 'فاتورة ضريبية') && str_contains($both, 'Tax Invoice'), 'both languages side by side');
    $roll = renderDocument(validateLayout(starter('roll_invoice')), sampleDocumentData('invoice', 60), 'en');
    check(str_contains($roll, 'size: 80mm 297mm') && str_contains($roll, 'width: 74mm'), '80 mm roll page');
    $a5 = renderDocument(validateLayout(starter('compact_invoice')), sampleDocumentData('invoice'), 'both');
    check(str_contains($a5, 'size: 148mm 210mm'), 'A5 page');
    $L2 = $L; [$a, $r, $b] = blocksOf($L2, 'lines')[0]; $L2[$a][$r]['blocks'][$b]['cond'] = 'insurance';
    check(!str_contains(renderDocument($L2, $d, 'en'), '&lt;script&gt;'), 'a block shown only for insurance invoices is left out of a cash invoice');
    check(str_contains(renderDocument($L, $d, 'en', [], 'VOID'), '<div class="stamp">VOID</div>'), 'status stamp');
}

function test_every_starter_layout_prints_correctly_in_arabic() {
    // Phase 14: each starter layout, as printed for an Arabic reader and side by side with English
    foreach (starterLayouts() as $key => [$name, $type]) {
        $L = validateLayout(starter($key));
        $d = sampleDocumentData($type, 3);
        $ar = renderDocument($L, $d, 'ar');
        [$en, $arTitle] = DOC_TITLES[$type];
        check(str_contains($ar, 'dir="rtl"') && str_contains($ar, 'lang="ar"') && str_contains($ar, $arTitle) && !str_contains($ar, ">$en<"), "$key in Arabic: right to left, “{$arTitle}”, no English title");
        check(str_contains($ar, 'صفحة " counter(page)') || !str_contains($ar, 'counter(page)'), "$key in Arabic: page numbers in Arabic");
        $both = renderDocument($L, $d, 'both');
        check(str_contains($both, $arTitle) && str_contains($both, $en), "$key in both languages: both titles");
    }
}

function test_versions_and_reprints_never_change() {
    $db = layDb();
    ensureStarterLayouts($db);
    $classic = (int)$db->query("SELECT id FROM document_layouts WHERE starter = 'classic_invoice'")->fetchColumn();
    $inv = createInvoice($db, 1, 1); addInvoiceLine($db, $inv, ['kind' => 'service', 'service_id' => 31]); issueInvoice($db, $inv);
    $orig = documentRendition($db, 'invoice', $inv);
    check($orig && (int)$orig['layout_version_id'] === (int)findLayout($db, $classic)['current_version_id'] && strlen($orig['content_hash']) === 64, 'the invoice stores the layout version and a hash of what was printed');
    check(str_contains($orig['html'], '&lt;b&gt;Lay&lt;/b&gt;'), 'the patient’s name is escaped in the stored copy');
    $L = json_decode(findLayout($db, $classic)['draft_json'], true);
    [$a, $r, $b] = blocksOf($L, 'title')[0];
    $L[$a][$r]['blocks'][$b]['text']['en'] = 'Tax Invoice — NEW DESIGN';
    saveLayoutDraft($db, $classic, $L);
    $v1 = $db->query("SELECT layout_json FROM document_layout_versions WHERE layout_id = $classic AND version = 1")->fetchColumn();
    check(!str_contains($v1, 'NEW DESIGN'), 'saving a draft doesn’t touch the published version');
    publishLayout($db, $classic);
    check((int)findLayout($db, $classic)['current_version'] === 2 && !str_contains($db->query("SELECT layout_json FROM document_layout_versions WHERE layout_id = $classic AND version = 1")->fetchColumn(), 'NEW DESIGN'), 'publishing adds version 2; version 1 is unchanged');
    check(documentRendition($db, 'invoice', $inv)['html'] === $orig['html'], 'the old invoice still prints exactly as issued');
    $re = storeDocumentRendition($db, 'invoice', $inv, 'rerender');
    $reRow = $db->query("SELECT * FROM document_renditions WHERE id = $re")->fetch();
    check(str_contains($reRow['html'], 'NEW DESIGN') && !$reRow['is_original'] && documentRendition($db, 'invoice', $inv)['id'] === $orig['id'], 're-render is an extra copy; the original stays the one reprinted');
    check((int)$db->query("SELECT COUNT(*) FROM audit_log WHERE entity = 'invoice' AND entity_id = $inv AND action = 'rerendered'")->fetchColumn() === 1, 're-render is audit-logged');
    $inv2 = createInvoice($db, 1, 1); addInvoiceLine($db, $inv2, ['kind' => 'service', 'service_id' => 31]); issueInvoice($db, $inv2);
    check(str_contains(documentRendition($db, 'invoice', $inv2)['html'], 'NEW DESIGN'), 'new invoices use the newly published version');
}

function test_defaults_archive_and_branch_override() {
    $db = layDb();
    ensureStarterLayouts($db);
    $modern = (int)$db->query("SELECT id FROM document_layouts WHERE starter = 'modern_invoice'")->fetchColumn();
    $classic = (int)$db->query("SELECT id FROM document_layouts WHERE starter = 'classic_invoice'")->fetchColumn();
    checkThrows(fn() => archiveLayout($db, $classic), 'default', 'the default can’t be archived');
    $db->exec("UPDATE document_layouts SET branch_id = 1 WHERE id = $modern");
    setDefaultLayout($db, $modern);
    check(chooseLayoutVersion($db, 'invoice', 1)['layout_id'] == $modern && chooseLayoutVersion($db, 'invoice', 2)['layout_id'] == $classic, 'a branch’s own default wins for that branch only');
    $draft = createLayout($db, 'invoice', 'Unpublished', findLayout($db, $classic)['draft_json']);
    checkThrows(fn() => setDefaultLayout($db, $draft), 'published', 'a draft can’t be the default');
    $dup = duplicateLayout($db, $classic);
    check(findLayout($db, $dup)['name'] === 'Copy of Classic' && findLayout($db, $dup)['status'] === 'draft', 'duplicate makes a draft copy');
}

function test_amount_in_words() {
    check(amountWordsEn(102900) === 'One thousand twenty-nine dirhams only', 'EN: ' . amountWordsEn(102900));
    check(amountWordsEn(105050) === 'One thousand fifty dirhams and fifty fils only', 'EN: ' . amountWordsEn(105050));
    check(amountWordsEn(100) === 'One dirham only' && amountWordsEn(123456789) === 'One million two hundred thirty-four thousand five hundred sixty-seven dirhams and eighty-nine fils only', 'EN singular and millions');
    check(amountWordsAr(102900) === 'فقط ألف وتسعة وعشرون درهم لا غير', 'AR: ' . amountWordsAr(102900));
    check(amountWordsAr(250050) === 'فقط ألفان وخمسمائة درهم وخمسون فلس لا غير', 'AR: ' . amountWordsAr(250050));
    check(amountWordsAr(1500000) === 'فقط خمسة عشر ألف درهم لا غير' && amountWordsAr(300000) === 'فقط ثلاثة آلاف درهم لا غير', 'AR thousands: 15,000 and 3,000');
}

function test_layout_images() {
    $db = layDb();
    checkThrows(fn() => saveLayoutAsset($db, "<?php echo 1;"), 'PNG or JPG', 'a script is refused as an image');
    $chunk = fn($t, $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
    $png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 20, 10, 8, 0, 0, 0, 0)) . $chunk('tEXt', "Comment\0secret") . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\x40", 20), 10))) . $chunk('IEND', '');
    $id = saveLayoutAsset($db, $png);
    $a = $db->query("SELECT * FROM document_layout_assets WHERE id = $id")->fetch();
    check($a['width'] == 20 && !str_contains((string)file_get_contents(tenantStoragePath() . '/' . $a['path']), 'secret'), 'image stored with its size; text metadata removed');
    $L = starter('classic_invoice'); [$ar, $r, $b] = blocksOf($L, 'logo')[0]; $L[$ar][$r]['blocks'][$b]['asset'] = $id;
    $urls = layoutAssetUrls($db, checkLayout($db, $L));
    check(str_starts_with($urls[$id] ?? '', 'data:image/png;base64,'), 'the stored document carries the image inside it');
    $L[$ar][$r]['blocks'][$b]['asset'] = 99999;
    checkThrows(fn() => checkLayout($db, $L), 'image is missing', 'a layout pointing at a missing image is refused');
    unlink(tenantStoragePath() . '/' . $a['path']);
}
