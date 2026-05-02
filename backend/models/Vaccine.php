<?php
/**
 * Vaccine Model - Handles all database operations related to vaccines
 * 
 * Tables: vaccines
 * 
 * Responsibilities:
 * - Retrieve all active vaccines (EPI schedule)
 * - Get vaccine by ID
 * - Create new vaccine (admin only)
 * - Update existing vaccine
 * - Soft delete (toggle active flag)
 * - Get vaccine by name
 * - Get vaccine by days from birth
 */

require_once __DIR__ . '/../config/database.php';

class Vaccine {
    private $db;
    
    public function __construct() {
        $this->db = Database::getConnection();
    }
    
    /**
     * Get all active vaccines (is_active = TRUE)
     * 
     * @return array
     */
    public function getAll() {
        $stmt = $this->db->prepare("
            SELECT * FROM vaccines 
            WHERE is_active = TRUE 
            ORDER BY days_from_birth ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * Get all vaccines including inactive (admin only)
     * 
     * @return array
     */
    public function getAllIncludingInactive() {
        $stmt = $this->db->prepare("
            SELECT * FROM vaccines 
            ORDER BY days_from_birth ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * Get vaccine by ID
     * 
     * @param int $id
     * @return array|false
     */
    public function getById($id) {
        $stmt = $this->db->prepare("SELECT * FROM vaccines WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    /**
     * Get vaccine by name
     * 
     * @param string $name
     * @return array|false
     */
    public function getByName($name) {
        $stmt = $this->db->prepare("SELECT * FROM vaccines WHERE name = ?");
        $stmt->execute([$name]);
        return $stmt->fetch();
    }
    
    /**
     * Get vaccines due on or after a specific days offset
     * 
     * @param int $daysFromBirth
     * @return array
     */
    public function getByDaysOffset($daysFromBirth) {
        $stmt = $this->db->prepare("
            SELECT * FROM vaccines 
            WHERE days_from_birth >= ? AND is_active = TRUE 
            ORDER BY days_from_birth ASC
        ");
        $stmt->execute([$daysFromBirth]);
        return $stmt->fetchAll();
    }
    
    /**
     * Create a new vaccine (admin only)
     * 
     * @param string $name
     * @param int $daysFromBirth
     * @param string|null $description
     * @return int|false Last insert ID or false on failure
     */
    public function create($name, $daysFromBirth, $description = null) {
        // Check if vaccine already exists
        if ($this->getByName($name)) {
            return false;
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO vaccines (name, days_from_birth, description, is_active) 
            VALUES (?, ?, ?, TRUE)
        ");
        $result = $stmt->execute([$name, $daysFromBirth, $description]);
        return $result ? $this->db->lastInsertId() : false;
    }
    
    /**
     * Update an existing vaccine
     * 
     * @param int $id
     * @param string $name
     * @param int $daysFromBirth
     * @param string|null $description
     * @return bool
     */
    public function update($id, $name, $daysFromBirth, $description = null) {
        // Check if another vaccine has the same name (excluding this one)
        $stmt = $this->db->prepare("SELECT id FROM vaccines WHERE name = ? AND id != ?");
        $stmt->execute([$name, $id]);
        if ($stmt->fetch()) {
            return false;
        }
        
        $stmt = $this->db->prepare("
            UPDATE vaccines 
            SET name = ?, days_from_birth = ?, description = ? 
            WHERE id = ?
        ");
        return $stmt->execute([$name, $daysFromBirth, $description, $id]);
    }
    
    /**
     * Soft delete (toggle is_active flag)
     * 
     * @param int $id
     * @param bool $isActive
     * @return bool
     */
    public function toggleActive($id, $isActive) {
        $stmt = $this->db->prepare("UPDATE vaccines SET is_active = ? WHERE id = ?");
        return $stmt->execute([$isActive, $id]);
    }
    
    /**
     * Hard delete a vaccine (admin only, use with caution)
     * 
     * @param int $id
     * @return bool
     */
    public function delete($id) {
        $stmt = $this->db->prepare("DELETE FROM vaccines WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    /**
     * Get vaccine count
     * 
     * @return int
     */
    public function getCount() {
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM vaccines WHERE is_active = TRUE");
        return (int)$stmt->fetch()['count'];
    }
    
    /**
     * Get the total number of vaccines in EPI schedule (full course)
     * 
     * @return int
     */
    public function getTotalVaccinesInSchedule() {
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM vaccines WHERE is_active = TRUE");
        return (int)$stmt->fetch()['count'];
    }
    
    /**
     * Get vaccine with appointment counts (for reports)
     * 
     * @param string|null $startDate
     * @param string|null $endDate
     * @return array
     */
    public function getWithAppointmentCounts($startDate = null, $endDate = null) {
        $sql = "
            SELECT v.*, 
                   (SELECT COUNT(*) FROM appointments WHERE vaccine_id = v.id AND status = 'completed') as total_given,
                   (SELECT COUNT(*) FROM appointments WHERE vaccine_id = v.id AND status = 'pending') as pending
            FROM vaccines v
            WHERE v.is_active = TRUE
        ";
        
        if ($startDate && $endDate) {
            $sql = "
                SELECT v.*, 
                       (SELECT COUNT(*) FROM appointments WHERE vaccine_id = v.id AND status = 'completed' AND given_date BETWEEN ? AND ?) as total_given,
                       (SELECT COUNT(*) FROM appointments WHERE vaccine_id = v.id AND status = 'pending') as pending
                FROM vaccines v
                WHERE v.is_active = TRUE
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$startDate, $endDate]);
        } else {
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
        }
        
        return $stmt->fetchAll();
    }
}
?>