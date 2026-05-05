<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/JwtHelper.php';

$db = Database::getConnection();

// Authenticate nurse
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';
if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Missing token']);
    exit;
}
$payload = JwtHelper::decode($matches[1]);
if (!$payload || $payload['role'] !== 'nurse') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$request_uri = $_SERVER['REQUEST_URI'];

// 1. GET pending parents
if ($_SERVER['REQUEST_METHOD'] === 'GET' && strpos($request_uri, '/api/nurse/pending-parents') !== false) {
    $stmt = $db->prepare("SELECT id, name, email, phone, created_at FROM users WHERE role_id = 1 AND is_verified = 0");
    $stmt->execute();
    $parents = $stmt->fetchAll();
    echo json_encode(['success' => true, 'data' => $parents]);
    exit;
}

// 2. POST approve parent
if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('#/api/nurse/approve-parent/(\d+)#', $request_uri, $matches2)) {
    $parentId = $matches2[1];
    $stmt = $db->prepare("UPDATE users SET is_verified = 1, approved_by = ? WHERE id = ?");
    $stmt->execute([$payload['user_id'], $parentId]);
    echo json_encode(['success' => true, 'message' => 'Parent approved']);
    exit;
}

// 3. GET nurse's assigned children
if ($_SERVER['REQUEST_METHOD'] === 'GET' && strpos($request_uri, '/api/nurse/my-children') !== false) {
    $stmt = $db->prepare("
        SELECT c.*, u.name as parent_name, u.phone as parent_phone 
        FROM children c
        JOIN nurse_assignments na ON c.id = na.child_id
        JOIN users u ON c.parent_id = u.id
        WHERE na.nurse_id = ?
    ");
    $stmt->execute([$payload['user_id']]);
    $children = $stmt->fetchAll();
    echo json_encode(['success' => true, 'data' => $children]);
    exit;
}

// 4. POST walk-in registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($request_uri, '/api/nurse/walkin') !== false) {
    $input = json_decode(file_get_contents('php://input'), true);
    $parentPhone = $input['parent_phone'] ?? '';
    $child = $input['child'] ?? [];

    if (!$parentPhone || empty($child['name']) || empty($child['dob'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Parent phone and child name/DOB required']);
        exit;
    }

    // Find or create parent
    $stmt = $db->prepare("SELECT id, is_verified FROM users WHERE phone = ?");
    $stmt->execute([$parentPhone]);
    $parent = $stmt->fetch();
    if (!$parent) {
        $tempEmail = $parentPhone . '@temp.com';
        $tempPass = bin2hex(random_bytes(4));
        $hash = password_hash($tempPass, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (name, email, phone, password_hash, role_id) VALUES (?, ?, ?, ?, 1)");
        $stmt->execute([$input['parent_name'] ?? 'Walk-in Parent', $tempEmail, $parentPhone, $hash]);
        $parentId = $db->lastInsertId();
        // Auto-approve parent (nurse registered)
        $db->prepare("UPDATE users SET is_verified = 1, approved_by = ? WHERE id = ?")->execute([$payload['user_id'], $parentId]);
    } else {
        $parentId = $parent['id'];
        if (!$parent['is_verified']) {
            $db->prepare("UPDATE users SET is_verified = 1, approved_by = ? WHERE id = ?")->execute([$payload['user_id'], $parentId]);
        }
    }

    // Create child
    $uniqueId = 'CH-' . strtoupper(bin2hex(random_bytes(5)));
    $stmt = $db->prepare("INSERT INTO children (parent_id, unique_child_id, name, dob, gender, blood_type, allergies, birth_weight, delivery_type, birth_place) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $parentId, $uniqueId,
        $child['name'], $child['dob'], $child['gender'] ?? 'Male',
        $child['blood_type'] ?? null, $child['allergies'] ?? '',
        $child['birth_weight'] ?? null, $child['delivery_type'] ?? null,
        $child['birth_place'] ?? ''
    ]);
    $childId = $db->lastInsertId();

    // Assign nurse
    $db->prepare("INSERT INTO nurse_assignments (child_id, nurse_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE nurse_id = VALUES(nurse_id)")->execute([$childId, $payload['user_id']]);

    // Auto-generate appointments (EPI schedule)
    $vaccines = $db->query("SELECT id, days_from_birth FROM vaccines WHERE is_active = 1")->fetchAll();
    foreach ($vaccines as $v) {
        $dueDate = date('Y-m-d', strtotime($child['dob'] . ' + ' . $v['days_from_birth'] . ' days'));
        $db->prepare("INSERT INTO appointments (child_id, vaccine_id, scheduled_date) VALUES (?, ?, ?)")->execute([$childId, $v['id'], $dueDate]);
    }

    echo json_encode(['success' => true, 'child_id' => $childId, 'unique_child_id' => $uniqueId]);
    exit;
}

http_response_code(404);
echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
?>