<?php
/**
 * NotificationService - Handles creation and delivery of notifications
 * 
 * Responsibilities:
 * - Create in-app notifications for users
 * - Send email notifications (using mail.php)
 * - Send SMS notifications (placeholder for integration)
 * - Send appointment reminders to parents
 * - Send approval notifications for certificates and accounts
 * - Send stock alerts and expiry alerts to admin
 * - Broadcast notifications to multiple users
 */

require_once __DIR__ . '/../models/Notification.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mail.php';  // For email functions

class NotificationService {
    private $notificationModel;
    
    public function __construct() {
        $this->notificationModel = new Notification();
    }
    
    /**
     * Create an in-app notification for a single user
     * 
     * @param int $userId
     * @param int|null $childId (optional)
     * @param string $title
     * @param string $message
     * @param string $type (appointment_reminder, approval, stock_alert, expiry_alert, certificate_ready, report)
     * @return bool
     */
    public function createNotification($userId, $childId, $title, $message, $type) {
        return $this->notificationModel->create($userId, $childId, $title, $message, $type);
    }
    
    /**
     * Send appointment reminder to parent (in-app + email)
     * 
     * @param int $parentId
     * @param int $childId
     * @param string $childName
     * @param string $vaccineName
     * @param string $scheduledDate
     * @param string $parentEmail
     * @return bool
     */
    public function sendAppointmentReminder($parentId, $childId, $childName, $vaccineName, $scheduledDate, $parentEmail) {
        $title = 'Upcoming Vaccination Appointment';
        $message = "Reminder: {$childName} has {$vaccineName} scheduled for {$scheduledDate}. Please bring your child to the health center.";
        
        // Create in-app notification
        $this->createNotification($parentId, $childId, $title, $message, 'appointment_reminder');
        
        // Send email
        $emailSent = sendAppointmentReminder($parentEmail, $childName, $vaccineName, $scheduledDate);
        
        // In production, also send SMS if phone number available
        // sendSMS($parentPhone, $message);
        
        return $emailSent;
    }
    
    /**
     * Send account approval notification to parent
     * 
     * @param int $parentId
     * @param string $parentName
     * @param string $parentEmail
     * @return bool
     */
    public function sendAccountApprovedNotification($parentId, $parentName, $parentEmail) {
        $title = 'Account Approved';
        $message = "Your account has been verified and approved. You can now log in to view your children's vaccination schedules.";
        
        $this->createNotification($parentId, null, $title, $message, 'approval');
        
        return sendAccountApprovedEmail($parentEmail, $parentName);
    }
    
    /**
     * Send certificate ready notification to parent
     * 
     * @param int $parentId
     * @param int $childId
     * @param string $childName
     * @param string $parentEmail
     * @param string $downloadLink
     * @return bool
     */
    public function sendCertificateReadyNotification($parentId, $childId, $childName, $parentEmail, $downloadLink) {
        $title = 'Vaccination Certificate Ready';
        $message = "The vaccination certificate for {$childName} has been approved and is ready for download.";
        
        $this->createNotification($parentId, $childId, $title, $message, 'certificate_ready');
        
        return sendCertificateReadyEmail($parentEmail, $childName, $downloadLink);
    }
    
    /**
     * Send low stock alert to admin
     * 
     * @param int $adminId
     * @param string $adminEmail
     * @param string $vaccineName
     * @param int $remainingQuantity
     * @return bool
     */
    public function sendLowStockAlert($adminId, $adminEmail, $vaccineName, $remainingQuantity) {
        $title = 'Low Stock Alert';
        $message = "Vaccine {$vaccineName} is running low. Only {$remainingQuantity} doses remaining. Please restock.";
        
        $this->createNotification($adminId, null, $title, $message, 'stock_alert');
        
        return sendLowStockAlert($adminEmail, $vaccineName, $remainingQuantity);
    }
    
    /**
     * Send expiry alert to admin
     * 
     * @param int $adminId
     * @param string $adminEmail
     * @param string $vaccineName
     * @param string $batchNumber
     * @param string $expiryDate
     * @param int $quantity
     * @return bool
     */
    public function sendExpiryAlert($adminId, $adminEmail, $vaccineName, $batchNumber, $expiryDate, $quantity) {
        $title = 'Vaccine Batch Expiring Soon';
        $message = "Batch {$batchNumber} of {$vaccineName} expires on {$expiryDate}. {$quantity} doses remaining.";
        
        $this->createNotification($adminId, null, $title, $message, 'expiry_alert');
        
        return sendExpiryNotification($adminEmail, $vaccineName, $batchNumber, $expiryDate, $quantity);
    }
    
    /**
     * Send report generated notification to admin
     * 
     * @param int $adminId
     * @param string $reportType
     * @param string $period
     * @param string $generatedByName
     * @return bool
     */
    public function sendReportGeneratedNotification($adminId, $reportType, $period, $generatedByName) {
        $title = 'New Report Generated';
        $message = "{$generatedByName} generated a {$reportType} report for period {$period}.";
        
        return $this->createNotification($adminId, null, $title, $message, 'report');
    }
    
    /**
     * Broadcast notification to all users of a specific role
     * 
     * @param string $role (parent, nurse, admin)
     * @param string $title
     * @param string $message
     * @param string $type
     * @return int Number of notifications created
     */
    public function broadcastToRole($role, $title, $message, $type) {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id FROM users WHERE role_id = (SELECT id FROM roles WHERE name = ?)");
        $stmt->execute([$role]);
        $users = $stmt->fetchAll();
        
        $count = 0;
        foreach ($users as $user) {
            if ($this->createNotification($user['id'], null, $title, $message, $type)) {
                $count++;
            }
        }
        
        return $count;
    }
    
    /**
     * Broadcast notification to all users
     * 
     * @param string $title
     * @param string $message
     * @param string $type
     * @return int
     */
    public function broadcastToAll($title, $message, $type) {
        $db = Database::getConnection();
        $stmt = $db->query("SELECT id FROM users");
        $users = $stmt->fetchAll();
        
        $count = 0;
        foreach ($users as $user) {
            if ($this->createNotification($user['id'], null, $title, $message, $type)) {
                $count++;
            }
        }
        
        return $count;
    }
    
    /**
     * Get unread notification count for a user
     * 
     * @param int $userId
     * @return int
     */
    public function getUnreadCount($userId) {
        return $this->notificationModel->getUnreadCount($userId);
    }
    
    /**
     * Get recent notifications for a user
     * 
     * @param int $userId
     * @param int $limit
     * @return array
     */
    public function getRecentNotifications($userId, $limit = 10) {
        return $this->notificationModel->getRecent($userId, $limit);
    }
    
    /**
     * Mark all notifications as read for a user
     * 
     * @param int $userId
     * @return int
     */
    public function markAllAsRead($userId) {
        return $this->notificationModel->markAllAsRead($userId);
    }
}
?>