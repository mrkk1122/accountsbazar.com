<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = db();

    $search = trim((string) ($_GET['q'] ?? ''));
    $category = trim((string) ($_GET['category'] ?? 'all'));

    $sql = 'SELECT id, category, platform, title, description, badge, price_usd, seller_status
            FROM products
            WHERE is_active = 1';

    $params = [];

    if ($category !== '' && $category !== 'all') {
        $sql .= ' AND category = :category';
        $params['category'] = $category;
    }

    if ($search !== '') {
        $sql .= ' AND (title LIKE :search OR platform LIKE :search OR description LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    $sql .= ' ORDER BY id DESC LIMIT 60';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode([
        'success' => true,
        'products' => $stmt->fetchAll(),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to load products',
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
}
