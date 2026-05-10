CREATE DATABASE IF NOT EXISTS accountsbazar CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE accountsbazar;

CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category ENUM('social', 'gaming', 'business') NOT NULL,
    platform VARCHAR(80) NOT NULL,
    title VARCHAR(180) NOT NULL,
    description TEXT NOT NULL,
    badge VARCHAR(80) NOT NULL DEFAULT 'Verified',
    price_usd DECIMAL(10,2) NOT NULL,
    seller_status VARCHAR(80) NOT NULL DEFAULT 'Trusted Seller',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO products (category, platform, title, description, badge, price_usd, seller_status) VALUES
('social', 'Instagram', 'Fashion Niche Handle', '185K followers, high story reach, organic audience.', 'Trusted Seller', 1450.00, 'Trusted Seller'),
('gaming', 'PUBG Mobile', 'Conqueror ID + Rare Set', 'Season elite history, mythic inventory, transfer-ready.', 'Escrow Ready', 860.00, 'Escrow Ready'),
('business', 'Meta Ads', 'Verified Ad Account', 'Long spend history, low risk profile, instant handover.', 'Fast Transfer', 2300.00, 'Fast Transfer'),
('social', 'YouTube', 'Monetized Tech Channel', '78K subs, stable RPM, reusable evergreen content base.', 'Income Proof', 3100.00, 'Income Proof'),
('gaming', 'Free Fire', 'Grandmaster Vault ID', 'Rare bundles, weapon skins, active tournament-ready setup.', 'Top Rated', 690.00, 'Top Rated'),
('business', 'Shopify', 'Ready Dropship Store', 'Winning product data, pixel setup, conversion assets included.', 'Verified Revenue', 4800.00, 'Verified Revenue');
