<?php
require_once __DIR__ . '/../config/mail.php';

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

function smtpSendMail($to, $subject, $body, $replyTo = MAIL_REPLY_TO) {
    $to = trim((string) $to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    if (trim((string) MAIL_SMTP_USERNAME) === '' || trim((string) MAIL_SMTP_PASSWORD) === '') {
        error_log('[smtpSendMail] Missing SMTP credentials in mail config constants (typically sourced from MAIL_SMTP_USERNAME and MAIL_SMTP_PASSWORD environment variables).');
        return false;
    }

    $hostPrefix = MAIL_SMTP_ENCRYPTION === 'ssl' ? 'ssl://' : '';
    $remoteSocket = $hostPrefix . MAIL_SMTP_HOST . ':' . MAIL_SMTP_PORT;

    $sslContext = stream_context_create(array(
        'ssl' => array(
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
        )
    ));
    $socket = @stream_socket_client($remoteSocket, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $sslContext);
    if (!$socket) {
        // Retry with relaxed SSL verification for shared hosting compatibility
        // (e.g. self-signed or untrusted certs on cPanel mail servers)
        error_log('[smtpSendMail] SSL connect failed (' . $errno . ': ' . $errstr . '), retrying with relaxed verification to ' . $remoteSocket);
        $sslContextRelaxed = stream_context_create(array(
            'ssl' => array(
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            )
        ));
        $socket = @stream_socket_client($remoteSocket, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $sslContextRelaxed);
    }
    if (!$socket) {
        error_log('[smtpSendMail] Could not connect to ' . $remoteSocket . ' (' . $errno . ': ' . $errstr . ')');
        return false;
    }

    stream_set_timeout($socket, 30);

    if (!smtpExpectCode($socket, array(220))) {
        fclose($socket);
        return false;
    }

    $hostName = preg_replace('/[^a-zA-Z0-9.-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    if ($hostName === '') {
        $hostName = 'localhost';
    }

    if (!smtpSendCommand($socket, 'EHLO ' . $hostName, array(250))) {
        if (!smtpSendCommand($socket, 'HELO ' . $hostName, array(250))) {
            fclose($socket);
            return false;
        }
    }

    if (!smtpSendCommand($socket, 'AUTH LOGIN', array(334))
        || !smtpSendCommand($socket, base64_encode(MAIL_SMTP_USERNAME), array(334))
        || !smtpSendCommand($socket, base64_encode(MAIL_SMTP_PASSWORD), array(235))) {
        fclose($socket);
        return false;
    }

    if (!smtpSendCommand($socket, 'MAIL FROM:<' . MAIL_FROM_ADDRESS . '>', array(250))
        || !smtpSendCommand($socket, 'RCPT TO:<' . $to . '>', array(250, 251))
        || !smtpSendCommand($socket, 'DATA', array(354))) {
        fclose($socket);
        return false;
    }

    $headers = array(
        'Date: ' . date(DATE_RFC2822),
        'From: ' . smtpEncodeHeader(MAIL_FROM_NAME) . ' <' . MAIL_FROM_ADDRESS . '>',
        'Reply-To: ' . $replyTo,
        'To: <' . $to . '>',
        'Subject: ' . smtpEncodeHeader($subject),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit'
    );

    $payload = implode("\r\n", $headers) . "\r\n\r\n" . smtpNormalizeBody($body);
    if (fwrite($socket, $payload . "\r\n.\r\n") === false || !smtpExpectCode($socket, array(250))) {
        fclose($socket);
        return false;
    }

    smtpSendCommand($socket, 'QUIT', array(221));
    fclose($socket);
    return true;
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
