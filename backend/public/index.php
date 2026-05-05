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
    $nurseId = 1; // TODO: extract from real nurse token

    $stmt = $pdo->prepare("SELECT * FROM children WHERE id = ? AND status = 'pending'");
    $stmt->execute([$childId]);
    $child = $stmt->fetch();
    if (!$child) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Child not found or already processed"]);
        exit;
    }

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

    // Auto-assign nurse
    $nurseStmt = $pdo->query("
        SELECT u.id, COUNT(na.child_id) AS assigned_count
        FROM users u
        LEFT JOIN nurse_assignments na ON u.id = na.nurse_id
        WHERE u.role_id = 2 AND u.is_verified = 1
        GROUP BY u.id
        ORDER BY assigned_count ASC
        LIMIT 1
    ");
    $assignedNurse = $nurseStmt->fetch();
    if ($assignedNurse) {
        $pdo->prepare("INSERT INTO nurse_assignments (nurse_id, child_id, assigned_at) VALUES (?, ?, NOW())")
            ->execute([$assignedNurse['id'], $childId]);
    }

    echo json_encode(["success" => true, "message" => "Child approved, schedule generated, nurse assigned"]);
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
        JOIN users u ON r.nurse_id = u.id
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
    $stmt = $pdo->query("
        SELECT i.*, v.name AS vaccine_name
        FROM inventory i
        JOIN vaccines v ON i.vaccine_id = v.id
        ORDER BY i.expiry_date ASC
    ");
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
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/nurse/my-children')       { echo json_encode([]); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/nurse/pending-parents')   { echo json_encode([]); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/nurse/upcoming-appointments') { echo json_encode([]); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/api/nurse/walkin')           { echo json_encode(["success"=>true,"child_id"=>123]); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/api/nurse/record-vaccine')   { echo json_encode(["success"=>true]); exit; }


// ==================== CERTIFICATE GENERATION ====================
// ==================== CERTIFICATE GENERATION (after both approvals) ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/api/certificates/generate') {
    $input = json_decode(file_get_contents('php://input'), true);
    $childId = $input['child_id'] ?? 0;
    
    // Check both approvals exist for this child
    $stmt = $pdo->prepare("
        SELECT * FROM certificates WHERE child_id = ? AND is_approved_by_nurse = 1 AND is_approved_by_admin = 1
    ");
    $stmt->execute([$childId]);
    $cert = $stmt->fetch();
    if (!$cert) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Certificate not fully approved yet."]);
        exit;
    }
    
    // Fetch child data
    $childStmt = $pdo->prepare("SELECT * FROM children WHERE id = ?");
    $childStmt->execute([$childId]);
    $child = $childStmt->fetch();
    if (!$child) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Child not found"]);
        exit;
    }
    
    // Fetch parent data
    $parentStmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $parentStmt->execute([$child['parent_id']]);
    $parent = $parentStmt->fetch();
    $parentName = $parent ? $parent['name'] : 'Unknown';
    
    // Fetch completed appointments
    $apptStmt = $pdo->prepare("
        SELECT a.*, v.name as vaccine_name 
        FROM appointments a 
        JOIN vaccines v ON a.vaccine_id = v.id 
        WHERE a.child_id = ? AND a.status = 'completed'
    ");
    $apptStmt->execute([$childId]);
    $appts = $apptStmt->fetchAll();
    
    $kebele = "04";
    $woreda = "Sample Woreda";
    $zone = "Sample Zone";
    $region = "Sample Region";
    
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Vaccination Certificate</title>
    <style>
        body{font-family:"Times New Roman", serif; margin:30px; background:#fff;}
        .certificate{border:5px double #2c3e50; padding:30px; max-width:800px; margin:0 auto; position:relative;}
        .header{text-align:center; border-bottom:2px solid #2c3e50; margin-bottom:20px;}
        .header .title{font-size:24px; font-weight:bold; color:#1a5276;}
        .header .subtitle{font-size:14px; color:#555;}
        .stamp{position:absolute; top:10px; right:20px; font-size:12px; border:2px solid #b33939; border-radius:50%; width:80px; height:80px; text-align:center; line-height:80px; color:#b33939; transform:rotate(-15deg); font-weight:bold;}
        .info-table{width:100%; margin:20px 0; border-collapse:collapse;}
        .info-table td{padding:5px; border-bottom:1px dotted #ccc; font-size:14px;}
        .info-table td:first-child{font-weight:bold; width:30%;}
        .vax-table{width:100%; border-collapse:collapse; margin-top:20px;}
        .vax-table th,.vax-table td{border:1px solid #333; padding:6px; text-align:left; font-size:13px;}
        .vax-table th{background:#2c3e50; color:#fff;}
        .footer{text-align:center; margin-top:30px; font-size:12px;}
        .signature{margin-top:40px; display:flex; justify-content:space-between;}
        .signature .line{width:200px; border-top:1px solid #000; padding-top:5px; font-size:12px; text-align:center;}
    </style></head><body><div class="certificate">';
    
    $html .= '<div class="header"><div class="title">የክትባት ሰርተፍኬት<br>VACCINATION CERTIFICATE</div><div class="subtitle">Federal Democratic Republic of Ethiopia<br>Ministry of Health</div></div>';
    $html .= '<div class="stamp">APPROVED</div>';
    $html .= '<table class="info-table"><tr><td>Child Name / የልጅ ስም:</td><td>'.htmlspecialchars($child['name']).'</td></tr>';
    $html .= '<tr><td>Unique ID / መለያ ቁጥር:</td><td>'.htmlspecialchars($child['unique_child_id']).'</td></tr>';
    $html .= '<tr><td>Date of Birth / የትውልድ ቀን:</td><td>'.$child['dob'].'</td></tr>';
    $html .= '<tr><td>Gender / ጾታ:</td><td>'.$child['gender'].'</td></tr>';
    $html .= '<tr><td>Parent/Guardian / ወላጅ:</td><td>'.htmlspecialchars($parentName).'</td></tr>';
    $html .= '<tr><td>Kebele / ቀበሌ:</td><td>'.$kebele.'</td></tr>';
    $html .= '<tr><td>Woreda / ወረዳ:</td><td>'.$woreda.'</td></tr>';
    $html .= '<tr><td>Zone / ዞን:</td><td>'.$zone.'</td></tr>';
    $html .= '<tr><td>Region / ክልል:</td><td>'.$region.'</td></tr></table>';
    $html .= '<h4>Vaccines Administered / የተከተቡ ክትባቶች</h4><table class="vax-table"><thead><tr><th>Vaccine</th><th>Date Given</th><th>Batch No.</th></tr></thead><tbody>';
    
    foreach ($appts as $v) {
        $batch = $v['batch_number'] ?? 'N/A';
        $html .= '<tr><td>'.htmlspecialchars($v['vaccine_name']).'</td>
               <td>'.$v['given_date'].'</td>
               <td>'.$batch.'</td></tr>';
    }
    $html .= '</tbody></table>';
    $html .= '<div class="footer"><p>Issued under the authority of the Ethiopian Ministry of Health</p><p>Certificate ID: '.$cert['id'].' | Date: '.date('F d, Y').'</p></div>';
    $html .= '<div class="signature"><div class="line">Health Center Stamp</div><div class="line">Authorized Signature</div></div>';
    $html .= '</div></body></html>';
    
    // Save file
    $dir = __DIR__.'/../../storage/certificates/';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $filename = 'cert_'.$child['unique_child_id'].'_'.time().'.html';
    file_put_contents($dir.$filename, $html);
    $pdo->prepare("UPDATE certificates SET file_path = ? WHERE id = ?")->execute([$dir.$filename, $cert['id']]);
    
    echo json_encode(["success" => true, "message" => "Certificate generated"]);
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

    // 4. Ethiopian‑style certificate HTML
    $kebele = "04";
    $woreda = "Yeka";
    $zone   = "Addis Ababa";
    $region = "Addis Ababa City Administration";

    $html = '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>Vaccination Certificate</title>
<style>
    body { font-family:"Times New Roman",serif; margin:30px; background:#fff; }
    .certificate {
        border:5px double #2c3e50; padding:30px; max-width:800px; margin:0 auto;
        position:relative;
    }
    .header { text-align:center; border-bottom:2px solid #2c3e50; margin-bottom:20px; }
    .header .title { font-size:26px; font-weight:bold; color:#1a5276; }
    .header .subtitle { font-size:15px; color:#555; }
    .stamp {
        position:absolute; top:20px; right:20px; font-size:12px; color:#b33939;
        border:2px solid #b33939; border-radius:50%; width:80px; height:80px;
        text-align:center; line-height:80px; transform:rotate(-15deg); font-weight:bold;
    }
    .info-table { width:100%; margin:20px 0; border-collapse:collapse; }
    .info-table td { padding:6px; border-bottom:1px dotted #ccc; font-size:14px; }
    .info-table td:first-child { font-weight:bold; width:30%; }
    .vax-table { width:100%; border-collapse:collapse; margin-top:20px; }
    .vax-table th, .vax-table td { border:1px solid #333; padding:7px; text-align:left; font-size:13px; }
    .vax-table th { background:#2c3e50; color:#fff; }
    .footer { text-align:center; margin-top:30px; font-size:12px; }
    .signature { margin-top:40px; display:flex; justify-content:space-between; }
    .signature .line { width:200px; border-top:1px solid #000; padding-top:5px; font-size:12px; text-align:center; }
</style></head><body>
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
    <div class="signature">
        <div class="line">Health Center Stamp / ማህተም</div>
        <div class="line">Authorized Signature / ፊርማ</div>
    </div>
</div></body></html>';

    // 5. Save the file
    $dir = __DIR__ . '/../../storage/certificates/';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $filename = 'cert_' . $cert['unique_child_id'] . '.html';
    file_put_contents($dir . $filename, $html);
    $stmt = $pdo->prepare("UPDATE certificates SET file_path = ? WHERE id = ?");
    $stmt->execute([$dir . $filename, $certId]);

    echo json_encode(["success" => true, "message" => "Certificate approved and generated"]);
    exit;
}

// ==================== PARENT DOWNLOAD CERTIFICATE ====================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('#^/api/parent/child/(\d+)/certificate$#', $path, $m)) {
    $childId = $m[1];
    $stmt = $pdo->prepare("SELECT * FROM certificates WHERE child_id = ? AND is_approved_by_nurse = 1 AND is_approved_by_admin = 1");
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
    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="certificate_'.$cert['unique_child_id'].'.html"');
    readfile($file);
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