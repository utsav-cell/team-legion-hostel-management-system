<?php
// ─────────────────────────────────────────────────
// warden/complaints.php — View and Update Complaints
// ─────────────────────────────────────────────────

require_once '../db.php';
require_role('warden');

$name    = $_SESSION['user_name'];
$success = $error = '';

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');

    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $new_status   = $_POST['status'] ?? '';
    $reply        = trim($_POST['reply'] ?? '');

    if (!in_array($new_status, ['open', 'resolved'])) {
        $error = 'Invalid status selected.';
    } elseif ($complaint_id <= 0) {
        $error = 'Invalid complaint.';
    } else {
        $stmt = mysqli_prepare($conn,
            "UPDATE complaints SET status = ?, reply = ?
             WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'ssi',
            $new_status, $reply, $complaint_id);
        if (mysqli_stmt_execute($stmt)) {
            $success = 'Complaint updated successfully.';
        } else {
            $error = 'Failed to update. Please try again.';
        }
        mysqli_stmt_close($stmt);
    }
}

// Fetch all complaints with student name
$complaints = [];
$res = mysqli_query($conn,
    "SELECT c.id, u.name AS student_name, c.subject,
            c.message, c.status, c.reply, c.created_at
     FROM complaints c
     JOIN users u ON u.id = c.student_id
     ORDER BY c.created_at DESC");
while ($r = mysqli_fetch_assoc($res)) $complaints[] = $r;

// Count by status
$total    = count($complaints);
$open     = count(array_filter($complaints, fn($c) => $c['status'] === 'open'));
$resolved = $total - $open;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complaints — HMS</title>
    <link rel="stylesheet" href="../css/style.css?v=2">
</head>
<body>
<?php echo render_sidebar('complaints.php'); ?>
<div class="container">
    <div class="page-header">
        <h1>Student Complaints</h1>
        <p>Review and respond to issues raised by students</p>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <!-- Summary -->
    <div class="stats-grid">
        <div class="stat-box">
            <div class="stat-label">Total</div>
            <div class="stat-value"><?= $total ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Open</div>
            <div class="stat-value" style="color:var(--danger);"><?= $open ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Resolved</div>
            <div class="stat-value" style="color:#10b981;"><?= $resolved ?></div>
        </div>
    </div>

    <!-- Complaints Table -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">All Complaints</h2>
        </div>
        <?php if ($complaints): ?>
            <?php foreach ($complaints as $c): ?>
            <div style="border:1px solid var(--border); border-radius:12px;
                         padding:1.25rem; margin-bottom:1rem;">
                <div style="display:flex; justify-content:space-between;
                             align-items:flex-start; margin-bottom:0.75rem;">
                    <div>
                        <span style="font-weight:800;">
                            <?= e($c['student_name']) ?>
                        </span>
                        <span style="color:var(--text-muted);
                                     font-size:0.8rem; margin-left:0.75rem;">
                            <?= e(date('d M Y', strtotime($c['created_at']))) ?>
                        </span>
                    </div>
                    <span class="badge badge-<?= $c['status'] === 'open'
                        ? 'red' : 'green' ?>">
                        <?= e(ucfirst($c['status'])) ?>
                    </span>
                </div>
                <p style="font-weight:700; margin-bottom:0.5rem;">
                    <?= e($c['subject']) ?>
                </p>
                <p style="color:var(--text-muted); font-size:0.875rem;
                           margin-bottom:1rem;">
                    <?= e($c['message']) ?>
                </p>

                <!-- Update form -->
                <form method="post"
                      style="display:flex; gap:0.75rem; flex-wrap:wrap;
                             align-items:flex-end; border-top:1px solid var(--border);
                             padding-top:0.75rem; margin-top:0.5rem;">
                    <input type="hidden" name="csrf_token"
                           value="<?= csrf_token() ?>">
                    <input type="hidden" name="complaint_id"
                           value="<?= (int)$c['id'] ?>">
                    <div style="flex:1; min-width:150px;">
                        <label style="font-size:0.75rem; font-weight:700;
                                       color:var(--text-muted); display:block;
                                       margin-bottom:4px;">Status</label>
                        <select name="status" style="width:100%; padding:0.5rem;
                                border:1px solid var(--border); border-radius:8px;
                                font-size:0.875rem;">
                            <option value="open"
                                <?= $c['status'] === 'open' ? 'selected' : '' ?>>
                                Open
                            </option>
                            <option value="resolved"
                                <?= $c['status'] === 'resolved' ? 'selected' : '' ?>>
                                Resolved
                            </option>
                        </select>
                    </div>
                    <div style="flex:3; min-width:200px;">
                        <label style="font-size:0.75rem; font-weight:700;
                                       color:var(--text-muted); display:block;
                                       margin-bottom:4px;">Reply to Student</label>
                        <input type="text" name="reply"
                               placeholder="Write a reply..."
                               value="<?= e($c['reply'] ?? '') ?>"
                               style="width:100%; padding:0.5rem;
                                      border:1px solid var(--border);
                                      border-radius:8px; font-size:0.875rem;">
                    </div>
                    <button type="submit" class="btn btn-primary"
                            style="padding:0.5rem 1rem; font-size:0.875rem;">
                        Update
                    </button>
                </form>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="color:var(--text-muted); text-align:center; padding:2rem;">
                No complaints submitted yet.
            </p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
