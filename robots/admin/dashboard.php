<?php
/**
 * Admin Dashboard Fallback
 * Use this page if index.php becomes corrupted during hosting upload.
 */

require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();
require_once '../products/config/config.php';
require_once '../products/includes/db.php';

$stats = array('products' => 0, 'users' => 0, 'orders' => 0, 'revenue' => 0, 'pending' => 0, 'delivered' => 0);
try {
    $db = new Database();
    $conn = $db->getConnection();
    $r = $conn->query("SELECT
        (SELECT COUNT(*) FROM products) AS products,
        (SELECT COUNT(*) FROM users)    AS users,
        (SELECT COUNT(*) FROM orders)   AS orders,
        (SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE payment_status='paid') AS revenue,
        (SELECT COUNT(*) FROM orders WHERE status='pending')   AS pending,
        (SELECT COUNT(*) FROM orders WHERE status='delivered') AS delivered
    ");
    if ($r && ($row = $r->fetch_assoc())) {
        $stats = $row;
    }
    $db->closeConnection();
} catch (Throwable $e) {
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../favicon.svg?v=20260429f" type="image/svg+xml">
    <link rel="shortcut icon" href="../favicon.png?v=20260429f" type="image/png">
    <link rel="apple-touch-icon" href="../images/logo.png">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="css/admin.css">
</head>
<body>
    <div class="admin-container">
        <button class="admin-menu-toggle" type="button" aria-label="Toggle menu" aria-expanded="false" aria-controls="admin-sidebar">
            <span></span>
            <span></span>
            <span></span>
        </button>
        <div class="admin-overlay"></div>
        <nav class="admin-sidebar" id="admin-sidebar">
            <div class="admin-logo">
                <h2>Admin Panel</h2>
            </div>
            <ul class="menu">
                <li><a href="dashboard.php" class="active">Dashboard</a></li>
                <li><a href="products.php">Products</a></li>
                <li><a href="ai-prompts.php">AI Prompts</a></li>
                <li><a href="user.php">Users</a></li>
                <li><a href="orders.php">Orders</a></li>
                <li><a href="support-messages.php">Messages</a></li>
                <li><a href="reports.php">Reports</a></li>
                <li><a href="settings.php">Settings</a></li>
                <li><a href="logout.php">Logout</a></li>
            </ul>
        </nav>

        <div class="admin-main">
            <header class="admin-header">
                <h1>Welcome to Admin Dashboard</h1>
                <div class="admin-user">
                    <span>Admin User</span>
                </div>
            </header>

            <main class="admin-content">
                <div class="dashboard-grid">
                    <div class="stat-card">
                        <h3>Total Products</h3>
                        <p class="stat-number"><?php echo (int) $stats['products']; ?></p>
                    </div>
                    <div class="stat-card">
                        <h3>Total Users</h3>
                        <p class="stat-number"><?php echo (int) $stats['users']; ?></p>
                    </div>
                    <div class="stat-card">
                        <h3>Total Orders</h3>
                        <p class="stat-number"><?php echo (int) $stats['orders']; ?></p>
                    </div>
                    <div class="stat-card">
                        <h3>Revenue (Paid)</h3>
                        <p class="stat-number">BDT <?php echo number_format((float) $stats['revenue'], 0); ?></p>
                    </div>
                    <div class="stat-card">
                        <h3>Pending Orders</h3>
                        <p class="stat-number" style="color:#f97316;"><?php echo (int) $stats['pending']; ?></p>
                    </div>
                    <div class="stat-card">
                        <h3>Delivered</h3>
                        <p class="stat-number" style="color:#16a34a;"><?php echo (int) $stats['delivered']; ?></p>
                    </div>
                </div>
                <div style="margin-top:24px;">
                    <a href="orders.php" style="display:inline-block;background:#667eea;color:#fff;padding:11px 24px;border-radius:10px;font-weight:800;text-decoration:none;font-size:15px;">Manage Orders</a>
                    <a href="products.php" style="display:inline-block;background:#0f172a;color:#fff;padding:11px 24px;border-radius:10px;font-weight:800;text-decoration:none;font-size:15px;margin-left:10px;">Manage Products</a>
                </div>
            </main>
        </div>
    </div>

    <script src="js/admin.js"></script>
</body>
</html>
