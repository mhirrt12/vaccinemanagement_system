<?php
/**
 * Notification Model - Handles all database operations related to notifications
 * 
 * Tables: notifications
 * 
 * Responsibilities:
 * - Create new notification for a user
 * - Retrieve notifications for a user (with pagination)
 * - Get unread notification count
 * - Mark single notification as read
 * - Mark all notifications as read
 * - Delete notification
 * - Delete all notifications for a user
 */

require_once __DIR__ . '/../config/database.php';

class Notification {
    private $db;
    
    public function __construct() {
        $this->db = Database::getConnection();
    }
    
    /**
     * Create a new notification
     * 
     * @param int $userId
     * @param int|null $childId (optional, related child)
     * @param string $title
     * @param string $message
     * @param string $type (appointment_reminder, approval, stock_alert, expiry_alert, certificate_ready, report)
     * @return bool
     */
    public function create($userId, $childId, $title, $message, $type) {
        $stmt = $this->db->prepare("
            INSERT INTO notifications (user_id, child_id, title, message, type, is_read)
            VALUES (?, ?, ?, ?, ?, FALSE)
        ");
        return $stmt->execute([$userId, $childId, $title, $message, $type]);
    }
    
    /**
     * Get notifications for a specific user (with pagination)
     * 
     * @param int $userId
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getByUser($userId, $limit = 50, $offset = 0) {
        $stmt = $this->db->prepare("
            SELECT n.*, c.name as child_name 
            FROM notifications n
            LEFT JOIN children c ON n.child_id = c.id
            WHERE n.user_id = ?
            ORDER BY n.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$userId, $limit, $offset]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get unread notifications for a specific user
     * 
     * @param int $userId
     * @return array
     */
    public function getUnreadByUser($userId) {
        $stmt = $this->db->prepare("
            SELECT n.*, c.name as child_name 
            FROM notifications n
            LEFT JOIN children c ON n.child_id = c.id
            WHERE n.user_id = ? AND n.is_read = FALSE
            ORDER BY n.created_at DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get unread notification count for a user
     * 
     * @param int $userId
     * @return int
     */
    public function getUnreadCount($userId) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count FROM notifications 
            WHERE user_id = ? AND is_read = FALSE
        ");
        $stmt->execute([$userId]);
        return (int)$stmt->fetch()['count'];
    }
    
    /**
     * Get a single notification by ID (verify ownership)
     * 
     * @param int $notificationId
     * @param int $userId
     * @return array|false
     */
    public function getById($notificationId, $userId) {
        $stmt = $this->db->prepare("
            SELECT n.*, c.name as child_name 
            FROM notifications n
            LEFT JOIN children c ON n.child_id = c.id
            WHERE n.id = ? AND n.user_id = ?
        ");
        $stmt->execute([$notificationId, $userId]);
        return $stmt->fetch();
    }
    
    /**
     * Mark a notification as read (verify ownership)
     * 
     * @param int $notificationId
     * @param int $userId
     * @return bool
     */
    public function markAsRead($notificationId, $userId) {
        $stmt = $this->db->prepare("
            UPDATE notifications 
            SET is_read = TRUE 
            WHERE id = ? AND user_id = ? AND is_read = FALSE
        ");
        return $stmt->execute([$notificationId, $userId]);
    }
    
    /**
     * Mark all notifications as read for a user
     * 
     * @param int $userId
     * @return int Number of affected rows
     */
    public function markAllAsRead($userId) {
        $stmt = $this->db->prepare("
            UPDATE notifications 
            SET is_read = TRUE 
            WHERE user_id = ? AND is_read = FALSE
        ");
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }
    
    /**
     * Delete a specific notification (verify ownership)
     * 
     * @param int $notificationId
     * @param int $userId
     * @return bool
     */
    public function delete($notificationId, $userId) {
        $stmt = $this->db->prepare("
            DELETE FROM notifications 
            WHERE id = ? AND user_id = ?
        ");
        return $stmt->execute([$notificationId, $userId]);
    }
    
    /**
     * Delete all notifications for a user
     * 
     * @param int $userId
     * @return int Number of deleted rows
     */
    public function deleteAllForUser($userId) {
        $stmt = $this->db->prepare("DELETE FROM notifications WHERE user_id = ?");
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }
    
    /**
     * Create bulk notifications for multiple users (e.g., broadcast)
     * 
     * @param array $userIds
     * @param string $title
     * @param string $message
     * @param string $type
     * @return int Number of inserted rows
     */
    public function createBulk($userIds, $title, $message, $type) {
        $inserted = 0;
        $stmt = $this->db->prepare("
            INSERT INTO notifications (user_id, child_id, title, message, type, is_read)
            VALUES (?, NULL, ?, ?, ?, FALSE)
        ");
        foreach ($userIds as $userId) {
            if ($stmt->execute([$userId, $title, $message, $type])) {
                $inserted++;
            }
        }
        return $inserted;
    }
    
    /**
     * Get recent notifications for dashboard widget
     * 
     * @param int $userId
     * @param int $limit
     * @return array
     */
    public function getRecent($userId, $limit = 5) {
        $stmt = $this->db->prepare("
            SELECT n.*, c.name as child_name 
            FROM notifications n
            LEFT JOIN children c ON n.child_id = c.id
            WHERE n.user_id = ?
            ORDER BY n.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    }
    
    /**
     * Delete old notifications (older than given days)
     * 
     * @param int $days
     * @return int Number of deleted rows
     */
    public function deleteOld($days = 30) {
        $stmt = $this->db->prepare("
            DELETE FROM notifications 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }
}
?>