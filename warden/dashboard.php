<?php
// ─────────────────────────────────────────────────
// warden/dashboard.php — Warden Home Page
// ─────────────────────────────────────────────────

require_once '../dp.php';
require_role('warden');

$name  = $_SESSION['user_name'];
$today = date('Y-m-d');

// Total students — no user input so direct query is safe
$total_students = (int) mysqli_fetch_row(
    mysqli_query($conn,
        "SELECT COUNT(*) FROM users WHERE role='student'")
)[0];

// Attendance stats using prepared statements
$stmt = mysqli_prepare($conn,
    "SELECT status, COUNT(*) as cnt
     FROM attendance WHERE date = ?
     GROUP BY status");
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

// Rooms occupied
$occupied_rooms = (int) mysqli_fetch_row(
    mysqli_query($conn,
        "SELECT COUNT(*) FROM rooms WHERE status='occupied'")
)[0];

// Recent students — no user input
$recent = [];
$res = mysqli_query($conn,
    "SELECT name, student_phone, room_preference, created_at
     FROM users WHERE role='student'
     ORDER BY created_at DESC LIMIT 5");
while ($r = mysqli_fetch_assoc($res)) $recent[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warden Dashboard — HMS</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
<?php echo render_sidebar('dashboard.php'); ?>
<div class="container">
    <div class="page-header">
        <h1>Dashboard</h1>
        <p>Welcome back, <strong><?= e($name) ?></strong></p>
    </div>

    <!-- Summary cards -->
    <div class="stats-grid">
        <div class="stat-box">
            <div class="stat-label">Total Students</div>
            <div class="stat-value"><?= $total_students ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Present Today</div>
            <div class="stat-value" style="color:#10b981;">
                <?= $present_today ?>
            </div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Absent Today</div>
            <div class="stat-value" style="color:#ef4444;">
                <?= $absent_today ?>
            </div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Rooms Occupied</div>
            <div class="stat-value"><?= $occupied_rooms ?></div>
        </div>
    </div>

    <!-- Daily Attendance Card -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Daily Attendance</h2>
        </div>
        <?php if ($marked_today === 0): ?>
            <p style="margin-bottom:1.5rem; color:var(--text-muted);">
                Attendance has not been recorded yet for today
                (<?= date('d M') ?>).
            </p>
            <a href="student_attendance.php" class="btn btn-primary">
                Mark Attendance Now
            </a>
        <?php else: ?>
            <div style="display:flex; gap:2rem; margin-bottom:1.5rem;">
                <div>
                    <p style="font-size:0.75rem; font-weight:700;
                               color:var(--text-muted);">PRESENT</p>
                    <p style="font-size:1.5rem; font-weight:800;
                               color:#10b981;"><?= $present_today ?></p>
                </div>
                <div>
                    <p style="font-size:0.75rem; font-weight:700;
                               color:var(--text-muted);">ABSENT</p>
                    <p style="font-size:1.5rem; font-weight:800;
                               color:#ef4444;"><?= $absent_today ?></p>
                </div>
                <div>
                    <p style="font-size:0.75rem; font-weight:700;
                               color:var(--text-muted);">MARKED</p>
                    <p style="font-size:1.5rem; font-weight:800;">
                        <?= $marked_today ?>
                    </p>
                </div>
            </div>
            <a href="student_attendance.php" class="btn btn-secondary">
                Review Attendance
            </a>
        <?php endif; ?>
    </div>

    <!-- Recent Students -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Recently Registered Students</h2>
        </div>
        <?php if ($recent): ?>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Preference</th>
                        <th>Registered</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recent as $s):
                    $d = new DateTime($s['created_at']); ?>
                    <tr>
                        <td style="font-weight:700;">
                            <?= e($s['name']) ?>
                        </td>
                        <td style="color:var(--text-muted);">
                            <?= e($s['student_phone'] ?? '—') ?>
                        </td>
                        <td><?= e($s['room_preference'] ?? '—') ?></td>
                        <td style="color:var(--text-muted);">
                            <?= e($d->format('d M Y')) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color:var(--text-muted);">No students registered yet.</p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
