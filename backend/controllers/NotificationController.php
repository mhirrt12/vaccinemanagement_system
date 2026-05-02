<?php
/**
 * NotificationController - Handles user notification operations
 * 
 * Responsibilities:
 * - Get all notifications for authenticated user
 * - Get unread notification count
 * - Mark single notification as read
 * - Mark all notifications as read
 * - Delete a notification
 * - Send notification (internal method for other controllers)
 * 
 * Accessible by: All authenticated users (parents, nurses, admin)
 */

require_once __DIR__ . '/../models/Notification.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/Logger.php';
require_once __DIR__ . '/../config/database.php';

class NotificationController {
    private $notificationModel;
    
    public function __construct() {
        $this->notificationModel = new Notification();
    }
    
    /**
     * Get all notifications for the authenticated user
     * GET /api/notifications?limit=50&offset=0
     */
    public function getNotifications() {
        global $authPayload;
        $userId = $authPayload['user_id'];
        
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT n.*, c.name as child_name 
            FROM notifications n
            LEFT JOIN children c ON n.child_id = c.id
            WHERE n.user_id = ?
            ORDER BY n.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$userId, $limit, $offset]);
        $notifications = $stmt->fetchAll();
        
        // Get total count for pagination
        $countStmt = $db->prepare("SELECT COUNT(*) as total FROM notifications WHERE user_id = ?");
        $countStmt->execute([$userId]);
        $total = $countStmt->fetch()['total'];
        
        Response::success([
            'notifications' => $notifications,
            'total' => (int)$total,
            'limit' => $limit,
            'offset' => $offset
        ]);
    }
    
    /**
     * Get count of unread notifications for the authenticated user
     * GET /api/notifications/unread-count
     */
    public function getUnreadCount() {
        global $authPayload;
        $userId = $authPayload['user_id'];
        
        $stmt = Database::getConnection()->prepare("
            SELECT COUNT(*) as count FROM notifications 
            WHERE user_id = ? AND is_read = FALSE
        ");
        $stmt->execute([$userId]);
        $count = $stmt->fetch()['count'];
        
        Response::success(['unread_count' => (int)$count]);
    }
    
    /**
     * Get a single notification by ID
     * GET /api/notifications/{id}
     */
    public function getNotification($id) {
        global $authPayload;
        $userId = $authPayload['user_id'];
        
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT n.*, c.name as child_name 
            FROM notifications n
            LEFT JOIN children c ON n.child_id = c.id
            WHERE n.id = ? AND n.user_id = ?
        ");
        $stmt->execute([$id, $userId]);
        $notification = $stmt->fetch();
        
        if (!$notification) {
            Response::notFound('Notification not found');
            return;
        }
        
        Response::success($notification);
    }
    
    /**
     * Mark a specific notification as read
     * POST /api/notifications/{id}/read
     */
    public function markAsRead($id) {
        global $authPayload;
        $userId = $authPayload['user_id'];
        
        $result = $this->notificationModel->markAsRead($id, $userId);
        if ($result) {
            Logger::info("Notification marked as read", $userId, ['notification_id' => $id]);
            Response::success(null, 'Notification marked as read');
        } else {
            Response::notFound('Notification not found or already read');
        }
    }
    
    /**
     * Mark all notifications as read for the authenticated user
     * POST /api/notifications/mark-all-read
     */
    public function markAllAsRead() {
        global $authPayload;
        $userId = $authPayload['user_id'];
        
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE notifications 
            SET is_read = TRUE 
            WHERE user_id = ? AND is_read = FALSE
        ");
        $stmt->execute([$userId]);
        $affected = $stmt->rowCount();
        
        Logger::info("All notifications marked as read", $userId, ['count' => $affected]);
        Response::success(['marked_count' => $affected], 'All notifications marked as read');
    }
    
    /**
     * Delete a notification
     * DELETE /api/notifications/{id}
     */
    public function deleteNotification($id) {
        global $authPayload;
        $userId = $authPayload['user_id'];
        
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        
        if ($stmt->rowCount() > 0) {
            Logger::info("Notification deleted", $userId, ['notification_id' => $id]);
            Response::success(null, 'Notification deleted');
        } else {
            Response::notFound('Notification not found');
        }
    }
    
    /**
     * Delete all notifications for the authenticated user
     * DELETE /api/notifications/delete-all
     */
    public function deleteAll() {
        global $authPayload;
        $userId = $authPayload['user_id'];
        
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM notifications WHERE user_id = ?");
        $stmt->execute([$userId]);
        $deleted = $stmt->rowCount();
        
        Logger::info("All notifications deleted", $userId, ['count' => $deleted]);
        Response::success(['deleted_count' => $deleted], 'All notifications deleted');
    }
}
?>