<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/JWTHelper.php';

$db = Database::getConnection();
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';
if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Missing token']);
    exit;
}
$payload = JwtHelper::decode($matches[1]);
if (!$payload || $payload['role'] !== 'parent') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit;
}
$parentId = $payload['user_id'];

// Add child
if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['REQUEST_URI'], '/api/parent/child') !== false) {
    $input = json_decode(file_get_contents('php://input'), true);
    $name = $input['name'] ?? '';
    $dob = $input['dob'] ?? '';
    $gender = $input['gender'] ?? 'Male';
    $blood_type = $input['blood_type'] ?? null;
    $allergies = $input['allergies'] ?? '';
    $birth_weight = $input['birth_weight'] ?? null;
    $delivery_type = $input['delivery_type'] ?? null;
    $birth_place = $input['birth_place'] ?? '';
    
    if (!$name || !$dob) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Name and date of birth required']);
        exit;
    }
    
    $uniqueId = 'CH-' . strtoupper(bin2hex(random_bytes(5)));
    $stmt = $db->prepare("INSERT INTO children (parent_id, unique_child_id, name, dob, gender, blood_type, allergies, birth_weight, delivery_type, birth_place) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$parentId, $uniqueId, $name, $dob, $gender, $blood_type, $allergies, $birth_weight, $delivery_type, $birth_place]);
    $childId = $db->lastInsertId();
    
    // Generate appointments (EPI schedule)
    $vaccines = $db->query("SELECT id, days_from_birth FROM vaccines WHERE is_active = 1")->fetchAll();
    foreach ($vaccines as $v) {
        $dueDate = date('Y-m-d', strtotime($dob . ' + ' . $v['days_from_birth'] . ' days'));
        $stmt = $db->prepare("INSERT INTO appointments (child_id, vaccine_id, scheduled_date) VALUES (?, ?, ?)");
        $stmt->execute([$childId, $v['id'], $dueDate]);
    }
    
    echo json_encode(['success' => true, 'child_id' => $childId]);
    exit;
}