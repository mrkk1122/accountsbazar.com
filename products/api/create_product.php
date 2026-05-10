<?php
/**
 * Add Products via API
 */

require_once '../config/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/webpush.php';

header('Content-Type: application/json');

function iniSizeToBytes($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }

    $unit = strtolower(substr($value, -1));
    $num = (float) $value;

    if ($unit === 'g') {
        $num *= 1024;
    }
    if ($unit === 'm' || $unit === 'g') {
        $num *= 1024;
    }
    if ($unit === 'k' || $unit === 'm' || $unit === 'g') {
        $num *= 1024;
    }

    return (int) $num;
}

function ensureProductsImageColumnCapacity($conn) {
    if (!$conn) {
        return;
    }

    $result = @$conn->query("SHOW COLUMNS FROM `products` LIKE 'image'");
    if (!$result) {
        return;
    }

    $column = $result->fetch_assoc();
    $result->free();
    if (!$column) {
        return;
    }

    $type = strtolower((string) ($column['Type'] ?? ''));
    if (strpos($type, 'varchar(') === 0) {
        if (preg_match('/varchar\((\d+)\)/', $type, $m)) {
            $length = (int) ($m[1] ?? 0);
            if ($length > 0 && $length < 1024) {
                @$conn->query("ALTER TABLE `products` MODIFY COLUMN `image` TEXT NULL");
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(array('success' => false, 'message' => 'POST request required'));
    exit;
}

try {
    $postLimitDisplay = (string) ini_get('post_max_size');
    $uploadLimitDisplay = (string) ini_get('upload_max_filesize');
    $postLimitBytes = iniSizeToBytes($postLimitDisplay);
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

    if ($contentLength > 0 && $postLimitBytes > 0 && $contentLength > $postLimitBytes) {
        echo json_encode(array(
            'success' => false,
            'message' => 'Request size exceeded server post_max_size (' . $postLimitDisplay . '). Increase upload_max_filesize/post_max_size on hosting.'
        ));
        exit;
    }

    $db = new Database();
    $conn = $db->getConnection();
    ensureProductsImageColumnCapacity($conn);
    
    $name = sanitizeInput($_POST['name'] ?? '');
    $description = sanitizeInput($_POST['description'] ?? '');
    $price = floatval($_POST['price'] ?? 0);
    $quantity = intval($_POST['quantity'] ?? 0);
    
    if (empty($name) || $price <= 0) {
        echo json_encode(array('success' => false, 'message' => 'Invalid input'));
        exit;
    }
    
    if (!isset($_FILES['images']) || !isset($_FILES['images']['name']) || !is_array($_FILES['images']['name'])) {
        echo json_encode(array('success' => false, 'message' => 'Please upload exactly 4 images. If image is large, current server upload_max_filesize is ' . $uploadLimitDisplay . '.'));
        exit;
    }

    $uploadErrors = $_FILES['images']['error'] ?? array();
    if (is_array($uploadErrors)) {
        foreach ($uploadErrors as $singleError) {
            $singleError = (int) $singleError;
            if ($singleError === UPLOAD_ERR_INI_SIZE || $singleError === UPLOAD_ERR_FORM_SIZE) {
                echo json_encode(array(
                    'success' => false,
                    'message' => 'Uploaded file is too large for server limit (upload_max_filesize: ' . $uploadLimitDisplay . ').'
                ));
                exit;
            }
        }
    }

    $nonEmptyUploadCount = 0;
    foreach ($_FILES['images']['name'] as $fileName) {
        if (trim((string) $fileName) !== '') {
            $nonEmptyUploadCount++;
        }
    }

    if ($nonEmptyUploadCount !== 4) {
        echo json_encode(array('success' => false, 'message' => 'Please upload exactly 4 images.'));
        exit;
    }

    $uploadedImages = uploadProductImages($_FILES['images']);
    if (count($uploadedImages) !== 4) {
        echo json_encode(array('success' => false, 'message' => 'Image upload failed. Please re-upload all 4 images.'));
        exit;
    }

    $image = implode(',', $uploadedImages);
    
    $query = "INSERT INTO products (name, description, price, quantity, image) 
              VALUES ('" . $conn->real_escape_string($name) . "', 
                      '" . $conn->real_escape_string($description) . "', 
                      " . $price . ", 
                      " . $quantity . ", 
                      '" . $conn->real_escape_string($image) . "')";
    
    if ($conn->query($query) === TRUE) {
        $newProductId = (int) $conn->insert_id;

        $mailSubject = 'New Product Added - Accounts Bazar';
        $mailMessage = "A new product has been added on the home/shop page:\r\n\r\n";
        $mailMessage .= "Product: " . $name . "\r\n";
        $mailMessage .= "Price: BDT " . number_format($price, 2);
        sendAnnouncementToUsers($conn, $mailSubject, $mailMessage);

        // True web-push broadcast queue + dispatch
        webpushEnsureTables($conn);
        webpushQueueEvent(
            $conn,
            'product-' . $newProductId,
            'New Flower Product Added',
            (string) $name,
            'product-details.php?id=' . $newProductId
        );
        webpushSendPendingEvents($conn, 10);

        echo json_encode(array('success' => true, 'message' => 'Product added successfully', 'id' => $newProductId));
    } else {
        echo json_encode(array('success' => false, 'message' => 'Error: ' . $conn->error));
    }
    
    $db->closeConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('success' => false, 'message' => $e->getMessage()));
}

?>
