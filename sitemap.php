<?php
require_once __DIR__ . '/products/config/config.php';
require_once __DIR__ . '/products/includes/db.php';

header('Content-Type: application/xml; charset=utf-8');

$baseUrl = 'https://accountsbazar.com';

date_default_timezone_set('UTC');

function xmlEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function isoDate($value) {
    $ts = strtotime((string) $value);
    if ($ts === false || $ts <= 0) {
        return gmdate('Y-m-d');
    }
    return gmdate('Y-m-d', $ts);
}

function firstImagePath($rawImage) {
    $rawImage = trim((string) $rawImage);
    if ($rawImage === '') {
        return '';
    }

    $list = array_values(array_filter(array_map('trim', explode(',', $rawImage))));
    $first = $list[0] ?? $rawImage;

    if (preg_match('/^https?:\/\//i', $first)) {
        return $first;
    }

    $first = ltrim($first, '/');
    if (strpos($first, 'images/') === 0) {
        $first = 'products/' . $first;
    }

    return $first;
}

$urls = array(
    array(
        'loc' => $baseUrl . '/',
        'changefreq' => 'daily',
        'priority' => '1.0',
        'lastmod' => gmdate('Y-m-d')
    ),
    array(
        'loc' => $baseUrl . '/shop.php',
        'changefreq' => 'daily',
        'priority' => '0.9',
        'lastmod' => gmdate('Y-m-d')
    ),
    array(
        'loc' => $baseUrl . '/offer-products.php',
        'changefreq' => 'daily',
        'priority' => '0.9',
        'lastmod' => gmdate('Y-m-d')
    ),
    array(
        'loc' => $baseUrl . '/ai-prompt.php',
        'changefreq' => 'weekly',
        'priority' => '0.8',
        'lastmod' => gmdate('Y-m-d')
    )
);

try {
    $db = new Database();
    $conn = $db->getConnection();

    $sql = 'SELECT id, name, image, created_at, updated_at FROM products WHERE quantity >= 0 ORDER BY id DESC';
    $res = $conn->query($sql);

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $image = firstImagePath($row['image'] ?? '');
            $imageLoc = '';
            if ($image !== '') {
                $imageLoc = preg_match('/^https?:\/\//i', $image) ? $image : ($baseUrl . '/' . $image);
            }

            $urls[] = array(
                'loc' => $baseUrl . '/product-details.php?id=' . (int) $row['id'],
                'changefreq' => 'weekly',
                'priority' => '0.7',
                'lastmod' => isoDate($row['updated_at'] ?? $row['created_at'] ?? ''),
                'image' => $imageLoc,
                'image_title' => trim((string) ($row['name'] ?? ''))
            );
        }
    }

    $db->closeConnection();
} catch (Throwable $e) {
    // Keep static URLs in sitemap even if DB is unavailable.
}

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

foreach ($urls as $item) {
    echo "  <url>\n";
    echo '    <loc>' . xmlEscape($item['loc']) . "</loc>\n";
    echo '    <lastmod>' . xmlEscape($item['lastmod']) . "</lastmod>\n";
    echo '    <changefreq>' . xmlEscape($item['changefreq']) . "</changefreq>\n";
    echo '    <priority>' . xmlEscape($item['priority']) . "</priority>\n";

    if (!empty($item['image'])) {
        echo "    <image:image>\n";
        echo '      <image:loc>' . xmlEscape($item['image']) . "</image:loc>\n";
        if (!empty($item['image_title'])) {
            echo '      <image:title>' . xmlEscape($item['image_title']) . "</image:title>\n";
            echo '      <image:caption>' . xmlEscape($item['image_title'] . ' | Accounts Bazar') . "</image:caption>\n";
        }
        echo "    </image:image>\n";
    }

    echo "  </url>\n";
}

echo "</urlset>\n";
