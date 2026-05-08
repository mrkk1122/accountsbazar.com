<?php
/**
 * Products Helper Functions
 */

require_once __DIR__ . '/mailer.php';

function getProducts($conn, $limit = 0) {
    $query = "SELECT * FROM " . PRODUCTS_TABLE;
    
    if ($limit > 0) {
        $query .= " LIMIT " . $limit;
    }
    
    $result = $conn->query($query);
    
    if (!$result) {
        return array();
    }
    
    $products = array();
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
    
    return $products;
}

function getProductById($conn, $id) {
    $id = intval($id);
    $query = "SELECT * FROM " . PRODUCTS_TABLE . " WHERE id = " . $id;
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

function deleteProduct($conn, $id) {
    $id = intval($id);
    $query = "DELETE FROM " . PRODUCTS_TABLE . " WHERE id = " . $id;
    
    if ($conn->query($query) === TRUE) {
        return true;
    }
    
    return false;
}

function sanitizeInput($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function convertImageToWebp($sourcePath, $targetPath, $quality = 84) {
    if (!function_exists('imagewebp')) {
        return false;
    }

    $info = @getimagesize($sourcePath);
    if (!$info || empty($info['mime'])) {
        return false;
    }

    $mime = strtolower((string) $info['mime']);
    $image = null;

    if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
        $image = @imagecreatefromjpeg($sourcePath);
    } elseif ($mime === 'image/png') {
        $image = @imagecreatefrompng($sourcePath);
        if ($image) {
            imagepalettetotruecolor($image);
            imagealphablending($image, true);
            imagesavealpha($image, true);
        }
    } elseif ($mime === 'image/gif') {
        $image = @imagecreatefromgif($sourcePath);
    } elseif ($mime === 'image/webp') {
        // Already webp
        return false;
    }

    if (!$image) {
        return false;
    }

    $ok = imagewebp($image, $targetPath, $quality);
    imagedestroy($image);
    return $ok;
}

function uploadProductImage($file) {
    $target_dir = UPLOAD_DIR;
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    $file_name = basename((string) ($file['name'] ?? ''));
    $tmp_name = (string) ($file['tmp_name'] ?? '');
    if ($file_name === '' || $tmp_name === '') {
        return false;
    }

    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $file_name);
    $savedName = time() . '_' . $safeName;
    $targetFile = $target_dir . $savedName;

    if (!move_uploaded_file($tmp_name, $targetFile)) {
        return false;
    }

    $webpName = preg_replace('/\.[a-zA-Z0-9]+$/', '', $savedName) . '.webp';
    $webpPath = $target_dir . $webpName;
    if (convertImageToWebp($targetFile, $webpPath)) {
        @unlink($targetFile);
        return 'images/' . $webpName;
    }

    return 'images/' . $savedName;
}

function uploadProductImages($files) {
    $savedImages = array();
    if (!isset($files['name']) || !is_array($files['name'])) {
        return $savedImages;
    }

    $target_dir = UPLOAD_DIR;
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    $total = count($files['name']);
    for ($i = 0; $i < $total; $i++) {
        $originalName = basename((string) ($files['name'][$i] ?? ''));
        $tmpName = (string) ($files['tmp_name'][$i] ?? '');
        $errorCode = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);

        if ($originalName === '' || $errorCode !== UPLOAD_ERR_OK || $tmpName === '') {
            continue;
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName);
        $savedName = time() . '_' . $i . '_' . $safeName;
        $targetFile = $target_dir . $savedName;

        if (!move_uploaded_file($tmpName, $targetFile)) {
            continue;
        }

        $webpName = preg_replace('/\.[a-zA-Z0-9]+$/', '', $savedName) . '.webp';
        $webpPath = $target_dir . $webpName;
        if (convertImageToWebp($targetFile, $webpPath)) {
            @unlink($targetFile);
            $savedImages[] = 'images/' . $webpName;
        } else {
            $savedImages[] = 'images/' . $savedName;
        }
    }

    return $savedImages;
}

    function sendAnnouncementToUsers($conn, $subject, $messageText) {
        if (!$conn || trim($subject) === '' || trim($messageText) === '') {
            return 0;
        }

        $queuedCount = 0;
        $res = $conn->query("SELECT email, first_name, last_name FROM users WHERE is_active = 1 AND email IS NOT NULL AND email <> ''");
        if (!$res) {
            return 0;
        }

        while ($row = $res->fetch_assoc()) {
            $email = trim((string) ($row['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $name = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));
            if ($name === '') {
                $name = 'Customer';
            }

            $body  = "Dear {$name},\r\n\r\n";
            $body .= $messageText . "\r\n\r\n";
            $body .= "Visit Accounts Bazar for details.\r\n\r\n";
            $body .= "Thanks,\r\nAccounts Bazar Team";

            if (enqueueEmail($conn, $email, $subject, $body)) {
                $queuedCount++;
            }
        }

        processEmailQueue($conn, 20);
        return $queuedCount;
    }

?>
