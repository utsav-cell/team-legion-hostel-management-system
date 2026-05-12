<?php
// ─────────────────────────────────────────────────
// student/book_room.php — FR-S2-008 Booking Creation
//   POST handler. Inserts bookings row, emails warden/owner, redirects.
// ─────────────────────────────────────────────────

require_once '../db.php';
require_once '../auth/mailer.php';
require_role('student');

$uid = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: browse_rooms.php'); exit;
}

try {
    csrf_verify($_POST['csrf_token'] ?? '');
    $room_id = (int)($_POST['room_id'] ?? 0);
    if (!$room_id) throw new Exception('Pick a room first.');

    
    // Block if student already has an active booking.
    $ab = $pdo->prepare("SELECT id, status FROM bookings WHERE student_id = ? AND status IN ('pending_approval','approved','active') LIMIT 1");
    $ab->execute([$uid]);
    if ($ab->fetch()) throw new Exception('You already have an active booking. Cancel it first.');

    // Room must exist and be bookable (available status, not deleted).
    $r = $pdo->prepare("SELECT id, room_number, room_type, price, capacity FROM rooms WHERE id = ? AND status = 'available' AND deleted_at IS NULL LIMIT 1");
    $r->execute([$room_id]);
    $room = $r->fetch();
    if (!$room) throw new Exception('Room no longer available.');

    // Capacity check — count pending+approved+active bookings on this room.
    // Single-cap rooms reject anyone after the 1st; double-cap rooms after the 2nd; etc.
    $cap = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE room_id = ? AND status IN ('pending_approval','approved','active')");
    $cap->execute([$room_id]);
    $taken = (int)$cap->fetchColumn();
    if ($taken >= (int)$room['capacity']) {
        throw new Exception('Room is fully booked (' . $taken . '/' . (int)$room['capacity'] . '). Pick a different one.');
    }

    $pdo->prepare("INSERT INTO bookings (student_id, room_id, start_date, status) VALUES (?, ?, CURDATE(), 'pending_approval')")
        ->execute([$uid, $room_id]);
    $booking_id = (int)$pdo->lastInsertId();

    // Email warden(s) + owner(s) — best effort.
    $student = $pdo->prepare('SELECT name, email FROM users WHERE id = ? LIMIT 1');
    $student->execute([$uid]);
    $stu = $student->fetch();

    $staff = $pdo->query("SELECT name, email, role FROM users WHERE role IN ('warden','owner') AND email IS NOT NULL")->fetchAll();
    foreach ($staff as $s) {
        try {
            $body = "<p>Hi " . htmlspecialchars($s['name']) . ",</p>"
                  . "<p>A new room booking is awaiting approval:</p>"
                  . "<ul>"
                  . "<li><strong>Student:</strong> " . htmlspecialchars($stu['name']) . " (" . htmlspecialchars($stu['email']) . ")</li>"
                  . "<li><strong>Room:</strong> " . htmlspecialchars($room['room_number']) . " · " . htmlspecialchars($room['room_type']) . "</li>"
                  . "<li><strong>Monthly price:</strong> NPR " . number_format((float)$room['price'], 2) . "</li>"
                  . "<li><strong>Booking ID:</strong> #$booking_id</li>"
                  . "</ul>"
                  . "<p>Open the warden dashboard → Booking Requests to approve or reject.</p>";
            send_app_mail($s['email'], $s['name'], 'New booking request — HMS', $body);
        } catch (Exception $e) { /* swallow — booking already created */ }
    }

    header('Location: my_bookings.php?msg=' . urlencode("Booking #$booking_id submitted for Room {$room['room_number']}. Waiting for warden approval."));
    exit;
} catch (Exception $ex) {
    header('Location: browse_rooms.php?err=' . urlencode($ex->getMessage()));
    exit;
}
