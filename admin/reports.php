<?php
require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();
header('Location: orders.php');
exit;
