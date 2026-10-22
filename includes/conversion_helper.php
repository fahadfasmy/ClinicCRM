<?php
// includes/conversion_helper.php
// Call this whenever a lead's stage is updated

function setConvertedAt(int $leadId, string $newStage, string $oldStage, PDO $db): void {
    // Only act if stage is changing TO converted
    if ($newStage !== 'converted') return;
    if ($oldStage === 'converted') return; // already converted, don't overwrite

    // Check if converted_at column exists
    try {
        $db->query("SELECT converted_at FROM leads LIMIT 1");
    } catch (PDOException $e) {
        return; // Column doesn't exist yet — skip silently
    }

    $db->prepare("UPDATE leads SET converted_at = NOW() WHERE id = ? AND (converted_at IS NULL OR converted_at = '0000-00-00 00:00:00')")
       ->execute([$leadId]);
}
