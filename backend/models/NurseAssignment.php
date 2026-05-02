<?php
/**
 * NurseAssignment Model - Handles all database operations related to nurse-child assignments
 * 
 * Tables: nurse_assignments
 * 
 * Responsibilities:
 * - Assign a nurse to a child (round-robin or specific)
 * - Get the assigned nurse for a child
 * - Get all children assigned to a specific nurse
 * - Get all assignments (admin view)
 * - Reassign a child to a different nurse
 * - Remove assignment (when child is transferred or deleted)
 * - Count assignments per nurse (for load balancing)
 * - Get nurse with least assignments (round-robin algorithm)
 */

require_once __DIR__ . '/../config/database.php';

class NurseAssignment {
    private $db;
    
    public function __construct() {
        $this->db = Database::getConnection();
    }
    
    /**
     * Assign a nurse to a child (insert or update if exists)
     * 
     * @param int $childId
     * @param int $nurseId
     * @return bool
     */
    public function assign($childId, $nurseId) {
        $stmt = $this->db->prepare("
            INSERT INTO nurse_assignments (child_id, nurse_id) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE nurse_id = VALUES(nurse_id), assigned_at = CURRENT_TIMESTAMP
        ");
        return $stmt->execute([$childId, $nurseId]);
    }
    
    /**
     * Get the nurse assigned to a specific child
     * 
     * @param int $childId
     * @return array|false (nurse_id, assigned_at)
     */
    public function getByChild($childId) {
        $stmt = $this->db->prepare("
            SELECT na.*, u.name as nurse_name, u.email as nurse_email, u.phone as nurse_phone
            FROM nurse_assignments na
            JOIN users u ON na.nurse_id = u.id
            WHERE na.child_id = ?
        ");
        $stmt->execute([$childId]);
        return $stmt->fetch();
    }
    
    /**
     * Get all children assigned to a specific nurse
     * 
     * @param int $nurseId
     * @return array
     */
    public function getByNurse($nurseId) {
        $stmt = $this->db->prepare("
            SELECT c.*, u.name as parent_name, u.phone as parent_phone, na.assigned_at
            FROM nurse_assignments na
            JOIN children c ON na.child_id = c.id
            JOIN users u ON c.parent_id = u.id
            WHERE na.nurse_id = ?
            ORDER BY na.assigned_at DESC
        ");
        $stmt->execute([$nurseId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get all assignments (admin view)
     * 
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getAll($limit = 100, $offset = 0) {
        $stmt = $this->db->prepare("
            SELECT na.*, 
                   c.name as child_name, c.unique_child_id,
                   u.name as parent_name,
                   n.name as nurse_name, n.email as nurse_email
            FROM nurse_assignments na
            JOIN children c ON na.child_id = c.id
            JOIN users u ON c.parent_id = u.id
            JOIN users n ON na.nurse_id = n.id
            ORDER BY na.assigned_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll();
    }
    
    /**
     * Remove assignment for a child (when child is deleted or unassigned)
     * 
     * @param int $childId
     * @return bool
     */
    public function removeAssignment($childId) {
        $stmt = $this->db->prepare("DELETE FROM nurse_assignments WHERE child_id = ?");
        return $stmt->execute([$childId]);
    }
    
    /**
     * Reassign a child to a different nurse
     * 
     * @param int $childId
     * @param int $newNurseId
     * @return bool
     */
    public function reassign($childId, $newNurseId) {
        return $this->assign($childId, $newNurseId);
    }
    
    /**
     * Get count of assignments per nurse (for load balancing)
     * 
     * @param int|null $nurseId Specific nurse ID or all nurses
     * @return array|int
     */
    public function getAssignmentCount($nurseId = null) {
        if ($nurseId) {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count FROM nurse_assignments WHERE nurse_id = ?
            ");
            $stmt->execute([$nurseId]);
            return (int)$stmt->fetch()['count'];
        } else {
            $stmt = $this->db->prepare("
                SELECT n.id as nurse_id, n.name, COUNT(na.child_id) as assignment_count
                FROM users n
                LEFT JOIN nurse_assignments na ON n.id = na.nurse_id
                WHERE n.role_id = 2
                GROUP BY n.id
                ORDER BY assignment_count ASC
            ");
            $stmt->execute();
            return $stmt->fetchAll();
        }
    }
    
    /**
     * Get the nurse with the least number of assigned children (for round-robin)
     * 
     * @return array|false (nurse_id, name, assignment_count)
     */
    public function getLeastLoadedNurse() {
        $stmt = $this->db->prepare("
            SELECT n.id as nurse_id, n.name, COUNT(na.child_id) as assignment_count
            FROM users n
            LEFT JOIN nurse_assignments na ON n.id = na.nurse_id
            WHERE n.role_id = 2
            GROUP BY n.id
            ORDER BY assignment_count ASC
            LIMIT 1
        ");
        $stmt->execute();
        return $stmt->fetch();
    }
    
    /**
     * Check if a child already has an assignment
     * 
     * @param int $childId
     * @return bool
     */
    public function hasAssignment($childId) {
        $stmt = $this->db->prepare("SELECT 1 FROM nurse_assignments WHERE child_id = ?");
        $stmt->execute([$childId]);
        return $stmt->fetch() !== false;
    }
    
    /**
     * Get total number of assignments
     * 
     * @return int
     */
    public function countTotal() {
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM nurse_assignments");
        return (int)$stmt->fetch()['count'];
    }
}
?>