<?php
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once 'products/config/config.php';
require_once 'products/includes/db.php';

$profileError = '';
$profileSuccess = '';
$formValues = array(
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'phone' => ''
);
$isEditMode = isset($_GET['edit']);

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$user = null;
$orders = [];
$orderStats = ['total' => 0, 'pending' => 0, 'delivered' => 0, 'processing' => 0, 'cancelled' => 0];
try {
    $db   = new Database();
    $conn = $db->getConnection();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_billing') {
        $isEditMode = true;
        $firstName = trim((string) ($_POST['first_name'] ?? ''));
        $lastName  = trim((string) ($_POST['last_name'] ?? ''));
        $email     = trim((string) ($_POST['email'] ?? ''));
        $phone     = trim((string) ($_POST['phone'] ?? ''));

        $formValues = array(
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $phone
        );

        if ($firstName === '' || $email === '' || $phone === '') {
            $profileError = 'First name, email and phone are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $profileError = 'Please enter a valid email address.';
        } else {
            $up = $conn->prepare('UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ? WHERE id = ? LIMIT 1');
            $up->bind_param('ssssi', $firstName, $lastName, $email, $phone, $_SESSION['user_id']);

            if ($up->execute()) {
                $_SESSION['name'] = trim($firstName . ' ' . $lastName);
                header('Location: profile.php?updated=1');
                exit;
            }

            $profileError = ((int) $conn->errno === 1062)
                ? 'This email is already in use by another account.'
                : 'Could not update profile. Please try again.';
            $up->close();
        }
    }

    $stmt = $conn->prepare('SELECT id, username, email, first_name, last_name, phone, user_type, created_at FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $user   = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    // Fetch orders with product name
    $os = $conn->prepare(
        'SELECT o.id, o.order_number, o.total_amount, o.status, o.payment_method, o.payment_status, o.shipping_address, o.notes, o.created_at,
                p.name AS product_name, p.image AS product_image
         FROM orders o
         LEFT JOIN order_items oi ON oi.order_id = o.id
         LEFT JOIN products p ON p.id = oi.product_id
         WHERE o.user_id = ?
         ORDER BY o.created_at DESC'
    );
    $os->bind_param('i', $_SESSION['user_id']);
    $os->execute();
    $or = $os->get_result();
    while ($row = $or->fetch_assoc()) {
        $orders[] = $row;
        $orderStats['total']++;
        $st = $row['status'];
        if (isset($orderStats[$st])) $orderStats[$st]++;
    }
    $os->close();
    $db->closeConnection();
} catch (Exception $e) {
    $user = null;
}

if (isset($_GET['updated'])) {
    $profileSuccess = 'Billing information updated successfully.';
}

if ($user && $formValues['first_name'] === '' && $formValues['last_name'] === '' && $formValues['email'] === '' && $formValues['phone'] === '') {
    $formValues['first_name'] = (string) ($user['first_name'] ?? '');
    $formValues['last_name'] = (string) ($user['last_name'] ?? '');
    $formValues['email'] = (string) ($user['email'] ?? '');
    $formValues['phone'] = (string) ($user['phone'] ?? '');
}

$displayName = $_SESSION['name'] ?? ($user['first_name'] ?? $user['username'] ?? 'User');
$initial     = strtoupper(substr($displayName, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php
$seo = [
    'title'       => 'My Profile - Accounts Bazar',
    'description' => 'Manage your Accounts Bazar profile, orders, and account information.',
    'keywords'    => 'accounts bazar profile, user dashboard, order history',
    'canonical'   => 'https://accountsbazar.com/profile.php',
    'og_image'    => 'https://accountsbazar.com/images/logo.png',
    'og_type'     => 'profile',
    'noindex'     => true,
];
require_once 'products/includes/seo.php';
?>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/mobile.css">
    <style>
        .profile-page {
            min-height: 80vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 36px 16px 100px;
            background: linear-gradient(135deg, #f0f4ff 0%, #fafafa 100%);
        }
        .profile-card {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 8px 40px rgba(15,23,42,0.12);
            padding: 40px 36px 36px;
            width: 100%;
            max-width: 480px;
            position: relative;
        }
        .profile-edit-icon {
            position: absolute;
            top: 14px;
            right: 14px;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            border: 1px solid #dbeafe;
            background: #eff6ff;
            color: #1d4ed8;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            text-decoration: none;
            box-shadow: 0 2px 8px rgba(30, 64, 175, 0.16);
            transition: transform 0.18s ease, background-color 0.18s ease, box-shadow 0.18s ease;
        }
        .profile-edit-icon:hover,
        .profile-edit-icon:focus-visible {
            background: #dbeafe;
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(30, 64, 175, 0.22);
            outline: none;
        }
        .profile-flash {
            width: 100%;
            max-width: 480px;
            margin-bottom: 12px;
            border-radius: 12px;
            padding: 10px 12px;
            font-size: 13px;
            font-weight: 700;
        }
        .profile-flash-success {
            background: #ecfdf3;
            border: 1px solid #86efac;
            color: #166534;
        }
        .profile-flash-error {
            background: #fff1f2;
            border: 1px solid #fecdd3;
            color: #be123c;
        }
        .profile-edit-form {
            margin: 4px 0 22px;
            display: grid;
            gap: 10px;
        }
        .profile-edit-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .profile-edit-form label {
            font-size: 12px;
            font-weight: 700;
            color: #475569;
            margin-bottom: 4px;
            display: inline-block;
        }
        .profile-edit-form input {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            font-size: 14px;
            padding: 10px 11px;
            color: #0f172a;
            background: #fff;
        }
        .profile-edit-actions {
            display: flex;
            gap: 8px;
            margin-top: 2px;
        }
        .profile-btn-secondary {
            background: #f8fafc;
            color: #334155;
            border: 1px solid #e2e8f0;
        }
        .profile-btn-secondary:hover {
            background: #eef2f7;
        }
        /* Order Stats */
        .order-stats-row {
            display: flex;
            gap: 12px;
            width: 100%;
            max-width: 480px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        .order-stat-box {
            flex: 1;
            min-width: 80px;
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(15,23,42,0.08);
            padding: 16px 10px;
            text-align: center;
        }
        .osb-pending    { border-top: 3px solid #f97316; }
        .osb-processing { border-top: 3px solid #3b82f6; }
        .osb-delivered  { border-top: 3px solid #16a34a; }
        .osb-num {
            font-size: 26px;
            font-weight: 900;
            color: #0f172a;
            line-height: 1;
        }
        .osb-label {
            font-size: 11px;
            color: #94a3b8;
            font-weight: 700;
            margin-top: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        /* Orders Section */
        .orders-section {
            width: 100%;
            max-width: 480px;
            margin-top: 24px;
        }
        .orders-title {
            font-size: 18px;
            font-weight: 900;
            color: #0f172a;
            margin-bottom: 14px;
        }
        .orders-empty {
            background: #fff;
            border-radius: 16px;
            padding: 40px 20px;
            text-align: center;
            box-shadow: 0 4px 16px rgba(15,23,42,0.07);
        }
        /* Order Card */
        .order-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 18px rgba(15,23,42,0.08);
            margin-bottom: 14px;
            overflow: hidden;
        }
        .order-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
        }
        .ocn-label {
            font-size: 11px;
            color: #94a3b8;
            font-weight: 700;
            text-transform: uppercase;
            margin-right: 6px;
        }
        .ocn-val {
            font-size: 14px;
            font-weight: 800;
            color: #0f172a;
        }
        .order-card-status {
            font-size: 12px;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 20px;
            display: flex;
            align-items: center;
        }
        .order-card-body {
            display: flex;
            gap: 12px;
            padding: 14px 16px;
            align-items: flex-start;
        }
        .order-card-img {
            width: 64px;
            height: 64px;
            object-fit: contain;
            border-radius: 8px;
            background: #f8fafc;
            flex-shrink: 0;
            border: 1px solid #e2e8f0;
        }
        .order-card-info { flex: 1; min-width: 0; }
        .oci-product {
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .oci-addr {
            font-size: 12px;
            color: #475569;
            margin-bottom: 2px;
        }
        .oci-notes {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 4px;
        }
        .order-card-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 16px;
            background: #f8fafc;
            border-top: 1px solid #f1f5f9;
            flex-wrap: wrap;
            gap: 4px;
        }
        .ocf-price {
            font-size: 16px;
            font-weight: 900;
            color: #991b1b;
        }
        .ocf-pay {
            font-size: 12px;
            font-weight: 700;
        }
        .ocf-date {
            font-size: 12px;
            color: #94a3b8;
        }
        @media (max-width: 480px) {
            .profile-card { padding: 28px 16px 28px; }
            .order-stat-box { padding: 12px 6px; }
            .profile-edit-grid { grid-template-columns: 1fr; }
        }
        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #0f172a 60%, #0ea5e9);
            color: #fff;
            font-size: 36px;
            font-weight: 900;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 14px;
        }
        .profile-name {
            text-align: center;
            font-size: 22px;
            font-weight: 900;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .profile-badge {
            text-align: center;
            margin-bottom: 24px;
        }
        .profile-badge span {
            display: inline-block;
            background: #0ea5e9;
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            padding: 3px 12px;
            border-radius: 20px;
            text-transform: capitalize;
        }
        .profile-info-list {
            list-style: none;
            padding: 0;
            margin: 0 0 24px;
        }
        .profile-info-list li {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 0;
            border-bottom: 1px solid #f1f5f9;
            font-size: 15px;
        }
        .profile-info-list li:last-child { border-bottom: none; }
        .pil-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #0ea5e9;
            flex-shrink: 0;
        }
        .pil-label { font-weight: 700; color: #94a3b8; font-size: 13px; min-width: 80px; }
        .pil-value { color: #0f172a; font-weight: 700; word-break: break-all; font-size: 14px; }
        .profile-actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .profile-btn {
            display: block;
            width: 100%;
            padding: 12px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 800;
            text-align: center;
            cursor: pointer;
            text-decoration: none;
            border: none;
        }
        .profile-btn-shop {
            background: #0f172a;
            color: #fff;
        }
        .profile-btn-shop:hover { background: #0ea5e9; }
        .profile-btn-logout {
            background: #fff0f0;
            color: #e11d48;
            border: 1.5px solid #fecdd3;
        }
        .profile-btn-logout:hover { background: #fee2e2; }
        .profile-joined {
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
            margin-top: 18px;
        }
        @media (max-width: 480px) {
            .profile-card { padding: 28px 16px 28px; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="container">
            <nav class="navbar">
                <div class="store-brand">
                    <a href="index.php" style="text-decoration:none;">
                        <span class="store-title">Accounts Bazar</span>
                    </a>
                </div>
            </nav>
        </div>
    </header>

    <div class="profile-page">
        <?php if ($profileSuccess): ?>
            <div class="profile-flash profile-flash-success"><?php echo htmlspecialchars($profileSuccess); ?></div>
        <?php endif; ?>
        <?php if ($profileError): ?>
            <div class="profile-flash profile-flash-error"><?php echo htmlspecialchars($profileError); ?></div>
        <?php endif; ?>

        <div class="profile-card">
            <?php if ($user): ?>
                <a class="profile-edit-icon" href="profile.php?edit=1" aria-label="Edit billing info" title="Edit Billing Info">✎</a>
                <div class="profile-avatar"><?php echo htmlspecialchars($initial); ?></div>
                <div class="profile-name"><?php echo htmlspecialchars($displayName); ?></div>
                <div class="profile-badge">
                    <span><?php echo htmlspecialchars($user['user_type'] ?? 'customer'); ?></span>
                </div>

                <?php if ($isEditMode): ?>
                <form class="profile-edit-form" method="POST" action="profile.php?edit=1">
                    <input type="hidden" name="action" value="update_billing">

                    <div class="profile-edit-grid">
                        <div>
                            <label for="first-name">First Name</label>
                            <input id="first-name" name="first_name" type="text" required value="<?php echo htmlspecialchars($formValues['first_name']); ?>">
                        </div>
                        <div>
                            <label for="last-name">Last Name</label>
                            <input id="last-name" name="last_name" type="text" value="<?php echo htmlspecialchars($formValues['last_name']); ?>">
                        </div>
                    </div>

                    <div>
                        <label for="billing-email">Email</label>
                        <input id="billing-email" name="email" type="email" required value="<?php echo htmlspecialchars($formValues['email']); ?>">
                    </div>

                    <div>
                        <label for="billing-phone">Phone</label>
                        <input id="billing-phone" name="phone" type="text" required value="<?php echo htmlspecialchars($formValues['phone']); ?>">
                    </div>

                    <div class="profile-edit-actions">
                        <button type="submit" class="profile-btn profile-btn-shop">Save Billing Info</button>
                        <a class="profile-btn profile-btn-secondary" href="profile.php">Cancel</a>
                    </div>
                </form>
                <?php endif; ?>

                <ul class="profile-info-list">
                    <li>
                        <span class="pil-dot"></span>
                        <span class="pil-label">Email</span>
                        <span class="pil-value"><?php echo htmlspecialchars($user['email'] ?? ''); ?></span>
                    </li>
                    <?php if (!empty($user['phone'])): ?>
                    <li>
                        <span class="pil-dot"></span>
                        <span class="pil-label">Phone</span>
                        <span class="pil-value"><?php echo htmlspecialchars($user['phone']); ?></span>
                    </li>
                    <?php endif; ?>
                    <li>
                        <span class="pil-dot"></span>
                        <span class="pil-label">Username</span>
                        <span class="pil-value"><?php echo htmlspecialchars($user['username'] ?? ''); ?></span>
                    </li>
                    <?php if (!empty($user['created_at'])): ?>
                    <li>
                        <span class="pil-dot"></span>
                        <span class="pil-label">Joined</span>
                        <span class="pil-value"><?php echo date('d M Y', strtotime($user['created_at'])); ?></span>
                    </li>
                    <?php endif; ?>
                </ul>

                <div class="profile-actions">
                    <a class="profile-btn profile-btn-shop" href="shop.php">🛍️ Continue Shopping</a>
                    <a class="profile-btn profile-btn-logout" href="profile.php?logout=1">🚪 Logout</a>
                </div>
            <?php else: ?>
                <p style="text-align:center;color:#64748b;">Could not load profile. <a href="login.php">Login again</a></p>
            <?php endif; ?>
        </div>

        <!-- Order Stats -->
        <div class="order-stats-row">
            <div class="order-stat-box">
                <div class="osb-num"><?php echo $orderStats['total']; ?></div>
                <div class="osb-label">Total Orders</div>
            </div>
            <div class="order-stat-box osb-pending">
                <div class="osb-num"><?php echo $orderStats['pending']; ?></div>
                <div class="osb-label">Pending</div>
            </div>
            <div class="order-stat-box osb-processing">
                <div class="osb-num"><?php echo $orderStats['processing']; ?></div>
                <div class="osb-label">Processing</div>
            </div>
            <div class="order-stat-box osb-delivered">
                <div class="osb-num"><?php echo $orderStats['delivered']; ?></div>
                <div class="osb-label">Delivered</div>
            </div>
        </div>

        <!-- Order Cards -->
        <div class="orders-section">
            <h2 class="orders-title">My Orders</h2>
            <?php if (empty($orders)): ?>
                <div class="orders-empty">
                    <div style="font-size:48px;margin-bottom:10px;">📦</div>
                    <div style="color:#64748b;font-size:15px;">You haven't placed any orders yet.</div>
                    <a href="shop.php" style="display:inline-block;margin-top:14px;background:#0f172a;color:#fff;padding:10px 24px;border-radius:8px;font-weight:800;text-decoration:none;">Start Shopping</a>
                </div>
            <?php else: ?>
                <?php foreach ($orders as $ord):
                    $statusColors = [
                        'pending'    => ['bg'=>'#fff7ed','txt'=>'#ea580c','dot'=>'#f97316'],
                        'processing' => ['bg'=>'#eff6ff','txt'=>'#2563eb','dot'=>'#3b82f6'],
                        'shipped'    => ['bg'=>'#f0fdf4','txt'=>'#16a34a','dot'=>'#22c55e'],
                        'delivered'  => ['bg'=>'#f0fdf4','txt'=>'#15803d','dot'=>'#16a34a'],
                        'cancelled'  => ['bg'=>'#fff0f0','txt'=>'#dc2626','dot'=>'#ef4444'],
                    ];
                    $sc = $statusColors[$ord['status']] ?? ['bg'=>'#f1f5f9','txt'=>'#475569','dot'=>'#94a3b8'];
                    $payColors = ['unpaid'=>'#ef4444','paid'=>'#16a34a','failed'=>'#dc2626'];
                    $payColor  = $payColors[$ord['payment_status']] ?? '#94a3b8';

                    $rawImage = (string) ($ord['product_image'] ?? '');
                    $imgList = array_values(array_filter(array_map('trim', explode(',', $rawImage))));
                    $imgPrimary = $imgList[0] ?? $rawImage;
                    $imgPath = ltrim((string) $imgPrimary, '/');
                    $imgSrc  = (strpos($imgPath, 'images/') === 0) ? 'products/' . $imgPath : $imgPath;
                    $addrLines = explode("\n", $ord['shipping_address'] ?? '');
                    $notesLine = $ord['notes'] ?? '';
                ?>
                <div class="order-card">
                    <div class="order-card-header">
                        <div class="order-card-num">
                            <span class="ocn-label">Order</span>
                            <span class="ocn-val"><?php echo htmlspecialchars($ord['order_number']); ?></span>
                        </div>
                        <div class="order-card-status" style="background:<?php echo $sc['bg']; ?>;color:<?php echo $sc['txt']; ?>;">
                            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?php echo $sc['dot']; ?>;margin-right:5px;"></span>
                            <?php echo ucfirst($ord['status']); ?>
                        </div>
                    </div>
                    <div class="order-card-body">
                        <?php if (!empty($imgSrc)): ?>
                        <img class="order-card-img" src="<?php echo htmlspecialchars($imgSrc); ?>" alt="">
                        <?php endif; ?>
                        <div class="order-card-info">
                            <div class="oci-product"><?php echo htmlspecialchars($ord['product_name'] ?? 'Product'); ?></div>
                            <div class="oci-addr"><?php echo htmlspecialchars($addrLines[0] ?? ''); ?></div>
                            <?php if (!empty($addrLines[1])): ?>
                            <div class="oci-addr"><?php echo htmlspecialchars($addrLines[1]); ?></div>
                            <?php endif; ?>
                            <?php if ($notesLine): ?>
                            <div class="oci-notes"><?php echo htmlspecialchars($notesLine); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="order-card-footer">
                        <div class="ocf-price">৳ <?php echo number_format((float)$ord['total_amount'], 2); ?></div>
                        <div class="ocf-pay" style="color:<?php echo $payColor; ?>;">
                            <?php echo ucfirst($ord['payment_status']); ?>
                            <?php if ($ord['payment_method']): ?> · <?php echo htmlspecialchars(strtoupper($ord['payment_method'])); ?><?php endif; ?>
                        </div>
                        <div class="ocf-date"><?php echo date('d M Y', strtotime($ord['created_at'])); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <nav class="mobile-bottom-nav" aria-label="Mobile Bottom Navigation">
        <a href="index.php"><span class="nav-icon">🏠</span><span class="nav-label">Home</span></a>
        <a href="shop.php"><span class="nav-icon">🛍️</span><span class="nav-label">Shop</span></a>
        <a class="ai-prompt-link" href="ai-prompt.php"><span class="nav-icon">🤖</span><span class="nav-label">AI Prompt</span></a>
        <a href="#" data-notification-toggle><span class="nav-icon">🔔</span><span class="nav-label">Notification</span><span class="notif-badge" data-notif-badge style="display:none;">0</span></a>
        <a class="active" href="profile.php"><span class="nav-icon">👤</span><span class="nav-label">Profile</span></a>
    </nav>
    <script src="js/client.js"></script>
</body>
</html>
