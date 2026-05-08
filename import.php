<?php
/**
 * SQL Import Script
 * This file will import the SQL database schema and data
 */

$servername = "localhost";
$username = "root";
$password = "";

// Create connection
$conn = new mysqli($servername, $username, $password);

// Check connection
if ($conn->connect_error) {
    die("❌ Connection failed: " . $conn->connect_error);
}

echo "<style>
    body {
        font-family: Arial, sans-serif;
        max-width: 800px;
        margin: 40px auto;
        padding: 20px;
        background-color: #f5f5f5;
    }
    .container {
        background: white;
        padding: 30px;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    .success {
        color: #27ae60;
        padding: 10px;
        margin: 10px 0;
        background-color: #d5f4e6;
        border-left: 4px solid #27ae60;
    }
    .error {
        color: #e74c3c;
        padding: 10px;
        margin: 10px 0;
        background-color: #fadbd8;
        border-left: 4px solid #e74c3c;
    }
    .info {
        color: #3498db;
        padding: 10px;
        margin: 10px 0;
        background-color: #d6eaf8;
        border-left: 4px solid #3498db;
    }
    h1 { color: #2c3e50; }
    a {
        display: inline-block;
        margin-top: 20px;
        padding: 10px 20px;
        background-color: #3498db;
        color: white;
        text-decoration: none;
        border-radius: 4px;
    }
    a:hover { background-color: #2980b9; }
</style>";

echo "<div class='container'>";
echo "<h1>🚀 Database Import</h1>";

// Read SQL file
$sqlFile = __DIR__ . '/accounuts.sql';

if (!file_exists($sqlFile)) {
    echo "<div class='error'>❌ SQL file not found: $sqlFile</div>";
    die();
}

echo "<div class='info'>📂 Reading SQL file...</div>";

$sqlContent = file_get_contents($sqlFile);

// Split SQL into individual queries
$queries = array_filter(array_map('trim', explode(';', $sqlContent)));

$successCount = 0;
$errorCount = 0;

echo "<div class='info'>📊 Found " . count($queries) . " queries to execute</div>";

foreach ($queries as $query) {
    if (empty($query)) continue;
    
    if ($conn->query($query) === TRUE) {
        $successCount++;
        echo "<div class='success'>✅ Query executed successfully</div>";
    } else {
        $errorCount++;
        echo "<div class='error'>❌ Error: " . $conn->error . "</div>";
    }
}

echo "<hr>";
echo "<div class='success'><strong>✅ Completed!</strong></div>";
echo "<div class='info'>📈 Successful: $successCount | Failed: $errorCount</div>";

// Display database summary
echo "<h2>📦 Database Summary</h2>";

$conn->select_db("accounta_bazar");

$tables = ["products", "users", "orders", "order_items", "cart", "reviews", "categories", "payments"];

foreach ($tables as $table) {
    $result = $conn->query("SELECT COUNT(*) as count FROM $table");
    if ($result) {
        $row = $result->fetch_assoc();
        echo "<div class='info'>📋 $table: " . $row['count'] . " records</div>";
    }
}

$conn->close();

echo "<br>";
echo "<a href='index.php'>← Back to Homepage</a>";
echo "<a href='admin/index.php' style='margin-left: 10px;'>Go to Admin →</a>";
echo "<a href='client/index.php' style='margin-left: 10px;'>Go to Shop →</a>";

echo "</div>";
?>
