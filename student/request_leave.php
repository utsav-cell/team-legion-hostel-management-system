<?php
require_once '../db.php';
require_role('student');

$auth = get_auth();
$student_id = $auth['id'];
$error = '';

if (isset($_POST['apply_leave'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $reason = trim($_POST['reason']);
    $start = $_POST['start_date'];
    $end = $_POST['end_date'];

    if (!$reason || !$start || !$end) {
        $error = 'Please provide a reason and valid dates.';
    } elseif ($end < $start) {
        $error = 'End date must be the same or after the start date.';
    } else {
        $stmt = mysqli_prepare($conn, "INSERT INTO leaves (student_id, reason, start_date, end_date) VALUES (?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'isss', $student_id, $reason, $start, $end);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $success = "Leave request submitted! It now requires approval from both the Warden and the Owner.";
    }
}

// Fetch existing leaves
$res = mysqli_query($conn, "SELECT * FROM leaves WHERE student_id = $student_id ORDER BY created_at DESC");
$leaves = []; while($l = mysqli_fetch_assoc($res)) $leaves[] = $l;

$active = 'request_leave.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Leave — Student Dashboard</title>
    <link rel="stylesheet" href="../css/style.css?v=2">
</head>
<body>
<?php echo render_sidebar($active); ?>
<div class="container">
    <div class="page-header">
        <h1>Leave Request</h1>
        <p>Submit your reason and dates for leaving the hostel</p>
    </div>

    <?php if (!empty($error)): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <?php if (isset($success)): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

    <div class="card" style="margin-bottom: 2rem;">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div class="grid-2">
                <div class="form-group"><label>Start Date</label><input type="date" name="start_date" required min="<?= date('Y-m-d') ?>"></div>
                <div class="form-group"><label>End Date</label><input type="date" name="end_date" required min="<?= date('Y-m-d') ?>"></div>
            </div>
            <div class="form-group">
                <label>Reason for Leave</label>
                <textarea name="reason" required placeholder="e.g. Visiting home for festival, Family emergency, etc." style="height: 100px;"></textarea>
            </div>
            <button type="submit" name="apply_leave" class="btn btn-primary" style="width: 100%;">Submit Request</button>
        </form>
    </div>

    <div class="card">
        <h3>My Leave History</h3>
        <table class="table" style="margin-top: 1rem;">
            <thead>
                <tr>
                    <th>Dates</th>
                    <th>Reason</th>
                    <th>Warden</th>
                    <th>Owner</th>
                    <th>Final Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($leaves)): ?><tr><td colspan="5">No leave history found.</td></tr><?php endif; ?>
                <?php foreach ($leaves as $l): ?>
                    <tr>
                        <td><strong><?= e($l['start_date']) ?></strong> to <strong><?= e($l['end_date']) ?></strong></td>
                        <td><?= e($l['reason']) ?></td>
                        <td><span class="badge" style="background:#f1f5f9; color:#475569;"><?= ucfirst($l['warden_status']) ?></span></td>
                        <td><span class="badge" style="background:#f1f5f9; color:#475569;"><?= ucfirst($l['owner_status']) ?></span></td>
                        <td>
                            <span class="badge" style="background: <?= $l['final_status'] == 'approved' ? 'var(--success-soft); color:#166534' : ($l['final_status'] == 'rejected' ? 'var(--danger-soft); color:#b91c1c' : 'var(--warning-soft); color:#b45309') ?>;">
                                <?= ucfirst($l['final_status']) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
