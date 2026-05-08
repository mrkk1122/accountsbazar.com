<?php
require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();
require_once '../products/config/config.php';
require_once '../products/includes/db.php';

function ensureSupportTables($conn) {
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
            CONSTRAINT fk_support_messages_thread_admin FOREIGN KEY (thread_id) REFERENCES support_threads(id) ON DELETE CASCADE
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

function markAdminActive($conn, $adminUserId) {
    $stmt = $conn->prepare('INSERT INTO support_admin_presence (admin_user_id) VALUES (?) ON DUPLICATE KEY UPDATE last_active_at = CURRENT_TIMESTAMP');
    $stmt->bind_param('i', $adminUserId);
    $stmt->execute();
    $stmt->close();
}

$message = '';
$error = '';
$requestedThreadId = (int) ($_GET['thread_id'] ?? $_POST['thread_id'] ?? 0);
$selectedThreadId = $requestedThreadId;
$threadViewMode = trim((string) ($_GET['view'] ?? $_POST['view'] ?? ''));
$threadOnlyView = ($threadViewMode === 'thread' && $requestedThreadId > 0);
$adminUserId = (int) $_SESSION['user_id'];

try {
    $db = new Database();
    $conn = $db->getConnection();
    ensureSupportTables($conn);
    markAdminActive($conn, $adminUserId);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = trim((string) ($_POST['action'] ?? ''));

        if ($action === 'heartbeat') {
            header('Content-Type: application/json');
            echo json_encode(array('success' => true));
            exit;
        }

        if ($action === 'fetch_messages') {
            $threadId = (int) ($_POST['thread_id'] ?? 0);
            $sinceId  = (int) ($_POST['since_id'] ?? 0);
            header('Content-Type: application/json');
            if ($threadId <= 0) {
                echo json_encode(array('success' => false));
                exit;
            }
            markAdminActive($conn, $adminUserId);
            if ($sinceId > 0) {
                $stmt = $conn->prepare('SELECT id, sender_type, message_text, created_at FROM support_messages WHERE thread_id = ? AND id > ? ORDER BY id ASC');
                $stmt->bind_param('ii', $threadId, $sinceId);
            } else {
                $stmt = $conn->prepare('SELECT id, sender_type, message_text, created_at FROM support_messages WHERE thread_id = ? ORDER BY id ASC');
                $stmt->bind_param('i', $threadId);
            }
            $stmt->execute();
            $rows = array();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $rows[] = array(
                    'id'           => (int) $row['id'],
                    'sender_type'  => $row['sender_type'],
                    'message_text' => $row['message_text'],
                    'created_at'   => $row['created_at']
                );
            }
            $stmt->close();
            echo json_encode(array('success' => true, 'messages' => $rows));
            exit;
        }

        if ($action === 'ajax_reply') {
            $threadId  = (int) ($_POST['thread_id'] ?? 0);
            $replyText = trim((string) ($_POST['reply_text'] ?? ''));
            $sinceId   = (int) ($_POST['since_id'] ?? 0);
            header('Content-Type: application/json');
            if ($threadId <= 0 || $replyText === '') {
                echo json_encode(array('success' => false, 'message' => 'Reply text required.'));
                exit;
            }
            $replyText = substr($replyText, 0, 2000);
            $stmt = $conn->prepare('INSERT INTO support_messages (thread_id, sender_type, message_text) VALUES (?, "admin", ?)');
            $stmt->bind_param('is', $threadId, $replyText);
            $stmt->execute();
            $conn->query('UPDATE support_threads SET updated_at = CURRENT_TIMESTAMP WHERE id = ' . $threadId);
            $stmt->close();
            // Return all messages since sinceId
            if ($sinceId > 0) {
                $stmt2 = $conn->prepare('SELECT id, sender_type, message_text, created_at FROM support_messages WHERE thread_id = ? AND id > ? ORDER BY id ASC');
                $stmt2->bind_param('ii', $threadId, $sinceId);
            } else {
                $stmt2 = $conn->prepare('SELECT id, sender_type, message_text, created_at FROM support_messages WHERE thread_id = ? ORDER BY id ASC');
                $stmt2->bind_param('i', $threadId);
            }
            $stmt2->execute();
            $rows = array();
            $res = $stmt2->get_result();
            while ($row = $res->fetch_assoc()) {
                $rows[] = array(
                    'id'           => (int) $row['id'],
                    'sender_type'  => $row['sender_type'],
                    'message_text' => $row['message_text'],
                    'created_at'   => $row['created_at']
                );
            }
            $stmt2->close();
            echo json_encode(array('success' => true, 'messages' => $rows));
            exit;
        }

        if ($action === 'reply') {
            $threadId = (int) ($_POST['thread_id'] ?? 0);
            $replyText = trim((string) ($_POST['reply_text'] ?? ''));
            if ($threadId <= 0 || $replyText === '') {
                $error = 'Reply text is required.';
            } else {
                $replyText = substr($replyText, 0, 2000);
                $stmt = $conn->prepare('INSERT INTO support_messages (thread_id, sender_type, message_text) VALUES (?, "admin", ?)');
                $stmt->bind_param('is', $threadId, $replyText);
                if ($stmt->execute()) {
                    $message = 'Reply sent successfully.';
                    $conn->query('UPDATE support_threads SET updated_at = CURRENT_TIMESTAMP WHERE id = ' . $threadId);
                } else {
                    $error = 'Could not send reply.';
                }
                $stmt->close();
            }
        }

        if ($action === 'delete_message') {
            $messageId = (int) ($_POST['message_id'] ?? 0);
            if ($messageId > 0) {
                $stmt = $conn->prepare('DELETE FROM support_messages WHERE id = ?');
                $stmt->bind_param('i', $messageId);
                if ($stmt->execute()) {
                    $message = 'Message deleted successfully.';
                } else {
                    $error = 'Failed to delete message.';
                }
                $stmt->close();
            }
        }

        if ($action === 'delete_thread') {
            $threadId = (int) ($_POST['thread_id'] ?? 0);
            if ($threadId > 0) {
                $stmt = $conn->prepare('DELETE FROM support_threads WHERE id = ?');
                $stmt->bind_param('i', $threadId);
                if ($stmt->execute()) {
                    $message = 'Conversation deleted successfully.';
                    if ($selectedThreadId === $threadId) {
                        $selectedThreadId = 0;
                    }
                } else {
                    $error = 'Failed to delete conversation.';
                }
                $stmt->close();
            }
        }
    }

    $threads = array();
    $threadSql = "SELECT
        t.id,
        t.customer_name,
        t.visitor_token,
        t.updated_at,
        t.created_at,
        u.email,
        MAX(m.created_at) AS last_message_at,
        SUBSTRING_INDEX(GROUP_CONCAT(m.message_text ORDER BY m.id DESC SEPARATOR '|||'), '|||', 1) AS last_message
    FROM support_threads t
    LEFT JOIN users u ON u.id = t.user_id
    LEFT JOIN support_messages m ON m.thread_id = t.id
    GROUP BY t.id, t.customer_name, t.visitor_token, t.updated_at, t.created_at, u.email
    ORDER BY COALESCE(MAX(m.created_at), t.updated_at) DESC, t.id DESC";
    $threadRes = $conn->query($threadSql);
    if ($threadRes) {
        while ($row = $threadRes->fetch_assoc()) {
            $threads[] = $row;
        }
    }

    if ($selectedThreadId <= 0 && !empty($threads)) {
        $selectedThreadId = (int) $threads[0]['id'];
    }

    $activeThread = null;
    $messages = array();
    if ($selectedThreadId > 0) {
        $threadStmt = $conn->prepare('SELECT t.id, t.customer_name, t.visitor_token, t.created_at, u.email FROM support_threads t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ? LIMIT 1');
        $threadStmt->bind_param('i', $selectedThreadId);
        $threadStmt->execute();
        $activeThread = $threadStmt->get_result()->fetch_assoc();
        $threadStmt->close();

        if ($activeThread) {
            $msgStmt = $conn->prepare('SELECT id, sender_type, message_text, created_at FROM support_messages WHERE thread_id = ? ORDER BY id ASC');
            $msgStmt->bind_param('i', $selectedThreadId);
            $msgStmt->execute();
            $msgRes = $msgStmt->get_result();
            if ($msgRes) {
                while ($row = $msgRes->fetch_assoc()) {
                    $messages[] = $row;
                }
            }
            $msgStmt->close();
        }
    }
} catch (Throwable $e) {
    $error = 'Support messages could not be loaded.';
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">    <link rel="icon" href="../favicon.svg?v=20260429f" type="image/svg+xml">
    <link rel="shortcut icon" href="../favicon.png?v=20260429f" type="image/png">
    <link rel="apple-touch-icon" href="../images/logo.png">
    <title>Support Messages - Admin</title>
    <link rel="stylesheet" href="css/admin.css">
    <style>
        .notice { margin-bottom: 14px; padding: 10px 12px; border-radius: 8px; font-weight: 700; }
        .notice.success { background: #dcfce7; color: #166534; }
        .notice.error { background: #fee2e2; color: #991b1b; }
        .support-layout { display: grid; grid-template-columns: 340px 1fr; gap: 18px; }
        .support-layout.thread-only { grid-template-columns: 1fr; }
        .support-layout.thread-only .thread-list { display: none; }
        .thread-list, .thread-view { background: #fff; border-radius: 14px; box-shadow: 0 10px 22px rgba(15,23,42,0.08); }
        .thread-list { padding: 12px; }
        .thread-link { display: block; padding: 12px; border-radius: 10px; text-decoration: none; color: #0f172a; border: 1px solid #e2e8f0; margin-bottom: 10px; }
        .thread-link.active { background: #eff6ff; border-color: #93c5fd; }
        .thread-name { font-size: 14px; font-weight: 900; margin-bottom: 2px; }
        .thread-meta { font-size: 12px; color: #64748b; margin-bottom: 6px; }
        .thread-preview { font-size: 12px; color: #334155; overflow-wrap: anywhere; }
        .thread-view { padding: 16px; }
        .thread-header { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; margin-bottom: 12px; }
        .thread-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .back-list-btn { border: none; border-radius: 8px; background: #e2e8f0; color: #0f172a; font-size: 12px; font-weight: 800; padding: 7px 10px; text-decoration: none; }
        .admin-btn { border: none; border-radius: 10px; padding: 9px 14px; font-weight: 800; cursor: pointer; }
        .admin-btn-danger { background: #b91c1c; color: #fff; }
        .admin-btn-primary { background: #1d4ed8; color: #fff; }
        .message-stack { display: flex; flex-direction: column; gap: 10px; max-height: 480px; overflow-y: auto; padding-right: 4px; }
        .message-item { border-radius: 12px; padding: 12px; border: 1px solid #e2e8f0; background: #f8fafc; }
        .message-item.admin { background: #dbeafe; border-color: #93c5fd; }
        .message-top { display: flex; justify-content: space-between; gap: 12px; align-items: center; margin-bottom: 6px; }
        .message-role { font-size: 12px; font-weight: 900; color: #0f172a; }
        .message-time { font-size: 11px; color: #64748b; }
        .message-text { font-size: 13px; color: #334155; overflow-wrap: anywhere; }
        .message-delete { margin-top: 8px; border: none; background: transparent; color: #b91c1c; font-size: 12px; font-weight: 800; cursor: pointer; padding: 0; }
        .reply-form { margin-top: 16px; }
        .reply-form textarea { width: 100%; min-height: 100px; border: 1px solid #cbd5e1; border-radius: 12px; padding: 12px; resize: vertical; }
        .reply-actions { margin-top: 10px; display: flex; justify-content: flex-end; }
        .notify-permission-box {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            background: #f8fafc;
            margin-bottom: 12px;
        }
        .notify-permission-text {
            font-size: 12px;
            font-weight: 700;
            color: #0f172a;
        }
        .notify-permission-btn {
            border: none;
            border-radius: 8px;
            background: #1d4ed8;
            color: #fff;
            font-size: 12px;
            font-weight: 800;
            padding: 8px 10px;
            cursor: pointer;
            white-space: nowrap;
        }
        .notify-permission-btn[disabled] {
            background: #94a3b8;
            cursor: not-allowed;
        }
        @media (max-width: 900px) { .support-layout { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="admin-container">
        <button class="admin-menu-toggle" type="button" aria-label="Toggle menu" aria-expanded="false" aria-controls="admin-sidebar">
            <span></span><span></span><span></span>
        </button>
        <div class="admin-overlay"></div>
        <nav class="admin-sidebar" id="admin-sidebar">
            <div class="admin-logo"><h2>Admin Panel</h2></div>
            <ul class="menu">
                <li><a href="index.php">Dashboard</a></li>
                <li><a href="products.php">Products</a></li>
                <li><a href="ai-prompts.php">AI Prompts</a></li>
                <li><a href="user.php">Users</a></li>
                <li><a href="orders.php">Orders</a></li>
                <li><a href="support-messages.php" class="active">Messages</a></li>
                <li><a href="reports.php">Reports</a></li>
                <li><a href="settings.php">Settings</a></li>
                <li><a href="logout.php">Logout</a></li>
            </ul>
        </nav>

        <div class="admin-main">
            <header class="admin-header">
                <h1>Support Messages</h1>
            </header>
            <main class="admin-content">
                <?php if ($message !== ''): ?><div class="notice success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
                <?php if ($error !== ''): ?><div class="notice error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

                <div class="notify-permission-box" id="notify-permission-box">
                    <div class="notify-permission-text" id="notify-permission-text">Enable mobile/browser notification permission for new help messages.</div>
                    <button class="notify-permission-btn" id="notify-permission-btn" type="button">Enable Notification</button>
                </div>

                <div class="support-layout<?php echo $threadOnlyView ? ' thread-only' : ''; ?>">
                    <div class="thread-list">
                        <?php if (!empty($threads)): ?>
                            <?php foreach ($threads as $thread): ?>
                                <a class="thread-link<?php echo (int) $thread['id'] === $selectedThreadId ? ' active' : ''; ?>" href="support-messages.php?thread_id=<?php echo (int) $thread['id']; ?>&view=thread">
                                    <div class="thread-name"><?php echo htmlspecialchars((string) ($thread['customer_name'] ?: 'Guest User')); ?></div>
                                    <div class="thread-meta"><?php echo htmlspecialchars((string) ($thread['email'] ?? $thread['visitor_token'])); ?></div>
                                    <div class="thread-preview"><?php echo htmlspecialchars((string) ($thread['last_message'] ?? 'No messages yet')); ?></div>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="thread-preview">No support messages yet.</div>
                        <?php endif; ?>
                    </div>

                    <div class="thread-view">
                        <?php if ($activeThread): ?>
                            <div class="thread-header">
                                <div>
                                    <h2 style="font-size:20px;font-weight:900;color:#0f172a;"><?php echo htmlspecialchars((string) ($activeThread['customer_name'] ?: 'Guest User')); ?></h2>
                                    <div class="thread-meta"><?php echo htmlspecialchars((string) ($activeThread['email'] ?? $activeThread['visitor_token'])); ?></div>
                                </div>
                                <div class="thread-actions">
                                    <?php if ($threadOnlyView): ?>
                                        <a href="support-messages.php" class="back-list-btn">Back to list</a>
                                    <?php endif; ?>
                                    <form method="POST" onsubmit="return confirm('Delete this full conversation?');">
                                        <input type="hidden" name="action" value="delete_thread">
                                        <input type="hidden" name="thread_id" value="<?php echo (int) $activeThread['id']; ?>">
                                        <button class="admin-btn admin-btn-danger" type="submit">Delete Chat</button>
                                    </form>
                                </div>
                            </div>

                            <div class="message-stack" id="admin-message-stack">
                                <?php if (!empty($messages)): ?>
                                    <?php foreach ($messages as $item): ?>
                                        <div class="message-item <?php echo $item['sender_type'] === 'admin' ? 'admin' : ''; ?>" data-msg-id="<?php echo (int) $item['id']; ?>">
                                            <div class="message-top">
                                                <span class="message-role"><?php echo $item['sender_type'] === 'admin' ? 'Admin Reply' : 'User Message'; ?></span>
                                                <span class="message-time"><?php echo htmlspecialchars((string) $item['created_at']); ?></span>
                                            </div>
                                            <div class="message-text"><?php echo nl2br(htmlspecialchars((string) $item['message_text'])); ?></div>
                                            <form method="POST" onsubmit="return confirm('Delete this message?');">
                                                <input type="hidden" name="action" value="delete_message">
                                                <input type="hidden" name="thread_id" value="<?php echo (int) $activeThread['id']; ?>">
                                                <input type="hidden" name="view" value="thread">
                                                <input type="hidden" name="message_id" value="<?php echo (int) $item['id']; ?>">
                                                <button class="message-delete" type="submit">Delete message</button>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="thread-preview" id="admin-no-msg">No messages in this conversation.</div>
                                <?php endif; ?>
                            </div>

                            <form class="reply-form" id="admin-reply-form">
                                <input type="hidden" name="action" value="ajax_reply">
                                <input type="hidden" name="thread_id" value="<?php echo (int) $activeThread['id']; ?>">
                                <textarea name="reply_text" id="admin-reply-text" placeholder="Write admin reply..." required></textarea>
                                <div class="reply-actions">
                                    <button class="admin-btn admin-btn-primary" type="submit" id="admin-reply-btn">Send Reply</button>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="thread-preview">Select a conversation to view and reply.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>
    <script src="js/admin.js"></script>
    <script>
    (function() {
        // Heartbeat every 30s
        setInterval(function () {
            var body = new URLSearchParams();
            body.set('action', 'heartbeat');
            fetch('support-messages.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).catch(function () {});
        }, 30000);

        // Live chat polling
        var stack = document.getElementById('admin-message-stack');
        var replyForm = document.getElementById('admin-reply-form');
        var replyText = document.getElementById('admin-reply-text');
        var replyBtn = document.getElementById('admin-reply-btn');
        var notifyBox = document.getElementById('notify-permission-box');
        var notifyText = document.getElementById('notify-permission-text');
        var notifyBtn = document.getElementById('notify-permission-btn');

        if (!stack || !replyForm) return;

        var threadIdInput = replyForm.querySelector('[name="thread_id"]');
        if (!threadIdInput) return;
        var threadId = parseInt(threadIdInput.value, 10);
        var notifiedIds = {};
        var threadNameEl = document.querySelector('.thread-header h2');
        var threadName = threadNameEl ? threadNameEl.textContent.trim() : 'Client';

        function refreshPermissionUi() {
            if (!notifyBox || !notifyText || !notifyBtn) {
                return;
            }
            if (!('Notification' in window)) {
                notifyText.textContent = 'This browser does not support notifications.';
                notifyBtn.disabled = true;
                return;
            }
            if (Notification.permission === 'granted') {
                notifyText.textContent = 'Notification permission granted. You will receive mobile/browser alerts.';
                notifyBtn.disabled = true;
            } else if (Notification.permission === 'denied') {
                notifyText.textContent = 'Notification blocked. Allow it from browser site settings.';
                notifyBtn.disabled = true;
            } else {
                notifyText.textContent = 'Enable mobile/browser notification permission for new help messages.';
                notifyBtn.disabled = false;
            }
        }

        function requestNotificationPermission() {
            if (!('Notification' in window)) {
                refreshPermissionUi();
                return;
            }
            if (Notification.permission === 'default') {
                Notification.requestPermission().then(function() {
                    refreshPermissionUi();
                }).catch(function() {
                    refreshPermissionUi();
                });
            } else {
                refreshPermissionUi();
            }
        }

        function sendMessageNotification(item) {
            if (!('Notification' in window) || Notification.permission !== 'granted') {
                return;
            }
            if (!item || item.sender_type !== 'user') {
                return;
            }
            var msgId = parseInt(item.id, 10);
            if (!msgId || notifiedIds[msgId]) {
                return;
            }
            notifiedIds[msgId] = true;

            var bodyText = String(item.message_text || '').trim();
            if (bodyText.length > 120) {
                bodyText = bodyText.slice(0, 117) + '...';
            }

            try {
                var n = new Notification('New Help Message - ' + threadName, {
                    body: bodyText || 'Client sent a new message.',
                    icon: '../favicon.png',
                    badge: '../favicon.png',
                    vibrate: [120, 70, 120]
                });
                n.onclick = function() {
                    window.focus();
                    this.close();
                };
            } catch (e) {
            }
        }

        if (notifyBtn) {
            notifyBtn.addEventListener('click', requestNotificationPermission);
        }
        refreshPermissionUi();
        setTimeout(requestNotificationPermission, 600);

        function escHtml(str) {
            return String(str)
                .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
        }

        function nlToBr(str) {
            return escHtml(str).replace(/\n/g, '<br>');
        }

        function getLastId() {
            var items = stack.querySelectorAll('[data-msg-id]');
            var last = 0;
            items.forEach(function(el) {
                var id = parseInt(el.getAttribute('data-msg-id'), 10);
                if (id > last) last = id;
            });
            return last;
        }

        function appendMessages(msgs, allowNotify) {
            if (!msgs || msgs.length === 0) return;
            var noMsg = document.getElementById('admin-no-msg');
            if (noMsg) noMsg.remove();
            msgs.forEach(function(item) {
                var div = document.createElement('div');
                div.className = 'message-item' + (item.sender_type === 'admin' ? ' admin' : '');
                div.setAttribute('data-msg-id', item.id);
                div.innerHTML =
                    '<div class="message-top">' +
                        '<span class="message-role">' + (item.sender_type === 'admin' ? 'Admin Reply' : 'User Message') + '</span>' +
                        '<span class="message-time">' + escHtml(item.created_at) + '</span>' +
                    '</div>' +
                    '<div class="message-text">' + nlToBr(item.message_text) + '</div>';
                stack.appendChild(div);

                if (allowNotify) {
                    sendMessageNotification(item);
                }
            });
            stack.scrollTop = stack.scrollHeight;
        }

        // Poll every 2.5 seconds
        setInterval(function() {
            var since = getLastId();
            var body = new URLSearchParams();
            body.set('action', 'fetch_messages');
            body.set('thread_id', threadId);
            body.set('since_id', since);
            fetch('support-messages.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function(r) { return r.json(); })
              .then(function(res) {
                  if (res && res.success && res.messages && res.messages.length > 0) {
                      appendMessages(res.messages, true);
                  }
              }).catch(function() {});
        }, 2500);

        // Scroll to bottom on load
        stack.scrollTop = stack.scrollHeight;

        // AJAX reply (no page reload)
        replyForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var text = replyText.value.trim();
            if (!text) return;
            replyBtn.disabled = true;
            var since = getLastId();
            var body = new URLSearchParams();
            body.set('action', 'ajax_reply');
            body.set('thread_id', threadId);
            body.set('reply_text', text);
            body.set('since_id', since);
            fetch('support-messages.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function(r) { return r.json(); })
              .then(function(res) {
                  if (res && res.success) {
                      replyText.value = '';
                      appendMessages(res.messages || [], false);
                  } else {
                      alert('Could not send reply.');
                  }
              }).catch(function() { alert('Send failed.'); })
              .finally(function() {
                  replyBtn.disabled = false;
                  replyText.focus();
              });
        });
    })();
    </script>
</body>
</html>
