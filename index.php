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

    $query = 'SELECT id, name, description, price, image, created_at FROM products WHERE quantity >= 0 ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?';
    $stmt = $conn->prepare($query);
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
    'title'       => 'Accounts Bazar – YouTube Premium, CapCut Premium, VPN Premium, ChatGPT Premium in Bangladesh',
    'description' => 'Accounts Bazar-এ YouTube Premium, CapCut Premium, VPN Premium, Google Veo Premium, ChatGPT Premium এবং অন্যান্য ডিজিটাল সাবস্ক্রিপশন কিনুন। বাংলাদেশে বিকাশ/নগদ পেমেন্টে দ্রুত ডেলিভারি।',
    'keywords'    => 'youtube premium bangladesh, capcut premium bd, vpn premium account, google veo premium, chatgpt premium bangladesh, premium subscriptions bd, accounts bazar',
    'canonical'   => 'https://accountsbazar.com/',
    'og_image'    => 'https://accountsbazar.com/images/logo.png',
    'og_type'     => 'website',
    'extra_json_ld' => [
        [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => [
                [
                    '@type' => 'Question',
                    'name' => 'Which premium subscriptions can I buy from Accounts Bazar?',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => 'You can buy YouTube Premium, CapCut Premium, VPN Premium, Google Veo Premium, ChatGPT Premium, and other digital accounts depending on current stock.'
                    ]
                ],
                [
                    '@type' => 'Question',
                    'name' => 'How do customers in Bangladesh pay for premium accounts?',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => 'Accounts Bazar accepts bKash, Nagad, and Rocket for premium subscription orders in Bangladesh.'
                    ]
                ]
            ]
        ]
    ],
];
require_once 'products/includes/seo.php';
require_once 'products/config/webpush.php';
$webPushPublicKey = defined('WEBPUSH_PUBLIC_KEY') ? (string) WEBPUSH_PUBLIC_KEY : '';
?>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/mobile.css">
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "WebSite",
        "name": "Accounts Bazar",
        "url": "https://accountsbazar.com/",
        "potentialAction": {
            "@type": "SearchAction",
            "target": "https://accountsbazar.com/shop.php?q={search_term_string}",
            "query-input": "required name=search_term_string"
        }
    }
    </script>
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "Organization",
        "name": "Accounts Bazar",
        "url": "https://accountsbazar.com/",
        "logo": "https://accountsbazar.com/images/logo.png",
        "contactPoint": {
            "@type": "ContactPoint",
            "email": "order@accountsbazar.com",
            "contactType": "customer support"
        }
    }
    </script>
</head>
<body>

    <!-- ===== WELCOME SPLASH SCREEN ===== -->
    <div id="welcome-splash">
        <div class="splash-bg-grid"></div>
        <div class="splash-ring splash-ring-1"></div>
        <div class="splash-ring splash-ring-2"></div>
        <div class="splash-ring splash-ring-3"></div>
        <div class="splash-orb splash-orb-1"></div>
        <div class="splash-orb splash-orb-2"></div>
        <div class="splash-content">
            <div class="splash-ai-icon">
                <svg viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <circle cx="40" cy="40" r="38" stroke="url(#sg)" stroke-width="2.5"/>
                    <circle cx="40" cy="40" r="28" fill="url(#sg2)" opacity="0.18"/>
                    <path d="M40 18 L46 32 L62 32 L49 42 L54 56 L40 47 L26 56 L31 42 L18 32 L34 32 Z" fill="url(#sg3)" opacity="0.9"/>
                    <circle cx="40" cy="40" r="6" fill="#fff" opacity="0.95"/>
                    <defs>
                        <linearGradient id="sg" x1="0" y1="0" x2="80" y2="80" gradientUnits="userSpaceOnUse">
                            <stop stop-color="#38bdf8"/>
                            <stop offset="1" stop-color="#818cf8"/>
                        </linearGradient>
                        <linearGradient id="sg2" x1="0" y1="0" x2="80" y2="80" gradientUnits="userSpaceOnUse">
                            <stop stop-color="#38bdf8"/>
                            <stop offset="1" stop-color="#818cf8"/>
                        </linearGradient>
                        <linearGradient id="sg3" x1="18" y1="18" x2="62" y2="62" gradientUnits="userSpaceOnUse">
                            <stop stop-color="#f0f9ff"/>
                            <stop offset="1" stop-color="#c7d2fe"/>
                        </linearGradient>
                    </defs>
                </svg>
            </div>
            <h1 class="splash-title">Welcome to</h1>
            <h2 class="splash-brand">Accounts Bazar</h2>
            <p class="splash-sub">Premium Digital Accounts &amp; AI Prompts</p>
            <div class="splash-bar-wrap"><div class="splash-bar"></div></div>
            <button id="splash-btn" class="splash-btn" type="button" onclick="closeSplash()">
                <span class="splash-btn-text">Shop Now</span>
                <span class="splash-btn-arrow">→</span>
            </button>
        </div>
    </div>

    <style>
    #welcome-splash {
        position: fixed;
        inset: 0;
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #0a0e1a 0%, #0f172a 45%, #0d1b3e 100%);
        overflow: hidden;
        transition: opacity 0.55s ease, visibility 0.55s ease;
    }
    #welcome-splash.splash-hidden {
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
    }

    /* animated grid */
    .splash-bg-grid {
        position: absolute;
        inset: 0;
        background-image:
            linear-gradient(rgba(56,189,248,0.07) 1px, transparent 1px),
            linear-gradient(90deg, rgba(56,189,248,0.07) 1px, transparent 1px);
        background-size: 38px 38px;
        animation: gridMove 8s linear infinite;
    }
    @keyframes gridMove {
        0%   { background-position: 0 0, 0 0; }
        100% { background-position: 38px 38px, 38px 38px; }
    }

    /* glowing rings */
    .splash-ring {
        position: absolute;
        border-radius: 50%;
        border: 1.5px solid;
        animation: ringPulse 3s ease-in-out infinite;
    }
    .splash-ring-1 {
        width: 340px; height: 340px;
        border-color: rgba(56,189,248,0.18);
        animation-delay: 0s;
    }
    .splash-ring-2 {
        width: 500px; height: 500px;
        border-color: rgba(129,140,248,0.13);
        animation-delay: 0.6s;
    }
    .splash-ring-3 {
        width: 660px; height: 660px;
        border-color: rgba(56,189,248,0.07);
        animation-delay: 1.2s;
    }
    @keyframes ringPulse {
        0%,100% { transform: scale(1); opacity: 1; }
        50%      { transform: scale(1.06); opacity: 0.5; }
    }

    /* floating orbs */
    .splash-orb {
        position: absolute;
        border-radius: 50%;
        filter: blur(60px);
        animation: orbFloat 5s ease-in-out infinite alternate;
    }
    .splash-orb-1 {
        width: 280px; height: 280px;
        top: -60px; left: -60px;
        background: radial-gradient(circle, rgba(56,189,248,0.22), transparent 70%);
        animation-delay: 0s;
    }
    .splash-orb-2 {
        width: 240px; height: 240px;
        bottom: -40px; right: -40px;
        background: radial-gradient(circle, rgba(129,140,248,0.22), transparent 70%);
        animation-delay: 1.5s;
    }
    @keyframes orbFloat {
        0%   { transform: translateY(0) translateX(0); }
        100% { transform: translateY(30px) translateX(20px); }
    }

    /* content */
    .splash-content {
        position: relative;
        z-index: 2;
        text-align: center;
        padding: 24px 16px;
        animation: splashFadeIn 0.6s ease forwards;
    }
    @keyframes splashFadeIn {
        from { opacity: 0; transform: translateY(24px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    /* AI icon */
    .splash-ai-icon {
        width: 88px;
        height: 88px;
        margin: 0 auto 18px;
        animation: iconSpin 6s linear infinite;
        filter: drop-shadow(0 0 18px rgba(56,189,248,0.55));
    }
    @keyframes iconSpin {
        0%   { transform: rotate(0deg) scale(1); }
        50%  { transform: rotate(180deg) scale(1.08); }
        100% { transform: rotate(360deg) scale(1); }
    }

    .splash-title {
        font-size: 16px;
        font-weight: 500;
        color: rgba(148,163,184,0.85);
        letter-spacing: 3px;
        text-transform: uppercase;
        margin: 0 0 6px;
    }
    .splash-brand {
        font-size: clamp(28px, 7vw, 46px);
        font-weight: 900;
        background: linear-gradient(90deg, #38bdf8, #818cf8, #38bdf8);
        background-size: 200% auto;
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        animation: shimmer 2.5s linear infinite;
        margin: 0 0 8px;
        line-height: 1.1;
    }
    @keyframes shimmer {
        0%   { background-position: 0% center; }
        100% { background-position: 200% center; }
    }
    .splash-sub {
        font-size: 13px;
        color: rgba(148,163,184,0.7);
        margin: 0 0 22px;
        letter-spacing: 0.5px;
    }

    /* progress bar */
    .splash-bar-wrap {
        width: 180px;
        height: 3px;
        background: rgba(56,189,248,0.15);
        border-radius: 99px;
        margin: 0 auto 22px;
        overflow: hidden;
    }
    .splash-bar {
        height: 100%;
        width: 0;
        background: linear-gradient(90deg, #38bdf8, #818cf8);
        border-radius: 99px;
        animation: barFill 3s linear forwards;
    }
    @keyframes barFill {
        from { width: 0%; }
        to   { width: 100%; }
    }

    /* CTA button */
    .splash-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 13px 28px;
        border: none;
        border-radius: 999px;
        background: linear-gradient(135deg, #38bdf8, #818cf8);
        color: #fff;
        font-size: 15px;
        font-weight: 800;
        cursor: pointer;
        box-shadow: 0 6px 28px rgba(56,189,248,0.35), 0 0 0 0 rgba(56,189,248,0.5);
        animation: btnGlow 2s ease-in-out infinite;
        letter-spacing: 0.3px;
        transition: transform 0.18s;
    }
    .splash-btn:hover { transform: scale(1.06); }
    .splash-btn:active { transform: scale(0.97); }
    .splash-btn-arrow {
        font-size: 18px;
        transition: transform 0.2s;
    }
    .splash-btn:hover .splash-btn-arrow { transform: translateX(4px); }
    @keyframes btnGlow {
        0%,100% { box-shadow: 0 6px 28px rgba(56,189,248,0.35), 0 0 0 0 rgba(56,189,248,0.45); }
        50%      { box-shadow: 0 6px 36px rgba(56,189,248,0.55), 0 0 0 8px rgba(56,189,248,0); }
    }

    @media (max-width: 480px) {
        .splash-ring-3 { display: none; }
    }
    </style>

    <script>
    (function () {
        var splash = document.getElementById('welcome-splash');
        if (!splash) return;

        var seen = sessionStorage.getItem('ab_splash_seen');
        if (seen) {
            splash.style.display = 'none';
            return;
        }

        function closeSplash() {
            sessionStorage.setItem('ab_splash_seen', '1');
            splash.classList.add('splash-hidden');
            setTimeout(function () { splash.style.display = 'none'; }, 580);
        }

        window.closeSplash = closeSplash;

        setTimeout(closeSplash, 3000);
    })();
    </script>

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

    <section class="hero home-hero" data-hero-slider>
        <div class="hero-slide active" style="background-image:url('images/hero-1.png');" aria-hidden="false"></div>
        <div class="hero-slide" style="background-image:url('images/hero-2.jpeg');" aria-hidden="true"></div>
        <div class="hero-slide" style="background-image:url('images/hero-3.png');" aria-hidden="true"></div>
        <div class="hero-slide" style="background-image:url('images/hero-4.png');" aria-hidden="true"></div>
        <div class="hero-overlay"></div>
        <div class="container hero-content">
            <div class="hero-captions" data-hero-captions>
                <div class="hero-caption active">
                    <h1 class="hero-title" style="--type-width: 16ch;">Our Shop</h1>
                    <p>Browse our collection of quality products</p>
                </div>
                <div class="hero-caption">
                    <h1 class="hero-title" style="--type-width: 15ch;">Premium Deals</h1>
                    <p>Grab trusted accounts with instant delivery support</p>
                </div>
                <div class="hero-caption">
                    <h1 class="hero-title" style="--type-width: 13ch;">Top Picks</h1>
                    <p>Best selling subscriptions picked for your daily needs</p>
                </div>
                <div class="hero-caption">
                    <h1 class="hero-title" style="--type-width: 15ch;">Latest Items</h1>
                    <p>Newly added products updated regularly every week</p>
                </div>
            </div>
            <div class="hero-dots" data-hero-dots aria-label="Hero Slides">
                <button type="button" class="hero-dot active" aria-label="Slide 1"></button>
                <button type="button" class="hero-dot" aria-label="Slide 2"></button>
                <button type="button" class="hero-dot" aria-label="Slide 3"></button>
                <button type="button" class="hero-dot" aria-label="Slide 4"></button>
            </div>
        </div>
    </section>

    <section class="hero-links-section">
        <div class="container">
            <div class="hero-links-row">
                <a class="hero-link-card hero-link-ai" href="ai-prompt.php">
                    <span class="hero-link-ai-glow" aria-hidden="true"></span>
                    <span class="hero-link-ai-scan" aria-hidden="true"></span>
                    <span class="hero-link-ai-noise" aria-hidden="true"></span>
                    <span class="hero-link-title">AI Prompt Page</span>
                    <span class="hero-link-sub">Explore latest AI prompt ideas</span>
                    <span class="hero-link-ai-status"><span class="status-dot"></span>Creating original photo...</span>
                </a>
                <a class="hero-link-card hero-link-offer" href="offer-products.php">
                    <span class="hero-link-title">Offer Products Page</span>
                    <span class="hero-link-sub">See discounted items and hot deals</span>
                </a>
            </div>
        </div>
    </section>

    <section class="products-section home-products-section">
        <div class="container">
            <h2 class="section-title">Featured Products</h2>
            <div class="products-grid">
                <?php if (count($products) > 0): ?>
                    <?php foreach ($products as $index => $product): ?>
                        <div
                            class="product-card product-card-clickable"
                            role="link"
                            tabindex="0"
                            onclick="window.location.href='product-details.php?id=<?php echo (int) $product['id']; ?>'"
                            onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); window.location.href='product-details.php?id=<?php echo (int) $product['id']; ?>'; }"
                        >
                            <?php if ($page === 1 && $index === 0): ?>
                                <div class="product-desc" style="font-weight:700;color:#2ecc71;padding:8px 12px;">Newest Product</div>
                            <?php endif; ?>
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
                                <div class="product-short-desc"><?php echo htmlspecialchars(substr((string) $product['description'], 0, 60)); ?></div>
                                <div class="product-actions">
                                    <button class="product-btn buy-btn" type="button" onclick="event.stopPropagation(); window.location.href='checkout.php?id=<?php echo (int) $product['id']; ?>'">Buy</button>
                                    <button class="product-btn cart-btn" type="button" onclick="event.stopPropagation(); addToCart(<?php echo (int) $product['id']; ?>)">Add Cart</button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>No products available right now.</p>
                <?php endif; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="pagination-wrap" aria-label="Home Products Pagination">
                    <?php if ($page > 1): ?>
                        <a class="pagination-link" href="index.php?page=<?php echo $page - 1; ?>">Previous</a>
                    <?php endif; ?>

                    <span class="pagination-status">Page <?php echo $page; ?> / <?php echo $totalPages; ?></span>

                    <?php if ($page < $totalPages): ?>
                        <a class="pagination-link" href="index.php?page=<?php echo $page + 1; ?>">Next</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php require_once 'products/includes/site-footer.php'; ?>

    <nav class="mobile-bottom-nav" aria-label="Mobile Bottom Navigation">
        <a class="active" href="index.php"><span class="nav-icon">🏠</span><span class="nav-label">Home</span></a>
        <a href="shop.php"><span class="nav-icon">🛍️</span><span class="nav-label">Shop</span></a>
        <a class="ai-prompt-link" href="ai-prompt.php"><span class="nav-icon">🤖</span><span class="nav-label">AI Prompt</span></a>
        <a href="#" data-notification-toggle><span class="nav-icon">🔔</span><span class="nav-label">Notification</span><span class="notif-badge" data-notif-badge style="display:none;">0</span></a>
        <?php if (!empty($_SESSION['user_id'])): ?>
        <a href="profile.php"><span class="nav-icon">👤</span><span class="nav-label">Profile</span></a>
        <?php else: ?>
        <a href="login.php"><span class="nav-icon">👤</span><span class="nav-label">Login</span></a>
        <?php endif; ?>
    </nav>

    <!-- PWA Install Floating Button -->
    <div id="pwa-install-bar" style="display:none;">
        <button id="pwa-install-btn" class="pwa-fab-btn" title="App Install করুন">
            <img src="favicon.png" alt="install" class="pwa-fab-icon">
            <span class="pwa-fab-label">Install App</span>
        </button>
        <button id="pwa-install-close" class="pwa-fab-close" aria-label="Close">✕</button>
    </div>

    <script>
    window.AB_WEBPUSH_PUBLIC_KEY = <?php echo json_encode($webPushPublicKey, JSON_UNESCAPED_SLASHES); ?>;
    </script>
    <script src="js/client.js"></script>
    <script>
    (function () {
        var hero = document.querySelector('[data-hero-slider]');
        if (!hero) {
            return;
        }

        var slides = hero.querySelectorAll('.hero-slide');
        var dots = hero.querySelectorAll('.hero-dot');
        var captions = hero.querySelectorAll('.hero-caption');
        if (slides.length < 2) {
            return;
        }

        var current = 0;
        function showSlide(index) {
            slides.forEach(function (slide, i) {
                var isActive = i === index;
                slide.classList.toggle('active', isActive);
                slide.setAttribute('aria-hidden', isActive ? 'false' : 'true');
            });

            dots.forEach(function (dot, i) {
                dot.classList.toggle('active', i === index);
            });

            captions.forEach(function (caption, i) {
                caption.classList.toggle('active', i === index);
            });
        }

        dots.forEach(function (dot, index) {
            dot.addEventListener('click', function () {
                current = index;
                showSlide(current);
            });
        });

        setInterval(function () {
            current = (current + 1) % slides.length;
            showSlide(current);
        }, 3500);
    })();
    </script>

    <script>
    // ===== PWA Install Prompt =====
    (function () {
        var deferredPrompt = null;
        var bar = document.getElementById('pwa-install-bar');
        var installBtn = document.getElementById('pwa-install-btn');
        var closeBtn = document.getElementById('pwa-install-close');
        var dismissed = false;

        try {
            dismissed = sessionStorage.getItem('pwa_dismissed') === '1';
        } catch (e) {
            dismissed = false;
        }

        // Show bar when browser fires beforeinstallprompt
        window.addEventListener('beforeinstallprompt', function (e) {
            e.preventDefault();
            deferredPrompt = e;
            if (!dismissed) {
                bar.style.display = 'flex';
            }
        });

        // Install button click
        installBtn.addEventListener('click', function () {
            if (!deferredPrompt) return;
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function (choice) {
                deferredPrompt = null;
                bar.style.display = 'none';
            });
        });

        // Dismiss button
        closeBtn.addEventListener('click', function () {
            bar.style.display = 'none';
            // Don't show again for this session
            try {
                sessionStorage.setItem('pwa_dismissed', '1');
                dismissed = true;
            } catch(e) {}
        });

        // Hide if already installed
        window.addEventListener('appinstalled', function () {
            bar.style.display = 'none';
            deferredPrompt = null;
        });

        // Register Service Worker
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw.js').catch(function () {});
        }
    })();
    </script>
</body>
</html>
