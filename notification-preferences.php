<?php
session_start();
require_once 'products/config/config.php';
require_once 'products/includes/db.php';
require_once 'products/includes/notifications.php';

// Check if user is logged in
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$db = new Database();
$conn = $db->getConnection();
$notificationManager = new NotificationManager();

$message = '';
$messageType = 'success';

// Get user info
$userStmt = $conn->prepare("SELECT first_name, last_name, email FROM users WHERE id = ?");
$userStmt->bind_param('i', $userId);
$userStmt->execute();
$userResult = $userStmt->get_result();
$userStmt->close();
$user = $userResult->fetch_assoc();

// Get current preferences
$stmt = $conn->prepare("
    SELECT email_on_order, email_on_shipment, email_on_delivery, email_on_support, email_promotions
    FROM notification_preferences
    WHERE user_id = ?
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

$preferences = array(
    'email_on_order' => 1,
    'email_on_shipment' => 1,
    'email_on_delivery' => 1,
    'email_on_support' => 1,
    'email_promotions' => 0
);

if ($result->num_rows > 0) {
    $preferences = $result->fetch_assoc();
}

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPrefs = array(
        'email_on_order' => (bool) ($_POST['email_on_order'] ?? 0),
        'email_on_shipment' => (bool) ($_POST['email_on_shipment'] ?? 0),
        'email_on_delivery' => (bool) ($_POST['email_on_delivery'] ?? 0),
        'email_on_support' => (bool) ($_POST['email_on_support'] ?? 0),
        'email_promotions' => (bool) ($_POST['email_promotions'] ?? 0)
    );
    
    if ($notificationManager->setPreferences($userId, $newPrefs)) {
        $message = 'Your notification preferences have been saved!';
        $preferences = $newPrefs;
    } else {
        $message = 'Failed to update preferences. Please try again.';
        $messageType = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification Settings | Accounts Bazar</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/mobile.css">
    <style>
        .preferences-container { max-width: 600px; margin: 40px auto; padding: 20px; }
        .pref-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 30px; box-shadow: 0 8px 30px rgba(15,23,42,0.08); }
        .pref-card h1 { font-size: 24px; color: #0f172a; margin: 0 0 10px; }
        .pref-subtitle { color: #64748b; font-size: 14px; margin-bottom: 30px; }
        .pref-group { margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid #e2e8f0; }
        .pref-group:last-of-type { border-bottom: none; }
        .pref-item { display: flex; align-items: center; gap: 12px; margin-bottom: 15px; }
        .pref-item input[type="checkbox"] { width: 20px; height: 20px; cursor: pointer; }
        .pref-item label { flex: 1; cursor: pointer; margin: 0; }
        .pref-item label .title { display: block; font-weight: 600; color: #0f172a; margin-bottom: 4px; }
        .pref-item label .desc { font-size: 13px; color: #64748b; }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
        .btn { padding: 12px 24px; background: #0ea5e9; color: #fff; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; }
        .btn:hover { background: #0b9dd9; }
        .back-link { display: inline-block; margin-bottom: 20px; color: #0ea5e9; text-decoration: none; font-weight: 600; }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="preferences-container">
        <a href="profile.php" class="back-link">← Back to Profile</a>
        
        <div class="pref-card">
            <h1>🔔 Notification Preferences</h1>
            <p class="pref-subtitle">Manage how you receive notifications about your orders and account</p>
            
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="pref-group">
                    <h3 style="color: #0f172a; margin-top: 0;">Order Notifications</h3>
                    
                    <div class="pref-item">
                        <input type="checkbox" id="email_on_order" name="email_on_order" value="1" <?php echo $preferences['email_on_order'] ? 'checked' : ''; ?>>
                        <label for="email_on_order">
                            <span class="title">Order Confirmation</span>
                            <span class="desc">Receive email when your order is confirmed</span>
                        </label>
                    </div>
                    
                    <div class="pref-item">
                        <input type="checkbox" id="email_on_shipment" name="email_on_shipment" value="1" <?php echo $preferences['email_on_shipment'] ? 'checked' : ''; ?>>
                        <label for="email_on_shipment">
                            <span class="title">Shipment Updates</span>
                            <span class="desc">Get notified when your order ships</span>
                        </label>
                    </div>
                    
                    <div class="pref-item">
                        <input type="checkbox" id="email_on_delivery" name="email_on_delivery" value="1" <?php echo $preferences['email_on_delivery'] ? 'checked' : ''; ?>>
                        <label for="email_on_delivery">
                            <span class="title">Delivery Confirmation</span>
                            <span class="desc">Receive confirmation when order is delivered</span>
                        </label>
                    </div>
                </div>
                
                <div class="pref-group">
                    <h3 style="color: #0f172a; margin-top: 0;">Support & Communications</h3>
                    
                    <div class="pref-item">
                        <input type="checkbox" id="email_on_support" name="email_on_support" value="1" <?php echo $preferences['email_on_support'] ? 'checked' : ''; ?>>
                        <label for="email_on_support">
                            <span class="title">Support Replies</span>
                            <span class="desc">Get notified about support chat responses</span>
                        </label>
                    </div>
                    
                    <div class="pref-item">
                        <input type="checkbox" id="email_promotions" name="email_promotions" value="1" <?php echo $preferences['email_promotions'] ? 'checked' : ''; ?>>
                        <label for="email_promotions">
                            <span class="title">Promotional Offers</span>
                            <span class="desc">Receive emails about special offers and promotions</span>
                        </label>
                    </div>
                </div>
                
                <button type="submit" class="btn">💾 Save Preferences</button>
            </form>
        </div>
    </div>
</body>
</html>
