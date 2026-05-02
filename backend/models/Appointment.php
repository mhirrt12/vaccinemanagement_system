<?php
/**
 * Appointment Model - Handles all database operations related to appointments
 * 
 * Tables: appointments
 * 
 * Responsibilities:
 * - Schedule new appointments for children
 * - Retrieve appointments by child, nurse, or ID
 * - Update appointment status (pending, completed, missed, rescheduled, cancelled)
 * - Request reschedule (parent action)
 * - Approve/reject reschedule requests (nurse action)
 * - Mark missed appointments (cron job)
 * - Get upcoming appointments for reminders
 */

require_once __DIR__ . '/../config/database.php';

class Appointment {
    private $db;
    
    public function __construct() {
        $this->db = Database::getConnection();
    }
    
    /**
     * Schedule a new appointment for a child
     * 
     * @param int $childId
     * @param int $vaccineId
     * @param string $scheduledDate (Y-m-d)
     * @return bool
     */
    public function schedule($childId, $vaccineId, $scheduledDate) {
        // Check if appointment already exists for this child and vaccine
        $stmt = $this->db->prepare("
            SELECT id FROM appointments 
            WHERE child_id = ? AND vaccine_id = ? AND status IN ('pending', 'rescheduled')
        ");
        $stmt->execute([$childId, $vaccineId]);
        if ($stmt->fetch()) {
            return false; // Already scheduled pending appointment
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO appointments (child_id, vaccine_id, scheduled_date, status)
            VALUES (?, ?, ?, 'pending')
        ");
        return $stmt->execute([$childId, $vaccineId, $scheduledDate]);
    }
    
    /**
     * Get all appointments for a specific child
     * 
     * @param int $childId
     * @return array
     */
    public function getByChild($childId) {
        $stmt = $this->db->prepare("
            SELECT a.*, v.name as vaccine_name, v.days_from_birth
            FROM appointments a
            JOIN vaccines v ON a.vaccine_id = v.id
            WHERE a.child_id = ?
            ORDER BY a.scheduled_date ASC
        ");
        $stmt->execute([$childId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get a single appointment by ID
     * 
     * @param int $appointmentId
     * @return array|false
     */
    public function getById($appointmentId) {
        $stmt = $this->db->prepare("
            SELECT a.*, v.name as vaccine_name, c.name as child_name, c.parent_id
            FROM appointments a
            JOIN vaccines v ON a.vaccine_id = v.id
            JOIN children c ON a.child_id = c.id
            WHERE a.id = ?
        ");
        $stmt->execute([$appointmentId]);
        return $stmt->fetch();
    }
    
    /**
     * Get appointments by child and status
     * 
     * @param int $childId
     * @param string $status (pending, completed, missed, rescheduled, cancelled)
     * @return array
     */
    public function getByChildAndStatus($childId, $status) {
        $stmt = $this->db->prepare("
            SELECT a.*, v.name as vaccine_name
            FROM appointments a
            JOIN vaccines v ON a.vaccine_id = v.id
            WHERE a.child_id = ? AND a.status = ?
            ORDER BY a.scheduled_date ASC
        ");
        $stmt->execute([$childId, $status]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get pending appointments for a child (not completed, not missed)
     * 
     * @param int $childId
     * @return array
     */
    public function getPendingByChild($childId) {
        $stmt = $this->db->prepare("
            SELECT a.*, v.name as vaccine_name
            FROM appointments a
            JOIN vaccines v ON a.vaccine_id = v.id
            WHERE a.child_id = ? AND a.status IN ('pending', 'rescheduled')
            ORDER BY a.scheduled_date ASC
        ");
        $stmt->execute([$childId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get upcoming appointments for a nurse (assigned children)
     * 
     * @param int $nurseId
     * @param int $daysAhead (default 7 days)
     * @return array
     */
    public function getUpcomingByNurse($nurseId, $daysAhead = 7) {
        $endDate = date('Y-m-d', strtotime("+$daysAhead days"));
        $stmt = $this->db->prepare("
            SELECT a.*, c.name as child_name, c.unique_child_id, v.name as vaccine_name,
                   u.name as parent_name, u.phone as parent_phone
            FROM appointments a
            JOIN children c ON a.child_id = c.id
            JOIN vaccines v ON a.vaccine_id = v.id
            JOIN nurse_assignments na ON c.id = na.child_id
            JOIN users u ON c.parent_id = u.id
            WHERE na.nurse_id = ? 
              AND a.status = 'pending'
              AND a.scheduled_date BETWEEN CURDATE() AND ?
            ORDER BY a.scheduled_date ASC
        ");
        $stmt->execute([$nurseId, $endDate]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get appointments scheduled for a specific date range
     * 
     * @param string $startDate
     * @param string $endDate
     * @param int|null $nurseId
     * @return array
     */
    public function getByDateRange($startDate, $endDate, $nurseId = null) {
        $sql = "
            SELECT a.*, c.name as child_name, v.name as vaccine_name, u.name as parent_name
            FROM appointments a
            JOIN children c ON a.child_id = c.id
            JOIN vaccines v ON a.vaccine_id = v.id
            JOIN users u ON c.parent_id = u.id
            WHERE a.scheduled_date BETWEEN ? AND ?
        ";
        $params = [$startDate, $endDate];
        
        if ($nurseId) {
            $sql .= " AND c.id IN (SELECT child_id FROM nurse_assignments WHERE nurse_id = ?)";
            $params[] = $nurseId;
        }
        
        $sql .= " ORDER BY a.scheduled_date ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * Update appointment status
     * 
     * @param int $appointmentId
     * @param string $status (pending, completed, missed, rescheduled, cancelled)
     * @param string|null $givenDate
     * @param string|null $batchNumber
     * @param int|null $nurseId
     * @return bool
     */
    public function updateStatus($appointmentId, $status, $givenDate = null, $batchNumber = null, $nurseId = null) {
        $stmt = $this->db->prepare("
            UPDATE appointments 
            SET status = ?, given_date = ?, batch_number = ?, nurse_id = ?
            WHERE id = ?
        ");
        return $stmt->execute([$status, $givenDate, $batchNumber, $nurseId, $appointmentId]);
    }
    
    /**
     * Request reschedule (parent action)
     * 
     * @param int $appointmentId
     * @param string $newDate
     * @return bool
     */
    public function requestReschedule($appointmentId, $newDate) {
        $stmt = $this->db->prepare("
            UPDATE appointments 
            SET reschedule_request_date = ?, status = 'rescheduled', reschedule_approved = FALSE
            WHERE id = ? AND status = 'pending'
        ");
        return $stmt->execute([$newDate, $appointmentId]);
    }
    
    /**
     * Approve a reschedule request (nurse action)
     * 
     * @param int $appointmentId
     * @return bool
     */
    public function approveReschedule($appointmentId) {
        $stmt = $this->db->prepare("
            UPDATE appointments 
            SET scheduled_date = reschedule_request_date, 
                reschedule_request_date = NULL, 
                reschedule_approved = TRUE,
                status = 'pending'
            WHERE id = ? AND status = 'rescheduled'
        ");
        return $stmt->execute([$appointmentId]);
    }
    
    /**
     * Reject a reschedule request (nurse action)
     * 
     * @param int $appointmentId
     * @return bool
     */
    public function rejectReschedule($appointmentId) {
        $stmt = $this->db->prepare("
            UPDATE appointments 
            SET reschedule_request_date = NULL, 
                reschedule_approved = FALSE,
                status = 'pending'
            WHERE id = ? AND status = 'rescheduled'
        ");
        return $stmt->execute([$appointmentId]);
    }
    
    /**
     * Mark appointments as missed (automatically, for cron job)
     * 
     * @return int Number of appointments marked as missed
     */
    public function markMissedAppointments() {
        $stmt = $this->db->prepare("
            UPDATE appointments 
            SET status = 'missed' 
            WHERE status = 'pending' AND scheduled_date < CURDATE()
        ");
        $stmt->execute();
        return $stmt->rowCount();
    }
    
    /**
     * Get number of completed appointments for a child
     * 
     * @param int $childId
     * @return int
     */
    public function countCompletedByChild($childId) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count FROM appointments 
            WHERE child_id = ? AND status = 'completed'
        ");
        $stmt->execute([$childId]);
        return (int)$stmt->fetch()['count'];
    }
    
    /**
     * Get the next upcoming appointment for a child
     * 
     * @param int $childId
     * @return array|false
     */
    public function getNextAppointment($childId) {
        $stmt = $this->db->prepare("
            SELECT a.*, v.name as vaccine_name
            FROM appointments a
            JOIN vaccines v ON a.vaccine_id = v.id
            WHERE a.child_id = ? AND a.status = 'pending' AND a.scheduled_date >= CURDATE()
            ORDER BY a.scheduled_date ASC
            LIMIT 1
        ");
        $stmt->execute([$childId]);
        return $stmt->fetch();
    }
    
    /**
     * Get total appointments count by status (for dashboard)
     * 
     * @param string|null $status
     * @return int
     */
    public function countByStatus($status = null) {
        if ($status) {
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM appointments WHERE status = ?");
            $stmt->execute([$status]);
        } else {
            $stmt = $this->db->query("SELECT COUNT(*) as count FROM appointments");
        }
        return (int)$stmt->fetch()['count'];
    }
    
    /**
     * Get appointments that need reminders (scheduled for tomorrow, reminder not sent)
     * 
     * @return array
     */
    public function getAppointmentsForReminder() {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $stmt = $this->db->prepare("
            SELECT a.*, c.parent_id, c.name as child_name, v.name as vaccine_name, u.email, u.phone
            FROM appointments a
            JOIN children c ON a.child_id = c.id
            JOIN users u ON c.parent_id = u.id
            JOIN vaccines v ON a.vaccine_id = v.id
            WHERE a.scheduled_date = ? AND a.status = 'pending' AND a.reminder_sent = FALSE
        ");
        $stmt->execute([$tomorrow]);
        return $stmt->fetchAll();
    }
    
    /**
     * Mark reminder as sent for an appointment
     * 
     * @param int $appointmentId
     * @return bool
     */
    public function markReminderSent($appointmentId) {
        $stmt = $this->db->prepare("UPDATE appointments SET reminder_sent = TRUE WHERE id = ?");
        return $stmt->execute([$appointmentId]);
    }
}
?>