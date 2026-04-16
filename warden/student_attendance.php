<?php
// ─────────────────────────────────────────────────
// warden/student_attendance.php — Mark Attendance
// Supports AJAX auto-save per student
// ─────────────────────────────────────────────────

require_once '../db.php';
require_role('warden');

$name  = $_SESSION['user_name'];
$today = date('Y-m-d');

// ── AJAX single-student auto-save ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    !empty($_POST['ajax'])) {

    header('Content-Type: application/json');

    // Verify CSRF
    if (!hash_equals(
        $_SESSION['csrf'] ?? '',
        $_POST['csrf_token'] ?? ''
    )) {
        echo json_encode(['success' => false, 'message' => 'Invalid token']);
        exit;
    }

    $sid    = (int)($_POST['student_id'] ?? 0);
    $status = trim($_POST['status'] ?? '');

    if ($sid <= 0 || !in_array($status, ['present', 'absent'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid input']);
        exit;
    }

    // Check if already marked today
    $chk = mysqli_prepare($conn,
        "SELECT id FROM attendance
         WHERE student_id = ? AND date = ? LIMIT 1");
    mysqli_stmt_bind_param($chk, 'is', $sid, $today);
    mysqli_stmt_execute($chk);
    mysqli_stmt_store_result($chk);
    $exists = mysqli_stmt_num_rows($chk) > 0;
    mysqli_stmt_close($chk);

    if ($exists) {
        $stmt = mysqli_prepare($conn,
            "UPDATE attendance SET status = ?
             WHERE student_id = ? AND date = ?");
        mysqli_stmt_bind_param($stmt, 'sis', $status, $sid, $today);
    } else {
        $stmt = mysqli_prepare($conn,
            "INSERT INTO attendance (student_id, date, status)
             VALUES (?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'iss', $sid, $today, $status);
    }

    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    echo json_encode(['success' => (bool)$ok]);
    exit;
}

// ── BULK SAVE (form submit) ─────────────────────────
$save_msg = $save_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    empty($_POST['ajax'])) {

    csrf_verify($_POST['csrf_token'] ?? '');
    $data = $_POST['attendance'] ?? [];

    foreach ($data as $sid => $status) {
        $sid    = (int)$sid;
        $status = trim($status);
        if ($sid <= 0 || !in_array($status, ['present', 'absent'])) {
            continue;
        }

        // Check if exists
        $chk = mysqli_prepare($conn,
            "SELECT id FROM attendance
             WHERE student_id = ? AND date = ? LIMIT 1");
        mysqli_stmt_bind_param($chk, 'is', $sid, $today);
        mysqli_stmt_execute($chk);
        mysqli_stmt_store_result($chk);
        $exists = mysqli_stmt_num_rows($chk) > 0;
        mysqli_stmt_close($chk);

        if ($exists) {
            $stmt = mysqli_prepare($conn,
                "UPDATE attendance SET status = ?
                 WHERE student_id = ? AND date = ?");
            mysqli_stmt_bind_param($stmt, 'sis', $status, $sid, $today);
        } else {
            $stmt = mysqli_prepare($conn,
                "INSERT INTO attendance (student_id, date, status)
                 VALUES (?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'iss', $sid, $today, $status);
        }
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    $save_msg  = 'Attendance saved for ' . date('d M Y') . '.';
    $save_type = 'success';
}

// ── LOAD STUDENTS ───────────────────────────────────
$students = [];
$res = mysqli_query($conn,
    "SELECT id, name, student_phone FROM users
     WHERE role = 'student' ORDER BY name ASC");
while ($r = mysqli_fetch_assoc($res)) $students[] = $r;

// Today's existing attendance
$today_att = [];
$stmt3 = mysqli_prepare($conn,
    "SELECT student_id, status FROM attendance WHERE date = ?");
mysqli_stmt_bind_param($stmt3, 's', $today);
mysqli_stmt_execute($stmt3);
$res3 = mysqli_stmt_get_result($stmt3);
while ($r = mysqli_fetch_assoc($res3)) {
    $today_att[(int)$r['student_id']] = $r['status'];
}
mysqli_stmt_close($stmt3);

$total_students = count($students);
$marked_count   = count($today_att);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mark Attendance — HMS</title>
    <link rel="stylesheet" href="../css/style.css?v=2">
    <style>
        .att-toggle { display:flex; gap:4px; background:var(--panel-alt);
                       padding:4px; border-radius:8px; }
        .att-toggle label { padding:6px 14px; border-radius:6px;
                             cursor:pointer; font-size:0.8rem;
                             font-weight:700; transition:0.2s; }
        .att-toggle input { display:none; }
        .att-toggle label.present:has(input:checked) {
            background:var(--success); color:#fff; }
        .att-toggle label.absent:has(input:checked) {
            background:var(--danger-strong); color:#fff; }
    </style>
</head>
<body>
<?php echo render_sidebar('student_attendance.php'); ?>
<div class="container">
    <div class="page-header">
        <h1>Mark Attendance</h1>
        <p>Marking for <strong><?= date('l, d M Y') ?></strong></p>
    </div>

    <?php if ($save_msg): ?>
        <div class="alert alert-<?= $save_type ?>">
            <?= e($save_msg) ?>
        </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="stats-grid">
        <div class="stat-box">
            <div class="stat-label">Total</div>
            <div class="stat-value"><?= $total_students ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Marked</div>
            <div class="stat-value" style="color: var(--brand-dark);">
                <?= $marked_count ?>
            </div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Remaining</div>
            <div class="stat-value" style="color:#64748b;">
                <?= max(0, $total_students - $marked_count) ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Student Attendance List</h2>
            <div style="display:flex; gap:0.75rem;">
                <button type="button" class="btn btn-secondary"
                        onclick="markAll('present')"
                        style="font-size:0.75rem;">
                    Mark All Present
                </button>
                    <button type="button" class="btn btn-secondary"
                            onclick="markAll('absent')"
                        style="font-size:0.75rem; color:var(--danger-strong);">
                    Mark All Absent
                </button>
            </div>
        </div>

        <form method="post" id="att-form">
            <input type="hidden" name="csrf_token"
                   value="<?= csrf_token() ?>">
            <!-- Used by AJAX calls -->
            <input type="hidden" id="csrf-val"
                   value="<?= csrf_token() ?>">

            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student Name</th>
                        <th>Phone</th>
                        <th>Attendance</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($students as $i => $s):
                    $sid     = (int)$s['id'];
                    $current = $today_att[$sid] ?? '';
                ?>
                    <tr>
                        <td style="color:var(--text-muted);">
                            <?= $i + 1 ?>
                        </td>
                        <td style="font-weight:700;">
                            <?= e($s['name']) ?>
                        </td>
                        <td style="color:var(--text-muted);">
                            <?= e($s['student_phone'] ?? '—') ?>
                        </td>
                        <td>
                            <div class="att-toggle">
                                <label class="present">
                                    <input type="radio"
                                           name="attendance[<?= $sid ?>]"
                                           value="present"
                                           data-sid="<?= $sid ?>"
                                           class="att-radio"
                                           <?= $current === 'present'
                                               ? 'checked' : '' ?>>
                                    Present
                                </label>
                                <label class="absent">
                                    <input type="radio"
                                           name="attendance[<?= $sid ?>]"
                                           value="absent"
                                           data-sid="<?= $sid ?>"
                                           class="att-radio"
                                           <?= $current === 'absent'
                                               ? 'checked' : '' ?>>
                                    Absent
                                </label>
                            </div>
                        </td>
                        <td id="save-<?= $sid ?>">
                            <?php if ($current): ?>
                                <span style="color:var(--success);
                                              font-size:0.8rem;
                                              font-weight:700;">
                                    ✓ Saved
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div style="margin-top:2rem; display:flex;
                         align-items:center; gap:1.5rem;">
                <button type="submit" class="btn btn-primary">
                    Save All Attendance
                </button>
                <p style="font-size:0.8rem; color:var(--text-muted);">
                    Changes are also auto-saved as you click.
                </p>
            </div>
        </form>
    </div>
</div>

<script>
// AJAX auto-save when radio clicked
document.querySelectorAll('.att-radio').forEach(function(radio) {
    radio.addEventListener('change', function() {
        var sid   = this.getAttribute('data-sid');
        var status = this.value;
        var csrf  = document.getElementById('csrf-val').value;
        var statusEl = document.getElementById('save-' + sid);

        statusEl.innerHTML = '<span style="color:var(--muted);">Saving...</span>';

        fetch('student_attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'ajax=1&student_id=' + sid +
                  '&status=' + status +
                  '&csrf_token=' + encodeURIComponent(csrf)
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            statusEl.innerHTML = d.success
                ? '<span style="color:var(--success);font-size:0.8rem;font-weight:700;">✓ Saved</span>'
                : '<span style="color:var(--danger-strong);font-size:0.8rem;">✗ Error</span>';
        })
        .catch(function() {
            statusEl.innerHTML = '<span style="color:var(--danger-strong);font-size:0.8rem;">✗ Error</span>';
        });
    });
});

// Mark all present or absent
function markAll(status) {
    if (!confirm('Mark all students as ' + status + '?')) return;
    document.querySelectorAll('.att-radio[value="' + status + '"]')
        .forEach(function(r) {
            if (!r.checked) {
                r.checked = true;
                r.dispatchEvent(new Event('change'));
            }
        });
}
</script>
</body>
</html>
