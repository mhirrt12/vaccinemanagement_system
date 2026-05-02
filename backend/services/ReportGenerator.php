<?php
/**
 * ReportGenerator Service - Generates various reports for nurses and admin
 * 
 * Responsibilities:
 * - Generate weekly/monthly vaccination reports for a nurse (assigned children only) or admin (all)
 * - Generate inventory usage report
 * - Generate child registration statistics
 * - Generate coverage rates (percentage of children fully vaccinated)
 * - Export reports to JSON, CSV, or HTML format
 * - Store report data in database for historical reference
 */

require_once __DIR__ . '/../models/Appointment.php';
require_once __DIR__ . '/../models/Child.php';
require_once __DIR__ . '/../models/Vaccine.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Inventory.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/Logger.php';

class ReportGenerator {
    private $appointmentModel;
    private $childModel;
    private $vaccineModel;
    private $userModel;
    private $inventoryModel;
    
    public function __construct() {
        $this->appointmentModel = new Appointment();
        $this->childModel = new Child();
        $this->vaccineModel = new Vaccine();
        $this->userModel = new User();
        $this->inventoryModel = new Inventory();
    }
    
    /**
     * Generate full report (admin) for a specific period
     * 
     * @param string $startDate (Y-m-d)
     * @param string $endDate (Y-m-d)
     * @param string $type (weekly or monthly)
     * @return array
     */
    public function generateFull($startDate, $endDate, $type) {
        $db = Database::getConnection();
        
        // Vaccination counts by vaccine
        $stmt = $db->prepare("
            SELECT v.name, COUNT(a.id) as count
            FROM appointments a
            JOIN vaccines v ON a.vaccine_id = v.id
            WHERE a.status = 'completed' AND a.given_date BETWEEN ? AND ?
            GROUP BY v.id
            ORDER BY v.days_from_birth ASC
        ");
        $stmt->execute([$startDate, $endDate]);
        $byVaccine = $stmt->fetchAll();
        
        // Total vaccinations
        $stmt = $db->prepare("
            SELECT COUNT(*) as total FROM appointments 
            WHERE status = 'completed' AND given_date BETWEEN ? AND ?
        ");
        $stmt->execute([$startDate, $endDate]);
        $totalVaccinations = $stmt->fetch()['total'];
        
        // Total children vaccinated (unique)
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT child_id) as total_children
            FROM appointments
            WHERE status = 'completed' AND given_date BETWEEN ? AND ?
        ");
        $stmt->execute([$startDate, $endDate]);
        $totalChildrenVaccinated = $stmt->fetch()['total_children'];
        
        // Missed appointments
        $stmt = $db->prepare("
            SELECT COUNT(*) as missed
            FROM appointments
            WHERE status = 'missed' AND scheduled_date BETWEEN ? AND ?
        ");
        $stmt->execute([$startDate, $endDate]);
        $missedAppointments = $stmt->fetch()['missed'];
        
        // New registrations (parents and children)
        $stmt = $db->prepare("
            SELECT 
                (SELECT COUNT(*) FROM users WHERE role_id = 1 AND created_at BETWEEN ? AND ?) as new_parents,
                (SELECT COUNT(*) FROM children WHERE created_at BETWEEN ? AND ?) as new_children
        ");
        $stmt->execute([$startDate, $endDate, $startDate, $endDate]);
        $newRegistrations = $stmt->fetch();
        
        // Coverage rate (fully vaccinated children)
        $totalChildren = $this->childModel->countTotal();
        $totalVaccines = $this->vaccineModel->getTotalVaccinesInSchedule();
        $fullyVaccinated = $db->prepare("
            SELECT COUNT(DISTINCT child_id) as count
            FROM appointments
            WHERE status = 'completed'
            GROUP BY child_id
            HAVING COUNT(*) >= ?
        ");
        $fullyVaccinated->execute([$totalVaccines]);
        $fullyVaccinatedCount = $fullyVaccinated->fetch()['count'] ?? 0;
        $coverageRate = $totalChildren > 0 ? round(($fullyVaccinatedCount / $totalChildren) * 100, 2) : 0;
        
        // Daily breakdown
        $stmt = $db->prepare("
            SELECT DATE(given_date) as date, COUNT(*) as daily_count
            FROM appointments
            WHERE status = 'completed' AND given_date BETWEEN ? AND ?
            GROUP BY DATE(given_date)
            ORDER BY date ASC
        ");
        $stmt->execute([$startDate, $endDate]);
        $dailyBreakdown = $stmt->fetchAll();
        
        return [
            'report_type' => $type,
            'period_start' => $startDate,
            'period_end' => $endDate,
            'generated_at' => date('Y-m-d H:i:s'),
            'summary' => [
                'total_vaccinations' => (int)$totalVaccinations,
                'total_children_vaccinated' => (int)$totalChildrenVaccinated,
                'missed_appointments' => (int)$missedAppointments,
                'new_parents' => (int)$newRegistrations['new_parents'],
                'new_children' => (int)$newRegistrations['new_children'],
                'coverage_rate' => $coverageRate,
                'fully_vaccinated_children' => (int)$fullyVaccinatedCount,
                'total_children' => $totalChildren
            ],
            'by_vaccine' => $byVaccine,
            'daily_breakdown' => $dailyBreakdown
        ];
    }
    
    /**
     * Generate report for a specific nurse (assigned children only)
     * 
     * @param int $nurseId
     * @param string $startDate
     * @param string $endDate
     * @param string $type
     * @return array
     */
    public function generateForNurse($nurseId, $startDate, $endDate, $type) {
        $db = Database::getConnection();
        
        // Get children assigned to this nurse
        $children = $this->childModel->getChildrenByNurse($nurseId);
        $childIds = array_column($children, 'id');
        
        if (empty($childIds)) {
            return [
                'report_type' => $type,
                'period_start' => $startDate,
                'period_end' => $endDate,
                'generated_at' => date('Y-m-d H:i:s'),
                'summary' => [
                    'total_vaccinations' => 0,
                    'total_children_vaccinated' => 0,
                    'missed_appointments' => 0,
                    'assigned_children' => 0
                ],
                'by_vaccine' => [],
                'children_list' => []
            ];
        }
        
        $placeholders = implode(',', array_fill(0, count($childIds), '?'));
        
        // Vaccination counts by vaccine for assigned children
        $stmt = $db->prepare("
            SELECT v.name, COUNT(a.id) as count
            FROM appointments a
            JOIN vaccines v ON a.vaccine_id = v.id
            WHERE a.status = 'completed' 
              AND a.given_date BETWEEN ? AND ?
              AND a.child_id IN ($placeholders)
            GROUP BY v.id
            ORDER BY v.days_from_birth ASC
        ");
        $params = array_merge([$startDate, $endDate], $childIds);
        $stmt->execute($params);
        $byVaccine = $stmt->fetchAll();
        
        // Total vaccinations
        $stmt = $db->prepare("
            SELECT COUNT(*) as total FROM appointments 
            WHERE status = 'completed' 
              AND given_date BETWEEN ? AND ?
              AND child_id IN ($placeholders)
        ");
        $stmt->execute($params);
        $totalVaccinations = $stmt->fetch()['total'];
        
        // Total children vaccinated (unique)
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT child_id) as total_children
            FROM appointments
            WHERE status = 'completed' 
              AND given_date BETWEEN ? AND ?
              AND child_id IN ($placeholders)
        ");
        $stmt->execute($params);
        $totalChildrenVaccinated = $stmt->fetch()['total_children'];
        
        // Missed appointments
        $stmt = $db->prepare("
            SELECT COUNT(*) as missed
            FROM appointments
            WHERE status = 'missed' 
              AND scheduled_date BETWEEN ? AND ?
              AND child_id IN ($placeholders)
        ");
        $stmt->execute($params);
        $missedAppointments = $stmt->fetch()['missed'];
        
        // Child-level breakdown
        $childData = [];
        foreach ($children as $child) {
            $stmt = $db->prepare("
                SELECT COUNT(*) as completed
                FROM appointments
                WHERE child_id = ? AND status = 'completed' AND given_date BETWEEN ? AND ?
            ");
            $stmt->execute([$child['id'], $startDate, $endDate]);
            $completed = $stmt->fetch()['completed'];
            
            $stmt = $db->prepare("
                SELECT COUNT(*) as missed
                FROM appointments
                WHERE child_id = ? AND status = 'missed' AND scheduled_date BETWEEN ? AND ?
            ");
            $stmt->execute([$child['id'], $startDate, $endDate]);
            $missed = $stmt->fetch()['missed'];
            
            $childData[] = [
                'child_id' => $child['id'],
                'child_name' => $child['name'],
                'unique_child_id' => $child['unique_child_id'],
                'completed_vaccinations' => (int)$completed,
                'missed_appointments' => (int)$missed
            ];
        }
        
        return [
            'report_type' => $type,
            'period_start' => $startDate,
            'period_end' => $endDate,
            'generated_at' => date('Y-m-d H:i:s'),
            'nurse_id' => $nurseId,
            'summary' => [
                'total_vaccinations' => (int)$totalVaccinations,
                'total_children_vaccinated' => (int)$totalChildrenVaccinated,
                'missed_appointments' => (int)$missedAppointments,
                'assigned_children' => count($children)
            ],
            'by_vaccine' => $byVaccine,
            'children_list' => $childData
        ];
    }
    
    /**
     * Generate inventory usage report (admin only)
     * 
     * @param string $startDate
     * @param string $endDate
     * @return array
     */
    public function generateInventoryReport($startDate, $endDate) {
        $db = Database::getConnection();
        
        // Batches used in period
        $stmt = $db->prepare("
            SELECT v.name as vaccine_name, a.batch_number, COUNT(*) as times_used,
                   MIN(a.given_date) as first_used, MAX(a.given_date) as last_used
            FROM appointments a
            JOIN vaccines v ON a.vaccine_id = v.id
            WHERE a.status = 'completed' 
              AND a.given_date BETWEEN ? AND ?
              AND a.batch_number IS NOT NULL
            GROUP BY a.vaccine_id, a.batch_number
            ORDER BY v.name ASC
        ");
        $stmt->execute([$startDate, $endDate]);
        $batchUsage = $stmt->fetchAll();
        
        // Current stock status
        $currentStock = $this->inventoryModel->getLowStock(100000);
        
        // Expiring batches
        $expiring = $this->inventoryModel->getAllWithExpiryWarning(30);
        
        return [
            'period_start' => $startDate,
            'period_end' => $endDate,
            'generated_at' => date('Y-m-d H:i:s'),
            'batch_usage' => $batchUsage,
            'current_stock' => $currentStock,
            'expiring_batches' => $expiring
        ];
    }
    
    /**
     * Generate child registration statistics report
     * 
     * @param string $startDate
     * @param string $endDate
     * @return array
     */
    public function generateRegistrationReport($startDate, $endDate) {
        $db = Database::getConnection();
        
        // Parents registered
        $stmt = $db->prepare("
            SELECT DATE(created_at) as date, COUNT(*) as count
            FROM users
            WHERE role_id = 1 AND created_at BETWEEN ? AND ?
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
        $stmt->execute([$startDate, $endDate]);
        $parentsDaily = $stmt->fetchAll();
        
        // Children registered
        $stmt = $db->prepare("
            SELECT DATE(created_at) as date, COUNT(*) as count
            FROM children
            WHERE created_at BETWEEN ? AND ?
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
        $stmt->execute([$startDate, $endDate]);
        $childrenDaily = $stmt->fetchAll();
        
        $totalParents = $this->userModel->countByRole(1);
        $totalChildren = $this->childModel->countTotal();
        
        $stmt = $db->prepare("
            SELECT COUNT(*) as verified_children FROM children WHERE is_verified = TRUE
        ");
        $stmt->execute();
        $verifiedChildren = $stmt->fetch()['verified_children'];
        
        return [
            'period_start' => $startDate,
            'period_end' => $endDate,
            'generated_at' => date('Y-m-d H:i:s'),
            'totals' => [
                'total_parents' => $totalParents,
                'total_children' => $totalChildren,
                'verified_children' => (int)$verifiedChildren,
                'verification_rate' => $totalChildren > 0 ? round(($verifiedChildren / $totalChildren) * 100, 2) : 0
            ],
            'parents_daily' => $parentsDaily,
            'children_daily' => $childrenDaily
        ];
    }
    
    /**
     * Export report data as CSV string
     * 
     * @param array $reportData
     * @param string $reportType
     * @return string
     */
    public function exportToCsv($reportData, $reportType) {
        $output = fopen('php://temp', 'r+');
        
        if ($reportType === 'vaccination') {
            fputcsv($output, ['Vaccine', 'Count']);
            foreach ($reportData['by_vaccine'] as $item) {
                fputcsv($output, [$item['name'], $item['count']]);
            }
            fputcsv($output, []);
            fputcsv($output, ['Summary']);
            fputcsv($output, ['Total Vaccinations', $reportData['summary']['total_vaccinations']]);
            fputcsv($output, ['Children Vaccinated', $reportData['summary']['total_children_vaccinated']]);
            fputcsv($output, ['Missed Appointments', $reportData['summary']['missed_appointments']]);
        } elseif ($reportType === 'children') {
            fputcsv($output, ['Child Name', 'Unique ID', 'Completed', 'Missed']);
            foreach ($reportData['children_list'] as $child) {
                fputcsv($output, [$child['child_name'], $child['unique_child_id'], $child['completed_vaccinations'], $child['missed_appointments']]);
            }
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }
    
    /**
     * Save report to database
     * 
     * @param int $generatedBy
     * @param string $type (weekly, monthly, inventory, registration)
     * @param array $data
     * @param string $startDate
     * @param string $endDate
     * @return int|false Report ID
     */
    public function saveReport($generatedBy, $type, $data, $startDate, $endDate) {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO reports (type, generated_by, data, period_start, period_end)
            VALUES (?, ?, ?, ?, ?)
        ");
        $dataJson = json_encode($data);
        $stmt->execute([$type, $generatedBy, $dataJson, $startDate, $endDate]);
        return $db->lastInsertId();
    }
}
?>