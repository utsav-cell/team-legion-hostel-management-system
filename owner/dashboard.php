<?php
require_once '../db.php';
require_role('owner');

// Handle Room Approval
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_room'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $sid = (int)$_POST['student_id'];
    $rid = (int)$_POST['room_id'];
    
    // Set status to approved in users
    mysqli_query($conn, "UPDATE users SET room_status = 'approved' WHERE id = $sid");
    // Set room to occupied in rooms
    mysqli_query($conn, "UPDATE rooms SET status = 'occupied', is_approved = 1 WHERE id = $rid");
    
    $success_msg = "Room allocation approved successfully!";
}

$name  = $_SESSION['user_name'];
$today = date('Y-m-d');

// 1. Stats
$total_students = (int)mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM users WHERE role='student'"))[0];
$rooms_stats = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total, SUM(status='occupied') AS occupied FROM rooms"));
$total_rooms = $rooms_stats['total'] ?? 0;
$occupied_rooms = $rooms_stats['occupied'] ?? 0;

// 1.5 Enquiries Stat
$unread_enq = (int)mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM enquiries WHERE status='unread'"))[0];

// 2. Pending Approvals
$pending_res = mysqli_query($conn, "SELECT u.id AS sid, u.name, u.email, u.photo, r.id AS rid, r.room_number FROM users u JOIN rooms r ON r.id = u.room_id WHERE u.room_status = 'pending' AND u.role = 'student'");
$pending = []; while ($p = mysqli_fetch_assoc($pending_res)) $pending[] = $p;

// 3. Student List (Top 50)
$students_res = mysqli_query($conn, "SELECT u.name, u.email, u.photo, r.room_number, u.room_status FROM users u LEFT JOIN rooms r ON r.id = u.room_id WHERE u.role = 'student' ORDER BY u.created_at DESC LIMIT 50");
$students = []; while ($s = mysqli_fetch_assoc($students_res)) $students[] = $s;

// 4. Warden List
$wardens_res = mysqli_query($conn, "SELECT name, email FROM users WHERE role = 'warden' ORDER BY name ASC");
$wardens = []; while ($w = mysqli_fetch_assoc($wardens_res)) $wardens[] = $w;

$role = 'owner'; $active = 'dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Central — HMS</title>
    <link rel="stylesheet" href="../css/style.css?v=4">
    <style>
        .badge { padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; }
        .badge-pending { background: var(--warning-soft); color: #b45309; }
        .badge-approved { background: var(--success-soft); color: #166534; }
        .dash-section { margin-top: 3rem; }
    </style>
</head>
<body>
<?php echo render_sidebar('dashboard.php'); ?>
<div class="container">
    <section class="page-header" data-reveal>
        <h1>Dashboard</h1>
        <p>Welcome back, <?= e($name) ?>. Here is the latest hostel overview.</p>
    </section>

    <?php if (isset($success_msg)): ?><div class="alert alert-success"><?= e($success_msg) ?></div><?php endif; ?>

    <!-- STATS -->
    <div class="stats-grid">
        <a class="stat-box stat-link" data-reveal href="report_attendance.php">
            <div class="stat-label">Room Status</div>
            <div class="stat-value"><?= $occupied_rooms ?>/<?= $total_rooms ?></div>
            <div class="stat-meta">Occupied rooms</div>
        </a>
        <a class="stat-box stat-link" data-reveal href="enquiries.php">
            <div class="stat-label">Public Enquiries</div>
            <div class="stat-value" data-count="<?= $unread_enq ?>"><?= $unread_enq ?></div>
            <div class="stat-meta">Unread messages</div>
        </a>
        <a class="stat-box stat-link" data-reveal href="#pending-approvals">
            <div class="stat-label">Pending Approvals</div>
            <div class="stat-value" data-count="<?= count($pending) ?>"><?= count($pending) ?></div>
            <div class="stat-meta">Awaiting decision</div>
        </a>
    </div>

    <section class="card" data-reveal>
        <div class="card-header">
            <div>
                <h2 class="card-title">Recent Activity</h2>
                <p class="card-subtitle">Latest system updates and alerts.</p>
            </div>
            <span class="badge badge-pending"><?= count($pending) ?> Pending</span>
        </div>
        <div class="activity-list">
            <a class="activity-item activity-link" href="manage_leaves.php">
                <div class="activity-icon activity-icon-blue"></div>
                <div>
                    <strong>Room approvals awaiting review</strong>
                    <p><?= count($pending) ?> student allocations need approval.</p>
                    <span class="activity-time">Today</span>
                </div>
            </a>
            <a class="activity-item activity-link" href="enquiries.php">
                <div class="activity-icon activity-icon-orange"></div>
                <div>
                    <strong>Unread public enquiries</strong>
                    <p><?= $unread_enq ?> messages are still unread.</p>
                    <span class="activity-time">Last 24 hours</span>
                </div>
            </a>
            <a class="activity-item activity-link" href="report_attendance.php">
                <div class="activity-icon activity-icon-green"></div>
                <div>
                    <strong>Occupancy status updated</strong>
                    <p><?= $occupied_rooms ?> rooms currently occupied.</p>
                    <span class="activity-time">This week</span>
                </div>
            </a>
        </div>
    </section>

    <!-- PENDING APPROVALS -->
    <?php if ($pending): ?>
    <div class="card" data-reveal id="pending-approvals">
        <div class="card-header">
            <div>
                <h2 class="card-title">Pending Room Approvals</h2>
                <p style="font-size: 0.875rem; color: var(--text-muted); margin-top: 0.25rem;">Students waiting for room allocation sign-off.</p>
            </div>
            <span class="badge badge-pending"><?= count($pending) ?> Pending</span>
        </div>
        <div style="overflow-x: auto;">
            <table>
                <thead><tr><th>Resident</th><th>Requested Room</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($pending as $p): ?>
                    <tr>
                        <td>
                            <div style="display: flex; align-items: center; gap: 1rem;">
                                <img src="../uploads/students/<?= e($p['photo'] ?: 'default.png') ?>" style="width:40px; height:40px; border-radius:50%; object-fit:cover;" onerror="this.src='../uploads/students/default.png'">
                                <div><div style="font-weight:700; color: var(--text);"><?= e($p['name']) ?></div><div style="font-size:0.75rem; color:var(--text-muted);"><?= e($p['email']) ?></div></div>
                            </div>
                        </td>
                        <td>
                            <span style="font-weight:800; color:var(--brand); background: var(--brand-bg); padding: 0.4rem 0.8rem; border-radius: 8px;">
                                Room <?= e($p['room_number']) ?>
                            </span>
                        </td>
                        <td>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="student_id" value="<?= $p['sid'] ?>">
                                <input type="hidden" name="room_id" value="<?= $p['rid'] ?>">
                                <button type="submit" name="approve_room" class="btn btn-primary" style="padding: 0.5rem 1.25rem; font-size: 0.75rem; border-radius: 10px;">Authorize Allocation</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="grid-2" data-reveal>
        <!-- STAFF DIRECTORY -->
        <div class="card" style="padding: 2.5rem;">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Authorized Wardens</h2>
                    <p style="font-size: 0.875rem; color: var(--text-muted); margin-top: 0.25rem;">Active staff accounts</p>
                </div>
            </div>
            <table style="margin-top: 0;">
                <thead><tr><th>Name</th><th>Email</th></tr></thead>
                <tbody>
                <?php foreach ($wardens as $w): ?>
                    <tr>
                        <td style="font-weight:700;"><?= e($w['name']) ?></td>
                        <td style="font-size:0.8125rem; color:var(--text-muted);"><?= e($w['email']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- RESIDENT RECORDS -->
        <div class="card" style="padding: 2.5rem;">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Recent Registrations</h2>
                    <p style="font-size: 0.875rem; color: var(--text-muted); margin-top: 0.25rem;">Top 50 newest students</p>
                </div>
            </div>
            <div style="max-height: 400px; overflow-y: auto;">
                <table style="margin-top: 0;">
                    <thead style="position: sticky; top: 0; background: var(--brand-bg); z-index: 10;">
                        <tr><th>Resident</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($students as $s): ?>
                        <tr>
                            <td>
                                <div style="font-weight:700;"><?= e($s['name']) ?></div>
                                <div style="font-size:0.75rem; color:var(--text-muted);"><?= e($s['email']) ?></div>
                            </td>
                            <td>
                                <?php if ($s['room_number']): ?>
                                    <span class="badge badge-<?= $s['room_status'] ?>"><?= ucfirst($s['room_status']) ?></span>
                                <?php else: ?>
                                    <span class="badge" style="background:#f8fafc; color:#94a3b8; border: 1px solid #e2e8f0;">No Room</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
<script src="../js/script.js"></script>
</body>
</html>
