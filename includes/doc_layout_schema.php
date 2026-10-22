<?php
// includes/doc_layout_schema.php — what a document layout may contain, and the validator every layout passes through
// (designer saves, publishing, imports). A layout is rows of blocks on a 12-column grid in three areas: header and
// footer (repeated on every page) and body. No HTML or script is accepted: free text goes through an allow-list
// sanitiser (b i u br p ul ol li span with a few safe styles) and {{merge fields}} must be on the whitelist.
// The designer (assets/js/layout-designer.js) receives LAYOUT_BLOCKS, FIELD_LABELS, LINE_COLUMNS and MERGE_FIELDS as JSON.

const LAYOUT_MAX_BYTES = 200 * 1024;
const LAYOUT_FONTS = ['DM Sans', 'IBM Plex Sans Arabic', 'DM Serif Display', 'Outfit', 'Arial'];
const LAYOUT_PAGE_SIZES = ['A4' => [210, 297], 'A5' => [148, 210], 'Letter' => [216, 279], 'roll80' => [80, 297]];
const LAYOUT_CONDITIONS = ['' => 'Always', 'insurance' => 'Insurance invoice', 'cash' => 'Cash invoice', 'has_discount' => 'Has a discount',
                           'has_balance' => 'Has a balance due', 'lang_en' => 'English is shown', 'lang_ar' => 'Arabic is shown'];   // + 'branch:<id>'

// type => [name, icon, field toggles (key => on by default), extra properties]
const LAYOUT_BLOCKS = [
    'logo'        => ['Logo', 'bi-image', [], ['asset', 'height']],
    'clinic'      => ['Clinic details', 'bi-building', ['name' => 1, 'address' => 1, 'phone' => 1, 'email' => 0, 'website' => 0, 'trn' => 1, 'dha' => 0], []],
    'title'       => ['Document title', 'bi-type-h1', [], ['text']],
    'details'     => ['Document details', 'bi-hash', ['number' => 1, 'issue_date' => 1, 'supply_date' => 0, 'due_date' => 0, 'branch' => 0, 'cashier' => 0, 'invoice_ref' => 1, 'invoice_type' => 0], []],
    'patient'     => ['Patient details', 'bi-person', ['name' => 1, 'name_ar' => 1, 'mrn' => 1, 'phone' => 0, 'eid' => 0], []],
    'insurance'   => ['Insurance details', 'bi-shield-plus', ['company' => 1, 'policy' => 1, 'member' => 1, 'approval' => 1], []],
    'visit'       => ['Visit details', 'bi-calendar-event', ['doctor' => 1, 'department' => 0, 'visit_date' => 1], []],
    'lines'       => ['Items table', 'bi-table', [], ['columns', 'stripe', 'borders']],
    'totals'      => ['Totals', 'bi-calculator', ['subtotal' => 1, 'discount' => 1, 'taxable' => 1, 'vat' => 1, 'total' => 1, 'paid' => 0, 'balance' => 0], []],
    'vat_summary' => ['VAT summary', 'bi-percent', [], []],
    'words'       => ['Amount in words', 'bi-fonts', ['en' => 1, 'ar' => 1], []],
    'payments'    => ['Payments', 'bi-wallet2', ['methods' => 1, 'allocations' => 1], []],
    'notes'       => ['Notes and terms', 'bi-card-text', [], ['text']],
    'bank'        => ['Bank details', 'bi-bank', [], ['text']],
    'signature'   => ['Signature / stamp', 'bi-pen', [], ['asset', 'height', 'text']],
    'footer_text' => ['Footer text', 'bi-text-paragraph', [], ['text']],
    'page_number' => ['Page number', 'bi-file-earmark-break', [], []],
    'text'        => ['Free text', 'bi-textarea-t', [], ['text']],
    'image'       => ['Image', 'bi-card-image', [], ['asset', 'height']],
    'divider'     => ['Divider', 'bi-hr', [], []],
    'spacer'      => ['Spacer', 'bi-arrows-expand', [], ['height']],
];

// Labels for fields and columns: key => [English, Arabic]
const FIELD_LABELS = [
    'name' => ['Name', 'الاسم'], 'address' => ['Address', 'العنوان'], 'phone' => ['Phone', 'الهاتف'], 'email' => ['Email', 'البريد الإلكتروني'],
    'website' => ['Website', 'الموقع'], 'trn' => ['TRN', 'الرقم الضريبي'], 'dha' => ['DHA licence', 'ترخيص الهيئة'],
    'number' => ['Number', 'الرقم'], 'issue_date' => ['Date', 'التاريخ'], 'supply_date' => ['Date of supply', 'تاريخ التوريد'], 'due_date' => ['Due date', 'تاريخ الاستحقاق'],
    'branch' => ['Branch', 'الفرع'], 'cashier' => ['Cashier', 'أمين الصندوق'], 'invoice_ref' => ['Original invoice', 'الفاتورة الأصلية'], 'invoice_type' => ['Type', 'النوع'],
    'name_ar' => ['Name (Arabic)', 'الاسم بالعربية'], 'mrn' => ['File no.', 'رقم الملف'], 'eid' => ['Emirates ID', 'الهوية الإماراتية'],
    'company' => ['Insurer', 'شركة التأمين'], 'policy' => ['Policy', 'الوثيقة'], 'member' => ['Member no.', 'رقم العضوية'], 'approval' => ['Approval no.', 'رقم الموافقة'],
    'doctor' => ['Doctor', 'الطبيب'], 'department' => ['Department', 'القسم'], 'visit_date' => ['Visit date', 'تاريخ الزيارة'],
    'subtotal' => ['Subtotal', 'المجموع'], 'discount' => ['Discount', 'الخصم'], 'taxable' => ['Taxable amount', 'المبلغ الخاضع للضريبة'], 'vat' => ['VAT', 'ضريبة القيمة المضافة'],
    'total' => ['Total (AED)', 'الإجمالي (درهم)'], 'paid' => ['Paid', 'المدفوع'], 'balance' => ['Balance due', 'المبلغ المستحق'],
    'methods' => ['Paid by', 'طريقة الدفع'], 'allocations' => ['Paid against', 'سداد'], 'en' => ['English', 'الإنجليزية'], 'ar' => ['Arabic', 'العربية'],
];

// Items table columns: key => [English, Arabic, default width %, align]
const LINE_COLUMNS = [
    'no' => ['#', '#', 4, 'start'], 'code' => ['Code', 'الرمز', 8, 'start'], 'description' => ['Description', 'الوصف', 30, 'start'],
    'practitioner' => ['Practitioner', 'الطبيب', 12, 'start'], 'qty' => ['Qty', 'الكمية', 6, 'end'], 'unit_price' => ['Unit price', 'سعر الوحدة', 10, 'end'],
    'discount' => ['Discount', 'الخصم', 8, 'end'], 'taxable' => ['Taxable', 'المبلغ الخاضع', 10, 'end'], 'vat_rate' => ['VAT %', 'نسبة الضريبة', 6, 'end'],
    'vat_amount' => ['VAT', 'الضريبة', 8, 'end'], 'total' => ['Total', 'الإجمالي', 10, 'end'],
];

// Merge fields allowed in free text: key => [description, sample]
const MERGE_FIELDS = [
    'invoice.number' => ['Document number', 'ABC-INV-2026-000123'], 'invoice.date' => ['Issue date', '18 Oct 2026'], 'invoice.total' => ['Total', '1,050.00'],
    'invoice.balance' => ['Balance due', '0.00'], 'patient.name' => ['Patient name', 'Hana Ahmed'], 'patient.mrn' => ['File number', 'MRN-000123'],
    'clinic.name' => ['Clinic name', 'Example Clinic'], 'clinic.trn' => ['TRN', '100000000000003'], 'clinic.phone' => ['Clinic phone', '+971 4 000 0000'],
    'clinic.email' => ['Clinic email', 'info@example.ae'], 'branch.name' => ['Branch', 'Main branch'], 'doc.cashier' => ['Cashier', 'Rana'],
];

/** Allow-list sanitiser for the limited formatting in notes and free text: every other tag and attribute is escaped. */
function layoutSanitizeText(string $s): string {
    $parts = preg_split('/(<\/?(?:b|i|u|br|p|ul|ol|li|span)\b[^<>]*>)/i', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    foreach ($parts as $i => $p) {
        if ($i % 2 === 0) { $out .= htmlspecialchars(html_entity_decode($p, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES, 'UTF-8'); continue; }
        preg_match('/^<(\/?)(\w+)([^>]*)>$/', $p, $m);
        $tag = strtolower($m[2]);
        if ($m[1]) { $out .= $tag === 'br' ? '' : "</$tag>"; continue; }
        $style = '';
        if ($tag === 'span' && preg_match('/style\s*=\s*"([^"]*)"/i', $m[3], $sm)) {
            $ok = [];
            foreach (explode(';', $sm[1]) as $decl) {
                [$k, $v] = array_map('trim', explode(':', $decl, 2) + [1 => '']);
                $k = strtolower($k);
                if ($k === 'color' && preg_match('/^#[0-9a-f]{3,6}$/i', $v)) $ok[] = "color:$v";
                elseif ($k === 'font-weight' && in_array($v, ['bold', 'normal', '600', '700'], true)) $ok[] = "font-weight:$v";
                elseif ($k === 'font-style' && in_array($v, ['italic', 'normal'], true)) $ok[] = "font-style:$v";
                elseif ($k === 'text-decoration' && in_array($v, ['underline', 'none'], true)) $ok[] = "text-decoration:$v";
            }
            if ($ok) $style = ' style="' . implode(';', $ok) . '"';
        }
        $out .= $tag === 'br' ? '<br>' : "<$tag$style>";
    }
    return $out;
}

/** The {{fields}} used in a text that aren't on the whitelist. */
function layoutUnknownMergeFields(string $s): array {
    preg_match_all('/\{\{\s*([^}]*?)\s*\}\}/', $s, $m);
    return array_values(array_unique(array_diff($m[1], array_keys(MERGE_FIELDS))));
}

/**
 * Validates a layout (decoded JSON) and returns it normalised. Throws InvalidArgumentException listing every problem.
 * $branches = valid branch ids (for "branch is X" conditions), $assets = valid image ids.
 */
function validateLayout($layout, array $branches = [], array $assets = []): array {
    $err = [];
    if (!is_array($layout)) throw new InvalidArgumentException('The layout is not valid JSON.');
    if (strlen((string)json_encode($layout)) > LAYOUT_MAX_BYTES) throw new InvalidArgumentException('The layout is larger than 200 KB.');
    $extra = array_diff(array_keys($layout), ['v', 'page', 'header', 'body', 'footer']);
    if ($extra) $err[] = 'Unknown layout setting: ' . implode(', ', $extra) . '.';
    $hex = fn($v) => $v === '' || (is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v));
    $num = function ($v, $min, $max, string $what) use (&$err) { if (!is_numeric($v) || $v < $min || $v > $max) { $err[] = "$what must be between $min and $max."; return $min; } return $v + 0; };
    $str = fn($v, int $max) => is_string($v) ? mb_substr($v, 0, $max) : '';

    $pg = is_array($layout['page'] ?? null) ? $layout['page'] : [];
    $page = [
        'size' => isset(LAYOUT_PAGE_SIZES[$pg['size'] ?? '']) ? $pg['size'] : 'A4',
        'orientation' => ($pg['orientation'] ?? '') === 'landscape' ? 'landscape' : 'portrait',
        'language' => in_array($pg['language'] ?? '', ['en', 'ar', 'both'], true) ? $pg['language'] : 'both',
        'margins' => [], 'font' => in_array($pg['font'] ?? '', LAYOUT_FONTS, true) ? $pg['font'] : 'DM Sans',
        'font_size' => $num($pg['font_size'] ?? 10, 7, 14, 'Page text size'),
        'color' => $hex($pg['color'] ?? '') && ($pg['color'] ?? '') !== '' ? $pg['color'] : '#1d1b18',
        'accent' => $hex($pg['accent'] ?? '') && ($pg['accent'] ?? '') !== '' ? $pg['accent'] : '#b8925a',
        'watermark' => $str($pg['watermark'] ?? '', 40), 'stamps' => !empty($pg['stamps']), 'footer_pinned' => !empty($pg['footer_pinned']),
    ];
    foreach (['t', 'r', 'b', 'l'] as $k) $page['margins'][$k] = $num($pg['margins'][$k] ?? 12, 0, 30, 'Margins');
    $okKeys = ['id', 'type', 'w', 'show', 'text', 'style', 'cond', 'asset', 'height', 'columns', 'stripe', 'borders'];
    $out = ['v' => 1, 'page' => $page];
    $count = 0;
    foreach (['header', 'body', 'footer'] as $area) {
        $out[$area] = [];
        foreach ((array)($layout[$area] ?? []) as $ri => $row) {
            if (!is_array($row) || !is_array($row['blocks'] ?? null)) { $err[] = "A row in the $area is not valid."; continue; }
            if (++$count > 80) { $err[] = 'Too many rows (80 at most).'; break 2; }
            $blocks = []; $width = 0;
            foreach ($row['blocks'] as $b) {
                $type = is_array($b) ? ($b['type'] ?? '') : '';
                if (!isset(LAYOUT_BLOCKS[$type])) { $err[] = 'Unknown block type “' . $str($type, 30) . '”.'; continue; }
                [$name, , $fields, $props] = LAYOUT_BLOCKS[$type];
                if ($bad = array_diff(array_keys($b), $okKeys)) $err[] = "$name: unknown setting " . implode(', ', $bad) . '.';
                $n = ['id' => preg_match('/^[a-z0-9_-]{1,24}$/i', (string)($b['id'] ?? '')) ? $b['id'] : 'b' . bin2hex(random_bytes(4)), 'type' => $type,
                      'w' => (int)$num($b['w'] ?? 12, 1, 12, "$name width"), 'cond' => ''];
                $width += $n['w'];
                $cond = (string)($b['cond'] ?? '');
                if ($cond !== '' && !isset(LAYOUT_CONDITIONS[$cond]) && !(preg_match('/^branch:(\d+)$/', $cond, $cm) && in_array((int)$cm[1], $branches, true))) $err[] = "$name: unknown condition.";
                else $n['cond'] = $cond;
                if ($fields) { $n['show'] = []; foreach ($fields as $f => $def) $n['show'][$f] = isset($b['show'][$f]) ? (bool)$b['show'][$f] : (bool)$def; }
                if (in_array('text', $props, true)) {
                    $n['text'] = [];
                    foreach (['en', 'ar'] as $l) {
                        $t = layoutSanitizeText($str($b['text'][$l] ?? '', 4000));
                        if ($u = layoutUnknownMergeFields($t)) $err[] = "$name: unknown field " . implode(', ', array_map(fn($x) => '{{' . $x . '}}', $u)) . '.';
                        $n['text'][$l] = $t;
                    }
                }
                if (in_array('asset', $props, true)) { $a = (int)($b['asset'] ?? 0); if ($a && !in_array($a, $assets, true)) $err[] = "$name: the image is missing."; $n['asset'] = $a ?: null; }
                if (in_array('height', $props, true)) $n['height'] = (int)$num($b['height'] ?? 20, 2, 80, "$name height");
                if ($type === 'lines') {
                    $n['stripe'] = !empty($b['stripe']); $n['borders'] = !array_key_exists('borders', $b) || !empty($b['borders']);
                    $cols = []; $seen = [];
                    foreach ((array)($b['columns'] ?? []) as $c) {
                        $k = $c['key'] ?? '';
                        if (!isset(LINE_COLUMNS[$k]) || isset($seen[$k])) { $err[] = 'Items table: unknown or repeated column.'; continue; }
                        $seen[$k] = 1;
                        $cols[] = ['key' => $k, 'on' => !empty($c['on']), 'label' => ['en' => $str(strip_tags((string)($c['label']['en'] ?? LINE_COLUMNS[$k][0])), 40), 'ar' => $str(strip_tags((string)($c['label']['ar'] ?? LINE_COLUMNS[$k][1])), 40)],
                                   'w' => (int)$num($c['w'] ?? LINE_COLUMNS[$k][2], 2, 70, 'Column width'), 'align' => in_array($c['align'] ?? '', ['start', 'center', 'end'], true) ? $c['align'] : LINE_COLUMNS[$k][3]];
                    }
                    foreach (LINE_COLUMNS as $k => [$en, $ar, $w, $al]) if (!isset($seen[$k])) $cols[] = ['key' => $k, 'on' => false, 'label' => ['en' => $en, 'ar' => $ar], 'w' => $w, 'align' => $al];
                    $n['columns'] = $cols;
                }
                $st = is_array($b['style'] ?? null) ? $b['style'] : [];
                if (($st['color'] ?? '') !== '' && !$hex($st['color'])) $err[] = "$name: the text colour must be like #1d1b18.";
                if (($st['bg'] ?? '') !== '' && !$hex($st['bg'])) $err[] = "$name: the background colour must be like #ffffff.";
                $n['style'] = ['font' => in_array($st['font'] ?? '', LAYOUT_FONTS, true) ? $st['font'] : '', 'size' => !empty($st['size']) ? (int)$num($st['size'], 6, 32, "$name text size") : 0,   // 0 = the page's size
                               'weight' => ($st['weight'] ?? '') === 'bold' ? 'bold' : 'normal', 'color' => $hex($st['color'] ?? '') ? (string)($st['color'] ?? '') : '',
                               'bg' => $hex($st['bg'] ?? '') ? (string)($st['bg'] ?? '') : '', 'align' => in_array($st['align'] ?? '', ['start', 'center', 'end'], true) ? $st['align'] : 'start',
                               'border' => in_array($st['border'] ?? '', ['none', 'box', 'top', 'bottom'], true) ? $st['border'] : 'none', 'pad' => (int)$num($st['pad'] ?? 0, 0, 10, "$name padding")];
                $blocks[] = $n;
            }
            if ($width > 12) $err[] = "A row in the $area is wider than the page (blocks add up to $width of 12 columns).";
            $out[$area][] = ['id' => preg_match('/^[a-z0-9_-]{1,24}$/i', (string)($row['id'] ?? '')) ? $row['id'] : 'r' . bin2hex(random_bytes(4)), 'blocks' => $blocks];
        }
    }
    if ($err) throw new InvalidArgumentException(implode("\n", array_unique($err)));
    return $out;
}
