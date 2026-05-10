<?php
ob_start();

register_shutdown_function(function () {
    $err = error_get_last();
    if (!is_array($err)) {
        return;
    }
    $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
    if (!in_array((int) ($err['type'] ?? 0), $fatalTypes, true)) {
        return;
    }
    ob_end_clean();
    if (!headers_sent()) {
        header('HTTP/1.1 500 Internal Server Error');
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Error - Accounts Bazar</title>';
    echo '<style>body{font-family:Arial,sans-serif;background:#f8fafc;color:#0f172a;display:flex;';
    echo 'align-items:center;justify-content:center;min-height:100vh;margin:0}';
    echo '.box{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px 28px;';
    echo 'max-width:520px;width:90%;box-shadow:0 8px 30px rgba(15,23,42,.08);text-align:center}';
    echo 'h1{font-size:20px;margin:0 0 10px;color:#dc2626}p{margin:6px 0;font-size:14px;color:#475569}';
    echo 'a{color:#2563eb;text-decoration:none}';
    echo '</style></head><body><div class="box">';
    echo '<h1>&#9888; Page Error</h1>';
    echo '<p>An error occurred loading this page. Please try again.</p>';
    echo '<p><a href="index.php">Return to Home</a></p>';
    echo '</div></body></html>';
    exit;
});

session_start();
require_once 'products/config/config.php';
require_once 'products/includes/db.php';
require_once 'products/includes/mailer.php';
require_once 'products/includes/notifications.php';

function checkoutResolveUserId($conn, $fullName, $email, $phone) {
    if (!empty($_SESSION['user_id'])) {
        return (int) $_SESSION['user_id'];
    }

    $email = strtolower(trim((string) $email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 0;
    }

    $find = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $find->bind_param('s', $email);
    $find->execute();
    $existing = $find->get_result()->fetch_assoc();
    $find->close();
    if ($existing) {
        return (int) $existing['id'];
    }

    $nameParts = preg_split('/\s+/', trim((string) $fullName));
    $firstName = (string) ($nameParts[0] ?? 'Guest');
    $lastName = (string) ($nameParts[1] ?? 'Customer');
    $baseUsername = 'guest_' . preg_replace('/[^a-z0-9]/i', '', strstr($email, '@', true) ?: 'user');
    if ($baseUsername === 'guest_') {
        $baseUsername = 'guest_user';
    }
    $username = $baseUsername . '_' . substr(md5((string) microtime(true)), 0, 6);
    $passwordHash = password_hash(bin2hex(random_bytes(12)), PASSWORD_BCRYPT);

    $ins = $conn->prepare('INSERT INTO users (username, email, password, first_name, last_name, phone, user_type, is_active) VALUES (?, ?, ?, ?, ?, ?, "customer", 1)');
    $ins->bind_param('ssssss', $username, $email, $passwordHash, $firstName, $lastName, $phone);
    $ok = $ins->execute();
    $guestId = $ok ? (int) $conn->insert_id : 0;
    $ins->close();

    return $guestId;
}

// ---- Handle AJAX order submission (supports guest checkout) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');

    $pid        = (int) ($_POST['product_id']      ?? 0);
    $plan       = trim((string) ($_POST['plan']    ?? '1-month'));
    $fullName   = trim((string) ($_POST['name']    ?? ''));
    $phone      = trim((string) ($_POST['phone']   ?? ''));
    $email      = trim((string) ($_POST['address'] ?? ''));
    $payMethod  = trim((string) ($_POST['payment_method'] ?? ''));
    $trxId      = trim((string) ($_POST['trx_id'] ?? ''));
    $couponCode     = strtoupper(trim((string) ($_POST['coupon_code'] ?? '')));

    if (!$pid || !$fullName || !$phone || !$email || !$payMethod || !$trxId) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Please provide a valid email.']);
        exit;
    }

    $allowedPlans = ['1-month'=>'1 Month','2-month'=>'2 Month','6-month'=>'6 Month','lifetime'=>'Life Time'];
    $planMultiplier = ['1-month' => 1, '2-month' => 2, '6-month' => 6, 'lifetime' => 12];
    $coupons = ['FLOWER10' => 10, 'LOVE15' => 15, 'WELCOME5' => 5];

    if (!isset($allowedPlans[$plan])) {
        $plan = '1-month';
    }
    try {
        $db   = new Database();
        $conn = $db->getConnection();

        $ps = $conn->prepare('SELECT id, name, price FROM products WHERE id = ? AND quantity >= 0 LIMIT 1');
        $ps->bind_param('i', $pid);
        $ps->execute();
        $prod = $ps->get_result()->fetch_assoc();
        $ps->close();

        if (!$prod) {
            echo json_encode(['success' => false, 'message' => 'Product not found.']);
            exit;
        }

        $basePrice = (float) $prod['price'];
        $multiplier = (float) ($planMultiplier[$plan] ?? 1);
        $subtotal = $basePrice * $multiplier;
        $discountPercent = (float) ($coupons[$couponCode] ?? 0);
        $discountAmount = round($subtotal * ($discountPercent / 100), 2);
        $price = max(0, $subtotal - $discountAmount);

        $userId = checkoutResolveUserId($conn, $fullName, $email, $phone);
        if ($userId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Could not create guest checkout profile.']);
            exit;
        }

        $orderNum  = 'ORD-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));

        $shippAddr = "Name: $fullName | Phone: $phone | Email: $email | Plan: {$allowedPlans[$plan]}\n";
        $shippAddr .= 'Delivery: Standard';

        $notes  = "Payment: $payMethod | TrxID: $trxId\n";
        $notes .= "Coupon: " . ($couponCode !== '' ? $couponCode : 'N/A') . " | Discount: " . number_format($discountAmount, 2) . "\n";

        $ins = $conn->prepare(
            'INSERT INTO orders (order_number, user_id, total_amount, status, payment_method, payment_status, shipping_address, notes)
             VALUES (?, ?, ?, "pending", ?, "unpaid", ?, ?)'
        );
        $ins->bind_param('sidsss', $orderNum, $userId, $price, $payMethod, $shippAddr, $notes);
        $ins->execute();
        $orderId = $conn->insert_id;
        $ins->close();

        $ii = $conn->prepare('INSERT INTO order_items (order_id, product_id, quantity, unit_price, total_price) VALUES (?, ?, 1, ?, ?)');
        $ii->bind_param('iidd', $orderId, $pid, $price, $price);
        $ii->execute();
        $ii->close();

        try {
            $notificationManager = new NotificationManager();
            $mailSubject = 'Order Confirmed: ' . $orderNum . ' | Accounts Bazar';
            $mailContent = '<p>Dear ' . htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') . ',</p>';
            $mailContent .= '<p>Thank you for your order! Here are your details:</p>';
            $mailContent .= '<ul>';
            $mailContent .= '<li><strong>Order Number:</strong> ' . htmlspecialchars($orderNum, ENT_QUOTES, 'UTF-8') . '</li>';
            $mailContent .= '<li><strong>Product:</strong> ' . htmlspecialchars((string) $prod['name'], ENT_QUOTES, 'UTF-8') . '</li>';
            $mailContent .= '<li><strong>Plan:</strong> ' . htmlspecialchars($allowedPlans[$plan], ENT_QUOTES, 'UTF-8') . '</li>';
            $mailContent .= '<li><strong>Delivery:</strong> Standard</li>';
            $mailContent .= '<li><strong>Subtotal:</strong> BDT ' . number_format($subtotal, 2) . '</li>';
            $mailContent .= '<li><strong>Discount:</strong> BDT ' . number_format($discountAmount, 2) . '</li>';
            $mailContent .= '<li><strong>Final Total:</strong> BDT ' . number_format($price, 2) . '</li>';
            $mailContent .= '<li><strong>Payment:</strong> ' . htmlspecialchars($payMethod, ENT_QUOTES, 'UTF-8') . '</li>';
            $mailContent .= '<li><strong>TrxID:</strong> ' . htmlspecialchars($trxId, ENT_QUOTES, 'UTF-8') . '</li>';
            $mailContent .= '</ul>';

            $notificationManager->sendNotification(
                $userId,
                'order',
                'Order Confirmed',
                'Your order ' . $orderNum . ' has been received. Amount: BDT ' . number_format($price, 2),
                $orderNum,
                array(
                    'email' => $email,
                    'subject' => $mailSubject,
                    'body' => getEmailTemplate('Order Confirmation', $mailContent, 'View Orders', 'https://accountsbazar.com/profile.php')
                )
            );
        } catch (Exception $e) {
            logMailActivity($email, 'Order ' . $orderNum, 'ERROR', $e->getMessage());
        }

        $db->closeConnection();

        echo json_encode([
            'success' => true,
            'order_number' => $orderNum,
            'total_amount' => number_format($price, 2, '.', ''),
            'discount_amount' => number_format($discountAmount, 2, '.', ''),
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    }
    exit;
}

$product = null;
$productId = (int) ($_GET['id'] ?? 0);
$selectedPlan = trim((string) ($_GET['plan'] ?? '1-month'));
$allowedPlans = array(
    '1-month' => '1 Month',
    '2-month' => '2 Month',
    '6-month' => '6 Month',
    'lifetime' => 'Life Time'
);

if (!isset($allowedPlans[$selectedPlan])) {
    $selectedPlan = '1-month';
}

$planMultiplier = array(
    '1-month' => 1,
    '2-month' => 2,
    '6-month' => 6,
    'lifetime' => 12
);

// ---- Auto-fill billing info from logged-in user ----
$billingName    = '';
$billingPhone   = '';
$billingAddress = '';
if (!empty($_SESSION['user_id'])) {
    try {
        $db   = new Database();
        $conn = $db->getConnection();
        $us   = $conn->prepare('SELECT first_name, last_name, phone, email FROM users WHERE id = ? LIMIT 1');
        $us->bind_param('i', $_SESSION['user_id']);
        $us->execute();
        $uRow = $us->get_result()->fetch_assoc();
        $us->close();
        $db->closeConnection();
        if ($uRow) {
            $billingName    = trim($uRow['first_name'] . ' ' . $uRow['last_name']);
            $billingPhone   = $uRow['phone'] ?? '';
            $billingAddress = $uRow['email'] ?? '';
        }
    } catch (Exception $e) {}
}

if ($productId > 0) {
    try {
        $db = new Database();
        $conn = $db->getConnection();

        $stmt = $conn->prepare('SELECT id, name, price, image FROM products WHERE id = ? AND quantity >= 0 LIMIT 1');
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result) {
            $product = $result->fetch_assoc();
        }

        $stmt->close();
        $db->closeConnection();
    } catch (Exception $e) {
        $product = null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php
$seo = [
    'title'       => 'Checkout - Accounts Bazar',
    'description' => 'Secure checkout page for completing your Accounts Bazar order.',
    'keywords'    => 'accounts bazar checkout, secure digital account payment, bkash nagad rocket payment',
    'canonical'   => 'https://accountsbazar.com/checkout.php',
    'og_image'    => 'https://accountsbazar.com/images/logo.png',
    'og_type'     => 'website',
    'noindex'     => true,
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

    <section class="product-details-page">
        <div class="container">
            <div class="product-details-wrap checkout-wrap">
                <a class="details-back-link" href="javascript:history.back()">← Back</a>
                <h1 class="details-title">Checkout</h1>

                <?php if ($product): ?>
                    <?php
                    $rawImages = (string) ($product['image'] ?? '');
                    $imageList = array_values(array_filter(array_map('trim', explode(',', $rawImages))));
                    $primaryImage = $imageList[0] ?? $rawImages;
                    $imagePath = ltrim((string) $primaryImage, '/');
                    $imageSrc = (strpos($imagePath, 'images/') === 0)
                        ? 'products/' . $imagePath
                        : $imagePath;
                    ?>
                    <div class="checkout-grid">
                        <div class="details-image-box">
                            <?php if (!empty($product['image'])): ?>
                                <img class="details-image" loading="lazy" decoding="async" src="<?php echo htmlspecialchars($imageSrc); ?>" alt="<?php echo htmlspecialchars($product['name'] . ' flower bouquet'); ?>">
                            <?php else: ?>
                                <div class="details-image">No image available</div>
                            <?php endif; ?>
                        </div>
                        <div class="details-content">
                            <?php
                            $checkoutMultiplier = (float) ($planMultiplier[$selectedPlan] ?? 1);
                            $checkoutPrice = (float) $product['price'] * $checkoutMultiplier;
                            ?>
                            <h2 class="details-title" style="font-size:28px;"><?php echo htmlspecialchars($product['name']); ?></h2>
                            <div class="details-price">৳ <?php echo number_format($checkoutPrice, 2); ?></div>
                            <div class="details-qty">Plan: <?php echo htmlspecialchars($allowedPlans[$selectedPlan]); ?></div>

                            <form class="details-review-form" id="checkout-form" action="#" method="POST" onsubmit="return handleCheckoutSubmit(event);">
                                <div class="review-form-row">
                                    <label for="customer-name">Full Name</label>
                                    <input id="customer-name" type="text" required placeholder="Enter your full name" value="<?php echo htmlspecialchars($billingName); ?>">
                                </div>
                                <div class="review-form-row">
                                    <label for="customer-phone">Phone</label>
                                    <input id="customer-phone" type="text" required placeholder="Enter phone number" value="<?php echo htmlspecialchars($billingPhone); ?>">
                                </div>
                                <div class="review-form-row">
                                    <label for="customer-email">Email</label>
                                    <input id="customer-email" type="email" required placeholder="Enter your email" value="<?php echo htmlspecialchars($billingAddress); ?>">
                                </div>

                                <div class="review-form-row review-form-row-half">
                                    <div>
                                        <label for="coupon-code">Coupon</label>
                                        <input id="coupon-code" type="text" placeholder="FLOWER10" style="text-transform:uppercase;">
                                    </div>
                                    <div style="display:flex;align-items:flex-end;">
                                        <button type="button" class="product-btn cart-btn" style="width:100%;" onclick="applyCoupon()">Apply Coupon</button>
                                    </div>
                                </div>
                                <p id="coupon-hint" style="margin:-6px 0 12px;color:#64748b;font-size:12px;">Suggested: FLOWER10, LOVE15, WELCOME5</p>

                                <div class="checkout-trust-strip" style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:10px 0 18px;">
                                    <div class="trust-pill">🔒 Secure Checkout</div>
                                    <div class="trust-pill">⚡ Same-Day Support</div>
                                    <div class="trust-pill">✅ Verified Payment</div>
                                </div>

                                <!-- Payment Method Selection -->
                                <div class="payment-section">
                                    <label class="payment-section-label">Payment Method</label>
                                    <div class="payment-methods-grid">
                                        <!-- bKash -->
                                        <div class="payment-method-card" data-method="bkash" onclick="selectPayment(this)">
                                            <div class="pm-logo">
                                                <img src="images/bkash-logo-free-vector.jpg" alt="bKash" style="width:44px;height:44px;object-fit:contain;border-radius:10px;">
                                            </div>
                                            <span class="pm-name">bKash</span>
                                            <span class="pm-check">✔</span>
                                        </div>
                                        <!-- Nagad -->
                                        <div class="payment-method-card" data-method="nagad" onclick="selectPayment(this)">
                                            <div class="pm-logo">
                                                <img src="images/Nagad-png.png" alt="Nagad" style="width:44px;height:44px;object-fit:contain;border-radius:10px;">
                                            </div>
                                            <span class="pm-name">Nagad</span>
                                            <span class="pm-check">✔</span>
                                        </div>
                                        <!-- Rocket -->
                                        <div class="payment-method-card" data-method="rocket" onclick="selectPayment(this)">
                                            <div class="pm-logo">
                                                <img src="images/rocket-color-logo-mobile-banking-icon-free-png.webp" alt="Rocket" style="width:44px;height:44px;object-fit:contain;border-radius:10px;">
                                            </div>
                                            <span class="pm-name">Rocket</span>
                                            <span class="pm-check">✔</span>
                                        </div>
                                    </div>
                                    <input type="hidden" id="selected-payment" name="payment_method" value="">

                                    <!-- bKash details -->
                                    <div class="payment-detail-box payment-detail-bkash" id="detail-bkash" style="display:none;">
                                        <div class="pm-detail-logo">
                                            <img src="images/bkash-logo-free-vector.jpg" alt="bKash" style="width:54px;height:54px;object-fit:contain;border-radius:12px;">
                                        </div>
                                        <div class="pm-detail-info">
                                            <div class="pm-detail-title">Pay via bKash</div>
                                            <div class="pm-detail-number">Send Money to: <strong>01790088564</strong> <button type="button" class="copy-btn" onclick="copyNum(this,'01790088564')" title="Copy number">📋</button></div>
                                            <div class="pm-detail-step">1. Open bKash app → Send Money</div>
                                            <div class="pm-detail-step">2. Enter number: <b>01790088564

                                            </b></div>
                                            <div class="pm-detail-step">3. Enter amount &amp; reference: your phone</div>
                                            <div class="pm-detail-step">4. Enter your TrxID below</div>
                                        </div>
                                    </div>
                                    <!-- Nagad details -->
                                    <div class="payment-detail-box payment-detail-nagad" id="detail-nagad" style="display:none;">
                                        <div class="pm-detail-logo">
                                            <img src="images/Nagad-png.png" alt="Nagad" style="width:54px;height:54px;object-fit:contain;border-radius:12px;">
                                        </div>
                                        <div class="pm-detail-info">
                                            <div class="pm-detail-title">Pay via Nagad</div>
                                            <div class="pm-detail-number">Send Money to: <strong>01790088564</strong> <button type="button" class="copy-btn" onclick="copyNum(this,'01XXXXXXXXX')" title="Copy number">📋</button></div>
                                            <div class="pm-detail-step">1. Open Nagad app → Send Money</div>
                                            <div class="pm-detail-step">2. Enter number: <b>01790088564</b></div>
                                            <div class="pm-detail-step">3. Enter amount &amp; reference: your phone</div>
                                            <div class="pm-detail-step">4. Enter your TrxID below</div>
                                        </div>
                                    </div>
                                    <!-- Rocket details -->
                                    <div class="payment-detail-box payment-detail-rocket" id="detail-rocket" style="display:none;">
                                        <div class="pm-detail-logo">
                                            <img src="images/rocket-color-logo-mobile-banking-icon-free-png.webp" alt="Rocket" style="width:54px;height:54px;object-fit:contain;border-radius:12px;">
                                        </div>
                                        <div class="pm-detail-info">
                                            <div class="pm-detail-title">Pay via Rocket</div>
                                            <div class="pm-detail-number">Send Money to: <strong>01790088564-8</strong> <button type="button" class="copy-btn" onclick="copyNum(this,'01790088564-5')" title="Copy number">📋</button></div>
                                            <div class="pm-detail-step">1. Dial *322# or open Rocket app</div>
                                            <div class="pm-detail-step">2. Send Money → <b>01790088564-8</b></div>
                                            <div class="pm-detail-step">3. Enter amount &amp; reference: your phone</div>
                                            <div class="pm-detail-step">4. Enter your TrxID below</div>
                                        </div>
                                    </div>

                                    <!-- TrxID input (shown after method selected) -->
                                    <div id="trxid-row" class="review-form-row" style="display:none; margin-top:10px;">
                                        <label for="trx-id">Transaction ID (TrxID)</label>
                                        <input id="trx-id" type="text" name="trx_id" placeholder="Enter TrxID after payment">
                                    </div>
                                </div>

                                <button class="product-btn buy-btn details-buy-full" id="place-order-btn" type="submit">Place Order</button>
                                <div style="margin-top:24px;"></div>
                            </form>

                            <!-- Success message -->
                            <div id="order-success-msg" style="display:none; margin-top:20px; background:#f0fff4; border-left:4px solid #16a34a; border-radius:12px; padding:22px 20px; text-align:center;">
                                <div style="font-size:42px; margin-bottom:8px;">✅</div>
                                <div style="font-size:20px; font-weight:900; color:#15803d; margin-bottom:6px;">Order Placed Successfully!</div>
                                <div style="font-size:14px; color:#166534; margin-bottom:4px;">Order Number: <strong id="order-number-display"></strong></div>
                                <div style="font-size:13px; color:#4ade80; margin-bottom:18px;">We will verify your payment and deliver shortly.</div>
                                <div style="display:flex; justify-content:center; gap:10px; flex-wrap:wrap;">
                                    <a href="profile.php" style="display:inline-block; background:#0f172a; color:#fff; padding:10px 24px; border-radius:8px; font-weight:800; text-decoration:none;">View My Orders</a>
                                    <a href="shop.php" style="display:inline-block; background:#e2e8f0; color:#0f172a; padding:10px 24px; border-radius:8px; font-weight:800; text-decoration:none;">Continue Shopping</a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="details-empty">Product not found for checkout.</p>
                <?php endif; ?>
            </div>
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

    <style>
    @keyframes btn-pulse {
        0% { opacity: 1; }
        50% { opacity: .55; }
        100% { opacity: 1; }
    }

    #place-order-btn.loading {
        animation: btn-pulse 1s infinite;
    }

    .review-form-row select,
    .review-form-row textarea {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        font-size: 14px;
    }

    .review-form-row-half {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .trust-pill {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: 11px;
        font-weight: 700;
        color: #334155;
        text-align: center;
        padding: 8px 6px;
    }

    .copy-btn {
        background: none;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        padding: 1px 6px;
        vertical-align: middle;
        transition: background .15s;
    }
    .copy-btn:hover { background: #e2e8f0; }
    .copy-btn.copied { border-color: #16a34a; background: #f0fff4; }
    .payment-section { margin-bottom: 24px; }

    @media (max-width: 640px) {
        .review-form-row-half {
            grid-template-columns: 1fr;
        }
    }
    </style>
    <script src="js/client.js"></script>
    <script>
    var appliedCouponCode = '';
    var checkoutAbandonedSent = false;

    function copyNum(btn, num) {
        navigator.clipboard.writeText(num).then(function() {
            btn.textContent = '✔';
            btn.classList.add('copied');
            setTimeout(function() {
                btn.textContent = '📋';
                btn.classList.remove('copied');
            }, 2000);
        }).catch(function() {
            var el = document.createElement('input');
            el.value = num;
            document.body.appendChild(el);
            el.select();
            document.execCommand('copy');
            document.body.removeChild(el);
            btn.textContent = '✔';
            btn.classList.add('copied');
            setTimeout(function() {
                btn.textContent = '📋';
                btn.classList.remove('copied');
            }, 2000);
        });
    }

    function applyCoupon() {
        var couponInput = document.getElementById('coupon-code');
        var hint = document.getElementById('coupon-hint');
        var code = (couponInput.value || '').trim().toUpperCase();
        var discounts = {
            'FLOWER10': 10,
            'LOVE15': 15,
            'WELCOME5': 5
        };

        if (discounts[code]) {
            appliedCouponCode = code;
            hint.textContent = 'Coupon applied: ' + code + ' (' + discounts[code] + '% OFF)';
            hint.style.color = '#16a34a';
        } else {
            appliedCouponCode = '';
            hint.textContent = 'Invalid coupon. Try FLOWER10, LOVE15, WELCOME5';
            hint.style.color = '#dc2626';
        }
    }

    function selectPayment(card) {
        document.querySelectorAll('.payment-method-card').forEach(function(c) {
            c.classList.remove('pm-selected');
        });
        card.classList.add('pm-selected');
        var method = card.getAttribute('data-method');
        document.getElementById('selected-payment').value = method;
        ['bkash','nagad','rocket'].forEach(function(m) {
            document.getElementById('detail-' + m).style.display = 'none';
        });
        document.getElementById('detail-' + method).style.display = 'flex';
        document.getElementById('trxid-row').style.display = 'block';
    }

    function handleCheckoutSubmit(e) {
        e.preventDefault();
        var method = document.getElementById('selected-payment').value;
        if (!method) { alert('Please select a payment method.'); return false; }
        var trx = document.getElementById('trx-id').value.trim();
        if (!trx) { alert('Please enter your Transaction ID (TrxID) after payment.'); return false; }

        var btn = document.getElementById('place-order-btn');
        btn.disabled = true;
        btn.classList.add('loading');

        var count = 3;
        btn.textContent = '⏳ Processing... (' + count + ')';
        var ticker = setInterval(function() {
            count--;
            if (count > 0) {
                btn.textContent = '⏳ Processing... (' + count + ')';
            } else {
                clearInterval(ticker);
                btn.textContent = '⏳ Completing Order...';
            }
        }, 1000);

        var fd = new FormData();
        fd.append('product_id', '<?php echo (int)($product['id'] ?? 0); ?>');
        fd.append('plan', '<?php echo htmlspecialchars($selectedPlan); ?>');
        fd.append('name', document.getElementById('customer-name').value);
        fd.append('phone', document.getElementById('customer-phone').value);
        fd.append('address', document.getElementById('customer-email').value);
        fd.append('coupon_code', appliedCouponCode);
        fd.append('payment_method', method);
        fd.append('trx_id', trx);

        var startTime = Date.now();

        fetch('checkout.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        })
        .then(function(r){ return r.json(); })
        .then(function(res){
            var elapsed = Date.now() - startTime;
            var remaining = Math.max(0, 3000 - elapsed);
            setTimeout(function() {
                clearInterval(ticker);
                if (res.success) {
                    checkoutAbandonedSent = true;
                    document.getElementById('checkout-form').style.display = 'none';
                    document.getElementById('order-success-msg').style.display = 'block';
                    document.getElementById('order-number-display').textContent = res.order_number;
                } else {
                    alert('Error: ' + (res.message || 'Order failed.'));
                    btn.disabled = false;
                    btn.classList.remove('loading');
                    btn.textContent = 'Place Order';
                }
            }, remaining);
        })
        .catch(function(){
            clearInterval(ticker);
            alert('Network error. Please try again.');
            btn.disabled = false;
            btn.classList.remove('loading');
            btn.textContent = 'Place Order';
        });
        return false;
    }

    function captureAbandonedCheckout() {
        if (checkoutAbandonedSent) {
            return;
        }

        var email = (document.getElementById('customer-email') || {}).value || '';
        var phone = (document.getElementById('customer-phone') || {}).value || '';
        var name = (document.getElementById('customer-name') || {}).value || '';
        if (!email || !phone || !name) {
            return;
        }

        var payload = {
            product_id: '<?php echo (int)($product['id'] ?? 0); ?>',
            plan: '<?php echo htmlspecialchars($selectedPlan); ?>',
            email: email,
            phone: phone,
            name: name,
            coupon_code: appliedCouponCode,
            total_hint: '<?php echo number_format((float)($checkoutPrice ?? 0), 2, '.', ''); ?>'
        };

        if (navigator.sendBeacon) {
            var blob = new Blob([JSON.stringify(payload)], { type: 'application/json' });
            navigator.sendBeacon('abandoned-cart-capture.php', blob);
        } else {
            fetch('abandoned-cart-capture.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
                keepalive: true
            });
        }

        checkoutAbandonedSent = true;
    }

    window.addEventListener('beforeunload', captureAbandonedCheckout);
    </script>
</body>
</html>
