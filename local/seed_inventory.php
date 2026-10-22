<?php
// local/seed_inventory.php — LOCAL ONLY. Example products, batches, services and two test logins
// (Inventory + Practitioner) for trying the treatment-stock feature. Safe to re-run: it skips if products exist.
// Run: C:\xampp\php\php.exe local\seed_inventory.php
if (PHP_SAPI !== 'cli') exit('CLI only');
session_start();
$_SESSION['staff_id'] = 1; $_SESSION['role'] = 'admin';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/inventory.php';
$db = getDB();
ensureInventorySchema($db);

if ((int)$db->query("SELECT COUNT(*) FROM products")->fetchColumn()) exit("Products already seeded.\n");

// Test logins share the password in local/test_accounts.txt
preg_match('/^Password: (\S+)/m', (string)@file_get_contents(__DIR__ . '/test_accounts.txt'), $m);
$hash = password_hash($m[1] ?? bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
foreach ([['Iman Inventory', 'inventory@clinic.test', 'inventory'], ['Dr. Hana Practitioner', 'doctor@clinic.test', 'practitioner']] as [$n, $e, $r]) {
    $db->prepare("INSERT IGNORE INTO staff (name, email, password, role) VALUES (?,?,?,?)")->execute([$n, $e, $hash, $r]);
}

$P = [];
$add = function (string $key, array $p) use ($db, &$P) {
    $p += ['brand' => null, 'category' => null, 'storage' => 'room', 'prescription_only' => 0, 'track_lots' => 1, 'reorder_level' => 0, 'reorder_qty' => 0];
    $db->prepare("INSERT INTO products (name, brand, category, stock_unit, usage_unit, units_per_stock, storage, prescription_only, track_lots, reorder_level, reorder_qty)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$p['name'], $p['brand'], $p['category'], $p['stock_unit'], $p['usage_unit'], $p['per'], $p['storage'], $p['prescription_only'], $p['track_lots'], $p['reorder_level'], $p['reorder_qty']]);
    $P[$key] = (int)$db->lastInsertId();
};
$add('toxin',  ['name' => 'Botulinum toxin type A 100U', 'brand' => 'Example Pharma', 'category' => 'Injectables', 'stock_unit' => 'vial', 'usage_unit' => 'units', 'per' => 100, 'storage' => 'fridge', 'prescription_only' => 1, 'reorder_level' => 200, 'reorder_qty' => 300]);
$add('filler', ['name' => 'Hyaluronic acid filler 1 ml', 'brand' => 'Example Derm', 'category' => 'Injectables', 'stock_unit' => 'syringe', 'usage_unit' => 'syringe', 'per' => 1, 'prescription_only' => 1, 'reorder_level' => 4, 'reorder_qty' => 10]);
$add('saline', ['name' => 'Sodium chloride 0.9 % 10 ml', 'category' => 'Consumables', 'stock_unit' => 'ampoule', 'usage_unit' => 'ampoule', 'per' => 1, 'reorder_level' => 5]);
$add('syr1',   ['name' => 'Syringe 1 ml', 'category' => 'Consumables', 'stock_unit' => 'box', 'usage_unit' => 'piece', 'per' => 100, 'track_lots' => 0, 'reorder_level' => 50]);
$add('ndl30',  ['name' => 'Needle 30G', 'category' => 'Consumables', 'stock_unit' => 'box', 'usage_unit' => 'piece', 'per' => 100, 'track_lots' => 0, 'reorder_level' => 50]);
$add('swab',   ['name' => 'Alcohol swab', 'category' => 'Consumables', 'stock_unit' => 'box', 'usage_unit' => 'piece', 'per' => 200, 'track_lots' => 0, 'reorder_level' => 100]);
$add('cream',  ['name' => 'Numbing cream 30 g', 'category' => 'Topicals', 'stock_unit' => 'tube', 'usage_unit' => 'g', 'per' => 30, 'reorder_level' => 30]);
$add('hydra',  ['name' => 'HydraFacial tip and serum kit', 'category' => 'Skin', 'stock_unit' => 'kit', 'usage_unit' => 'kit', 'per' => 1, 'reorder_level' => 5, 'reorder_qty' => 10]);
$add('prp',    ['name' => 'PRP separation tube kit', 'category' => 'Regenerative', 'stock_unit' => 'kit', 'usage_unit' => 'kit', 'per' => 1, 'reorder_level' => 3]);

// The toxin vial is shared between patients once opened (Phase 2)
$db->prepare("UPDATE products SET opened_use_hours = 24 WHERE id = ?")->execute([$P['toxin']]);
// Example GS1 product codes so barcode scanning can be tried with generated test barcodes (Phase 3)
$db->prepare("UPDATE products SET gtin = ? WHERE id = ?")->execute(['05012345678900', $P['toxin']]);
$db->prepare("UPDATE products SET gtin = ? WHERE id = ?")->execute(['04006381333931', $P['filler']]);

$in = fn($k, $qty, $lot, $exp, $cost, $sup = 'Example Medical Supplies LLC') => receiveStock($db, $P[$k], $qty,
    ['lot_number' => $lot, 'expiry_date' => $exp, 'supplier' => $sup, 'invoice_ref' => 'INV-' . random_int(1000, 9999), 'unit_cost' => $cost, 'cold_chain_ok' => 1]);
$in('toxin', 2, 'B-2291', '2027-03-31', 950);
$in('toxin', 1, 'B-2310', '2027-06-30', 950);
$in('filler', 4, 'F-118', '2027-01-31', 420);
$in('filler', 2, 'F-120', date('Y-m-d', strtotime('+25 days')), 420);   // expiring soon
$in('saline', 20, 'S-77', '2028-02-28', 3);
$in('syr1', 3, '', null, 45);
$in('ndl30', 3, '', null, 38);
$in('swab', 2, '', null, 20);
$in('cream', 2, 'C-09', '2027-05-31', 60);
$in('hydra', 3, 'H-501', '2027-09-30', 85);                                // below reorder level → low
$in('prp', 4, 'P-330', '2027-02-28', 120);
// An old PRP batch that has since expired (inserted directly: receiving refuses expired stock)
$db->prepare("INSERT INTO stock_lots (product_id, lot_number, expiry_date, supplier, unit_cost, qty_received, qty_on_hand, received_by) VALUES (?,?,?,?,?,2,0,1)")
   ->execute([$P['prp'], 'P-301', date('Y-m-d', strtotime('-20 days')), 'Example Medical Supplies LLC', 120]);
$old = (int)$db->lastInsertId();
stockTx($db, fn() => addMovement($db, ['product_id' => $P['prp'], 'lot_id' => $old, 'type' => 'receive', 'qty_change' => 2, 'note' => 'Seeded expired batch']));

$svc = function (string $name, string $cat, float $price, int $mins, array $items, array $treatments) use ($db, $P) {
    $db->prepare("INSERT INTO services (name, category, price, duration_min) VALUES (?,?,?,?)")->execute([$name, $cat, $price, $mins]);
    $sid = (int)$db->lastInsertId();
    foreach ($items as [$k, $q, $min, $max]) {
        $db->prepare("INSERT INTO service_items (service_id, product_id, qty, qty_min, qty_max) VALUES (?,?,?,?,?)")->execute([$sid, $P[$k], $q, $min, $max]);
    }
    foreach ($treatments as $tid) $db->prepare("INSERT IGNORE INTO service_treatments (service_id, treatment_id) VALUES (?,?)")->execute([$sid, $tid]);
};
$svc('Anti-wrinkle, upper face', 'Injectables', 1200, 30, [['toxin', 40, 20, 64], ['saline', 1, 1, 1], ['syr1', 2, 1, 3], ['ndl30', 3, 2, 5], ['swab', 4, 2, 6]], [1]);
$svc('Lip filler 1 ml', 'Injectables', 1500, 45, [['filler', 1, 1, 2], ['cream', 2, 0, 5], ['ndl30', 2, 1, 4], ['swab', 2, 1, 4]], [3, 14, 17]);
$svc('HydraFacial', 'Skin', 350, 60, [['hydra', 1, 1, 1]], [8]);
$svc('Hair PRP session', 'Hair', 1100, 60, [['prp', 1, 1, 2], ['ndl30', 2, 1, 4], ['swab', 2, 1, 4]], [4, 22]);

echo "Seeded " . count($P) . " products, 4 services and 2 test logins (inventory@clinic.test, doctor@clinic.test).\n";
