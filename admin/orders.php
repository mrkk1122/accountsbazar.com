<?php
/**
 * Admin - Orders Page
 */

require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();
require_once '../products/config/config.php';
require_once '../products/includes/db.php';

$db   = new Database();
$conn = $db->getConnection();

$message = '';
$error   = '';

// ---- Update order status ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $orderId       = (int) ($_POST['order_id'] ?? 0);
    $newStatus     = trim((string) ($_POST['status'] ?? ''));
    $newPayStatus  = trim((string) ($_POST['payment_status'] ?? ''));
    $allowedStatus = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];
    $allowedPay    = ['unpaid', 'paid', 'failed'];

    if ($orderId > 0 && in_array($newStatus, $allowedStatus) && in_array($newPayStatus, $allowedPay)) {
        $upd = $conn->prepare('UPDATE orders SET status = ?, payment_status = ? WHERE id = ?');
        $upd->bind_param('ssi', $newStatus, $newPayStatus, $orderId);
        if ($upd->execute()) {
            $message = 'Order #' . $orderId . ' updated successfully.';
        } else {
            $error = 'Failed to update order.';
        }
        $upd->close();
    } else {
        $error = 'Invalid data.';
    }
}

// ---- Stats ----
$stats = ['total' => 0, 'pending' => 0, 'processing' => 0, 'delivered' => 0, 'cancelled' => 0, 'revenue' => 0];
$sr = $conn->query(
    "SELECT COUNT(*) AS total,
            SUM(status='pending')    AS pending,
            SUM(status='processing') AS processing,
            SUM(status='delivered')  AS delivered,
            SUM(status='cancelled')  AS cancelled,
            SUM(CASE WHEN payment_status='paid' THEN total_amount ELSE 0 END) AS revenue
     FROM orders"
);
if ($sr && $row = $sr->fetch_assoc()) {
    $stats = array_merge($stats, array_map('floatval', $row));
}

// ---- Filters ----
$filterStatus = trim($_GET['status'] ?? '');
$search       = trim($_GET['q'] ?? '');
$page         = max(1, (int) ($_GET['page'] ?? 1));
$perPage      = 20;
$offset       = ($page - 1) * $perPage;

$where  = '1';
$params = [];
$types  = '';
if ($filterStatus) {
    $where   .= ' AND o.status = ?';
    $params[] = $filterStatus;
    $types   .= 's';
}
if ($search) {
    $like     = '%' . $search . '%';
    $where   .= ' AND (o.order_number LIKE ? OR u.email LIKE ? OR u.username LIKE ?)';
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= 'sss';
}

// Count
$cntQ = $conn->prepare("SELECT COUNT(*) AS total FROM orders o LEFT JOIN users u ON u.id=o.user_id WHERE $where");
if ($types) $cntQ->bind_param($types, ...$params);
$cntQ->execute();
$totalOrders = (int)($cntQ->get_result()->fetch_assoc()['total'] ?? 0);
$cntQ->close();
$totalPages = max(1, (int) ceil($totalOrders / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

// Orders
$ordQ = $conn->prepare(
    "SELECT o.id, o.order_number, o.total_amount, o.status, o.payment_method, o.payment_status,
            o.shipping_address, o.notes, o.created_at,
            u.username, u.email,
            p.name AS product_name, p.image AS product_image
     FROM orders o
     LEFT JOIN users u  ON u.id  = o.user_id
     LEFT JOIN order_items oi ON oi.order_id = o.id
     LEFT JOIN products p ON p.id = oi.product_id
     WHERE $where
     ORDER BY o.created_at DESC
     LIMIT ? OFFSET ?"
);
$allTypes  = $types . 'ii';
$allParams = array_merge($params, [$perPage, $offset]);
$ordQ->bind_param($allTypes, ...$allParams);
$ordQ->execute();
$ordResult = $ordQ->get_result();
$orders = [];
while ($row = $ordResult->fetch_assoc()) $orders[] = $row;
$ordQ->close();

$db->closeConnection();

$statusColors = [
    'pending'    => '#f97316',
    'processing' => '#3b82f6',
    'shipped'    => '#06b6d4',
    'delivered'  => '#16a34a',
    'cancelled'  => '#ef4444',
];
$payColors = ['unpaid' => '#ef4444', 'paid' => '#16a34a', 'failed' => '#dc2626'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">    <link rel="icon" href="../favicon.svg?v=20260429f" type="image/svg+xml">
    <link rel="shortcut icon" href="../favicon.png?v=20260429f" type="image/png">
    <link rel="apple-touch-icon" href="../images/logo.png">
    <title>Orders - Admin Panel</title>
    <link rel="stylesheet" href="css/admin.css">
    <style>
        .orders-wrap { padding: 24px 20px 40px; }
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .page-title { font-size: 22px; font-weight: 800; color: #1e293b; }

        /* Stats */
        .o-stats { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 22px; }
        .o-stat {
            flex: 1; min-width: 110px;
            background: #fff;
            border-radius: 12px;
            padding: 16px 14px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            border-left: 4px solid #e2e8f0;
        }
        .o-stat.s-pending    { border-left-color: #f97316; }
        .o-stat.s-processing { border-left-color: #3b82f6; }
        .o-stat.s-delivered  { border-left-color: #16a34a; }
        .o-stat.s-cancelled  { border-left-color: #ef4444; }
        .o-stat.s-revenue    { border-left-color: #8b5cf6; }
        .o-stat-num { font-size: 24px; font-weight: 900; color: #0f172a; }
        .o-stat-label { font-size: 11px; color: #94a3b8; font-weight: 700; text-transform: uppercase; margin-top: 2px; }

        /* Filters */
        .filters-bar {
            display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 18px; align-items: center;
        }
        .filters-bar input[type="text"] {
            padding: 9px 14px; border: 1.5px solid #e2e8f0; border-radius: 8px;
            font-size: 14px; outline: none; min-width: 200px;
        }
        .filters-bar input:focus { border-color: #667eea; }
        .filters-bar select {
            padding: 9px 12px; border: 1.5px solid #e2e8f0; border-radius: 8px;
            font-size: 14px; outline: none; background: #fff; cursor: pointer;
        }
        .filter-btn {
            padding: 9px 18px; background: #667eea; color: #fff; border: none;
            border-radius: 8px; font-size: 14px; font-weight: 700; cursor: pointer;
        }

        /* Alert */
        .alert-success { background: #f0fff4; border-left: 4px solid #16a34a; color: #15803d; padding: 10px 16px; border-radius: 8px; margin-bottom: 16px; font-weight: 600; }
        .alert-error   { background: #fff0f0; border-left: 4px solid #ef4444; color: #b91c1c; padding: 10px 16px; border-radius: 8px; margin-bottom: 16px; font-weight: 600; }

        /* Table */
        .orders-table-wrap { overflow-x: auto; background: #fff; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,0,0,0.07); }
        .orders-table { width: 100%; border-collapse: collapse; font-size: 14px; min-width: 700px; }
        .orders-table th {
            background: #f8fafc; padding: 12px 14px; text-align: left;
            font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;
            border-bottom: 2px solid #f1f5f9;
        }
        .orders-table td { padding: 13px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .orders-table tr:last-child td { border-bottom: none; }
        .orders-table tr:hover td { background: #fafbff; }

        /* Product cell */
        .td-product { display: flex; align-items: center; gap: 10px; }
        .td-product-img {
            width: 46px; height: 46px; object-fit: contain; border-radius: 8px;
            background: #f8fafc; border: 1px solid #e2e8f0; flex-shrink: 0;
        }
        .td-product-name { font-weight: 700; color: #0f172a; font-size: 13px; max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .td-product-plan { font-size: 11px; color: #94a3b8; margin-top: 2px; }

        /* Status badge */
        .status-badge {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 20px;
        }
        .status-dot { width: 7px; height: 7px; border-radius: 50%; }

        /* Price */
        .td-price { font-weight: 900; color: #991b1b; font-size: 15px; }

        /* User info */
        .td-user { font-size: 13px; }
        .td-user-name { font-weight: 700; color: #1e293b; }
        .td-user-email { color: #94a3b8; font-size: 11px; }

        /* Action form */
        .action-form { display: flex; flex-direction: column; gap: 6px; }
        .action-form select {
            padding: 5px 8px; border: 1.5px solid #e2e8f0; border-radius: 7px;
            font-size: 12px; outline: none; background: #fff; cursor: pointer;
        }
        .action-form select:focus { border-color: #667eea; }
        .action-save {
            padding: 5px 12px; background: #667eea; color: #fff; border: none;
            border-radius: 7px; font-size: 12px; font-weight: 700; cursor: pointer;
        }
        .action-save:hover { background: #4f46e5; }

        /* Pagination */
        .pagination { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 18px; justify-content: center; }
        .pagination a, .pagination span {
            display: inline-block; padding: 7px 14px; border-radius: 8px;
            font-size: 13px; font-weight: 700; text-decoration: none;
            background: #fff; border: 1.5px solid #e2e8f0; color: #334155;
        }
        .pagination a:hover { background: #667eea; color: #fff; border-color: #667eea; }
        .pagination .pg-active { background: #667eea; color: #fff; border-color: #667eea; }

        .no-orders { text-align: center; padding: 50px 20px; color: #94a3b8; font-size: 15px; }
    </style>
</head>
<body>
<div class="admin-container">
    <button class="admin-menu-toggle" type="button" aria-label="Toggle menu" aria-expanded="false" aria-controls="admin-sidebar">
        <span></span><span></span><span></span>
    </button>
    <div class="admin-overlay"></div>
    <nav class="admin-sidebar" id="admin-sidebar">
        <div class="admin-logo"><h2>Admin Panel</h2></div>
        <ul class="menu">
            <li><a href="index.php">Dashboard</a></li>
            <li><a href="products.php">Products</a></li>
            <li><a href="ai-prompts.php">AI Prompts</a></li>
            <li><a href="user.php">Users</a></li>
            <li><a href="orders.php" class="active">Orders</a></li>
            <li><a href="support-messages.php">Messages</a></li>
        </ul>
    </nav>

    <div class="admin-main">
        <header class="admin-header">
            <h1>Orders Management</h1>
        </header>

        <main class="admin-content">
            <div class="orders-wrap">

                <!-- Stats -->
                <div class="o-stats">
                    <div class="o-stat">
                        <div class="o-stat-num"><?php echo (int)$stats['total']; ?></div>
                        <div class="o-stat-label">Total</div>
                    </div>
                    <div class="o-stat s-pending">
                        <div class="o-stat-num"><?php echo (int)$stats['pending']; ?></div>
                        <div class="o-stat-label">Pending</div>
                    </div>
                    <div class="o-stat s-processing">
                        <div class="o-stat-num"><?php echo (int)$stats['processing']; ?></div>
                        <div class="o-stat-label">Processing</div>
                    </div>
                    <div class="o-stat s-delivered">
                        <div class="o-stat-num"><?php echo (int)$stats['delivered']; ?></div>
                        <div class="o-stat-label">Delivered</div>
                    </div>
                    <div class="o-stat s-cancelled">
                        <div class="o-stat-num"><?php echo (int)$stats['cancelled']; ?></div>
                        <div class="o-stat-label">Cancelled</div>
                    </div>
                    <div class="o-stat s-revenue">
                        <div class="o-stat-num">৳<?php echo number_format($stats['revenue'], 0); ?></div>
                        <div class="o-stat-label">Revenue</div>
                    </div>
                </div>

                <?php if ($message): ?><div class="alert-success">✅ <?php echo htmlspecialchars($message); ?></div><?php endif; ?>
                <?php if ($error):   ?><div class="alert-error">❌ <?php echo htmlspecialchars($error); ?></div><?php endif; ?>

                <!-- Filters -->
                <form class="filters-bar" method="GET" action="orders.php">
                    <input type="text" name="q" placeholder="Search order, email, username…" value="<?php echo htmlspecialchars($search); ?>">
                    <select name="status">
                        <option value="">All Status</option>
                        <?php foreach (['pending','processing','shipped','delivered','cancelled'] as $s): ?>
                        <option value="<?php echo $s; ?>" <?php if ($filterStatus === $s) echo 'selected'; ?>><?php echo ucfirst($s); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="filter-btn" type="submit">Filter</button>
                    <?php if ($search || $filterStatus): ?>
                    <a class="filter-btn" href="orders.php" style="background:#e2e8f0;color:#334155;text-decoration:none;padding:9px 14px;border-radius:8px;font-weight:700;font-size:14px;">Clear</a>
                    <?php endif; ?>
                </form>

                <!-- Table -->
                <?php if (empty($orders)): ?>
                    <div class="no-orders">📦 No orders found.</div>
                <?php else: ?>
                <div class="orders-table-wrap">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Order</th>
                                <th>Product</th>
                                <th>Customer</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Payment</th>
                                <th>Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($orders as $i => $ord):
                            $sc  = $statusColors[$ord['status']]       ?? '#94a3b8';
                            $pc  = $payColors[$ord['payment_status']]  ?? '#94a3b8';
                            $rawImage = (string) ($ord['product_image'] ?? '');
                            $imgList = array_values(array_filter(array_map('trim', explode(',', $rawImage))));
                            $imgPrimary = $imgList[0] ?? $rawImage;
                            $imgPath = ltrim((string) $imgPrimary, '/');
                            $imgSrc  = (strpos($imgPath, 'images/') === 0) ? '../products/' . $imgPath : '../' . $imgPath;
                            $addrLine = explode('|', $ord['shipping_address'] ?? '')[0] ?? '';
                            $noteLine = $ord['notes'] ?? '';
                        ?>
                        <tr>
                            <td style="color:#94a3b8;font-size:12px;"><?php echo $offset + $i + 1; ?></td>
                            <td>
                                <div style="font-weight:800;font-size:13px;color:#0f172a;"><?php echo htmlspecialchars($ord['order_number']); ?></div>
                                <div style="font-size:11px;color:#94a3b8;margin-top:2px;"><?php echo htmlspecialchars($addrLine); ?></div>
                                <?php if ($noteLine): ?>
                                <div style="font-size:11px;color:#cbd5e1;margin-top:1px;"><?php echo htmlspecialchars($noteLine); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="td-product">
                                    <?php if ($imgSrc && $ord['product_image']): ?>
                                    <img class="td-product-img" src="<?php echo htmlspecialchars($imgSrc); ?>" alt="">
                                    <?php endif; ?>
                                    <div>
                                        <div class="td-product-name"><?php echo htmlspecialchars($ord['product_name'] ?? '—'); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="td-user">
                                <div class="td-user-name"><?php echo htmlspecialchars($ord['username'] ?? '—'); ?></div>
                                <div class="td-user-email"><?php echo htmlspecialchars($ord['email'] ?? ''); ?></div>
                            </td>
                            <td class="td-price">৳<?php echo number_format((float)$ord['total_amount'], 2); ?></td>
                            <td>
                                <span class="status-badge" style="background:<?php echo $sc; ?>22;color:<?php echo $sc; ?>;">
                                    <span class="status-dot" style="background:<?php echo $sc; ?>;"></span>
                                    <?php echo ucfirst($ord['status']); ?>
                                </span>
                            </td>
                            <td>
                                <span style="color:<?php echo $pc; ?>;font-weight:700;font-size:13px;"><?php echo ucfirst($ord['payment_status']); ?></span>
                                <?php if ($ord['payment_method']): ?>
                                <div style="font-size:11px;color:#94a3b8;"><?php echo strtoupper(htmlspecialchars($ord['payment_method'])); ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:12px;color:#64748b;white-space:nowrap;"><?php echo date('d M Y', strtotime($ord['created_at'])); ?></td>
                            <td>
                                <form class="action-form" method="POST" action="orders.php">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="order_id" value="<?php echo $ord['id']; ?>">
                                    <select name="status">
                                        <?php foreach (['pending','processing','shipped','delivered','cancelled'] as $s): ?>
                                        <option value="<?php echo $s; ?>" <?php if ($ord['status'] === $s) echo 'selected'; ?>><?php echo ucfirst($s); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select name="payment_status">
                                        <?php foreach (['unpaid','paid','failed'] as $ps): ?>
                                        <option value="<?php echo $ps; ?>" <?php if ($ord['payment_status'] === $ps) echo 'selected'; ?>><?php echo ucfirst($ps); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="action-save" type="submit">Save</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page-1; ?>&status=<?php echo urlencode($filterStatus); ?>&q=<?php echo urlencode($search); ?>">← Prev</a>
                    <?php endif; ?>
                    <?php for ($p = max(1,$page-2); $p <= min($totalPages,$page+2); $p++): ?>
                    <<?php echo $p==$page?'span class="pg-active"':'a href="?page='.$p.'&status='.urlencode($filterStatus).'&q='.urlencode($search).'"'; ?>><?php echo $p; ?></<?php echo $p==$page?'span':'a'; ?>>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page+1; ?>&status=<?php echo urlencode($filterStatus); ?>&q=<?php echo urlencode($search); ?>">Next →</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>

            </div>
        </main>
    </div>
</div>
<script src="js/admin.js"></script>
</body>
</html>
