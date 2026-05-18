<?php
// includes/config.php
session_start();

// Database configuration
define('DB_HOST', 'localhost');
define('DB_PORT', '5432');
define('DB_NAME', 'bisu_ige_dup'); // Changed from BISU_IGE_DB
define('DB_USER', 'postgres');
define('DB_PASS', 'imweak345');

// System constants
define('SITE_NAME', 'BISU IGE Aquaculture Management System');
define('SITE_URL', 'http://localhost/BISU_IGE');
define('UPLOAD_PATH', __DIR__ . '/../uploads/');

// Color scheme
define('COLOR_PRIMARY', '#3b82f6');
define('COLOR_SECONDARY', '#10b981');
define('COLOR_ACCENT', '#f59e0b');
define('COLOR_LIGHT', '#f3f4f6');
define('COLOR_DARK', '#1f2937');

// Database connection class
class Database {
    private $host = DB_HOST;
    private $port = DB_PORT;
    private $dbname = DB_NAME;
    private $user = DB_USER;
    private $pass = DB_PASS;
    private $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $dsn = "pgsql:host={$this->host};port={$this->port};dbname={$this->dbname}";
            $this->conn = new PDO($dsn, $this->user, $this->pass);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $e) {
            error_log("Connection error: " . $e->getMessage());
            die("Database connection failed. Please check error logs.");
        }
        return $this->conn;
    }
}

// Load consolidated system functions
require_once __DIR__ . '/functions.php';

// Include session.php which contains the SessionManager class
require_once __DIR__ . '/session.php';
?>