<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$db = getDB();

// ── Date range ──
$today    = date('Y-m-d');
$firstDay = date('Y-m-01');
$dateFrom = isset($_GET['from']) ? $_GET['from'] : $firstDay;
$dateTo   = isset($_GET['to'])   ? $_GET['to']   : $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = $firstDay;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = $today;
if ($dateFrom > $dateTo) { $tmp=$dateFrom; $dateFrom=$dateTo; $dateTo=$tmp; }

$dateLabel = date('d M Y', strtotime($dateFrom)) . ' — ' . date('d M Y', strtotime($dateTo));
$printMode = isset($_GET['print']);
$sf        = can('leads.all') ? '' : ('AND assigned_to = ' . (int)currentStaffId());

$presets = array(
    array(date('Y-m-d'),                        date('Y-m-d'),  'Today'),
    array(date('Y-m-d',strtotime('-6 days')),   date('Y-m-d'),  '7 Days'),
    array(date('Y-m-01'),                        date('Y-m-d'), 'This Month'),
    array(date('Y-m-d',strtotime('-29 days')),  date('Y-m-d'),  'Last 30d'),
    array(date('Y-m-d',strtotime('-89 days')),  date('Y-m-d'),  'Last 90d'),
    array(date('Y-01-01'),                       date('Y-m-d'), 'This Year'),
);

// ── Safe helpers ──
function safeQ(PDO $db, $sql, $default=array()) {
    try { $r=$db->query($sql); return $r?$r->fetchAll():$default; }
    catch(PDOException $e){ return $default; }
}
function safeV(PDO $db, $sql, $default=0) {
    try { $r=$db->query($sql); return ($r&&($v=$r->fetchColumn())!==false)?$v:$default; }
    catch(PDOException $e){ return $default; }
}

// ── KPIs ──
$totalLeads = (int)safeV($db,"SELECT COUNT(*) FROM leads WHERE DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo' $sf");
$converted  = (int)safeV($db,"SELECT COUNT(*) FROM leads WHERE stage='converted' AND DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo' $sf");
$booked     = (int)safeV($db,"SELECT COUNT(*) FROM leads WHERE stage='booked' AND DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo' $sf");
$lost       = (int)safeV($db,"SELECT COUNT(*) FROM leads WHERE stage='lost' AND DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo' $sf");
$hot        = (int)safeV($db,"SELECT COUNT(*) FROM leads WHERE score>=61 AND stage NOT IN('converted','lost') $sf");
$convRate   = $totalLeads>0 ? round($converted/$totalLeads*100,1) : 0;
$avgDays    = round((float)safeV($db,"SELECT AVG(DATEDIFF(updated_at,created_at)) FROM leads WHERE stage='converted' AND DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo' $sf",0),1);

// ── Previous period ──
$daysDiff = max(1,(int)((strtotime($dateTo)-strtotime($dateFrom))/86400));
$prevTo   = date('Y-m-d',strtotime($dateFrom)-86400);
$prevFrom = date('Y-m-d',strtotime($prevTo)-($daysDiff*86400));
$prevTotal= (int)safeV($db,"SELECT COUNT(*) FROM leads WHERE DATE(created_at) BETWEEN '$prevFrom' AND '$prevTo' $sf");
$prevConv = (int)safeV($db,"SELECT COUNT(*) FROM leads WHERE stage='converted' AND DATE(created_at) BETWEEN '$prevFrom' AND '$prevTo' $sf");
$totalDiff= $prevTotal>0 ? round(($totalLeads-$prevTotal)/$prevTotal*100,1) : null;
$convDiff = $prevConv >0 ? round(($converted-$prevConv)/$prevConv*100,1)   : null;

// ── Avg response ──
$avgResponseHours = 0;
try {
    $rr = safeQ($db,"SELECT MIN(TIMESTAMPDIFF(HOUR,l.created_at,f.created_at)) as hrs FROM leads l JOIN followups f ON f.lead_id=l.id WHERE DATE(l.created_at) BETWEEN '$dateFrom' AND '$dateTo' $sf GROUP BY l.id");
    if (!empty($rr)) { $hrs=array_column($rr,'hrs'); $avgResponseHours=count($hrs)>0?round(array_sum($hrs)/count($hrs),1):0; }
} catch(Exception $e){}

// ── Daily trend ──
$dailyTrend = safeQ($db,"SELECT DATE(created_at) as day, COUNT(*) as cnt, SUM(stage='converted') as conv FROM leads WHERE DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo' $sf GROUP BY DATE(created_at) ORDER BY day");

// ── Funnel ──
$funnelRows = safeQ($db,"SELECT stage,COUNT(*) as cnt FROM leads WHERE DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo' $sf GROUP BY stage");
$funnel = array();
foreach ($funnelRows as $r) $funnel[$r['stage']] = (int)$r['cnt'];

// ── Source stats ──
$sourceConv = safeQ($db,"SELECT source,COUNT(*) as total,SUM(stage='converted') as converted,SUM(stage='lost') as lost,ROUND(AVG(score),1) as avg_score,ROUND(SUM(stage='converted')/COUNT(*)*100,1) as rate FROM leads WHERE DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo' $sf GROUP BY source ORDER BY total DESC");

// ── Day of week ──
$byDow = safeQ($db,"SELECT DAYOFWEEK(created_at) as dow,DAYNAME(created_at) as day_name,COUNT(*) as cnt,SUM(stage='converted') as conv FROM leads WHERE DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo' $sf GROUP BY DAYOFWEEK(created_at),DAYNAME(created_at) ORDER BY dow");

// ── Hourly ──
$byHour = safeQ($db,"SELECT HOUR(created_at) as hr,COUNT(*) as cnt FROM leads WHERE DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo' $sf GROUP BY HOUR(created_at) ORDER BY hr");
$hourData=array_fill(0,24,0);
foreach($byHour as $h) $hourData[(int)$h['hr']]=(int)$h['cnt'];
$hourLbls=array();
for($i=0;$i<=23;$i++){
    if($i===0) $hourLbls[]='12am';
    elseif($i<12) $hourLbls[]=$i.'am';
    elseif($i===12) $hourLbls[]='12pm';
    else $hourLbls[]=($i-12).'pm';
}

// ── Treatment demand ──
$treatDemand = safeQ($db,"SELECT t.name,t.avg_price,COUNT(lt.lead_id) as lead_count,COUNT(lt.lead_id)*t.avg_price as revenue_potential,SUM(l.stage='converted') as converted FROM treatments t JOIN lead_treatments lt ON lt.treatment_id=t.id JOIN leads l ON l.id=lt.lead_id WHERE DATE(l.created_at) BETWEEN '$dateFrom' AND '$dateTo' $sf GROUP BY t.id,t.name,t.avg_price ORDER BY lead_count DESC");
$totalRevPot=0; $revConv=0;
foreach($treatDemand as $t){ $totalRevPot+=$t['revenue_potential']; $revConv+=$t['converted']*$t['avg_price']; }
$revPipeline=$totalRevPot-$revConv;

// ── Staff leaderboard ──
$staffBoard = safeQ($db,"SELECT s.name,COUNT(DISTINCT l.id) as assigned,SUM(CASE WHEN l.stage='converted' THEN 1 ELSE 0 END) as converted,SUM(CASE WHEN l.stage='lost' THEN 1 ELSE 0 END) as lost,SUM(CASE WHEN l.stage='booked' THEN 1 ELSE 0 END) as booked,COUNT(DISTINCT f.id) as followups,ROUND(AVG(l.score),1) as avg_score,ROUND(SUM(CASE WHEN l.stage='converted' THEN 1 ELSE 0 END)/NULLIF(COUNT(DISTINCT l.id),0)*100,1) as conv_rate FROM staff s LEFT JOIN leads l ON l.assigned_to=s.id AND DATE(l.created_at) BETWEEN '$dateFrom' AND '$dateTo' LEFT JOIN followups f ON f.staff_id=s.id AND DATE(f.created_at) BETWEEN '$dateFrom' AND '$dateTo' WHERE s.is_active=1 GROUP BY s.id,s.name ORDER BY converted DESC,assigned DESC");

// ── Lost leads — group by reason only (source separate) ──
$lostByReason = safeQ($db,"SELECT CASE WHEN TRIM(COALESCE(lost_reason,''))='' THEN 'No reason given' ELSE TRIM(lost_reason) END as reason, COUNT(*) as cnt FROM leads WHERE stage='lost' AND DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo' $sf GROUP BY CASE WHEN TRIM(COALESCE(lost_reason,''))='' THEN 'No reason given' ELSE TRIM(lost_reason) END ORDER BY cnt DESC LIMIT 10");
$totalLostCount = (int)safeV($db,"SELECT COUNT(*) FROM leads WHERE stage='lost' AND DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo' $sf");

// ── Lost by source ──
$lostBySource = safeQ($db,"SELECT source,SUM(CASE WHEN stage='lost' THEN 1 ELSE 0 END) as lost_cnt,COUNT(*) as total FROM leads WHERE DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo' $sf GROUP BY source HAVING lost_cnt>0 ORDER BY lost_cnt DESC");

// ── Meta ads: what each campaign and ad led to (stage reached now; ad name comes from the import note) ──
// ponytail: a lead that re-enquired via a second ad counts under the first ad named in its notes
$adRows = safeQ($db,"SELECT COALESCE(NULLIF(source_campaign,''),'(no campaign)') as campaign,
        COALESCE(NULLIF(TRIM(SUBSTRING(REGEXP_SUBSTR(notes,'Ad: [^\n]+'),5)),''),'(ad not recorded)') as ad,
        COUNT(*) as total, SUM(stage IN('contacted','qualified','booked','converted')) as contacted,
        SUM(stage IN('qualified','booked','converted')) as qualified, SUM(stage IN('booked','converted')) as booked,
        SUM(stage='converted') as converted, SUM(stage IN('lost','cold')) as lost
    FROM leads WHERE source='meta_form' AND DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo' $sf
    GROUP BY campaign, ad ORDER BY campaign, total DESC");
$adCampaigns = array();
foreach ($adRows as $r) {
    $c = &$adCampaigns[$r['campaign']];
    $c['ads'][] = $r;
    foreach (array('total','contacted','qualified','booked','converted','lost') as $k) $c[$k] = ($c[$k] ?? 0) + (int)$r[$k];
    unset($c);
}
uasort($adCampaigns, fn($a, $b) => $b['booked'] <=> $a['booked'] ?: $b['total'] <=> $a['total']);

// ── Aging heatmap ──
$agingRows = safeQ($db,"SELECT stage,aging_status,COUNT(*) as cnt FROM leads WHERE stage NOT IN('converted','lost','cold') $sf GROUP BY stage,aging_status");
$agingMap=array();
foreach($agingRows as $r) $agingMap[$r['stage']][$r['aging_status']]=(int)$r['cnt'];

// ── Month over month ──
$mom = safeQ($db,"SELECT DATE_FORMAT(created_at,'%Y-%m') as month,DATE_FORMAT(created_at,'%b %Y') as label,COUNT(*) as total,SUM(CASE WHEN stage='converted' THEN 1 ELSE 0 END) as converted,SUM(CASE WHEN stage='lost' THEN 1 ELSE 0 END) as lost,ROUND(SUM(CASE WHEN stage='converted' THEN 1 ELSE 0 END)/COUNT(*)*100,1) as rate FROM leads WHERE created_at>=DATE_SUB(NOW(),INTERVAL 6 MONTH) $sf GROUP BY DATE_FORMAT(created_at,'%Y-%m'),DATE_FORMAT(created_at,'%b %Y') ORDER BY month");

// ════════════════════════════
// PRINT MODE
// ════════════════════════════
if ($printMode) { ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Analytics Report — <?= sanitize($dateLabel) ?></title>
<link href="/assets/vendor/fonts/fonts.css" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Outfit',sans-serif;font-size:11px;color:#1a1a1a;background:#fff;padding:24px}
.np{margin-bottom:18px}
/* Header */
.rh{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:linear-gradient(135deg,#C9956C,#A8764E);border-radius:10px;color:#fff;margin-bottom:18px}
.rh-title{font-size:18px;font-weight:700;margin-bottom:3px}
.rh-sub{font-size:11px;opacity:.85}
.rh-right{text-align:right}
.rh-stat{font-size:20px;font-weight:700}
.rh-slbl{font-size:10px;opacity:.8}
/* KPI Grid */
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px}
.kpi-box{border:1.5px solid #e8e8e8;border-radius:8px;padding:10px 12px;background:#fafafa}
.kpi-val{font-size:20px;font-weight:700;color:#1a1a1a;line-height:1.1;margin-bottom:2px}
.kpi-lbl{font-size:8.5px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#999}
.kpi-delta{font-size:10px;font-weight:600;margin-top:3px}
/* Revenue box */
.rev-box{display:grid;grid-template-columns:1fr auto auto;gap:0;border:1.5px solid #e8d5c4;border-radius:8px;overflow:hidden;margin-bottom:16px;background:#fffaf6}
.rev-main{padding:12px 16px;border-right:1px solid #e8d5c4}
.rev-main-label{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#A8764E;margin-bottom:4px}
.rev-main-val{font-size:22px;font-weight:800;color:#A8764E;line-height:1}
.rev-main-sub{font-size:10px;color:#aaa;margin-top:3px}
.rev-side{padding:12px 16px;text-align:center;border-right:1px solid #e8d5c4}
.rev-side:last-child{border-right:none}
.rev-side-val{font-size:16px;font-weight:700;margin-bottom:2px}
.rev-side-lbl{font-size:9px;color:#999;text-transform:uppercase;letter-spacing:.4px}
/* Section title */
.sec{font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.7px;color:#C9956C;margin:14px 0 7px;padding-bottom:5px;border-bottom:2px solid #f0ece8;display:flex;align-items:center;gap:6px}
/* Two col layout */
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
.grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px}
/* Panel boxes */
.panel{border:1.5px solid #e8e8e8;border-radius:8px;overflow:hidden}
.panel-head{background:#f5f5f5;padding:7px 12px;font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#666;border-bottom:1px solid #e8e8e8}
.panel-body{padding:10px 12px}
/* Funnel */
.funnel-item{display:flex;align-items:center;gap:8px;margin-bottom:7px}
.funnel-lbl{width:65px;font-size:10px;font-weight:600;color:#555;text-transform:capitalize;flex-shrink:0}
.funnel-track{flex:1;background:#f0f0f0;border-radius:4px;height:16px;overflow:hidden}
.funnel-fill{height:16px;border-radius:4px;display:flex;align-items:center;padding:0 6px}
.funnel-fill span{font-size:9.5px;font-weight:700;color:#fff;white-space:nowrap}
.funnel-num{width:25px;text-align:right;font-size:11px;font-weight:700;color:#333}
/* Tables */
table{width:100%;border-collapse:collapse}
th{background:#f5f5f5;padding:6px 8px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#777;border-bottom:2px solid #e8e8e8;text-align:left}
td{padding:6px 8px;font-size:10.5px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:nth-child(even){background:#fafafa}
.green{color:#16a34a;font-weight:700}
.red{color:#dc2626;font-weight:700}
.amber{color:#d97706;font-weight:700}
.blue{color:#2563eb;font-weight:700}
/* DoW grid */
.dow-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:5px}
.dow-cell{text-align:center;border:1.5px solid #e8e8e8;border-radius:6px;padding:7px 3px;background:#fafafa}
.dow-cell.peak{border-color:#C9956C;background:#fdf6f0}
.dow-num{font-size:15px;font-weight:700;color:#333}
.dow-cell.peak .dow-num{color:#C9956C}
.dow-name{font-size:8px;color:#aaa;text-transform:uppercase;letter-spacing:.3px;margin-top:2px}
.dow-conv{font-size:8.5px;color:#16a34a;margin-top:2px}
/* Lost reasons */
.lost-item{display:flex;align-items:center;gap:8px;margin-bottom:6px}
.lost-lbl{flex:1;font-size:10px;color:#444}
.lost-bar-wrap{width:80px;height:6px;background:#f0f0f0;border-radius:3px;overflow:hidden}
.lost-bar{height:6px;background:#dc2626;border-radius:3px;opacity:.7}
.lost-cnt{width:20px;text-align:right;font-size:10px;font-weight:700;color:#dc2626}
/* Aging */
.aging-grid{display:grid;grid-template-columns:auto repeat(4,1fr) auto;gap:0}
.ag-head{font-size:8.5px;font-weight:700;text-align:center;padding:5px 3px;background:#f5f5f5;border:1px solid #e8e8e8;color:#777}
.ag-head.left{text-align:left;padding-left:8px}
.ag-cell{font-size:11px;font-weight:700;text-align:center;padding:6px 3px;border:1px solid #f0f0f0;border-top:none}
.ag-cell.stage{text-align:left;padding-left:8px;font-size:10px;font-weight:600;text-transform:capitalize;color:#444}
.ag-fresh{background:#f0fdf4;color:#16a34a}
.ag-due{background:#fffbeb;color:#d97706}
.ag-over{background:#fff7ed;color:#f97316}
.ag-crit{background:#fef2f2;color:#dc2626;font-weight:800}
.ag-tot{font-size:11px;font-weight:700;background:#f5f5f5;text-align:center;padding:6px 3px;border:1px solid #f0f0f0;border-top:none}
/* Medals */
.medal{font-size:13px}
/* Bar inline */
.ibar-wrap{width:50px;height:5px;background:#eee;border-radius:3px;overflow:hidden;display:inline-block;vertical-align:middle;margin-right:4px}
.ibar-fill{height:5px;border-radius:3px}
/* Footer */
.pfoot{margin-top:16px;padding-top:10px;border-top:1.5px solid #e8e8e8;display:flex;justify-content:space-between;align-items:center}
.pfoot-left{font-size:9.5px;color:#aaa}
.pfoot-right{font-size:9px;color:#C9956C;font-weight:600}
@media print{.np{display:none!important}@page{margin:10mm;size:A4 portrait}body{padding:0}h1,h2,h3{page-break-after:avoid}.panel{page-break-inside:avoid}}
</style>
</head>
<body>

<div class="np">
  <button onclick="window.print()" style="background:#C9956C;color:#fff;border:none;padding:10px 24px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;margin-right:12px;box-shadow:0 2px 8px rgba(201,149,108,.4)">🖨 Print / Save PDF</button>
  <a href="javascript:history.back()" style="font-size:12.5px;color:#777;text-decoration:none;">← Back to Analytics</a>
</div>

<!-- Header -->
<div class="rh">
  <div>
    <div class="rh-title">Analytics Report</div>
    <div class="rh-sub"><?= sanitize($dateLabel) ?></div>
    <div class="rh-sub" style="margin-top:2px;">Generated by <?= sanitize(staffName()) ?> · <?= date('d M Y, h:i A') ?></div>
  </div>
  <div style="display:flex;gap:24px;">
    <div style="text-align:center;"><div class="rh-stat"><?= $totalLeads ?></div><div class="rh-slbl">Total Leads</div></div>
    <div style="text-align:center;"><div class="rh-stat"><?= $converted ?></div><div class="rh-slbl">Converted</div></div>
    <div style="text-align:center;"><div class="rh-stat"><?= $convRate ?>%</div><div class="rh-slbl">Conv. Rate</div></div>
    <div style="text-align:center;"><div class="rh-stat">AED <?= number_format($totalRevPot,0) ?></div><div class="rh-slbl">Revenue Potential</div></div>
  </div>
</div>

<!-- KPIs -->
<div class="sec">📊 Key Performance Indicators</div>
<div class="kpi-grid">
  <?php
  $printKpis = array(
    array('Total Leads',      $totalLeads,  '#2563EB', $totalDiff),
    array('Converted',        $converted,   '#16A34A', $convDiff),
    array('Conv. Rate',       $convRate.'%','#7C3AED', null),
    array('Booked',           $booked,      '#D97706', null),
    array('Lost',             $lost,        '#DC2626', null),
    array('Hot Leads Now',    $hot,         '#DC2626', null),
    array('Avg Days to Conv.',$avgDays?:'—','#16A34A', null),
    array('Avg Response',     $avgResponseHours?($avgResponseHours.'h'):'—','#2563EB',null),
  );
  foreach ($printKpis as $kp):
    $clr = $kp[2];
  ?>
  <div class="kpi-box">
    <div class="kpi-val" style="color:<?= $clr ?>"><?= $kp[1] ?></div>
    <div class="kpi-lbl"><?= $kp[0] ?></div>
    <?php if ($kp[3]!==null): $dc=$kp[3]>=0?'#16a34a':'#dc2626'; $da=$kp[3]>=0?'↑':'↓'; ?>
    <div class="kpi-delta" style="color:<?= $dc ?>"><?= $da ?> <?= abs($kp[3]) ?>% vs prev period</div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<!-- Revenue -->
<?php if ($totalRevPot > 0): ?>
<div class="sec">💰 Pipeline Revenue Potential</div>
<div class="rev-box">
  <div class="rev-main">
    <div class="rev-main-label">Total Pipeline Value</div>
    <div class="rev-main-val">AED <?= number_format($totalRevPot,0) ?></div>
    <div class="rev-main-sub">Based on treatment interest × avg price · <?= $totalLeads ?> leads</div>
  </div>
  <div class="rev-side">
    <div class="rev-side-val green">AED <?= number_format($revConv,0) ?></div>
    <div class="rev-side-lbl">Already Converted</div>
  </div>
  <div class="rev-side">
    <div class="rev-side-val amber">AED <?= number_format($revPipeline,0) ?></div>
    <div class="rev-side-lbl">Still in Pipeline</div>
  </div>
</div>
<?php endif; ?>

<!-- Funnel + DoW -->
<div class="grid2">
  <div>
    <div class="sec">🔽 Pipeline Funnel</div>
    <div class="panel">
      <div class="panel-body">
        <?php
        $stageOrder  = array('new','contacted','qualified','booked','converted');
        $stageColors = array('new'=>'#9CA3AF','contacted'=>'#3B82F6','qualified'=>'#8B5CF6','booked'=>'#F59E0B','converted'=>'#22C55E');
        $fMax = 0;
        foreach ($stageOrder as $st) { $c=isset($funnel[$st])?(int)$funnel[$st]:0; if($c>$fMax)$fMax=$c; }
        if ($fMax===0) $fMax=1;
        foreach ($stageOrder as $st):
          $c   = isset($funnel[$st])?(int)$funnel[$st]:0;
          $pct = round($c/$fMax*100);
          $newC= isset($funnel['new'])?(int)$funnel['new']:0;
          $dropPct = ($st!=='new'&&$newC>0) ? round($c/$newC*100) : 0;
        ?>
        <div class="funnel-item">
          <div class="funnel-lbl"><?= ucfirst($st) ?></div>
          <div class="funnel-track">
            <div class="funnel-fill" style="width:<?= max($pct,3) ?>%;background:<?= $stageColors[$st] ?>">
              <?php if ($pct >= 15): ?><span><?= $c ?></span><?php endif; ?>
            </div>
          </div>
          <div class="funnel-num"><?= $c ?></div>
        </div>
        <?php endforeach; ?>
        <?php if (isset($funnel['lost']) && $funnel['lost']>0): ?>
        <div style="margin-top:8px;padding-top:8px;border-top:1px dashed #eee;font-size:10px;color:#dc2626;">
          ✗ <?= $funnel['lost'] ?> leads lost <?= $totalLeads>0?'('.round($funnel['lost']/$totalLeads*100,1).'%)':'' ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div>
    <div class="sec">📅 Best Days for Leads</div>
    <div class="panel">
      <div class="panel-body">
        <?php
        $dowMax=0; foreach($byDow as $d){if((int)$d['cnt']>$dowMax)$dowMax=(int)$d['cnt'];} if($dowMax===0)$dowMax=1;
        ?>
        <div class="dow-grid">
          <?php foreach($byDow as $d):
            $isPeak=(int)$d['cnt']===$dowMax;
          ?>
          <div class="dow-cell <?= $isPeak?'peak':'' ?>">
            <div class="dow-num"><?= $d['cnt'] ?></div>
            <div class="dow-name"><?= substr($d['day_name'],0,3) ?></div>
            <?php if((int)$d['conv']>0):?><div class="dow-conv"><?=$d['conv']?>✓</div><?php endif;?>
          </div>
          <?php endforeach;?>
        </div>
        <div style="font-size:9px;color:#bbb;margin-top:8px;text-align:center;">Highlighted = busiest day · ✓ = conversions</div>
      </div>
    </div>
  </div>
</div>

<!-- Source Performance -->
<div class="sec">📡 Source Performance</div>
<div class="panel">
  <table>
    <thead><tr><th>Source</th><th>Leads</th><th>Converted</th><th>Lost</th><th>Conv. Rate</th><th>Avg Score</th><th style="width:80px;">Performance</th></tr></thead>
    <tbody>
    <?php foreach($sourceConv as $s):
      $r=(float)($s['rate']??0);
      $rc=$r>=30?'#16a34a':($r>=15?'#d97706':'#dc2626');
    ?>
    <tr>
      <td style="font-weight:600;"><?= sanitize(ucfirst(str_replace('_',' ',$s['source']))) ?></td>
      <td><?= $s['total'] ?></td>
      <td class="green"><?= $s['converted'] ?></td>
      <td class="red"><?= $s['lost'] ?></td>
      <td style="color:<?=$rc?>;font-weight:700;"><?= $r ?>%</td>
      <td><?= $s['avg_score'] ?></td>
      <td>
        <div style="background:#f0f0f0;border-radius:3px;height:8px;overflow:hidden;">
          <div style="width:<?=$r?>%;height:8px;background:<?=$rc?>;border-radius:3px;"></div>
        </div>
      </td>
    </tr>
    <?php endforeach;?>
    <?php if(empty($sourceConv)):?><tr><td colspan="7" style="text-align:center;color:#aaa;padding:12px;">No data</td></tr><?php endif;?>
    </tbody>
  </table>
</div>

<!-- Treatment + Staff -->
<div class="grid2" style="margin-top:0;">
  <div>
    <div class="sec">💆 Treatment Demand</div>
    <div class="panel">
      <table>
        <thead><tr><th>Treatment</th><th>Leads</th><th>Conv.</th><th>Revenue Pot.</th></tr></thead>
        <tbody>
        <?php
        $tMax = !empty($treatDemand)&&$treatDemand[0]['lead_count']>0?(int)$treatDemand[0]['lead_count']:1;
        foreach($treatDemand as $t):
          $bw = round($t['lead_count']/$tMax*100);
        ?>
        <tr>
          <td style="font-weight:500;"><?= sanitize($t['name']) ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:4px;">
              <div class="ibar-wrap"><div class="ibar-fill" style="width:<?=$bw?>%;background:#C9956C;"></div></div>
              <?= $t['lead_count'] ?>
            </div>
          </td>
          <td class="green"><?= $t['converted'] ?></td>
          <td style="color:#A8764E;font-weight:600;font-size:10px;">AED <?= number_format($t['revenue_potential'],0) ?></td>
        </tr>
        <?php endforeach;?>
        <?php if(empty($treatDemand)):?><tr><td colspan="4" style="text-align:center;color:#aaa;padding:12px;">No data</td></tr><?php endif;?>
        </tbody>
      </table>
    </div>
  </div>

  <div>
    <div class="sec">🏆 Staff Leaderboard</div>
    <div class="panel">
      <table>
        <thead><tr><th>#</th><th>Staff</th><th>Leads</th><th>Conv.</th><th>Rate</th><th>FU</th></tr></thead>
        <tbody>
        <?php
        $medals=array('🥇','🥈','🥉');
        foreach($staffBoard as $i=>$sp):
          $m=isset($medals[$i])?$medals[$i]:($i+1);
          $cr=isset($sp['conv_rate'])?(float)$sp['conv_rate']:0;
          $rc=$cr>=30?'#16a34a':($cr>=15?'#d97706':'#aaa');
        ?>
        <tr>
          <td class="medal"><?=$m?></td>
          <td style="font-weight:600;"><?= sanitize($sp['name']) ?></td>
          <td><?= $sp['assigned'] ?></td>
          <td class="green"><?= $sp['converted'] ?></td>
          <td style="color:<?=$rc?>;font-weight:700;"><?= $cr ?>%</td>
          <td><?= $sp['followups'] ?></td>
        </tr>
        <?php endforeach;?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Lost analysis + MoM -->
<div class="grid2">
  <div>
    <div class="sec">❌ Lost Leads Analysis <span style="font-size:9px;color:#aaa;font-weight:400;">(<?= $totalLostCount ?> total)</span></div>
    <div class="panel">
      <div class="panel-body">
        <?php if(empty($lostByReason)):?>
        <div style="text-align:center;color:#aaa;padding:16px;font-size:11px;">No lost leads this period 🎉</div>
        <?php else: foreach($lostByReason as $l):
          $lostTotal=array_sum(array_column($lostByReason,'cnt')); $pct=$lostTotal>0?round($l['cnt']/$lostTotal*100):0;
        ?>
        <div class="lost-item">
          <div class="lost-lbl"><?= sanitize(mb_substr($l['reason'],0,35)) ?></div>
          <div class="lost-bar-wrap"><div class="lost-bar" style="width:<?=$pct?>%;"></div></div>
          <div class="lost-cnt"><?= $l['cnt'] ?></div>
        </div>
        <?php endforeach;endif;?>
      </div>
    </div>
  </div>

  <div>
    <div class="sec">📈 Month-over-Month</div>
    <div class="panel">
      <table>
        <thead><tr><th>Month</th><th>Leads</th><th>Conv.</th><th>Lost</th><th>Rate</th></tr></thead>
        <tbody>
        <?php foreach(array_reverse($mom) as $m):
          $rc=$m['rate']>=20?'#16a34a':($m['rate']>=10?'#d97706':'#dc2626');
        ?>
        <tr>
          <td style="font-weight:600;"><?= sanitize($m['label']) ?></td>
          <td><?= $m['total'] ?></td>
          <td class="green"><?= $m['converted'] ?></td>
          <td class="red"><?= $m['lost'] ?></td>
          <td style="color:<?=$rc?>;font-weight:700;"><?= $m['rate'] ?>%</td>
        </tr>
        <?php endforeach;?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Aging Heatmap -->
<div class="sec">⏰ Lead Aging Heatmap (Active Leads)</div>
<div class="panel">
  <div class="aging-grid">
    <div class="ag-head left">Stage</div>
    <div class="ag-head">🟢 Fresh (&lt;24h)</div>
    <div class="ag-head">🟡 Due (2–3d)</div>
    <div class="ag-head">🟠 Overdue (3–7d)</div>
    <div class="ag-head">🔴 Critical (7+d)</div>
    <div class="ag-head">Total</div>
    <?php foreach(array('new','contacted','qualified','booked') as $st):
      $row=isset($agingMap[$st])?$agingMap[$st]:array();
      $f=isset($row['fresh'])?(int)$row['fresh']:0;
      $d=isset($row['due'])?(int)$row['due']:0;
      $o=isset($row['overdue'])?(int)$row['overdue']:0;
      $c=isset($row['critical'])?(int)$row['critical']:0;
      $tot=$f+$d+$o+$c;
      if($tot===0)continue;
    ?>
    <div class="ag-cell stage"><?= ucfirst($st) ?></div>
    <div class="ag-cell <?= $f>0?'ag-fresh':'' ?>"><?= $f?:'' ?></div>
    <div class="ag-cell <?= $d>0?'ag-due':'' ?>"><?= $d?:'' ?></div>
    <div class="ag-cell <?= $o>0?'ag-over':'' ?>"><?= $o?:'' ?></div>
    <div class="ag-cell <?= $c>0?'ag-crit':'' ?>"><?= $c?:'' ?></div>
    <div class="ag-tot"><?= $tot ?></div>
    <?php endforeach;?>
  </div>
</div>

<div class="pfoot">
  <div class="pfoot-left">Clinic CRM Analytics · <?= sanitize($dateLabel) ?> · Confidential — For internal use only</div>
  <div class="pfoot-right">Generated by <?= sanitize(staffName()) ?> · <?= date('d M Y, H:i') ?></div>
</div>

</body></html>
<?php exit; } // end print mode

// ════════════════════════════
// NORMAL PAGE
// ════════════════════════════

// Zero-fill daily series across the selected range (max 400 days)
$dailyMap = [];
foreach ($dailyTrend as $d) $dailyMap[$d['day']] = $d;
$dailyLabels = $dailyCounts = $dailyConv = [];
for ($t = strtotime($dateFrom), $n = 0; $t <= strtotime($dateTo) && $n < 400; $t = strtotime('+1 day', $t), $n++) {
    $k = date('Y-m-d', $t);
    $dailyLabels[] = date('d M', $t);
    $dailyCounts[] = (int)($dailyMap[$k]['cnt'] ?? 0);
    $dailyConv[]   = (int)($dailyMap[$k]['conv'] ?? 0);
}

function rdelta($diff) {
    if ($diff === null) return '<span class="text-tx-3">No prior data</span>';
    $cls = $diff >= 0 ? 'text-green' : 'text-red';
    $icon = $diff >= 0 ? 'bi-arrow-up-right' : 'bi-arrow-down-right';
    return '<span class="' . $cls . ' fw-600"><i class="bi ' . $icon . '"></i> ' . abs($diff) . '%</span> <span class="text-tx-3">vs previous period</span>';
}

$activePreset = null;
foreach ($presets as $pr) if ($dateFrom === $pr[0] && $dateTo === $pr[1]) $activePreset = $pr[2];

$pageTitle    = 'Analytics';
$pageSubtitle = sanitize($dateLabel) . (can('leads.all') ? '' : ' · your leads only');
$pageActions  = '<a href="?from=' . $dateFrom . '&to=' . $dateTo . '&print=1" target="_blank" class="btn btn-ghost"><i class="bi bi-printer"></i>Print report</a>';
require_once __DIR__ . '/../includes/header.php';

$stageOrder = ['new' => 'New', 'contacted' => 'Contacted', 'qualified' => 'Qualified', 'booked' => 'Booked', 'converted' => 'Converted'];
$stageVars  = ['new' => 'slate', 'contacted' => 'blue', 'qualified' => 'violet', 'booked' => 'amber', 'converted' => 'green'];
$topT = array_slice($treatDemand, 0, 8);
?>

<!-- Period -->
<div class="toolbar">
  <div class="segmented">
    <?php foreach ($presets as $pr): ?>
    <a href="?from=<?= $pr[0] ?>&to=<?= $pr[1] ?>" class="<?= $activePreset === $pr[2] ? 'active' : '' ?>"><?= $pr[2] ?></a>
    <?php endforeach; ?>
  </div>
  <form method="GET" class="d-flex align-items-center gap-2 flex-wrap">
    <input type="date" name="from" class="form-control form-control-sm" value="<?= $dateFrom ?>" max="<?= $today ?>" aria-label="From date" style="min-width:0">
    <span class="text-tx-3 fs-12">to</span>
    <input type="date" name="to" class="form-control form-control-sm" value="<?= $dateTo ?>" max="<?= $today ?>" aria-label="To date" style="min-width:0">
    <button type="submit" class="btn btn-ghost btn-sm">Apply</button>
  </form>
</div>

<!-- Headline KPIs -->
<div class="row g-3 page-section">
  <?php foreach ([
    ['Leads created', number_format($totalLeads), rdelta($totalDiff), 'bi-people', 'tone-gold'],
    ['Converted', $converted, rdelta($convDiff), 'bi-check2-circle', 'tone-green'],
    ['Conversion rate', $convRate . '%', "$booked booked · $lost lost", 'bi-graph-up-arrow', 'tone-violet'],
    ['First response', $avgResponseHours ? $avgResponseHours . 'h' : '—', ($avgDays ? "$avgDays days to convert on average" : 'Avg. time to first follow-up'), 'bi-lightning-charge', 'tone-blue'],
  ] as [$label, $value, $foot, $icon, $tone]): ?>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-top"><div class="stat-label"><?= $label ?></div><span class="stat-icon <?= $tone ?>"><i class="bi <?= $icon ?>"></i></span></div>
      <div class="stat-value"><?= $value ?></div>
      <div class="stat-foot"><?= $foot ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Volume + Funnel -->
<div class="row g-3 page-section">
  <div class="col-xl-8">
    <div class="card-crm h-100">
      <div class="card-header-crm"><span><i class="bi bi-bar-chart"></i> Daily lead volume</span><span class="card-header-sub">New leads and how many have converted</span></div>
      <div class="card-body-crm"><div style="height:240px"><canvas id="dailyChart"></canvas></div></div>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="card-crm h-100">
      <div class="card-header-crm"><span><i class="bi bi-funnel"></i> Funnel</span><span class="card-header-sub">Where these leads are now</span></div>
      <div class="card-body-crm">
        <?php
        $fMax = max(1, max(array_map(fn($s) => (int)($funnel[$s] ?? 0), array_keys($stageOrder))));
        foreach ($stageOrder as $st => $label):
          $c = (int)($funnel[$st] ?? 0);
          $share = $totalLeads > 0 ? round($c / $totalLeads * 100) : 0; ?>
        <a href="/pages/leads.php?stage=<?= $st ?>" class="d-block mb-3 text-reset">
          <div class="d-flex justify-content-between align-items-baseline mb-1 fs-13">
            <span class="text-tx-2 fw-500"><?= $label ?></span>
            <span><strong class="tabular"><?= $c ?></strong> <span class="text-tx-3 fs-12 tabular"><?= $share ?>%</span></span>
          </div>
          <div class="score-bar" style="height:8px"><div class="score-bar-fill" style="width:<?= max(round($c / $fMax * 100), $c ? 2 : 0) ?>%;background:var(--<?= $stageVars[$st] ?>)"></div></div>
        </a>
        <?php endforeach; ?>
        <div class="divider"></div>
        <div class="d-flex justify-content-between align-items-center fs-13">
          <span class="d-flex gap-1"><span class="chip stage-lost">Lost</span><span class="chip stage-cold">Cold</span></span>
          <span><strong class="tabular text-red"><?= (int)($funnel['lost'] ?? 0) ?></strong> <span class="text-tx-3">·</span> <strong class="tabular text-tx-2"><?= (int)($funnel['cold'] ?? 0) ?></strong></span>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Revenue -->
<?php if ($totalRevPot > 0): ?>
<div class="card-crm page-section">
  <div class="row g-0 revenue-strip">
    <div class="col-md-6">
      <div class="stat-label"><i class="bi bi-cash-stack text-gold"></i> Pipeline revenue potential</div>
      <div class="stat-value" style="font-size:30px;color:var(--gold-dark)">AED <?= number_format($totalRevPot, 0) ?></div>
      <div class="stat-foot">Treatments of interest × average price, for <?= $totalLeads ?> leads in this period</div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-label">Already converted</div>
      <div class="stat-value text-green" style="font-size:22px">AED <?= number_format($revConv, 0) ?></div>
      <div class="score-bar mt-2"><div class="score-bar-fill" style="width:<?= round($revConv / $totalRevPot * 100) ?>%;background:var(--green)"></div></div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-label">Still in pipeline</div>
      <div class="stat-value text-amber" style="font-size:22px">AED <?= number_format($revPipeline, 0) ?></div>
      <div class="score-bar mt-2"><div class="score-bar-fill" style="width:<?= round($revPipeline / $totalRevPot * 100) ?>%;background:#D9A23F"></div></div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Sources + Lost -->
<div class="row g-3 page-section">
  <div class="col-xl-8">
    <div class="card-crm h-100">
      <div class="card-header-crm"><span><i class="bi bi-signpost-split"></i> Source performance</span></div>
      <div class="table-responsive">
        <table class="table table-hover">
          <thead><tr><th>Source</th><th class="text-end">Leads</th><th class="text-end">Converted</th><th class="text-end">Lost</th><th>Conversion</th><th class="text-end">Avg score</th></tr></thead>
          <tbody>
          <?php foreach ($sourceConv as $s): $rate = (float)($s['rate'] ?? 0); ?>
          <tr>
            <td><a href="/pages/leads.php?source=<?= urlencode($s['source']) ?>" class="source-label"><?= sourceBadge($s['source']) ?></a></td>
            <td class="text-end tabular fw-600"><?= $s['total'] ?></td>
            <td class="text-end tabular text-green"><?= $s['converted'] ?></td>
            <td class="text-end tabular text-tx-3"><?= $s['lost'] ?></td>
            <td style="min-width:150px">
              <div class="score-cell">
                <span class="tabular fw-600" style="width:44px"><?= $rate ?>%</span>
                <div class="score-bar"><div class="score-bar-fill" style="width:<?= min(100, $rate) ?>%;background:var(--green)"></div></div>
              </div>
            </td>
            <td class="text-end"><?= scoreChip((int)$s['avg_score']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($sourceConv)): ?><tr><td colspan="6"><div class="empty-state py-4"><p>No leads in this period.</p></div></td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="card-crm h-100">
      <div class="card-header-crm"><span><i class="bi bi-x-circle"></i> Why leads were lost</span><span class="card-header-sub"><?= $totalLostCount ?> lost</span></div>
      <div class="card-body-crm">
        <?php if (empty($lostByReason)): ?>
        <div class="empty-state py-3"><i class="bi bi-emoji-smile"></i><p>No lost leads in this period.</p></div>
        <?php else: $lostTotal = array_sum(array_column($lostByReason, 'cnt'));
          foreach ($lostByReason as $l): $pct = $lostTotal > 0 ? round($l['cnt'] / $lostTotal * 100) : 0; ?>
        <div class="mb-3">
          <div class="d-flex justify-content-between align-items-baseline mb-1 fs-13 gap-2">
            <span class="text-tx-1 text-truncate" title="<?= sanitize($l['reason']) ?>"><?= sanitize($l['reason']) ?></span>
            <span class="tabular flex-shrink-0"><strong><?= $l['cnt'] ?></strong> <span class="text-tx-3 fs-12"><?= $pct ?>%</span></span>
          </div>
          <div class="score-bar"><div class="score-bar-fill" style="width:<?= $pct ?>%;background:var(--red);opacity:.7"></div></div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Meta ads -->
<div class="card-crm page-section">
  <div class="card-header-crm"><span><i class="bi bi-meta"></i> Meta ads performance</span><span class="card-header-sub">Meta form leads created in this period, by campaign and ad · where they are now</span></div>
  <div class="table-responsive">
    <table class="table table-hover">
      <thead><tr><th>Campaign / ad</th><th class="text-end">Leads</th><th class="text-end">Contacted</th><th class="text-end">Qualified</th><th class="text-end">Booked</th><th class="text-end">Patients</th><th class="text-end">Lost / cold</th><th>Booking rate</th></tr></thead>
      <tbody>
      <?php foreach ($adCampaigns as $name => $c):
        $rows = count($c['ads']) > 1 || $c['ads'][0]['ad'] !== '(ad not recorded)' ? array_merge(array($c + array('campaign' => $name, 'ad' => null)), $c['ads']) : array($c + array('campaign' => $name, 'ad' => null));
        foreach ($rows as $r): $rate = $r['total'] ? round($r['booked'] / $r['total'] * 100, 1) : 0; $isAd = $r['ad'] !== null; ?>
      <tr>
        <td class="<?= $isAd ? 'ps-4 text-tx-2' : 'fw-600' ?>"><?= $isAd ? '<i class="bi bi-arrow-return-right text-tx-3"></i> ' . sanitize($r['ad']) : sanitize($name) ?></td>
        <td class="text-end tabular <?= $isAd ? '' : 'fw-600' ?>"><?= (int)$r['total'] ?></td>
        <td class="text-end tabular"><?= (int)$r['contacted'] ?></td>
        <td class="text-end tabular"><?= (int)$r['qualified'] ?></td>
        <td class="text-end tabular"><?= (int)$r['booked'] ?></td>
        <td class="text-end tabular text-green fw-600"><?= (int)$r['converted'] ?></td>
        <td class="text-end tabular text-tx-3"><?= (int)$r['lost'] ?></td>
        <td style="min-width:150px">
          <div class="score-cell">
            <span class="tabular fw-600" style="width:44px"><?= $rate ?>%</span>
            <div class="score-bar"><div class="score-bar-fill" style="width:<?= min(100, $rate) ?>%;background:var(--green)"></div></div>
          </div>
        </td>
      </tr>
      <?php endforeach; endforeach; ?>
      <?php if (empty($adCampaigns)): ?><tr><td colspan="8"><div class="empty-state py-4"><p>No Meta form leads in this period.</p></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Treatments -->
<div class="row g-3 page-section">
  <div class="col-xl-8">
    <div class="card-crm h-100">
      <div class="card-header-crm"><span><i class="bi bi-clipboard2-pulse"></i> Treatment demand</span><span class="card-header-sub">Interested leads · value at average price</span></div>
      <div class="table-responsive" style="max-height:420px">
        <table class="table table-hover">
          <thead><tr><th>Treatment</th><th>Interested leads</th><th class="text-end">Converted</th><th class="text-end">Avg price</th><th class="text-end">Potential</th></tr></thead>
          <tbody>
          <?php $tMax = !empty($treatDemand) && $treatDemand[0]['lead_count'] > 0 ? (int)$treatDemand[0]['lead_count'] : 1;
          foreach ($treatDemand as $t): ?>
          <tr>
            <td class="fw-500"><?= sanitize($t['name']) ?></td>
            <td style="min-width:140px"><div class="score-cell"><span class="tabular" style="width:26px"><?= $t['lead_count'] ?></span><div class="score-bar"><div class="score-bar-fill" style="width:<?= round($t['lead_count'] / $tMax * 100) ?>%;background:var(--gold)"></div></div></div></td>
            <td class="text-end tabular text-green"><?= (int)$t['converted'] ?></td>
            <td class="text-end tabular text-tx-2"><?= (float)$t['avg_price'] > 0 ? 'AED ' . number_format($t['avg_price'], 0) : '<span class="text-tx-3">Not set</span>' ?></td>
            <td class="text-end tabular fw-600 text-gold"><?= (float)$t['revenue_potential'] > 0 ? 'AED ' . number_format($t['revenue_potential'], 0) : '—' ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($treatDemand)): ?><tr><td colspan="5"><div class="empty-state py-4"><p>No treatment interest recorded.</p></div></td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="card-crm h-100">
      <div class="card-header-crm"><span><i class="bi bi-pie-chart"></i> Top treatments</span></div>
      <div class="card-body-crm">
        <?php if ($topT): ?>
        <div class="mx-auto mb-3" style="height:180px;max-width:180px"><canvas id="treatChart"></canvas></div>
        <ul class="legend-list">
          <?php foreach ($topT as $i => $t): ?>
          <li><span class="swatch" data-series="<?= $i ?>"></span><span class="text-truncate"><?= sanitize($t['name']) ?></span><span class="val"><?= $t['lead_count'] ?></span></li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <div class="empty-state py-4"><i class="bi bi-pie-chart"></i><p>No data.</p></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Timing -->
<div class="row g-3 page-section">
  <div class="col-lg-6">
    <div class="card-crm h-100">
      <div class="card-header-crm"><span><i class="bi bi-calendar-week"></i> Leads by day of week</span></div>
      <div class="card-body-crm"><div style="height:220px"><canvas id="dowChart"></canvas></div></div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card-crm h-100">
      <div class="card-header-crm"><span><i class="bi bi-clock"></i> Leads by hour</span><span class="card-header-sub">When to have staff available</span></div>
      <div class="card-body-crm"><div style="height:220px"><canvas id="hourChart"></canvas></div></div>
    </div>
  </div>
</div>

<!-- Team -->
<div class="card-crm page-section">
  <div class="card-header-crm"><span><i class="bi bi-trophy"></i> Team performance</span><span class="card-header-sub">Leads created in this period, by owner</span></div>
  <div class="table-responsive">
    <table class="table table-hover">
      <thead><tr><th style="width:56px">#</th><th>Staff</th><th class="text-end">Assigned</th><th class="text-end">Booked</th><th class="text-end">Converted</th><th class="text-end">Lost</th><th class="text-end">Follow-ups</th><th>Conversion</th><th class="text-end">Avg score</th></tr></thead>
      <tbody>
      <?php foreach ($staffBoard as $i => $sp): $cr = (float)($sp['conv_rate'] ?? 0); ?>
      <tr>
        <td><span class="avatar avatar-sm <?= $i === 0 && $sp['converted'] > 0 ? 'tone-gold' : 'tone-slate' ?>"><?= $i + 1 ?></span></td>
        <td class="cell-title"><?= sanitize($sp['name']) ?></td>
        <td class="text-end tabular"><?= $sp['assigned'] ?></td>
        <td class="text-end tabular text-amber"><?= (int)$sp['booked'] ?></td>
        <td class="text-end tabular text-green fw-600"><?= (int)$sp['converted'] ?></td>
        <td class="text-end tabular text-tx-3"><?= (int)$sp['lost'] ?></td>
        <td class="text-end tabular"><?= $sp['followups'] ?></td>
        <td style="min-width:140px"><div class="score-cell"><span class="tabular fw-600" style="width:44px"><?= $cr ?>%</span><div class="score-bar"><div class="score-bar-fill" style="width:<?= min(100, $cr) ?>%;background:var(--green)"></div></div></div></td>
        <td class="text-end"><?= scoreChip((int)($sp['avg_score'] ?? 0)) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Trend + Aging -->
<div class="row g-3">
  <div class="col-xl-7">
    <div class="card-crm h-100">
      <div class="card-header-crm"><span><i class="bi bi-graph-up"></i> Last 6 months</span><span class="card-header-sub">Not affected by the date filter</span></div>
      <div class="card-body-crm"><div style="height:220px"><canvas id="momChart"></canvas></div></div>
      <div class="table-responsive border-top">
        <table class="table table-sm">
          <thead><tr><th>Month</th><th class="text-end">Leads</th><th class="text-end">Converted</th><th class="text-end">Lost</th><th class="text-end">Rate</th></tr></thead>
          <tbody>
          <?php foreach (array_reverse($mom) as $m): ?>
          <tr>
            <td class="fw-500"><?= sanitize($m['label']) ?></td>
            <td class="text-end tabular"><?= $m['total'] ?></td>
            <td class="text-end tabular text-green"><?= $m['converted'] ?></td>
            <td class="text-end tabular text-tx-3"><?= $m['lost'] ?></td>
            <td class="text-end tabular fw-600"><?= $m['rate'] ?>%</td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="card-crm h-100">
      <div class="card-header-crm"><span><i class="bi bi-hourglass-split"></i> Open leads by aging</span><span class="card-header-sub">Right now</span></div>
      <div class="table-responsive">
        <table class="table heatmap text-center">
          <thead><tr><th class="text-start">Stage</th><th>Fresh</th><th>Due</th><th>Overdue</th><th>Critical</th><th>Total</th></tr></thead>
          <tbody>
          <?php foreach (['new','contacted','qualified','booked'] as $st):
            $row = $agingMap[$st] ?? [];
            $vals = ['fresh' => (int)($row['fresh'] ?? 0), 'due' => (int)($row['due'] ?? 0), 'overdue' => (int)($row['overdue'] ?? 0), 'critical' => (int)($row['critical'] ?? 0)];
            $tot = array_sum($vals); if ($tot === 0) continue; ?>
          <tr>
            <td class="text-start fw-500"><?= ucfirst($st) ?></td>
            <?php foreach ($vals as $ag => $c): ?>
            <td><?php if ($c): ?><a href="/pages/leads.php?stage=<?= $st ?>&aging=<?= $ag ?>" class="heat aging-<?= $ag ?>"><?= $c ?></a><?php else: ?><span class="text-tx-3">·</span><?php endif; ?></td>
            <?php endforeach; ?>
            <td class="fw-600 tabular"><?= $tot ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer-crm fs-12 text-tx-3"><i class="bi bi-info-circle"></i> Fresh: contacted in the last 24h · Due: 2–3 days · Overdue: 3–7 days · Critical: 7+ days. Click a number to open those leads.</div>
    </div>
  </div>
</div>

<?php
$extraJs = "
document.querySelectorAll('.legend-list .swatch').forEach(el => el.style.background = CRM.series[el.dataset.series % CRM.series.length]);
const C = CRM.colors;
new Chart(document.getElementById('dailyChart'), {
  type: 'bar',
  data: { labels: " . json_encode($dailyLabels) . ", datasets: [
    { label: 'New leads', data: " . json_encode($dailyCounts) . ", backgroundColor: C.gold, maxBarThickness: 22, order: 2 },
    { label: 'Converted', data: " . json_encode($dailyConv) . ", type: 'line', borderColor: C.green, backgroundColor: C.green, tension: .35, pointRadius: 0, order: 1 }
  ]},
  options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
    plugins: { legend: { position: 'top', align: 'end' } },
    scales: { x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } }, y: { beginAtZero: true, ticks: { precision: 0, maxTicksLimit: 5 } } } }
});
new Chart(document.getElementById('dowChart'), {
  type: 'bar',
  data: { labels: " . json_encode(array_map(fn($d) => substr($d, 0, 3), array_column($byDow, 'day_name'))) . ", datasets: [
    { label: 'Leads', data: " . json_encode(array_map('intval', array_column($byDow, 'cnt'))) . ", backgroundColor: C.gold, maxBarThickness: 28 },
    { label: 'Converted', data: " . json_encode(array_map('intval', array_column($byDow, 'conv'))) . ", backgroundColor: C.green, maxBarThickness: 28 }
  ]},
  options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top', align: 'end' } },
    scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0, maxTicksLimit: 5 } } } }
});
new Chart(document.getElementById('hourChart'), {
  type: 'bar',
  data: { labels: " . json_encode($hourLbls) . ", datasets: [{ label: 'Leads', data: " . json_encode(array_values($hourData)) . ", backgroundColor: C.blue, maxBarThickness: 14 }] },
  options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
    scales: { x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 12 } }, y: { beginAtZero: true, ticks: { precision: 0, maxTicksLimit: 5 } } } }
});
const treatEl = document.getElementById('treatChart');
if (treatEl) new Chart(treatEl, {
  type: 'doughnut',
  data: { labels: " . json_encode(array_column($topT, 'name')) . ", datasets: [{ data: " . json_encode(array_map('intval', array_column($topT, 'lead_count'))) . ", backgroundColor: CRM.series, borderColor: CRM.colors.surface, borderWidth: 2 }] },
  options: { responsive: true, maintainAspectRatio: false, cutout: '70%', plugins: { legend: { display: false } } }
});
new Chart(document.getElementById('momChart'), {
  type: 'bar',
  data: { labels: " . json_encode(array_column($mom, 'label')) . ", datasets: [
    { label: 'Leads', data: " . json_encode(array_map('intval', array_column($mom, 'total'))) . ", backgroundColor: C.gold, maxBarThickness: 26 },
    { label: 'Converted', data: " . json_encode(array_map('intval', array_column($mom, 'converted'))) . ", backgroundColor: C.green, maxBarThickness: 26 },
    { label: 'Lost', data: " . json_encode(array_map('intval', array_column($mom, 'lost'))) . ", backgroundColor: '#D9A0A0', maxBarThickness: 26 }
  ]},
  options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top', align: 'end' } },
    scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0, maxTicksLimit: 5 } } } }
});
";
require_once __DIR__ . '/../includes/footer.php';
?>
