<?php
require_once __DIR__ . '/../config/webpush.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

function webpushIsConfigured() {
    return trim((string) WEBPUSH_PUBLIC_KEY) !== ''
        && trim((string) WEBPUSH_PRIVATE_KEY) !== ''
        && trim((string) WEBPUSH_SUBJECT) !== '';
}

function webpushEnsureTables($conn) {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS web_push_subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            endpoint TEXT NOT NULL,
            p256dh VARCHAR(255) NOT NULL,
            auth_token VARCHAR(255) NOT NULL,
            content_encoding VARCHAR(20) DEFAULT 'aes128gcm',
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_endpoint (endpoint(255)),
            INDEX idx_is_active (is_active),
            INDEX idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS web_push_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_uid VARCHAR(190) NOT NULL,
            title VARCHAR(190) NOT NULL,
            message TEXT,
            target_url VARCHAR(255) DEFAULT 'index.php',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_event_uid (event_uid),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS web_push_delivery_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            subscription_id INT NOT NULL,
            event_uid VARCHAR(190) NOT NULL,
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            status_code INT DEFAULT 0,
            status_text VARCHAR(255) DEFAULT '',
            UNIQUE KEY uniq_sub_event (subscription_id, event_uid),
            INDEX idx_event_uid (event_uid),
            INDEX idx_sent_at (sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function webpushUpsertSubscription($conn, $userId, $payload) {
    $endpoint = trim((string) ($payload['endpoint'] ?? ''));
    $keys = $payload['keys'] ?? array();
    $p256dh = trim((string) ($keys['p256dh'] ?? ''));
    $auth = trim((string) ($keys['auth'] ?? ''));
    $encoding = trim((string) ($payload['contentEncoding'] ?? 'aes128gcm'));

    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        return false;
    }

    $sql = 'INSERT INTO web_push_subscriptions (user_id, endpoint, p256dh, auth_token, content_encoding, is_active)
            VALUES (?, ?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id),
            p256dh = VALUES(p256dh),
            auth_token = VALUES(auth_token),
            content_encoding = VALUES(content_encoding),
            is_active = 1';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('issss', $userId, $endpoint, $p256dh, $auth, $encoding);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function webpushDeactivateByEndpoint($conn, $endpoint) {
    $endpoint = trim((string) $endpoint);
    if ($endpoint === '') {
        return false;
    }

    $stmt = $conn->prepare('UPDATE web_push_subscriptions SET is_active = 0 WHERE endpoint = ?');
    $stmt->bind_param('s', $endpoint);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function webpushQueueEvent($conn, $eventUid, $title, $message, $targetUrl) {
    $eventUid = trim((string) $eventUid);
    if ($eventUid === '') {
        return false;
    }

    $title = trim((string) $title);
    $message = trim((string) $message);
    $targetUrl = trim((string) $targetUrl);
    if ($targetUrl === '') {
        $targetUrl = 'index.php';
    }

    $stmt = $conn->prepare('INSERT IGNORE INTO web_push_events (event_uid, title, message, target_url) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('ssss', $eventUid, $title, $message, $targetUrl);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function webpushSendPendingEvents($conn, $limit = 10) {
    if (!webpushIsConfigured()) {
        return array('sent' => 0, 'events' => 0, 'subscriptions' => 0, 'error' => 'Web Push keys missing');
    }

    $auth = array(
        'VAPID' => array(
            'subject' => WEBPUSH_SUBJECT,
            'publicKey' => WEBPUSH_PUBLIC_KEY,
            'privateKey' => WEBPUSH_PRIVATE_KEY,
        ),
    );

    $webPush = new WebPush($auth);

    $subscriptions = array();
    $subRes = $conn->query('SELECT id, endpoint, p256dh, auth_token, content_encoding FROM web_push_subscriptions WHERE is_active = 1 ORDER BY id ASC');
    if ($subRes) {
        while ($row = $subRes->fetch_assoc()) {
            $subscriptions[] = $row;
        }
    }

    if (count($subscriptions) === 0) {
        return array('sent' => 0, 'events' => 0, 'subscriptions' => 0);
    }

    $events = array();
    $evtSql = 'SELECT e.id, e.event_uid, e.title, e.message, e.target_url
               FROM web_push_events e
               ORDER BY e.id DESC
               LIMIT ' . (int) $limit;
    $evtRes = $conn->query($evtSql);
    if ($evtRes) {
        while ($row = $evtRes->fetch_assoc()) {
            $events[] = $row;
        }
    }

    if (count($events) === 0) {
        return array('sent' => 0, 'events' => 0, 'subscriptions' => count($subscriptions));
    }

    $queued = 0;
    foreach ($events as $event) {
        foreach ($subscriptions as $sub) {
            $checkStmt = $conn->prepare('SELECT id FROM web_push_delivery_logs WHERE subscription_id = ? AND event_uid = ? LIMIT 1');
            $checkStmt->bind_param('is', $sub['id'], $event['event_uid']);
            $checkStmt->execute();
            $exists = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();

            if ($exists) {
                continue;
            }

            $subObj = Subscription::create(array(
                'endpoint' => (string) $sub['endpoint'],
                'publicKey' => (string) $sub['p256dh'],
                'authToken' => (string) $sub['auth_token'],
                'contentEncoding' => (string) ($sub['content_encoding'] ?: 'aes128gcm'),
            ));

            $payload = json_encode(array(
                'title' => (string) $event['title'],
                'body' => (string) $event['message'],
                'url' => (string) $event['target_url'],
                'icon' => 'images/logo.png',
                'badge' => 'favicon.png',
                'tag' => 'ab-' . (string) $event['event_uid'],
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $webPush->queueNotification($subObj, $payload);
            $queued++;
        }
    }

    $sentCount = 0;
    foreach ($webPush->flush() as $report) {
        $endpoint = (string) $report->getRequest()->getUri();
        $isSuccess = $report->isSuccess();
        $response = $report->getResponse();
        $statusCode = $response ? (int) $response->getStatusCode() : 0;
        $reason = $response ? (string) $response->getReasonPhrase() : '';

        $subId = 0;
        $subStmt = $conn->prepare('SELECT id FROM web_push_subscriptions WHERE endpoint = ? LIMIT 1');
        $subStmt->bind_param('s', $endpoint);
        $subStmt->execute();
        $subRow = $subStmt->get_result()->fetch_assoc();
        $subStmt->close();
        if ($subRow) {
            $subId = (int) $subRow['id'];
        }

        // Endpoint usually maps to one event per flush result queue order may vary.
        // We log as delivered for all unsent recent events for this subscription after success,
        // and deactivate broken subscriptions on terminal failures.
        if ($subId > 0) {
            foreach ($events as $event) {
                $logStmt = $conn->prepare('INSERT IGNORE INTO web_push_delivery_logs (subscription_id, event_uid, status_code, status_text) VALUES (?, ?, ?, ?)');
                $logStmt->bind_param('isis', $subId, $event['event_uid'], $statusCode, $reason);
                $logStmt->execute();
                $logStmt->close();
            }
        }

        if ($isSuccess) {
            $sentCount++;
        } else {
            if (in_array($statusCode, array(404, 410), true) && $endpoint !== '') {
                $deact = $conn->prepare('UPDATE web_push_subscriptions SET is_active = 0 WHERE endpoint = ?');
                $deact->bind_param('s', $endpoint);
                $deact->execute();
                $deact->close();
            }
        }
    }

    return array(
        'sent' => $sentCount,
        'queued' => $queued,
        'events' => count($events),
        'subscriptions' => count($subscriptions),
    );
}
