<?php
// includes/report_ui.php — one way to show every report: period and branch filters, tables (with totals), a Chart.js
// chart where useful, CSV export (opens in Excel, Arabic kept) and a print view. A page builds its sections with
// rptSection() and hands them to rptOutput(), which answers ?export=csv (all tables, or one with &s=n), ?print=1 or the
// normal page. Column types: text, int, money, pct, date, datetime, min.

/** The period (default: this month so far) and branch from the query string. */
function rptFilters(): array {
    $ok = fn($v) => is_string($v) && ($d = DateTime::createFromFormat('!Y-m-d', $v)) && $d->format('Y-m-d') === $v;
    $from = $ok($_GET['from'] ?? null) ? $_GET['from'] : date('Y-m-01');
    $to = $ok($_GET['to'] ?? null) ? $_GET['to'] : date('Y-m-d');
    if ($from > $to) [$from, $to] = [$to, $from];
    return ['from' => $from, 'to' => $to, 'branch' => max(0, (int)($_GET['branch'] ?? 0))];
}

function rptSection(string $title, array $cols, array $rows, array $opt = []): array {
    return ['title' => $title, 'cols' => $cols, 'rows' => array_values($rows)] + $opt;
}

function rptFmt($v, string $type, bool $csv = false): string {
    if ($v === null || $v === '') return $csv ? '' : '–';
    return match ($type) {
        'money' => $csv ? number_format((float)$v, 2, '.', '') : number_format((float)$v, 2),
        'int' => $csv ? (string)(int)$v : number_format((int)$v),
        'pct' => $csv ? (string)$v : $v . '%',
        'min' => $csv ? (string)$v : $v . ' min',
        'date' => substr((string)$v, 0, 10),
        'datetime' => substr((string)$v, 0, 16),
        default => (string)$v,
    };
}

/** Totals for the int and money columns of a section (null for the rest). */
function rptTotals(array $s): array {
    $t = [];
    foreach ($s['cols'] as $k => [, $type]) $t[$k] = in_array($type, ['int', 'money'], true) && !in_array($k, $s['no_total'] ?? [], true) ? array_sum(array_map(fn($r) => (float)($r[$k] ?? 0), $s['rows'])) : null;
    return $t;
}

function rptSlug(string $s): string { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-'); }

/** The current query string with changes. */
function rptQuery(array $set): string { return '?' . http_build_query(array_filter(array_merge($_GET, $set), fn($v) => $v !== null && $v !== '')); }

function rptCsv(string $title, array $f, array $sections): void {
    $only = isset($_GET['s']) ? (int)$_GET['s'] : null;
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . rptSlug($title) . "-{$f['from']}-{$f['to']}.csv\"");
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // Excel reads the file as UTF-8 (Arabic names)
    fputcsv($out, [$title, "{$f['from']} to {$f['to']}"]);
    foreach ($sections as $i => $s) {
        if ($only !== null && $only !== $i) continue;
        fputcsv($out, []); fputcsv($out, [$s['title']]);
        fputcsv($out, array_map(fn($c) => $c[0], $s['cols']));
        foreach ($s['rows'] as $r) fputcsv($out, array_map(fn($k) => rptFmt($r[$k] ?? null, $s['cols'][$k][1], true), array_keys($s['cols'])));
        if (!empty($s['total'])) { $t = rptTotals($s); $first = true;
            fputcsv($out, array_map(function ($k) use ($t, $s, &$first) { if ($first) { $first = false; return 'Total'; } return $t[$k] === null ? '' : rptFmt($t[$k], $s['cols'][$k][1], true); }, array_keys($s['cols']))); }
    }
    fclose($out);
    exit;
}

function rptTableHtml(array $s, int $i, bool $print = false): string {
    $h = '<div class="' . ($print ? 'sec' : 'card-crm mb-3') . '"><div class="' . ($print ? '' : 'card-header-crm') . '"><h2 class="' . ($print ? '' : 'h6 m-0') . '">' . sanitize($s['title']) . '</h2>';
    if (!$print) $h .= '<a class="btn btn-ghost btn-sm ms-auto" href="' . sanitize(rptQuery(['export' => 'csv', 's' => $i])) . '"><i class="bi bi-download"></i>CSV</a>';
    $h .= '</div>';
    if (!empty($s['note'])) $h .= '<p class="' . ($print ? 'note' : 'fs-13 text-tx-3 px-3 pt-2 mb-0') . '">' . sanitize($s['note']) . '</p>';
    if (!empty($s['chart']) && !$print && $s['rows']) {
        $c = $s['chart'];
        $data = ['type' => $c['type'] ?? 'bar', 'labels' => array_map(fn($r) => (string)$r[$c['label']], array_slice($s['rows'], 0, 20)),
                 'sets' => array_map(fn($k) => ['label' => $s['cols'][$k][0], 'data' => array_map(fn($r) => (float)($r[$k] ?? 0), array_slice($s['rows'], 0, 20))], (array)$c['values'])];
        $h .= '<div class="px-3 pt-3" style="height:240px"><canvas data-rpt-chart="' . sanitize(json_encode($data)) . '" role="img" aria-label="' . sanitize('Chart: ' . $s['title']) . '"></canvas></div>';
    }
    $h .= '<div class="' . ($print ? '' : 'table-responsive') . '"><table class="' . ($print ? '' : 'table table-hover mb-0 align-middle') . '"><thead><tr>';
    foreach ($s['cols'] as [$label, $type]) $h .= '<th' . (in_array($type, ['int', 'money', 'pct', 'min'], true) ? ' class="text-end"' : '') . '>' . sanitize($label) . '</th>';
    $h .= '</tr></thead><tbody>';
    foreach ($s['rows'] as $r) {
        $h .= '<tr>';
        foreach ($s['cols'] as $k => [, $type]) {
            $v = sanitize(rptFmt($r[$k] ?? null, $type));
            if (!$print && isset($s['links'][$k]) && !empty($r[$s['links'][$k][1]])) $v = '<a href="' . sanitize($s['links'][$k][0] . $r[$s['links'][$k][1]]) . '">' . $v . '</a>';
            $h .= '<td' . (in_array($type, ['int', 'money', 'pct', 'min'], true) ? ' class="text-end tabular"' : '') . ">$v</td>";
        }
        $h .= '</tr>';
    }
    if (!$s['rows']) $h .= '<tr><td colspan="' . count($s['cols']) . '" class="' . ($print ? '' : 'text-tx-3') . '">' . sanitize($s['empty'] ?? 'Nothing in this period.') . '</td></tr>';
    $h .= '</tbody>';
    if (!empty($s['total']) && $s['rows']) {
        $t = rptTotals($s); $h .= '<tfoot><tr class="fw-700">'; $first = true;
        foreach ($s['cols'] as $k => [, $type]) { $h .= '<td' . ($t[$k] !== null ? ' class="text-end tabular"' : '') . '>' . ($first ? 'Total' : ($t[$k] === null ? '' : sanitize(rptFmt($t[$k], $type)))) . '</td>'; $first = false; }
        $h .= '</tr></tfoot>';
    }
    return $h . '</table></div></div>';
}

/** Tiles: [[label, value, sub?], …] */
function rptTilesHtml(array $tiles): string {
    $h = '<div class="row g-3 mb-3">';
    foreach ($tiles as $t) $h .= '<div class="col-6 col-lg-3"><div class="card-crm h-100"><div class="card-body-crm"><div class="fs-12 text-tx-3 text-uppercase fw-600">' . sanitize($t[0])
        . '</div><div class="fs-4 fw-700 tabular">' . sanitize((string)$t[1]) . '</div>' . (!empty($t[2]) ? '<div class="fs-12 text-tx-3">' . sanitize($t[2]) . '</div>' : '') . '</div></div></div>';
    return $h . '</div>';
}

/**
 * Shows the report. $opt: subtitle, tiles, tabs ([key => label], current $_GET['r']), extra_filters (HTML), before (HTML,
 * e.g. forms), after (HTML), no_period (true for "as of today" reports).
 */
function rptOutput(string $title, array $f, array $sections, array $opt = []): void {
    global $pageTitle, $pageSubtitle, $pageActions;
    if (($_GET['export'] ?? '') === 'csv') rptCsv($title, $f, $sections);
    $db = getDB();
    $branches = branchList($db);
    $period = !empty($opt['no_period']) ? 'As of ' . date('d M Y') : date('d M Y', strtotime($f['from'])) . ' – ' . date('d M Y', strtotime($f['to']));
    $where = $f['branch'] && isset($branches[$f['branch']]) ? $branches[$f['branch']]['name'] : 'All branches';
    if (!empty($_GET['print'])) {
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . sanitize($title) . '</title><style>body{font:12px/1.4 system-ui,Segoe UI,Arial,sans-serif;color:#111;margin:24px}'
           . 'h1{font-size:18px;margin:0}h2{font-size:14px;margin:18px 0 6px}.meta{color:#555;margin:4px 0 12px}table{width:100%;border-collapse:collapse;margin-bottom:8px}th,td{border:1px solid #bbb;padding:4px 6px;text-align:left}'
           . 'th{background:#f1f1f1}.text-end{text-align:right}tfoot td{font-weight:700}.note{color:#555;margin:0 0 6px}.tiles{display:flex;flex-wrap:wrap;gap:8px}.tile{border:1px solid #bbb;padding:6px 10px;min-width:140px}'
           . '@media print{.noprint{display:none}}</style></head><body><button class="noprint" onclick="print()">Print</button><h1>' . sanitize(clinicName() . ' — ' . $title) . '</h1>'
           . '<div class="meta">' . sanitize("$period · $where · printed " . date('d M Y H:i') . ' by ' . ($_SESSION['staff_name'] ?? '')) . '</div>';
        if (!empty($opt['tiles'])) { echo '<div class="tiles">'; foreach ($opt['tiles'] as $t) echo '<div class="tile">' . sanitize($t[0]) . '<br><b>' . sanitize((string)$t[1]) . '</b></div>'; echo '</div>'; }
        foreach ($sections as $i => $s) echo rptTableHtml($s, $i, true);
        echo '<script>window.addEventListener("load", function () { print(); });</script></body></html>';
        exit;
    }
    $pageTitle = $title;
    $pageSubtitle = $opt['subtitle'] ?? "$period · $where";
    $pageActions = '<a class="btn btn-ghost" href="' . sanitize(rptQuery(['export' => 'csv', 's' => null])) . '"><i class="bi bi-download"></i>Export</a>'
                 . '<a class="btn btn-ghost" target="_blank" rel="noopener" href="' . sanitize(rptQuery(['print' => 1])) . '"><i class="bi bi-printer"></i>Print</a>';
    require __DIR__ . '/header.php';
    if (!empty($opt['tabs'])) {
        echo '<ul class="nav nav-tabs mb-3">';
        foreach ($opt['tabs'] as $k => $l) echo '<li class="nav-item"><a class="nav-link' . (($_GET['r'] ?? array_key_first($opt['tabs'])) === $k ? ' active" aria-current="page' : '') . '" href="' . sanitize(rptQuery(['r' => $k, 's' => null])) . '">' . sanitize($l) . '</a></li>';
        echo '</ul>';
    }
    echo '<form class="card-crm page-section mb-3" method="GET"><div class="card-body-crm d-flex flex-wrap gap-2 align-items-end">';
    if (isset($_GET['r'])) echo '<input type="hidden" name="r" value="' . sanitize((string)$_GET['r']) . '">';
    if (empty($opt['no_period'])) {
        echo '<div><label class="form-label" for="rf">From</label><input class="form-control" type="date" id="rf" name="from" value="' . $f['from'] . '"></div>'
           . '<div><label class="form-label" for="rt">To</label><input class="form-control" type="date" id="rt" name="to" value="' . $f['to'] . '"></div>';
    }
    if (count($branches) > 1) {
        echo '<div><label class="form-label" for="rb">Branch</label><select class="form-select" id="rb" name="branch"><option value="">All branches</option>';
        foreach ($branches as $b) echo '<option value="' . (int)$b['id'] . '"' . ($f['branch'] === (int)$b['id'] ? ' selected' : '') . '>' . sanitize($b['name']) . '</option>';
        echo '</select></div>';
    }
    echo ($opt['extra_filters'] ?? '') . '<button class="btn btn-gold">Show</button>';
    if (empty($opt['no_period'])) {
        $q = fn($a, $b) => sanitize(rptQuery(['from' => $a, 'to' => $b]));
        echo '<div class="ms-auto d-flex gap-1 flex-wrap"><a class="btn btn-ghost btn-sm" href="' . $q(date('Y-m-d'), date('Y-m-d')) . '">Today</a>'
           . '<a class="btn btn-ghost btn-sm" href="' . $q(date('Y-m-01'), date('Y-m-d')) . '">This month</a>'
           . '<a class="btn btn-ghost btn-sm" href="' . $q(date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))) . '">Last month</a>'
           . '<a class="btn btn-ghost btn-sm" href="' . $q(date('Y-01-01'), date('Y-m-d')) . '">This year</a></div>';
    }
    echo '</div></form>';
    echo $opt['before'] ?? '';
    if (!empty($opt['tiles'])) echo rptTilesHtml($opt['tiles']);
    echo '<div class="row g-3"><div class="col-12">';
    foreach ($sections as $i => $s) echo !empty($s['half']) ? '</div><div class="col-xl-6">' . rptTableHtml($s, $i) . '</div><div class="col-12">' : rptTableHtml($s, $i);
    echo '</div></div>' . ($opt['after'] ?? '');
    echo <<<'JS'
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (!window.Chart) return;
  var css = getComputedStyle(document.documentElement), gold = (css.getPropertyValue('--gold') || '#b08d57').trim();
  var palette = [gold, '#6b8f71', '#5b7fa6', '#b5651d', '#8c6bb1', '#c44d58', '#4f9da6', '#9e9e9e'];
  document.querySelectorAll('canvas[data-rpt-chart]').forEach(function (c) {
    var d = JSON.parse(c.dataset.rptChart), pie = d.type === 'doughnut' || d.type === 'pie';
    new Chart(c, { type: d.type, data: { labels: d.labels, datasets: d.sets.map(function (s, i) {
      return { label: s.label, data: s.data, backgroundColor: pie ? palette : palette[i % palette.length], borderColor: pie ? '#fff' : palette[i % palette.length] }; }) },
      options: { maintainAspectRatio: false, plugins: { legend: { display: pie || d.sets.length > 1, position: 'bottom' } } } });
  });
});
</script>
JS;
    require __DIR__ . '/footer.php';
    exit;
}
