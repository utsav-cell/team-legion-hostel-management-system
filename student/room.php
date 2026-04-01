<?php
// ─────────────────────────────────────────────────
// student/room.php — View My Room Details
// ─────────────────────────────────────────────────

require_once '../dp.php';
require_role('student');

$uid = (int)$_SESSION['user_id'];

// Get room details — prepared statement
$stmt = mysqli_prepare($conn,
    "SELECT r.room_number, r.room_type, r.floor, u.room_status
     FROM users u
     LEFT JOIN rooms r ON r.id = u.room_id
     WHERE u.id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $uid);
mysqli_stmt_execute($stmt);
$room = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Room — HMS</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
<?php echo render_sidebar('room.php'); ?>
<div class="container">
    <div class="page-header">
        <h1>My Room</h1>
        <p>Your current accommodation details</p>
    </div>

    <?php if ($room && $room['room_number']): ?>
        <!-- Status Banner -->
        <div style="padding:1.5rem; border-radius:16px; margin-bottom:2rem;
             background:<?= $room['room_status'] === 'approved'
                 ? '#f0fdf4' : '#fffbeb' ?>;
             border:1px solid <?= $room['room_status'] === 'approved'
                 ? '#dcfce7' : '#fef3c7' ?>;">
            <div style="font-weight:800; font-size:1.125rem;
                         color:<?= $room['room_status'] === 'approved'
                             ? '#166534' : '#92400e' ?>;">
                Status:
                <?= $room['room_status'] === 'approved'
                    ? 'Confirmed' : 'Pending Approval' ?>
            </div>
            <div style="font-size:0.875rem; margin-top:0.25rem;
                         color:<?= $room['room_status'] === 'approved'
                             ? '#166534' : '#92400e' ?>;">
                <?= $room['room_status'] === 'approved'
                    ? 'Your room has been confirmed. Welcome!'
                    : 'Your room is assigned and waiting for owner approval.' ?>
            </div>
        </div>

        <!-- Room Info Cards -->
        <div class="stats-grid">
            <div class="stat-box">
                <div class="stat-label">Room Number</div>
                <div class="stat-value"><?= e($room['room_number']) ?></div>
            </div>
            <div class="stat-box">
                <div class="stat-label">Room Type</div>
                <div class="stat-value" style="font-size:1.5rem;">
                    <?= e($room['room_type'] ?: 'Standard') ?>
                </div>
            </div>
            <div class="stat-box">
                <div class="stat-label">Floor</div>
                <div class="stat-value">
                    <?= e((string)$room['floor']) ?>
                </div>
            </div>
        </div>

        <!-- Guidelines -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Hostel Guidelines</h2>
            </div>
            <ul style="list-style:none; color:var(--text-muted);
                        font-size:0.9rem; line-height:2.2;">
                <li>Keep your room clean and tidy at all times</li>
                <li>Curfew: 11:30 PM — all students must be inside</li>
                <li>No visitors allowed after 9:00 PM</li>
                <li>Report any damage or issues via the Complaints section</li>
            </ul>
        </div>

    <?php else: ?>
        <div class="card" style="text-align:center; padding:5rem 2rem;">
            <h2 style="margin-bottom:0.5rem; font-weight:800;">
                No Room Allocated
            </h2>
            <p style="color:var(--text-muted); max-width:400px;
                       margin:0 auto 2rem;">
                Your registration is complete. The Warden will assign
                you a room shortly.
            </p>
            <div style="display:inline-block; padding:0.5rem 1rem;
                         background:var(--brand-bg); color:var(--brand);
                         border-radius:12px; font-weight:700;">
                Status: Pending Placement
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
