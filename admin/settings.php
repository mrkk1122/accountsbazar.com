<?php
require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();

$message = '';
$error = '';
$currentAdminEmail = '';

try {
	$db = new Database();
	$conn = $db->getConnection();

	$adminId = (int) ($_SESSION['user_id'] ?? 0);
	if ($adminId > 0) {
		$emailStmt = $conn->prepare('SELECT email FROM users WHERE id = ? AND user_type = ? LIMIT 1');
		if ($emailStmt) {
			$adminType = 'admin';
			$emailStmt->bind_param('is', $adminId, $adminType);
			$emailStmt->execute();
			$emailStmt->bind_result($currentAdminEmail);
			$emailStmt->fetch();
			$emailStmt->close();
		}
	}

	if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'change_admin_credentials') {
		$newEmail = strtolower(trim((string) ($_POST['admin_email'] ?? '')));
		$currentPassword = (string) ($_POST['current_password'] ?? '');
		$newPassword = (string) ($_POST['new_password'] ?? '');
		$confirmPassword = (string) ($_POST['confirm_password'] ?? '');

		if ($adminId <= 0) {
			$error = 'Admin session not found. Please log in again.';
		} elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
			$error = 'Please enter a valid admin email address.';
		} elseif ($currentPassword === '') {
			$error = 'Current password is required.';
		} elseif ($newPassword !== '' && strlen($newPassword) < 8) {
			$error = 'New password must be at least 8 characters.';
		} elseif ($newPassword !== $confirmPassword) {
			$error = 'New password and confirm password do not match.';
		} else {
			$userStmt = $conn->prepare('SELECT id, username, email, password FROM users WHERE id = ? AND user_type = ? LIMIT 1');
			if (!$userStmt) {
				$error = 'Unable to verify admin account right now.';
			} else {
				$adminType = 'admin';
				$storedId = 0;
				$storedUsername = '';
				$storedEmail = '';
				$storedPassword = '';
				$userStmt->bind_param('is', $adminId, $adminType);
				$userStmt->execute();
				$userStmt->bind_result($storedId, $storedUsername, $storedEmail, $storedPassword);
				$hasUser = $userStmt->fetch();
				$userStmt->close();

				if (!$hasUser) {
					$error = 'Admin account not found.';
				} else {
					$passwordValid = password_verify($currentPassword, $storedPassword)
						|| ($storedPassword === $currentPassword)
						|| (strlen((string) $storedPassword) === 32 && strtolower((string) $storedPassword) === md5($currentPassword));

					if (!$passwordValid) {
						$error = 'Current password is incorrect.';
					} else {
						$checkStmt = $conn->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
						if ($checkStmt) {
							$duplicateId = 0;
							$checkStmt->bind_param('si', $newEmail, $adminId);
							$checkStmt->execute();
							$checkStmt->bind_result($duplicateId);
							$hasDuplicate = $checkStmt->fetch();
							$checkStmt->close();
							if ($hasDuplicate) {
								$error = 'This email is already used by another account.';
							}
						}

						if ($error === '') {
							$passwordToSave = $storedPassword;
							if ($newPassword !== '') {
								$passwordToSave = password_hash($newPassword, PASSWORD_BCRYPT);
							}

							$updateStmt = $conn->prepare('UPDATE users SET email = ?, password = ? WHERE id = ? AND user_type = ? LIMIT 1');
							if (!$updateStmt) {
								$error = 'Could not update admin credentials right now.';
							} else {
								$adminType = 'admin';
								$updateStmt->bind_param('ssis', $newEmail, $passwordToSave, $adminId, $adminType);
								if ($updateStmt->execute()) {
									$currentAdminEmail = $newEmail;
									$message = $newPassword !== ''
										? 'Admin email and password updated successfully.'
										: 'Admin email updated successfully.';
								} else {
									$error = 'Failed to update admin credentials.';
								}
								$updateStmt->close();
							}
						}
					}
				}
			}
		}
	}

	$db->closeConnection();
} catch (Throwable $e) {
	$error = 'Could not load admin settings right now.';
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<link rel="icon" href="../favicon.svg?v=20260429f" type="image/svg+xml">
<link rel="shortcut icon" href="../favicon.png?v=20260429f" type="image/png">
<title>Settings - Admin</title>
<link rel="stylesheet" href="css/admin.css">
<style>
.settings-grid {
	display: grid;
	grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
	gap: 20px;
}
.settings-card {
	background: #ffffff;
	border-radius: 14px;
	padding: 24px;
	box-shadow: 0 10px 28px rgba(15, 23, 42, 0.08);
}
.settings-card h2 {
	margin-bottom: 18px;
}
.settings-table {
	width: 100%;
	border-collapse: collapse;
}
.settings-table th,
.settings-table td {
	text-align: left;
	padding: 10px;
	border-bottom: 1px solid #e2e8f0;
}
.settings-form-row {
	margin-bottom: 14px;
}
.settings-form-row label {
	display: block;
	margin-bottom: 6px;
	font-size: 13px;
	font-weight: 700;
	color: #0f172a;
}
.settings-form-row input {
	width: 100%;
	border: 1px solid #cbd5e1;
	border-radius: 10px;
	padding: 11px 12px;
	font-size: 14px;
	outline: none;
}
.settings-form-row input:focus {
	border-color: #2563eb;
	box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.14);
}
.settings-help {
	font-size: 12px;
	color: #64748b;
	margin-top: 4px;
}
.settings-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	border: none;
	border-radius: 10px;
	background: linear-gradient(135deg, #1d4ed8, #0f172a);
	color: #fff;
	font-size: 14px;
	font-weight: 800;
	padding: 12px 16px;
	cursor: pointer;
}
.settings-alert {
	margin-bottom: 16px;
	border-radius: 10px;
	padding: 10px 12px;
	font-size: 13px;
	font-weight: 700;
}
.settings-alert.success {
	background: #dcfce7;
	color: #166534;
}
.settings-alert.error {
	background: #fee2e2;
	color: #991b1b;
}
@media (max-width: 900px) {
	.settings-grid {
		grid-template-columns: 1fr;
	}
}
</style>
</head>
<body>
<div class="admin-container">
<nav class="admin-sidebar" id="admin-sidebar">
<div class="admin-logo"><h2>Admin Panel</h2></div>
<ul class="menu">
<li><a href="index.php">Dashboard</a></li>
<li><a href="products.php">Products</a></li>
<li><a href="ai-prompts.php">AI Prompts</a></li>
<li><a href="user.php">Users</a></li>
<li><a href="orders.php">Orders</a></li>
<li><a href="support-messages.php">Messages</a></li>
<li><a href="settings.php" class="active">Settings</a></li>
<li><a href="logout.php">Logout</a></li>
</ul>
</nav>
<div class="admin-main">
<div class="admin-header"><h1>Settings</h1></div>
<div class="admin-content">
<?php if ($message !== ''): ?>
<div class="settings-alert success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
<div class="settings-alert error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="settings-grid">
<div class="settings-card">
<h2>Site Settings</h2>
<table class="settings-table">
<tr><th>Setting</th><th>Value</th></tr>
<tr><td>Site Name</td><td>Accounts Bazar</td></tr>
<tr><td>Upload Limit</td><td>20 MB</td></tr>
<tr><td>PHP Version</td><td><?php echo phpversion(); ?></td></tr>
<tr><td>upload_max_filesize</td><td><?php echo ini_get('upload_max_filesize'); ?></td></tr>
<tr><td>post_max_size</td><td><?php echo ini_get('post_max_size'); ?></td></tr>
</table>
</div>

<div class="settings-card">
<h2>Admin Credentials</h2>
<form method="POST">
<input type="hidden" name="action" value="change_admin_credentials">

<div class="settings-form-row">
<label for="admin_email">Admin Gmail / Email</label>
<input id="admin_email" name="admin_email" type="email" required value="<?php echo htmlspecialchars($currentAdminEmail); ?>">
<div class="settings-help">Use the email you want to log in with as admin.</div>
</div>

<div class="settings-form-row">
<label for="current_password">Current Password</label>
<input id="current_password" name="current_password" type="password" required>
</div>

<div class="settings-form-row">
<label for="new_password">New Password</label>
<input id="new_password" name="new_password" type="password" minlength="8">
<div class="settings-help">Leave blank if you only want to change the admin email.</div>
</div>

<div class="settings-form-row">
<label for="confirm_password">Confirm New Password</label>
<input id="confirm_password" name="confirm_password" type="password" minlength="8">
</div>

<button type="submit" class="settings-btn">Update Admin Login</button>
</form>
</div>
</div>
</div>
</div>
</div>
<script src="js/admin.js"></script>
</body>
</html>
