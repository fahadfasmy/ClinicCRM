<?php
// pages/bulk_upload.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requirePermission('leads.import');

$db = getDB();

// Download template CSV
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="leads_bulk_upload_template.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM: makes Excel open the file as UTF-8 so Arabic displays correctly
    // Header row
    fputcsv($out, [
        'name', 'phone', 'email', 'source', 'stage',
        'budget_range', 'urgency', 'gender', 'age_range',
        'treatments', 'notes', 'source_campaign', 'referrer_name'
    ]);
    // Example rows
    fputcsv($out, [
        'Sarah Ahmed', '+971501234567', 'sarah@email.com', 'instagram', 'new',
        '1000_3000', 'within_month', 'female', '25-34',
        'Botox, Dermal Fillers', 'Interested in full face refresh', 'Summer Campaign', ''
    ]);
    fputcsv($out, [
        'Lina Khalid', '+971559876543', '', 'whatsapp', 'contacted',
        '500_1000', 'immediate', 'female', '35-44',
        'HydraFacial', 'Called via WhatsApp, very interested', '', ''
    ]);
    fputcsv($out, [
        'John Smith', '+971521112222', 'john@email.com', 'referral', 'new',
        'unknown', 'just_browsing', 'male', 'unknown',
        '', '', '', 'Dr. Hana referral'
    ]);
    // Instructions
    fputcsv($out, []);
    fputcsv($out, ['--- VALID VALUES ---']);
    fputcsv($out, ['source:', 'meta_form, whatsapp, instagram, facebook, phone_call, walkin, referral, other']);
    fputcsv($out, ['stage:', 'new, contacted, qualified, booked, converted, lost, cold']);
    fputcsv($out, ['budget_range:', 'under_500, 500_1000, 1000_3000, 3000_plus, unknown']);
    fputcsv($out, ['urgency:', 'immediate, within_month, 3_months, just_browsing, unknown']);
    fputcsv($out, ['gender:', 'male, female, other, unknown']);
    fputcsv($out, ['age_range:', '18-24, 25-34, 35-44, 45-54, 55+, unknown']);
    fputcsv($out, ['treatments:', 'Comma-separated treatment names matching your treatments list']);
    fclose($out);
    exit;
}

$results  = [];
$summary  = ['total' => 0, 'imported' => 0, 'skipped' => 0, 'errors' => [], 'notes' => []];

$uploaded = false;

// Get valid sources and treatments for validation
$validSources    = ['meta_form','whatsapp','instagram','facebook','phone_call','walkin','referral','other'];
$validStages     = ['new','contacted','qualified','booked','converted','lost','cold'];
$validBudgets    = ['under_500','500_1000','1000_3000','3000_plus','unknown'];
$validUrgency    = ['immediate','within_month','3_months','just_browsing','unknown'];
$validGenders    = ['male','female','other','unknown'];
$validAgeRanges  = ['18-24','25-34','35-44','45-54','55+','unknown'];

// Treatment name → id lookup
$treatmentMap = [];
foreach ($db->query("SELECT id, name FROM treatments WHERE is_active=1")->fetchAll() as $t) {
    $treatmentMap[strtolower(trim($t['name']))] = $t['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $uploaded  = true;
    $file      = $_FILES['csv_file'];
    $assignTo  = (int)($_POST['assign_to'] ?? 0) ?: null;
    $skipDupes = isset($_POST['skip_duplicates']);

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $summary['errors'][] = 'File upload failed. Error code: ' . $file['error'];
    } elseif (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        $summary['errors'][] = 'Only CSV files are accepted.';
    } else {
        $handle = openCsvAsUtf8($file['tmp_name'], $csvEncoding);
        if ($handle && $csvEncoding !== 'UTF-8') {
            $summary['notes'][] = "File was saved as {$csvEncoding}; it was converted to UTF-8 so Arabic names are kept.";
        }
        if (!$handle) {
            $summary['errors'][] = 'Could not read uploaded file.';
        } else {
            $header   = null;
            $rowNum   = 0;
            $insStmt  = $db->prepare("
                INSERT INTO leads
                  (name, phone, email, source, stage, budget_range, urgency,
                   gender, age_range, notes, source_campaign, referrer_name, assigned_to)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $insTreat = $db->prepare("INSERT IGNORE INTO lead_treatments (lead_id, treatment_id) VALUES (?,?)");

            while (($row = fgetcsv($handle, 2000, ',')) !== false) {
                $rowNum++;

                // Skip empty rows and comment rows
                if (empty(array_filter($row))) continue;
                if (isset($row[0]) && str_starts_with(trim($row[0]), '---')) continue;
                if (isset($row[0]) && strtolower(trim($row[0])) === 'source:') continue;
                if (isset($row[0]) && strtolower(trim($row[0])) === 'stage:') continue;
                if (isset($row[0]) && strtolower(trim($row[0])) === 'budget_range:') continue;
                if (isset($row[0]) && strtolower(trim($row[0])) === 'urgency:') continue;
                if (isset($row[0]) && strtolower(trim($row[0])) === 'gender:') continue;
                if (isset($row[0]) && strtolower(trim($row[0])) === 'age_range:') continue;
                if (isset($row[0]) && strtolower(trim($row[0])) === 'treatments:') continue;

                // First real row = header
                if ($header === null) {
                    $header = array_map('strtolower', array_map('trim', $row));
                    continue;
                }

                $summary['total']++;
                $data = [];
                foreach ($header as $i => $col) {
                    $data[$col] = trim($row[$i] ?? '');
                }

                $name  = $data['name']  ?? '';
                $phone = $data['phone'] ?? '';

                // Required fields
                if (!$name || !$phone) {
                    $results[] = ['row' => $rowNum, 'name' => $name ?: '(empty)', 'status' => 'error', 'msg' => 'Name and phone are required'];
                    $summary['errors'][] = "Row {$rowNum}: Name and phone required";
                    $summary['skipped']++;
                    continue;
                }

                // Duplicate check
                $dup = findDuplicateLead($phone, $db);
                if ($dup) {
                    if ($skipDupes) {
                        $results[] = ['row' => $rowNum, 'name' => $name, 'status' => 'skipped', 'msg' => 'Duplicate phone — existing lead: ' . $dup['name']];
                        $summary['skipped']++;
                        continue;
                    }
                }

                // Sanitise & validate values
                $source    = in_array($data['source'] ?? '', $validSources)     ? $data['source']     : 'other';
                $stage     = in_array($data['stage']  ?? '', $validStages)      ? $data['stage']      : 'new';
                $budget    = in_array($data['budget_range'] ?? '', $validBudgets) ? $data['budget_range'] : 'unknown';
                $urgency   = in_array($data['urgency'] ?? '', $validUrgency)    ? $data['urgency']    : 'unknown';
                $gender    = in_array($data['gender']  ?? '', $validGenders)    ? $data['gender']     : 'unknown';
                $ageRange  = in_array($data['age_range'] ?? '', $validAgeRanges) ? $data['age_range'] : 'unknown';
                $email     = $data['email']            ?? '';
                $notes     = $data['notes']            ?? '';
                $campaign  = $data['source_campaign']  ?? '';
                $referrer  = $data['referrer_name']    ?? '';

                try {
                    $insStmt->execute([
                        $name, $phone, $email ?: null, $source, $stage,
                        $budget, $urgency, $gender, $ageRange, $notes ?: null,
                        $campaign ?: null, $referrer ?: null, $assignTo
                    ]);
                    $leadId = (int)$db->lastInsertId();

                    // Map treatments
                    if (!empty($data['treatments'])) {
                        $treatNames = array_map('trim', explode(',', $data['treatments']));
                        foreach ($treatNames as $tn) {
                            $key = strtolower($tn);
                            if (isset($treatmentMap[$key])) {
                                $insTreat->execute([$leadId, $treatmentMap[$key]]);
                            }
                        }
                    }

                    updateLeadScore($leadId, $db);
                    $results[]  = ['row' => $rowNum, 'name' => $name, 'status' => 'success', 'msg' => 'Imported (ID #' . $leadId . ')'];
                    $summary['imported']++;
                } catch (PDOException $e) {
                    $results[]  = ['row' => $rowNum, 'name' => $name, 'status' => 'error', 'msg' => $e->getMessage()];
                    $summary['errors'][] = "Row {$rowNum}: DB error";
                    $summary['skipped']++;
                }
            }
            fclose($handle);
        }
    }
}

$allStaff = $db->query("SELECT id, name FROM staff WHERE is_active=1 ORDER BY name")->fetchAll();
$pageTitle    = 'Bulk upload';
$pageSubtitle = 'Import many leads at once from a CSV file.';
$pageActions  = '<a href="?template=1" class="btn btn-ghost"><i class="bi bi-download"></i>Download template</a>';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row g-3">

  <!-- Upload form -->
  <div class="col-xl-4">
    <div class="card-crm form-side">
      <div class="card-header-crm"><span><i class="bi bi-cloud-arrow-up"></i> Upload CSV</span></div>
      <form method="POST" enctype="multipart/form-data">
        <div class="card-body-crm">
          <label class="dropzone mb-3" id="dropzone">
            <input type="file" name="csv_file" accept=".csv" required id="csvInput">
            <i class="bi bi-filetype-csv"></i>
            <span class="dz-title" id="dzTitle">Choose a CSV file</span>
            <span class="dz-sub">or drag it here · max 2 MB</span>
          </label>

          <div class="mb-3">
            <label class="form-label" for="assignTo">Assign all leads to</label>
            <select name="assign_to" id="assignTo" class="form-select">
              <option value="">Unassigned</option>
              <?php foreach ($allStaff as $s): ?>
              <option value="<?= $s['id'] ?>"><?= sanitize($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <label class="form-check mb-0">
            <input class="form-check-input" type="checkbox" name="skip_duplicates" checked>
            <span class="form-check-label">Skip phone numbers that already exist</span>
          </label>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-gold w-100"><i class="bi bi-upload"></i>Upload &amp; import</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Results / instructions -->
  <div class="col-xl-8">
    <?php if ($uploaded): ?>

    <div class="row g-3 page-section">
      <?php foreach ([
        ['Rows processed', $summary['total'], 'bi-list-ol', 'tone-blue'],
        ['Imported', $summary['imported'], 'bi-check2-circle', 'tone-green'],
        ['Skipped or failed', $summary['skipped'], 'bi-skip-forward', $summary['skipped'] ? 'tone-amber' : 'tone-slate'],
      ] as [$lbl, $val, $icon, $tone]): ?>
      <div class="col-4">
        <div class="stat-card">
          <div class="stat-top"><div class="stat-label"><?= $lbl ?></div><span class="stat-icon <?= $tone ?>"><i class="bi <?= $icon ?>"></i></span></div>
          <div class="stat-value"><?= $val ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php foreach ($summary['notes'] as $note): ?>
    <div class="alert alert-info"><i class="bi bi-translate"></i><div><?= sanitize($note) ?></div></div>
    <?php endforeach; ?>

    <?php if ($summary['imported'] > 0): ?>
    <div class="alert alert-success">
      <i class="bi bi-check-circle-fill"></i>
      <div><?= $summary['imported'] ?> leads imported. <a href="/pages/leads.php" class="alert-link">View leads →</a></div>
    </div>
    <?php endif; ?>

    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-clipboard-check"></i> Import results</span></div>
      <div class="table-responsive" style="max-height:520px">
        <table class="table table-sm">
          <thead class="sticky-top"><tr><th>Row</th><th>Name</th><th>Status</th><th>Message</th></tr></thead>
          <tbody>
          <?php foreach ($results as $r): ?>
          <tr>
            <td class="text-tx-3 tabular"><?= $r['row'] ?></td>
            <td class="fw-500"><?= sanitize($r['name']) ?></td>
            <td>
              <?php if ($r['status'] === 'success'): ?><span class="chip aging-fresh">Imported</span>
              <?php elseif ($r['status'] === 'skipped'): ?><span class="chip aging-due">Skipped</span>
              <?php else: ?><span class="chip aging-critical">Error</span><?php endif; ?>
            </td>
            <td class="text-tx-2 fs-12"><?= sanitize($r['msg']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($results)): ?>
          <tr><td colspan="4"><div class="empty-state py-4"><p>No rows were processed.</p></div></td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php else: ?>

    <div class="card-crm page-section">
      <div class="card-header-crm"><span><i class="bi bi-list-check"></i> How it works</span></div>
      <div class="card-body-crm">
        <ol class="steps">
          <li>Download the <a href="?template=1">CSV template</a>. It includes example rows and every valid value.</li>
          <li>Fill in one lead per row in Excel or Google Sheets. Keep the header row.</li>
          <li>For <strong>treatments</strong>, list names separated by commas, exactly as they appear on the Treatments page (e.g. <code>Botox, Hydra Facial</code>).</li>
          <li>Save as <strong>CSV</strong> (not XLSX) and upload it on the left.</li>
          <li>Review the results. Every skipped or failed row shows the reason.</li>
        </ol>
      </div>
    </div>

    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-table"></i> Columns</span></div>
      <table class="table table-sm">
        <thead><tr><th>Column</th><th>Accepted values</th></tr></thead>
        <tbody>
          <tr><td><code class="slug">name</code> <span class="req text-red">*</span></td><td class="text-tx-2">Full name</td></tr>
          <tr><td><code class="slug">phone</code> <span class="req text-red">*</span></td><td class="text-tx-2">Any format, e.g. +971501234567</td></tr>
          <tr><td><code class="slug">source</code></td><td class="text-tx-2 fs-12"><?= implode(', ', $validSources) ?></td></tr>
          <tr><td><code class="slug">stage</code></td><td class="text-tx-2 fs-12"><?= implode(', ', $validStages) ?></td></tr>
          <tr><td><code class="slug">budget_range</code></td><td class="text-tx-2 fs-12"><?= implode(', ', $validBudgets) ?></td></tr>
          <tr><td><code class="slug">urgency</code></td><td class="text-tx-2 fs-12"><?= implode(', ', $validUrgency) ?></td></tr>
          <tr><td><code class="slug">gender</code></td><td class="text-tx-2 fs-12"><?= implode(', ', $validGenders) ?></td></tr>
          <tr><td><code class="slug">age_range</code></td><td class="text-tx-2 fs-12">18-24, 25-34, 35-44, 45-54, 55+, unknown</td></tr>
          <tr><td><code class="slug">email</code>, <code class="slug">treatments</code>, <code class="slug">notes</code>, <code class="slug">source_campaign</code>, <code class="slug">referrer_name</code></td><td class="text-tx-2 fs-12">Free text</td></tr>
        </tbody>
      </table>
    </div>

    <?php endif; ?>
  </div>
</div>

<?php
$extraJs = "
const dz = document.getElementById('dropzone'), fi = document.getElementById('csvInput');
fi.addEventListener('change', () => { document.getElementById('dzTitle').textContent = fi.files[0] ? fi.files[0].name : 'Choose a CSV file'; dz.classList.toggle('has-file', !!fi.files[0]); });
['dragenter','dragover'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.add('drag'); }));
['dragleave','drop'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.remove('drag'); }));
dz.addEventListener('drop', e => { if (e.dataTransfer.files.length) { fi.files = e.dataTransfer.files; fi.dispatchEvent(new Event('change')); } });
";
require_once __DIR__ . '/../includes/footer.php';
?>
