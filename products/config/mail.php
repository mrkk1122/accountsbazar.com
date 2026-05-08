<?php
/**
 * Advanced Mail Configuration
 * Supports multiple email accounts for different purposes
 */

// Primary Order/System Account
define('MAIL_SMTP_HOST', 'mail.accountsbazar.com');
define('MAIL_SMTP_PORT', 465);
define('MAIL_SMTP_ENCRYPTION', 'ssl');
define('MAIL_SMTP_USERNAME', 'order@accountsbazar.com');
define('MAIL_SMTP_PASSWORD', '1410689273KK');
define('MAIL_FROM_ADDRESS', 'order@accountsbazar.com');
define('MAIL_FROM_NAME', 'Accounts Bazar');
define('MAIL_REPLY_TO', 'order@accountsbazar.com');

// Support/Notification Account (for support and notifications)
define('MAIL_SUPPORT_USERNAME', 'needhelp@accountsbazar.com');
define('MAIL_SUPPORT_PASSWORD', ''); // TODO: Add password here
define('MAIL_SUPPORT_ADDRESS', 'needhelp@accountsbazar.com');
define('MAIL_SUPPORT_NAME', 'Accounts Bazar Support');

// Mail Settings
define('MAIL_SEND_TIMEOUT', 30);
define('MAIL_RETRY_ATTEMPTS', 3);
define('MAIL_DEBUG_MODE', false); // Set to true for debugging
define('MAIL_LOG_ENABLED', true);
