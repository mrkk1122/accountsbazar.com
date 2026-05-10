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

function fileIsoDate($filePath, $fallback = '') {
    $filePath = (string) $filePath;
    if ($filePath !== '' && is_file($filePath)) {
        $ts = @filemtime($filePath);
        if ($ts !== false && $ts > 0) {
            return gmdate('Y-m-d', $ts);
        }
    }

    if ($fallback !== '') {
        return isoDate($fallback);
    }

    return gmdate('Y-m-d');
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

function addSitemapUrl(&$urls, $item) {
    $loc = trim((string) ($item['loc'] ?? ''));
    if ($loc === '') {
        return;
    }

    $urls[$loc] = array_merge(array(
        'loc' => $loc,
        'changefreq' => 'weekly',
        'priority' => '0.5',
        'lastmod' => gmdate('Y-m-d'),
    ), $item);
}

function normalizeImageUrl($baseUrl, $imagePath) {
    $imagePath = trim((string) $imagePath);
    if ($imagePath === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $imagePath)) {
        return $imagePath;
    }

    return rtrim($baseUrl, '/') . '/' . ltrim($imagePath, '/');
}

function sitemapBuildDbNameVariants($primaryName, $fallbackName) {
    $names = array();

    $add = function ($value) use (&$names) {
        $value = trim((string) $value);
        if ($value === '' || in_array($value, $names, true)) {
            return;
        }
        $names[] = $value;
    };

    $add($primaryName);
    $add($fallbackName);

    $base = trim((string) $primaryName);
    if ($base !== '') {
        $add(str_replace('accounta_', 'accounts_', $base));
        $add(str_replace('accounts_', 'accounta_', $base));
        $add(str_replace('_', '', $base));
    }

    $add('accounta_bazar');
    $add('accounts_bazar');
    $add('accountsbazar');

    return $names;
}

function sitemapDetectCpanelUser() {
    $docRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($docRoot === '') {
        return '';
    }

    $normalized = str_replace('\\', '/', $docRoot);
    if (preg_match('#/home(?:\d+)?/([^/]+)/#', $normalized, $m)) {
        return trim((string) ($m[1] ?? ''));
    }

    return '';
}

function sitemapPrefixWithCpanelUser($cpanelUser, $value) {
    $cpanelUser = trim((string) $cpanelUser);
    $value = trim((string) $value);
    if ($cpanelUser === '' || $value === '') {
        return $value;
    }

    if (strpos($value, $cpanelUser . '_') === 0) {
        return $value;
    }

    return $cpanelUser . '_' . $value;
}

function sitemapOpenConnection() {
    $credentialSets = array(
        array(DB_HOST, DB_USER, DB_PASS),
    );

    if (defined('DB_FALLBACK_HOST') && defined('DB_FALLBACK_USER') && defined('DB_FALLBACK_PASS')) {
        $credentialSets[] = array(DB_FALLBACK_HOST, DB_FALLBACK_USER, DB_FALLBACK_PASS);
    }

    $dbNames = sitemapBuildDbNameVariants(DB_NAME, defined('DB_FALLBACK_NAME') ? DB_FALLBACK_NAME : '');
    $attempts = array();

    foreach ($credentialSets as $set) {
        foreach ($dbNames as $dbName) {
            $attempts[] = array($set[0], $set[1], $set[2], $dbName);
        }
    }

    $cpanelUser = sitemapDetectCpanelUser();
    if ($cpanelUser !== '') {
        foreach ($credentialSets as $set) {
            $prefixedUser = sitemapPrefixWithCpanelUser($cpanelUser, $set[1]);
            foreach ($dbNames as $dbName) {
                $attempts[] = array($set[0], $prefixedUser, $set[2], sitemapPrefixWithCpanelUser($cpanelUser, $dbName));
            }
        }
    }

    $seen = array();
    foreach ($attempts as $attempt) {
        $key = implode('|', $attempt);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        try {
            $conn = @new mysqli($attempt[0], $attempt[1], $attempt[2], $attempt[3]);
            if ($conn instanceof mysqli && !$conn->connect_error) {
                return $conn;
            }
        } catch (Throwable $e) {
            // Ignore and continue to next attempt.
        }
    }

    return null;
}

$urls = array();
$defaultImage = $baseUrl . '/images/logo.png';

$staticPages = array(
    array(
        'loc' => $baseUrl . '/',
        'file' => __DIR__ . '/index.php',
        'changefreq' => 'daily',
        'priority' => '1.0',
        'image' => $defaultImage,
        'image_title' => 'Accounts Bazar Home'
    ),
    array(
        'loc' => $baseUrl . '/shop.php',
        'file' => __DIR__ . '/shop.php',
        'changefreq' => 'daily',
        'priority' => '0.95',
        'image' => $defaultImage,
        'image_title' => 'Premium Digital Accounts Shop'
    ),
    array(
        'loc' => $baseUrl . '/offer-products.php',
        'file' => __DIR__ . '/offer-products.php',
        'changefreq' => 'daily',
        'priority' => '0.9',
        'image' => $defaultImage,
        'image_title' => 'Premium Subscription Offers'
    ),
    array(
        'loc' => $baseUrl . '/ai-prompt.php',
        'file' => __DIR__ . '/ai-prompt.php',
        'changefreq' => 'weekly',
        'priority' => '0.8',
        'image' => $defaultImage,
        'image_title' => 'AI Prompt Marketplace'
    ),
    array(
        'loc' => $baseUrl . '/login.php',
        'file' => __DIR__ . '/login.php',
        'changefreq' => 'monthly',
        'priority' => '0.4'
    ),
    array(
        'loc' => $baseUrl . '/register.php',
        'file' => __DIR__ . '/register.php',
        'changefreq' => 'monthly',
        'priority' => '0.4'
    )
);

foreach ($staticPages as $pageItem) {
    $pageItem['lastmod'] = fileIsoDate((string) ($pageItem['file'] ?? ''));
    unset($pageItem['file']);
    addSitemapUrl($urls, $pageItem);
}

try {
    $conn = sitemapOpenConnection();

    if ($conn) {
        $sql = 'SELECT id, name, image, created_at, updated_at FROM products WHERE quantity >= 0 ORDER BY id DESC';
        $res = $conn->query($sql);

        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $image = firstImagePath($row['image'] ?? '');
                $imageLoc = normalizeImageUrl($baseUrl, $image);
                $productLastmod = isoDate($row['updated_at'] ?? $row['created_at'] ?? '');
                $productTs = strtotime((string) ($row['updated_at'] ?? $row['created_at'] ?? ''));
                $priority = ($productTs !== false && $productTs >= strtotime('-14 days')) ? '0.8' : '0.7';

                addSitemapUrl($urls, array(
                    'loc' => $baseUrl . '/product-details.php?id=' . (int) $row['id'],
                    'changefreq' => 'weekly',
                    'priority' => $priority,
                    'lastmod' => $productLastmod,
                    'image' => $imageLoc,
                    'image_title' => trim((string) ($row['name'] ?? ''))
                ));
            }
        }

        $conn->close();
    }
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
