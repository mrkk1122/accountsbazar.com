<?php
session_start();
require_once 'products/config/config.php';
require_once 'products/includes/db.php';

$product = null;
$suggestedProducts = array();
$reviews = array();
$reviewSuccess = '';
$reviewError = '';
$productId = (int) ($_GET['id'] ?? 0);

if ($productId > 0) {
    try {
        $db = new Database();
        $conn = $db->getConnection();

        $stmt = $conn->prepare('SELECT id, name, description, price, quantity, image, created_at FROM products WHERE id = ? AND quantity >= 0 LIMIT 1');
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result) {
            $product = $result->fetch_assoc();
        }

        $stmt->close();

        if ($product && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
            $rating = 5;
            $title = '';
            $reviewText = trim((string) ($_POST['review'] ?? ''));

            if ($reviewText === '') {
                $reviewError = 'Please write your review.';
            } else {
                $title = substr($title, 0, 255);

                if (empty($_SESSION['user_id'])) {
                    $reviewError = 'Please login first to submit a review.';
                } elseif (!empty($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
                    $reviewError = 'Admin account cannot submit customer reviews.';
                } else {
                    $reviewUserId = (int) $_SESSION['user_id'];
                    $insertReviewStmt = $conn->prepare('INSERT INTO reviews (product_id, user_id, rating, title, review, is_verified) VALUES (?, ?, ?, ?, ?, 1)');
                    $insertReviewStmt->bind_param('iiiss', $productId, $reviewUserId, $rating, $title, $reviewText);
                    if ($insertReviewStmt->execute()) {
                        $reviewSuccess = 'Thanks! Your review has been added.';
                    } else {
                        $reviewError = 'Could not submit review right now.';
                    }
                    $insertReviewStmt->close();
                }
            }
        }

        if ($product) {
            $suggestStmt = $conn->prepare('SELECT id, name, price, image FROM products WHERE id <> ? AND quantity >= 0 ORDER BY created_at DESC, id DESC LIMIT 4');
            $suggestStmt->bind_param('i', $productId);
            $suggestStmt->execute();
            $suggestResult = $suggestStmt->get_result();

            if ($suggestResult) {
                while ($row = $suggestResult->fetch_assoc()) {
                    $suggestedProducts[] = $row;
                }
            }

            $suggestStmt->close();

            $reviewStmt = $conn->prepare('SELECT r.id, r.rating, r.title, r.review, r.created_at, u.username, u.first_name, u.last_name FROM reviews r INNER JOIN users u ON u.id = r.user_id WHERE r.product_id = ? AND (u.user_type IS NULL OR u.user_type <> "admin") ORDER BY r.created_at DESC, r.id DESC LIMIT 3');
            $reviewStmt->bind_param('i', $productId);
            $reviewStmt->execute();
            $reviewResult = $reviewStmt->get_result();
            if ($reviewResult) {
                while ($reviewRow = $reviewResult->fetch_assoc()) {
                    $reviews[] = $reviewRow;
                }
            }
            $reviewStmt->close();
        }

        $db->closeConnection();
    } catch (Exception $e) {
        $product = null;
        $suggestedProducts = array();
    }
}

$seoTitle = $product ? htmlspecialchars($product['name']) . ' - Accounts Bazar' : 'Product Details - Accounts Bazar';
$seoDescription = $product
    ? mb_substr(trim((string) ($product['description'] ?? 'Premium product details at Accounts Bazar.')), 0, 155)
    : 'View premium product details, plans, reviews, and checkout options at Accounts Bazar.';
$seoCanonical = 'https://accountsbazar.com/product-details.php' . ($productId > 0 ? '?id=' . (int) $productId : '');
$seoKeywords = $product
    ? strtolower(trim((string) ($product['name'] ?? ''))) . ', buy digital account bangladesh, premium subscription, accounts bazar'
    : 'product details, premium accounts, digital subscriptions, accounts bazar';

$seoImage = 'https://accountsbazar.com/images/logo.png';
if ($product && !empty($product['image'])) {
    $rawImages = (string) ($product['image'] ?? '');
    $imageList = array_values(array_filter(array_map('trim', explode(',', $rawImages))));
    $primaryImage = (string) ($imageList[0] ?? $rawImages);
    if ($primaryImage !== '') {
        $normalizedImage = ltrim($primaryImage, '/');
        if (strpos($normalizedImage, 'images/') === 0) {
            $normalizedImage = 'products/' . $normalizedImage;
        }
        if (preg_match('/^https?:\/\//i', $normalizedImage)) {
            $seoImage = $normalizedImage;
        } else {
            $seoImage = 'https://accountsbazar.com/' . $normalizedImage;
        }
    }
}

$reviewCount = count($reviews);
$ratingValue = 5;
if ($reviewCount > 0) {
    $sum = 0;
    foreach ($reviews as $item) {
        $sum += max(1, min(5, (int) ($item['rating'] ?? 5)));
    }
    $ratingValue = round($sum / $reviewCount, 1);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php
$seo = [
    'title'       => html_entity_decode($seoTitle, ENT_QUOTES, 'UTF-8'),
    'description' => $seoDescription,
    'keywords'    => $seoKeywords,
    'canonical'   => $seoCanonical,
    'og_image'    => $seoImage,
    'og_type'     => 'product',
];
require_once 'products/includes/seo.php';
?>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/mobile.css">
    <?php if ($product): ?>
    <script type="application/ld+json">
    <?php
    $productSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => (string) ($product['name'] ?? ''),
        'description' => (string) ($seoDescription ?? ''),
        'image' => [$seoImage],
        'sku' => (string) ($product['id'] ?? ''),
        'brand' => [
            '@type' => 'Brand',
            'name' => 'Accounts Bazar',
        ],
        'offers' => [
            '@type' => 'Offer',
            'url' => $seoCanonical,
            'priceCurrency' => 'BDT',
            'price' => number_format((float) ($product['price'] ?? 0), 2, '.', ''),
            'availability' => ((int) ($product['quantity'] ?? 0) > 0)
                ? 'https://schema.org/InStock'
                : 'https://schema.org/OutOfStock',
            'itemCondition' => 'https://schema.org/NewCondition',
        ],
    ];
    if ($reviewCount > 0) {
        $productSchema['aggregateRating'] = [
            '@type' => 'AggregateRating',
            'ratingValue' => $ratingValue,
            'reviewCount' => $reviewCount,
        ];
    }
    echo json_encode($productSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>
    </script>
    <?php endif; ?>
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

    <section class="product-details-page">
        <div class="container">
            <div class="product-details-wrap">
                <a class="details-back-link" href="javascript:history.back()">← Back</a>

                <?php if ($product): ?>
                    <?php
                    $rawImages = (string) ($product['image'] ?? '');
                    $productImages = array_values(array_filter(array_map('trim', explode(',', $rawImages))));
                    $sliderImages = array();
                    foreach ($productImages as $pImg) {
                        $imagePath = ltrim((string) $pImg, '/');
                        $sliderImages[] = (strpos($imagePath, 'images/') === 0)
                            ? 'products/' . $imagePath
                            : $imagePath;
                    }

                    foreach ($suggestedProducts as $suggestedItem) {
                        if (!empty($suggestedItem['image'])) {
                            $suggestRaw = (string) $suggestedItem['image'];
                            $suggestList = array_values(array_filter(array_map('trim', explode(',', $suggestRaw))));
                            $sPrimary = $suggestList[0] ?? $suggestRaw;
                            $sPath = ltrim((string) $sPrimary, '/');
                            $sliderImages[] = (strpos($sPath, 'images/') === 0)
                                ? 'products/' . $sPath
                                : $sPath;
                        }
                    }

                    $sliderImages = array_values(array_unique($sliderImages));

                    if (count($sliderImages) === 0) {
                        $sliderImages[] = 'images/logo.png';
                    }

                    while (count($sliderImages) < 4) {
                        $sliderImages[] = $sliderImages[0];
                    }

                    $sliderImages = array_slice($sliderImages, 0, 4);
                    ?>
                    <div class="product-details-grid">
                        <div class="details-image-box">
                            <div class="details-slider" data-auto-slider>
                                <?php foreach ($sliderImages as $index => $sliderImage): ?>
                                    <img
                                        class="details-slide<?php echo $index === 0 ? ' active' : ''; ?>"
                                        src="<?php echo htmlspecialchars($sliderImage); ?>"
                                        alt="<?php echo htmlspecialchars($product['name']); ?> image <?php echo $index + 1; ?>"
                                    >
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="details-content">
                            <h1 class="details-title"><?php echo htmlspecialchars($product['name']); ?></h1>
                            <div class="details-price" id="details-price" data-base-price="<?php echo htmlspecialchars(number_format((float) $product['price'], 2, '.', '')); ?>">৳ <?php echo number_format((float) $product['price'], 2); ?></div>
                            <div class="details-qty">Available Quantity: <?php echo (int) $product['quantity']; ?></div>
                            <div class="details-plan-wrap">
                                <label class="details-plan-label" for="plan-duration">Select Duration</label>
                                <select id="plan-duration" class="details-plan-select" name="plan_duration">
                                    <option value="1-month">1 Month</option>
                                    <option value="2-month">2 Month</option>
                                    <option value="6-month">6 Month</option>
                                    <option value="lifetime">Life Time</option>
                                </select>
                            </div>
                            <div class="details-actions">
                                <button class="product-btn buy-btn details-buy-full" type="button" onclick="const planEl=document.getElementById('plan-duration'); const planValue=planEl ? encodeURIComponent(planEl.value) : ''; window.location.href='checkout.php?id=<?php echo (int) $product['id']; ?>&plan=' + planValue;">Buy</button>
                                <button class="product-btn cart-btn" type="button" onclick="addToCart(<?php echo (int) $product['id']; ?>)">Add Cart</button>
                            </div>
                            <?php
                            $descriptionText = trim((string) ($product['description'] ?? ''));
                            $descriptionLines = $descriptionText !== ''
                                ? preg_split('/\r\n|\r|\n/', $descriptionText)
                                : array('No description available.');
                            ?>
                            <ul class="details-desc details-desc-list">
                                <?php foreach ($descriptionLines as $line): ?>
                                    <?php if (trim($line) !== ''): ?>
                                        <li><?php echo htmlspecialchars($line); ?></li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="details-empty">Product not found.</p>
                <?php endif; ?>
            </div>

            <?php if ($product): ?>
                <section class="details-reviews-section" aria-label="Customer Reviews">
                    <h2 class="details-reviews-title">Customer Reviews</h2>

                    <form class="details-review-form" method="POST" action="product-details.php?id=<?php echo (int) $productId; ?>">
                        <?php if ($reviewSuccess !== ''): ?>
                            <p class="review-message-success"><?php echo htmlspecialchars($reviewSuccess); ?></p>
                        <?php endif; ?>
                        <?php if ($reviewError !== ''): ?>
                            <p class="review-message-error"><?php echo htmlspecialchars($reviewError); ?></p>
                        <?php endif; ?>

                        <p class="review-fixed-stars" aria-label="5 star rating">★★★★★</p>

                        <div class="review-form-row">
                            <label for="review">Your Review</label>
                            <textarea id="review" name="review" rows="4" placeholder="Write your review" required></textarea>
                        </div>

                        <button type="submit" name="submit_review" class="product-btn cart-btn">Submit Review</button>
                    </form>

                    <div class="details-reviews-list" id="reviews-list">
                        <?php if (count($reviews) > 0): ?>
                            <?php foreach ($reviews as $item): ?>
                                <?php
                                $displayName = trim((string) (($item['first_name'] ?? '') . ' ' . ($item['last_name'] ?? '')));
                                if ($displayName === '') {
                                    $displayName = (string) ($item['username'] ?? 'Customer');
                                }
                                $stars = str_repeat('★', (int) $item['rating']) . str_repeat('☆', max(0, 5 - (int) $item['rating']));
                                ?>
                                <article class="details-review-item">
                                    <div class="review-top">
                                        <strong class="review-user"><?php echo htmlspecialchars($displayName); ?></strong>
                                        <span class="review-stars"><?php echo $stars; ?></span>
                                    </div>
                                    <?php if (!empty($item['title'])): ?>
                                        <h3 class="review-item-title"><?php echo htmlspecialchars((string) $item['title']); ?></h3>
                                    <?php endif; ?>
                                    <p class="review-item-text"><?php echo nl2br(htmlspecialchars((string) $item['review'])); ?></p>
                                </article>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="details-empty">No reviews yet. Be the first to review.</p>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($product && count($suggestedProducts) > 0): ?>
                <section class="details-suggest-section" aria-label="Suggested Products">
                    <h2 class="details-suggest-title">Suggested Products</h2>
                    <div class="details-suggest-grid">
                        <?php foreach ($suggestedProducts as $suggested): ?>
                            <?php
                            $suggestRaw = (string) ($suggested['image'] ?? '');
                            $suggestList = array_values(array_filter(array_map('trim', explode(',', $suggestRaw))));
                            $suggestPrimary = $suggestList[0] ?? $suggestRaw;
                            $suggestImagePath = ltrim((string) $suggestPrimary, '/');
                            $suggestImageSrc = (strpos($suggestImagePath, 'images/') === 0)
                                ? 'products/' . $suggestImagePath
                                : $suggestImagePath;
                            ?>
                            <article class="details-suggest-card" onclick="window.location.href='product-details.php?id=<?php echo (int) $suggested['id']; ?>'" role="link" tabindex="0" onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); window.location.href='product-details.php?id=<?php echo (int) $suggested['id']; ?>'; }">
                                <?php if (!empty($suggested['image'])): ?>
                                    <img class="details-suggest-image" src="<?php echo htmlspecialchars($suggestImageSrc); ?>" alt="<?php echo htmlspecialchars($suggested['name']); ?>">
                                <?php else: ?>
                                    <div class="details-suggest-image">No Image</div>
                                <?php endif; ?>
                                <div class="details-suggest-body">
                                    <h3 class="details-suggest-name"><?php echo htmlspecialchars($suggested['name']); ?></h3>
                                    <p class="details-suggest-price">৳ <?php echo number_format((float) $suggested['price'], 2); ?></p>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
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
        <a href="shop.php"><span class="nav-icon">🛍️</span><span class="nav-label">Shop</span></a>
        <a class="ai-prompt-link" href="ai-prompt.php"><span class="nav-icon">🤖</span><span class="nav-label">AI Prompt</span></a>
        <a href="#" data-notification-toggle><span class="nav-icon">🔔</span><span class="nav-label">Notification</span><span class="notif-badge" data-notif-badge style="display:none;">0</span></a>
        <?php if (!empty($_SESSION['user_id'])): ?>
        <a href="profile.php"><span class="nav-icon">👤</span><span class="nav-label">Profile</span></a>
        <?php else: ?>
        <a href="login.php"><span class="nav-icon">👤</span><span class="nav-label">Login</span></a>
        <?php endif; ?>
    </nav>

    <script src="js/client.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const planEl = document.getElementById('plan-duration');
            const priceEl = document.getElementById('details-price');
            if (planEl && priceEl) {
                const basePrice = parseFloat(priceEl.getAttribute('data-base-price') || '0');
                const planMultiplier = {
                    '1-month': 1,
                    '2-month': 2,
                    '6-month': 6,
                    'lifetime': 12
                };

                const formatBdt = function (value) {
                    return value.toLocaleString('en-US', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                };

                const updatePriceByPlan = function () {
                    const selectedPlan = planEl.value || '1-month';
                    const multiplier = planMultiplier[selectedPlan] || 1;
                    const finalPrice = basePrice * multiplier;
                    priceEl.textContent = '৳ ' + formatBdt(finalPrice);
                };

                planEl.addEventListener('change', updatePriceByPlan);
                updatePriceByPlan();
            }

            const slider = document.querySelector('[data-auto-slider]');
            if (!slider) {
                return;
            }

            const slides = slider.querySelectorAll('.details-slide');
            if (slides.length <= 1) {
                return;
            }

            let current = 0;
            setInterval(function () {
                slides[current].classList.remove('active');
                current = (current + 1) % slides.length;
                slides[current].classList.add('active');
            }, 2200);

        });
    </script>
</body>
</html>
