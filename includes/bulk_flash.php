<?php
// includes/bulk_flash.php — result of a bulk action (set in $_SESSION['bulk_flash'] before redirecting).
$_bf = $_SESSION['bulk_flash'] ?? null;
unset($_SESSION['bulk_flash']);
if ($_bf):
    $_verb = ['hide' => 'Hidden', 'show' => 'Shown again', 'rx_on' => 'Marked prescription only', 'rx_off' => 'Marked not prescription only', 'delete' => 'Deleted'][$_bf['action']] ?? 'Updated';
    $_n = count($_bf['done']);
?>
<?php if ($_n): ?>
<div class="alert alert-success alert-auto"><i class="bi bi-check-circle"></i><div><strong><?= $_verb ?>: <?= $_n ?></strong>
  <?= $_n <= 5 ? '(' . sanitize(implode(', ', $_bf['done'])) . ')' : '' ?></div></div>
<?php endif; ?>
<?php if ($_bf['failed']): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i><div><strong><?= count($_bf['failed']) ?> left unchanged:</strong>
  <ul class="mb-0 mt-1"><?php foreach ($_bf['failed'] as $_m): ?><li><?= sanitize($_m) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>
<?php endif; unset($_bf, $_verb, $_n, $_m); ?>
