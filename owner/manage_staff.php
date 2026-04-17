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

$active = 'manage_staff.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Management — Owner Central</title>
    <link rel="stylesheet" href="../css/style.css?v=2">
    <style>
        .staff-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-top: 2rem; }
        .staff-card { background: white; padding: 2rem; border-radius: 20px; box-shadow: var(--shadow-sm); border: 1px solid var(--border); transition: transform 0.2s; }
        .staff-card:hover { transform: translateY(-5px); }
        .staff-role { font-size: 0.75rem; font-weight: 800; color: var(--brand); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 0.5rem; }
        .staff-name { font-size: 1.25rem; font-weight: 900; color: var(--text); margin-bottom: 1rem; }
        .allocation-badge { display: inline-block; padding: 6px 12px; border-radius: 99px; font-size: 0.75rem; font-weight: 700; background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
    </style>
</head>
<body>
<?php echo render_sidebar($active); ?>
<div class="container staff-page">
    <div class="page-header" style="background: #fff; padding: 3rem; border-radius: var(--radius-lg); margin-bottom: 2rem; border: 1px solid var(--border); box-shadow: var(--shadow-sm);">
        <h1 style="color: var(--text); font-weight: 900; letter-spacing: -0.05em;">Staff & Resource Allocation</h1>
        <p style="color: var(--text-muted); font-weight: 600;">Manage deployments for cleaners, cooks, and support staff</p>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>

    <!-- ADD STAFF PANEL -->
    <div class="card" style="margin-bottom: 3rem;">
        <h2 style="font-size: 1.25rem; font-weight: 800; margin-bottom: 1.5rem;">Add New Staff Member</h2>
        <form method="post" style="display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 1rem; align-items: end;">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div>
                <label style="display:block; font-size:0.875rem; font-weight:700; margin-bottom:0.5rem;">Full Name</label>
                <input type="text" name="name" required placeholder="e.g. Ram Bahadur" style="padding: 0.8rem 1rem; border-radius: 12px; font-size: 0.9rem;">
            </div>
            <div>
                <label style="display:block; font-size:0.875rem; font-weight:700; margin-bottom:0.5rem;">Position / Role</label>
                <input type="text" name="role" required placeholder="e.g. Cleaner, Guard" style="padding: 0.8rem 1rem; border-radius: 12px; font-size: 0.9rem;">
            </div>
            <div>
                <label style="display:block; font-size:0.875rem; font-weight:700; margin-bottom:0.5rem;">Initial Allocation</label>
                <select name="allocation" required style="padding: 0.8rem 1rem; border-radius: 12px; font-size: 0.9rem;">
                    <option value="Room">Room Area</option>
                    <option value="Canteen">Canteen</option>
                    <option value="Toilets">Toilets</option>
                    <option value="Garden">Garden</option>
                    <option value="General">Main Gate / General</option>
                </select>
            </div>
            <button type="submit" name="add_staff" class="btn btn-success btn-medium">
                <span class="btn-icon">+</span>
                <span>Add Staff</span>
            </button>
        </form>
    </div>

    <div class="staff-grid">
        <?php foreach ($staff_list as $s): ?>
            <div class="staff-card">
                <div class="staff-role"><?= e($s['role']) ?></div>
                <div class="staff-name"><?= e($s['name']) ?></div>
                <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 2rem;">
                    <span style="font-size: 0.875rem; color: var(--text-muted);">Currently at:</span>
                    <span class="allocation-badge"><?= e($s['allocation']) ?> Area</span>
                </div>
                
                <form method="post" style="border-top: 1px solid var(--border); pt: 1.5rem; margin-top: 1rem; padding-top: 1.5rem;">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="staff_id" value="<?= $s['id'] ?>">
                    <div class="staff-relocate-row">
                        <select name="new_area" class="form-control staff-select">
                            <option value="Room" <?= $s['allocation'] == 'Room' ? 'selected' : '' ?>>Room Area</option>
                            <option value="Canteen" <?= $s['allocation'] == 'Canteen' ? 'selected' : '' ?>>Canteen</option>
                            <option value="Toilets" <?= $s['allocation'] == 'Toilets' ? 'selected' : '' ?>>Toilets</option>
                            <option value="Garden" <?= $s['allocation'] == 'Garden' ? 'selected' : '' ?>>Garden</option>
                            <option value="General" <?= $s['allocation'] == 'General' ? 'selected' : '' ?>>Main Gate / General</option>
                        </select>
                        <button type="submit" name="relocate_staff" class="btn btn-primary btn-medium">Relocate</button>
                    </div>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
</div>
</body>
</html>
