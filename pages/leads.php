<?php
// pages/leads.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/inventory.php';
require_once __DIR__ . '/../includes/reenquiry.php';
requireLogin();

$db = getDB();
ensureInventorySchema($db);
ensureReEnquirySchema($db);

// Handle delete (must run before any output so the redirect works)
if (isset($_GET['delete']) && can('leads.delete')) {
    $id = (int)$_GET['delete'];
    // Treatment records (and the batches used) must stay traceable, so leads with appointments can't be deleted
    $hasAppts = $db->prepare("SELECT COUNT(*) FROM appointments WHERE lead_id = ?");
    $hasAppts->execute([$id]);
    if ((int)$hasAppts->fetchColumn() > 0) {
        header('Location: /pages/lead_view.php?id=' . $id . '&nodelete=1');
        exit;
    }
    $db->prepare("DELETE FROM leads WHERE id=?")->execute([$id]);
    logActivity(currentStaffId(), 'lead_deleted', $id);
    header('Location: /pages/leads.php?deleted=1');
    exit;
}

$stageTabs = ['' => 'All', 'new' => 'New', 'contacted' => 'Contacted', 'qualified' => 'Qualified', 'booked' => 'Booked', 'converted' => 'Converted', 'lost' => 'Lost', 'cold' => 'Cold'];

// List or board view. An explicit choice is remembered; a stage link (e.g. from the dashboard) opens the list.
if (isset($_GET['view'])) {
    $view = $_GET['view'] === 'board' ? 'board' : 'list';
    setcookie('leads_view', $view, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);
} elseif (($_GET['stage'] ?? '') !== '') {
    $view = 'list';
} else {
    $view = ($_COOKIE['leads_view'] ?? '') === 'board' ? 'board' : 'list';
}
const BOARD_PAGE = 40;

if (!isset($_GET['more'])) updateAgingStatuses($db);

// Filters
$fStage  = $_GET['stage']  ?? '';
$fSource = $_GET['source'] ?? '';
$fAging  = $_GET['aging']  ?? '';
$fScore  = $_GET['score']  ?? '';
$fQuery  = trim($_GET['q'] ?? '');
$fStaff  = can('leads.all') ? ($_GET['staff'] ?? '') : currentStaffId();

$where = ['1=1'];
$params = [];

if ($fSource) { $where[] = 'l.source = ?';       $params[] = $fSource; }
if ($fAging === 'returning') { $where[] = REENQUIRY_OPEN_SQL; }
elseif ($fAging)  { $where[] = 'l.aging_status = ?'; $params[] = $fAging; }
if ($fStaff)  { $where[] = 'l.assigned_to = ?';  $params[] = $fStaff; }
if ($fScore === 'hot')      { $where[] = 'l.score >= 61'; }
if ($fScore === 'priority') { $where[] = 'l.score >= 86'; }
if ($fQuery !== '') {
    $like = '%' . $fQuery . '%';
    $digits = preg_replace('/\D/', '', $fQuery);
    $cond = '(l.name LIKE ? OR l.email LIKE ? OR l.phone LIKE ?';
    array_push($params, $like, $like, $like);
    if (strlen($digits) >= 4) {
        $cond .= ' OR REPLACE(REPLACE(REPLACE(l.phone," ",""),"-",""),"+","") LIKE ?';
        $cond .= ' OR EXISTS (SELECT 1 FROM lead_phones lp WHERE lp.lead_id=l.id AND REPLACE(REPLACE(REPLACE(lp.phone," ",""),"-",""),"+","") LIKE ?)';
        array_push($params, "%$digits%", "%$digits%");
    }
    $where[] = $cond . ')';
}

// Stage counts respect every filter except stage itself
$baseWhere = implode(' AND ', $where);
$stmt = $db->prepare("SELECT l.stage, COUNT(*) FROM leads l WHERE $baseWhere GROUP BY l.stage");
$stmt->execute($params);
$stageCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$totalAll = array_sum($stageCounts);

function boardLeads(PDO $db, string $where, array $params, string $stage, int $offset): array {
    $stmt = $db->prepare("
        SELECT l.id, l.name, l.phone, l.source, l.stage, l.score, l.aging_status, l.last_contacted, l.is_duplicate, l.reenquiry_at, l.reenquiry_note,
               s.name AS staff_name,
               (SELECT COUNT(*) FROM appointments a WHERE a.lead_id = l.id AND a.status IN ('booked','confirmed','arrived','in_triage','with_doctor')) AS open_appts,
               (SELECT a.id FROM appointments a WHERE a.lead_id = l.id AND a.status IN ('booked','confirmed','arrived','in_triage','with_doctor') ORDER BY a.scheduled_at LIMIT 1) AS next_appt_id,
               (SELECT MIN(a.scheduled_at) FROM appointments a WHERE a.lead_id = l.id AND a.status IN ('booked','confirmed','arrived','in_triage','with_doctor')) AS next_appt_at
        FROM leads l
        LEFT JOIN staff s ON s.id = l.assigned_to
        WHERE $where AND l.stage = ?
        ORDER BY l.score DESC, l.created_at DESC
        LIMIT " . BOARD_PAGE . " OFFSET " . max(0, $offset));
    $stmt->execute([...$params, $stage]);
    return $stmt->fetchAll();
}

function boardCard(array $l, array $stageTabs): string {
    $id     = (int)$l['id'];
    $url    = '/pages/lead_view.php?id=' . $id;
    $digits = preg_replace('/\D/', '', (string)$l['phone']);
    $aging  = $l['aging_status'] ?? 'fresh';
    ob_start(); ?>
<article class="kb-card<?= in_array($aging, ['overdue','critical'], true) ? ' kb-' . $aging : '' ?>" draggable="true"
         data-id="<?= $id ?>" data-stage="<?= sanitize($l['stage']) ?>" data-name="<?= sanitize($l['name']) ?>"
         data-open-appts="<?= (int)($l['open_appts'] ?? 0) ?>" data-next-appt="<?= (int)($l['next_appt_id'] ?? 0) ?>">
  <div class="kb-card-top">
    <a href="<?= $url ?>" class="kb-name" draggable="false"><?= sanitize($l['name']) ?></a>
    <?= scoreChip((int)$l['score']) ?>
  </div>
  <div class="kb-sub"><span class="cell-num"><?= sanitize((string)$l['phone']) ?></span><span class="kb-dot">·</span><span class="source-label"><?= sourceBadge($l['source']) ?></span></div>
  <div class="kb-foot">
    <?php if ($aging !== 'fresh'): ?><?= agingBadge($aging) ?><?php endif; ?>
    <?php if (!empty($l['is_duplicate'])): ?><span class="chip aging-due"><?= t('Duplicate') ?></span><?php endif; ?>
    <?= returningChip($l) ?>
    <?php if (!empty($l['next_appt_at'])): ?><a class="chip stage-booked" href="/pages/appointment.php?id=<?= (int)$l['next_appt_id'] ?>" draggable="false" title="Next treatment<?= $l['open_appts'] > 1 ? ' (' . (int)$l['open_appts'] . ' booked)' : '' ?>"><i class="bi bi-calendar-check"></i><?= date('d M', strtotime($l['next_appt_at'])) ?></a><?php endif; ?>
    <span class="kb-when" title="<?= t('Last contact') ?>"><i class="bi bi-clock-history"></i><?= timeSince($l['last_contacted']) ?></span>
    <span class="kb-spacer"></span>
    <?php if (strlen($digits) >= 8): ?>
    <a href="https://wa.me/<?= $digits ?>" target="_blank" rel="noopener" class="kb-act" title="<?= t('WhatsApp') ?>" aria-label="<?= t('Open WhatsApp chat') ?>" draggable="false"><i class="bi bi-whatsapp"></i></a>
    <?php endif; ?>
    <div class="dropdown">
      <button type="button" class="kb-act" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" title="<?= t('Move to stage') ?>" aria-label="<?= t('Move to stage') ?>"><i class="bi bi-arrow-left-right"></i></button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><h6 class="dropdown-header"><?= t('Move to') ?></h6></li>
        <?php foreach ($stageTabs as $st => $label): if ($st === '') continue; ?>
        <li><button type="button" class="dropdown-item kb-move<?= $st === $l['stage'] ? ' active' : '' ?>" data-to="<?= $st ?>"><span class="kb-swatch kb-swatch-<?= $st ?>"></span><?= $label ?></button></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <span class="avatar avatar-xs" title="<?= $l['staff_name'] ? sanitize($l['staff_name']) : 'Unassigned' ?>"><?= $l['staff_name'] ? sanitize(initials($l['staff_name'])) : '–' ?></span>
  </div>
</article>
<?php
    return ob_get_clean();
}

// Board: "Show more" in a column returns just the next batch of cards
if ($view === 'board' && isset($_GET['more'])) {
    $st = (string)$_GET['more'];
    if ($st === '' || !isset($stageTabs[$st])) { http_response_code(400); exit; }
    foreach (boardLeads($db, $baseWhere, $params, $st, (int)($_GET['offset'] ?? 0)) as $l) echo boardCard($l, $stageTabs);
    exit;
}

if ($view === 'board') $fStage = ''; // columns replace the stage filter

if ($fStage) { $where[] = 'l.stage = ?'; $params[] = $fStage; }
$whereSQL = implode(' AND ', $where);

// List: sorting + pagination happen in SQL so only one page of rows is loaded
$sortCols = [
    'name'    => 'l.name',
    'stage'   => "FIELD(l.stage,'new','contacted','qualified','booked','converted','lost','cold')",
    'score'   => 'l.score',
    'aging'   => "FIELD(l.aging_status,'fresh','due','overdue','critical')",
    'owner'   => 's.name',
    'contact' => 'l.last_contacted',
    'created' => 'l.created_at',
];
$sort    = isset($sortCols[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'score';
$dir     = ($_GET['dir'] ?? ($sort === 'name' || $sort === 'owner' ? 'asc' : 'desc')) === 'asc' ? 'asc' : 'desc';
$perPage = in_array((int)($_GET['per'] ?? 0), [25, 50, 100], true) ? (int)$_GET['per'] : 50;
$listTotal = $fStage ? (int)($stageCounts[$fStage] ?? 0) : $totalAll;
$pages   = max(1, (int)ceil($listTotal / $perPage));
$page    = min($pages, max(1, (int)($_GET['page'] ?? 1)));

$leads = [];
$board = [];
if ($view === 'list') {
    $leads = $db->prepare("
        SELECT l.*, s.name as staff_name
        FROM leads l
        LEFT JOIN staff s ON s.id = l.assigned_to
        WHERE $whereSQL
        ORDER BY {$sortCols[$sort]} " . strtoupper($dir) . ", l.id DESC
        LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
    $leads->execute($params);
    $leads = $leads->fetchAll();
} else {
    foreach ($stageTabs as $st => $_) {
        if ($st !== '') $board[$st] = boardLeads($db, $baseWhere, $params, $st, 0);
    }
}
$shownTotal = $view === 'board' ? $totalAll : $listTotal;

$allStaff = $db->query("SELECT id, name FROM staff WHERE is_active=1 ORDER BY name")->fetchAll();
try {
    $sourceOptions = $db->query("SELECT slug, name FROM lead_sources WHERE is_active=1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    $sourceOptions = [];
}
if (!$sourceOptions) {
    foreach (['meta_form','whatsapp','instagram','facebook','phone_call','walkin','referral','other'] as $s) $sourceOptions[$s] = ucfirst(str_replace('_',' ',$s));
}

$hasFilters = $fSource || $fAging || $fScore || $fQuery !== '' || (can('leads.all') && $fStaff);

function leadsUrl(array $changes): string {
    $q = array_merge($_GET, $changes);
    unset($q['delete'], $q['deleted']);
    $q = array_filter($q, fn($v) => $v !== '' && $v !== null);
    return '/pages/leads.php' . ($q ? '?' . http_build_query($q) : '');
}

/** Column header that sorts the list; clicking the active column flips the direction. */
function sortHeader(string $key, string $label, string $sort, string $dir, string $class = ''): string {
    $active = $sort === $key;
    $next   = $active ? ($dir === 'asc' ? 'desc' : 'asc') : (in_array($key, ['name', 'owner'], true) ? 'asc' : 'desc');
    $icon   = $active ? ($dir === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill') : 'bi-chevron-expand';
    $aria   = $active ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none';
    return '<th class="' . $class . '" aria-sort="' . $aria . '"><a href="' . sanitize(leadsUrl(['sort' => $key, 'dir' => $next, 'page' => ''])) . '" class="th-sort' . ($active ? ' active' : '') . '">'
         . $label . '<i class="bi ' . $icon . '"></i></a></th>';
}

/** Page numbers with gaps: 1 … 4 5 [6] 7 8 … 67 */
function pageList(int $page, int $pages): array {
    $out = [];
    foreach (range(1, $pages) as $p) {
        if ($p === 1 || $p === $pages || abs($p - $page) <= 2) $out[] = $p;
        elseif (end($out) !== '…') $out[] = '…';
    }
    return $out;
}

$pageTitle = 'Leads';
$pageSubtitle = $fQuery !== ''
    ? $shownTotal . ' result' . ($shownTotal == 1 ? '' : 's') . ' for “' . sanitize($fQuery) . '”'
    : number_format($shownTotal) . ' lead' . ($shownTotal == 1 ? '' : 's') . ($fStage ? ' · ' . $stageTabs[$fStage] : '');
$pageActions = (can('leads.import')
        ? '<a href="/pages/bulk_upload.php" class="btn btn-ghost"><i class="bi bi-cloud-arrow-up"></i>' . t('Import') . '</a>' : '')
    . '<a href="/pages/lead_add.php" class="btn btn-gold"><i class="bi bi-plus-lg"></i>' . t('Add lead') . '</a>';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-success alert-auto"><i class="bi bi-check-circle"></i> <?= t('Lead deleted.') ?></div>
<?php endif; ?>

<?php if ($view === 'list'): ?>
<div class="toolbar">
  <div class="segmented" role="tablist" aria-label="<?= t('Filter by stage') ?>">
    <?php foreach ($stageTabs as $key => $label):
      $cnt = $key === '' ? $totalAll : ($stageCounts[$key] ?? 0); ?>
    <a href="<?= leadsUrl(['stage' => $key, 'page' => '']) ?>" class="<?= $fStage === $key ? 'active' : '' ?>" role="tab" aria-selected="<?= $fStage === $key ? 'true' : 'false' ?>">
      <?= $label ?> <span class="count"><?= $cnt ?></span>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="toolbar">
  <select class="form-select form-select-sm" onchange="applyFilter('source',this.value)" aria-label="<?= t('Source') ?>">
    <option value=""><?= t('All sources') ?></option>
    <?php foreach ($sourceOptions as $slug => $name): ?>
    <option value="<?= sanitize($slug) ?>" <?= $fSource === $slug ? 'selected' : '' ?>><?= sanitize($name) ?></option>
    <?php endforeach; ?>
  </select>
  <select class="form-select form-select-sm" onchange="applyFilter('aging',this.value)" aria-label="<?= t('Aging') ?>">
    <option value=""><?= t('Any aging') ?></option>
    <?php foreach (['fresh','due','overdue','critical'] as $ag): ?>
    <option value="<?= $ag ?>" <?= $fAging === $ag ? 'selected' : '' ?>><?= ucfirst($ag) ?></option>
    <?php endforeach; ?>
    <option value="returning" <?= $fAging === 'returning' ? 'selected' : '' ?>><?= t('Returning enquiry') ?></option>
  </select>
  <select class="form-select form-select-sm" onchange="applyFilter('score',this.value)" aria-label="<?= t('Score') ?>">
    <option value=""><?= t('Any score') ?></option>
    <option value="hot" <?= $fScore === 'hot' ? 'selected' : '' ?>><?= t('Hot (61+)') ?></option>
    <option value="priority" <?= $fScore === 'priority' ? 'selected' : '' ?>><?= t('Priority (86+)') ?></option>
  </select>
  <?php if (can('leads.all')): ?>
  <select class="form-select form-select-sm" onchange="applyFilter('staff',this.value)" aria-label="<?= t('Assigned to') ?>">
    <option value=""><?= t('Everyone') ?></option>
    <?php foreach ($allStaff as $st): ?>
    <option value="<?= $st['id'] ?>" <?= $fStaff == $st['id'] ? 'selected' : '' ?>><?= sanitize($st['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>
  <?php if ($hasFilters): ?>
  <a href="<?= leadsUrl(['source'=>'','aging'=>'','score'=>'','staff'=>'','q'=>'','page'=>'']) ?>" class="filter-reset"><i class="bi bi-x-circle"></i> <?= t('Clear filters') ?></a>
  <?php endif; ?>
  <span class="toolbar-spacer"></span>
  <div class="segmented" aria-label="<?= t('View') ?>">
    <a href="<?= leadsUrl(['view' => 'list']) ?>" class="<?= $view === 'list' ? 'active' : '' ?>"><i class="bi bi-list-ul"></i><?= t('List') ?></a>
    <a href="<?= leadsUrl(['view' => 'board', 'stage' => '']) ?>" class="<?= $view === 'board' ? 'active' : '' ?>"><i class="bi bi-kanban"></i><?= t('Board') ?></a>
  </div>
</div>

<?php if ($view === 'board'): ?>
<div class="kb-board" id="kbBoard" data-more-url="<?= sanitize(leadsUrl(['view' => 'board', 'stage' => ''])) ?>" data-page="<?= BOARD_PAGE ?>">
  <?php foreach ($board as $st => $cards): $cnt = (int)($stageCounts[$st] ?? 0); ?>
  <section class="kb-col" data-stage="<?= $st ?>" data-total="<?= $cnt ?>" aria-label="<?= $stageTabs[$st] ?>">
    <header class="kb-col-head">
      <span class="kb-swatch kb-swatch-<?= $st ?>"></span>
      <span class="kb-col-title"><?= $stageTabs[$st] ?></span>
      <span class="kb-count"><?= number_format($cnt) ?></span>
    </header>
    <div class="kb-list">
      <?php foreach ($cards as $l) echo boardCard($l, $stageTabs); ?>
      <div class="kb-empty"<?= $cards ? ' hidden' : '' ?>><?= t('No leads') ?></div>
    </div>
    <button type="button" class="kb-more"<?= $cnt > count($cards) ? '' : ' hidden' ?>><?= t('Show more') ?></button>
  </section>
  <?php endforeach; ?>
</div>

<div class="modal fade" id="kbApptModal" tabindex="-1" aria-labelledby="kbApptTitle" aria-hidden="true" data-can-book="<?= can('appointments.book') ? 1 : 0 ?>" data-can-complete="<?= can('appointments.complete') ? 1 : 0 ?>">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="kbApptTitle"></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= t('Close') ?>"></button>
      </div>
      <div class="modal-body">
        <p class="mb-0" id="kbApptText"></p>
        <div class="form-check mt-3" id="kbApptCheckWrap" hidden>
          <input class="form-check-input" type="checkbox" id="kbApptCheck" checked>
          <label class="form-check-label" for="kbApptCheck" id="kbApptCheckLabel"></label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" id="kbApptMove"><?= t('Just move it') ?></button>
        <a class="btn btn-gold" id="kbApptGo" href="#"></a>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="kbLostModal" tabindex="-1" aria-labelledby="kbLostTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="kbLostForm">
      <div class="modal-header">
        <h5 class="modal-title" id="kbLostTitle"><?= t('Mark as lost') ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= t('Close') ?>"></button>
      </div>
      <div class="modal-body">
        <label class="form-label" for="kbLostReason"><?= t('Why didn\'t') ?> <b id="kbLostName"></b> <?= t('convert?') ?></label>
        <input type="text" class="form-control" id="kbLostReason" maxlength="255" placeholder="<?= t('e.g. Price, went elsewhere, no response') ?>">
        <div class="form-text"><?= t('Optional. Shows up in Analytics under lost reasons.') ?></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
        <button type="submit" class="btn btn-gold"><?= t('Mark as lost') ?></button>
      </div>
    </form>
  </div>
</div>
<?php else: ?>
<div class="card-crm">
  <div class="table-responsive">
  <table id="leadsTable" class="table table-hover">
    <thead>
      <tr>
        <?= sortHeader('name', 'Lead', $sort, $dir) ?>
        <th><?= t('Phone') ?></th>
        <th><?= t('Source') ?></th>
        <?= sortHeader('stage', 'Stage', $sort, $dir) ?>
        <?= sortHeader('score', 'Score', $sort, $dir) ?>
        <?= sortHeader('aging', 'Aging', $sort, $dir) ?>
        <?= sortHeader('owner', 'Owner', $sort, $dir) ?>
        <?= sortHeader('contact', 'Last contact', $sort, $dir) ?>
        <th class="text-end"><?= t('Actions') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($leads as $l): ?>
    <tr class="row-link <?= $l['aging_status'] !== 'fresh' ? 'aging-'.$l['aging_status'] : '' ?>" data-href="/pages/lead_view.php?id=<?= $l['id'] ?>">
      <td data-order="<?= sanitize($l['name']) ?>">
        <div class="d-flex align-items-center gap-2">
          <span class="avatar avatar-sm"><?= sanitize(initials($l['name'])) ?></span>
          <div class="min-w-0">
            <a href="/pages/lead_view.php?id=<?= $l['id'] ?>" class="cell-title"><?= sanitize($l['name']) ?></a>
            <?php if ($l['is_duplicate']): ?><span class="chip aging-due ms-1"><?= t('Duplicate') ?></span><?php endif; ?>
            <?= returningChip($l, 'ms-1') ?>
            <?php if (!empty($l['email'])): ?><span class="cell-sub"><?= sanitize($l['email']) ?></span><?php endif; ?>
          </div>
        </div>
      </td>
      <td class="cell-num text-nowrap"><?= sanitize($l['phone']) ?></td>
      <td><span class="source-label"><?= sourceBadge($l['source']) ?></span></td>
      <td data-order="<?= array_search($l['stage'], array_keys($stageTabs)) ?>"><?= stageBadge($l['stage']) ?></td>
      <td data-order="<?= (int)$l['score'] ?>">
        <div class="score-cell">
          <?= scoreChip((int)$l['score']) ?>
          <div class="score-bar"><div class="score-bar-fill" data-score="<?= (int)$l['score'] ?>"></div></div>
        </div>
      </td>
      <td data-order="<?= array_search($l['aging_status'], ['fresh','due','overdue','critical']) ?>"><?= agingBadge($l['aging_status']) ?></td>
      <td><?= $l['staff_name'] ? sanitize($l['staff_name']) : '<span class="text-tx-3">' . t('Unassigned') . '</span>' ?></td>
      <td class="text-nowrap text-tx-2" data-order="<?= $l['last_contacted'] ? strtotime($l['last_contacted']) : 0 ?>"><?= timeSince($l['last_contacted']) ?></td>
      <td class="text-end">
        <div class="row-actions">
          <a href="/pages/lead_edit.php?id=<?= $l['id'] ?>" class="btn-icon" title="<?= t('Edit') ?>" aria-label="<?= t('Edit lead') ?>"><i class="bi bi-pencil"></i></a>
          <?php if (can('leads.delete')): ?>
          <a href="/pages/leads.php?delete=<?= $l['id'] ?>" class="btn-icon danger" title="<?= t('Delete') ?>" aria-label="<?= t('Delete lead') ?>"
             data-confirm="Delete <?= sanitize($l['name']) ?> and all follow-ups? This cannot be undone."><i class="bi bi-trash3"></i></a>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$leads): ?>
    <tr><td colspan="9" class="table-empty"><?= t('No leads match these filters.') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
  <?php if ($listTotal > 0): ?>
  <div class="list-foot">
    <span class="list-foot-info"><?= number_format(($page - 1) * $perPage + 1) ?>–<?= number_format(min($page * $perPage, $listTotal)) ?> of <?= number_format($listTotal) ?></span>
    <label class="list-foot-per"><?= t('Show') ?>
      <select class="form-select form-select-sm" onchange="applyFilter('per', this.value === '50' ? '' : this.value)" aria-label="<?= t('Leads per page') ?>">
        <?php foreach ([25, 50, 100] as $n): ?><option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?>
      </select>
    </label>
    <?php if ($pages > 1): ?>
    <nav aria-label="<?= t('Leads pages') ?>">
      <ul class="pagination">
        <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= sanitize(leadsUrl(['page' => $page > 2 ? $page - 1 : ''])) ?>" aria-label="<?= t('Previous page') ?>"><i class="bi bi-chevron-left"></i></a></li>
        <?php foreach (pageList($page, $pages) as $p): ?>
          <?php if ($p === '…'): ?>
          <li class="page-item disabled"><span class="page-link">…</span></li>
          <?php else: ?>
          <li class="page-item<?= $p === $page ? ' active' : '' ?>"><a class="page-link" href="<?= sanitize(leadsUrl(['page' => $p > 1 ? $p : ''])) ?>"<?= $p === $page ? ' aria-current="page"' : '' ?>><?= $p ?></a></li>
          <?php endif; ?>
        <?php endforeach; ?>
        <li class="page-item<?= $page >= $pages ? ' disabled' : '' ?>"><a class="page-link" href="<?= sanitize(leadsUrl(['page' => min($pages, $page + 1)])) ?>" aria-label="<?= t('Next page') ?>"><i class="bi bi-chevron-right"></i></a></li>
      </ul>
    </nav>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php
$extraJs = ($view === 'board' ? "initLeadBoard(document.getElementById('kbBoard'));\n" : '') . "
function applyFilter(key, val) {
  const url = new URL(window.location);
  url.searchParams.delete('deleted');
  url.searchParams.delete('page');
  if (val) url.searchParams.set(key, val); else url.searchParams.delete(key);
  window.location = url;
}";
require_once __DIR__ . '/../includes/footer.php';
?>
