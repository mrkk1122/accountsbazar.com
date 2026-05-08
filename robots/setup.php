<?php
/**
 * Database Setup & Sample Data
 */

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "accounta_bazar";

// Create connection
$conn = new mysqli($servername, $username, $password);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database if not exists
$sql = "CREATE DATABASE IF NOT EXISTS " . $dbname;
if ($conn->query($sql) === TRUE) {
    echo "Database created successfully or already exists<br>";
} else {
    echo "Error creating database: " . $conn->error . "<br>";
}

// Select database
$conn->select_db($dbname);

// Create products table
$createTable = "CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    price DECIMAL(10, 2) NOT NULL,
    quantity INT NOT NULL,
    image VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

if ($conn->query($createTable) === TRUE) {
    echo "Products table created successfully or already exists<br>";
} else {
    echo "Error creating table: " . $conn->error . "<br>";
}

// Check if products already exist
$checkProducts = "SELECT COUNT(*) as count FROM products";
$result = $conn->query($checkProducts);
$row = $result->fetch_assoc();

if ($row['count'] == 0) {
    // Insert sample products
    $products = array(
        array('name' => 'Laptop Computer', 'description' => 'High-performance laptop with Intel i7 processor', 'price' => 85000, 'quantity' => 15),
        array('name' => 'Wireless Mouse', 'description' => 'Ergonomic wireless mouse with USB receiver', 'price' => 1500, 'quantity' => 50),
        array('name' => 'Mechanical Keyboard', 'description' => 'RGB mechanical keyboard with blue switches', 'price' => 5500, 'quantity' => 30),
        array('name' => 'USB-C Hub', 'description' => 'Multi-port USB-C hub with HDMI and card reader', 'price' => 3500, 'quantity' => 25),
        array('name' => 'Wireless Headphones', 'description' => 'Noise-canceling wireless headphones with 30-hour battery', 'price' => 12000, 'quantity' => 20),
        array('name' => 'External Hard Drive', 'description' => '2TB external hard drive for backup and storage', 'price' => 5000, 'quantity' => 35),
        array('name' => 'Monitor 4K', 'description' => '27-inch 4K monitor with HDR support', 'price' => 35000, 'quantity' => 10),
        array('name' => 'Gaming Mouse Pad', 'description' => 'Large gaming mouse pad with non-slip base', 'price' => 2500, 'quantity' => 45),
        array('name' => 'Laptop Stand', 'description' => 'Adjustable aluminum laptop stand for better ergonomics', 'price' => 3000, 'quantity' => 40),
        array('name' => 'Webcam 1080p', 'description' => 'Full HD webcam with built-in microphone', 'price' => 4500, 'quantity' => 28)
    );
    
    foreach ($products as $product) {
        $name = $conn->real_escape_string($product['name']);
        $description = $conn->real_escape_string($product['description']);
        $price = $product['price'];
        $quantity = $product['quantity'];
        
        $insertProduct = "INSERT INTO products (name, description, price, quantity, image) 
                         VALUES ('$name', '$description', $price, $quantity, 'images/default.jpg')";
        
        if ($conn->query($insertProduct) === TRUE) {
            echo "Product '{$product['name']}' added successfully<br>";
        } else {
            echo "Error adding product: " . $conn->error . "<br>";
        }
    }
} else {
    echo "Products already exist in database<br>";
}

$conn->close();
echo "<br><strong>✅ Database setup completed!</strong><br>";
echo "<a href='admin/index.php'>Go to Admin Dashboard</a>";

?>
