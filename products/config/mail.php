<?php
/**
 * Advanced Mail Configuration
 * Supports multiple email accounts for different purposes
 */

if (!function_exists('mailConfigEnvValue')) {
	function mailConfigWrappedInQuotes($value) {
		$value = (string) $value;
		$len = strlen($value);
		if ($len < 2) {
			return false;
		}
		$first = $value[0];
		$last = $value[$len - 1];
		return (($first === '"' && $last === '"') || ($first === "'" && $last === "'"));
	}

	function mailConfigEnvValue($key, $default = '') {
		$raw = getenv((string) $key);
		if ($raw === false) {
			return (string) $default;
		}

		$value = trim((string) $raw);
		if ($value === '') {
			return (string) $default;
		}

		// Remove accidental wrapping quotes from hosting panel values.
		if (mailConfigWrappedInQuotes($value)) {
			$value = substr($value, 1, -1);
			$value = trim((string) $value);
		}

		return $value === '' ? (string) $default : $value;
	}
}

// Primary Order/System Account
define('MAIL_SMTP_HOST', mailConfigEnvValue('MAIL_SMTP_HOST', 'mail.accountsbazar.com'));
define('MAIL_SMTP_PORT', (int) mailConfigEnvValue('MAIL_SMTP_PORT', '465'));
define('MAIL_SMTP_ENCRYPTION', mailConfigEnvValue('MAIL_SMTP_ENCRYPTION', 'ssl'));
define('MAIL_SMTP_AUTH', true);
define('MAIL_SMTP_AUTH_METHOD', mailConfigEnvValue('MAIL_SMTP_AUTH_METHOD', 'auto'));
// Alternative port if primary fails (usually 587 with TLS for submission)
define('MAIL_SMTP_ALT_PORT', (int) mailConfigEnvValue('MAIL_SMTP_ALT_PORT', '587'));
define('MAIL_SMTP_ALT_ENCRYPTION', mailConfigEnvValue('MAIL_SMTP_ALT_ENCRYPTION', 'tls'));
define('MAIL_HELO_DOMAIN', mailConfigEnvValue('MAIL_HELO_DOMAIN', 'accountsbazar.com'));

$smtpUserEnv = mailConfigEnvValue('MAIL_SMTP_USERNAME', mailConfigEnvValue('MAIL_SUPPORT_USERNAME', ''));
$smtpUserDefault = 'otp@accountsbazar.com';
if (!filter_var($smtpUserEnv, FILTER_VALIDATE_EMAIL)) {
	$smtpUserEnv = $smtpUserDefault;
}
define('MAIL_SMTP_USERNAME', $smtpUserEnv);

$smtpPassEnv = mailConfigEnvValue('MAIL_SMTP_PASSWORD', mailConfigEnvValue('MAIL_SUPPORT_PASSWORD', ''));
if ($smtpPassEnv === '') {
	$smtpPassEnv = '1410689273KK@#';
}
define('MAIL_SMTP_PASSWORD', $smtpPassEnv);

$fromAddressEnv = mailConfigEnvValue('MAIL_FROM_ADDRESS', MAIL_SMTP_USERNAME);
if (!filter_var($fromAddressEnv, FILTER_VALIDATE_EMAIL)) {
	$fromAddressEnv = MAIL_SMTP_USERNAME;
}
define('MAIL_FROM_ADDRESS', $fromAddressEnv);
define('MAIL_FROM_NAME', 'Accounts Bazar');

$replyToEnv = mailConfigEnvValue('MAIL_REPLY_TO', MAIL_FROM_ADDRESS);
if (!filter_var($replyToEnv, FILTER_VALIDATE_EMAIL)) {
	$replyToEnv = MAIL_FROM_ADDRESS;
}
define('MAIL_REPLY_TO', $replyToEnv);

// Support/Notification Account (for support and notifications)
define('MAIL_SUPPORT_USERNAME', mailConfigEnvValue('MAIL_SUPPORT_USERNAME', MAIL_SMTP_USERNAME));
define('MAIL_SUPPORT_PASSWORD', mailConfigEnvValue('MAIL_SUPPORT_PASSWORD', MAIL_SMTP_PASSWORD));
define('MAIL_SUPPORT_ADDRESS', mailConfigEnvValue('MAIL_SUPPORT_ADDRESS', MAIL_FROM_ADDRESS));
define('MAIL_SUPPORT_NAME', 'Accounts Bazar Support');

// Incoming mail server settings
define('MAIL_IMAP_HOST', mailConfigEnvValue('MAIL_IMAP_HOST', 'mail.accountsbazar.com'));
define('MAIL_IMAP_PORT', (int) mailConfigEnvValue('MAIL_IMAP_PORT', '993'));
define('MAIL_IMAP_AUTH', true);

define('MAIL_POP3_HOST', mailConfigEnvValue('MAIL_POP3_HOST', 'mail.accountsbazar.com'));
define('MAIL_POP3_PORT', (int) mailConfigEnvValue('MAIL_POP3_PORT', '995'));
define('MAIL_POP3_AUTH', true);

// Mail Settings
define('MAIL_SEND_TIMEOUT', 30);
define('MAIL_RETRY_ATTEMPTS', 3);
define('MAIL_DEBUG_MODE', true);
define('MAIL_LOG_ENABLED', true);
