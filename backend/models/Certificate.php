<?php
/**
 * Certificate Model - Handles all database operations related to vaccination certificates
 * 
 * Tables: certificates
 * 
 * Responsibilities:
 * - Create a new certificate record for a child
 * - Get certificate by ID
 * - Get certificate by child ID
 * - Approve certificate by nurse (first approval step)
 * - Approve certificate by admin (final approval step)
 * - Get all certificates pending nurse or admin approval
 * - List all certificates (admin only)
 * - Update certificate file path
 * - Delete certificate
 * - Check if a child already has a certificate
 */

require_once __DIR__ . '/../config/database.php';

class Certificate {
    private $db;
    
    public function __construct() {
        $this->db = Database::getConnection();
    }
    
    /**
     * Create a new certificate record
     * 
     * @param int $childId
     * @param string $filePath (path to generated certificate file)
     * @return int|false Last insert ID or false on failure
     */
    public function create($childId, $filePath) {
        // Check if certificate already exists for this child
        if ($this->getByChild($childId)) {
            return false;
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO certificates (child_id, file_path, is_approved_by_nurse, is_approved_by_admin)
            VALUES (?, ?, FALSE, FALSE)
        ");
        $result = $stmt->execute([$childId, $filePath]);
        return $result ? $this->db->lastInsertId() : false;
    }
    
    /**
     * Get certificate by ID
     * 
     * @param int $certificateId
     * @return array|false
     */
    public function getById($certificateId) {
        $stmt = $this->db->prepare("
            SELECT c.*, ch.name as child_name, ch.unique_child_id, u.name as parent_name
            FROM certificates c
            JOIN children ch ON c.child_id = ch.id
            JOIN users u ON ch.parent_id = u.id
            WHERE c.id = ?
        ");
        $stmt->execute([$certificateId]);
        return $stmt->fetch();
    }
    
    /**
     * Get certificate by child ID
     * 
     * @param int $childId
     * @return array|false
     */
    public function getByChild($childId) {
        $stmt = $this->db->prepare("
            SELECT c.*, ch.name as child_name, ch.unique_child_id
            FROM certificates c
            JOIN children ch ON c.child_id = ch.id
            WHERE c.child_id = ?
        ");
        $stmt->execute([$childId]);
        return $stmt->fetch();
    }
    
    /**
     * Update certificate file path (if regenerated)
     * 
     * @param int $certificateId
     * @param string $newFilePath
     * @return bool
     */
    public function updateFilePath($certificateId, $newFilePath) {
        $stmt = $this->db->prepare("UPDATE certificates SET file_path = ? WHERE id = ?");
        return $stmt->execute([$newFilePath, $certificateId]);
    }
    
    /**
     * Approve certificate by nurse (first approval step)
     * 
     * @param int $certificateId
     * @return bool
     */
    public function approveByNurse($certificateId) {
        $stmt = $this->db->prepare("
            UPDATE certificates 
            SET is_approved_by_nurse = TRUE 
            WHERE id = ? AND is_approved_by_nurse = FALSE
        ");
        return $stmt->execute([$certificateId]);
    }
    
    /**
     * Approve certificate by admin (final approval step)
     * 
     * @param int $certificateId
     * @return bool
     */
    public function approveByAdmin($certificateId) {
        $stmt = $this->db->prepare("
            UPDATE certificates 
            SET is_approved_by_admin = TRUE, approved_at = NOW() 
            WHERE id = ? AND is_approved_by_admin = FALSE
        ");
        return $stmt->execute([$certificateId]);
    }
    
    /**
     * Get all certificates pending nurse approval
     * 
     * @return array
     */
    public function getPendingNurseApproval() {
        $stmt = $this->db->prepare("
            SELECT c.*, ch.name as child_name, ch.unique_child_id, u.name as parent_name
            FROM certificates c
            JOIN children ch ON c.child_id = ch.id
            JOIN users u ON ch.parent_id = u.id
            WHERE c.is_approved_by_nurse = FALSE
            ORDER BY c.created_at ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * Get all certificates pending admin approval (already approved by nurse)
     * 
     * @return array
     */
    public function getPendingAdminApproval() {
        $stmt = $this->db->prepare("
            SELECT c.*, ch.name as child_name, ch.unique_child_id, u.name as parent_name
            FROM certificates c
            JOIN children ch ON c.child_id = ch.id
            JOIN users u ON ch.parent_id = u.id
            WHERE c.is_approved_by_nurse = TRUE AND c.is_approved_by_admin = FALSE
            ORDER BY c.created_at ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * Get all certificates (admin only) with pagination
     * 
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getAll($limit = 100, $offset = 0) {
        $stmt = $this->db->prepare("
            SELECT c.*, ch.name as child_name, ch.unique_child_id, u.name as parent_name
            FROM certificates c
            JOIN children ch ON c.child_id = ch.id
            JOIN users u ON ch.parent_id = u.id
            ORDER BY c.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get count of certificates by approval status
     * 
     * @param string|null $status (pending_nurse, pending_admin, approved)
     * @return int
     */
    public function countByStatus($status = null) {
        if ($status === 'pending_nurse') {
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM certificates WHERE is_approved_by_nurse = FALSE");
        } elseif ($status === 'pending_admin') {
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM certificates WHERE is_approved_by_nurse = TRUE AND is_approved_by_admin = FALSE");
        } elseif ($status === 'approved') {
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM certificates WHERE is_approved_by_nurse = TRUE AND is_approved_by_admin = TRUE");
        } else {
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM certificates");
        }
        $stmt->execute();
        return (int)$stmt->fetch()['count'];
    }
    
    /**
     * Delete a certificate record and optionally remove the file
     * 
     * @param int $certificateId
     * @param bool $deleteFile Whether to delete the physical file
     * @return bool
     */
    public function delete($certificateId, $deleteFile = true) {
        if ($deleteFile) {
            $cert = $this->getById($certificateId);
            if ($cert && file_exists($cert['file_path'])) {
                unlink($cert['file_path']);
            }
        }
        $stmt = $this->db->prepare("DELETE FROM certificates WHERE id = ?");
        return $stmt->execute([$certificateId]);
    }
    
    /**
     * Delete certificate by child ID
     * 
     * @param int $childId
     * @param bool $deleteFile
     * @return bool
     */
    public function deleteByChild($childId, $deleteFile = true) {
        $cert = $this->getByChild($childId);
        if (!$cert) {
            return true;
        }
        return $this->delete($cert['id'], $deleteFile);
    }
    
    /**
     * Check if a child has a fully approved certificate
     * 
     * @param int $childId
     * @return bool
     */
    public function isFullyApproved($childId) {
        $cert = $this->getByChild($childId);
        return $cert && $cert['is_approved_by_nurse'] && $cert['is_approved_by_admin'];
    }
}
?>