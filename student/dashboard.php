<?php
// student/dashboard.php — Student portal dashboard
require_once '../db.php';
require_role('student');

$uid   = (int)$_SESSION['user_id'];
$name  = $_SESSION['user_name'];
$photo = $_SESSION['user_photo'] ?? 'default.png'; 

// Avatar initials
$parts    = array_values(array_filter(explode(' ', trim($name))));
$initials = strtoupper(substr($parts[0] ?? 'S', 0, 1)) . strtoupper(substr($parts[1] ?? '', 0, 1));
$photo_url = ($photo !== 'default.png') ? '../uploads/students/' . rawurlencode($photo) : null;

    // Room info — prefer the user's active/approved booking (canonical source per db.php:250),
    // fall back to legacy users.room_id only when no booking exists.
    $room = null;
    try {
        $stmt = $pdo->prepare(
            "SELECT
                COALESCE(rb.room_number, rl.room_number) AS room_number,
                COALESCE(rb.room_type,   rl.room_type)   AS room_type,
                COALESCE(rb.floor,       rl.floor)       AS floor,
                u.room_status, u.fee_status
            FROM users u
            LEFT JOIN rooms rl ON rl.id = u.room_id
            LEFT JOIN rooms rb ON rb.id = (
                SELECT b.room_id FROM bookings b
                WHERE b.student_id = u.id AND b.status IN ('approved','active')
                ORDER BY b.created_at DESC LIMIT 1
            )
            WHERE u.id = ? LIMIT 1"
        );
        $stmt->execute([$uid]);
        $room = $stmt->fetch();
    } catch (Exception $e) {}

// Attendance
$total = $present = 0;
try {
    $att = $pdo->prepare("SELECT status FROM attendance WHERE student_id = ?");
    $att->execute([$uid]);
    foreach ($att->fetchAll() as $row) {
        $total++;
        if ($row['status'] === 'present') $present++;
    }
} catch (Exception $e) {}
$absent = $total - $present;
$pct    = $total > 0 ? round($present / $total * 100, 1) : 0;

// Open complaints
$open_complaints = 0;
try {
    $cst = $pdo->prepare("SELECT COUNT(*) AS c FROM complaints WHERE student_id = ? AND status = 'open'");
    $cst->execute([$uid]);
    $open_complaints = (int)($cst->fetch()['c'] ?? 0);
} catch (Exception $e) {}

// Pending leaves
$pending_leaves = 0;
try {
    $lst = $pdo->prepare("SELECT COUNT(*) FROM leaves WHERE student_id = ? AND warden_status = 'pending'");
    $lst->execute([$uid]);
    $pending_leaves = (int)$lst->fetchColumn();
} catch (Exception $e) {}

// Next meal
$next_meal = null;
try {
    $meal = $pdo->query("SELECT time_slot, activity FROM daily_routine WHERE is_school_hours = 0 ORDER BY id ASC LIMIT 1");
    $next_meal = $meal ? $meal->fetch() : null;
} catch (Exception $e) {}

// Room display helpers
$room_number  = $room['room_number'] ?? null;
$room_label   = $room_number ? 'Room ' . $room_number : 'Pending';
$room_sub     = $room_number
    ? e($room['room_type'] ?? '') . ' &middot; Floor ' . (int)($room['floor'] ?? 0)
    : 'Awaiting allocation';
$fee_status   = ucfirst($room['fee_status'] ?? 'N/A');
$room_status  = ucfirst($room['room_status'] ?? 'N/A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard &mdash; HMS</title>
    <link rel="stylesheet" href="../css/style.css?v=34">
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

            <a class="stat-box stat-link stat-indigo" href="room.php">
                <div class="stat-icon-bubble"><?= get_svg_icon('bed') ?></div>
                <div class="stat-label">Room</div>
                <div class="stat-value"><?= $room_number ? e($room_number) : 'Pending' ?></div>
                <div class="stat-meta"><?= $room_sub ?></div>
            </a>

            <a class="stat-box stat-link stat-green" href="my_attendance.php">
                <div class="stat-icon-bubble"><?= get_svg_icon('checkmark') ?></div>
                <div class="stat-label">Attendance</div>
                <div class="stat-value" data-count="<?= $pct ?>" data-suffix="%"><?= $pct ?>%</div>
                <div class="stat-meta"><?= $present ?> present &middot; <?= $absent ?> absent</div>
            </a>

            <a class="stat-box stat-link stat-amber" href="my_complaints.php">
                <div class="stat-icon-bubble"><?= get_svg_icon('alert') ?></div>
                <div class="stat-label">Open Complaints</div>
                <div class="stat-value" data-count="<?= $open_complaints ?>"><?= $open_complaints ?></div>
                <div class="stat-meta"><?= $open_complaints === 1 ? '1 issue' : $open_complaints . ' issues' ?></div>
            </a>

            <a class="stat-box stat-link stat-red" href="request_leave.php">
                <div class="stat-icon-bubble"><?= get_svg_icon('key') ?></div>
                <div class="stat-label">Leave Requests</div>
                <div class="stat-value" data-count="<?= $pending_leaves ?>"><?= $pending_leaves ?></div>
                <div class="stat-meta">Pending approval</div>
            </a>

        </div>

        <!-- Recent Activity card -->
        <div class="card">
            <div class="card-header">
                <div>
                    <div class="card-title">Recent Activity</div>
                    <div class="card-subtitle">Your latest hostel updates and status.</div>
                </div>
                <span class="badge <?= $pct >= 75 ? 'badge-green' : 'badge-pending' ?>">
                    <?= $pct >= 75 ? 'Good Standing' : 'Action Required' ?>
                </span>
            </div>
            <div class="activity-list">
                <a class="activity-item activity-link" href="my_attendance.php">
                    <div class="activity-icon activity-icon-blue"></div>
                    <div>
                        <strong>Attendance rate</strong>
                        <p>Current rate <?= $pct ?>% &mdash; <?= $pct >= 75 ? 'within required threshold.' : 'below 75%, improvement needed.' ?></p>
                        <span class="activity-time"><?= $present ?> present of <?= $total ?> total</span>
                    </div>
                </a>
                <a class="activity-item activity-link" href="my_complaints.php">
                    <div class="activity-icon activity-icon-orange"></div>
                    <div>
                        <strong>Complaints status</strong>
                        <p><?= $open_complaints > 0 ? $open_complaints . ' open complaint(s) awaiting resolution.' : 'No open complaints at this time.' ?></p>
                        <span class="activity-time">Updated today</span>
                    </div>
                </a>
                <a class="activity-item activity-link" href="request_leave.php">
                    <div class="activity-icon activity-icon-green"></div>
                    <div>
                        <strong>Leave status</strong>
                        <p><?= $pending_leaves > 0 ? $pending_leaves . ' leave request(s) pending warden review.' : 'No pending leave requests.' ?></p>
                        <span class="activity-time">Current cycle</span>
                    </div>
                </a>
            </div>
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
                    <div style="font-size:0.78rem;color:var(--muted);">Student</div>
                </div>
            </div>
            <hr class="rp-divider">
            <div style="font-size:0.78rem;color:var(--muted);margin-bottom:0.5rem;">
                <span>Room: </span><strong style="color:var(--text);"><?= $room_number ? e($room_number) : 'Not assigned' ?></strong>
            </div>
            <div style="font-size:0.78rem;color:var(--muted);margin-bottom:0.75rem;">
                <span>Fee Status: </span><strong style="color:var(--text);"><?= e($fee_status) ?></strong>
            </div>
        </div>

        <!-- Quick Links -->
        <div class="rp-card">
            <div class="rp-card-title">Quick Links</div>
            <a href="my_attendance.php" class="rp-link-row">
                Attendance
                <span class="rp-link-count<?= $pct < 75 ? ' amber' : '' ?>"><?= $pct ?>%</span>
            </a>
            <a href="browse_rooms.php" class="rp-link-row">
                Browse Rooms
                <span style="color:var(--muted);font-size:0.78rem;">&rsaquo;</span>
            </a>
            <a href="my_bookings.php" class="rp-link-row">
                My Bookings
                <span style="color:var(--muted);font-size:0.78rem;">&rsaquo;</span>
            </a>
            <a href="my_complaints.php" class="rp-link-row">
                Complaints
                <span class="rp-link-count<?= $open_complaints > 0 ? ' amber' : '' ?>"><?= $open_complaints ?></span>
            </a>
            <a href="food_routine.php" class="rp-link-row">
                Meal Schedule
                <span style="color:var(--muted);font-size:0.78rem;">&rsaquo;</span>
            </a>
        </div>

        <?php if ($next_meal): ?>
        <!-- Upcoming Activity -->
        <div class="rp-card">
            <div class="rp-card-title">Upcoming Activity</div>
            <div style="font-size:0.82rem;font-weight:600;color:var(--text);margin-bottom:0.25rem;"><?= e($next_meal['activity']) ?></div>
            <div style="font-size:0.78rem;color:var(--muted);"><?= e($next_meal['time_slot']) ?></div>
        </div>
        <?php endif; ?>

    </aside>
</div>

<?= render_footer() ?>
<script src="../js/script.js"></script>
</body>
</html>
