<?php
/**
 * ============================================================
 *  HOSTING CONFIG – Accounts Bazar
 *  Edit this file with your cPanel / hosting DB credentials
 *  then RENAME IT to: products/config/config.php
 * ============================================================
 */

// ── Database (cPanel → MySQL Databases) ─────────────────────
define('DB_HOST', 'localhost');          // usually localhost
define('DB_USER', 'root');  // ← change this
define('DB_PASS', ''); // ← change this
define('DB_NAME', 'accounts_bazar'); // ← change this

// ── Table names ──────────────────────────────────────────────
define('PRODUCTS_TABLE', 'products');

// ── Pagination ───────────────────────────────────────────────
define('ITEMS_PER_PAGE', 12);

// ── File Upload ──────────────────────────────────────────────
define('UPLOAD_DIR', __DIR__ . '/../images/');
define('MAX_FILE_SIZE', 134217728);  // 128 MB
define('ALLOWED_TYPES', array('image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp', 'image/tiff', 'image/svg+xml'));

// ── API ──────────────────────────────────────────────────────
define('API_SUCCESS', 1);
define('API_ERROR',   0);

