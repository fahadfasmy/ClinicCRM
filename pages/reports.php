<?php
// pages/reports.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db = getDB();

// ── Check optional columns ──
$hasFuColumns = false;
try { $db->query("SELECT followup_1_done FROM leads LIMIT 1"); $hasFuColumns = true; } catch (PDOException $e) {}

$hasConvertedAt = false;
try { $db->query("SELECT converted_at FROM leads LIMIT 1"); $hasConvertedAt = true; } catch (PDOException $e) {}

// ── Parameters ──
$type   = in_array($_GET['type'] ?? '', ['daily','monthly','custom']) ? $_GET['type'] : 'monthly';
// A real calendar date (YYYY-MM-DD), or the fallback
$validDay = fn($v, string $else) => is_string($v) && ($d = DateTime::createFromFormat('!Y-m-d', $v)) && $d->format('Y-m-d') === $v ? $v : $else;
$month  = substr($validDay(($_GET['month'] ?? '') . '-01', date('Y-m-01')), 0, 7);
$day    = $validDay($_GET['day'] ?? '', date('Y-m-d'));
$source = $_GET['source'] ?? '';
$stage  = in_array($_GET['stage'] ?? '', ['','new','contacted','qualified','booked','converted','lost','cold'])
          ? ($_GET['stage'] ?? '') : '';

$from   = $validDay($_GET['from'] ?? '', date('Y-m-01'));
$to     = $validDay($_GET['to'] ?? '', date('Y-m-d'));
if ($from > $to) [$from, $to] = [$to, $from];

// Every report type is a date range [$rangeFrom, $rangeTo], both days included
if ($type === 'daily') {
    [$rangeFrom, $rangeTo] = [$day, $day];
    $label = 'Daily Report — ' . date('d M Y', strtotime($day));
} elseif ($type === 'custom') {
    [$rangeFrom, $rangeTo] = [$from, $to];
    $label = 'Report — ' . ($from === $to ? date('d M Y', strtotime($from)) : date('d M Y', strtotime($from)) . ' to ' . date('d M Y', strtotime($to)));
} else {
    [$rangeFrom, $rangeTo] = [$month . '-01', date('Y-m-t', strtotime($month . '-01'))];
    $label = 'Monthly Report — ' . date('F Y', strtotime($month . '-01'));
}

// ── Source list for filter ──
$sources = [];
try {
    $sources = $db->query("SELECT slug, name, icon FROM lead_sources WHERE is_active=1 ORDER BY sort_order")->fetchAll();
} catch (PDOException $e) {
    try {
        $slugs = $db->query("SELECT DISTINCT source FROM leads WHERE source IS NOT NULL ORDER BY source")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($slugs as $slug) {
            $sources[] = ['slug'=>$slug,'name'=>ucfirst(str_replace('_',' ',$slug)),'icon'=>'📌'];
        }
    } catch (PDOException $e2) {}
}

// ── Build WHERE for main leads table (filtered by CREATED date) ──
// Two separate WHERE clauses:
// 1. $whereSQL         — includes stage/source filters (for the table)
// 2. $whereTotalSQL    — only date + staff filter (for the "Leads Created" KPI — never affected by stage filter)
$where      = [];
$whereTotal = [];  // no stage, no source filter
$params      = [];
$paramsTotal = [];

$where[]      = 'DATE(l.created_at) BETWEEN ? AND ?'; array_push($params, $rangeFrom, $rangeTo);
$whereTotal[] = 'DATE(l.created_at) BETWEEN ? AND ?'; array_push($paramsTotal, $rangeFrom, $rangeTo);

if ($source !== '') { $where[] = 'l.source COLLATE utf8mb4_general_ci = ?'; $params[] = $source; }
if ($stage  !== '') { $where[] = 'l.stage  COLLATE utf8mb4_general_ci = ?'; $params[] = $stage; }
if (!can('leads.all'))     {
    $where[]       = 'l.assigned_to = ?'; $params[]       = currentStaffId();
    $whereTotal[]    = 'l.assigned_to = ?'; $paramsTotal[]   = currentStaffId();
}

$whereSQL      = 'WHERE ' . implode(' AND ', $where);
$whereTotalSQL = 'WHERE ' . implode(' AND ', $whereTotal);

// ── Optional SELECT columns ──
$fuCols        = $hasFuColumns   ? ', l.followup_1_done, l.followup_2_done, l.followup_3_done' : '';
$convAtCol     = $hasConvertedAt ? ', l.converted_at' : '';
$fuGroupBy     = $hasFuColumns   ? ', l.followup_1_done, l.followup_2_done, l.followup_3_done' : '';
$convAtGroupBy = $hasConvertedAt ? ', l.converted_at' : '';

// ── Fetch leads (respects all filters including stage — for the table) ──
$leads      = [];
$queryError = '';
try {
    $stmt = $db->prepare("
        SELECT
            l.id, l.name, l.phone, l.email,
            l.source, l.stage, l.score,
            l.created_at
            {$fuCols}
            {$convAtCol},
            s.name AS assigned_staff,
            GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ') AS treatments
        FROM leads l
        LEFT JOIN staff s ON s.id = l.assigned_to
        LEFT JOIN lead_treatments lt ON lt.lead_id = l.id
        LEFT JOIN treatments t ON t.id = lt.treatment_id
        {$whereSQL}
        GROUP BY
            l.id, l.name, l.phone, l.email,
            l.source, l.stage, l.score,
            l.created_at
            {$fuGroupBy}
            {$convAtGroupBy},
            s.name
        ORDER BY l.created_at DESC
    ");
    $stmt->execute($params);
    $leads = $stmt->fetchAll();
} catch (PDOException $e) {
    $queryError = $e->getMessage();
}

// ── Total leads created this period — IGNORES stage/source filter ──
// This is what shows in the "Leads Created" KPI box and never changes with stage filter
$totalLeadsCreated = 0;
try {
    $totalStmt = $db->prepare("SELECT COUNT(DISTINCT l.id) FROM leads l {$whereTotalSQL}");
    $totalStmt->execute($paramsTotal);
    $totalLeadsCreated = (int)$totalStmt->fetchColumn();
} catch (PDOException $e) {
    $totalLeadsCreated = count($leads); // fallback
}

// ── Conversion count: leads converted IN this period ──
// Fix: also check leads where converted_at IS NULL but stage=converted
// and created_at falls in the period (handles leads converted before schema update)
$convertedThisPeriod = 0;
try {
    $mine = !can('leads.all') ? ' AND assigned_to = ' . (int)currentStaffId() : '';
    if ($hasConvertedAt) {
        $convStmt = $db->prepare("
            SELECT COUNT(*) FROM leads
            WHERE stage = 'converted'
            AND (
                DATE(converted_at) BETWEEN ? AND ?
                OR (converted_at IS NULL AND DATE(updated_at) BETWEEN ? AND ?)
            )$mine
        ");
        $convStmt->execute([$rangeFrom, $rangeTo, $rangeFrom, $rangeTo]);
    } else {
        // No converted_at column: use the last update of converted leads
        $convStmt = $db->prepare("SELECT COUNT(*) FROM leads WHERE stage='converted' AND DATE(updated_at) BETWEEN ? AND ?$mine");
        $convStmt->execute([$rangeFrom, $rangeTo]);
    }
    $convertedThisPeriod = (int)$convStmt->fetchColumn();
} catch (PDOException $e) {
    $convertedThisPeriod = count(array_filter($leads, fn($r) => $r['stage'] === 'converted'));
}

// ── Summary stats ──
$totalLeads = count($leads);           // filtered set (for table display)
$booked     = count(array_filter($leads, fn($r) => $r['stage'] === 'booked'));
$lost       = count(array_filter($leads, fn($r) => $r['stage'] === 'lost'));
// Conv rate = conversions this period ÷ total created this period (unfiltered)
$convRate   = $totalLeadsCreated > 0 ? round($convertedThisPeriod / $totalLeadsCreated * 100, 1) : 0;

// ── Source breakdown (from displayed leads) ──
$bySource = [];
foreach ($leads as $l) {
    $src = $l['source'] ?? 'other';
    $bySource[$src] = ($bySource[$src] ?? 0) + 1;
}
arsort($bySource);

// ── Total column count (for empty state colspan) ──
$totalCols = 10 + ($hasFuColumns ? 1 : 0) + ($hasConvertedAt ? 1 : 0);

// ── Querystring helper ──
$qs = http_build_query(['type'=>$type,'month'=>$month,'day'=>$day,'from'=>$from,'to'=>$to,'source'=>$source,'stage'=>$stage]);

// ── CSV Export ──
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="leads_' . $type . '_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    $hdr = ['#','Name','Phone','Email','Treatment(s)','Source','Stage','Score','Assigned To','Date Added'];
    if ($hasConvertedAt) $hdr[] = 'Converted On';
    if ($hasFuColumns)  { $hdr[] = 'FU 1'; $hdr[] = 'FU 2'; $hdr[] = 'FU 3'; }
    fputcsv($out, $hdr);
    foreach ($leads as $i => $l) {
        $row = [
            $i+1, $l['name'], $l['phone'], $l['email']??'',
            $l['treatments']??'', $l['source'], ucfirst($l['stage']),
            $l['score'], $l['assigned_staff']??'',
            date('d M Y', strtotime($l['created_at'])),
        ];
        if ($hasConvertedAt) {
            $row[] = !empty($l['converted_at']) ? date('d M Y', strtotime($l['converted_at'])) : '—';
        }
        if ($hasFuColumns) {
            $row[] = !empty($l['followup_1_done']) ? 'Done' : 'Pending';
            $row[] = !empty($l['followup_2_done']) ? 'Done' : 'Pending';
            $row[] = !empty($l['followup_3_done']) ? 'Done' : 'Pending';
        }
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

// ── Print mode ──
if (isset($_GET['print'])) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= sanitize($label) ?></title>
<link href="/assets/vendor/fonts/fonts.css" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Outfit',sans-serif;font-size:11.5px;color:#111;background:#fff;padding:20px}
.no-print{margin-bottom:14px}
.hdr{display:flex;justify-content:space-between;margin-bottom:16px;padding-bottom:10px;border-bottom:2px solid #C9956C}
.clinic{font-size:20px;font-weight:700;color:#C9956C}
.rtitle{font-size:14px;font-weight:600;margin-top:3px}
.rmeta{font-size:11px;color:#777;margin-top:2px}
.stats{display:flex;gap:12px;margin-bottom:14px}
.sbox{border:1px solid #e5e5e5;border-radius:6px;padding:8px 14px;flex:1}
.sbox .v{font-size:19px;font-weight:700}
.sbox .l{font-size:10px;color:#777;text-transform:uppercase;letter-spacing:.3px}
.note{background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:8px 12px;font-size:11px;color:#92400e;margin-bottom:14px}
.chips{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px}
.chip{padding:2px 10px;background:#f4f3f0;border-radius:20px;font-size:11px;border:1px solid #e5e5e5}
table{width:100%;border-collapse:collapse;font-size:11px}
thead th{background:#f4f4f4;padding:6px 7px;text-align:left;font-weight:600;font-size:10px;text-transform:uppercase;letter-spacing:.3px;border-bottom:1px solid #ddd}
tbody td{padding:5px 7px;border-bottom:1px solid #eee;vertical-align:top}
tbody tr:nth-child(even){background:#fafafa}
.s-converted{color:#16a34a;font-weight:600}
.s-booked{color:#d97706;font-weight:600}
.s-lost{color:#dc2626}
.s-new,.s-cold,.s-contacted,.s-qualified{color:#555}
.fu{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:2px;vertical-align:middle}
.fu-y{background:#16a34a}.fu-n{background:#d1d5db;border:1px solid #9ca3af}
.converted-date{font-size:10px;color:#16a34a;font-style:italic}
.foot{font-size:10px;color:#aaa;border-top:1px solid #eee;padding-top:8px;margin-top:10px}
@media print{.no-print{display:none!important}@page{margin:12mm;size:A4 landscape}}
</style>
</head>
<body>
<div class="no-print">
  <button onclick="window.print()" style="background:#C9956C;color:#fff;border:none;padding:8px 20px;border-radius:6px;font-size:13px;cursor:pointer;margin-right:10px;">
    🖨 Print / Save as PDF
  </button>
  <a href="javascript:history.back()" style="font-size:12px;color:#666;text-decoration:none;">← Back</a>
</div>

<div class="hdr">
  <div>
    <div class="clinic">Clinic CRM</div>
    <div class="rtitle"><?= sanitize($label) ?></div>
    <?php if ($source||$stage): ?>
    <div class="rmeta">
      <?= $source ? 'Source: '.sanitize($source) : '' ?>
      <?= ($source&&$stage) ? ' · ' : '' ?>
      <?= $stage ? 'Stage: '.ucfirst($stage) : '' ?>
    </div>
    <?php endif; ?>
  </div>
  <div style="text-align:right">
    <div class="rmeta">Generated: <?= date('d M Y, h:i A') ?></div>
    <div class="rmeta">By: <?= sanitize(staffName()) ?></div>
    <div class="rmeta"><?= $totalLeads ?> leads in period</div>
  </div>
</div>

<!-- Important note about how conversion is counted -->
<div class="note">
  ℹ️ <strong>How this report works:</strong>
  All <?= $totalLeadsCreated ?> leads shown were <strong>created</strong> during <?= sanitize($label) ?>.
  The conversion count (<?= $convertedThisPeriod ?>) reflects leads that were
  <strong>converted during this same period</strong>
  <?= $hasConvertedAt ? '(based on actual conversion date)' : '(approximate — run the schema update for exact dates)' ?>.
</div>

<div class="stats">
  <div class="sbox"><div class="v"><?=$totalLeadsCreated?></div><div class="l">Leads Created</div></div>
  <div class="sbox"><div class="v" style="color:#16a34a"><?=$convertedThisPeriod?></div><div class="l">Converted This Period</div></div>
  <div class="sbox"><div class="v" style="color:#d97706"><?=$booked?></div><div class="l">Currently Booked</div></div>
  <div class="sbox"><div class="v" style="color:#dc2626"><?=$lost?></div><div class="l">Lost</div></div>
  <div class="sbox"><div class="v" style="color:#7c3aed"><?=$convRate?>%</div><div class="l">Conv. Rate</div></div>
</div>

<?php if (!empty($bySource)): ?>
<div class="chips">
  <?php foreach ($bySource as $src=>$cnt): ?>
  <span class="chip"><?= sanitize(str_replace('_',' ',$src)) ?>: <strong><?=$cnt?></strong></span>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<table>
  <thead>
    <tr>
      <th>#</th><th>Name</th><th>Phone</th><th>Email</th>
      <th>Treatment(s)</th><th>Source</th><th>Stage</th>
      <?php if ($hasConvertedAt): ?><th>Converted On</th><?php endif; ?>
      <?php if ($hasFuColumns): ?><th>FU</th><?php endif; ?>
      <th>Score</th><th>Assigned</th><th>Created</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($leads)): ?>
    <tr><td colspan="<?=$totalCols?>" style="text-align:center;color:#999;padding:16px">No leads found.</td></tr>
  <?php endif; ?>
  <?php foreach ($leads as $i=>$l): ?>
  <tr>
    <td style="color:#aaa"><?=$i+1?></td>
    <td><strong><?=sanitize($l['name'])?></strong></td>
    <td><?=sanitize($l['phone'])?></td>
    <td><?=sanitize($l['email']??'—')?></td>
    <td><?=sanitize($l['treatments']??'—')?></td>
    <td><?=sanitize(str_replace('_',' ',$l['source']))?></td>
    <td class="s-<?=$l['stage']?>"><?=ucfirst($l['stage'])?></td>
    <?php if ($hasConvertedAt): ?>
    <td class="converted-date">
      <?= !empty($l['converted_at']) ? date('d M Y', strtotime($l['converted_at'])) : '—' ?>
    </td>
    <?php endif; ?>
    <?php if ($hasFuColumns): ?>
    <td>
      <?php for($f=1;$f<=3;$f++): ?>
      <span class="fu <?=!empty($l["followup_{$f}_done"])?'fu-y':'fu-n'?>"></span>
      <?php endfor; ?>
    </td>
    <?php endif; ?>
    <td><?=(int)$l['score']?></td>
    <td><?=sanitize($l['assigned_staff']??'—')?></td>
    <td><?=date('d M Y',strtotime($l['created_at']))?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<div class="foot"><?=$totalLeadsCreated?> leads created · <?=$convertedThisPeriod?> converted this period · <?=sanitize($label)?> · <?=date('d M Y H:i')?></div>
</body></html>
<?php exit; }

// ── Normal page ──
$periodQs = function (array $over) use ($type, $month, $day, $from, $to, $source, $stage) {
    return '?' . http_build_query(array_merge(['type' => $type, 'month' => $month, 'day' => $day, 'from' => $from, 'to' => $to, 'source' => $source, 'stage' => $stage], $over));
};
if ($type === 'custom') {
    // Previous / next move by the length of the chosen range
    $span = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
    $prevHref = $periodQs(['from' => date('Y-m-d', strtotime("$from -$span days")), 'to' => date('Y-m-d', strtotime("$to -$span days"))]);
    $nextHref = $periodQs(['from' => date('Y-m-d', strtotime("$from +$span days")), 'to' => date('Y-m-d', strtotime("$to +$span days"))]);
    $isLatest = $to >= date('Y-m-d');
} elseif ($type === 'daily') {
    $prevHref = $periodQs(['day' => date('Y-m-d', strtotime("$day -1 day"))]);
    $nextHref = $periodQs(['day' => date('Y-m-d', strtotime("$day +1 day"))]);
    $isLatest = $day >= date('Y-m-d');
} else {
    $prevHref = $periodQs(['month' => date('Y-m', strtotime("$month-01 -1 month"))]);
    $nextHref = $periodQs(['month' => date('Y-m', strtotime("$month-01 +1 month"))]);
    $isLatest = $month >= date('Y-m');
}

$pageTitle    = 'Reports';
$pageSubtitle = sanitize($label);
$pageActions  = '<a href="?' . $qs . '&export=csv" class="btn btn-ghost"><i class="bi bi-filetype-csv"></i>Export CSV</a>'
              . '<a href="?' . $qs . '&print=1" target="_blank" class="btn btn-gold"><i class="bi bi-printer"></i>Print / PDF</a>';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Filters -->
<div class="toolbar">
  <div class="segmented">
    <a href="<?= $periodQs(['type' => 'monthly']) ?>" class="<?= $type === 'monthly' ? 'active' : '' ?>">Monthly</a>
    <a href="<?= $periodQs(['type' => 'daily']) ?>" class="<?= $type === 'daily' ? 'active' : '' ?>">Daily</a>
    <a href="<?= $periodQs(['type' => 'custom']) ?>" class="<?= $type === 'custom' ? 'active' : '' ?>">Custom range</a>
  </div>
  <div class="d-flex align-items-center gap-1">
    <a href="<?= $prevHref ?>" class="btn-icon" title="Previous <?= ['daily' => 'day', 'monthly' => 'month', 'custom' => 'period'][$type] ?>" aria-label="Previous period"><i class="bi bi-chevron-left"></i></a>
    <?php if ($type === 'custom'): ?>
    <form method="get" class="d-flex align-items-center gap-1">
      <?php foreach (['type' => 'custom', 'source' => $source, 'stage' => $stage] as $k => $v): ?><input type="hidden" name="<?= $k ?>" value="<?= sanitize($v) ?>"><?php endforeach; ?>
      <input type="date" name="from" class="form-control form-control-sm" style="min-width:0;width:auto" value="<?= $from ?>" max="<?= date('Y-m-d') ?>" aria-label="From" required>
      <span class="text-tx-3 fs-13">to</span>
      <input type="date" name="to" class="form-control form-control-sm" style="min-width:0;width:auto" value="<?= $to ?>" max="<?= date('Y-m-d') ?>" aria-label="To" required>
      <button class="btn btn-ghost btn-sm">Show</button>
    </form>
    <?php elseif ($type === 'daily'): ?>
    <input type="date" class="form-control form-control-sm" style="min-width:0;width:auto" value="<?= $day ?>" aria-label="Day"
           onchange="location='<?= $periodQs(['day' => '']) ?>'.replace('day=', 'day=' + this.value)">
    <?php else: ?>
    <input type="month" class="form-control form-control-sm" style="min-width:0;width:auto" value="<?= $month ?>" aria-label="Month"
           onchange="location='<?= $periodQs(['month' => '']) ?>'.replace('month=', 'month=' + this.value)">
    <?php endif; ?>
    <a href="<?= $nextHref ?>" class="btn-icon <?= $isLatest ? 'disabled opacity-25 pe-none' : '' ?>" title="Next <?= ['daily' => 'day', 'monthly' => 'month', 'custom' => 'period'][$type] ?>" aria-label="Next period"><i class="bi bi-chevron-right"></i></a>
  </div>
  <select class="form-select form-select-sm" aria-label="Source" onchange="location='<?= $periodQs(['source' => '']) ?>'.replace('source=', 'source=' + encodeURIComponent(this.value))">
    <option value="">All sources</option>
    <?php foreach ($sources as $src): ?>
    <option value="<?= sanitize($src['slug']) ?>" <?= $source === $src['slug'] ? 'selected' : '' ?>><?= htmlspecialchars(trim(($src['icon'] ?? '') . ' ' . ($src['name'] ?? $src['slug'])), ENT_QUOTES, 'UTF-8') ?></option>
    <?php endforeach; ?>
  </select>
  <select class="form-select form-select-sm" aria-label="Stage" onchange="location='<?= $periodQs(['stage' => '']) ?>'.replace('stage=', 'stage=' + this.value)">
    <option value="">All stages</option>
    <?php foreach (['new','contacted','qualified','booked','converted','lost','cold'] as $st): ?>
    <option value="<?= $st ?>" <?= $stage === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<?php if ($queryError): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill"></i><?= sanitize($queryError) ?></div>
<?php endif; ?>

<?php if (!$hasConvertedAt): ?>
<div class="alert alert-warning">
  <i class="bi bi-exclamation-triangle-fill"></i>
  <div>
    <strong>Database update needed for exact conversion dates.</strong> Run this in phpMyAdmin:
    <pre class="mb-0 mt-2 p-2 bg-white border rounded fs-12">ALTER TABLE leads ADD COLUMN IF NOT EXISTS converted_at DATETIME DEFAULT NULL;
UPDATE leads SET converted_at = updated_at WHERE stage = 'converted' AND converted_at IS NULL;</pre>
  </div>
</div>
<?php endif; ?>

<!-- KPI Summary -->
<div class="row g-3 page-section">
  <?php foreach ([
    ['Leads created', $totalLeadsCreated, 'All sources and stages', 'bi-people', 'tone-gold'],
    ['Converted this period', $convertedThisPeriod, $hasConvertedAt ? 'Counted by conversion date' : 'Counted by last update', 'bi-check2-circle', 'tone-green'],
    ['Conversion rate', $convRate . '%', 'Conversions ÷ leads created', 'bi-graph-up-arrow', 'tone-violet'],
    ['Booked', $booked, 'In the list below', 'bi-calendar-check', 'tone-amber'],
    ['Lost', $lost, 'In the list below', 'bi-x-circle', 'tone-red'],
  ] as [$lbl, $val, $sub, $icon, $tone]): ?>
  <div class="col-6 col-lg">
    <div class="stat-card">
      <div class="stat-top"><div class="stat-label"><?= $lbl ?></div><span class="stat-icon <?= $tone ?>"><i class="bi <?= $icon ?>"></i></span></div>
      <div class="stat-value"><?= $val ?></div>
      <div class="stat-foot"><?= $sub ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Report table -->
<div class="card-crm">
  <div class="card-header-crm flex-wrap">
    <span><i class="bi bi-table"></i> Leads created in <?= sanitize($label) ?> <span class="card-header-sub ms-1"><?= $totalLeads ?> records</span></span>
    <input type="search" class="form-control form-control-sm" style="max-width:260px" placeholder="Filter by name, phone, email…" oninput="filterTable(this.value)" aria-label="Filter table">
  </div>

  <?php if (!empty($bySource)): ?>
  <div class="d-flex flex-wrap gap-2 px-3 py-2 border-bottom" style="background:var(--surface-2)">
    <?php foreach ($bySource as $src => $cnt): ?>
    <a href="<?= $periodQs(['source' => $src]) ?>" class="chip" style="background:var(--surface)"><span class="source-label"><?= sourceBadge($src) ?></span> <strong class="text-tx-1"><?= $cnt ?></strong></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="table-responsive">
    <table class="table table-hover">
      <thead>
        <tr>
          <th>#</th>
          <th>Lead</th>
          <th>Treatments</th>
          <th>Source</th>
          <th>Stage</th>
          <?php if ($hasConvertedAt): ?><th>Converted</th><?php endif; ?>
          <?php if ($hasFuColumns): ?><th>FU 1–3</th><?php endif; ?>
          <th>Score</th>
          <th>Owner</th>
          <th>Created</th>
        </tr>
      </thead>
      <tbody id="reportTbody">
      <?php if (empty($leads)): ?>
      <tr><td colspan="<?= $totalCols ?>"><div class="empty-state"><i class="bi bi-inbox"></i><div class="empty-state-title">No leads in this period</div><p>Try another month or clear the filters.</p></div></td></tr>
      <?php endif; ?>
      <?php foreach ($leads as $i => $l): ?>
      <tr class="row-link" data-href="/pages/lead_view.php?id=<?= (int)$l['id'] ?>">
        <td class="text-tx-3 tabular"><?= $i + 1 ?></td>
        <td>
          <a href="/pages/lead_view.php?id=<?= (int)$l['id'] ?>" class="cell-title"><?= sanitize($l['name']) ?></a>
          <span class="cell-sub tabular"><?= sanitize($l['phone']) ?><?= !empty($l['email']) ? ' · ' . sanitize($l['email']) : '' ?></span>
        </td>
        <td class="fs-12 text-tx-2" style="max-width:200px"><?= $l['treatments'] ? sanitize($l['treatments']) : '<span class="text-tx-3">—</span>' ?></td>
        <td><span class="source-label"><?= sourceBadge($l['source']) ?></span></td>
        <td><?= stageBadge($l['stage']) ?></td>
        <?php if ($hasConvertedAt): ?>
        <td class="fs-12 text-nowrap <?= !empty($l['converted_at']) ? 'text-green fw-500' : 'text-tx-3' ?>"><?= !empty($l['converted_at']) ? date('d M Y', strtotime($l['converted_at'])) : '—' ?></td>
        <?php endif; ?>
        <?php if ($hasFuColumns): ?>
        <td class="text-nowrap">
          <?php for ($f = 1; $f <= 3; $f++): $done = !empty($l["followup_{$f}_done"]); ?>
          <span class="fu-dot <?= $done ? 'done' : '' ?>" title="Follow-up <?= $f ?> <?= $done ? 'done' : 'not done' ?>"></span>
          <?php endfor; ?>
        </td>
        <?php endif; ?>
        <td><?= scoreChip((int)$l['score']) ?></td>
        <td class="fs-12 text-nowrap"><?= sanitize($l['assigned_staff'] ?? '—') ?></td>
        <td class="fs-12 text-nowrap text-tx-2"><?= date('d M Y', strtotime($l['created_at'])) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer-crm fs-12 text-tx-3">
    <i class="bi bi-info-circle"></i>
    The list shows leads <em>created</em> in this period. “Converted this period” counts leads that converted during it<?= $hasConvertedAt ? ', by their conversion date' : '' ?>, even if they were created earlier.
  </div>
</div>

<?php
$extraJs = "function filterTable(q){q=q.toLowerCase();document.querySelectorAll('#reportTbody tr').forEach(r=>r.style.display=r.textContent.toLowerCase().includes(q)?'':'none');}";
require_once __DIR__ . '/../includes/footer.php';
?>
