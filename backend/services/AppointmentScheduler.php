<?php
/**
 * AppointmentScheduler Service - Handles automatic appointment generation and management
 * 
 * Responsibilities:
 * - Generate all appointments for a child based on DOB and EPI schedule
 * - Update missed appointments (daily cron)
 * - Reschedule appointments when requested and approved
 * - Send appointment reminders (integrated with NotificationService)
 * - Prevent duplicate scheduling
 * - Recalculate schedule if child's DOB is updated
 */

require_once __DIR__ . '/../models/Vaccine.php';
require_once __DIR__ . '/../models/Appointment.php';
require_once __DIR__ . '/../models/Child.php';
require_once __DIR__ . '/../models/Notification.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../helpers/Logger.php';

class AppointmentScheduler {
    private $vaccineModel;
    private $appointmentModel;
    private $childModel;
    private $notificationService;
    
    public function __construct() {
        $this->vaccineModel = new Vaccine();
        $this->appointmentModel = new Appointment();
        $this->childModel = new Child();
        $this->notificationService = new NotificationService();
    }
    
    /**
     * Generate all appointments for a child based on their date of birth
     * This is called when a child is registered or when DOB is updated
     * 
     * @param int $childId
     * @param string $dob (Y-m-d)
     * @param bool $overwrite Whether to delete existing pending appointments
     * @return int Number of appointments generated
     */
    public function generateScheduleForChild($childId, $dob, $overwrite = true) {
        // Delete existing pending appointments if overwrite is true
        if ($overwrite) {
            $this->deletePendingAppointments($childId);
        }
        
        // Get all active vaccines
        $vaccines = $this->vaccineModel->getAll();
        $generated = 0;
        
        foreach ($vaccines as $vaccine) {
            // Calculate due date based on DOB and days from birth
            $dueDate = date('Y-m-d', strtotime($dob . ' + ' . $vaccine['days_from_birth'] . ' days'));
            
            // Check if appointment already exists (to avoid duplicates)
            if (!$this->appointmentExists($childId, $vaccine['id'])) {
                $this->appointmentModel->schedule($childId, $vaccine['id'], $dueDate);
                $generated++;
            }
        }
        
        Logger::info("Appointments generated for child", null, [
            'child_id' => $childId,
            'generated_count' => $generated
        ]);
        
        return $generated;
    }
    
    /**
     * Delete all pending appointments for a child (used when regenerating schedule)
     * 
     * @param int $childId
     * @return int Number of deleted appointments
     */
    public function deletePendingAppointments($childId) {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            DELETE FROM appointments 
            WHERE child_id = ? AND status IN ('pending', 'rescheduled')
        ");
        $stmt->execute([$childId]);
        $deleted = $stmt->rowCount();
        
        Logger::info("Pending appointments deleted", null, [
            'child_id' => $childId,
            'deleted_count' => $deleted
        ]);
        
        return $deleted;
    }
    
    /**
     * Check if an appointment already exists for a child and vaccine
     * 
     * @param int $childId
     * @param int $vaccineId
     * @return bool
     */
    public function appointmentExists($childId, $vaccineId) {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT id FROM appointments 
            WHERE child_id = ? AND vaccine_id = ? AND status IN ('pending', 'rescheduled')
        ");
        $stmt->execute([$childId, $vaccineId]);
        return $stmt->fetch() !== false;
    }
    
    /**
     * Mark appointments that are past due as 'missed'
     * This should be run daily via cron job
     * 
     * @return int Number of appointments marked as missed
     */
    public function markMissedAppointments() {
        $missedCount = $this->appointmentModel->markMissedAppointments();
        
        if ($missedCount > 0) {
            Logger::info("Missed appointments marked", null, ['count' => $missedCount]);
        }
        
        return $missedCount;
    }
    
    /**
     * Send appointment reminders for appointments scheduled for tomorrow
     * This should be run daily via cron job
     * 
     * @return int Number of reminders sent
     */
    public function sendAppointmentReminders() {
        $appointments = $this->appointmentModel->getAppointmentsForReminder();
        $sent = 0;
        
        foreach ($appointments as $app) {
            // Create in-app notification for parent
            $this->notificationService->createNotification(
                $app['parent_id'],
                $app['child_id'],
                'Upcoming Vaccination Reminder',
                "Reminder: {$app['child_name']} has {$app['vaccine_name']} scheduled for tomorrow ({$app['scheduled_date']}). Please bring your child to the health center.",
                'appointment_reminder'
            );
            
            // Mark reminder as sent
            $this->appointmentModel->markReminderSent($app['id']);
            $sent++;
        }
        
        if ($sent > 0) {
            Logger::info("Appointment reminders sent", null, ['count' => $sent]);
        }
        
        return $sent;
    }
    
    /**
     * Get upcoming appointments for a specific child (next 30 days)
     * 
     * @param int $childId
     * @return array
     */
    public function getUpcomingForChild($childId) {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT a.*, v.name as vaccine_name
            FROM appointments a
            JOIN vaccines v ON a.vaccine_id = v.id
            WHERE a.child_id = ? 
              AND a.status = 'pending'
              AND a.scheduled_date >= CURDATE()
              AND a.scheduled_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            ORDER BY a.scheduled_date ASC
        ");
        $stmt->execute([$childId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get overdue appointments for a child
     * 
     * @param int $childId
     * @return array
     */
    public function getOverdueForChild($childId) {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT a.*, v.name as vaccine_name,
                   DATEDIFF(CURDATE(), a.scheduled_date) as days_overdue
            FROM appointments a
            JOIN vaccines v ON a.vaccine_id = v.id
            WHERE a.child_id = ? 
              AND a.status = 'pending'
              AND a.scheduled_date < CURDATE()
            ORDER BY a.scheduled_date ASC
        ");
        $stmt->execute([$childId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Reschedule an appointment (parent request, needs nurse approval)
     * 
     * @param int $appointmentId
     * @param string $newDate
     * @return bool
     */
    public function requestReschedule($appointmentId, $newDate) {
        return $this->appointmentModel->requestReschedule($appointmentId, $newDate);
    }
    
    /**
     * Approve a reschedule request (nurse action)
     * 
     * @param int $appointmentId
     * @return bool
     */
    public function approveReschedule($appointmentId) {
        return $this->appointmentModel->approveReschedule($appointmentId);
    }
    
    /**
     * Reject a reschedule request (nurse action)
     * 
     * @param int $appointmentId
     * @return bool
     */
    public function rejectReschedule($appointmentId) {
        return $this->appointmentModel->rejectReschedule($appointmentId);
    }
    
    /**
     * Get vaccination progress for a child (percentage completed)
     * 
     * @param int $childId
     * @return float
     */
    public function getProgressPercentage($childId) {
        $totalVaccines = $this->vaccineModel->getTotalVaccinesInSchedule();
        $completed = $this->appointmentModel->countCompletedByChild($childId);
        
        if ($totalVaccines == 0) return 0;
        return round(($completed / $totalVaccines) * 100, 2);
    }
    
    /**
     * Get next scheduled appointment for a child
     * 
     * @param int $childId
     * @return array|null
     */
    public function getNextAppointment($childId) {
        return $this->appointmentModel->getNextAppointment($childId);
    }
    
    /**
     * Regenerate schedule for all children (admin operation)
     * Useful if EPI schedule changes
     * 
     * @return array Result with processed and updated counts
     */
    public function regenerateAllSchedules() {
        $children = $this->childModel->getAllChildren(10000, 0);
        $processed = 0;
        $updated = 0;
        
        foreach ($children as $child) {
            $processed++;
            // Regenerate schedule (overwrite pending)
            $generated = $this->generateScheduleForChild($child['id'], $child['dob'], true);
            if ($generated > 0) {
                $updated++;
            }
        }
        
        Logger::info("All schedules regenerated", null, [
            'processed' => $processed,
            'updated' => $updated
        ]);
        
        return [
            'processed_children' => $processed,
            'updated_schedules' => $updated
        ];
    }
}
?>