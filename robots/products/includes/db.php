<?php
/**
 * Database Connection Class
 */

class Database {
    private $host;
    private $db_user;
    private $db_pass;
    private $db_name;
    private $conn;
    
    public function __construct() {
        $this->host = DB_HOST;
        $this->db_user = DB_USER;
        $this->db_pass = DB_PASS;
        $this->db_name = DB_NAME;
        
        $this->connect();
    }
    
    private function connect() {
        $attempts = array();

        $credentialSets = array(
            array($this->host, $this->db_user, $this->db_pass)
        );

        if (defined('DB_FALLBACK_HOST') && defined('DB_FALLBACK_USER') && defined('DB_FALLBACK_PASS')) {
            $credentialSets[] = array(DB_FALLBACK_HOST, DB_FALLBACK_USER, DB_FALLBACK_PASS);
        }

        $dbNames = $this->buildDbNameVariants(
            $this->db_name,
            defined('DB_FALLBACK_NAME') ? DB_FALLBACK_NAME : ''
        );

        foreach ($credentialSets as $set) {
            foreach ($dbNames as $dbName) {
                $attempts[] = array($set[0], $set[1], $set[2], $dbName);
            }
        }

        $cpanelUser = $this->detectCpanelUser();
        if ($cpanelUser !== '') {
            foreach ($credentialSets as $set) {
                $prefixedUser = $this->prefixWithCpanelUser($cpanelUser, $set[1]);
                foreach ($dbNames as $dbName) {
                    $prefixedDb = $this->prefixWithCpanelUser($cpanelUser, $dbName);
                    $attempts[] = array($set[0], $prefixedUser, $set[2], $prefixedDb);
                }
            }
        }

        $seen = array();
        foreach ($attempts as $attempt) {
            $key = implode('|', $attempt);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $this->conn = $this->tryConnect($attempt[0], $attempt[1], $attempt[2], $attempt[3]);
            if ($this->conn) {
                break;
            }
        }

        if (!$this->conn) {
            // Output a full HTML page so the response body is never empty
            // (some shared hosts strip the body of raw 500 text/plain responses).
            if (!headers_sent()) {
                header('HTTP/1.1 503 Service Unavailable');
                header('Content-Type: text/html; charset=UTF-8');
                header('Retry-After: 60');
            }
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
            echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
            echo '<title>Site Unavailable</title>';
            echo '<style>*{box-sizing:border-box}body{font-family:Arial,sans-serif;background:#f8fafc;';
            echo 'color:#0f172a;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}';
            echo '.box{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px 28px;';
            echo 'max-width:560px;width:90%;box-shadow:0 8px 30px rgba(15,23,42,.08);text-align:center}';
            echo 'h1{font-size:22px;margin:0 0 10px;color:#dc2626}p{margin:6px 0;font-size:14px;color:#475569}';
            echo '.code{font-family:monospace;background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:12px}';
            echo '</style></head><body><div class="box">';
            echo '<h1>&#9888; Database Connection Error</h1>';
            echo '<p>Could not connect to the database. Please check your credentials.</p>';
            echo '<p class="code">products/config/config.php &mdash; DB_USER / DB_PASS / DB_NAME</p>';
            echo '<p>Ensure the database is created and imported on your hosting panel.</p>';
            echo '</div></body></html>';
            exit;
        }

        $this->ensureSchemaCompatibility();
    }

    private function tryConnect($host, $user, $pass, $name) {
        try {
            $conn = @new mysqli($host, $user, $pass, $name);
            if ($conn->connect_error) {
                return null;
            }
            return $conn;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function buildDbNameVariants($primaryName, $fallbackName) {
        $names = array();

        $add = function($value) use (&$names) {
            $value = trim((string) $value);
            if ($value === '') {
                return;
            }
            if (!in_array($value, $names, true)) {
                $names[] = $value;
            }
        };

        $add($primaryName);
        $add($fallbackName);

        $base = trim((string) $primaryName);
        if ($base !== '') {
            $add(str_replace('accounta_', 'accounts_', $base));
            $add(str_replace('accounts_', 'accounta_', $base));
            $add(str_replace('_', '', $base));
        }

        $add('accounta_bazar');
        $add('accounts_bazar');
        $add('accountsbazar');

        return $names;
    }

    private function prefixWithCpanelUser($cpanelUser, $value) {
        $cpanelUser = trim((string) $cpanelUser);
        $value = trim((string) $value);
        if ($cpanelUser === '' || $value === '') {
            return $value;
        }

        if (strpos($value, $cpanelUser . '_') === 0) {
            return $value;
        }

        return $cpanelUser . '_' . $value;
    }

    private function detectCpanelUser() {
        $docRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($docRoot === '') {
            return '';
        }

        $normalized = str_replace('\\', '/', $docRoot);
        if (preg_match('#/home(?:\d+)?/([^/]+)/#', $normalized, $m)) {
            return trim((string) ($m[1] ?? ''));
        }

        return '';
    }

    private function columnExists($table, $column) {
        $tableEsc = $this->conn->real_escape_string($table);
        $colEsc = $this->conn->real_escape_string($column);
        $sql = "SELECT COUNT(*) AS c
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = '{$tableEsc}'
                  AND COLUMN_NAME = '{$colEsc}'";
        $res = $this->conn->query($sql);
        if (!$res) {
            return false;
        }
        $row = $res->fetch_assoc();
        return ((int) ($row['c'] ?? 0)) > 0;
    }

    private function ensureSchemaCompatibility() {
        // Keep hosting DB compatible with current app queries.
        $productsTable = $this->conn->query("SHOW TABLES LIKE 'products'");
        if (!$productsTable || $productsTable->num_rows === 0) {
            $this->ensureAiPromptsCompatibility();
            return;
        }

        if (!$this->columnExists('products', 'quantity')) {
            $this->conn->query("ALTER TABLE products ADD COLUMN quantity INT NOT NULL DEFAULT 0");
        }

        if (!$this->columnExists('products', 'category')) {
            $this->conn->query("ALTER TABLE products ADD COLUMN category VARCHAR(100) DEFAULT NULL");
        }

        if (!$this->columnExists('products', 'sku')) {
            $this->conn->query("ALTER TABLE products ADD COLUMN sku VARCHAR(100) DEFAULT NULL");
        }

        if (!$this->columnExists('products', 'created_at')) {
            $this->conn->query("ALTER TABLE products ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP");
        }

        if (!$this->columnExists('products', 'updated_at')) {
            $this->conn->query("ALTER TABLE products ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        }

        $this->ensureAiPromptsCompatibility();
    }

    private function ensureAiPromptsCompatibility() {
        $this->conn->query(
            "CREATE TABLE IF NOT EXISTS ai_prompts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                prompt_text TEXT NOT NULL,
                image_path VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )"
        );

        // Legacy column migration support (old installs may have prompt/image columns).
        if ($this->columnExists('ai_prompts', 'prompt') && !$this->columnExists('ai_prompts', 'prompt_text')) {
            $this->conn->query("ALTER TABLE ai_prompts ADD COLUMN prompt_text TEXT NULL");
            $this->conn->query("UPDATE ai_prompts SET prompt_text = prompt WHERE (prompt_text IS NULL OR prompt_text = '')");
        }
        if ($this->columnExists('ai_prompts', 'image') && !$this->columnExists('ai_prompts', 'image_path')) {
            $this->conn->query("ALTER TABLE ai_prompts ADD COLUMN image_path VARCHAR(255) NULL");
            $this->conn->query("UPDATE ai_prompts SET image_path = image WHERE (image_path IS NULL OR image_path = '')");
        }
        if (!$this->columnExists('ai_prompts', 'prompt_text')) {
            $this->conn->query("ALTER TABLE ai_prompts ADD COLUMN prompt_text TEXT NULL");
        }
        if (!$this->columnExists('ai_prompts', 'image_path')) {
            $this->conn->query("ALTER TABLE ai_prompts ADD COLUMN image_path VARCHAR(255) NULL");
        }
        if (!$this->columnExists('ai_prompts', 'created_at')) {
            $this->conn->query("ALTER TABLE ai_prompts ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP");
        }

        // Enforce prompt_text not empty for existing rows.
        $this->conn->query("UPDATE ai_prompts SET prompt_text = COALESCE(NULLIF(prompt_text, ''), 'Untitled prompt')");

        $this->conn->query(
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
    }
    
    public function getConnection() {
        return $this->conn;
    }
    
    public function closeConnection() {
        $this->conn->close();
    }
}

?>
