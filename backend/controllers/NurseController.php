<?php
/**
 * NurseController - Handles all nurse-specific operations
 * 
 * Responsibilities:
 * - Verify parent registrations (approve accounts)
 * - View assigned children (auto-assignment via NurseAssignmentService)
 * - Register walk-in children (with parent creation if needed)
 * - Manage vaccinations (update status, add clinical notes)
 * - Appointment management (view upcoming, reschedule approvals)
 * - Search children by ID, name, or phone
 * - Filter children by vaccine type (which vaccines are pending)
 * - Generate weekly/monthly reports and send to admin
 * - Approve vaccination certificates
 * 
 * All methods require authentication and nurse role.
 */

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Child.php';
require_once __DIR__ . '/../models/Appointment.php';
require_once __DIR__ . '/../models/Vaccine.php';
require_once __DIR__ . '/../models/Notification.php';
require_once __DIR__ . '/../models/Certificate.php';
require_once __DIR__ . '/../models/NurseAssignment.php';
require_once __DIR__ . '/../models/Inventory.php';
require_once __DIR__ . '/../services/NurseAssignmentService.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/ReportGenerator.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/Logger.php';
require_once __DIR__ . '/../helpers/Validator.php';
require_once __DIR__ . '/../config/database.php';

class NurseController {
    private $userModel;
    private $childModel;
    private $appointmentModel;
    private $vaccineModel;
    private $notificationModel;
    private $certificateModel;
    private $nurseAssignmentModel;
    private $inventoryModel;
    private $assignmentService;
    private $notificationService;
    private $reportGenerator;
    
    public function __construct() {
        $this->userModel = new User();
        $this->childModel = new Child();
        $this->appointmentModel = new Appointment();
        $this->vaccineModel = new Vaccine();
        $this->notificationModel = new Notification();
        $this->certificateModel = new Certificate();
        $this->nurseAssignmentModel = new NurseAssignment();
        $this->inventoryModel = new Inventory();
        $this->assignmentService = new NurseAssignmentService();
        $this->notificationService = new NotificationService();
        $this->reportGenerator = new ReportGenerator();
    }
    
    /**
     * Get all pending parent registrations for verification
     * GET /api/nurse/pending-parents
     */
  public function getPendingChildren() {
    $db = Database::getConnection();
    $stmt = $db->prepare("
        SELECT c.*, u.name as parent_name, u.phone as parent_phone
        FROM children c
        JOIN users u ON c.parent_id = u.id
        WHERE c.status = 'pending'
        ORDER BY c.created_at ASC
    ");
    $stmt->execute();
    $children = $stmt->fetchAll();
    Response::success($children);
}
    
    /**
     * Approve a parent registration (verify)
     * POST /api/nurse/approve-parent/{parentId}
     */
   public function approveChild($childId) {
    global $authPayload;
    $nurseId = $authPayload['user_id'];
    $db = Database::getConnection();

    // Check child exists and is pending
    $stmt = $db->prepare("SELECT * FROM children WHERE id = ? AND status = 'pending'");
    $stmt->execute([$childId]);
    $child = $stmt->fetch();
    if (!$child) {
        Response::notFound('Child not found or already processed');
        return;
    }

    // Approve child
    $stmt = $db->prepare("UPDATE children SET status = 'approved', approved_by_nurse_id = ?, approved_at = NOW() WHERE id = ?");
    $stmt->execute([$nurseId, $childId]);

    // Generate vaccination schedule based on DOB
    $scheduler = new AppointmentScheduler();
    $generated = $scheduler->generateScheduleForChild($childId, $child['dob']);

    // Process historical vaccines (mark as completed with notes)
    if (!empty($child['pending_historical_vaccines'])) {
        $vaccineIds = explode(',', $child['pending_historical_vaccines']);
        foreach ($vaccineIds as $vaccineId) {
            // Insert completed appointment with current date and note 'Historical'
            $db->prepare("INSERT INTO appointments (child_id, vaccine_id, status, scheduled_date, given_date, notes)
                          VALUES (?, ?, 'completed', CURDATE(), CURDATE(), 'Historical - previously given elsewhere')")
               ->execute([$childId, $vaccineId]);
        }
    }

    // Auto assign nurse (same as before, pick least loaded)
    $nurseStmt = $db->prepare("
        SELECT u.id, COUNT(na.child_id) as assigned_count
        FROM users u
        LEFT JOIN nurse_assignments na ON u.id = na.nurse_id AND na.end_date IS NULL
        WHERE u.role = 'nurse' AND u.status = 'active'
        GROUP BY u.id
        ORDER BY assigned_count ASC
        LIMIT 1
    ");
    $nurseStmt->execute();
    $assignedNurse = $nurseStmt->fetch();
    if ($assignedNurse) {
        $db->prepare("INSERT INTO nurse_assignments (nurse_id, child_id, assigned_at) VALUES (?, ?, NOW())")
           ->execute([$assignedNurse['id'], $childId]);
    }

    // Notify parent
    $notif = new NotificationService();
    $notif->createNotification(
        $child['parent_id'],
        $childId,
        'Child Registration Approved',
        "Your child {$child['name']} ({$child['unique_child_id']}) has been approved. Full vaccination schedule is now available.",
        'child_approved'
    );

    Logger::info("Child registration approved", $nurseId, ['child_id' => $childId]);
    Response::success(null, 'Child approved successfully');
}

    /**
     * Get all children assigned to this nurse
     * GET /api/nurse/my-children
     */
    public function getMyAssignedChildren() {
        global $authPayload;
        $nurseId = $authPayload['user_id'];
        
        $children = $this->nurseAssignmentModel->getByNurse($nurseId);
        
        // Enrich with vaccination progress and next appointment
        foreach ($children as &$child) {
            $appointments = $this->appointmentModel->getByChild($child['id']);
            $total = count($appointments);
            $completed = 0;
            $nextAppointment = null;
            foreach ($appointments as $app) {
                if ($app['status'] === 'completed') $completed++;
                if (!$nextAppointment && $app['status'] === 'pending' && $app['scheduled_date'] >= date('Y-m-d')) {
                    $nextAppointment = $app;
                }
            }
            $child['vaccine_progress'] = $total > 0 ? round(($completed / $total) * 100) : 0;
            $child['next_appointment_date'] = $nextAppointment ? $nextAppointment['scheduled_date'] : null;
            $child['next_vaccine_name'] = $nextAppointment ? $nextAppointment['vaccine_name'] : null;
            
            // Get pending vaccine counts
            $pendingCount = 0;
            foreach ($appointments as $app) {
                if ($app['status'] === 'pending') $pendingCount++;
            }
            $child['pending_vaccines_count'] = $pendingCount;
        }
        
        Response::success($children);
    }
    


    public function rejectChild($childId) {
    global $authPayload;
    $nurseId = $authPayload['user_id'];
    $db = Database::getConnection();

    $stmt = $db->prepare("SELECT * FROM children WHERE id = ? AND status = 'pending'");
    $stmt->execute([$childId]);
    $child = $stmt->fetch();
    if (!$child) {
        Response::notFound('Child not found or already processed');
        return;
    }

    // Delete or mark as rejected (better to add a 'rejected' status)
    $db->prepare("UPDATE children SET status = 'rejected', approved_by_nurse_id = ?, approved_at = NOW() WHERE id = ?")
       ->execute([$nurseId, $childId]);

    // Notify parent
    $notif = new NotificationService();
    $notif->createNotification(
        $child['parent_id'],
        $childId,
        'Child Registration Rejected',
        "Your child {$child['name']} registration was not approved. Please contact the health center.",
        'child_rejected'
    );

    Logger::info("Child registration rejected", $nurseId, ['child_id' => $childId]);
    Response::success(null, 'Child rejected');
}
    /**
     * Register a walk-in child (with optional parent creation)
     * POST /api/nurse/walkin
     * Input:
     * {
     *   "parent_phone": "0912345678",
     *   "parent_name": "New Parent Name", (required only if parent not found)
     *   "child": {
     *       "name": "Child Name",
     *       "dob": "2023-01-01",
     *       "gender": "Male",
     *       "blood_type": "O+",
     *       "allergies": "None",
     *       "birth_weight": 3.2,
     *       "delivery_type": "Normal",
     *       "birth_place": "Black Lion Hospital"
     *   }
     * }
     */
    public function walkinRegistration() {
        global $authPayload;
        $nurseId = $authPayload['user_id'];
        $input = json_decode(file_get_contents('php://input'), true);
        
        $parentPhone = $input['parent_phone'] ?? '';
        $parentName = $input['parent_name'] ?? '';
        $childData = $input['child'] ?? [];
        
        // Validate required fields
        if (!Validator::required($parentPhone) || !Validator::ethiopianPhone($parentPhone)) {
            Response::badRequest('Valid parent phone number (10 digits, starting with 09) is required');
            return;
        }
        
        if (empty($childData) || !Validator::required($childData['name'] ?? '') || !Validator::required($childData['dob'] ?? '')) {
            Response::badRequest('Child name and date of birth are required');
            return;
        }
        
        // Find or create parent
        $parent = $this->userModel->findByPhone($parentPhone);
        if (!$parent) {
            // Create new parent account
            if (!Validator::required($parentName)) {
                Response::badRequest('Parent name is required for new parent account');
                return;
            }
            // Generate temporary email (phone@temp.vaccine)
            $tempEmail = $parentPhone . '@temp.vaccine.com';
            $tempPassword = bin2hex(random_bytes(4)); // Temporary password, user can reset later
            $parentId = $this->userModel->create($parentName, $tempEmail, $parentPhone, $tempPassword, 1);
            if (!$parentId) {
                Response::internalError('Failed to create parent account');
                return;
            }
            // Auto-approve parent (since nurse is registering)
            $this->userModel->approveParent($parentId, $nurseId);
            $parent = ['id' => $parentId, 'name' => $parentName, 'phone' => $parentPhone];
        } else {
            $parentId = $parent['id'];
            // Ensure parent is verified
            if (!$parent['is_verified']) {
                $this->userModel->approveParent($parentId, $nurseId);
            }
        }
        
        // Create child record
        $childInput = [
            'parent_id' => $parentId,
            'name' => $childData['name'],
            'dob' => $childData['dob'],
            'gender' => $childData['gender'] ?? 'Male',
            'blood_type' => $childData['blood_type'] ?? null,
            'allergies' => $childData['allergies'] ?? 'None',
            'birth_weight' => $childData['birth_weight'] ?? null,
            'delivery_type' => $childData['delivery_type'] ?? 'Normal',
            'birth_place' => $childData['birth_place'] ?? 'Unknown'
        ];
        
        $childId = $this->childModel->create($childInput);
        if (!$childId) {
            Response::internalError('Failed to register child');
            return;
        }
        
        // Verify child (nurse walk-in implies immediate verification)
        $this->childModel->verifyChild($childId);
        
        // Assign this child to the registering nurse
        $this->assignmentService->assignNurse($childId, $nurseId);
        
        // Generate appointments based on DOB
        $this->scheduleAppointmentsForChild($childId, $childData['dob']);
        
        // Log action
        Logger::info("Walk-in child registered", $nurseId, [
            'child_id' => $childId,
            'parent_id' => $parentId,
            'child_name' => $childData['name']
        ]);
        
        // Notify parent (if parent has email, otherwise skip)
        if (filter_var($parent['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $this->notificationService->createNotification(
                $parentId,
                $childId,
                'Child Registered',
                "Your child {$childData['name']} has been registered by nurse. You can log in to view their vaccination schedule.",
                'approval'
            );
        }
        
        Response::success([
            'child_id' => $childId,
            'unique_child_id' => $this->childModel->getChildById($childId)['unique_child_id'],
            'parent_id' => $parentId
        ], 'Walk-in child registered successfully and assigned to you.');
    }
    
    /**
     * Administer a vaccine (record given dose)
     * POST /api/nurse/record-vaccine
     * Input:
     * {
     *   "appointment_id": 123,
     *   "batch_number": "BCG2024001",
     *   "notes": "Administered in left arm"
     * }
     */
    public function recordVaccine() {
        global $authPayload;
        $nurseId = $authPayload['user_id'];
        $input = json_decode(file_get_contents('php://input'), true);
        
        $appointmentId = $input['appointment_id'] ?? 0;
        $batchNumber = $input['batch_number'] ?? '';
        $notes = $input['notes'] ?? '';
        
        if (!$appointmentId || !Validator::required($batchNumber)) {
            Response::badRequest('Appointment ID and batch number are required');
            return;
        }
        
        // Get appointment details
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT a.*, c.id as child_id, c.name as child_name 
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
        
        if ($appointment['status'] === 'completed') {
            Response::badRequest('Vaccine already administered');
            return;
        }
        
        // Verify that this vaccine batch exists and has stock
        $batch = $this->inventoryModel->getAvailableBatch($appointment['vaccine_id'], $batchNumber);
        if (!$batch) {
            Response::badRequest('Batch not found or expired or insufficient stock');
            return;
        }
        
        // Deduct stock
        $deducted = $this->inventoryModel->deductStock($batch['id'], 1);
        if (!$deducted) {
            Response::internalError('Failed to update stock');
            return;
        }
        
        // Update appointment
        $today = date('Y-m-d');
        $updated = $this->appointmentModel->updateStatus(
            $appointmentId,
            'completed',
            $today,
            $batchNumber,
            $nurseId
        );
        
        if (!$updated) {
            Response::internalError('Failed to record vaccination');
            return;
        }
        
        // Add clinical notes if provided
        if ($notes) {
            $db->prepare("UPDATE appointments SET notes = CONCAT(IFNULL(notes, ''), ?) WHERE id = ?")
               ->execute(["\n[" . date('Y-m-d H:i:s') . "] Nurse: " . $notes, $appointmentId]);
        }
        
        // Log action
        Logger::info("Vaccine administered", $nurseId, [
            'appointment_id' => $appointmentId,
            'child_id' => $appointment['child_id'],
            'vaccine_id' => $appointment['vaccine_id'],
            'batch_number' => $batchNumber
        ]);
        
        // Send notification to parent
        $parent = $db->prepare("SELECT parent_id FROM children WHERE id = ?")->execute([$appointment['child_id']]);
        $stmt = $db->prepare("SELECT parent_id FROM children WHERE id = ?");
        $stmt->execute([$appointment['child_id']]);
        $parentRow = $stmt->fetch();
        $parentId = $parentRow ? $parentRow['parent_id'] : null;
        if ($parentId) {
            $vaccineName = $this->vaccineModel->getById($appointment['vaccine_id'])['name'];
            $this->notificationService->createNotification(
                $parentId,
                $appointment['child_id'],
                'Vaccine Administered',
                "{$appointment['child_name']} received {$vaccineName} vaccine on {$today}.",
                'appointment_reminder'
            );
        }
        
        Response::success(null, 'Vaccine administered successfully.');
    }
    
    /**
     * Approve or reject a reschedule request from parent
     * POST /api/nurse/appointment/{appointmentId}/approve-reschedule
     * Input: { "approved": true }
     */
    public function approveReschedule($appointmentId) {
        global $authPayload;
        $nurseId = $authPayload['user_id'];
        $input = json_decode(file_get_contents('php://input'), true);
        $approved = $input['approved'] ?? false;
        
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
        
        if ($appointment['status'] !== 'rescheduled' || !$appointment['reschedule_request_date']) {
            Response::badRequest('No pending reschedule request for this appointment');
            return;
        }
        
        if ($approved) {
            // Approve: move scheduled_date to request_date
            $db->prepare("
                UPDATE appointments 
                SET scheduled_date = reschedule_request_date, 
                    reschedule_request_date = NULL, 
                    reschedule_approved = TRUE
                WHERE id = ?
            ")->execute([$appointmentId]);
            
            // Notify parent
            $this->notificationService->createNotification(
                $appointment['parent_id'],
                $appointment['child_id'],
                'Reschedule Approved',
                "Your request to reschedule {$appointment['vaccine_name']} for {$appointment['child_name']} has been approved. New date: {$appointment['reschedule_request_date']}",
                'appointment_reminder'
            );
            
            Logger::info("Reschedule approved", $nurseId, ['appointment_id' => $appointmentId]);
            Response::success(null, 'Reschedule request approved');
        } else {
            // Reject: clear request date and set status back to pending
            $db->prepare("
                UPDATE appointments 
                SET reschedule_request_date = NULL, 
                    status = 'pending',
                    reschedule_approved = FALSE
                WHERE id = ?
            ")->execute([$appointmentId]);
            
            $this->notificationService->createNotification(
                $appointment['parent_id'],
                $appointment['child_id'],
                'Reschedule Rejected',
                "Your request to reschedule {$appointment['vaccine_name']} for {$appointment['child_name']} has been rejected. Please contact the health center.",
                'appointment_reminder'
            );
            
            Logger::info("Reschedule rejected", $nurseId, ['appointment_id' => $appointmentId]);
            Response::success(null, 'Reschedule request rejected');
        }
    }
    
    /**
     * Search children by ID, name, or parent phone
     * GET /api/nurse/search?keyword=...
     */
    public function searchChildren() {
        global $authPayload;
        $nurseId = $authPayload['user_id'];
        $keyword = $_GET['keyword'] ?? '';
        
        if (!Validator::required($keyword)) {
            Response::badRequest('Search keyword is required');
            return;
        }
        
        $results = $this->childModel->search($keyword, $nurseId);
        Response::success($results);
    }
    
    /**
     * Get children filtered by vaccine type (which vaccine they are pending for)
     * GET /api/nurse/filter-by-vaccine?vaccine_id=1
     */
    public function filterByVaccine() {
        global $authPayload;
        $nurseId = $authPayload['user_id'];
        $vaccineId = $_GET['vaccine_id'] ?? 0;
        
        if (!$vaccineId) {
            Response::badRequest('Vaccine ID is required');
            return;
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT DISTINCT c.*, u.name as parent_name, u.phone as parent_phone
            FROM children c
            JOIN nurse_assignments na ON c.id = na.child_id
            JOIN users u ON c.parent_id = u.id
            JOIN appointments a ON c.id = a.child_id
            WHERE na.nurse_id = ? 
              AND a.vaccine_id = ? 
              AND a.status = 'pending'
              AND a.scheduled_date >= CURDATE()
            ORDER BY a.scheduled_date ASC
        ");
        $stmt->execute([$nurseId, $vaccineId]);
        $children = $stmt->fetchAll();
        
        Response::success($children);
    }
    
    /**
     * Generate weekly or monthly report and send to admin
     * POST /api/nurse/generate-report
     * Input: { "type": "weekly", "period_start": "2025-01-01", "period_end": "2025-01-07" }
     */
    public function generateReport() {
        global $authPayload;
        $nurseId = $authPayload['user_id'];
        $input = json_decode(file_get_contents('php://input'), true);
        
        $type = $input['type'] ?? 'weekly';
        $periodStart = $input['period_start'] ?? null;
        $periodEnd = $input['period_end'] ?? null;
        
        if (!$periodStart || !$periodEnd) {
            Response::badRequest('Period start and end dates are required');
            return;
        }
        
        // Generate report data
       $reportData = $this->reportGenerator->generateForNurse($nurseId, $periodStart, $periodEnd, $type);
        
        // Store report in database
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO reports (type, generated_by, data, period_start, period_end)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$type, $nurseId, json_encode($reportData), $periodStart, $periodEnd]);
        $reportId = $db->lastInsertId();
        
        // Notify admin (all admins)
        $stmt = $db->prepare("SELECT id FROM users WHERE role_id = 3");
         $stmt->execute();
          $admins = $stmt->fetchAll();
        foreach ($admins as $admin) {
            $this->notificationService->createNotification(
                $admin['id'],
                null,
                "New Report Available",
                "Nurse {$authPayload['name']} has generated a $type report for period $periodStart to $periodEnd.",
                'report'
            );
        }
        
        Logger::info("Report generated", $nurseId, ['type' => $type, 'period' => "$periodStart to $periodEnd"]);
        Response::success(['report_id' => $reportId, 'data' => $reportData], 'Report generated and admin notified.');
    }
    
    /**
     * Approve a certificate (nurse approval)
     * POST /api/nurse/approve-certificate/{certificateId}
     */
    public function approveCertificate($certificateId) {
        global $authPayload;
        $nurseId = $authPayload['user_id'];
        
        $result = $this->certificateModel->approveByNurse($certificateId);
        if (!$result) {
            Response::internalError('Failed to approve certificate');
            return;
        }
        
        Logger::info("Certificate approved by nurse", $nurseId, ['certificate_id' => $certificateId]);
        Response::success(null, 'Certificate approved by nurse. Awaiting admin approval.');
    }
    
    /**
     * Get upcoming appointments for this nurse (next 7 days)
     * GET /api/nurse/upcoming-appointments
     */
    public function getUpcomingAppointments() {
        global $authPayload;
        $nurseId = $authPayload['user_id'];
        
        $appointments = $this->appointmentModel->getUpcomingByNurse($nurseId, 50);
        Response::success($appointments);
    }
    
    /**
     * Add notes to a child record
     * POST /api/nurse/child/{childId}/notes
     * Input: { "notes": "Has egg allergy" }
     */
    public function addChildNotes($childId) {
        global $authPayload;
        $nurseId = $authPayload['user_id'];
        $input = json_decode(file_get_contents('php://input'), true);
        $notes = $input['notes'] ?? '';
        
        if (!Validator::required($notes)) {
            Response::badRequest('Notes are required');
            return;
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE children 
            SET allergies = IFNULL(CONCAT(allergies, '\n', ?), ?)
            WHERE id = ?
        ");
        $stmt->execute([$notes, $notes, $childId]);
        
        Logger::info("Child notes added", $nurseId, ['child_id' => $childId]);
        Response::success(null, 'Notes added successfully.');
    }
    
    /**
     * Helper: Schedule all appointments for a child based on DOB
     */
    private function scheduleAppointmentsForChild($childId, $dob) {
        $vaccines = $this->vaccineModel->getAll();
        $db = Database::getConnection();
        
        foreach ($vaccines as $vaccine) {
            $scheduledDate = date('Y-m-d', strtotime($dob . ' + ' . $vaccine['days_from_birth'] . ' days'));
            // Only create if not already exists
         $stmt = $db->prepare("SELECT id FROM appointments WHERE child_id = ? AND vaccine_id = ?");
          $stmt->execute([$childId, $vaccine['id']]);
           $exists = $stmt->fetch();
            if (!$exists) {
                $this->appointmentModel->schedule($childId, $vaccine['id'], $scheduledDate);
            }
        }
    }
}
?>