<?php
require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<link rel="icon" href="../favicon.svg?v=20260429f" type="image/svg+xml">
<link rel="shortcut icon" href="../favicon.png?v=20260429f" type="image/png">
<title>Settings - Admin</title>
<link rel="stylesheet" href="css/admin.css">
</head>
<body>
<div class="admin-container">
<nav class="admin-sidebar" id="admin-sidebar">
<div class="admin-logo"><h2>Admin Panel</h2></div>
<ul class="menu">
<li><a href="index.php">Dashboard</a></li>
<li><a href="products.php">Products</a></li>
<li><a href="ai-prompts.php">AI Prompts</a></li>
<li><a href="user.php">Users</a></li>
<li><a href="orders.php">Orders</a></li>
<li><a href="support-messages.php">Messages</a></li>
<li><a href="settings.php" class="active">Settings</a></li>
<li><a href="logout.php">Logout</a></li>
</ul>
</nav>
<div class="admin-main">
<div class="admin-header"><h1>Settings</h1></div>
<div class="admin-content">
<div class="card" style="padding:30px;max-width:600px;">
<h2 style="margin-bottom:20px;">Site Settings</h2>
<table style="width:100%;border-collapse:collapse;">
<tr><th style="text-align:left;padding:10px;border-bottom:1px solid #e2e8f0;">Setting</th><th style="text-align:left;padding:10px;border-bottom:1px solid #e2e8f0;">Value</th></tr>
<tr><td style="padding:10px;border-bottom:1px solid #e2e8f0;">Site Name</td><td style="padding:10px;border-bottom:1px solid #e2e8f0;">Accounts Bazar</td></tr>
<tr><td style="padding:10px;border-bottom:1px solid #e2e8f0;">Upload Limit</td><td style="padding:10px;border-bottom:1px solid #e2e8f0;">20 MB</td></tr>
<tr><td style="padding:10px;border-bottom:1px solid #e2e8f0;">PHP Version</td><td style="padding:10px;border-bottom:1px solid #e2e8f0;"><?php echo phpversion(); ?></td></tr>
<tr><td style="padding:10px;border-bottom:1px solid #e2e8f0;">upload_max_filesize</td><td style="padding:10px;border-bottom:1px solid #e2e8f0;"><?php echo ini_get('upload_max_filesize'); ?></td></tr>
<tr><td style="padding:10px;">post_max_size</td><td style="padding:10px;"><?php echo ini_get('post_max_size'); ?></td></tr>
</table>
</div>
</div>
</div>
</div>
<script src="js/admin.js"></script>
</body>
</html>
