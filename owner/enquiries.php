<?php
require_once '../db.php';
require_role('owner');

$error = '';

// Mark as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $id = (int)$_POST['enquiry_id'];
    mysqli_query($conn, "UPDATE enquiries SET status = 'read' WHERE id = $id");
    header("Location: enquiries.php"); exit;
}

// Send Reply
if (isset($_POST['send_reply'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $id = (int)$_POST['enquiry_id'];
    $reply_msg = trim($_POST['reply_message'] ?? '');
    $to_email = trim($_POST['to_email'] ?? '');

    if (!$reply_msg) {
        $error = 'Reply message is required.';
    } else {
        mysqli_query($conn, "UPDATE enquiries SET status = 'replied' WHERE id = $id");
        $success = $to_email ? "Reply recorded for $to_email." : 'Reply recorded.';
    }
}

// Delete enquiry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $id = (int)$_POST['enquiry_id'];
    mysqli_query($conn, "DELETE FROM enquiries WHERE id = $id");
    header("Location: enquiries.php"); exit;
}

$res = mysqli_query($conn, "SELECT * FROM enquiries ORDER BY created_at DESC");
$enquiries = []; while($e = mysqli_fetch_assoc($res)) $enquiries[] = $e;

$active = 'enquiries.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enquiries — Owner Dashboard</title>
    <link rel="stylesheet" href="../css/style.css?v=2">
    <style>
        .enquiry-card { background: #fff; padding: 2.5rem; border-radius: 20px; border-left: 8px solid var(--brand); margin-bottom: 2rem; box-shadow: var(--shadow); position: relative; }
        .enquiry-card.read { border-left-color: #e5e7eb; opacity: 0.9; }
        .enquiry-card.replied { border-left-color: #10b981; }
        .enquiry-meta { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1.5rem; }
        .enquiry-body { font-size: 1.1rem; color: var(--text); line-height: 1.6; margin-bottom: 2rem; }
        .reply-form { background: var(--brand-bg); padding: 2rem; border-radius: 12px; margin-top: 2rem; display: none; }
    </style>
</head>
<body>
<?php echo render_sidebar($active); ?>
<div class="container">
    <div class="page-header">
        <h1>Public Enquiries</h1>
        <p>Direct communication with prospective students and parents</p>
    </div>

    <?php if (!empty($success)): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <?php if (empty($enquiries)): ?>
        <div class="card" style="text-align: center; padding: 6rem;">
            <p style="color: var(--text-muted);">Your inbox is currently clear.</p>
        </div>
    <?php else: ?>
        <?php foreach ($enquiries as $e): ?>
            <div class="enquiry-card <?= $e['status'] ?>" id="enq-<?= $e['id'] ?>">
                <div class="enquiry-meta">
                    <strong><?= e($e['name']) ?></strong> &bull; <?= e($e['email']) ?> &bull; 
                    <span style="color: var(--brand); font-weight: 700;"><?= date('M d, Y', strtotime($e['created_at'])) ?></span>
                    <span class="badge" style="float: right;"><?= strtoupper($e['status']) ?></span>
                </div>
                <div class="enquiry-body">
                    <?= nl2br(e($e['message'])) ?>
                </div>
                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                    <?php if ($e['status'] !== 'replied'): ?>
                        <button onclick="toggleReply(<?= $e['id'] ?>)" class="btn btn-primary" style="padding: 0.5rem 1.5rem; font-size: 0.85rem;">Compose Reply</button>
                    <?php endif; ?>
                    <?php if ($e['status'] === 'unread'): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="enquiry_id" value="<?= (int)$e['id'] ?>">
                            <button type="submit" name="mark_read" class="btn" style="background:#f3f4f6; padding: 0.5rem 1.5rem; font-size: 0.85rem;">Mark Read</button>
                        </form>
                    <?php endif; ?>
                    <form method="post" onsubmit="return confirm('Delete permanently?')">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="enquiry_id" value="<?= (int)$e['id'] ?>">
                        <button type="submit" name="delete" class="btn" style="background:none; border:0; color:var(--danger); font-size:0.85rem;">Remove</button>
                    </form>
                </div>

                <form method="post" class="reply-form" id="reply-form-<?= $e['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="enquiry_id" value="<?= $e['id'] ?>">
                    <input type="hidden" name="to_email" value="<?= e($e['email']) ?>">
                    <h4 style="margin-bottom: 1rem;">Reply to <?= e($e['name']) ?></h4>
                    <textarea name="reply_message" required placeholder="Type your response here..." style="min-height: 120px; margin-bottom: 1rem; border: 1px solid #ddd;"></textarea>
                    <div style="display: flex; gap: 1rem;">
                        <button type="submit" name="send_reply" class="btn btn-primary" style="padding: 0.5rem 2rem;">Save Reply</button>
                        <button type="button" onclick="toggleReply(<?= $e['id'] ?>)" class="btn" style="background:none; border:1px solid #ccc;">Cancel</button>
                    </div>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<script>
function toggleReply(id) {
    const f = document.getElementById('reply-form-' + id);
    f.style.display = (f.style.display === 'block') ? 'none' : 'block';
}
</script>
</body>
</html>
