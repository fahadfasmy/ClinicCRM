<?php
// pages/stock_import.php — set up products and opening stock from a spreadsheet (CSV saved from Excel).
// Upload → preview every row (nothing saved) → import. Rows with problems are skipped and listed.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';
requirePermission('inventory.manage');

$db = getDB();
ensureInventorySchema($db);

const IMPORT_COLUMNS = [
    'products' => [
        'name' => 'Name', 'brand' => 'Brand', 'category' => 'Category', 'sku' => 'Code / SKU', 'gtin' => 'Barcode',
        'stock_unit' => 'Received as', 'usage_unit' => 'Used in', 'units_per_stock' => 'How many per pack',
        'storage' => 'Storage', 'prescription_only' => 'Prescription only', 'track_lots' => 'Track batches',
        'opened_use_hours' => 'Multi-dose hours', 'reorder_level' => 'Reorder level', 'reorder_qty' => 'Usual order quantity',
        'supplier' => 'Usual supplier', 'notes' => 'Notes',
    ],
    'stock' => [
        'product' => 'Product', 'lot_number' => 'Batch', 'expiry_date' => 'Expiry', 'qty' => 'Quantity',
        'unit_cost' => 'Cost per pack', 'supplier' => 'Supplier', 'invoice_ref' => 'Invoice',
    ],
    'treatments' => [
        'name' => 'Treatment', 'category' => 'Category', 'price' => 'Price (AED)', 'duration_min' => 'Minutes',
        'is_active' => 'Bookable', 'description' => 'Description',
    ],
];
const IMPORT_HELP = [
    'treatments' => 'Download the treatment menu, change the prices (or any other column) and upload it back. Rows match treatments by name; only filled cells are changed, so a file with just Treatment and Price (AED) columns works too. A name that isn’t on the menu yet is added as a new treatment without a recipe. New prices apply to all bookings from now on.',
    'products' => 'One row per product. Name is required. Received as / Used in / How many per pack: e.g. vial / units / 100. Storage: room, fridge or freezer. Yes/No columns accept yes, no, 1, 0. Multi-dose hours: leave empty unless one pack is shared between patients once opened. Rows whose barcode, code or name match an existing product update it; only filled cells are changed.',
    'stock'    => 'One row per batch on the shelf. Product: the product’s name, code or barcode. Quantity is in packs as received (e.g. 2.5 vials). Expiry: 2027-06-30, 30/06/2027 or 06/2027. Cold items are taken as stored correctly, since this is stock already in the clinic.',
];

/** Lower-case key without spaces or punctuation, for matching headers and names. */
function importKey(string $s): string {
    return preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim($s)));
}

function importBool(string $v, ?bool $default): ?bool {
    $k = importKey($v);
    if ($k === '') return $default;
    if (in_array($k, ['yes', 'y', '1', 'true', 'x'], true)) return true;
    if (in_array($k, ['no', 'n', '0', 'false'], true)) return false;
    throw new StockException('“' . $v . '” should be yes or no.');
}

function importNumber(string $v, string $label, bool $required = false): ?float {
    $v = str_replace(' ', '', trim($v));
    // "2,5" is a decimal comma (European Excel); "1,200" and "1,200.50" use the comma for thousands
    $v = preg_match('/^\d+,\d{1,2}$/', $v) ? str_replace(',', '.', $v) : str_replace(',', '', $v);
    if ($v === '') { if ($required) throw new StockException("$label is required."); return null; }
    if (!is_numeric($v) || (float)$v < 0) throw new StockException("$label must be a number, not “{$v}”.");
    return (float)$v;
}

/** 2027-06-30, 30/06/2027, 30-06-2027, 30.06.2027, 06/2027 (last day of month). */
function importDate(string $v): ?string {
    $v = trim($v);
    if ($v === '') return null;
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $v, $m)) [$y, $mo, $d] = [$m[1], $m[2], $m[3]];
    elseif (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})$#', $v, $m)) [$d, $mo, $y] = [$m[1], $m[2], strlen($m[3]) === 2 ? '20' . $m[3] : $m[3]];
    elseif (preg_match('#^(\d{1,2})[/.\-](\d{4})$#', $v, $m)) { [$mo, $y] = [$m[1], $m[2]]; $d = date('t', mktime(0, 0, 0, (int)$mo, 1, (int)$y)); }
    else throw new StockException('Expiry “' . $v . '” isn’t a date. Use 2027-06-30 or 30/06/2027.');
    if (!checkdate((int)$mo, (int)$d, (int)$y)) throw new StockException('Expiry “' . $v . '” isn’t a real date (day/month order is day first).');
    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}

/** Reads the CSV into rows keyed by field, matching headers by their label or field name. */
function readImportCsv(string $path, string $kind, ?string &$encoding): array {
    $h = openCsvAsUtf8($path, $encoding);
    if (!$h) throw new StockException('The file couldn’t be read.');
    $first = fgets($h);
    rewind($h);
    $delim = substr_count((string)$first, ';') > substr_count((string)$first, ',') ? ';' : ',';
    $header = fgetcsv($h, 0, $delim);
    if (!$header) throw new StockException('The file is empty.');
    $lookup = [];
    foreach (IMPORT_COLUMNS[$kind] as $field => $label) { $lookup[importKey($label)] = $field; $lookup[importKey($field)] = $field; }
    $map = [];
    foreach ($header as $i => $col) if (isset($lookup[importKey((string)$col)])) $map[$i] = $lookup[importKey((string)$col)];
    $needed = $kind === 'stock' ? 'product' : 'name';
    if (!in_array($needed, $map, true)) {
        throw new StockException('The first row must be the column headings from the template (missing “' . IMPORT_COLUMNS[$kind][$needed] . '”). Did you choose the right import?');
    }
    $rows = [];
    $line = 1;
    while (($cells = fgetcsv($h, 0, $delim)) !== false) {
        $line++;
        $r = ['_line' => $line];
        foreach ($map as $i => $field) $r[$field] = trim((string)($cells[$i] ?? ''));
        if (!array_filter(array_diff_key($r, ['_line' => 1]), fn($v) => $v !== '')) continue;   // blank row
        if (str_starts_with(mb_strtolower($r[$needed] ?? ''), 'example')) continue;            // template example
        $rows[] = $r;
        if (count($rows) > 2000) throw new StockException('Import at most 2,000 rows at a time.');
    }
    return $rows;
}

/** Supplier id by name, created if new (inside the import transaction). */
function importSupplier(PDO $db, string $name, array &$created): ?int {
    $name = trim($name);
    if ($name === '') return null;
    $s = $db->prepare("SELECT id FROM suppliers WHERE name = ?");
    $s->execute([$name]);
    if ($id = (int)$s->fetchColumn()) return $id;
    $db->prepare("INSERT INTO suppliers (name) VALUES (?)")->execute([$name]);
    $created[$name] = true;
    return (int)$db->lastInsertId();
}

/** Existing product for a row: barcode, then code, then name. */
function importFindProduct(PDO $db, ?string $gtin, string $sku, string $name): ?array {
    foreach ([['gtin', $gtin], ['sku', $sku]] as [$col, $v]) {
        if ($v === null || $v === '') continue;
        $s = $db->prepare("SELECT * FROM products WHERE $col = ?");
        $s->execute([$v]);
        if ($p = $s->fetch()) return $p;
    }
    if ($name === '') return null;
    $s = $db->prepare("SELECT * FROM products WHERE LOWER(name) = LOWER(?)");
    $s->execute([trim($name)]);
    return $s->fetch() ?: null;
}

function importProductRow(PDO $db, array $r, array &$suppliersCreated): string {
    $gtin = null;
    if (($r['gtin'] ?? '') !== '') {
        $gtin = normalizeGtin($r['gtin']);
        if (!$gtin) throw new StockException('Barcode “' . $r['gtin'] . '” isn’t a valid GS1 product code. Spreadsheets sometimes turn long numbers into 5.01E+12; format the column as text.');
    }
    $existing = importFindProduct($db, $gtin, $r['sku'] ?? '', $r['name'] ?? '');
    if (!$existing && ($r['name'] ?? '') === '') throw new StockException('Name is required for a new product.');

    $f = [];
    foreach (['name', 'brand', 'category', 'sku', 'notes'] as $k) if (($r[$k] ?? '') !== '') $f[$k] = mb_substr($r[$k], 0, $k === 'notes' ? 2000 : 150);
    if ($gtin) $f['gtin'] = $gtin;
    if (($r['stock_unit'] ?? '') !== '') $f['stock_unit'] = mb_substr($r['stock_unit'], 0, 30);
    if (($r['usage_unit'] ?? '') !== '') $f['usage_unit'] = mb_substr($r['usage_unit'], 0, 30);
    if (($per = importNumber($r['units_per_stock'] ?? '', 'How many per pack')) !== null) {
        if ($per <= 0) throw new StockException('How many per pack must be above zero.');
        $f['units_per_stock'] = $per;
    }
    if (($r['storage'] ?? '') !== '') {
        $st = ['room' => 'room', 'roomtemperature' => 'room', 'ambient' => 'room', 'fridge' => 'fridge', 'refrigerated' => 'fridge', '28c' => 'fridge', 'freezer' => 'freezer', 'frozen' => 'freezer'][importKey($r['storage'])] ?? null;
        if (!$st) throw new StockException('Storage “' . $r['storage'] . '” should be room, fridge or freezer.');
        $f['storage'] = $st;
    }
    foreach (['prescription_only', 'track_lots'] as $k) {
        if (($r[$k] ?? '') !== '') $f[$k] = importBool($r[$k], null) ? 1 : 0;
    }
    if (($r['opened_use_hours'] ?? '') !== '') {
        $hrs = importNumber($r['opened_use_hours'], 'Multi-dose hours');
        $f['opened_use_hours'] = $hrs > 0 ? min(2000, (int)round($hrs)) : null;
    }
    foreach (['reorder_level' => 'Reorder level', 'reorder_qty' => 'Usual order quantity'] as $k => $label) {
        if (($v = importNumber($r[$k] ?? '', $label)) !== null) $f[$k] = $v;
    }
    if (($r['supplier'] ?? '') !== '') $f['supplier_id'] = importSupplier($db, $r['supplier'], $suppliersCreated);

    $per = $f['units_per_stock'] ?? (float)($existing['units_per_stock'] ?? 1);
    if (!empty($f['opened_use_hours']) && $per <= 1) throw new StockException('Multi-dose needs more than one ' . ($f['usage_unit'] ?? $existing['usage_unit'] ?? 'unit') . ' per pack.');
    if ($gtin) {
        $dup = $db->prepare("SELECT name FROM products WHERE gtin = ? AND id <> ?");
        $dup->execute([$gtin, $existing['id'] ?? 0]);
        if ($n = $dup->fetchColumn()) throw new StockException('Barcode is already used by ' . $n . '.');
    }

    if ($existing) {
        if (isset($f['units_per_stock']) && (float)$existing['units_per_stock'] != $f['units_per_stock']
            && (int)$db->query("SELECT COUNT(*) FROM stock_movements WHERE product_id = " . (int)$existing['id'])->fetchColumn()) {
            throw new StockException('“How many per pack” can’t change for ' . $existing['name'] . ' because stock is already recorded.');
        }
        $same = fn($a, $b) => is_numeric($a) && is_numeric($b) ? abs((float)$a - (float)$b) < 0.0005 : (string)$a === (string)$b;
        $changed = array_filter($f, fn($v, $k) => !$same($v, $existing[$k]), ARRAY_FILTER_USE_BOTH);
        if (!$changed) return 'unchanged ' . $existing['name'];
        $db->prepare("UPDATE products SET " . implode(', ', array_map(fn($k) => "$k = ?", array_keys($changed))) . " WHERE id = ?")
           ->execute([...array_values($changed), $existing['id']]);
        return 'updated ' . $existing['name'] . ' (' . implode(', ', array_map(fn($k) => mb_strtolower(IMPORT_COLUMNS['products'][$k] ?? str_replace('_id', '', $k)), array_keys($changed))) . ')';
    }
    $f += ['stock_unit' => 'piece', 'usage_unit' => $f['stock_unit'] ?? 'piece', 'units_per_stock' => 1, 'track_lots' => 1];
    $db->prepare("INSERT INTO products (" . implode(', ', array_keys($f)) . ") VALUES (" . rtrim(str_repeat('?,', count($f)), ',') . ")")->execute(array_values($f));
    return 'new product ' . $f['name'];
}

function importStockRow(PDO $db, array $r): string {
    $ref = trim($r['product'] ?? '');
    if ($ref === '') throw new StockException('Product is required.');
    $p = importFindProduct($db, normalizeGtin($ref), $ref, $ref);
    if (!$p) throw new StockException('No product called or coded “' . $ref . '”. Import products first, or check the spelling.');
    $qty = importNumber($r['qty'] ?? '', 'Quantity', true);
    if ($qty <= 0) throw new StockException('Quantity must be above zero.');
    $expiry = importDate($r['expiry_date'] ?? '');
    $lot = ['lot_number' => $r['lot_number'] ?? '', 'expiry_date' => $expiry, 'supplier' => $r['supplier'] ?? '',
            'invoice_ref' => ($r['invoice_ref'] ?? '') !== '' ? $r['invoice_ref'] : 'Opening stock',
            'unit_cost' => ($c = importNumber($r['unit_cost'] ?? '', 'Cost per pack')) === null ? '' : $c, 'cold_chain_ok' => true];
    if ($lot['supplier'] !== '') { $sc = []; importSupplier($db, $lot['supplier'], $sc); }
    receiveStock($db, (int)$p['id'], $qty, $lot);
    return $p['name'] . ': ' . qtyUnit($qty, $p['stock_unit']) . ($lot['lot_number'] !== '' ? ', batch ' . $lot['lot_number'] : '') . ($expiry ? ', exp ' . date('M Y', strtotime($expiry)) : '');
}

/** Updates a treatment (matched by name) or adds it to the menu. */
function importServiceRow(PDO $db, array $r): string {
    $name = trim($r['name'] ?? '');
    if ($name === '') throw new StockException('Treatment name is required.');
    $s = $db->prepare("SELECT * FROM services WHERE LOWER(name) = LOWER(?)");
    $s->execute([$name]);
    $existing = $s->fetch() ?: null;

    $f = [];
    if (($r['category'] ?? '') !== '') $f['category'] = mb_substr($r['category'], 0, 80);
    $price = preg_replace('/^\s*(aed|dhs?|د\.إ)\s*/iu', '', $r['price'] ?? '');   // "AED 1,200" → "1,200"
    if (($p = importNumber($price, 'Price')) !== null) $f['price'] = round($p, 2);
    if (($m = importNumber($r['duration_min'] ?? '', 'Minutes')) !== null) {
        if ($m < 5 || $m > 600) throw new StockException('Minutes should be between 5 and 600.');
        $f['duration_min'] = (int)round($m);
    }
    if (($r['is_active'] ?? '') !== '') $f['is_active'] = importBool($r['is_active'], null) ? 1 : 0;
    if (($r['description'] ?? '') !== '') $f['description'] = mb_substr($r['description'], 0, 2000);

    $aed = fn($n) => number_format((float)$n, fmod((float)$n, 1) ? 2 : 0);
    if ($existing) {
        $changed = array_filter($f, fn($v, $k) => is_numeric($v) ? abs((float)$v - (float)$existing[$k]) >= 0.005 : (string)$v !== (string)$existing[$k], ARRAY_FILTER_USE_BOTH);
        if (!$changed) return 'unchanged ' . $existing['name'];
        $db->prepare("UPDATE services SET " . implode(', ', array_map(fn($k) => "$k = ?", array_keys($changed))) . " WHERE id = ?")
           ->execute([...array_values($changed), $existing['id']]);
        $what = [];
        foreach ($changed as $k => $v) {
            $what[] = match ($k) {
                'price'        => 'price AED ' . $aed($existing['price']) . ' → ' . $aed($v),
                'duration_min' => $existing['duration_min'] . ' → ' . $v . ' min',
                'is_active'    => $v ? 'now bookable' : 'hidden from booking',
                default        => mb_strtolower(IMPORT_COLUMNS['treatments'][$k]),
            };
        }
        return 'updated ' . $existing['name'] . ' (' . implode(', ', $what) . ')';
    }
    $f = ['name' => mb_substr($name, 0, 120)] + $f;
    $db->prepare("INSERT INTO services (" . implode(', ', array_keys($f)) . ") VALUES (" . rtrim(str_repeat('?,', count($f)), ',') . ")")->execute(array_values($f));
    return 'new treatment ' . $f['name'] . (isset($f['price']) ? ', AED ' . $aed($f['price']) : ', no price yet') . ' (add its recipe on the treatment page)';
}

/** Runs every row in one transaction; each row in its own savepoint. Preview rolls everything back. */
function runImport(PDO $db, string $kind, array $rows, bool $commit): array {
    $res = ['ok' => [], 'errors' => [], 'suppliers' => []];
    $db->beginTransaction();
    try {
        foreach ($rows as $r) {
            $db->exec("SAVEPOINT import_row");
            try {
                $res['ok'][] = [$r['_line'], match ($kind) {
                    'products'   => importProductRow($db, $r, $res['suppliers']),
                    'treatments' => importServiceRow($db, $r),
                    default      => importStockRow($db, $r),
                }];
                $db->exec("RELEASE SAVEPOINT import_row");
            } catch (StockException | PDOException $e) {
                $db->exec("ROLLBACK TO SAVEPOINT import_row");
                $res['errors'][] = [$r['_line'], $e instanceof PDOException ? 'Could not save this row (' . $e->getMessage() . ')' : $e->getMessage()];
            }
        }
        $commit ? $db->commit() : $db->rollBack();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return $res;
}

// ─────────── TEMPLATE DOWNLOAD ───────────
if (isset($_GET['template']) && isset(IMPORT_COLUMNS[$_GET['template']])) {
    $kind = $_GET['template'];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . ['products' => 'products', 'stock' => 'opening-stock', 'treatments' => 'treatment-prices'][$kind] . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_values(IMPORT_COLUMNS[$kind]));
    if ($kind === 'treatments') {
        // The whole menu (hidden ones too), so prices can be edited and imported back
        $rows = $db->query("SELECT * FROM services ORDER BY is_active DESC, category, name")->fetchAll();
        foreach ($rows as $s) {
            fputcsv($out, [$s['name'], $s['category'], (string)(float)$s['price'], $s['duration_min'], $s['is_active'] ? 'yes' : 'no', $s['description']]);
        }
        if (!$rows) fputcsv($out, ['EXAMPLE Lip filler 1 ml (delete this row)', 'Fillers', '1500', '45', 'yes', '']);
    } elseif ($kind === 'products') {
        // Current products, so the file can be edited and imported back
        $rows = $db->query("SELECT p.*, s.name AS supplier FROM products p LEFT JOIN suppliers s ON s.id = p.supplier_id WHERE p.is_active = 1 ORDER BY p.category, p.name")->fetchAll();
        foreach ($rows as $p) {
            fputcsv($out, [$p['name'], $p['brand'], $p['category'], $p['sku'], $p['gtin'] ? "\u{200B}" . $p['gtin'] : '', $p['stock_unit'], $p['usage_unit'], fmtQty($p['units_per_stock']),
                           $p['storage'], $p['prescription_only'] ? 'yes' : 'no', $p['track_lots'] ? 'yes' : 'no', $p['opened_use_hours'] ?? '',
                           fmtQty($p['reorder_level']), fmtQty($p['reorder_qty']), $p['supplier'], $p['notes']]);
        }
        if (!$rows) {
            fputcsv($out, ['EXAMPLE Botulinum toxin type A 100U (delete this row)', 'Brand', 'Injectables', 'BTX100', '05012345678900', 'vial', 'units', '100', 'fridge', 'yes', 'yes', '24', '200', '300', 'Supplier name', '']);
            fputcsv($out, ['EXAMPLE Syringe 1 ml (delete this row)', '', 'Consumables', '', '', 'box', 'piece', '100', 'room', 'no', 'no', '', '50', '200', '', '']);
        }
    } else {
        fputcsv($out, ['EXAMPLE Botulinum toxin type A 100U (delete this row)', 'C3456AB', '30/06/2027', '3', '950', 'Supplier name', '']);
    }
    fclose($out);
    exit;
}

// ─────────── UPLOAD, PREVIEW, IMPORT ───────────
$error = '';
$preview = null;
$done = $_SESSION['import_done'] ?? null;
unset($_SESSION['import_done']);
$kind = isset(IMPORT_COLUMNS[$_POST['kind'] ?? '']) ? $_POST['kind'] : (isset(IMPORT_COLUMNS[$_GET['kind'] ?? '']) ? $_GET['kind'] : 'products');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    try {
        if (($_POST['action'] ?? '') === 'preview') {
            $file = $_FILES['file'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) throw new StockException('Choose the CSV file to upload.');
            if ($file['size'] > 5 * 1024 * 1024) throw new StockException('The file is larger than 5 MB.');
            if (!preg_match('/\.(csv|txt)$/i', $file['name'])) throw new StockException('Save the spreadsheet as CSV (File → Save As → CSV UTF-8) and upload that.');
            $enc = null;
            $rows = readImportCsv($file['tmp_name'], $kind, $enc);
            if (!$rows) throw new StockException('No rows to import (example rows are ignored).');
            $preview = runImport($db, $kind, $rows, false) + ['encoding' => $enc, 'file' => $file['name'], 'rows' => count($rows)];
            $_SESSION['import_pending'] = ['kind' => $kind, 'rows' => $rows, 'token' => bin2hex(random_bytes(8)), 'file' => $file['name']];
            $preview['token'] = $_SESSION['import_pending']['token'];
        } elseif (($_POST['action'] ?? '') === 'import') {
            $pending = $_SESSION['import_pending'] ?? null;
            if (!$pending || !hash_equals($pending['token'], (string)($_POST['token'] ?? ''))) throw new StockException('The preview has expired. Upload the file again.');
            $res = runImport($db, $pending['kind'], $pending['rows'], true);
            unset($_SESSION['import_pending']);
            logActivity(currentStaffId(), ['products' => 'products_imported', 'stock' => 'opening_stock_imported', 'treatments' => 'treatments_imported'][$pending['kind']], null, null,
                        $pending['file'] . ': ' . count($res['ok']) . ' rows, ' . count($res['errors']) . ' skipped');
            $_SESSION['import_done'] = ['kind' => $pending['kind'], 'ok' => count($res['ok']), 'errors' => $res['errors'], 'suppliers' => array_keys($res['suppliers'])];
            header('Location: /pages/stock_import.php?kind=' . $pending['kind']); exit;
        }
    } catch (StockException $e) {
        $error = $e->getMessage();
    }
}

$counts = ['products' => (int)$db->query("SELECT COUNT(*) FROM products WHERE is_active = 1")->fetchColumn(),
           'lots' => (int)$db->query("SELECT COUNT(*) FROM stock_lots WHERE qty_on_hand > 0")->fetchColumn(),
           'services' => (int)$db->query("SELECT COUNT(*) FROM services")->fetchColumn()];
$pageTitle    = 'Import from a spreadsheet';
$pageBack     = $kind === 'treatments' ? ['href' => '/pages/services.php', 'label' => 'Treatments'] : ['href' => '/pages/inventory.php', 'label' => 'Inventory'];
$pageSubtitle = 'Set up products, opening stock and treatment prices in one go';
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($done): ?>
<div class="alert alert-success"><i class="bi bi-check-circle"></i><div><strong><?= $done['ok'] ?> row<?= $done['ok'] == 1 ? '' : 's' ?> imported.</strong>
  <?= $done['suppliers'] ? 'New suppliers added: ' . sanitize(implode(', ', $done['suppliers'])) . '. ' : '' ?>
  <?= $done['errors'] ? count($done['errors']) . ' row' . (count($done['errors']) == 1 ? ' was' : 's were') . ' skipped; fix them in the file and import just those rows again.' : '' ?>
  <?= ['products' => '<a href="?kind=stock">Next: import opening stock</a>', 'stock' => '<a href="/pages/inventory.php">See the stock list</a>', 'treatments' => '<a href="/pages/services.php">See the treatment menu</a>'][$done['kind']] ?></div></div>
  <?php if ($done['errors']): ?>
  <div class="card-crm page-section"><div class="card-header-crm"><span><i class="bi bi-exclamation-triangle"></i> Skipped rows</span></div>
    <div class="list-rows"><?php foreach ($done['errors'] as [$line, $msg]): ?><div class="list-row"><div class="list-row-main"><div class="list-row-title">Row <?= (int)$line ?></div><div class="list-row-sub text-danger"><?= sanitize($msg) ?></div></div></div><?php endforeach; ?></div></div>
  <?php endif; ?>
<?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i><?= sanitize($error) ?></div><?php endif; ?>

<?php if ($preview): ?>
  <div class="card-crm page-section">
    <div class="card-header-crm"><span><i class="bi bi-eye"></i> Preview of <?= sanitize($preview['file']) ?></span>
      <span class="card-header-sub">Nothing has been saved yet<?= $preview['encoding'] !== 'UTF-8' ? ' · read as ' . sanitize($preview['encoding']) : '' ?></span></div>
    <div class="card-body-crm">
      <div class="d-flex flex-wrap gap-2 mb-3">
        <span class="chip chip-lg aging-fresh"><?= count($preview['ok']) ?> ready</span>
        <?php if ($preview['errors']): ?><span class="chip chip-lg aging-critical"><?= count($preview['errors']) ?> with problems, will be skipped</span><?php endif; ?>
        <?php if ($preview['suppliers']): ?><span class="chip chip-lg aging-due"><?= count($preview['suppliers']) ?> new supplier<?= count($preview['suppliers']) == 1 ? '' : 's' ?></span><?php endif; ?>
      </div>
      <?php if ($preview['errors']): ?>
      <div class="section-title">Problems</div>
      <div class="list-rows mb-3"><?php foreach ($preview['errors'] as [$line, $msg]): ?><div class="list-row"><div class="list-row-main"><div class="list-row-title">Row <?= (int)$line ?></div><div class="list-row-sub text-danger"><?= sanitize($msg) ?></div></div></div><?php endforeach; ?></div>
      <?php endif; ?>
      <?php if ($preview['ok']): ?>
      <div class="section-title">Will be imported</div>
      <div class="list-rows" style="max-height:420px;overflow:auto"><?php foreach ($preview['ok'] as [$line, $msg]): ?><div class="list-row"><div class="list-row-main"><div class="list-row-sub"><span class="text-tx-3">Row <?= (int)$line ?></span> · <?= sanitize(ucfirst($msg)) ?></div></div></div><?php endforeach; ?></div>
      <?php endif; ?>
    </div>
    <div class="form-actions">
      <a class="btn btn-ghost me-auto" href="?kind=<?= $kind ?>">Choose another file</a>
      <?php if ($preview['ok']): ?>
      <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="import"><input type="hidden" name="token" value="<?= $preview['token'] ?>">
        <button class="btn btn-gold" type="submit"><i class="bi bi-cloud-arrow-up"></i>Import <?= count($preview['ok']) ?> row<?= count($preview['ok']) == 1 ? '' : 's' ?></button></form>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>
  <div class="row g-3" style="max-width:1100px">
    <?php foreach (['products' => ['Step 1: Products', 'bi-box-seam', $counts['products'] . ' active products now. The download includes them, so you can edit and re-import.', 'products file'],
                    'stock' => ['Step 2: Opening stock', 'bi-boxes', $counts['lots'] . ' batches with stock now. Import products first; each row is received as a batch.', 'opening stock template'],
                    'treatments' => ['Treatment prices', 'bi-tags', $counts['services'] . ' treatments on the menu now. The download includes all of them with their current prices.', 'treatment menu']] as $k => [$label, $icon, $note, $dl]): ?>
    <div class="col-lg-6" id="<?= $k ?>">
      <form method="POST" enctype="multipart/form-data" class="card-crm h-100"<?= $kind === $k && isset($_GET['kind']) ? ' style="border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-light)"' : '' ?>>
        <?= csrfField() ?><input type="hidden" name="action" value="preview"><input type="hidden" name="kind" value="<?= $k ?>">
        <div class="card-header-crm"><span><i class="bi <?= $icon ?>"></i> <?= $label ?></span></div>
        <div class="card-body-crm">
          <p class="fs-13 text-tx-2"><?= sanitize(IMPORT_HELP[$k]) ?></p>
          <p class="fs-12 text-tx-3"><?= sanitize($note) ?></p>
          <a class="btn btn-ghost btn-sm mb-3" href="?template=<?= $k ?>"><i class="bi bi-download"></i>Download <?= $dl ?></a>
          <label class="form-label d-block" for="f_<?= $k ?>">Upload the filled-in file (CSV)</label>
          <input class="form-control" type="file" id="f_<?= $k ?>" name="file" accept=".csv,.txt" required>
        </div>
        <div class="form-actions"><button class="btn btn-gold" type="submit"><i class="bi bi-eye"></i>Check the file</button></div>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
  <p class="fs-12 text-tx-3 mt-3" style="max-width:1100px">Edit the file in Excel, then <strong>File → Save As → CSV UTF-8</strong>. You’ll see a preview of every row before anything is saved, and rows with problems are skipped, not half-imported. Opening stock is recorded in the stock history as received, with “Opening stock” as the invoice unless you give one.</p>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
