<?php
session_start();
require_once 'products/config/config.php';
require_once 'products/includes/db.php';

header('Content-Type: application/json');

$items = array();

try {
    $db = new Database();
    $conn = $db->getConnection();

    $productSql = 'SELECT id, name, created_at FROM products WHERE quantity >= 0 ORDER BY created_at DESC, id DESC LIMIT 8';
    $productRes = $conn->query($productSql);
    if ($productRes) {
        while ($row = $productRes->fetch_assoc()) {
            $productId = (int) ($row['id'] ?? 0);
            $items[] = array(
                'uid' => 'product-' . $productId,
                'type' => 'product',
                'title' => 'New Product Added',
                'message' => (string) ($row['name'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'url' => 'product-details.php?id=' . $productId
            );
        }
    }

    $hasPromptTable = false;
    $tableCheck = $conn->query("SHOW TABLES LIKE 'ai_prompts'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $hasPromptTable = true;
    }

    if ($hasPromptTable) {
        $promptSql = 'SELECT id, prompt_text, created_at FROM ai_prompts ORDER BY created_at DESC, id DESC LIMIT 8';
        $promptRes = $conn->query($promptSql);
        if ($promptRes) {
            while ($row = $promptRes->fetch_assoc()) {
                $promptId = (int) ($row['id'] ?? 0);
                $text = trim((string) ($row['prompt_text'] ?? ''));
                if (strlen($text) > 60) {
                    $text = substr($text, 0, 60) . '...';
                }
                $items[] = array(
                    'uid' => 'prompt-' . $promptId,
                    'type' => 'ai_prompt',
                    'title' => 'New AI Prompt Added',
                    'message' => $text,
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'url' => 'ai-prompt.php#prompt-' . $promptId
                );
            }
        }
    }

    $hasReviewTable = false;
    $reviewCheck = $conn->query("SHOW TABLES LIKE 'reviews'");
    if ($reviewCheck && $reviewCheck->num_rows > 0) {
        $hasReviewTable = true;
    }

    if ($hasReviewTable) {
        $reviewSql = 'SELECT r.id, r.product_id, r.created_at, p.name AS product_name FROM reviews r LEFT JOIN products p ON p.id = r.product_id ORDER BY r.created_at DESC, r.id DESC LIMIT 8';
        $reviewRes = $conn->query($reviewSql);
        if ($reviewRes) {
            while ($row = $reviewRes->fetch_assoc()) {
                $productId = (int) ($row['product_id'] ?? 0);
                $items[] = array(
                    'uid' => 'review-' . (int) $row['id'],
                    'type' => 'review',
                    'title' => 'New Customer Review',
                    'message' => 'Product: ' . (string) ($row['product_name'] ?? 'Unknown'),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'url' => ($productId > 0 ? 'product-details.php?id=' . $productId : 'shop.php')
                );
            }
        }
    }

    usort($items, function ($a, $b) {
        return strtotime((string) $b['created_at']) <=> strtotime((string) $a['created_at']);
    });

    $items = array_slice($items, 0, 12);

    $db->closeConnection();

    echo json_encode(array(
        'success' => true,
        'items' => $items,
        'server_time' => date('Y-m-d H:i:s')
    ));
} catch (Exception $e) {
    echo json_encode(array(
        'success' => false,
        'items' => array(),
        'message' => 'Notification feed unavailable.'
    ));
}
