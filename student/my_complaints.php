<?php
// ─────────────────────────────────────────────────
// student/my_complaints.php — Submit & View Complaints
// ─────────────────────────────────────────────────

require_once '../db.php';
require_role('student');

$uid     = (int)$_SESSION['user_id'];
$name    = $_SESSION['user_name'];
$error   = $success = '';

// Handle complaint submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');

    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (!$subject || !$message) {
        $error = 'Subject and message are required.';
    } else {
        $stmt = mysqli_prepare($conn,
            "INSERT INTO complaints
             (student_id, subject, message, status, created_at)
             VALUES (?, ?, ?, 'open', NOW())");
        mysqli_stmt_bind_param($stmt, 'iss', $uid, $subject, $message);
        if (mysqli_stmt_execute($stmt)) {
            $success = 'Complaint submitted successfully.';
        } else {
            $error = 'Failed to submit. Please try again.';
        }
        mysqli_stmt_close($stmt);
    }
}

// Fetch this student's complaints — prepared statement
$complaints = [];
$stmt2 = mysqli_prepare($conn,
    "SELECT subject, message, status, reply, created_at
     FROM complaints WHERE student_id = ?
     ORDER BY created_at DESC");
mysqli_stmt_bind_param($stmt2, 'i', $uid);
mysqli_stmt_execute($stmt2);
$res = mysqli_stmt_get_result($stmt2);
while ($r = mysqli_fetch_assoc($res)) $complaints[] = $r;
mysqli_stmt_close($stmt2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Complaints — HMS</title>
    <link rel="stylesheet" href="../css/style.css?v=2">
</head>
<body>
<?php echo render_sidebar('my_complaints.php'); ?>
<div class="container">
    <div class="page-header">
        <h1>Complaints</h1>
        <p>Report issues and track your requests</p>
    </div>

    <?php if ($error):  ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success):?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

    <!-- Submit Form -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Submit a New Complaint</h2>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token"
                   value="<?= csrf_token() ?>">
            <div class="form-group">
                <label for="subject">Subject</label>
                <input id="subject" name="subject" type="text"
                       placeholder="e.g. Broken fan, Water issue..."
                       required>
            </div>
            <div class="form-group">
                <label for="message">Detailed Message</label>
                <textarea id="message" name="message" rows="4"
                          placeholder="Describe the issue in detail..."
                          required
                          style="width:100%; padding:0.875rem;
                                 border:1px solid var(--border);
                                 border-radius:10px; font-size:0.9rem;
                                 font-family:inherit;"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">
                Submit Complaint
            </button>
        </form>
    </div>

    <!-- Complaints History -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">My Complaints</h2>
        </div>
        <?php if ($complaints): ?>
            <table>
                <thead>
                    <tr>
                        <th>Subject</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Warden Reply</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($complaints as $c): ?>
                    <tr>
                        <td style="font-weight:600;">
                            <?= e($c['subject']) ?>
                        </td>
                        <td style="color:var(--text-muted);">
                            <?= e(date('d M Y',
                                strtotime($c['created_at']))) ?>
                        </td>
                        <td>
                            <span class="badge badge-<?= $c['status'] === 'open'
                                ? 'red' : 'green' ?>">
                                <?= e(ucfirst($c['status'])) ?>
                            </span>
                        </td>
                        <td style="color:var(--text-muted);">
                            <?= e($c['reply'] ?: '— No reply yet —') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color:var(--text-muted); text-align:center; padding:2rem;">
                No complaints submitted yet.
            </p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
