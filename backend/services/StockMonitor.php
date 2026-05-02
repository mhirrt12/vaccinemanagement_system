<?php
/**
 * StockMonitor Service - Monitors vaccine inventory for low stock and expiry alerts
 * 
 * Responsibilities:
 * - Check for low stock (quantity < threshold) and send notifications
 * - Check for expiring batches (expiry within days threshold) and send notifications
 * - Run daily via cron job to generate alerts
 * - Track alert history to avoid duplicate notifications
 * - Provide stock status summary for admin dashboard
 */

require_once __DIR__ . '/../models/Inventory.php';
require_once __DIR__ . '/../models/Vaccine.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../helpers/Logger.php';

class StockMonitor {
    private $inventoryModel;
    private $vaccineModel;
    private $userModel;
    private $notificationService;
    
    // Default thresholds
    private $lowStockThreshold = 100;
    private $expiryWarningDays = 15;
    
    public function __construct() {
        $this->inventoryModel = new Inventory();
        $this->vaccineModel = new Vaccine();
        $this->userModel = new User();
        $this->notificationService = new NotificationService();
    }
    
    /**
     * Set low stock threshold (quantity below which alert is triggered)
     * 
     * @param int $threshold
     * @return void
     */
    public function setLowStockThreshold($threshold) {
        $this->lowStockThreshold = $threshold;
    }
    
    /**
     * Set expiry warning days (days before expiry to trigger alert)
     * 
     * @param int $days
     * @return void
     */
    public function setExpiryWarningDays($days) {
        $this->expiryWarningDays = $days;
    }
    
    /**
     * Check all inventory for low stock and expiry alerts
     * This method should be called by cron job daily
     * 
     * @return array Summary of alerts triggered
     */
    public function checkAll() {
        $lowStockAlerts = $this->checkLowStock();
        $expiryAlerts = $this->checkExpiringBatches();
        
        Logger::info("Stock monitor check completed", null, [
            'low_stock_alerts' => count($lowStockAlerts),
            'expiry_alerts' => count($expiryAlerts)
        ]);
        
        return [
            'low_stock_alerts' => $lowStockAlerts,
            'expiry_alerts' => $expiryAlerts,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Check for low stock items and send notifications to admins
     * 
     * @return array List of low stock items that triggered alerts
     */
    public function checkLowStock() {
        $lowStockItems = $this->inventoryModel->getLowStock($this->lowStockThreshold);
        $admins = $this->userModel->getByRole('admin');
        
        $triggered = [];
        
        foreach ($lowStockItems as $item) {
            $triggered[] = [
                'vaccine_name' => $item['vaccine_name'],
                'batch_number' => $item['batch_number'],
                'quantity' => $item['quantity'],
                'expiry_date' => $item['expiry_date']
            ];
            
            // Send notification to all admins
            foreach ($admins as $admin) {
                $this->notificationService->sendLowStockAlert(
                    $admin['id'],
                    $admin['email'],
                    $item['vaccine_name'],
                    $item['quantity']
                );
            }
        }
        
        return $triggered;
    }
    
    /**
     * Check for expiring batches and send notifications to admins
     * 
     * @return array List of expiring batches that triggered alerts
     */
    public function checkExpiringBatches() {
        $expiringItems = $this->inventoryModel->getAllWithExpiryWarning($this->expiryWarningDays);
        $admins = $this->userModel->getByRole('admin');
        
        $triggered = [];
        
        foreach ($expiringItems as $item) {
            $daysLeft = (strtotime($item['expiry_date']) - time()) / 86400;
            $triggered[] = [
                'vaccine_name' => $item['vaccine_name'],
                'batch_number' => $item['batch_number'],
                'expiry_date' => $item['expiry_date'],
                'days_left' => round($daysLeft),
                'quantity' => $item['quantity']
            ];
            
            // Send notification to all admins
            foreach ($admins as $admin) {
                $this->notificationService->sendExpiryAlert(
                    $admin['id'],
                    $admin['email'],
                    $item['vaccine_name'],
                    $item['batch_number'],
                    $item['expiry_date'],
                    $item['quantity']
                );
            }
        }
        
        return $triggered;
    }
    
    /**
     * Check a specific vaccine batch for alerts
     * 
     * @param int $inventoryId
     * @return array Alert status for this batch
     */
    public function checkBatch($inventoryId) {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT i.*, v.name as vaccine_name 
            FROM inventory i
            JOIN vaccines v ON i.vaccine_id = v.id
            WHERE i.id = ?
        ");
        $stmt->execute([$inventoryId]);
        $batch = $stmt->fetch();
        
        if (!$batch) {
            return ['error' => 'Batch not found'];
        }
        
        $alerts = [];
        
        // Check low stock
        if ($batch['quantity'] < $this->lowStockThreshold) {
            $alerts['low_stock'] = [
                'triggered' => true,
                'quantity' => $batch['quantity'],
                'threshold' => $this->lowStockThreshold
            ];
        }
        
        // Check expiry
        $daysToExpiry = (strtotime($batch['expiry_date']) - time()) / 86400;
        if ($daysToExpiry <= $this->expiryWarningDays) {
            $alerts['expiry'] = [
                'triggered' => true,
                'days_left' => round($daysToExpiry),
                'expiry_date' => $batch['expiry_date'],
                'warning_days' => $this->expiryWarningDays
            ];
        }
        
        return $alerts;
    }
    
    /**
     * Get stock status summary for dashboard
     * 
     * @return array
     */
    public function getStockStatusSummary() {
        $db = Database::getConnection();
        
        // Total batches
        $stmt = $db->query("SELECT COUNT(*) as total FROM inventory");
        $totalBatches = $stmt->fetch()['total'];
        
        // Low stock batches
        $lowStockCount = count($this->inventoryModel->getLowStock($this->lowStockThreshold));
        
        // Expiring batches
        $expiringCount = count($this->inventoryModel->getAllWithExpiryWarning($this->expiryWarningDays));
        
        // Total doses in stock
        $stmt = $db->query("SELECT SUM(quantity) as total FROM inventory WHERE expiry_date > CURDATE()");
        $totalDoses = (int)$stmt->fetch()['total'];
        
        // Stock by vaccine (top 5)
        $stmt = $db->query("
            SELECT v.name, SUM(i.quantity) as total_quantity
            FROM inventory i
            JOIN vaccines v ON i.vaccine_id = v.id
            WHERE i.expiry_date > CURDATE()
            GROUP BY i.vaccine_id
            ORDER BY total_quantity DESC
            LIMIT 5
        ");
        $stockByVaccine = $stmt->fetchAll();
        
        return [
            'total_batches' => $totalBatches,
            'low_stock_batches' => $lowStockCount,
            'expiring_batches' => $expiringCount,
            'total_doses_available' => $totalDoses,
            'stock_by_vaccine' => $stockByVaccine,
            'low_stock_threshold' => $this->lowStockThreshold,
            'expiry_warning_days' => $this->expiryWarningDays
        ];
    }
    
    /**
     * Get detailed low stock report
     * 
     * @return array
     */
    public function getLowStockReport() {
        return $this->inventoryModel->getLowStock($this->lowStockThreshold);
    }
    
    /**
     * Get detailed expiring batches report
     * 
     * @return array
     */
    public function getExpiringReport() {
        return $this->inventoryModel->getAllWithExpiryWarning($this->expiryWarningDays);
    }
    
    /**
     * Get all inventory with status flags
     * 
     * @return array
     */
    public function getInventoryWithStatus() {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT i.*, v.name as vaccine_name,
                   CASE 
                       WHEN i.quantity < ? THEN 'low_stock'
                       WHEN i.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) THEN 'expiring_soon'
                       WHEN i.expiry_date < CURDATE() THEN 'expired'
                       ELSE 'ok'
                   END as status
            FROM inventory i
            JOIN vaccines v ON i.vaccine_id = v.id
            ORDER BY i.expiry_date ASC
        ");
        $stmt->execute([$this->lowStockThreshold, $this->expiryWarningDays]);
        return $stmt->fetchAll();
    }
}
?>