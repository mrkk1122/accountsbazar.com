<?php
/**
 * Advanced Notification System
 * Handles both in-app and email notifications
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

class NotificationManager {
    private $conn;
    
    public function __construct() {
        $db = new Database();
        $this->conn = $db->getConnection();
        $this->ensureNotificationTables();
    }
    
    /**
     * Create notification tables if they don't exist
     */
    private function ensureNotificationTables() {
        // Notifications table
        $this->conn->query("
            CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                type VARCHAR(50) NOT NULL,
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                related_id VARCHAR(100),
                is_read BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NULL,
                INDEX idx_user_id (user_id),
                INDEX idx_type (type),
                INDEX idx_created_at (created_at),
                CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
        
        // Email notification preferences
        $this->conn->query("
            CREATE TABLE IF NOT EXISTS notification_preferences (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL UNIQUE,
                email_on_order BOOLEAN DEFAULT TRUE,
                email_on_shipment BOOLEAN DEFAULT TRUE,
                email_on_delivery BOOLEAN DEFAULT TRUE,
                email_on_support BOOLEAN DEFAULT TRUE,
                email_promotions BOOLEAN DEFAULT FALSE,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_prefs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
        
        // Notification queue for failed sends
        $this->conn->query("
            CREATE TABLE IF NOT EXISTS notification_queue (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                email VARCHAR(255),
                notification_type VARCHAR(50),
                subject VARCHAR(255),
                body LONGTEXT,
                status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
                retry_count INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                sent_at TIMESTAMP NULL,
                INDEX idx_status (status),
                INDEX idx_created_at (created_at)
            )
        ");
    }
    
    /**
     * Create an in-app notification
     */
    public function createNotification($userId, $type, $title, $message, $relatedId = null, $expiresAt = null) {
        $stmt = $this->conn->prepare("
            INSERT INTO notifications (user_id, type, title, message, related_id, expires_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->bind_param('isssss', $userId, $type, $title, $message, $relatedId, $expiresAt);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
    }
    
    /**
     * Send notification and optionally email
     */
    public function sendNotification($userId, $type, $title, $message, $relatedId = null, $emailData = null) {
        // Create in-app notification
        $this->createNotification($userId, $type, $title, $message, $relatedId);
        
        // Send email if data provided and user preferences allow
        if ($emailData && $this->shouldSendEmail($userId, $type)) {
            $this->queueEmailNotification($userId, $type, $emailData);
        }
        
        return true;
    }
    
    /**
     * Check user preferences for email notifications
     */
    private function shouldSendEmail($userId, $type) {
        $stmt = $this->conn->prepare("
            SELECT email_on_order, email_on_shipment, email_on_delivery, email_on_support 
            FROM notification_preferences 
            WHERE user_id = ?
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
        
        if ($result->num_rows === 0) {
            return true; // Default: send emails
        }
        
        $prefs = $result->fetch_assoc();
        
        $typeMap = array(
            'order' => 'email_on_order',
            'shipment' => 'email_on_shipment',
            'delivery' => 'email_on_delivery',
            'support' => 'email_on_support'
        );
        
        $prefKey = $typeMap[$type] ?? 'email_on_order';
        return (bool) ($prefs[$prefKey] ?? true);
    }
    
    /**
     * Queue email notification for processing
     */
    public function queueEmailNotification($userId, $type, $emailData) {
        $stmt = $this->conn->prepare("
            INSERT INTO notification_queue (user_id, email, notification_type, subject, body)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $email = $emailData['email'] ?? '';
        $subject = $emailData['subject'] ?? '';
        $body = $emailData['body'] ?? '';
        
        $stmt->bind_param('issss', $userId, $email, $type, $subject, $body);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
    }
    
    /**
     * Process queued notifications (to be called by cron/scheduler)
     */
    public function processQueuedNotifications() {
        $stmt = $this->conn->prepare("
            SELECT id, email, notification_type, subject, body, retry_count
            FROM notification_queue
            WHERE status = 'pending' AND retry_count < 3
            ORDER BY created_at ASC
            LIMIT 10
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
        
        $processed = 0;
        
        while ($row = $result->fetch_assoc()) {
            $id = (int) $row['id'];
            $email = (string) $row['email'];
            $subject = (string) $row['subject'];
            $body = (string) $row['body'];
            $retryCount = (int) $row['retry_count'];
            
            if (smtpSendMail($email, $subject, $body)) {
                // Mark as sent
                $updateStmt = $this->conn->prepare("
                    UPDATE notification_queue 
                    SET status = 'sent', sent_at = NOW() 
                    WHERE id = ?
                ");
                $updateStmt->bind_param('i', $id);
                $updateStmt->execute();
                $updateStmt->close();
                $processed++;
            } else {
                // Increment retry count
                $newRetryCount = $retryCount + 1;
                $updateStmt = $this->conn->prepare("
                    UPDATE notification_queue 
                    SET retry_count = ? 
                    WHERE id = ?
                ");
                $updateStmt->bind_param('ii', $newRetryCount, $id);
                $updateStmt->execute();
                $updateStmt->close();
            }
        }
        
        return $processed;
    }
    
    /**
     * Get user notifications
     */
    public function getUserNotifications($userId, $limit = 10, $unreadOnly = false) {
        $query = "SELECT * FROM notifications WHERE user_id = ?";
        if ($unreadOnly) {
            $query .= " AND is_read = FALSE";
        }
        $query .= " ORDER BY created_at DESC LIMIT ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('ii', $userId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
        
        $notifications = array();
        while ($row = $result->fetch_assoc()) {
            $notifications[] = $row;
        }
        
        return $notifications;
    }
    
    /**
     * Mark notification as read
     */
    public function markAsRead($notificationId) {
        $stmt = $this->conn->prepare("
            UPDATE notifications 
            SET is_read = TRUE 
            WHERE id = ?
        ");
        $stmt->bind_param('i', $notificationId);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
    }
    
    /**
     * Get unread notification count
     */
    public function getUnreadCount($userId) {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as count 
            FROM notifications 
            WHERE user_id = ? AND is_read = FALSE
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        return (int) ($row['count'] ?? 0);
    }
    
    /**
     * Order notification helpers
     */
    public function notifyOrderCreated($orderId, $userId, $userEmail, $userName) {
        $orderDetails = $this->getOrderDetails($orderId);
        $content = '<p>Dear ' . htmlspecialchars((string) $userName, ENT_QUOTES, 'UTF-8') . ',</p>';
        $content .= '<p>Your order has been received successfully.</p>';
        $content .= '<ul>';
        $content .= '<li><strong>Order ID:</strong> ' . htmlspecialchars((string) $orderId, ENT_QUOTES, 'UTF-8') . '</li>';
        $content .= '<li><strong>Total Amount:</strong> ' . htmlspecialchars((string) $orderDetails['amount'], ENT_QUOTES, 'UTF-8') . '</li>';
        $content .= '<li><strong>Status:</strong> Pending</li>';
        $content .= '</ul>';
        
        $emailData = array(
            'email' => $userEmail,
            'subject' => 'Order Confirmation – Order #' . $orderId,
            'body' => getEmailTemplate('Order Confirmation', $content, 'View Order', 'https://accountsbazar.com/profile.php')
        );
        
        return $this->sendNotification(
            $userId,
            'order',
            'Order Confirmed',
            'Your order #' . $orderId . ' has been received',
            $orderId,
            $emailData
        );
    }
    
    public function notifyOrderShipped($orderId, $userId, $userEmail, $userName, $trackingNumber = '') {
        $content = '<p>Dear ' . htmlspecialchars((string) $userName, ENT_QUOTES, 'UTF-8') . ',</p>';
        $content .= '<p>Your order has been shipped.</p>';
        $content .= '<ul>';
        $content .= '<li><strong>Order ID:</strong> ' . htmlspecialchars((string) $orderId, ENT_QUOTES, 'UTF-8') . '</li>';
        if ($trackingNumber !== '') {
            $content .= '<li><strong>Tracking Number:</strong> ' . htmlspecialchars((string) $trackingNumber, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        $content .= '</ul>';

        $emailData = array(
            'email' => $userEmail,
            'subject' => 'Your Order Has Been Shipped',
            'body' => getEmailTemplate('Order Shipped', $content, 'View Order', 'https://accountsbazar.com/profile.php')
        );
        
        return $this->sendNotification(
            $userId,
            'shipment',
            'Order Shipped',
            'Your order #' . $orderId . ' has been shipped' . ($trackingNumber ? ' (Tracking: ' . $trackingNumber . ')' : ''),
            $orderId,
            $emailData
        );
    }
    
    public function notifyOrderDelivered($orderId, $userId, $userEmail, $userName) {
        $content = '<p>Dear ' . htmlspecialchars((string) $userName, ENT_QUOTES, 'UTF-8') . ',</p>';
        $content .= '<p>Your order has been delivered successfully.</p>';
        $content .= '<p><strong>Order ID:</strong> ' . htmlspecialchars((string) $orderId, ENT_QUOTES, 'UTF-8') . '</p>';

        $emailData = array(
            'email' => $userEmail,
            'subject' => 'Your Order Has Been Delivered',
            'body' => getEmailTemplate('Order Delivered', $content, 'Shop Again', 'https://accountsbazar.com/shop.php')
        );
        
        return $this->sendNotification(
            $userId,
            'delivery',
            'Order Delivered',
            'Your order #' . $orderId . ' has been delivered',
            $orderId,
            $emailData
        );
    }
    
    /**
     * Helper to get order details (you may need to adjust based on your DB schema)
     */
    private function getOrderDetails($orderId) {
        $stmt = $this->conn->prepare("
            SELECT id, total_amount, status 
            FROM orders 
            WHERE id = ? 
            LIMIT 1
        ");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        return array(
            'amount' => $row['total_amount'] ?? '0.00',
            'status' => $row['status'] ?? 'pending'
        );
    }
    
    /**
     * Set user notification preferences
     */
    public function setPreferences($userId, $preferences) {
        $stmt = $this->conn->prepare("
            INSERT INTO notification_preferences 
            (user_id, email_on_order, email_on_shipment, email_on_delivery, email_on_support, email_promotions)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            email_on_order = VALUES(email_on_order),
            email_on_shipment = VALUES(email_on_shipment),
            email_on_delivery = VALUES(email_on_delivery),
            email_on_support = VALUES(email_on_support),
            email_promotions = VALUES(email_promotions)
        ");
        
        $onOrder = (int) ($preferences['email_on_order'] ?? 1);
        $onShipment = (int) ($preferences['email_on_shipment'] ?? 1);
        $onDelivery = (int) ($preferences['email_on_delivery'] ?? 1);
        $onSupport = (int) ($preferences['email_on_support'] ?? 1);
        $onPromo = (int) ($preferences['email_promotions'] ?? 0);
        
        $stmt->bind_param('iiiiii', $userId, $onOrder, $onShipment, $onDelivery, $onSupport, $onPromo);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
    }
}
