<?php
// includes/legal.php — the platform's legal documents and their versions. A lawyer drafts the text; this only shows it
// and records who accepted which version (legal_acceptances). Put the final text in legal/<doc>.html and bump the version.
const LEGAL_DOCS = [
    'terms' => ['Terms of Service', '2026-10-draft'],
    'privacy' => ['Privacy Policy', '2026-10-draft'],
    'dpa' => ['Data Processing Agreement', '2026-10-draft'],
];

/** The document's text (legal/<doc>.html when the lawyer's version is in place), or a plainly marked placeholder. */
function legalText(string $doc): string {
    $f = __DIR__ . "/../legal/$doc.html";
    if (is_file($f)) return (string)file_get_contents($f);
    $title = LEGAL_DOCS[$doc][0];
    $more = $doc === 'dpa' ? '<p>The clinic is the data controller for its patients’ data; the platform is the processor and keeps that data in the United Arab Emirates.</p>' : '';
    return "<p><strong>Draft.</strong> The final $title is being prepared by our lawyer and will replace this page. Its version is shown below; you will be asked to accept any new version.</p>$more";
}

function recordLegalAcceptance(PDO $p, ?int $tenantId, ?int $signupId, string $email, ?string $ip): void {
    $ins = $p->prepare("INSERT INTO legal_acceptances (tenant_id, signup_id, doc, version, accepted_by_email, ip) VALUES (?,?,?,?,?,?)");
    foreach (LEGAL_DOCS as $doc => [, $version]) $ins->execute([$tenantId, $signupId, $doc, $version, $email, $ip]);
}

/** Documents whose current version this clinic has not accepted yet. */
function legalOutstanding(PDO $p, int $tenantId): array {
    $s = $p->prepare("SELECT doc, version FROM legal_acceptances WHERE tenant_id = ?");
    $s->execute([$tenantId]);
    $have = [];
    foreach ($s as $r) $have[$r['doc']][$r['version']] = true;
    return array_keys(array_filter(LEGAL_DOCS, fn($d, $k) => empty($have[$k][$d[1]]), ARRAY_FILTER_USE_BOTH));
}

/** A public legal page (terms.php, privacy.php, dpa.php). */
function legalPage(string $doc): void {
    [$title, $version] = LEGAL_DOCS[$doc];
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . htmlspecialchars($title) . '</title>'
       . '<style>body{font:16px/1.6 system-ui,Segoe UI,Arial,sans-serif;max-width:760px;margin:40px auto;padding:0 20px;color:#1d1b18}h1{font-size:28px}.v{color:#666;font-size:14px}a{color:#8a6a3a}</style></head><body>'
       . '<h1>' . htmlspecialchars($title) . '</h1><p class="v">Version ' . htmlspecialchars($version) . '</p>' . legalText($doc)
       . '<p class="v"><a href="/terms.php">Terms</a> · <a href="/privacy.php">Privacy</a> · <a href="/dpa.php">Data Processing Agreement</a></p></body></html>';
}
