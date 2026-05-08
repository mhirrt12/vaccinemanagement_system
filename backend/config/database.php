<?php
/**
 * Database configuration and connection class
 * Uses Singleton pattern for single PDO instance
 * Reads configuration from environment variables
 */

class Database {
    private static $instance = null;
    private $pdo;
    
    /**
     * Private constructor to prevent direct instantiation
     * Loads database configuration from environment variables
     */
    private function __construct() {
        // Load environment variables from .env file if not already loaded
        $this->loadEnv();
        
        // Get database configuration from environment or use defaults
        $host = getenv('DB_HOST') ?: 'localhost';
        $dbname = getenv('DB_NAME') ?: 'vaccine_ms';
       $user = 'root';
       $pass = 'root';;
        $charset = 'utf8mb4';
        
        // DSN (Data Source Name)
        $dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
        
        // PDO options for security and performance
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,      // Throw exceptions on errors
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, // Fetch associative arrays by default
            PDO::ATTR_EMULATE_PREPARES => false,              // Use native prepared statements
            PDO::ATTR_STRINGIFY_FETCHES => false,             // Don't convert numbers to strings
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci" // UTF-8 support
        ];
        
        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            // Log error and return a user-friendly message (no sensitive info)
            error_log("Database connection failed: " . $e->getMessage());
            die(json_encode([
                'success' => false,
                'message' => 'Database connection failed. Please check your configuration.',
                'error' => $e->getMessage() // Remove in production
            ]));
        }
    }
    
    /**
     * Load environment variables from .env file
     */
    private function loadEnv() {
        $envFile = __DIR__ . '/../.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                // Skip comments
                if (strpos(trim($line), '#') === 0) {
                    continue;
                }
                // Parse key=value
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $key = trim($parts[0]);
                    $value = trim($parts[1]);
                    putenv("$key=$value");
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                }
            }
        }
    }
    
    /**
     * Get the single PDO instance (Singleton pattern)
     * 
     * @return PDO The database connection
     */
    public static function getConnection() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance->pdo;
    }
    
    /**
     * Prevent cloning of the instance
     */
    private function __clone() {}
    
    /**
     * Prevent unserializing of the instance
     */
    public function __wakeup() {}
    
    /**
     * Begin a transaction
     * 
     * @return bool
     */
    public static function beginTransaction() {
        return self::getConnection()->beginTransaction();
    }
    
    /**
     * Commit a transaction
     * 
     * @return bool
     */
    public static function commit() {
        return self::getConnection()->commit();
    }
    
    /**
     * Rollback a transaction
     * 
     * @return bool
     */
    public static function rollback() {
        return self::getConnection()->rollBack();
    }
    
    /**
     * Get the last insert ID
     * 
     * @return string
     */
    public static function lastInsertId() {
        return self::getConnection()->lastInsertId();
    }
    
    /**
     * Prepare a SQL statement
     * 
     * @param string $sql
     * @return PDOStatement
     */
    public static function prepare($sql) {
        return self::getConnection()->prepare($sql);
    }
    
    /**
     * Execute a SQL query
     * 
     * @param string $sql
     * @return PDOStatement
     */
    public static function query($sql) {
        return self::getConnection()->query($sql);
    }
    
    /**
     * Escape a string for use in SQL (use prepared statements instead)
     * 
     * @param string $string
     * @return string
     */
    public static function quote($string) {
        return self::getConnection()->quote($string);
    }
}
?>