<?php
// warden/dashboard.php — Warden portal dashboard
require_once '../db.php';
require_role('warden');

$name  = $_SESSION['user_name'];
$photo = $_SESSION['user_photo'] ?? 'default.png';
$today = date('Y-m-d');

// Avatar initials
$parts    = array_values(array_filter(explode(' ', trim($name))));
$initials = strtoupper(substr($parts[0] ?? 'W', 0, 1)) . strtoupper(substr($parts[1] ?? '', 0, 1));
$photo_url = ($photo !== 'default.png') ? '../uploads/students/' . rawurlencode($photo) : null;

// Total students
$total_students = 0;
try {
    $total_students = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
} catch (Exception $e) {}

// Room occupancy
$occupied_rooms = $total_rooms = 0;
try {
    $rs = $pdo->query("SELECT COUNT(*) AS total, SUM(status='occupied') AS occupied FROM rooms")->fetch();
    $total_rooms    = (int)($rs['total']    ?? 0);
    $occupied_rooms = (int)($rs['occupied'] ?? 0);
} catch (Exception $e) {}

// Today's attendance
$present_today = $absent_today = 0;
try {
    $att = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM attendance WHERE date = ? GROUP BY status");
    $att->execute([$today]);
    foreach ($att->fetchAll() as $row) {
        if ($row['status'] === 'present') $present_today = (int)$row['cnt'];
        if ($row['status'] === 'absent')  $absent_today  = (int)$row['cnt'];
    }
} catch (Exception $e) {}
$marked_today   = $present_today + $absent_today;
$attendance_pct = $total_students > 0 ? round($present_today / $total_students * 100, 1) : 0;

// Open complaints
$open_complaints = 0;
try {
    $open_complaints = (int)$pdo->query("SELECT COUNT(*) FROM complaints WHERE status = 'open'")->fetchColumn();
} catch (Exception $e) {}

// Pending bookings
$pending_bookings = 0;
try {
    $pending_bookings = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status = 'pending_approval'")->fetchColumn();
} catch (Exception $e) {}

// Pending leaves
$pending_leaves = 0;
try {
    $pending_leaves = (int)$pdo->query("SELECT COUNT(*) FROM leaves WHERE warden_status = 'pending'")->fetchColumn();
} catch (Exception $e) {}

// Pending room transfers
$pending_transfers = 0;
try {
    $pending_transfers = (int)$pdo->query("SELECT COUNT(*) FROM room_transfers WHERE status = 'pending'")->fetchColumn();
} catch (Exception $e) {}

// Recent students
$recent_students = [];
try {
    $rs2 = $pdo->query(
        "SELECT name, student_phone, room_preference, created_at
         FROM users WHERE role = 'student'
         ORDER BY created_at DESC LIMIT 5"
    );
    $recent_students = $rs2->fetchAll();
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warden Dashboard &mdash; HMS</title>
    <link rel="stylesheet" href="../css/style.css?v=18">
</head>
<body>
<?= render_sidebar('dashboard.php') ?>
<?= render_topbar('Dashboard') ?>

<div class="app-shell">
    <main class="shell-main">

        <!-- Page header -->
        <div class="page-header">
            <div>
                <h1>Dashboard</h1>
            </div>
            <div class="ph-date">
                <span class="ph-day"><?= date('l') ?></span>
                <span class="ph-full"><?= date('d M Y') ?></span>
            </div>
        </div>

        <!-- Stats grid -->
        <div class="stats-grid" style="margin-bottom:1.75rem;">

            <a class="stat-box stat-link stat-indigo" href="room_requests.php">
                <div class="stat-icon-bubble"><?= get_svg_icon('bed') ?></div>
                <div class="stat-label">Occupancy</div>
                <div class="stat-value"><?= $occupied_rooms ?>/<?= $total_rooms ?></div>
                <div class="stat-meta">Occupied rooms</div>
            </a>

            <a class="stat-box stat-link stat-green" href="student_attendance.php">
                <div class="stat-icon-bubble"><?= get_svg_icon('checkmark') ?></div>
                <div class="stat-label">Today's Attendance</div>
                <div class="stat-value" data-count="<?= $attendance_pct ?>" data-suffix="%"><?= $attendance_pct ?>%</div>
                <div class="stat-meta"><?= $present_today ?> of <?= $total_students ?> marked</div>
            </a>

            <a class="stat-box stat-link stat-amber" href="booking_requests.php">
                <div class="stat-icon-bubble"><?= get_svg_icon('clipboard') ?></div>
                <div class="stat-label">Pending Bookings</div>
                <div class="stat-value" data-count="<?= $pending_bookings ?>"><?= $pending_bookings ?></div>
                <div class="stat-meta">Need approval</div>
            </a>

            <a class="stat-box stat-link stat-red" href="complaints.php">
                <div class="stat-icon-bubble"><?= get_svg_icon('alert') ?></div>
                <div class="stat-label">Open Complaints</div>
                <div class="stat-value" data-count="<?= $open_complaints ?>"><?= $open_complaints ?></div>
                <div class="stat-meta">Awaiting review</div>
            </a>

        </div>

        <!-- Recent students table -->
        <div class="card">
            <div class="card-header">
                <div>
                    <div class="card-title">Recently Registered Students</div>
                    <div class="card-subtitle">Latest 5 new student registrations.</div>
                </div>
                <a href="list_student.php" class="btn btn-secondary" style="flex-shrink:0;">View All</a>
            </div>
            <?php if (empty($recent_students)): ?>
                <p style="color:var(--muted);font-size:0.875rem;">No students registered yet.</p>
            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Room Preference</th>
                            <th>Registered</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_students as $s): ?>
                        <tr>
                            <td style="font-weight:600;"><?= e($s['name']) ?></td>
                            <td><?= e($s['student_phone'] ?? '') ?></td>
                            <td><?= e($s['room_preference'] ?? '') ?></td>
                            <td style="color:var(--muted);font-size:0.82rem;"><?= date('d M Y', strtotime($s['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- Right panel -->
    <aside class="right-panel">

        <!-- Profile card -->
        <div class="rp-card">
            <div class="rp-card-title">Your Profile</div>
            <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.85rem;">
                <div class="rp-avatar">
                    <?php if ($photo_url): ?>
                        <img src="<?= $photo_url ?>" alt="<?= e($name) ?>" onerror="this.parentNode.textContent='<?= e($initials) ?>'">
                    <?php else: ?><?= e($initials) ?><?php endif; ?>
                </div>
                <div>
                    <div style="font-weight:700;font-size:0.9rem;"><?= e($name) ?></div>
                    <div style="font-size:0.78rem;color:var(--muted);">Warden</div>
                </div>
            </div>
            <hr class="rp-divider">
            <div style="display:flex;gap:1.25rem;margin-bottom:0.75rem;">
                <div>
                    <div style="font-size:1.25rem;font-weight:700;color:var(--text);line-height:1;"><?= $total_students ?></div>
                    <div style="font-size:0.72rem;color:var(--muted);">Total students</div>
                </div>
                <div>
                    <div style="font-size:1.25rem;font-weight:700;color:var(--text);line-height:1;"><?= $present_today ?></div>
                    <div style="font-size:0.72rem;color:var(--muted);">Present today</div>
                </div>
            </div>
            <hr class="rp-divider">
            <a href="../auth/logout.php" class="rp-link-row" style="color:var(--danger);">Logout</a>
        </div>

        <!-- Calendar -->
        <div class="rp-card">
            <div class="rp-card-title">Calendar</div>
            <div id="nepali-cal"></div>
        </div>

        <!-- Action Items -->
        <div class="rp-card">
            <div class="rp-card-title">Action Items</div>
            <a href="booking_requests.php" class="rp-link-row">
                Booking Requests
                <span class="rp-link-count<?= $pending_bookings > 0 ? ' amber' : '' ?>"><?= $pending_bookings ?></span>
            </a>
            <a href="room_transfers.php" class="rp-link-row">
                Transfer Requests
                <span class="rp-link-count<?= $pending_transfers > 0 ? ' amber' : '' ?>"><?= $pending_transfers ?></span>
            </a>
            <a href="manage_leaves.php" class="rp-link-row">
                Pending Leaves
                <span class="rp-link-count<?= $pending_leaves > 0 ? ' amber' : '' ?>"><?= $pending_leaves ?></span>
            </a>
            <a href="complaints.php" class="rp-link-row">
                Open Complaints
                <span class="rp-link-count<?= $open_complaints > 0 ? ' red' : '' ?>"><?= $open_complaints ?></span>
            </a>
            <a href="list_student.php" class="rp-link-row">
                Student List
                <span class="rp-link-count"><?= $total_students ?></span>
            </a>
            <a href="../owner/report_attendance.php" class="rp-link-row">
                Attendance Report
                <span style="color:var(--muted);font-size:0.78rem;">&rsaquo;</span>
            </a>
        </div>

    </aside>
</div>

<?= render_footer() ?>
<script src="../js/script.js"></script>
<script src="../js/nepali-cal.js"></script>
</body>
</html>
