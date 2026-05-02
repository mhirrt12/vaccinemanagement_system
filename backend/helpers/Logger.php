<?php
/**
 * Logger Helper - Handles system logging for audit trail and debugging
 * 
 * Responsibilities:
 * - Log user actions to audit_logs table
 * - Log system events (info, warning, error) to file (optional)
 * - Capture IP address and user agent
 * - Provide static methods for easy logging from anywhere
 * - Automatic JSON encoding of details
 */

require_once __DIR__ . '/../config/database.php';

class Logger {
    // Log levels for file logging
    const LEVEL_INFO = 'INFO';
    const LEVEL_WARNING = 'WARNING';
    const LEVEL_ERROR = 'ERROR';
    const LEVEL_DEBUG = 'DEBUG';
    
    // Log file path (configurable)
    private static $logFile = null;
    private static $fileLoggingEnabled = false;
    
    /**
     * Initialize logger settings (call once at application start)
     * 
     * @param bool $enableFileLogging
     * @param string|null $logFilePath
     * @return void
     */
    public static function init($enableFileLogging = true, $logFilePath = null) {
        self::$fileLoggingEnabled = $enableFileLogging;
        
        if ($logFilePath === null) {
            $logDir = __DIR__ . '/../../logs/';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0777, true);
            }
            self::$logFile = $logDir . 'app.log';
        } else {
            self::$logFile = $logFilePath;
        }
    }
    
    /**
     * Get client IP address
     * 
     * @return string|null
     */
    private static function getClientIp() {
        $headers = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                foreach ($ips as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                }
            }
        }
        return null;
    }
    
    /**
     * Write to file log (if enabled)
     * 
     * @param string $level
     * @param string $message
     * @param array|null $context
     * @return void
     */
    private static function writeToFile($level, $message, $context = null) {
        if (!self::$fileLoggingEnabled) return;
        
        $logEntry = sprintf(
            "[%s] [%s] %s %s\n",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            $context ? json_encode($context) : ''
        );
        
        if (self::$logFile && is_writable(dirname(self::$logFile))) {
            file_put_contents(self::$logFile, $logEntry, FILE_APPEND | LOCK_EX);
        }
    }
    
    /**
     * Log to database (audit_logs table)
     * 
     * @param int|null $userId
     * @param string $action
     * @param mixed $details
     * @param string|null $ipAddress
     * @return void
     */
    private static function logToDatabase($userId, $action, $details = null, $ipAddress = null) {
        $db = Database::getConnection();
        $detailsJson = $details ? (is_array($details) ? json_encode($details) : $details) : null;
        $ip = $ipAddress ?: self::getClientIp();
        
        $stmt = $db->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, $action, $detailsJson, $ip]);
    }
    
    /**
     * Generic log method
     * 
     * @param string $level
     * @param string $action
     * @param int|null $userId
     * @param mixed $details
     * @param string|null $ip
     * @return void
     */
    public static function log($level, $action, $userId = null, $details = null, $ip = null) {
        // Log to database (for audit trail)
        if ($userId || $action) {
            self::logToDatabase($userId, $action, $details, $ip);
        }
        
        // Log to file
        self::writeToFile($level, $action, ['user_id' => $userId, 'details' => $details]);
    }
    
    /**
     * Log info-level message
     * 
     * @param string $action
     * @param int|null $userId
     * @param mixed $details
     * @param string|null $ip
     * @return void
     */
    public static function info($action, $userId = null, $details = null, $ip = null) {
        self::log(self::LEVEL_INFO, $action, $userId, $details, $ip);
    }
    
    /**
     * Log warning-level message
     * 
     * @param string $action
     * @param int|null $userId
     * @param mixed $details
     * @param string|null $ip
     * @return void
     */
    public static function warning($action, $userId = null, $details = null, $ip = null) {
        self::log(self::LEVEL_WARNING, $action, $userId, $details, $ip);
    }
    
    /**
     * Log error-level message
     * 
     * @param string $action
     * @param int|null $userId
     * @param mixed $details
     * @param string|null $ip
     * @return void
     */
    public static function error($action, $userId = null, $details = null, $ip = null) {
        self::log(self::LEVEL_ERROR, $action, $userId, $details, $ip);
    }
    
    /**
     * Log debug-level message (only to file, not database)
     * 
     * @param string $message
     * @param mixed $context
     * @return void
     */
    public static function debug($message, $context = null) {
        self::writeToFile(self::LEVEL_DEBUG, $message, $context);
    }
    
    /**
     * Log user login success
     * 
     * @param int $userId
     * @param string $email
     * @return void
     */
    public static function loginSuccess($userId, $email) {
        self::info('User logged in', $userId, ['email' => $email]);
    }
    
    /**
     * Log user login failure
     * 
     * @param string $email
     * @param string $reason
     * @return void
     */
    public static function loginFailed($email, $reason = 'Invalid credentials') {
        self::warning('Login failed', null, ['email' => $email, 'reason' => $reason]);
    }
    
    /**
     * Log user logout
     * 
     * @param int $userId
     * @return void
     */
    public static function logout($userId) {
        self::info('User logged out', $userId);
    }
    
    /**
     * Log database query error
     * 
     * @param string $sql
     * @param array $params
     * @param string $error
     * @return void
     */
    public static function dbError($sql, $params, $error) {
        self::error('Database error', null, ['sql' => $sql, 'params' => $params, 'error' => $error]);
    }
    
    /**
     * Log API request (for debugging)
     * 
     * @param string $method
     * @param string $uri
     * @param int $statusCode
     * @param int $durationMs
     * @return void
     */
    public static function apiRequest($method, $uri, $statusCode, $durationMs) {
        self::debug('API Request', [
            'method' => $method,
            'uri' => $uri,
            'status' => $statusCode,
            'duration_ms' => $durationMs
        ]);
    }
}
?>