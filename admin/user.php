<?php
/**
 * Admin - User Management Page
 */

require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();
require_once '../products/config/config.php';
require_once '../products/includes/db.php';

$db = new Database();
$conn = $db->getConnection();

$message = '';
$error = '';
$currentAdminId = (int) ($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $userId = (int) ($_POST['user_id'] ?? 0);

    if ($userId <= 0) {
        $error = 'Invalid user id.';
    } elseif ($action === 'toggle_active') {
        $newActive = ((int) ($_POST['is_active'] ?? 0)) === 1 ? 1 : 0;

        if ($userId === $currentAdminId && $newActive === 0) {
            $error = 'You cannot deactivate your own account.';
        } else {
            $stmt = $conn->prepare('UPDATE users SET is_active = ? WHERE id = ?');
            $stmt->bind_param('ii', $newActive, $userId);
            if ($stmt->execute()) {
                $message = 'User status updated successfully.';
            } else {
                $error = 'Failed to update user status.';
            }
            $stmt->close();
        }
    } elseif ($action === 'change_role') {
        $newRole = trim((string) ($_POST['user_type'] ?? 'customer'));
        if (!in_array($newRole, ['admin', 'customer'], true)) {
            $error = 'Invalid role selected.';
        } elseif ($userId === $currentAdminId && $newRole !== 'admin') {
            $error = 'You cannot remove your own admin role.';
        } else {
            $stmt = $conn->prepare('UPDATE users SET user_type = ? WHERE id = ?');
            $stmt->bind_param('si', $newRole, $userId);
            if ($stmt->execute()) {
                $message = 'User role updated successfully.';
            } else {
                $error = 'Failed to update user role.';
            }
            $stmt->close();
        }
    }
}

$stats = ['total' => 0, 'admins' => 0, 'customers' => 0, 'active' => 0, 'inactive' => 0];
$sr = $conn->query("SELECT
    COUNT(*) AS total,
    SUM(user_type='admin') AS admins,
    SUM(user_type='customer') AS customers,
    SUM(is_active=1) AS active,
    SUM(is_active=0) AS inactive
    FROM users");
if ($sr && $row = $sr->fetch_assoc()) {
    $stats = [
        'total' => (int) ($row['total'] ?? 0),
        'admins' => (int) ($row['admins'] ?? 0),
        'customers' => (int) ($row['customers'] ?? 0),
        'active' => (int) ($row['active'] ?? 0),
        'inactive' => (int) ($row['inactive'] ?? 0),
    ];
}

$search = trim((string) ($_GET['q'] ?? ''));
$role = trim((string) ($_GET['role'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = '1';
$params = [];
$types = '';

if ($search !== '') {
    $like = '%' . $search . '%';
    $where .= ' AND (username LIKE ? OR email LIKE ? OR first_name LIKE ? OR last_name LIKE ?)';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}

if ($role !== '' && in_array($role, ['admin', 'customer'], true)) {
    $where .= ' AND user_type = ?';
    $params[] = $role;
    $types .= 's';
}

$countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM users WHERE $where");
if ($types !== '') {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalUsers = (int) (($countStmt->get_result()->fetch_assoc()['total'] ?? 0));
$countStmt->close();

$totalPages = max(1, (int) ceil($totalUsers / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$listStmt = $conn->prepare("SELECT id, username, email, first_name, last_name, user_type, is_active, created_at FROM users WHERE $where ORDER BY id DESC LIMIT ? OFFSET ?");
$listTypes = $types . 'ii';
$listParams = array_merge($params, [$perPage, $offset]);
$listStmt->bind_param($listTypes, ...$listParams);
$listStmt->execute();
$result = $listStmt->get_result();
$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}
$listStmt->close();

$db->closeConnection();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">    <link rel="icon" href="../favicon.svg?v=20260429f" type="image/svg+xml">
    <link rel="shortcut icon" href="../favicon.png?v=20260429f" type="image/png">
    <link rel="apple-touch-icon" href="../images/logo.png">
    <title>Users - Admin Panel</title>
    <link rel="stylesheet" href="css/admin.css">
    <style>
        .user-wrap { padding: 20px; }
        .notice {
            margin-bottom: 12px;
            padding: 10px 12px;
            border-radius: 8px;
            font-weight: 600;
        }
        .notice.success { background: #dcfce7; color: #166534; }
        .notice.error { background: #fee2e2; color: #991b1b; }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 10px;
            margin-bottom: 14px;
        }
        .summary-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 12px;
        }
        .summary-card .num {
            font-size: 24px;
            font-weight: 900;
            color: #1e3a8a;
        }
        .summary-card .lbl {
            font-size: 12px;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 700;
        }

        .filters {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 14px;
            align-items: center;
        }
        .filters input,
        .filters select {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 14px;
        }
        .filters button,
        .filters a {
            border: none;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }
        .filters button { background: #4f46e5; color: #fff; }
        .filters a { background: #e2e8f0; color: #334155; }

        .table-wrap {
            overflow-x: auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        .user-table {
            width: 100%;
            min-width: 840px;
            border-collapse: collapse;
        }
        .user-table th {
            background: #f8fafc;
            color: #475569;
            font-size: 12px;
            text-transform: uppercase;
            padding: 10px;
            text-align: left;
        }
        .user-table td {
            padding: 10px;
            border-top: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        .user-name {
            font-weight: 700;
            color: #0f172a;
        }
        .user-meta {
            font-size: 12px;
            color: #64748b;
        }
        .badge {
            display: inline-block;
            border-radius: 999px;
            padding: 3px 10px;
            font-size: 12px;
            font-weight: 700;
        }
        .role-admin { background: #e0e7ff; color: #3730a3; }
        .role-customer { background: #e0f2fe; color: #075985; }
        .status-active { background: #dcfce7; color: #166534; }
        .status-inactive { background: #fee2e2; color: #991b1b; }

        .action-form {
            display: inline-flex;
            gap: 5px;
            align-items: center;
            margin: 2px 4px 2px 0;
        }
        .action-form select,
        .action-form button {
            padding: 4px 8px;
            border-radius: 6px;
            border: 1px solid #d1d5db;
            font-size: 12px;
        }
        .action-form button {
            border: none;
            color: #fff;
            font-weight: 700;
            cursor: pointer;
        }
        .btn-role { background: #4f46e5; }
        .btn-status { background: #0f766e; }

        .pagination {
            margin-top: 12px;
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            justify-content: center;
        }
        .pagination a,
        .pagination span {
            text-decoration: none;
            border: 1px solid #d1d5db;
            background: #fff;
            border-radius: 8px;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 700;
            color: #334155;
        }
        .pagination .active { background: #4f46e5; color: #fff; border-color: #4f46e5; }
    </style>
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
            <li><a href="index.php">Dashboard</a></li>
            <li><a href="products.php">Products</a></li>
            <li><a href="ai-prompts.php">AI Prompts</a></li>
            <li><a href="user.php" class="active">Users</a></li>
            <li><a href="orders.php">Orders</a></li>
            <li><a href="support-messages.php">Messages</a></li>
            <li><a href="settings.php">Settings</a></li>
            <li><a href="logout.php">Logout</a></li>
        </ul>
    </nav>

    <div class="admin-main">
        <header class="admin-header">
            <h1>User Management</h1>
        </header>

        <main class="admin-content user-wrap">
            <?php if ($message !== ''): ?>
                <div class="notice success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="notice error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="summary-grid">
                <div class="summary-card"><div class="num"><?php echo $stats['total']; ?></div><div class="lbl">Total Users</div></div>
                <div class="summary-card"><div class="num"><?php echo $stats['admins']; ?></div><div class="lbl">Admins</div></div>
                <div class="summary-card"><div class="num"><?php echo $stats['customers']; ?></div><div class="lbl">Customers</div></div>
                <div class="summary-card"><div class="num"><?php echo $stats['active']; ?></div><div class="lbl">Active</div></div>
                <div class="summary-card"><div class="num"><?php echo $stats['inactive']; ?></div><div class="lbl">Inactive</div></div>
            </div>

            <form class="filters" method="GET" action="user.php">
                <input type="text" name="q" placeholder="Search username/email/name" value="<?php echo htmlspecialchars($search); ?>">
                <select name="role">
                    <option value="">All Roles</option>
                    <option value="admin" <?php if ($role === 'admin') echo 'selected'; ?>>Admin</option>
                    <option value="customer" <?php if ($role === 'customer') echo 'selected'; ?>>Customer</option>
                </select>
                <button type="submit">Filter</button>
                <?php if ($search !== '' || $role !== ''): ?>
                    <a href="user.php">Clear</a>
                <?php endif; ?>
            </form>

            <div class="table-wrap">
                <table class="user-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="5" style="text-align:center;color:#64748b;">No users found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($users as $u): ?>
                            <?php $uid = (int) $u['id']; $isSelf = ($uid === $currentAdminId); ?>
                            <tr>
                                <td>
                                    <div class="user-name"><?php echo htmlspecialchars((string) $u['username']); ?><?php if ($isSelf): ?> (You)<?php endif; ?></div>
                                    <div class="user-meta"><?php echo htmlspecialchars((string) $u['email']); ?></div>
                                </td>
                                <td>
                                    <?php if ((string) $u['user_type'] === 'admin'): ?>
                                        <span class="badge role-admin">Admin</span>
                                    <?php else: ?>
                                        <span class="badge role-customer">Customer</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int) $u['is_active'] === 1): ?>
                                        <span class="badge status-active">Active</span>
                                    <?php else: ?>
                                        <span class="badge status-inactive">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars((string) $u['created_at']); ?></td>
                                <td>
                                    <form method="POST" class="action-form">
                                        <input type="hidden" name="action" value="change_role">
                                        <input type="hidden" name="user_id" value="<?php echo $uid; ?>">
                                        <select name="user_type" <?php if ($isSelf): ?>disabled<?php endif; ?>>
                                            <option value="customer" <?php if ((string) $u['user_type'] === 'customer') echo 'selected'; ?>>Customer</option>
                                            <option value="admin" <?php if ((string) $u['user_type'] === 'admin') echo 'selected'; ?>>Admin</option>
                                        </select>
                                        <?php if (!$isSelf): ?>
                                            <button type="submit" class="btn-role">Save</button>
                                        <?php endif; ?>
                                    </form>

                                    <form method="POST" class="action-form">
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="user_id" value="<?php echo $uid; ?>">
                                        <input type="hidden" name="is_active" value="<?php echo ((int) $u['is_active'] === 1) ? 0 : 1; ?>">
                                        <button type="submit" class="btn-status" <?php if ($isSelf): ?>disabled<?php endif; ?>>
                                            <?php echo ((int) $u['is_active'] === 1) ? 'Deactivate' : 'Activate'; ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <?php
                            $url = '?page=' . $p;
                            if ($search !== '') {
                                $url .= '&q=' . urlencode($search);
                            }
                            if ($role !== '') {
                                $url .= '&role=' . urlencode($role);
                            }
                        ?>
                        <?php if ($p === $page): ?>
                            <span class="active"><?php echo $p; ?></span>
                        <?php else: ?>
                            <a href="<?php echo htmlspecialchars($url); ?>"><?php echo $p; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<script src="js/admin.js"></script>
</body>
</html>
