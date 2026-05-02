<?php
/**
 * User Model - Handles all database operations related to users
 * 
 * Tables: users, roles
 * 
 * Responsibilities:
 * - Create, read, update user accounts
 * - Password hashing and verification
 * - Role-based queries (parents, nurses, admin)
 * - Parent approval workflow
 * - Fetch user by email, phone, or ID
 */

require_once __DIR__ . '/../config/database.php';

class User {
    private $db;
    
    public function __construct() {
        $this->db = Database::getConnection();
    }
    
    /**
     * Find user by ID
     * 
     * @param int $id
     * @return array|false
     */
    public function findById($id) {
        $stmt = $this->db->prepare("
            SELECT u.*, r.name as role_name 
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE u.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    /**
     * Find user by email address
     * 
     * @param string $email
     * @return array|false
     */
    public function findByEmail($email) {
        $stmt = $this->db->prepare("
            SELECT u.*, r.name as role_name 
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE u.email = ?
        ");
        $stmt->execute([$email]);
        return $stmt->fetch();
    }
    
    /**
     * Find user by phone number
     * 
     * @param string $phone
     * @return array|false
     */
    public function findByPhone($phone) {
        $stmt = $this->db->prepare("
            SELECT u.*, r.name as role_name 
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE u.phone = ?
        ");
        $stmt->execute([$phone]);
        return $stmt->fetch();
    }
    
    /**
     * Create a new user account
     * 
     * @param string $name
     * @param string $email
     * @param string $phone
     * @param string $password (plain text, will be hashed)
     * @param int $roleId (1=parent, 2=nurse, 3=admin)
     * @return int|false Last insert ID or false on failure
     */
    public function create($name, $email, $phone, $password, $roleId = 1) {
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        
        $stmt = $this->db->prepare("
            INSERT INTO users (name, email, phone, password_hash, role_id) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        try {
            $stmt->execute([$name, $email, $phone, $hashedPassword, $roleId]);
            return $this->db->lastInsertId();
        } catch (PDOException $e) {
            // Duplicate entry error (email or phone already exists)
            if ($e->errorInfo[1] == 1062) {
                return false;
            }
            throw $e;
        }
    }
    
    /**
     * Create a nurse account (role_id = 2, automatically verified)
     * 
     * @param string $name
     * @param string $email
     * @param string $phone
     * @param string $password
     * @return int|false
     */
    public function createNurse($name, $email, $phone, $password) {
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        
        $stmt = $this->db->prepare("
            INSERT INTO users (name, email, phone, password_hash, role_id, is_verified) 
            VALUES (?, ?, ?, ?, 2, TRUE)
        ");
        
        try {
            $stmt->execute([$name, $email, $phone, $hashedPassword]);
            return $this->db->lastInsertId();
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) {
                return false;
            }
            throw $e;
        }
    }
    
    /**
     * Approve a parent account (set is_verified = TRUE)
     * 
     * @param int $parentId
     * @param int $approvedBy (nurse or admin ID)
     * @return bool
     */
    public function approveParent($parentId, $approvedBy) {
        $stmt = $this->db->prepare("
            UPDATE users 
            SET is_verified = TRUE, approved_by = ? 
            WHERE id = ? AND role_id = 1
        ");
        return $stmt->execute([$approvedBy, $parentId]);
    }
    
    /**
     * Get all pending parent registrations (is_verified = FALSE)
     * 
     * @return array
     */
    public function getPendingParents() {
        $stmt = $this->db->prepare("
            SELECT id, name, email, phone, created_at 
            FROM users 
            WHERE role_id = 1 AND is_verified = FALSE
            ORDER BY created_at ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * Get all nurses
     * 
     * @return array
     */
    public function getNurses() {
        $stmt = $this->db->prepare("
            SELECT id, name, email, phone, created_at 
            FROM users 
            WHERE role_id = 2
            ORDER BY name ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * Delete a nurse account (role_id = 2)
     * 
     * @param int $id
     * @return bool
     */
    public function deleteNurse($id) {
        $stmt = $this->db->prepare("
            DELETE FROM users 
            WHERE id = ? AND role_id = 2
        ");
        return $stmt->execute([$id]);
    }
    
    /**
     * Get all parents
     * 
     * @param bool $verifiedOnly Whether to return only verified parents
     * @return array
     */
    public function getParents($verifiedOnly = false) {
        $sql = "SELECT id, name, email, phone, is_verified, created_at FROM users WHERE role_id = 1";
        if ($verifiedOnly) {
            $sql .= " AND is_verified = TRUE";
        }
        $sql .= " ORDER BY created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * Update user profile information
     * 
     * @param int $userId
     * @param array $data (name, email, phone)
     * @return bool
     */
    public function updateProfile($userId, $data) {
        $fields = [];
        $params = [];
        
        if (isset($data['name'])) {
            $fields[] = "name = ?";
            $params[] = $data['name'];
        }
        if (isset($data['email'])) {
            $fields[] = "email = ?";
            $params[] = $data['email'];
        }
        if (isset($data['phone'])) {
            $fields[] = "phone = ?";
            $params[] = $data['phone'];
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $params[] = $userId;
        $sql = "UPDATE users SET " . implode(", ", $fields) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    /**
     * Change user password
     * 
     * @param int $userId
     * @param string $newPassword (plain text)
     * @return bool
     */
    public function updatePassword($userId, $newPassword) {
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $this->db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        return $stmt->execute([$hashedPassword, $userId]);
    }
    
    /**
     * Verify if a password matches the stored hash
     * 
     * @param string $password
     * @param string $hash
     * @return bool
     */
    public function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
    
    /**
     * Get user role name by role_id
     * 
     * @param int $roleId
     * @return string|null
     */
    public function getRoleName($roleId) {
        $stmt = $this->db->prepare("SELECT name FROM roles WHERE id = ?");
        $stmt->execute([$roleId]);
        $role = $stmt->fetch();
        return $role ? $role['name'] : null;
    }
    /**
 * Get users by role name
 * 
 * @param string $roleName 'parent', 'nurse', or 'admin'
 * @return array
 */
public function getByRole($roleName) {
    $stmt = $this->db->prepare("
        SELECT u.* FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE r.name = ?
    ");
    $stmt->execute([$roleName]);
    return $stmt->fetchAll();
}
     /**
     * Count users by role ID
     * 
     * @param int $roleId (1=parent, 2=nurse, 3=admin)
     * @return int
     */
    public function countByRole($roleId) {
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM users WHERE role_id = ?");
        $stmt->execute([$roleId]);
        return (int)$stmt->fetch()['count'];
    }
    
    /**
     * Check if email already exists (excluding a specific user ID)
     * 
     * @param string $email
     * @param int $excludeUserId (optional)
     * @return bool
     */
    public function emailExists($email, $excludeUserId = null) {
        $sql = "SELECT id FROM users WHERE email = ?";
        $params = [$email];
        if ($excludeUserId) {
            $sql .= " AND id != ?";
            $params[] = $excludeUserId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() !== false;
    }
    
    /**
     * Check if phone already exists (excluding a specific user ID)
     * 
     * @param string $phone
     * @param int $excludeUserId (optional)
     * @return bool
     */
    public function phoneExists($phone, $excludeUserId = null) {
        $sql = "SELECT id FROM users WHERE phone = ?";
        $params = [$phone];
        if ($excludeUserId) {
            $sql .= " AND id != ?";
            $params[] = $excludeUserId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() !== false;
    }
}
?>