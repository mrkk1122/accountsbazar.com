<?php
// Run via cron every 1-5 minutes:
// php /path/to/accountsbazar.com/cron/email-worker.php

require_once __DIR__ . '/../products/config/config.php';
require_once __DIR__ . '/../products/includes/db.php';
require_once __DIR__ . '/../products/includes/mailer.php';

try {
    $db = new Database();
    $conn = $db->getConnection();

    $result = processEmailQueue($conn, 30);

    $db->closeConnection();

    echo json_encode(array('success' => true, 'result' => $result), JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(array('success' => false, 'message' => $e->getMessage())) . PHP_EOL;
    exit(1);
}
