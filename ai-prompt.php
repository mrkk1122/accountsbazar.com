<?php
session_start();
require_once 'products/config/config.php';
require_once 'products/includes/db.php';

if (empty($_SESSION['user_id'])) {
    $redirectTarget = urlencode((string) ($_SERVER['REQUEST_URI'] ?? 'ai-prompt.php'));
    header('Location: login.php?redirect=' . $redirectTarget . '&message=' . urlencode('Please login first to open AI Prompt Page.'));
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$conn->query(
    "CREATE TABLE IF NOT EXISTS ai_prompts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        prompt_text TEXT NOT NULL,
        image_path VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS ai_prompt_reactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        prompt_id INT NOT NULL,
        user_id INT NOT NULL,
        reaction ENUM('like', 'unlike') NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_prompt_user (prompt_id, user_id),
        INDEX idx_prompt_reaction (prompt_id, reaction)
    )"
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');

    if (empty($_SESSION['user_id'])) {
        echo json_encode(array('success' => false, 'message' => 'Please login to react.'));
        exit;
    }

    $promptId = (int) ($_POST['prompt_id'] ?? 0);
    $reaction = trim((string) ($_POST['reaction'] ?? ''));
    $userId = (int) $_SESSION['user_id'];

    if ($promptId <= 0 || !in_array($reaction, array('like', 'unlike'), true)) {
        echo json_encode(array('success' => false, 'message' => 'Invalid reaction request.'));
        exit;
    }

    try {
        $checkPrompt = $conn->prepare('SELECT id FROM ai_prompts WHERE id = ? LIMIT 1');
        $checkPrompt->bind_param('i', $promptId);
        $checkPrompt->execute();
        $promptExists = $checkPrompt->get_result()->fetch_assoc();
        $checkPrompt->close();

        if (!$promptExists) {
            echo json_encode(array('success' => false, 'message' => 'Prompt not found.'));
            exit;
        }

        $findStmt = $conn->prepare('SELECT reaction FROM ai_prompt_reactions WHERE prompt_id = ? AND user_id = ? LIMIT 1');
        $findStmt->bind_param('ii', $promptId, $userId);
        $findStmt->execute();
        $existing = $findStmt->get_result()->fetch_assoc();
        $findStmt->close();

        $currentReaction = '';
        if ($existing && ($existing['reaction'] ?? '') === $reaction) {
            $deleteStmt = $conn->prepare('DELETE FROM ai_prompt_reactions WHERE prompt_id = ? AND user_id = ?');
            $deleteStmt->bind_param('ii', $promptId, $userId);
            $deleteStmt->execute();
            $deleteStmt->close();
        } else {
            $upsertStmt = $conn->prepare(
                "INSERT INTO ai_prompt_reactions (prompt_id, user_id, reaction) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE reaction = VALUES(reaction), updated_at = CURRENT_TIMESTAMP"
            );
            $upsertStmt->bind_param('iis', $promptId, $userId, $reaction);
            $upsertStmt->execute();
            $upsertStmt->close();
            $currentReaction = $reaction;
        }

        $countStmt = $conn->prepare(
            "SELECT
                SUM(CASE WHEN reaction = 'like' THEN 1 ELSE 0 END) AS like_count,
                SUM(CASE WHEN reaction = 'unlike' THEN 1 ELSE 0 END) AS unlike_count
             FROM ai_prompt_reactions
             WHERE prompt_id = ?"
        );
        $countStmt->bind_param('i', $promptId);
        $countStmt->execute();
        $counts = $countStmt->get_result()->fetch_assoc();
        $countStmt->close();

        echo json_encode(array(
            'success' => true,
            'currentReaction' => $currentReaction,
            'likeCount' => (int) ($counts['like_count'] ?? 0),
            'unlikeCount' => (int) ($counts['unlike_count'] ?? 0)
        ));
    } catch (Throwable $e) {
        echo json_encode(array('success' => false, 'message' => 'Reaction failed.'));
    }
    exit;
}

$prompts = array();
$currentUserId = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

function promptImageSrc($storedPath) {
    $path = trim((string) $storedPath);
    if ($path === '') {
        return 'images/ai-prompt.svg';
    }

    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }

    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#^\./+#', '', $path);
    $path = preg_replace('#^(\.\./)+#', '', $path);
    $path = ltrim($path, '/');

    if (stripos($path, 'images/ai-prompts/') === false) {
        $path = 'images/ai-prompts/' . basename($path);
    } else {
        $parts = explode('images/ai-prompts/', $path, 2);
        $path = 'images/ai-prompts/' . ($parts[1] ?? '');
    }

    return $path;
}

$reactionSql = "SELECT
    p.id,
    p.prompt_text,
    p.image_path,
    p.created_at,
    COALESCE(SUM(CASE WHEN r.reaction = 'like' THEN 1 ELSE 0 END), 0) AS like_count,
    COALESCE(SUM(CASE WHEN r.reaction = 'unlike' THEN 1 ELSE 0 END), 0) AS unlike_count,
    MAX(CASE WHEN r.user_id = ? THEN r.reaction ELSE '' END) AS user_reaction
FROM ai_prompts p
LEFT JOIN ai_prompt_reactions r ON r.prompt_id = p.id
GROUP BY p.id, p.prompt_text, p.image_path, p.created_at
ORDER BY p.id DESC
LIMIT 20";
$result = null;
$promptStmt = $conn->prepare($reactionSql);
if ($promptStmt) {
    $promptStmt->bind_param('i', $currentUserId);
    $promptStmt->execute();
    $result = $promptStmt->get_result();
}

// Fallback for legacy/strict SQL environments where the aggregate JOIN query may fail.
if (!$result) {
    $fallbackStmt = $conn->prepare(
        'SELECT id, prompt_text, image_path, created_at, 0 AS like_count, 0 AS unlike_count, "" AS user_reaction
         FROM ai_prompts
         ORDER BY id DESC
         LIMIT 20'
    );
    if ($fallbackStmt) {
        $fallbackStmt->execute();
        $result = $fallbackStmt->get_result();
    }
}
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $prompts[] = $row;
    }
}

if (isset($promptStmt)) {
    $promptStmt->close();
}
if (isset($fallbackStmt) && $fallbackStmt) {
    $fallbackStmt->close();
}

$db->closeConnection();
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<?php
$seo = [
    'title'       => 'AI Prompt কালেকশন – ChatGPT, Midjourney, DALL·E | Accounts Bazar',
    'description' => 'Accounts Bazar-এর কিউরেটেড AI প্রম্পট কালেকশন দেখুন। ChatGPT, Midjourney, DALL·E, Stable Diffusion-এর জন্য সেরা বাংলা ও ইংরেজি AI প্রম্পট আইডিয়া এবং ছবি তৈরির প্রম্পট।',
    'keywords'    => 'ai prompt bangladesh, chatgpt prompt bangla, midjourney prompts, dalle prompts, stable diffusion prompts, image generation prompts, ai prompt ideas, accounts bazar ai, best ai prompts 2025',
    'canonical'   => 'https://accountsbazar.com/ai-prompt.php',
    'og_image'    => 'https://accountsbazar.com/images/logo.png',
    'og_type'     => 'website',
];
require_once 'products/includes/seo.php';
?>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/mobile.css">
    <style>
        .prompt-wrap {
            min-height: calc(100vh - 140px);
            padding: 48px 16px;
            background: radial-gradient(circle at top, #e0f2fe, #f8fafc 45%);
        }
        .prompt-card {
            max-width: 980px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 14px 34px rgba(15, 23, 42, 0.12);
            padding: 24px;
        }
        .prompt-title {
            font-size: 30px;
            margin-bottom: 12px;
            color: #0f172a;
        }
        .prompt-subtitle {
            color: #475569;
            margin-bottom: 20px;
        }
        .prompt-grid {
            margin-top: 18px;
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
        }
        .prompt-item {
            border: 1px solid #dbe2ea;
            border-radius: 10px;
            background: #ffffff;
            color: #1e293b;
            padding: 12px;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
            overflow: hidden;
            min-width: 0;
            height: 340px;
            display: flex;
            flex-direction: column;
        }
        .newest-badge {
            margin-bottom: 8px;
            font-size: 11px;
            font-weight: 700;
            color: #047857;
            background: #d1fae5;
            border: 1px solid #6ee7b7;
            border-radius: 999px;
            padding: 4px 8px;
            display: inline-block;
        }
        .prompt-image {
            width: 100%;
            height: 110px;
            object-fit: cover;
            border-radius: 8px;
            display: block;
            cursor: zoom-in;
            flex-shrink: 0;
        }
        .prompt-actions {
            margin-top: 10px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            min-width: 0;
            flex-shrink: 0;
        }
        .action-btn {
            border: none;
            border-radius: 8px;
            padding: 7px 8px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-width: 0;
            max-width: 100%;
            overflow: hidden;
        }
        .like-btn {
            background: #e0f2fe;
            color: #0c4a6e;
        }
        .unlike-btn {
            background: #fee2e2;
            color: #7f1d1d;
        }
        .action-btn.active {
            outline: 2px solid #2563eb;
        }
        .reaction-emoji {
            font-size: 15px;
            line-height: 1;
        }
        .reaction-count {
            min-width: 18px;
            text-align: center;
            font-size: 11px;
            font-weight: 900;
            flex-shrink: 0;
        }
        .prompt-login-note {
            margin: 10px 0 0;
            color: #64748b;
            font-size: 12px;
            font-weight: 600;
            flex-shrink: 0;
        }
        .prompt-text {
            margin-top: 10px;
            border: 1px dashed #cbd5e1;
            border-radius: 8px;
            padding: 8px;
            font-size: 12px;
            line-height: 1.5;
            color: #334155;
            min-height: 0;
            height: 100%;
            overflow-wrap: anywhere;
            word-break: break-word;
            overflow-y: auto;
            flex: 1 1 auto;
        }
        .copy-btn {
            margin-top: 8px;
            width: 100%;
            border: none;
            border-radius: 8px;
            background: #2563eb;
            color: #ffffff;
            font-weight: 700;
            padding: 8px;
            font-size: 12px;
            cursor: pointer;
            max-width: 100%;
            flex-shrink: 0;
        }
        .empty-note {
            margin-top: 16px;
            padding: 14px;
            border-radius: 10px;
            background: #eff6ff;
            color: #1e40af;
            border: 1px solid #bfdbfe;
            font-weight: 600;
        }
        .back-link {
            display: inline-block;
            margin-top: 18px;
            color: #2563eb;
            text-decoration: none;
            font-weight: 600;
        }
        .image-lightbox {
            position: fixed;
            inset: 0;
            background: rgba(2, 6, 23, 0.9);
            z-index: 1200;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .image-lightbox.open {
            display: flex;
        }
        .lightbox-image {
            max-width: min(96vw, 1200px);
            max-height: 90vh;
            border-radius: 12px;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.45);
        }
        .lightbox-close {
            position: absolute;
            top: 14px;
            right: 14px;
            width: 40px;
            height: 40px;
            border: none;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.18);
            color: #ffffff;
            font-size: 24px;
            line-height: 1;
            cursor: pointer;
        }
        @media (max-width: 768px) {
            .prompt-wrap {
                padding: 24px 0;
            }
            .prompt-card {
                border-radius: 0;
                padding: 16px 0;
            }
            .prompt-title,
            .prompt-subtitle,
            .back-link {
                margin-left: 12px;
                margin-right: 12px;
            }
            .prompt-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                column-gap: 6px;
                row-gap: 6px;
            }
            .prompt-item {
                border-radius: 6px;
                box-shadow: none;
                height: 320px;
            }
        }
    </style>
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

    <main class="prompt-wrap">
        <section class="prompt-card">
            <h1 class="prompt-title">AI Prompt Page</h1>
            <p class="prompt-subtitle">Admin panel থেকে upload করা prompt ও photo এখানে newest first দেখাবে।</p>

            <?php if (count($prompts) > 0): ?>
                <div class="prompt-grid">
                    <?php foreach ($prompts as $index => $prompt): ?>
                        <?php
                        $promptId = 'prompt-' . (int) $prompt['id'];
                        $imagePath = promptImageSrc($prompt['image_path'] ?? '');
                        $reaction = (string) ($prompt['user_reaction'] ?? '');
                        ?>
                        <div class="prompt-item" data-prompt-id="<?php echo (int) $prompt['id']; ?>">
                            <?php if ($index === 0): ?>
                                <span class="newest-badge">Newest</span>
                            <?php endif; ?>
                            <img class="prompt-image" src="<?php echo htmlspecialchars($imagePath); ?>" alt="AI Prompt" data-fullsrc="<?php echo htmlspecialchars($imagePath); ?>">
                            <div class="prompt-actions">
                                <button class="action-btn like-btn<?php echo $reaction === 'like' ? ' active' : ''; ?>" type="button" data-reaction="like">
                                    <span class="reaction-emoji">👍</span>
                                    <span>Like</span>
                                    <span class="reaction-count" data-like-count><?php echo (int) ($prompt['like_count'] ?? 0); ?></span>
                                </button>
                                <button class="action-btn unlike-btn<?php echo $reaction === 'unlike' ? ' active' : ''; ?>" type="button" data-reaction="unlike">
                                    <span class="reaction-emoji">👎</span>
                                    <span>Unlike</span>
                                    <span class="reaction-count" data-unlike-count><?php echo (int) ($prompt['unlike_count'] ?? 0); ?></span>
                                </button>
                            </div>
                            <?php if (empty($_SESSION['user_id'])): ?>
                                <p class="prompt-login-note">Login required to react on prompts.</p>
                            <?php endif; ?>
                            <p class="prompt-text" id="<?php echo htmlspecialchars($promptId); ?>"><?php echo htmlspecialchars($prompt['prompt_text']); ?></p>
                            <button class="copy-btn" type="button" data-copy-target="<?php echo htmlspecialchars($promptId); ?>">Copy AI Prompt</button>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-note">No AI prompts yet. Admin panel থেকে prompt add করলে এখানে প্রথম card-এ newest item দেখাবে।</div>
            <?php endif; ?>

            <a class="back-link" href="index.php">Back to Home</a>
        </section>
    </main>

    <nav class="mobile-bottom-nav" aria-label="Mobile Bottom Navigation">
        <a href="index.php"><span class="nav-icon">🏠</span><span class="nav-label">Home</span></a>
        <a href="shop.php"><span class="nav-icon">🛍️</span><span class="nav-label">Shop</span></a>
        <a class="ai-prompt-link active" href="ai-prompt.php"><span class="nav-icon">🤖</span><span class="nav-label">AI Prompt</span></a>
        <a href="#" data-notification-toggle><span class="nav-icon">🔔</span><span class="nav-label">Notification</span><span class="notif-badge" data-notif-badge style="display:none;">0</span></a>
        <a href="login.php"><span class="nav-icon">👤</span><span class="nav-label">Login</span></a>
    </nav>

    <div class="image-lightbox" id="image-lightbox" aria-hidden="true">
        <button class="lightbox-close" id="lightbox-close" type="button" aria-label="Close">×</button>
        <img class="lightbox-image" id="lightbox-image" src="" alt="Preview">
    </div>

    <script src="js/client.js"></script>
    <script>
        var lightbox = document.getElementById('image-lightbox');
        var lightboxImage = document.getElementById('lightbox-image');
        var lightboxClose = document.getElementById('lightbox-close');

        document.querySelectorAll('.prompt-image').forEach(function(image) {
            image.addEventListener('click', function() {
                var fullSrc = image.getAttribute('data-fullsrc') || image.getAttribute('src');
                lightboxImage.setAttribute('src', fullSrc);
                lightbox.classList.add('open');
                lightbox.setAttribute('aria-hidden', 'false');
            });
        });

        function closeLightbox() {
            lightbox.classList.remove('open');
            lightbox.setAttribute('aria-hidden', 'true');
            lightboxImage.setAttribute('src', '');
        }

        lightboxClose.addEventListener('click', closeLightbox);

        lightbox.addEventListener('click', function(event) {
            if (event.target === lightbox) {
                closeLightbox();
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && lightbox.classList.contains('open')) {
                closeLightbox();
            }
        });

        document.querySelectorAll('.copy-btn').forEach(function(button) {
            button.addEventListener('click', function() {
                var targetId = button.getAttribute('data-copy-target');
                var promptText = document.getElementById(targetId);
                if (!promptText) {
                    return;
                }

                navigator.clipboard.writeText(promptText.textContent.trim()).then(function() {
                    var original = button.textContent;
                    button.textContent = 'Copied';
                    setTimeout(function() {
                        button.textContent = original;
                    }, 1200);
                });
            });
        });

        document.querySelectorAll('.prompt-actions').forEach(function(actionGroup) {
            var promptItem = actionGroup.closest('.prompt-item');
            var likeButton = actionGroup.querySelector('.like-btn');
            var unlikeButton = actionGroup.querySelector('.unlike-btn');

            [likeButton, unlikeButton].forEach(function(button) {
                button.addEventListener('click', function() {
                    var promptId = promptItem ? promptItem.getAttribute('data-prompt-id') : '';
                    var reaction = button.getAttribute('data-reaction');
                    if (!promptId || !reaction) {
                        return;
                    }

                    var fd = new FormData();
                    fd.append('prompt_id', promptId);
                    fd.append('reaction', reaction);

                    fetch('ai-prompt.php', {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: fd
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (!res.success) {
                            alert(res.message || 'Please login first to react.');
                            return;
                        }

                        likeButton.classList.toggle('active', res.currentReaction === 'like');
                        unlikeButton.classList.toggle('active', res.currentReaction === 'unlike');

                        var likeCount = promptItem.querySelector('[data-like-count]');
                        var unlikeCount = promptItem.querySelector('[data-unlike-count]');
                        if (likeCount) {
                            likeCount.textContent = String(res.likeCount || 0);
                        }
                        if (unlikeCount) {
                            unlikeCount.textContent = String(res.unlikeCount || 0);
                        }
                    })
                    .catch(function() {
                        alert('Reaction failed. Please try again.');
                    });
                });
            });
        });
    </script>
</body>
</html>
