<?php
// owner/dashboard.php — Owner control panel dashboard
require_once '../db.php';
require_role('owner');

// POST: approve room allocation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_room'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $sid = (int)$_POST['student_id'];
    $rid = (int)$_POST['room_id'];
    $pdo->prepare("UPDATE users SET room_status = 'approved' WHERE id = ?")->execute([$sid]);
    $pdo->prepare("UPDATE rooms SET status = 'occupied', is_approved = 1 WHERE id = ?")->execute([$rid]);
    $success_msg = 'Room allocation approved successfully.';
}

$name  = $_SESSION['user_name'];
$photo = $_SESSION['user_photo'] ?? 'default.png';

// Avatar initials
$parts    = array_values(array_filter(explode(' ', trim($name))));
$initials = strtoupper(substr($parts[0] ?? 'O', 0, 1)) . strtoupper(substr($parts[1] ?? '', 0, 1));
$photo_url = ($photo !== 'default.png') ? '../uploads/students/' . rawurlencode($photo) : null;

// Total students
$total_students = 0;
try {
    $total_students = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
} catch (Exception $e) {}

// Rooms stats
$total_rooms = $occupied_rooms = 0;
try {
    $rs = $pdo->query("SELECT COUNT(*) AS total, SUM(status='occupied') AS occupied FROM rooms")->fetch();
    $total_rooms    = (int)($rs['total']    ?? 0);
    $occupied_rooms = (int)($rs['occupied'] ?? 0);
} catch (Exception $e) {}

// Unread enquiries
$unread_enq = 0;
try {
    $unread_enq = (int)$pdo->query("SELECT COUNT(*) FROM enquiries WHERE status = 'unread'")->fetchColumn();
} catch (Exception $e) {}

// Pending bookings
$pending_bookings = 0;
try {
    $pending_bookings = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status = 'pending_approval'")->fetchColumn();
} catch (Exception $e) {}

// Pending payments
$pending_payments = 0;
try {
    $pending_payments = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'pending'")->fetchColumn();
} catch (Exception $e) {}

// Pending room approvals
$pending_approvals = [];
try {
    $pa = $pdo->query(
        "SELECT u.id AS sid, u.name, u.email, u.photo, r.id AS rid, r.room_number
         FROM users u JOIN rooms r ON r.id = u.room_id
         WHERE u.room_status = 'pending' AND u.role = 'student'"
    );
    $pending_approvals = $pa->fetchAll();
} catch (Exception $e) {}

// Recent students (last 10)
$recent_students = [];
try {
    $rstmt = $pdo->query(
        "SELECT u.name, u.email, u.photo, r.room_number, u.room_status
         FROM users u LEFT JOIN rooms r ON r.id = u.room_id
         WHERE u.role = 'student'
         ORDER BY u.created_at DESC LIMIT 10"
    );
    $recent_students = $rstmt->fetchAll();
} catch (Exception $e) {}

// Wardens
$wardens = [];
try {
    $ws = $pdo->query("SELECT name, email, photo FROM users WHERE role = 'warden' ORDER BY name ASC");
    $wardens = $ws->fetchAll();
} catch (Exception $e) {}

// Daily registrations (last 14 days, for chart)
$daily_regs = [];
try {
    $dr = $pdo->query(
        "SELECT DATE(created_at) AS d, COUNT(*) AS c
         FROM users
         WHERE role = 'student' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
         GROUP BY DATE(created_at)"
    );
    foreach ($dr->fetchAll() as $row) {
        $daily_regs[$row['d']] = (int)$row['c'];
    }
} catch (Exception $e) {}
// Build a normalized 14-day series (oldest -> newest)
$reg_series = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $reg_series[] = [
        'date'  => $d,
        'count' => $daily_regs[$d] ?? 0,
    ];
}
$reg_max = max(1, ...array_column($reg_series, 'count'));
$reg_total = array_sum(array_column($reg_series, 'count'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Dashboard &mdash; HMS</title>
    <link rel="stylesheet" href="../css/style.css?v=19">
    <style>
        /* Quick Links icon */
        .rp-link-row .rp-link-label { display: inline-flex; align-items: center; gap: 0.55rem; flex: 1; min-width: 0; }
        .rp-link-row .rp-link-icon  { width: 16px; height: 16px; display: inline-flex; align-items: center; justify-content: center; color: var(--primary); flex-shrink: 0; }
        .rp-link-row .rp-link-icon svg { width: 100%; height: 100%; stroke-width: 1.75; }

        /* Calendar today-pill glow */
        #nepali-cal .ncal-today {
            box-shadow: 0 0 0 4px rgba(99,102,241,0.18), 0 6px 14px rgba(99,102,241,0.35) !important;
            transform: scale(1.05);
        }

        /* Registrations chart */
        .reg-chart-card { padding: 1.1rem 1.35rem 1.2rem; margin-bottom: 1.25rem; }
        .reg-chart-head { margin-bottom: 1rem; }
        .reg-chart-head h3 { font-size: 1.05rem; font-weight: 800; color: var(--text); margin: 0 0 0.4rem; letter-spacing: -0.015em; }
        .reg-chart-head .reg-sub {
            font-size: 0.9rem;
            color: var(--muted);
            line-height: 1.55;
            max-width: 56ch;
            margin: 0;
        }
        .reg-chart-head .reg-sub strong { color: var(--primary); font-weight: 800; }

        .reg-chart {
            display: grid;
            grid-template-columns: repeat(14, 1fr);
            gap: 0.45rem;
            align-items: end;
            height: 130px;
            padding: 0.5rem 0 0;
        }
        .reg-bar {
            position: relative;
            border-radius: 5px 5px 2px 2px;
            background: linear-gradient(180deg, #6366f1 0%, #818cf8 100%);
            min-height: 4px;
            transition: filter 0.15s ease, transform 0.15s ease;
            cursor: default;
        }
        .reg-bar.empty { background: #e2e8f0; }
        .reg-bar.today { background: linear-gradient(180deg, #4f46e5 0%, #6366f1 100%); box-shadow: 0 4px 10px rgba(99,102,241,0.35); }
        .reg-bar:hover { filter: brightness(1.08); transform: translateY(-2px); }
        .reg-bar .reg-tooltip {
            position: absolute;
            bottom: calc(100% + 6px);
            left: 50%;
            transform: translateX(-50%);
            background: #0f172a;
            color: #fff;
            font-size: 0.7rem;
            font-weight: 600;
            padding: 0.3rem 0.55rem;
            border-radius: 6px;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.15s ease;
            z-index: 5;
        }
        .reg-bar:hover .reg-tooltip { opacity: 1; }
        .reg-x-labels {
            display: grid;
            grid-template-columns: repeat(14, 1fr);
            gap: 0.45rem;
            margin-top: 0.5rem;
            font-size: 0.65rem;
            color: var(--muted);
            font-weight: 600;
            text-align: center;
        }
        .reg-x-labels span { overflow: hidden; text-overflow: ellipsis; }
        .reg-x-labels span.today-label { color: var(--primary); font-weight: 800; }
    </style>
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

        <?php if (isset($success_msg)): ?>
        <div class="alert alert-success" style="margin-bottom:1.25rem;"><?= e($success_msg) ?></div>
        <?php endif; ?>

        <!-- Stats grid -->
        <div class="stats-grid" style="margin-bottom:1.75rem;">

            <a class="stat-box stat-link stat-indigo" href="manage_rooms.php">
                <div class="stat-icon-bubble"><?= get_svg_icon('bed') ?></div>
                <div class="stat-label">Occupancy</div>
                <div class="stat-value" data-count="<?= $occupied_rooms ?>" data-suffix="/<?= $total_rooms ?>"><?= $occupied_rooms ?>/<?= $total_rooms ?></div>
                <div class="stat-meta">Rooms occupied</div>
                <div class="progress-track" style="margin-top:0.6rem;height:5px;">
                    <div class="progress-bar" style="width:<?= $total_rooms ? round($occupied_rooms / $total_rooms * 100) : 0 ?>%;"></div>
                </div>
            </a>

            <a class="stat-box stat-link stat-amber" href="enquiries.php">
                <div class="stat-icon-bubble"><?= get_svg_icon('message') ?></div>
                <div class="stat-label">Unread Enquiries</div>
                <div class="stat-value" data-count="<?= $unread_enq ?>"><?= $unread_enq ?></div>
                <div class="stat-meta">Public messages</div>
            </a>

            <a class="stat-box stat-link stat-green" href="booking_approvals.php">
                <div class="stat-icon-bubble"><?= get_svg_icon('clipboard') ?></div>
                <div class="stat-label">Pending Bookings</div>
                <div class="stat-value" data-count="<?= $pending_bookings ?>"><?= $pending_bookings ?></div>
                <div class="stat-meta">Awaiting decision</div>
            </a>

            <a class="stat-box stat-link stat-red" href="payments.php">
                <div class="stat-icon-bubble"><?= get_svg_icon('money') ?></div>
                <div class="stat-label">Payment Claims</div>
                <div class="stat-value" data-count="<?= $pending_payments ?>"><?= $pending_payments ?></div>
                <div class="stat-meta">Pending verification</div>
            </a>

        </div>

        <!-- Registrations chart -->
        <div class="card reg-chart-card">
            <div class="reg-chart-head">
                <div>
                    <h3>Registrations &mdash; Last 14 Days</h3>
                    <p class="reg-sub">
                        <?php if ($reg_total === 0): ?>
                            No new students joined in the past two weeks &mdash; share your enquiry link to bring more residents in.
                        <?php elseif ($reg_total === 1): ?>
                            One new student joined in the past two weeks.
                        <?php else: ?>
                            <strong><?= $reg_total ?></strong> new students joined in the past two weeks &mdash; that's an average of about <strong><?= round($reg_total / 14, 1) ?></strong> per day.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="reg-chart">
                <?php $today_str = date('Y-m-d'); foreach ($reg_series as $pt):
                    $h_pct  = $reg_max > 0 ? round(($pt['count'] / $reg_max) * 100) : 0;
                    $is_today = $pt['date'] === $today_str;
                    $cls = 'reg-bar' . ($pt['count'] === 0 ? ' empty' : '') . ($is_today ? ' today' : '');
                ?>
                <div class="<?= $cls ?>" style="height:<?= max(4, $h_pct) ?>%;">
                    <span class="reg-tooltip"><?= $pt['count'] ?> on <?= date('d M', strtotime($pt['date'])) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="reg-x-labels">
                <?php foreach ($reg_series as $pt):
                    $is_today = $pt['date'] === $today_str;
                ?>
                <span class="<?= $is_today ? 'today-label' : '' ?>"><?= date('d', strtotime($pt['date'])) ?></span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Pending room approvals -->
        <?php if (!empty($pending_approvals)): ?>
        <div class="card" id="pending-approvals" style="margin-bottom:1.25rem;">
            <div class="card-header">
                <div>
                    <div class="card-title">Pending Room Approvals</div>
                    <div class="card-subtitle">Students waiting for room allocation sign-off.</div>
                </div>
                <span class="badge badge-pending" style="flex-shrink:0;"><?= count($pending_approvals) ?> Pending</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Resident</th>
                            <th>Requested Room</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_approvals as $p): ?>
                        <tr>
                            <td>
                                <div style="display:flex;align-items:center;gap:0.75rem;">
                                    <?php
                                    $pphoto = $p['photo'] ?? 'default.png';
                                    $pparts = array_values(array_filter(explode(' ', trim($p['name']))));
                                    $pinit  = strtoupper(substr($pparts[0] ?? 'S', 0, 1)) . strtoupper(substr($pparts[1] ?? '', 0, 1));
                                    ?>
                                    <div class="rp-avatar" style="width:36px;height:36px;font-size:0.75rem;">
                                        <?php if ($pphoto !== 'default.png'): ?>
                                            <img src="../uploads/students/<?= rawurlencode($pphoto) ?>" alt="<?= e($p['name']) ?>" onerror="this.parentNode.textContent='<?= e($pinit) ?>'">
                                        <?php else: ?><?= e($pinit) ?><?php endif; ?>
                                    </div>
                                    <div>
                                        <div style="font-weight:600;font-size:0.875rem;"><?= e($p['name']) ?></div>
                                        <div style="font-size:0.78rem;color:var(--muted);"><?= e($p['email']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-pending">Room <?= e($p['room_number']) ?></span>
                            </td>
                            <td>
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="student_id" value="<?= (int)$p['sid'] ?>">
                                    <input type="hidden" name="room_id"    value="<?= (int)$p['rid'] ?>">
                                    <button type="submit" name="approve_room" class="btn btn-primary" style="padding:0.3rem 0.85rem;min-height:auto;font-size:0.78rem;">Approve</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Wardens + Recent students -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;">

            <!-- Wardens -->
            <div class="card" style="margin-bottom:0;">
                <div class="card-header">
                    <div>
                        <div class="card-title">Authorized Wardens</div>
                        <div class="card-subtitle">Active staff accounts</div>
                    </div>
                    <a href="manage_staff.php" class="btn btn-secondary" style="flex-shrink:0;padding:0.3rem 0.85rem;min-height:auto;font-size:0.78rem;">Manage</a>
                </div>
                <?php if (empty($wardens)): ?>
                    <p style="color:var(--muted);font-size:0.875rem;">No wardens assigned.</p>
                <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Name</th><th>Email</th></tr></thead>
                        <tbody>
                            <?php foreach ($wardens as $w):
                                $wphoto = $w['photo'] ?? 'default.png';
                                $wparts = array_values(array_filter(explode(' ', trim($w['name']))));
                                $winit  = strtoupper(substr($wparts[0] ?? 'W', 0, 1)) . strtoupper(substr($wparts[1] ?? '', 0, 1));
                            ?>
                            <tr>
                                <td>
                                    <div style="display:flex;align-items:center;gap:0.65rem;">
                                        <div class="rp-avatar" style="width:32px;height:32px;font-size:0.72rem;">
                                            <?php if ($wphoto !== 'default.png'): ?>
                                                <img src="../uploads/students/<?= rawurlencode($wphoto) ?>" alt="<?= e($w['name']) ?>" onerror="this.parentNode.textContent='<?= e($winit) ?>'">
                                            <?php else: ?><?= e($winit) ?><?php endif; ?>
                                        </div>
                                        <span style="font-weight:600;"><?= e($w['name']) ?></span>
                                    </div>
                                </td>
                                <td style="font-size:0.8rem;color:var(--muted);"><?= e($w['email']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Recent students -->
            <div class="card" style="margin-bottom:0;">
                <div class="card-header">
                    <div>
                        <div class="card-title">Recent Registrations</div>
                        <div class="card-subtitle">Latest 10 students</div>
                    </div>
                </div>
                <?php if (empty($recent_students)): ?>
                    <p style="color:var(--muted);font-size:0.875rem;">No students registered.</p>
                <?php else: ?>
                <div class="table-wrap" style="max-height:320px;overflow-y:auto;">
                    <table>
                        <thead style="position:sticky;top:0;background:var(--panel);z-index:1;">
                            <tr><th>Resident</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_students as $s):
                                $sphoto = $s['photo'] ?? 'default.png';
                                $sparts = array_values(array_filter(explode(' ', trim($s['name']))));
                                $sinit  = strtoupper(substr($sparts[0] ?? 'S', 0, 1)) . strtoupper(substr($sparts[1] ?? '', 0, 1));
                            ?>
                            <tr>
                                <td>
                                    <div style="display:flex;align-items:center;gap:0.65rem;">
                                        <div class="rp-avatar" style="width:32px;height:32px;font-size:0.72rem;">
                                            <?php if ($sphoto !== 'default.png'): ?>
                                                <img src="../uploads/students/<?= rawurlencode($sphoto) ?>" alt="<?= e($s['name']) ?>" onerror="this.parentNode.textContent='<?= e($sinit) ?>'">
                                            <?php else: ?><?= e($sinit) ?><?php endif; ?>
                                        </div>
                                        <div style="min-width:0;">
                                            <div style="font-weight:600;font-size:0.875rem;"><?= e($s['name']) ?></div>
                                            <div style="font-size:0.76rem;color:var(--muted);"><?= e($s['email']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($s['room_number']): ?>
                                        <?php $sc = $s['room_status'] === 'approved' ? 'badge-green' : 'badge-pending'; ?>
                                        <span class="badge <?= $sc ?>"><?= ucfirst($s['room_status']) ?></span>
                                    <?php else: ?>
                                        <span class="badge" style="background:var(--panel-alt);color:var(--muted);border:1px solid var(--border);">No Room</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
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
                    <div style="font-size:0.78rem;color:var(--muted);">Owner</div>
                </div>
            </div>
            <hr class="rp-divider">
            <div style="display:flex;gap:1.25rem;margin-bottom:0.75rem;">
                <div>
                    <div style="font-size:1.25rem;font-weight:700;color:var(--text);line-height:1;"><?= $total_students ?></div>
                    <div style="font-size:0.72rem;color:var(--muted);">Total students</div>
                </div>
                <div>
                    <div style="font-size:1.25rem;font-weight:700;color:var(--text);line-height:1;"><?= $occupied_rooms ?></div>
                    <div style="font-size:0.72rem;color:var(--muted);">Rooms occupied</div>
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

        <!-- Quick Links -->
        <div class="rp-card">
            <div class="rp-card-title">Quick Links</div>
            <a href="booking_approvals.php" class="rp-link-row">
                <span class="rp-link-label"><span class="rp-link-icon"><?= get_svg_icon('clipboard') ?></span>Booking Approvals</span>
                <span class="rp-link-count<?= $pending_bookings > 0 ? ' amber' : '' ?>"><?= $pending_bookings ?></span>
            </a>
            <a href="payments.php" class="rp-link-row">
                <span class="rp-link-label"><span class="rp-link-icon"><?= get_svg_icon('money') ?></span>Fee Tracking</span>
                <span class="rp-link-count<?= $pending_payments > 0 ? ' red' : '' ?>"><?= $pending_payments ?></span>
            </a>
            <a href="enquiries.php" class="rp-link-row">
                <span class="rp-link-label"><span class="rp-link-icon"><?= get_svg_icon('message') ?></span>Enquiries</span>
                <span class="rp-link-count<?= $unread_enq > 0 ? ' amber' : '' ?>"><?= $unread_enq ?></span>
            </a>
            <a href="manage_rooms.php" class="rp-link-row">
                <span class="rp-link-label"><span class="rp-link-icon"><?= get_svg_icon('bed') ?></span>Manage Rooms</span>
                <span class="rp-link-count"><?= $total_rooms ?></span>
            </a>
            <a href="report_attendance.php" class="rp-link-row">
                <span class="rp-link-label"><span class="rp-link-icon"><?= get_svg_icon('chart') ?></span>Attendance Report</span>
                <span style="color:var(--muted);font-size:0.78rem;">&rsaquo;</span>
            </a>
            <a href="manage_staff.php" class="rp-link-row">
                <span class="rp-link-label"><span class="rp-link-icon"><?= get_svg_icon('users') ?></span>Manage Staff</span>
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
