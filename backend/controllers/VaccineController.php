<?php
/**
 * VaccineController - Handles vaccine-related operations
 * 
 * Responsibilities:
 * - Fetch all vaccines (with optional filtering)
 * - Get vaccine details by ID
 * - Get vaccination schedule for a child (based on age)
 * - Calculate due dates based on birth date and EPI schedule
 * 
 * Accessible by: All authenticated users (parents, nurses, admin)
 * Endpoints are protected by JWT token; role-specific access is handled by middleware.
 */

require_once __DIR__ . '/../models/Vaccine.php';
require_once __DIR__ . '/../models/Child.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/Validator.php';
require_once __DIR__ . '/../config/database.php';

class VaccineController {
    private $vaccineModel;
    
    public function __construct() {
        $this->vaccineModel = new Vaccine();
    }
    
    /**
     * Get all active vaccines (EPI schedule)
     * GET /api/vaccines
     * Query params: is_active (optional, default true)
     */
    public function getAll() {
     $isActive = isset($_GET['is_active']) ? filter_var($_GET['is_active'], FILTER_VALIDATE_BOOLEAN) : true;
        
        $db = Database::getConnection();
        $sql = "SELECT * FROM vaccines WHERE is_active = ? ORDER BY days_from_birth ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute([$isActive]);
        $vaccines = $stmt->fetchAll();
        
        Response::success($vaccines);
    }
    
    /**
     * Get a single vaccine by ID
     * GET /api/vaccines/{id}
     */
    public function getById($id) {
        $vaccine = $this->vaccineModel->getById($id);
        if (!$vaccine) {
            Response::notFound('Vaccine not found');
            return;
        }
        Response::success($vaccine);
    }
    
    /**
     * Calculate vaccination due dates for a child based on their date of birth
     * GET /api/vaccines/calculate-schedule?dob=2023-01-01&child_id=123 (optional)
     * 
     * This endpoint returns a full schedule of due dates for all EPI vaccines,
     * including which ones are overdue or already completed (if child_id provided).
     */
    public function calculateSchedule() {
        $dob = $_GET['dob'] ?? '';
        $childId = $_GET['child_id'] ?? null;
        
        if (!Validator::required($dob) || !strtotime($dob)) {
            Response::badRequest('Valid date of birth is required');
            return;
        }
        
        // Get all active vaccines
        $vaccines = $this->vaccineModel->getAll();
        
        $birthDate = new DateTime($dob);
        $today = new DateTime();
        $schedule = [];
        
        // If child_id provided, get already administered vaccines
        $completedVaccineIds = [];
        if ($childId) {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT vaccine_id FROM appointments 
                WHERE child_id = ? AND status = 'completed'
            ");
            $stmt->execute([$childId]);
            $completed = $stmt->fetchAll();
            foreach ($completed as $c) {
                $completedVaccineIds[] = $c['vaccine_id'];
            }
        }
        
        foreach ($vaccines as $vaccine) {
            $dueDate = clone $birthDate;
            $dueDate->modify("+{$vaccine['days_from_birth']} days");
            $dueDateStr = $dueDate->format('Y-m-d');
            
            $status = 'pending';
            if (in_array($vaccine['id'], $completedVaccineIds)) {
                $status = 'completed';
            } elseif ($dueDate < $today) {
                $status = 'overdue';
            }
            
            $schedule[] = [
                'vaccine_id' => $vaccine['id'],
                'vaccine_name' => $vaccine['name'],
                'due_date' => $dueDateStr,
                'status' => $status,
                'days_from_birth' => $vaccine['days_from_birth']
            ];
        }
        
        Response::success($schedule);
    }
    
    /**
     * Get vaccines that are due for a child within a given date range
     * GET /api/vaccines/due?child_id=123&days=30
     */
    public function getDueVaccines() {
        $childId = $_GET['child_id'] ?? 0;
        $days = isset($_GET['days']) ? (int)$_GET['days'] : 30;
        
        if (!$childId) {
            Response::badRequest('Child ID is required');
            return;
        }
        
        $db = Database::getConnection();
        
        // Get child's date of birth
        $stmt = $db->prepare("SELECT dob FROM children WHERE id = ?");
        $stmt->execute([$childId]);
        $child = $stmt->fetch();
        if (!$child) {
            Response::notFound('Child not found');
            return;
        }
        
        $dob = $child['dob'];
        $today = date('Y-m-d');
        $futureDate = date('Y-m-d', strtotime("+$days days"));
        
        // Get vaccines where due date is between today and futureDate, and not yet completed
        $stmt = $db->prepare("
            SELECT v.*, 
                   DATE_ADD(?, INTERVAL v.days_from_birth DAY) as due_date
            FROM vaccines v
            WHERE v.is_active = TRUE
              AND DATE_ADD(?, INTERVAL v.days_from_birth DAY) BETWEEN ? AND ?
              AND NOT EXISTS (
                  SELECT 1 FROM appointments a 
                  WHERE a.child_id = ? AND a.vaccine_id = v.id AND a.status = 'completed'
              )
            ORDER BY due_date ASC
        ");
        $stmt->execute([$dob, $dob, $today, $futureDate, $childId]);
        $dueVaccines = $stmt->fetchAll();
        
        Response::success($dueVaccines);
    }
    
    /**
     * Get overdue vaccines for a child (due date passed and not completed)
     * GET /api/vaccines/overdue?child_id=123
     */
    public function getOverdueVaccines() {
        $childId = $_GET['child_id'] ?? 0;
        
        if (!$childId) {
            Response::badRequest('Child ID is required');
            return;
        }
        
        $db = Database::getConnection();
        
        // Get child's date of birth
        $stmt = $db->prepare("SELECT dob FROM children WHERE id = ?");
        $stmt->execute([$childId]);
        $child = $stmt->fetch();
        if (!$child) {
            Response::notFound('Child not found');
            return;
        }
        
        $dob = $child['dob'];
        $today = date('Y-m-d');
        
        $stmt = $db->prepare("
            SELECT v.*, 
                   DATE_ADD(?, INTERVAL v.days_from_birth DAY) as due_date,
                   DATEDIFF(?, DATE_ADD(?, INTERVAL v.days_from_birth DAY)) as days_overdue
            FROM vaccines v
            WHERE v.is_active = TRUE
              AND DATE_ADD(?, INTERVAL v.days_from_birth DAY) < ?
              AND NOT EXISTS (
                  SELECT 1 FROM appointments a 
                  WHERE a.child_id = ? AND a.vaccine_id = v.id AND a.status = 'completed'
              )
            ORDER BY due_date ASC
        ");
        $stmt->execute([$dob, $today, $dob, $dob, $today, $childId]);
        $overdue = $stmt->fetchAll();
        
        Response::success($overdue);
    }
    
    /**
     * Get EPI schedule description (human-readable)
     * GET /api/vaccines/epi-schedule
     */
    public function getEpiSchedule() {
        $schedule = [
            'birth' => ['BCG', 'OPV-0'],
            '6_weeks' => ['OPV-1', 'Pentavalent-1', 'PCV-1', 'Rota-1'],
            '10_weeks' => ['OPV-2', 'Pentavalent-2', 'PCV-2', 'Rota-2'],
            '14_weeks' => ['OPV-3', 'Pentavalent-3', 'PCV-3', 'IPV'],
            '9_months' => ['MCV1 (Measles)'],
            '15_months' => ['MCV2 (Measles)']
        ];
        
        Response::success($schedule);
    }
}
?>