<?php
/**
 * Products Configuration File
 */

// -- Environment Detection --------------------------------------
$serverName = strtolower((string) ($_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? ''));
$isLocalHost = ($serverName === 'localhost' || $serverName === '127.0.0.1' || $serverName === '::1');

if (!defined('APP_IS_LOCAL')) {
    define('APP_IS_LOCAL', $isLocalHost);
}

if (!defined('APP_ENV')) {
    define('APP_ENV', APP_IS_LOCAL ? 'local' : 'production');
}

$appScheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$appHost = (string) ($_SERVER['HTTP_HOST'] ?? $serverName ?: 'localhost');
if (!defined('APP_BASE_URL')) {
    define('APP_BASE_URL', $appScheme . '://' . $appHost);
}

// -- Database Configuration ------------------------------------
// Optional env overrides (if defined in hosting panel):
// APP_DB_HOST, APP_DB_USER, APP_DB_PASS, APP_DB_NAME
$envDbHost = getenv('APP_DB_HOST');
$envDbUser = getenv('APP_DB_USER');
$envDbPass = getenv('APP_DB_PASS');
$envDbName = getenv('APP_DB_NAME');

$primaryHost = $envDbHost !== false && $envDbHost !== '' ? $envDbHost : 'localhost';
$primaryUser = $envDbUser !== false && $envDbUser !== '' ? $envDbUser : (APP_IS_LOCAL ? 'root' : 'accounts_bazar');
$primaryPass = $envDbPass !== false ? (string) $envDbPass : (APP_IS_LOCAL ? '' : '1410689273KK@#');
$primaryName = $envDbName !== false && $envDbName !== '' ? $envDbName : (APP_IS_LOCAL ? 'accounta_bazar' : 'accounts_bazar');

if (!defined('DB_HOST')) {
    define('DB_HOST', $primaryHost);
}
if (!defined('DB_USER')) {
    define('DB_USER', $primaryUser);
}
if (!defined('DB_PASS')) {
    define('DB_PASS', $primaryPass);
}
if (!defined('DB_NAME')) {
    define('DB_NAME', $primaryName);
}

// Fallback set tries the opposite environment automatically.
if (!defined('DB_FALLBACK_HOST')) {
    define('DB_FALLBACK_HOST', 'localhost');
}
if (!defined('DB_FALLBACK_USER')) {
    define('DB_FALLBACK_USER', APP_IS_LOCAL ? 'accounts_bazar' : 'root');
}
if (!defined('DB_FALLBACK_PASS')) {
    define('DB_FALLBACK_PASS', APP_IS_LOCAL ? '1410689273KK@#' : '');
}
if (!defined('DB_FALLBACK_NAME')) {
    define('DB_FALLBACK_NAME', APP_IS_LOCAL ? 'accounts_bazar' : 'accounta_bazar');
}

// -- Products Table --------------------------------------------
define('PRODUCTS_TABLE', 'products');

// -- Pagination -----------------------------------------------
define('ITEMS_PER_PAGE', 12);

// -- Runtime Upload Limits (best effort on shared hosting) -----
@ini_set('upload_max_filesize', '128M');
@ini_set('post_max_size', '140M');
@ini_set('memory_limit', '256M');
@ini_set('max_file_uploads', '50');

// -- File Upload Settings -------------------------------------
define('UPLOAD_DIR',    __DIR__ . '/../images/');
define('MAX_FILE_SIZE', 134217728); // 128 MB
define('ALLOWED_TYPES', array(
    'image/jpeg',
    'image/jpg',
    'image/png',
    'image/gif',
    'image/webp',
    'image/avif',
    'image/bmp',
    'image/tiff',
    'image/svg+xml',
));

// -- API Response Codes ---------------------------------------
define('API_SUCCESS', 1);
define('API_ERROR',   0);

