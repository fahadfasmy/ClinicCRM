<?php
// includes/inventory.php — treatment stock: products, batches, recipes, appointments.
//
// Rules (see the Treatment Stock Plan):
//  * Every stock change is a row in stock_movements (insert only). Corrections are new rows.
//  * qty_change moves on-hand stock of a batch; reserved_change moves the product's reservation.
//  * Cached totals (stock_lots.qty_on_hand, products.qty_reserved) change in the same
//    transaction as the movement, with the product row locked, so two bookings can't
//    take the last vial twice.
//  * Quantities are stored in the product's usage unit (e.g. units, ml, piece).
//  * Booking reserves by product; completing deducts from batches, earliest expiry first.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/gcal.php';          // practitioners' Google Calendars

const INVENTORY_SCHEMA_VERSION = 3;

class StockException extends RuntimeException {}

// ─────────────────────────────────────────────
// SCHEMA (created on first use, versioned in app_meta)
// ─────────────────────────────────────────────
function ensureInventorySchema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $db->exec("CREATE TABLE IF NOT EXISTS app_meta (
        k VARCHAR(50) NOT NULL PRIMARY KEY,
        v VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ver = (int)($db->query("SELECT v FROM app_meta WHERE k = 'inventory_schema'")->fetchColumn() ?: 0);
    if ($ver >= INVENTORY_SCHEMA_VERSION) return;

    $opts = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if ($ver < 1) ensureInventorySchemaV1($db, $opts);
    if ($ver < 2) ensureInventorySchemaV2($db, $opts);
    if ($ver < 3) ensureInventorySchemaV3($db, $opts);
    $db->prepare("REPLACE INTO app_meta (k, v) VALUES ('inventory_schema', ?)")->execute([INVENTORY_SCHEMA_VERSION]);
}

/** Phase 0/1: products, batches, recipes, appointments, ledger, roles. */
function ensureInventorySchemaV1(PDO $db, string $opts): void {
    $db->exec("CREATE TABLE IF NOT EXISTS stock_locations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(80) NOT NULL,
        is_default TINYINT(1) NOT NULL DEFAULT 0
    ) $opts");
    if (!(int)$db->query("SELECT COUNT(*) FROM stock_locations")->fetchColumn()) {
        $db->exec("INSERT INTO stock_locations (name, is_default) VALUES ('Main store', 1)");
    }

    $db->exec("CREATE TABLE IF NOT EXISTS products (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        brand VARCHAR(100) NULL,
        category VARCHAR(60) NULL,
        sku VARCHAR(60) NULL,
        stock_unit VARCHAR(30) NOT NULL DEFAULT 'piece',
        usage_unit VARCHAR(30) NOT NULL DEFAULT 'piece',
        units_per_stock DECIMAL(12,3) NOT NULL DEFAULT 1,
        storage ENUM('room','fridge','freezer') NOT NULL DEFAULT 'room',
        prescription_only TINYINT(1) NOT NULL DEFAULT 0,
        track_lots TINYINT(1) NOT NULL DEFAULT 1,
        reorder_level DECIMAL(12,3) NOT NULL DEFAULT 0,
        reorder_qty DECIMAL(12,3) NOT NULL DEFAULT 0,
        qty_reserved DECIMAL(12,3) NOT NULL DEFAULT 0,
        notes TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_active (is_active)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS stock_lots (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id INT UNSIGNED NOT NULL,
        location_id INT UNSIGNED NOT NULL DEFAULT 1,
        lot_number VARCHAR(80) NULL,
        expiry_date DATE NULL,
        supplier VARCHAR(150) NULL,
        invoice_ref VARCHAR(80) NULL,
        unit_cost DECIMAL(12,2) NULL COMMENT 'cost per stock unit (e.g. per vial)',
        qty_received DECIMAL(12,3) NOT NULL DEFAULT 0,
        qty_on_hand DECIMAL(12,3) NOT NULL DEFAULT 0,
        status ENUM('available','quarantined','recalled') NOT NULL DEFAULT 'available',
        cold_chain_ok TINYINT(1) NULL,
        received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        received_by INT UNSIGNED NULL,
        KEY idx_product (product_id, status, expiry_date),
        KEY idx_lot_number (lot_number),
        CONSTRAINT fk_lot_product FOREIGN KEY (product_id) REFERENCES products(id)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS services (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        category VARCHAR(80) NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0,
        duration_min SMALLINT UNSIGNED NOT NULL DEFAULT 30,
        description TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS service_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        service_id INT UNSIGNED NOT NULL,
        product_id INT UNSIGNED NOT NULL,
        qty DECIMAL(12,3) NOT NULL,
        qty_min DECIMAL(12,3) NULL,
        qty_max DECIMAL(12,3) NULL,
        UNIQUE KEY uq_service_product (service_id, product_id),
        CONSTRAINT fk_si_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
        CONSTRAINT fk_si_product FOREIGN KEY (product_id) REFERENCES products(id)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS service_treatments (
        service_id INT UNSIGNED NOT NULL,
        treatment_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (service_id, treatment_id),
        KEY idx_treatment (treatment_id),
        CONSTRAINT fk_st_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS appointments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        lead_id INT UNSIGNED NOT NULL,
        service_id INT UNSIGNED NOT NULL,
        scheduled_at DATETIME NOT NULL,
        practitioner_id INT UNSIGNED NULL,
        status ENUM('booked','completed','cancelled','no_show') NOT NULL DEFAULT 'booked',
        notes TEXT NULL,
        booked_by INT UNSIGNED NULL,
        completed_by INT UNSIGNED NULL,
        completed_at DATETIME NULL,
        cancelled_by INT UNSIGNED NULL,
        cancelled_at DATETIME NULL,
        cancel_reason VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_lead (lead_id),
        KEY idx_when (scheduled_at),
        KEY idx_status (status, scheduled_at),
        CONSTRAINT fk_appt_lead FOREIGN KEY (lead_id) REFERENCES leads(id),
        CONSTRAINT fk_appt_service FOREIGN KEY (service_id) REFERENCES services(id)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS appointment_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        appointment_id INT UNSIGNED NOT NULL,
        product_id INT UNSIGNED NOT NULL,
        qty_reserved DECIMAL(12,3) NOT NULL DEFAULT 0,
        qty_used DECIMAL(12,3) NULL,
        variance_reason VARCHAR(255) NULL,
        KEY idx_appt (appointment_id),
        CONSTRAINT fk_ai_appt FOREIGN KEY (appointment_id) REFERENCES appointments(id),
        CONSTRAINT fk_ai_product FOREIGN KEY (product_id) REFERENCES products(id)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS stock_movements (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NULL,
        type ENUM('receive','reserve','release','consume','adjust','waste','count','return') NOT NULL,
        qty_change DECIMAL(12,3) NOT NULL DEFAULT 0,
        reserved_change DECIMAL(12,3) NOT NULL DEFAULT 0,
        appointment_id INT UNSIGNED NULL,
        reason VARCHAR(60) NULL,
        note VARCHAR(255) NULL,
        staff_id INT UNSIGNED NULL,
        reverses_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_product (product_id, created_at),
        KEY idx_lot (lot_id),
        KEY idx_appt (appointment_id),
        CONSTRAINT fk_mv_product FOREIGN KEY (product_id) REFERENCES products(id)
    ) $opts");

    // New staff roles for the stock team and the clinicians who complete treatments
    $roleCol = $db->query("SHOW COLUMNS FROM staff LIKE 'role'")->fetch();
    if ($roleCol && !str_contains($roleCol['Type'], 'practitioner')) {
        $db->exec("ALTER TABLE staff MODIFY role ENUM('admin','consultant','receptionist','inventory','practitioner') NOT NULL DEFAULT 'consultant'");
    }
}

/** Phase 2: opened multi-dose containers, stock counts with approval. */
function ensureInventorySchemaV2(PDO $db, string $opts): void {
    $hasCol = function (string $table, string $col) use ($db): bool {
        return (bool)$db->query("SHOW COLUMNS FROM `$table` LIKE " . $db->quote($col))->fetch();
    };
    if (!$hasCol('products', 'opened_use_hours')) {
        $db->exec("ALTER TABLE products ADD COLUMN opened_use_hours SMALLINT UNSIGNED NULL COMMENT 'multi-dose: hours usable after opening; NULL = not tracked' AFTER track_lots");
    }
    if (!$hasCol('stock_movements', 'container_id')) {
        $db->exec("ALTER TABLE stock_movements ADD COLUMN container_id INT UNSIGNED NULL AFTER lot_id, ADD KEY idx_container (container_id)");
    }

    $db->exec("CREATE TABLE IF NOT EXISTS opened_containers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        qty_initial DECIMAL(12,3) NOT NULL,
        qty_left DECIMAL(12,3) NOT NULL,
        opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        opened_by INT UNSIGNED NULL,
        use_by DATETIME NOT NULL,
        status ENUM('open','finished','discarded') NOT NULL DEFAULT 'open',
        closed_at DATETIME NULL,
        closed_by INT UNSIGNED NULL,
        KEY idx_open (product_id, status, use_by),
        KEY idx_lot (lot_id),
        CONSTRAINT fk_oc_product FOREIGN KEY (product_id) REFERENCES products(id),
        CONSTRAINT fk_oc_lot FOREIGN KEY (lot_id) REFERENCES stock_lots(id)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS stock_counts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        status ENUM('counting','awaiting_approval','closed','abandoned') NOT NULL DEFAULT 'counting',
        category VARCHAR(60) NULL COMMENT 'NULL = every category',
        note VARCHAR(255) NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        submitted_by INT UNSIGNED NULL,
        submitted_at DATETIME NULL,
        closed_at DATETIME NULL,
        KEY idx_status (status)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS stock_count_lines (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        count_id INT UNSIGNED NOT NULL,
        product_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        expected_qty DECIMAL(12,3) NULL COMMENT 'system quantity when the count was submitted',
        counted_qty DECIMAL(12,3) NULL,
        diff_qty DECIMAL(12,3) NULL,
        diff_value DECIMAL(12,2) NULL,
        reason VARCHAR(40) NULL,
        note VARCHAR(255) NULL,
        status ENUM('open','no_difference','posted','pending','approved','rejected') NOT NULL DEFAULT 'open',
        decided_by INT UNSIGNED NULL,
        decided_at DATETIME NULL,
        decision_note VARCHAR(255) NULL,
        movement_id BIGINT UNSIGNED NULL,
        UNIQUE KEY uq_count_lot (count_id, lot_id),
        KEY idx_status (status),
        CONSTRAINT fk_scl_count FOREIGN KEY (count_id) REFERENCES stock_counts(id) ON DELETE CASCADE,
        CONSTRAINT fk_scl_lot FOREIGN KEY (lot_id) REFERENCES stock_lots(id)
    ) $opts");
}

/** Phase 3: suppliers, purchase orders, product barcodes. */
function ensureInventorySchemaV3(PDO $db, string $opts): void {
    $hasCol = function (string $table, string $col) use ($db): bool {
        return (bool)$db->query("SHOW COLUMNS FROM `$table` LIKE " . $db->quote($col))->fetch();
    };
    $db->exec("CREATE TABLE IF NOT EXISTS suppliers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        contact_name VARCHAR(100) NULL,
        phone VARCHAR(40) NULL,
        email VARCHAR(150) NULL,
        lead_time_days SMALLINT UNSIGNED NULL,
        notes TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $opts");
    // Suppliers typed on earlier deliveries become supplier records
    $db->exec("INSERT INTO suppliers (name) SELECT DISTINCT TRIM(l.supplier) FROM stock_lots l
               WHERE l.supplier IS NOT NULL AND TRIM(l.supplier) <> '' AND TRIM(l.supplier) NOT IN (SELECT name FROM suppliers)");

    if (!$hasCol('products', 'gtin')) {
        $db->exec("ALTER TABLE products ADD COLUMN gtin CHAR(14) NULL COMMENT 'GS1 product code, 14 digits' AFTER sku, ADD UNIQUE KEY uq_gtin (gtin)");
    }
    if (!$hasCol('products', 'supplier_id')) {
        $db->exec("ALTER TABLE products ADD COLUMN supplier_id INT UNSIGNED NULL COMMENT 'usual supplier' AFTER gtin");
    }

    $db->exec("CREATE TABLE IF NOT EXISTS purchase_orders (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        supplier_id INT UNSIGNED NOT NULL,
        status ENUM('draft','ordered','partial','received','closed','cancelled') NOT NULL DEFAULT 'draft',
        expected_date DATE NULL,
        notes TEXT NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ordered_by INT UNSIGNED NULL,
        ordered_at DATETIME NULL,
        closed_at DATETIME NULL,
        KEY idx_status (status),
        CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS purchase_order_lines (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        po_id INT UNSIGNED NOT NULL,
        product_id INT UNSIGNED NOT NULL,
        qty_ordered DECIMAL(12,3) NOT NULL COMMENT 'stock units',
        qty_received DECIMAL(12,3) NOT NULL DEFAULT 0,
        unit_cost DECIMAL(12,2) NULL COMMENT 'per stock unit',
        UNIQUE KEY uq_po_product (po_id, product_id),
        CONSTRAINT fk_pol_po FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
        CONSTRAINT fk_pol_product FOREIGN KEY (product_id) REFERENCES products(id)
    ) $opts");

    if (!$hasCol('stock_lots', 'po_line_id')) {
        $db->exec("ALTER TABLE stock_lots ADD COLUMN supplier_id INT UNSIGNED NULL AFTER supplier, ADD COLUMN po_line_id INT UNSIGNED NULL AFTER invoice_ref");
        $db->exec("UPDATE stock_lots l JOIN suppliers s ON s.name = TRIM(l.supplier) SET l.supplier_id = s.id");
    }
}

// Permissions (can(), requirePermission(), PERMISSIONS) live in includes/permissions.php

// ─────────────────────────────────────────────
// FORM PROTECTION (CSRF)
// ─────────────────────────────────────────────
function csrfToken(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrfField(): string {
    return '<input type="hidden" name="_csrf" value="' . csrfToken() . '">';
}
function requireCsrf(): void {
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals(csrfToken(), $sent)) {
        http_response_code(419);
        die('<h2>This form has expired</h2><p>Go back, refresh the page and try again.</p>');
    }
}

// ─────────────────────────────────────────────
// LABELS & FORMATTING
// ─────────────────────────────────────────────
// Colours follow the visit: booked blue, confirmed violet, arrived amber, triage orange, with doctor teal, done green
const APPT_STATUS = [
    'booked'      => ['Booked', 'stage-contacted'],
    'confirmed'   => ['Confirmed', 'stage-qualified'],
    'arrived'     => ['Arrived', 'stage-booked'],
    'in_triage'   => ['In triage', 'tone-orange'],
    'with_doctor' => ['With doctor', 'stage-cold'],
    'completed'   => ['Completed', 'stage-converted'],
    'cancelled'   => ['Cancelled', 'stage-new'],
    'no_show'     => ['No-show', 'stage-lost'],
    'rescheduled' => ['Rescheduled', 'stage-new'],
];
const STORAGE_LABELS = ['room' => 'Room temperature', 'fridge' => 'Fridge 2–8 °C', 'freezer' => 'Freezer'];
const MOVEMENT_LABELS = [
    'receive' => 'Received', 'reserve' => 'Reserved', 'release' => 'Released', 'consume' => 'Used',
    'adjust' => 'Correction', 'waste' => 'Wastage', 'count' => 'Stock count', 'return' => 'Returned',
];
const ADJUST_REASONS = [
    'receiving_error' => 'Receiving entered wrongly',
    'found'           => 'Found stock',
    'other'           => 'Other',
];
const WASTE_REASONS = [
    'expired'         => 'Expired',
    'damaged'         => 'Damaged or broken',
    'dropped'         => 'Dropped or contaminated',
    'cold_chain'      => 'Cold chain failure',
    'opened_leftover' => 'Leftover in opened vial',
    'recalled'        => 'Recalled, returned or destroyed',
    'other'           => 'Other',
];
const COUNT_REASONS = [
    'miscount'        => 'Earlier count or entry was wrong',
    'unrecorded_use'  => 'Used but not recorded',
    'unrecorded_waste'=> 'Wasted but not recorded',
    'found'           => 'Found stock',
    'missing'         => 'Missing, cause unknown',
    'other'           => 'Other',
];
/** Every reason code that can appear in the ledger (older rows included). */
function reasonLabel(?string $code): string {
    if ($code === null || $code === '') return '';
    return WASTE_REASONS[$code] ?? COUNT_REASONS[$code] ?? ADJUST_REASONS[$code] ?? ['count' => 'Counted difference'][$code] ?? $code;
}
/** A count difference above this value (AED, at batch cost) needs admin approval. Prescription-only items always do. */
const COUNT_APPROVAL_AED = 500;

function apptBadge(string $status): string {
    [$label, $cls] = APPT_STATUS[$status] ?? [ucfirst($status), 'stage-new'];
    return "<span class=\"stage-badge {$cls}\">{$label}</span>";
}

/** 44 → "44", 2.5 → "2.5" */
function fmtQty($q): string {
    $q = (float)$q;
    return rtrim(rtrim(number_format($q, 3, '.', ','), '0'), '.') ?: '0';
}

/** "1 vial", "3 vials", "2 boxes", "40 units", "5 ml" (short units like ml or g stay as they are). */
function unitWord(string $unit, $qty): string {
    $u = trim($unit);
    if (abs((float)$qty) == 1.0 || strlen($u) <= 2 || preg_match('/(s|units?)$/i', $u) || !preg_match('/^[a-z ]+$/i', $u)) return $u;
    return preg_match('/(x|ch|sh)$/i', $u) ? $u . 'es' : $u . 's';
}

/** "44 units", "3 vials" — escaped for HTML. */
function qtyUnit($qty, string $unit): string {
    return fmtQty($qty) . ' ' . sanitize(unitWord($unit, $qty));
}

/** "256 units (2.56 vials)" style text for a product row. */
function fmtStock($qty, array $p, bool $withStockUnits = true): string {
    $s = qtyUnit($qty, $p['usage_unit']);
    $per = (float)$p['units_per_stock'];
    if ($withStockUnits && $per > 1 && (float)$qty != 0) {
        $s .= ' <span class="text-tx-3">(' . qtyUnit(round($qty / $per, 2), $p['stock_unit']) . ')</span>';
    }
    return $s;
}

// ─────────────────────────────────────────────
// STOCK QUERIES
// ─────────────────────────────────────────────
/** Usable stock (available batches, not expired on $onDate) per product. */
function usableOnHand(PDO $db, int $productId, ?string $onDate = null): float {
    $stmt = $db->prepare("SELECT COALESCE(SUM(qty_on_hand),0) FROM stock_lots
        WHERE product_id = ? AND status = 'available' AND qty_on_hand > 0
          AND (expiry_date IS NULL OR expiry_date >= ?)");
    $stmt->execute([$productId, $onDate ?? date('Y-m-d')]);
    return (float)$stmt->fetchColumn();
}

/** Stock list rows: on hand (usable today), unusable, reserved, available, next expiry. */
function stockSummary(PDO $db, bool $activeOnly = true): array {
    $rows = $db->query("
        SELECT p.*,
          COALESCE(SUM(CASE WHEN l.status='available' AND (l.expiry_date IS NULL OR l.expiry_date >= CURDATE()) THEN l.qty_on_hand END),0) AS on_hand,
          COALESCE(SUM(CASE WHEN l.status<>'available' OR l.expiry_date < CURDATE() THEN l.qty_on_hand END),0) AS unusable,
          MIN(CASE WHEN l.qty_on_hand > 0 AND l.status='available' AND (l.expiry_date IS NULL OR l.expiry_date >= CURDATE()) THEN l.expiry_date END) AS next_expiry,
          SUM(CASE WHEN l.qty_on_hand > 0 AND l.expiry_date < CURDATE() THEN 1 ELSE 0 END) AS expired_lots,
          COALESCE(SUM(l.qty_on_hand * l.unit_cost / NULLIF(p.units_per_stock,0)),0) AS stock_value
        FROM products p
        LEFT JOIN stock_lots l ON l.product_id = p.id
        " . ($activeOnly ? "WHERE p.is_active = 1" : "") . "
        GROUP BY p.id
        ORDER BY p.category, p.name
    ")->fetchAll();
    foreach ($rows as &$r) {
        $r['available'] = (float)$r['on_hand'] - (float)$r['qty_reserved'];
        $r['state'] = stockState($r);
    }
    return $rows;
}

/** 'short' | 'low' | 'expiring' | 'ok' | 'empty' */
function stockState(array $r): string {
    if ((float)$r['available'] < 0) return 'short';
    if ((float)$r['on_hand'] <= 0 && (float)$r['qty_reserved'] <= 0) return 'empty';
    if ((float)$r['reorder_level'] > 0 && (float)$r['available'] < (float)$r['reorder_level']) return 'low';
    if ($r['next_expiry'] && strtotime($r['next_expiry']) <= strtotime('+60 days')) return 'expiring';
    return 'ok';
}

const STOCK_STATE_CHIPS = [
    'short'    => ['Short for bookings', 'aging-critical'],
    'low'      => ['Low stock', 'aging-overdue'],
    'expiring' => ['Expiring soon', 'aging-due'],
    'empty'    => ['Out of stock', ''],
    'ok'       => ['In stock', 'aging-fresh'],
];

/** Counts for the sidebar badge and dashboard. */
function inventoryAlertCounts(PDO $db): array {
    $rows = stockSummary($db);
    $c = ['short' => 0, 'low' => 0, 'expiring' => 0];
    foreach ($rows as $r) if (isset($c[$r['state']])) $c[$r['state']]++;
    $c['expired_lots'] = (int)$db->query("SELECT COUNT(*) FROM stock_lots WHERE qty_on_hand > 0 AND expiry_date < CURDATE()")->fetchColumn();
    $c['approvals'] = can('inventory.approve') ? pendingApprovalCount($db) : 0;
    $c['total'] = $c['short'] + $c['low'] + $c['expired_lots'] + $c['approvals'];
    return $c;
}

// ─────────────────────────────────────────────
// LEDGER CORE
// ─────────────────────────────────────────────
/** Runs $fn all-or-nothing: its own transaction, or a savepoint when called inside another one. */
function stockTx(PDO $db, callable $fn) {
    static $depth = 0;
    $own = !$db->inTransaction();
    $sp  = 'stock_sp_' . (++$depth);
    $own ? $db->beginTransaction() : $db->exec("SAVEPOINT $sp");
    try {
        $result = $fn();
        $own ? $db->commit() : $db->exec("RELEASE SAVEPOINT $sp");
        return $result;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $own ? $db->rollBack() : $db->exec("ROLLBACK TO SAVEPOINT $sp");
        throw $e;
    } finally {
        $depth--;
    }
}

function lockProduct(PDO $db, int $productId): array {
    $stmt = $db->prepare("SELECT * FROM products WHERE id = ? FOR UPDATE");
    $stmt->execute([$productId]);
    $p = $stmt->fetch();
    if (!$p) throw new StockException('Product not found.');
    return $p;
}

/** Inserts one ledger row and moves the cached totals with it. Call inside stockTx with the product locked. */
function addMovement(PDO $db, array $m): int {
    $m += ['lot_id' => null, 'container_id' => null, 'qty_change' => 0, 'reserved_change' => 0, 'appointment_id' => null,
           'reason' => null, 'note' => null, 'staff_id' => currentStaffId() ?: null, 'reverses_id' => null];
    $db->prepare("INSERT INTO stock_movements
        (product_id, lot_id, container_id, type, qty_change, reserved_change, appointment_id, reason, note, staff_id, reverses_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$m['product_id'], $m['lot_id'], $m['container_id'], $m['type'], $m['qty_change'], $m['reserved_change'],
                  $m['appointment_id'], $m['reason'], $m['note'], $m['staff_id'], $m['reverses_id']]);
    $id = (int)$db->lastInsertId();
    if ((float)$m['qty_change'] != 0) {
        if (!$m['lot_id']) throw new StockException('On-hand changes need a batch.');
        $db->prepare("UPDATE stock_lots SET qty_on_hand = qty_on_hand + ? WHERE id = ? AND product_id = ?")
           ->execute([$m['qty_change'], $m['lot_id'], $m['product_id']]);
        $left = $db->prepare("SELECT qty_on_hand FROM stock_lots WHERE id = ?");
        $left->execute([$m['lot_id']]);
        $onHand = (float)$left->fetchColumn();
        if ($onHand < -0.0005) throw new StockException('That would take a batch below zero.');
        if ((float)$m['qty_change'] < 0) trimOpenContainers($db, (int)$m['lot_id'], $onHand);
    }
    if ((float)$m['reserved_change'] != 0) {
        $db->prepare("UPDATE products SET qty_reserved = GREATEST(0, qty_reserved + ?) WHERE id = ?")
           ->execute([$m['reserved_change'], $m['product_id']]);
    }
    return $id;
}

// ─────────────────────────────────────────────
// RECEIVING & CORRECTIONS
// ─────────────────────────────────────────────
/**
 * Receives stock. $qtyStockUnits is in the product's stock unit (e.g. vials);
 * it is stored in usage units (e.g. 3 vials × 100 = 300 units).
 */
function receiveStock(PDO $db, int $productId, float $qtyStockUnits, array $lot): int {
    if ($qtyStockUnits <= 0) throw new StockException('Enter a quantity above zero.');
    return stockTx($db, function () use ($db, $productId, $qtyStockUnits, $lot) {
        $p = lockProduct($db, $productId);
        if ($p['track_lots'] && trim($lot['lot_number'] ?? '') === '') throw new StockException('Enter the batch number printed on the box.');
        if ($p['track_lots'] && empty($lot['expiry_date'])) throw new StockException('Enter the expiry date printed on the box.');
        if (!empty($lot['expiry_date']) && $lot['expiry_date'] < date('Y-m-d')) throw new StockException('This batch has already expired. Don’t receive it into usable stock.');
        if ($p['storage'] !== 'room' && empty($lot['cold_chain_ok'])) throw new StockException('Confirm the cold chain was kept for this delivery, or don’t receive it.');
        $qty = round($qtyStockUnits * (float)$p['units_per_stock'], 3);
        // A delivery against a purchase order: the line must be open and for this product
        $poLine = null;
        if (!empty($lot['po_line_id'])) {
            $s = $db->prepare("SELECT pl.*, po.status, po.supplier_id, s.name AS supplier_name FROM purchase_order_lines pl
                               JOIN purchase_orders po ON po.id = pl.po_id JOIN suppliers s ON s.id = po.supplier_id WHERE pl.id = ? FOR UPDATE");
            $s->execute([(int)$lot['po_line_id']]);
            $poLine = $s->fetch();
            if (!$poLine || (int)$poLine['product_id'] !== $productId) throw new StockException('That order line is for a different product.');
            if (!in_array($poLine['status'], ['ordered', 'partial'], true)) throw new StockException('That order is not open for receiving.');
            $lot['supplier_id'] = (int)$poLine['supplier_id'];
            $lot['supplier'] = $poLine['supplier_name'];
        }
        $supplierId = !empty($lot['supplier_id']) ? (int)$lot['supplier_id'] : null;
        if (!$supplierId && trim($lot['supplier'] ?? '') !== '') {
            $s = $db->prepare("SELECT id FROM suppliers WHERE name = ?");
            $s->execute([trim($lot['supplier'])]);
            $supplierId = (int)$s->fetchColumn() ?: null;
        }
        $db->prepare("INSERT INTO stock_lots (product_id, lot_number, expiry_date, supplier, supplier_id, invoice_ref, po_line_id, unit_cost, qty_received, qty_on_hand, cold_chain_ok, received_by)
                      VALUES (?,?,?,?,?,?,?,?,?,0,?,?)")
           ->execute([$productId, trim($lot['lot_number'] ?? '') ?: null, $lot['expiry_date'] ?: null, trim($lot['supplier'] ?? '') ?: null, $supplierId,
                      trim($lot['invoice_ref'] ?? '') ?: null, $poLine ? (int)$poLine['id'] : null,
                      $lot['unit_cost'] !== '' && $lot['unit_cost'] !== null ? (float)$lot['unit_cost'] : null,
                      $qty, $p['storage'] !== 'room' ? 1 : null, currentStaffId() ?: null]);
        $lotId = (int)$db->lastInsertId();
        if ($poLine) {
            $db->prepare("UPDATE purchase_order_lines SET qty_received = qty_received + ? WHERE id = ?")->execute([$qtyStockUnits, $poLine['id']]);
            refreshPoStatus($db, (int)$poLine['po_id']);
        }
        addMovement($db, ['product_id' => $productId, 'lot_id' => $lotId, 'type' => 'receive', 'qty_change' => $qty,
                          'note' => trim(implode(' · ', array_filter([$poLine ? poNumber((int)$poLine['po_id']) : '', trim($lot['invoice_ref'] ?? '') ? 'Invoice ' . trim($lot['invoice_ref']) : '']))) ?: null]);
        return $lotId;
    });
}

/** Admin correction of one batch, in usage units (signed). */
function adjustLot(PDO $db, int $lotId, float $qtyChange, string $reason, string $note): void {
    if ($qtyChange == 0) throw new StockException('Enter a quantity to add or remove.');
    if (!isset(ADJUST_REASONS[$reason])) throw new StockException('Pick a reason.');
    if ($reason === 'other' && trim($note) === '') throw new StockException('Describe the reason in the note.');
    stockTx($db, function () use ($db, $lotId, $qtyChange, $reason, $note) {
        $stmt = $db->prepare("SELECT product_id FROM stock_lots WHERE id = ?");
        $stmt->execute([$lotId]);
        $pid = (int)$stmt->fetchColumn();
        if (!$pid) throw new StockException('Batch not found.');
        lockProduct($db, $pid);
        addMovement($db, ['product_id' => $pid, 'lot_id' => $lotId, 'type' => 'adjust',
                          'qty_change' => $qtyChange, 'reason' => $reason, 'note' => trim($note) ?: null]);
    });
}

function setLotStatus(PDO $db, int $lotId, string $status): void {
    if (!in_array($status, ['available', 'quarantined', 'recalled'], true)) throw new StockException('Unknown status.');
    $db->prepare("UPDATE stock_lots SET status = ? WHERE id = ?")->execute([$status, $lotId]);
}

// ─────────────────────────────────────────────
// OPENED MULTI-DOSE CONTAINERS (e.g. a toxin vial shared between patients)
// An open container is part of its batch: its remainder is still in stock_lots.qty_on_hand.
// Treatments draw from open containers first; past its use-by time the remainder is written off.
// ─────────────────────────────────────────────
function isMultiDose(array $p): bool {
    return !empty($p['opened_use_hours']) && (float)$p['units_per_stock'] > 1;
}

function openContainers(PDO $db, ?int $productId = null): array {
    $sql = "SELECT c.*, l.lot_number, l.expiry_date, l.status AS lot_status, p.name AS product_name, p.usage_unit, p.stock_unit, s.name AS opened_by_name
            FROM opened_containers c JOIN stock_lots l ON l.id = c.lot_id JOIN products p ON p.id = c.product_id LEFT JOIN staff s ON s.id = c.opened_by
            WHERE c.status = 'open'" . ($productId ? " AND c.product_id = " . (int)$productId : '') . " ORDER BY c.use_by, c.id";
    return $db->query($sql)->fetchAll();
}

function openQtyInLot(PDO $db, int $lotId): float {
    $s = $db->prepare("SELECT COALESCE(SUM(qty_left),0) FROM opened_containers WHERE lot_id = ? AND status = 'open'");
    $s->execute([$lotId]);
    return (float)$s->fetchColumn();
}

/** Keeps open containers within what their batch still holds (after wastage, corrections or counts). */
function trimOpenContainers(PDO $db, int $lotId, float $onHand): void {
    $s = $db->prepare("SELECT id, qty_left FROM opened_containers WHERE lot_id = ? AND status = 'open' ORDER BY opened_at DESC, id DESC");
    $s->execute([$lotId]);
    $rows = $s->fetchAll();
    $excess = array_sum(array_map(fn($r) => (float)$r['qty_left'], $rows)) - max(0, $onHand);
    foreach ($rows as $r) {
        if ($excess <= 0.0005) break;
        $cut = min($excess, (float)$r['qty_left']);
        $newLeft = round((float)$r['qty_left'] - $cut, 3);
        $db->prepare("UPDATE opened_containers SET qty_left = ?, status = IF(? <= 0, 'finished', status), closed_at = IF(? <= 0, NOW(), closed_at) WHERE id = ?")
           ->execute([$newLeft, $newLeft, $newLeft, $r['id']]);
        $excess -= $cut;
    }
}

/** Writes off the remainder of every open container past its use-by time. Returns how many were closed. */
function expireOpenContainers(PDO $db, ?int $productId = null): int {
    $s = $db->prepare("SELECT id, product_id FROM opened_containers WHERE status = 'open' AND use_by < NOW()" . ($productId ? " AND product_id = ?" : ''));
    $s->execute($productId ? [$productId] : []);
    $n = 0;
    foreach ($s->fetchAll() as $c) {
        $n += stockTx($db, function () use ($db, $c) {
            lockProduct($db, (int)$c['product_id']);
            $row = $db->prepare("SELECT * FROM opened_containers WHERE id = ? AND status = 'open' FOR UPDATE");
            $row->execute([$c['id']]);
            $oc = $row->fetch();
            if (!$oc) return 0;
            closeContainer($db, $oc, 'opened_leftover', 'Use-by time passed (' . date('d M Y, H:i', strtotime($oc['use_by'])) . ')', true);
            return 1;
        });
    }
    return $n;
}

/** Discards what is left in an open container as wastage. Call inside stockTx with the product locked. */
function closeContainer(PDO $db, array $oc, string $reason, ?string $note, bool $bySystem = false): void {
    $db->prepare("UPDATE opened_containers SET status = 'discarded', qty_left = 0, closed_at = NOW(), closed_by = ? WHERE id = ?")
       ->execute([$bySystem ? null : (currentStaffId() ?: null), $oc['id']]);
    $onHand = $db->prepare("SELECT qty_on_hand FROM stock_lots WHERE id = ?");
    $onHand->execute([$oc['lot_id']]);
    $qty = min((float)$oc['qty_left'], (float)$onHand->fetchColumn());
    if ($qty > 0.0005) {
        addMovement($db, ['product_id' => $oc['product_id'], 'lot_id' => $oc['lot_id'], 'container_id' => $oc['id'], 'type' => 'waste',
                          'qty_change' => -$qty, 'reason' => $reason, 'note' => $note, 'staff_id' => $bySystem ? null : (currentStaffId() ?: null)]);
    }
}

/** Opens a sealed container from a batch. Returns the new container row, or null when the batch has nothing sealed left. */
function openNewContainer(PDO $db, array $p, int $lotId): ?array {
    $s = $db->prepare("SELECT qty_on_hand FROM stock_lots WHERE id = ?");
    $s->execute([$lotId]);
    $sealed = (float)$s->fetchColumn() - openQtyInLot($db, $lotId);
    if ($sealed <= 0.0005) return null;
    $qty = round(min((float)$p['units_per_stock'], $sealed), 3);
    $useBy = date('Y-m-d H:i:s', time() + (int)$p['opened_use_hours'] * 3600);
    $db->prepare("INSERT INTO opened_containers (product_id, lot_id, qty_initial, qty_left, opened_by, use_by) VALUES (?,?,?,?,?,?)")
       ->execute([$p['id'], $lotId, $qty, $qty, currentStaffId() ?: null, $useBy]);
    $s = $db->prepare("SELECT * FROM opened_containers WHERE id = ?");
    $s->execute([(int)$db->lastInsertId()]);
    return $s->fetch();
}

/** Takes up to $want from an open container. Returns the amount taken. */
function takeFromContainer(PDO $db, array $oc, float $want, int $apptId): float {
    $take = round(min($want, (float)$oc['qty_left']), 3);
    if ($take <= 0) return 0;
    $left = round((float)$oc['qty_left'] - $take, 3);
    $db->prepare("UPDATE opened_containers SET qty_left = ?, status = IF(? <= 0, 'finished', 'open'), closed_at = IF(? <= 0, NOW(), NULL) WHERE id = ?")
       ->execute([$left, $left, $left, $oc['id']]);
    addMovement($db, ['product_id' => $oc['product_id'], 'lot_id' => $oc['lot_id'], 'container_id' => $oc['id'], 'type' => 'consume',
                      'qty_change' => -$take, 'appointment_id' => $apptId]);
    return $take;
}

/** Multi-dose consumption: open containers first (chosen batch first), then open new ones, earliest expiry first. Returns what couldn't be supplied. */
function consumeMultiDose(PDO $db, array $p, array $lots, float $qty, int $apptId, ?int $preferLotId): float {
    $usable = array_map(fn($l) => (int)$l['id'], $lots);
    $open = array_values(array_filter(openContainers($db, (int)$p['id']), fn($c) => in_array((int)$c['lot_id'], $usable, true)));
    $left = $qty;
    $drainOpen = function (?int $lotId) use ($db, &$open, &$left, $apptId) {
        foreach ($open as $i => $c) {
            if ($left <= 0.0005) return;
            if ($lotId !== null && (int)$c['lot_id'] !== $lotId) continue;
            $left = round($left - takeFromContainer($db, $c, $left, $apptId), 3);
            unset($open[$i]);
        }
    };
    $drainSealed = function (array $lotIds) use ($db, $p, &$left, $apptId) {
        foreach ($lotIds as $lotId) {
            while ($left > 0.0005 && ($oc = openNewContainer($db, $p, $lotId))) {
                $left = round($left - takeFromContainer($db, $oc, $left, $apptId), 3);
            }
        }
    };
    if ($preferLotId) { $drainOpen($preferLotId); $drainSealed([$preferLotId]); }
    $drainOpen(null);
    $drainSealed($usable);
    return $left;
}

// ─────────────────────────────────────────────
// WASTAGE
// ─────────────────────────────────────────────
/** Records wastage from a batch, in usage units. With $containerId, discards whatever is left in that open container. */
function recordWastage(PDO $db, int $lotId, float $qty, string $reason, string $note, ?int $containerId = null): void {
    if (!isset(WASTE_REASONS[$reason])) throw new StockException('Pick a reason.');
    if ($reason === 'other' && trim($note) === '') throw new StockException('Describe what happened in the note.');
    if (!$containerId && $qty <= 0) throw new StockException('Enter the quantity wasted.');
    stockTx($db, function () use ($db, $lotId, $qty, $reason, $note, $containerId) {
        $s = $db->prepare("SELECT product_id FROM stock_lots WHERE id = ?");
        $s->execute([$lotId]);
        $pid = (int)$s->fetchColumn();
        if (!$pid) throw new StockException('Batch not found.');
        lockProduct($db, $pid);
        if ($containerId) {
            $c = $db->prepare("SELECT * FROM opened_containers WHERE id = ? AND lot_id = ? AND status = 'open' FOR UPDATE");
            $c->execute([$containerId, $lotId]);
            $oc = $c->fetch();
            if (!$oc) throw new StockException('That opened vial is already closed.');
            closeContainer($db, $oc, $reason, trim($note) ?: null);
            return;
        }
        $s = $db->prepare("SELECT qty_on_hand FROM stock_lots WHERE id = ?");
        $s->execute([$lotId]);
        $onHand = (float)$s->fetchColumn();
        if ($qty > $onHand + 0.0005) throw new StockException('The batch only has ' . fmtQty($onHand) . ' on record. Enter what was actually wasted.');
        addMovement($db, ['product_id' => $pid, 'lot_id' => $lotId, 'type' => 'waste', 'qty_change' => -round($qty, 3),
                          'reason' => $reason, 'note' => trim($note) ?: null]);
    });
    logActivity(currentStaffId(), 'stock_wasted', null, null, reasonLabel($reason) . ' lot #' . $lotId);
}

// ─────────────────────────────────────────────
// STOCK COUNTS  counting → review differences → submitted. Small differences post at once;
// large or prescription-only ones wait for an admin who didn't submit the count.
// ─────────────────────────────────────────────
function getStockCount(PDO $db, int $id): ?array {
    $s = $db->prepare("SELECT c.*, a.name AS created_by_name, b.name AS submitted_by_name FROM stock_counts c
                       LEFT JOIN staff a ON a.id = c.created_by LEFT JOIN staff b ON b.id = c.submitted_by WHERE c.id = ?");
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

/** Lines with live system quantities, product details and batch cost. */
function stockCountLines(PDO $db, int $countId): array {
    $s = $db->prepare("SELECT cl.*, l.lot_number, l.expiry_date, l.qty_on_hand AS system_qty, l.unit_cost, l.status AS lot_status,
                              p.name AS product_name, p.category, p.usage_unit, p.stock_unit, p.units_per_stock, p.prescription_only,
                              d.name AS decided_by_name
                       FROM stock_count_lines cl JOIN stock_lots l ON l.id = cl.lot_id JOIN products p ON p.id = cl.product_id
                       LEFT JOIN staff d ON d.id = cl.decided_by
                       WHERE cl.count_id = ? ORDER BY p.category, p.name, l.expiry_date IS NULL, l.expiry_date, l.id");
    $s->execute([$countId]);
    return $s->fetchAll();
}

/** Batch cost of one usage unit (e.g. per toxin unit), 0 when the cost wasn't entered. */
function costPerUsageUnit(array $row): float {
    return $row['unit_cost'] !== null && (float)$row['units_per_stock'] > 0 ? (float)$row['unit_cost'] / (float)$row['units_per_stock'] : 0.0;
}

function countNeedsApproval(array $line, float $diff): bool {
    return (int)$line['prescription_only'] === 1 || abs($diff) * costPerUsageUnit($line) > COUNT_APPROVAL_AED;
}

function startStockCount(PDO $db, ?string $category, string $note): int {
    if ((int)$db->query("SELECT COUNT(*) FROM stock_counts WHERE status = 'counting'")->fetchColumn()) {
        throw new StockException('A count is already in progress. Finish or abandon it first.');
    }
    expireOpenContainers($db);
    $id = stockTx($db, function () use ($db, $category, $note) {
        $db->prepare("INSERT INTO stock_counts (category, note, created_by) VALUES (?,?,?)")
           ->execute([$category ?: null, trim($note) ?: null, currentStaffId() ?: null]);
        $id = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO stock_count_lines (count_id, product_id, lot_id)
            SELECT ?, l.product_id, l.id FROM stock_lots l JOIN products p ON p.id = l.product_id
            WHERE l.qty_on_hand > 0 AND p.is_active = 1" . ($category ? " AND p.category = ?" : ''));
        $ins->execute($category ? [$id, $category] : [$id]);
        if (!$ins->rowCount()) throw new StockException('There is no stock on record' . ($category ? ' in that category' : '') . ' to count.');
        return $id;
    });
    logActivity(currentStaffId(), 'stock_count_started', null, null, '#' . $id);
    return $id;
}

/** Saves counted quantities, reasons and notes while counting. $entries[line_id] = ['counted' => ?, 'reason' => ?, 'note' => ?]. */
function saveCountEntries(PDO $db, int $countId, array $entries): void {
    $c = getStockCount($db, $countId);
    if (!$c || $c['status'] !== 'counting') throw new StockException('This count is no longer open.');
    $cur = $db->prepare("SELECT counted_qty, reason, note FROM stock_count_lines WHERE id = ? AND count_id = ?");
    $upd = $db->prepare("UPDATE stock_count_lines SET counted_qty = ?, reason = ?, note = ? WHERE id = ? AND count_id = ?");
    foreach ($entries as $lineId => $e) {
        $cur->execute([(int)$lineId, $countId]);
        $old = $cur->fetch();
        if (!$old || !is_array($e)) continue;
        $counted = $old['counted_qty'];
        if (array_key_exists('counted', $e)) {
            $raw = trim((string)$e['counted']);
            if ($raw !== '' && !is_numeric($raw)) throw new StockException('Counted quantities must be numbers.');
            $counted = $raw === '' ? null : round((float)$raw, 3);
            if ($counted !== null && $counted < 0) throw new StockException('A counted quantity can’t be negative.');
        }
        $reason = array_key_exists('reason', $e) ? (isset(COUNT_REASONS[$e['reason']]) ? $e['reason'] : null) : $old['reason'];
        $note = array_key_exists('note', $e) ? (mb_substr(trim((string)$e['note']), 0, 255) ?: null) : $old['note'];
        $upd->execute([$counted, $reason, $note, (int)$lineId, $countId]);
    }
}

/** Finalises a count. Returns ['posted' => n, 'pending' => n, 'same' => n]. */
function submitStockCount(PDO $db, int $countId): array {
    expireOpenContainers($db);
    $result = stockTx($db, function () use ($db, $countId) {
        $row = $db->prepare("SELECT * FROM stock_counts WHERE id = ? FOR UPDATE");
        $row->execute([$countId]);
        $c = $row->fetch();
        if (!$c || $c['status'] !== 'counting') throw new StockException('This count is no longer open.');
        $out = ['posted' => 0, 'pending' => 0, 'same' => 0];
        foreach (stockCountLines($db, $countId) as $ln) {
            $label = $ln['product_name'] . ', batch ' . ($ln['lot_number'] ?: 'without number');
            if ($ln['counted_qty'] === null) throw new StockException("Enter a count for every batch. Missing: {$label}.");
            lockProduct($db, (int)$ln['product_id']);
            $sys = $db->prepare("SELECT qty_on_hand FROM stock_lots WHERE id = ?");
            $sys->execute([$ln['lot_id']]);
            $expected = (float)$sys->fetchColumn();
            $diff = round((float)$ln['counted_qty'] - $expected, 3);
            $value = round(abs($diff) * costPerUsageUnit($ln), 2);
            if (abs($diff) < 0.0005) {
                $db->prepare("UPDATE stock_count_lines SET expected_qty = ?, diff_qty = 0, diff_value = 0, status = 'no_difference' WHERE id = ?")->execute([$expected, $ln['id']]);
                $out['same']++;
                continue;
            }
            if (!$ln['reason']) throw new StockException("Pick a reason for the difference on {$label}.");
            if ($ln['reason'] === 'other' && !$ln['note']) throw new StockException("Describe the reason for {$label} in the note.");
            if (countNeedsApproval($ln, $diff)) {
                $db->prepare("UPDATE stock_count_lines SET expected_qty = ?, diff_qty = ?, diff_value = ?, status = 'pending' WHERE id = ?")->execute([$expected, $diff, $value, $ln['id']]);
                $out['pending']++;
            } else {
                $mv = addMovement($db, ['product_id' => $ln['product_id'], 'lot_id' => $ln['lot_id'], 'type' => 'count', 'qty_change' => $diff,
                                        'reason' => $ln['reason'], 'note' => 'Stock count #' . $countId . ($ln['note'] ? ': ' . $ln['note'] : '')]);
                $db->prepare("UPDATE stock_count_lines SET expected_qty = ?, diff_qty = ?, diff_value = ?, status = 'posted', movement_id = ? WHERE id = ?")->execute([$expected, $diff, $value, $mv, $ln['id']]);
                $out['posted']++;
            }
        }
        $status = $out['pending'] ? 'awaiting_approval' : 'closed';
        $db->prepare("UPDATE stock_counts SET status = ?, submitted_by = ?, submitted_at = NOW(), closed_at = " . ($status === 'closed' ? 'NOW()' : 'NULL') . " WHERE id = ?")
           ->execute([$status, currentStaffId() ?: null, $countId]);
        return $out;
    });
    logActivity(currentStaffId(), 'stock_count_submitted', null, null, '#' . $countId);
    return $result;
}

/** Admin decision on one difference. The person who submitted the count can't approve it. */
function decideCountLine(PDO $db, int $lineId, bool $approve, string $note): void {
    stockTx($db, function () use ($db, $lineId, $approve, $note) {
        $s = $db->prepare("SELECT cl.*, c.submitted_by, c.status AS count_status FROM stock_count_lines cl JOIN stock_counts c ON c.id = cl.count_id WHERE cl.id = ? FOR UPDATE");
        $s->execute([$lineId]);
        $ln = $s->fetch();
        if (!$ln || $ln['status'] !== 'pending' || $ln['count_status'] !== 'awaiting_approval') throw new StockException('This difference has already been decided.');
        if ((int)$ln['submitted_by'] === currentStaffId()) throw new StockException('You submitted this count, so another admin has to decide its differences.');
        if (!$approve && trim($note) === '') throw new StockException('Say why the difference is rejected, so the batch can be counted again.');
        $mv = null;
        if ($approve) {
            lockProduct($db, (int)$ln['product_id']);
            try {
                $mv = addMovement($db, ['product_id' => $ln['product_id'], 'lot_id' => $ln['lot_id'], 'type' => 'count', 'qty_change' => (float)$ln['diff_qty'],
                                        'reason' => $ln['reason'], 'note' => 'Stock count #' . $ln['count_id'] . ' (approved)' . ($ln['note'] ? ': ' . $ln['note'] : '')]);
            } catch (StockException $e) {
                throw new StockException('Stock from this batch has been used since the count, so the difference no longer fits. Reject it and count the batch again.');
            }
        }
        $db->prepare("UPDATE stock_count_lines SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = ?, movement_id = ? WHERE id = ?")
           ->execute([$approve ? 'approved' : 'rejected', currentStaffId() ?: null, trim($note) ?: null, $mv, $lineId]);
        $left = $db->prepare("SELECT COUNT(*) FROM stock_count_lines WHERE count_id = ? AND status = 'pending'");
        $left->execute([$ln['count_id']]);
        if (!(int)$left->fetchColumn()) $db->prepare("UPDATE stock_counts SET status = 'closed', closed_at = NOW() WHERE id = ?")->execute([$ln['count_id']]);
    });
    logActivity(currentStaffId(), $approve ? 'count_difference_approved' : 'count_difference_rejected', null, null, 'line #' . $lineId);
}

function abandonStockCount(PDO $db, int $countId): void {
    $n = $db->prepare("UPDATE stock_counts SET status = 'abandoned', closed_at = NOW() WHERE id = ? AND status = 'counting'");
    $n->execute([$countId]);
    if (!$n->rowCount()) throw new StockException('Only a count in progress can be abandoned.');
}

function pendingApprovalCount(PDO $db): int {
    return (int)$db->query("SELECT COUNT(*) FROM stock_count_lines WHERE status = 'pending'")->fetchColumn();
}

/** Sub-navigation shared by the stock pages. */
function inventoryTabs(string $active): string {
    $tabs = ['inventory' => ['Stock', '/pages/inventory.php', 'inventory.view'],
             'wastage'   => ['Wastage', '/pages/wastage.php', 'inventory.view'],
             'counts'    => ['Stock counts', '/pages/stock_counts.php', 'inventory.view'],
             'recall'    => ['Batch recall', '/pages/recall.php', 'inventory.view'],
             'purchasing'=> ['Purchasing', '/pages/purchasing.php', 'purchasing.manage'],
             'reports'   => ['Reports', '/pages/stock_reports.php', 'inventory.reports']];
    $h = '<nav class="segmented inv-tabs" aria-label="Inventory sections">';
    foreach ($tabs as $k => [$label, $href, $perm]) {
        if (!can($perm)) continue;
        $h .= '<a href="' . $href . '"' . ($k === $active ? ' class="active" aria-current="page"' : '') . '>' . $label . '</a>';
    }
    return $h . '</nav>';
}

// ─────────────────────────────────────────────
// PURCHASING  suppliers → purchase orders (draft → ordered → partial → received / closed) → receive against a line.
// Order quantities and costs are in the product's stock unit (vials, boxes), like receiving.
// ─────────────────────────────────────────────
const PO_STATUS = [
    'draft'     => ['Draft', ''],
    'ordered'   => ['Ordered', 'aging-due'],
    'partial'   => ['Part received', 'aging-overdue'],
    'received'  => ['Received', 'aging-fresh'],
    'closed'    => ['Closed short', 'aging-fresh'],
    'cancelled' => ['Cancelled', ''],
];

function poNumber(int $id): string {
    return 'PO-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
}

function poBadge(string $status): string {
    [$label, $cls] = PO_STATUS[$status] ?? [ucfirst($status), ''];
    return '<span class="chip ' . $cls . '">' . $label . '</span>';
}

function supplierOptions(PDO $db, bool $activeOnly = true): array {
    return $db->query("SELECT * FROM suppliers" . ($activeOnly ? " WHERE is_active = 1" : '') . " ORDER BY name")->fetchAll();
}

/** Stock still to arrive on open orders, per product, in usage units. */
function onOrderByProduct(PDO $db): array {
    $rows = $db->query("SELECT pl.product_id, SUM(GREATEST(pl.qty_ordered - pl.qty_received, 0) * p.units_per_stock) AS qty
                        FROM purchase_order_lines pl JOIN purchase_orders po ON po.id = pl.po_id JOIN products p ON p.id = pl.product_id
                        WHERE po.status IN ('ordered','partial') GROUP BY pl.product_id")->fetchAll();
    return array_map('floatval', array_column($rows, 'qty', 'product_id'));
}

/**
 * Products to order: projected = usable on hand + on order − reserved for bookings.
 * Suggested when projected is below the reorder level, or below zero (bookings not covered).
 * Suggested quantity (stock units, rounded up) brings it back to the reorder level, at least the usual order quantity.
 */
function reorderSuggestions(PDO $db): array {
    $onOrder = onOrderByProduct($db);
    $lastCost = array_column($db->query("SELECT l.product_id, l.unit_cost FROM stock_lots l
        JOIN (SELECT product_id, MAX(id) AS id FROM stock_lots WHERE unit_cost IS NOT NULL GROUP BY product_id) x ON x.id = l.id")->fetchAll(), 'unit_cost', 'product_id');
    $out = [];
    foreach (stockSummary($db) as $p) {
        $pid = (int)$p['id'];
        $projected = (float)$p['on_hand'] + ($onOrder[$pid] ?? 0) - (float)$p['qty_reserved'];
        $level = (float)$p['reorder_level'];
        if ($projected >= $level && $projected >= 0) continue;
        if ($level <= 0 && $projected >= 0) continue;
        $needUsage = max($level - $projected, (float)$p['reorder_qty'], 0.001);
        $per = max((float)$p['units_per_stock'], 0.001);
        $p['projected'] = $projected;
        $p['on_order'] = $onOrder[$pid] ?? 0;
        $p['suggest_stock_units'] = (int)ceil(round($needUsage / $per, 6));
        $p['last_cost'] = isset($lastCost[$pid]) ? (float)$lastCost[$pid] : null;
        $p['why'] = $projected < 0 ? 'bookings' : 'level';
        $out[] = $p;
    }
    return $out;
}

function getPurchaseOrder(PDO $db, int $id): ?array {
    $s = $db->prepare("SELECT po.*, s.name AS supplier_name, s.contact_name, s.phone AS supplier_phone, s.email AS supplier_email,
                              a.name AS created_by_name, b.name AS ordered_by_name
                       FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id
                       LEFT JOIN staff a ON a.id = po.created_by LEFT JOIN staff b ON b.id = po.ordered_by WHERE po.id = ?");
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function purchaseOrderLines(PDO $db, int $poId): array {
    $s = $db->prepare("SELECT pl.*, p.name AS product_name, p.stock_unit, p.usage_unit, p.units_per_stock, p.sku, p.gtin, p.brand
                       FROM purchase_order_lines pl JOIN products p ON p.id = pl.product_id WHERE pl.po_id = ? ORDER BY p.name, pl.id");
    $s->execute([$poId]);
    return $s->fetchAll();
}

/** Creates a draft order. $lines = [['product_id' => , 'qty' => stock units, 'unit_cost' => ?]]. */
function createPurchaseOrder(PDO $db, int $supplierId, array $lines, string $notes = ''): int {
    $sup = $db->prepare("SELECT id FROM suppliers WHERE id = ? AND is_active = 1");
    $sup->execute([$supplierId]);
    if (!$sup->fetch()) throw new StockException('Pick a supplier.');
    $lines = array_values(array_filter($lines, fn($l) => (float)($l['qty'] ?? 0) > 0));
    if (!$lines) throw new StockException('Add at least one product with a quantity.');
    $id = stockTx($db, function () use ($db, $supplierId, $lines, $notes) {
        $db->prepare("INSERT INTO purchase_orders (supplier_id, notes, created_by) VALUES (?,?,?)")
           ->execute([$supplierId, trim($notes) ?: null, currentStaffId() ?: null]);
        $id = (int)$db->lastInsertId();
        writePoLines($db, $id, $lines);
        return $id;
    });
    logActivity(currentStaffId(), 'po_created', null, null, poNumber($id));
    return $id;
}

function writePoLines(PDO $db, int $poId, array $lines): void {
    $db->prepare("DELETE FROM purchase_order_lines WHERE po_id = ?")->execute([$poId]);
    $ins = $db->prepare("INSERT INTO purchase_order_lines (po_id, product_id, qty_ordered, unit_cost) VALUES (?,?,?,?)");
    $seen = [];
    foreach ($lines as $l) {
        $pid = (int)($l['product_id'] ?? 0);
        $qty = round((float)($l['qty'] ?? 0), 3);
        if (!$pid || $qty <= 0) continue;
        if (isset($seen[$pid])) throw new StockException('Each product can only be on the order once.');
        $seen[$pid] = true;
        $cost = isset($l['unit_cost']) && $l['unit_cost'] !== '' && $l['unit_cost'] !== null ? max(0, round((float)$l['unit_cost'], 2)) : null;
        $ins->execute([$poId, $pid, $qty, $cost]);
    }
    if (!$seen) throw new StockException('Add at least one product with a quantity.');
}

/** Replaces the lines, supplier, expected date and notes of a draft order. */
function updateDraftOrder(PDO $db, int $poId, int $supplierId, ?string $expected, string $notes, array $lines): void {
    stockTx($db, function () use ($db, $poId, $supplierId, $expected, $notes, $lines) {
        $s = $db->prepare("SELECT status FROM purchase_orders WHERE id = ? FOR UPDATE");
        $s->execute([$poId]);
        if ($s->fetchColumn() !== 'draft') throw new StockException('Only a draft order can be edited.');
        $db->prepare("UPDATE purchase_orders SET supplier_id = ?, expected_date = ?, notes = ? WHERE id = ?")
           ->execute([$supplierId, $expected ?: null, trim($notes) ?: null, $poId]);
        writePoLines($db, $poId, $lines);
    });
}

/** draft → ordered, ordered/partial → closed (stop waiting for the rest), draft/ordered → cancelled (nothing received). */
function setPoStatus(PDO $db, int $poId, string $to): void {
    stockTx($db, function () use ($db, $poId, $to) {
        $s = $db->prepare("SELECT po.status, COALESCE(SUM(pl.qty_received),0) AS got, COUNT(pl.id) AS n
                           FROM purchase_orders po LEFT JOIN purchase_order_lines pl ON pl.po_id = po.id WHERE po.id = ? GROUP BY po.id FOR UPDATE");
        $s->execute([$poId]);
        $po = $s->fetch();
        if (!$po) throw new StockException('Order not found.');
        $from = $po['status'];
        $ok = ['ordered' => ['draft'], 'closed' => ['ordered', 'partial'], 'cancelled' => ['draft', 'ordered']][$to] ?? [];
        if (!in_array($from, $ok, true)) throw new StockException('That change isn’t possible for an order that is ' . strtolower(PO_STATUS[$from][0]) . '.');
        if ($to === 'ordered' && !(int)$po['n']) throw new StockException('Add products before placing the order.');
        if ($to === 'cancelled' && (float)$po['got'] > 0) throw new StockException('Stock has already been received on this order. Close it instead.');
        $set = $to === 'ordered' ? ", ordered_by = " . (int)currentStaffId() . ", ordered_at = NOW()" : ", closed_at = NOW()";
        $db->prepare("UPDATE purchase_orders SET status = ?$set WHERE id = ?")->execute([$to, $poId]);
    });
    logActivity(currentStaffId(), 'po_' . $to, null, null, poNumber($poId));
}

/** Recomputes ordered/partial/received after a receipt. Call inside stockTx. */
function refreshPoStatus(PDO $db, int $poId): void {
    $s = $db->prepare("SELECT SUM(qty_received) AS got, SUM(qty_received + 0.0005 >= qty_ordered) AS full, COUNT(*) AS n FROM purchase_order_lines WHERE po_id = ?");
    $s->execute([$poId]);
    $r = $s->fetch();
    $status = (int)$r['full'] === (int)$r['n'] ? 'received' : ((float)$r['got'] > 0 ? 'partial' : 'ordered');
    $db->prepare("UPDATE purchase_orders SET status = ?, closed_at = IF(? = 'received', NOW(), NULL) WHERE id = ? AND status IN ('ordered','partial')")
       ->execute([$status, $status, $poId]);
}

// ─────────────────────────────────────────────
// BARCODES  GS1: (01) GTIN-14, (17) expiry YYMMDD, (10) batch. Products store a 14-digit GTIN.
// ─────────────────────────────────────────────
/** 8, 12, 13 or 14 digits → 14-digit GTIN with a valid check digit, else null. */
function normalizeGtin(?string $raw): ?string {
    $d = preg_replace('/\D/', '', (string)$raw);
    if (strlen($d) === 16 && str_starts_with($d, '01')) $d = substr($d, 2);   // pasted with its (01) prefix
    if (!in_array(strlen($d), [8, 12, 13, 14], true)) return null;
    $d = str_pad($d, 14, '0', STR_PAD_LEFT);
    $sum = 0;
    for ($i = 0; $i < 13; $i++) $sum += (int)$d[$i] * ($i % 2 === 0 ? 3 : 1);
    return (10 - $sum % 10) % 10 === (int)$d[13] ? $d : null;
}

// ─────────────────────────────────────────────
// SCHEDULED MAINTENANCE  (run by cron.php; safe to run any number of times)
// ─────────────────────────────────────────────
function metaGet(PDO $db, string $k): ?string {
    $s = $db->prepare("SELECT v FROM app_meta WHERE k = ?");
    $s->execute([$k]);
    $v = $s->fetchColumn();
    return $v === false ? null : $v;
}

function metaSet(PDO $db, string $k, string $v): void {
    $db->prepare("REPLACE INTO app_meta (k, v) VALUES (?, ?)")->execute([$k, mb_substr($v, 0, 255)]);
}

/**
 * Writes off expired opened vials, reminds staff about treatments that were never completed,
 * and checks that every cached total still equals its ledger. Mismatches are reported, never fixed silently.
 */
function runInventoryMaintenance(PDO $db): array {
    $out = ['vials_written_off' => expireOpenContainers($db), 'overdue_reminders' => 0, 'overdue_total' => 0];

    // Past appointments still marked booked hold their stock reserved; remind whoever treats or booked them, once a day
    $overdue = $db->query("SELECT a.id, a.lead_id, a.scheduled_at, COALESCE(a.practitioner_id, a.booked_by) AS staff_id, l.name, s.name AS service
                           FROM appointments a JOIN leads l ON l.id = a.lead_id JOIN services s ON s.id = a.service_id
                           WHERE a.status IN ('booked','confirmed','arrived','in_triage','with_doctor') AND a.scheduled_at < NOW() - INTERVAL 1 HOUR")->fetchAll();
    $out['overdue_total'] = count($overdue);
    $seen = $db->prepare("SELECT 1 FROM reminders WHERE lead_id = ? AND message LIKE ? AND created_at > NOW() - INTERVAL 20 HOUR LIMIT 1");
    $add = $db->prepare("INSERT INTO reminders (lead_id, staff_id, message, remind_at, type) VALUES (?,?,?,NOW(),'manual')");
    foreach ($overdue as $a) {
        if (!$a['staff_id']) continue;
        $tag = 'Treatment not completed (#' . $a['id'] . ')%';
        $seen->execute([$a['lead_id'], $tag]);
        if ($seen->fetchColumn()) continue;
        $add->execute([$a['lead_id'], $a['staff_id'], 'Treatment not completed (#' . $a['id'] . '): ' . $a['service'] . ' for ' . $a['name'] . ' on '
            . date('d M, H:i', strtotime($a['scheduled_at'])) . '. Complete it, or mark it cancelled or no-show to release the reserved stock.']);
        $out['overdue_reminders']++;
    }

    // Ledger check: batch on-hand and product reservations must equal the sum of their movements
    $badLots = $db->query("SELECT l.id FROM stock_lots l LEFT JOIN (SELECT lot_id, SUM(qty_change) AS s FROM stock_movements WHERE lot_id IS NOT NULL GROUP BY lot_id) m ON m.lot_id = l.id
                           WHERE ABS(l.qty_on_hand - COALESCE(m.s, 0)) > 0.0005")->fetchAll(PDO::FETCH_COLUMN);
    $badReserved = $db->query("SELECT p.id FROM products p LEFT JOIN (SELECT product_id, SUM(reserved_change) AS s FROM stock_movements GROUP BY product_id) m ON m.product_id = p.id
                               WHERE ABS(p.qty_reserved - GREATEST(COALESCE(m.s, 0), 0)) > 0.0005")->fetchAll(PDO::FETCH_COLUMN);
    $out['ledger_ok'] = !$badLots && !$badReserved;
    metaSet($db, 'inventory_check', json_encode(['at' => date('Y-m-d H:i:s'), 'lots' => array_map('intval', array_slice($badLots, 0, 20)), 'products' => array_map('intval', array_slice($badReserved, 0, 20))]));
    if (!$out['ledger_ok']) logActivity(0, 'inventory_check_failed', null, null, 'batches ' . implode(',', $badLots) . ' · products ' . implode(',', $badReserved));
    return $out;
}

/** Problems an admin should see on the stock pages: scheduled job not running, ledger mismatch. */
function maintenanceWarnings(PDO $db): array {
    $w = [];
    $last = metaGet($db, 'cron_last_run');
    if (!$last || strtotime($last) < time() - 26 * 3600) {
        $w[] = 'The scheduled job hasn’t run ' . ($last ? 'since ' . date('d M Y, H:i', strtotime($last)) : 'yet') . '. Opened vials are still written off when stock pages are used, but overdue-treatment reminders and the nightly stock check need it. Set up the cron job in hPanel (Advanced → Cron Jobs).';
    }
    $check = json_decode((string)metaGet($db, 'inventory_check'), true);
    if ($check && ($check['lots'] || $check['products'])) {
        $w[] = 'The stock check on ' . date('d M, H:i', strtotime($check['at'])) . ' found figures that don’t match the stock history (batches ' . (implode(', ', $check['lots']) ?: '–') . '; products ' . (implode(', ', $check['products']) ?: '–') . '). Nothing was changed. Ask for this to be investigated before the next count.';
    }
    return $w;
}

// ─────────────────────────────────────────────
// DELETING  Only records nothing refers to can be deleted; anything with history is hidden instead,
// so the stock ledger, recalls and patient records stay complete.
// ─────────────────────────────────────────────
/** Why a product can't be deleted (empty array = it can). */
function productDeleteBlockers(PDO $db, int $productId): array {
    $checks = [
        "SELECT COUNT(*) FROM stock_lots WHERE product_id = ?"            => 'it has stock or stock history',
        "SELECT COUNT(*) FROM appointment_items WHERE product_id = ?"     => 'treatments have reserved or used it',
        "SELECT COUNT(*) FROM purchase_order_lines WHERE product_id = ?"  => 'it is on a purchase order',
    ];
    $out = [];
    foreach ($checks as $sql => $why) {
        $s = $db->prepare($sql);
        $s->execute([$productId]);
        if ((int)$s->fetchColumn()) $out[] = $why;
    }
    $s = $db->prepare("SELECT s.name FROM service_items si JOIN services s ON s.id = si.service_id WHERE si.product_id = ? ORDER BY s.name");
    $s->execute([$productId]);
    if ($names = $s->fetchAll(PDO::FETCH_COLUMN)) $out[] = 'it is in the recipe of ' . implode(', ', $names);
    return $out;
}

/** Why a treatment can't be deleted (empty array = it can). */
function serviceDeleteBlockers(PDO $db, int $serviceId): array {
    $s = $db->prepare("SELECT COUNT(*) FROM appointments WHERE service_id = ?");
    $s->execute([$serviceId]);
    $n = (int)$s->fetchColumn();
    return $n ? ["it has been booked $n time" . ($n === 1 ? '' : 's')] : [];
}

/** "a", "a and b", "a, b and c" */
function listAnd(array $items): string {
    return count($items) > 1 ? implode(', ', array_slice($items, 0, -1)) . ' and ' . end($items) : (string)($items[0] ?? '');
}

/** Deletes a product nothing refers to. Throws with the reasons otherwise. */
function deleteProduct(PDO $db, int $productId): string {
    $s = $db->prepare("SELECT name FROM products WHERE id = ?");
    $s->execute([$productId]);
    $name = $s->fetchColumn();
    if ($name === false) throw new StockException('Product not found.');
    if ($why = productDeleteBlockers($db, $productId)) throw new StockException("$name can’t be deleted because " . listAnd($why) . '. Hide it instead.');
    $db->prepare("DELETE FROM products WHERE id = ?")->execute([$productId]);
    logActivity(currentStaffId(), 'product_deleted', null, null, $name);
    return $name;
}

/** Deletes a treatment that was never booked (its recipe and interest links go with it). */
function deleteService(PDO $db, int $serviceId): string {
    $s = $db->prepare("SELECT name FROM services WHERE id = ?");
    $s->execute([$serviceId]);
    $name = $s->fetchColumn();
    if ($name === false) throw new StockException('Treatment not found.');
    if ($why = serviceDeleteBlockers($db, $serviceId)) throw new StockException("$name can’t be deleted because " . listAnd($why) . '. Hide it instead.');
    $db->prepare("DELETE FROM services WHERE id = ?")->execute([$serviceId]);
    logActivity(currentStaffId(), 'service_deleted', null, null, $name);
    return $name;
}

/**
 * Applies one action to many products or treatments. Each item succeeds or fails on its own.
 * Returns ['done' => [names], 'failed' => [messages]].
 */
function bulkCatalogAction(PDO $db, string $type, string $action, array $ids): array {
    $table = $type === 'product' ? 'products' : 'services';
    $flags = $type === 'product'
        ? ['hide' => ['is_active', 0], 'show' => ['is_active', 1], 'rx_on' => ['prescription_only', 1], 'rx_off' => ['prescription_only', 0]]
        : ['hide' => ['is_active', 0], 'show' => ['is_active', 1]];
    if ($action !== 'delete' && !isset($flags[$action])) throw new StockException('Unknown action.');
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) throw new StockException('Select at least one ' . ($type === 'product' ? 'product' : 'treatment') . '.');
    $res = ['done' => [], 'failed' => []];
    foreach ($ids as $id) {
        try {
            if ($action === 'delete') {
                $res['done'][] = $type === 'product' ? deleteProduct($db, $id) : deleteService($db, $id);
                continue;
            }
            [$col, $val] = $flags[$action];
            $s = $db->prepare("SELECT name FROM $table WHERE id = ?");
            $s->execute([$id]);
            if (($name = $s->fetchColumn()) === false) continue;
            $db->prepare("UPDATE $table SET $col = ? WHERE id = ?")->execute([$val, $id]);
            $res['done'][] = $name;
        } catch (StockException $e) {
            $res['failed'][] = $e->getMessage();
        } catch (PDOException $e) {
            $res['failed'][] = 'Item #' . $id . ' is still linked to other records, so it was left as it is. Hide it instead.';
        }
    }
    if ($action !== 'delete' && $res['done']) logActivity(currentStaffId(), $type . '_bulk_' . $action, null, null, count($res['done']) . ': ' . mb_substr(implode(', ', $res['done']), 0, 200));
    return $res;
}

// ─────────────────────────────────────────────
// APPOINTMENTS
// ─────────────────────────────────────────────
function getAppointment(PDO $db, int $id): ?array {
    $stmt = $db->prepare("
        SELECT a.*, s.name AS service_name, s.price AS service_price, COALESCE(a.duration_min, s.duration_min) AS duration_min,
               COALESCE(NULLIF(TRIM(CONCAT_WS(' ', pt.first_name, pt.last_name)), ''), l.name, 'Client') AS lead_name,
               COALESCE((SELECT phone FROM patient_phones x WHERE x.patient_id = a.patient_id ORDER BY is_primary DESC, id LIMIT 1), l.phone) AS lead_phone,
               l.stage AS lead_stage, l.assigned_to AS lead_owner, pt.mrn, br.name AS branch_name, rm.name AS room_name,
               (SELECT SUM(sv.price) FROM appointment_services x JOIN services sv ON sv.id = x.service_id WHERE x.appointment_id = a.id) AS visit_price,
               (SELECT MIN(iv.issued_at) FROM invoices iv WHERE iv.appointment_id = a.id AND iv.status <> 'void') AS invoiced_at,
               p.name AS practitioner_name, b.name AS booked_by_name, c.name AS completed_by_name
        FROM appointments a
        JOIN services s ON s.id = a.service_id
        LEFT JOIN leads l ON l.id = a.lead_id
        LEFT JOIN patients pt ON pt.id = a.patient_id
        LEFT JOIN branches br ON br.id = a.branch_id
        LEFT JOIN rooms rm ON rm.id = a.room_id
        LEFT JOIN staff p ON p.id = a.practitioner_id
        LEFT JOIN staff b ON b.id = a.booked_by
        LEFT JOIN staff c ON c.id = a.completed_by
        WHERE a.id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function appointmentItems(PDO $db, int $apptId): array {
    $stmt = $db->prepare("
        SELECT ai.*, p.name AS product_name, p.usage_unit, p.stock_unit, p.units_per_stock, p.track_lots, p.prescription_only, p.opened_use_hours, p.gtin,
               si.qty_min, si.qty_max
        FROM appointment_items ai
        JOIN products p ON p.id = ai.product_id
        -- the usual range adds up over every treatment of the visit that uses the product
        LEFT JOIN (SELECT x.appointment_id, i.product_id, SUM(i.qty_min) AS qty_min, SUM(i.qty_max) AS qty_max
                   FROM appointment_services x JOIN service_items i ON i.service_id = x.service_id GROUP BY x.appointment_id, i.product_id) si
               ON si.appointment_id = ai.appointment_id AND si.product_id = ai.product_id
        WHERE ai.appointment_id = ?
        ORDER BY p.name");
    $stmt->execute([$apptId]);
    return $stmt->fetchAll();
}

/** Shortfall warnings if the service's recipe were booked for $date (excluding $ignoreApptId's own reservation). */
function bookingWarnings(PDO $db, int $serviceId, string $date, ?int $ignoreApptId = null): array {
    $stmt = $db->prepare("SELECT si.product_id, si.qty, p.name, p.usage_unit, p.stock_unit, p.units_per_stock, p.qty_reserved
                          FROM service_items si JOIN products p ON p.id = si.product_id WHERE si.service_id = ?");
    $stmt->execute([$serviceId]);
    $warnings = [];
    foreach ($stmt->fetchAll() as $it) {
        $reserved = (float)$it['qty_reserved'];
        if ($ignoreApptId) {
            $own = $db->prepare("SELECT COALESCE(SUM(qty_reserved),0) FROM appointment_items WHERE appointment_id = ? AND product_id = ? AND qty_used IS NULL");
            $own->execute([$ignoreApptId, $it['product_id']]);
            $reserved -= (float)$own->fetchColumn();
        }
        $usable = usableOnHand($db, (int)$it['product_id'], substr($date, 0, 10));
        $after = $usable - $reserved - (float)$it['qty'];
        if ($after < 0) {
            $warnings[] = sprintf('%s: needs %s %s, only %s %s free on that date.',
                $it['name'], fmtQty($it['qty']), $it['usage_unit'], fmtQty(max(0, $usable - $reserved)), $it['usage_unit']);
        }
    }
    return $warnings;
}

/** Books one treatment for a lead (lead page). Same rules as the scheduler: see createAppointment(). Returns [id, warnings]. */
function bookAppointment(PDO $db, int $leadId, int $serviceId, string $when, ?int $practitionerId, string $notes): array {
    require_once __DIR__ . '/scheduler.php';
    return createAppointment($db, ['lead_id' => $leadId, 'service_ids' => [$serviceId], 'start' => $when, 'practitioner_id' => $practitionerId, 'notes' => $notes]);
}

/** Moves a booking (appointment page). See moveAppointment(). Returns stock warnings. */
function rescheduleAppointment(PDO $db, int $apptId, string $when, ?int $practitionerId): array {
    require_once __DIR__ . '/scheduler.php';
    return moveAppointment($db, $apptId, $when, $practitionerId);
}

/** Cancel or no-show: releases whatever is still reserved. */
function cancelAppointment(PDO $db, int $apptId, string $status, string $reason = ''): void {
    if (!in_array($status, ['cancelled', 'no_show'], true)) throw new StockException('Unknown status.');
    stockTx($db, function () use ($db, $apptId, $status, $reason) {
        $row = $db->prepare("SELECT * FROM appointments WHERE id = ? FOR UPDATE");
        $row->execute([$apptId]);
        $a = $row->fetch();
        // No-show only before they arrive; a visit can be cancelled until it is completed
        $allowed = $status === 'no_show' ? ['booked', 'confirmed'] : ['booked', 'confirmed', 'arrived', 'in_triage', 'with_doctor'];
        if (!$a || !in_array($a['status'], $allowed, true)) throw new StockException($status === 'no_show' ? 'Only visits where the patient hasn’t arrived can be marked no-show.' : 'Only open visits can be cancelled.');
        releaseReservations($db, $apptId);
        $db->prepare("UPDATE appointments SET status = ?, cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ? WHERE id = ?")
           ->execute([$status, currentStaffId() ?: null, trim($reason) ?: null, $apptId]);
        $db->prepare("INSERT INTO appointment_status_history (appointment_id, from_status, to_status, note, staff_id) VALUES (?,?,?,?,?)")
           ->execute([$apptId, $a['status'], $status, trim($reason) ?: null, currentStaffId() ?: null]);
        logActivity(currentStaffId(), $status === 'no_show' ? 'appointment_no_show' : 'appointment_cancelled', $a['lead_id'] ? (int)$a['lead_id'] : null, null, (string)$apptId);
    });
    gcalQueue($db, $apptId);
}

function releaseReservations(PDO $db, int $apptId): void {
    $items = $db->prepare("SELECT * FROM appointment_items WHERE appointment_id = ? AND qty_used IS NULL AND qty_reserved > 0 ORDER BY product_id");
    $items->execute([$apptId]);
    foreach ($items->fetchAll() as $it) {
        lockProduct($db, (int)$it['product_id']);
        addMovement($db, ['product_id' => $it['product_id'], 'type' => 'release', 'reserved_change' => -(float)$it['qty_reserved'], 'appointment_id' => $apptId]);
    }
}

/** Cancels all booked appointments of a lead (used when a lead is marked Lost or Cold). Returns how many. */
function cancelOpenAppointmentsForLead(PDO $db, int $leadId, string $reason): int {
    $stmt = $db->prepare("SELECT id FROM appointments WHERE lead_id = ? AND status IN ('booked','confirmed')");
    $stmt->execute([$leadId]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) cancelAppointment($db, (int)$id, 'cancelled', $reason);
    return count($ids);
}

function openAppointmentCount(PDO $db, int $leadId): int {
    $stmt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE lead_id = ? AND status IN ('booked','confirmed','arrived','in_triage','with_doctor')");
    $stmt->execute([$leadId]);
    return (int)$stmt->fetchColumn();
}

/** Batches that could supply a product, earliest expiry first. */
function usableLots(PDO $db, int $productId): array {
    $stmt = $db->prepare("SELECT * FROM stock_lots
        WHERE product_id = ? AND status = 'available' AND qty_on_hand > 0 AND (expiry_date IS NULL OR expiry_date >= CURDATE())
        ORDER BY expiry_date IS NULL, expiry_date, id");
    $stmt->execute([$productId]);
    return $stmt->fetchAll();
}

/**
 * Completes a treatment. $used = [appointment_item_id => ['qty' => float, 'lot_id' => ?int, 'reason' => string]].
 * Releases the reservation, deducts the used amounts from batches (chosen batch first, then earliest expiry),
 * and moves the lead to Converted.
 */
function completeAppointment(PDO $db, int $apptId, array $used, string $notes = ''): void {
    stockTx($db, function () use ($db, $apptId, $used, $notes) {
        $row = $db->prepare("SELECT * FROM appointments WHERE id = ? FOR UPDATE");
        $row->execute([$apptId]);
        $a = $row->fetch();
        if (!$a || !in_array($a['status'], ['booked', 'confirmed', 'arrived', 'in_triage', 'with_doctor'], true)) throw new StockException('This treatment is not open for completion.');

        foreach (appointmentItems($db, $apptId) as $it) {
            $pid = (int)$it['product_id'];
            $u = $used[$it['id']] ?? null;
            if ($u === null || $u['qty'] === '' || !is_numeric($u['qty'])) throw new StockException("Enter the amount of {$it['product_name']} used (0 if none).");
            $qty = round((float)$u['qty'], 3);
            if ($qty < 0) throw new StockException("The amount of {$it['product_name']} can’t be negative.");
            $outOfRange = ($it['qty_min'] !== null && $qty < (float)$it['qty_min']) || ($it['qty_max'] !== null && $qty > (float)$it['qty_max']);
            $reason = trim($u['reason'] ?? '');
            if ($outOfRange && $reason === '') {
                throw new StockException("{$it['product_name']}: {$qty} {$it['usage_unit']} is outside the usual range (" .
                    fmtQty($it['qty_min']) . '–' . fmtQty($it['qty_max']) . "). Add a reason.");
            }
            lockProduct($db, $pid);
            if ((float)$it['qty_reserved'] > 0) {
                addMovement($db, ['product_id' => $pid, 'type' => 'release', 'reserved_change' => -(float)$it['qty_reserved'], 'appointment_id' => $apptId, 'note' => 'Treatment completed']);
            }
            if ($qty > 0) consumeFromLots($db, $pid, $qty, $apptId, !empty($u['lot_id']) ? (int)$u['lot_id'] : null, $it['product_name'], $it['usage_unit']);
            $db->prepare("UPDATE appointment_items SET qty_used = ?, variance_reason = ? WHERE id = ?")
               ->execute([$qty, $reason ?: null, $it['id']]);
        }

        $db->prepare("UPDATE appointments SET status = 'completed', completed_by = ?, completed_at = NOW(), doctor_out_at = IF(doctor_in_at IS NULL, doctor_out_at, NOW()),
                      notes = CONCAT_WS('\n', notes, ?) WHERE id = ?")
           ->execute([currentStaffId() ?: null, trim($notes) ?: null, $apptId]);
        $db->prepare("INSERT INTO appointment_status_history (appointment_id, from_status, to_status, staff_id) VALUES (?,?,'completed',?)")
           ->execute([$apptId, $a['status'], currentStaffId() ?: null]);
        if (!empty($a['patient_package_id'])) usePackageSessions($db, $a);
        if (!$a['lead_id']) return;   // a patient without a lead: nothing to convert

        // First completed treatment converts the lead
        $lead = $db->prepare("SELECT stage FROM leads WHERE id = ? FOR UPDATE");
        $lead->execute([$a['lead_id']]);
        $stage = (string)$lead->fetchColumn();
        $db->prepare("UPDATE leads SET last_contacted = NOW(), aging_status = 'fresh', updated_at = NOW()" . ($stage !== 'converted' ? ", stage = 'converted'" : '') . " WHERE id = ?")
           ->execute([$a['lead_id']]);
        if ($stage !== 'converted') {
            setConvertedAt((int)$a['lead_id'], 'converted', $stage, $db);
            logActivity(currentStaffId(), 'stage_changed', (int)$a['lead_id'], $stage, 'converted');
        }
        logActivity(currentStaffId(), 'appointment_completed', (int)$a['lead_id'], null, (string)$apptId);
    });
    try { if ($lid = (int)getAppointment($db, $apptId)['lead_id']) updateLeadScore($lid, $db); } catch (Throwable $e) {}
}

/** Completing a visit booked on a package uses one session of each booked service the package still covers. */
function usePackageSessions(PDO $db, array $a): void {
    $s = $db->prepare("SELECT ss.service_id FROM patient_package_sessions ss JOIN patient_packages pp ON pp.id = ss.patient_package_id
        WHERE pp.id = ? AND pp.patient_id = ? AND pp.status = 'active' AND pp.expires_on >= CURDATE() AND ss.used < ss.total
          AND ss.service_id IN (SELECT service_id FROM appointment_services WHERE appointment_id = ? UNION SELECT ?) FOR UPDATE");
    $s->execute([$a['patient_package_id'], $a['patient_id'], $a['id'], $a['service_id']]);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $sid) {
        $db->prepare("UPDATE patient_package_sessions SET used = used + 1 WHERE patient_package_id = ? AND service_id = ?")->execute([$a['patient_package_id'], $sid]);
        $db->prepare("INSERT IGNORE INTO package_usages (patient_package_id, service_id, appointment_id, staff_id) VALUES (?,?,?,?)")->execute([$a['patient_package_id'], $sid, $a['id'], currentStaffId() ?: null]);
    }
    $db->prepare("UPDATE patient_packages SET status = 'completed' WHERE id = ? AND NOT EXISTS (SELECT 1 FROM patient_package_sessions WHERE patient_package_id = ? AND used < total)")
       ->execute([$a['patient_package_id'], $a['patient_package_id']]);
}

function consumeFromLots(PDO $db, int $pid, float $qty, int $apptId, ?int $preferLotId, string $name, string $unit): void {
    $p = lockProduct($db, $pid);
    if (isMultiDose($p)) expireOpenContainers($db, $pid);
    $lots = usableLots($db, $pid);
    if ($preferLotId) {
        usort($lots, fn($x, $y) => ((int)$y['id'] === $preferLotId) <=> ((int)$x['id'] === $preferLotId));
        if (!$lots || (int)$lots[0]['id'] !== $preferLotId) throw new StockException("{$name}: the chosen batch isn’t usable (empty, expired or on hold).");
    }
    $have = array_sum(array_map(fn($l) => (float)$l['qty_on_hand'], $lots));
    if ($have + 0.0005 < $qty) {
        throw new StockException("{$name}: records show only " . fmtQty($have) . " {$unit} in usable batches, but {$qty} {$unit} were entered. Receive the stock or ask an admin to correct it first.");
    }
    if (isMultiDose($p)) {
        if (consumeMultiDose($db, $p, $lots, $qty, $apptId, $preferLotId) > 0.0005) throw new StockException("{$name}: not enough in usable batches.");
        return;
    }
    $left = $qty;
    foreach ($lots as $l) {
        if ($left <= 0.0005) break;
        $take = min($left, (float)$l['qty_on_hand']);
        addMovement($db, ['product_id' => $pid, 'lot_id' => $l['id'], 'type' => 'consume', 'qty_change' => -$take, 'appointment_id' => $apptId]);
        $left = round($left - $take, 3);
    }
}

/** Batches used in a completed appointment (for the record and recalls). */
function appointmentLotsUsed(PDO $db, int $apptId): array {
    $stmt = $db->prepare("SELECT m.product_id, -SUM(m.qty_change) AS qty, l.lot_number, l.expiry_date
        FROM stock_movements m JOIN stock_lots l ON l.id = m.lot_id
        WHERE m.appointment_id = ? AND m.type = 'consume'
        GROUP BY m.product_id, m.lot_id ORDER BY l.expiry_date");
    $stmt->execute([$apptId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) $out[(int)$r['product_id']][] = $r;
    return $out;
}

/** Same rule as the lead pages (own or unassigned leads), plus practitioners, who treat whoever is booked. */
function canAccessLead(array $lead): bool {
    return can('leads.all') || currentRole() === 'practitioner'
        || empty($lead['assigned_to'] ?? $lead['lead_owner'] ?? null)
        || (int)($lead['assigned_to'] ?? $lead['lead_owner']) === currentStaffId();
}

/** Staff who can be picked as the treating practitioner. */
function practitionerOptions(PDO $db): array {
    return $db->query("SELECT id, name, role FROM staff WHERE is_active = 1 AND role IN ('practitioner','nurse','admin') ORDER BY role = 'admin', name")->fetchAll();
}
