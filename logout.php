<?php
require_once __DIR__ . '/includes/auth.php';
logoutStaff();
header('Location: /login.php' . (isset($_GET['idle']) ? '?idle=1' : ''));
exit;
