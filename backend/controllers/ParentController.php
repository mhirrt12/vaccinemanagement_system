<?php
/**
 * ParentController - Handles all parent-specific operations
 * 
 * Responsibilities:
 * - View children profiles
 * - View vaccination roadmap (schedule)
 * - Request appointment reschedule (max 5 days change, requires nurse approval)
 * - Download vaccination certificate (after nurse + admin approval)
 * - Receive notifications
 * - View assigned nurse details
 * 
 * All methods require authentication and parent role.
 */

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Child.php';
require_once __DIR__ . '/../models/Appointment.php';
require_once __DIR__ . '/../models/Vaccine.php';
require_once __DIR__ . '/../models/Notification.php';
require_once __DIR__ . '/../models/Certificate.php';
require_once __DIR__ . '/../models/NurseAssignment.php';
require_once __DIR__ . '/../services/AppointmentScheduler.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/Logger.php';
require_once __DIR__ . '/../helpers/Validator.php';
require_once __DIR__ . '/../config/database.php';

class ParentController {
    private $childModel;
    private $appointmentModel;
    private $vaccineModel;
    private $notificationModel;
    private $certificateModel;
    private $nurseAssignmentModel;
    private $userModel;
    private $notificationService;
    
    public function __construct() {
        $this->childModel = new Child();
        $this->appointmentModel = new Appointment();
        $this->vaccineModel = new Vaccine();
        $this->notificationModel = new Notification();
        $this->certificateModel = new Certificate();
        $this->nurseAssignmentModel = new NurseAssignment();
        $this->userModel = new User();
        $this->notificationService = new NotificationService();
    }
    
    /**
     * Get all children for the authenticated parent
     * GET /api/parent/children
     */
  public function getChildren() {
    global $authPayload;
    $parentId = $authPayload['user_id'];

    // Only fetch approved children
    $children = $this->childModel->getChildrenByParent($parentId, 'approved'); // need to update model slightly
    // ... rest of the method stays the same
}
    
    /**
     * Get vaccination roadmap (schedule) for a specific child
     * GET /api/parent/child/{childId}/schedule
     */

    /**
 * Register a new child for the authenticated parent
 * POST /api/parent/child
 * Input: { name, dob, gender, blood_type, allergies, birth_weight, delivery_type, birth_place }
 */
/**
 * Register a new child for the authenticated parent
 * POST /api/parent/child
 * Input: { name, dob, gender, blood_type, allergies, birth_weight, delivery_type, birth_place }
 */
/**
 * Register a new child for the authenticated parent
 * POST /api/parent/child
 * Input: { name, dob, gender, blood_type, allergies, birth_weight, delivery_type, birth_place }
 */
/**
 * Register a new child for the authenticated parent (pending nurse approval)
 * POST /api/parent/child
 * Input: { name, dob, gender, blood_type, allergies, birth_weight, delivery_type, birth_place, historical_vaccines: [1,3] }
 */
public function addChild()
{
    global $authPayload;
    $parentId = $authPayload['user_id'];
    $input = json_decode(file_get_contents('php://input'), true);

    // --- Validation ---
    $required = ['name', 'dob', 'gender'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            Response::badRequest("Missing required field: $field");
            return;
        }
    }
    if (!empty($input['dob']) && strtotime($input['dob']) > time()) {
        Response::badRequest('Date of birth cannot be in the future');
        return;
    }

    // --- Generate unique child ID ---
    $db = Database::getConnection();
    $date = date('Ymd');
    $stmt = $db->prepare("SELECT COUNT(*) FROM children WHERE unique_child_id LIKE ?");
    $stmt->execute(["CHLD-$date-%"]);
    $count = $stmt->fetchColumn() + 1;
    $uniqueChildId = sprintf("CHLD-%s-%04d", $date, $count);

    // -> Store historical vaccine IDs as comma-separated string (or JSON)
    $historicalVaccines = '';
    if (!empty($input['historical_vaccines']) && is_array($input['historical_vaccines'])) {
        $historicalVaccines = implode(',', array_map('intval', $input['historical_vaccines']));
    }

    // --- Insert child with pending status ---
    $sql = "INSERT INTO children 
            (parent_id, name, dob, gender, blood_type, allergies, birth_weight, delivery_type, birth_place, unique_child_id, status, pending_historical_vaccines, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())";
    $stmt = $db->prepare($sql);
    $success = $stmt->execute([
        $parentId,
        $input['name'],
        $input['dob'],
        $input['gender'],
        $input['blood_type'] ?? null,
        $input['allergies'] ?? null,
        $input['birth_weight'] ?? null,
        $input['delivery_type'] ?? 'Normal',
        $input['birth_place'] ?? null,
        $uniqueChildId,
        $historicalVaccines
    ]);

    if (!$success) {
        Response::internalError('Failed to register child');
        return;
    }

    $childId = $db->lastInsertId();

    // --- Log the pending registration ---
    Logger::info("Child registration submitted (pending nurse approval)", $parentId, ['child_id' => $childId, 'unique_id' => $uniqueChildId]);

    // --- Return a confirmation message ---
    Response::success(null, 'Child registration submitted successfully. It will be reviewed by a nurse. You will be notified once approved.', 201);
}

    public function getChildSchedule($childId) {
        global $authPayload;
        $parentId = $authPayload['user_id'];
        
        // Verify child belongs to this parent
        $child = $this->childModel->getChildById($childId);
        if (!$child || $child['parent_id'] != $parentId) {
            Response::forbidden('You do not have access to this child');
            return;
        }
        
        $appointments = $this->appointmentModel->getByChild($childId);
        
        // Format schedule with status and notes
        $schedule = [];
        foreach ($appointments as $app) {
            $schedule[] = [
                'id' => $app['id'],
                'vaccine_name' => $app['vaccine_name'],
                'scheduled_date' => $app['scheduled_date'],
                'status' => $app['status'],
                'given_date' => $app['given_date'],
                'notes' => $app['notes'],
                'can_reschedule' => ($app['status'] === 'pending' && 
                                     $this->canReschedule($app['scheduled_date']))
            ];
        }
        
        Response::success([
            'child' => [
                'id' => $child['id'],
                'name' => $child['name'],
                'dob' => $child['dob'],
                'unique_child_id' => $child['unique_child_id']
            ],
            'schedule' => $schedule
        ]);
    }
    
    /**
     * Request reschedule for an appointment
     * POST /api/parent/appointment/{appointmentId}/reschedule
     * Input: { new_date: "YYYY-MM-DD" }
     * Rules: Max 5 days change from original, requires nurse approval
     */
    public function requestReschedule($appointmentId) {
        global $authPayload;
        $parentId = $authPayload['user_id'];
        $input = json_decode(file_get_contents('php://input'), true);
        $newDate = $input['new_date'] ?? '';
        
        if (!Validator::required($newDate) || !Validator::futureDate($newDate)) {
            Response::badRequest('Valid future date is required');
            return;
        }
        
        // Get appointment and verify parent owns the child
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT a.*, c.parent_id, c.name as child_name 
            FROM appointments a
            JOIN children c ON a.child_id = c.id
            WHERE a.id = ?
        ");
        $stmt->execute([$appointmentId]);
        $appointment = $stmt->fetch();
        
        if (!$appointment) {
            Response::notFound('Appointment not found');
            return;
        }
        
        if ($appointment['parent_id'] != $parentId) {
            Response::forbidden('You do not have access to this appointment');
            return;
        }
        
        if ($appointment['status'] !== 'pending') {
            Response::badRequest('Only pending appointments can be rescheduled');
            return;
        }
        
        // Check max 5 days change from original scheduled date
        $originalDate = new DateTime($appointment['scheduled_date']);
        $requestedDate = new DateTime($newDate);
        $diffDays = $requestedDate->diff($originalDate)->days;
        
        if ($diffDays > 5) {
            Response::badRequest('You can only reschedule within 5 days of the original appointment date');
            return;
        }
        
        // Update appointment with reschedule request
        $result = $this->appointmentModel->requestReschedule($appointmentId, $newDate);
        
        if ($result) {
            // Notify nurse about reschedule request
            $assignment = $this->nurseAssignmentModel->getByChild($appointment['child_id']);
            if ($assignment) {
                $this->notificationService->createNotification(
                    $assignment['nurse_id'],
                    $appointment['child_id'],
                    'Reschedule Request',
                    "Parent requested to reschedule {$appointment['vaccine_name']} for child {$appointment['child_name']} to $newDate",
                    'appointment_reminder'
                );
            }
            
            Logger::info("Reschedule requested", $parentId, [
                'appointment_id' => $appointmentId,
                'original_date' => $appointment['scheduled_date'],
                'requested_date' => $newDate
            ]);
            
            Response::success(null, 'Reschedule request submitted. Waiting for nurse approval.');
        } else {
            Response::internalError('Failed to submit reschedule request');
        }
    }
    
    /**
     * Download vaccination certificate for a child (after nurse + admin approval)
     * GET /api/parent/child/{childId}/certificate
     * Returns file download
     */
    public function downloadCertificate($childId) {
        global $authPayload;
        $parentId = $authPayload['user_id'];
        
        // Verify child belongs to parent
        $child = $this->childModel->getChildById($childId);
        if (!$child || $child['parent_id'] != $parentId) {
            Response::forbidden('You do not have access to this child');
            return;
        }
        
        // Get certificate
        $certificate = $this->certificateModel->getByChild($childId);
        if (!$certificate) {
            Response::notFound('Certificate not found for this child');
            return;
        }
        
        // Check approvals
        if (!$certificate['is_approved_by_nurse'] || !$certificate['is_approved_by_admin']) {
            Response::forbidden('Certificate is still pending approval. Please wait for nurse and admin approval.');
            return;
        }
        
        // Check if file exists
        $filePath = $certificate['file_path'];
        if (!file_exists($filePath)) {
            Response::notFound('Certificate file not found on server');
            return;
        }
        
        // Log download
        Logger::info("Certificate downloaded", $parentId, ['child_id' => $childId]);
        
        // Serve file for download
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="vaccination_certificate_' . $child['unique_child_id'] . '.pdf"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
    
    /**
     * Get notifications for the parent
     * GET /api/parent/notifications
     */
    public function getNotifications() {
        global $authPayload;
        $parentId = $authPayload['user_id'];
        
        $notifications = $this->notificationModel->getByUser($parentId);
        Response::success($notifications);
    }
    
    /**
     * Mark a notification as read
     * POST /api/parent/notifications/{notificationId}/read
     */
    public function markNotificationRead($notificationId) {
        global $authPayload;
        $parentId = $authPayload['user_id'];
        
        $result = $this->notificationModel->markAsRead($notificationId, $parentId);
        if ($result) {
            Response::success(null, 'Notification marked as read');
        } else {
            Response::notFound('Notification not found');
        }
    }
    
    /**
     * Get upcoming appointments (next 30 days)
     * GET /api/parent/upcoming-appointments
     */
    public function getUpcomingAppointments() {
        global $authPayload;
        $parentId = $authPayload['user_id'];
        
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT a.*, v.name as vaccine_name, c.name as child_name, c.unique_child_id
            FROM appointments a
            JOIN children c ON a.child_id = c.id
            JOIN vaccines v ON a.vaccine_id = v.id
            WHERE c.parent_id = ? 
              AND a.status = 'pending' 
              AND a.scheduled_date >= CURDATE()
              AND a.scheduled_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            ORDER BY a.scheduled_date ASC
        ");
        $stmt->execute([$parentId]);
        $appointments = $stmt->fetchAll();
        
        Response::success($appointments);
    }
    
    /**
     * Helper: Check if an appointment can be rescheduled (within rules)
     * Max 5 days from original date, and not already rescheduled too many times
     */
    private function canReschedule($scheduledDate) {
        $today = new DateTime();
        $scheduled = new DateTime($scheduledDate);
        $daysUntil = $today->diff($scheduled)->days;
        
        // Can only reschedule if appointment is at least 2 days away
        // And not more than 5 days away from original (this is checked separately)
        return $daysUntil >= 2;
    }
}
?>