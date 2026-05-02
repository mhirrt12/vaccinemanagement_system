<?php
/**
 * AdminController - Handles all administrative operations
 */

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Vaccine.php';
require_once __DIR__ . '/../models/Inventory.php';
require_once __DIR__ . '/../models/Child.php';
require_once __DIR__ . '/../models/Certificate.php';
require_once __DIR__ . '/../models/AuditLog.php';
require_once __DIR__ . '/../models/Appointment.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/Logger.php';
require_once __DIR__ . '/../helpers/Validator.php';
require_once __DIR__ . '/../config/database.php';

class AdminController {
    private $userModel;
    private $vaccineModel;
    private $inventoryModel;
    private $childModel;
    private $certificateModel;
    private $auditLogModel;
    private $appointmentModel;
    private $notificationService;
    
    public function __construct() {
        $this->userModel = new User();
        $this->vaccineModel = new Vaccine();
        $this->inventoryModel = new Inventory();
        $this->childModel = new Child();
        $this->certificateModel = new Certificate();
        $this->auditLogModel = new AuditLog();
        $this->appointmentModel = new Appointment();  // ← fixed typo (was Appointments)
        $this->notificationService = new NotificationService();
    }
    
    // ==================== DASHBOARD STATISTICS ====================
    
    public function getStats() {
        $db = Database::getConnection();
        
        $stmt = $db->query("SELECT COUNT(*) as total FROM children");
        $totalChildren = $stmt->fetch()['total'];
        
        $stmt = $db->prepare("
            SELECT COUNT(*) as total 
            FROM appointments 
            WHERE status = 'completed' AND given_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ");
        $stmt->execute();
        $monthlyVaccinations = $stmt->fetch()['total'];
        
        $stmt = $db->query("SELECT COUNT(*) as total FROM users WHERE role_id = 2");
        $totalNurses = $stmt->fetch()['total'];
        
        $stmt = $db->query("SELECT COUNT(*) as total FROM users WHERE role_id = 1");
        $totalParents = $stmt->fetch()['total'];
        
        $stmt = $db->prepare("
            SELECT v.name, COUNT(a.id) as count
            FROM appointments a
            JOIN vaccines v ON a.vaccine_id = v.id
            WHERE a.status = 'completed' AND a.given_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY v.id
            ORDER BY count DESC
        ");
        $stmt->execute();
        $byVaccine = $stmt->fetchAll();
        
        $vaccineLabels = [];
        $vaccineCounts = [];
        foreach ($byVaccine as $item) {
            $vaccineLabels[] = $item['name'];
            $vaccineCounts[] = (int)$item['count'];
        }
        
        $stmt = $db->prepare("
            SELECT a.*, c.name as child_name, v.name as vaccine_name
            FROM appointments a
            JOIN children c ON a.child_id = c.id
            JOIN vaccines v ON a.vaccine_id = v.id
            WHERE a.given_date IS NOT NULL
            ORDER BY a.given_date DESC
            LIMIT 10
        ");
        $stmt->execute();
        $recentActivities = $stmt->fetchAll();
        
        Response::success([
            'totalChildren' => $totalChildren,
            'monthlyVaccinations' => $monthlyVaccinations,
            'totalNurses' => $totalNurses,
            'totalParents' => $totalParents,
            'vaccineLabels' => $vaccineLabels,
            'vaccineCounts' => $vaccineCounts,
            'recentActivities' => $recentActivities
        ]);
    }
    
    // ==================== VACCINE MANAGEMENT ====================
    
    public function getVaccines() {
        $vaccines = $this->vaccineModel->getAll();
        Response::success($vaccines);
    }
    
    public function addVaccine() {
        global $authPayload;
        $input = json_decode(file_get_contents('php://input'), true);
        $name = $input['name'] ?? '';
        $daysFromBirth = $input['days_from_birth'] ?? 0;
        $description = $input['description'] ?? null;
        
        if (!Validator::required($name) || !Validator::between($daysFromBirth, 0, 1000)) {
            Response::badRequest('Valid vaccine name and days from birth are required');
            return;
        }
        
        $id = $this->vaccineModel->create($name, $daysFromBirth, $description);
        if ($id) {
            Logger::info("Vaccine added", $authPayload['user_id'], ['vaccine' => $name]);
            Response::created(['id' => $id], 'Vaccine added successfully');
        } else {
            Response::internalError('Failed to add vaccine (maybe duplicate name)');
        }
    }
    
    public function updateVaccine($id) {
        global $authPayload;
        $input = json_decode(file_get_contents('php://input'), true);
        $name = $input['name'] ?? '';
        $daysFromBirth = $input['days_from_birth'] ?? 0;
        $description = $input['description'] ?? null;
        
        if (!Validator::required($name)) {
            Response::badRequest('Vaccine name is required');
            return;
        }
        
        $result = $this->vaccineModel->update($id, $name, $daysFromBirth, $description);
        if ($result) {
            Logger::info("Vaccine updated", $authPayload['user_id'], ['vaccine_id' => $id]);
            Response::success(null, 'Vaccine updated successfully');
        } else {
            Response::internalError('Failed to update vaccine');
        }
    }
    
    public function deleteVaccine($id) {
        global $authPayload;
        $result = $this->vaccineModel->delete($id);
        if ($result) {
            Logger::info("Vaccine deleted", $authPayload['user_id'], ['vaccine_id' => $id]);
            Response::success(null, 'Vaccine deleted successfully');
        } else {
            Response::internalError('Failed to delete vaccine');
        }
    }
    
    public function toggleVaccine($id) {
        global $authPayload;
        $input = json_decode(file_get_contents('php://input'), true);
        $isActive = $input['is_active'] ?? true;
        
        $result = $this->vaccineModel->toggleActive($id, $isActive);
        if ($result) {
            Logger::info("Vaccine toggled", $authPayload['user_id'], ['vaccine_id' => $id, 'active' => $isActive]);
            Response::success(null, 'Vaccine status updated');
        } else {
            Response::internalError('Failed to update status');
        }
    }
    
    // ==================== INVENTORY MANAGEMENT ====================
    
    public function addInventoryBatch() {
        global $authPayload;
        $input = json_decode(file_get_contents('php://input'), true);
        $vaccineId = $input['vaccine_id'] ?? 0;
        $batchNumber = $input['batch_number'] ?? '';
        $expiryDate = $input['expiry_date'] ?? '';
        $quantity = $input['quantity'] ?? 0;
        
        if (!$vaccineId || !Validator::required($batchNumber) || !Validator::required($expiryDate) || $quantity <= 0) {
            Response::badRequest('All fields are required and quantity must be positive');
            return;
        }
        
        $result = $this->inventoryModel->addBatch($vaccineId, $batchNumber, $expiryDate, $quantity);
        if ($result) {
            $daysToExpiry = (strtotime($expiryDate) - time()) / 86400;
            if ($daysToExpiry <= 15) {
                $vaccine = $this->vaccineModel->getById($vaccineId);
                $this->notificationService->createNotification(
                    $authPayload['user_id'],
                    null,
                    'Vaccine Batch Expiring Soon',
                    "Batch {$batchNumber} of {$vaccine['name']} expires on {$expiryDate}. Only " . round($daysToExpiry) . " days left.",
                    'expiry_alert'
                );
            }
            Logger::info("Inventory batch added", $authPayload['user_id'], ['vaccine_id' => $vaccineId, 'batch' => $batchNumber]);
            Response::created(null, 'Batch added successfully');
        } else {
            Response::internalError('Failed to add batch');
        }
    }
    
    public function getInventory() {
        $db = Database::getConnection();
        $stmt = $db->query("
            SELECT i.*, v.name as vaccine_name 
            FROM inventory i
            JOIN vaccines v ON i.vaccine_id = v.id
            ORDER BY i.expiry_date ASC
        ");
        $inventory = $stmt->fetchAll();
        Response::success($inventory);
    }
    
    public function getLowStock() {
        $lowStock = $this->inventoryModel->getLowStock(100);
        Response::success($lowStock);
    }
    
    public function getExpiringBatches() {
        $expiring = $this->inventoryModel->getAllWithExpiryWarning();
        Response::success($expiring);
    }
    
    // ==================== NURSE MANAGEMENT ====================
    
    public function getNurses() {
        $nurses = $this->userModel->getNurses();
        Response::success($nurses);
    }
    
    public function createNurse() {
        global $authPayload;
        $input = json_decode(file_get_contents('php://input'), true);
        $name = $input['name'] ?? '';
        $email = $input['email'] ?? '';
        $phone = $input['phone'] ?? '';
        $password = $input['password'] ?? '';
        
        if (!Validator::required($name) || !Validator::email($email) || !Validator::ethiopianPhone($phone) || !Validator::strongPassword($password)) {
            Response::badRequest('Valid name, email, Ethiopian phone, and strong password are required');
            return;
        }
        
        $userId = $this->userModel->createNurse($name, $email, $phone, $password);
        if ($userId) {
            Logger::info("Nurse created", $authPayload['user_id'], ['nurse_id' => $userId]);
            Response::created(['id' => $userId], 'Nurse account created successfully');
        } else {
            Response::conflict('Email or phone already exists');
        }
    }
    
    public function deleteNurse($id) {
        global $authPayload;
        if ($id == $authPayload['user_id']) {
            Response::forbidden('Cannot delete your own account');
            return;
        }
        $result = $this->userModel->deleteNurse($id);
        if ($result) {
            Logger::info("Nurse deleted", $authPayload['user_id'], ['nurse_id' => $id]);
            Response::success(null, 'Nurse account revoked');
        } else {
            Response::notFound('Nurse not found');
        }
    }
    
    // ==================== CERTIFICATE APPROVAL ====================
    
    public function getPendingCertificates() {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT c.*, ch.name as child_name, ch.unique_child_id, u.name as parent_name
            FROM certificates c
            JOIN children ch ON c.child_id = ch.id
            JOIN users u ON ch.parent_id = u.id
            WHERE c.is_approved_by_nurse = TRUE AND c.is_approved_by_admin = FALSE
        ");
        $stmt->execute();
        $certificates = $stmt->fetchAll();
        Response::success($certificates);
    }
    
    public function approveCertificate($certificateId) {
        global $authPayload;
        $result = $this->certificateModel->approveByAdmin($certificateId);
        if (!$result) {
            Response::internalError('Failed to approve certificate');
            return;
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT c.child_id, ch.parent_id, ch.name as child_name
            FROM certificates c
            JOIN children ch ON c.child_id = ch.id
            WHERE c.id = ?
        ");
        $stmt->execute([$certificateId]);
        $cert = $stmt->fetch();
        
        if ($cert) {
            $this->notificationService->createNotification(
                $cert['parent_id'],
                $cert['child_id'],
                'Certificate Approved',
                "The vaccination certificate for {$cert['child_name']} has been fully approved. You can now download it from the parent dashboard.",
                'certificate_ready'
            );
        }
        Logger::info("Certificate approved by admin", $authPayload['user_id'], ['certificate_id' => $certificateId]);
        Response::success(null, 'Certificate approved successfully');
    }
    
    // ==================== AUDIT LOGS & REPORTS ====================
    
    public function getAuditLogs() {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $logs = $this->auditLogModel->getAll($limit, $offset);
        Response::success($logs);
    }
    
    public function getReports() {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT r.*, u.name as nurse_name
            FROM reports r
            JOIN users u ON r.generated_by = u.id
            ORDER BY r.created_at DESC
        ");
        $stmt->execute();
        $reports = $stmt->fetchAll();
        Response::success($reports);
    }
    
    public function getReport($id) {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT r.*, u.name as nurse_name
            FROM reports r
            JOIN users u ON r.generated_by = u.id
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        $report = $stmt->fetch();
        if (!$report) {
            Response::notFound('Report not found');
            return;
        }
        Response::success($report);
    }
    
    // ==================== SYSTEM MONITORING ====================
    
    public function checkAlerts() {
        global $authPayload;
        $lowStock = $this->inventoryModel->getLowStock(100);
        foreach ($lowStock as $item) {
            $this->notificationService->createNotification(
                $authPayload['user_id'],
                null,
                'Low Stock Alert',
                "Vaccine {$item['vaccine_name']} (Batch: {$item['batch_number']}) has only {$item['quantity']} doses remaining. Please restock.",
                'stock_alert'
            );
        }
        
        $expiring = $this->inventoryModel->getAllWithExpiryWarning();
        foreach ($expiring as $item) {
            $daysLeft = (strtotime($item['expiry_date']) - time()) / 86400;
            $this->notificationService->createNotification(
                $authPayload['user_id'],
                null,
                'Expiry Alert',
                "Vaccine {$item['vaccine_name']} (Batch: {$item['batch_number']}) expires on {$item['expiry_date']} (in " . round($daysLeft) . " days).",
                'expiry_alert'
            );
        }
        Response::success([
            'low_stock_count' => count($lowStock),
            'expiring_count' => count($expiring)
        ], 'Alerts checked and notifications sent');
    }
}
?>