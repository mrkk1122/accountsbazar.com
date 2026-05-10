<?php
/**
 * Shared SEO head include for Accounts Bazar
 * Usage: set $seo array before including this file.
 *
 * $seo = [
 *   'title'       => 'Page Title',
 *   'description' => 'Page description (150-160 chars)',
 *   'keywords'    => 'keyword1, keyword2',
 *   'canonical'   => 'https://accountsbazar.com/page.php',
 *   'og_image'    => 'https://accountsbazar.com/images/logo.png',
 *   'noindex'     => false,   // set true for private pages
 *   'extra_json_ld' => [],
 * ];
 */

if (file_exists(__DIR__ . '/../config/webpush.php')) {
    require_once __DIR__ . '/../config/webpush.php';
}


$seo = array_merge([
    'title'       => 'Accounts Bazar – Premium Digital Accounts, AI Tools & Subscriptions in Bangladesh',
    'description' => 'Buy YouTube Premium, CapCut Premium, VPN Premium, Google Veo Premium, ChatGPT Premium and other digital subscriptions in Bangladesh from Accounts Bazar.',
    'keywords'    => 'accounts bazar, youtube premium bangladesh, capcut premium, vpn premium, google veo premium, chatgpt premium, digital subscriptions bd, premium accounts bangladesh',
    'canonical'   => 'https://accountsbazar.com/',
    'og_image'    => 'https://accountsbazar.com/images/logo.png',
    'og_image_alt'=> 'Accounts Bazar logo',
    'og_type'     => 'website',
    'locale'      => 'bn_BD',
    'twitter_site'=> '@AccountsBazar',
    'noindex'     => false,
    'extra_json_ld' => array(),
    'breadcrumb_items' => [
        ['name' => 'Home', 'item' => 'https://accountsbazar.com/']
    ],
], $seo ?? []);

$robotsContent = $seo['noindex'] ? 'noindex, nofollow' : 'index, follow, max-image-preview:large, max-snippet:-1';
$safeTitle       = htmlspecialchars($seo['title'],       ENT_QUOTES, 'UTF-8');
$safeDesc        = htmlspecialchars($seo['description'], ENT_QUOTES, 'UTF-8');
$safeKeywords    = htmlspecialchars($seo['keywords'],    ENT_QUOTES, 'UTF-8');
$safeCanonical   = htmlspecialchars($seo['canonical'],   ENT_QUOTES, 'UTF-8');
$safeOgImage     = htmlspecialchars($seo['og_image'],    ENT_QUOTES, 'UTF-8');
$safeOgImageAlt  = htmlspecialchars($seo['og_image_alt'], ENT_QUOTES, 'UTF-8');
$safeOgType      = htmlspecialchars($seo['og_type'], ENT_QUOTES, 'UTF-8');
$safeLocale      = htmlspecialchars($seo['locale'], ENT_QUOTES, 'UTF-8');
$safeSiteName    = htmlspecialchars('Accounts Bazar', ENT_QUOTES, 'UTF-8');
$safeSiteUrl     = htmlspecialchars('https://accountsbazar.com/', ENT_QUOTES, 'UTF-8');
$safeTwitterSite = htmlspecialchars($seo['twitter_site'], ENT_QUOTES, 'UTF-8');
$breadcrumbItems = is_array($seo['breadcrumb_items'] ?? null) ? $seo['breadcrumb_items'] : array();
if (count($breadcrumbItems) === 0) {
    $breadcrumbItems = array(array('name' => 'Home', 'item' => 'https://accountsbazar.com/'));
}
$breadcrumbList = array();
$position = 1;
foreach ($breadcrumbItems as $b) {
    $name = trim((string) ($b['name'] ?? ''));
    $item = trim((string) ($b['item'] ?? ''));
    if ($name === '' || $item === '') {
        continue;
    }
    $breadcrumbList[] = array(
        '@type' => 'ListItem',
        'position' => $position,
        'name' => $name,
        'item' => $item,
    );
    $position++;
}
if (count($breadcrumbList) === 0) {
    $breadcrumbList[] = array(
        '@type' => 'ListItem',
        'position' => 1,
        'name' => 'Home',
        'item' => 'https://accountsbazar.com/'
    );
}

// Auto-append current page breadcrumb when not explicitly supplied.
if (count($breadcrumbList) === 1 && trim((string) ($seo['canonical'] ?? '')) !== 'https://accountsbazar.com/') {
    $breadcrumbList[] = array(
        '@type' => 'ListItem',
        'position' => 2,
        'name' => trim((string) ($seo['title'] ?? 'Page')),
        'item' => trim((string) ($seo['canonical'] ?? 'https://accountsbazar.com/')),
    );
}
$scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
$assetBase = rtrim($scriptDir, '/');
if ($assetBase === '' || $assetBase === '.') {
    $assetBase = '';
}
$manifestHref = htmlspecialchars($assetBase . '/manifest.json', ENT_QUOTES, 'UTF-8');
$faviconSvgHref = htmlspecialchars($assetBase . '/favicon.svg?v=20260429f', ENT_QUOTES, 'UTF-8');
$faviconPngHref = htmlspecialchars($assetBase . '/favicon.png?v=20260429f', ENT_QUOTES, 'UTF-8');
$faviconIcoHref = htmlspecialchars($assetBase . '/favicon.ico?v=20260429f', ENT_QUOTES, 'UTF-8');
$appleTouchHref = htmlspecialchars($assetBase . '/images/logo.png', ENT_QUOTES, 'UTF-8');
?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?php echo $safeTitle; ?></title>
    <meta name="description" content="<?php echo $safeDesc; ?>">
    <meta name="keywords"    content="<?php echo $safeKeywords; ?>">
    <meta name="robots"      content="<?php echo $robotsContent; ?>">
    <meta name="googlebot"   content="<?php echo $robotsContent; ?>">
    <meta name="author"      content="Accounts Bazar">
    <meta name="format-detection" content="telephone=no">
    <meta name="theme-color" content="#07101f">
    <meta name="application-name" content="Accounts Bazar">
    <meta name="geo.region" content="BD-13">
    <meta name="geo.placename" content="Dhaka, Bangladesh">
    <meta name="geo.position" content="23.8103;90.4125">
    <meta name="ICBM" content="23.8103, 90.4125">
    <meta name="rating" content="general">
    <meta name="revisit-after" content="3 days">
    <meta name="language" content="Bengali">
    <?php if (defined('WEBPUSH_PUBLIC_KEY') && trim((string) WEBPUSH_PUBLIC_KEY) !== ''): ?>
    <meta name="ab-webpush-public-key" content="<?php echo htmlspecialchars((string) WEBPUSH_PUBLIC_KEY, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <link rel="canonical" href="<?php echo $safeCanonical; ?>">
    <link rel="sitemap" type="application/xml" title="Sitemap" href="https://accountsbazar.com/sitemap.xml">
    <link rel="alternate" hreflang="bn-BD" href="<?php echo $safeCanonical; ?>">
    <link rel="alternate" hreflang="x-default" href="<?php echo $safeCanonical; ?>">

    <!-- Open Graph -->
    <meta property="og:type"        content="<?php echo $safeOgType; ?>">
    <meta property="og:site_name"   content="Accounts Bazar">
    <meta property="og:title"       content="<?php echo $safeTitle; ?>">
    <meta property="og:description" content="<?php echo $safeDesc; ?>">
    <meta property="og:url"         content="<?php echo $safeCanonical; ?>">
    <meta property="og:image"       content="<?php echo $safeOgImage; ?>">
    <meta property="og:image:alt"   content="<?php echo $safeOgImageAlt; ?>">
    <meta property="og:image:type"  content="image/png">
    <meta property="og:image:width"  content="512">
    <meta property="og:image:height" content="512">
    <meta property="og:locale"      content="<?php echo $safeLocale; ?>">

    <!-- Twitter Card -->
    <meta name="twitter:card"        content="summary_large_image">
    <meta name="twitter:title"       content="<?php echo $safeTitle; ?>">
    <meta name="twitter:description" content="<?php echo $safeDesc; ?>">
    <meta name="twitter:image"       content="<?php echo $safeOgImage; ?>">
    <meta name="twitter:image:alt"   content="<?php echo $safeOgImageAlt; ?>">
    <meta name="twitter:site"        content="<?php echo $safeTwitterSite; ?>">

        <!-- PWA + Icon -->
        <link rel="manifest" href="<?php echo $manifestHref; ?>">
        <link rel="icon" href="<?php echo $faviconSvgHref; ?>" type="image/svg+xml">
        <link rel="icon" href="<?php echo $faviconPngHref; ?>" type="image/png">
        <link rel="icon" type="image/png" sizes="48x48" href="<?php echo $faviconPngHref; ?>">
        <link rel="shortcut icon" type="image/png" href="<?php echo $faviconPngHref; ?>">
        <link rel="apple-touch-icon" href="<?php echo $appleTouchHref; ?>">

        <!-- Google SEO Structured Data -->
        <script type="application/ld+json">
        {
            "@context": "https://schema.org",
            "@type": ["Organization", "OnlineStore"],
            "@id": "https://accountsbazar.com/#organization",
            "name": "<?php echo $safeSiteName; ?>",
            "url": "<?php echo $safeSiteUrl; ?>",
            "logo": {
                "@type": "ImageObject",
                "url": "https://accountsbazar.com/images/logo.png",
                "width": 512,
                "height": 512
            },
            "description": "Bangladesh-এর জন্য YouTube Premium, CapCut Premium, VPN Premium, Google Veo Premium, ChatGPT Premium এবং অন্যান্য ডিজিটাল সাবস্ক্রিপশনের বিশ্বস্ত মার্কেটপ্লেস।",
            "foundingDate": "2024",
            "areaServed": {
                "@type": "Country",
                "name": "Bangladesh"
            },
            "address": {
                "@type": "PostalAddress",
                "addressLocality": "Dhaka",
                "addressCountry": "BD"
            },
            "contactPoint": {
                "@type": "ContactPoint",
                "contactType": "customer support",
                "availableLanguage": ["Bengali", "English"],
                "url": "https://wa.me/8801790088564"
            },
            "sameAs": [
                "https://www.facebook.com/accountsbazar",
                "https://www.youtube.com/@accountsbazar",
                "https://t.me/accountsbazar",
                "https://wa.me/8801790088564"
            ]
        }
        </script>
        <script type="application/ld+json">
        {
            "@context": "https://schema.org",
            "@type": "WebSite",
            "@id": "https://accountsbazar.com/#website",
            "name": "<?php echo $safeSiteName; ?>",
            "url": "<?php echo $safeSiteUrl; ?>",
            "inLanguage": "bn-BD",
            "publisher": {
                "@id": "https://accountsbazar.com/#organization"
            },
            "potentialAction": {
                "@type": "SearchAction",
                "target": {
                    "@type": "EntryPoint",
                    "urlTemplate": "https://accountsbazar.com/shop.php?q={search_term_string}"
                },
                "query-input": "required name=search_term_string"
            }
        }
        </script>
        <script type="application/ld+json">
        <?php
        $webPageSchema = array(
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $seo['title'],
            'url' => $seo['canonical'],
            'description' => $seo['description'],
            'inLanguage' => 'bn-BD',
            'dateModified' => gmdate('Y-m-d'),
            'isPartOf' => array(
                '@type' => 'WebSite',
                '@id' => 'https://accountsbazar.com/#website',
                'name' => 'Accounts Bazar',
                'url' => 'https://accountsbazar.com/'
            ),
            'breadcrumb' => array(
                '@type' => 'BreadcrumbList',
                'itemListElement' => $breadcrumbList
            )
        );
        echo json_encode($webPageSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        ?>
        </script>
        <?php
        $extraJsonLd = is_array($seo['extra_json_ld'] ?? null) ? $seo['extra_json_ld'] : array();
        foreach ($extraJsonLd as $extraSchema):
            if (!is_array($extraSchema) || empty($extraSchema)) {
                continue;
            }
        ?>
        <script type="application/ld+json"><?php echo json_encode($extraSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
        <?php endforeach; ?>
