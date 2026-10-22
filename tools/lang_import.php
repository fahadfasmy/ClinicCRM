<?php
// tools/lang_import.php — loads the reviewers' CSV (English, Arabic, …) into lang/ar.php. Rows whose Arabic is empty are
// left out (that text stays English). Refuses rows whose {placeholders} differ from the English, so a value can never go
// missing from a sentence. Prints what changed.
//   php tools/lang_import.php arabic-review.csv
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Reads the CSV into [english => arabic]; throws on a placeholder mismatch. */
function langReadCsv(string $file): array {
    $h = fopen($file, 'r');
    if (!$h) throw new RuntimeException("Can't read $file");
    $map = []; $line = 0; $errors = [];
    while (($r = fgetcsv($h)) !== false) {
        $line++;
        if ($line === 1) { $r[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$r[0]); if (strcasecmp(trim($r[0]), 'English') === 0) continue; }
        $en = (string)($r[0] ?? ''); $ar = trim((string)($r[1] ?? ''));
        if ($en === '' || $ar === '') continue;
        preg_match_all('/\{\w+\}/', $en, $a); preg_match_all('/\{\w+\}/', $ar, $b);
        sort($a[0]); sort($b[0]);
        if ($a[0] !== $b[0]) { $errors[] = "line $line: the Arabic must keep " . (implode(' ', $a[0]) ?: 'no {placeholders}') . " — “{$en}”"; continue; }
        $map[$en] = $ar;
    }
    fclose($h);
    if ($errors) throw new RuntimeException("Not loaded, fix these first:\n  " . implode("\n  ", $errors));
    return $map;
}

function langWrite(string $file, array $map): void {
    ksort($map, SORT_NATURAL | SORT_FLAG_CASE);
    $php = "<?php\n// lang/ar.php — Arabic for the staff screens (English text => Arabic). Made by tools/lang_import.php from the reviewed CSV;\n// edit the CSV (tools/lang_export.php) rather than this file. Missing entries show in English.\nreturn [\n";
    foreach ($map as $en => $ar) $php .= '    ' . var_export((string)$en, true) . ' => ' . var_export($ar, true) . ",\n";
    file_put_contents($file, $php . "];\n");
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $csv = $argv[1] ?? '';
    if (!is_file($csv)) { fwrite(STDERR, "Usage: php tools/lang_import.php arabic-review.csv\n"); exit(2); }
    $target = realpath(__DIR__ . '/..') . '/lang/ar.php';
    try {
        $new = langReadCsv($csv);
        $old = is_file($target) ? require $target : [];
        $added = count(array_diff_key($new, $old));
        $reworded = count(array_diff_assoc(array_intersect_key($new, $old), $old));
        $removed = count(array_diff_key($old, $new));
        langWrite($target, $new);
        echo "lang/ar.php: " . count($new) . " texts in Arabic ($added new, $reworded reworded, $removed no longer in the list).\n";
    } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
}
