<?php
require_once '../dp.php';
require_role('owner');

if (isset($_POST['update_routine'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $stmt = mysqli_prepare($conn, "UPDATE daily_routine SET activity = ? WHERE id = ?");
    foreach ($_POST['activity'] as $id => $activity) {
        $id = (int)$id;
        $activity = trim($activity);
        mysqli_stmt_bind_param($stmt, 'si', $activity, $id);
        mysqli_stmt_execute($stmt);
    }
    mysqli_stmt_close($stmt);
    $success = "Routine updated successfully!";
}

$res = mysqli_query($conn, "SELECT * FROM daily_routine ORDER BY id ASC");
$routine = []; while($r = mysqli_fetch_assoc($res)) $routine[] = $r;

$active = 'manage_routine.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Routine — Owner Dashboard</title>
    <link rel="stylesheet" href="../css/style.css?v=2">
</head>
<body>
<?php echo render_sidebar($active); ?>
<div class="container">
    <div class="page-header">
        <h1>Student Daily Routine</h1>
        <p>Manage the official schedule for all hostel residents</p>
    </div>

    <?php if (isset($success)): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>

    <div class="card">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <table class="table">
                <thead>
                    <tr>
                        <th width="30%">Time Slot</th>
                        <th>Activity / Task</th>
                        <th width="15%">Type</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($routine as $r): ?>
                        <tr>
                            <td style="font-weight: 700; color: var(--brand);"><?= e($r['time_slot']) ?></td>
                            <td>
                                <input type="text" name="activity[<?= $r['id'] ?>]" value="<?= e($r['activity']) ?>" class="form-group" style="margin:0; width:100%;">
                            </td>
                            <td>
                                <span class="badge" style="background: <?= $r['is_school_hours'] ? 'var(--warning-soft); color:#b45309' : 'var(--panel-alt); color:#475569' ?>">
                                    <?= $r['is_school_hours'] ? 'School' : 'Hostel' ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="submit" name="update_routine" class="btn btn-primary" style="margin-top: 1.5rem;">Save Changes</button>
        </form>
    </div>
</div>
</body>
</html>
