<?php
/**
 * Child Model - Handles all database operations related to children
 * 
 * Tables: children
 * 
 * Responsibilities:
 * - Generate unique child ID (format: CH-XXXXXXXXXX)
 * - Create new child record
 * - Retrieve children by parent, nurse, or ID
 * - Search children by name, unique ID, or parent phone
 * - Verify child (set is_verified = TRUE)
 * - Update child information
 * - Delete child record (soft delete optional)
 */

require_once __DIR__ . '/../config/database.php';

class Child {
    private $db;
    
    public function __construct() {
        $this->db = Database::getConnection();
    }
    
    /**
     * Generate a unique child ID
     * Format: CH-XXXXXXXXXX (10 random alphanumeric characters)
     * 
     * @return string
     */
    public function generateUniqueId() {
        $prefix = 'CH-';
        $random = strtoupper(bin2hex(random_bytes(5))); // 10 characters
        $uniqueId = $prefix . $random;
        
        // Ensure uniqueness (very rare collision but check)
        $stmt = $this->db->prepare("SELECT id FROM children WHERE unique_child_id = ?");
        $stmt->execute([$uniqueId]);
        if ($stmt->fetch()) {
            return $this->generateUniqueId(); // Recursively generate new one
        }
        return $uniqueId;
    }
    
    /**
     * Create a new child record
     * 
     * @param array $data {
     *     parent_id: int,
     *     name: string,
     *     dob: string (Y-m-d),
     *     gender: string (Male/Female/Other),
     *     blood_type: string|null (A+, A-, etc.),
     *     allergies: string|null,
     *     birth_weight: float|null,
     *     delivery_type: string|null (Normal/C-Section),
     *     birth_place: string|null
     * }
     * @return int|false Last insert ID or false on failure
     */
    public function create($data) {
        $uniqueId = $this->generateUniqueId();
        
        $stmt = $this->db->prepare("
            INSERT INTO children (
                parent_id, unique_child_id, name, dob, gender, 
                blood_type, allergies, birth_weight, delivery_type, birth_place
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $data['parent_id'],
            $uniqueId,
            $data['name'],
            $data['dob'],
            $data['gender'],
            $data['blood_type'] ?? null,
            $data['allergies'] ?? null,
            $data['birth_weight'] ?? null,
            $data['delivery_type'] ?? null,
            $data['birth_place'] ?? null
        ]);
        
        return $result ? $this->db->lastInsertId() : false;
    }
    
    /**
     * Get child by ID
     * 
     * @param int $childId
     * @return array|false
     */
    public function getChildById($childId) {
        $stmt = $this->db->prepare("
            SELECT c.*, u.name as parent_name, u.phone as parent_phone, u.email as parent_email
            FROM children c
            JOIN users u ON c.parent_id = u.id
            WHERE c.id = ?
        ");
        $stmt->execute([$childId]);
        return $stmt->fetch();
    }
    
    /**
     * Get child by unique child ID (CH-XXXXXX)
     * 
     * @param string $uniqueChildId
     * @return array|false
     */
    public function getChildByUniqueId($uniqueChildId) {
        $stmt = $this->db->prepare("
            SELECT c.*, u.name as parent_name, u.phone as parent_phone
            FROM children c
            JOIN users u ON c.parent_id = u.id
            WHERE c.unique_child_id = ?
        ");
        $stmt->execute([$uniqueChildId]);
        return $stmt->fetch();
    }
    
    /**
     * Get all children for a specific parent
     * 
     * @param int $parentId
     * @return array
     */
    public function getChildrenByParent($parentId) {
        $stmt = $this->db->prepare("
            SELECT c.*, 
                   (SELECT COUNT(*) FROM appointments WHERE child_id = c.id AND status = 'completed') as completed_vaccines,
                   (SELECT COUNT(*) FROM appointments WHERE child_id = c.id) as total_vaccines
            FROM children c
            WHERE c.parent_id = ?
            ORDER BY c.created_at DESC
        ");
        $stmt->execute([$parentId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get all children assigned to a specific nurse (via nurse_assignments)
     * 
     * @param int $nurseId
     * @return array
     */
    public function getChildrenByNurse($nurseId) {
        $stmt = $this->db->prepare("
            SELECT c.*, u.name as parent_name, u.phone as parent_phone
            FROM children c
            JOIN nurse_assignments na ON c.id = na.child_id
            JOIN users u ON c.parent_id = u.id
            WHERE na.nurse_id = ?
            ORDER BY c.created_at DESC
        ");
        $stmt->execute([$nurseId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get all children (admin only)
     * 
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getAllChildren($limit = 100, $offset = 0) {
        $stmt = $this->db->prepare("
            SELECT c.*, u.name as parent_name, u.phone as parent_phone
            FROM children c
            JOIN users u ON c.parent_id = u.id
            ORDER BY c.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll();
    }
    
    /**
     * Search children by name, unique ID, or parent phone
     * 
     * @param string $keyword Search term
     * @param int|null $nurseId If provided, restrict to children assigned to this nurse
     * @return array
     */
    public function search($keyword, $nurseId = null) {
        $sql = "
            SELECT c.*, u.name as parent_name, u.phone as parent_phone
            FROM children c
            JOIN users u ON c.parent_id = u.id
            WHERE c.name LIKE ? 
               OR c.unique_child_id LIKE ? 
               OR u.phone LIKE ?
        ";
        $params = ["%$keyword%", "%$keyword%", "%$keyword%"];
        
        if ($nurseId) {
            $sql .= " AND c.id IN (SELECT child_id FROM nurse_assignments WHERE nurse_id = ?)";
            $params[] = $nurseId;
        }
        
        $sql .= " ORDER BY c.created_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * Update child information
     * 
     * @param int $childId
     * @param array $data Fields to update (name, blood_type, allergies, etc.)
     * @return bool
     */
    public function updateChild($childId, $data) {
        $allowedFields = ['name', 'blood_type', 'allergies', 'birth_weight', 'delivery_type', 'birth_place'];
        $updates = [];
        $params = [];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "$field = ?";
                $params[] = $data[$field];
            }
        }
        
        if (empty($updates)) {
            return false;
        }
        
        $params[] = $childId;
        $sql = "UPDATE children SET " . implode(", ", $updates) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    /**
     * Verify child (set is_verified = TRUE)
     * 
     * @param int $childId
     * @return bool
     */
    public function verifyChild($childId) {
        $stmt = $this->db->prepare("UPDATE children SET is_verified = TRUE WHERE id = ?");
        return $stmt->execute([$childId]);
    }
    
    /**
     * Unverify child (set is_verified = FALSE) - admin use
     * 
     * @param int $childId
     * @return bool
     */
    public function unverifyChild($childId) {
        $stmt = $this->db->prepare("UPDATE children SET is_verified = FALSE WHERE id = ?");
        return $stmt->execute([$childId]);
    }
    
    /**
     * Delete a child record (cascade delete appointments, assignments)
     * 
     * @param int $childId
     * @return bool
     */
    public function deleteChild($childId) {
        $stmt = $this->db->prepare("DELETE FROM children WHERE id = ?");
        return $stmt->execute([$childId]);
    }
    
    /**
     * Count children by parent
     * 
     * @param int $parentId
     * @return int
     */
    public function countByParent($parentId) {
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM children WHERE parent_id = ?");
        $stmt->execute([$parentId]);
        return (int)$stmt->fetch()['count'];
    }
    
    /**
     * Count total children (admin dashboard)
     * 
     * @return int
     */
    public function countTotal() {
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM children");
        return (int)$stmt->fetch()['count'];
    }
    
    /**
     * Get children due for vaccination in a date range
     * 
     * @param string $startDate
     * @param string $endDate
     * @param int|null $nurseId
     * @return array
     */
    public function getChildrenDueForVaccination($startDate, $endDate, $nurseId = null) {
        $sql = "
            SELECT DISTINCT c.*, a.scheduled_date, v.name as vaccine_name
            FROM children c
            JOIN appointments a ON c.id = a.child_id
            JOIN vaccines v ON a.vaccine_id = v.id
            WHERE a.scheduled_date BETWEEN ? AND ?
              AND a.status = 'pending'
        ";
        $params = [$startDate, $endDate];
        
        if ($nurseId) {
            $sql .= " AND c.id IN (SELECT child_id FROM nurse_assignments WHERE nurse_id = ?)";
            $params[] = $nurseId;
        }
        
        $sql .= " ORDER BY a.scheduled_date ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
?>