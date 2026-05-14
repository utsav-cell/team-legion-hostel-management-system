<?php
// ─────────────────────────────────────────────────
// student/request_transfer.php — Submit room transfer request
//   Student picks a target room and writes a reason. Submission creates a
//   row in `room_transfers` with status='pending'. Warden approves/rejects
//   from warden/room_transfers.php. On approval, users.room_id + bookings
//   are updated, so dashboard reflects the new room immediately.
// ─────────────────────────────────────────────────

require_once '../db.php';
require_role('student');

$uid     = (int)$_SESSION['user_id'];
$success = $error = '';

// ── Look up the student's current room (prefer active booking; fall back to users.room_id) ─
$current_room_id = 0;
$current_room    = null;
try {
    // Active/approved booking first
    $bs = $pdo->prepare(
        "SELECT r.id AS rid, r.room_number, r.room_type, r.floor
         FROM bookings b
         JOIN rooms r ON r.id = b.room_id
         WHERE b.student_id = ? AND b.status IN ('approved','active')
         ORDER BY b.created_at DESC LIMIT 1"
    );
    $bs->execute([$uid]);
    $current_room = $bs->fetch();

    if (!$current_room) {
        $us = $pdo->prepare(
            "SELECT r.id AS rid, r.room_number, r.room_type, r.floor
             FROM users u LEFT JOIN rooms r ON r.id = u.room_id
             WHERE u.id = ? LIMIT 1"
        );
        $us->execute([$uid]);
        $current_room = $us->fetch();
    }
    $current_room_id = (int)($current_room['rid'] ?? 0);
} catch (Exception $e) {}

// ── Handle submission ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify($_POST['csrf_token'] ?? '');

        if (!$current_room_id) {
            throw new Exception('You do not have a room yet. Book a room first.');
        }

        $to_room_id = (int)($_POST['to_room_id'] ?? 0);
        $reason     = trim((string)($_POST['reason'] ?? ''));

        if (!$to_room_id)                       throw new Exception('Pick a room to transfer to.');
        if ($to_room_id === $current_room_id)   throw new Exception('Target room is the same as your current room.');
        if (strlen($reason) < 10)               throw new Exception('Please give a brief reason (at least 10 characters).');

        // Block duplicate pending requests
        $dup = $pdo->prepare("SELECT id FROM room_transfers WHERE student_id = ? AND status = 'pending' LIMIT 1");
        $dup->execute([$uid]);
        if ($dup->fetch()) throw new Exception('You already have a pending transfer request. Wait for the warden to review it.');

        // Verify target room is actually available
        $chk = $pdo->prepare("SELECT id FROM rooms WHERE id = ? AND status = 'available' AND deleted_at IS NULL LIMIT 1");
        $chk->execute([$to_room_id]);
        if (!$chk->fetch()) throw new Exception('That room is no longer available. Pick another.');

        $pdo->prepare(
            "INSERT INTO room_transfers (student_id, from_room_id, to_room_id, reason, status)
             VALUES (?, ?, ?, ?, 'pending')"
        )->execute([$uid, $current_room_id ?: null, $to_room_id, $reason]);

        $success = 'Transfer request submitted. The warden will review it shortly.';
    } catch (Exception $ex) {
        $error = $ex->getMessage();
    }
}

// ── Rooms list — available + booked, exclude the student's current room ──────
$rooms = $pdo->prepare(
    "SELECT id, room_number, room_type, floor, price, status
     FROM rooms
     WHERE deleted_at IS NULL AND id <> ?
     ORDER BY status = 'available' DESC, room_number ASC"
);
$rooms->execute([$current_room_id]);
$rooms = $rooms->fetchAll();

// ── Student's recent transfer requests ───────────────────────────────────────
$my_requests = $pdo->prepare(
    "SELECT rt.id, rt.status, rt.reason, rt.created_at, rt.decided_at,
            rf.room_number AS from_room, rt2.room_number AS to_room
     FROM room_transfers rt
     LEFT JOIN rooms rf  ON rf.id  = rt.from_room_id
     LEFT JOIN rooms rt2 ON rt2.id = rt.to_room_id
     WHERE rt.student_id = ?
     ORDER BY rt.created_at DESC LIMIT 10"
);
$my_requests->execute([$uid]);
$my_requests = $my_requests->fetchAll();

$has_pending = false;
foreach ($my_requests as $r) {
    if ($r['status'] === 'pending') { $has_pending = true; break; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Request Room Transfer — HMS</title>
    <link rel="stylesheet" href="../css/style.css?v=34">
    <style>
        .tr-current { display:flex; align-items:center; gap:1rem; background:#fff; border:1.5px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow-soft); padding:1rem 1.25rem; margin-bottom:1.25rem; }
        .tr-current-icon { width:42px; height:42px; border-radius:10px; background:var(--primary-soft); display:flex; align-items:center; justify-content:center; color:var(--primary); flex-shrink:0; }
        .tr-current-icon svg { width:18px; height:18px; stroke:currentColor; fill:none; stroke-width:2; }
        .tr-current-label { font-size:0.72rem; font-weight:700; letter-spacing:0.06em; color:var(--muted); text-transform:uppercase; }
        .tr-current-value { font-size:1.1rem; font-weight:800; color:var(--text); letter-spacing:-0.01em; }
        .tr-current-meta  { font-size:0.78rem; color:var(--muted); margin-top:2px; }

        .room-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(180px, 1fr)); gap:0.75rem; margin-top:0.75rem; }
        .room-tile { position:relative; border:1.5px solid var(--border); border-radius:10px; padding:0.85rem 0.95rem; background:#fff; cursor:pointer; transition:all 0.15s ease; }
        .room-tile:hover:not(.is-disabled) { border-color:var(--primary); box-shadow:var(--shadow-soft); transform:translateY(-1px); }
        .room-tile.is-selected { border-color:var(--primary); background:var(--primary-soft); box-shadow:0 0 0 3px rgba(99,102,241,0.12); }
        .room-tile.is-disabled { opacity:0.55; cursor:not-allowed; background:var(--panel-alt); }
        .room-tile input[type=radio] { position:absolute; opacity:0; pointer-events:none; }
        .room-tile-num { font-size:1.1rem; font-weight:800; color:var(--text); letter-spacing:-0.02em; }
        .room-tile-meta { font-size:0.74rem; color:var(--muted); margin-top:3px; }
        .room-tile-price { font-size:0.78rem; font-weight:700; color:var(--text); margin-top:0.5rem; }
        .room-tile-status { position:absolute; top:0.5rem; right:0.55rem; font-size:0.65rem; font-weight:800; padding:0.15rem 0.45rem; border-radius:999px; text-transform:uppercase; letter-spacing:0.05em; }
        .room-tile-status.s-avail { background:#dcfce7; color:#15803d; }
        .room-tile-status.s-booked { background:#fee2e2; color:#b91c1c; }
        .room-tile-status.s-maint { background:#fef3c7; color:#b45309; }

        .form-textarea { width:100%; border:1.5px solid var(--border); border-radius:10px; padding:0.7rem 0.85rem; font-family:inherit; font-size:0.9rem; resize:vertical; min-height:90px; transition:border-color 0.15s; }
        .form-textarea:focus { outline:none; border-color:var(--primary); }

        .tr-req-table { width:100%; border-collapse:collapse; font-size:0.85rem; }
        .tr-req-table th { text-align:left; font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; color:var(--muted); padding:0 0 0.6rem; border-bottom:1.5px solid var(--border); }
        .tr-req-table td { padding:0.7rem 0; border-bottom:1px solid var(--border); }
        .tr-req-table tr:last-child td { border-bottom:none; }
        .arrow { color:var(--muted); }

        .pending-banner { display:flex; gap:0.7rem; align-items:flex-start; background:#fef3c7; border:1.5px solid #fcd34d; border-radius:10px; padding:0.85rem 1rem; margin-bottom:1.25rem; }
        .pending-banner svg { width:18px; height:18px; stroke:#b45309; fill:none; stroke-width:2; flex-shrink:0; margin-top:1px; }
        .pending-banner-body { font-size:0.85rem; color:#78350f; }
        .pending-banner-body b { color:#7c2d12; }

        .form-section-card { background:#fff; border:1.5px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow-soft); padding:1.25rem 1.4rem; margin-bottom:1.5rem; }
        .form-section-title { font-size:0.95rem; font-weight:700; color:var(--text); margin-bottom:0.25rem; }
        .form-section-sub   { font-size:0.78rem; color:var(--muted); margin-bottom:0.85rem; }
    </style>
</head>
<body>
<?= render_sidebar('request_transfer.php') ?>
<?= render_topbar() ?>

<div class="container">
    <div class="page-header"><h1>Request Room Transfer</h1></div>

    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <!-- Current Room -->
    <div class="tr-current">
        <div class="tr-current-icon">
            <svg viewBox="0 0 24 24"><path d="M2 7v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7M4 12h16M4 7h16v4H4z"/></svg>
        </div>
        <div style="flex:1;">
            <div class="tr-current-label">Your Current Room</div>
            <?php if ($current_room && $current_room['room_number']): ?>
                <div class="tr-current-value">Room <?= e($current_room['room_number']) ?></div>
                <div class="tr-current-meta"><?= e($current_room['room_type']) ?> &middot; Floor <?= (int)$current_room['floor'] ?></div>
            <?php else: ?>
                <div class="tr-current-value" style="color:var(--muted);">No room assigned</div>
                <div class="tr-current-meta">Book a room first from <a href="browse_rooms.php" style="color:var(--primary);font-weight:600;">Browse Rooms</a>.</div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($has_pending): ?>
    <div class="pending-banner">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <div class="pending-banner-body">
            <b>You already have a pending transfer request.</b> Wait for the warden to review it before submitting another.
        </div>
    </div>
    <?php endif; ?>

    <?php if ($current_room_id && !$has_pending): ?>
    <form method="post" id="transfer-form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

        <!-- Pick target room -->
        <div class="form-section-card">
            <div class="form-section-title">Pick a room to transfer to</div>
            <div class="form-section-sub">Booked or unavailable rooms are shown but cannot be selected.</div>

            <?php if (!$rooms): ?>
                <div style="padding:1rem;text-align:center;color:var(--muted);font-size:0.875rem;">No other rooms in the hostel yet.</div>
            <?php else: ?>
                <div class="room-grid">
                    <?php foreach ($rooms as $r):
                        $is_avail = ($r['status'] === 'available');
                        $sclass   = $is_avail ? 's-avail' : ($r['status'] === 'maintenance' ? 's-maint' : 's-booked');
                        $slabel   = $is_avail ? 'Open' : ($r['status'] === 'maintenance' ? 'Maint' : 'Booked');
                    ?>
                        <label class="room-tile <?= $is_avail ? '' : 'is-disabled' ?>" data-avail="<?= $is_avail ? '1' : '0' ?>">
                            <span class="room-tile-status <?= $sclass ?>"><?= $slabel ?></span>
                            <?php if ($is_avail): ?>
                                <input type="radio" name="to_room_id" value="<?= (int)$r['id'] ?>" required>
                            <?php endif; ?>
                            <div class="room-tile-num">Room <?= e($r['room_number']) ?></div>
                            <div class="room-tile-meta"><?= e($r['room_type']) ?> &middot; Floor <?= (int)$r['floor'] ?></div>
                            <?php if ($r['price'] > 0): ?>
                                <div class="room-tile-price">NPR <?= number_format((float)$r['price'], 0) ?>/mo</div>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Reason -->
        <div class="form-section-card">
            <div class="form-section-title">Reason for transfer</div>
            <div class="form-section-sub">A short explanation helps the warden decide quickly.</div>
            <textarea name="reason" class="form-textarea" required minlength="10"
                      placeholder="e.g. I would like a quieter room on a lower floor for my evening study schedule."></textarea>
            <div style="text-align:right;margin-top:1rem;">
                <button type="submit" class="btn btn-primary">Submit Transfer Request</button>
            </div>
        </div>
    </form>
    <?php endif; ?>

    <!-- Recent requests -->
    <?php if ($my_requests): ?>
    <div class="form-section-card">
        <div class="form-section-title">Your Recent Transfer Requests</div>
        <div class="form-section-sub">Status updates appear here once the warden decides.</div>
        <table class="tr-req-table">
            <thead><tr><th>From</th><th>To</th><th>Reason</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($my_requests as $r):
                $bcls = match($r['status']) { 'approved' => 'badge-green', 'rejected' => 'badge-red', default => 'badge-pending' };
            ?>
                <tr>
                    <td><?= $r['from_room'] ? 'Room ' . e($r['from_room']) : '—' ?></td>
                    <td><span class="arrow">→</span> Room <?= e($r['to_room'] ?? '—') ?></td>
                    <td style="color:var(--muted);font-size:0.8rem;max-width:280px;"><?= e($r['reason']) ?></td>
                    <td><span class="badge <?= $bcls ?>"><?= e(ucfirst($r['status'])) ?></span></td>
                    <td style="color:var(--muted);font-size:0.78rem;">
                        <?= e(date('d M Y', strtotime($r['decided_at'] ?: $r['created_at']))) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
// Visual selection state for room tiles
document.querySelectorAll('.room-tile').forEach(function (tile) {
    if (tile.dataset.avail !== '1') return;
    tile.addEventListener('click', function () {
        document.querySelectorAll('.room-tile').forEach(t => t.classList.remove('is-selected'));
        tile.classList.add('is-selected');
        const radio = tile.querySelector('input[type=radio]');
        if (radio) radio.checked = true;
    });
});
</script>
<?php include __DIR__ . '/../chatbot/widget.php'; ?>
<script src="../js/script.js"></script>
</body>
</html>
