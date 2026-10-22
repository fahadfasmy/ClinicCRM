<?php
// includes/doc_layouts.php — document layouts: the starter layouts (made the first time they are needed), drafts,
// publishing (an immutable version each time), defaults per document type / branch / invoice type, layout images,
// the data a document is rendered from (documentData) or sample data for previews, and the stored copy made at issue.
// The designer only changes presentation: amounts, rounding, numbering and VAT come from the billing code.
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/doc_required.php';
require_once __DIR__ . '/doc_render.php';

const LAYOUT_DOC_TYPES = ['invoice' => 'Tax invoice', 'receipt' => 'Receipt', 'credit_note' => 'Credit note'];
const LAYOUT_ASSET_MAX = 512 * 1024;

// ── Starter layouts ──

/** A block with the catalogue's default fields and a style. */
function layoutBlock(string $type, int $w, array $extra = []): array {
    [, , $fields, $props] = LAYOUT_BLOCKS[$type];
    $b = ['id' => $type . '_' . bin2hex(random_bytes(3)), 'type' => $type, 'w' => $w, 'cond' => '', 'style' => ['align' => 'start']];
    if ($fields) $b['show'] = array_map('boolval', $fields);
    if (in_array('height', $props, true)) $b['height'] = 18;
    if ($type === 'lines') {
        $on = $extra['cols'] ?? ['no', 'description', 'qty', 'unit_price', 'discount', 'taxable', 'vat_rate', 'vat_amount', 'total'];
        $b['columns'] = array_map(fn($k) => ['key' => $k, 'on' => in_array($k, $on, true), 'label' => ['en' => LINE_COLUMNS[$k][0], 'ar' => LINE_COLUMNS[$k][1]], 'w' => LINE_COLUMNS[$k][2], 'align' => LINE_COLUMNS[$k][3]],
                                    array_unique([...$on, ...array_keys(LINE_COLUMNS)]));
        $b['stripe'] = $extra['stripe'] ?? false; $b['borders'] = true;
        unset($extra['cols'], $extra['stripe']);
    }
    if (isset($extra['show'])) { $b['show'] = array_merge($b['show'] ?? [], $extra['show']); unset($extra['show']); }
    if (isset($extra['style'])) { $b['style'] = array_merge($b['style'], $extra['style']); unset($extra['style']); }
    return array_merge($b, $extra);
}

/** The starter layouts: [key => [name, document type, page, header, body, footer]]. */
function starterLayouts(): array {
    $row = fn(...$blocks) => ['id' => 'r' . bin2hex(random_bytes(3)), 'blocks' => $blocks];
    $page = fn(string $size, float $font, array $more = []) => array_merge(['size' => $size, 'orientation' => 'portrait', 'language' => 'both', 'font' => 'DM Sans', 'font_size' => $font,
        'color' => '#1d1b18', 'accent' => '#b8925a', 'watermark' => '', 'stamps' => true, 'footer_pinned' => false,
        'margins' => $size === 'roll80' ? ['t' => 3, 'r' => 3, 'b' => 3, 'l' => 3] : ['t' => 12, 'r' => 12, 'b' => 12, 'l' => 12]], $more);
    $title = fn(string $type, array $extra = []) => layoutBlock('title', 4, array_merge_recursive(['text' => ['en' => DOC_TITLES[$type][0], 'ar' => DOC_TITLES[$type][1]], 'style' => ['align' => 'end']], $extra));
    $thanks = layoutBlock('footer_text', 8, ['text' => ['en' => 'Thank you for choosing us.', 'ar' => 'شكراً لاختياركم.']]);
    $out = [];
    foreach (['invoice', 'credit_note'] as $t) {
        $details = layoutBlock('details', 6, ['show' => ['branch' => true, 'invoice_ref' => $t === 'credit_note']]);
        $lineCols = $t === 'credit_note' ? ['no', 'description', 'qty', 'taxable', 'vat_rate', 'vat_amount', 'total'] : null;
        $lines = layoutBlock('lines', 12, $lineCols ? ['cols' => $lineCols] : []);
        $out["classic_$t"] = ['Classic', $t, $page('A4', 10),
            [$row(layoutBlock('logo', 3), layoutBlock('clinic', 5), $title($t)), $row(layoutBlock('patient', 6), $details)],
            [$row($lines), $row(layoutBlock('words', 7), layoutBlock('totals', 5, ['show' => ['paid' => false, 'balance' => false]])), $row(layoutBlock('vat_summary', 6))],
            [$row($thanks, layoutBlock('page_number', 4, ['style' => ['align' => 'end']]))]];
    }
    $out['modern_invoice'] = ['Modern', 'invoice', $page('A4', 10, ['accent' => '#3a5a78']),
        [$row(layoutBlock('clinic', 8, ['style' => ['color' => '#ffffff', 'bg' => '#3a5a78', 'pad' => 4]]), $title('invoice', ['style' => ['color' => '#ffffff', 'bg' => '#3a5a78', 'pad' => 4]])),
         $row(layoutBlock('patient', 4), layoutBlock('visit', 4), layoutBlock('details', 4))],
        [$row(layoutBlock('lines', 12, ['stripe' => true])), $row(layoutBlock('notes', 7, ['text' => ['en' => '', 'ar' => '']]), layoutBlock('totals', 5, ['show' => ['paid' => true, 'balance' => true]]))],
        [$row($thanks, layoutBlock('page_number', 4, ['style' => ['align' => 'end']]))]];
    $out['compact_invoice'] = ['Compact (A5)', 'invoice', $page('A5', 8.5, ['margins' => ['t' => 8, 'r' => 8, 'b' => 8, 'l' => 8]]),
        [$row(layoutBlock('clinic', 7, ['show' => ['phone' => false]]), $title('invoice', ['w' => 5])), $row(layoutBlock('patient', 6, ['show' => ['name_ar' => false]]), layoutBlock('details', 6))],
        [$row(layoutBlock('lines', 12, ['cols' => ['description', 'qty', 'unit_price', 'discount', 'taxable', 'vat_rate', 'vat_amount', 'total']])), $row(layoutBlock('totals', 12, ['style' => ['align' => 'end']]))],
        []];
    $out['roll_invoice'] = ['Receipt printer (80 mm)', 'invoice', $page('roll80', 7.5, ['language' => 'en']),
        [$row(layoutBlock('clinic', 12, ['show' => ['address' => true], 'style' => ['align' => 'center']])), $row(layoutBlock('title', 12, ['text' => ['en' => 'Tax Invoice', 'ar' => 'فاتورة ضريبية'], 'style' => ['align' => 'center']])),
         $row(layoutBlock('details', 12)), $row(layoutBlock('patient', 12, ['show' => ['name_ar' => false]]))],
        [$row(layoutBlock('lines', 12, ['cols' => ['description', 'qty', 'unit_price', 'discount', 'taxable', 'vat_rate', 'vat_amount', 'total']])), $row(layoutBlock('totals', 12))],
        [$row(layoutBlock('footer_text', 12, ['text' => ['en' => 'Thank you', 'ar' => 'شكراً'], 'style' => ['align' => 'center']]))]];
    $out['classic_receipt'] = ['Classic', 'receipt', $page('A4', 10),
        [$row(layoutBlock('logo', 3), layoutBlock('clinic', 5), $title('receipt')), $row(layoutBlock('patient', 6, ['show' => ['name_ar' => false]]), layoutBlock('details', 6, ['show' => ['cashier' => true, 'invoice_ref' => false]]))],
        [$row(layoutBlock('payments', 12)), $row(layoutBlock('words', 7), layoutBlock('totals', 5, ['show' => ['subtotal' => false, 'discount' => false, 'taxable' => false, 'vat' => false]]))],
        [$row($thanks)]];
    $out['roll_receipt'] = ['Receipt printer (80 mm)', 'receipt', $page('roll80', 7.5, ['language' => 'en']),
        [$row(layoutBlock('clinic', 12, ['show' => ['trn' => false], 'style' => ['align' => 'center']])), $row(layoutBlock('title', 12, ['text' => ['en' => 'Receipt', 'ar' => 'إيصال'], 'style' => ['align' => 'center']])), $row(layoutBlock('details', 12, ['show' => ['cashier' => true, 'invoice_ref' => false]]))],
        [$row(layoutBlock('payments', 12)), $row(layoutBlock('totals', 12, ['show' => ['subtotal' => false, 'discount' => false, 'taxable' => false, 'vat' => false]]))], []];
    return $out;
}

/** Creates and publishes the starter layouts once (Classic is the default for each document type). */
function ensureStarterLayouts(PDO $db): void {
    if ($db->query("SELECT COUNT(*) FROM document_layouts WHERE starter IS NOT NULL")->fetchColumn()) return;
    foreach (starterLayouts() as $key => [$name, $type, $page, $header, $body, $footer]) {
        $id = createLayout($db, $type, $name, ['page' => $page, 'header' => $header, 'body' => $body, 'footer' => $footer], $key);
        publishLayout($db, $id);
        if (str_starts_with($key, 'classic_')) setDefaultLayout($db, $id);
    }
}

// ── Layouts ──

function findLayout(PDO $db, int $id): ?array {
    $s = $db->prepare("SELECT l.*, v.version AS current_version FROM document_layouts l LEFT JOIN document_layout_versions v ON v.id = l.current_version_id WHERE l.id = ?");
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function layoutAssetIds(PDO $db): array { return array_map('intval', $db->query("SELECT id FROM document_layout_assets")->fetchAll(PDO::FETCH_COLUMN)); }
function branchIds(PDO $db): array { return array_map('intval', $db->query("SELECT id FROM branches")->fetchAll(PDO::FETCH_COLUMN)); }

/** Validates a layout against the schema with this clinic's branches and images. */
function checkLayout(PDO $db, $json): array {
    return validateLayout(is_string($json) ? json_decode($json, true) : $json, branchIds($db), layoutAssetIds($db));
}

function createLayout(PDO $db, string $type, string $name, $json, ?string $starter = null): int {
    if (!isset(LAYOUT_DOC_TYPES[$type])) throw new RuntimeException('Unknown document type.');
    $name = trim($name);
    if ($name === '') throw new RuntimeException('Give the layout a name.');
    $L = checkLayout($db, $json);
    if ($starter === null) planCheck($db, 'layouts');
    $db->prepare("INSERT INTO document_layouts (document_type, name, language, page_size, orientation, draft_json, draft_saved_at, starter, created_by, updated_by) VALUES (?,?,?,?,?,?, NOW(), ?,?,?)")
       ->execute([$type, mb_substr($name, 0, 100), $L['page']['language'], $L['page']['size'], $L['page']['orientation'], json_encode($L, JSON_UNESCAPED_UNICODE), $starter, currentStaffId() ?: null, currentStaffId() ?: null]);
    $id = (int)$db->lastInsertId();
    auditLog($db, 'layout', $id, 'created', ['name' => $name, 'type' => $type]);
    return $id;
}

/** Saves the designer's draft. Returns what would still stop publishing (empty = ready). */
function saveLayoutDraft(PDO $db, int $id, $json, array $meta = []): array {
    $l = findLayout($db, $id);
    if (!$l || $l['status'] === 'archived') throw new RuntimeException('Layout not found or archived.');
    $L = checkLayout($db, $json);
    $name = trim((string)($meta['name'] ?? $l['name'])) ?: $l['name'];
    $itype = in_array($meta['invoice_type'] ?? '', ['any', 'cash', 'insurance'], true) ? $meta['invoice_type'] : $l['invoice_type'];
    $branch = array_key_exists('branch_id', $meta) ? ((int)$meta['branch_id'] && in_array((int)$meta['branch_id'], branchIds($db), true) ? (int)$meta['branch_id'] : null) : $l['branch_id'];
    $db->prepare("UPDATE document_layouts SET draft_json = ?, draft_saved_at = NOW(), name = ?, invoice_type = ?, branch_id = ?, language = ?, page_size = ?, orientation = ?, updated_by = ? WHERE id = ?")
       ->execute([json_encode($L, JSON_UNESCAPED_UNICODE), mb_substr($name, 0, 100), $itype, $branch, $L['page']['language'], $L['page']['size'], $L['page']['orientation'], currentStaffId() ?: null, $id]);
    auditLog($db, 'layout', $id, 'edited', []);
    return layoutRequiredProblems($L, $l['document_type']);
}

/** Publishes the draft as a new, never-changing version. Refused with the list of what is missing. */
function publishLayout(PDO $db, int $id): int {
    $l = findLayout($db, $id);
    if (!$l || $l['status'] === 'archived') throw new RuntimeException('Layout not found or archived.');
    $L = checkLayout($db, $l['draft_json']);
    if ($problems = layoutRequiredProblems($L, $l['document_type'])) throw new RuntimeException("This layout can’t be published yet:\n• " . implode("\n• ", $problems));
    $json = json_encode($L, JSON_UNESCAPED_UNICODE);
    return stockTx($db, function () use ($db, $id, $json) {
        $db->prepare("SELECT id FROM document_layouts WHERE id = ? FOR UPDATE")->execute([$id]);
        $v = (int)$db->query("SELECT COALESCE(MAX(version), 0) + 1 FROM document_layout_versions WHERE layout_id = $id")->fetchColumn();
        $db->prepare("INSERT INTO document_layout_versions (layout_id, version, layout_json, content_hash, published_by) VALUES (?,?,?,?,?)")
           ->execute([$id, $v, $json, hash('sha256', $json), currentStaffId() ?: null]);
        $vid = (int)$db->lastInsertId();
        $db->prepare("UPDATE document_layouts SET current_version_id = ?, status = 'published' WHERE id = ?")->execute([$vid, $id]);
        auditLog($db, 'layout', $id, 'published', ['version' => $v]);
        return $vid;
    });
}

function duplicateLayout(PDO $db, int $id): int {
    $l = findLayout($db, $id);
    if (!$l) throw new RuntimeException('Layout not found.');
    return createLayout($db, $l['document_type'], 'Copy of ' . $l['name'], $l['draft_json']);
}

/** The default for its document type (and branch / invoice type, when the layout is limited to them). */
function setDefaultLayout(PDO $db, int $id): void {
    $l = findLayout($db, $id);
    if (!$l || $l['status'] !== 'published') throw new RuntimeException('Only a published layout can be the default.');
    $db->prepare("UPDATE document_layouts SET is_default = 0 WHERE document_type = ? AND invoice_type = ? AND branch_id <=> ?")->execute([$l['document_type'], $l['invoice_type'], $l['branch_id']]);
    $db->prepare("UPDATE document_layouts SET is_default = 1 WHERE id = ?")->execute([$id]);
    auditLog($db, 'layout', $id, 'set_default', []);
}

function archiveLayout(PDO $db, int $id): void {
    $l = findLayout($db, $id);
    if (!$l) throw new RuntimeException('Layout not found.');
    if ($l['is_default']) throw new RuntimeException('This is a default layout. Make another layout the default first.');
    $db->prepare("UPDATE document_layouts SET status = 'archived' WHERE id = ?")->execute([$id]);
    auditLog($db, 'layout', $id, 'archived', []);
}

/** The published version to print a document with: the most specific default (branch, then invoice type). */
function chooseLayoutVersion(PDO $db, string $type, ?int $branchId = null, string $invoiceType = 'cash'): array {
    ensureStarterLayouts($db);
    $s = $db->prepare("SELECT v.*, l.name, l.language FROM document_layouts l JOIN document_layout_versions v ON v.id = l.current_version_id
                       WHERE l.document_type = ? AND l.status = 'published' AND l.is_default = 1 AND (l.branch_id IS NULL OR l.branch_id = ?) AND l.invoice_type IN ('any', ?)
                       ORDER BY l.branch_id IS NULL, l.invoice_type = 'any', l.id LIMIT 1");
    $s->execute([$type, $branchId, $invoiceType]);
    $v = $s->fetch();
    if (!$v) {   // no default for this type: the newest published layout of it
        $s = $db->prepare("SELECT v.*, l.name, l.language FROM document_layouts l JOIN document_layout_versions v ON v.id = l.current_version_id WHERE l.document_type = ? AND l.status = 'published' ORDER BY l.id LIMIT 1");
        $s->execute([$type]);
        $v = $s->fetch();
    }
    if (!$v) throw new RuntimeException('No published layout for ' . LAYOUT_DOC_TYPES[$type] . '.');
    return $v;
}

function findLayoutVersion(PDO $db, int $versionId): ?array {
    $s = $db->prepare("SELECT v.*, l.name, l.language, l.document_type FROM document_layout_versions v JOIN document_layouts l ON l.id = v.layout_id WHERE v.id = ?");
    $s->execute([$versionId]);
    return $s->fetch() ?: null;
}

// ── Images (logo, stamp, signature) ──

/** Stores an image for layouts: PNG or JPEG by content, 512 KB and 3000 px at most, location/camera data removed. */
function saveLayoutAsset(PDO $db, string $bytes): int {
    if (!can('setup.layouts')) throw new RuntimeException('You don’t have permission to change layouts.');
    if ($bytes === '' || strlen($bytes) > LAYOUT_ASSET_MAX) throw new RuntimeException('The image must be smaller than 512 KB.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg'][$mime] ?? null;
    $size = $ext ? @getimagesizefromstring($bytes) : false;
    if (!$ext || !$size || $size[0] < 1 || $size[0] > 3000 || $size[1] < 1 || $size[1] > 3000) throw new RuntimeException('Upload a PNG or JPG image (up to 3000 × 3000 pixels).');
    // ponytail: no re-encode (GD isn't installed here); metadata is stripped and the type is checked by content. Re-encode once GD is available.
    $bytes = stripImageMetadata($bytes, $mime);
    $rel = 'layouts/' . bin2hex(random_bytes(16)) . '.' . $ext;
    $full = tenantStoragePath() . '/' . $rel;
    if (!is_dir(dirname($full)) && !mkdir(dirname($full), 0750, true)) throw new RuntimeException("Can't create the layouts folder.");
    if (file_put_contents($full, $bytes) === false) throw new RuntimeException('Saving the image failed.');
    $db->prepare("INSERT INTO document_layout_assets (path, mime, size, width, height, created_by) VALUES (?,?,?,?,?,?)")->execute([$rel, $mime, strlen($bytes), $size[0], $size[1], currentStaffId() ?: null]);
    $id = (int)$db->lastInsertId();
    auditLog($db, 'layout_asset', $id, 'uploaded', ['size' => strlen($bytes)]);
    return $id;
}

/** The images a layout uses, as data: URLs (so a stored document carries its images with it). */
function layoutAssetUrls(PDO $db, array $L): array {
    $ids = [];
    foreach (['header', 'body', 'footer'] as $a) foreach ($L[$a] ?? [] as $r) foreach ($r['blocks'] as $b) if (!empty($b['asset'])) $ids[] = (int)$b['asset'];
    if (!$ids) return [];
    $base = realpath(tenantStoragePath());
    $out = [];
    foreach ($db->query("SELECT * FROM document_layout_assets WHERE id IN (" . implode(',', array_unique($ids)) . ")") as $a) {
        $f = realpath($base . '/' . $a['path']);
        if ($f && str_starts_with($f, $base . DIRECTORY_SEPARATOR)) $out[(int)$a['id']] = 'data:' . $a['mime'] . ';base64,' . base64_encode((string)file_get_contents($f));
    }
    return $out;
}

// ── The data a document is printed from ──

function clinicBlockData(PDO $db, ?int $branchId): array {
    $b = $branchId ? (branchList($db, true)[$branchId] ?? []) : [];
    return [['name' => clinicName(), 'address' => ($b['address'] ?? '') ?: tenantSetting('clinic.address'), 'phone' => ($b['phone'] ?? '') ?: tenantSetting('clinic.phone'),
             'email' => ($b['email'] ?? '') ?: tenantSetting('clinic.email'), 'website' => '', 'trn' => $b['trn'] ?? '', 'dha' => $b['dha_license'] ?? ''],
            ['id' => $branchId, 'name' => $b['name'] ?? '']];
}

function patientBlockData(PDO $db, ?int $pid): array {
    $p = $pid ? findPatient($db, $pid) : null;
    if (!$p) return [];
    $ph = patientPhones($db, (int)$p['id']);
    return ['name' => patientName($p), 'name_ar' => $p['name_ar'] ?? '', 'mrn' => $p['mrn'], 'phone' => $ph[0]['phone'] ?? '', 'eid_last4' => $p['eid_last4'] ?? ''];
}

/** documentData for an issued invoice, receipt or credit note (see the shape in doc_render.php). */
function documentData(PDO $db, string $type, int $id): array {
    $d = ['doc' => ['type' => $type], 'insurance' => [], 'visit' => [], 'lines' => [], 'vat_summary' => [], 'payments' => [], 'allocations' => [], 'flags' => []];
    $vatSum = function (array $lines) { $g = []; foreach ($lines as $l) { $k = (string)(float)$l['vat_rate']; $g[$k] ??= ['rate' => (float)$l['vat_rate'], 'taxable' => 0, 'vat' => 0]; $g[$k]['taxable'] += (float)$l['taxable']; $g[$k]['vat'] += (float)$l['vat_amount']; } return array_values($g); };
    if ($type === 'invoice') {
        $i = findInvoice($db, $id);
        $s = $db->prepare("SELECT s.name FROM staff s WHERE s.id = ?"); $s->execute([$i['issued_by']]); $cashier = (string)$s->fetchColumn();
        $a = $i['appointment_id'] ? $db->query("SELECT a.scheduled_at, a.completed_at, st.name AS doctor FROM appointments a LEFT JOIN staff st ON st.id = a.practitioner_id WHERE a.id = " . (int)$i['appointment_id'])->fetch() : null;
        $supply = $a && $a['completed_at'] && substr($a['completed_at'], 0, 10) !== substr((string)$i['issued_at'], 0, 10) ? $a['completed_at'] : '';
        $d['doc'] += ['number' => $i['number'], 'issue_date' => $i['issued_at'], 'supply_date' => $supply, 'due_date' => '', 'cashier' => $cashier, 'invoice_ref' => '', 'invoice_type' => $i['type'] === 'insurance' ? 'Insurance' : 'Cash', 'status' => $i['status']];
        [$d['clinic'], $d['branch']] = clinicBlockData($db, (int)$i['branch_id']);
        $d['patient'] = patientBlockData($db, (int)$i['patient_id']);
        if ($a) $d['visit'] = ['doctor' => $a['doctor'], 'department' => '', 'visit_date' => $a['scheduled_at']];
        if ($i['patient_insurance_id']) {
            $ins = $db->query("SELECT c.name, pi.policy_no, pi.member_no, pa.approval_no FROM patient_insurance pi JOIN insurance_plans p ON p.id = pi.plan_id JOIN insurance_companies c ON c.id = p.company_id
                               LEFT JOIN prior_auths pa ON pa.id = " . (int)$i['prior_auth_id'] . " WHERE pi.id = " . (int)$i['patient_insurance_id'])->fetch();
            if ($ins) $d['insurance'] = ['company' => $ins['name'], 'policy' => $ins['policy_no'], 'member' => $ins['member_no'], 'approval' => $ins['approval_no']];
        }
        $lines = invoiceLines($db, $id);
        foreach ($lines as $n => $l) $d['lines'][] = ['no' => $n + 1, 'code' => $l['cpt_code'], 'description' => $l['description'], 'sub' => '', 'practitioner' => $l['practitioner_name'],
            'qty' => $l['qty'], 'unit_price' => $l['unit_price'], 'discount' => $l['discount'], 'taxable' => $l['taxable'], 'vat_rate' => $l['vat_rate'], 'vat_amount' => $l['vat_amount'], 'total' => $l['line_total']];
        $d['vat_summary'] = $vatSum($lines);
        $bal = invoiceBalance($i);
        $d['totals'] = ['subtotal' => $i['gross'], 'discount' => $i['discount'], 'taxable' => $i['net'], 'vat' => $i['vat'], 'total' => $i['total'], 'paid' => aed(fils($i['paid']) - fils($i['refunded'])), 'balance' => aed(max(0, $bal))];
        $p = $db->prepare("SELECT r.number, a.amount FROM receipt_allocations a JOIN receipts r ON r.id = a.receipt_id AND r.status = 'valid' WHERE a.invoice_id = ? ORDER BY r.id"); $p->execute([$id]);
        foreach ($p as $r) $d['payments'][] = ['method' => 'Receipt ' . $r['number'], 'reference' => '', 'amount' => $r['amount']];
        $d['flags'] = ['insurance' => $i['type'] === 'insurance', 'has_discount' => fils($i['discount']) > 0, 'has_balance' => $bal > 0];
        $total = fils($i['total']);
    } elseif ($type === 'receipt') {
        $r = findReceipt($db, $id);
        $d['doc'] += ['number' => $r['number'], 'issue_date' => $r['received_at'], 'supply_date' => '', 'due_date' => '', 'cashier' => $r['received_by_name'], 'invoice_ref' => '', 'invoice_type' => '', 'status' => $r['status']];
        [$d['clinic'], $d['branch']] = clinicBlockData($db, (int)$r['branch_id']);
        $d['patient'] = patientBlockData($db, $r['patient_id'] ? (int)$r['patient_id'] : null) ?: ['name' => (string)$r['notes']];
        $m = $db->prepare("SELECT * FROM receipt_methods WHERE receipt_id = ? ORDER BY id"); $m->execute([$id]);
        foreach ($m as $x) $d['payments'][] = ['method' => PAY_METHODS[$x['method']] ?? $x['method'], 'reference' => trim($x['reference'] . ($x['cheque_date'] ? ' · ' . $x['cheque_date'] : '')), 'amount' => $x['amount']];
        $a = $db->prepare("SELECT i.number, a.amount FROM receipt_allocations a JOIN invoices i ON i.id = a.invoice_id WHERE a.receipt_id = ? ORDER BY i.id"); $a->execute([$id]);
        $d['allocations'] = $a->fetchAll();
        $kept = fils($r['total']) - array_sum(array_map(fn($x) => fils($x['amount']), $d['allocations']));
        if ($kept > 0) $d['allocations'][] = ['number' => $r['voucher_id'] ? 'Gift voucher' : 'Kept as balance', 'amount' => aed($kept)];
        $d['totals'] = ['subtotal' => $r['total'], 'discount' => 0, 'taxable' => 0, 'vat' => 0, 'total' => $r['total'], 'paid' => $r['total'], 'balance' => 0];
        $total = fils($r['total']);
    } else {
        $s = $db->prepare("SELECT c.*, i.number AS inv_number, i.issued_at AS inv_date, i.patient_id, i.type AS inv_type, st.name AS cashier FROM credit_notes c JOIN invoices i ON i.id = c.invoice_id LEFT JOIN staff st ON st.id = c.issued_by WHERE c.id = ?");
        $s->execute([$id]); $c = $s->fetch();
        $d['doc'] += ['number' => $c['number'], 'issue_date' => $c['issued_at'], 'supply_date' => '', 'due_date' => '', 'cashier' => $c['cashier'], 'invoice_ref' => $c['inv_number'] . ' (' . date('d M Y', strtotime($c['inv_date'])) . ')', 'invoice_type' => '', 'status' => 'issued'];
        [$d['clinic'], $d['branch']] = clinicBlockData($db, (int)$c['branch_id']);
        $d['patient'] = patientBlockData($db, (int)$c['patient_id']);
        $l = $db->prepare("SELECT c.*, l.description, l.cpt_code, l.unit_price, l.vat_rate FROM credit_note_lines c JOIN invoice_lines l ON l.id = c.invoice_line_id WHERE c.credit_note_id = ? ORDER BY c.id"); $l->execute([$id]);
        $rows = $l->fetchAll();
        foreach ($rows as $n => $x) $d['lines'][] = ['no' => $n + 1, 'code' => $x['cpt_code'], 'description' => $x['description'], 'sub' => '', 'practitioner' => '', 'qty' => $x['qty'], 'unit_price' => $x['unit_price'],
            'discount' => 0, 'taxable' => $x['amount'], 'vat_rate' => $x['vat_rate'], 'vat_amount' => $x['vat_amount'], 'total' => aed(fils($x['amount']) + fils($x['vat_amount']))];
        $d['vat_summary'] = $vatSum(array_map(fn($x) => ['vat_rate' => $x['vat_rate'], 'taxable' => $x['amount'], 'vat_amount' => $x['vat_amount']], $rows));
        $d['totals'] = ['subtotal' => $c['gross'], 'discount' => 0, 'taxable' => $c['gross'], 'vat' => $c['vat'], 'total' => $c['total'], 'paid' => $c['refunded'], 'balance' => 0];
        $d['flags'] = ['insurance' => $c['inv_type'] === 'insurance'];
        $total = fils($c['total']);
    }
    $d['amount_in_words'] = ['en' => amountWordsEn($total), 'ar' => amountWordsAr($total)];
    return $d;
}

/** Made-up data for previews; $lines items (60 to check page breaks). */
function sampleDocumentData(string $type, int $lines = 4): array {
    $names = ['Anti-wrinkle, upper face', 'HydraFacial', 'Lip filler 1 ml', 'Consultation', 'Aftercare cream', 'Laser hair removal, full legs'];
    $d = ['doc' => ['type' => $type, 'number' => 'ABC-' . ['invoice' => 'INV', 'receipt' => 'RCT', 'credit_note' => 'CN'][$type] . '-2026-000123', 'issue_date' => date('Y-m-d H:i'), 'supply_date' => '', 'due_date' => '',
                    'cashier' => 'Rana', 'invoice_ref' => $type === 'credit_note' ? 'ABC-INV-2026-000100 (' . date('d M Y', strtotime('-3 days')) . ')' : '', 'invoice_type' => 'Cash', 'status' => 'issued'],
          'clinic' => ['name' => clinicName(), 'address' => tenantSetting('clinic.address') ?: "Villa 12, Al Wasl Road\nJumeirah, Dubai", 'phone' => tenantSetting('clinic.phone') ?: '+971 4 000 0000',
                       'email' => tenantSetting('clinic.email') ?: 'info@example.ae', 'website' => '', 'trn' => '100000000000003', 'dha' => 'DHA-F-0000000'],
          'branch' => ['id' => 0, 'name' => 'Main branch'], 'patient' => ['name' => 'Hana Ahmed', 'name_ar' => 'هناء أحمد', 'mrn' => 'MRN-000123', 'phone' => '+971501234567', 'eid_last4' => '1234'],
          'insurance' => ['company' => 'Example Insurance', 'policy' => 'POL-55501', 'member' => 'M-889912', 'approval' => 'PA-2231'],
          'visit' => ['doctor' => 'Dr. Sara Khan', 'department' => 'Dermatology', 'visit_date' => date('Y-m-d')], 'lines' => [], 'payments' => [], 'allocations' => [], 'flags' => ['has_discount' => true, 'has_balance' => false]];
    $tot = ['gross' => 0, 'disc' => 0, 'net' => 0, 'vat' => 0, 'total' => 0]; $byRate = [];
    for ($n = 0; $n < ($type === 'receipt' ? 0 : $lines); $n++) {
        $unit = [120000, 35000, 150000, 30000, 8000, 90000][$n % 6]; $disc = $n === 1 ? 3500 : 0; $rate = $n % 6 === 3 ? 0.0 : 5.0;
        [$g, $dd, $net, $vat, $t] = lineAmounts(1, $unit, $disc, $rate, false);
        $d['lines'][] = ['no' => $n + 1, 'code' => $n % 2 ? '' : '1720' . $n, 'description' => $names[$n % 6], 'sub' => $n === 2 ? 'session 2 of 6' : '', 'practitioner' => 'Dr. Sara Khan', 'qty' => 1,
                         'unit_price' => aed($unit), 'discount' => aed($dd), 'taxable' => aed($net), 'vat_rate' => $rate, 'vat_amount' => aed($vat), 'total' => aed($t)];
        foreach (['gross' => $g, 'disc' => $dd, 'net' => $net, 'vat' => $vat, 'total' => $t] as $k => $v) $tot[$k] += $v;
        $byRate[(string)$rate] ??= ['rate' => $rate, 'taxable' => 0, 'vat' => 0]; $byRate[(string)$rate]['taxable'] += $net / 100; $byRate[(string)$rate]['vat'] += $vat / 100;
    }
    if ($type === 'receipt') { $tot['total'] = 105000; $d['payments'] = [['method' => 'Cash', 'reference' => '', 'amount' => '500.00'], ['method' => 'Card', 'reference' => '4242', 'amount' => '550.00']]; $d['allocations'] = [['number' => 'ABC-INV-2026-000123', 'amount' => '1050.00']]; }
    $d['vat_summary'] = array_values($byRate);
    $d['totals'] = ['subtotal' => aed($tot['gross']), 'discount' => aed($tot['disc']), 'taxable' => aed($tot['net']), 'vat' => aed($tot['vat']), 'total' => aed($tot['total']), 'paid' => aed($tot['total']), 'balance' => '0.00'];
    $d['amount_in_words'] = ['en' => amountWordsEn($tot['total']), 'ar' => amountWordsAr($tot['total'])];
    return $d;
}

// ── Stored copies ──

/** Renders a document with a layout version and stores it. The first copy (reason issue) is the original that reprints show;
 *  re-renders (another or a newer layout) are extra copies and never replace it. Returns the rendition id. */
function storeDocumentRendition(PDO $db, string $type, int $id, string $reason = 'issue', ?int $versionId = null): int {
    if ($versionId) { $v = findLayoutVersion($db, $versionId); if (!$v || $v['document_type'] !== $type) throw new RuntimeException('Choose a layout for this kind of document.'); }
    else {
        $meta = $type === 'credit_note' ? $db->query("SELECT c.branch_id, i.type FROM credit_notes c JOIN invoices i ON i.id = c.invoice_id WHERE c.id = $id")->fetch()
              : ($type === 'invoice' ? $db->query("SELECT branch_id, type FROM invoices WHERE id = $id")->fetch() : $db->query("SELECT branch_id, 'cash' AS type FROM receipts WHERE id = $id")->fetch());
        $v = chooseLayoutVersion($db, $type, (int)$meta['branch_id'], (string)$meta['type']);
    }
    $L = json_decode($v['layout_json'], true);
    $html = renderDocument($L, documentData($db, $type, $id), $L['page']['language'], layoutAssetUrls($db, $L));
    $db->prepare("INSERT INTO document_renditions (doc_type, doc_id, layout, layout_version_id, language, html, content_hash, is_original, reason, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
       ->execute([$type, $id, mb_substr($v['name'] . ' v' . $v['version'], 0, 40), $v['id'], $L['page']['language'], $html, hash('sha256', $html), $reason === 'issue' ? 1 : 0, $reason, currentStaffId() ?: null]);
    $rid = (int)$db->lastInsertId();
    if ($reason !== 'issue') auditLog($db, $type, $id, 'rerendered', ['rendition' => $rid, 'layout' => $v['name'] . ' v' . $v['version']]);
    return $rid;
}
