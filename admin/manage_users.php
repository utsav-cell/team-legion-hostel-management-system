<?php
// admin/manage_users.php — User management for admin
require_once '../db.php';
require_role('admin');

$auth     = get_auth();
$admin_id = (int)$auth['id'];

$success_msg = '';
$error_msg   = '';

// ── POST handlers ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $action  = $_POST['action'] ?? '';
    $user_id = (int)($_POST['user_id'] ?? 0);

    if ($user_id < 1) {
        $error_msg = 'Invalid user.';
    } elseif ($user_id === $admin_id) {
        $error_msg = 'You cannot modify your own account from this panel.';
    } elseif ($action === 'set_role') {
        $new_role = $_POST['new_role'] ?? '';
        $allowed  = ['student', 'warden', 'owner', 'admin'];
        if (!in_array($new_role, $allowed, true)) {
            $error_msg = 'Invalid role selected.';
        } else {
            $stmt = $pdo->prepare("UPDATE users SET role=? WHERE id=?");
            $stmt->execute([$new_role, $user_id]);
            // Bump token version to force re-login with new role
            revoke_user_tokens($user_id);
            $success_msg = 'Role updated. The user will need to log in again.';
        }

    } elseif ($action === 'toggle_access') {
        // Toggle is_verified: 0 = blocked, 1 = active
        $stmt = $pdo->prepare("UPDATE users SET is_verified = 1 - is_verified WHERE id=?");
        $stmt->execute([$user_id]);
        // Bump token version to immediately kick the user if blocking them
        revoke_user_tokens($user_id);
        $row = $pdo->prepare("SELECT is_verified FROM users WHERE id=?");
        $row->execute([$user_id]);
        $row = $row->fetch();
        $success_msg = ($row && $row['is_verified']) ? 'User access restored.' : 'User access revoked. They will be logged out.';
    }
}

// ── Search + paginated list ──────────────────────────────
$search   = trim($_GET['q'] ?? '');
$per_page = 20;
$page     = max(1,(int)($_GET['page']??1));

$where  = $search !== '' ? "WHERE (name LIKE ? OR email LIKE ? OR role LIKE ?)" : "";
$params = $search !== '' ? ["%$search%","%$search%","%$search%"] : [];

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM users $where");
$count_stmt->execute($params);
$total_users = (int)$count_stmt->fetchColumn();
$total_pages = max(1,(int)ceil($total_users/$per_page));
$page = min($page,$total_pages);

$sql = "SELECT id, name, email, role, is_verified, created_at, student_phone, photo FROM users $where
        ORDER BY FIELD(role,'admin','owner','warden','student'), name ASC
        LIMIT $per_page OFFSET ".(($page-1)*$per_page);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HMS — Manage Users</title>
    <link rel="stylesheet" href="../css/style.css?v=34">
    <style>
        .page-header h1 { font-size: 1.5rem; font-weight: 800; letter-spacing: -0.02em; color: var(--text); margin: 0; }
        .page-sub-line { font-size: 0.875rem; color: var(--muted); margin: 0.25rem 0 1.5rem; }

        .search-wrap { position: relative; max-width: 380px; margin-bottom: 1rem; }
        .search-wrap svg { position: absolute; left: 0.8rem; top: 50%; transform: translateY(-50%); width: 1rem; height: 1rem; color: var(--muted); pointer-events: none; }
        .search-wrap input { padding-left: 2.4rem; }

        .card.users-card { padding: 0; }
        .card.users-card .card-header { padding: 1.1rem 1.3rem 0.9rem; margin: 0; border-bottom: 1px solid var(--border); }
        .card.users-card .table-wrap { overflow-x: auto; }

        /* Avatar: matches topbar-avatar style (soft primary tint) */
        .user-cell { display: flex; align-items: center; gap: 0.7rem; min-width: 0; }
        .user-avatar {
            width: 34px; height: 34px;
            border-radius: 999px;
            background: var(--primary-soft);
            color: var(--primary);
            font-weight: 700;
            font-size: 0.72rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            overflow: hidden;
            letter-spacing: 0.02em;
        }
        .user-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 999px; }
        .user-name { font-weight: 700; color: var(--text); font-size: 0.9rem; line-height: 1.25; }
        .user-meta-you { font-size: 0.7rem; font-weight: 700; color: var(--primary); margin-top: 2px; }

        .role-sel { border: 1px solid var(--border); border-radius: 8px; padding: 0.35rem 0.6rem; font-size: 0.82rem; background: var(--panel); cursor: pointer; height: 32px; width: auto; font-weight: 600; }
        .user-blocked td:not(:last-child) { opacity: 0.55; }
        .inline-form { display: inline; }

        .empty-row td { text-align: center; padding: 2.5rem 1rem !important; }
        .empty-illustration { display: inline-flex; flex-direction: column; align-items: center; gap: 0.5rem; color: var(--muted); }
        .empty-illustration svg { width: 38px; height: 38px; stroke: var(--muted); fill: none; stroke-width: 1.75; opacity: 0.6; }
        .empty-illustration .et { font-size: 0.9rem; font-weight: 700; color: var(--text); }

        .users-footer { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.85rem; }
        .users-footer .count-text { color: var(--muted); font-size: 0.82rem; font-weight: 600; }
        .users-footer .count-text strong { color: var(--text); font-weight: 800; }

        .pager { display: flex; gap: 0.3rem; flex-wrap: wrap; }
        .pager a, .pager .page-current, .pager .page-dots {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 32px; height: 32px; padding: 0 0.6rem;
            border-radius: 8px; font-size: 0.8rem; font-weight: 700;
        }
        .pager a { background: var(--panel); border: 1px solid var(--border); color: var(--text); text-decoration: none; transition: all 0.12s ease; }
        .pager a:hover { border-color: var(--primary); color: var(--primary); }
        .pager .page-current { background: var(--primary); color: #fff; }
        .pager .page-dots { color: var(--muted); }
    </style>
</head>
<body>
<?= render_sidebar('manage_users.php') ?>
<?= render_topbar('Manage Users') ?>

<div class="container">
    <div id="toast-container"></div>

    <?php if ($success_msg): ?>
    <div class="alert alert-success" data-auto-toast="1"><?= e($success_msg) ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
    <div class="alert alert-error" data-auto-toast="1"><?= e($error_msg) ?></div>
    <?php endif; ?>

    <div class="page-header">
        <h1>Manage Users</h1>
    </div>
    <p class="page-sub-line">Search, change roles, and manage access for everyone on the platform.</p>

    <div class="card users-card">
        <div class="card-header" style="flex-direction:column;align-items:stretch;">
            <div>
                <div class="card-title">All Users</div>
                <div class="card-subtitle"><?= $total_users ?> total<?= $search ? ' &mdash; filtered by "'.e($search).'"' : '' ?></div>
            </div>
            <div class="search-wrap" style="margin-top:0.9rem;margin-bottom:0;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="student-search" class="form-input" placeholder="Search by name, email or role…"
                       value="<?= e($search) ?>"
                       oninput="liveSearch(this.value)">
            </div>
        </div>
        <div class="table-wrap">
            <table id="users-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                    <tr class="empty-row">
                        <td colspan="8">
                            <div class="empty-illustration">
                                <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                <div class="et">No users match your search</div>
                                <div>Try a different name, email or role.</div>
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($users as $u): ?>
                    <?php
                    $isMe = ((int)$u['id'] === $admin_id);
                    $isBlocked = !(int)$u['is_verified'];
                    $roleCls = match($u['role']) {
                        'admin'  => 'badge-blue',
                        'owner'  => 'badge-green',
                        'warden' => 'badge-orange',
                        default  => 'badge-pending'
                    };

                    // Build avatar: real photo if present, else initials
                    $parts = array_filter(explode(' ', trim($u['name'])));
                    $initials = strtoupper(substr($parts[0] ?? 'U', 0, 1))
                              . strtoupper(substr($parts[1] ?? '', 0, 1));
                    $photo = $u['photo'] ?? 'default.png';
                    $photo_url = ($photo && $photo !== 'default.png')
                        ? '../uploads/students/' . rawurlencode($photo)
                        : null;
                    ?>
                    <tr class="<?= $isBlocked ? 'user-blocked' : '' ?>">
                        <td style="color:var(--muted);font-size:0.8rem;font-weight:600;"><?= $u['id'] ?></td>
                        <td>
                            <div class="user-cell">
                                <div class="user-avatar">
                                    <?php if ($photo_url): ?>
                                        <img src="<?= e($photo_url) ?>" alt="<?= e($u['name']) ?>"
                                             onerror="this.parentNode.textContent='<?= e($initials) ?>'">
                                    <?php else: ?>
                                        <?= e($initials) ?>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div class="user-name"><?= e($u['name']) ?></div>
                                    <?php if ($isMe): ?>
                                        <div class="user-meta-you">You</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td style="font-size:0.875rem;"><?= e($u['email']) ?></td>
                        <td style="color:var(--muted);font-size:0.83rem;"><?= e($u['student_phone'] ?: '—') ?></td>
                        <td>
                            <span class="badge <?= $roleCls ?>"><?= e(ucfirst($u['role'])) ?></span>
                        </td>
                        <td>
                            <?php if ($isBlocked): ?>
                                <span class="badge badge-red">Blocked</span>
                            <?php else: ?>
                                <span class="badge badge-green">Active</span>
                            <?php endif; ?>
                        </td>
                        <td style="color:var(--muted);font-size:0.82rem;"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
                        <td>
                            <?php if ($isMe): ?>
                                <span style="color:var(--muted);font-size:0.78rem;font-style:italic;">—</span>
                            <?php else: ?>
                            <div style="display:flex;gap:0.4rem;flex-wrap:wrap;align-items:center;">
                                <!-- View profile -->
                                <a href="../auth/profile.php?id=<?= $u['id'] ?>"
                                   class="btn btn-secondary"
                                   style="font-size:0.78rem;min-height:30px;padding:0.3rem 0.85rem;"
                                   title="View profile">View</a>

                                <!-- Change role -->
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="action" value="set_role">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <select name="new_role" class="role-sel" onchange="this.form.submit()" title="Change role">
                                        <?php foreach (['student','warden','owner','admin'] as $r): ?>
                                        <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>

                                <!-- Toggle access -->
                                <form method="post" class="inline-form"
                                      data-confirm="<?= $isBlocked ? 'Restore access for ' : 'Revoke access for ' ?><?= htmlspecialchars($u['name'], ENT_QUOTES) ?>?">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="action" value="toggle_access">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <?php if ($isBlocked): ?>
                                        <button type="submit" class="btn btn-success" style="font-size:0.78rem;min-height:30px;padding:0.3rem 0.85rem;">Restore</button>
                                    <?php else: ?>
                                        <button type="submit" class="btn btn-danger" style="font-size:0.78rem;min-height:30px;padding:0.3rem 0.85rem;">Block</button>
                                    <?php endif; ?>
                                </form>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="users-footer">
        <span class="count-text">
            <strong><?= $total_users ?></strong> user<?= $total_users!==1?'s':'' ?> found<?= $search?' for "'.e($search).'"':'' ?>
        </span>
        <?php if($total_pages>1): ?>
        <div class="pager">
            <?php if($page>1): ?>
                <a href="?q=<?=urlencode($search)?>&page=<?=$page-1?>">&lsaquo;</a>
            <?php endif; ?>
            <?php for($p=1;$p<=$total_pages;$p++):
                if($p===$page): ?>
                    <span class="page-current"><?=$p?></span>
                <?php elseif($p===1||$p===$total_pages||abs($p-$page)<=1): ?>
                    <a href="?q=<?=urlencode($search)?>&page=<?=$p?>"><?=$p?></a>
                <?php elseif(abs($p-$page)===2): ?>
                    <span class="page-dots">...</span>
                <?php endif;
            endfor; ?>
            <?php if($page<$total_pages): ?>
                <a href="?q=<?=urlencode($search)?>&page=<?=$page+1?>">&rsaquo;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?= render_footer() ?>

<script src="../js/script.js"></script>
<script>
function liveSearch(q) {
    const val = q.toLowerCase();
    document.querySelectorAll('#users-table tbody tr').forEach(function(row) {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(val) ? '' : 'none';
    });
}

(function(){
    // Auto-toast alerts
    const tc = document.getElementById('toast-container');
    document.querySelectorAll('[data-auto-toast]').forEach(function(el) {
        const t = document.createElement('div');
        t.className = el.className.replace('alert','') + ' alert toast';
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
</body>
</html>
