<?php
// ─────────────────────────────────────────────────
// student/dashboard.php — Student Home Page
// ─────────────────────────────────────────────────

require_once '../dp.php';
require_role('student');

$uid  = (int)$_SESSION['user_id'];
$name = $_SESSION['user_name'];

// Get room info — prepared statement
$stmt = mysqli_prepare($conn,
    "SELECT r.room_number, r.room_type, r.floor, u.room_status
     FROM users u
     LEFT JOIN rooms r ON r.id = u.room_id
     WHERE u.id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $uid);
mysqli_stmt_execute($stmt);
$room = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

// Attendance stats — prepared statement
$stmt2 = mysqli_prepare($conn,
    "SELECT status FROM attendance WHERE student_id = ?");
mysqli_stmt_bind_param($stmt2, 'i', $uid);
mysqli_stmt_execute($stmt2);
$att_res = mysqli_stmt_get_result($stmt2);
$total = $present = 0;
while ($r = mysqli_fetch_assoc($att_res)) {
    $total++;
    if ($r['status'] === 'present') $present++;
}
mysqli_stmt_close($stmt2);
$absent = $total - $present;
$pct    = $total > 0 ? round($present / $total * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard — HMS</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
<?php echo render_sidebar('dashboard.php'); ?>
<div class="container">
    <div class="page-header">
        <h1>Dashboard</h1>
        <p>Hello, <strong><?= e($name) ?></strong></p>
    </div>

    <!-- Summary Cards -->
    <div class="stats-grid">
        <div class="stat-box">
            <div class="stat-label">My Room</div>
            <div class="stat-value">
                <?= ($room && $room['room_number'])
                    ? e($room['room_number']) : '—' ?>
            </div>
            <p style="font-size:0.75rem; color:var(--text-muted); margin-top:0.5rem;">
                <?= ($room && $room['room_number'])
                    ? e($room['room_type']) . ' · Floor ' . e((string)$room['floor'])
                    : 'Not assigned yet' ?>
            </p>
        </div>
        <div class="stat-box">
            <div class="stat-label">Attendance Rate</div>
            <div class="stat-value"
                 style="color:<?= $pct >= 75 ? '#16a34a' : '#be123c' ?>;">
                <?= $pct ?>%
            </div>
            <p style="font-size:0.75rem; color:var(--text-muted); margin-top:0.5rem;">
                <?= $present ?> of <?= $total ?> days present
            </p>
        </div>
        <div class="stat-box">
            <div class="stat-label">Days Present</div>
            <div class="stat-value" style="color:#16a34a;"><?= $present ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Days Absent</div>
            <div class="stat-value" style="color:#be123c;"><?= $absent ?></div>
        </div>
    </div>

    <!-- Attendance Progress Bar -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Attendance Overview</h2>
            <span class="badge <?= $pct >= 75 ? 'badge-green' : 'badge-red' ?>">
                <?= $pct >= 75 ? 'Good Standing' : 'Action Required' ?>
            </span>
        </div>
        <div style="background:#eef2f7; border-radius:999px; height:12px;
                     overflow:hidden; margin:1.5rem 0;">
            <div style="height:100%; background:var(--brand);
                         width:<?= $pct ?>%; border-radius:999px;"></div>
        </div>
        <p style="color:var(--text-muted); font-size:0.875rem;">
            <?= $pct ?>% overall attendance rate
        </p>
    </div>

    <!-- Quick Actions -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Quick Actions</h2>
        </div>
        <div class="dashboard-actions">
            <a href="room.php"            class="action-card">My Room</a>
            <a href="my_attendance.php"   class="action-card">Attendance</a>
            <a href="my_complaints.php"   class="action-card">Complaints</a>
            <a href="food_routine.php"    class="action-card">Food Routine</a>
        </div>
    </div>
</div>
</body>
</html>
