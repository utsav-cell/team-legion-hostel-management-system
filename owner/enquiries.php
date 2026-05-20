<?php
require_once '../db.php';
require_once '../auth/mailer.php';
require_role('owner');

$error = '';

// Mark as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $id = (int)$_POST['enquiry_id'];
    $pdo->prepare("UPDATE enquiries SET status = 'read' WHERE id = ?")->execute([$id]);
    header("Location: enquiries.php"); exit;
}

// ── DEBUG: log every POST that arrives so we can see what's happening ─────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    @file_put_contents(
        __DIR__ . '/../logs/enquiry_debug.log',
        '[' . date('Y-m-d H:i:s') . "] POST keys=" . implode(',', array_keys($_POST))
            . " send_reply=" . (isset($_POST['send_reply']) ? 'yes' : 'no')
            . " enquiry_id=" . ($_POST['enquiry_id'] ?? 'NONE')
            . " reply_len=" . strlen($_POST['reply_message'] ?? '')
            . " csrf_in=" . substr($_POST['csrf_token'] ?? '', 0, 8)
            . " csrf_session=" . substr($_SESSION['csrf'] ?? '', 0, 8)
            . PHP_EOL,
        FILE_APPEND
    );
}

// Send Reply — saves the reply, emails the enquirer, marks status as replied
if (isset($_POST['send_reply'])) {
    // Verify CSRF, but log the failure if it happens (instead of silent die)
    try {
        csrf_verify($_POST['csrf_token'] ?? '');
    } catch (Throwable $csrfEx) {
        @file_put_contents(__DIR__ . '/../logs/enquiry_debug.log',
            '[' . date('Y-m-d H:i:s') . "] CSRF FAIL\n", FILE_APPEND);
        throw $csrfEx;
    }
    $id = (int)$_POST['enquiry_id'];
    $reply_msg = trim($_POST['reply_message'] ?? '');

    if (!$reply_msg) {
        $error = 'Reply message is required.';
    } else {
        // Fetch the enquiry so we have the recipient name + email + original message
        $look = $pdo->prepare("SELECT id, name, email, message FROM enquiries WHERE id = ? LIMIT 1");
        $look->execute([$id]);
        $enq = $look->fetch();

        if (!$enq) {
            $error = 'Enquiry not found.';
        } elseif (empty($enq['email'])) {
            $error = 'This enquiry has no email address — cannot send a reply.';
        } else {
            // Persist the reply text + timestamp + status in one update
            $upd = $pdo->prepare(
                "UPDATE enquiries SET reply = ?, replied_at = NOW(), status = 'replied' WHERE id = ?"
            );
            $upd->execute([$reply_msg, $id]);

            // Build a branded HTML email and send it via PHPMailer/SMTP
            try {
                $body = '<p>Thanks for getting in touch with HMS — here is our reply to your enquiry.</p>'
                      . '<table style="width:100%;border-collapse:collapse;font-size:14px;margin-top:8px;">'
                      .   '<tr><td style="padding:8px 0;color:#64748b;width:130px;vertical-align:top;">Your message</td>'
                      .       '<td style="padding:8px 0;">' . nl2br(htmlspecialchars($enq['message'])) . '</td></tr>'
                      .   '<tr style="background:#f8fafc;"><td style="padding:8px 6px;color:#64748b;vertical-align:top;">Our reply</td>'
                      .       '<td style="padding:8px 6px;font-weight:600;">' . nl2br(htmlspecialchars($reply_msg)) . '</td></tr>'
                      . '</table>';

                $html = render_branded_email([
                    'name'      => $enq['name'],
                    'kicker'    => 'Enquiry reply',
                    'title'     => 'We have replied to your enquiry',
                    'intro'     => 'Hello ' . htmlspecialchars($enq['name']) . ', thanks for reaching out.',
                    'body_html' => $body,
                    'footnote'  => 'Have more questions? Just reply to this email and we will get back to you.',
                    'accent'    => '#2563eb',
                    'accent2'   => '#1d4ed8',
                ]);

                send_app_mail($enq['email'], $enq['name'], 'Reply to your HMS enquiry', $html);
                $success = 'Reply sent to ' . $enq['email'] . '.';
            } catch (Throwable $mailEx) {
                // Reply is saved in DB even if mail fails — show a clear note so owner knows.
                $error = 'Reply saved, but email failed to send: ' . $mailEx->getMessage();
            }
        }
    }
}

// Delete enquiry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $id = (int)$_POST['enquiry_id'];
    $pdo->prepare("DELETE FROM enquiries WHERE id = ?")->execute([$id]);
    header("Location: enquiries.php"); exit;
}

$enquiries = $pdo->query("SELECT * FROM enquiries ORDER BY created_at DESC")->fetchAll();

$active = 'enquiries.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enquiries — Owner Dashboard</title>
    <link rel="stylesheet" href="../css/style.css?v=34">
    <style>
        /* Clean cards — use design-token vars so dark mode flips cleanly */
        .enquiry-card {
            background: var(--panel);
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            padding: 1.25rem 1.4rem;
            margin-bottom: 1rem;
            box-shadow: var(--shadow-soft);
            position: relative;
            transition: box-shadow 0.15s ease, border-color 0.15s ease;
        }
        .enquiry-card:hover { box-shadow: var(--shadow); }
        .enquiry-card.read { opacity: 0.78; }
        .enquiry-card.replied { border-color: rgba(16,185,129,0.3); }
        html[data-theme="dark"] .enquiry-card.replied { border-color: rgba(52,211,153,0.32); }

        .enquiry-meta {
            display: flex; align-items: center; justify-content: space-between;
            font-size: 0.82rem; color: var(--muted);
            margin-bottom: 0.75rem; flex-wrap: wrap; gap: 0.5rem;
        }
        .enquiry-meta strong { color: var(--text); font-weight: 700; }
        .enquiry-meta .enq-date { color: var(--primary); font-weight: 600; }
        html[data-theme="dark"] .enquiry-meta .enq-date { color: #818cf8; }

        .enquiry-body {
            font-size: 0.9rem; color: var(--text);
            line-height: 1.65; margin-bottom: 1rem;
        }
        .enquiry-actions {
            display: flex; gap: 0.5rem; justify-content: flex-end;
            align-items: center; flex-wrap: wrap;
            padding-top: 0.75rem; border-top: 1px solid var(--border);
        }
        .reply-form {
            background: var(--panel-alt); border: 1px solid var(--border);
            padding: 1.25rem; border-radius: 8px;
            margin-top: 1rem; display: none;
        }
        html[data-theme="dark"] .reply-form { background: rgba(255,255,255,0.04); }

        .saved-reply {
            background: rgba(16,185,129,0.08);
            border: 1px solid rgba(16,185,129,0.22);
            padding: 0.875rem 1rem; border-radius: 8px;
            margin-top: 0.75rem; font-size: 0.85rem; line-height: 1.6;
            color: #065f46;
        }
        html[data-theme="dark"] .saved-reply {
            background: rgba(52,211,153,0.1);
            border-color: rgba(52,211,153,0.28);
            color: #6ee7b7;
        }
        .saved-reply .reply-label {
            font-size: 0.7rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.06em; color: var(--success); margin-bottom: 0.4rem;
        }
        html[data-theme="dark"] .saved-reply .reply-label { color: #34d399; }
    </style>
</head>
<body>
<?php echo render_sidebar($active); ?>
<?= render_topbar() ?>
<div class="container">
    <div class="page-header">
        <h1>Public Enquiries</h1>
    </div>

    <?php if (!empty($success)): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <?php if (empty($enquiries)): ?>
        <div class="card" style="text-align: center; padding: 6rem;">
            <p style="color: var(--text-muted);">Your inbox is currently clear.</p>
        </div>
    <?php else: ?>
        <?php foreach ($enquiries as $e):
            $is_chatbot = strtolower($e['source'] ?? '') === 'chatbot';
            $status_badge = match($e['status']) {
                'replied' => 'badge-green', 'read' => 'badge-blue', default => 'badge-pending'
            };
        ?>
            <div class="enquiry-card <?= $e['status'] ?>" id="enq-<?= (int)$e['id'] ?>">
                <!-- Header -->
                <div class="enquiry-meta">
                    <div>
                        <strong><?= e($e['name']) ?></strong>
                        <?php if($e['email']): ?><span style="color:var(--muted);"> &bull; <?= e($e['email']) ?></span><?php endif; ?>
                        <?php if($is_chatbot): ?><span class="badge badge-blue" style="margin-left:0.4rem;font-size:0.65rem;">Chatbot</span><?php endif; ?>
                    </div>
                    <div style="display:flex;align-items:center;gap:0.6rem;">
                        <span class="enq-date"><?= date('d M Y', strtotime($e['created_at'])) ?></span>
                        <span class="badge <?= $status_badge ?>"><?= ucfirst(e($e['status'])) ?></span>
                    </div>
                </div>

                <!-- Message -->
                <div class="enquiry-body"><?= nl2br(e($e['message'])) ?></div>

                <!-- Actions -->
                <div class="enquiry-actions">
                    <button onclick="toggleReply(<?= (int)$e['id'] ?>)" class="btn btn-primary" style="min-height:32px;padding:0.3rem 0.9rem;font-size:0.8rem;">
                        <?= $e['status'] === 'replied' ? 'Send Another Reply' : 'Reply' ?>
                    </button>
                    <?php if ($e['status'] === 'unread'): ?>
                        <form method="post" style="margin:0;">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="enquiry_id" value="<?= (int)$e['id'] ?>">
                            <button type="submit" name="mark_read" class="btn btn-secondary" style="min-height:32px;padding:0.3rem 0.9rem;font-size:0.8rem;">Mark Read</button>
                        </form>
                    <?php endif; ?>
                    <form method="post" style="margin:0 0 0 auto;" data-confirm="Delete this enquiry permanently?">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="enquiry_id" value="<?= (int)$e['id'] ?>">
                        <button type="submit" name="delete" class="btn btn-ghost" style="min-height:32px;padding:0.3rem 0.75rem;font-size:0.8rem;color:var(--muted);">Delete</button>
                    </form>
                </div>

                <!-- Reply form -->
                <form method="post" class="reply-form" id="reply-form-<?= (int)$e['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="enquiry_id" value="<?= (int)$e['id'] ?>">
                    <div style="font-size:0.82rem;color:var(--muted);margin-bottom:0.6rem;">
                        Replying to <strong><?= e($e['name']) ?></strong><?= $e['email'] ? ' at '.e($e['email']) : '' ?>
                    </div>
                    <textarea name="reply_message" required placeholder="Write your reply..." style="width:100%;min-height:100px;padding:0.65rem 0.875rem;border:1px solid rgba(15,23,42,0.14);border-radius:8px;font:inherit;font-size:0.875rem;resize:vertical;outline:none;margin-bottom:0.6rem;"></textarea>
                    <div style="display:flex;gap:0.5rem;">
                        <button type="submit" name="send_reply" class="btn btn-primary" style="min-height:32px;font-size:0.82rem;" <?= empty($e['email']) ? 'disabled' : '' ?>>Send Reply</button>
                        <button type="button" onclick="toggleReply(<?= (int)$e['id'] ?>)" class="btn btn-secondary" style="min-height:32px;font-size:0.82rem;">Cancel</button>
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
