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
    return false;
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


function audit_log($pdo, $userId, $action, $details = '') {
    $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
    $stmt->execute([$userId, $action, $details, $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
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

// ==================== CHILD REGISTRATION (PARENT) ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/api/parent/child') {
    $input = json_decode(file_get_contents('php://input'), true);
    // Use the parent_id sent from the frontend, fallback to 1 only for testing
    $parentId = isset($input['parent_id']) ? (int)$input['parent_id'] : 1;
    $uniqueId = 'CHLD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
    $stmt = $pdo->prepare("INSERT INTO children (parent_id, unique_child_id, name, dob, gender, blood_type, allergies, birth_weight, delivery_type, birth_place, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
    $stmt->execute([
        $parentId, $uniqueId, $input['name'], $input['dob'], $input['gender'],
        $input['blood_type'] ?? null, $input['allergies'] ?? '', $input['birth_weight'] ?? null,
        $input['delivery_type'] ?? 'Normal', $input['birth_place'] ?? ''
    ]);
    $childId = $pdo->lastInsertId();

    // Store historical vaccines if provided
    if (!empty($input['historical_vaccines'])) {
        $histVaccines = is_array($input['historical_vaccines']) ? implode(',', $input['historical_vaccines']) : '';
        $pdo->prepare("UPDATE children SET pending_historical_vaccines = ? WHERE id = ?")->execute([$histVaccines, $childId]);
    }

    echo json_encode(["success" => true, "message" => "Child registered successfully, pending nurse approval", "child_id" => $childId]);
    exit;
}

// ==================== VACCINES ====================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/vaccines') {
    $stmt = $pdo->query("SELECT id, name, days_from_birth, description FROM vaccines WHERE is_active = 1 ORDER BY days_from_birth");
    echo json_encode(["success" => true, "data" => $stmt->fetchAll()]);
    exit;
}


// ==================== PARENT CHILDREN LIST ====================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/parent/children') {
    $parentId = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : 0;
    $stmt = $pdo->prepare("
        SELECT c.*,
               na.nurse_id,
               u.name AS nurse_name,
               u.phone AS nurse_phone,
               u.email AS nurse_email
        FROM children c
        LEFT JOIN nurse_assignments na ON c.id = na.child_id
        LEFT JOIN users u ON na.nurse_id = u.id
        WHERE c.parent_id = ? AND c.status = 'approved'
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([$parentId]);
    $children = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(["success" => true, "data" => $children]);
    exit;
}

// ==================== CHILD SCHEDULE ====================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('#^/api/parent/child/(\d+)/schedule$#', $path, $m)) {
    $childId = $m[1];
    $parentId = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : 0;
    // Verify child belongs to parent
    $stmt = $pdo->prepare("SELECT id FROM children WHERE id = ? AND parent_id = ?");
    $stmt->execute([$childId, $parentId]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Access denied"]);
        exit;
    }
    $stmt = $pdo->prepare("
        SELECT a.*, v.name AS vaccine_name
        FROM appointments a
        JOIN vaccines v ON a.vaccine_id = v.id
        WHERE a.child_id = ?
        ORDER BY a.scheduled_date ASC
    ");
    $stmt->execute([$childId]);
    $schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(["success" => true, "data" => ["child" => ["id" => $childId], "schedule" => $schedule]]);
    exit;
}

// ==================== NURSE PENDING CHILDREN ====================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/nurse/pending-children') {
    $stmt = $pdo->query("
        SELECT c.*, u.name AS parent_name, u.phone AS parent_phone
        FROM children c
        JOIN users u ON c.parent_id = u.id
        WHERE c.status = 'pending'
        ORDER BY c.created_at ASC
    ");
    $children = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(["success" => true, "data" => $children]);
    exit;
}

// ==================== NURSE APPROVE CHILD ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('#^/api/nurse/approve-child/(\d+)$#', $path, $m)) {
    $childId = $m[1];
    $input = json_decode(file_get_contents('php://input'), true);
    $nurseId = isset($input['nurse_id']) ? (int)$input['nurse_id'] : 0;

    if (!$nurseId) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Nurse ID required"]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM children WHERE id = ? AND status = 'pending'");
    $stmt->execute([$childId]);
    $child = $stmt->fetch();
    if (!$child) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Child not found or already processed"]);
        exit;
    }

    // Approve child with this nurse
    $pdo->prepare("UPDATE children SET status = 'approved', approved_by_nurse_id = ?, approved_at = NOW() WHERE id = ?")
       ->execute([$nurseId, $childId]);

    // Generate appointments
    $stmt = $pdo->query("SELECT id, days_from_birth FROM vaccines WHERE is_active = 1");
    $vaccines = $stmt->fetchAll();
    foreach ($vaccines as $vaccine) {
        $dueDate = date('Y-m-d', strtotime($child['dob'] . ' + ' . $vaccine['days_from_birth'] . ' days'));
        $check = $pdo->prepare("SELECT id FROM appointments WHERE child_id = ? AND vaccine_id = ?");
        $check->execute([$childId, $vaccine['id']]);
        if (!$check->fetch()) {
            $pdo->prepare("INSERT INTO appointments (child_id, vaccine_id, scheduled_date, status) VALUES (?, ?, ?, 'pending')")
                ->execute([$childId, $vaccine['id'], $dueDate]);
        }
    }

    // Historical vaccines
    if (!empty($child['pending_historical_vaccines'])) {
        $histIds = explode(',', $child['pending_historical_vaccines']);
        foreach ($histIds as $vId) {
            $vId = intval($vId);
            $pdo->prepare("INSERT INTO appointments (child_id, vaccine_id, scheduled_date, status, given_date, notes)
                           VALUES (?, ?, CURDATE(), 'completed', CURDATE(), 'Historical - previously given elsewhere')")
                ->execute([$childId, $vId]);
        }
    }

    // Assign the child directly to THIS nurse (the one who approved)
    // Insert assignment if not already exists
    $checkAssign = $pdo->prepare("SELECT id FROM nurse_assignments WHERE child_id = ? AND nurse_id = ?");
    $checkAssign->execute([$childId, $nurseId]);
    if (!$checkAssign->fetch()) {
        $pdo->prepare("INSERT INTO nurse_assignments (nurse_id, child_id, assigned_at) VALUES (?, ?, NOW())")
            ->execute([$nurseId, $childId]);
    }

    audit_log($pdo, $nurseId, 'APPROVE_CHILD', "Child ID: $childId, Nurse ID: $nurseId");
    echo json_encode(["success" => true, "message" => "Child approved and assigned to you"]);
    exit;
}

// ==================== NURSE REJECT CHILD ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('#^/api/nurse/reject-child/(\d+)$#', $path, $m)) {
    $childId = $m[1];
    $pdo->prepare("UPDATE children SET status = 'rejected' WHERE id = ? AND status = 'pending'")->execute([$childId]);
    echo json_encode(["success" => true, "message" => "Child rejected"]);
    exit;
}

// ==================== ADMIN DASHBOARD ====================
// ==================== ADMIN DASHBOARD STATS (REAL DATA) ====================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/admin/stats') {
    $totalChildren = $pdo->query("SELECT COUNT(*) FROM children")->fetchColumn();
    $monthlyVaccinations = $pdo->query("SELECT COUNT(*) FROM appointments WHERE status='completed' AND given_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
    $totalNurses = $pdo->query("SELECT COUNT(*) FROM users WHERE role_id=2")->fetchColumn();
    $totalParents = $pdo->query("SELECT COUNT(*) FROM users WHERE role_id=1")->fetchColumn();

    // Vaccine distribution for last 30 days
    $distStmt = $pdo->query("
        SELECT v.name AS label, COUNT(a.id) AS count
        FROM vaccines v
        LEFT JOIN appointments a ON v.id = a.vaccine_id AND a.status = 'completed' AND a.given_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        WHERE v.is_active = 1
        GROUP BY v.id, v.name
        ORDER BY v.days_from_birth ASC
    ");
    $dist = $distStmt->fetchAll(PDO::FETCH_ASSOC);
    $vaccineLabels = array_column($dist, 'label');
    $vaccineCounts = array_column($dist, 'count');

    // Recent 10 completed vaccinations
    $recentStmt = $pdo->query("
        SELECT c.name AS child_name, v.name AS vaccine_name, a.given_date, a.batch_number
        FROM appointments a
        JOIN children c ON a.child_id = c.id
        JOIN vaccines v ON a.vaccine_id = v.id
        WHERE a.status = 'completed'
        ORDER BY a.given_date DESC, a.id DESC
        LIMIT 10
    ");
    $recentActivities = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "totalChildren" => (int)$totalChildren,
        "monthlyVaccinations" => (int)$monthlyVaccinations,
        "totalNurses" => (int)$totalNurses,
        "totalParents" => (int)$totalParents,
        "vaccineLabels" => $vaccineLabels,
        "vaccineCounts" => $vaccineCounts,
        "recentActivities" => $recentActivities
    ]);
    exit;
}

// ==================== ADMIN VACCINE CRUD ====================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/admin/low-stock') {
    $stmt = $pdo->query("
        SELECT v.name AS vaccine_name, i.batch_number, SUM(i.quantity) AS quantity
        FROM inventory i
        JOIN vaccines v ON i.vaccine_id = v.id
        GROUP BY i.vaccine_id, i.batch_number, v.name
        HAVING SUM(i.quantity) < 100
        ORDER BY quantity ASC
    ");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/admin/expiring-batches') {
    $stmt = $pdo->query("
        SELECT v.name AS vaccine_name, i.batch_number, i.expiry_date, i.quantity
        FROM inventory i
        JOIN vaccines v ON i.vaccine_id = v.id
        WHERE i.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 15 DAY)
        ORDER BY i.expiry_date ASC
    ");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/admin/vaccines') {
    $stmt = $pdo->query("SELECT id, name, days_from_birth, description, is_active FROM vaccines ORDER BY days_from_birth");
    echo json_encode($stmt->fetchAll());
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('#^/api/admin/vaccines/(\d+)$#', $path, $m)) {
    $stmt = $pdo->prepare("SELECT * FROM vaccines WHERE id = ?");
    $stmt->execute([$m[1]]);
    $v = $stmt->fetch();
    echo json_encode($v ?: ["error" => "Vaccine not found"]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('#^/api/admin/vaccines/(\d+)/toggle$#', $path, $m)) {
    $input = json_decode(file_get_contents('php://input'), true);
    $pdo->prepare("UPDATE vaccines SET is_active = ? WHERE id = ?")->execute([$input['is_active'] ?? true, $m[1]]);
    echo json_encode(["success" => true]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'PUT' && preg_match('#^/api/admin/vaccines/(\d+)$#', $path, $m)) {
    $input = json_decode(file_get_contents('php://input'), true);
    $pdo->prepare("UPDATE vaccines SET name = ?, days_from_birth = ?, description = ? WHERE id = ?")
        ->execute([$input['name'], $input['days_from_birth'], $input['description'], $m[1]]);
    echo json_encode(["success" => true]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && preg_match('#^/api/admin/vaccines/(\d+)$#', $path, $m)) {
    $pdo->prepare("UPDATE vaccines SET is_active = 0 WHERE id = ?")->execute([$m[1]]);
    echo json_encode(["success" => true]);
    exit;
}
// ==================== ADMIN PENDING CERTIFICATES ====================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/admin/pending-certificates') {
    $stmt = $pdo->prepare("
        SELECT c.id, c.is_approved_by_nurse, c.is_approved_by_admin,
               ch.name AS child_name, ch.unique_child_id, u.name AS parent_name
        FROM certificates c
        JOIN children ch ON c.child_id = ch.id
        JOIN users u ON ch.parent_id = u.id
        WHERE c.is_approved_by_nurse = 1 AND c.is_approved_by_admin = 0
        ORDER BY c.created_at ASC
    ");
    $stmt->execute();
    echo json_encode(["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ==================== ADMIN NURSES ====================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/admin/nurses') {
    $stmt = $pdo->query("SELECT id, name, email, phone, username, education_level, certificate, work_experience, created_at
                         FROM users WHERE role_id = 2 AND is_verified = 1");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}
// Edit nurse
if ($_SERVER['REQUEST_METHOD'] === 'PUT' && preg_match('#^/api/admin/nurses/(\d+)$#', $path, $m)) {
    $nurseId = $m[1];
    $input = json_decode(file_get_contents('php://input'), true);
    $name      = $input['name'] ?? '';
    $phone     = $input['phone'] ?? '';
    $education = $input['education_level'] ?? '';
    $cert      = $input['certificate'] ?? '';
    $workExp   = $input['work_experience'] ?? '';
    $username  = $input['username'] ?? '';
    $password  = $input['password'] ?? '';

    // Validate required
    if (!$name || !$phone || !$username) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Name, phone, and username are required"]);
        exit;
    }
    // Check duplicate username or phone (exclude current)
    $check = $pdo->prepare("SELECT id FROM users WHERE (username = ? OR phone = ?) AND id != ?");
    $check->execute([$username, $phone, $nurseId]);
    if ($check->fetch()) {
        http_response_code(409);
        echo json_encode(["success" => false, "message" => "Username or phone already used by another nurse"]);
        exit;
    }

    // Build update
    if (!empty($password)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET name=?, phone=?, education_level=?, certificate=?, work_experience=?, username=?, password_hash=? WHERE id=? AND role_id=2");
        $stmt->execute([$name, $phone, $education, $cert, $workExp, $username, $hash, $nurseId]);
    } else {
        $stmt = $pdo->prepare("UPDATE users SET name=?, phone=?, education_level=?, certificate=?, work_experience=?, username=? WHERE id=? AND role_id=2");
        $stmt->execute([$name, $phone, $education, $cert, $workExp, $username, $nurseId]);
    }
    echo json_encode(["success" => true, "message" => "Nurse updated"]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/api/admin/nurses') {
    $input = json_decode(file_get_contents('php://input'), true);
    $name      = $input['name'] ?? '';
    $phone     = $input['phone'] ?? '';
    $education = $input['education_level'] ?? '';
    $cert      = $input['certificate'] ?? '';
    $workExp   = $input['work_experience'] ?? '';
    $username  = $input['username'] ?? '';
    $password  = $input['password'] ?? '';
    $email     = $input['email'] ?? ''; // optional, can default to something

    // Validate required
    if (!$name || !$phone || !$username || !$password) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Name, phone, username, and password are required"]);
        exit;
    }
    // Validate Ethiopian phone (10 digits)
    if (!preg_match('/^09\d{8}$/', $phone)) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Invalid Ethiopian phone number (must start with 09 and be 10 digits)"]);
        exit;
    }
    // Check duplicate username or phone
    $check = $pdo->prepare("SELECT id FROM users WHERE username = ? OR phone = ?");
    $check->execute([$username, $phone]);
    if ($check->fetch()) {
        http_response_code(409);
        echo json_encode(["success" => false, "message" => "Username or phone already exists"]);
        exit;
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (name, email, phone, education_level, certificate, work_experience, username, password_hash, role_id, is_verified)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, 2, 1)");
    $stmt->execute([$name, $username.'@nurse.local', $phone, $education, $cert, $workExp, $username, $hash]);
    echo json_encode(["success" => true, "id" => $pdo->lastInsertId()]);
    exit;
}
// Delete nurse (revoke)
if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && preg_match('#^/api/admin/nurses/(\d+)$#', $path, $m)) {
    $nurseId = $m[1];
    $pdo->prepare("UPDATE users SET is_verified = 0 WHERE id = ? AND role_id = 2")->execute([$nurseId]);
    echo json_encode(["success" => true]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/admin/nurse-reports') {
    $stmt = $pdo->query("
        SELECT r.*, u.name AS nurse_name
        FROM reports r
        JOIN users u ON r.generated_by = u.id
        ORDER BY r.created_at DESC
    ");
    echo json_encode(["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}
// ==================== ADMIN AUDIT LOGS (dummy) ====================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/admin/audit-logs') {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $stmt = $pdo->prepare("
        SELECT a.id, a.action, a.description, a.created_at,
               u.name AS user_name, u.role_id
        FROM audit_logs a
        LEFT JOIN users u ON a.user_id = u.id
        ORDER BY a.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$limit, $offset]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total = $pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
    echo json_encode(["success" => true, "data" => $logs, "total" => (int)$total]);
    exit;
}

// ==================== ADMIN INVENTORY (dummy) ====================
// ==================== INVENTORY MANAGEMENT ====================

// Get all inventory batches with vaccine name
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/admin/inventory') {
    $vaccineId = isset($_GET['vaccine_id']) ? (int)$_GET['vaccine_id'] : 0;
    if ($vaccineId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM inventory WHERE vaccine_id = ? AND quantity > 0 ORDER BY expiry_date ASC");
        $stmt->execute([$vaccineId]);
    } else {
        $stmt = $pdo->query("SELECT i.*, v.name AS vaccine_name FROM inventory i JOIN vaccines v ON i.vaccine_id = v.id ORDER BY i.expiry_date ASC");
    }
    echo json_encode(["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// Add new batch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/api/admin/inventory') {
    $input = json_decode(file_get_contents('php://input'), true);
    $vaccineId = $input['vaccine_id'] ?? 0;
    $batchNo   = $input['batch_number'] ?? '';
    $expiry    = $input['expiry_date'] ?? '';
    $quantity  = $input['quantity'] ?? 0;
    $regDate   = $input['registration_date'] ?? date('Y-m-d');
    $notes     = $input['notes'] ?? '';

    if (!$vaccineId || !$batchNo || !$expiry || $quantity <= 0) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "All required fields must be filled"]);
        exit;
    }

    // Check duplicate batch
    $check = $pdo->prepare("SELECT id FROM inventory WHERE vaccine_id = ? AND batch_number = ?");
    $check->execute([$vaccineId, $batchNo]);
    if ($check->fetch()) {
        http_response_code(409);
        echo json_encode(["success" => false, "message" => "This batch number already exists for the selected vaccine"]);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO inventory (vaccine_id, batch_number, expiry_date, quantity, registration_date, notes)
                           VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$vaccineId, $batchNo, $expiry, $quantity, $regDate, $notes]);
    echo json_encode(["success" => true, "id" => $pdo->lastInsertId()]);
    exit;
}

// Update a batch
if ($_SERVER['REQUEST_METHOD'] === 'PUT' && preg_match('#^/api/admin/inventory/(\d+)$#', $path, $m)) {
    $batchId = $m[1];
    $input = json_decode(file_get_contents('php://input'), true);
    $expiry = $input['expiry_date'] ?? '';
    $quantity = $input['quantity'] ?? 0;
    $regDate = $input['registration_date'] ?? '';
    $notes = $input['notes'] ?? '';

    $stmt = $pdo->prepare("UPDATE inventory SET expiry_date = ?, quantity = ?, registration_date = ?, notes = ? WHERE id = ?");
    $stmt->execute([$expiry, $quantity, $regDate, $notes, $batchId]);
    echo json_encode(["success" => true]);
    exit;
}

// Delete a batch (for expired or any)
if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && preg_match('#^/api/admin/inventory/(\d+)$#', $path, $m)) {
    $batchId = $m[1];
    $pdo->prepare("DELETE FROM inventory WHERE id = ?")->execute([$batchId]);
    echo json_encode(["success" => true]);
    exit;
}

// ==================== NURSE DUMMY ENDPOINTS ====================
if ($_SERVER['REQUEST_METHOD'] === 'PUT' && preg_match('#^/api/appointments/(\d+)/status$#', $path, $m)) {
    $apptId = $m[1];
    $input = json_decode(file_get_contents('php://input'), true);
    $status = $input['status'] ?? 'completed'; // completed, missed, etc.
    $batchNumber = $input['batch_number'] ?? '';
    $notes = $input['notes'] ?? '';
    $nurseId = 1; // TODO: from token

    $stmt = $pdo->prepare("UPDATE appointments SET status = ?, given_date = CURDATE(), batch_number = ?, nurse_id = ?, notes = ? WHERE id = ?");
    $stmt->execute([$status, $batchNumber, $nurseId, $notes, $apptId]);

    // Decrease stock if batch provided
    if ($batchNumber) {
        $pdo->prepare("UPDATE inventory SET quantity = quantity - 1 WHERE batch_number = ? AND quantity > 0")->execute([$batchNumber]);
    }

    audit_log($pdo, $nurseId, 'RECORD_VACCINE', "Appointment ID: $apptId");
    echo json_encode(["success" => true, "message" => "Status updated"]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/nurse/reports') {
    $nurseId = isset($_GET['nurse_id']) ? (int)$_GET['nurse_id'] : 0;
    $stmt = $pdo->prepare("SELECT * FROM reports WHERE generated_by = ? ORDER BY created_at DESC");
    $stmt->execute([$nurseId]);
    echo json_encode(["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/api/nurse/generate-report') {
    $input = json_decode(file_get_contents('php://input'), true);
    $type        = $input['type'] ?? 'weekly';
    $periodStart = $input['period_start'] ?? date('Y-m-d', strtotime('-7 days'));
    $periodEnd   = $input['period_end'] ?? date('Y-m-d');
    $nurseId     = isset($input['nurse_id']) ? (int)$input['nurse_id'] : 0;
    $challenges  = $input['challenges'] ?? '';   // nurse's written notes

    if (!$nurseId) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Nurse ID is required"]);
        exit;
    }

    // ---------- VACCINATION SUMMARY ----------
    // Total children vaccinated (distinct child_id)
    $totalChildrenStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT child_id) FROM appointments
        WHERE nurse_id = ? AND status = 'completed' AND given_date BETWEEN ? AND ?
    ");
    $totalChildrenStmt->execute([$nurseId, $periodStart, $periodEnd]);
    $totalChildren = $totalChildrenStmt->fetchColumn();

    // Doses per vaccine type
    $dosesPerVaccine = $pdo->prepare("
        SELECT v.name, COUNT(*) AS count
        FROM appointments a
        JOIN vaccines v ON a.vaccine_id = v.id
        WHERE a.nurse_id = ? AND a.status = 'completed' AND a.given_date BETWEEN ? AND ?
        GROUP BY v.id, v.name
    ");
    $dosesPerVaccine->execute([$nurseId, $periodStart, $periodEnd]);
    $vaccineBreakdown = $dosesPerVaccine->fetchAll(PDO::FETCH_ASSOC);

    // New registrations vs follow-ups (all appointments completed in period)
    // We'll treat all as follow-ups after first vaccine; first vaccine = BCG/OPV0
    $newReg = $pdo->prepare("
        SELECT COUNT(DISTINCT a.child_id)
        FROM appointments a
        JOIN children c ON a.child_id = c.id
        WHERE a.nurse_id = ? AND a.status = 'completed' AND a.given_date BETWEEN ? AND ?
          AND c.id NOT IN (
            SELECT child_id FROM appointments WHERE nurse_id = ? AND status = 'completed' AND given_date < ?
          )
    ");
    $newReg->execute([$nurseId, $periodStart, $periodEnd, $nurseId, $periodStart]);
    $newRegistrations = $newReg->fetchColumn();
    $followUps = $totalChildren - $newRegistrations;

    // ---------- APPOINTMENT TRACKING ----------
    // Completed appointments
    $completedStmt = $pdo->prepare("
        SELECT COUNT(*) FROM appointments
        WHERE nurse_id = ? AND status = 'completed' AND given_date BETWEEN ? AND ?
    ");
    $completedStmt->execute([$nurseId, $periodStart, $periodEnd]);
    $completedAppointments = $completedStmt->fetchColumn();

    // Missed appointments with child & parent details
    $defaulterStmt = $pdo->prepare("
        SELECT a.scheduled_date, c.name AS child_name, u.phone AS parent_phone
        FROM appointments a
        JOIN children c ON a.child_id = c.id
        JOIN users u ON c.parent_id = u.id
        JOIN nurse_assignments na ON c.id = na.child_id AND na.nurse_id = ?
        WHERE a.status = 'missed' AND a.scheduled_date BETWEEN ? AND ?
    ");
    $defaulterStmt->execute([$nurseId, $periodStart, $periodEnd]);
    $defaulters = $defaulterStmt->fetchAll(PDO::FETCH_ASSOC);

    // Pending and approved reschedule requests
    $pendingReschedule = $pdo->prepare("
        SELECT COUNT(*) FROM appointments
        WHERE nurse_id = ? AND status = 'rescheduled' AND reschedule_approved = 0
          AND reschedule_request_date BETWEEN ? AND ?
    ");
    $pendingReschedule->execute([$nurseId, $periodStart, $periodEnd]);
    $pendingRescheduleCount = $pendingReschedule->fetchColumn();

    $approvedReschedule = $pdo->prepare("
        SELECT COUNT(*) FROM appointments
        WHERE nurse_id = ? AND status = 'rescheduled' AND reschedule_approved = 1
          AND reschedule_request_date BETWEEN ? AND ?
    ");
    $approvedReschedule->execute([$nurseId, $periodStart, $periodEnd]);
    $approvedRescheduleCount = $approvedReschedule->fetchColumn();

    // ---------- INVENTORY & WASTAGE ----------
    // Doses used (assuming each completed appointment with a batch_number reduces inventory)
    // We'll count total completed appointments with a batch number
    $dosesUsedStmt = $pdo->prepare("
        SELECT COUNT(*) FROM appointments
        WHERE nurse_id = ? AND status = 'completed' AND batch_number IS NOT NULL AND batch_number != ''
          AND given_date BETWEEN ? AND ?
    ");
    $dosesUsedStmt->execute([$nurseId, $periodStart, $periodEnd]);
    $dosesUsed = $dosesUsedStmt->fetchColumn();

    // Wastage: expired batches (no direct tracking, keep placeholder)
    $wastage = []; // Could be improved with a dedicated wastage log

    // Current closing stock per vaccine
    $stockStmt = $pdo->query("
        SELECT v.name, COALESCE(SUM(i.quantity), 0) AS total_qty
        FROM vaccines v
        LEFT JOIN inventory i ON v.id = i.vaccine_id
        WHERE v.is_active = 1
        GROUP BY v.id, v.name
        ORDER BY v.name
    ");
    $stockBreakdown = $stockStmt->fetchAll(PDO::FETCH_ASSOC);

    // ---------- HEALTH & SAFETY ----------
    // AEFI placeholder: could be recorded in children notes or a separate table
    $aefiStmt = $pdo->prepare("
        SELECT c.name, c.notes
        FROM children c
        JOIN nurse_assignments na ON c.id = na.child_id AND na.nurse_id = ?
        WHERE c.notes IS NOT NULL AND c.notes != ''
    ");
    $aefiStmt->execute([$nurseId]);
    $aefiCases = $aefiStmt->fetchAll(PDO::FETCH_ASSOC);

    // ---------- BUILD REPORT DATA ----------
    $reportData = [
        'type' => $type,
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'vaccination_summary' => [
            'total_children_vaccinated' => $totalChildren,
            'new_registrations' => $newRegistrations,
            'follow_ups' => $followUps,
            'doses_per_vaccine' => $vaccineBreakdown,
        ],
        'appointment_tracking' => [
            'completed_appointments' => $completedAppointments,
            'defaulters' => $defaulters,
            'pending_reschedule_requests' => $pendingRescheduleCount,
            'approved_reschedule_requests' => $approvedRescheduleCount,
        ],
        'inventory_wastage' => [
            'doses_used' => $dosesUsed,
            'wastage' => $wastage,
            'closing_stock' => $stockBreakdown,
        ],
        'health_safety' => [
            'aefi_cases' => $aefiCases,
            'challenges' => $challenges,
        ],
    ];

    $jsonData = json_encode($reportData, JSON_UNESCAPED_UNICODE);

    // Insert into reports
    $stmt = $pdo->prepare("
        INSERT INTO reports (type, generated_by, data, period_start, period_end)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$type, $nurseId, $jsonData, $periodStart, $periodEnd]);

    audit_log($pdo, $nurseId, 'GENERATE_REPORT', "Report type: $type, period: $periodStart to $periodEnd");

    echo json_encode(["success" => true, "message" => "Detailed report generated and sent to admin"]);
    exit;
}
// ==================== CERTIFICATE GENERATION ====================
// ==================== CERTIFICATE GENERATION (after both approvals) ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/api/certificates/generate') {
    $input = json_decode(file_get_contents('php://input'), true);
    $childId = $input['child_id'] ?? 0;

    // Check if a certificate already exists
    $stmt = $pdo->prepare("SELECT id FROM certificates WHERE child_id = ?");
    $stmt->execute([$childId]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(["success" => false, "message" => "Certificate already requested"]);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO certificates (child_id, is_approved_by_nurse, is_approved_by_admin) VALUES (?, 0, 0)");
    $stmt->execute([$childId]);
    audit_log($pdo, $childId, 'CERT_REQUEST', "Certificate requested for child ID $childId");
    echo json_encode(["success" => true, "message" => "Certificate request submitted"]);
    exit;
}



// ==================== CERTIFICATE : NURSE APPROVE ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('#^/api/certificates/nurse-approve/(\d+)$#', $path, $m)) {
    $certId = $m[1];
    $stmt = $pdo->prepare("UPDATE certificates SET is_approved_by_nurse = 1 WHERE id = ? AND is_approved_by_nurse = 0");
    $stmt->execute([$certId]);
    echo json_encode(["success" => true, "message" => "Certificate approved by nurse"]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/nurse/pending-certificates') {
    $nurseId = isset($_GET['nurse_id']) ? (int)$_GET['nurse_id'] : 0;

    $stmt = $pdo->prepare("
        SELECT c.id AS certificate_id, c.child_id, ch.name AS child_name, ch.unique_child_id, c.created_at
        FROM certificates c
        JOIN children ch ON c.child_id = ch.id
        JOIN nurse_assignments na ON ch.id = na.child_id AND na.nurse_id = ?
        WHERE c.is_approved_by_nurse = 0
        ORDER BY c.created_at ASC
    ");
    $stmt->execute([$nurseId]);
    $certs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(["success" => true, "data" => $certs]);
    exit;
}
// ==================== CERTIFICATE : ADMIN APPROVE + GENERATE FILE ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('#^/api/certificates/admin-approve/(\d+)$#', $path, $m)) {
    $certId = $m[1];

    // 1. Mark admin approved
    $stmt = $pdo->prepare("UPDATE certificates SET is_approved_by_admin = 1, approved_at = NOW() WHERE id = ? AND is_approved_by_admin = 0");
    $stmt->execute([$certId]);

    // 2. Fetch certificate data
    $stmt = $pdo->prepare("
        SELECT c.*, ch.name AS child_name, ch.unique_child_id, ch.dob, ch.gender,
               u.name AS parent_name, u.phone, u.email
        FROM certificates c
        JOIN children ch ON c.child_id = ch.id
        JOIN users u ON ch.parent_id = u.id
        WHERE c.id = ?
    ");
    $stmt->execute([$certId]);
    $cert = $stmt->fetch();

    if (!$cert) {
        echo json_encode(["success" => false, "message" => "Certificate not found"]);
        exit;
    }

    // 3. Get completed vaccinations
    $stmt = $pdo->prepare("
        SELECT a.given_date, a.batch_number, v.name AS vaccine_name
        FROM appointments a
        JOIN vaccines v ON a.vaccine_id = v.id
        WHERE a.child_id = ? AND a.status = 'completed'
        ORDER BY a.given_date ASC
    ");
    $stmt->execute([$cert['child_id']]);
    $appts = $stmt->fetchAll();

    // 4. Ethiopian‑style certificate HTML (improved CSS for signature section)
    $kebele = "04";
    $woreda = "Menatabiya";
    $zone   = "Debre Birhan";
    $region = "Amhara Region";

    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Vaccination Certificate</title>
    <style>
        body {
            font-family: "Times New Roman", serif;
            margin: 30px;
            background: #fff;
        }
        .certificate {
            border: 5px double #2c3e50;
            padding: 30px;
            max-width: 800px;
            margin: 0 auto;
            position: relative;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #2c3e50;
            margin-bottom: 20px;
        }
        .header .title {
            font-size: 26px;
            font-weight: bold;
            color: #1a5276;
        }
        .header .subtitle {
            font-size: 15px;
            color: #555;
        }
        .stamp {
            position: absolute;
            top: 20px;
            right: 20px;
            font-size: 12px;
            color: #b33939;
            border: 2px solid #b33939;
            border-radius: 50%;
            width: 80px;
            height: 80px;
            text-align: center;
            line-height: 80px;
            transform: rotate(-15deg);
            font-weight: bold;
        }
        .info-table {
            width: 100%;
            margin: 20px 0;
            border-collapse: collapse;
        }
        .info-table td {
            padding: 6px;
            border-bottom: 1px dotted #ccc;
            font-size: 14px;
        }
        .info-table td:first-child {
            font-weight: bold;
            width: 30%;
        }
        .vax-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .vax-table th, .vax-table td {
            border: 1px solid #333;
            padding: 7px;
            text-align: left;
            font-size: 13px;
        }
        .vax-table th {
            background: #2c3e50;
            color: #fff;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            font-size: 12px;
        }
        /* Signature area – uses flexbox for proper alignment */
        .signature-area {
            margin-top: 40px;
            display: flex;
            flex-direction: row;
            justify-content: space-around;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
            border-top: 2px solid #2c3e50;
            padding-top: 20px;
        }
        .signature-area .line {
            width: 200px;
            text-align: center;
            font-size: 12px;
        }
        .signature-area .line img {
            max-height: 80px;
            margin-bottom: 5px;
            opacity: 0.9;
        }
        .signature-area .qr-code {
            text-align: center;
        }
        .signature-area .qr-code img {
            width: 100px;
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
<div class="certificate">
    <div class="header">
        <div class="title">የክትባት ሰርተፍኬት<br>VACCINATION CERTIFICATE</div>
        <div class="subtitle">Federal Democratic Republic of Ethiopia<br>Ministry of Health</div>
    </div>
    <div class="stamp">APPROVED</div>
    <table class="info-table">
        <tr><td>Child Name / የልጅ ስም:</td><td>'.htmlspecialchars($cert['child_name']).'</td></tr>
        <tr><td>Unique ID / መለያ ቁጥር:</td><td>'.htmlspecialchars($cert['unique_child_id']).'</td></tr>
        <tr><td>Date of Birth / የትውልድ ቀን:</td><td>'.$cert['dob'].'</td></tr>
        <tr><td>Gender / ጾታ:</td><td>'.$cert['gender'].'</td></tr>
        <tr><td>Parent/Guardian / ወላጅ:</td><td>'.htmlspecialchars($cert['parent_name']).'</td></tr>
        <tr><td>Kebele / ቀበሌ:</td><td>'.$kebele.'</td></tr>
        <tr><td>Woreda / ወረዳ:</td><td>'.$woreda.'</td></tr>
        <tr><td>Zone / ዞን:</td><td>'.$zone.'</td></tr>
        <tr><td>Region / ክልል:</td><td>'.$region.'</td></tr>
    </table>
    <h4>Vaccines Administered / የተከተቡ ክትባቶች</h4>
    <table class="vax-table">
        <thead><tr><th>Vaccine</th><th>Date Given</th><th>Batch No.</th></tr></thead>
        <tbody>';
    foreach ($appts as $v) {
        $vaccine = htmlspecialchars($v['vaccine_name']);
        $date    = $v['given_date'];
        $batch   = $v['batch_number'] ?? 'N/A';
        $html .= '<tr><td>'.$vaccine.'</td><td>'.$date.'</td><td>'.$batch.'</td></tr>';
    }
    $html .= '</tbody></table>
    <div class="footer">
        <p>Issued under the authority of the Ethiopian Ministry of Health</p>
        <p>Certificate ID: '.$certId.' | Date: '.date('F d, Y').'</p>
    </div>
    <div class="signature-area">
        <!-- Placeholder – will be replaced by branding images & QR code -->
    </div>
</div>
</body>
</html>';

    // **********************************************
    // DIGITAL BRANDING & QR CODE OVERLAY
    // **********************************************

    // Generate unique verification hash
    $verificationHash = bin2hex(random_bytes(16));
    $pdo->prepare("UPDATE certificates SET verification_hash = ? WHERE id = ?")
        ->execute([$verificationHash, $certId]);

    // Get branding images from settings
    $settingsStmt = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('stamp_image', 'signature_image')");
    $settings = [];
    while ($row = $settingsStmt->fetch()) {
        $settings[$row['key']] = $row['value'];
    }

    // Embed stamp and signature (base64)
    $stampBase64 = '';
    $signatureBase64 = '';
    if (!empty($settings['stamp_image']) && file_exists($settings['stamp_image'])) {
        $stampData = file_get_contents($settings['stamp_image']);
        $stampBase64 = 'data:image/png;base64,' . base64_encode($stampData);
    }
    if (!empty($settings['signature_image']) && file_exists($settings['signature_image'])) {
        $sigData = file_get_contents($settings['signature_image']);
        $signatureBase64 = 'data:image/png;base64,' . base64_encode($sigData);
    }

    // QR code image URL
    $verifyUrl = "http://localhost:3000/verify/" . $verificationHash;
    $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($verifyUrl);

    // Replace the placeholder with the actual branded section
    $brandedSection = '
    <div class="signature-area">
        <div class="line">';
    if ($stampBase64) {
        $brandedSection .= '<img src="'.$stampBase64.'" /><br/>';
    }
    $brandedSection .= 'Health Center Stamp / ማህተም
        </div>
        <div class="line">';
    if ($signatureBase64) {
        $brandedSection .= '<img src="'.$signatureBase64.'" /><br/>';
    }
    $brandedSection .= 'Authorized Signature / ፊርማ
        </div>
        <div class="qr-code">
            <img src="'.$qrUrl.'" /><br/>
            <small>Scan to verify</small>
        </div>
    </div>';

    $html = str_replace(
        '<div class="signature-area">
        <!-- Placeholder – will be replaced by branding images & QR code -->
    </div>',
        $brandedSection,
        $html
    );

    // 5. Save the file
    $dir = __DIR__ . '/../../storage/certificates/';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $filename = 'cert_' . $cert['unique_child_id'] . '.html';
    file_put_contents($dir . $filename, $html);
    $stmt = $pdo->prepare("UPDATE certificates SET file_path = ? WHERE id = ?");
    $stmt->execute([$dir . $filename, $certId]);

    audit_log($pdo, 1, 'CERT_ADMIN_APPROVE', "Certificate ID: $certId approved with QR");

    echo json_encode(["success" => true, "message" => "Certificate approved and generated"]);
    exit;
}
// ==================== PARENT DOWNLOAD CERTIFICATE ====================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('#^/api/parent/child/(\d+)/certificate$#', $path, $m)) {
    $childId = $m[1];

    $stmt = $pdo->prepare("
        SELECT c.*, ch.unique_child_id
        FROM certificates c
        JOIN children ch ON c.child_id = ch.id
        WHERE c.child_id = ? AND c.is_approved_by_nurse = 1 AND c.is_approved_by_admin = 1
    ");
    $stmt->execute([$childId]);
    $cert = $stmt->fetch();

    if (!$cert) {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Certificate is not fully approved yet."]);
        exit;
    }

    $file = $cert['file_path'];
    if (!file_exists($file)) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Certificate file missing"]);
        exit;
    }

    audit_log($pdo, $childId, 'DOWNLOAD_CERT', "Child ID: $childId");
    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="certificate_'.$cert['unique_child_id'].'.html"');
    readfile($file);
    exit;
}


//nursessssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssss

// ==================== NURSE PENDING PARENTS ====================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/nurse/pending-parents') {
    $stmt = $pdo->query("SELECT id, name, email, phone, created_at FROM users WHERE role_id = 1 AND is_verified = 0 ORDER BY created_at ASC");
    echo json_encode(["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('#^/api/nurse/approve-parent/(\d+)$#', $path, $m)) {
    $parentId = $m[1];
    $pdo->prepare("UPDATE users SET is_verified = 1 WHERE id = ? AND role_id = 1 AND is_verified = 0")->execute([$parentId]);
    audit_log($pdo, 1, 'APPROVE_PARENT', "Parent ID: $parentId approved");
    echo json_encode(["success" => true, "message" => "Parent approved"]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('#^/api/nurse/reject-parent/(\d+)$#', $path, $m)) {
    $parentId = $m[1];
    // soft reject - set verified=2 (or delete) – set to -1 for rejected
    $pdo->prepare("UPDATE users SET is_verified = -1 WHERE id = ? AND role_id = 1 AND is_verified = 0")->execute([$parentId]);
    audit_log($pdo, 1, 'REJECT_PARENT', "Parent ID: $parentId rejected");
    echo json_encode(["success" => true, "message" => "Parent rejected"]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/nurse/my-children') {
    $nurseId = isset($_GET['nurse_id']) ? (int)$_GET['nurse_id'] : 0;
    $stmt = $pdo->prepare("
        SELECT c.*, u.name AS parent_name, u.phone AS parent_phone,
               (SELECT COUNT(*) FROM appointments WHERE child_id = c.id AND status = 'completed') AS vaccines_given,
               (SELECT COUNT(*) FROM appointments WHERE child_id = c.id) AS total_appointments
        FROM children c
        JOIN nurse_assignments na ON c.id = na.child_id AND na.nurse_id = ?
        JOIN users u ON c.parent_id = u.id
        WHERE c.status = 'approved'
        ORDER BY c.name ASC
    ");
    $stmt->execute([$nurseId]);
    $children = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($children as &$child) {
        // Vaccination progress
        $child['vaccine_progress'] = $child['total_appointments'] > 0
            ? round(($child['vaccines_given'] / $child['total_appointments']) * 100)
            : 0;

        // Find the earliest upcoming appointment date (including today)
        $nextDateStmt = $pdo->prepare("
            SELECT MIN(scheduled_date) FROM appointments
            WHERE child_id = ? AND status = 'pending' AND scheduled_date >= CURDATE()
        ");
        $nextDateStmt->execute([$child['id']]);
        $child['next_appointment_date'] = $nextDateStmt->fetchColumn();

        // Get all vaccines for that date
        if ($child['next_appointment_date']) {
            $vaccinesStmt = $pdo->prepare("
                SELECT v.name FROM appointments a
                JOIN vaccines v ON a.vaccine_id = v.id
                WHERE a.child_id = ? AND a.status = 'pending'
                  AND a.scheduled_date = ?
                ORDER BY v.name
            ");
            $vaccinesStmt->execute([$child['id'], $child['next_appointment_date']]);
            $child['next_vaccines'] = $vaccinesStmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $child['next_vaccines'] = [];
        }
    }

    echo json_encode(["success" => true, "data" => $children]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/api/nurse/walkin') {
    $input = json_decode(file_get_contents('php://input'), true);
    $parentId     = $input['parent_id'] ?? 0;
    $parentName   = $input['parent_name'] ?? '';
    $parentPhone  = $input['parent_phone'] ?? '';
    $childName    = $input['child_name'] ?? '';
    $dob          = $input['dob'] ?? '';
    $gender       = $input['gender'] ?? 'Male';
    $bloodType    = $input['blood_type'] ?? '';
    $allergies    = $input['allergies'] ?? '';
    $birthWeight  = $input['birth_weight'] ?? null;
    $deliveryType = $input['delivery_type'] ?? 'Normal';
    $birthPlace   = $input['birth_place'] ?? '';
    $notes        = $input['notes'] ?? '';
    $nurseId      = isset($input['nurse_id']) ? (int)$input['nurse_id'] : 0;

    if (!$childName || !$dob) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Child name and DOB required"]);
        exit;
    }

    // If parent_id = 0, create new parent record
    if ($parentId == 0 && $parentName && $parentPhone) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ?");
        $stmt->execute([$parentPhone]);
        $existing = $stmt->fetch();
        if ($existing) {
            $parentId = $existing['id'];
        } else {
            $stmt = $pdo->prepare("INSERT INTO users (name, email, phone, password_hash, role_id, is_verified) VALUES (?, ?, ?, ?, 1, 1)");
            $stmt->execute([$parentName, $parentPhone.'@walkin.local', $parentPhone, password_hash('walkin123', PASSWORD_DEFAULT)]);
            $parentId = $pdo->lastInsertId();
            audit_log($pdo, $nurseId, 'WALKIN_PARENT_CREATED', "Parent: $parentName, Phone: $parentPhone");
        }
    }

    if ($parentId == 0) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Parent information missing"]);
        exit;
    }

    // Insert child as approved immediately
    $uniqueId = 'CHLD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
    $stmt = $pdo->prepare("INSERT INTO children (parent_id, unique_child_id, name, dob, gender, blood_type, allergies, birth_weight, delivery_type, birth_place, notes, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', NOW())");
    $stmt->execute([$parentId, $uniqueId, $childName, $dob, $gender, $bloodType, $allergies, $birthWeight, $deliveryType, $birthPlace, $notes]);
    $childId = $pdo->lastInsertId();

    // Generate vaccination schedule
    $vaccines = $pdo->query("SELECT id, days_from_birth FROM vaccines WHERE is_active = 1")->fetchAll();
    foreach ($vaccines as $vax) {
        $due = date('Y-m-d', strtotime($dob . ' + ' . $vax['days_from_birth'] . ' days'));
        $pdo->prepare("INSERT INTO appointments (child_id, vaccine_id, scheduled_date, status) VALUES (?, ?, ?, 'pending')")
            ->execute([$childId, $vax['id'], $due]);
    }

    // Assign the child to THIS nurse (the one who performed the walk‑in)
    if ($nurseId > 0) {
        $checkAssign = $pdo->prepare("SELECT id FROM nurse_assignments WHERE child_id = ? AND nurse_id = ?");
        $checkAssign->execute([$childId, $nurseId]);
        if (!$checkAssign->fetch()) {
            $pdo->prepare("INSERT INTO nurse_assignments (nurse_id, child_id, assigned_at) VALUES (?, ?, NOW())")
                ->execute([$nurseId, $childId]);
        }
    }

    audit_log($pdo, $nurseId, 'WALKIN_REGISTER', "Child ID: $childId, Name: $childName");
    echo json_encode(["success" => true, "child_id" => $childId, "message" => "Child registered and assigned to you"]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/api/nurse/record-vaccine') {
    $input = json_decode(file_get_contents('php://input'), true);
    $appointmentId = $input['appointment_id'] ?? 0;
    $batchNumber = $input['batch_number'] ?? '';
    $notes = $input['notes'] ?? '';
    $nurseId = 1; // TODO: from token

    $stmt = $pdo->prepare("SELECT * FROM appointments WHERE id = ?");
    $stmt->execute([$appointmentId]);
    $appt = $stmt->fetch();
    if (!$appt) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Appointment not found"]);
        exit;
    }

    // Mark as completed
    $pdo->prepare("UPDATE appointments SET status = 'completed', given_date = CURDATE(), batch_number = ?, nurse_id = ?, notes = ? WHERE id = ?")
        ->execute([$batchNumber, $nurseId, $notes, $appointmentId]);
     // --- Reset certificate if exists ---
$certCheck = $pdo->prepare("SELECT id FROM certificates WHERE child_id = ? AND (is_approved_by_nurse = 1 OR is_approved_by_admin = 1)");
$certCheck->execute([$appt['child_id']]);
$existingCert = $certCheck->fetch();
if ($existingCert) {
    // Revoke both approvals → status becomes outdated
    $pdo->prepare("UPDATE certificates SET is_approved_by_nurse = 0, is_approved_by_admin = 0 WHERE id = ?")
        ->execute([$existingCert['id']]);

    // Insert a notification for the assigned nurse
    $nurseStmt = $pdo->prepare("SELECT nurse_id FROM nurse_assignments WHERE child_id = ?");
    $nurseStmt->execute([$appt['child_id']]);
    $assignedNurse = $nurseStmt->fetch();
    if ($assignedNurse) {
        $pdo->prepare("INSERT INTO notifications (user_id, child_id, title, message, type)
                       VALUES (?, ?, 'Certificate Update Required', 'A new vaccine was recorded. Please re‑approve the certificate.', 'cert_update')")
            ->execute([$assignedNurse['nurse_id'], $appt['child_id']]);
    }
    audit_log($pdo, $nurseId, 'CERT_RESET', "Certificate for child {$appt['child_id']} reset due to new vaccination");
}
    // Decrease inventory quantity if batch provided
    if ($batchNumber) {
        $pdo->prepare("UPDATE inventory SET quantity = quantity - 1 WHERE vaccine_id = ? AND batch_number = ? AND quantity > 0")
            ->execute([$appt['vaccine_id'], $batchNumber]);
    }

    audit_log($pdo, $nurseId, 'RECORD_VACCINE', "Appointment ID: $appointmentId, Vaccine: {$appt['vaccine_id']}");
    echo json_encode(["success" => true, "message" => "Vaccine recorded"]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/nurse/upcoming-appointments') {
    $nurseId = isset($_GET['nurse_id']) ? (int)$_GET['nurse_id'] : 0;
    $stmt = $pdo->prepare("
        SELECT a.*, c.name AS child_name, c.unique_child_id, v.name AS vaccine_name,
               u.name AS parent_name, u.phone AS parent_phone
        FROM appointments a
        JOIN children c ON a.child_id = c.id
        JOIN vaccines v ON a.vaccine_id = v.id
        JOIN nurse_assignments na ON c.id = na.child_id AND na.nurse_id = ?
        JOIN users u ON c.parent_id = u.id
        WHERE a.status = 'pending'
          AND a.scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ORDER BY a.scheduled_date ASC
    ");
    $stmt->execute([$nurseId]);
    echo json_encode(["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/nurse/search') {
    $query   = $_GET['q'] ?? '';
    $nurseId = isset($_GET['nurse_id']) ? (int)$_GET['nurse_id'] : 0;

    if (!$query) {
        echo json_encode(["success" => true, "data" => []]);
        exit;
    }

    // 1. Find children assigned to this nurse that match the search
    $stmt = $pdo->prepare("
        SELECT c.id, c.name, c.unique_child_id, c.dob, c.gender, c.blood_type,
               c.allergies, c.status,
               u.name AS parent_name, u.phone AS parent_phone
        FROM children c
        JOIN nurse_assignments na ON c.id = na.child_id AND na.nurse_id = ?
        JOIN users u ON c.parent_id = u.id
        WHERE (c.unique_child_id LIKE ? OR c.name LIKE ?)
        ORDER BY c.name ASC
    ");
    $stmt->execute([$nurseId, "%$query%", "%$query%"]);
    $children = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Enrich each child with vaccination progress and upcoming appointments
    foreach ($children as &$child) {
        // Total appointments and completed count
        $total = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE child_id = ?");
        $total->execute([$child['id']]);
        $totalCount = $total->fetchColumn();
        $completed = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE child_id = ? AND status = 'completed'");
        $completed->execute([$child['id']]);
        $completedCount = $completed->fetchColumn();
        $child['vaccine_progress'] = $totalCount > 0
            ? round(($completedCount / $totalCount) * 100)
            : 0;

        // Next appointment: earliest pending date + all vaccines on that date
        $nextDateStmt = $pdo->prepare("
            SELECT MIN(a.scheduled_date) AS next_date
            FROM appointments a
            WHERE a.child_id = ? AND a.status = 'pending' AND a.scheduled_date >= CURDATE()
        ");
        $nextDateStmt->execute([$child['id']]);
        $nextDateRow = $nextDateStmt->fetch();
        $child['next_appointment_date'] = $nextDateRow ? $nextDateRow['next_date'] : null;

        if ($child['next_appointment_date']) {
            $vaccinesStmt = $pdo->prepare("
                SELECT v.name AS vaccine_name
                FROM appointments a
                JOIN vaccines v ON a.vaccine_id = v.id
                WHERE a.child_id = ? AND a.status = 'pending'
                  AND a.scheduled_date = ?
                ORDER BY v.name
            ");
            $vaccinesStmt->execute([$child['id'], $child['next_appointment_date']]);
            $child['next_vaccines'] = $vaccinesStmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $child['next_vaccines'] = [];
        }
    }

    echo json_encode(["success" => true, "data" => $children]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/nurse/filter-by-vaccine') {
    $vaccineId = $_GET['vaccine_id'] ?? 0;
    $nurseId   = isset($_GET['nurse_id']) ? (int)$_GET['nurse_id'] : 0;

    $stmt = $pdo->prepare("
        SELECT a.*, c.name AS child_name, c.unique_child_id, v.name AS vaccine_name
        FROM appointments a
        JOIN children c ON a.child_id = c.id
        JOIN vaccines v ON a.vaccine_id = v.id
        JOIN nurse_assignments na ON c.id = na.child_id AND na.nurse_id = ?
        WHERE a.vaccine_id = ? AND a.status = 'pending'
        ORDER BY a.scheduled_date ASC
    ");
    $stmt->execute([$nurseId, $vaccineId]);
    echo json_encode(["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('#^/api/nurse/child/(\d+)/notes$#', $path, $m)) {
    $childId = $m[1];
    $input = json_decode(file_get_contents('php://input'), true);
    $notes = $input['notes'] ?? '';
    $pdo->prepare("UPDATE children SET notes = ? WHERE id = ?")->execute([$notes, $childId]);
    echo json_encode(["success" => true, "message" => "Notes saved"]);
    exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('#^/api/nurse/appointment/(\d+)/approve-reschedule$#', $path, $m)) {
    $apptId = $m[1];
    $input = json_decode(file_get_contents('php://input'), true);
    $approved = $input['approved'] ?? false;
    if ($approved) {
        $pdo->prepare("UPDATE appointments SET scheduled_date = reschedule_request_date, reschedule_request_date = NULL, status = 'pending' WHERE id = ?")->execute([$apptId]);
    } else {
        $pdo->prepare("UPDATE appointments SET reschedule_request_date = NULL, status = 'pending' WHERE id = ?")->execute([$apptId]);
    }
    audit_log($pdo, 1, 'APPROVE_RESCHEDULE', "Appointment ID: $apptId, Approved: $approved");
    echo json_encode(["success" => true]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('#^/api/nurse/approve-certificate/(\d+)$#', $path, $m)) {
    $certId = $m[1];
    $pdo->prepare("UPDATE certificates SET is_approved_by_nurse = 1 WHERE id = ? AND is_approved_by_nurse = 0")->execute([$certId]);
    audit_log($pdo, 1, 'NURSE_CERT_APPROVE', "Certificate ID: $certId");
    echo json_encode(["success" => true, "message" => "Certificate approved"]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('#^/api/appointments/child/(\d+)$#', $path, $m)) {
    $childId = $m[1];
    $stmt = $pdo->prepare("
        SELECT a.*, v.name AS vaccine_name
        FROM appointments a
        JOIN vaccines v ON a.vaccine_id = v.id
        WHERE a.child_id = ?
        ORDER BY a.scheduled_date ASC
    ");
    $stmt->execute([$childId]);
    echo json_encode(["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ==================== GET CERTIFICATE STATUS ====================
// ==================== GET CERTIFICATE STATUS BY CHILD ID ====================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('#^/api/certificates/child/(\d+)$#', $path, $m)) {
    $childId = $m[1];

    $stmt = $pdo->prepare("
        SELECT c.*, ch.name AS child_name, ch.unique_child_id
        FROM certificates c
        JOIN children ch ON c.child_id = ch.id
        WHERE c.child_id = ?
    ");
    $stmt->execute([$childId]);
    $cert = $stmt->fetch();

    if (!$cert) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "No certificate found for this child"]);
        exit;
    }

    // Determine dynamic status text
    if ($cert['is_approved_by_nurse'] && $cert['is_approved_by_admin']) {
        $cert['status_text'] = 'Ready for Download';
        $cert['is_fully_approved'] = true;
    } elseif ($cert['is_approved_by_nurse'] && !$cert['is_approved_by_admin']) {
        $cert['status_text'] = 'Waiting for Admin Sign-off…';
        $cert['is_fully_approved'] = false;
    } elseif (!$cert['is_approved_by_nurse'] && !$cert['is_approved_by_admin']) {
        $cert['status_text'] = 'New Vaccine Added – Certificate Updating…';
        $cert['is_fully_approved'] = false;
    } else {
        $cert['status_text'] = 'Pending Nurse Approval…';
        $cert['is_fully_approved'] = false;
    }

    echo json_encode(["success" => true, "data" => $cert]);
    exit;
}

// Admin upload stamp or signature
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/api/admin/upload-branding') {
    $type = $_POST['type'] ?? '';  // 'stamp' or 'signature'
    if (!in_array($type, ['stamp', 'signature'])) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Invalid type"]);
        exit;
    }

    if (!isset($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "File missing"]);
        exit;
    }

    $dir = __DIR__ . '/../../storage/branding/';
    if (!is_dir($dir)) mkdir($dir, 0777, true);

    $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
    $filename = $type . '.' . $ext;
    move_uploaded_file($_FILES['file']['tmp_name'], $dir . $filename);

    // Save path to settings
    $stmt = $pdo->prepare("REPLACE INTO settings (`key`, `value`) VALUES (?, ?)");
    $stmt->execute([$type . '_image', $dir . $filename]);

    echo json_encode(["success" => true, "message" => ucfirst($type) . " uploaded"]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/admin/get-branding') {
    $stmt = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('stamp_image', 'signature_image')");
    $settings = [];
    while ($row = $stmt->fetch()) {
        $settings[$row['key']] = $row['value'];
    }
    echo json_encode(["success" => true, "data" => $settings]);
    exit;
}




// ==================== CATCH-ALL ====================
if (strpos($path, '/api/') === 0) {
    echo json_encode(["success" => true, "message" => "Demo endpoint – implement later"]);
    exit;
}

// ==================== 404 ====================
http_response_code(404);
echo "Not Found";