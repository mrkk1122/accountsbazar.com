<?php
// Run via cron every 1-5 minutes:
// php /path/to/accountsbazar.com/cron/push-worker.php

require_once __DIR__ . '/../products/config/config.php';
require_once __DIR__ . '/../products/includes/db.php';
require_once __DIR__ . '/../products/includes/webpush.php';

function queueLatestEvents($conn) {
    $items = array();

    $productRes = $conn->query('SELECT id, name, created_at FROM products WHERE quantity >= 0 ORDER BY created_at DESC, id DESC LIMIT 8');
    if ($productRes) {
        while ($row = $productRes->fetch_assoc()) {
            $items[] = array(
                'uid' => 'product-' . (int) $row['id'],
                'title' => 'New Product Added',
                'message' => (string) ($row['name'] ?? ''),
                'url' => 'product-details.php?id=' . (int) $row['id'],
            );
        }
    }

    $promptTable = $conn->query("SHOW TABLES LIKE 'ai_prompts'");
    if ($promptTable && $promptTable->num_rows > 0) {
        $promptRes = $conn->query('SELECT id, prompt_text FROM ai_prompts ORDER BY created_at DESC, id DESC LIMIT 5');
        if ($promptRes) {
            while ($row = $promptRes->fetch_assoc()) {
                $text = trim((string) ($row['prompt_text'] ?? ''));
                if (strlen($text) > 60) {
                    $text = substr($text, 0, 60) . '...';
                }
                $items[] = array(
                    'uid' => 'prompt-' . (int) $row['id'],
                    'title' => 'New AI Prompt Added',
                    'message' => $text,
                    'url' => 'ai-prompt.php#prompt-' . (int) $row['id'],
                );
            }
        }
    }

    foreach ($items as $item) {
        webpushQueueEvent($conn, $item['uid'], $item['title'], $item['message'], $item['url']);
    }
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    webpushEnsureTables($conn);
    queueLatestEvents($conn);
    $result = webpushSendPendingEvents($conn, 10);

    $db->closeConnection();

    echo json_encode(array('success' => true, 'result' => $result), JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(array('success' => false, 'message' => $e->getMessage())) . PHP_EOL;
    exit(1);
}
