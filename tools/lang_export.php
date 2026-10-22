<?php
// tools/lang_export.php — the word list for Arabic reviewers: every text the staff screens pass through t(), with the
// current Arabic and where it appears, as a CSV that opens in Excel (UTF-8). Fix the Arabic column, save as CSV (UTF-8),
// and load it back with tools/lang_import.php. Empty Arabic = still in English on screen.
//   php tools/lang_export.php > arabic-review.csv           (--missing for only the untranslated ones)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Every t('…') text in the code, plus menu labels, page titles, back links and role names that reach t() as values. */
function langKeys(string $root): array {
    $keys = [];
    $add = function (string $s, string $where) use (&$keys) { $keys[$s][$where] = true; };
    $files = array_merge(glob("$root/*.php"), glob("$root/includes/*.php"), glob("$root/includes/*/*.php"), glob("$root/pages/*.php"));
    foreach ($files as $f) {
        $where = str_replace(str_replace('\\', '/', $root) . '/', '', str_replace('\\', '/', $f));
        $src = file_get_contents($f);
        $toks = array_values(array_filter(token_get_all($src), fn($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        foreach ($toks as $i => $t) {
            if (is_array($t) && $t[0] === T_STRING && $t[1] === 't' && ($toks[$i + 1] ?? null) === '(' && is_array($toks[$i + 2] ?? null) && $toks[$i + 2][0] === T_CONSTANT_ENCAPSED_STRING
                && !(is_array($toks[$i - 1] ?? null) && in_array($toks[$i - 1][0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)))
                $add(langLiteral($toks[$i + 2][1]), $where);
        }
        if (preg_match_all('/\$pageTitle\s*=\s*(\'(?:[^\'\\\\]|\\\\.)*\')\s*;/', $src, $m)) foreach ($m[1] as $l) $add(langLiteral($l), $where);
        if (preg_match_all('/\$pageBack\s*=\s*\[[^\]]*\'label\'\s*=>\s*(\'(?:[^\'\\\\]|\\\\.)*\')/', $src, $m)) foreach ($m[1] as $l) $add(langLiteral($l), $where);
    }
    $h = file_get_contents("$root/includes/header.php");
    preg_match_all('/^\s*\'([A-Z][^\']+)\' => \[$/m', $h, $g);
    preg_match_all('/^\s*\[\'[a-z_]+\',\s*\'([^\']+)\'/m', $h, $n);
    foreach (array_merge($g[1], $n[1]) as $s) $add($s, 'includes/header.php (menu)');
    require_once "$root/includes/permissions.php";
    foreach (ROLES as $r) $add($r[0], 'includes/permissions.php (roles)');
    ksort($keys, SORT_NATURAL | SORT_FLAG_CASE);
    return array_map(fn($w) => array_keys($w), $keys);
}

function langLiteral(string $lit): string {
    return $lit[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], substr($lit, 1, -1)) : stripcslashes(substr($lit, 1, -1));
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $root = realpath(__DIR__ . '/..');
    $ar = is_file("$root/lang/ar.php") ? require "$root/lang/ar.php" : [];
    $missingOnly = in_array('--missing', $argv, true);
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['English', 'Arabic', 'Where it appears']);
    foreach (langKeys($root) as $en => $where) {
        if ($missingOnly && ($ar[$en] ?? '') !== '') continue;
        fputcsv($out, [$en, $ar[$en] ?? '', implode(', ', array_slice($where, 0, 4)) . (count($where) > 4 ? ' …' : '')]);
    }
}
