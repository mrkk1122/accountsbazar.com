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

function smtpGetStatusCode($response) {
    return (int) substr((string) $response, 0, 3);
}

function smtpSendCommandWithResponse($socket, $command) {
    if (fwrite($socket, $command . "\r\n") === false) {
        return array(false, '');
    }
    $response = smtpReadResponse($socket);
    return array(true, $response);
}

function smtpAuth($socket, $smtpUsername, $smtpPassword) {
    $authMethod = strtolower(trim((string) (defined('MAIL_SMTP_AUTH_METHOD') ? MAIL_SMTP_AUTH_METHOD : 'auto')));

    $tryPlain = ($authMethod === 'auto' || $authMethod === 'plain');
    $tryLogin = ($authMethod === 'auto' || $authMethod === 'login');

    if ($tryPlain) {
        $plainToken = base64_encode("\0" . $smtpUsername . "\0" . $smtpPassword);
        list($plainWriteOk, $plainResponse) = smtpSendCommandWithResponse($socket, 'AUTH PLAIN ' . $plainToken);
        if ($plainWriteOk && in_array(smtpGetStatusCode($plainResponse), array(235), true)) {
            return array(true, $plainResponse);
        }
    }

    if ($tryLogin) {
        list($authOk, $authResponse) = smtpSendCommandWithResponse($socket, 'AUTH LOGIN');
        if (!$authOk || !in_array(smtpGetStatusCode($authResponse), array(334), true)) {
            return array(false, $authResponse);
        }

        list($userOk, $userResponse) = smtpSendCommandWithResponse($socket, base64_encode($smtpUsername));
        if (!$userOk || !in_array(smtpGetStatusCode($userResponse), array(334), true)) {
            return array(false, $userResponse);
        }

        list($passOk, $passResponse) = smtpSendCommandWithResponse($socket, base64_encode($smtpPassword));
        if (!$passOk || !in_array(smtpGetStatusCode($passResponse), array(235), true)) {
            return array(false, $passResponse);
        }

        return array(true, $passResponse);
    }

    return array(false, 'No SMTP auth method enabled');
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
 * Core SMTP Mail Sending Function with retry logic and fallback
 */
function smtpSendMail($to, $subject, $body, $replyTo = MAIL_REPLY_TO, $username = MAIL_SMTP_USERNAME, $password = MAIL_SMTP_PASSWORD) {
    $to = trim((string) $to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email: $to";
        logMailActivity($to, $subject, 'FAILED', $error);
        return false;
    }
    $missingCredentials = array();
    $smtpUsername = is_string($username) ? trim($username) : '';
    $smtpPassword = is_string($password) ? trim($password) : '';
    if (!is_string($smtpUsername) || trim($smtpUsername) === '') {
        $missingCredentials[] = 'MAIL_SMTP_USERNAME';
    }
    if (!is_string($smtpPassword) || trim($smtpPassword) === '') {
        $missingCredentials[] = 'MAIL_SMTP_PASSWORD';
    }
    if (!empty($missingCredentials)) {
        $error = '[smtpSendMail] Missing SMTP credentials in mail config constants: ' . implode(', ', $missingCredentials) . '. Set environment variables MAIL_SMTP_USERNAME and MAIL_SMTP_PASSWORD.';
        if (MAIL_DEBUG_MODE) {
            error_log($error);
        }
        logMailActivity($to, $subject, 'FAILED', $error);
        return false;
    }

    $attempts = 0;
    $maxAttempts = MAIL_RETRY_ATTEMPTS;
    
    // Try both primary and alternate port/encryption
    $portConfigs = array(
        array('port' => MAIL_SMTP_PORT, 'encryption' => MAIL_SMTP_ENCRYPTION),
        array('port' => (defined('MAIL_SMTP_ALT_PORT') ? MAIL_SMTP_ALT_PORT : 587), 'encryption' => (defined('MAIL_SMTP_ALT_ENCRYPTION') ? MAIL_SMTP_ALT_ENCRYPTION : 'tls'))
    );
    $configIndex = 0;

    while ($attempts < $maxAttempts) {
        $attempts++;
        $currentPort = $portConfigs[$configIndex]['port'];
        $currentEncryption = $portConfigs[$configIndex]['encryption'];
        $lastSmtpResponse = '';
        
        $hostPrefix = $currentEncryption === 'ssl' ? 'ssl://' : '';
        $remoteSocket = $hostPrefix . MAIL_SMTP_HOST . ':' . $currentPort;
        $socket = false;
        $errno = 0;
        $errstr = '';

        if ($currentEncryption === 'ssl') {
            $sslContext = stream_context_create(array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                )
            ));
            $socket = @stream_socket_client($remoteSocket, $errno, $errstr, MAIL_SEND_TIMEOUT, STREAM_CLIENT_CONNECT, $sslContext);
        } else {
            $socket = @stream_socket_client($remoteSocket, $errno, $errstr, MAIL_SEND_TIMEOUT, STREAM_CLIENT_CONNECT);
        }
        
        if (!$socket) {
            // Try next port config if available
            if ($configIndex < count($portConfigs) - 1) {
                $configIndex++;
                $attempts = 0; // Reset attempts for new config
                continue;
            }
            
            if ($attempts >= $maxAttempts) {
                $error = "Connection failed (attempt $attempts/$maxAttempts on all ports): $errstr";
                logMailActivity($to, $subject, 'FAILED', $error);
                if (MAIL_DEBUG_MODE) {
                    error_log('[smtpSendMail] ' . $error);
                }
                break; // Exit loop to try fallback
            }
            sleep(1); // Wait before retry
            continue;
        }

        stream_set_timeout($socket, MAIL_SEND_TIMEOUT);

        $greeting = smtpReadResponse($socket);
        $lastSmtpResponse = $greeting;
        if (!in_array(smtpGetStatusCode($greeting), array(220), true)) {
            fclose($socket);
            if ($attempts >= $maxAttempts) {
                logMailActivity($to, $subject, 'FAILED', 'No SMTP greeting');
                return false;
            }
            continue;
        }

        $defaultHelo = defined('MAIL_HELO_DOMAIN') ? MAIL_HELO_DOMAIN : 'accountsbazar.com';
        $hostName = preg_replace('/[^a-zA-Z0-9.-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? $defaultHelo));
        if ($hostName === '') {
            $hostName = $defaultHelo;
        }

        list($ehloOk, $ehloResponse) = smtpSendCommandWithResponse($socket, 'EHLO ' . $hostName);
        $lastSmtpResponse = $ehloResponse;
        if (!$ehloOk || !in_array(smtpGetStatusCode($ehloResponse), array(250), true)) {
            list($heloOk, $heloResponse) = smtpSendCommandWithResponse($socket, 'HELO ' . $hostName);
            $lastSmtpResponse = $heloResponse;
            if (!$heloOk || !in_array(smtpGetStatusCode($heloResponse), array(250), true)) {
                fclose($socket);
                if ($attempts >= $maxAttempts) {
                    logMailActivity($to, $subject, 'FAILED', 'EHLO/HELO failed');
                    return false;
                }
                continue;
            }
        }

        if ($currentEncryption === 'tls') {
            list($startTlsWriteOk, $startTlsResponse) = smtpSendCommandWithResponse($socket, 'STARTTLS');
            $lastSmtpResponse = $startTlsResponse;
            if (!$startTlsWriteOk || !in_array(smtpGetStatusCode($startTlsResponse), array(220), true)) {
                fclose($socket);
                if ($attempts >= $maxAttempts) {
                    $debugMsg = 'STARTTLS failed | Host: ' . MAIL_SMTP_HOST . ' | Port: ' . $currentPort . ' | SMTP: ' . trim($lastSmtpResponse);
                    logMailActivity($to, $subject, 'FAILED', $debugMsg);
                    if (MAIL_DEBUG_MODE) {
                        error_log('[smtpSendMail] ' . $debugMsg);
                    }
                    return false;
                }
                continue;
            }

            $cryptoEnabled = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($cryptoEnabled !== true) {
                fclose($socket);
                if ($attempts >= $maxAttempts) {
                    $debugMsg = 'TLS negotiation failed | Host: ' . MAIL_SMTP_HOST . ' | Port: ' . $currentPort;
                    logMailActivity($to, $subject, 'FAILED', $debugMsg);
                    if (MAIL_DEBUG_MODE) {
                        error_log('[smtpSendMail] ' . $debugMsg);
                    }
                    return false;
                }
                continue;
            }

            list($ehloTlsOk, $ehloTlsResponse) = smtpSendCommandWithResponse($socket, 'EHLO ' . $hostName);
            $lastSmtpResponse = $ehloTlsResponse;
            if (!$ehloTlsOk || !in_array(smtpGetStatusCode($ehloTlsResponse), array(250), true)) {
                fclose($socket);
                if ($attempts >= $maxAttempts) {
                    $debugMsg = 'EHLO after STARTTLS failed | SMTP: ' . trim($lastSmtpResponse);
                    logMailActivity($to, $subject, 'FAILED', $debugMsg);
                    if (MAIL_DEBUG_MODE) {
                        error_log('[smtpSendMail] ' . $debugMsg);
                    }
                    return false;
                }
                continue;
            }
        }

        if (MAIL_SMTP_AUTH) {
            list($authSuccess, $authResponse) = smtpAuth($socket, $smtpUsername, $smtpPassword);
            $lastSmtpResponse = $authResponse;
            if (!$authSuccess) {
                fclose($socket);
                if ($attempts >= $maxAttempts) {
                    $debugMsg = 'SMTP authentication failed | Username: ' . $smtpUsername . ' | SMTP: ' . trim($lastSmtpResponse);
                    if (MAIL_DEBUG_MODE) {
                        error_log('[smtpSendMail] ' . $debugMsg);
                    }
                    logMailActivity($to, $subject, 'FAILED', $debugMsg);
                    return false;
                }
                sleep(1);
                continue;
            }
        }

        $envelopeFrom = $smtpUsername;
        list($mailFromOk, $mailFromResponse) = smtpSendCommandWithResponse($socket, 'MAIL FROM:<' . $envelopeFrom . '>');
        $lastSmtpResponse = $mailFromResponse;
        if ((!$mailFromOk || !in_array(smtpGetStatusCode($mailFromResponse), array(250), true)) && MAIL_FROM_ADDRESS !== $envelopeFrom) {
            $envelopeFrom = MAIL_FROM_ADDRESS;
            list($mailFromOk, $mailFromResponse) = smtpSendCommandWithResponse($socket, 'MAIL FROM:<' . $envelopeFrom . '>');
            $lastSmtpResponse = $mailFromResponse;
        }

        $rcptOk = false;
        $dataOk = false;
        $rcptResponse = '';
        $dataResponse = '';
        if ($mailFromOk && in_array(smtpGetStatusCode($mailFromResponse), array(250), true)) {
            list($rcptOk, $rcptResponse) = smtpSendCommandWithResponse($socket, 'RCPT TO:<' . $to . '>');
            $lastSmtpResponse = $rcptResponse;
            if ($rcptOk && in_array(smtpGetStatusCode($rcptResponse), array(250, 251), true)) {
                list($dataOk, $dataResponse) = smtpSendCommandWithResponse($socket, 'DATA');
                $lastSmtpResponse = $dataResponse;
            }
        }

        if (!$mailFromOk || !in_array(smtpGetStatusCode($mailFromResponse), array(250), true)
            || !$rcptOk || !in_array(smtpGetStatusCode($rcptResponse), array(250, 251), true)
            || !$dataOk || !in_array(smtpGetStatusCode($dataResponse), array(354), true)) {
            fclose($socket);
            if ($attempts >= $maxAttempts) {
                $debugMsg = 'MAIL FROM/RCPT TO failed | From: ' . $envelopeFrom . ' | To: ' . $to . ' | SMTP: ' . trim($lastSmtpResponse);
                if (MAIL_DEBUG_MODE) {
                    error_log('[smtpSendMail] ' . $debugMsg);
                }
                logMailActivity($to, $subject, 'FAILED', $debugMsg);
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
    
    // Fallback: Try PHP's built-in mail() function as last resort
    if (MAIL_DEBUG_MODE) {
        error_log('[smtpSendMail] SMTP failed, attempting fallback with mail() function');
    }
    
    $headers = "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM_ADDRESS . ">\r\n";
    $headers .= "Reply-To: " . $replyTo . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "X-Mailer: Accounts Bazar";
    
    if (@mail($to, $subject, $body, $headers)) {
        logMailActivity($to, $subject, 'SUCCESS', 'Sent via PHP mail() fallback');
        return true;
    }
    
    logMailActivity($to, $subject, 'FAILED', 'All mail methods failed (SMTP + PHP mail)');
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

/**
 * Stylish order confirmation email template
 */
function getOrderConfirmationEmailTemplate($data = array()) {
    $baseUrl = 'https://accountsbazar.com';
    $profileLink = $baseUrl . '/profile.php';
    $supportEmail = htmlspecialchars((string) (defined('MAIL_REPLY_TO') ? MAIL_REPLY_TO : 'support@accountsbazar.com'), ENT_QUOTES, 'UTF-8');

    $customerName = htmlspecialchars((string) ($data['customer_name'] ?? 'Customer'), ENT_QUOTES, 'UTF-8');
    $orderNumber = htmlspecialchars((string) ($data['order_number'] ?? ''), ENT_QUOTES, 'UTF-8');
    $productName = htmlspecialchars((string) ($data['product_name'] ?? 'Product'), ENT_QUOTES, 'UTF-8');
    $planName = htmlspecialchars((string) ($data['plan_name'] ?? 'Standard'), ENT_QUOTES, 'UTF-8');
    $paymentMethod = htmlspecialchars((string) ($data['payment_method'] ?? 'N/A'), ENT_QUOTES, 'UTF-8');
    $trxId = htmlspecialchars((string) ($data['trx_id'] ?? 'N/A'), ENT_QUOTES, 'UTF-8');
    $subtotal = number_format((float) ($data['subtotal'] ?? 0), 2);
    $discount = number_format((float) ($data['discount'] ?? 0), 2);
    $total = number_format((float) ($data['total'] ?? 0), 2);

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmed</title>
</head>
<body style="margin:0;padding:0;background:#eef2ff;font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef2ff;padding:22px 10px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #dbeafe;">
                    <tr>
                        <td style="padding:22px 24px;background:linear-gradient(135deg,#0f172a 0%,#1d4ed8 55%,#0ea5e9 100%);color:#ffffff;">
                            <div style="font-size:21px;font-weight:800;letter-spacing:.3px;">Accounts Bazar</div>
                            <div style="font-size:13px;opacity:.92;margin-top:4px;">Order confirmation and receipt</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            <h2 style="margin:0 0 12px;color:#0f172a;font-size:22px;">Your Order Is Confirmed</h2>
                            <p style="margin:0 0 16px;color:#334155;font-size:14px;line-height:1.6;">Hello {$customerName}, thank you for shopping with us. Your order has been received and is now in our processing queue.</p>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;">
                                <tr><td style="padding:11px 14px;background:#f8fafc;color:#475569;font-size:13px;">Order Number</td><td style="padding:11px 14px;background:#f8fafc;color:#0f172a;font-size:13px;font-weight:700;text-align:right;">{$orderNumber}</td></tr>
                                <tr><td style="padding:11px 14px;color:#475569;font-size:13px;border-top:1px solid #eef2f7;">Product</td><td style="padding:11px 14px;color:#0f172a;font-size:13px;font-weight:700;text-align:right;border-top:1px solid #eef2f7;">{$productName}</td></tr>
                                <tr><td style="padding:11px 14px;color:#475569;font-size:13px;border-top:1px solid #eef2f7;">Plan</td><td style="padding:11px 14px;color:#0f172a;font-size:13px;font-weight:700;text-align:right;border-top:1px solid #eef2f7;">{$planName}</td></tr>
                                <tr><td style="padding:11px 14px;color:#475569;font-size:13px;border-top:1px solid #eef2f7;">Payment Method</td><td style="padding:11px 14px;color:#0f172a;font-size:13px;font-weight:700;text-align:right;border-top:1px solid #eef2f7;">{$paymentMethod}</td></tr>
                                <tr><td style="padding:11px 14px;color:#475569;font-size:13px;border-top:1px solid #eef2f7;">Transaction ID</td><td style="padding:11px 14px;color:#0f172a;font-size:13px;font-weight:700;text-align:right;border-top:1px solid #eef2f7;">{$trxId}</td></tr>
                            </table>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-top:14px;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;">
                                <tr><td style="padding:10px 14px;color:#64748b;font-size:13px;">Subtotal</td><td style="padding:10px 14px;color:#334155;font-size:13px;font-weight:700;text-align:right;">BDT {$subtotal}</td></tr>
                                <tr><td style="padding:10px 14px;color:#64748b;font-size:13px;border-top:1px solid #eef2f7;">Discount</td><td style="padding:10px 14px;color:#16a34a;font-size:13px;font-weight:700;text-align:right;border-top:1px solid #eef2f7;">- BDT {$discount}</td></tr>
                                <tr><td style="padding:12px 14px;color:#0f172a;font-size:14px;font-weight:800;border-top:1px solid #e2e8f0;background:#f8fafc;">Total Paid</td><td style="padding:12px 14px;color:#0f172a;font-size:14px;font-weight:800;text-align:right;border-top:1px solid #e2e8f0;background:#f8fafc;">BDT {$total}</td></tr>
                            </table>

                            <div style="text-align:center;margin-top:20px;">
                                <a href="{$profileLink}" style="display:inline-block;background:#1d4ed8;color:#ffffff;text-decoration:none;padding:11px 22px;border-radius:8px;font-size:13px;font-weight:700;">View My Orders</a>
                            </div>

                            <p style="margin:18px 0 0;color:#64748b;font-size:12px;line-height:1.6;">Need help? Reply to this email or contact support at <a href="mailto:{$supportEmail}" style="color:#2563eb;text-decoration:none;">{$supportEmail}</a>.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
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
    $body = getOrderConfirmationEmailTemplate(array(
        'customer_name' => $name,
        'order_number' => $orderId,
        'product_name' => (string) ($orderDetails['product_name'] ?? 'Product'),
        'plan_name' => (string) ($orderDetails['plan_name'] ?? 'Standard'),
        'payment_method' => (string) ($orderDetails['payment_method'] ?? 'N/A'),
        'trx_id' => (string) ($orderDetails['trx_id'] ?? 'N/A'),
        'subtotal' => (float) ($orderDetails['amount'] ?? 0),
        'discount' => (float) ($orderDetails['discount'] ?? 0),
        'total' => (float) ($orderDetails['amount'] ?? 0)
    ));

    return smtpSendMail($email, 'Order Confirmation - Order #' . $orderId, $body);
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
    // Prioritize newest pending emails (like OTP) before retrying older failed ones.
    $sql = 'SELECT id, to_email, subject, body, attempts, status FROM email_queue '
        . 'WHERE status IN ("pending", "failed") AND attempts < 5 '
        . 'ORDER BY CASE WHEN status = "pending" THEN 0 ELSE 1 END, id DESC LIMIT ' . (int) $limit;
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
