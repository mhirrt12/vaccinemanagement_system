<?php
/**
 * AppointmentController - Handles all appointment-related operations
 * 
 * Responsibilities:
 * - Generate appointments for a child automatically based on DOB (EPI schedule)
 * - Get appointments for a child (with filtering by status)
 * - Update appointment status (complete, miss, cancel)
 * - Reschedule appointment (with validation and approval workflow)
 * - Batch appointment generation for future dates
 * - Send appointment reminders (integrated with notification service)
 * 
 * Access: 
 * - Parents: view their children's appointments, request reschedule
 * - Nurses: view assigned children's appointments, approve reschedules, mark completed
 * - Admin: full access
 */

require_once __DIR__ . '/../models/Appointment.php';
require_once __DIR__ . '/../models/Child.php';
require_once __DIR__ . '/../models/Vaccine.php';
require_once __DIR__ . '/../models/Notification.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/Validator.php';
require_once __DIR__ . '/../helpers/Logger.php';
require_once __DIR__ . '/../config/database.php';

class AppointmentController {
    private $appointmentModel;
    private $childModel;
    private $vaccineModel;
    private $notificationService;
    
    public function __construct() {
        $this->appointmentModel = new Appointment();
        $this->childModel = new Child();
        $this->vaccineModel = new Vaccine();
        $this->notificationService = new NotificationService();
    }
    
    /**
     * Get all appointments for a specific child (with optional status filter)
     * GET /api/appointments/child/{childId}?status=pending
     */
    public function getByChild($childId) {
        global $authPayload;
        
        // Verify access: parent owns child or nurse is assigned or admin
        $child = $this->childModel->getChildById($childId);
        if (!$child) {
            Response::notFound('Child not found');
            return;
        }
        
        $role = $authPayload['role'];
        $userId = $authPayload['user_id'];
        
        if ($role === 'parent' && $child['parent_id'] != $userId) {
            Response::forbidden('You do not have access to this child');
            return;
        }
        
        if ($role === 'nurse') {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT 1 FROM nurse_assignments WHERE child_id = ? AND nurse_id = ?");
            $stmt->execute([$childId, $userId]);
            if (!$stmt->fetch()) {
                Response::forbidden('This child is not assigned to you');
                return;
            }
        }
        
        $status = $_GET['status'] ?? null;
        $appointments = $this->appointmentModel->getByChild($childId);
        
        if ($status) {
            $appointments = array_filter($appointments, function($app) use ($status) {
                return $app['status'] === $status;
            });
            $appointments = array_values($appointments);
        }
        
        Response::success($appointments);
    }
    
    /**
     * Get a single appointment by ID
     * GET /api/appointments/{id}
     */
    public function getById($id) {
        global $authPayload;
        
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT a.*, c.parent_id, c.name as child_name, v.name as vaccine_name
            FROM appointments a
            JOIN children c ON a.child_id = c.id
            JOIN vaccines v ON a.vaccine_id = v.id
            WHERE a.id = ?
        ");
        $stmt->execute([$id]);
        $appointment = $stmt->fetch();
        
        if (!$appointment) {
            Response::notFound('Appointment not found');
            return;
        }
        
        // Role-based access control
        $role = $authPayload['role'];
        $userId = $authPayload['user_id'];
        
        if ($role === 'parent' && $appointment['parent_id'] != $userId) {
            Response::forbidden('Forbidden');
            return;
        }
        if ($role === 'nurse') {
            $check = $db->prepare("SELECT 1 FROM nurse_assignments WHERE child_id = ? AND nurse_id = ?");
            $check->execute([$appointment['child_id'], $userId]);
            if (!$check->fetch()) {
                Response::forbidden('Forbidden');
                return;
            }
        }
        
        Response::success($appointment);
    }
    
    /**
     * Generate appointments for a child from DOB (used during registration)
     * POST /api/appointments/generate-for-child
     * Input: { "child_id": 123, "dob": "2023-01-01" }
     */
    public function generateForChild() {
        global $authPayload;
        $input = json_decode(file_get_contents('php://input'), true);
        
        $childId = $input['child_id'] ?? 0;
        $dob = $input['dob'] ?? '';
        
        if (!$childId || !$dob) {
            Response::badRequest('Child ID and date of birth are required');
            return;
        }
        
        // Verify child exists
        $child = $this->childModel->getChildById($childId);
        if (!$child) {
            Response::notFound('Child not found');
            return;
        }
        
        // Verify access (nurse or admin can generate)
        $role = $authPayload['role'];
        $userId = $authPayload['user_id'];
        
        if ($role === 'nurse') {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT 1 FROM nurse_assignments WHERE child_id = ? AND nurse_id = ?");
            $stmt->execute([$childId, $userId]);
            if (!$stmt->fetch()) {
                Response::forbidden('This child is not assigned to you');
                return;
            }
        } elseif ($role !== 'admin') {
            Response::forbidden('Only nurses or admin can generate appointments');
            return;
        }
        
        // Generate appointments
        $scheduled = $this->generateAppointmentsFromDOB($childId, $dob);
        
        Logger::info("Appointments generated", $userId, ['child_id' => $childId, 'count' => $scheduled]);
        Response::success(['generated_count' => $scheduled], 'Appointments generated successfully');
    }
    
    /**
     * Helper: Generate all appointments for a child based on DOB
     */
    private function generateAppointmentsFromDOB($childId, $dob) {
        $vaccines = $this->vaccineModel->getAll();
        $db = Database::getConnection();
        $scheduled = 0;
        
        foreach ($vaccines as $vaccine) {
            $dueDate = date('Y-m-d', strtotime($dob . ' + ' . $vaccine['days_from_birth'] . ' days'));
            // Check if appointment already exists
            $stmt = $db->prepare("SELECT id FROM appointments WHERE child_id = ? AND vaccine_id = ?");
            $stmt->execute([$childId, $vaccine['id']]);
            if (!$stmt->fetch()) {
                $this->appointmentModel->schedule($childId, $vaccine['id'], $dueDate);
                $scheduled++;
            }
        }
        return $scheduled;
    }
    
    /**
     * Update appointment status (complete, miss, cancel)
     * PUT /api/appointments/{id}/status
     * Input: { "status": "completed", "notes": "Given in left arm", "batch_number": "BCG001" }
     */
    public function updateStatus($id) {
        global $authPayload;
        $input = json_decode(file_get_contents('php://input'), true);
        
        $newStatus = $input['status'] ?? '';
        $notes = $input['notes'] ?? null;
        $batchNumber = $input['batch_number'] ?? null;
        
        $allowedStatuses = ['pending', 'completed', 'missed', 'rescheduled', 'cancelled'];
        if (!in_array($newStatus, $allowedStatuses)) {
            Response::badRequest('Invalid status');
            return;
        }
        
        // Get appointment details
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT a.*, c.parent_id 
            FROM appointments a
            JOIN children c ON a.child_id = c.id
            WHERE a.id = ?
        ");
        $stmt->execute([$id]);
        $appointment = $stmt->fetch();
        
        if (!$appointment) {
            Response::notFound('Appointment not found');
            return;
        }
        
        $role = $authPayload['role'];
        $userId = $authPayload['user_id'];
        
        // Only nurse or admin can change status to completed/missed
        if ($newStatus === 'completed' || $newStatus === 'missed') {
            if ($role !== 'nurse' && $role !== 'admin') {
                Response::forbidden('Only nurses or admin can mark appointments as completed or missed');
                return;
            }
            // Verify nurse is assigned to this child
            if ($role === 'nurse') {
                $check = $db->prepare("SELECT 1 FROM nurse_assignments WHERE child_id = ? AND nurse_id = ?");
                $check->execute([$appointment['child_id'], $userId]);
                if (!$check->fetch()) {
                    Response::forbidden('You are not assigned to this child');
                    return;
                }
            }
        } else {
            // Parents can only cancel/reschedule their own appointments
            if ($role === 'parent' && $appointment['parent_id'] != $userId) {
                Response::forbidden('Forbidden');
                return;
            }
        }
        
        $givenDate = ($newStatus === 'completed') ? date('Y-m-d') : null;
        $updateResult = $this->appointmentModel->updateStatus($id, $newStatus, $givenDate, $batchNumber, $userId);
        
        if (!$updateResult) {
            Response::internalError('Failed to update appointment status');
            return;
        }
        
        // Add notes if provided
        if ($notes) {
            $db->prepare("UPDATE appointments SET notes = CONCAT(IFNULL(notes, ''), ?) WHERE id = ?")
               ->execute(["\n[" . date('Y-m-d H:i:s') . "] " . ucfirst($role) . ": " . $notes, $id]);
        }
        
        // Notify parent if status changed to completed or missed
        if ($newStatus === 'completed' || $newStatus === 'missed') {
            $child = $this->childModel->getChildById($appointment['child_id']);
            $vaccine = $this->vaccineModel->getById($appointment['vaccine_id']);
            $statusText = $newStatus === 'completed' ? 'has been administered' : 'was missed';
            $this->notificationService->createNotification(
                $appointment['parent_id'],
                $appointment['child_id'],
                "Appointment {$newStatus}",
                "Vaccination for {$child['name']} ({$vaccine['name']}) {$statusText} on " . date('Y-m-d'),
                'appointment_reminder'
            );
        }
        
        Logger::info("Appointment status updated", $userId, ['appointment_id' => $id, 'status' => $newStatus]);
        Response::success(null, "Appointment marked as {$newStatus}");
    }
    
    /**
     * Request reschedule (parent action)
     * POST /api/appointments/{id}/request-reschedule
     * Input: { "new_date": "2025-06-20" }
     */
    public function requestReschedule($id) {
        global $authPayload;
        $input = json_decode(file_get_contents('php://input'), true);
        $newDate = $input['new_date'] ?? '';
        
        if (!Validator::required($newDate) || !Validator::futureDate($newDate)) {
            Response::badRequest('Valid future date is required');
            return;
        }
        
        $userId = $authPayload['user_id'];
        
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT a.*, c.parent_id, c.name as child_name, v.name as vaccine_name
            FROM appointments a
            JOIN children c ON a.child_id = c.id
            JOIN vaccines v ON a.vaccine_id = v.id
            WHERE a.id = ?
        ");
        $stmt->execute([$id]);
        $appointment = $stmt->fetch();
        
        if (!$appointment) {
            Response::notFound('Appointment not found');
            return;
        }
        
        // Verify parent owns the child
        if ($appointment['parent_id'] != $userId) {
            Response::forbidden('You can only reschedule your own children\'s appointments');
            return;
        }
        
        if ($appointment['status'] !== 'pending') {
            Response::badRequest('Only pending appointments can be rescheduled');
            return;
        }
        
        // Check max 5 days change from original scheduled date
        $originalDate = new DateTime($appointment['scheduled_date']);
        $requestedDate = new DateTime($newDate);
        $diffDays = abs($requestedDate->diff($originalDate)->days);
        
        if ($diffDays > 5) {
            Response::badRequest('You can only reschedule within 5 days of the original appointment date');
            return;
        }
        
        // Update appointment with reschedule request
        $result = $this->appointmentModel->requestReschedule($id, $newDate);
        
        if (!$result) {
            Response::internalError('Failed to submit reschedule request');
            return;
        }
        
        // Notify assigned nurse
        $nurseStmt = $db->prepare("SELECT nurse_id FROM nurse_assignments WHERE child_id = ?");
        $nurseStmt->execute([$appointment['child_id']]);
        $nurse = $nurseStmt->fetch();
        if ($nurse) {
            $this->notificationService->createNotification(
                $nurse['nurse_id'],
                $appointment['child_id'],
                'Reschedule Request',
                "Parent requested to reschedule {$appointment['vaccine_name']} for {$appointment['child_name']} from {$appointment['scheduled_date']} to {$newDate}",
                'appointment_reminder'
            );
        }
        
        Logger::info("Reschedule requested", $userId, ['appointment_id' => $id, 'new_date' => $newDate]);
        Response::success(null, 'Reschedule request submitted. Nurse will review and approve.');
    }
    
    /**
     * Approve or reject a reschedule request (nurse action)
     * POST /api/appointments/{id}/approve-reschedule
     * Input: { "approved": true }
     */
    public function approveReschedule($id) {
        global $authPayload;
        $input = json_decode(file_get_contents('php://input'), true);
        $approved = $input['approved'] ?? false;
        
        $nurseId = $authPayload['user_id'];
        
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT a.*, c.parent_id, c.name as child_name, v.name as vaccine_name
            FROM appointments a
            JOIN children c ON a.child_id = c.id
            JOIN vaccines v ON a.vaccine_id = v.id
            WHERE a.id = ? AND a.status = 'rescheduled'
        ");
        $stmt->execute([$id]);
        $appointment = $stmt->fetch();
        
        if (!$appointment) {
            Response::notFound('No pending reschedule request for this appointment');
            return;
        }
        
        // Verify nurse is assigned to this child
        $check = $db->prepare("SELECT 1 FROM nurse_assignments WHERE child_id = ? AND nurse_id = ?");
        $check->execute([$appointment['child_id'], $nurseId]);
        if (!$check->fetch()) {
            Response::forbidden('You are not assigned to this child');
            return;
        }
        
        if ($approved) {
            // Approve: set scheduled_date = request_date, clear request fields
            $newDate = $appointment['reschedule_request_date'];
            $db->prepare("
                UPDATE appointments 
                SET scheduled_date = ?, status = 'pending', reschedule_request_date = NULL, reschedule_approved = TRUE
                WHERE id = ?
            ")->execute([$newDate, $id]);
            
            $this->notificationService->createNotification(
                $appointment['parent_id'],
                $appointment['child_id'],
                'Reschedule Approved',
                "Your request to reschedule {$appointment['vaccine_name']} for {$appointment['child_name']} has been approved. New date: {$newDate}",
                'appointment_reminder'
            );
            $message = 'Reschedule request approved';
        } else {
            // Reject: clear request fields, keep original date, set status back to pending
            $db->prepare("
                UPDATE appointments 
                SET status = 'pending', reschedule_request_date = NULL, reschedule_approved = FALSE
                WHERE id = ?
            ")->execute([$id]);
            
            $this->notificationService->createNotification(
                $appointment['parent_id'],
                $appointment['child_id'],
                'Reschedule Rejected',
                "Your request to reschedule {$appointment['vaccine_name']} for {$appointment['child_name']} has been rejected. Please contact the health center.",
                'appointment_reminder'
            );
            $message = 'Reschedule request rejected';
        }
        
        Logger::info("Reschedule " . ($approved ? "approved" : "rejected"), $nurseId, ['appointment_id' => $id]);
        Response::success(null, $message);
    }
    
    /**
     * Mark missed appointments automatically (cron job endpoint)
     * POST /api/appointments/mark-missed
     */
    public function markMissed() {
        global $authPayload;
        
        // Allow system or admin to call this
        if ($authPayload['role'] !== 'admin') {
            Response::forbidden('Only admin can trigger this');
            return;
        }
        
        $updated = $this->appointmentModel->markMissedAppointments();
        Logger::info("Marked missed appointments", $authPayload['user_id'], ['count' => $updated]);
        Response::success(['missed_count' => $updated], 'Missed appointments updated');
    }
    
    /**
     * Send appointment reminders for tomorrow (cron job endpoint)
     * POST /api/appointments/send-reminders
     */
    public function sendReminders() {
        global $authPayload;
        
        if ($authPayload['role'] !== 'admin') {
            Response::forbidden('Only admin can trigger this');
            return;
        }
        
        $db = Database::getConnection();
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        
        // Get appointments scheduled for tomorrow that haven't had reminder sent
        $stmt = $db->prepare("
            SELECT a.*, c.parent_id, c.name as child_name, v.name as vaccine_name, u.email, u.phone
            FROM appointments a
            JOIN children c ON a.child_id = c.id
            JOIN users u ON c.parent_id = u.id
            JOIN vaccines v ON a.vaccine_id = v.id
            WHERE a.scheduled_date = ? AND a.status = 'pending' AND a.reminder_sent = FALSE
        ");
        $stmt->execute([$tomorrow]);
        $appointments = $stmt->fetchAll();
        
        $sent = 0;
        foreach ($appointments as $app) {
            // Create in-app notification
            $this->notificationService->createNotification(
                $app['parent_id'],
                $app['child_id'],
                'Upcoming Vaccination',
                "Reminder: {$app['child_name']} has {$app['vaccine_name']} scheduled for tomorrow ({$tomorrow}).",
                'appointment_reminder'
            );
            
            // Mark reminder as sent
            $db->prepare("UPDATE appointments SET reminder_sent = TRUE WHERE id = ?")->execute([$app['id']]);
            
            // In production: also send email/SMS using mail.php
            $sent++;
        }
        
        Logger::info("Sent appointment reminders", $authPayload['user_id'], ['count' => $sent]);
        Response::success(['reminders_sent' => $sent], 'Reminders sent successfully');
    }
}
?>