<?php
/**
 * API: Delete Product
 */

require_once '../config/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(array('success' => false, 'message' => 'Product ID required'));
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $id = intval($_GET['id']);
    
    if (deleteProduct($conn, $id)) {
        echo json_encode(array('success' => true, 'message' => 'Product deleted successfully'));
    } else {
        echo json_encode(array('success' => false, 'message' => 'Failed to delete product'));
    }
    
    $db->closeConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('success' => false, 'message' => $e->getMessage()));
}

?>
