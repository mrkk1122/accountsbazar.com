<?php
/**
 * User Notifications API
 * Endpoints for managing user notifications and preferences
 */

session_start();
require_once 'products/config/config.php';
require_once 'products/includes/db.php';
require_once 'products/includes/notifications.php';

header('Content-Type: application/json');

function jsonResponse($payload, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

// Check if user is logged in
if (empty($_SESSION['user_id'])) {
    jsonResponse(array('success' => false, 'error' => 'Not authenticated'), 401);
}

$userId = (int) $_SESSION['user_id'];
$action = trim((string) ($_GET['action'] ?? ''));
$method = $_SERVER['REQUEST_METHOD'];

try {
    $notificationManager = new NotificationManager();
    
    switch ($action) {
        // Get user notifications
        case 'list':
            $limit = (int) ($_GET['limit'] ?? 10);
            $unreadOnly = (bool) ($_GET['unread_only'] ?? false);
            
            $notifications = $notificationManager->getUserNotifications($userId, min($limit, 50), $unreadOnly);
            
            jsonResponse(array(
                'success' => true,
                'count' => count($notifications),
                'notifications' => $notifications
            ));
            break;
        
        // Get unread count
        case 'unread_count':
            $count = $notificationManager->getUnreadCount($userId);
            
            jsonResponse(array(
                'success' => true,
                'unread_count' => $count
            ));
            break;
        
        // Mark notification as read
        case 'mark_read':
            if ($method !== 'POST') {
                jsonResponse(array('success' => false, 'error' => 'POST required'), 400);
            }
            
            $notificationId = (int) ($_POST['notification_id'] ?? 0);
            if ($notificationId <= 0) {
                jsonResponse(array('success' => false, 'error' => 'Invalid notification ID'), 400);
            }
            
            if ($notificationManager->markAsRead($notificationId)) {
                jsonResponse(array('success' => true, 'message' => 'Marked as read'));
            } else {
                jsonResponse(array('success' => false, 'error' => 'Failed to update'), 500);
            }
            break;
        
        // Get notification preferences
        case 'get_preferences':
            $db = new Database();
            $conn = $db->getConnection();
            
            $result = $conn->query(
                "SELECT email_on_order, email_on_shipment, email_on_delivery, email_on_support, email_promotions "
                . "FROM notification_preferences WHERE user_id = " . (int) $userId . " LIMIT 1"
            );

            if ($result && $result->num_rows > 0) {
                $prefs = $result->fetch_assoc();
                jsonResponse(array('success' => true, 'preferences' => $prefs));
            } else {
                // Return default preferences
                jsonResponse(array('success' => true, 'preferences' => array(
                    'email_on_order' => 1,
                    'email_on_shipment' => 1,
                    'email_on_delivery' => 1,
                    'email_on_support' => 1,
                    'email_promotions' => 0
                )));
            }
            break;
        
        // Update notification preferences
        case 'update_preferences':
            if ($method !== 'POST') {
                jsonResponse(array('success' => false, 'error' => 'POST required'), 400);
            }
            
            $preferences = array(
                'email_on_order' => (bool) ($_POST['email_on_order'] ?? 1),
                'email_on_shipment' => (bool) ($_POST['email_on_shipment'] ?? 1),
                'email_on_delivery' => (bool) ($_POST['email_on_delivery'] ?? 1),
                'email_on_support' => (bool) ($_POST['email_on_support'] ?? 1),
                'email_promotions' => (bool) ($_POST['email_promotions'] ?? 0)
            );
            
            if ($notificationManager->setPreferences($userId, $preferences)) {
                jsonResponse(array('success' => true, 'message' => 'Preferences updated'));
            } else {
                jsonResponse(array('success' => false, 'error' => 'Failed to update preferences'), 500);
            }
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
