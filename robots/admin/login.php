<?php
ob_start();

register_shutdown_function(function () {
    $err = error_get_last();
    if (!is_array($err)) {
        return;
    }
    $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
    if (!in_array((int) ($err['type'] ?? 0), $fatalTypes, true)) {
        return;
    }
    ob_end_clean();
    if (!headers_sent()) {
        header('HTTP/1.1 500 Internal Server Error');
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Admin Error</title>';
    echo '<style>body{font-family:Arial,sans-serif;background:#0f172a;color:#f8fafc;display:flex;';
    echo 'align-items:center;justify-content:center;min-height:100vh;margin:0}';
    echo '.box{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:24px 28px;';
    echo 'max-width:560px;width:90%;box-shadow:0 8px 30px rgba(0,0,0,.4);text-align:center}';
    echo 'h1{font-size:20px;margin:0 0 10px;color:#f87171}p{margin:6px 0;font-size:14px;color:#94a3b8}';
    echo 'a{color:#60a5fa;text-decoration:none}';
    echo '</style></head><body><div class="box">';
    echo '<h1>&#9888; Admin Page Error</h1>';
    echo '<p>A server error occurred. Check PHP version, DB credentials, and hosting error_log.</p>';
    echo '<p><a href="login.php">Try again</a></p>';
    echo '</div></body></html>';
    exit;
});

session_start();
require_once '../products/config/config.php';
require_once '../products/includes/db.php';

function isSafeAdminRedirect($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return false;
    }
    if (preg_match('/^https?:/i', $value)) {
        return false;
    }
    if (strpos($value, '..') !== false || strpos($value, '/') !== false || strpos($value, '\\') !== false) {
        return false;
    }
    return (bool) preg_match('/^[a-zA-Z0-9._-]+\.php(?:\?[a-zA-Z0-9_=&-]*)?$/', $value);
}

$redirectAfterLogin = trim((string) ($_GET['redirect'] ?? $_POST['redirect'] ?? 'dashboard.php'));
if (!isSafeAdminRedirect($redirectAfterLogin)) {
    $redirectAfterLogin = 'dashboard.php';
}

if (!empty($_SESSION['user_id']) && strtolower((string) ($_SESSION['user_type'] ?? '')) === 'admin') {
    if (!headers_sent()) {
        header('Location: ' . $redirectAfterLogin);
    } else {
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8">';
        echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirectAfterLogin, ENT_QUOTES, 'UTF-8') . '">';
        echo '<script>window.location.href=' . json_encode($redirectAfterLogin) . ';</script>';
        echo '</head><body></body></html>';
    }
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailOrUser = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($emailOrUser === '' || $password === '') {
        $error = 'Please fill in all fields.';
    } else {
        try {
            $db = new Database();
            $conn = $db->getConnection();

            $stmt = $conn->prepare(
                'SELECT id, username, email, password, first_name, last_name, user_type, is_active
                 FROM users
                 WHERE (email = ? OR username = ?)
                 LIMIT 1'
            );
            $stmt->bind_param('ss', $emailOrUser, $emailOrUser);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            $db->closeConnection();

            if (!$user) {
                $error = 'No account found with that email or username.';
            } else {
                $passwordValid = password_verify($password, $user['password'])
                    || ($user['password'] === $password)
                    || (strlen((string) $user['password']) === 32 && strtolower((string) $user['password']) === md5($password));

                if (!$passwordValid) {
                    $error = 'Incorrect password.';
                } elseif ((int) $user['is_active'] === 0) {
                    $error = 'Your account is inactive.';
                } elseif (strtolower((string) ($user['user_type'] ?? '')) !== 'admin') {
                    $error = 'Admin access only.';
                } else {
                    $_SESSION['user_id'] = (int) $user['id'];
                    $_SESSION['username'] = (string) $user['username'];
                    $_SESSION['user_type'] = (string) $user['user_type'];
                    $_SESSION['name'] = trim(((string) $user['first_name']) . ' ' . ((string) $user['last_name'])) ?: (string) $user['username'];

                    if (!headers_sent()) {
                        header('Location: ' . $redirectAfterLogin);
                    } else {
                        echo '<!DOCTYPE html><html><head><meta charset="UTF-8">';
                        echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirectAfterLogin, ENT_QUOTES, 'UTF-8') . '">';
                        echo '<script>window.location.href=' . json_encode($redirectAfterLogin) . ';</script>';
                        echo '</head><body></body></html>';
                    }
                    exit;
                }
            }
        } catch (Throwable $e) {
            $error = 'Something went wrong. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">    <link rel="icon" href="../favicon.svg?v=20260429f" type="image/svg+xml">
    <link rel="shortcut icon" href="../favicon.png?v=20260429f" type="image/png">
    <link rel="apple-touch-icon" href="../images/logo.png">
    <title>Admin Login - Accounts Bazar</title>
    <link rel="stylesheet" href="css/admin.css">
    <style>
        .admin-login-wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: linear-gradient(135deg, #0f172a, #1e3a8a);
        }
        .admin-login-card {
            width: 100%;
            max-width: 420px;
            background: #ffffff;
            border-radius: 16px;
            padding: 28px 24px;
            box-shadow: 0 20px 45px rgba(2, 6, 23, 0.32);
        }
        .admin-login-title {
            font-size: 28px;
            font-weight: 900;
            color: #0f172a;
            margin-bottom: 4px;
            text-align: center;
        }
        .admin-login-subtitle {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 20px;
            text-align: center;
        }
        .admin-login-row {
            margin-bottom: 14px;
        }
        .admin-login-row label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
        }
        .admin-login-row input {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 11px 12px;
            font-size: 14px;
            outline: none;
        }
        .admin-login-row input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.14);
        }
        .admin-login-btn {
            width: 100%;
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, #1d4ed8, #0f172a);
            color: #ffffff;
            font-size: 14px;
            font-weight: 800;
            padding: 12px;
            cursor: pointer;
        }
        .admin-login-error {
            margin-bottom: 12px;
            border-radius: 10px;
            padding: 9px 11px;
            font-size: 13px;
            font-weight: 700;
            background: #fee2e2;
            color: #991b1b;
        }
        .admin-login-back {
            margin-top: 12px;
            text-align: center;
            font-size: 12px;
        }
        .admin-login-back a {
            color: #1d4ed8;
            text-decoration: none;
            font-weight: 700;
        }
    </style>
</head>
<body>
    <div class="admin-login-wrap">
        <div class="admin-login-card">
            <h1 class="admin-login-title">Admin Login</h1>
            <p class="admin-login-subtitle">Sign in to manage dashboard, orders and messages.</p>

            <?php if ($error !== ''): ?>
                <div class="admin-login-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirectAfterLogin); ?>">

                <div class="admin-login-row">
                    <label for="email">Email or Username</label>
                    <input id="email" name="email" type="text" required>
                </div>

                <div class="admin-login-row">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" required>
                </div>

                <button type="submit" class="admin-login-btn">Login as Admin</button>
            </form>

            <p class="admin-login-back"><a href="../index.php">Back to Website</a></p>
        </div>
    </div>
</body>
</html>
