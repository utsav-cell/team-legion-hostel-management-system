<?php
require_once '../db.php';
require_role('owner');

$success = '';

if (isset($_POST['relocate_staff'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $id = (int)$_POST['staff_id'];
    $new_area = $_POST['new_area'];

    $stmt = mysqli_prepare($conn, "UPDATE staff SET allocation = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'si', $new_area, $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $success = "Staff member successfully relocated!";
}

if (isset($_POST['add_staff'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $area = trim($_POST['allocation'] ?? '');

    if ($name && $role && $area) {
        $stmt = mysqli_prepare($conn, "INSERT INTO staff (name, role, allocation) VALUES (?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'sss', $name, $role, $area);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $success = "New staff member deployed successfully!";
    }
}

// Fetch all staff
$res = mysqli_query($conn, "SELECT * FROM staff ORDER BY name ASC");
$staff_list = []; while($s = mysqli_fetch_assoc($res)) $staff_list[] = $s;

// Helper: derive a color theme + icon from a staff role keyword
function staff_role_theme(string $role): array {
    $r = strtolower($role);
    if (str_contains($r, 'security') || str_contains($r, 'guard'))    return ['indigo',  'shield'];
    if (str_contains($r, 'clean'))                                    return ['sky',     'spray'];
    if (str_contains($r, 'cook') || str_contains($r, 'chef'))         return ['amber',   'chef'];
    if (str_contains($r, 'garden'))                                   return ['green',   'leaf'];
    if (str_contains($r, 'help') || str_contains($r, 'support'))      return ['pink',    'hand'];
    if (str_contains($r, 'electric') || str_contains($r, 'plumber'))  return ['orange',  'wrench'];
    return ['slate', 'user'];
}

function staff_role_icon(string $key): string {
    switch ($key) {
        case 'shield': return '<svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>';
        case 'spray':  return '<svg viewBox="0 0 24 24"><path d="M9 11h6v9a2 2 0 0 1-2 2h-2a2 2 0 0 1-2-2v-9z"/><path d="M9 11V7a3 3 0 0 1 6 0v4"/><circle cx="17" cy="4" r="1"/><circle cx="20" cy="6" r="1"/><circle cx="19" cy="2" r="1"/></svg>';
        case 'chef':   return '<svg viewBox="0 0 24 24"><path d="M6 14a4 4 0 1 1 1-7.87A4 4 0 0 1 12 4a4 4 0 0 1 5 2.13A4 4 0 1 1 18 14H6z"/><path d="M6 14v5a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2v-5"/></svg>';
        case 'leaf':   return '<svg viewBox="0 0 24 24"><path d="M11 20A7 7 0 0 1 4 13c0-5 5-9 16-9 0 11-4 16-9 16z"/><path d="M2 22c4-4 6-8 8-12"/></svg>';
        case 'hand':   return '<svg viewBox="0 0 24 24"><path d="M18 11V6a2 2 0 0 0-4 0v5"/><path d="M14 10V4a2 2 0 0 0-4 0v6"/><path d="M10 10.5V6a2 2 0 0 0-4 0v8"/><path d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2a8 8 0 0 1-8-8 2 2 0 0 1 4 0"/></svg>';
        case 'wrench': return '<svg viewBox="0 0 24 24"><path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.5 2.5-2.4-2.4 2.5-2.5z"/></svg>';
        default:       return '<svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
    }
}

$active = 'manage_staff.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Management — Owner Central</title>
    <link rel="stylesheet" href="../css/style.css?v=18">
    <style>
        .page-title { font-size: 1.55rem; font-weight: 800; letter-spacing: -0.02em; color: var(--text); margin: 0 0 1.25rem; }

        /* ── Add staff card ── */
        .add-staff-card {
            background: #fff;
            border: 2px solid #cbd5e1;
            border-radius: 10px;
            padding: 1.25rem 1.4rem 1.3rem;
            margin-bottom: 1.5rem;
        }
        .add-staff-head { margin-bottom: 1rem; }
        .add-staff-head h2 { font-size: 1rem; font-weight: 700; color: var(--text); margin: 0; }
        .add-staff-head .ash-sub { font-size: 0.82rem; color: var(--muted); margin-top: 0.15rem; }
        .add-staff-head .ash-icon { display: none; }

        .add-staff-form { display: grid; grid-template-columns: 1.3fr 1fr 1fr auto; gap: 0.85rem; align-items: end; }
        @media (max-width: 900px) { .add-staff-form { grid-template-columns: 1fr 1fr; } .add-staff-form button { grid-column: 1 / -1; } }
        @media (max-width: 540px) { .add-staff-form { grid-template-columns: 1fr; } }
        .add-staff-form label { display: block; font-size: 0.82rem; font-weight: 600; color: var(--text); margin-bottom: 0.3rem; }
        .add-staff-form input, .add-staff-form select {
            width: 100%; padding: 0.55rem 0.75rem;
            border: 2px solid #cbd5e1; border-radius: 6px;
            font-size: 0.88rem; background: #fff; color: var(--text);
        }
        .add-staff-form input:focus, .add-staff-form select:focus {
            outline: none; border-color: var(--primary);
        }
        .add-staff-form .add-btn {
            background: #059669;
            color: #fff; border: none; cursor: pointer;
            padding: 0.55rem 1.1rem;
            border-radius: 6px;
            font-size: 0.88rem; font-weight: 600;
            height: 38px;
        }
        .add-staff-form .add-btn:hover { background: #047857; }
        .add-staff-form .add-btn svg { display: none; }

        /* ── Staff section header ── */
        .staff-section-head { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 0.85rem; flex-wrap: wrap; gap: 0.5rem; }
        .staff-section-head h2 { font-size: 1.05rem; font-weight: 800; color: var(--text); margin: 0; letter-spacing: -0.01em; }
        .staff-count { font-size: 0.78rem; font-weight: 700; color: var(--muted); }
        .staff-count strong { color: var(--text); font-weight: 800; }

        /* ── Staff cards ── */
        .staff-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: 1.15rem; }
        .staff-card {
            background: var(--accent-bg, #eef2ff);
            border: 2.5px solid var(--accent, var(--primary));
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 6px var(--accent-shadow-soft, rgba(99,102,241,0.15));
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }
        .staff-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 28px var(--accent-shadow, rgba(99,102,241,0.3));
        }

        /* Header zone — sits flush on the tinted card background */
        .sc-header {
            padding: 1.15rem 1.25rem 1.1rem;
            position: relative;
        }

        .sc-top { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.55rem; }
        .sc-avatar {
            width: 44px; height: 44px;
            border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            color: #fff; font-weight: 800; font-size: 0.92rem;
            flex-shrink: 0;
            background: var(--accent, var(--primary));
            box-shadow: 0 4px 10px var(--accent-shadow, rgba(99,102,241,0.35)),
                        0 0 0 3px #fff,
                        0 0 0 4px var(--accent-border, rgba(99,102,241,0.2));
            letter-spacing: 0.02em;
        }
        .sc-role-chip {
            display: inline-flex; align-items: center; gap: 0.35rem;
            padding: 0.28rem 0.65rem 0.28rem 0.5rem;
            border-radius: 999px;
            font-size: 0.66rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            background: #fff;
            color: var(--accent, var(--primary));
            box-shadow: 0 1px 3px rgba(15,23,42,0.06);
        }
        .sc-role-chip svg { width: 11px; height: 11px; stroke: currentColor; fill: none; stroke-width: 2.2; flex-shrink: 0; }
        .sc-name { font-size: 1.15rem; font-weight: 800; color: var(--text); letter-spacing: -0.015em; line-height: 1.2; margin-top: 2px; }

        /* Body — same tint as header, no divider */
        .sc-body {
            padding: 0.6rem 1.25rem 1.15rem;
        }

        .sc-area { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.95rem; font-size: 0.82rem; flex-wrap: wrap; }
        .sc-area-label { color: var(--muted); font-weight: 600; }
        .sc-area-pill {
            display: inline-flex; align-items: center; gap: 0.4rem;
            padding: 0.32rem 0.75rem;
            background: var(--accent-soft, rgba(99,102,241,0.1));
            color: var(--accent, var(--primary));
            border: 1px solid var(--accent-border, rgba(99,102,241,0.22));
            border-radius: 999px;
            font-size: 0.74rem;
            font-weight: 700;
        }
        .sc-area-pill::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

        .sc-relocate { display: flex; gap: 0.5rem; padding-top: 0.4rem; }
        .sc-relocate select {
            flex: 1; min-width: 0;
            padding: 0.5rem 0.75rem;
            font-size: 0.83rem;
            font-weight: 600;
            border: 1.5px solid var(--border);
            border-radius: 9px;
            background: #fff;
            color: var(--text);
            cursor: pointer;
            transition: border 0.12s ease, box-shadow 0.12s ease;
        }
        .sc-relocate select:focus, .sc-relocate select:hover { outline: none; border-color: var(--accent, var(--primary)); }
        .sc-relocate select:focus { box-shadow: 0 0 0 3px var(--accent-soft, rgba(99,102,241,0.15)); }
        .sc-relocate .btn-relocate {
            display: inline-flex; align-items: center; gap: 0.3rem;
            padding: 0.5rem 1.05rem;
            background: var(--accent, var(--primary));
            color: #fff;
            border: none;
            border-radius: 9px;
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.12s ease;
            box-shadow: 0 2px 6px var(--accent-shadow, rgba(15,23,42,0.15));
            white-space: nowrap;
        }
        .sc-relocate .btn-relocate:hover { transform: translateY(-1px); filter: brightness(1.06); }
        .sc-relocate .btn-relocate svg { width: 12px; height: 12px; stroke: currentColor; fill: none; stroke-width: 2.5; }

        /* Per-role themes: accent (border + buttons) + soft tint (card bg) + shadow */
        .theme-indigo { --accent: #6366f1; --accent-soft: rgba(99,102,241,0.16); --accent-border: rgba(99,102,241,0.4); --accent-bg: #eef2ff; --accent-shadow: rgba(99,102,241,0.28); --accent-shadow-soft: rgba(99,102,241,0.15); }
        .theme-sky    { --accent: #0284c7; --accent-soft: rgba(14,165,233,0.16); --accent-border: rgba(14,165,233,0.4); --accent-bg: #e0f2fe; --accent-shadow: rgba(14,165,233,0.28); --accent-shadow-soft: rgba(14,165,233,0.15); }
        .theme-amber  { --accent: #d97706; --accent-soft: rgba(245,158,11,0.2);  --accent-border: rgba(245,158,11,0.45); --accent-bg: #fef3c7; --accent-shadow: rgba(245,158,11,0.28); --accent-shadow-soft: rgba(245,158,11,0.15); }
        .theme-green  { --accent: #059669; --accent-soft: rgba(16,185,129,0.16); --accent-border: rgba(16,185,129,0.4); --accent-bg: #d1fae5; --accent-shadow: rgba(16,185,129,0.28); --accent-shadow-soft: rgba(16,185,129,0.15); }
        .theme-pink   { --accent: #db2777; --accent-soft: rgba(236,72,153,0.16); --accent-border: rgba(236,72,153,0.4); --accent-bg: #fce7f3; --accent-shadow: rgba(219,39,119,0.28); --accent-shadow-soft: rgba(219,39,119,0.15); }
        .theme-orange { --accent: #ea580c; --accent-soft: rgba(249,115,22,0.16); --accent-border: rgba(249,115,22,0.4); --accent-bg: #ffedd5; --accent-shadow: rgba(234,88,12,0.28); --accent-shadow-soft: rgba(234,88,12,0.15); }
        .theme-slate  { --accent: #475569; --accent-soft: rgba(100,116,139,0.2); --accent-border: rgba(100,116,139,0.45); --accent-bg: #f1f5f9; --accent-shadow: rgba(71,85,105,0.28); --accent-shadow-soft: rgba(71,85,105,0.15); }

        .empty-state-staff {
            text-align: center;
            padding: 3rem 1rem;
            background: #fff;
            border: 1.5px dashed var(--border);
            border-radius: 14px;
            color: var(--muted);
        }
        .empty-state-staff h3 { color: var(--text); font-weight: 700; margin: 0.5rem 0 0.25rem; font-size: 1rem; }
    </style>
</head>
<body>
<?php echo render_sidebar($active); ?>
<?= render_topbar() ?>
<div class="container">

    <h1 class="page-title">Staff &amp; Resource Allocation</h1>

    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

    <!-- ADD STAFF PANEL -->
    <div class="add-staff-card">
        <div class="add-staff-head">
            <div class="ash-icon">
                <svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
            </div>
            <div>
                <h2>Add New Staff Member</h2>
                <div class="ash-sub">Deploy a new team member to an area.</div>
            </div>
        </div>
        <form method="post" class="add-staff-form">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="add_staff" value="1">
            <div>
                <label>Full Name</label>
                <input type="text" name="name" required placeholder="e.g. Ram Bahadur">
            </div>
            <div>
                <label>Position / Role</label>
                <input type="text" name="role" required placeholder="e.g. Cleaner, Guard">
            </div>
            <div>
                <label>Initial Allocation</label>
                <select name="allocation" required>
                    <option value="Room">Room Area</option>
                    <option value="Canteen">Canteen</option>
                    <option value="Toilets">Toilets</option>
                    <option value="Garden">Garden</option>
                    <option value="General">Main Gate / General</option>
                </select>
            </div>
            <button type="submit" class="add-btn">
                <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add Staff
            </button>
        </form>
    </div>

    <!-- STAFF LIST HEADER -->
    <div class="staff-section-head">
        <h2>Deployed Staff</h2>
        <span class="staff-count"><strong><?= count($staff_list) ?></strong> member<?= count($staff_list) !== 1 ? 's' : '' ?></span>
    </div>

    <?php if (empty($staff_list)): ?>
        <div class="empty-state-staff">
            <h3>No staff members yet</h3>
            <p>Use the form above to add your first team member.</p>
        </div>
    <?php else: ?>
    <div class="staff-grid">
        <?php foreach ($staff_list as $s):
            [$theme, $iconKey] = staff_role_theme($s['role']);
            $parts = array_filter(explode(' ', trim($s['name'])));
            $initials = strtoupper(substr($parts[0] ?? 'U', 0, 1))
                      . strtoupper(substr($parts[1] ?? '', 0, 1));
        ?>
            <div class="staff-card theme-<?= $theme ?>">
                <div class="sc-header">
                    <div class="sc-top">
                        <div class="sc-avatar"><?= e($initials) ?></div>
                        <span class="sc-role-chip">
                            <?= staff_role_icon($iconKey) ?>
                            <?= e($s['role']) ?>
                        </span>
                    </div>
                    <div class="sc-name"><?= e($s['name']) ?></div>
                </div>

                <div class="sc-body">
                    <div class="sc-area">
                        <span class="sc-area-label">Currently at</span>
                        <span class="sc-area-pill"><?= e($s['allocation']) ?> Area</span>
                    </div>

                    <form method="post" class="sc-relocate">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="staff_id" value="<?= $s['id'] ?>">
                        <input type="hidden" name="relocate_staff" value="1">
                        <select name="new_area">
                            <option value="Room"    <?= $s['allocation'] == 'Room'    ? 'selected' : '' ?>>Room Area</option>
                            <option value="Canteen" <?= $s['allocation'] == 'Canteen' ? 'selected' : '' ?>>Canteen</option>
                            <option value="Toilets" <?= $s['allocation'] == 'Toilets' ? 'selected' : '' ?>>Toilets</option>
                            <option value="Garden"  <?= $s['allocation'] == 'Garden'  ? 'selected' : '' ?>>Garden</option>
                            <option value="General" <?= $s['allocation'] == 'General' ? 'selected' : '' ?>>Main Gate / General</option>
                        </select>
                        <button type="submit" class="btn-relocate">
                            <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
                            Relocate
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>
<script src="../js/script.js"></script>
</body>
</html>
