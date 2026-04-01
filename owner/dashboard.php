<?php
require_once '../dp.php';
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
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .badge { padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-approved { background: #d1fae5; color: #065f46; }
        .dash-section { margin-top: 3rem; }
    </style>
</head>
<body>
<?php echo render_sidebar('dashboard.php'); ?>
<div class="container">
    <div class="page-header">
        <h1>Administrative Oversight</h1>
        <p>System status and critical approvals</p>
    </div>

    <?php if (isset($success_msg)): ?><div class="alert alert-success"><?= e($success_msg) ?></div><?php endif; ?>

    <!-- STATS -->
    <div class="stats-grid">
        <div class="stat-box"><div class="stat-label">Verified Residents</div><div class="stat-value"><?= $total_students ?></div></div>
        <div class="stat-box"><div class="stat-label">Occupancy Rate</div><div class="stat-value"><?= $occupied_rooms ?>/<?= $total_rooms ?></div></div>
        <div class="stat-box"><div class="stat-label">Pending Rooms</div><div class="stat-value" style="color:<?= count($pending) > 0 ? '#e11d48' : '#6366f1' ?>;"><?= count($pending) ?></div></div>
        <div class="stat-box"><div class="stat-label">System Health</div><div class="stat-value" style="color:#22c55e;">Online</div></div>
    </div>

    <!-- PENDING APPROVALS -->
    <?php if ($pending): ?>
    <div class="dash-section">
        <div class="card-header"><h2 class="card-title">Pending Room Approvals</h2></div>
        <div class="card">
            <table>
                <thead><tr><th>Resident</th><th>Room</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($pending as $p): ?>
                    <tr>
                        <td>
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <img src="../uploads/students/<?= e($p['photo'] ?: 'default.png') ?>" class="avatar" onerror="this.src='../uploads/students/default.png'">
                                <div><div style="font-weight:700;"><?= e($p['name']) ?></div><div style="font-size:0.75rem; color:var(--text-muted);"><?= e($p['email']) ?></div></div>
                            </div>
                        </td>
                        <td style="font-weight:700; color:var(--brand);">Room <?= e($p['room_number']) ?></td>
                        <td>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="student_id" value="<?= $p['sid'] ?>">
                                <input type="hidden" name="room_id" value="<?= $p['rid'] ?>">
                                <button type="submit" name="approve_room" class="btn btn-primary" style="padding: 0.4rem 1rem; font-size: 0.8125rem;">Approve Allocation</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="grid-2 dash-section">
        <!-- STAFF DIRECTORY -->
        <div class="card">
            <div class="card-header"><h2 class="card-title">Authorized Wardens</h2></div>
            <table>
                <thead><tr><th>Name</th><th>Email</th></tr></thead>
                <tbody>
                <?php foreach ($wardens as $w): ?>
                    <tr><td style="font-weight:700;"><?= e($w['name']) ?></td><td style="font-size:0.875rem; color:var(--text-muted);"><?= e($w['email']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- RESIDENT RECORDS -->
        <div class="card">
            <div class="card-header"><h2 class="card-title">Recent Registrations</h2></div>
            <table>
                <thead><tr><th>Resident</th><th>Status</th></tr></thead>
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
                                <span class="badge" style="background:#f1f5f9; color:#64748b;">No Room</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
<script src="../js/script.js"></script>
</body>
</html>
