<?php
// includes/staff_history.php — who changed which staff member's account or access, read from activity_log.
// Log rows keep the affected person's id in old_value (older rows only have their name in new_value).

const STAFF_HISTORY_ACTIONS = [
    'staff_added'          => ['bi-person-plus', 'added'],
    'staff_deactivated'    => ['bi-person-dash', 'deactivated'],
    'staff_activated'      => ['bi-person-check', 'reactivated'],
    'staff_role_changed'   => ['bi-person-gear', 'changed the role of'],
    'staff_access_changed' => ['bi-shield-lock', 'changed access for'],
    'staff_access_reset'   => ['bi-arrow-counterclockwise', 'reset access for'],
];

/** Latest staff changes, for everyone or for one person. */
function staffHistory(PDO $db, int $limit, ?array $member = null): array {
    $in = implode(',', array_fill(0, count(STAFF_HISTORY_ACTIONS), '?'));
    $sql = "SELECT a.action, a.old_value, a.new_value, a.created_at, s.name AS actor
            FROM activity_log a LEFT JOIN staff s ON s.id = a.staff_id
            WHERE a.action IN ($in)";
    $params = array_keys(STAFF_HISTORY_ACTIONS);
    if ($member) {
        $sql .= " AND (a.old_value = ? OR ((a.old_value IS NULL OR a.old_value NOT REGEXP '^[0-9]+$') AND a.new_value LIKE ?))";
        $params[] = (string)$member['id'];
        $params[] = addcslashes($member['name'], '%_\\') . '%';
    }
    $s = $db->prepare($sql . " ORDER BY a.id DESC LIMIT " . max(1, $limit));
    $s->execute($params);
    return $s->fetchAll();
}

/** One history row as list HTML: actor, what they did, and the +/− access changes as chips. */
function staffHistoryRow(array $h): string {
    [$icon, $verb] = STAFF_HISTORY_ACTIONS[$h['action']] ?? ['bi-dot', $h['action']];
    $text = (string)$h['new_value'];
    $detail = '';
    if ($h['action'] === 'staff_access_changed' && ($p = strpos($text, ': ')) !== false) {
        // "Name: +Feature, −Feature and 3 more" → name, then one chip per change
        $list = substr($text, $p + 2);
        $more = preg_match('/ and (\d+) more$/', $list, $m) ? (int)$m[1] : 0;
        if ($more) $list = substr($list, 0, -strlen($m[0]));
        foreach (preg_split('/, (?=[+−])/u', $list) as $c) {
            $add = str_starts_with($c, '+');
            $detail .= '<span class="chip ' . ($add ? 'aging-fresh' : 'aging-critical') . ' me-1 mb-1">' . ($add ? '+ ' : '− ') . sanitize(mb_substr($c, 1)) . '</span>';
        }
        if ($more) $detail .= '<span class="fs-12 text-tx-3 align-self-center">and ' . $more . ' more</span>';
        $text = substr($text, 0, $p);
    }
    $when = strtotime($h['created_at']);
    return '<div class="list-row align-items-start"><i class="bi ' . $icon . ' text-tx-3 mt-1"></i><div class="list-row-main">'
         . '<div style="white-space:normal"><strong>' . sanitize($h['actor'] ?: 'Someone') . '</strong> ' . $verb . ' ' . sanitize($text) . '</div>'
         . ($detail ? '<div class="mt-1 d-flex flex-wrap">' . $detail . '</div>' : '')
         . '</div><span class="list-row-end fs-12 text-tx-3 text-nowrap" title="' . date('d M Y, h:i A', $when) . '">'
         . (time() - $when < 86400 * 7 ? timeSince($h['created_at']) : date('d M Y', $when)) . '</span></div>';
}
