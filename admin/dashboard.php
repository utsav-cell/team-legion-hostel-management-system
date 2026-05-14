<?php
// admin/dashboard.php — Admin control panel dashboard
require_once '../db.php';
require_role('admin');

$auth = get_auth();

// ── Stats ─────────────────────────────────────────
$tickets_open        = (int)$pdo->query("SELECT COUNT(*) FROM help_tickets WHERE status='open'")->fetchColumn();
$tickets_in_progress = (int)$pdo->query("SELECT COUNT(*) FROM help_tickets WHERE status='in_progress'")->fetchColumn();
$tickets_resolved_today = (int)$pdo->query("SELECT COUNT(*) FROM help_tickets WHERE status='resolved' AND DATE(resolved_at)=CURDATE()")->fetchColumn();

// Users by role
$role_counts = [];
$rc = $pdo->query("SELECT role, COUNT(*) AS cnt FROM users GROUP BY role ORDER BY cnt DESC");
foreach ($rc->fetchAll() as $r) {
    $role_counts[$r['role']] = (int)$r['cnt'];
}
$total_users = array_sum($role_counts);

// Recent help tickets (last 10)
$recent_tickets = $pdo->query(
    "SELECT ht.id, ht.subject, ht.status, ht.created_at, ht.role AS submitter_role,
            u.name AS user_name, u.email AS user_email
     FROM help_tickets ht
     JOIN users u ON u.id = ht.user_id
     ORDER BY ht.created_at DESC
     LIMIT 10"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HMS — Admin Dashboard</title>
    <link rel="stylesheet" href="../css/style.css?v=34">
</head>
<body>
<?= render_sidebar('dashboard.php') ?>
<?= render_topbar('Dashboard') ?>

<div class="app-shell">
    <main class="shell-main">

        <!-- Page header -->
        <div class="page-header">
            <h1>Admin Dashboard</h1>
        </div>

        <!-- Ticket stats -->
        <div class="stats-grid" style="margin-bottom:1.75rem;">
            <div class="stat-box stat-amber">
                <div class="stat-icon-bubble">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2z"/></svg>
                </div>
                <div class="stat-label">Open Tickets</div>
                <div class="stat-value"><?= $tickets_open ?></div>
                <div class="stat-meta">Awaiting action</div>
            </div>
            <div class="stat-box stat-indigo">
                <div class="stat-icon-bubble">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
                <div class="stat-label">In Progress</div>
                <div class="stat-value"><?= $tickets_in_progress ?></div>
                <div class="stat-meta">Being reviewed</div>
            </div>
            <div class="stat-box stat-green">
                <div class="stat-icon-bubble">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <div class="stat-label">Resolved Today</div>
                <div class="stat-value"><?= $tickets_resolved_today ?></div>
                <div class="stat-meta"><?= date('d M Y') ?></div>
            </div>
            <div class="stat-box stat-indigo">
                <div class="stat-icon-bubble">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
                <div class="stat-label">Total Users</div>
                <div class="stat-value"><?= $total_users ?></div>
                <div class="stat-meta">All roles</div>
            </div>
        </div>

        <!-- Recent help tickets -->
        <div class="card">
            <div class="card-header">
                <div>
                    <div class="card-title">Recent Help Tickets</div>
                    <div class="card-subtitle">Latest 10 support requests across all roles</div>
                </div>
                <a href="resolve_tickets.php" class="btn btn-primary" style="flex-shrink:0;">View All</a>
            </div>
            <?php if (empty($recent_tickets)): ?>
                <p style="color:var(--muted);font-size:0.875rem;">No help tickets submitted yet.</p>
            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Subject</th>
                            <th>Submitted By</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_tickets as $t): ?>
                        <tr>
                            <td style="color:var(--muted);font-size:0.8rem;">#<?= $t['id'] ?></td>
                            <td style="font-weight:600;max-width:240px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($t['subject']) ?></td>
                            <td>
                                <div style="font-weight:600;font-size:0.875rem;"><?= e($t['user_name']) ?></div>
                                <div style="font-size:0.78rem;color:var(--muted);"><?= e($t['user_email']) ?></div>
                            </td>
                            <td>
                                <?php
                                $roleCls = match($t['submitter_role']) {
                                    'admin'   => 'badge-blue',
                                    'warden'  => 'badge-orange',
                                    'owner'   => 'badge-green',
                                    default   => 'badge-pending'
                                };
                                ?>
                                <span class="badge <?= $roleCls ?>"><?= e(ucfirst($t['submitter_role'])) ?></span>
                            </td>
                            <td>
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
                                ?>
                                <span class="badge <?= $sCls ?>"><?= $sLabel ?></span>
                            </td>
                            <td style="color:var(--muted);font-size:0.82rem;"><?= date('d M Y', strtotime($t['created_at'])) ?></td>
                            <td>
                                <a href="resolve_tickets.php?id=<?= $t['id'] ?>" class="btn btn-secondary" style="padding:0.3rem 0.75rem;min-height:auto;font-size:0.78rem;">Manage</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- Right panel -->
    <aside class="right-panel">

        <!-- Profile card -->
        <div class="rp-card">
            <div class="rp-card-title">Your Profile</div>
            <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.85rem;">
                <?php
                $parts    = array_filter(explode(' ', trim($auth['name'])));
                $initials = strtoupper(substr($parts[0] ?? 'A', 0, 1)) . strtoupper(substr($parts[1] ?? '', 0, 1));
                $photo    = $auth['photo'] ?? 'default.png';
                $photo_url = ($photo !== 'default.png') ? '../uploads/students/' . rawurlencode($photo) : null;
                ?>
                <div class="rp-avatar">
                    <?php if ($photo_url): ?>
                        <img src="<?= $photo_url ?>" alt="<?= e($auth['name']) ?>" onerror="this.parentNode.textContent='<?= e($initials) ?>'">
                    <?php else: ?><?= e($initials) ?><?php endif; ?>
                </div>
                <div>
                    <div style="font-weight:700;font-size:0.9rem;"><?= e($auth['name']) ?></div>
                    <div style="font-size:0.78rem;color:var(--muted);">Administrator</div>
                </div>
            </div>
            <hr class="rp-divider">
            <a href="resolve_tickets.php" class="rp-link-row">
                Help Tickets
                <span class="rp-link-count<?= $tickets_open > 0 ? ' amber' : '' ?>"><?= $tickets_open + $tickets_in_progress ?></span>
            </a>
            <a href="manage_users.php" class="rp-link-row">
                Manage Users
                <span class="rp-link-count"><?= $total_users ?></span>
            </a>
            <a href="../auth/change_password.php" class="rp-link-row">
                Change Password
                <span style="color:var(--muted);font-size:0.78rem;">&rsaquo;</span>
            </a>
        </div>

        <!-- Users by role -->
        <div class="rp-card">
            <div class="rp-card-title">Users by Role</div>
            <?php foreach (['student','warden','owner','admin'] as $role): ?>
            <?php $cnt = $role_counts[$role] ?? 0; ?>
            <div style="margin-bottom:0.65rem;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.25rem;">
                    <span style="font-size:0.82rem;font-weight:600;text-transform:capitalize;"><?= $role ?></span>
                    <span style="font-size:0.78rem;color:var(--muted);"><?= $cnt ?></span>
                </div>
                <div class="progress-track">
                    <div class="progress-bar" style="width:<?= $total_users > 0 ? round($cnt / $total_users * 100) : 0 ?>%;background:var(--primary);"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Mini calendar placeholder -->
        <div class="rp-card">
            <div class="rp-card-title">Today</div>
            <div style="text-align:center;padding:0.5rem 0;">
                <div style="font-size:2.4rem;font-weight:800;letter-spacing:-0.04em;color:var(--primary);"><?= date('d') ?></div>
                <div style="font-size:0.85rem;font-weight:600;color:var(--text);"><?= date('F Y') ?></div>
                <div style="font-size:0.78rem;color:var(--muted);margin-top:0.25rem;"><?= date('l') ?></div>
            </div>
        </div>

    </aside>
</div>

<?= render_footer() ?>

<script>
(function(){
    const toggle = document.querySelector('.sidebar-toggle');
    const sidebar = document.querySelector('.sidebar');
    if (toggle && sidebar) {
        toggle.addEventListener('click', () => sidebar.classList.toggle('open'));
    }
})();
</script>
</body>
</html>
