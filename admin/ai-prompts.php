<?php
/**
 * Admin - AI Prompts Management
 */

require_once '../products/config/config.php';
require_once '../products/includes/db.php';
require_once '../products/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

requireAdminLogin();

$message = '';
$error = '';

$db = new Database();
$conn = $db->getConnection();

function normalizeAiPromptImagePath($path) {
    $path = trim((string) $path);
    if ($path === '') {
        return '';
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

function adminPromptImageSrc($storedPath) {
    $normalized = normalizeAiPromptImagePath($storedPath);
    if ($normalized === '') {
        return '../images/ai-prompt.svg';
    }

    if (preg_match('/^https?:\/\//i', $normalized)) {
        return $normalized;
    }

    return '../' . ltrim($normalized, '/');
}

function resolveUploadedImageMimeType($tmpPath, $reportedType, $originalName) {
    $mime = '';

    if ($tmpPath !== '' && function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = @finfo_file($finfo, $tmpPath);
            if (is_string($detected)) {
                $mime = trim($detected);
            }
            finfo_close($finfo);
        }
    }

    if ($mime === '' && $tmpPath !== '' && function_exists('mime_content_type')) {
        $detected = @mime_content_type($tmpPath);
        if (is_string($detected)) {
            $mime = trim($detected);
        }
    }

    if ($mime === '' && $tmpPath !== '' && function_exists('exif_imagetype')) {
        $type = @exif_imagetype($tmpPath);
        if (is_int($type) && function_exists('image_type_to_mime_type')) {
            $detected = @image_type_to_mime_type($type);
            if (is_string($detected)) {
                $mime = trim($detected);
            }
        }
    }

    if ($mime === '' && $tmpPath !== '' && function_exists('getimagesize')) {
        $imgInfo = @getimagesize($tmpPath);
        if (is_array($imgInfo) && !empty($imgInfo['mime']) && is_string($imgInfo['mime'])) {
            $mime = trim($imgInfo['mime']);
        }
    }

    if ($mime === '' && $tmpPath !== '' && is_file($tmpPath) && is_readable($tmpPath)) {
        $fh = @fopen($tmpPath, 'rb');
        if ($fh) {
            $header = (string) @fread($fh, 64);
            $peek = $header . (string) @fread($fh, 4032);
            @fclose($fh);

            $bytes = array_values(unpack('C*', $header));
            if (isset($bytes[0], $bytes[1], $bytes[2]) && $bytes[0] === 0xFF && $bytes[1] === 0xD8 && $bytes[2] === 0xFF) {
                $mime = 'image/jpeg';
            } elseif (strncmp($header, "\x89PNG\r\n\x1A\n", 8) === 0) {
                $mime = 'image/png';
            } elseif (strncmp($header, 'GIF87a', 6) === 0 || strncmp($header, 'GIF89a', 6) === 0) {
                $mime = 'image/gif';
            } elseif (strlen($header) >= 12 && substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WEBP') {
                $mime = 'image/webp';
            } elseif (strlen($header) >= 2 && substr($header, 0, 2) === 'BM') {
                $mime = 'image/bmp';
            } elseif (strlen($header) >= 4 && (substr($header, 0, 4) === "II*\x00" || substr($header, 0, 4) === "MM\x00*")) {
                $mime = 'image/tiff';
            } elseif (strlen($header) >= 12 && substr($header, 4, 4) === 'ftyp' && strpos(substr($header, 8, 16), 'avif') !== false) {
                $mime = 'image/avif';
            } elseif (stripos($peek, '<svg') !== false) {
                $mime = 'image/svg+xml';
            }
        }
    }

    if ($mime === '' && is_string($reportedType) && $reportedType !== '') {
        $mime = trim($reportedType);
    }

    if ($mime === '') {
        $ext = strtolower(pathinfo((string) $originalName, PATHINFO_EXTENSION));
        $map = array(
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'bmp' => 'image/bmp',
            'svg' => 'image/svg+xml',
            'tif' => 'image/tiff',
            'tiff' => 'image/tiff',
            'ico' => 'image/x-icon'
        );
        $mime = $map[$ext] ?? '';
    }

    return strtolower($mime);
}

function uploadErrorMessage($errorCode) {
    $messages = array(
        UPLOAD_ERR_INI_SIZE => 'Uploaded file is too large for server limit (upload_max_filesize).',
        UPLOAD_ERR_FORM_SIZE => 'Uploaded file is too large for form limit.',
        UPLOAD_ERR_PARTIAL => 'File upload was partial. Please try again.',
        UPLOAD_ERR_NO_FILE => 'Prompt photo is required.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server temporary folder is missing.',
        UPLOAD_ERR_CANT_WRITE => 'Server cannot write uploaded file.',
        UPLOAD_ERR_EXTENSION => 'File upload blocked by a server extension.'
    );

    return $messages[$errorCode] ?? 'Image upload failed due to unknown server error.';
}

function iniSizeToBytes($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }

    $unit = strtolower(substr($value, -1));
    $num = (float) $value;

    if ($unit === 'g') {
        $num *= 1024;
    }
    if ($unit === 'm' || $unit === 'g') {
        $num *= 1024;
    }
    if ($unit === 'k' || $unit === 'm' || $unit === 'g') {
        $num *= 1024;
    }

    return (int) $num;
}

$uploadMaxDisplay = (string) ini_get('upload_max_filesize');
$postMaxDisplay = (string) ini_get('post_max_size');
$postMaxBytes = iniSizeToBytes($postMaxDisplay);

$conn->query(
    "CREATE TABLE IF NOT EXISTS ai_prompts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        prompt_text TEXT NOT NULL,
        image_path VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        $deleteId = (int) $_POST['delete_id'];

        if ($deleteId <= 0) {
            $error = 'Invalid prompt id.';
        } else {
            $imagePath = null;
            $stmt = $conn->prepare('SELECT image_path FROM ai_prompts WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $deleteId);
            $stmt->execute();
            $stmt->bind_result($imagePath);
            $stmt->fetch();
            $stmt->close();

            $stmt = $conn->prepare('DELETE FROM ai_prompts WHERE id = ?');
            $stmt->bind_param('i', $deleteId);

            if ($stmt->execute() && $stmt->affected_rows > 0) {
                if (!empty($imagePath)) {
                    $fullImagePath = __DIR__ . '/../' . ltrim($imagePath, '/');
                    if (is_file($fullImagePath)) {
                        unlink($fullImagePath);
                    }
                }
                $message = 'AI Prompt deleted successfully.';
            } else {
                $error = 'Failed to delete AI Prompt.';
            }
            $stmt->close();
        }
    } else {
        $promptText = trim($_POST['prompt_text'] ?? '');
        $imagePath = null;
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        if ($contentLength > 0 && $postMaxBytes > 0 && $contentLength > $postMaxBytes) {
            $error = 'Request size exceeded server post_max_size (' . $postMaxDisplay . '). Increase upload_max_filesize/post_max_size in hosting settings.';
        }

        if ($error === '' && $promptText === '') {
            $error = 'Prompt text is required.';
        } elseif ($error === '') {
            $fileInfo = $_FILES['prompt_image'] ?? null;
            $uploadError = (int) ($fileInfo['error'] ?? UPLOAD_ERR_NO_FILE);

            if (!is_array($fileInfo) || $uploadError !== UPLOAD_ERR_OK) {
                $error = uploadErrorMessage($uploadError);
            } else {
                $projectRoot = dirname(__DIR__);
                $uploadDir = $projectRoot . '/images/ai-prompts/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $originalName = basename((string) ($fileInfo['name'] ?? ''));
                $tmpName = (string) ($fileInfo['tmp_name'] ?? '');
                $reportedType = (string) ($fileInfo['type'] ?? '');
                $mimeType = resolveUploadedImageMimeType($tmpName, $reportedType, $originalName);

                if (strpos($mimeType, 'image/') !== 0) {
                    $error = 'Invalid file type. Please upload an image file.';
                } elseif (!is_writable($uploadDir)) {
                    $error = 'Upload folder is not writable on hosting (images/ai-prompts).';
                } else {
                    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
                    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                    $safeBase = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $baseName);
                    if ($safeBase === '') {
                        $safeBase = 'prompt_image';
                    }
                    if ($extension === '') {
                        $extension = 'jpg';
                    }

                    $fileName = time() . '_' . $safeBase . '.' . $extension;
                    $targetFile = $uploadDir . $fileName;

                    if (move_uploaded_file($tmpName, $targetFile)) {
                        $imagePath = 'images/ai-prompts/' . $fileName;
                    } else {
                        $error = 'Image upload failed. Check folder permission and PHP upload limits.';
                    }
                }
            }

            if ($error === '') {
                $imagePath = normalizeAiPromptImagePath($imagePath);
                $stmt = $conn->prepare('INSERT INTO ai_prompts (prompt_text, image_path) VALUES (?, ?)');
                $stmt->bind_param('ss', $promptText, $imagePath);

                if ($stmt->execute()) {
                    $mailSubject = 'New AI Prompt Added - Accounts Bazar';
                    $previewText = mb_substr($promptText, 0, 140);
                    if (mb_strlen($promptText) > 140) {
                        $previewText .= '...';
                    }
                    $mailMessage = "A new AI Prompt item has been added:\r\n\r\n";
                    $mailMessage .= $previewText;
                    sendAnnouncementToUsers($conn, $mailSubject, $mailMessage);

                    $message = 'AI Prompt saved successfully.';
                } else {
                    $error = 'Failed to save AI Prompt.';
                }
                $stmt->close();
            }
        }
    }
}

$prompts = array();
$result = $conn->query('SELECT id, prompt_text, image_path, created_at FROM ai_prompts ORDER BY id DESC LIMIT 50');
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $prompts[] = $row;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">    <link rel="icon" href="../favicon.svg?v=20260429c" type="image/svg+xml">
    <link rel="shortcut icon" href="../favicon.png?v=20260429c" type="image/png">
    <link rel="apple-touch-icon" href="../images/logo.png">
    <title>AI Prompts - Admin</title>
    <link rel="stylesheet" href="css/admin.css">
    <style>
        .notice {
            margin: 12px 0;
            padding: 12px;
            border-radius: 8px;
            font-weight: 600;
        }
        .notice.success {
            background: #dcfce7;
            color: #166534;
        }
        .notice.error {
            background: #fee2e2;
            color: #991b1b;
        }
        .ai-form {
            background: #fff;
            padding: 18px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 18px;
        }
        .ai-form label {
            display: block;
            margin: 10px 0 6px;
            font-weight: 700;
        }
        .ai-form textarea,
        .ai-form input[type="file"] {
            width: 100%;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 10px;
        }
        .upload-help {
            margin: 6px 0 12px;
            color: #475569;
            font-size: 12px;
            font-weight: 600;
        }
        .prompt-thumb {
            width: 70px;
            height: 50px;
            object-fit: cover;
            border-radius: 6px;
            border: 1px solid #ddd;
        }
        .prompt-text-cell {
            max-width: 420px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .delete-form {
            margin: 0;
        }
        .btn-sm {
            padding: 6px 10px;
            margin: 0;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <button class="admin-menu-toggle" type="button" aria-label="Toggle menu" aria-expanded="false" aria-controls="admin-sidebar">
            <span></span>
            <span></span>
            <span></span>
        </button>
        <div class="admin-overlay"></div>
        <nav class="admin-sidebar" id="admin-sidebar">
            <div class="admin-logo">
                <h2>Admin Panel</h2>
            </div>
            <ul class="menu">
                <li><a href="index.php">Dashboard</a></li>
                <li><a href="products.php">Products</a></li>
                <li><a href="ai-prompts.php" class="active">AI Prompts</a></li>
                <li><a href="user.php">Users</a></li>
                <li><a href="orders.php">Orders</a></li>
                <li><a href="support-messages.php">Messages</a></li>
                <li><a href="reports.php">Reports</a></li>
                <li><a href="settings.php">Settings</a></li>
                <li><a href="logout.php">Logout</a></li>
            </ul>
        </nav>

        <div class="admin-main">
            <header class="admin-header">
                <h1>Manage AI Prompts</h1>
            </header>

            <main class="admin-content">
                <?php if ($message !== ''): ?>
                    <div class="notice success"><?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="notice error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <form class="ai-form" method="POST" enctype="multipart/form-data">
                    <label for="prompt_text">AI Prompt Text</label>
                    <textarea id="prompt_text" name="prompt_text" rows="4" placeholder="Write AI prompt text here..." required></textarea>

                    <label for="prompt_image">Prompt Photo</label>
                    <input id="prompt_image" name="prompt_image" type="file" accept="image/*" required>
                    <p class="upload-help">Server upload limit: <?php echo htmlspecialchars($uploadMaxDisplay); ?> | post limit: <?php echo htmlspecialchars($postMaxDisplay); ?> | Allowed: all image types</p>

                    <button class="btn btn-primary" type="submit">Save AI Prompt</button>
                </form>

                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Photo</th>
                            <th>Prompt</th>
                            <th>Created</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($prompts) > 0): ?>
                            <?php foreach ($prompts as $prompt): ?>
                                <tr>
                                    <td><?php echo (int) $prompt['id']; ?></td>
                                    <td>
                                        <?php if (!empty($prompt['image_path'])): ?>
                                            <img class="prompt-thumb" src="<?php echo htmlspecialchars(adminPromptImageSrc($prompt['image_path'])); ?>" alt="Prompt photo">
                                        <?php else: ?>
                                            <img class="prompt-thumb" src="../images/ai-prompt.svg" alt="Default prompt photo">
                                        <?php endif; ?>
                                    </td>
                                    <td class="prompt-text-cell"><?php echo htmlspecialchars($prompt['prompt_text']); ?></td>
                                    <td><?php echo htmlspecialchars($prompt['created_at']); ?></td>
                                    <td>
                                        <form class="delete-form" method="POST" onsubmit="return confirm('Delete this AI prompt?');">
                                            <input type="hidden" name="delete_id" value="<?php echo (int) $prompt['id']; ?>">
                                            <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align:center;">No AI prompts found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </main>
        </div>
    </div>

    <script src="js/admin.js"></script>
</body>
</html>
<?php
$db->closeConnection();
?>
