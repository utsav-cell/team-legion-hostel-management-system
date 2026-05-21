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
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO complaints
                 (student_id, subject, message, status, created_at)
                 VALUES (?, ?, ?, 'open', NOW())"
            );
            $stmt->execute([$uid, $subject, $message]);
            $success = 'Complaint submitted successfully.';
        } catch (PDOException $e) {
            $error = 'Failed to submit. Please try again.';
        }
    }
}

// Fetch this student's complaints — prepared statement
$stmt2 = $pdo->prepare(
    "SELECT subject, message, status, reply, created_at
     FROM complaints WHERE student_id = ?
     ORDER BY created_at DESC"
);
$stmt2->execute([$uid]);
$complaints = $stmt2->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Complaints — HMS</title>
    <link rel="stylesheet" href="../css/style.css?v=34">
</head>
<body>
<?php echo render_sidebar('my_complaints.php'); ?>
<?= render_topbar() ?>
<div class="container">
    <div class="page-header">
        <h1>Complaints</h1>
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

<?= render_footer() ?>
<script src="../js/script.js"></script>
</body>
</html>
