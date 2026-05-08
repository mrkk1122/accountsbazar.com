<?php
/**
 * Products Index
 * Display all products
 */

require_once 'config/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <h1>Products Management</h1>
        <a href="add.php" class="btn btn-primary">+ Add New Product</a>
        
        <div id="products-container" class="products-grid">
            <p>Loading products...</p>
        </div>
    </div>
    
    <script src="js/script.js"></script>
</body>
</html>
