<?php
$publicPage = true;
require_once __DIR__ . '/includes/init.php';
header('Location: ' . (is_logged_in() ? 'dashboard.php' : 'login.php'));
exit;
