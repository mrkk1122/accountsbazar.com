<?php
/**
 * Process Queued Notifications
 * This script should be run periodically (every 5 minutes) via cron job
 * 
 * Cron setup:
 * */5 * * * * curl -s http://accountsbazar.com/cron/process-notifications.php > /dev/null
 */

require_once __DIR__ . '/../products/config/config.php';
require_once __DIR__ . '/../products/includes/db.php';
require_once __DIR__ . '/../products/includes/notifications.php';

try {
    $notificationManager = new NotificationManager();
    $processed = $notificationManager->processQueuedNotifications();
    
    // Log the result
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/notifications-cron.log';
    $logEntry = date('Y-m-d H:i:s') . " - Processed: $processed notifications\n";
    @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    
    // Return success
    http_response_code(200);
    echo "OK: Processed $processed notifications";
    
} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage();
    
    // Log error
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/notifications-cron.log';
    $logEntry = date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
    @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}
