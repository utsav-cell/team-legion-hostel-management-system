<?php
require_once '../db.php';
require_role('owner');

// Final Approval (Only if Warden already approved)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $id = (int)$_POST['leave_id'];
    mysqli_query($conn, "UPDATE leaves SET owner_status = 'approved', final_status = 'approved' WHERE id = $id AND warden_status = 'approved'");
    
    // Log Email to Student
    $res = mysqli_query($conn, "SELECT l.*, u.name, u.email FROM leaves l JOIN users u ON l.student_id = u.id WHERE l.id = $id");
    $l = mysqli_fetch_assoc($res);
    if ($l) {
        // Leave approved by owner; no email notification is sent.
    }
    
    header("Location: manage_leaves.php"); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $id = (int)$_POST['leave_id'];
    mysqli_query($conn, "UPDATE leaves SET owner_status = 'rejected', final_status = 'rejected' WHERE id = $id");
    
    $res = mysqli_query($conn, "SELECT l.*, u.name, u.email FROM leaves l JOIN users u ON l.student_id = u.id WHERE l.id = $id");
    $l = mysqli_fetch_assoc($res);
    if ($l) {
        // Leave rejected by owner; no email notification is sent.
    }
    
    header("Location: manage_leaves.php"); exit;
}

$res = mysqli_query($conn, "SELECT l.*, u.name as student_name FROM leaves l JOIN users u ON l.student_id = u.id WHERE l.warden_status = 'approved' AND l.owner_status = 'pending' ORDER BY l.created_at ASC");
$pending = []; while($p = mysqli_fetch_assoc($res)) $pending[] = $p;

$active = 'manage_leaves.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Leaves — Owner Dashboard</title>
    <link rel="stylesheet" href="../css/style.css?v=2">
</head>
<body>
<?php echo render_sidebar($active); ?>
<div class="container">
    <div class="page-header">
        <h1>Final Leave Approval</h1>
        <p>Review leave requests that have already been cleared by the Warden</p>
    </div>

    <?php if (empty($pending)): ?>
        <div class="card" style="text-align: center; padding: 3rem;">
            <p style="color: #64748b;">No leaves awaiting owner approval.</p>
        </div>
    <?php else: ?>
        <?php foreach ($pending as $l): ?>
            <div class="card" style="margin-bottom: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem;">
                    <div>
                        <h3 style="margin:0;"><?= e($l['student_name']) ?></h3>
                        <p style="color: #64748b; font-size: 0.85rem; margin-top: 0.25rem;">Dates: <strong><?= e($l['start_date']) ?></strong> to <strong><?= e($l['end_date']) ?></strong></p>
                    </div>
                    <span class="badge" style="background:var(--success-soft); color:#166534;">Warden Approved</span>
                </div>
                <div style="background: #f1f5f9; padding: 1rem; border-radius: 8px; font-size: 0.95rem; line-height: 1.5; margin-bottom: 1.5rem;">
                    <strong>Reason:</strong><br><?= nl2br(e($l['reason'])) ?>
                </div>
                <div style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="leave_id" value="<?= (int)$l['id'] ?>">
                        <button type="submit" name="approve" class="btn btn-primary" style="background: var(--brand);">Issue Final Permit</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Reject final permit?')">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="leave_id" value="<?= (int)$l['id'] ?>">
                        <button type="submit" name="reject" class="btn" style="color:var(--danger); border:1px solid var(--danger-soft);">Decline</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</body>
</html>
