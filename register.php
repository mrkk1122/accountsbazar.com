<?php
session_start();

// Redirect if already logged in
if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

require_once 'products/config/config.php';
require_once 'products/includes/db.php';
require_once 'products/includes/mailer.php';

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName  = trim((string) ($_POST['full_name'] ?? ''));
    $firstName = $fullName;
    $lastName  = '';
    $email     = trim((string) ($_POST['email']      ?? ''));
    $phone     = trim((string) ($_POST['phone']      ?? ''));
    $password  = (string) ($_POST['password']        ?? '');
    $confirm   = (string) ($_POST['confirm']         ?? '');
    // Auto-generate username from email (part before @)
    $username  = preg_replace('/[^a-zA-Z0-9_]/', '_', explode('@', $email)[0]);

    // --- Validation ---
    if (empty($fullName) || empty($email) || empty($password) || empty($confirm)) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $db   = new Database();
            $conn = $db->getConnection();

            // Check duplicate email
            $chk = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $chk->bind_param('s', $email);
            $chk->execute();
            $chk->store_result();
            $exists = $chk->num_rows > 0;
            $chk->close();

            if ($exists) {
                $error = 'An account with that email already exists.';
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);

                $ins = $conn->prepare(
                    'INSERT INTO users (username, email, password, first_name, last_name, phone, user_type, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, "customer", 1)'
                );
                $ins->bind_param('ssssss', $username, $email, $hashed, $firstName, $lastName, $phone);

                if ($ins->execute()) {
                    $ins->close();
                    $db->closeConnection();

                    // Send welcome email using new notification system
                    sendRegistrationEmail($email, $fullName, $username);

                    $success = 'Registration successful! A confirmation email has been sent to <strong>' . htmlspecialchars($email) . '</strong>. <a href="login.php">Login now →</a>';
                } else {
                    $ins->close();
                    $db->closeConnection();
                    $error = 'Registration failed. Please try again.';
                }
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
    'title'       => 'Register – Create Account | Accounts Bazar',
    'description' => 'Accounts Bazar-এ নতুন অ্যাকাউন্ট তৈরি করুন। অর্ডার করুন, ট্র্যাক করুন।',
    'canonical'   => 'https://accountsbazar.com/register.php',
    'noindex'     => true,
];
require_once 'products/includes/seo.php';
?>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/mobile.css">
    <style>
        .reg-page {
            min-height: 80vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 32px 16px 100px;
            background: linear-gradient(135deg, #f0f4ff 0%, #fafafa 100%);
        }
        .reg-card {
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 8px 40px rgba(15,23,42,0.12);
            padding: 40px 36px 36px;
            width: 100%;
            max-width: 480px;
        }
        .reg-logo {
            text-align: center;
            margin-bottom: 8px;
        }
        .reg-logo-icon {
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
        .reg-title {
            text-align: center;
            font-size: 24px;
            font-weight: 900;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .reg-subtitle {
            text-align: center;
            font-size: 14px;
            color: #64748b;
            margin-bottom: 24px;
        }
        .reg-form .rf-row {
            margin-bottom: 14px;
        }
        .reg-form .rf-row-half {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 14px;
        }
        .reg-form label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 5px;
        }
        .reg-form label .req {
            color: #e11d48;
            margin-left: 2px;
        }
        .reg-form input[type="text"],
        .reg-form input[type="email"],
        .reg-form input[type="tel"],
        .reg-form input[type="password"] {
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
        .reg-form input:focus {
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
        .pw-hint {
            font-size: 11px;
            color: #94a3b8;
            margin-top: 4px;
        }
        .reg-alert-error {
            background: #fff0f0;
            border-left: 4px solid #e11d48;
            color: #9f1239;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 16px;
            font-weight: 600;
        }
        .reg-alert-success {
            background: #f0fff4;
            border-left: 4px solid #16a34a;
            color: #15803d;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 16px;
            font-weight: 600;
            line-height: 1.6;
        }
        .reg-alert-success a {
            color: #16a34a;
            font-weight: 800;
        }
        .reg-btn {
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
            margin-top: 6px;
        }
        .reg-btn:hover {
            background: #0ea5e9;
            transform: translateY(-1px);
        }
        .reg-divider {
            text-align: center;
            color: #94a3b8;
            font-size: 13px;
            margin: 18px 0 14px;
            position: relative;
        }
        .reg-divider::before,
        .reg-divider::after {
            content: '';
            display: inline-block;
            width: 38%;
            height: 1px;
            background: #e2e8f0;
            vertical-align: middle;
            margin: 0 8px;
        }
        .reg-login-link {
            text-align: center;
            font-size: 14px;
            color: #64748b;
        }
        .reg-login-link a {
            color: #0ea5e9;
            font-weight: 700;
            text-decoration: none;
        }
        .reg-login-link a:hover { text-decoration: underline; }
        .strength-bar-wrap {
            height: 4px;
            background: #e2e8f0;
            border-radius: 4px;
            margin-top: 6px;
            overflow: hidden;
        }
        .strength-bar {
            height: 100%;
            width: 0%;
            border-radius: 4px;
            transition: width 0.3s, background 0.3s;
        }
        @media (max-width: 480px) {
            .reg-card { padding: 26px 16px 28px; }
            .reg-form .rf-row-half { grid-template-columns: 1fr; gap: 0; }
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

    <div class="reg-page">
        <div class="reg-card">
            <div class="reg-logo">
                <div class="reg-logo-icon">📝</div>
            </div>
            <h1 class="reg-title">Create Account</h1>
            <p class="reg-subtitle">Join Accounts Bazar and start shopping</p>

            <?php if (!empty($error)): ?>
                <div class="reg-alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if (!empty($success)): ?>
                <div class="reg-alert-success">✅ <?php echo $success; ?></div>
            <?php endif; ?>

            <?php if (empty($success)): ?>
            <form class="reg-form" method="POST" action="register.php" autocomplete="off">
                <div class="rf-row">
                    <label for="reg-fullname">Full Name <span class="req">*</span></label>
                    <input id="reg-fullname" type="text" name="full_name" required
                        placeholder="Enter your full name"
                        value="<?php echo htmlspecialchars((string)($_POST['full_name'] ?? '')); ?>">
                </div>

                <div class="rf-row">
                    <label for="reg-email">Email Address <span class="req">*</span></label>
                    <input id="reg-email" type="email" name="email" required
                        placeholder="Enter your email"
                        value="<?php echo htmlspecialchars((string)($_POST['email'] ?? '')); ?>">
                </div>

                <div class="rf-row">
                    <label for="reg-phone">Phone Number</label>
                    <input id="reg-phone" type="tel" name="phone"
                        placeholder="e.g. 01XXXXXXXXX"
                        value="<?php echo htmlspecialchars((string)($_POST['phone'] ?? '')); ?>">
                </div>

                <div class="rf-row">
                    <label for="reg-password">Password <span class="req">*</span></label>
                    <div class="pw-wrap">
                        <input id="reg-password" type="password" name="password" required
                            autocomplete="new-password"
                            placeholder="Minimum 6 characters"
                            oninput="checkStrength(this.value)">
                        <button type="button" class="pw-toggle" onclick="togglePw('reg-password')" aria-label="Show/hide password">👁</button>
                    </div>
                    <div class="strength-bar-wrap">
                        <div class="strength-bar" id="strength-bar"></div>
                    </div>
                    <div class="pw-hint" id="strength-label">Enter a password</div>
                </div>

                <div class="rf-row">
                    <label for="reg-confirm">Confirm Password <span class="req">*</span></label>
                    <div class="pw-wrap">
                        <input id="reg-confirm" type="password" name="confirm" required
                            autocomplete="new-password"
                            placeholder="Re-enter password">
                        <button type="button" class="pw-toggle" onclick="togglePw('reg-confirm')" aria-label="Show/hide password">👁</button>
                    </div>
                </div>

                <button class="reg-btn" type="submit">Create Account</button>
            </form>
            <?php endif; ?>

            <div class="reg-divider">or</div>
            <div class="reg-login-link">
                Already have an account? <a href="login.php">Login</a>
            </div>
        </div>
    </div>

    <nav class="mobile-bottom-nav" aria-label="Mobile Bottom Navigation">
        <a href="index.php"><span class="nav-icon">🏠</span><span class="nav-label">Home</span></a>
        <a href="shop.php"><span class="nav-icon">🛍️</span><span class="nav-label">Shop</span></a>
        <a class="ai-prompt-link" href="ai-prompt.php"><span class="nav-icon">🤖</span><span class="nav-label">AI Prompt</span></a>
            <a href="#" data-notification-toggle><span class="nav-icon">🔔</span><span class="nav-label">Notification</span><span class="notif-badge" data-notif-badge style="display:none;">0</span></a>
        <a href="login.php"><span class="nav-icon">👤</span><span class="nav-label">Login</span></a>
    </nav>

    <script>
    function togglePw(id) {
        var inp = document.getElementById(id);
        inp.type = inp.type === 'password' ? 'text' : 'password';
    }

    function checkStrength(val) {
        var bar   = document.getElementById('strength-bar');
        var label = document.getElementById('strength-label');
        var score = 0;
        if (val.length >= 6)  score++;
        if (val.length >= 10) score++;
        if (/[A-Z]/.test(val)) score++;
        if (/[0-9]/.test(val)) score++;
        if (/[^A-Za-z0-9]/.test(val)) score++;

        var levels = [
            { w: '0%',   color: '#e2e8f0', text: 'Enter a password' },
            { w: '25%',  color: '#ef4444', text: 'Weak' },
            { w: '50%',  color: '#f97316', text: 'Fair' },
            { w: '75%',  color: '#eab308', text: 'Good' },
            { w: '100%', color: '#22c55e', text: 'Strong' },
        ];
        var idx = val.length === 0 ? 0 : Math.min(score, 4);
        bar.style.width      = levels[idx].w;
        bar.style.background = levels[idx].color;
        label.textContent    = levels[idx].text;
        label.style.color    = levels[idx].color;
    }
    </script>
    <script src="js/client.js"></script>
</body>
</html>
