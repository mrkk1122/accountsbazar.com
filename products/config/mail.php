<?php
/**
 * Advanced Mail Configuration
 * Supports multiple email accounts for different purposes
 */

// Primary Order/System Account
define('MAIL_SMTP_HOST', getenv('MAIL_SMTP_HOST') ?: 'mail.accountsbazar.com');
define('MAIL_SMTP_PORT', (int) (getenv('MAIL_SMTP_PORT') ?: 465));
define('MAIL_SMTP_ENCRYPTION', getenv('MAIL_SMTP_ENCRYPTION') ?: 'ssl');
define('MAIL_SMTP_AUTH', true);
define('MAIL_SMTP_USERNAME', getenv('MAIL_SMTP_USERNAME') ?: (getenv('MAIL_SUPPORT_USERNAME') ?: 'otp@accountsbazar.com'));
define('MAIL_SMTP_PASSWORD', getenv('MAIL_SMTP_PASSWORD') ?: (getenv('MAIL_SUPPORT_PASSWORD') ?: '1410689273KK@#'));
define('MAIL_FROM_ADDRESS', getenv('MAIL_FROM_ADDRESS') ?: MAIL_SMTP_USERNAME);
define('MAIL_FROM_NAME', 'Accounts Bazar');
define('MAIL_REPLY_TO', getenv('MAIL_REPLY_TO') ?: MAIL_FROM_ADDRESS);

// Support/Notification Account (for support and notifications)
define('MAIL_SUPPORT_USERNAME', getenv('MAIL_SUPPORT_USERNAME') ?: MAIL_SMTP_USERNAME);
define('MAIL_SUPPORT_PASSWORD', getenv('MAIL_SUPPORT_PASSWORD') ?: MAIL_SMTP_PASSWORD);
define('MAIL_SUPPORT_ADDRESS', getenv('MAIL_SUPPORT_ADDRESS') ?: MAIL_FROM_ADDRESS);
define('MAIL_SUPPORT_NAME', 'Accounts Bazar Support');

// Incoming mail server settings
define('MAIL_IMAP_HOST', getenv('MAIL_IMAP_HOST') ?: 'mail.accountsbazar.com');
define('MAIL_IMAP_PORT', (int) (getenv('MAIL_IMAP_PORT') ?: 993));
define('MAIL_IMAP_AUTH', true);

define('MAIL_POP3_HOST', getenv('MAIL_POP3_HOST') ?: 'mail.accountsbazar.com');
define('MAIL_POP3_PORT', (int) (getenv('MAIL_POP3_PORT') ?: 995));
define('MAIL_POP3_AUTH', true);

// Mail Settings
define('MAIL_SEND_TIMEOUT', 30);
define('MAIL_RETRY_ATTEMPTS', 3);
define('MAIL_DEBUG_MODE', false);
define('MAIL_LOG_ENABLED', true);
