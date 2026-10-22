<?php
// pages/download.php — the only way to open a patient's stored file:
//   ?doc=     a document (patients.view)
//   ?photo=   a clinical photo (emr.photos)
//   ?consent= a consent signature, &part=witness for the witness's (patients.view)
// Checks the permission and that the file belongs to this clinic's database and folder, logs the access, and sends the
// file with no caching. Files live outside the web root.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/patients.php';
requireLogin();

$db = getDB();
$notFound = function () { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Not found'); };
if (isset($_GET['photo'])) {
    requirePermission('emr.photos');
    $s = $db->prepare("SELECT patient_id, path, mime, CONCAT('photo-', id, '.', IF(mime = 'image/png', 'png', 'jpg')) AS name, CONCAT('photo:', id) AS what FROM patient_photos WHERE id = ? AND deleted_at IS NULL");
    $s->execute([(int)$_GET['photo']]);
} elseif (isset($_GET['consent'])) {
    requirePermission('patients.view');
    $col = ($_GET['part'] ?? '') === 'witness' ? 'witness_signature_path' : 'signature_path';
    $s = $db->prepare("SELECT patient_id, $col AS path, 'image/png' AS mime, CONCAT('signature-', id, '.png') AS name, CONCAT('consent_signature:', id) AS what FROM patient_consents WHERE id = ?");
    $s->execute([(int)$_GET['consent']]);
} else {
    requirePermission('patients.view');
    $s = $db->prepare("SELECT patient_id, path, mime, original_name AS name, CONCAT('document:', id) AS what FROM patient_documents WHERE id = ?");
    $s->execute([(int)($_GET['doc'] ?? 0)]);
}
$f = $s->fetch();
$file = $f ? patientFilePath($f['path']) : null;
if (!$file) $notFound();
logPatientAccess($db, (int)$f['patient_id'], $f['what']);
$name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $f['name'] ?: ('document.' . (PATIENT_DOC_MIMES[$f['mime']] ?? 'bin')));
header('Content-Type: ' . $f['mime']);
header('Content-Length: ' . filesize($file));
header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
header('Cache-Control: private, no-store');
readfile($file);
