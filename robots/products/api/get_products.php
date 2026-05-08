<?php
/**
 * API: Get All Products
 */

require_once '../config/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $products = getProducts($conn);
    
    echo json_encode($products);
    
    $db->closeConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('error' => $e->getMessage()));
}

?>
