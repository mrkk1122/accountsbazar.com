<?php
/**
 * Email Queue Processor
 * 
 * This script processes pending emails from the email_queue table.
 * Can be run via cron or manually from CLI.
 * 
 * Usage: 
 *   php process-mail-queue.php [limit]
 *   php process-mail-queue.php 10  (process 10 emails)
 */

require_once __DIR__ . '/products/config/config.php';
require_once __DIR__ . '/products/includes/db.php';
require_once __DIR__ . '/products/includes/mailer.php';

$limit = isset($argv[1]) ? (int)$argv[1] : 20;
$limit = max(1, min($limit, 100)); // Clamp between 1-100

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Ensure table exists
    ensureEmailQueueTable($conn);
    
    // Get pending/failed emails
    $sql = 'SELECT id, to_email, subject, body, attempts FROM email_queue 
            WHERE status IN ("pending", "failed") AND attempts < 5 
            ORDER BY id ASC LIMIT ' . $limit;
    
    $result = $conn->query($sql);
    $items = array();
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
    }
    
    if (empty($items)) {
        echo "[INFO] No pending emails to process.\n";
        $db->closeConnection();
        exit(0);
    }
    
    echo "[INFO] Processing " . count($items) . " pending email(s)...\n";
    $sent = 0;
    $failed = 0;
    
    foreach ($items as $item) {
        $id = (int)$item['id'];
        $to = (string)$item['to_email'];
        $subject = (string)$item['subject'];
        $body = (string)$item['body'];
        $attempts = (int)$item['attempts'];
        
        echo "[PROCESSING] ID=$id TO=$to (Attempt " . ($attempts + 1) . "/5)\n";
        
        // Mark as sending
        $updateStmt = $conn->prepare('UPDATE email_queue SET status = "sending", attempts = attempts + 1 WHERE id = ?');
        $updateStmt->bind_param('i', $id);
        $updateStmt->execute();
        $updateStmt->close();
        
        // Try to send
        $ok = smtpSendMail($to, $subject, $body);
        
        if ($ok) {
            $sent++;
            echo "  ✓ SENT via SMTP\n";
            
            $doneStmt = $conn->prepare('UPDATE email_queue SET status = "sent", sent_at = NOW(), last_error = NULL WHERE id = ?');
            $doneStmt->bind_param('i', $id);
            $doneStmt->execute();
            $doneStmt->close();
        } else {
            $failed++;
            echo "  ✗ FAILED (will retry)\n";
            
            $err = 'SMTP send failed on attempt ' . ($attempts + 1);
            $failStmt = $conn->prepare('UPDATE email_queue SET status = "failed", last_error = ? WHERE id = ?');
            $failStmt->bind_param('si', $err, $id);
            $failStmt->execute();
            $failStmt->close();
        }
    }
    
    $db->closeConnection();
    
    echo "\n[SUMMARY] Sent: $sent | Failed: $failed\n";
    exit(0);
    
} catch (Throwable $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
