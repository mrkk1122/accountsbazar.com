<?php
/**
 * OTP & Email Queue Test Script
 * 
 * Tests the complete OTP generation and email delivery pipeline
 * 
 * Usage: php test-otp-system.php [test_email]
 * Example: php test-otp-system.php user@example.com
 */

require_once __DIR__ . '/products/config/config.php';
require_once __DIR__ . '/products/includes/db.php';
require_once __DIR__ . '/products/includes/mailer.php';

$testEmail = $argv[1] ?? 'test-otp-' . time() . '@example.local';

echo "========================================\n";
echo "OTP & Email System Test\n";
echo "========================================\n\n";

try {
    echo "[TEST 1] Database Connection\n";
    $db = new Database();
    $conn = $db->getConnection();
    echo "  ✓ Connected to database\n\n";
    
    echo "[TEST 2] Create password_resets table\n";
    $conn->query(
        'CREATE TABLE IF NOT EXISTS `password_resets` (
            `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `email`      VARCHAR(255) NOT NULL,
            `otp_code`   VARCHAR(6)   NOT NULL,
            `expires_at` DATETIME     NOT NULL,
            `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    echo "  ✓ password_resets table ready\n\n";
    
    echo "[TEST 3] Create email_queue table\n";
    ensureEmailQueueTable($conn);
    echo "  ✓ email_queue table ready\n\n";
    
    echo "[TEST 4] Generate OTP\n";
    $otp = (string)random_int(100000, 999999);
    $expires = date('Y-m-d H:i:s', time() + 15 * 60);
    echo "  OTP Code: $otp\n";
    echo "  Expires: $expires\n\n";
    
    echo "[TEST 5] Store OTP in database\n";
    $stmt = $conn->prepare('INSERT INTO password_resets (email, otp_code, expires_at) VALUES (?, ?, ?)');
    $stmt->bind_param('sss', $testEmail, $otp, $expires);
    $ok = $stmt->execute();
    $stmt->close();
    
    if ($ok) {
        echo "  ✓ OTP stored in database\n";
        echo "  Email: $testEmail\n\n";
    } else {
        throw new Exception("Failed to insert OTP: " . $conn->error);
    }
    
    echo "[TEST 6] Generate HTML email body\n";
    $htmlBody = getEmailTemplate(
        'Password Reset OTP',
        '<p>Hello,</p><p>We received a password reset request.</p><p><strong>Your OTP: ' . htmlspecialchars($otp) . '</strong></p><p>Valid for 15 minutes.</p>'
    );
    echo "  ✓ Email template generated\n\n";
    
    echo "[TEST 7] Queue email for delivery\n";
    $subject = 'Accounts Bazar – Password Reset OTP';
    $queued = enqueueEmail($conn, $testEmail, $subject, $htmlBody);
    
    if ($queued) {
        echo "  ✓ Email queued successfully\n";
        echo "  Subject: $subject\n\n";
    } else {
        throw new Exception("Failed to queue email");
    }
    
    echo "[TEST 8] Try immediate SMTP delivery\n";
    $smtpResult = smtpSendMail($testEmail, $subject, $htmlBody);
    if ($smtpResult) {
        echo "  ✓ SMTP delivery successful!\n";
    } else {
        echo "  ✗ SMTP delivery failed (will retry via queue)\n";
    }
    echo "\n";
    
    echo "[TEST 9] Check email queue status\n";
    $result = $conn->query(
        "SELECT COUNT(*) as total, 
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
         FROM email_queue"
    );
    
    if ($result) {
        $row = $result->fetch_assoc();
        echo "  Email Queue Stats:\n";
        echo "    Total: " . (int)$row['total'] . "\n";
        echo "    Pending: " . (int)$row['pending'] . "\n";
        echo "    Sent: " . (int)$row['sent'] . "\n";
        echo "    Failed: " . (int)$row['failed'] . "\n\n";
    }
    
    echo "[TEST 10] Retrieve OTP from database\n";
    $chkStmt = $conn->prepare('SELECT otp_code FROM password_resets WHERE email = ? AND expires_at >= NOW() ORDER BY id DESC LIMIT 1');
    $chkStmt->bind_param('s', $testEmail);
    $chkStmt->execute();
    $chkRow = $chkStmt->get_result()->fetch_assoc();
    $chkStmt->close();
    
    if ($chkRow && $chkRow['otp_code'] === $otp) {
        echo "  ✓ OTP verified in database\n";
        echo "  Retrieved OTP: " . htmlspecialchars($chkRow['otp_code']) . "\n\n";
    } else {
        throw new Exception("OTP verification failed");
    }
    
    echo "[TEST 11] Check mail logs\n";
    $logFile = __DIR__ . '/mail-logs/mail-' . date('Y-m-d') . '.log';
    if (file_exists($logFile)) {
        $lines = file($logFile);
        $lastLines = array_slice($lines, -3);
        echo "  Last 3 log entries:\n";
        foreach ($lastLines as $line) {
            echo "    " . trim($line) . "\n";
        }
    } else {
        echo "  No log file yet (mail-logs may be empty)\n";
    }
    echo "\n";
    
    echo "[TEST 12] Process email queue\n";
    $processResult = processEmailQueue($conn, 3);
    echo "  Queue processing result:\n";
    echo "    Queued items: " . (int)$processResult['queued'] . "\n";
    echo "    Successfully sent: " . (int)$processResult['sent'] . "\n\n";
    
    $db->closeConnection();
    
    echo "========================================\n";
    echo "✓ ALL TESTS COMPLETED SUCCESSFULLY\n";
    echo "========================================\n\n";
    
    echo "Summary:\n";
    echo "• OTP generated and stored: $otp\n";
    echo "• Email queued to: $testEmail\n";
    echo "• Check queue status: http://localhost/admin/email-queue-debug.php\n";
    echo "• Manually process queue: php process-mail-queue.php\n";
    echo "• View logs: tail mail-logs/mail-*.log\n";
    
} catch (Throwable $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
