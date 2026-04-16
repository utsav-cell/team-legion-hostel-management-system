<?php
// ─────────────────────────────────────────────────
// warden/room_requests.php — Room Request Management
// ─────────────────────────────────────────────────

require_once '../db.php';
require_role('warden');

$uid = (int)$_SESSION['user_id'];
$error = $success = '';

// Handle approve/reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify($_POST['csrf_token'] ?? '');
        $action = $_POST['action'] ?? '';
        
        if ($action === 'approve') {
            $student_id = (int)$_POST['student_id'] ?? 0;
            $room_id = (int)$_POST['room_id'] ?? 0;
            
            if (!$student_id || !$room_id) {
                throw new Exception('Invalid student or room.');
            }
            
            // Update student's room_status and assign room
            $stmt = mysqli_prepare($conn,
                "UPDATE users SET room_id = ?, room_status = 'approved'
                 WHERE id = ? AND role = 'student'");
            mysqli_stmt_bind_param($stmt, 'ii', $room_id, $student_id);
            
            if (mysqli_stmt_execute($stmt)) {
                // Update room to occupied
                $stmt2 = mysqli_prepare($conn,
                    "UPDATE rooms SET status = 'occupied', student_id = ?
                     WHERE id = ?");
                mysqli_stmt_bind_param($stmt2, 'ii', $student_id, $room_id);
                mysqli_stmt_execute($stmt2);
                mysqli_stmt_close($stmt2);
                
                $success = 'Room request approved successfully!';
            } else {
                throw new Exception('Database error occurred.');
            }
            mysqli_stmt_close($stmt);
        }
        
        elseif ($action === 'reject') {
            $student_id = (int)$_POST['student_id'] ?? 0;
            
            if (!$student_id) {
                throw new Exception('Invalid student.');
            }
            
            // Reset room request as pending
            $stmt = mysqli_prepare($conn,
                "UPDATE users SET room_status = 'pending'
                 WHERE id = ? AND role = 'student'");
            mysqli_stmt_bind_param($stmt, 'i', $student_id);
            
            if (mysqli_stmt_execute($stmt)) {
                $success = 'Room request rejected.';
            } else {
                throw new Exception('Database error occurred.');
            }
            mysqli_stmt_close($stmt);
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get pending room requests
$pending = [];
$stmt = mysqli_prepare($conn,
    "SELECT u.id, u.name, u.email, u.student_phone, u.room_preference,
            r.id as room_id, r.room_number, r.room_type, r.floor,
            u.created_at
     FROM users u
     LEFT JOIN rooms r ON r.status = 'available'
     WHERE u.role = 'student' AND u.room_status = 'pending'
     ORDER BY u.created_at DESC");
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $pending[] = $row;
}
mysqli_stmt_close($stmt);

// Get approved requests
$approved = [];
$stmt = mysqli_prepare($conn,
    "SELECT u.id, u.name, u.email, u.student_phone, u.room_preference,
            r.room_number, r.room_type, r.floor,
            u.created_at
     FROM users u
     LEFT JOIN rooms r ON r.id = u.room_id
     WHERE u.role = 'student' AND u.room_status = 'approved' AND u.room_id IS NOT NULL
     ORDER BY u.created_at DESC");
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $approved[] = $row;
}
mysqli_stmt_close($stmt);

// Get available rooms
$available_rooms = [];
$res = mysqli_query($conn,
    "SELECT id, room_number, room_type, floor
     FROM rooms WHERE status = 'available'
     ORDER BY floor ASC, room_number ASC");
while ($r = mysqli_fetch_assoc($res)) {
    $available_rooms[] = $r;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Room Requests — HMS</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .request-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            margin-bottom: 1rem;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 2rem;
            align-items: center;
        }
        .request-info h3 {
            font-size: 1.125rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        .request-details {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 1rem;
            font-size: 0.875rem;
            color: var(--text-muted);
            margin-top: 0.75rem;
        }
        .request-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            align-items: center;
        }
        select {
            padding: 0.75rem 1rem;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-family: inherit;
            font-size: 0.875rem;
            cursor: pointer;
            background: white;
        }
        select:focus {
            border-color: var(--brand);
            outline: none;
        }
        .btn-sm {
            padding: 0.625rem 1.25rem;
            font-size: 0.875rem;
        }
        .badge-status {
            display: inline-block;
            padding: 0.4rem 0.75rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-pending {
            background: #fef3c7;
            color: #b45309;
        }
        .badge-approved {
            background: #dcfce7;
            color: #15803d;
        }
        .form-group-inline {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
        }
        .form-group-inline > div {
            flex: 1;
        }
    </style>
</head>
<body>
<?php echo render_sidebar('room_requests.php'); ?>
<div class="container">
    <div class="page-header">
        <h1>Room Assignment Requests</h1>
        <p>Manage student room requests and available rooms</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <!-- PENDING REQUESTS SECTION -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">
                <span style="color:var(--brand);">●</span>
                Pending Room Requests
                <span style="font-weight: 400; color: var(--text-muted); margin-left: 1rem;">
                    (<?= count($pending) ?> request<?= count($pending) !== 1 ? 's' : '' ?>)
                </span>
            </h2>
        </div>

        <?php if (empty($pending)): ?>
            <p style="color: var(--text-muted); padding: 2rem 0; text-align: center;">
                No pending room requests at this time.
            </p>
        <?php else: ?>
            <?php foreach ($pending as $req): ?>
                <div class="request-card">
                    <div class="request-info">
                        <h3><?= e($req['name']) ?></h3>
                        <p style="font-size: 0.875rem; color: var(--text-muted);">
                            <?= e($req['email']) ?>
                        </p>
                        <div class="request-details">
                            <div>
                                <strong>Phone:</strong><br>
                                <?= e($req['student_phone'] ?? 'N/A') ?>
                            </div>
                            <div>
                                <strong>Preference:</strong><br>
                                <?= e($req['room_preference'] ?? 'No Preference') ?>
                            </div>
                            <div>
                                <strong>Requested:</strong><br>
                                <?= date('d M Y', strtotime($req['created_at'])) ?>
                            </div>
                        </div>
                    </div>
                    <div class="request-actions">
                        <form method="POST" style="display: flex; gap: 0.75rem; align-items: center;">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="student_id" value="<?= $req['id'] ?>">
                            
                            <select name="room_id" required>
                                <option value="">Select a room...</option>
                                <?php foreach ($available_rooms as $room): ?>
                                    <option value="<?= $room['id'] ?>">
                                        Room <?= e($room['room_number']) ?> — 
                                        <?= e($room['room_type']) ?> (Floor <?= $room['floor'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <button type="submit" class="btn btn-success btn-sm">
                                Approve
                            </button>
                        </form>
                        
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="student_id" value="<?= $req['id'] ?>">
                            <button type="submit" class="btn btn-secondary btn-sm" 
                                    onclick="return confirm('Reject this request?')">
                                Reject
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- APPROVED REQUESTS SECTION -->
    <div class="card" style="margin-top: 2rem;">
        <div class="card-header">
            <h2 class="card-title">
                <span style="color: #16a34a;">●</span>
                Approved Assignments
                <span style="font-weight: 400; color: var(--text-muted); margin-left: 1rem;">
                    (<?= count($approved) ?> assignment<?= count($approved) !== 1 ? 's' : '' ?>)
                </span>
            </h2>
        </div>

        <?php if (empty($approved)): ?>
            <p style="color: var(--text-muted); padding: 2rem 0; text-align: center;">
                No approved room assignments yet.
            </p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Assigned Room</th>
                        <th>Room Type</th>
                        <th>Approval Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($approved as $app): ?>
                        <tr>
                            <td style="font-weight: 700;">
                                <?= e($app['name']) ?>
                            </td>
                            <td><?= e($app['email']) ?></td>
                            <td><?= e($app['student_phone'] ?? 'N/A') ?></td>
                            <td style="font-weight: 600; color: var(--brand);">
                                <?= e($app['room_number'] ?? '—') ?>
                            </td>
                            <td><?= e($app['room_type'] ?? '—') ?></td>
                            <td><?= date('d M Y', strtotime($app['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</div>
</body>
</html>
