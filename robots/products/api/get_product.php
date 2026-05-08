<?php
/**
 * API: Get Single Product
 */

require_once '../config/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(array('error' => 'Product ID required'));
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $product = getProductById($conn, $_GET['id']);
    
    if ($product) {
        echo json_encode($product);
    } else {
        http_response_code(404);
        echo json_encode(array('error' => 'Product not found'));
    }
    
    $db->closeConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('error' => $e->getMessage()));
}

?>
