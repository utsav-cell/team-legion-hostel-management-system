<?php
require_once '../db.php';
require_role('student');

$res = mysqli_query($conn, "SELECT * FROM daily_routine ORDER BY id ASC");
$routine = []; while($r = mysqli_fetch_assoc($res)) $routine[] = $r;

$active = 'my_routine.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Routine — Student Dashboard</title>
    <link rel="stylesheet" href="../css/style.css?v=2">
    <style>
        .routine-item { display: flex; align-items: center; padding: 1.25rem; border-bottom: 
        1px solid var(--border); transition: 0.3s; gap: 1.5rem; }
        .routine-item:last-child { border-bottom: none; }
        .routine-time { font-weight: 800; color: var(--brand); min-width: 180px; font-size: 0.95rem; }
        .routine-activity { font-weight: 600; color: var(--text); font-size: 1rem; }
        .routine-item.school { background: var(--warning-soft); border-left: 4px solid var(--warning); }
        .routine-item.hostel { border-left: 4px solid var(--brand); }
    </style>
</head>
<body>
<?php echo render_sidebar($active); ?>
<div class="container">
    <div class="page-header">
        <h1>Official Daily Schedule</h1>
        <p>Your guide to hostel life and academic balance</p>
    </div>

    <div class="card" style="padding: 0;">
        <?php foreach ($routine as $r): ?>
            <div class="routine-item <?= $r['is_school_hours'] ? 'school' : 'hostel' ?>">
                <div class="routine-time"><?= e($r['time_slot']) ?></div>
                <div class="routine-box">
                    <div class="routine-activity"><?= e($r['activity']) ?></div>
                    <span style="font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase;">
                        <?= $r['is_school_hours'] ? 'External Activity' : 'Hostel Program' ?>
                    </span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
</body>
</html>
