<?php
// ─────────────────────────────────────────────────
// warden/list_student.php — Student List & Room Assign
// ─────────────────────────────────────────────────

require_once '../dp.php';
require_role('warden');

$success_msg = $error_msg = '';

// Handle Room Assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['assign_room'])) {

    csrf_verify($_POST['csrf_token'] ?? '');

    $student_id = (int)($_POST['student_id'] ?? 0);
    $room_id    = (int)($_POST['room_id']    ?? 0);

    if ($student_id > 0 && $room_id > 0) {
        // Update student's room_id
        $s1 = mysqli_prepare($conn,
            "UPDATE users SET room_id = ?, room_status = 'pending'
             WHERE id = ? AND role = 'student'");
        mysqli_stmt_bind_param($s1, 'ii', $room_id, $student_id);
        mysqli_stmt_execute($s1);
        mysqli_stmt_close($s1);

        // Link student to room and mark occupied
        $s2 = mysqli_prepare($conn,
            "UPDATE rooms SET student_id = ?, status = 'occupied'
             WHERE id = ?");
        mysqli_stmt_bind_param($s2, 'ii', $student_id, $room_id);
        mysqli_stmt_execute($s2);
        mysqli_stmt_close($s2);

        $success_msg = 'Room assigned successfully.';
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
        $s1 = mysqli_prepare($conn,
            "UPDATE users SET room_id = NULL, room_status = 'pending'
             WHERE id = ?");
        mysqli_stmt_bind_param($s1, 'i', $student_id);
        mysqli_stmt_execute($s1);
        mysqli_stmt_close($s1);

        $s2 = mysqli_prepare($conn,
            "UPDATE rooms SET student_id = NULL, status = 'available'
             WHERE id = ?");
        mysqli_stmt_bind_param($s2, 'i', $room_id);
        mysqli_stmt_execute($s2);
        mysqli_stmt_close($s2);

        $success_msg = 'Room unassigned successfully.';
    }
}

// Load all students with room info
$students = [];
$res = mysqli_query($conn,
    "SELECT u.id, u.name, u.email, u.student_phone,
            u.room_id, u.room_status, r.room_number, r.id as rid
     FROM users u
     LEFT JOIN rooms r ON r.id = u.room_id
     WHERE u.role = 'student'
     ORDER BY u.created_at DESC");
while ($r = mysqli_fetch_assoc($res)) $students[] = $r;

// Available rooms for dropdown
$avail_rooms = [];
$rr = mysqli_query($conn,
    "SELECT id, room_number, room_type, floor
     FROM rooms WHERE status = 'available'
     ORDER BY room_number ASC");
while ($r = mysqli_fetch_assoc($rr)) $avail_rooms[] = $r;

// Students without rooms for dropdown
$unassigned = array_filter($students, fn($s) => !$s['room_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student List — HMS</title>
    <link rel="stylesheet" href="../css/style.css?v=2">
</head>
<body>
<?php echo render_sidebar('list_student.php'); ?>
<div class="container">
    <div class="page-header">
        <h1>Student Management</h1>
        <p>View students and manage room assignments</p>
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

    <!-- Students Table -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">All Students</h2>
            <span style="font-size:0.8rem; color:var(--text-muted);">
                Total: <?= count($students) ?>
            </span>
        </div>
        <?php if ($students): ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Room</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($students as $i => $s): ?>
                    <tr>
                        <td style="color:var(--text-muted);">
                            <?= $i + 1 ?>
                        </td>
                        <td style="font-weight:700;">
                            <?= e($s['name']) ?>
                        </td>
                        <td style="color:var(--text-muted);">
                            <?= e($s['email']) ?>
                        </td>
                        <td style="color:var(--text-muted);">
                            <?= e($s['student_phone'] ?? '—') ?>
                        </td>
                        <td>
                            <?php if ($s['room_number']): ?>
                                <span class="badge badge-green">
                                    Room <?= e($s['room_number']) ?>
                                </span>
                            <?php else: ?>
                                <span class="badge badge-red">
                                    Not Assigned
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($s['room_id']): ?>
                                <span class="badge badge-<?= $s['room_status'] === 'approved'
                                    ? 'green' : 'yellow' ?>">
                                    <?= e(ucfirst($s['room_status'])) ?>
                                </span>
                            <?php else: ?>
                                <span style="color:var(--text-muted);">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($s['room_id'] && $s['rid']): ?>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="csrf_token"
                                           value="<?= csrf_token() ?>">
                                    <input type="hidden" name="unassign_room"
                                           value="1">
                                    <input type="hidden" name="student_id"
                                           value="<?= (int)$s['id'] ?>">
                                    <input type="hidden" name="room_id"
                                           value="<?= (int)$s['rid'] ?>">
                                    <button type="submit"
                                            class="btn btn-secondary"
                                            style="font-size:0.75rem;
                                                   padding:0.25rem 0.75rem;
                                                   color:var(--danger);"
                                            onclick="return confirm(
                                                'Unassign room from <?= e(addslashes($s['name'])) ?>?'
                                            )">
                                        Unassign
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color:var(--text-muted); text-align:center; padding:2rem;">
                No students registered yet.
            </p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
