<?php
require_once __DIR__ . '/../config/database.php';

class Inventory {
    private $db;
    
    public function __construct() {
        $this->db = Database::getConnection();
    }
    
    public function addBatch($vaccineId, $batchNumber, $expiryDate, $quantity) {
        $stmt = $this->db->prepare("
            INSERT INTO inventory (vaccine_id, batch_number, expiry_date, quantity) 
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
        ");
        return $stmt->execute([$vaccineId, $batchNumber, $expiryDate, $quantity]);
    }
    
    public function getAvailableBatch($vaccineId, $batchNumber = null) {
        if ($batchNumber) {
            $stmt = $this->db->prepare("
                SELECT * FROM inventory 
                WHERE vaccine_id = ? AND batch_number = ? AND expiry_date > CURDATE() AND quantity > 0
            ");
            $stmt->execute([$vaccineId, $batchNumber]);
            return $stmt->fetch();
        } else {
            $stmt = $this->db->prepare("
                SELECT * FROM inventory 
                WHERE vaccine_id = ? AND expiry_date > CURDATE() AND quantity > 0
                ORDER BY expiry_date ASC LIMIT 1
            ");
            $stmt->execute([$vaccineId]);
            return $stmt->fetch();
        }
    }
    
    public function deductStock($inventoryId, $quantity = 1) {
        $stmt = $this->db->prepare("UPDATE inventory SET quantity = quantity - ? WHERE id = ? AND quantity >= ?");
        return $stmt->execute([$quantity, $inventoryId, $quantity]);
    }
    
    public function getAllWithExpiryWarning($days = 15) {
        $stmt = $this->db->prepare("
            SELECT i.*, v.name as vaccine_name 
            FROM inventory i
            JOIN vaccines v ON i.vaccine_id = v.id
            WHERE i.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
            ORDER BY i.expiry_date ASC
        ");
        $stmt->execute([$days]);
        return $stmt->fetchAll();
    }
    
    public function getLowStock($threshold = 100) {
        $stmt = $this->db->prepare("
            SELECT i.*, v.name as vaccine_name 
            FROM inventory i
            JOIN vaccines v ON i.vaccine_id = v.id
            WHERE i.quantity < ? AND i.expiry_date > CURDATE()
            ORDER BY i.quantity ASC
        ");
        $stmt->execute([$threshold]);
        return $stmt->fetchAll();
    }
}
?>