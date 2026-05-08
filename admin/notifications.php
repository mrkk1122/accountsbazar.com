<?php
session_start();
require_once '../products/config/config.php';
require_once '../products/includes/db.php';
require_once '../products/includes/notifications.php';
require_once 'includes/auth.php';

// Check if admin is logged in
if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();
$notificationManager = new NotificationManager();

// Get stats
$stats = $conn->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
    FROM notification_queue
")->fetch_assoc();

// Get recent notifications
$recentNotifications = $conn->query("
    SELECT * FROM notification_queue 
    ORDER BY created_at DESC 
    LIMIT 20
") or array();

// Handle actions
$action = trim((string) ($_GET['action'] ?? ''));
if ($action === 'process_queue' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $processed = $notificationManager->processQueuedNotifications();
    header('Location: notifications.php?message=' . urlencode("Processed $processed notifications"));
    exit;
}

$message = trim((string) ($_GET['message'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Admin | Accounts Bazar</title>
    <link rel="stylesheet" href="css/admin.css">
    <style>
        .notifications-container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; text-align: center; }
        .stat-card h3 { margin: 0 0 10px; color: #0f172a; font-size: 14px; text-transform: uppercase; }
        .stat-card .number { font-size: 32px; font-weight: bold; color: #0ea5e9; }
        .stat-card.pending .number { color: #f59e0b; }
        .stat-card.failed .number { color: #ef4444; }
        .action-buttons { margin-bottom: 20px; display: flex; gap: 10px; }
        .btn { padding: 10px 20px; background: #0ea5e9; color: #fff; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn:hover { background: #0b9dd9; }
        .btn-danger { background: #ef4444; }
        .btn-danger:hover { background: #dc2626; }
        .table-container { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; padding: 12px; text-align: left; font-weight: 600; color: #0f172a; border-bottom: 1px solid #e2e8f0; }
        td { padding: 12px; border-bottom: 1px solid #e2e8f0; }
        tr:hover { background: #f8fafc; }
        .badge { display: inline-block; padding: 4px 12px; border-radius: 4px; font-size: 12px; font-weight: 600; }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-sent { background: #d1fae5; color: #065f46; }
        .badge-failed { background: #fee2e2; color: #991b1b; }
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
    </style>
</head>
<body>
    <div class="notifications-container">
        <h1>📧 Notification Management</h1>
        
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total</h3>
                <div class="number"><?php echo $stats['total'] ?? 0; ?></div>
            </div>
            <div class="stat-card pending">
                <h3>Pending</h3>
                <div class="number"><?php echo $stats['pending'] ?? 0; ?></div>
            </div>
            <div class="stat-card">
                <h3>Sent</h3>
                <div class="number"><?php echo $stats['sent'] ?? 0; ?></div>
            </div>
            <div class="stat-card failed">
                <h3>Failed</h3>
                <div class="number"><?php echo $stats['failed'] ?? 0; ?></div>
            </div>
        </div>
        
        <div class="action-buttons">
            <form method="POST" action="notifications.php?action=process_queue" style="display: inline;">
                <button type="submit" class="btn">▶ Process Pending Notifications</button>
            </form>
            <a href="notifications-send.php" class="btn">✉ Send New Notification</a>
        </div>
        
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Email</th>
                        <th>Type</th>
                        <th>Subject</th>
                        <th>Status</th>
                        <th>Retries</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (is_array($recentNotifications)): ?>
                        <?php foreach ($recentNotifications as $notif): ?>
                            <tr>
                                <td><?php echo $notif['id']; ?></td>
                                <td><?php echo htmlspecialchars(substr($notif['email'], 0, 40)); ?></td>
                                <td><?php echo htmlspecialchars($notif['notification_type']); ?></td>
                                <td><?php echo htmlspecialchars(substr($notif['subject'], 0, 50)); ?></td>
                                <td><span class="badge badge-<?php echo $notif['status']; ?>"><?php echo ucfirst($notif['status']); ?></span></td>
                                <td><?php echo $notif['retry_count']; ?>/3</td>
                                <td><?php echo date('M d, H:i', strtotime($notif['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
