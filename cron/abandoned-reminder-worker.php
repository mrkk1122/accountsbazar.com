<?php
// Run via cron every 5-10 minutes:
// php /path/to/accountsbazar.com/cron/abandoned-reminder-worker.php

require_once __DIR__ . '/../products/config/config.php';
require_once __DIR__ . '/../products/includes/db.php';
require_once __DIR__ . '/../products/includes/mailer.php';

try {
    $db = new Database();
    $conn = $db->getConnection();

    ensureEmailQueueTable($conn);
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

    $sql = 'SELECT a.id, a.email, a.phone, a.customer_name, a.product_id, a.plan, a.coupon_code, a.total_hint, p.name AS product_name
            FROM abandoned_cart_leads a
            LEFT JOIN products p ON p.id = a.product_id
            WHERE a.status = "pending" AND a.created_at <= (NOW() - INTERVAL 30 MINUTE)
            ORDER BY a.id ASC
            LIMIT 40';

    $res = $conn->query($sql);
    $queued = 0;

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $name = trim((string) ($row['customer_name'] ?? 'Customer'));
            $email = trim((string) ($row['email'] ?? ''));
            $phone = trim((string) ($row['phone'] ?? ''));
            $productName = trim((string) ($row['product_name'] ?? 'your selected bouquet'));
            $coupon = trim((string) ($row['coupon_code'] ?? ''));
            $plan = trim((string) ($row['plan'] ?? '1-month'));

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $waMessage = rawurlencode('Hi, I want to complete my order for ' . $productName . '.');
            $waNumber = preg_replace('/[^0-9]/', '', $phone);
            $waLink = $waNumber !== '' ? ('https://wa.me/' . $waNumber . '?text=' . $waMessage) : 'https://wa.me/8801790088564';

            $subject = 'Complete Your Flower Order – We saved your checkout';
            $body  = "Hello {$name},\r\n\r\n";
            $body .= "You left checkout before placing your order. Your selected item is still available:\r\n";
            $body .= "Product: {$productName}\r\n";
            $body .= "Plan: {$plan}\r\n";
            if ($coupon !== '') {
                $body .= "Coupon: {$coupon}\r\n";
            }
            $body .= "\r\nComplete now: https://accountsbazar.com/checkout.php?id=" . (int) ($row['product_id'] ?? 0) . "\r\n";
            $body .= "Need help on WhatsApp: {$waLink}\r\n\r\n";
            $body .= "Thanks,\r\nAccounts Bazar Team";

            if (enqueueEmail($conn, $email, $subject, $body)) {
                $queued++;
                $upd = $conn->prepare('UPDATE abandoned_cart_leads SET status = "reminded", reminder_sent_at = NOW() WHERE id = ?');
                $id = (int) $row['id'];
                $upd->bind_param('i', $id);
                $upd->execute();
                $upd->close();
            }
        }
    }

    $processed = processEmailQueue($conn, 40);
    $db->closeConnection();

    echo json_encode(array('success' => true, 'queued_reminders' => $queued, 'email_worker' => $processed), JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(array('success' => false, 'message' => $e->getMessage())) . PHP_EOL;
    exit(1);
}
