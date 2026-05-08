<?php
session_start();
require_once __DIR__ . '/products/config/config.php';
require_once __DIR__ . '/products/includes/db.php';
require_once __DIR__ . '/products/includes/webpush.php';

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

$action = trim((string) ($data['action'] ?? 'subscribe'));
$userId = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

try {
    $db = new Database();
    $conn = $db->getConnection();
    webpushEnsureTables($conn);

    if ($action === 'unsubscribe') {
        $endpoint = (string) ($data['endpoint'] ?? '');
        $ok = webpushDeactivateByEndpoint($conn, $endpoint);
        $db->closeConnection();
        echo json_encode(array('success' => (bool) $ok));
        exit;
    }

    $subscription = $data['subscription'] ?? array();
    $ok = webpushUpsertSubscription($conn, $userId > 0 ? $userId : null, $subscription);
    $db->closeConnection();

    echo json_encode(array('success' => (bool) $ok));
} catch (Exception $e) {
    echo json_encode(array('success' => false, 'message' => 'Subscription save failed'));
}
