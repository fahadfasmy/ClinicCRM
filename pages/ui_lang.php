<?php
// pages/ui_lang.php — switches the signed-in person's screens between English and Arabic (POST from the top bar),
// remembers it on their staff record, and returns to the page they were on.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';   // requireCsrf
requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /pages/dashboard.php'); exit; }
requireCsrf();
$lang = ($_POST['lang'] ?? '') === 'ar' ? 'ar' : 'en';
try { getDB()->prepare("UPDATE staff SET ui_language = ? WHERE id = ?")->execute([$lang, currentStaffId()]); }
catch (PDOException $e) { if ($e->getCode() !== '42S22') throw $e; }   // column not added yet: this session only
$_SESSION['ui_lang'] = $lang;
uiRememberLang($lang);
$back = (string)($_POST['back'] ?? '');
header('Location: ' . (preg_match('#^/(pages/)?[a-z0-9_]+\.php(\?[^\s<>"]*)?$#i', $back) ? $back : '/pages/dashboard.php'));
exit;
