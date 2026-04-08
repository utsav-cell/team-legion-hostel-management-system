<?php
require_once '../dp.php';
require_role('warden');

$name  = $_SESSION['user_name'];
$today = date('Y-m-d');

$total_students = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM users WHERE role='student'"))[0];
$stmt = mysqli_prepare($conn,
    "SELECT status, COUNT(*) as cnt FROM attendance WHERE date = ? GROUP BY status");
mysqli_stmt_bind_param($stmt, 's', $today);
mysqli_stmt_execute($stmt);
$att_res = mysqli_stmt_get_result($stmt);
$present_today = $absent_today = 0;
while ($row = mysqli_fetch_assoc($att_res)) {
    if ($row['status'] === 'present') $present_today = (int)$row['cnt'];
    if ($row['status'] === 'absent')  $absent_today  = (int)$row['cnt'];
}
mysqli_stmt_close($stmt);
$marked_today = $present_today + $absent_today;
$occupied_rooms = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM rooms WHERE status='occupied'"))[0];
$total_rooms = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM rooms"))[0];
$attendance_pct = $total_students > 0 ? round($present_today / $total_students * 100, 1) : 0;
$open_complaints = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM complaints WHERE status='open'"))[0];
$recent = [];
$res = mysqli_query($conn, "SELECT name, student_phone, room_preference, created_at FROM users WHERE role='student' ORDER BY created_at DESC LIMIT 5");
while ($r = mysqli_fetch_assoc($res)) $recent[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warden Dashboard — HMS</title>
    <link rel="stylesheet" href="../css/style.css?v=4">
</head>
<body>
<?= render_sidebar('dashboard.php'); ?>
<div class="container">
    <section class="page-header" data-reveal>
        <h1>Dashboard</h1>
        <p>Welcome back, <?= e($name) ?>. Here is today's hostel summary.</p>
    </section>

    <section class="stats-grid">
        <a class="stat-box stat-link" data-reveal href="room_requests.php">
            <div class="stat-label">Room Status</div>
            <div class="stat-value"><?= $occupied_rooms ?>/<?= $total_rooms ?></div>
            <div class="stat-meta">Occupied rooms</div>
        </a>
        <a class="stat-box stat-link" data-reveal href="student_attendance.php">
            <div class="stat-label">Attendance Percentage</div>
            <div class="stat-value" data-count="<?= $attendance_pct ?>" data-suffix="%">0%</div>
            <div class="stat-meta">Present today</div>
        </a>
        <a class="stat-box stat-link" data-reveal href="complaints.php">
            <div class="stat-label">Active Complaints</div>
            <div class="stat-value" data-count="<?= $open_complaints ?>"><?= $open_complaints ?></div>
            <div class="stat-meta">Open issues</div>
        </a>
    </section>

    <section class="card" data-reveal>
        <div class="card-header">
            <div>
                <h2 class="card-title">Recent Activity</h2>
                <p class="card-subtitle">Latest updates and daily checkpoints.</p>
            </div>
            <a href="student_attendance.php" class="btn btn-secondary">Attendance</a>
        </div>
        <div class="activity-list">
            <a class="activity-item activity-link" href="student_attendance.php">
                <div class="activity-icon activity-icon-blue"></div>
                <div>
                    <strong>Attendance marked</strong>
                    <p><?= $marked_today ?> records captured for today.</p>
                    <span class="activity-time">Today</span>
                </div>
            </a>
            <a class="activity-item activity-link" href="room_requests.php">
                <div class="activity-icon activity-icon-orange"></div>
                <div>
                    <strong>Room requests pending</strong>
                    <p>Review allocation requests from students.</p>
                    <span class="activity-time">This week</span>
                </div>
            </a>
            <a class="activity-item activity-link" href="complaints.php">
                <div class="activity-icon activity-icon-green"></div>
                <div>
                    <strong>Complaints to review</strong>
                    <p><?= $open_complaints ?> open issues in the queue.</p>
                    <span class="activity-time">Open</span>
                </div>
            </a>
        </div>
    </section>

    <section class="card" data-reveal>
        <div class="card-header">
            <div>
                <h2 class="card-title">Recently Registered Students</h2>
                <p class="card-subtitle">Latest 5 registrations with preferences and phone numbers.</p>
            </div>
        </div>
        <?php if ($recent): ?>
            <div class="table-wrap"><table>
                <thead><tr><th>Name</th><th>Phone</th><th>Preference</th><th>Registered</th></tr></thead>
                <tbody>
                <?php foreach ($recent as $s): $d = new DateTime($s['created_at']); ?>
                    <tr>
                        <td style="font-weight:700;"><?= e($s['name']) ?></td>
                        <td><?= e($s['student_phone'] ?? '—') ?></td>
                        <td><?= e($s['room_preference'] ?? '—') ?></td>
                        <td><?= e($d->format('d M Y')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php else: ?>
            <p style="color:var(--muted);">No students registered yet.</p>
        <?php endif; ?>
    </section>
</div>
<script src="../js/script.js"></script>
</body>
</html>
