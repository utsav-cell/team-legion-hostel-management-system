<?php
require_once '../db.php';
require_role('student');

$uid  = (int)$_SESSION['user_id'];
$name = $_SESSION['user_name'];
$today = date('l, d M Y');

$stmt = mysqli_prepare($conn,
    "SELECT r.room_number, r.room_type, r.floor, u.room_status
     FROM users u
     LEFT JOIN rooms r ON r.id = u.room_id
     WHERE u.id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $uid);
mysqli_stmt_execute($stmt);
$room = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

$stmt2 = mysqli_prepare($conn, "SELECT status FROM attendance WHERE student_id = ?");
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
$statusClass = $pct >= 75 ? 'success' : 'danger';
$statusBadge = $pct >= 75 ? 'badge-green' : 'badge-red';

$stmt3 = mysqli_prepare($conn, "SELECT COUNT(*) AS total_open FROM complaints WHERE student_id = ? AND status = 'open'");
mysqli_stmt_bind_param($stmt3, 'i', $uid);
mysqli_stmt_execute($stmt3);
$complaints = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt3));
mysqli_stmt_close($stmt3);
$activeComplaints = (int)($complaints['total_open'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard — HMS</title>
    <link rel="stylesheet" href="../css/style.css?v=4">
</head>
<body>
<?= render_sidebar('dashboard.php'); ?>
<div class="container">
    <section class="page-header" data-reveal>
        <h1>Dashboard</h1>
        <p>Welcome back, <?= e($name) ?>. Here's your hostel summary for the week.</p>
    </section>

    <section class="stats-grid">
        <a class="stat-box stat-link" data-reveal href="room.php">
            <div class="stat-label">Room Status</div>
            <div class="stat-value"><?= $room && $room['room_number'] ? 'Room ' . e($room['room_number']) : 'Pending' ?></div>
            <div class="stat-meta"><?= $room && $room['room_number'] ? e($room['room_type']) . ' · Floor ' . e((string)$room['floor']) : 'Awaiting allocation' ?></div>
        </a>
        <a class="stat-box stat-link" data-reveal href="my_attendance.php">
            <div class="stat-label">Attendance Percentage</div>
            <div class="stat-value" data-count="<?= $pct ?>" data-suffix="%">0%</div>
            <div class="stat-meta">Last 30 days</div>
        </a>
        <a class="stat-box stat-link" data-reveal href="my_complaints.php">
            <div class="stat-label">Active Complaints</div>
            <div class="stat-value" data-count="<?= $activeComplaints ?>"><?= $activeComplaints ?></div>
            <div class="stat-meta"><?= $activeComplaints === 1 ? '1 open issue' : $activeComplaints . ' open issues' ?></div>
        </a>
    </section>

    <section class="card" data-reveal>
        <div class="card-header">
            <div>
                <h2 class="card-title">Recent Activity</h2>
                <p class="card-subtitle">Your latest updates and notifications.</p>
            </div>
            <span class="badge <?= $statusBadge ?>"><?= $pct >= 75 ? 'Good Standing' : 'Action Required' ?></span>
        </div>
        <div class="activity-list">
            <a class="activity-item activity-link" href="my_attendance.php">
                <div class="activity-icon activity-icon-blue"></div>
                <div>
                    <strong>Attendance marked</strong>
                    <p>Your attendance was marked for today.</p>
                    <span class="activity-time">2 hours ago</span>
                </div>
            </a>
            <a class="activity-item activity-link" href="my_complaints.php">
                <div class="activity-icon activity-icon-green"></div>
                <div>
                    <strong>Complaint updated</strong>
                    <p>Your WiFi complaint is now in progress.</p>
                    <span class="activity-time">5 hours ago</span>
                </div>
            </a>
            <a class="activity-item activity-link" href="room.php">
                <div class="activity-icon activity-icon-orange"></div>
                <div>
                    <strong>Room booking confirmed</strong>
                    <p>Your room has been allocated for this semester.</p>
                    <span class="activity-time">1 day ago</span>
                </div>
            </a>
        </div>
    </section>
</div>
<script src="../js/script.js"></script>
</body>
</html>
