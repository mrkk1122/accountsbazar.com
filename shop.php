<?php
session_start();
require_once 'products/config/config.php';
require_once 'products/includes/db.php';

function shopStmtFetchAssocRow($stmt) {
    if (!$stmt) {
        return null;
    }

    if (method_exists($stmt, 'get_result')) {
        $result = $stmt->get_result();
        if ($result instanceof mysqli_result) {
            $row = $result->fetch_assoc();
            return $row ?: null;
        }
    }

    $meta = $stmt->result_metadata();
    if (!$meta) {
        return null;
    }

    $fields = array();
    $bindVars = array();
    while ($field = $meta->fetch_field()) {
        $fields[$field->name] = null;
        $bindVars[] = &$fields[$field->name];
    }
    $meta->free();

    if (empty($bindVars)) {
        return null;
    }

    call_user_func_array(array($stmt, 'bind_result'), $bindVars);
    if (!$stmt->fetch()) {
        return null;
    }

    $row = array();
    foreach ($fields as $key => $value) {
        $row[$key] = $value;
    }

    return $row;
}

function shopStmtFetchAllRows($stmt) {
    $rows = array();
    if (!$stmt) {
        return $rows;
    }

    if (method_exists($stmt, 'get_result')) {
        $result = $stmt->get_result();
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            return $rows;
        }
    }

    $meta = $stmt->result_metadata();
    if (!$meta) {
        return $rows;
    }

    $fields = array();
    $bindVars = array();
    while ($field = $meta->fetch_field()) {
        $fields[$field->name] = null;
        $bindVars[] = &$fields[$field->name];
    }
    $meta->free();

    if (empty($bindVars)) {
        return $rows;
    }

    call_user_func_array(array($stmt, 'bind_result'), $bindVars);
    while ($stmt->fetch()) {
        $row = array();
        foreach ($fields as $key => $value) {
            $row[$key] = $value;
        }
        $rows[] = $row;
    }

    return $rows;
}

function shopColumnExists($conn, $table, $column) {
    if (!$conn) {
        return false;
    }

    $safeTable = $conn->real_escape_string((string) $table);
    $safeColumn = $conn->real_escape_string((string) $column);
    $res = $conn->query("SHOW COLUMNS FROM `" . $safeTable . "` LIKE '" . $safeColumn . "'");
    if (!$res) {
        return false;
    }
    $row = $res->fetch_assoc();
    $res->free();
    return !empty($row);
}

$products = array();
$searchQuery = trim($_GET['q'] ?? '');
$perPage = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$totalProducts = 0;
$totalPages = 1;

try {
    $db = new Database();
    $conn = $db->getConnection();

    $hasCategory = shopColumnExists($conn, 'products', 'category');
    $hasSku = shopColumnExists($conn, 'products', 'sku');
    $searchClauses = array('name LIKE ?', 'description LIKE ?');
    if ($hasCategory) {
        $searchClauses[] = 'category LIKE ?';
    }
    if ($hasSku) {
        $searchClauses[] = 'sku LIKE ?';
    }
    $searchWhere = implode(' OR ', $searchClauses);

    if ($searchQuery !== '') {
        $countSql = 'SELECT COUNT(*) AS total FROM products WHERE quantity >= 0 AND (' . $searchWhere . ')';
        $countStmt = $conn->prepare($countSql);
        $searchTerm = '%' . $searchQuery . '%';
        $countTypes = str_repeat('s', count($searchClauses));
        $countParams = array_fill(0, count($searchClauses), $searchTerm);
        $countBind = array_merge(array($countTypes), $countParams);
        $countRefs = array();
        foreach ($countBind as $k => $v) {
            $countRefs[$k] = &$countBind[$k];
        }
        call_user_func_array(array($countStmt, 'bind_param'), $countRefs);
        $countStmt->execute();
        $countRow = shopStmtFetchAssocRow($countStmt);
        if ($countRow) {
            $totalProducts = (int) $countRow['total'];
        }
        $countStmt->close();

        $sql = 'SELECT id, name, description, price, image, created_at FROM products WHERE quantity >= 0 AND (' . $searchWhere . ') ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?';
        $stmt = $conn->prepare($sql);
        $dataTypes = str_repeat('s', count($searchClauses)) . 'ii';
        $dataParams = array_fill(0, count($searchClauses), $searchTerm);
        $dataBind = array_merge(array($dataTypes), $dataParams, array($perPage, $offset));
        $dataRefs = array();
        foreach ($dataBind as $k => $v) {
            $dataRefs[$k] = &$dataBind[$k];
        }
        call_user_func_array(array($stmt, 'bind_param'), $dataRefs);
        $stmt->execute();
        $products = shopStmtFetchAllRows($stmt);
    } else {
        $countResult = $conn->query('SELECT COUNT(*) AS total FROM products WHERE quantity >= 0');
        if ($countResult && ($countRow = $countResult->fetch_assoc())) {
            $totalProducts = (int) $countRow['total'];
        }

        $sql = 'SELECT id, name, description, price, image, created_at FROM products WHERE quantity >= 0 ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $perPage, $offset);
        $stmt->execute();
        $products = shopStmtFetchAllRows($stmt);
    }

    $totalPages = max(1, (int) ceil($totalProducts / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }

    if (isset($stmt)) {
        $stmt->close();
    }

    $db->closeConnection();
} catch (Throwable $e) {
    $products = array();
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<?php
$seo = [
    'title'       => 'Shop Premium Accounts – YouTube Premium, CapCut Premium, VPN, ChatGPT | Accounts Bazar',
    'description' => 'Shop page থেকে YouTube Premium, CapCut Premium, VPN Premium, Google Veo Premium, ChatGPT Premium এবং অন্যান্য ডিজিটাল সাবস্ক্রিপশন খুঁজে কিনুন। বাংলাদেশে দ্রুত ডেলিভারি।',
    'keywords'    => 'shop youtube premium bangladesh, capcut premium buy bd, vpn premium subscription, google veo premium buy, chatgpt premium price bd, digital account shop bangladesh',
    'canonical'   => 'https://accountsbazar.com/shop.php',
    'og_image'    => 'https://accountsbazar.com/images/logo.png',
    'og_type'     => 'website',
    'extra_json_ld' => [
        [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => 'Premium Digital Accounts Shop',
            'url' => 'https://accountsbazar.com/shop.php',
            'description' => 'Browse premium subscriptions and digital tools sold by Accounts Bazar in Bangladesh.'
        ]
    ],
];
require_once 'products/includes/seo.php';
?>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/mobile.css">
</head>
<body>
    <header class="header">
        <div class="container">
            <nav class="navbar">
                <div class="store-brand">
                    <span class="store-title">Accounts Bazar</span>
                </div>
                <form class="header-search-form" method="GET" action="shop.php">
                    <input class="header-search-input" type="text" name="q" placeholder="Search products..." aria-label="Search products" value="<?php echo htmlspecialchars($searchQuery); ?>">
                </form>
                <button class="header-search-toggle" type="button" aria-label="Open search">🔎</button>
            </nav>
        </div>
    </header>

    <section class="products-section">
        <div class="container">
            <h1 class="section-title">Shop Products</h1>
            <?php if ($searchQuery !== ''): ?>
                <p class="search-result-title">Search result for: <?php echo htmlspecialchars($searchQuery); ?></p>
            <?php endif; ?>

            <?php
            $fromItem = ($totalProducts > 0) ? (($page - 1) * $perPage) + 1 : 0;
            $toItem = min($page * $perPage, $totalProducts);
            ?>
            <p class="search-result-title">Showing <?php echo $fromItem; ?>-<?php echo $toItem; ?> of <?php echo $totalProducts; ?> products</p>

            <div class="products-grid" id="products-container">
                <?php if (count($products) > 0): ?>
                    <?php foreach ($products as $product): ?>
                        <div
                            class="product-card product-card-clickable"
                            role="link"
                            tabindex="0"
                            onclick="window.location.href='product-details.php?id=<?php echo (int) $product['id']; ?>'"
                            onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); window.location.href='product-details.php?id=<?php echo (int) $product['id']; ?>'; }"
                        >
                            <?php if (!empty($product['image'])): ?>
                                <?php
                                $rawImages = (string) ($product['image'] ?? '');
                                $imageList = array_values(array_filter(array_map('trim', explode(',', $rawImages))));
                                $primaryImage = $imageList[0] ?? $rawImages;
                                $imagePath = ltrim((string) $primaryImage, '/');
                                $imageSrc = (strpos($imagePath, 'images/') === 0)
                                    ? 'products/' . $imagePath
                                    : $imagePath;
                                ?>
                                <img class="product-image" loading="lazy" decoding="async" src="<?php echo htmlspecialchars($imageSrc); ?>" alt="<?php echo htmlspecialchars($product['name'] . ' flower bouquet'); ?>">
                            <?php else: ?>
                                <div class="product-image">📦</div>
                            <?php endif; ?>
                            <div class="product-info">
                                <div class="product-title"><?php echo htmlspecialchars($product['name']); ?></div>
                                <div class="product-price">৳ <?php echo number_format((float) $product['price'], 2); ?></div>
                                <div class="product-short-desc"><?php echo htmlspecialchars(substr((string) $product['description'], 0, 80)); ?></div>
                                <div class="product-actions">
                                    <button class="product-btn buy-btn" type="button" onclick="event.stopPropagation(); window.location.href='checkout.php?id=<?php echo (int) $product['id']; ?>'">Buy</button>
                                    <button class="product-btn cart-btn" type="button" onclick="event.stopPropagation(); addToCart(<?php echo (int) $product['id']; ?>)">Add Cart</button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>No matching products found.</p>
                <?php endif; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="pagination-wrap" aria-label="Products Pagination">
                    <?php
                    $prevPage = max(1, $page - 1);
                    $nextPage = min($totalPages, $page + 1);
                    $baseParams = array();
                    if ($searchQuery !== '') {
                        $baseParams['q'] = $searchQuery;
                    }
                    $prevParams = $baseParams;
                    $prevParams['page'] = $prevPage;
                    $nextParams = $baseParams;
                    $nextParams['page'] = $nextPage;
                    ?>

                    <?php if ($page > 1): ?>
                        <a class="pagination-link" href="shop.php?<?php echo htmlspecialchars(http_build_query($prevParams)); ?>">Previous</a>
                    <?php endif; ?>

                    <span class="pagination-status">Page <?php echo $page; ?> / <?php echo $totalPages; ?></span>

                    <?php if ($page < $totalPages): ?>
                        <a class="pagination-link" href="shop.php?<?php echo htmlspecialchars(http_build_query($nextParams)); ?>">Next</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php require_once 'products/includes/site-footer.php'; ?>

    <nav class="mobile-bottom-nav" aria-label="Mobile Bottom Navigation">
        <a href="index.php"><span class="nav-icon">🏠</span><span class="nav-label">Home</span></a>
        <a class="active" href="shop.php"><span class="nav-icon">🛍️</span><span class="nav-label">Shop</span></a>
        <a class="ai-prompt-link" href="ai-prompt.php"><span class="nav-icon">🤖</span><span class="nav-label">AI Prompt</span></a>
        <a href="#" data-notification-toggle><span class="nav-icon">🔔</span><span class="nav-label">Notification</span><span class="notif-badge" data-notif-badge style="display:none;">0</span></a>
        <?php if (!empty($_SESSION['user_id'])): ?>
        <a href="profile.php"><span class="nav-icon">👤</span><span class="nav-label">Profile</span></a>
        <?php else: ?>
        <a href="login.php"><span class="nav-icon">👤</span><span class="nav-label">Login</span></a>
        <?php endif; ?>
    </nav>

    <script src="js/client.js"></script>
</body>
</html>
