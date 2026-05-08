<?php
/**
 * View Single Product
 */

require_once 'config/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

if (!isset($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();
$product = getProductById($conn, $_GET['id']);

if (!$product) {
    header('Location: index.php');
    exit;
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $product['name']; ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <a href="index.php">← Back to Products</a>
        
        <div style="margin-top: 20px;">
            <h1><?php echo htmlspecialchars($product['name']); ?></h1>
            
            <?php if (isset($product['image'])): ?>
                <img src="<?php echo htmlspecialchars($product['image']); ?>" alt="Product" style="max-width: 400px;">
            <?php endif; ?>
            
            <p><strong>Price:</strong> ৳ <?php echo number_format($product['price'], 2); ?></p>
            <p><strong>Quantity:</strong> <?php echo $product['quantity']; ?></p>
            
            <?php if (isset($product['description'])): ?>
                <p><strong>Description:</strong></p>
                <p><?php echo htmlspecialchars($product['description']); ?></p>
            <?php endif; ?>
            
            <button class="btn btn-primary">Edit</button>
            <button class="btn btn-danger">Delete</button>
        </div>
    </div>
</body>
</html>

<?php
$db->closeConnection();
?>
