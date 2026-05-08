<?php
// Temporary diagnostic file for hosting DB issues.
// Remove this file after troubleshooting.

mysqli_report(MYSQLI_REPORT_OFF);

require_once __DIR__ . '/products/config/config.php';

header('Content-Type: text/plain; charset=utf-8');

echo "DB Diagnostic\n";
echo "===========\n\n";

$attempts = [
    ['label' => 'Primary', 'host' => DB_HOST, 'user' => DB_USER, 'pass' => DB_PASS, 'name' => DB_NAME],
    ['label' => 'Fallback', 'host' => defined('DB_FALLBACK_HOST') ? DB_FALLBACK_HOST : DB_HOST, 'user' => defined('DB_FALLBACK_USER') ? DB_FALLBACK_USER : DB_USER, 'pass' => defined('DB_FALLBACK_PASS') ? DB_FALLBACK_PASS : DB_PASS, 'name' => defined('DB_FALLBACK_NAME') ? DB_FALLBACK_NAME : DB_NAME],
];

$seen = [];
$uniqueAttempts = [];
foreach ($attempts as $a) {
    $k = $a['host'] . '|' . $a['user'] . '|' . $a['name'];
    if (!isset($seen[$k])) {
        $uniqueAttempts[] = $a;
        $seen[$k] = true;
    }
}

$connected = false;

foreach ($uniqueAttempts as $a) {
    echo "Attempt: {$a['label']}\n";
    echo "Host   : {$a['host']}\n";
    echo "User   : {$a['user']}\n";
    echo "DB Name: {$a['name']}\n";

    $conn = @new mysqli($a['host'], $a['user'], $a['pass'], $a['name']);

    if ($conn->connect_error) {
        echo "Result : FAILED\n";
        echo "Error  : {$conn->connect_error}\n\n";
        continue;
    }

    $connected = true;
    echo "Result : CONNECTED\n";

    $tbl = $conn->query("SHOW TABLES LIKE 'products'");
    $hasProducts = ($tbl && $tbl->num_rows > 0);
    echo "products table: " . ($hasProducts ? 'YES' : 'NO') . "\n";

    if ($hasProducts) {
        $cntRes = $conn->query("SELECT COUNT(*) AS c FROM products");
        $cntRow = $cntRes ? $cntRes->fetch_assoc() : null;
        $count = (int) ($cntRow['c'] ?? 0);
        echo "products rows : {$count}\n";
    }

    echo "\n";
    $conn->close();
}

if (!$connected) {
    echo "FINAL: No DB connection succeeded.\n";
    echo "Fix: Set exact cPanel MySQL DB name/user/password in products/config/config.php\n";
    echo "Note: cPanel often uses prefix like cpaneluser_dbname and cpaneluser_dbuser.\n";
} else {
    echo "FINAL: At least one connection succeeded.\n";
    echo "If products table is NO, import accounuts.sql into the connected DB.\n";
}
