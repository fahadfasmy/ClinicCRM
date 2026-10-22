<?php
// includes/doc_required.php — what each document must show, by law or by the clinic's own rules. A layout can move,
// resize, relabel and restyle these elements but not delete or hide them: publishing is refused with a plain list of
// what is missing. Tax invoice and tax credit note follow the UAE VAT rules (Federal Decree-Law 8/2017, Executive
// Regulation art. 59 and 60). The clinic's accountant must confirm this list before launch.

const DOC_REQUIRED = [
    'invoice' => [
        ['block' => 'title', 'title' => ['en' => 'Tax Invoice', 'ar' => 'فاتورة ضريبية'], 'what' => 'the document title (“Tax Invoice”)'],
        ['block' => 'clinic', 'fields' => ['name', 'address', 'trn'], 'what' => 'the clinic’s name, address and TRN'],
        ['block' => 'patient', 'fields' => ['name'], 'what' => 'the patient’s name'],
        ['block' => 'details', 'fields' => ['number', 'issue_date'], 'what' => 'the invoice number and issue date'],
        ['block' => 'lines', 'columns' => ['description', 'qty', 'unit_price', 'taxable', 'vat_rate', 'vat_amount'], 'what' => 'the items table with description, quantity, unit price, taxable amount, VAT rate and VAT amount'],
        // The discount must show when one is given: the Totals discount line prints only then, so the column may be hidden
        ['block' => 'totals', 'fields' => ['discount', 'vat', 'total'], 'what' => 'the totals with discount (when given), total VAT and the total payable'],
    ],
    'credit_note' => [
        ['block' => 'title', 'title' => ['en' => 'Tax Credit Note', 'ar' => 'إشعار دائن ضريبي'], 'what' => 'the document title (“Tax Credit Note”)'],
        ['block' => 'clinic', 'fields' => ['name', 'address', 'trn'], 'what' => 'the clinic’s name, address and TRN'],
        ['block' => 'patient', 'fields' => ['name'], 'what' => 'the patient’s name'],
        ['block' => 'details', 'fields' => ['number', 'issue_date', 'invoice_ref'], 'what' => 'the credit note number, its date and the original invoice'],
        ['block' => 'lines', 'columns' => ['description', 'qty', 'taxable', 'vat_rate', 'vat_amount'], 'what' => 'the items table with description, quantity, taxable amount, VAT rate and VAT amount'],
        ['block' => 'totals', 'fields' => ['vat', 'total'], 'what' => 'the VAT and total credited'],
    ],
    'receipt' => [
        ['block' => 'title', 'title' => ['en' => 'Receipt', 'ar' => 'إيصال'], 'what' => 'the document title (“Receipt”)'],
        ['block' => 'clinic', 'fields' => ['name'], 'what' => 'the clinic’s name'],
        ['block' => 'details', 'fields' => ['number', 'issue_date'], 'what' => 'the receipt number and date'],
        ['block' => 'payments', 'fields' => [], 'what' => 'how it was paid'],
        ['block' => 'totals', 'fields' => ['total'], 'what' => 'the amount received'],
    ],
];

/** Problems that stop a layout being published, in plain English (empty = fine). */
function layoutRequiredProblems(array $layout, string $docType): array {
    $blocks = [];
    foreach (['header', 'body', 'footer'] as $area) foreach ($layout[$area] ?? [] as $row) foreach ($row['blocks'] ?? [] as $b) $blocks[$b['type']][] = $b;
    $lang = $layout['page']['language'] ?? 'both';
    $out = [];
    foreach (DOC_REQUIRED[$docType] ?? [] as $r) {
        $found = $blocks[$r['block']] ?? [];
        $ok = false; $why = 'is missing';
        foreach ($found as $b) {
            if (($b['cond'] ?? '') !== '') { $why = 'has a “show only if” condition that could hide it'; continue; }
            if (isset($r['fields']) && array_filter($r['fields'], fn($f) => empty($b['show'][$f]))) { $why = 'is hiding a required part'; continue; }
            if (isset($r['columns'])) {
                $on = array_column(array_filter($b['columns'] ?? [], fn($c) => !empty($c['on'])), 'key');
                if (array_diff($r['columns'], $on)) { $why = 'is missing a required column'; continue; }
            }
            if (isset($r['title'])) {
                $need = [];
                if ($lang !== 'ar') $need[] = ['en', $r['title']['en']];
                if ($lang !== 'en') $need[] = ['ar', $r['title']['ar']];
                $bad = array_filter($need, fn($n) => mb_stripos((string)($b['text'][$n[0]] ?? ''), $n[1]) === false);
                if ($bad) { $why = 'must say “' . implode('” / “', array_column($bad, 1)) . '”'; continue; }
            }
            $ok = true; break;
        }
        // A wrongly worded title reads better without the expected words twice
        if (!$ok) $out[] = ucfirst(str_starts_with($why, 'must say') ? 'the document title' : $r['what']) . ' ' . $why . '.';
    }
    return $out;
}

/** The block types and parts that may not be deleted or hidden (sent to the designer to lock them). */
function layoutLocked(string $docType): array {
    $out = [];
    foreach (DOC_REQUIRED[$docType] ?? [] as $r) $out[$r['block']] = ['fields' => $r['fields'] ?? [], 'columns' => $r['columns'] ?? [], 'title' => $r['title'] ?? null];
    return $out;
}
