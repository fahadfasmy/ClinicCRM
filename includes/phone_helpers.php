<?php
// includes/phone_helpers.php
// Multi-phone support — compatible with PHP 7.2+

function getLeadPhones($leadId, $db) {
    try {
        $stmt = $db->prepare("SELECT id, phone FROM lead_phones WHERE lead_id = ? ORDER BY id ASC");
        $stmt->execute([$leadId]);
        $rows = $stmt->fetchAll();
        if (!empty($rows)) return $rows;
        // Fallback to leads.phone
        $s2 = $db->prepare("SELECT phone FROM leads WHERE id = ?");
        $s2->execute([$leadId]);
        $row = $s2->fetch();
        return $row ? [['id' => 0, 'phone' => $row['phone']]] : [];
    } catch (PDOException $e) {
        try {
            $s2 = $db->prepare("SELECT phone FROM leads WHERE id = ?");
            $s2->execute([$leadId]);
            $row = $s2->fetch();
            return $row ? [['id' => 0, 'phone' => $row['phone']]] : [];
        } catch (PDOException $e2) {
            return [];
        }
    }
}

function saveLeadPhones($leadId, $phones, $db) {
    $phones = array_values(array_filter(array_map('trim', $phones)));
    if (empty($phones)) return;
    try {
        $db->prepare("DELETE FROM lead_phones WHERE lead_id = ?")->execute([$leadId]);
        $ins = $db->prepare("INSERT INTO lead_phones (lead_id, phone) VALUES (?, ?)");
        foreach ($phones as $phone) {
            if ($phone !== '') $ins->execute([$leadId, $phone]);
        }
        $db->prepare("UPDATE leads SET phone = ? WHERE id = ?")->execute([$phones[0], $leadId]);
    } catch (PDOException $e) {
        if (!empty($phones[0])) {
            try { $db->prepare("UPDATE leads SET phone = ? WHERE id = ?")->execute([$phones[0], $leadId]); }
            catch (PDOException $e2) {}
        }
    }
}

function findDuplicateByAnyPhone($phones, $db, $excludeId = null) {
    foreach ($phones as $phone) {
        $phone = trim($phone);
        if (!$phone) continue;
        try {
            $sql = "SELECT l.id, l.name, l.stage FROM lead_phones lp JOIN leads l ON l.id = lp.lead_id WHERE lp.phone = ?";
            $params = [$phone];
            if ($excludeId) { $sql .= " AND lp.lead_id != ?"; $params[] = $excludeId; }
            $sql .= " LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $dup = $stmt->fetch();
            if ($dup) return $dup;
        } catch (PDOException $e) {}
        $dup = findDuplicateLead($phone, $db, $excludeId);
        if ($dup) return $dup;
    }
    return null;
}

function renderPhones($phones, $style = 'card') {
    if (empty($phones)) return '<span style="color:var(--tx-3)">—</span>';
    $out = '';
    foreach ($phones as $p) {
        $num = htmlspecialchars(trim($p['phone']), ENT_QUOTES, 'UTF-8');
        if ($style === 'card') {
            $out .= '<a href="tel:' . $num . '" class="lead-card-phone" onclick="event.stopPropagation()">'
                  . '<i class="bi bi-telephone-fill"></i>' . $num . '</a>';
        } else {
            $out .= '<a href="tel:' . $num . '" style="display:flex;align-items:center;gap:5px;color:var(--gold-dark);font-size:14px;text-decoration:none;margin-bottom:3px;">'
                  . '<i class="bi bi-telephone-fill"></i>' . $num . '</a>';
        }
    }
    return $out;
}