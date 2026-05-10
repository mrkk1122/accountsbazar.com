<?php
/**
 * Admin Notifications API
 * For admin to send notifications to users
 */

session_start();
require_once __DIR__ . '/../../products/config/config.php';
require_once __DIR__ . '/../../products/includes/db.php';
require_once __DIR__ . '/../../products/includes/notifications.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

function jsonResponse($payload, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

// Check if admin is logged in
if (empty($_SESSION['admin_id']) || $_SESSION['user_type'] !== 'admin') {
    jsonResponse(array('success' => false, 'error' => 'Admin access required'), 403);
}

$action = trim((string) ($_GET['action'] ?? ''));
$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = new Database();
    $conn = $db->getConnection();
    $notificationManager = new NotificationManager();
    
    switch ($action) {
        // Send notification to specific user
        case 'send_to_user':
            if ($method !== 'POST') {
                jsonResponse(array('success' => false, 'error' => 'POST required'), 400);
            }
            
            $userId = (int) ($_POST['user_id'] ?? 0);
            $type = trim((string) ($_POST['type'] ?? 'alert'));
            $title = trim((string) ($_POST['title'] ?? ''));
            $message = trim((string) ($_POST['message'] ?? ''));
            $sendEmail = (bool) ($_POST['send_email'] ?? false);
            
            if ($userId <= 0 || empty($title) || empty($message)) {
                jsonResponse(array('success' => false, 'error' => 'Missing required fields'), 400);
            }
            
            // Get user email
            $result = $conn->query("SELECT email, first_name FROM users WHERE id = " . (int) $userId . " LIMIT 1");
            if (!$result || $result->num_rows === 0) {
                jsonResponse(array('success' => false, 'error' => 'User not found'), 404);
            }

            $user = $result->fetch_assoc();
            $userEmail = (string) ($user['email'] ?? '');
            $userName = (string) ($user['first_name'] ?? '');
            
            // Create notification
            $emailData = null;
            if ($sendEmail) {
                $emailData = array(
                    'email' => $userEmail,
                    'subject' => $title,
                    'body' => getEmailTemplate($title, '<p>' . nl2br(htmlspecialchars($message)) . '</p>')
                );
            }
            
            if ($notificationManager->sendNotification($userId, $type, $title, $message, null, $emailData)) {
                jsonResponse(array('success' => true, 'message' => 'Notification sent'));
            } else {
                jsonResponse(array('success' => false, 'error' => 'Failed to send'), 500);
            }
            break;
        
        // Send notification to multiple users
        case 'send_bulk':
            if ($method !== 'POST') {
                jsonResponse(array('success' => false, 'error' => 'POST required'), 400);
            }
            
            $userIds = array_map('intval', (array) ($_POST['user_ids'] ?? array()));
            $type = trim((string) ($_POST['type'] ?? 'alert'));
            $title = trim((string) ($_POST['title'] ?? ''));
            $message = trim((string) ($_POST['message'] ?? ''));
            $sendEmail = (bool) ($_POST['send_email'] ?? false);
            
            if (empty($userIds) || empty($title) || empty($message)) {
                jsonResponse(array('success' => false, 'error' => 'Missing required fields'), 400);
            }
            
            $sent = 0;
            foreach ($userIds as $userId) {
                $result = $conn->query("SELECT email, first_name FROM users WHERE id = " . (int) $userId . " LIMIT 1");
                if (!$result || $result->num_rows === 0) {
                    continue;
                }

                $user = $result->fetch_assoc();
                $userEmail = (string) ($user['email'] ?? '');
                
                $emailData = null;
                if ($sendEmail) {
                    $emailData = array(
                        'email' => $userEmail,
                        'subject' => $title,
                        'body' => getEmailTemplate($title, '<p>' . nl2br(htmlspecialchars($message)) . '</p>')
                    );
                }
                
                if ($notificationManager->sendNotification($userId, $type, $title, $message, null, $emailData)) {
                    $sent++;
                }
            }
            
            jsonResponse(array('success' => true, 'sent_count' => $sent, 'total' => count($userIds)));
            break;
        
        // Send to all users
        case 'send_all':
            if ($method !== 'POST') {
                jsonResponse(array('success' => false, 'error' => 'POST required'), 400);
            }
            
            $type = trim((string) ($_POST['type'] ?? 'alert'));
            $title = trim((string) ($_POST['title'] ?? ''));
            $message = trim((string) ($_POST['message'] ?? ''));
            $sendEmail = (bool) ($_POST['send_email'] ?? false);
            
            if (empty($title) || empty($message)) {
                jsonResponse(array('success' => false, 'error' => 'Missing required fields'), 400);
            }
            
            // Get all active users
            $result = $conn->query("SELECT id, email, first_name FROM users WHERE is_active = 1");
            $sent = 0;
            
            while ($user = $result->fetch_assoc()) {
                $userId = (int) $user['id'];
                $userEmail = (string) $user['email'];
                
                $emailData = null;
                if ($sendEmail) {
                    $emailData = array(
                        'email' => $userEmail,
                        'subject' => $title,
                        'body' => getEmailTemplate($title, '<p>' . nl2br(htmlspecialchars($message)) . '</p>')
                    );
                }
                
                if ($notificationManager->sendNotification($userId, $type, $title, $message, null, $emailData)) {
                    $sent++;
                }
            }
            
            jsonResponse(array('success' => true, 'sent_count' => $sent, 'message' => "Sent to $sent users"));
            break;
        
        // Get notification queue status
        case 'queue_status':
            $stats = $conn->query("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
                FROM notification_queue
            ")->fetch_assoc();
            
            jsonResponse(array('success' => true, 'stats' => $stats));
            break;
        
        // Process pending notifications
        case 'process_queue':
            if ($method !== 'POST') {
                jsonResponse(array('success' => false, 'error' => 'POST required'), 400);
            }
            
            $processed = $notificationManager->processQueuedNotifications();
            
            jsonResponse(array(
                'success' => true,
                'processed' => $processed,
                'message' => "Processed $processed notifications"
            ));
            break;
        
        default:
            jsonResponse(array('success' => false, 'error' => 'Invalid action'), 400);
    }
    
} catch (Exception $e) {
    jsonResponse(array(
        'success' => false,
        'error' => 'Server error',
        'message' => MAIL_DEBUG_MODE ? $e->getMessage() : ''
    ), 500);
}
