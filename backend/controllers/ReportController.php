<?php
/**
 * ReportController - Handles report generation and retrieval
 * 
 * Responsibilities:
 * - Generate weekly/monthly vaccination reports (for nurses)
 * - Generate inventory status report (admin only)
 * - Generate child registration statistics
 * - Export reports to CSV/PDF (placeholder)
 * - List previously generated reports
 * - View specific report data
 * 
 * Access Control:
 * - Nurses: generate reports for assigned children only
 * - Admin: generate full system reports, view all reports
 */

require_once __DIR__ . '/../models/Appointment.php';
require_once __DIR__ . '/../models/Child.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Inventory.php';
require_once __DIR__ . '/../services/ReportGenerator.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/Logger.php';
require_once __DIR__ . '/../config/database.php';

class ReportController {
    private $appointmentModel;
    private $childModel;
    private $userModel;
    private $inventoryModel;
    private $reportGenerator;
    private $notificationService;
    
    public function __construct() {
        $this->appointmentModel = new Appointment();
        $this->childModel = new Child();
        $this->userModel = new User();
        $this->inventoryModel = new Inventory();
        $this->reportGenerator = new ReportGenerator();
        $this->notificationService = new NotificationService();
    }
    
    /**
     * Generate a new report (weekly or monthly)
     * POST /api/reports/generate
     * Input: { "type": "weekly", "period_start": "2025-01-01", "period_end": "2025-01-07" }
     * For nurses, only their assigned children data is included.
     * For admin, full system data.
     */
    public function generate() {
        global $authPayload;
        $input = json_decode(file_get_contents('php://input'), true);
        
        $type = $input['type'] ?? 'weekly';
        $periodStart = $input['period_start'] ?? null;
        $periodEnd = $input['period_end'] ?? null;
        $format = $input['format'] ?? 'json'; // json, csv, pdf (placeholder)
        
        if (!in_array($type, ['weekly', 'monthly'])) {
            Response::badRequest('Invalid report type. Must be "weekly" or "monthly"');
            return;
        }
        
        if (!$periodStart || !$periodEnd) {
            Response::badRequest('Period start and end dates are required');
            return;
        }
        
        // Validate date range (max 31 days for weekly, 93 for monthly)
        $start = new DateTime($periodStart);
        $end = new DateTime($periodEnd);
        $days = $start->diff($end)->days;
        
        if ($type === 'weekly' && $days > 7) {
            Response::badRequest('Weekly report period cannot exceed 7 days');
            return;
        }
        if ($type === 'monthly' && $days > 31) {
            Response::badRequest('Monthly report period cannot exceed 31 days');
            return;
        }
        
        $role = $authPayload['role'];
        $userId = $authPayload['user_id'];
        
        // Generate report data
        if ($role === 'admin') {
            $reportData = $this->reportGenerator->generateFull($periodStart, $periodEnd, $type);
        } else if ($role === 'nurse') {
            $reportData = $this->reportGenerator->generateForNurse($userId, $periodStart, $periodEnd, $type);
        } else {
            Response::forbidden('Only nurses and admin can generate reports');
            return;
        }
        
        // Store report in database
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO reports (type, generated_by, data, period_start, period_end)
            VALUES (?, ?, ?, ?, ?)
        ");
        $dataJson = json_encode($reportData);
        $stmt->execute([$type, $userId, $dataJson, $periodStart, $periodEnd]);
        $reportId = $db->lastInsertId();
        
        // Generate file if requested (CSV/PDF)
        $filePath = null;
        if ($format === 'csv') {
            $filePath = $this->exportToCSV($reportData, $type, $periodStart, $periodEnd);
        } elseif ($format === 'pdf') {
            $filePath = $this->exportToPDF($reportData, $type, $periodStart, $periodEnd);
        }
        
        if ($filePath) {
            $db->prepare("UPDATE reports SET file_path = ? WHERE id = ?")->execute([$filePath, $reportId]);
        }
        
        // Notify admin if nurse generated report
        if ($role === 'nurse') {
            $stmt = $db->prepare("SELECT id FROM users WHERE role_id = 3");
          $stmt->execute();
          $admins = $stmt->fetchAll();
            foreach ($admins as $admin) {
                $this->notificationService->createNotification(
                    $admin['id'],
                    null,
                    'New Report Generated',
                    "Nurse {$authPayload['name']} generated a {$type} report for period {$periodStart} to {$periodEnd}.",
                    'report'
                );
            }
        }
        
        Logger::info("Report generated", $userId, ['type' => $type, 'period' => "$periodStart to $periodEnd"]);
        Response::created([
            'report_id' => $reportId,
            'data' => $reportData,
            'file_path' => $filePath
        ], 'Report generated successfully');
    }
    
    /**
     * Get all reports (with pagination)
     * GET /api/reports?limit=20&offset=0
     */
    public function getAll() {
        global $authPayload;
        $role = $authPayload['role'];
        $userId = $authPayload['user_id'];
        
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        
        $db = Database::getConnection();
        
        if ($role === 'admin') {
            $stmt = $db->prepare("
                SELECT r.*, u.name as generated_by_name
                FROM reports r
                JOIN users u ON r.generated_by = u.id
                ORDER BY r.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$limit, $offset]);
        } elseif ($role === 'nurse') {
            $stmt = $db->prepare("
                SELECT r.*, u.name as generated_by_name
                FROM reports r
                JOIN users u ON r.generated_by = u.id
                WHERE r.generated_by = ?
                ORDER BY r.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$userId, $limit, $offset]);
        } else {
            Response::forbidden('Only nurses and admin can view reports');
            return;
        }
        
        $reports = $stmt->fetchAll();
        
        // Get total count
        if ($role === 'admin') {
            $countStmt = $db->query("SELECT COUNT(*) as total FROM reports");
        } else {
            $countStmt = $db->prepare("SELECT COUNT(*) as total FROM reports WHERE generated_by = ?");
            $countStmt->execute([$userId]);
        }
        $total = $countStmt->fetch()['total'];
        
        Response::success([
            'reports' => $reports,
            'total' => (int)$total,
            'limit' => $limit,
            'offset' => $offset
        ]);
    }
    
    /**
     * Get a specific report by ID
     * GET /api/reports/{id}
     */
    public function getById($id) {
        global $authPayload;
        $role = $authPayload['role'];
        $userId = $authPayload['user_id'];
        
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT r.*, u.name as generated_by_name
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
        
        // Check access
        if ($role !== 'admin' && $report['generated_by'] != $userId) {
            Response::forbidden('You do not have access to this report');
            return;
        }
        
        // Decode JSON data
        $report['data'] = json_decode($report['data'], true);
        
        Response::success($report);
    }
    
    /**
     * Delete a report
     * DELETE /api/reports/{id}
     */
    public function delete($id) {
        global $authPayload;
        $role = $authPayload['role'];
        $userId = $authPayload['user_id'];
        
        $db = Database::getConnection();
        
        // Check ownership or admin
        if ($role !== 'admin') {
            $stmt = $db->prepare("SELECT generated_by FROM reports WHERE id = ?");
            $stmt->execute([$id]);
            $report = $stmt->fetch();
            if (!$report || $report['generated_by'] != $userId) {
                Response::forbidden('You can only delete your own reports');
                return;
            }
        }
        
        // Get file path to delete
        $stmt = $db->prepare("SELECT file_path FROM reports WHERE id = ?");
        $stmt->execute([$id]);
        $report = $stmt->fetch();
        if ($report && $report['file_path'] && file_exists($report['file_path'])) {
            unlink($report['file_path']);
        }
        
        $stmt = $db->prepare("DELETE FROM reports WHERE id = ?");
        $stmt->execute([$id]);
        
        Logger::info("Report deleted", $userId, ['report_id' => $id]);
        Response::success(null, 'Report deleted successfully');
    }
    
    /**
     * Download report file (CSV/PDF)
     * GET /api/reports/{id}/download
     */
    public function download($id) {
        global $authPayload;
        $role = $authPayload['role'];
        $userId = $authPayload['user_id'];
        
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT file_path, generated_by FROM reports WHERE id = ?");
        $stmt->execute([$id]);
        $report = $stmt->fetch();
        
        if (!$report || !$report['file_path']) {
            Response::notFound('Report file not found');
            return;
        }
        
        // Check access
        if ($role !== 'admin' && $report['generated_by'] != $userId) {
            Response::forbidden('Forbidden');
            return;
        }
        
        if (!file_exists($report['file_path'])) {
            Response::notFound('File does not exist on server');
            return;
        }
        
        Logger::info("Report downloaded", $userId, ['report_id' => $id]);
        
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($report['file_path']) . '"');
        header('Content-Length: ' . filesize($report['file_path']));
        readfile($report['file_path']);
        exit;
    }
    
    /**
     * Export report data to CSV
     */
    private function exportToCSV($data, $type, $periodStart, $periodEnd) {
        $uploadDir = __DIR__ . '/../../storage/reports/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $filename = 'report_' . $type . '_' . $periodStart . '_to_' . $periodEnd . '_' . time() . '.csv';
        $filepath = $uploadDir . $filename;
        
        $fp = fopen($filepath, 'w');
        
        // Write headers based on data structure
        if (isset($data['by_vaccine'])) {
            fputcsv($fp, ['Report Type', $type]);
            fputcsv($fp, ['Period', $periodStart . ' to ' . $periodEnd]);
            fputcsv($fp, []);
            fputcsv($fp, ['Vaccination by Vaccine']);
            fputcsv($fp, ['Vaccine', 'Count']);
            foreach ($data['by_vaccine'] as $item) {
                fputcsv($fp, [$item['name'], $item['count']]);
            }
            fputcsv($fp, []);
            fputcsv($fp, ['Total Vaccinations', $data['total_vaccinations']]);
            fputcsv($fp, ['Total Children Vaccinated', $data['total_children_vaccinated']]);
            fputcsv($fp, ['Missed Appointments', $data['missed_appointments']]);
        }
        
        fclose($fp);
        return $filepath;
    }
    
    /**
     * Export report data to PDF (placeholder - would require dompdf)
     */
    private function exportToPDF($data, $type, $periodStart, $periodEnd) {
        // In production, use Dompdf or TCPDF to generate PDF
        // For now, generate HTML and convert (placeholder)
        $uploadDir = __DIR__ . '/../../storage/reports/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $filename = 'report_' . $type . '_' . $periodStart . '_to_' . $periodEnd . '_' . time() . '.html';
        $filepath = $uploadDir . $filename;
        
        $html = $this->generateReportHTML($data, $type, $periodStart, $periodEnd);
        file_put_contents($filepath, $html);
        
        return $filepath;
    }
    
    /**
     * Generate HTML for report (for PDF export)
     */
    private function generateReportHTML($data, $type, $periodStart, $periodEnd) {
        $html = '<!DOCTYPE html>
        <html>
        <head><title>Vaccination Report</title>
        <style>
            body { font-family: Arial; margin: 40px; }
            h1 { color: #007bff; }
            table { width: 100%; border-collapse: collapse; margin: 20px 0; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background: #007bff; color: white; }
        </style>
        </head>
        <body>
            <h1>' . ucfirst($type) . ' Vaccination Report</h1>
            <p>Period: ' . $periodStart . ' to ' . $periodEnd . '</p>
            <h3>Vaccination by Type</h3>
            <table>
                <tr><th>Vaccine</th><th>Count</th></tr>';
        foreach ($data['by_vaccine'] as $item) {
            $html .= '<tr><td>' . htmlspecialchars($item['name']) . '</td><td>' . $item['count'] . '</td></tr>';
        }
        $html .= '</table>
            <p><strong>Total Vaccinations:</strong> ' . $data['total_vaccinations'] . '</p>
            <p><strong>Total Children Vaccinated:</strong> ' . $data['total_children_vaccinated'] . '</p>
            <p><strong>Missed Appointments:</strong> ' . $data['missed_appointments'] . '</p>
        </body>
        </html>';
        return $html;
    }
    
    /**
     * Get report statistics summary (dashboard widget)
     * GET /api/reports/summary
     */
    public function getSummary() {
        global $authPayload;
        
        $db = Database::getConnection();
        
        // This month's vaccinations
        $stmt = $db->query("
            SELECT COUNT(*) as count FROM appointments 
            WHERE status = 'completed' AND MONTH(given_date) = MONTH(CURDATE()) AND YEAR(given_date) = YEAR(CURDATE())
        ");
        $monthly = $stmt->fetch()['count'];
        
        // Last month's vaccinations
        $stmt = $db->query("
            SELECT COUNT(*) as count FROM appointments 
            WHERE status = 'completed' AND MONTH(given_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
        ");
        $lastMonthly = $stmt->fetch()['count'];
        
        // Total children
        $stmt = $db->query("SELECT COUNT(*) as count FROM children");
        $totalChildren = $stmt->fetch()['count'];
        
        // Vaccine coverage (children who have completed all vaccines)
        $stmt = $db->query("
            SELECT COUNT(DISTINCT child_id) as count 
            FROM appointments 
            WHERE status = 'completed' 
            GROUP BY child_id 
            HAVING COUNT(*) >= 16
        ");
        $fullyVaccinated = $stmt->rowCount();
        
        Response::success([
            'monthly_vaccinations' => (int)$monthly,
            'last_month_vaccinations' => (int)$lastMonthly,
            'total_children' => (int)$totalChildren,
            'fully_vaccinated_children' => $fullyVaccinated,
            'coverage_rate' => $totalChildren > 0 ? round(($fullyVaccinated / $totalChildren) * 100, 2) : 0
        ]);
    }
}
?>