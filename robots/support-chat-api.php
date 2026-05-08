<?php
session_start();
require_once 'products/config/config.php';
require_once 'products/includes/db.php';

header('Content-Type: application/json');

function jsonResponse($payload, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function normalizeVisitorToken($token) {
    $token = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $token);
    return substr($token, 0, 64);
}

function ensureChatTables($conn) {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS support_threads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            visitor_token VARCHAR(64) NOT NULL,
            customer_name VARCHAR(120) DEFAULT '',
            status ENUM('open', 'closed') DEFAULT 'open',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_visitor_thread (visitor_token),
            INDEX idx_user_id (user_id)
        )"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS support_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            thread_id INT NOT NULL,
            sender_type ENUM('user', 'admin') NOT NULL,
            message_text TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_thread_id (thread_id),
            CONSTRAINT fk_support_messages_thread FOREIGN KEY (thread_id) REFERENCES support_threads(id) ON DELETE CASCADE
        )"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS support_admin_presence (
            admin_user_id INT PRIMARY KEY,
            last_active_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_last_active_at (last_active_at)
        )"
    );
}

function isAdminActive($conn) {
    $result = $conn->query("SELECT 1 FROM support_admin_presence WHERE last_active_at >= (NOW() - INTERVAL 2 MINUTE) LIMIT 1");
    if (!$result) {
        return false;
    }
    return (bool) $result->fetch_row();
}

function resolveDisplayName($conn, $userId, $fallbackName) {
    if ($userId > 0) {
        $stmt = $conn->prepare('SELECT first_name, last_name, username FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $name = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));
            if ($name !== '') {
                return $name;
            }
            if (!empty($row['username'])) {
                return (string) $row['username'];
            }
        }
    }

    $fallbackName = trim((string) $fallbackName);
    return $fallbackName !== '' ? substr($fallbackName, 0, 120) : 'Guest User';
}

function getOrCreateThread($conn, $visitorToken, $userId, $displayName) {
    $thread = null;
    if ($userId > 0) {
        $stmt = $conn->prepare('SELECT id, user_id, visitor_token, customer_name, status FROM support_threads WHERE user_id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $thread = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$thread) {
        $stmt = $conn->prepare('SELECT id, user_id, visitor_token, customer_name, status FROM support_threads WHERE visitor_token = ? LIMIT 1');
        $stmt->bind_param('s', $visitorToken);
        $stmt->execute();
        $thread = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if ($thread) {
        $threadId = (int) $thread['id'];
        $stmt = $conn->prepare('UPDATE support_threads SET user_id = ?, customer_name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->bind_param('isi', $userId, $displayName, $threadId);
        $stmt->execute();
        $stmt->close();
        $thread['user_id'] = $userId;
        $thread['customer_name'] = $displayName;
        return $thread;
    }

    $stmt = $conn->prepare('INSERT INTO support_threads (user_id, visitor_token, customer_name) VALUES (?, ?, ?)');
    $stmt->bind_param('iss', $userId, $visitorToken, $displayName);
    $stmt->execute();
    $threadId = (int) $conn->insert_id;
    $stmt->close();

    return array(
        'id' => $threadId,
        'user_id' => $userId,
        'visitor_token' => $visitorToken,
        'customer_name' => $displayName,
        'status' => 'open'
    );
}

function fetchMessages($conn, $threadId, $sinceId = 0) {
    $items = array();
    if ($sinceId > 0) {
        $stmt = $conn->prepare('SELECT id, sender_type, message_text, created_at FROM support_messages WHERE thread_id = ? AND id > ? ORDER BY id ASC');
        $stmt->bind_param('ii', $threadId, $sinceId);
    } else {
        $stmt = $conn->prepare('SELECT id, sender_type, message_text, created_at FROM support_messages WHERE thread_id = ? ORDER BY id ASC');
        $stmt->bind_param('i', $threadId);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $items[] = array(
                'id' => (int) $row['id'],
                'sender_type' => (string) $row['sender_type'],
                'message_text' => (string) $row['message_text'],
                'created_at' => (string) $row['created_at']
            );
        }
    }
    $stmt->close();
    return $items;
}

function getLastMessageId($conn, $threadId) {
    $result = $conn->query('SELECT MAX(id) AS last_id FROM support_messages WHERE thread_id = ' . (int) $threadId);
    if ($result) {
        $row = $result->fetch_row();
        return (int) ($row[0] ?? 0);
    }
    return 0;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(array('success' => false, 'message' => 'POST request required.'), 405);
}

$action = trim((string) ($_POST['action'] ?? ''));
$visitorToken = normalizeVisitorToken($_POST['visitor_token'] ?? '');
if ($visitorToken === '') {
    jsonResponse(array('success' => false, 'message' => 'Missing visitor token.'), 400);
}

$userId = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

if ($userId <= 0) {
    if ($action === 'load') {
        jsonResponse(array(
            'success' => true,
            'locked' => true,
            'admin_active' => false,
            'messages' => array()
        ));
    }

    if ($action === 'send') {
        jsonResponse(array('success' => false, 'locked' => true, 'message' => 'Please login to send messages.'), 401);
    }
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    ensureChatTables($conn);

    $displayName = resolveDisplayName($conn, $userId, $_POST['name'] ?? ($_SESSION['name'] ?? ''));
    $thread = getOrCreateThread($conn, $visitorToken, $userId, $displayName);
    $threadId = (int) $thread['id'];

    if ($action === 'load') {
        $sinceId = (int) ($_POST['since_id'] ?? 0);
        $messages = fetchMessages($conn, $threadId, $sinceId);
        $lastId = $sinceId > 0 && count($messages) > 0
            ? (int) end($messages)['id']
            : getLastMessageId($conn, $threadId);
        jsonResponse(array(
            'success' => true,
            'admin_active' => isAdminActive($conn),
            'last_id' => $lastId,
            'thread' => array(
                'id' => $threadId,
                'customer_name' => (string) $thread['customer_name']
            ),
            'messages' => $messages
        ));
    }

    if ($action === 'send') {
        $messageText = trim((string) ($_POST['message'] ?? ''));
        if ($messageText === '') {
            jsonResponse(array('success' => false, 'message' => 'Message is required.'), 422);
        }

        $messageText = substr($messageText, 0, 2000);
        $stmt = $conn->prepare('INSERT INTO support_messages (thread_id, sender_type, message_text) VALUES (?, "user", ?)');
        $stmt->bind_param('is', $threadId, $messageText);
        $stmt->execute();
        $newMsgId = (int) $conn->insert_id;
        $stmt->close();

        $conn->query('UPDATE support_threads SET updated_at = CURRENT_TIMESTAMP WHERE id = ' . $threadId);

        $sinceId = (int) ($_POST['since_id'] ?? 0);
        $messages = fetchMessages($conn, $threadId, $sinceId);
        $lastId = count($messages) > 0 ? (int) end($messages)['id'] : $newMsgId;
        jsonResponse(array(
            'success' => true,
            'admin_active' => isAdminActive($conn),
            'last_id' => $lastId,
            'thread' => array(
                'id' => $threadId,
                'customer_name' => (string) $thread['customer_name']
            ),
            'messages' => $messages
        ));
    }

    jsonResponse(array('success' => false, 'message' => 'Unknown action.'), 400);
} catch (Throwable $e) {
    jsonResponse(array('success' => false, 'message' => 'Chat service unavailable.'), 500);
}
