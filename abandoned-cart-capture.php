<?php
session_start();
require_once __DIR__ . '/products/config/config.php';
require_once __DIR__ . '/products/includes/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('success' => false, 'message' => 'POST required'));
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode((string) $raw, true);
if (!is_array($data)) {
    echo json_encode(array('success' => false, 'message' => 'Invalid payload'));
    exit;
}

$productId = (int) ($data['product_id'] ?? 0);
$plan = trim((string) ($data['plan'] ?? '1-month'));
$email = strtolower(trim((string) ($data['email'] ?? '')));
$phone = trim((string) ($data['phone'] ?? ''));
$name = trim((string) ($data['name'] ?? ''));
$couponCode = strtoupper(trim((string) ($data['coupon_code'] ?? '')));
$totalHint = (float) ($data['total_hint'] ?? 0);

if ($productId <= 0 || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '') {
    echo json_encode(array('success' => false, 'message' => 'Missing required fields'));
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    $conn->query(
        "CREATE TABLE IF NOT EXISTS abandoned_cart_leads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            phone VARCHAR(30) NOT NULL,
            customer_name VARCHAR(120) NOT NULL,
            product_id INT NOT NULL,
            plan VARCHAR(40) DEFAULT '1-month',
            coupon_code VARCHAR(40) DEFAULT '',
            total_hint DECIMAL(10,2) DEFAULT 0,
            status ENUM('pending','reminded','closed') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            reminder_sent_at TIMESTAMP NULL DEFAULT NULL,
            UNIQUE KEY uniq_pending (email, product_id, status),
            INDEX idx_status_created (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $stmt = $conn->prepare(
        'INSERT INTO abandoned_cart_leads (email, phone, customer_name, product_id, plan, coupon_code, total_hint, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, "pending")
         ON DUPLICATE KEY UPDATE
            phone = VALUES(phone),
            customer_name = VALUES(customer_name),
            plan = VALUES(plan),
            coupon_code = VALUES(coupon_code),
            total_hint = VALUES(total_hint),
            updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->bind_param('sssissd', $email, $phone, $name, $productId, $plan, $couponCode, $totalHint);
    $ok = $stmt->execute();
    $stmt->close();

    $db->closeConnection();

    echo json_encode(array('success' => (bool) $ok));
} catch (Throwable $e) {
    echo json_encode(array('success' => false, 'message' => 'Unable to save abandoned cart'));
}
