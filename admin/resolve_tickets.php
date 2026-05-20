<?php
// admin/resolve_tickets.php — Help ticket management for admin
require_once '../db.php';
require_once '../auth/mailer.php';
require_role('admin');

$auth    = get_auth();
$admin_id = (int)$auth['id'];

$success_msg = '';
$error_msg   = '';

// ── POST handlers ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $action    = $_POST['action'] ?? '';
    $ticket_id = (int)($_POST['ticket_id'] ?? 0);

    if ($ticket_id < 1) {
        $error_msg = 'Invalid ticket.';
    } elseif ($action === 'in_progress') {
        $stmt = $pdo->prepare("UPDATE help_tickets SET status='in_progress', assigned_to=? WHERE id=? AND status='open'");
        $stmt->execute([$admin_id, $ticket_id]);
        $success_msg = 'Ticket marked as in progress.';

    } elseif ($action === 'resolve') {
        $admin_note = trim($_POST['admin_note'] ?? '');
        if (!$admin_note) {
            $error_msg = 'Please enter a resolution note before resolving.';
        } else {
            $stmt = $pdo->prepare(
                "UPDATE help_tickets
                 SET status='resolved', admin_note=?, resolved_by=?, resolved_at=NOW()
                 WHERE id=? AND status IN ('open','in_progress')"
            );
            $stmt->execute([$admin_note, $admin_id, $ticket_id]);

            // Fetch ticket + user info to send email
            $tkt = $pdo->prepare("SELECT ht.*, u.name, u.email FROM help_tickets ht JOIN users u ON u.id=ht.user_id WHERE ht.id=?");
            $tkt->execute([$ticket_id]);
            $tkt = $tkt->fetch();
            if ($tkt) {
                try {
                    $body = '<p>We\'ve gone through your ticket and added a reply below.</p>'
                          . '<table style="width:100%;border-collapse:collapse;font-size:14px;">'
                          . '<tr><td style="padding:8px 0;color:#64748b;width:120px;">Subject</td><td style="padding:8px 0;font-weight:700;">' . htmlspecialchars($tkt['subject']) . '</td></tr>'
                          . '<tr style="background:#f8fafc;"><td style="padding:8px 6px;color:#64748b;">Our reply</td><td style="padding:8px 6px;">' . nl2br(htmlspecialchars($admin_note)) . '</td></tr>'
                          . '</table>';
                    $html = render_branded_email([
                        'name'      => $tkt['name'],
                        'kicker'    => 'Support ticket',
                        'title'     => 'Your ticket is resolved',
                        'intro'     => 'Thanks for getting in touch — here\'s what we found.',
                        'body_html' => $body,
                        'footnote'  => 'Still need help? Just open a new ticket from your dashboard and we\'ll take another look.',
                        'accent'    => '#10b981',
                        'accent2'   => '#059669',
                    ]);
                    send_app_mail($tkt['email'], $tkt['name'], 'Your support ticket is resolved', $html);
                } catch (Exception $e) {
                    // Email failure is non-fatal
                }
            }
            $success_msg = 'Ticket resolved and notification sent.';
        }
    }
}

// ── Filter ─────────────────────────────────────────
$filter = $_GET['filter'] ?? 'all';
$allowed_filters = ['all', 'open', 'in_progress', 'resolved'];
if (!in_array($filter, $allowed_filters, true)) $filter = 'all';

$where = $filter === 'all' ? '' : "WHERE ht.status = " . $pdo->quote($filter);
$tickets = $pdo->query(
    "SELECT ht.id, ht.subject, ht.description, ht.status, ht.admin_note,
            ht.created_at, ht.resolved_at, ht.role AS submitter_role,
            u.name AS user_name, u.email AS user_email
     FROM help_tickets ht
     JOIN users u ON u.id = ht.user_id
     $where
     ORDER BY FIELD(ht.status,'open','in_progress','resolved'), ht.created_at DESC"
)->fetchAll();

// Counts per status for tab badges
$counts = [];
foreach (['open','in_progress','resolved'] as $s) {
    $counts[$s] = (int)$pdo->query("SELECT COUNT(*) FROM help_tickets WHERE status=" . $pdo->quote($s))->fetchColumn();
}
$counts['all'] = array_sum($counts);

// Focus ticket from URL (clicking "Manage" on dashboard)
$focus_id = (int)($_GET['id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HMS — Help Tickets</title>
    <link rel="stylesheet" href="../css/style.css?v=34">
    <style>
        .filter-tabs { display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:1.5rem; }
        .filter-tab {
            padding:0.45rem 1.1rem; border-radius:999px; font-size:0.82rem; font-weight:600;
            border:1px solid var(--border); background:var(--panel); color:var(--muted);
            text-decoration:none; transition:all 0.15s;
        }
        .filter-tab:hover { background:var(--primary-soft); color:var(--primary); border-color:transparent; }
        .filter-tab.active { background:var(--primary); color:#fff; border-color:transparent; }
        .filter-tab .cnt { font-size:0.73rem; background:rgba(255,255,255,0.25); border-radius:999px; padding:0 5px; margin-left:4px; }
        .filter-tab:not(.active) .cnt { background:var(--panel-alt); color:var(--muted); }

        .ticket-row { border:1px solid var(--border); border-radius:10px; padding:1rem 1.25rem; margin-bottom:0.75rem; background:var(--panel); transition:box-shadow 0.15s; }
        .ticket-row:hover { box-shadow:var(--shadow); }
        .ticket-row.focused { border-color:var(--primary); box-shadow:0 0 0 3px rgba(99,102,241,0.12); }
        .ticket-row-top { display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; flex-wrap:wrap; }
        .ticket-subject { font-weight:700; font-size:0.95rem; margin-bottom:0.2rem; }
        .ticket-meta { font-size:0.8rem; color:var(--muted); display:flex; gap:0.75rem; flex-wrap:wrap; margin-top:0.3rem; }
        .ticket-desc { font-size:0.875rem; color:var(--muted); margin:0.6rem 0 0; border-top:1px solid var(--border); padding-top:0.6rem; }
        .ticket-note { font-size:0.85rem; background:var(--success-soft); color:#065f46; border-radius:8px; padding:0.6rem 0.9rem; margin-top:0.65rem; }
        .resolve-form { margin-top:0.75rem; border-top:1px solid var(--border); padding-top:0.75rem; display:none; }
        .resolve-form.open { display:block; }
        .ticket-actions { display:flex; gap:0.4rem; flex-shrink:0; flex-wrap:wrap; align-items:center; }
    </style>
</head>
<body>
<?= render_sidebar('resolve_tickets.php') ?>
<?= render_topbar('Help Tickets') ?>

<div class="container">
    <div id="toast-container"></div>

    <?php if ($success_msg): ?>
    <div class="alert alert-success" data-auto-toast="1"><?= e($success_msg) ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
    <div class="alert alert-error" data-auto-toast="1"><?= e($error_msg) ?></div>
    <?php endif; ?>

    <div class="page-header">
        <h1>Help Tickets</h1>
    </div>

    <!-- Filter tabs -->
    <div class="filter-tabs">
        <?php
        $labels = ['all' => 'All', 'open' => 'Open', 'in_progress' => 'In Progress', 'resolved' => 'Resolved'];
        foreach ($labels as $key => $label):
        ?>
        <a href="?filter=<?= $key ?>" class="filter-tab <?= $filter === $key ? 'active' : '' ?>">
            <?= $label ?><span class="cnt"><?= $counts[$key] ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($tickets)): ?>
        <div class="card" style="text-align:center;padding:2.5rem;">
            <p style="color:var(--muted);">No tickets found for this filter.</p>
        </div>
    <?php else: ?>
        <?php foreach ($tickets as $t): ?>
        <?php
        $sCls = match($t['status']) {
            'open'        => 'badge-pending',
            'in_progress' => 'badge-blue',
            'resolved'    => 'badge-green',
            default       => ''
        };
        $sLabel = match($t['status']) {
            'open'        => 'Open',
            'in_progress' => 'In Progress',
            'resolved'    => 'Resolved',
            default       => e($t['status'])
        };
        $roleCls = match($t['submitter_role']) {
            'admin'  => 'badge-blue',
            'warden' => 'badge-orange',
            'owner'  => 'badge-green',
            default  => 'badge-pending'
        };
        $isFocused = ($focus_id === (int)$t['id']);
        ?>
        <div class="ticket-row <?= $isFocused ? 'focused' : '' ?>" id="ticket-<?= $t['id'] ?>">
            <div class="ticket-row-top">
                <div style="min-width:0;flex:1;">
                    <div class="ticket-subject"><?= e($t['subject']) ?></div>
                    <div class="ticket-meta">
                        <span><?= e($t['user_name']) ?> &lt;<?= e($t['user_email']) ?>&gt;</span>
                        <span class="badge <?= $roleCls ?>"><?= e(ucfirst($t['submitter_role'])) ?></span>
                        <span><?= date('d M Y, h:i A', strtotime($t['created_at'])) ?></span>
                        <?php if ($t['resolved_at']): ?>
                            <span style="color:var(--success);">Resolved <?= date('d M Y', strtotime($t['resolved_at'])) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="ticket-actions">
                    <span class="badge <?= $sCls ?>"><?= $sLabel ?></span>
                    <?php if ($t['status'] === 'open'): ?>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                            <input type="hidden" name="action" value="in_progress">
                            <button type="submit" class="btn btn-secondary" style="font-size:0.8rem;min-height:32px;padding:0.35rem 0.9rem;">Mark In Progress</button>
                        </form>
                        <button type="button" class="btn btn-primary" style="font-size:0.8rem;min-height:32px;padding:0.35rem 0.9rem;"
                                onclick="toggleResolveForm(<?= $t['id'] ?>)">Resolve</button>
                    <?php elseif ($t['status'] === 'in_progress'): ?>
                        <button type="button" class="btn btn-success" style="font-size:0.8rem;min-height:32px;padding:0.35rem 0.9rem;"
                                onclick="toggleResolveForm(<?= $t['id'] ?>)">Resolve</button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="ticket-desc"><?= nl2br(e($t['description'])) ?></div>

            <?php if ($t['admin_note'] && $t['status'] === 'resolved'): ?>
                <div class="ticket-note">
                    <strong>Admin note:</strong> <?= nl2br(e($t['admin_note'])) ?>
                </div>
            <?php endif; ?>

            <?php if (in_array($t['status'], ['open','in_progress'])): ?>
            <div class="resolve-form <?= ($isFocused ? 'open' : '') ?>" id="resolve-form-<?= $t['id'] ?>">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                    <input type="hidden" name="action" value="resolve">
                    <div class="form-group" style="margin-bottom:0.6rem;">
                        <label>Resolution Note <span style="color:var(--danger);">*</span></label>
                        <textarea name="admin_note" rows="3" placeholder="Describe what was done or provide instructions..." required minlength="5"><?= e($_POST['admin_note'] ?? '') ?></textarea>
                    </div>
                    <div style="display:flex;gap:0.5rem;">
                        <button type="submit" class="btn btn-success" style="font-size:0.85rem;">Mark as Resolved &amp; Notify Student</button>
                        <button type="button" class="btn btn-secondary" style="font-size:0.85rem;"
                                onclick="toggleResolveForm(<?= $t['id'] ?>)">Cancel</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?= render_footer() ?>

<script>
function toggleResolveForm(id) {
    const form = document.getElementById('resolve-form-' + id);
    if (form) form.classList.toggle('open');
}

(function(){
    // Scroll to focused ticket
    const focus = document.querySelector('.ticket-row.focused');
    if (focus) setTimeout(() => focus.scrollIntoView({behavior:'smooth', block:'center'}), 200);

    // Auto-toast alerts
    const tc = document.getElementById('toast-container');
    document.querySelectorAll('[data-auto-toast]').forEach(function(el) {
        const t = document.createElement('div');
        t.className = el.className + ' toast';
        t.innerHTML = el.textContent + '<button class="toast-close" onclick="this.parentNode.remove()">&times;</button>';
        t.style.display = 'block';
        tc.appendChild(t);
        el.remove();
        setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 400); }, 4500);
    });

    // Sidebar toggle
    const toggle = document.querySelector('.sidebar-toggle');
    const sidebar = document.querySelector('.sidebar');
    if (toggle && sidebar) toggle.addEventListener('click', () => sidebar.classList.toggle('open'));
})();
</script>
<script src="../js/script.js"></script>
</body>
</html>
