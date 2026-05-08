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

    $hostPrefix = MAIL_SMTP_ENCRYPTION === 'ssl' ? 'ssl://' : '';
    $remoteSocket = $hostPrefix . MAIL_SMTP_HOST . ':' . MAIL_SMTP_PORT;
    $socket = @stream_socket_client($remoteSocket, $errno, $errstr, 30, STREAM_CLIENT_CONNECT);
    if (!$socket) {
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
