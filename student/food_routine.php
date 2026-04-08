<?php
session_start();

require_once '../dp.php';
require_role('student');

$name  = $_SESSION['user_name'];
$today = date('Y-m-d');
$menu = [];
$res  = mysqli_query($conn, "SELECT meal_time, menu, menu_date FROM food_routine WHERE menu_date = '$today' ORDER BY FIELD(meal_time,'breakfast','lunch','dinner')");
if (mysqli_num_rows($res) === 0) {
    $res = mysqli_query($conn, "SELECT meal_time, menu, menu_date FROM food_routine ORDER BY menu_date DESC, FIELD(meal_time,'breakfast','lunch','dinner') LIMIT 21");
}
while ($r = mysqli_fetch_assoc($res)) $menu[] = $r;

$role = 'student'; $active = 'food_routine.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Food Routine — HMS</title>
    <link rel="stylesheet" href="../css/style.css?v=2">
</head>
<body>
<?php echo render_sidebar('food_routine.php'); ?>
<div class="container">
    <div class="page-header">
        <h1>Food Routine</h1>
        <p>Menu and meal schedule for today</p>
    </div>

    <div class="card">
        <div class="card-header"><h2 class="card-title">Daily Meal Menu</h2></div>
        <?php if ($menu): ?>
            <table>
                <thead><tr><th>Date</th><th>Meal</th><th>Menu Description</th></tr></thead>
                <tbody>
                <?php foreach ($menu as $m): ?>
                <tr>
                    <td style="color: var(--text-muted); font-weight: 600;"><?= e(date('d M', strtotime($m['menu_date']))) ?></td>
                    <td><span class="badge badge-blue"><?= e(ucfirst($m['meal_time'])) ?></span></td>
                    <td style="font-weight: 500;"><?= e($m['menu']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color: var(--text-muted); text-align: center; padding: 2rem;">No menu data available at the moment.</p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
