<?php
require_once '../db.php';
require_role('owner');
$uid   = (int)$_SESSION['user_id'];
$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $bid = (int)$_POST['booking_id'];
    $act = $_POST['action'] ?? '';

    $s = mysqli_prepare($conn,
        "SELECT b.*, r.room_number, r.price, u.name AS sn
         FROM bookings b
         JOIN rooms r ON r.id = b.room_id
         JOIN users u ON u.id = b.student_id
         WHERE b.id = ? AND b.status = 'pending_approval'
         LIMIT 1");
    mysqli_stmt_bind_param($s, 'i', $bid);
    mysqli_stmt_execute($s);
    $bk = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);

    if (!$bk) {
        $error = 'Booking not found or already processed.';
    } elseif ($act === 'approve') {
        mysqli_begin_transaction($conn);
        try {
            mysqli_query($conn,
                "UPDATE bookings SET status='approved', approved_by=$uid, approved_at=NOW()
                 WHERE id=$bid");
            mysqli_query($conn,
                "UPDATE users SET room_id={$bk['room_id']}, room_status='approved'
                 WHERE id={$bk['student_id']}");
            mysqli_query($conn,
                "UPDATE rooms SET status='occupied' WHERE id={$bk['room_id']}");
            $notes = "Booking #$bid approved for {$bk['sn']}";
            $s = mysqli_prepare($conn,
                "INSERT INTO room_audit_log (room_id, student_id, event, actor_id, notes)
                 VALUES (?, ?, 'booking_approved', ?, ?)");
            mysqli_stmt_bind_param($s, 'iiis',
                $bk['room_id'], $bk['student_id'], $uid, $notes);
            mysqli_stmt_execute($s);
            mysqli_stmt_close($s);
            mysqli_commit($conn);
            $success = "Approved: {$bk['sn']} — Room {$bk['room_number']}";
        } catch (Exception $ex) {
            mysqli_rollback($conn);
            $error = 'Approval failed: ' . $ex->getMessage();
        }
    } elseif ($act === 'reject') {
        mysqli_query($conn,
            "UPDATE bookings SET status='rejected', approved_by=$uid, approved_at=NOW()
             WHERE id=$bid");
        $notes = "Booking #$bid rejected for {$bk['sn']}";
        $s = mysqli_prepare($conn,
            "INSERT INTO room_audit_log (room_id, student_id, event, actor_id, notes)
             VALUES (?, ?, 'booking_rejected', ?, ?)");
        mysqli_stmt_bind_param($s, 'iiis',
            $bk['room_id'], $bk['student_id'], $uid, $notes);
        mysqli_stmt_execute($s);
        mysqli_stmt_close($s);
        $success = "Booking rejected for {$bk['sn']}.";
    } else {
        $error = 'Invalid action.';
    }
}

// Pending bookings
$pending = [];
$res = mysqli_query($conn,
    "SELECT b.id, b.created_at, b.start_date,
            r.room_number, r.room_type, r.floor, r.price,
            u.id AS student_id, u.name, u.email, u.student_phone, u.photo
     FROM bookings b
     JOIN rooms r ON r.id = b.room_id
     JOIN users u ON u.id = b.student_id
     WHERE b.status = 'pending_approval'
     ORDER BY b.created_at ASC");
if ($res) while ($row = mysqli_fetch_assoc($res)) $pending[] = $row;

// Recently processed
$processed = [];
$res = mysqli_query($conn,
    "SELECT b.id, b.status, b.approved_at, b.start_date,
            r.room_number, u.name, u.email,
            a.name AS approver
     FROM bookings b
     JOIN rooms r ON r.id = b.room_id
     JOIN users u ON u.id = b.student_id
     LEFT JOIN users a ON a.id = b.approved_by
     WHERE b.status IN ('approved', 'rejected')
     ORDER BY b.updated_at DESC
     LIMIT 20");
if ($res) while ($row = mysqli_fetch_assoc($res)) $processed[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Approvals - HMS</title>
    <link rel="stylesheet" href="../css/style.css?v=18">
    <style>
        .bc {
            background: var(--panel);
            border: 1.5px solid var(--border);
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 0.875rem;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 1.5rem;
            align-items: center;
        }
        .bs {
            display: flex;
            align-items: center;
            gap: 0.875rem;
            margin-bottom: 0.6rem;
        }
        .bs img {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            object-fit: cover;
            border: 1.5px solid var(--border);
        }
        .bi {
            display: flex;
            gap: 1.25rem;
            font-size: .82rem;
            color: var(--muted);
            flex-wrap: wrap;
        }
        .bi strong { color: var(--text); }
        .ba { display: flex; gap: .5rem; flex-shrink: 0; }
        @media (max-width: 700px) {
            .bc { grid-template-columns: 1fr; }
            .ba { justify-content: flex-start; }
        }
    </style>
</head>
<body>
<?= render_sidebar('booking_approvals.php') ?>
<?= render_topbar() ?>
<div class="container">

    <div class="page-header">
        <h1>Booking Approvals</h1>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <div class="stats-grid" style="margin-bottom:1.5rem;">
        <div class="stat-box stat-amber">
            <div class="stat-icon-bubble">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M16 4h-2V2h-4v2H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z"/>
                    <path d="M9 11h6M9 15h6"/>
                </svg>
            </div>
            <div class="stat-label">Pending</div>
            <div class="stat-value"><?= count($pending) ?></div>
            <div class="stat-meta">Awaiting review</div>
        </div>
        <div class="stat-box stat-green">
            <div class="stat-icon-bubble">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
            </div>
            <div class="stat-label">Recent</div>
            <div class="stat-value"><?= count($processed) ?></div>
            <div class="stat-meta">Processed (last 20)</div>
        </div>
    </div>

    <!-- Pending requests -->
    <div class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Pending Requests</h2>
                <p class="card-subtitle">Students waiting for their booking to be approved or rejected.</p>
            </div>
            <?php if (!empty($pending)): ?>
                <span class="badge badge-pending"><?= count($pending) ?> Pending</span>
            <?php endif; ?>
        </div>

        <?php if (empty($pending)): ?>
            <p style="text-align:center;color:var(--muted);padding:3rem 1rem;font-size:.875rem;">
                No pending booking requests.
            </p>
        <?php else: ?>
            <?php foreach ($pending as $b): ?>
                <div class="bc">
                    <div>
                        <div class="bs">
                            <img src="../uploads/students/<?= e($b['photo'] ?: 'default.png') ?>"
                                 onerror="this.src='../uploads/students/default.png'"
                                 alt="<?= e($b['name']) ?>">
                            <div>
                                <div style="font-weight:700;font-size:.9rem;"><?= e($b['name']) ?></div>
                                <div style="font-size:.78rem;color:var(--muted);"><?= e($b['email']) ?></div>
                                <?php if ($b['student_phone']): ?>
                                    <div style="font-size:.78rem;color:var(--muted);"><?= e($b['student_phone']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="bi">
                            <div><strong>Room:</strong> <?= e($b['room_number']) ?></div>
                            <div><strong>Type:</strong> <?= e($b['room_type']) ?></div>
                            <div><strong>Floor:</strong> <?= e($b['floor']) ?></div>
                            <div><strong>NPR <?= number_format($b['price'], 0) ?></strong>/mo</div>
                            <?php if ($b['start_date']): ?>
                                <div><strong>Start:</strong> <?= date('d M Y', strtotime($b['start_date'])) ?></div>
                            <?php endif; ?>
                            <div>Requested <?= date('d M Y', strtotime($b['created_at'])) ?></div>
                        </div>
                    </div>
                    <div class="ba">
                        <button type="button" class="btn btn-success"
                                onclick="openConfirm(
                                    <?= (int)$b['id'] ?>,
                                    'approve',
                                    <?= json_encode($b['name'], JSON_HEX_QUOT|JSON_HEX_TAG) ?>,
                                    <?= json_encode($b['room_number'], JSON_HEX_QUOT|JSON_HEX_TAG) ?>
                                )">
                            Approve
                        </button>
                        <button type="button" class="btn btn-danger"
                                onclick="openConfirm(
                                    <?= (int)$b['id'] ?>,
                                    'reject',
                                    <?= json_encode($b['name'], JSON_HEX_QUOT|JSON_HEX_TAG) ?>,
                                    <?= json_encode($b['room_number'], JSON_HEX_QUOT|JSON_HEX_TAG) ?>
                                )">
                            Reject
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Recently processed -->
    <?php if (!empty($processed)): ?>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Recently Processed</h2>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Room</th>
                            <th>Start Date</th>
                            <th>Status</th>
                            <th>Actioned By</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($processed as $p): ?>
                            <tr>
                                <td>
                                    <strong><?= e($p['name']) ?></strong><br>
                                    <span style="font-size:.75rem;color:var(--muted);"><?= e($p['email']) ?></span>
                                </td>
                                <td><?= e($p['room_number']) ?></td>
                                <td><?= $p['start_date'] ? date('d M Y', strtotime($p['start_date'])) : '—' ?></td>
                                <td>
                                    <span class="badge <?= $p['status'] === 'approved' ? 'badge-green' : 'badge-red' ?>">
                                        <?= ucfirst(e($p['status'])) ?>
                                    </span>
                                </td>
                                <td><?= e($p['approver'] ?? '—') ?></td>
                                <td>
                                    <?= $p['approved_at']
                                        ? date('d M Y', strtotime($p['approved_at']))
                                        : '—' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div><!-- /.container -->

<!-- Hidden form submitted by confirm modal -->
<form method="POST" id="confirm-form" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="booking_id" id="cf-booking-id">
    <input type="hidden" name="action"     id="cf-action">
</form>

<!-- Confirmation modal -->
<div class="modal-overlay" id="confirm-modal">
    <div class="modal-box" style="max-width:420px;">
        <div class="modal-header">
            <span class="modal-title" id="cm-title">Confirm Action</span>
            <button class="modal-close" type="button" onclick="closeConfirm()">
                <?= get_svg_icon('x') ?>
            </button>
        </div>
        <p id="cm-body"
           style="font-size:.875rem;color:var(--muted);margin-bottom:1.5rem;line-height:1.6;"></p>
        <div style="display:flex;gap:.6rem;justify-content:flex-end;">
            <button type="button" class="btn btn-secondary" onclick="closeConfirm()">Cancel</button>
            <button type="button" class="btn" id="cm-confirm-btn" onclick="submitConfirm()">Confirm</button>
        </div>
    </div>
</div>

<script src="../js/script.js"></script>
<script>
function openConfirm(bookingId, action, studentName, roomNumber) {
    document.getElementById('cf-booking-id').value = bookingId;
    document.getElementById('cf-action').value = action;
    var btn = document.getElementById('cm-confirm-btn');
    if (action === 'approve') {
        document.getElementById('cm-title').textContent = 'Approve Booking';
        document.getElementById('cm-body').textContent =
            'Approve the booking for ' + studentName + ' in Room ' + roomNumber + '? ' +
            'The student will be assigned to this room and the room will be marked occupied.';
        btn.className = 'btn btn-success';
        btn.textContent = 'Yes, Approve';
    } else {
        document.getElementById('cm-title').textContent = 'Reject Booking';
        document.getElementById('cm-body').textContent =
            'Reject the booking request from ' + studentName + ' for Room ' + roomNumber + '? ' +
            'The room will remain available for other students.';
        btn.className = 'btn btn-danger';
        btn.textContent = 'Yes, Reject';
    }
    document.getElementById('confirm-modal').classList.add('open');
}

function closeConfirm() {
    document.getElementById('confirm-modal').classList.remove('open');
}

function submitConfirm() {
    var btn = document.getElementById('cm-confirm-btn');
    btn.disabled = true;
    btn.textContent = 'Processing...';
    document.getElementById('confirm-form').submit();
}

document.getElementById('confirm-modal').addEventListener('click', function (e) {
    if (e.target === this) closeConfirm();
});
</script>
</body>
</html>
