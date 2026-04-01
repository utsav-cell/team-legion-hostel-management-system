<?php
// ─────────────────────────────────────────────────
// student/my_attendance.php — View My Attendance
// ─────────────────────────────────────────────────

require_once '../dp.php';
require_role('student');

$uid  = (int)$_SESSION['user_id'];
$name = $_SESSION['user_name'];

// Get attendance records — prepared statement
$stmt = mysqli_prepare($conn,
    "SELECT date, status FROM attendance
     WHERE student_id = ?
     ORDER BY date DESC");
mysqli_stmt_bind_param($stmt, 'i', $uid);
mysqli_stmt_execute($stmt);
$att_res = mysqli_stmt_get_result($stmt);
mysqli_stmt_close($stmt);

$records = [];
$total = $present = 0;
while ($r = mysqli_fetch_assoc($att_res)) {
    $records[] = $r;
    $total++;
    if ($r['status'] === 'present') $present++;
}
$absent = $total - $present;
$pct    = $total > 0 ? round($present / $total * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Attendance — HMS</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
<?php echo render_sidebar('my_attendance.php'); ?>
<div class="container">
    <div class="page-header">
        <h1>Attendance History</h1>
        <p>Tracking for <strong><?= e($name) ?></strong> &mdash;
           <span style="color:<?= $pct >= 75 ? '#16a34a' : '#be123c' ?>;
                        font-weight:700;">
               <?= $pct >= 75 ? 'Good Standing' : 'Below 75% — Action Required' ?>
           </span>
        </p>
    </div>

    <!-- Summary Cards -->
    <div class="stats-grid">
        <div class="stat-box">
            <div class="stat-label">Total Days</div>
            <div class="stat-value"><?= $total ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Present</div>
            <div class="stat-value" style="color:#16a34a;"><?= $present ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Absent</div>
            <div class="stat-value" style="color:#be123c;"><?= $absent ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Overall Rate</div>
            <div class="stat-value"
                 style="color:<?= $pct >= 75 ? '#4338ca' : '#be123c' ?>;">
                <?= $pct ?>%
            </div>
        </div>
    </div>

    <!-- Progress Bar -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Attendance Rate</h2>
        </div>
        <div style="background:#eef2f7; border-radius:999px; height:12px;
                     overflow:hidden; margin:1rem 0;">
            <div style="height:100%; background:<?= $pct >= 75
                ? 'var(--brand)' : '#ef4444' ?>;
                         width:<?= $pct ?>%; border-radius:999px;"></div>
        </div>
        <p style="color:var(--text-muted); font-size:0.875rem;">
            <?= $pct ?>% — <?= $present ?> present out of <?= $total ?> days
        </p>
    </div>

    <!-- Attendance Records Table -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Daily Attendance Logs</h2>
        </div>
        <?php if ($records): ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date</th>
                        <th>Day</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($records as $i => $r):
                    $d = new DateTime($r['date']); ?>
                    <tr>
                        <td style="color:var(--text-muted);">
                            <?= $i + 1 ?>
                        </td>
                        <td style="font-weight:600;">
                            <?= e($d->format('d M Y')) ?>
                        </td>
                        <td style="color:var(--text-muted);">
                            <?= e($d->format('l')) ?>
                        </td>
                        <td>
                            <span class="badge badge-<?= $r['status'] === 'present'
                                ? 'green' : 'red' ?>">
                                <?= e(ucfirst($r['status'])) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color:var(--text-muted); text-align:center; padding:2rem;">
                No attendance records found yet.
            </p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
