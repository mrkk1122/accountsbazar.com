<?php
/**
 * Admin Diagnostic Page — password protected
 * Access: /admin/diag.php?key=YOUR_SECRET
 * DELETE this file after diagnosing hosting issues.
 */

define('DIAG_SECRET', 'ab_diag_2025');

$key = trim((string) ($_GET['key'] ?? ''));
if (!hash_equals(DIAG_SECRET, $key)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>403</title></head>';
    echo '<body style="font-family:Arial;padding:30px"><h1>403 Forbidden</h1>';
    echo '<p>Pass <code>?key=ab_diag_2025</code> to access this page.</p></body></html>';
    exit;
}

// Attempt DB connection
$dbStatus  = 'Not tested';
$dbError   = '';
$dbUser    = '';
$dbName    = '';
$dbHost    = '';
$tableList = array();

$cfgFile = __DIR__ . '/../products/config/config.php';
if (file_exists($cfgFile)) {
    require_once $cfgFile;
    require_once __DIR__ . '/../products/includes/db.php';
  $mailCfg = __DIR__ . '/../products/config/mail.php';
  if (file_exists($mailCfg)) {
    require_once $mailCfg;
  }

    ob_start();
    try {
        $db   = new Database();
        $conn = $db->getConnection();
        if ($conn && !$conn->connect_error) {
            $dbStatus = 'Connected OK';
            $dbUser   = $conn->query('SELECT USER()')->fetch_row()[0] ?? 'n/a';
            $dbName   = $conn->query('SELECT DATABASE()')->fetch_row()[0] ?? 'n/a';
            $dbHost   = $conn->host_info ?? 'n/a';
            $r        = $conn->query('SHOW TABLES');
            while ($r && $row = $r->fetch_row()) {
                $tableList[] = $row[0];
            }
            $db->closeConnection();
        } else {
            $dbStatus = 'FAILED';
            $dbError  = $conn->connect_error ?? 'Unknown error';
        }
    } catch (Throwable $e) {
        $dbStatus = 'EXCEPTION';
        $dbError  = $e->getMessage();
    }
    $dbInitOutput = trim(ob_get_clean());
} else {
    $dbInitOutput = 'config.php not found at ' . $cfgFile;
}

// Session info
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$sessionData = $_SESSION;

// PHP extensions relevant to app
$exts = array('mysqli', 'pdo_mysql', 'mbstring', 'json', 'openssl', 'session', 'gd', 'zip');
$optionalExts = array('fileinfo');
$mimeFallbacks = array(
  'finfo_open' => function_exists('finfo_open'),
  'mime_content_type' => function_exists('mime_content_type'),
  'getimagesize' => function_exists('getimagesize'),
  'exif_imagetype' => function_exists('exif_imagetype')
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Diagnostics — Accounts Bazar</title>
<style>
*{box-sizing:border-box}
body{font-family:Arial,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:20px}
h1{font-size:22px;margin:0 0 4px;color:#f8fafc}
.sub{font-size:12px;color:#64748b;margin-bottom:20px}
.card{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:16px 18px;margin-bottom:16px}
.card h2{font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;margin:0 0 10px}
table{width:100%;border-collapse:collapse;font-size:13px}
td,th{padding:6px 8px;border-bottom:1px solid #1e293b;text-align:left;vertical-align:top}
tr:last-child td{border-bottom:none}
th{color:#64748b;font-weight:600;width:42%}
.ok{color:#34d399}.err{color:#f87171}.warn{color:#fbbf24}
.badge{display:inline-block;font-size:11px;padding:2px 7px;border-radius:99px;font-weight:700}
.badge-ok{background:#064e3b;color:#34d399}
.badge-err{background:#450a0a;color:#f87171}
.badge-warn{background:#451a03;color:#fbbf24}
.pre{background:#0f172a;border-radius:6px;padding:8px 10px;font-size:12px;font-family:monospace;word-break:break-all;color:#a5f3fc;margin-top:6px;overflow-x:auto}
.note{font-size:12px;color:#64748b;margin-top:12px}
</style>
</head>
<body>
<h1>&#129520; Admin Diagnostics</h1>
<p class="sub">Accounts Bazar &mdash; <?php echo htmlspecialchars(gethostname() ?: 'n/a', ENT_QUOTES, 'UTF-8'); ?> &mdash; <?php echo date('Y-m-d H:i:s T'); ?></p>

<!-- PHP Info -->
<div class="card">
  <h2>PHP Environment</h2>
  <table>
    <tr><th>PHP Version</th><td><?php echo htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th>SAPI</th><td><?php echo htmlspecialchars(PHP_SAPI, ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th>OS</th><td><?php echo htmlspecialchars(PHP_OS, ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th>Document Root</th><td><?php echo htmlspecialchars($_SERVER['DOCUMENT_ROOT'] ?? 'n/a', ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th>Script Path</th><td><?php echo htmlspecialchars(__FILE__, ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th>display_errors</th><td><?php echo ini_get('display_errors') ? '<span class="warn">On</span>' : '<span class="ok">Off</span>'; ?></td></tr>
    <tr><th>error_reporting</th><td><?php echo ini_get('error_reporting'); ?></td></tr>
    <tr><th>memory_limit</th><td><?php echo ini_get('memory_limit'); ?></td></tr>
    <tr><th>upload_max_filesize</th><td><?php echo ini_get('upload_max_filesize'); ?></td></tr>
    <tr><th>post_max_size</th><td><?php echo ini_get('post_max_size'); ?></td></tr>
    <tr><th>max_execution_time</th><td><?php echo ini_get('max_execution_time'); ?>s</td></tr>
    <tr><th>session.save_path</th><td><?php echo htmlspecialchars(ini_get('session.save_path') ?: '(default)', ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th>session.save_handler</th><td><?php echo htmlspecialchars(ini_get('session.save_handler') ?: 'files', ENT_QUOTES, 'UTF-8'); ?></td></tr>
  </table>
</div>

<!-- DB Connection -->
<div class="card">
  <h2>Database Connection</h2>
  <?php
  $badgeClass = $dbStatus === 'Connected OK' ? 'badge-ok' : 'badge-err';
  ?>
  <table>
    <tr>
      <th>Status</th>
      <td><span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($dbStatus, ENT_QUOTES, 'UTF-8'); ?></span></td>
    </tr>
    <?php if ($dbError): ?>
    <tr><th>Error</th><td class="err"><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <?php endif; ?>
    <?php if ($dbInitOutput): ?>
    <tr><th>Init Output</th><td><div class="pre"><?php echo htmlspecialchars($dbInitOutput, ENT_QUOTES, 'UTF-8'); ?></div></td></tr>
    <?php endif; ?>
    <?php if ($dbStatus === 'Connected OK'): ?>
    <tr><th>Connected as</th><td class="ok"><?php echo htmlspecialchars($dbUser, ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th>Database</th><td class="ok"><?php echo htmlspecialchars($dbName, ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th>Host Info</th><td><?php echo htmlspecialchars($dbHost, ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th>Config DB_USER</th><td><?php echo htmlspecialchars(DB_USER, ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th>Config DB_NAME</th><td><?php echo htmlspecialchars(DB_NAME, ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <?php endif; ?>
  </table>
  <?php if (!empty($tableList)): ?>
  <div style="margin-top:10px">
    <strong style="font-size:12px;color:#94a3b8">Tables (<?php echo count($tableList); ?>):</strong>
    <div class="pre"><?php echo htmlspecialchars(implode(', ', $tableList), ENT_QUOTES, 'UTF-8'); ?></div>
  </div>
  <?php endif; ?>
</div>

<!-- PHP Extensions -->
<div class="card">
  <h2>Required PHP Extensions</h2>
  <table>
    <?php foreach ($exts as $ext): ?>
    <tr>
      <th><?php echo htmlspecialchars($ext, ENT_QUOTES, 'UTF-8'); ?></th>
      <td><?php echo extension_loaded($ext)
            ? '<span class="badge badge-ok">Loaded</span>'
            : '<span class="badge badge-err">MISSING</span>'; ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <h2 style="margin-top:14px">Optional PHP Extensions</h2>
  <table>
    <?php foreach ($optionalExts as $ext): ?>
    <tr>
      <th><?php echo htmlspecialchars($ext, ENT_QUOTES, 'UTF-8'); ?></th>
      <td><?php echo extension_loaded($ext)
            ? '<span class="badge badge-ok">Loaded</span>'
            : '<span class="badge badge-warn">Missing (optional)</span>'; ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <h2 style="margin-top:14px">Image MIME Fallbacks</h2>
  <table>
    <?php foreach ($mimeFallbacks as $fn => $available): ?>
    <tr>
      <th><?php echo htmlspecialchars($fn . '()', ENT_QUOTES, 'UTF-8'); ?></th>
      <td><?php echo $available
            ? '<span class="badge badge-ok">Available</span>'
            : '<span class="badge badge-warn">Unavailable</span>'; ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>

<!-- Session -->
<div class="card">
  <h2>Session</h2>
  <table>
    <tr><th>session_id()</th><td><?php echo htmlspecialchars(session_id() ?: '(none)', ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th>session_status()</th><td><?php echo session_status(); ?> (0=disabled 1=no-session 2=active)</td></tr>
    <tr><th>$_SESSION keys</th><td><?php echo empty($sessionData) ? '(empty)' : htmlspecialchars(implode(', ', array_keys($sessionData)), ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th>user_id</th><td><?php echo htmlspecialchars((string) ($sessionData['user_id'] ?? '(not set)'), ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th>user_type</th><td><?php echo htmlspecialchars((string) ($sessionData['user_type'] ?? '(not set)'), ENT_QUOTES, 'UTF-8'); ?></td></tr>
  </table>
</div>

<!-- File Permissions -->
<div class="card">
  <h2>Key File / Directory Access</h2>
  <?php
  $checks = array(
      'products/config/config.php'    => __DIR__ . '/../products/config/config.php',
      'products/includes/db.php'      => __DIR__ . '/../products/includes/db.php',
      'products/includes/mailer.php'  => __DIR__ . '/../products/includes/mailer.php',
      'products/config/mail.php'      => __DIR__ . '/../products/config/mail.php',
      'products/images/ (uploads)'    => __DIR__ . '/../products/images/',
      '.user.ini'                     => __DIR__ . '/../.user.ini',
  );
  ?>
  <table>
    <?php foreach ($checks as $label => $path): ?>
    <tr>
      <th><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></th>
      <td>
        <?php if (file_exists($path)): ?>
          <span class="badge badge-ok">Exists</span>
          <?php if (is_dir($path)): ?>
            <?php echo is_writable($path) ? ' <span class="ok">writable</span>' : ' <span class="warn">not writable</span>'; ?>
          <?php endif; ?>
        <?php else: ?>
          <span class="badge badge-err">NOT FOUND</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>

<!-- SMTP Test -->
<div class="card">
  <h2>SMTP Connectivity</h2>
  <?php
  if (defined('MAIL_SMTP_HOST') && defined('MAIL_SMTP_PORT')) {
      $smtpHost = MAIL_SMTP_HOST;
      $smtpPort = MAIL_SMTP_PORT;
      $prefix   = MAIL_SMTP_ENCRYPTION === 'ssl' ? 'ssl://' : '';
      $socket   = @stream_socket_client($prefix . $smtpHost . ':' . $smtpPort, $smtpErrNo, $smtpErrStr, 5, STREAM_CLIENT_CONNECT);
      if ($socket) {
          fclose($socket);
          $smtpResult = '<span class="badge badge-ok">Reachable</span>';
      } else {
          $smtpResult = '<span class="badge badge-err">UNREACHABLE</span> <span class="err">' . htmlspecialchars($smtpErrStr, ENT_QUOTES, 'UTF-8') . '</span>';
      }
  } else {
      $smtpResult = '<span class="warn">mail.php not loaded</span>';
  }
  ?>
  <table>
    <tr><th>Host</th><td><?php echo htmlspecialchars(defined('MAIL_SMTP_HOST') ? MAIL_SMTP_HOST : 'n/a', ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th>Port</th><td><?php echo htmlspecialchars(defined('MAIL_SMTP_PORT') ? (string) MAIL_SMTP_PORT : 'n/a', ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th>Encryption</th><td><?php echo htmlspecialchars(defined('MAIL_SMTP_ENCRYPTION') ? MAIL_SMTP_ENCRYPTION : 'n/a', ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th>Connection test</th><td><?php echo $smtpResult; ?></td></tr>
  </table>
</div>

<p class="note">&#9888; <strong>Delete this file from your server after diagnosing.</strong> It exposes system information.</p>

</body>
</html>
