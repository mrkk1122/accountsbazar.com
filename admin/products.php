<?php
/**
 * Admin - Products Page
 */

require_once '../products/config/config.php';
require_once '../products/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

requireAdminLogin();

$db = new Database();
$conn = $db->getConnection();

$message = '';
$error = '';
$editingProduct = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0) {
            $error = 'Invalid product id.';
        } else {
            $imagePath = '';
            $imgStmt = $conn->prepare('SELECT image FROM products WHERE id = ? LIMIT 1');
            $imgStmt->bind_param('i', $id);
            $imgStmt->execute();
            $imgStmt->bind_result($imagePath);
            $imgStmt->fetch();
            $imgStmt->close();

            $conn->begin_transaction();

            try {
                $orderIds = array();
                $orderIdStmt = $conn->prepare('SELECT DISTINCT order_id FROM order_items WHERE product_id = ?');
                $orderIdStmt->bind_param('i', $id);
                $orderIdStmt->execute();
                $orderIdResult = $orderIdStmt->get_result();
                if ($orderIdResult) {
                    while ($orderRow = $orderIdResult->fetch_assoc()) {
                        $orderIds[] = (int) $orderRow['order_id'];
                    }
                }
                $orderIdStmt->close();

                $delCartStmt = $conn->prepare('DELETE FROM cart WHERE product_id = ?');
                $delCartStmt->bind_param('i', $id);
                $delCartStmt->execute();
                $delCartStmt->close();

                $delReviewStmt = $conn->prepare('DELETE FROM reviews WHERE product_id = ?');
                $delReviewStmt->bind_param('i', $id);
                $delReviewStmt->execute();
                $delReviewStmt->close();

                $delOrderItemsStmt = $conn->prepare('DELETE FROM order_items WHERE product_id = ?');
                $delOrderItemsStmt->bind_param('i', $id);
                $delOrderItemsStmt->execute();
                $delOrderItemsStmt->close();

                if (!empty($orderIds)) {
                    $remainingStmt = $conn->prepare('SELECT COUNT(*) FROM order_items WHERE order_id = ?');
                    $deleteOrderStmt = $conn->prepare('DELETE FROM orders WHERE id = ?');

                    foreach ($orderIds as $orderId) {
                        $remainingCount = 0;
                        $remainingStmt->bind_param('i', $orderId);
                        $remainingStmt->execute();
                        $remainingStmt->bind_result($remainingCount);
                        $remainingStmt->fetch();
                        $remainingStmt->free_result();

                        if ($remainingCount === 0) {
                            $deleteOrderStmt->bind_param('i', $orderId);
                            $deleteOrderStmt->execute();
                        }
                    }

                    $remainingStmt->close();
                    $deleteOrderStmt->close();
                }

                $stmt = $conn->prepare('DELETE FROM products WHERE id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();

                if ($stmt->affected_rows > 0) {
                    $conn->commit();
                    $message = 'Product deleted successfully.';

                    $allImages = array_values(array_filter(array_map('trim', explode(',', (string) $imagePath))));
                    foreach ($allImages as $storedImage) {
                        $storedImage = ltrim($storedImage, '/');
                        $fullPath = __DIR__ . '/../products/' . $storedImage;
                        if ($storedImage !== '' && is_file($fullPath)) {
                            unlink($fullPath);
                        }
                    }
                } else {
                    $conn->rollback();
                    $error = 'Failed to delete product.';
                }

                $stmt->close();
            } catch (Throwable $e) {
                $conn->rollback();
                $error = 'Delete failed: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $price = (float) ($_POST['price'] ?? 0);
        $quantity = (int) ($_POST['quantity'] ?? 0);
        $description = trim($_POST['description'] ?? '');

        if ($id <= 0 || $name === '' || $price <= 0 || $quantity < 0) {
            $error = 'Please provide valid product information.';
        } else {
            $stmt = $conn->prepare('UPDATE products SET name = ?, price = ?, quantity = ?, description = ? WHERE id = ?');
            $stmt->bind_param('sdisi', $name, $price, $quantity, $description, $id);

            if ($stmt->execute()) {
                $message = 'Product updated successfully.';
            } else {
                $error = 'Failed to update product.';
            }
            $stmt->close();
        }
    }
}

if (isset($_GET['edit_id'])) {
    $editId = (int) $_GET['edit_id'];
    if ($editId > 0) {
        $stmt = $conn->prepare('SELECT id, name, description, price, quantity FROM products WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $editId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $editingProduct = $result->fetch_assoc();
        }
        $stmt->close();
    }
}

$products = array();
if ($conn) {
    $result = $conn->query("SELECT * FROM products ORDER BY id DESC LIMIT 50");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">    <link rel="icon" href="../favicon.svg?v=20260429f" type="image/svg+xml">
    <link rel="shortcut icon" href="../favicon.png?v=20260429f" type="image/png">
    <link rel="apple-touch-icon" href="../images/logo.png">
    <title>Manage Products - Admin</title>
    <link rel="stylesheet" href="css/admin.css">
    <style>
        .notice {
            margin-bottom: 14px;
            padding: 10px 12px;
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
        .edit-form {
            background: #ffffff;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            padding: 16px;
            margin-bottom: 16px;
        }
        .edit-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .edit-form label {
            display: block;
            margin-bottom: 4px;
            font-weight: 600;
        }
        .edit-form input,
        .edit-form textarea {
            width: 100%;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 8px 10px;
        }
        .edit-form textarea {
            min-height: 90px;
            resize: vertical;
        }
        .full-col {
            grid-column: 1 / -1;
        }
        .action-inline {
            display: inline-block;
            margin: 0;
        }
        @media (max-width: 768px) {
            .edit-grid {
                grid-template-columns: 1fr;
            }
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
                <li><a href="products.php" class="active">Products</a></li>
                <li><a href="ai-prompts.php">AI Prompts</a></li>
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
                <h1>Manage Products</h1>
                <a href="../products/add.php" class="btn btn-primary">+ Add New Product</a>
            </header>
            
            <main class="admin-content">
                <?php if ($message !== ''): ?>
                    <div class="notice success"><?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="notice error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <?php if ($editingProduct): ?>
                    <form class="edit-form" method="POST">
                        <h3 style="margin-bottom: 10px;">Edit Product #<?php echo (int) $editingProduct['id']; ?></h3>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?php echo (int) $editingProduct['id']; ?>">

                        <div class="edit-grid">
                            <div>
                                <label for="name">Product Name</label>
                                <input id="name" name="name" type="text" value="<?php echo htmlspecialchars($editingProduct['name']); ?>" required>
                            </div>
                            <div>
                                <label for="price">Price</label>
                                <input id="price" name="price" type="number" step="0.01" min="0.01" value="<?php echo htmlspecialchars((string) $editingProduct['price']); ?>" required>
                            </div>
                            <div>
                                <label for="quantity">Quantity</label>
                                <input id="quantity" name="quantity" type="number" min="0" value="<?php echo (int) $editingProduct['quantity']; ?>" required>
                            </div>
                            <div class="full-col">
                                <label for="description">Description</label>
                                <textarea id="description" name="description"><?php echo htmlspecialchars((string) $editingProduct['description']); ?></textarea>
                            </div>
                        </div>

                        <button class="btn btn-primary" type="submit">Save Changes</button>
                        <a class="btn" href="products.php" style="text-decoration:none;display:inline-block;">Cancel</a>
                    </form>
                <?php endif; ?>

                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Price</th>
                            <th>Quantity</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($products) > 0): ?>
                            <?php foreach ($products as $product): ?>
                            <tr>
                                <td><?php echo $product['id']; ?></td>
                                <td><?php echo htmlspecialchars($product['name']); ?></td>
                                <td>৳ <?php echo number_format($product['price'], 2); ?></td>
                                <td><?php echo $product['quantity']; ?></td>
                                <td>
                                    <a class="btn btn-primary" href="products.php?edit_id=<?php echo (int) $product['id']; ?>">Edit</a>
                                    <form class="action-inline" method="POST" onsubmit="return confirm('Delete this product?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int) $product['id']; ?>">
                                        <button class="btn btn-danger" type="submit">Delete</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align: center;">No products found</td>
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
