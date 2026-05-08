<?php
/**
 * Mail Configuration
 */

define('MAIL_SMTP_HOST', 'mail.accountsbazar.com');
define('MAIL_SMTP_PORT', 465);
define('MAIL_SMTP_ENCRYPTION', 'ssl');
define('MAIL_SMTP_USERNAME', getenv('MAIL_SMTP_USERNAME') ?: 'needhelp@accountsbazar.com');
define('MAIL_SMTP_PASSWORD', getenv('MAIL_SMTP_PASSWORD') ?: '');
define('MAIL_FROM_ADDRESS', getenv('MAIL_FROM_ADDRESS') ?: 'needhelp@accountsbazar.com');
define('MAIL_FROM_NAME', 'Accounts Bazar');
define('MAIL_REPLY_TO', getenv('MAIL_REPLY_TO') ?: MAIL_FROM_ADDRESS);
