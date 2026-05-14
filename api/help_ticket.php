<?php
// -------------------------------------------------
// api/help_ticket.php - Help ticket submission endpoint
//   Authenticated users can submit a new help ticket.
//   Accepts POST only. Returns JSON.
// -------------------------------------------------

require_once '../db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// Require authenticated user
$auth = get_auth();
if (!$auth) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'You must be logged in to submit a ticket.']);
    exit;
}

// CSRF check
try {
    csrf_verify($_POST['csrf_token'] ?? '');
} catch (Throwable $ex) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security error: invalid CSRF token.']);
    exit;
}

$user_id     = (int)$auth['id'];
$user_role   = $auth['role'];
$subject     = trim($_POST['subject'] ?? '');
$description = trim($_POST['description'] ?? '');

// Validate
if (!$subject) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Subject is required.']);
    exit;
}
if (mb_strlen($subject) > 255) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Subject must not exceed 255 characters.']);
    exit;
}
if (!$description) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Description is required.']);
    exit;
}
if (mb_strlen($description) < 10) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Description must be at least 10 characters.']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "INSERT INTO help_tickets (user_id, role, subject, description, status)
         VALUES (?, ?, ?, ?, 'open')"
    );
    $stmt->execute([$user_id, $user_role, $subject, $description]);
    echo json_encode(['success' => true, 'message' => 'Ticket submitted successfully.']);
} catch (PDOException $ex) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
}
?>
