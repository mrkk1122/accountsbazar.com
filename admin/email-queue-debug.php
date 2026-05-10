<?php
/**
 * Email Queue Debugger (Admin Only)
 * View and manage pending emails in the email_queue table
 */

session_start();

// Only allow from localhost or admin with specific debug token
$isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost'], true);
$debugToken = $_GET['debug'] ?? '';
$adminAuth = (isset($_SESSION['admin_user']) && !empty($_SESSION['admin_user']));

if (!($isLocalhost || $debugToken === 'accounts_bazar_debug_2026' || $adminAuth)) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/products/config/config.php';
require_once __DIR__ . '/products/includes/db.php';
require_once __DIR__ . '/products/includes/mailer.php';

$action = $_GET['action'] ?? '';
$message = '';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    ensureEmailQueueTable($conn);
    
    // Retry a specific email
    if ($action === 'retry' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $conn->prepare('SELECT id, to_email, subject, body FROM email_queue WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($row) {
            $ok = smtpSendMail($row['to_email'], $row['subject'], $row['body']);
            if ($ok) {
                $upStmt = $conn->prepare('UPDATE email_queue SET status = "sent", sent_at = NOW(), last_error = NULL WHERE id = ?');
                $upStmt->bind_param('i', $id);
                $upStmt->execute();
                $upStmt->close();
                $message = '✓ Email sent successfully!';
            } else {
                $message = '✗ Email send failed. Check mail logs.';
            }
        }
        header('Location: ?page=queue');
        exit;
    }
    
    // Delete a queued email
    if ($action === 'delete' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $conn->prepare('DELETE FROM email_queue WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $message = '✓ Email deleted!';
        header('Location: ?page=queue');
        exit;
    }
    
    // Get queue stats
    $statsStmt = $conn->prepare(
        'SELECT 
            SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = "sending" THEN 1 ELSE 0 END) as sending,
            SUM(CASE WHEN status = "sent" THEN 1 ELSE 0 END) as sent,
            SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed
        FROM email_queue'
    );
    $statsStmt->execute();
    $stats = $statsStmt->get_result()->fetch_assoc();
    $statsStmt->close();
    
    // Get recent emails
    $limit = $_GET['limit'] ?? 50;
    $limit = (int)$limit;
    $limit = max(10, min($limit, 500));
    
    $sql = 'SELECT id, to_email, subject, status, attempts, last_error, created_at, sent_at 
            FROM email_queue 
            ORDER BY id DESC 
            LIMIT ' . $limit;
    
    $result = $conn->query($sql);
    $emails = array();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $emails[] = $row;
        }
    }
    
    $db->closeConnection();

} catch (Throwable $e) {
    $message = '✗ Error: ' . $e->getMessage();
    $emails = array();
    $stats = array('pending' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Queue Debugger</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { margin-bottom: 20px; color: #333; }
        .alert {
            padding: 12px 16px;
            margin-bottom: 20px;
            border-radius: 8px;
            font-weight: 600;
        }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .stat-box {
            background: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .stat-box .number {
            font-size: 32px;
            font-weight: bold;
            color: #0066cc;
        }
        .stat-box .label {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
            text-transform: uppercase;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        th {
            background: #0066cc;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
        }
        td {
            padding: 12px;
            border-bottom: 1px solid #eee;
            font-size: 13px;
        }
        tr:last-child td { border-bottom: none; }
        tr:hover { background: #f9f9f9; }
        
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 11px;
        }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-sending { background: #cce5ff; color: #004085; }
        .status-sent { background: #d4edda; color: #155724; }
        .status-failed { background: #f8d7da; color: #721c24; }
        
        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin-right: 5px;
        }
        .btn-retry { background: #28a745; color: white; }
        .btn-retry:hover { background: #218838; }
        .btn-delete { background: #dc3545; color: white; }
        .btn-delete:hover { background: #c82333; }
        
        .text-truncate {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📧 Email Queue Debugger</h1>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo strpos($message, '✓') === 0 ? 'success' : 'error'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <div class="stats">
            <div class="stat-box">
                <div class="number"><?php echo (int)($stats['pending'] ?? 0); ?></div>
                <div class="label">Pending</div>
            </div>
            <div class="stat-box">
                <div class="number"><?php echo (int)($stats['sending'] ?? 0); ?></div>
                <div class="label">Sending</div>
            </div>
            <div class="stat-box">
                <div class="number"><?php echo (int)($stats['sent'] ?? 0); ?></div>
                <div class="label">Sent</div>
            </div>
            <div class="stat-box">
                <div class="number"><?php echo (int)($stats['failed'] ?? 0); ?></div>
                <div class="label">Failed</div>
            </div>
        </div>
        
        <h2 style="margin-bottom: 15px; font-size: 18px;">Recent Emails (Latest <?php echo $limit; ?>)</h2>
        
        <?php if (empty($emails)): ?>
            <p style="padding: 20px; background: white; text-align: center; color: #666; border-radius: 8px;">
                No emails in queue
            </p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>To</th>
                        <th>Subject</th>
                        <th>Status</th>
                        <th>Attempts</th>
                        <th>Error</th>
                        <th>Created</th>
                        <th>Sent</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($emails as $email): ?>
                        <tr>
                            <td><?php echo (int)$email['id']; ?></td>
                            <td><?php echo htmlspecialchars($email['to_email']); ?></td>
                            <td><span class="text-truncate"><?php echo htmlspecialchars($email['subject']); ?></span></td>
                            <td>
                                <span class="status-badge status-<?php echo htmlspecialchars($email['status']); ?>">
                                    <?php echo htmlspecialchars($email['status']); ?>
                                </span>
                            </td>
                            <td><?php echo (int)$email['attempts']; ?>/5</td>
                            <td><span class="text-truncate"><?php echo $email['last_error'] ? htmlspecialchars($email['last_error']) : '-'; ?></span></td>
                            <td><?php echo htmlspecialchars(substr($email['created_at'] ?? '', 0, 19)); ?></td>
                            <td><?php echo $email['sent_at'] ? htmlspecialchars(substr($email['sent_at'], 0, 19)) : '-'; ?></td>
                            <td>
                                <?php if ($email['status'] !== 'sent'): ?>
                                    <a href="?action=retry&id=<?php echo (int)$email['id']; ?>" class="btn btn-retry" onclick="return confirm('Retry sending this email?');">Retry</a>
                                <?php endif; ?>
                                <a href="?action=delete&id=<?php echo (int)$email['id']; ?>" class="btn btn-delete" onclick="return confirm('Delete this email?');">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        
        <p style="margin-top: 30px; padding: 20px; background: white; border-radius: 8px; border-left: 4px solid #0066cc; color: #666; font-size: 12px;">
            <strong>Usage:</strong><br>
            • View queue: <code><?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?></code><br>
            • Access via: localhost OR admin login OR debug token in URL<br>
            • Cron setup: <code>*/5 * * * * php /path/to/process-mail-queue.php</code>
        </p>
    </div>
</body>
</html>
