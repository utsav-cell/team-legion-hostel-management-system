<?php
require_once '../dp.php';
require_role('owner');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_fee'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $id = (int)$_POST['student_id'];
    $u = mysqli_fetch_assoc(mysqli_query($conn, "SELECT fee_status FROM users WHERE id = $id"));
    $new_status = ($u['fee_status'] == 'paid') ? 'unpaid' : 'paid';
    mysqli_query($conn, "UPDATE users SET fee_status = '$new_status' WHERE id = $id");
    header("Location: student_fees.php"); exit;
}

$res = mysqli_query($conn, "SELECT u.id, u.name, u.email, u.fee_status, r.room_number FROM users u LEFT JOIN rooms r ON u.room_id = r.id WHERE u.role = 'student' ORDER BY u.name ASC");
$students = []; while($s = mysqli_fetch_assoc($res)) $students[] = $s;

$active = 'student_fees.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fee Tracking — Owner Dashboard</title>
    <link rel="stylesheet" href="../css/style.css?v=2">
    <style>
        .fee-paid { color: #16a34a; font-weight: 700; }
        .fee-unpaid { color: var(--danger); font-weight: 700; }
        .toggle-btn { padding: 4px 10px; font-size: 0.75rem; border-radius: 4px; border: 1px solid #e2e8f0; cursor: pointer; background: #fff; transition: 0.2s; }
        .toggle-btn:hover { background: #f8fafc; }
    </style>
</head>
<body>
<?php echo render_sidebar($active); ?>
<div class="container">
    <div class="page-header">
        <h1>Student Fee Tracking</h1>
        <p>Monitor and update the payment status of all hostel residents</p>
    </div>

    <div class="card">
        <table class="table">
            <thead>
                <tr>
                    <th>Student Name</th>
                    <th>Email</th>
                    <th>Room</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($students as $s): ?>
                    <tr>
                        <td><strong><?= e($s['name']) ?></strong></td>
                        <td><?= e($s['email']) ?></td>
                        <td><?= $s['room_number'] ? 'Room '.e($s['room_number']) : '<span style="color:#94a3b8">Unassigned</span>' ?></td>
                        <td>
                            <span class="<?= $s['fee_status'] == 'paid' ? 'fee-paid' : 'fee-unpaid' ?>">
                                <?= strtoupper($s['fee_status']) ?>
                            </span>
                        </td>
                        <td>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
                                <button type="submit" name="toggle_fee" class="toggle-btn">
                                    Mark as <?= $s['fee_status'] == 'paid' ? 'Unpaid' : 'Paid' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
