<?php
/**
 * CertificateController - Handles vaccination certificate operations
 * 
 * Responsibilities:
 * - Generate PDF certificate for a child (full vaccination record)
 * - Request certificate generation (parent action)
 * - Get certificate status and details
 * - Download certificate (after approvals)
 * - Admin: approve certificate (final step)
 * - Nurse: approve certificate (first step)
 * - List all certificates (admin only)
 * - Verify certificate by unique ID (public endpoint for validation)
 * 
 * Access Control:
 * - Parents: request, view status, download (after approvals)
 * - Nurses: approve certificates for children assigned to them
 * - Admin: full access, final approval
 * 
 * The certificate generation uses a simple HTML-to-PDF approach; in production,
 * use a library like Dompdf or TCPDF. This implementation creates an HTML string
 * and saves it as a .html file (or PDF with proper library).
 */

require_once __DIR__ . '/../models/Certificate.php';
require_once __DIR__ . '/../models/Child.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Appointment.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/Logger.php';
require_once __DIR__ . '/../config/database.php';

class CertificateController {
    private $certificateModel;
    private $childModel;
    private $userModel;
    private $appointmentModel;
    private $notificationService;
    
    public function __construct() {
        $this->certificateModel = new Certificate();
        $this->childModel = new Child();
        $this->userModel = new User();
        $this->appointmentModel = new Appointment();
        $this->notificationService = new NotificationService();
    }
    
    /**
     * Generate a certificate for a child (called by parent or nurse)
     * POST /api/certificates/generate
     * Input: { "child_id": 123 }
     */
    public function generate() {
        global $authPayload;
        $input = json_decode(file_get_contents('php://input'), true);
        $childId = $input['child_id'] ?? 0;
        
        if (!$childId) {
            Response::badRequest('Child ID is required');
            return;
        }
        
        // Verify access
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
                Response::forbidden('You are not assigned to this child');
                return;
            }
        }
        // Admin has full access
        
        // Check if certificate already exists
        $existing = $this->certificateModel->getByChild($childId);
        if ($existing) {
            Response::success($existing, 'Certificate already exists');
            return;
        }
        
        // Generate certificate content
        $certificateContent = $this->generateCertificateContent($childId);
        if (!$certificateContent) {
            Response::internalError('Failed to generate certificate content');
            return;
        }
        
        // Save certificate file
        $uploadDir = __DIR__ . '/../../storage/certificates/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $fileName = 'certificate_' . $child['unique_child_id'] . '_' . time() . '.html';
        $filePath = $uploadDir . $fileName;
        file_put_contents($filePath, $certificateContent);
        
        // Create record in database
        $certificateId = $this->certificateModel->create($childId, $filePath);
        if (!$certificateId) {
            unlink($filePath);
            Response::internalError('Failed to save certificate record');
            return;
        }
        
        Logger::info("Certificate generated", $userId, ['child_id' => $childId]);
        Response::created(['certificate_id' => $certificateId], 'Certificate generated successfully. Pending nurse and admin approval.');
    }
    
    /**
     * Get certificate details for a child
     * GET /api/certificates/child/{childId}
     */
    public function getByChild($childId) {
        global $authPayload;
        
        $child = $this->childModel->getChildById($childId);
        if (!$child) {
            Response::notFound('Child not found');
            return;
        }
        
        // Verify access
        $role = $authPayload['role'];
        $userId = $authPayload['user_id'];
        
        if ($role === 'parent' && $child['parent_id'] != $userId) {
            Response::forbidden('Forbidden');
            return;
        }
        if ($role === 'nurse') {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT 1 FROM nurse_assignments WHERE child_id = ? AND nurse_id = ?");
            $stmt->execute([$childId, $userId]);
            if (!$stmt->fetch()) {
                Response::forbidden('Forbidden');
                return;
            }
        }
        
        $certificate = $this->certificateModel->getByChild($childId);
        if (!$certificate) {
            Response::notFound('Certificate not found for this child');
            return;
        }
        
        // Add approval status details
        $certificate['is_fully_approved'] = ($certificate['is_approved_by_nurse'] && $certificate['is_approved_by_admin']);
        $certificate['download_url'] = "/api/certificates/download/{$certificate['id']}";
        
        Response::success($certificate);
    }
    
    /**
     * Download certificate (requires full approval)
     * GET /api/certificates/download/{certificateId}
     */
    public function download($certificateId) {
        global $authPayload;
        
        $certificate = $this->certificateModel->getById($certificateId);
        if (!$certificate) {
            Response::notFound('Certificate not found');
            return;
        }
        
        $child = $this->childModel->getChildById($certificate['child_id']);
        if (!$child) {
            Response::notFound('Child not found');
            return;
        }
        
        // Verify access
        $role = $authPayload['role'];
        $userId = $authPayload['user_id'];
        
        if ($role === 'parent' && $child['parent_id'] != $userId) {
            Response::forbidden('Forbidden');
            return;
        }
        if ($role === 'nurse') {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT 1 FROM nurse_assignments WHERE child_id = ? AND nurse_id = ?");
            $stmt->execute([$child['id'], $userId]);
            if (!$stmt->fetch()) {
                Response::forbidden('Forbidden');
                return;
            }
        }
        
        // Check approval status
        if (!$certificate['is_approved_by_nurse'] || !$certificate['is_approved_by_admin']) {
            Response::forbidden('Certificate is not fully approved yet. Please wait for nurse and admin approval.');
            return;
        }
        
        // Check if file exists
        $filePath = $certificate['file_path'];
        if (!file_exists($filePath)) {
            Response::notFound('Certificate file not found on server');
            return;
        }
        
        // Log download
        Logger::info("Certificate downloaded", $userId, ['certificate_id' => $certificateId, 'child_id' => $child['id']]);
        
        // Serve file
        $fileExtension = pathinfo($filePath, PATHINFO_EXTENSION);
        $contentType = $fileExtension === 'pdf' ? 'application/pdf' : 'text/html';
        $fileName = 'vaccination_certificate_' . $child['unique_child_id'] . '.' . $fileExtension;
        
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
    
    /**
     * Nurse approves a certificate (first approval step)
     * POST /api/certificates/nurse-approve/{certificateId}
     */
    public function nurseApprove($certificateId) {
        global $authPayload;
        $nurseId = $authPayload['user_id'];
        
        $certificate = $this->certificateModel->getById($certificateId);
        if (!$certificate) {
            Response::notFound('Certificate not found');
            return;
        }
        
        // Verify nurse is assigned to this child
        $child = $this->childModel->getChildById($certificate['child_id']);
        if (!$child) {
            Response::notFound('Child not found');
            return;
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT 1 FROM nurse_assignments WHERE child_id = ? AND nurse_id = ?");
        $stmt->execute([$child['id'], $nurseId]);
        if (!$stmt->fetch()) {
            Response::forbidden('You are not assigned to this child');
            return;
        }
        
        $result = $this->certificateModel->approveByNurse($certificateId);
        if (!$result) {
            Response::internalError('Failed to approve certificate');
            return;
        }
        
        // Notify admin
        $adminStmt = $db->prepare("SELECT id FROM users WHERE role_id = 3");
        $adminStmt->execute();
        $admins = $adminStmt->fetchAll();
        foreach ($admins as $admin) {
            $this->notificationService->createNotification(
                $admin['id'],
                $child['id'],
                'Certificate Awaiting Admin Approval',
                "Certificate for {$child['name']} has been approved by nurse. Please review and approve.",
                'certificate_ready'
            );
        }
        
        Logger::info("Certificate approved by nurse", $nurseId, ['certificate_id' => $certificateId]);
        Response::success(null, 'Certificate approved by nurse. Awaiting admin approval.');
    }
    
    /**
     * Admin approves a certificate (final approval step)
     * POST /api/certificates/admin-approve/{certificateId}
     */
    public function adminApprove($certificateId) {
        global $authPayload;
        $adminId = $authPayload['user_id'];
        
        $certificate = $this->certificateModel->getById($certificateId);
        if (!$certificate) {
            Response::notFound('Certificate not found');
            return;
        }
        
        if (!$certificate['is_approved_by_nurse']) {
            Response::badRequest('Certificate must be approved by nurse first');
            return;
        }
        
        $result = $this->certificateModel->approveByAdmin($certificateId);
        if (!$result) {
            Response::internalError('Failed to approve certificate');
            return;
        }
        
        // Get child and parent details
        $child = $this->childModel->getChildById($certificate['child_id']);
        if ($child) {
            $this->notificationService->createNotification(
                $child['parent_id'],
                $child['id'],
                'Certificate Fully Approved',
                "The vaccination certificate for {$child['name']} has been fully approved. You can now download it.",
                'certificate_ready'
            );
        }
        
        Logger::info("Certificate approved by admin", $adminId, ['certificate_id' => $certificateId]);
        Response::success(null, 'Certificate fully approved. Parent can now download.');
    }
    
    /**
     * Get all certificates pending approval (for admin dashboard)
     * GET /api/certificates/pending
     */
    public function getPending() {
        global $authPayload;
        
        if ($authPayload['role'] !== 'admin') {
            Response::forbidden('Only admin can view pending certificates');
            return;
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT c.*, ch.name as child_name, ch.unique_child_id, u.name as parent_name
            FROM certificates c
            JOIN children ch ON c.child_id = ch.id
            JOIN users u ON ch.parent_id = u.id
            WHERE (c.is_approved_by_nurse = FALSE OR c.is_approved_by_admin = FALSE)
            ORDER BY c.created_at DESC
        ");
        $stmt->execute();
        $certificates = $stmt->fetchAll();
        
        foreach ($certificates as &$cert) {
            $cert['status'] = $this->getStatusText($cert);
        }
        
        Response::success($certificates);
    }
    
    /**
     * Verify a certificate by unique child ID (public endpoint for validation)
     * GET /api/certificates/verify/{uniqueChildId}
     */
    public function verify($uniqueChildId) {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT c.*, ch.name as child_name, ch.dob, ch.unique_child_id
            FROM certificates c
            JOIN children ch ON c.child_id = ch.id
            WHERE ch.unique_child_id = ? AND c.is_approved_by_nurse = TRUE AND c.is_approved_by_admin = TRUE
        ");
        $stmt->execute([$uniqueChildId]);
        $certificate = $stmt->fetch();
        
        if (!$certificate) {
            Response::notFound('No valid certificate found for this child ID');
            return;
        }
        
        Response::success([
            'unique_child_id' => $certificate['unique_child_id'],
            'child_name' => $certificate['child_name'],
            'date_of_birth' => $certificate['dob'],
            'certificate_issued_at' => $certificate['approved_at'],
            'is_valid' => true
        ]);
    }
    
    /**
     * List all certificates (admin only)
     * GET /api/certificates?limit=50&offset=0
     */
    public function listAll() {
        global $authPayload;
        
        if ($authPayload['role'] !== 'admin') {
            Response::forbidden('Only admin can list all certificates');
            return;
        }
        
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT c.*, ch.name as child_name, ch.unique_child_id, u.name as parent_name
            FROM certificates c
            JOIN children ch ON c.child_id = ch.id
            JOIN users u ON ch.parent_id = u.id
            ORDER BY c.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        $certificates = $stmt->fetchAll();
        
        $countStmt = $db->query("SELECT COUNT(*) as total FROM certificates");
        $total = $countStmt->fetch()['total'];
        
        Response::success([
            'certificates' => $certificates,
            'total' => (int)$total,
            'limit' => $limit,
            'offset' => $offset
        ]);
    }
    
    /**
     * Generate HTML content for certificate
     */
    private function generateCertificateContent($childId) {
        $child = $this->childModel->getChildById($childId);
        if (!$child) return null;
        
        $parent = $this->userModel->findById($child['parent_id']);
        $appointments = $this->appointmentModel->getByChild($childId);
        
        // Filter completed vaccinations
        $completedVaccines = array_filter($appointments, function($app) {
            return $app['status'] === 'completed';
        });
        
        $html = '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Vaccination Certificate</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; }
                .certificate { border: 2px solid #007bff; padding: 30px; max-width: 800px; margin: 0 auto; }
                .header { text-align: center; margin-bottom: 30px; }
                .header h1 { color: #007bff; margin: 0; }
                .header h3 { color: #555; }
                .seal { position: relative; float: right; width: 100px; height: 100px; }
                .content { margin: 30px 0; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #007bff; color: white; }
                .footer { text-align: center; margin-top: 30px; font-size: 12px; color: #777; }
                .signature { margin-top: 40px; }
            </style>
        </head>
        <body>
            <div class="certificate">
                <div class="header">
                    <h1>VACCINATION CERTIFICATE</h1>
                    <h3>Ethiopian Federal Ministry of Health</h3>
                    <p>Vaccine Management System</p>
                </div>
                <div class="content">
                    <p><strong>Child Name:</strong> ' . htmlspecialchars($child['name']) . '</p>
                    <p><strong>Unique Child ID:</strong> ' . htmlspecialchars($child['unique_child_id']) . '</p>
                    <p><strong>Date of Birth:</strong> ' . $child['dob'] . '</p>
                    <p><strong>Gender:</strong> ' . $child['gender'] . '</p>
                    <p><strong>Parent Name:</strong> ' . htmlspecialchars($parent['name']) . '</p>
                    <p><strong>Blood Type:</strong> ' . ($child['blood_type'] ?? 'Not specified') . '</p>
                    <h4>Vaccination Record</h4>
                    <table>
                        <thead>
                            <tr><th>Vaccine</th><th>Date Given</th><th>Batch Number</th><th>Given By</th></tr>
                        </thead>
                        <tbody>';
        
        foreach ($completedVaccines as $vax) {
            $nurseName = '';
            if ($vax['nurse_id']) {
                $nurse = $this->userModel->findById($vax['nurse_id']);
                $nurseName = $nurse ? $nurse['name'] : 'Nurse';
            }
            $html .= '<tr><td>' . htmlspecialchars($vax['vaccine_name']) . '</td>
                      <td>' . ($vax['given_date'] ?? $vax['scheduled_date']) . '</td>
                      <td>' . ($vax['batch_number'] ?? 'N/A') . '</td>
                      <td>' . htmlspecialchars($nurseName) . '</td></tr>';
        }
        
        $html .= '</tbody>
                    </table>
                    <p><strong>Total Vaccines Received:</strong> ' . count($completedVaccines) . '</p>
                </div>
                <div class="footer">
                    <p>This certificate is issued under the authority of the Ethiopian Ministry of Health.</p>
                    <p>Certificate generated on: ' . date('F d, Y') . '</p>
                    <div class="signature">
                        <p>_________________________</p>
                        <p>Authorized Signature</p>
                    </div>
                </div>
            </div>
        </body>
        </html>';
        
        return $html;
    }
    
    /**
     * Helper: Get human-readable status text
     */
    private function getStatusText($certificate) {
        if ($certificate['is_approved_by_nurse'] && $certificate['is_approved_by_admin']) {
            return 'Fully Approved';
        } elseif ($certificate['is_approved_by_nurse']) {
            return 'Pending Admin Approval';
        } else {
            return 'Pending Nurse Approval';
        }
    }
}
?>