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

// Handle sending
$sent = false;
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sendTo = trim((string) ($_POST['send_to'] ?? 'all'));
    $type = trim((string) ($_POST['type'] ?? 'alert'));
    $title = trim((string) ($_POST['title'] ?? ''));
    $content = trim((string) ($_POST['content'] ?? ''));
    $sendEmail = (bool) ($_POST['send_email'] ?? false);
    
    if (empty($title) || empty($content)) {
        $message = 'Title and content are required.';
    } else {
        $notificationManager = new NotificationManager();
        $sendCount = 0;
        
        if ($sendTo === 'all') {
            // Send to all users
            $result = $conn->query("SELECT id, email, first_name FROM users WHERE is_active = 1");
            while ($user = $result->fetch_assoc()) {
                $userId = (int) $user['id'];
                $userEmail = (string) $user['email'];
                
                $emailData = null;
                if ($sendEmail) {
                    $emailData = array(
                        'email' => $userEmail,
                        'subject' => $title,
                        'body' => getEmailTemplate($title, '<p>' . nl2br(htmlspecialchars($content)) . '</p>')
                    );
                }
                
                if ($notificationManager->sendNotification($userId, $type, $title, $content, null, $emailData)) {
                    $sendCount++;
                }
            }
        } else {
            // Send to single user
            $userId = (int) $sendTo;
            $stmt = $conn->prepare("SELECT email, first_name FROM users WHERE id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $stmt->close();
            
            if ($result->num_rows > 0) {
                $user = $result->fetch_assoc();
                $userEmail = (string) $user['email'];
                
                $emailData = null;
                if ($sendEmail) {
                    $emailData = array(
                        'email' => $userEmail,
                        'subject' => $title,
                        'body' => getEmailTemplate($title, '<p>' . nl2br(htmlspecialchars($content)) . '</p>')
                    );
                }
                
                if ($notificationManager->sendNotification($userId, $type, $title, $content, null, $emailData)) {
                    $sendCount++;
                }
            }
        }
        
        if ($sendCount > 0) {
            $sent = true;
            $message = "Notification sent to $sendCount user(s)!";
        } else {
            $message = 'Failed to send notification.';
        }
    }
}

// Get list of users
$users = $conn->query("SELECT id, CONCAT(first_name, ' ', last_name) as name, email FROM users WHERE is_active = 1 ORDER BY first_name LIMIT 100") or array();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send Notification - Admin | Accounts Bazar</title>
    <link rel="stylesheet" href="css/admin.css">
    <style>
        .send-container { max-width: 700px; margin: 0 auto; padding: 20px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #0f172a; }
        .form-group input,
        .form-group select,
        .form-group textarea { width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-family: Arial, sans-serif; }
        .form-group textarea { min-height: 200px; resize: vertical; }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus { outline: none; border-color: #0ea5e9; box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1); }
        .checkbox-group { display: flex; align-items: center; gap: 10px; }
        .checkbox-group input[type="checkbox"] { width: 18px; height: 18px; }
        .btn { padding: 12px 24px; background: #0ea5e9; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; }
        .btn:hover { background: #0b9dd9; }
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
        .back-link { display: inline-block; margin-bottom: 20px; color: #0ea5e9; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="send-container">
        <a href="notifications.php" class="back-link">← Back to Notifications</a>
        
        <h1>📤 Send Notification</h1>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $sent ? 'success' : 'error'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label for="send_to">Send To:</label>
                <select name="send_to" id="send_to" required>
                    <option value="all">All Users (<?php echo count($users); ?>)</option>
                    <option value="" disabled>──────────────────</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo $user['id']; ?>">
                            <?php echo htmlspecialchars($user['name'] . ' (' . $user['email'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="type">Notification Type:</label>
                <select name="type" id="type" required>
                    <option value="alert">Alert</option>
                    <option value="info">Info</option>
                    <option value="promo">Promotion</option>
                    <option value="update">Update</option>
                    <option value="order">Order</option>
                    <option value="support">Support</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="title">Title:</label>
                <input type="text" name="title" id="title" placeholder="e.g., New Product Available" required>
            </div>
            
            <div class="form-group">
                <label for="content">Content:</label>
                <textarea name="content" id="content" placeholder="Enter notification message..." required></textarea>
            </div>
            
            <div class="form-group checkbox-group">
                <input type="checkbox" name="send_email" id="send_email" value="1">
                <label for="send_email" style="margin: 0;">Also send as email</label>
            </div>
            
            <button type="submit" class="btn">✉ Send Notification</button>
        </form>
    </div>
</body>
</html>
