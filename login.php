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
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('HTTP/1.1 500 Internal Server Error');
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Error - Accounts Bazar</title>';
    echo '<style>body{font-family:Arial,sans-serif;background:#f8fafc;color:#0f172a;display:flex;';
    echo 'align-items:center;justify-content:center;min-height:100vh;margin:0}';
    echo '.box{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px 28px;';
    echo 'max-width:520px;width:90%;text-align:center}';
    echo 'h1{font-size:20px;margin:0 0 10px;color:#dc2626}p{margin:6px 0;font-size:14px;color:#475569}';
    echo 'a{color:#2563eb}</style>';
    echo '</head><body><div class="box"><h1>&#9888; Login Page Error</h1>';
    echo '<p>An error occurred. Please try again.</p>';
    echo '<p><a href="index.php">Return to Home</a></p>';
    echo '</div></body></html>';
    exit;
});

session_start();

// Redirect if already logged in
if (!empty($_SESSION['user_id'])) {
    $alreadyLoggedRedirect = trim((string) ($_GET['redirect'] ?? $_POST['redirect'] ?? 'index.php'));
    if ($alreadyLoggedRedirect === '' || preg_match('/^https?:/i', $alreadyLoggedRedirect)) {
        $alreadyLoggedRedirect = 'index.php';
    }

    if (($_SESSION['user_type'] ?? '') === '') {
        try {
            require_once 'products/config/config.php';
            require_once 'products/includes/db.php';
            $authDb = new Database();
            $authConn = $authDb->getConnection();
            $authUserId = (int) $_SESSION['user_id'];
            $authStmt = $authConn->prepare('SELECT user_type FROM users WHERE id = ? LIMIT 1');
            $authStmt->bind_param('i', $authUserId);
            $authStmt->execute();
            $authRow = $authStmt->get_result()->fetch_assoc();
            $authStmt->close();
            $authDb->closeConnection();

            if (!empty($authRow['user_type'])) {
                $_SESSION['user_type'] = (string) $authRow['user_type'];
            }
        } catch (Throwable $e) {
        }
    }

    // Non-admin users cannot be redirected into admin routes.
    if (strpos($alreadyLoggedRedirect, 'admin/') === 0 && strtolower((string) ($_SESSION['user_type'] ?? '')) !== 'admin') {
        $alreadyLoggedRedirect = 'index.php';
    }

    header('Location: ' . $alreadyLoggedRedirect);
    exit;
}

require_once 'products/config/config.php';
require_once 'products/includes/db.php';

$error = '';
$success = '';
$redirectAfterLogin = trim((string) ($_GET['redirect'] ?? $_POST['redirect'] ?? 'index.php'));
$redirectMessage = trim((string) ($_GET['message'] ?? ''));

if ($redirectMessage !== '') {
    $success = $redirectMessage;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailOrUser = trim((string) ($_POST['email'] ?? ''));
    $password    = (string) ($_POST['password'] ?? '');

    if (empty($emailOrUser) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        try {
            $db   = new Database();
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
            $user   = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            $db->closeConnection();

            if ($user) {
                // Support both hashed and plain passwords
                $passwordValid = password_verify($password, $user['password'])
                    || ($user['password'] === $password);

                if (!$passwordValid) {
                    $error = 'Incorrect password.';
                } elseif ((int) $user['is_active'] === 0) {
                    $error = 'Your account is inactive. Please contact support.';
                } else {
                    $_SESSION['user_id']   = $user['id'];
                    $_SESSION['username']  = $user['username'];
                    $_SESSION['user_type'] = $user['user_type'];
                    $_SESSION['name']      = trim($user['first_name'] . ' ' . $user['last_name']) ?: $user['username'];
                    if ($redirectAfterLogin === '' || preg_match('/^https?:/i', $redirectAfterLogin)) {
                        $redirectAfterLogin = 'index.php';
                    }
                    header('Location: ' . $redirectAfterLogin);
                    exit;
                }
            } else {
                $error = 'No account found with that email or username.';
            }
        } catch (Exception $e) {
            $error = 'Something went wrong. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<?php
$seo = [
    'title'    => 'Login – Accounts Bazar',
    'description' => 'Accounts Bazar-এ লগইন করুন। অর্ডার ট্র্যাক করুন, প্রোফাইল ম্যানেজ করুন।',
    'canonical'   => 'https://accountsbazar.com/login.php',
    'noindex'     => true,
];
require_once 'products/includes/seo.php';
?>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/mobile.css">
    <style>
        .login-page {
            min-height: 80vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 16px 80px;
            background: linear-gradient(135deg, #f0f4ff 0%, #fafafa 100%);
        }
        .login-card {
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 8px 40px rgba(15,23,42,0.12);
            padding: 40px 36px 36px;
            width: 100%;
            max-width: 420px;
        }
        .login-logo {
            text-align: center;
            margin-bottom: 8px;
        }
        .login-logo-icon {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg,#0f172a 60%,#0ea5e9);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            margin-bottom: 4px;
        }
        .login-title {
            text-align: center;
            font-size: 24px;
            font-weight: 900;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .login-subtitle {
            text-align: center;
            font-size: 14px;
            color: #64748b;
            margin-bottom: 28px;
        }
        .login-form .lf-row {
            margin-bottom: 16px;
        }
        .login-form label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 6px;
        }
        .login-form input[type="text"],
        .login-form input[type="email"],
        .login-form input[type="password"] {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid #e2e8f0;
            border-radius: 9px;
            font-size: 15px;
            color: #0f172a;
            background: #f8fafc;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
            box-sizing: border-box;
        }
        .login-form input:focus {
            border-color: #0ea5e9;
            box-shadow: 0 0 0 3px rgba(14,165,233,0.13);
            background: #fff;
        }
        .pw-wrap {
            position: relative;
        }
        .pw-wrap input {
            padding-right: 44px;
        }
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
        .forgot-pass-row {
            margin-top: 7px;
            text-align: right;
        }
        .forgot-pass-row a {
            font-size: 12px;
            font-weight: 700;
            color: #0ea5e9;
            text-decoration: none;
        }
        .forgot-pass-row a:hover {
            text-decoration: underline;
        }
        .login-alert-error {
            background: #fff0f0;
            border-left: 4px solid #e11d48;
            color: #9f1239;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 16px;
            font-weight: 600;
        }
        .login-alert-success {
            background: #f0fff4;
            border-left: 4px solid #16a34a;
            color: #15803d;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 16px;
            font-weight: 600;
        }
        .login-btn {
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
        .login-btn:hover {
            background: #0ea5e9;
            transform: translateY(-1px);
        }
        .login-divider {
            text-align: center;
            color: #94a3b8;
            font-size: 13px;
            margin: 18px 0 14px;
            position: relative;
        }
        .login-divider::before,
        .login-divider::after {
            content: '';
            display: inline-block;
            width: 38%;
            height: 1px;
            background: #e2e8f0;
            vertical-align: middle;
            margin: 0 8px;
        }
        .login-register-link {
            text-align: center;
            font-size: 14px;
            color: #64748b;
        }
        .login-register-link a {
            color: #0ea5e9;
            font-weight: 700;
            text-decoration: none;
        }
        .login-register-link a:hover { text-decoration: underline; }
        @media (max-width: 480px) {
            .login-card { padding: 28px 18px 28px; }
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

    <div class="login-page">
        <div class="login-card">
            <div class="login-logo">
                <div class="login-logo-icon">👤</div>
            </div>
            <h1 class="login-title">Welcome Back</h1>
            <p class="login-subtitle">Sign in to your Accounts Bazar account</p>

            <?php if (!empty($error)): ?>
                <div class="login-alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if (!empty($success)): ?>
                <div class="login-alert-success">✅ <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form class="login-form" method="POST" action="login.php" autocomplete="off">
                <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirectAfterLogin); ?>">
                <div class="lf-row">
                    <label for="login-email">Email or Username</label>
                    <input
                        id="login-email"
                        type="text"
                        name="email"
                        required
                        autocomplete="username"
                        placeholder="Enter email or username"
                        value="<?php echo htmlspecialchars((string)($_POST['email'] ?? '')); ?>"
                    >
                </div>
                <div class="lf-row">
                    <label for="login-password">Password</label>
                    <div class="pw-wrap">
                        <input
                            id="login-password"
                            type="password"
                            name="password"
                            required
                            autocomplete="current-password"
                            placeholder="Enter your password"
                        >
                        <button type="button" class="pw-toggle" onclick="togglePw()" aria-label="Show/hide password">👁</button>
                    </div>
                    <div class="forgot-pass-row">
                        <a href="forgot-password.php">Forgot Password</a>
                    </div>
                </div>

                <button class="login-btn" type="submit">Login</button>
            </form>

            <div class="login-divider">or</div>
            <div class="login-register-link">
                Don't have an account? <a href="register.php">Register</a>
            </div>
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
    function togglePw() {
        var inp = document.getElementById('login-password');
        inp.type = inp.type === 'password' ? 'text' : 'password';
    }
    </script>
    <script src="js/client.js"></script>
</body>
</html>
