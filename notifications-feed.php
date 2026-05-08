<?php
session_start();
require_once 'products/config/config.php';
require_once 'products/includes/db.php';
require_once 'products/includes/notifications.php';

header('Content-Type: application/json');

function jsonResponse($payload, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    $notificationManager = new NotificationManager();
    
    $items = array();
    
    // Get system/product notifications
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
                'url' => 'product-details.php?id=' . $productId,
                'category' => 'system'
            );
        }
    }

    // Check for AI Prompts
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
                    'url' => 'ai-prompt.php?id=' . $promptId,
                    'category' => 'system'
                );
            }
        }
    }

    // Get user notifications if logged in
    if (!empty($_SESSION['user_id'])) {
        $userId = (int) $_SESSION['user_id'];
        $userNotifications = $notificationManager->getUserNotifications($userId, 15);
        
        foreach ($userNotifications as $notif) {
            $items[] = array(
                'uid' => 'notif-' . $notif['id'],
                'type' => $notif['type'],
                'title' => (string) ($notif['title'] ?? ''),
                'message' => (string) ($notif['message'] ?? ''),
                'created_at' => (string) ($notif['created_at'] ?? ''),
                'is_read' => (bool) ($notif['is_read'] ?? false),
                'category' => 'personal'
            );
        }
    }

    // Sort by most recent
    usort($items, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });

    // Return limited results
    $items = array_slice($items, 0, 20);
    
    jsonResponse(array(
        'success' => true,
        'count' => count($items),
        'notifications' => $items
    ));
    
} catch (Exception $e) {
    jsonResponse(array(
        'success' => false,
        'error' => 'Failed to fetch notifications',
        'message' => MAIL_DEBUG_MODE ? $e->getMessage() : ''
    ), 500);
}
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
