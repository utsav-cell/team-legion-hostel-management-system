<?php
// ─────────────────────────────────────────────────
// warden/list_student.php — Student List & Room Assign
// ─────────────────────────────────────────────────

require_once '../db.php';
require_role('warden');

$success_msg = $error_msg = '';

$actor_id = (int)$_SESSION['user_id'];

// Handle Room Assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['assign_room'])) {

    csrf_verify($_POST['csrf_token'] ?? '');

    $student_id = (int)($_POST['student_id'] ?? 0);
    $room_id    = (int)($_POST['room_id']    ?? 0);

    if ($student_id > 0 && $room_id > 0) {
        try {
            $pdo->beginTransaction();
            // Update student's room_id
            $pdo->prepare("UPDATE users SET room_id = ?, room_status = 'approved' WHERE id = ? AND role = 'student'")
                ->execute([$room_id, $student_id]);
            // Link student to room. Keep student_id pointer for legacy single-occupancy
            // tracking but DON'T flip status yet — that's done conditionally below.
            $pdo->prepare("UPDATE rooms SET student_id = ? WHERE id = ?")
                ->execute([$student_id, $room_id]);
            // Sync bookings: cancel any prior active bookings + create one approved booking
            $pdo->prepare("UPDATE bookings SET status = 'cancelled', updated_at = NOW()
                           WHERE student_id = ? AND status IN ('pending_approval','approved','active')")
                ->execute([$student_id]);
            $pdo->prepare("INSERT INTO bookings (student_id, room_id, start_date, status, approved_by, approved_at)
                           VALUES (?, ?, CURDATE(), 'approved', ?, NOW())")
                ->execute([$student_id, $room_id, $actor_id]);
            // Only mark 'occupied' once the room has actually reached capacity.
            // Partially filled triples/doubles stay 'available' so other students can book.
            $pdo->prepare(
                "UPDATE rooms r
                 SET r.status = 'occupied'
                 WHERE r.id = ?
                   AND (SELECT COUNT(*) FROM users u WHERE u.room_id = r.id AND u.role = 'student') >= r.capacity"
            )->execute([$room_id]);
            $pdo->commit();
            $success_msg = 'Room assigned successfully.';
        } catch (Exception $ex) {
            $pdo->rollBack();
            $error_msg = 'Assignment failed: ' . $ex->getMessage();
        }
    } else {
        $error_msg = 'Please select both a student and a room.';
    }
}

// Handle Room Unassignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['unassign_room'])) {

    csrf_verify($_POST['csrf_token'] ?? '');
    $student_id = (int)($_POST['student_id'] ?? 0);
    $room_id    = (int)($_POST['room_id']    ?? 0);

    if ($student_id > 0 && $room_id > 0) {
        try {
            $pdo->beginTransaction();
            // Clear student's room and reset status
            $pdo->prepare("UPDATE users SET room_id = NULL, room_status = 'pending' WHERE id = ?")
                ->execute([$student_id]);
            // Free the room
            $pdo->prepare("UPDATE rooms SET student_id = NULL, status = 'available' WHERE id = ?")
                ->execute([$room_id]);
            // Cancel any active/approved bookings so student dashboard + my_bookings clear too
            $pdo->prepare("UPDATE bookings SET status = 'cancelled', updated_at = NOW()
                           WHERE student_id = ? AND status IN ('pending_approval','approved','active')")
                ->execute([$student_id]);
            $pdo->commit();
            $success_msg = 'Room unassigned successfully.';
        } catch (Exception $ex) {
            $pdo->rollBack();
            $error_msg = 'Unassignment failed: ' . $ex->getMessage();
        }
    }
}

// Handle Room Transfer
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['transfer_room'])) {

    csrf_verify($_POST['csrf_token'] ?? '');
    $student_id   = (int)($_POST['student_id']   ?? 0);
    $from_room_id = (int)($_POST['from_room_id'] ?? 0);
    $to_room_id   = (int)($_POST['to_room_id']   ?? 0);

    if ($student_id <= 0 || $to_room_id <= 0) {
        $error_msg = 'Please select a target room.';
    } elseif ($to_room_id === $from_room_id) {
        $error_msg = 'Target room is the same as the current room.';
    } else {
        $chk = $pdo->prepare("SELECT id FROM rooms WHERE id = ? AND status = 'available' AND deleted_at IS NULL LIMIT 1");
        $chk->execute([$to_room_id]);
        if (!$chk->fetch()) {
            $error_msg = 'Target room is not available.';
        } else {
            try {
                $pdo->beginTransaction();
                // Move student to the new room
                $pdo->prepare("UPDATE users SET room_id = ?, room_status = 'approved' WHERE id = ? AND role = 'student'")
                    ->execute([$to_room_id, $student_id]);
                // Cancel old bookings, insert new approved booking
                $pdo->prepare("UPDATE bookings SET status = 'cancelled', updated_at = NOW()
                               WHERE student_id = ? AND status IN ('pending_approval','approved','active')")
                    ->execute([$student_id]);
                $pdo->prepare("INSERT INTO bookings (student_id, room_id, start_date, status, approved_by, approved_at)
                               VALUES (?, ?, CURDATE(), 'approved', ?, NOW())")
                    ->execute([$student_id, $to_room_id, $actor_id]);
                // Free old room: if empty → 'available', else just clear the
                // single-occupancy student_id pointer. Multi-bed rooms with
                // remaining students stay listed as available for booking.
                if ($from_room_id) {
                    $oc = $pdo->prepare("SELECT COUNT(*) FROM users WHERE room_id = ?");
                    $oc->execute([$from_room_id]);
                    if ((int)$oc->fetchColumn() === 0) {
                        $pdo->prepare("UPDATE rooms SET student_id = NULL, status = 'available' WHERE id = ?")
                            ->execute([$from_room_id]);
                    } else {
                        // Room still has other students — if it was 'occupied' but now
                        // has free beds, flip back to 'available'.
                        $pdo->prepare(
                            "UPDATE rooms r
                             SET r.status = 'available'
                             WHERE r.id = ?
                               AND r.status = 'occupied'
                               AND (SELECT COUNT(*) FROM users u WHERE u.room_id = r.id AND u.role='student') < r.capacity"
                        )->execute([$from_room_id]);
                    }
                }
                // Link the student to the new room. Only flip 'occupied' if it has
                // reached capacity, otherwise leave 'available' for further bookings.
                $pdo->prepare("UPDATE rooms SET student_id = ? WHERE id = ?")
                    ->execute([$student_id, $to_room_id]);
                $pdo->prepare(
                    "UPDATE rooms r
                     SET r.status = 'occupied'
                     WHERE r.id = ?
                       AND (SELECT COUNT(*) FROM users u WHERE u.room_id = r.id AND u.role='student') >= r.capacity"
                )->execute([$to_room_id]);
                // Audit
                $pdo->prepare("INSERT INTO room_audit_log (room_id, student_id, event, from_room_id, to_room_id, actor_id, notes)
                               VALUES (?, ?, 'warden_transfer', ?, ?, ?, 'Direct warden transfer')")
                    ->execute([$to_room_id, $student_id, $from_room_id ?: null, $to_room_id, $actor_id]);
                $pdo->commit();
                $success_msg = 'Student transferred to new room.';
            } catch (Exception $ex) {
                $pdo->rollBack();
                $error_msg = 'Transfer failed: ' . $ex->getMessage();
            }
        }
    }
}

// Search + pagination params
$search   = trim($_GET['q'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;

// Load all students (filtered by search if provided)
$students = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    $sp = $pdo->prepare(
        "SELECT u.id, u.name, u.email, u.student_phone,
                u.room_id, u.room_status, u.photo, r.room_number, r.id as rid
         FROM users u
         LEFT JOIN rooms r ON r.id = u.room_id
         WHERE u.role = 'student'
           AND (u.name LIKE ? OR u.email LIKE ? OR u.student_phone LIKE ?)
         ORDER BY u.created_at DESC"
    );
    $sp->execute([$like, $like, $like]);
    $students = $sp->fetchAll();
} else {
    $sp = $pdo->query(
        "SELECT u.id, u.name, u.email, u.student_phone,
                u.room_id, u.room_status, u.photo, r.room_number, r.id as rid
         FROM users u
         LEFT JOIN rooms r ON r.id = u.room_id
         WHERE u.role = 'student'
         ORDER BY u.created_at DESC"
    );
    $students = $sp->fetchAll();
}

$total_students  = count($students);
$total_pages     = max(1, (int)ceil($total_students / $per_page));
$page            = min($page, $total_pages);
$offset          = ($page - 1) * $per_page;
$paged_students  = array_slice($students, $offset, $per_page);

// Summary stats
$assigned_count   = 0;
$unassigned_count = 0;
foreach ($students as $s) {
    if ($s['room_id']) $assigned_count++;
    else               $unassigned_count++;
}

// Available rooms for dropdown
$avail_rooms = [];
$rr = mysqli_query($conn,
    "SELECT id, room_number, room_type, floor
     FROM rooms WHERE status = 'available'
     ORDER BY room_number ASC");
while ($r = mysqli_fetch_assoc($rr)) $avail_rooms[] = $r;

// Students without rooms for dropdown (uses full list, not paged)
$unassigned = array_filter($students, fn($s) => !$s['room_id']);

// Helper to build paginated URLs preserving search query
function ls_url($page_num, $search) {
    $qs = http_build_query(array_filter(['q' => $search, 'page' => $page_num]));
    return 'list_student.php' . ($qs ? '?' . $qs : '');
}

// Initials for avatar fallback
function ls_initials($name) {
    $parts = array_values(array_filter(explode(' ', trim($name))));
    return strtoupper(substr($parts[0] ?? '?', 0, 1) . substr($parts[1] ?? '', 0, 1));
}

// Stable color from name for avatar background
function ls_avatar_color($name) {
    $colors = ['#6366f1','#0ea5e9','#10b981','#f59e0b','#ec4899','#8b5cf6','#14b8a6','#f43f5e'];
    return $colors[abs(crc32($name)) % count($colors)];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student List — HMS</title>
    <link rel="stylesheet" href="../css/style.css?v=34">
    <style>
        .ls-stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
        .ls-stat { background: #fff; border: 1.5px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-soft); padding: 1rem 1.15rem; display: flex; align-items: center; gap: 0.85rem; }
        .ls-stat-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .ls-stat-icon svg { width: 18px; height: 18px; stroke: currentColor; fill: none; stroke-width: 2; }
        .ls-stat-value { font-size: 1.5rem; font-weight: 800; color: var(--text); letter-spacing: -0.02em; line-height: 1; }
        .ls-stat-label { font-size: 0.72rem; font-weight: 700; letter-spacing: 0.06em; color: var(--muted); text-transform: uppercase; margin-top: 4px; }
        @media (max-width: 700px) { .ls-stats-grid { grid-template-columns: 1fr; } }

        .ls-card-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem 1.25rem 0.85rem; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
        .ls-card-head h2 { font-size: 1rem; font-weight: 700; margin: 0; color: var(--text); }
        .ls-search-form { display: flex; gap: 0.5rem; align-items: center; }
        .ls-search-input { border: 1.5px solid var(--border); border-radius: 8px; padding: 0.45rem 0.75rem 0.45rem 2rem; font-size: 0.85rem; min-width: 220px; background: #fff url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2'><circle cx='11' cy='11' r='8'/><line x1='21' y1='21' x2='16.65' y2='16.65'/></svg>") no-repeat 0.55rem center; background-size: 14px 14px; transition: border-color 0.15s; }
        .ls-search-input:focus { outline: none; border-color: var(--primary); }
        .ls-search-clear { color: var(--muted); font-size: 0.78rem; text-decoration: none; }
        .ls-search-clear:hover { color: var(--text); }

        .ls-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 0.875rem; }
        .ls-table thead th { background: var(--panel-alt); padding: 0.75rem 1rem; text-align: left; font-size: 0.7rem; font-weight: 700; letter-spacing: 0.07em; color: var(--muted); text-transform: uppercase; border-bottom: 1.5px solid var(--border); }
        .ls-table tbody td { padding: 0.85rem 1rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        .ls-table tbody tr { transition: background 0.12s; }
        .ls-table tbody tr:hover { background: rgba(99,102,241,0.04); }
        .ls-table tbody tr:nth-child(even) { background: rgba(15,23,42,0.015); }
        .ls-table tbody tr:nth-child(even):hover { background: rgba(99,102,241,0.05); }
        .ls-table tbody tr:last-child td { border-bottom: none; }

        .ls-cell-user { display: flex; align-items: center; gap: 0.7rem; min-width: 200px; }
        .ls-avatar { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; font-size: 0.78rem; flex-shrink: 0; box-shadow: 0 2px 4px rgba(0,0,0,0.05); overflow: hidden; background: #6366f1; }
        .ls-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .ls-cell-name { font-weight: 700; color: var(--text); line-height: 1.2; }
        .ls-cell-id { font-size: 0.72rem; color: var(--muted); margin-top: 2px; }
        .ls-cell-contact { color: var(--muted); font-size: 0.8rem; line-height: 1.4; }
        .ls-cell-contact .ls-email { display: block; color: var(--text); font-weight: 500; }

        .ls-room-chip { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.78rem; font-weight: 700; padding: 0.3rem 0.65rem; border-radius: 7px; }
        .ls-room-chip.has-room { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .ls-room-chip.no-room  { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        .ls-room-chip svg { width: 12px; height: 12px; stroke: currentColor; fill: none; stroke-width: 2.5; }

        .ls-status-pill { display: inline-flex; align-items: center; gap: 0.3rem; font-size: 0.72rem; font-weight: 700; padding: 0.25rem 0.55rem; border-radius: 999px; text-transform: uppercase; letter-spacing: 0.05em; }
        .ls-status-pill::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        .ls-status-pill.s-approved { background: rgba(16,185,129,0.12); color: #059669; }
        .ls-status-pill.s-pending  { background: rgba(245,158,11,0.12); color: #b45309; }
        .ls-status-pill.s-none     { color: var(--muted); }

        .ls-action-group { display: flex; gap: 0.4rem; flex-wrap: wrap; align-items: center; }
        .ls-action-group select { font-size: 0.75rem; padding: 0.35rem 0.5rem; border: 1.5px solid var(--border); border-radius: 7px; max-width: 150px; background: #fff; transition: border-color 0.15s; }
        .ls-action-group select:focus { outline: none; border-color: var(--primary); }
        .ls-btn-mini { font-size: 0.74rem; padding: 0.35rem 0.75rem; border-radius: 7px; font-weight: 700; border: none; cursor: pointer; transition: all 0.12s; }
        .ls-btn-transfer { background: var(--primary); color: #fff; }
        .ls-btn-transfer:hover { filter: brightness(1.08); transform: translateY(-1px); }
        .ls-btn-unassign { background: #fff; color: #b91c1c; border: 1.5px solid #fecaca; }
        .ls-btn-unassign:hover { background: #fee2e2; }

        .ls-empty { text-align: center; padding: 3.5rem 1rem; color: var(--muted); }
        .ls-empty svg { width: 42px; height: 42px; stroke: var(--muted); fill: none; stroke-width: 1.5; opacity: 0.4; margin-bottom: 0.75rem; }
        .ls-empty h3 { font-size: 0.95rem; font-weight: 700; color: var(--text); margin-bottom: 0.25rem; }
        .ls-empty p  { font-size: 0.82rem; }

        .ls-pagination { display: flex; align-items: center; justify-content: space-between; padding: 0.85rem 1.25rem; border-top: 1px solid var(--border); flex-wrap: wrap; gap: 0.75rem; }
        .ls-page-info { font-size: 0.8rem; color: var(--muted); }
        .ls-page-info strong { color: var(--text); font-weight: 700; }
        .ls-page-nav { display: flex; gap: 0.3rem; }
        .ls-page-btn { padding: 0.35rem 0.7rem; border: 1.5px solid var(--border); background: #fff; color: var(--text); border-radius: 7px; font-size: 0.8rem; font-weight: 600; text-decoration: none; transition: all 0.12s; min-width: 32px; text-align: center; display: inline-flex; align-items: center; justify-content: center; }
        .ls-page-btn:hover:not(.is-disabled):not(.is-active) { border-color: var(--primary); color: var(--primary); }
        .ls-page-btn.is-active { background: var(--primary); color: #fff; border-color: var(--primary); }
        .ls-page-btn.is-disabled { opacity: 0.45; cursor: not-allowed; pointer-events: none; }

        .ls-card { background: #fff; border: 1.5px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-soft); overflow: hidden; }
        .ls-table-wrap { overflow-x: auto; }
    </style>
</head>
<body>
<?php echo render_sidebar('list_student.php'); ?>
<?= render_topbar() ?>
<div class="container">
    <div class="page-header">
        <h1>Student Management</h1>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?= e($success_msg) ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><?= e($error_msg) ?></div>
    <?php endif; ?>

    <!-- Assign Room Form -->
    <?php if ($avail_rooms && $unassigned): ?>
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Assign a Room</h2>
        </div>
        <form method="post"
              style="display:flex; gap:1rem; flex-wrap:wrap; align-items:flex-end;">
            <input type="hidden" name="csrf_token"
                   value="<?= csrf_token() ?>">
            <input type="hidden" name="assign_room" value="1">
            <div class="form-group" style="margin-bottom:0; flex:1; min-width:180px;">
                <label>Select Student</label>
                <select name="student_id" required
                        style="width:100%; padding:0.625rem;
                               border:1px solid var(--border); border-radius:10px;">
                    <option value="">— Choose Student —</option>
                    <?php foreach ($unassigned as $s): ?>
                        <option value="<?= (int)$s['id'] ?>">
                            <?= e($s['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:0; flex:1; min-width:180px;">
                <label>Select Room</label>
                <select name="room_id" required
                        style="width:100%; padding:0.625rem;
                               border:1px solid var(--border); border-radius:10px;">
                    <option value="">— Choose Room —</option>
                    <?php foreach ($avail_rooms as $r): ?>
                        <option value="<?= (int)$r['id'] ?>">
                            Room <?= e($r['room_number']) ?> —
                            <?= e($r['room_type']) ?>, Floor <?= (int)$r['floor'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">
                Assign Room
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Summary Stats -->
    <div class="ls-stats-grid">
        <div class="ls-stat">
            <div class="ls-stat-icon" style="background:rgba(99,102,241,0.1);color:#6366f1;">
                <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
            <div>
                <div class="ls-stat-value"><?= $total_students ?></div>
                <div class="ls-stat-label">Total Students</div>
            </div>
        </div>
        <div class="ls-stat">
            <div class="ls-stat-icon" style="background:rgba(16,185,129,0.1);color:#10b981;">
                <svg viewBox="0 0 24 24"><path d="M2 7v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7M4 12h16M4 7h16v4H4z"/></svg>
            </div>
            <div>
                <div class="ls-stat-value"><?= $assigned_count ?></div>
                <div class="ls-stat-label">Room Assigned</div>
            </div>
        </div>
        <div class="ls-stat">
            <div class="ls-stat-icon" style="background:rgba(245,158,11,0.1);color:#f59e0b;">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            </div>
            <div>
                <div class="ls-stat-value"><?= $unassigned_count ?></div>
                <div class="ls-stat-label">Awaiting Room</div>
            </div>
        </div>
    </div>

    <!-- Students Table -->
    <div class="ls-card">
        <div class="ls-card-head">
            <h2>All Students<?php if ($search !== ''): ?> <span style="color:var(--muted);font-weight:500;">— results for "<?= e($search) ?>"</span><?php endif; ?></h2>
            <form method="get" class="ls-search-form">
                <input type="search" name="q" class="ls-search-input"
                       placeholder="Search by name, email, phone…"
                       value="<?= e($search) ?>">
                <?php if ($search !== ''): ?>
                    <a href="list_student.php" class="ls-search-clear">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if ($paged_students): ?>
        <div class="ls-table-wrap">
            <table class="ls-table" data-paginate="15">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Contact</th>
                        <th>Room</th>
                        <th>Status</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($paged_students as $s):
                    $photo_url = (!empty($s['photo']) && $s['photo'] !== 'default.png')
                        ? '../uploads/students/' . rawurlencode($s['photo'])
                        : null;
                    $initials = ls_initials($s['name']);
                    $avatar_bg = ls_avatar_color($s['name']);
                ?>
                    <tr>
                        <td>
                            <a href="../auth/profile.php?id=<?= (int)$s['id'] ?>"
                               class="ls-cell-user"
                               style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:0.65rem;">
                                <div class="ls-avatar" style="background: <?= e($avatar_bg) ?>;">
                                    <?php if ($photo_url): ?>
                                        <img src="<?= e($photo_url) ?>" alt="">
                                    <?php else: ?>
                                        <?= e($initials) ?>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div class="ls-cell-name"><?= e($s['name']) ?></div>
                                    <div class="ls-cell-id">ID #<?= (int)$s['id'] ?></div>
                                </div>
                            </a>
                        </td>
                        <td>
                            <div class="ls-cell-contact">
                                <span class="ls-email"><?= e($s['email']) ?></span>
                                <?= e($s['student_phone'] ?? '—') ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($s['room_number']): ?>
                                <span class="ls-room-chip has-room">
                                    <svg viewBox="0 0 24 24"><path d="M3 6a2 2 0 0 0 0 4h18a2 2 0 0 0 0-4H3z"/><path d="M3 10v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-10"/></svg>
                                    Room <?= e($s['room_number']) ?>
                                </span>
                            <?php else: ?>
                                <span class="ls-room-chip no-room">Not Assigned</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($s['room_id']):
                                $cls = $s['room_status'] === 'approved' ? 's-approved' : 's-pending';
                            ?>
                                <span class="ls-status-pill <?= $cls ?>"><?= e(ucfirst($s['room_status'])) ?></span>
                            <?php else: ?>
                                <span class="ls-status-pill s-none">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($s['room_id'] && $s['rid']): ?>
                                <div class="ls-action-group" style="justify-content:flex-end;">
                                    <?php if ($avail_rooms): ?>
                                    <form method="post" style="display:inline-flex;gap:0.3rem;align-items:center;">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="transfer_room" value="1">
                                        <input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
                                        <input type="hidden" name="from_room_id" value="<?= (int)$s['rid'] ?>">
                                        <select name="to_room_id" required>
                                            <option value="">— Transfer to —</option>
                                            <?php foreach ($avail_rooms as $ar): ?>
                                                <option value="<?= (int)$ar['id'] ?>">
                                                    Room <?= e($ar['room_number']) ?> · <?= e($ar['room_type']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="ls-btn-mini ls-btn-transfer"
                                                data-confirm="Transfer <?= e($s['name']) ?> to the selected room?">
                                            Transfer
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="unassign_room" value="1">
                                        <input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
                                        <input type="hidden" name="room_id" value="<?= (int)$s['rid'] ?>">
                                        <button type="submit" class="ls-btn-mini ls-btn-unassign"
                                                data-confirm="Unassign room from <?= e($s['name']) ?>? Their booking will be cancelled.">
                                            Unassign
                                        </button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <div style="text-align:right;color:var(--muted);font-size:0.78rem;">Use the form above to assign.</div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="ls-pagination">
            <div class="ls-page-info">
                Showing <strong><?= $offset + 1 ?>–<?= min($offset + $per_page, $total_students) ?></strong>
                of <strong><?= $total_students ?></strong> students
            </div>
            <div class="ls-page-nav">
                <a class="ls-page-btn <?= $page <= 1 ? 'is-disabled' : '' ?>"
                   href="<?= e(ls_url(max(1, $page - 1), $search)) ?>">‹ Prev</a>
                <?php
                $window_start = max(1, $page - 2);
                $window_end   = min($total_pages, $page + 2);
                if ($window_start > 1) {
                    echo '<a class="ls-page-btn" href="' . e(ls_url(1, $search)) . '">1</a>';
                    if ($window_start > 2) echo '<span class="ls-page-btn is-disabled" style="border:none;">…</span>';
                }
                for ($p = $window_start; $p <= $window_end; $p++): ?>
                    <a class="ls-page-btn <?= $p === $page ? 'is-active' : '' ?>"
                       href="<?= e(ls_url($p, $search)) ?>"><?= $p ?></a>
                <?php endfor;
                if ($window_end < $total_pages) {
                    if ($window_end < $total_pages - 1) echo '<span class="ls-page-btn is-disabled" style="border:none;">…</span>';
                    echo '<a class="ls-page-btn" href="' . e(ls_url($total_pages, $search)) . '">' . $total_pages . '</a>';
                }
                ?>
                <a class="ls-page-btn <?= $page >= $total_pages ? 'is-disabled' : '' ?>"
                   href="<?= e(ls_url(min($total_pages, $page + 1), $search)) ?>">Next ›</a>
            </div>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="ls-empty">
            <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <h3><?= $search !== '' ? 'No matches found' : 'No students registered yet' ?></h3>
            <p>
                <?php if ($search !== ''): ?>
                    No students match "<?= e($search) ?>". <a href="list_student.php" style="color:var(--primary);font-weight:600;">Clear search</a>.
                <?php else: ?>
                    Students will appear here as they register.
                <?php endif; ?>
            </p>
        </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
