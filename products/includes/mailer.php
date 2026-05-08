<?php
require_once __DIR__ . '/../config/mail.php';

// ==================== CORE SMTP FUNCTIONS ====================

function smtpReadResponse($socket) {
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }
        $response .= $line;
        if (strlen($line) < 4 || $line[3] === ' ') {
            break;
        }
    }
    return $response;
}

function smtpExpectCode($socket, $expectedCodes) {
    $response = smtpReadResponse($socket);
    $statusCode = (int) substr($response, 0, 3);
    return in_array($statusCode, $expectedCodes, true);
}

function smtpSendCommand($socket, $command, $expectedCodes) {
    if (fwrite($socket, $command . "\r\n") === false) {
        return false;
    }
    return smtpExpectCode($socket, $expectedCodes);
}

function smtpEncodeHeader($value) {
    return '=?UTF-8?B?' . base64_encode((string) $value) . '?=';
}

function smtpNormalizeBody($body) {
    $body = str_replace(array("\r\n", "\r"), "\n", (string) $body);
    $body = preg_replace('/^\./m', '..', $body);
    return str_replace("\n", "\r\n", $body);
}

/**
 * Log mail activity for debugging and tracking
 */
function logMailActivity($recipient, $subject, $status, $error = '') {
    if (!MAIL_LOG_ENABLED) {
        return;
    }
    
    $logDir = __DIR__ . '/../../mail-logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/mail-' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] TO: $recipient | STATUS: $status | SUBJECT: $subject";
    if ($error) {
        $logEntry .= " | ERROR: $error";
    }
    $logEntry .= "\n";
    
    @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Core SMTP Mail Sending Function with retry logic
 */
function smtpSendMail($to, $subject, $body, $replyTo = MAIL_REPLY_TO, $username = MAIL_SMTP_USERNAME, $password = MAIL_SMTP_PASSWORD) {
    $to = trim((string) $to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email: $to";
        logMailActivity($to, $subject, 'FAILED', $error);
        return false;
    }
    $missingCredentials = array();
    $smtpUsername = MAIL_SMTP_USERNAME;
    $smtpPassword = MAIL_SMTP_PASSWORD;
    if (!is_string($smtpUsername) || trim($smtpUsername) === '') {
        $missingCredentials[] = 'MAIL_SMTP_USERNAME';
    }
    if (!is_string($smtpPassword) || trim($smtpPassword) === '') {
        $missingCredentials[] = 'MAIL_SMTP_PASSWORD';
    }
    if (!empty($missingCredentials)) {
        error_log('[smtpSendMail] Missing SMTP credentials in mail config constants: ' . implode(', ', $missingCredentials) . '. Set environment variables MAIL_SMTP_USERNAME and MAIL_SMTP_PASSWORD.');
        return false;
    }

    $attempts = 0;
    $maxAttempts = MAIL_RETRY_ATTEMPTS;

    while ($attempts < $maxAttempts) {
        $attempts++;
        
        $hostPrefix = MAIL_SMTP_ENCRYPTION === 'ssl' ? 'ssl://' : '';
        $remoteSocket = $hostPrefix . MAIL_SMTP_HOST . ':' . MAIL_SMTP_PORT;
        $socket = false;
        $errno = 0;
        $errstr = '';

        if (MAIL_SMTP_ENCRYPTION === 'ssl') {
            $sslContext = stream_context_create(array(
                'ssl' => array(
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                )
            ));
            $socket = @stream_socket_client($remoteSocket, $errno, $errstr, MAIL_SEND_TIMEOUT, STREAM_CLIENT_CONNECT, $sslContext);

            if (!$socket) {
                $sslContextRelaxed = stream_context_create(array(
                    'ssl' => array(
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    )
                ));
                $socket = @stream_socket_client($remoteSocket, $errno, $errstr, MAIL_SEND_TIMEOUT, STREAM_CLIENT_CONNECT, $sslContextRelaxed);
            }
        } else {
            $socket = @stream_socket_client($remoteSocket, $errno, $errstr, MAIL_SEND_TIMEOUT, STREAM_CLIENT_CONNECT);
        }
        
        if (!$socket) {
            if ($attempts >= $maxAttempts) {
                $error = "Connection failed (attempt $attempts/$maxAttempts): $errstr";
                logMailActivity($to, $subject, 'FAILED', $error);
                return false;
            }
            sleep(1); // Wait before retry
            continue;
        }

        stream_set_timeout($socket, MAIL_SEND_TIMEOUT);

        if (!smtpExpectCode($socket, array(220))) {
            fclose($socket);
            if ($attempts >= $maxAttempts) {
                logMailActivity($to, $subject, 'FAILED', 'No SMTP greeting');
                return false;
            }
            continue;
        }

        $hostName = preg_replace('/[^a-zA-Z0-9.-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        if ($hostName === '') {
            $hostName = 'localhost';
        }

        if (!smtpSendCommand($socket, 'EHLO ' . $hostName, array(250))) {
            if (!smtpSendCommand($socket, 'HELO ' . $hostName, array(250))) {
                fclose($socket);
                if ($attempts >= $maxAttempts) {
                    logMailActivity($to, $subject, 'FAILED', 'EHLO/HELO failed');
                    return false;
                }
                continue;
            }
        }

        if (!smtpSendCommand($socket, 'AUTH LOGIN', array(334))
            || !smtpSendCommand($socket, base64_encode($username), array(334))
            || !smtpSendCommand($socket, base64_encode($password), array(235))) {
            fclose($socket);
            if ($attempts >= $maxAttempts) {
                logMailActivity($to, $subject, 'FAILED', 'Authentication failed');
                return false;
            }
            sleep(1);
            continue;
        }

        if (!smtpSendCommand($socket, 'MAIL FROM:<' . MAIL_FROM_ADDRESS . '>', array(250))
            || !smtpSendCommand($socket, 'RCPT TO:<' . $to . '>', array(250, 251))
            || !smtpSendCommand($socket, 'DATA', array(354))) {
            fclose($socket);
            if ($attempts >= $maxAttempts) {
                logMailActivity($to, $subject, 'FAILED', 'MAIL FROM/RCPT TO failed');
                return false;
            }
            continue;
        }

        $headers = array(
            'Date: ' . date(DATE_RFC2822),
            'From: ' . smtpEncodeHeader(MAIL_FROM_NAME) . ' <' . MAIL_FROM_ADDRESS . '>',
            'Reply-To: ' . $replyTo,
            'To: <' . $to . '>',
            'Subject: ' . smtpEncodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            'X-Mailer: Accounts Bazar'
        );

        $payload = implode("\r\n", $headers) . "\r\n\r\n" . smtpNormalizeBody($body);
        if (fwrite($socket, $payload . "\r\n.\r\n") === false || !smtpExpectCode($socket, array(250))) {
            fclose($socket);
            if ($attempts >= $maxAttempts) {
                logMailActivity($to, $subject, 'FAILED', 'Data transmission failed');
                return false;
            }
            continue;
        }

        smtpSendCommand($socket, 'QUIT', array(221));
        fclose($socket);
        logMailActivity($to, $subject, 'SUCCESS');
        return true;
    }

    logMailActivity($to, $subject, 'FAILED', "Failed after $maxAttempts attempts");
    return false;
}

// ==================== EMAIL TEMPLATE FUNCTIONS ====================

/**
 * HTML Email template wrapper
 */
function getEmailTemplate($title, $content, $ctaText = '', $ctaLink = '') {
    $baseUrl = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'accountsbazar.com');
    $supportEmail = MAIL_REPLY_TO;
    
    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 0 auto; background: #fff; }
        .header { background: linear-gradient(135deg, #0f172a 60%, #0ea5e9); color: #fff; padding: 20px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 30px 20px; }
        .content p { margin: 10px 0; }
        .cta { text-align: center; margin: 30px 0; }
        .cta a { background: #0ea5e9; color: #fff; padding: 12px 30px; text-decoration: none; border-radius: 6px; display: inline-block; }
        .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #999; border-top: 1px solid #eee; }
        .footer a { color: #0ea5e9; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🛍️ Accounts Bazar</h1>
        </div>
        <div class="content">
            <h2>$title</h2>
            $content
HTML;
    
    if (!empty($ctaText) && !empty($ctaLink)) {
        $html .= "<div class=\"cta\"><a href=\"$ctaLink\">$ctaText</a></div>";
    }
    
    $html .= <<<HTML
        </div>
        <div class="footer">
            <p>&copy; Accounts Bazar | <a href="$baseUrl">Visit Website</a></p>
            <p>Need help? <a href="mailto:$supportEmail">Contact Support</a></p>
        </div>
    </div>
</body>
</html>
HTML;
    
    return $html;
}

// ==================== NOTIFICATION EMAIL FUNCTIONS ====================

/**
 * Send registration confirmation email
 */
function sendRegistrationEmail($email, $name, $username) {
    $baseUrl = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'accountsbazar.com');
    $loginLink = $baseUrl . '/login.php';
    
    $content = "<p>Dear $name,</p>";
    $content .= "<p>Welcome to <strong>Accounts Bazar</strong>! Your account has been successfully created.</p>";
    $content .= "<p><strong>Your Login Credentials:</strong></p>";
    $content .= "<ul>";
    $content .= "<li><strong>Email:</strong> $email</li>";
    $content .= "<li><strong>Username:</strong> $username</li>";
    $content .= "</ul>";
    $content .= "<p>You can now log in and start exploring our products.</p>";
    
    $body = getEmailTemplate('Welcome to Accounts Bazar!', $content, 'Login Now', $loginLink);
    
    return smtpSendMail($email, 'Welcome to Accounts Bazar – Account Created', $body);
}

/**
 * Send order confirmation email
 */
function sendOrderConfirmationEmail($email, $name, $orderId, $orderDetails) {
    $baseUrl = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'accountsbazar.com');
    $orderLink = $baseUrl . '/order-details.php?id=' . urlencode($orderId);
    
    $content = "<p>Dear $name,</p>";
    $content .= "<p>Thank you for your order! We've received your order and will process it shortly.</p>";
    $content .= "<p><strong>Order Details:</strong></p>";
    $content .= "<ul>";
    $content .= "<li><strong>Order ID:</strong> $orderId</li>";
    $content .= "<li><strong>Total Amount:</strong> " . $orderDetails['amount'] . "</li>";
    $content .= "<li><strong>Status:</strong> Pending</li>";
    $content .= "</ul>";
    $content .= "<p>We'll send you updates about your order status.</p>";
    
    $body = getEmailTemplate('Order Confirmation', $content, 'View Order', $orderLink);
    
    return smtpSendMail($email, 'Order Confirmation – Order #' . $orderId, $body);
}

/**
 * Send order status update email
 */
function sendOrderStatusEmail($email, $name, $orderId, $status, $message = '') {
    $baseUrl = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'accountsbazar.com');
    $orderLink = $baseUrl . '/order-details.php?id=' . urlencode($orderId);
    
    $statusMessages = array(
        'processing' => 'Your order is being processed',
        'shipped' => 'Your order has been shipped',
        'delivered' => 'Your order has been delivered',
        'cancelled' => 'Your order has been cancelled',
        'refunded' => 'Your order has been refunded'
    );
    
    $statusTitle = $statusMessages[$status] ?? 'Order Status Updated';
    
    $content = "<p>Dear $name,</p>";
    $content .= "<p><strong>$statusTitle</strong></p>";
    $content .= "<p><strong>Order ID:</strong> $orderId</p>";
    if (!empty($message)) {
        $content .= "<p><strong>Details:</strong> $message</p>";
    }
    
    $body = getEmailTemplate('Order Status Update', $content, 'View Order', $orderLink);
    
    return smtpSendMail($email, 'Order Status Update – Order #' . $orderId, $body);
}

/**
 * Send password reset email
 */
function sendPasswordResetEmail($email, $name, $resetLink) {
    $content = "<p>Dear $name,</p>";
    $content .= "<p>You requested to reset your password. Click the link below to create a new password:</p>";
    $content .= "<p><strong>Note:</strong> This link will expire in 1 hour.</p>";
    
    $body = getEmailTemplate('Password Reset Request', $content, 'Reset Password', $resetLink);
    
    return smtpSendMail($email, 'Password Reset Request', $body);
}

/**
 * Send support reply email
 */
function sendSupportReplyEmail($email, $name, $message, $threadLink) {
    $content = "<p>Dear $name,</p>";
    $content .= "<p>We have replied to your support message:</p>";
    $content .= "<div style=\"background: #f5f5f5; padding: 15px; border-left: 4px solid #0ea5e9; margin: 15px 0;\">";
    $content .= "<p>" . nl2br(htmlspecialchars($message)) . "</p>";
    $content .= "</div>";
    $content .= "<p>Click the link below to view the conversation:</p>";
    
    $body = getEmailTemplate('Support Reply', $content, 'View Conversation', $threadLink);
    
    return smtpSendMail($email, 'We Replied to Your Support Message', $body);
}

/**
 * Send admin notification email
 */
function sendAdminNotificationEmail($adminEmail, $subject, $message, $actionLink = '') {
    $content = "<p>Hello Admin,</p>";
    $content .= "<p><strong>Alert:</strong> $message</p>";
    if (!empty($actionLink)) {
        $content .= "<p><strong>Action Required:</strong> <a href=\"$actionLink\">Click here to take action</a></p>";
    }
    
    $body = getEmailTemplate('Admin Notification', $content);
    
    return smtpSendMail($adminEmail, '[ADMIN] ' . $subject, $body);
}

function ensureEmailQueueTable($conn) {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS email_queue (
            id INT AUTO_INCREMENT PRIMARY KEY,
            to_email VARCHAR(255) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body MEDIUMTEXT NOT NULL,
            attempts INT DEFAULT 0,
            status ENUM('pending','sending','sent','failed') DEFAULT 'pending',
            last_error TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            sent_at TIMESTAMP NULL DEFAULT NULL,
            INDEX idx_status (status),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function enqueueEmail($conn, $to, $subject, $body) {
    $to = trim((string) $to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    try {
        ensureEmailQueueTable($conn);
        $stmt = $conn->prepare('INSERT INTO email_queue (to_email, subject, body, status) VALUES (?, ?, ?, "pending")');
        if (!$stmt) {
            error_log('[enqueueEmail] prepare failed: ' . $conn->error);
            return false;
        }
        $stmt->bind_param('sss', $to, $subject, $body);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    } catch (Throwable $e) {
        error_log('[enqueueEmail] ' . $e->getMessage());
        return false;
    }
}

function processEmailQueue($conn, $limit = 20) {
    ensureEmailQueueTable($conn);

    $items = array();
    $sql = 'SELECT id, to_email, subject, body, attempts FROM email_queue WHERE status IN ("pending", "failed") AND attempts < 5 ORDER BY id ASC LIMIT ' . (int) $limit;
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $items[] = $row;
        }
    }

    $sent = 0;
    foreach ($items as $item) {
        $id = (int) $item['id'];

        $markSending = $conn->prepare('UPDATE email_queue SET status = "sending", attempts = attempts + 1 WHERE id = ?');
        $markSending->bind_param('i', $id);
        $markSending->execute();
        $markSending->close();

        $ok = smtpSendMail((string) $item['to_email'], (string) $item['subject'], (string) $item['body']);
        if ($ok) {
            $sent++;
            $done = $conn->prepare('UPDATE email_queue SET status = "sent", sent_at = NOW(), last_error = NULL WHERE id = ?');
            $done->bind_param('i', $id);
            $done->execute();
            $done->close();
        } else {
            $err = 'SMTP send failed';
            $fail = $conn->prepare('UPDATE email_queue SET status = "failed", last_error = ? WHERE id = ?');
            $fail->bind_param('si', $err, $id);
            $fail->execute();
            $fail->close();
        }
    }

    return array('queued' => count($items), 'sent' => $sent);
}
