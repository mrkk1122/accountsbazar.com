<?php
session_start();
require_once 'products/config/config.php';
require_once 'products/includes/db.php';

$products = array();
$perPage = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$totalProducts = 0;
$totalPages = 1;
$discountPercent = 15;

try {
    $db = new Database();
    $conn = $db->getConnection();

    $countResult = $conn->query('SELECT COUNT(*) AS total FROM products WHERE quantity >= 0');
    if ($countResult && ($countRow = $countResult->fetch_assoc())) {
        $totalProducts = (int) $countRow['total'];
    }

    $totalPages = max(1, (int) ceil($totalProducts / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $sql = 'SELECT id, name, description, price, image, created_at FROM products WHERE quantity >= 0 ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $perPage, $offset);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
    }

    if (isset($stmt)) {
        $stmt->close();
    }

    $db->closeConnection();
} catch (Exception $e) {
    $products = array();
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<?php
$seo = [
    'title'       => 'অফার প্রোডাক্ট – বিশেষ ছাড়ে ডিজিটাল অ্যাকাউন্ট | Accounts Bazar',
    'description' => 'Accounts Bazar Offer Page-এ পান Netflix, Spotify, YouTube Premium সহ প্রিমিয়াম ডিজিটাল অ্যাকাউন্ট বিশেষ ছাড়ে। সীমিত সময়ের হট ডিল – এখনই অর্ডার করুন!',
    'keywords'    => 'offer products bangladesh, discount digital accounts, netflix offer bd, cheap subscriptions, hot deals accounts bazar, premium accounts discount, limited time offer bd',
    'canonical'   => 'https://accountsbazar.com/offer-products.php',
    'og_image'    => 'https://accountsbazar.com/images/logo.png',
    'og_type'     => 'website',
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
                    <input class="header-search-input" type="text" name="q" placeholder="Search products..." aria-label="Search products">
                </form>
                <button class="header-search-toggle" type="button" aria-label="Open search">🔎</button>
            </nav>
        </div>
    </header>

    <section class="products-section">
        <div class="container">
            <h1 class="section-title">Offer Products</h1>
            <p class="search-result-title">Special discount: <?php echo (int) $discountPercent; ?>% OFF</p>

            <div class="products-grid">
                <?php if (count($products) > 0): ?>
                    <?php foreach ($products as $product): ?>
                        <?php
                        $rawImages = (string) ($product['image'] ?? '');
                        $imageList = array_values(array_filter(array_map('trim', explode(',', $rawImages))));
                        $primaryImage = $imageList[0] ?? $rawImages;
                        $imagePath = ltrim((string) $primaryImage, '/');
                        $imageSrc = (strpos($imagePath, 'images/') === 0)
                            ? 'products/' . $imagePath
                            : $imagePath;

                        $oldPrice = (float) $product['price'];
                        $newPrice = $oldPrice - ($oldPrice * ((float) $discountPercent / 100));
                        ?>
                        <div
                            class="product-card product-card-clickable"
                            role="link"
                            tabindex="0"
                            onclick="window.location.href='product-details.php?id=<?php echo (int) $product['id']; ?>'"
                            onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); window.location.href='product-details.php?id=<?php echo (int) $product['id']; ?>'; }"
                        >
                            <?php if (!empty($product['image'])): ?>
                                <img class="product-image" src="<?php echo htmlspecialchars($imageSrc); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                            <?php else: ?>
                                <div class="product-image">📦</div>
                            <?php endif; ?>
                            <div class="product-info">
                                <div class="product-title"><?php echo htmlspecialchars($product['name']); ?></div>
                                <div class="offer-price-row">
                                    <span class="offer-old-price">৳ <?php echo number_format($oldPrice, 2); ?></span>
                                    <span class="offer-new-price">৳ <?php echo number_format($newPrice, 2); ?></span>
                                </div>
                                <div class="product-short-desc"><?php echo htmlspecialchars(substr((string) $product['description'], 0, 80)); ?></div>
                                <div class="product-actions">
                                    <button class="product-btn buy-btn" type="button" onclick="event.stopPropagation(); window.location.href='checkout.php?id=<?php echo (int) $product['id']; ?>'">Buy</button>
                                    <button class="product-btn cart-btn" type="button" onclick="event.stopPropagation(); addToCart(<?php echo (int) $product['id']; ?>)">Add Cart</button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>No offer products available right now.</p>
                <?php endif; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="pagination-wrap" aria-label="Offer Products Pagination">
                    <?php if ($page > 1): ?>
                        <a class="pagination-link" href="offer-products.php?page=<?php echo $page - 1; ?>">Previous</a>
                    <?php endif; ?>

                    <span class="pagination-status">Page <?php echo $page; ?> / <?php echo $totalPages; ?></span>

                    <?php if ($page < $totalPages): ?>
                        <a class="pagination-link" href="offer-products.php?page=<?php echo $page + 1; ?>">Next</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <footer class="footer">
        <div class="container">
            <p>&copy; 2026 Accounts Bazar. All rights reserved.</p>
        </div>
    </footer>

    <nav class="mobile-bottom-nav" aria-label="Mobile Bottom Navigation">
        <a href="index.php"><span class="nav-icon">🏠</span><span class="nav-label">Home</span></a>
        <a class="active" href="offer-products.php"><span class="nav-icon">🔥</span><span class="nav-label">Offer</span></a>
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
