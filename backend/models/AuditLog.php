<?php
/**
 * AuditLog Model - Handles all database operations related to audit logs
 * 
 * Tables: audit_logs
 * 
 * Responsibilities:
 * - Insert new audit log entry (user action)
 * - Retrieve audit logs with pagination and filters
 * - Get audit logs by user ID
 * - Get audit logs by action type
 * - Get audit logs by date range
 * - Count total logs
 * - Delete old logs (retention policy)
 */

require_once __DIR__ . '/../config/database.php';

class AuditLog {
    private $db;
    
    public function __construct() {
        $this->db = Database::getConnection();
    }
    
    /**
     * Create a new audit log entry
     * 
     * @param int $userId
     * @param string $action
     * @param array|string|null $details
     * @param string|null $ipAddress
     * @return bool
     */
    public function create($userId, $action, $details = null, $ipAddress = null) {
        $detailsJson = $details ? (is_array($details) ? json_encode($details) : $details) : null;
        $ip = $ipAddress ?: ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null);
        
        $stmt = $this->db->prepare("
            INSERT INTO audit_logs (user_id, action, details, ip_address) 
            VALUES (?, ?, ?, ?)
        ");
        return $stmt->execute([$userId, $action, $detailsJson, $ip]);
    }
    
    /**
     * Get all audit logs with pagination and optional filters
     * 
     * @param int $limit
     * @param int $offset
     * @param array $filters (user_id, action, start_date, end_date)
     * @return array
     */
    public function getAll($limit = 100, $offset = 0, $filters = []) {
        $sql = "
            SELECT al.*, u.name as user_name, u.email as user_email, u.role_id
            FROM audit_logs al
            JOIN users u ON al.user_id = u.id
            WHERE 1=1
        ";
        $params = [];
        
        if (!empty($filters['user_id'])) {
            $sql .= " AND al.user_id = ?";
            $params[] = $filters['user_id'];
        }
        
        if (!empty($filters['action'])) {
            $sql .= " AND al.action LIKE ?";
            $params[] = "%{$filters['action']}%";
        }
        
        if (!empty($filters['start_date'])) {
            $sql .= " AND DATE(al.created_at) >= ?";
            $params[] = $filters['start_date'];
        }
        
        if (!empty($filters['end_date'])) {
            $sql .= " AND DATE(al.created_at) <= ?";
            $params[] = $filters['end_date'];
        }
        
        $sql .= " ORDER BY al.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll();
        
        // Decode JSON details for each log
        foreach ($logs as &$log) {
            if ($log['details']) {
                $log['details'] = json_decode($log['details'], true);
            }
        }
        
        return $logs;
    }
    
    /**
     * Get audit logs for a specific user
     * 
     * @param int $userId
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getByUser($userId, $limit = 100, $offset = 0) {
        $stmt = $this->db->prepare("
            SELECT al.*, u.name as user_name
            FROM audit_logs al
            JOIN users u ON al.user_id = u.id
            WHERE al.user_id = ?
            ORDER BY al.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$userId, $limit, $offset]);
        $logs = $stmt->fetchAll();
        
        foreach ($logs as &$log) {
            if ($log['details']) {
                $log['details'] = json_decode($log['details'], true);
            }
        }
        
        return $logs;
    }
    
    /**
     * Get audit logs by action type
     * 
     * @param string $action
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getByAction($action, $limit = 100, $offset = 0) {
        $stmt = $this->db->prepare("
            SELECT al.*, u.name as user_name
            FROM audit_logs al
            JOIN users u ON al.user_id = u.id
            WHERE al.action = ?
            ORDER BY al.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$action, $limit, $offset]);
        $logs = $stmt->fetchAll();
        
        foreach ($logs as &$log) {
            if ($log['details']) {
                $log['details'] = json_decode($log['details'], true);
            }
        }
        
        return $logs;
    }
    
    /**
     * Get audit logs within a date range
     * 
     * @param string $startDate (Y-m-d)
     * @param string $endDate (Y-m-d)
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getByDateRange($startDate, $endDate, $limit = 100, $offset = 0) {
        $stmt = $this->db->prepare("
            SELECT al.*, u.name as user_name
            FROM audit_logs al
            JOIN users u ON al.user_id = u.id
            WHERE DATE(al.created_at) BETWEEN ? AND ?
            ORDER BY al.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$startDate, $endDate, $limit, $offset]);
        $logs = $stmt->fetchAll();
        
        foreach ($logs as &$log) {
            if ($log['details']) {
                $log['details'] = json_decode($log['details'], true);
            }
        }
        
        return $logs;
    }
    
    /**
     * Count total audit logs (with optional filters)
     * 
     * @param array $filters (user_id, action, start_date, end_date)
     * @return int
     */
    public function count($filters = []) {
        $sql = "SELECT COUNT(*) as count FROM audit_logs al WHERE 1=1";
        $params = [];
        
        if (!empty($filters['user_id'])) {
            $sql .= " AND al.user_id = ?";
            $params[] = $filters['user_id'];
        }
        
        if (!empty($filters['action'])) {
            $sql .= " AND al.action LIKE ?";
            $params[] = "%{$filters['action']}%";
        }
        
        if (!empty($filters['start_date'])) {
            $sql .= " AND DATE(al.created_at) >= ?";
            $params[] = $filters['start_date'];
        }
        
        if (!empty($filters['end_date'])) {
            $sql .= " AND DATE(al.created_at) <= ?";
            $params[] = $filters['end_date'];
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetch()['count'];
    }
    
    /**
     * Delete old audit logs (retention policy)
     * 
     * @param int $days (keep logs newer than this many days)
     * @return int Number of deleted rows
     */
    public function deleteOld($days = 90) {
        $stmt = $this->db->prepare("
            DELETE FROM audit_logs 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }
    
    /**
     * Get distinct action types for filter dropdown
     * 
     * @return array
     */
    public function getDistinctActions() {
        $stmt = $this->db->query("
            SELECT DISTINCT action FROM audit_logs ORDER BY action ASC
        ");
        return $stmt->fetchAll();
    }
}
?>