<?php
/**
 * Admin Authentication Include
 */

if (!ob_get_level()) {
    ob_start();
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('adminFatalFallbackRegistered')) {
    function adminFatalFallbackRegistered() {
        return true;
    }

    register_shutdown_function(function () {
        $err = error_get_last();
        if (!is_array($err)) {
            return;
        }

        $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
        if (!in_array((int) ($err['type'] ?? 0), $fatalTypes, true)) {
            return;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
        }

        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>Admin Error</title>';
        echo '<style>*{box-sizing:border-box}body{font-family:Arial,sans-serif;background:#0f172a;';
        echo 'color:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}';
        echo '.box{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:24px 28px;';
        echo 'max-width:580px;width:90%;text-align:center}';
        echo 'h1{font-size:20px;margin:0 0 10px;color:#f87171}p{margin:6px 0;font-size:14px;color:#94a3b8}';
        echo 'a{color:#60a5fa}</style>';
        echo '</head><body><div class="box"><h1>&#9888; Admin Page Error</h1>';
        echo '<p>A server error occurred. Please check PHP version, DB credentials, and your error_log.</p>';
        echo '<p><a href="login.php">Go to Admin Login</a></p>';
        echo '</div></body></html>';
    });
}

if (!class_exists('Database')) {
    require_once __DIR__ . '/../../products/config/config.php';
    require_once __DIR__ . '/../../products/includes/db.php';
}

function getAdminRedirectTarget() {
    return basename((string) ($_SERVER['PHP_SELF'] ?? 'dashboard.php'));
}

function redirectToLogin() {
    $target = getAdminRedirectTarget();
    $url = 'login.php?redirect=' . urlencode($target);

    if (!headers_sent()) {
        header('Location: ' . $url);
        exit;
    }

    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">';
    echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
    echo '<script>window.location.href=' . json_encode($url) . ';</script>';
    echo '</head><body></body></html>';
    exit;
}

function resolveSessionUserTypeFromDb($userId) {
    try {
        $db = new Database();
        $conn = $db->getConnection();
        $stmt = $conn->prepare('SELECT user_type FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $db->closeConnection();
        return (string) ($row['user_type'] ?? '');
    } catch (Throwable $e) {
        return '';
    }
}

function isAdminLoggedIn() {
    if (empty($_SESSION['user_id'])) {
        return false;
    }

    if (strtolower((string) ($_SESSION['user_type'] ?? '')) === 'admin') {
        return true;
    }

    $resolvedType = resolveSessionUserTypeFromDb((int) $_SESSION['user_id']);
    if ($resolvedType !== '') {
        $_SESSION['user_type'] = $resolvedType;
    }

    return (strtolower((string) ($_SESSION['user_type'] ?? '')) === 'admin');
}

function requireAdminLogin() {
    if (!isAdminLoggedIn()) {
        redirectToLogin();
    }
}

function logout() {
    unset($_SESSION['user_id']);
    unset($_SESSION['username']);
    unset($_SESSION['user_type']);
    unset($_SESSION['name']);
    session_destroy();
}
