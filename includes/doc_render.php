<?php
// includes/doc_render.php — renderDocument($layout, $data, $lang): a validated layout + one document's data → a
// standalone printable HTML page. Pure: no database, no session. Table markup only (no flexbox or grid) so it prints
// the same everywhere: the header and footer areas sit in the page table's <thead>/<tfoot> and repeat on every printed
// page, as does the items table's header row. RTL for Arabic. Every value from $data is HTML-escaped; free text was
// sanitised when the layout was saved.
//
// $data shape (built by documentData() / sampleDocumentData() in doc_layouts.php):
//   doc      type, number, issue_date, supply_date, due_date, branch, cashier, invoice_ref, invoice_type, status
//   clinic   name, address, phone, email, website, trn, dha        branch   id, name
//   patient  name, name_ar, mrn, phone, eid_last4                   insurance company, policy, member, approval
//   visit    doctor, department, visit_date
//   lines    [no, code, description, sub, practitioner, qty, unit_price, discount, taxable, vat_rate, vat_amount, total]
//   totals   subtotal, discount, taxable, vat, total, paid, balance  vat_summary [rate, taxable, vat]
//   payments [method, reference, amount]   allocations [number, amount]   amount_in_words {en, ar}   flags {insurance, has_discount, has_balance}
require_once __DIR__ . '/doc_layout_schema.php';

const DOC_TITLES = ['invoice' => ['Tax Invoice', 'فاتورة ضريبية'], 'credit_note' => ['Tax Credit Note', 'إشعار دائن ضريبي'], 'receipt' => ['Receipt', 'إيصال']];
const DOC_STAMPS = ['PAID' => 'مدفوعة', 'VOID' => 'ملغاة', 'DRAFT' => 'مسودة', 'COPY' => 'نسخة'];

function renderDocument(array $L, array $d, ?string $lang = null, array $assets = [], ?string $stamp = null): string {
    $lang = $lang ?: ($L['page']['language'] ?? 'both');
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $pair = function (string $en, string $ar) use ($lang) { return $lang === 'en' || $ar === '' ? $en : ($lang === 'ar' ? $ar : ($en === $ar ? $en : "$en / $ar")); };
    $lbl = fn(string $k) => $pair(FIELD_LABELS[$k][0] ?? $k, FIELD_LABELS[$k][1] ?? '');
    $money = fn($v) => number_format((float)$v, 2);
    $date = fn($v) => $v ? date('d M Y', strtotime((string)$v)) : '';
    $merge = function (string $html) use ($d, $e, $money, $date) {
        $vals = ['invoice.number' => $d['doc']['number'] ?? '', 'invoice.date' => $date($d['doc']['issue_date'] ?? ''), 'invoice.total' => $money($d['totals']['total'] ?? 0),
                 'invoice.balance' => $money($d['totals']['balance'] ?? 0), 'patient.name' => $d['patient']['name'] ?? '', 'patient.mrn' => $d['patient']['mrn'] ?? '',
                 'clinic.name' => $d['clinic']['name'] ?? '', 'clinic.trn' => $d['clinic']['trn'] ?? '', 'clinic.phone' => $d['clinic']['phone'] ?? '',
                 'clinic.email' => $d['clinic']['email'] ?? '', 'branch.name' => $d['branch']['name'] ?? '', 'doc.cashier' => $d['doc']['cashier'] ?? ''];
        return preg_replace_callback('/\{\{\s*([a-z.]+)\s*\}\}/', fn($m) => $e($vals[$m[1]] ?? ''), $html);
    };
    $text = function (array $b) use ($lang, $merge) {
        $en = $b['text']['en'] ?? ''; $ar = $b['text']['ar'] ?? '';
        if ($lang === 'en' || $ar === '') return $merge($en);
        if ($lang === 'ar' || $en === '') return '<div lang="ar" dir="rtl">' . $merge($ar) . '</div>';
        return $merge($en) . '<div lang="ar" dir="rtl" class="ar2">' . $merge($ar) . '</div>';
    };
    $shown = function (array $b) use ($d, $lang) {
        $c = $b['cond'] ?? '';
        $f = $d['flags'] ?? [];
        return match (true) {
            $c === '' => true, $c === 'insurance' => !empty($f['insurance']), $c === 'cash' => empty($f['insurance']),
            $c === 'has_discount' => !empty($f['has_discount']), $c === 'has_balance' => !empty($f['has_balance']),
            $c === 'lang_en' => $lang !== 'ar', $c === 'lang_ar' => $lang !== 'en',
            str_starts_with($c, 'branch:') => (int)substr($c, 7) === (int)($d['branch']['id'] ?? 0), default => false,
        };
    };
    $kv = function (array $rows) use ($e) {   // [label, value] pairs as a small two-column table
        $rows = array_filter($rows, fn($r) => (string)$r[1] !== '');
        return $rows ? '<table class="kv">' . implode('', array_map(fn($r) => '<tr><th>' . $r[0] . '</th><td>' . $e($r[1]) . '</td></tr>', $rows)) . '</table>' : '';
    };
    $pageNo = [];

    $block = function (array $b) use (&$pageNo, $d, $e, $lang, $pair, $lbl, $money, $date, $text, $kv, $assets) {
        $s = $b['show'] ?? [];
        switch ($b['type']) {
            case 'logo': case 'image':
                return !empty($b['asset']) && isset($assets[$b['asset']]) ? '<img src="' . $e($assets[$b['asset']]) . '" alt="" style="height:' . (int)$b['height'] . 'mm">' : '';
            case 'signature':
                return (!empty($b['asset']) && isset($assets[$b['asset']]) ? '<img src="' . $e($assets[$b['asset']]) . '" alt="" style="height:' . (int)$b['height'] . 'mm"><br>' : '<div style="height:' . (int)$b['height'] . 'mm"></div>')
                     . '<div class="sigline">' . $text($b) . '</div>';
            case 'clinic':
                $c = $d['clinic'] ?? [];
                $h = !empty($s['name']) ? '<div class="cname">' . $e($c['name'] ?? '') . '</div>' : '';
                foreach (['address', 'phone', 'email', 'website'] as $k) if (!empty($s[$k]) && ($c[$k] ?? '') !== '') $h .= '<div>' . nl2br($e($c[$k])) . '</div>';
                foreach (['trn', 'dha'] as $k) if (!empty($s[$k])) $h .= '<div><strong>' . $lbl($k) . ':</strong> ' . $e(($c[$k] ?? '') ?: '—') . '</div>';
                return $h;
            case 'title':
                return '<div class="dtitle">' . $text($b) . '</div>';
            case 'details':
                $x = $d['doc'] ?? [];
                $rows = [];
                foreach (['number' => $x['number'] ?? '', 'issue_date' => $date($x['issue_date'] ?? ''), 'supply_date' => $date($x['supply_date'] ?? ''), 'due_date' => $date($x['due_date'] ?? ''),
                          'branch' => $d['branch']['name'] ?? '', 'cashier' => $x['cashier'] ?? '', 'invoice_ref' => $x['invoice_ref'] ?? '', 'invoice_type' => $x['invoice_type'] ?? ''] as $k => $v)
                    if (!empty($s[$k])) $rows[] = [$lbl($k), $v];
                return $kv($rows);
            case 'patient':
                $p = $d['patient'] ?? [];
                $rows = [];
                if (!empty($s['name'])) $rows[] = [$lbl('name'), $p['name'] ?? ''];
                if (!empty($s['name_ar']) && $lang !== 'en') $rows[] = [$lbl('name_ar'), $p['name_ar'] ?? ''];
                if (!empty($s['mrn'])) $rows[] = [$lbl('mrn'), $p['mrn'] ?? ''];
                if (!empty($s['phone'])) $rows[] = [$lbl('phone'), $p['phone'] ?? ''];
                if (!empty($s['eid']) && !empty($p['eid_last4'])) $rows[] = [$lbl('eid'), '784-••••-•••' . substr($p['eid_last4'], 0, 3) . '-' . substr($p['eid_last4'], 3)];
                return $kv($rows);
            case 'insurance':
                $i = $d['insurance'] ?? [];
                return $kv(array_map(fn($k) => [$lbl($k), $i[$k] ?? ''], array_keys(array_filter($s))));
            case 'visit':
                $v = $d['visit'] ?? [];
                return $kv(array_map(fn($k) => [$lbl($k), $k === 'visit_date' ? $date($v[$k] ?? '') : ($v[$k] ?? '')], array_keys(array_filter($s))));
            case 'lines':
                $cols = array_values(array_filter($b['columns'] ?? [], fn($c) => !empty($c['on'])));
                if (!$cols) return '';
                $h = '<table class="lines' . (!empty($b['stripe']) ? ' stripe' : '') . (!empty($b['borders']) ? ' borders' : '') . '"><thead><tr>';
                foreach ($cols as $c) $h .= '<th style="width:' . (int)$c['w'] . '%;text-align:' . $c['align'] . '">' . $e($pair($c['label']['en'], $c['label']['ar'])) . '</th>';
                $h .= '</tr></thead><tbody>';
                foreach ($d['lines'] ?? [] as $l) {
                    $h .= '<tr>';
                    foreach ($cols as $c) {
                        $k = $c['key'];
                        $v = match ($k) {
                            'no' => $e($l['no'] ?? ''), 'description' => $e($l['description'] ?? '') . (($l['sub'] ?? '') !== '' ? '<div class="sub">' . $e($l['sub']) . '</div>' : ''),
                            'qty' => $e(rtrim(rtrim(number_format((float)($l['qty'] ?? 0), 2, '.', ''), '0'), '.')), 'vat_rate' => $e(rtrim(rtrim(number_format((float)($l['vat_rate'] ?? 0), 2, '.', ''), '0'), '.') . '%'),
                            'unit_price', 'discount', 'taxable', 'vat_amount', 'total' => $e($money($l[$k] ?? 0)),
                            default => $e($l[$k] ?? ''),
                        };
                        $h .= '<td style="text-align:' . $c['align'] . '">' . $v . '</td>';
                    }
                    $h .= '</tr>';
                }
                return $h . '</tbody></table>';
            case 'totals':
                $t = $d['totals'] ?? [];
                $h = '<table class="totals">';
                foreach (['subtotal', 'discount', 'taxable', 'vat', 'total', 'paid', 'balance'] as $k) {
                    if (empty($s[$k]) || ($k === 'discount' && !(float)($t['discount'] ?? 0))) continue;
                    $h .= '<tr class="t-' . $k . '"><th>' . $lbl($k) . '</th><td>' . ($k === 'discount' ? '−' : '') . $e($money($t[$k] ?? 0)) . '</td></tr>';
                }
                return $h . '</table>';
            case 'vat_summary':
                if (empty($d['vat_summary'])) return '';
                $h = '<table class="lines borders"><thead><tr><th>' . $lbl('vat') . ' %</th><th style="text-align:end">' . $lbl('taxable') . '</th><th style="text-align:end">' . $lbl('vat') . '</th></tr></thead><tbody>';
                foreach ($d['vat_summary'] as $v) $h .= '<tr><td>' . $e(rtrim(rtrim(number_format((float)$v['rate'], 2, '.', ''), '0'), '.')) . '%</td><td style="text-align:end">' . $e($money($v['taxable'])) . '</td><td style="text-align:end">' . $e($money($v['vat'])) . '</td></tr>';
                return $h . '</tbody></table>';
            case 'words':
                $w = $d['amount_in_words'] ?? [];
                return (!empty($s['en']) && $lang !== 'ar' ? '<div>' . $e($w['en'] ?? '') . '</div>' : '') . (!empty($s['ar']) && $lang !== 'en' ? '<div lang="ar" dir="rtl">' . $e($w['ar'] ?? '') . '</div>' : '');
            case 'payments':
                $h = '';
                if (!empty($s['methods']) && !empty($d['payments'])) {
                    $h .= '<table class="lines borders"><thead><tr><th>' . $lbl('methods') . '</th><th>' . $pair('Reference', 'المرجع') . '</th><th style="text-align:end">' . $pair('Amount', 'المبلغ') . '</th></tr></thead><tbody>';
                    foreach ($d['payments'] as $p) $h .= '<tr><td>' . $e($p['method']) . '</td><td>' . $e($p['reference'] ?? '') . '</td><td style="text-align:end">' . $e($money($p['amount'])) . '</td></tr>';
                    $h .= '</tbody></table>';
                }
                if (!empty($s['allocations']) && !empty($d['allocations'])) {
                    $h .= '<table class="lines borders" style="margin-top:2mm"><thead><tr><th>' . $lbl('allocations') . '</th><th style="text-align:end">' . $pair('Amount', 'المبلغ') . '</th></tr></thead><tbody>';
                    foreach ($d['allocations'] as $a) $h .= '<tr><td>' . $e($a['number']) . '</td><td style="text-align:end">' . $e($money($a['amount'])) . '</td></tr>';
                    $h .= '</tbody></table>';
                }
                return $h;
            case 'notes': case 'bank': case 'footer_text': case 'text':
                return $text($b);
            case 'page_number':
                $pageNo[] = $b['style']['align'] ?? 'center';
                return '<span class="pgno">' . $e($pair('Page 1', 'صفحة ١')) . '</span>';
            case 'divider': return '<hr>';
            case 'spacer': return '<div style="height:' . (int)$b['height'] . 'mm"></div>';
        }
        return '';
    };
    $rows = function (array $rows) use ($block, $shown, $e) {
        $h = '';
        foreach ($rows as $r) {
            $cells = ''; $used = 0;
            foreach ($r['blocks'] as $b) {
                $used += $b['w'];
                $st = $b['style'] ?? [];
                $css = 'width:' . round($b['w'] / 12 * 100, 3) . '%;text-align:' . ($st['align'] ?? 'start') . ';';
                if (!empty($st['font'])) $css .= "font-family:'" . $st['font'] . "',sans-serif;";
                if (!empty($st['size'])) $css .= 'font-size:' . (int)$st['size'] . 'pt;';
                if (($st['weight'] ?? '') === 'bold') $css .= 'font-weight:700;';
                if (!empty($st['color'])) $css .= 'color:' . $st['color'] . ';';
                if (!empty($st['bg'])) $css .= 'background:' . $st['bg'] . ';';
                if (!empty($st['pad'])) $css .= 'padding:' . (int)$st['pad'] . 'mm;';
                $border = ['box' => 'border:1px solid #bbb;', 'top' => 'border-top:1px solid #bbb;', 'bottom' => 'border-bottom:1px solid #bbb;'][$st['border'] ?? 'none'] ?? '';
                $cells .= '<td class="b b-' . $b['type'] . '" style="' . $css . $border . '">' . ($shown($b) ? $block($b) : '') . '</td>';
            }
            if ($used < 12) $cells .= '<td style="width:' . round((12 - $used) / 12 * 100, 3) . '%"></td>';
            $h .= '<table class="row"><tr>' . $cells . '</tr></table>';
        }
        return $h;
    };
    $p = $L['page'];
    $header = $rows($L['header'] ?? []); $body = $rows($L['body'] ?? []); $footer = $rows($L['footer'] ?? []);
    [$w, $hgt] = LAYOUT_PAGE_SIZES[$p['size']];
    if ($p['orientation'] === 'landscape' && $p['size'] !== 'roll80') [$w, $hgt] = [$hgt, $w];
    $m = $p['margins'];
    $dir = $lang === 'ar' ? 'rtl' : 'ltr';
    $stampText = $stamp && $p['stamps'] ? $pair($stamp, DOC_STAMPS[$stamp] ?? '') : ($p['watermark'] !== '' ? $p['watermark'] : '');
    $pgCss = '';
    foreach (array_unique($pageNo) as $al) $pgCss .= '@page { @bottom-' . ['start' => 'left', 'center' => 'center', 'end' => 'right'][$al] . ' { content: "' . ($lang === 'ar' ? 'صفحة " counter(page) " من " counter(pages)' : 'Page " counter(page) " of " counter(pages)') . '; font-size: 8pt; color: #666; } } ';
    $pinned = $p['footer_pinned'] && $footer !== '';
    ob_start(); ?>
<!DOCTYPE html>
<html lang="<?= $lang === 'ar' ? 'ar' : 'en' ?>" dir="<?= $dir ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e(($d['doc']['number'] ?? '') ?: 'Document') ?></title>
<link rel="stylesheet" href="/assets/vendor/fonts/fonts.css">
<style>
  @page { size: <?= $w ?>mm <?= $hgt ?>mm; margin: <?= $m['t'] ?>mm <?= $m['r'] ?>mm <?= $m['b'] ?>mm <?= $m['l'] ?>mm; }
  <?= $pgCss ?>
  * { box-sizing: border-box; }
  body { margin: 0; background: #fff; color: <?= $p['color'] ?>; font: <?= $p['font_size'] ?>pt/1.35 '<?= $p['font'] ?>', 'IBM Plex Sans Arabic', Arial, sans-serif; }
  [lang="ar"], .ar2 { font-family: 'IBM Plex Sans Arabic', '<?= $p['font'] ?>', sans-serif; }
  .sheet { width: <?= $w - $m['l'] - $m['r'] ?>mm; margin: 0 auto; }
  @media screen { body { background: #eee; padding: 6mm 0; } .sheet { background: #fff; padding: <?= $m['t'] ?>mm <?= $m['r'] ?>mm <?= $m['b'] ?>mm <?= $m['l'] ?>mm; width: <?= $w ?>mm; min-height: <?= $p['size'] === 'roll80' ? 100 : $hgt ?>mm; box-shadow: 0 1px 6px rgba(0,0,0,.15); } }
  table { border-collapse: collapse; width: 100%; }
  table.doc > thead { display: table-header-group; } table.doc > tfoot { display: table-footer-group; }
  table.row { table-layout: fixed; margin-bottom: 2mm; } table.row td.b { vertical-align: top; padding: 0 1.5mm; overflow-wrap: anywhere; }
  .cname { font-size: 1.45em; font-weight: 700; }
  .dtitle { font-size: 1.6em; font-weight: 700; color: <?= $p['accent'] ?>; }
  table.kv th { text-align: start; font-weight: 600; padding: .3mm 2mm .3mm 0; white-space: nowrap; width: 1%; color: #555; } table.kv td { padding: .3mm 0; }
  table.lines { margin: 1mm 0; } table.lines thead { display: table-header-group; } table.lines tr { page-break-inside: avoid; }
  table.lines th { background: <?= $p['accent'] ?>22; border-bottom: 1.5px solid <?= $p['accent'] ?>; padding: 1.2mm 1mm; font-size: .88em; text-align: start; vertical-align: bottom; }
  table.lines td { padding: 1.2mm 1mm; vertical-align: top; } table.lines.borders td { border-bottom: .5px solid #ddd; } table.lines.stripe tbody tr:nth-child(even) td { background: #f6f3ee; }
  .sub { font-size: .85em; color: #666; }
  table.totals { width: auto; margin-inline-start: auto; } table.totals th { text-align: start; font-weight: 500; padding: .6mm 4mm .6mm 0; } table.totals td { text-align: end; padding: .6mm 0; white-space: nowrap; }
  table.totals tr.t-total th, table.totals tr.t-total td { border-top: 1.5px solid currentColor; font-weight: 700; font-size: 1.15em; padding-top: 1.2mm; }
  hr { border: 0; border-top: 1px solid <?= $p['accent'] ?>; margin: 1mm 0; }
  .sigline { border-top: .5px solid #999; padding-top: 1mm; font-size: .9em; }
  .stamp { position: fixed; top: 40%; left: 0; right: 0; text-align: center; font-size: 60pt; font-weight: 800; color: <?= $p['accent'] ?>; opacity: .13; transform: rotate(-24deg); pointer-events: none; }
  .pinned { position: fixed; bottom: 0; left: 0; right: 0; } @media screen { .pinned { position: static; } .reserve { display: none; } }
  .reserve { visibility: hidden; }
  @media print { .pgno { display: none; } .noprint { display: none; } }
</style></head>
<body><div class="sheet">
<?php if ($stampText !== ''): ?><div class="stamp"><?= $e($stampText) ?></div><?php endif; ?>
<table class="doc">
  <?php if ($header !== ''): ?><thead><tr><td><?= $header ?></td></tr></thead><?php endif; ?>
  <?php if ($footer !== ''): ?><tfoot><tr><td class="<?= $pinned ? 'reserve' : '' ?>"><?= $footer ?></td></tr></tfoot><?php endif; ?>
  <tbody><tr><td><?= $body ?></td></tr></tbody>
</table>
<?php if ($pinned): ?><div class="pinned"><?= $footer ?></div><?php endif; ?>
</div></body></html>
<?php
    return (string)ob_get_clean();
}

// ── Amount in words ──

function amountWordsEn(int $fils): string {
    $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
    $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
    $under1000 = function (int $n) use ($ones, $tens) {
        $w = [];
        if ($n >= 100) { $w[] = $ones[intdiv($n, 100)] . ' hundred'; $n %= 100; }
        if ($n >= 20) { $w[] = $tens[intdiv($n, 10)] . ($n % 10 ? '-' . $ones[$n % 10] : ''); }
        elseif ($n > 0) $w[] = $ones[$n];
        return implode(' ', $w);
    };
    $words = function (int $n) use ($under1000) {
        if ($n === 0) return 'zero';
        $w = [];
        foreach ([1000000000 => 'billion', 1000000 => 'million', 1000 => 'thousand', 1 => ''] as $unit => $name) {
            if ($n >= $unit) { $w[] = trim($under1000(intdiv($n, $unit)) . ' ' . $name); $n %= $unit; }
        }
        return implode(' ', $w);
    };
    $d = intdiv($fils, 100); $f = $fils % 100;
    return ucfirst($words($d) . ' dirham' . ($d === 1 ? '' : 's') . ($f ? ' and ' . $words($f) . ' fils' : '') . ' only');
}

function amountWordsAr(int $fils): string {
    $ones = ['', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة', 'عشرة', 'أحد عشر', 'اثنا عشر', 'ثلاثة عشر', 'أربعة عشر', 'خمسة عشر', 'ستة عشر', 'سبعة عشر', 'ثمانية عشر', 'تسعة عشر'];
    $tens = ['', '', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون'];
    $hund = ['', 'مائة', 'مائتان', 'ثلاثمائة', 'أربعمائة', 'خمسمائة', 'ستمائة', 'سبعمائة', 'ثمانمائة', 'تسعمائة'];
    $under1000 = function (int $n) use ($ones, $tens, $hund) {
        $w = [];
        if ($n >= 100) { $w[] = $hund[intdiv($n, 100)]; $n %= 100; }
        if ($n >= 20) $w[] = $n % 10 ? $ones[$n % 10] . ' و' . $tens[intdiv($n, 10)] : $tens[intdiv($n, 10)];
        elseif ($n > 0) $w[] = $ones[$n];
        return implode(' و', $w);
    };
    $scale = function (int $n, array $f) use ($under1000) {   // [one, two, 3-10 plural, 11+ singular]
        return match (true) { $n === 1 => $f[0], $n === 2 => $f[1], $n <= 10 => $under1000($n) . ' ' . $f[2], default => $under1000($n) . ' ' . $f[3] };
    };
    $words = function (int $n) use ($under1000, $scale) {
        if ($n === 0) return 'صفر';
        $w = [];
        if ($n >= 1000000) { $w[] = $scale(intdiv($n, 1000000), ['مليون', 'مليونان', 'ملايين', 'مليون']); $n %= 1000000; }
        if ($n >= 1000) { $w[] = $scale(intdiv($n, 1000), ['ألف', 'ألفان', 'آلاف', 'ألف']); $n %= 1000; }
        if ($n > 0) $w[] = $under1000($n);
        return implode(' و', $w);
    };
    $d = intdiv($fils, 100); $f = $fils % 100;
    return 'فقط ' . $words($d) . ' درهم' . ($f ? ' و' . $words($f) . ' فلس' : '') . ' لا غير';
}
