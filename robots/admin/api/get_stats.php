<?php
/**
 * Admin API: Get Dashboard Statistics
 */

require_once '../products/config/config.php';
require_once '../products/includes/db.php';

header('Content-Type: application/json');

$stats = array(
    'total_products' => 0,
    'total_users' => 0,
    'total_orders' => 0,
    'total_revenue' => 0
);

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Get total products
    $result = $conn->query("SELECT COUNT(*) as count FROM products");
    if ($result) {
        $row = $result->fetch_assoc();
        $stats['total_products'] = $row['count'];
    }
    
    // Get total revenue
    $result = $conn->query("SELECT SUM(price * quantity) as revenue FROM products");
    if ($result) {
        $row = $result->fetch_assoc();
        $stats['total_revenue'] = $row['revenue'] ?? 0;
    }
    
    $db->closeConnection();
} catch (Exception $e) {
    // Return mock data on error
}

echo json_encode($stats);

?>
