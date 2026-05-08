-- cPanel/phpMyAdmin import note:
-- 1) First create/select your hosting database (example: cpaneluser_accountsbazar)
-- 2) Then import this file. Do not run CREATE DATABASE/USE here.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Products Table
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    price DECIMAL(10, 2) NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    image VARCHAR(255),
    category VARCHAR(100),
    sku VARCHAR(100) UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_price (price)
);

-- Users Table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    phone VARCHAR(20),
    address VARCHAR(255),
    city VARCHAR(100),
    country VARCHAR(100),
    postal_code VARCHAR(20),
    user_type ENUM('customer', 'admin') DEFAULT 'customer',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_username (username)
);

-- Orders Table
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(50) UNIQUE NOT NULL,
    user_id INT NOT NULL,
    total_amount DECIMAL(10, 2) NOT NULL,
    tax_amount DECIMAL(10, 2) DEFAULT 0,
    shipping_amount DECIMAL(10, 2) DEFAULT 0,
    status ENUM('pending', 'processing', 'shipped', 'delivered', 'cancelled') DEFAULT 'pending',
    payment_method VARCHAR(50),
    payment_status ENUM('unpaid', 'paid', 'failed') DEFAULT 'unpaid',
    shipping_address TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
);

-- Order Items Table
CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10, 2) NOT NULL,
    total_price DECIMAL(10, 2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    INDEX idx_order_id (order_id)
);

-- Cart Table
CREATE TABLE IF NOT EXISTS cart (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    UNIQUE KEY unique_cart (user_id, product_id)
);

-- Reviews Table
CREATE TABLE IF NOT EXISTS reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    user_id INT NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    title VARCHAR(255),
    review TEXT,
    is_verified TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_product_id (product_id)
);

-- Categories Table
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    image VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Payments Table
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    payment_method VARCHAR(50),
    transaction_id VARCHAR(100),
    status ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id),
    INDEX idx_order_id (order_id)
);

-- AI Prompts Table
CREATE TABLE IF NOT EXISTS ai_prompts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prompt_text TEXT NOT NULL,
    image_path VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- AI Prompt Reactions Table
CREATE TABLE IF NOT EXISTS ai_prompt_reactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prompt_id INT NOT NULL,
    user_id INT NOT NULL,
    reaction ENUM('like', 'unlike') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_prompt_user (prompt_id, user_id),
    INDEX idx_prompt_reaction (prompt_id, reaction)
);

-- Support Threads Table
CREATE TABLE IF NOT EXISTS support_threads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    visitor_token VARCHAR(64) NOT NULL,
    customer_name VARCHAR(120) DEFAULT '',
    status ENUM('open', 'closed') DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_visitor_thread (visitor_token),
    INDEX idx_user_id (user_id)
);

-- Support Messages Table
CREATE TABLE IF NOT EXISTS support_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    thread_id INT NOT NULL,
    sender_type ENUM('user', 'admin') NOT NULL,
    message_text TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_thread_id (thread_id),
    CONSTRAINT fk_support_messages_thread FOREIGN KEY (thread_id) REFERENCES support_threads(id) ON DELETE CASCADE
);

-- Support Admin Presence Table
CREATE TABLE IF NOT EXISTS support_admin_presence (
    admin_user_id INT PRIMARY KEY,
    last_active_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_last_active_at (last_active_at)
);

-- Site Settings Table
CREATE TABLE IF NOT EXISTS site_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(120) NOT NULL UNIQUE,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert Sample Products
INSERT IGNORE INTO products (name, description, price, quantity, category, sku, image) VALUES
('Laptop Computer', 'High-performance laptop with Intel i7 processor', 85000.00, 15, 'Electronics', 'LAPTOP-001', 'images/laptop.jpg'),
('Wireless Mouse', 'Ergonomic wireless mouse with USB receiver', 1500.00, 50, 'Accessories', 'MOUSE-001', 'images/mouse.jpg'),
('Mechanical Keyboard', 'RGB mechanical keyboard with blue switches', 5500.00, 30, 'Accessories', 'KEYBOARD-001', 'images/keyboard.jpg'),
('USB-C Hub', 'Multi-port USB-C hub with HDMI and card reader', 3500.00, 25, 'Accessories', 'HUB-001', 'images/hub.jpg'),
('Wireless Headphones', 'Noise-canceling wireless headphones with 30-hour battery', 12000.00, 20, 'Audio', 'HEADPHONES-001', 'images/headphones.jpg'),
('External Hard Drive', '2TB external hard drive for backup and storage', 5000.00, 35, 'Storage', 'HDD-001', 'images/hdd.jpg'),
('Monitor 4K', '27-inch 4K monitor with HDR support', 35000.00, 10, 'Electronics', 'MONITOR-001', 'images/monitor.jpg'),
('Gaming Mouse Pad', 'Large gaming mouse pad with non-slip base', 2500.00, 45, 'Accessories', 'MOUSEPAD-001', 'images/mousepad.jpg'),
('Laptop Stand', 'Adjustable aluminum laptop stand for better ergonomics', 3000.00, 40, 'Accessories', 'STAND-001', 'images/stand.jpg'),
('Webcam 1080p', 'Full HD webcam with built-in microphone', 4500.00, 28, 'Electronics', 'WEBCAM-001', 'images/webcam.jpg');

-- Insert Categories
INSERT IGNORE INTO categories (name, description, image) VALUES
('Electronics', 'Electronic devices and gadgets', 'images/electronics.jpg'),
('Accessories', 'Computer accessories and peripherals', 'images/accessories.jpg'),
('Audio', 'Audio equipment and speakers', 'images/audio.jpg'),
('Storage', 'Storage devices and solutions', 'images/storage.jpg');

-- Insert Sample Admin User
INSERT INTO users (username, email, password, first_name, last_name, user_type, is_active) VALUES
('admin', 'admin@accountsbazar.com', MD5('admin123'), 'Admin', 'User', 'admin', 1)
ON DUPLICATE KEY UPDATE
    password = VALUES(password),
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    user_type = 'admin',
    is_active = 1;

-- Insert Sample Customer Users
INSERT IGNORE INTO users (username, email, password, first_name, last_name, phone, city, country, user_type) VALUES
('customer1', 'customer1@email.com', MD5('password123'), 'Ahmed', 'Khan', '01712345678', 'Dhaka', 'Bangladesh', 'customer'),
('customer2', 'customer2@email.com', MD5('password123'), 'Fatima', 'Ahmed', '01798765432', 'Chittagong', 'Bangladesh', 'customer'),
('customer3', 'customer3@email.com', MD5('password123'), 'Mohammad', 'Hassan', '01956789012', 'Sylhet', 'Bangladesh', 'customer');

SET FOREIGN_KEY_CHECKS = 1;

-- ── Password Reset OTPs ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `password_resets` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `email`      VARCHAR(255) NOT NULL,
    `otp_code`   VARCHAR(6)   NOT NULL,
    `expires_at` DATETIME     NOT NULL,
    `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Email Queue (cron-based delivery fallback) ───────────────────────────────
CREATE TABLE IF NOT EXISTS `email_queue` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `to_email`   VARCHAR(255) NOT NULL,
    `subject`    VARCHAR(255) NOT NULL,
    `body`       MEDIUMTEXT   NOT NULL,
    `attempts`   INT          DEFAULT 0,
    `status`     ENUM('pending','sending','sent','failed') DEFAULT 'pending',
    `last_error` TEXT,
    `created_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    `sent_at`    TIMESTAMP    NULL DEFAULT NULL,
    INDEX `idx_status`     (`status`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

