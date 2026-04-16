<?php
require_once '../db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

csrf_verify($_POST['csrf_token'] ?? '');

$name    = trim($_POST['name'] ?? '');
$email   = trim($_POST['email'] ?? '');
$message = trim($_POST['message'] ?? '');
$source  = trim($_POST['source'] ?? 'Website Form');

if (!$name || !$message) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Name and message are required.']);
    exit;
}

if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email']);
    exit;
}

$stmt = $pdo->prepare('INSERT INTO enquiries (name, email, message, source) VALUES (?, ?, ?, ?)');
if ($stmt->execute([$name, $email, $message, $source])) {
    echo json_encode(['success' => true, 'message' => 'Enquiry submitted.']);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
?>
