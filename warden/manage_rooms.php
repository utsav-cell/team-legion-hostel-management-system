<?php
require_once '../db.php';
require_role('warden');

$uid = (int)$_SESSION['user_id'];
$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify($_POST['csrf_token'] ?? '');
        $action  = $_POST['action'] ?? '';
        $room_id = (int)($_POST['room_id'] ?? 0);
        if (!$room_id) throw new Exception('Invalid room.');

        $stmt = $pdo->prepare("SELECT id, room_number, status FROM rooms WHERE id = ? LIMIT 1");
        $stmt->execute([$room_id]);
        $room = $stmt->fetch();
        if (!$room) throw new Exception('Room not found.');

        if ($action === 'mark_maintenance') {
            if ($room['status'] === 'occupied') throw new Exception('Cannot mark an occupied room as maintenance.');
            $pdo->prepare("UPDATE rooms SET status = 'maintenance' WHERE id = ?")->execute([$room_id]);
            $success = "Room {$room['room_number']} marked as maintenance.";
        } elseif ($action === 'mark_available') {
            if ($room['status'] !== 'maintenance') throw new Exception('Only maintenance rooms can be reopened here.');
            $pdo->prepare("UPDATE rooms SET status = 'available' WHERE id = ?")->execute([$room_id]);
            $success = "Room {$room['room_number']} is back online.";
        } else {
            throw new Exception('Unknown action.');
        }
    } catch (Exception $ex) {
        $error = $ex->getMessage();
    }
}

$rooms = $pdo->query(
    "SELECT r.id, r.room_number, r.room_type, r.floor, r.status, r.capacity, r.price, r.notes, r.photo,
            u.name AS occupant_name
     FROM rooms r
     LEFT JOIN users u ON u.id = r.student_id
     WHERE r.deleted_at IS NULL
     ORDER BY r.floor ASC, r.room_number ASC"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Manage Rooms - HMS</title>
    <link rel="stylesheet" href="../css/style.css?v=18">
    <style>
        .rm-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:1.25rem; }
        @media(max-width:600px){ .rm-grid{ grid-template-columns:1fr; } }
        .rm-card { background:#fff; border:1.5px solid var(--border); border-radius:14px; overflow:hidden; box-shadow:var(--shadow-soft); display:flex; flex-direction:column; transition:box-shadow 0.2s ease; }
        .rm-card:hover { box-shadow:var(--shadow); }
        .rm-card.status-maintenance { border-color:rgba(239,68,68,0.3); }
        .rm-img { position:relative; }
        .rm-img img { height:160px; width:100%; object-fit:cover; display:block; }
        .rm-img-overlay { position:absolute; inset:0; background:linear-gradient(to top,rgba(15,23,42,0.55) 0%,transparent 55%); }
        .rm-num-badge  { position:absolute; top:0.6rem; left:0.6rem; background:rgba(15,23,42,0.7); color:#fff; font-size:0.68rem; font-weight:800; letter-spacing:0.06em; text-transform:uppercase; padding:0.2rem 0.55rem; border-radius:999px; backdrop-filter:blur(4px); }
        .rm-status-badge { position:absolute; top:0.6rem; right:0.6rem; font-size:0.65rem; font-weight:800; letter-spacing:0.05em; text-transform:uppercase; padding:0.2rem 0.55rem; border-radius:999px; }
        .s-available   { background:rgba(16,185,129,0.85); color:#fff; }
        .s-occupied    { background:rgba(59,130,246,0.85); color:#fff; }
        .s-maintenance { background:rgba(239,68,68,0.85); color:#fff; }
        .rm-floor-badge { position:absolute; bottom:0.6rem; right:0.6rem; background:rgba(15,23,42,0.6); color:rgba(255,255,255,0.85); font-size:0.68rem; font-weight:600; padding:0.18rem 0.5rem; border-radius:999px; backdrop-filter:blur(3px); }
        .rm-body { padding:1rem 1.1rem 1.1rem; flex:1; display:flex; flex-direction:column; gap:0.3rem; }
        .rm-type  { font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.08em; color:var(--muted); }
        .rm-price { font-size:1.1rem; font-weight:800; letter-spacing:-0.02em; color:var(--text); }
        .rm-price span { font-size:0.72rem; font-weight:500; color:var(--muted); }
        .rm-actions { display:flex; gap:0.4rem; margin-top:auto; flex-wrap:wrap; }
    </style>
</head>
<body>
<?= render_sidebar('manage_rooms.php') ?>
<?= render_topbar() ?>
<div class="container">
    <div class="page-header"><h1>Manage Rooms</h1></div>
    <?php if ($error):   ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

    <?php
    $type_images = [
        'Single'=>'https://images.unsplash.com/photo-1631049307264-da0ec9d70304?auto=format&fit=crop&q=80&w=600',
        'Double'=>'https://images.unsplash.com/photo-1555854877-bab0e564b8d5?auto=format&fit=crop&q=80&w=600',
        'Triple'=>'https://images.unsplash.com/photo-1484154218962-a197022b5858?auto=format&fit=crop&q=80&w=600',
    ];
    ?>
    <div class="rm-grid">
    <?php foreach ($rooms as $r):
        $img = !empty($r['photo']) ? '../uploads/rooms/'.e($r['photo']) : ($type_images[$r['room_type']] ?? $type_images['Single']);
        $bkStmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE room_id=? AND status IN('pending_approval','approved','active')");
        $bkStmt->execute([(int)$r['id']]);
        $taken = (int)$bkStmt->fetchColumn();
    ?>
        <div class="rm-card status-<?= e($r['status']) ?>">
            <div class="rm-img">
                <img src="<?= $img ?>" alt="<?= e($r['room_type']) ?> Room">
                <div class="rm-img-overlay"></div>
                <span class="rm-num-badge">Room <?= e($r['room_number']) ?></span>
                <span class="rm-status-badge s-<?= e($r['status']) ?>"><?= ucfirst(e($r['status'])) ?></span>
                <span class="rm-floor-badge">Floor <?= (int)$r['floor'] ?></span>
            </div>
            <div class="rm-body">
                <div class="rm-type"><?= e($r['room_type']) ?> Sharing</div>
                <div class="rm-price">NPR <?= number_format((float)$r['price'],0) ?><span> /month</span></div>
                <div style="font-size:0.78rem;color:var(--muted);"><?= $taken ?>/<?= (int)$r['capacity'] ?> beds occupied</div>
                <div style="display:flex;align-items:center;gap:0.4rem;margin-bottom:0.5rem;font-size:0.78rem;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px;flex-shrink:0;color:<?= $r['occupant_name'] ? 'var(--muted)' : 'rgba(15,23,42,0.2)' ?>;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <?php if ($r['occupant_name']): ?>
                        <span style="font-weight:600;color:var(--text);"><?= e($r['occupant_name']) ?></span>
                    <?php else: ?>
                        <span style="color:rgba(15,23,42,0.25);font-style:italic;">Unoccupied</span>
                    <?php endif; ?>
                </div>
                <div class="rm-actions">
                    <?php if ($r['status'] === 'available'): ?>
                        <form method="POST" style="margin:0;flex:1;" data-confirm="Mark room <?= e($r['room_number']) ?> as maintenance?">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="mark_maintenance">
                            <input type="hidden" name="room_id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-danger" style="width:100%;min-height:32px;font-size:0.78rem;padding:0.35rem 0.75rem;">Mark Maintenance</button>
                        </form>
                    <?php elseif ($r['status'] === 'maintenance'): ?>
                        <form method="POST" style="margin:0;flex:1;">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="mark_available">
                            <input type="hidden" name="room_id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-success" style="width:100%;min-height:32px;font-size:0.78rem;padding:0.35rem 0.75rem;">Reopen Room</button>
                        </form>
                    <?php else: ?>
                        <span style="font-size:0.78rem;color:var(--muted);padding:0.35rem 0;">Currently occupied</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<script src="../js/script.js"></script>
</body>
</html>
