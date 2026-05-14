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
    <link rel="stylesheet" href="../css/style.css?v=34">
    <style>
        /* Aligned with the rest of the app: white panels, indigo accent,
           soft border, project-standard shadow + radius. */
        .page-title {
            font-size: 1.5rem; font-weight: 800;
            letter-spacing: -0.02em; color: var(--text);
            margin: 0 0 1.5rem;
        }

        /* ── Add staff card ── matches the booking_requests .br-card style */
        .add-staff-card {
            background: var(--panel);
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-soft);
            padding: 1.25rem 1.4rem 1.4rem;
            margin-bottom: 1.75rem;
        }
        .add-staff-head { margin-bottom: 1.1rem; }
        .add-staff-head h2 {
            font-size: 0.98rem; font-weight: 700;
            color: var(--text); margin: 0; letter-spacing: -0.01em;
        }
        .add-staff-head .ash-sub {
            font-size: 0.8rem; color: var(--muted); margin-top: 0.2rem;
        }
        .add-staff-head .ash-icon { display: none; }

        .add-staff-form {
            display: grid;
            grid-template-columns: 1.3fr 1fr 1fr auto;
            gap: 0.95rem; align-items: end;
        }
        @media (max-width: 900px) { .add-staff-form { grid-template-columns: 1fr 1fr; } .add-staff-form button { grid-column: 1 / -1; } }
        @media (max-width: 540px) { .add-staff-form { grid-template-columns: 1fr; } }
        .add-staff-form label {
            display: block;
            font-size: 0.78rem; font-weight: 700;
            color: var(--text); margin-bottom: 0.4rem;
            letter-spacing: -0.005em;
        }
        .add-staff-form input,
        .add-staff-form select {
            width: 100%; padding: 0.7rem 0.9rem;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            font-size: 0.88rem; font-weight: 500;
            background: var(--panel); color: var(--text);
            font-family: inherit;
            transition: border-color .18s ease, box-shadow .18s ease;
        }
        .add-staff-form input::placeholder { color: rgba(100,116,139,0.7); font-weight: 400; }
        .add-staff-form input:hover,
        .add-staff-form select:hover { border-color: rgba(99,102,241,0.35); }
        .add-staff-form input:focus,
        .add-staff-form select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99,102,241,0.18);
        }
        html[data-theme="dark"] .add-staff-form input,
        html[data-theme="dark"] .add-staff-form select {
            background: #232a42; color: #f1f5f9;
            border-color: rgba(148,163,184,0.28);
        }
        .add-staff-form .add-btn {
            display: inline-flex; align-items: center; gap: .4rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-strong));
            color: #fff; border: none; cursor: pointer;
            padding: 0.7rem 1.25rem;
            border-radius: 10px;
            font-size: 0.88rem; font-weight: 700;
            box-shadow: 0 8px 20px -10px rgba(79,70,229,0.55);
            transition: transform .15s ease, box-shadow .2s ease, filter .15s ease;
        }
        .add-staff-form .add-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 24px -10px rgba(79,70,229,0.7);
            filter: brightness(1.04);
        }
        .add-staff-form .add-btn svg {
            display: inline-block; width: 14px; height: 14px;
            stroke: currentColor; fill: none; stroke-width: 2.4;
        }

        /* ── Staff section header ── */
        .staff-section-head {
            display: flex; align-items: baseline;
            justify-content: space-between;
            margin-bottom: 1rem;
            flex-wrap: wrap; gap: 0.5rem;
        }
        .staff-section-head h2 {
            font-size: 1.05rem; font-weight: 800;
            color: var(--text); margin: 0; letter-spacing: -0.01em;
        }
        .staff-count {
            font-size: 0.78rem; font-weight: 600; color: var(--muted);
        }
        .staff-count strong { color: var(--text); font-weight: 800; }

        /* ── Staff cards ── matches .booking-card / .br-card style ── */
        .staff-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
            gap: 1.1rem;
        }
        .staff-card {
            background: var(--panel);
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-soft);
            overflow: hidden;
            transition: transform .18s ease, box-shadow .2s ease, border-color .2s ease;
        }
        .staff-card:hover {
            border-color: rgba(99,102,241,0.35);
            box-shadow: 0 10px 28px -14px rgba(15,23,42,0.16);
        }

        .sc-header {
            display: flex; align-items: center; gap: .85rem;
            padding: 1.1rem 1.2rem .9rem;
        }
        .sc-avatar {
            width: 42px; height: 42px;
            border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            color: #fff; font-weight: 700; font-size: 0.88rem;
            flex-shrink: 0;
            background: linear-gradient(135deg, var(--primary), var(--primary-strong));
            box-shadow: 0 2px 6px rgba(99,102,241,0.25);
            letter-spacing: 0.02em;
        }
        .sc-name-wrap { flex: 1; min-width: 0; }
        .sc-name {
            font-size: 1rem; font-weight: 700;
            color: var(--text); letter-spacing: -0.01em;
            line-height: 1.2;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }

        .sc-body {
            padding: 0.25rem 1.2rem 1.2rem;
            border-top: 1px dashed var(--border);
            margin-top: 0;
        }
        .sc-area {
            display: flex; align-items: center; gap: 0.55rem;
            margin: 1rem 0; font-size: 0.82rem; flex-wrap: wrap;
        }
        .sc-area-label {
            font-size: 0.7rem;
            color: var(--muted); font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.06em;
        }
        .sc-area-pill {
            display: inline-flex; align-items: center; gap: 0.4rem;
            padding: 0.3rem 0.75rem;
            background: var(--primary-soft);
            color: var(--primary);
            border: 1px solid rgba(99,102,241,0.22);
            border-radius: 999px;
            font-size: 0.74rem; font-weight: 700;
        }
        .sc-area-pill::before {
            content: ''; width: 6px; height: 6px;
            border-radius: 50%; background: currentColor;
        }
        html[data-theme="dark"] .sc-area-pill {
            background: rgba(99,102,241,0.18);
            border-color: rgba(129,140,248,0.32);
            color: #a5b4fc;
        }

        .sc-relocate {
            display: flex; gap: 0.5rem;
            padding-top: 0.6rem;
            border-top: 1px dashed var(--border);
        }
        .sc-relocate select {
            flex: 1; min-width: 0;
            padding: 0.55rem 0.8rem;
            font-size: 0.84rem; font-weight: 500;
            font-family: inherit;
            border: 1.5px solid var(--border);
            border-radius: 9px;
            background: var(--panel);
            color: var(--text);
            cursor: pointer;
            transition: border-color .15s ease, box-shadow .15s ease;
        }
        .sc-relocate select:hover { border-color: rgba(99,102,241,0.35); }
        .sc-relocate select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99,102,241,0.18);
        }
        html[data-theme="dark"] .sc-relocate select {
            background: #232a42; color: #f1f5f9;
            border-color: rgba(148,163,184,0.28);
        }
        .sc-relocate .btn-relocate {
            display: inline-flex; align-items: center; gap: 0.35rem;
            padding: 0.55rem 1.05rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-strong));
            color: #fff; border: none;
            border-radius: 9px;
            font-size: 0.82rem; font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            white-space: nowrap;
            box-shadow: 0 6px 14px -6px rgba(79,70,229,0.5);
            transition: transform .15s ease, box-shadow .2s ease, filter .15s ease;
        }
        .sc-relocate .btn-relocate:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 18px -6px rgba(79,70,229,0.65);
            filter: brightness(1.05);
        }
        .sc-relocate .btn-relocate svg {
            width: 12px; height: 12px;
            stroke: currentColor; fill: none; stroke-width: 2.5;
        }

        .empty-state-staff {
            text-align: center;
            padding: 3rem 1rem;
            background: var(--panel);
            border: 1.5px dashed var(--border);
            border-radius: var(--radius);
            color: var(--muted);
        }
        .empty-state-staff h3 {
            color: var(--text); font-weight: 700;
            margin: 0.5rem 0 0.25rem; font-size: 1rem;
        }
    </style>
</head>
<body>
<?php echo render_sidebar($active); ?>
<?= render_topbar() ?>
<div class="container">

    <h1 class="page-title">Our staff</h1>

    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

    <!-- ADD STAFF PANEL -->
    <div class="add-staff-card">
        <div class="add-staff-head">
            <div class="ash-icon">
                <svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
            </div>
            <div>
                <h2>Add someone new</h2>
                <div class="ash-sub">Bring a new person onto the team and pick where they'll work.</div>
            </div>
        </div>
        <form method="post" class="add-staff-form">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="add_staff" value="1">
            <div>
                <label>Name</label>
                <input type="text" name="name" required placeholder="Ram Bahadur">
            </div>
            <div>
                <label>What they do</label>
                <input type="text" name="role" required placeholder="Cleaner, Guard, Cook…">
            </div>
            <div>
                <label>Where they'll work</label>
                <select name="allocation" required>
                    <option value="Room">Rooms</option>
                    <option value="Canteen">Canteen</option>
                    <option value="Toilets">Toilets</option>
                    <option value="Garden">Garden</option>
                    <option value="General">Main gate / general</option>
                </select>
            </div>
            <button type="submit" class="add-btn">
                <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add to team
            </button>
        </form>
    </div>

    <!-- STAFF LIST HEADER -->
    <div class="staff-section-head">
        <h2>Who's on the team</h2>
        <span class="staff-count"><strong><?= count($staff_list) ?></strong> <?= count($staff_list) !== 1 ? 'people' : 'person' ?></span>
    </div>

    <?php if (empty($staff_list)): ?>
        <div class="empty-state-staff">
            <h3>No one on the team yet</h3>
            <p>Add your first person using the form above.</p>
        </div>
    <?php else: ?>
    <div class="staff-grid">
        <?php foreach ($staff_list as $s):
            $parts = array_filter(explode(' ', trim($s['name'])));
            $initials = strtoupper(substr($parts[0] ?? 'U', 0, 1))
                      . strtoupper(substr($parts[1] ?? '', 0, 1));
        ?>
            <div class="staff-card">
                <div class="sc-header">
                    <div class="sc-avatar"><?= e($initials) ?></div>
                    <div class="sc-name-wrap">
                        <div class="sc-name"><?= e($s['name']) ?></div>
                    </div>
                </div>

                <div class="sc-body">
                    <div class="sc-area">
                        <span class="sc-area-label">Working at</span>
                        <span class="sc-area-pill"><?= e($s['allocation']) ?></span>
                    </div>

                    <form method="post" class="sc-relocate">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="staff_id" value="<?= $s['id'] ?>">
                        <input type="hidden" name="relocate_staff" value="1">
                        <select name="new_area">
                            <option value="Room"    <?= $s['allocation'] == 'Room'    ? 'selected' : '' ?>>Rooms</option>
                            <option value="Canteen" <?= $s['allocation'] == 'Canteen' ? 'selected' : '' ?>>Canteen</option>
                            <option value="Toilets" <?= $s['allocation'] == 'Toilets' ? 'selected' : '' ?>>Toilets</option>
                            <option value="Garden"  <?= $s['allocation'] == 'Garden'  ? 'selected' : '' ?>>Garden</option>
                            <option value="General" <?= $s['allocation'] == 'General' ? 'selected' : '' ?>>Main gate / general</option>
                        </select>
                        <button type="submit" class="btn-relocate">
                            <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
                            Move
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
