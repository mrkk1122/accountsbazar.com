<?php
ob_start();

session_start();
require_once 'products/config/config.php';
require_once 'products/includes/db.php';
require_once 'products/includes/mailer.php';

function ensurePasswordResetsTable($conn) {
    if (!$conn) {
        return false;
    }

    $queries = array(
        'CREATE TABLE IF NOT EXISTS `password_resets` (
            `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `email`      VARCHAR(255) NOT NULL,
            `otp_code`   VARCHAR(6)   NOT NULL,
            `expires_at` DATETIME     NOT NULL,
            `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS `password_resets` (
            `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `email`      VARCHAR(255) NOT NULL,
            `otp_code`   VARCHAR(6)   NOT NULL,
            `expires_at` DATETIME     NOT NULL,
            `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8',
        'CREATE TABLE IF NOT EXISTS `password_resets` (
            `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `email`      VARCHAR(255) NOT NULL,
            `otp_code`   VARCHAR(6)   NOT NULL,
            `expires_at` DATETIME     NOT NULL,
            `created_at` DATETIME,
            INDEX `idx_email` (`email`)
        ) ENGINE=InnoDB'
    );

    foreach ($queries as $sql) {
        if (@$conn->query($sql)) {
            return true;
        }
    }

    return false;
}

// ── Ensure the password_resets table exists ───────────────────────────────────
try {
    $setupDb   = new Database();
    $setupConn = $setupDb->getConnection();
    ensurePasswordResetsTable($setupConn);
    $setupDb->closeConnection();
} catch (Exception $e) {
    // Non-fatal; table may already exist.
}

// ── State management via session ──────────────────────────────────────────────
// fp_step  : 'email' | 'otp' | 'reset'
// fp_email : verified email address
// fp_otp_ok: true when OTP is verified

if (empty($_SESSION['fp_step'])) {
    $_SESSION['fp_step']   = 'email';
    $_SESSION['fp_email']  = '';
    $_SESSION['fp_otp_ok'] = false;
}

$step    = (string) ($_SESSION['fp_step'] ?? 'email');
$error   = '';
$success = '';

function normalizePhoneNumber($value) {
    return preg_replace('/\D+/', '', (string) $value);
}

function lastPhoneDigits($digits, $length = 10) {
    $digits = (string) $digits;
    $length = (int) $length;
    if ($length <= 0) {
        return $digits;
    }
    return strlen($digits) > $length ? substr($digits, -$length) : $digits;
}

function stmtFetchAssocRow($stmt) {
    if (method_exists($stmt, 'get_result')) {
        $result = $stmt->get_result();
        if ($result === false) {
            return null;
        }
        $row = $result->fetch_assoc();
        $result->free();
        return $row ?: null;
    }

    $meta = $stmt->result_metadata();
    if (!$meta) {
        return null;
    }

    $fields = array();
    $row = array();
    $bindParams = array();

    while ($field = $meta->fetch_field()) {
        $fields[] = $field->name;
        $row[$field->name] = null;
        $bindParams[] = &$row[$field->name];
    }
    $meta->free();

    if (!empty($bindParams)) {
        call_user_func_array(array($stmt, 'bind_result'), $bindParams);
    }

    if ($stmt->fetch()) {
        $out = array();
        foreach ($fields as $fieldName) {
            $out[$fieldName] = $row[$fieldName];
        }
        $stmt->free_result();
        return $out;
    }

    $stmt->free_result();
    return null;
}

function maskEmailAddress($email) {
    $email = (string) $email;
    $atPos = strpos($email, '@');
    if ($atPos === false) {
        return $email;
    }

    $local = substr($email, 0, $atPos);
    $domain = substr($email, $atPos + 1);

    if (strlen($local) <= 2) {
        $maskedLocal = substr($local, 0, 1) . str_repeat('*', max(strlen($local) - 1, 1));
    } else {
        $maskedLocal = substr($local, 0, 2) . str_repeat('*', max(strlen($local) - 2, 2));
    }

    return $maskedLocal . '@' . $domain;
}

// ── STEP 1: Receive email, send OTP ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fp_action']) && $_POST['fp_action'] === 'send_otp') {
    $identity = trim((string) ($_POST['fp_email'] ?? ''));
    $email = '';
    $phoneDigits = '';
    $isEmailInput = false;

    if (filter_var($identity, FILTER_VALIDATE_EMAIL)) {
        $isEmailInput = true;
        $email = strtolower($identity);
    } else {
        $phoneDigits = normalizePhoneNumber($identity);
        if (strlen($phoneDigits) < 8 || strlen($phoneDigits) > 15) {
            $error = 'Enter a valid email address or phone number.';
        }
    }

    if ($error === '') {
        try {
            $db   = new Database();
            $conn = $db->getConnection();

            // Check user exists
            if ($isEmailInput) {
                $userStmt = $conn->prepare('SELECT id, email, is_active, phone FROM users WHERE email = ? LIMIT 1');
            } else {
                $phoneLast10 = lastPhoneDigits($phoneDigits, 10);
                $userStmt = $conn->prepare(
                    'SELECT id, email, is_active, phone FROM users
                     WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, " ", ""), "-", ""), "+", ""), "(", ""), ")", "") = ?
                        OR RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, " ", ""), "-", ""), "+", ""), "(", ""), ")", ""), 10) = ?
                     LIMIT 1'
                );
            }
            if (!$userStmt) {
                throw new RuntimeException('DB prepare failed (find user): ' . $conn->error);
            }
            if ($isEmailInput) {
                $userStmt->bind_param('s', $email);
            } else {
                $userStmt->bind_param('ss', $phoneDigits, $phoneLast10);
            }
            $userStmt->execute();
            $userRow = stmtFetchAssocRow($userStmt);
            $userStmt->close();

            if (!$userRow) {
                $error = $isEmailInput
                    ? 'No account found with that email address.'
                    : 'No account found with that phone number.';
            } elseif ((int) ($userRow['is_active'] ?? 1) === 0) {
                $error = 'This account is inactive. Please contact support.';
            } else {
                $email = strtolower(trim((string) ($userRow['email'] ?? '')));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Your account email is invalid. Please contact support.';
                } else {
                    // Ensure password_resets table exists before querying it
                    if (!ensurePasswordResetsTable($conn)) {
                        throw new RuntimeException('Could not create or access password_resets table');
                    }

                    // Rate-limit: max 3 requests per 10 minutes per email
                    $rateSql  = 'SELECT COUNT(*) AS cnt FROM password_resets WHERE email = ? AND created_at >= NOW() - INTERVAL 10 MINUTE';
                    $rateStmt = $conn->prepare($rateSql);
                    if (!$rateStmt) {
                        throw new RuntimeException('DB prepare failed (rate-limit): ' . $conn->error);
                    }
                    $rateStmt->bind_param('s', $email);
                    $rateStmt->execute();
                    $rateRow  = stmtFetchAssocRow($rateStmt);
                    $rateStmt->close();

                    if ((int) ($rateRow['cnt'] ?? 0) >= 3) {
                        $error = 'Too many OTP requests. Please wait 10 minutes and try again.';
                    } else {
                        // Delete any stale OTPs for this email
                        $delStmt = $conn->prepare('DELETE FROM password_resets WHERE email = ?');
                        if ($delStmt) {
                            $delStmt->bind_param('s', $email);
                            $delStmt->execute();
                            $delStmt->close();
                        } else {
                            error_log('[ForgotPassword/send_otp] DELETE prepare failed: ' . $conn->error);
                        }

                        // Generate 6-digit OTP
                        $otp     = (string) random_int(100000, 999999);
                        $expires = date('Y-m-d H:i:s', time() + 15 * 60); // 15 minutes

                        $insStmt = $conn->prepare('INSERT INTO password_resets (email, otp_code, expires_at) VALUES (?, ?, ?)');
                        if (!$insStmt) {
                            throw new RuntimeException('DB prepare failed (insert OTP): ' . $conn->error);
                        }
                        $insStmt->bind_param('sss', $email, $otp, $expires);
                        $insStmt->execute();
                        $insStmt->close();

                        // Build OTP email
                        $subject  = 'Accounts Bazar - Password Reset OTP';
                        $body  = "Hello,\r\n\r\n";
                        $body .= "We received a request to reset the password for your Accounts Bazar account.\r\n\r\n";
                        $body .= "Your One-Time Password (OTP) is:\r\n\r\n";
                        $body .= "  " . $otp . "\r\n\r\n";
                        $body .= "This OTP is valid for 15 minutes. Do not share it with anyone.\r\n\r\n";
                        $body .= "If you did not request a password reset, you can safely ignore this email.\r\n\r\n";
                        $body .= "-- Accounts Bazar Team\r\nhttps://accountsbazar.com/";

                        $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
                        $htmlBody = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#111;">'
                            . '<p>Hello,</p>'
                            . '<p>We received a password reset request for your Accounts Bazar account.</p>'
                            . '<p style="font-size:20px;"><strong>Your OTP: ' . $safeOtp . '</strong></p>'
                            . '<p>This OTP is valid for 15 minutes. Do not share it with anyone.</p>'
                            . '<p>Accounts Bazar Team</p>'
                            . '</body></html>';

                        // Try direct SMTP send first for immediate OTP delivery.
                        $smtpSent = smtpSendMail($email, $subject, $htmlBody);

                        // Always queue as backup for cron-based retry
                        $mailQueued = enqueueEmail($conn, $email, $subject, $htmlBody);

                        // Process a few queued jobs immediately so OTP can arrive
                        // even when cron is not yet configured.
                        $queueResult = array('queued' => 0, 'sent' => 0);
                        if ($mailQueued) {
                            $queueResult = processEmailQueue($conn, 3);
                        }

                        // If SMTP failed, try PHP mail() as fallback
                        $phpMailSent = false;
                        if (!$smtpSent && !((int) ($queueResult['sent'] ?? 0) > 0)) {
                            $headers = "From: Accounts Bazar <" . MAIL_FROM_ADDRESS . ">\r\n";
                            $headers .= "Reply-To: " . MAIL_REPLY_TO . "\r\n";
                            $headers .= "MIME-Version: 1.0\r\n";
                            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                            $mailParams = '';
                            if (defined('MAIL_FROM_ADDRESS') && filter_var(MAIL_FROM_ADDRESS, FILTER_VALIDATE_EMAIL)) {
                                $mailParams = '-f' . MAIL_FROM_ADDRESS;
                            }
                            $phpMailSent = $mailParams !== ''
                                ? @mail($email, $subject, $htmlBody, $headers, $mailParams)
                                : @mail($email, $subject, $htmlBody, $headers);
                            if ($phpMailSent) {
                                logMailActivity($email, $subject, 'SUCCESS', 'Sent via PHP mail() fallback');
                            }
                        }

                        if ($smtpSent && $mailQueued) {
                            // Mark as sent in the queue so cron does not resend
                            $markSentStmt = $conn->prepare('UPDATE email_queue SET status = "sent", sent_at = NOW() WHERE to_email = ? AND status = "pending" ORDER BY id DESC LIMIT 1');
                            if ($markSentStmt) {
                                $markSentStmt->bind_param('s', $email);
                                $markSentStmt->execute();
                                $markSentStmt->close();
                            }
                        }
                        $db->closeConnection();

                        $sentNow = $smtpSent || ((int) ($queueResult['sent'] ?? 0) > 0) || $phpMailSent;

                        if ($sentNow) {
                            $_SESSION['fp_step']   = 'otp';
                            $_SESSION['fp_email']  = $email;
                            $_SESSION['fp_otp_ok'] = false;
                            $step    = 'otp';
                            $success = 'OTP sent to ' . htmlspecialchars(maskEmailAddress($email), ENT_QUOTES, 'UTF-8') . '. Check your inbox (and spam folder).';
                        } elseif (!$mailQueued) {
                            $error = 'OTP email could not be sent or queued. Please contact support.';
                            $_SESSION['fp_step'] = 'email';
                            $step = 'email';
                        } else {
                            $error = 'OTP email could not be delivered right now. Please try again after 1-2 minutes.';
                            $_SESSION['fp_step'] = 'email';
                            $step = 'email';
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('[ForgotPassword/send_otp] ' . $e->getMessage());
            $error = 'Server setup issue while generating OTP. Please try again shortly.';
        }
    }
}

// ── STEP 2: Verify OTP ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fp_action']) && $_POST['fp_action'] === 'verify_otp') {
    $otp   = preg_replace('/\D/', '', (string) ($_POST['fp_otp'] ?? ''));
    $email = (string) ($_SESSION['fp_email'] ?? '');

    if (strlen($otp) !== 6 || $email === '') {
        $error = 'Please enter the 6-digit OTP.';
    } else {
        try {
            $db   = new Database();
            $conn = $db->getConnection();

            if (!ensurePasswordResetsTable($conn)) {
                throw new RuntimeException('Could not create or access password_resets table');
            }

            $chkStmt = $conn->prepare(
                'SELECT id FROM password_resets WHERE email = ? AND otp_code = ? AND expires_at >= NOW() LIMIT 1'
            );
            if (!$chkStmt) {
                throw new RuntimeException('DB prepare failed (verify OTP): ' . $conn->error);
            }
            $chkStmt->bind_param('ss', $email, $otp);
            $chkStmt->execute();
            $chkRow = stmtFetchAssocRow($chkStmt);
            $chkStmt->close();
            $db->closeConnection();

            if (!$chkRow) {
                $error = 'Invalid or expired OTP. Please check the code or request a new one.';
            } else {
                $_SESSION['fp_step']   = 'reset';
                $_SESSION['fp_otp_ok'] = true;
                $step = 'reset';
            }
        } catch (Throwable $e) {
            error_log('[ForgotPassword/verify_otp] ' . $e->getMessage());
            $error = 'Something went wrong. Please try again.';
        }
    }
}

// ── STEP 3: Reset password ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fp_action']) && $_POST['fp_action'] === 'reset_password') {
    $email   = (string) ($_SESSION['fp_email'] ?? '');
    $otpOk   = (bool)   ($_SESSION['fp_otp_ok'] ?? false);
    $pw1     = (string) ($_POST['fp_password']  ?? '');
    $pw2     = (string) ($_POST['fp_password2'] ?? '');

    if (!$otpOk || $email === '') {
        $error = 'Session expired. Please start again.';
        $_SESSION['fp_step']   = 'email';
        $_SESSION['fp_email']  = '';
        $_SESSION['fp_otp_ok'] = false;
        $step = 'email';
    } elseif (strlen($pw1) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($pw1 !== $pw2) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $db   = new Database();
            $conn = $db->getConnection();

            $hash = password_hash($pw1, PASSWORD_BCRYPT);

            $upStmt = $conn->prepare('UPDATE users SET password = ? WHERE email = ? LIMIT 1');
            $upStmt->bind_param('ss', $hash, $email);
            $upStmt->execute();
            $affected = $upStmt->affected_rows;
            $upStmt->close();

            // Clean up OTP rows
            $cleanStmt = $conn->prepare('DELETE FROM password_resets WHERE email = ?');
            $cleanStmt->bind_param('s', $email);
            $cleanStmt->execute();
            $cleanStmt->close();

            $db->closeConnection();

            if ($affected > 0) {
                // Clear session state and redirect to login with success message
                unset($_SESSION['fp_step'], $_SESSION['fp_email'], $_SESSION['fp_otp_ok']);
                header('Location: login.php?message=' . urlencode('Password reset successful! Please log in with your new password.'));
                exit;
            } else {
                $error = 'Could not update password. Please try again.';
            }
        } catch (Throwable $e) {
            error_log('[ForgotPassword/reset_password] ' . $e->getMessage());
            $error = 'Something went wrong. Please try again.';
        }
    }
}

// ── Resend OTP action ─────────────────────────────────────────────────────────
if (isset($_GET['resend']) && $step === 'otp') {
    // Reset to email step so the user re-submits their email
    $_SESSION['fp_step']   = 'email';
    $_SESSION['fp_email']  = '';
    $_SESSION['fp_otp_ok'] = false;
    $step = 'email';
    $success = 'Please enter your email again to receive a new OTP.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php
$seo = [
    'title'       => 'Forgot Password – Accounts Bazar',
    'description' => 'Reset your Accounts Bazar account password using a one-time OTP sent to your email.',
    'canonical'   => 'https://accountsbazar.com/forgot-password.php',
    'noindex'     => true,
];
require_once 'products/includes/seo.php';
?>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/mobile.css">
    <style>
        /* ── Page layout ────────────────────────────────────────── */
        .fp-page {
            min-height: 80vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 16px 100px;
            background: linear-gradient(135deg, #f0f4ff 0%, #fafafa 100%);
        }
        .fp-card {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 8px 40px rgba(15,23,42,0.12);
            padding: 40px 36px 36px;
            width: 100%;
            max-width: 440px;
        }
        /* ── Icon / header ──────────────────────────────────────── */
        .fp-icon {
            width: 68px;
            height: 68px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin-bottom: 6px;
        }
        .fp-icon-email  { background: linear-gradient(135deg,#0f172a 60%,#0ea5e9); }
        .fp-icon-otp    { background: linear-gradient(135deg,#0f172a 60%,#8b5cf6); }
        .fp-icon-reset  { background: linear-gradient(135deg,#0f172a 60%,#16a34a); }
        .fp-header { text-align: center; margin-bottom: 6px; }
        .fp-title {
            text-align: center;
            font-size: 22px;
            font-weight: 900;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .fp-subtitle {
            text-align: center;
            font-size: 13px;
            color: #64748b;
            margin-bottom: 26px;
            line-height: 1.5;
        }
        /* ── Steps indicator ────────────────────────────────────── */
        .fp-steps {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0;
            margin-bottom: 28px;
        }
        .fp-step-dot {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            border: 2px solid #e2e8f0;
            background: #f8fafc;
            color: #94a3b8;
            font-size: 12px;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.2s;
        }
        .fp-step-dot.active {
            border-color: #0ea5e9;
            background: #0ea5e9;
            color: #fff;
        }
        .fp-step-dot.done {
            border-color: #16a34a;
            background: #16a34a;
            color: #fff;
        }
        .fp-step-line {
            flex: 1;
            height: 2px;
            background: #e2e8f0;
            max-width: 48px;
        }
        .fp-step-line.done { background: #16a34a; }
        /* ── Form ───────────────────────────────────────────────── */
        .fp-form .fp-row {
            margin-bottom: 16px;
        }
        .fp-form label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 6px;
        }
        .fp-form input[type="email"],
        .fp-form input[type="text"],
        .fp-form input[type="password"] {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid #e2e8f0;
            border-radius: 9px;
            font-size: 15px;
            color: #0f172a;
            background: #f8fafc;
            outline: none;
            box-sizing: border-box;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .fp-form input:focus {
            border-color: #0ea5e9;
            box-shadow: 0 0 0 3px rgba(14,165,233,0.13);
            background: #fff;
        }
        /* ── OTP input row ──────────────────────────────────────── */
        .otp-hint {
            font-size: 12px;
            color: #64748b;
            margin-top: 5px;
        }
        /* ── Password strength bar ──────────────────────────────── */
        .pw-strength-bar {
            height: 4px;
            border-radius: 4px;
            margin-top: 6px;
            background: #e2e8f0;
            overflow: hidden;
        }
        .pw-strength-fill {
            height: 100%;
            width: 0%;
            border-radius: 4px;
            transition: width 0.25s, background 0.25s;
        }
        .pw-strength-text {
            font-size: 11px;
            color: #64748b;
            margin-top: 3px;
        }
        /* ── Password visibility toggle ─────────────────────────── */
        .pw-wrap { position: relative; }
        .pw-wrap input { padding-right: 44px; }
        .pw-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            font-size: 18px;
            color: #94a3b8;
            padding: 0;
            line-height: 1;
        }
        .pw-toggle:hover { color: #0ea5e9; }
        /* ── Alerts ─────────────────────────────────────────────── */
        .fp-alert-error {
            background: #fff0f0;
            border-left: 4px solid #e11d48;
            color: #9f1239;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 16px;
            font-weight: 600;
        }
        .fp-alert-success {
            background: #f0fff4;
            border-left: 4px solid #16a34a;
            color: #15803d;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 16px;
            font-weight: 600;
        }
        /* ── Buttons ────────────────────────────────────────────── */
        .fp-btn {
            width: 100%;
            padding: 13px;
            background: #0f172a;
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 800;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
            margin-top: 4px;
        }
        .fp-btn:hover {
            background: #0ea5e9;
            transform: translateY(-1px);
        }
        .fp-back-link {
            display: block;
            text-align: center;
            margin-top: 18px;
            font-size: 13px;
            color: #64748b;
        }
        .fp-back-link a {
            color: #0ea5e9;
            font-weight: 700;
            text-decoration: none;
        }
        .fp-back-link a:hover { text-decoration: underline; }
        .fp-resend {
            text-align: center;
            margin-top: 14px;
            font-size: 13px;
            color: #64748b;
        }
        .fp-resend a {
            color: #8b5cf6;
            font-weight: 700;
            text-decoration: none;
        }
        .fp-resend a:hover { text-decoration: underline; }
        @media (max-width: 480px) {
            .fp-card { padding: 28px 18px 28px; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="container">
            <nav class="navbar">
                <div class="store-brand">
                    <a href="index.php" style="text-decoration:none;">
                        <span class="store-title">Accounts Bazar</span>
                    </a>
                </div>
            </nav>
        </div>
    </header>

    <div class="fp-page">
        <div class="fp-card">

            <!-- Step icons / titles -->
            <?php if ($step === 'email'): ?>
                <div class="fp-header">
                    <div class="fp-icon fp-icon-email">🔑</div>
                </div>
                <h1 class="fp-title">Forgot Password?</h1>
                <p class="fp-subtitle">Enter your account email or phone number and we'll send a 6-digit OTP to your registered email.</p>
            <?php elseif ($step === 'otp'): ?>
                <div class="fp-header">
                    <div class="fp-icon fp-icon-otp">📨</div>
                </div>
                <h1 class="fp-title">Enter OTP</h1>
                <p class="fp-subtitle">A 6-digit code was sent to <strong><?php echo htmlspecialchars((string)$_SESSION['fp_email']); ?></strong>. It expires in 15 minutes.</p>
            <?php else: ?>
                <div class="fp-header">
                    <div class="fp-icon fp-icon-reset">🔒</div>
                </div>
                <h1 class="fp-title">Set New Password</h1>
                <p class="fp-subtitle">Choose a strong password for your account.</p>
            <?php endif; ?>

            <!-- Step progress dots -->
            <div class="fp-steps" aria-label="Password reset steps">
                <div class="fp-step-dot <?php echo $step === 'email' ? 'active' : 'done'; ?>" aria-label="Step 1: Email">1</div>
                <div class="fp-step-line <?php echo in_array($step, ['otp','reset'], true) ? 'done' : ''; ?>"></div>
                <div class="fp-step-dot <?php echo $step === 'otp' ? 'active' : ($step === 'reset' ? 'done' : ''); ?>" aria-label="Step 2: OTP">2</div>
                <div class="fp-step-line <?php echo $step === 'reset' ? 'done' : ''; ?>"></div>
                <div class="fp-step-dot <?php echo $step === 'reset' ? 'active' : ''; ?>" aria-label="Step 3: Reset">3</div>
            </div>

            <!-- Alerts -->
            <?php if ($error !== ''): ?>
                <div class="fp-alert-error" role="alert">❌ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success !== ''): ?>
                <div class="fp-alert-success" role="status">✅ <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <!-- ── STEP 1: Email form ──────────────────────────────── -->
            <?php if ($step === 'email'): ?>
                <form class="fp-form" method="POST" action="forgot-password.php">
                    <input type="hidden" name="fp_action" value="send_otp">
                    <div class="fp-row">
                        <label for="fp-email">Email Address or Phone Number</label>
                        <input
                            id="fp-email"
                            type="text"
                            name="fp_email"
                            required
                            autocomplete="username"
                            placeholder="your@email.com or 01XXXXXXXXX"
                            value="<?php echo htmlspecialchars((string)($_POST['fp_email'] ?? '')); ?>"
                        >
                    </div>
                    <button class="fp-btn" type="submit">Send OTP</button>
                </form>
                <div class="fp-back-link">
                    Remembered your password? <a href="login.php">Back to Login</a>
                </div>

            <!-- ── STEP 2: OTP form ───────────────────────────────── -->
            <?php elseif ($step === 'otp'): ?>
                <form class="fp-form" method="POST" action="forgot-password.php">
                    <input type="hidden" name="fp_action" value="verify_otp">
                    <div class="fp-row">
                        <label for="fp-otp">6-Digit OTP</label>
                        <input
                            id="fp-otp"
                            type="text"
                            name="fp_otp"
                            required
                            inputmode="numeric"
                            pattern="[0-9]{6}"
                            maxlength="6"
                            autocomplete="one-time-code"
                            placeholder="e.g. 482931"
                        >
                        <p class="otp-hint">Check your email inbox (and spam/junk folder).</p>
                    </div>
                    <button class="fp-btn" type="submit">Verify OTP</button>
                </form>
                <div class="fp-resend">
                    Didn't receive the code? <a href="forgot-password.php?resend=1">Resend OTP</a>
                </div>

            <!-- ── STEP 3: New password form ──────────────────────── -->
            <?php else: ?>
                <form class="fp-form" method="POST" action="forgot-password.php" id="reset-form">
                    <input type="hidden" name="fp_action" value="reset_password">
                    <div class="fp-row">
                        <label for="fp-pw1">New Password</label>
                        <div class="pw-wrap">
                            <input
                                id="fp-pw1"
                                type="password"
                                name="fp_password"
                                required
                                minlength="8"
                                autocomplete="new-password"
                                placeholder="At least 8 characters"
                                oninput="checkStrength(this.value)"
                            >
                            <button type="button" class="pw-toggle" onclick="togglePw('fp-pw1')" aria-label="Show/hide password">👁</button>
                        </div>
                        <div class="pw-strength-bar"><div class="pw-strength-fill" id="pw-fill"></div></div>
                        <p class="pw-strength-text" id="pw-strength-text"></p>
                    </div>
                    <div class="fp-row">
                        <label for="fp-pw2">Confirm Password</label>
                        <div class="pw-wrap">
                            <input
                                id="fp-pw2"
                                type="password"
                                name="fp_password2"
                                required
                                autocomplete="new-password"
                                placeholder="Repeat the password"
                            >
                            <button type="button" class="pw-toggle" onclick="togglePw('fp-pw2')" aria-label="Show/hide password">👁</button>
                        </div>
                    </div>
                    <button class="fp-btn" type="submit">Reset Password</button>
                </form>
            <?php endif; ?>

        </div>
    </div>

    <nav class="mobile-bottom-nav" aria-label="Mobile Bottom Navigation">
        <a href="index.php"><span class="nav-icon">🏠</span><span class="nav-label">Home</span></a>
        <a href="shop.php"><span class="nav-icon">🛍️</span><span class="nav-label">Shop</span></a>
        <a class="ai-prompt-link" href="ai-prompt.php"><span class="nav-icon">🤖</span><span class="nav-label">AI Prompt</span></a>
        <a href="#" data-notification-toggle><span class="nav-icon">🔔</span><span class="nav-label">Notification</span><span class="notif-badge" data-notif-badge style="display:none;">0</span></a>
        <a class="active" href="login.php"><span class="nav-icon">👤</span><span class="nav-label">Login</span></a>
    </nav>

    <script>
    function togglePw(id) {
        var inp = document.getElementById(id);
        if (inp) {
            inp.type = inp.type === 'password' ? 'text' : 'password';
        }
    }

    function checkStrength(pw) {
        var fill = document.getElementById('pw-fill');
        var txt  = document.getElementById('pw-strength-text');
        if (!fill || !txt) return;

        var score = 0;
        if (pw.length >= 8)  score++;
        if (pw.length >= 12) score++;
        if (/[A-Z]/.test(pw)) score++;
        if (/[0-9]/.test(pw)) score++;
        if (/[^A-Za-z0-9]/.test(pw)) score++;

        var widths = ['0%', '20%', '45%', '65%', '85%', '100%'];
        var colors = ['#e2e8f0', '#ef4444', '#f97316', '#eab308', '#22c55e', '#16a34a'];
        var labels = ['', 'Very Weak', 'Weak', 'Fair', 'Strong', 'Very Strong'];

        fill.style.width      = widths[score];
        fill.style.background = colors[score];
        txt.textContent       = labels[score];
        txt.style.color       = colors[score];
    }

    // Client-side confirm-match validation
    (function () {
        var form = document.getElementById('reset-form');
        if (!form) return;
        form.addEventListener('submit', function (e) {
            var pw1 = document.getElementById('fp-pw1');
            var pw2 = document.getElementById('fp-pw2');
            if (pw1 && pw2 && pw1.value !== pw2.value) {
                e.preventDefault();
                alert('Passwords do not match. Please try again.');
                pw2.focus();
            }
        });
    }());
    </script>
    <script src="js/client.js"></script>
</body>
</html>
