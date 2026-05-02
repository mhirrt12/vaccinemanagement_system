<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ==================== CORS ====================
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$static_file = __DIR__ . $path;
if (file_exists($static_file) && is_file($static_file) && $path !== '/index.php') {
    return false; // serve static files like test.php
}

// ==================== Database ====================
try {
    $pdo = new PDO("mysql:host=localhost;dbname=vaccine_ms;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
    exit;
}

// ==================== AUTHENTICATION ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/api/auth/login') {
    $input = json_decode(file_get_contents('php://input'), true);
    $email = $input['email'] ?? '';
    $password = $input['password'] ?? '';
    if (!$email || !$password) {
        echo json_encode(["success" => false, "message" => "Email and password required"]);
        exit;
    }
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        echo json_encode(["success" => false, "message" => "Invalid credentials"]);
        exit;
    }
    $role = $user['role_id'] == 1 ? 'parent' : ($user['role_id'] == 2 ? 'nurse' : 'admin');
    $token = bin2hex(random_bytes(32));
    echo json_encode([
        "success" => true,
        "data" => [
            "token" => $token,
            "user" => [
                "id" => $user['id'],
                "name" => $user['name'],
                "email" => $user['email'],
                "role" => $role
            ]
        ]
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/api/auth/register') {
    $input = json_decode(file_get_contents('php://input'), true);
    $name = $input['name'] ?? '';
    $email = $input['email'] ?? '';
    $phone = $input['phone'] ?? '';
    $password = $input['password'] ?? '';
    $confirm = $input['confirm_password'] ?? '';
    if ($password !== $confirm) {
        echo json_encode(["success" => false, "message" => "Passwords do not match"]);
        exit;
    }
    if (!$name || !$email || !$phone || !$password) {
        echo json_encode(["success" => false, "message" => "All fields required"]);
        exit;
    }
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? OR phone = ?");
    $stmt->execute([$email, $phone]);
    if ($stmt->fetch()) {
        echo json_encode(["success" => false, "message" => "Email or phone already exists"]);
        exit;
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (name, email, phone, password_hash, role_id) VALUES (?, ?, ?, ?, 1)");
    if ($stmt->execute([$name, $email, $phone, $hash])) {
        echo json_encode(["success" => true, "message" => "Registered. Awaiting nurse approval."]);
    } else {
        echo json_encode(["success" => false, "message" => "Registration failed"]);
    }
    exit;
}

// ==================== ADMIN DASHBOARD ====================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/admin/stats') {
    $totalChildren = $pdo->query("SELECT COUNT(*) FROM children")->fetchColumn();
    $monthlyVaccinations = $pdo->query("SELECT COUNT(*) FROM appointments WHERE status='completed' AND given_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
    $totalNurses = $pdo->query("SELECT COUNT(*) FROM users WHERE role_id=2")->fetchColumn();
    $totalParents = $pdo->query("SELECT COUNT(*) FROM users WHERE role_id=1")->fetchColumn();
    echo json_encode([
        "totalChildren" => (int)$totalChildren,
        "monthlyVaccinations" => (int)$monthlyVaccinations,
        "totalNurses" => (int)$totalNurses,
        "totalParents" => (int)$totalParents,
        "vaccineLabels" => ['BCG', 'OPV-1', 'Penta-1', 'MCV1'],
        "vaccineCounts" => [12, 10, 8, 5],
        "recentActivities" => []
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/admin/low-stock') {
    echo json_encode([]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/admin/expiring-batches') {
    echo json_encode([]);
    exit;
}

// Vaccine CRUD
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/admin/vaccines') {
    $stmt = $pdo->query("SELECT id, name, days_from_birth, description, is_active FROM vaccines ORDER BY days_from_birth");
    echo json_encode($stmt->fetchAll());
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('#^/api/admin/vaccines/(\d+)$#', $path, $matches)) {
    $id = $matches[1];
    $stmt = $pdo->prepare("SELECT * FROM vaccines WHERE id = ?");
    $stmt->execute([$id]);
    $vaccine = $stmt->fetch();
    if (!$vaccine) {
        http_response_code(404);
        echo json_encode(["error" => "Vaccine not found"]);
    } else {
        echo json_encode($vaccine);
    }
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('#^/api/admin/vaccines/(\d+)/toggle$#', $path, $matches)) {
    $id = $matches[1];
    $input = json_decode(file_get_contents('php://input'), true);
    $isActive = $input['is_active'] ?? true;
    $stmt = $pdo->prepare("UPDATE vaccines SET is_active = ? WHERE id = ?");
    $stmt->execute([$isActive, $id]);
    echo json_encode(["success" => true]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'PUT' && preg_match('#^/api/admin/vaccines/(\d+)$#', $path, $matches)) {
    $id = $matches[1];
    $input = json_decode(file_get_contents('php://input'), true);
    $name = $input['name'] ?? '';
    $daysFromBirth = $input['days_from_birth'] ?? 0;
    $description = $input['description'] ?? '';
    $stmt = $pdo->prepare("UPDATE vaccines SET name = ?, days_from_birth = ?, description = ? WHERE id = ?");
    $stmt->execute([$name, $daysFromBirth, $description, $id]);
    echo json_encode(["success" => true]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && preg_match('#^/api/admin/vaccines/(\d+)$#', $path, $matches)) {
    $id = $matches[1];
    $pdo->prepare("DELETE FROM vaccines WHERE id = ?")->execute([$id]);
    echo json_encode(["success" => true]);
    exit;
}

// Nurse management
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/admin/nurses') {
    $stmt = $pdo->query("SELECT id, name, email, phone, created_at FROM users WHERE role_id = 2");
    echo json_encode($stmt->fetchAll());
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/api/admin/nurses') {
    $input = json_decode(file_get_contents('php://input'), true);
    $hash = password_hash($input['password'], PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (name, email, phone, password_hash, role_id, is_verified) VALUES (?, ?, ?, ?, 2, 1)");
    $stmt->execute([$input['name'], $input['email'], $input['phone'], $hash]);
    echo json_encode(["success" => true, "id" => $pdo->lastInsertId()]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && preg_match('#^/api/admin/vaccines/(\d+)$#', $path, $matches)) {
    $id = $matches[1];
    // Instead of deleting, mark as inactive (soft delete)
    $pdo->prepare("UPDATE vaccines SET is_active = 0 WHERE id = ?")->execute([$id]);
    echo json_encode(["success" => true]);
    exit;
}

// Inventory
// GET inventory list
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/admin/inventory') {
    $stmt = $pdo->query("SELECT i.*, v.name as vaccine_name FROM inventory i JOIN vaccines v ON i.vaccine_id = v.id");
    echo json_encode($stmt->fetchAll());
    exit;
}

// POST add inventory batch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/api/admin/inventory') {
    $input = json_decode(file_get_contents('php://input'), true);
    $stmt = $pdo->prepare("INSERT INTO inventory (vaccine_id, batch_number, expiry_date, quantity) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)");
    $stmt->execute([$input['vaccine_id'], $input['batch_number'], $input['expiry_date'], $input['quantity']]);
    echo json_encode(["success" => true]);
    exit;
}

// Audit logs
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/admin/audit-logs') {
    echo json_encode(["logs" => [], "total" => 0]);
    exit;
}

// ==================== PARENT ENDPOINTS ====================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/parent/children') {
    // In a real app you'd get the logged-in parent ID from the token.
    // For now, return empty.
    echo json_encode([]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/api/children') {
    $input = json_decode(file_get_contents('php://input'), true);
    $uniqueId = 'CH-' . strtoupper(uniqid());
    $stmt = $pdo->prepare("
        INSERT INTO children 
        (parent_id, unique_child_id, name, dob, gender, blood_type, allergies, birth_weight, delivery_type, birth_place, is_verified)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, FALSE)
    ");
    $stmt->execute([
        $input['parent_id'], $uniqueId, $input['name'], $input['dob'], $input['gender'],
        $input['blood_type'] ?? null, $input['allergies'] ?? '', $input['birth_weight'] ?? null,
        $input['delivery_type'] ?? 'Normal', $input['birth_place'] ?? ''
    ]);
    echo json_encode(["success" => true, "child_id" => $pdo->lastInsertId()]);
    exit;
}

// ==================== NURSE ENDPOINTS (dummy or partial) ====================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/nurse/my-children')       { echo json_encode([]); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/nurse/pending-parents')   { echo json_encode([]); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/api/nurse/generate-report')  { echo json_encode(["success"=>true,"data"=>[]]); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'GET' && strpos($path, '/api/nurse/search')===0)  { echo json_encode([]); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/nurse/upcoming-appointments') { echo json_encode([]); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/api/nurse/walkin')           { echo json_encode(["success"=>true,"child_id"=>123]); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/api/nurse/record-vaccine')   { echo json_encode(["success"=>true]); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('#/api/nurse/appointment/\d+/approve-reschedule#', $path)) { echo json_encode(["success"=>true]); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/reports')                { echo json_encode([]); exit; }

// ==================== CATCH‑ALL for any other API route ====================
if (strpos($path, '/api/') === 0) {
    echo json_encode(["success" => true, "message" => "Demo endpoint – implement later"]);
    exit;
}

// ==================== 404 for non‑API ====================
http_response_code(404);
echo "Not Found";