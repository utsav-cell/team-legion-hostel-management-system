<?php
require_once '../db.php';
require_role('owner');
$uid   = (int)$_SESSION['user_id'];
$error = $success = '';

// Ensure upload directory exists
$upload_dir = __DIR__ . '/../uploads/rooms/';
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0755, true);
}

// ─── Handle POST actions ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    // ── Create room ────────────────────────────────────────────────
    if ($action === 'create') {
        $room_number = trim($_POST['room_number'] ?? '');
        $room_type   = $_POST['room_type']   ?? 'Single';
        $floor       = (int)($_POST['floor'] ?? 1);
        $capacity    = (int)($_POST['capacity'] ?? 1);
        $price       = (float)($_POST['price'] ?? 3000);
        $notes       = trim($_POST['notes'] ?? '');
        $status      = $_POST['status'] ?? 'available';

        if ($room_number === '') {
            $error = 'Room number is required.';
        } else {
            // Handle photo upload
            $photo = null;
            if (!empty($_FILES['photo']['tmp_name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    $fname = 'room_' . preg_replace('/[^a-z0-9]/i', '_', $room_number)
                           . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $fname)) {
                        $photo = $fname;
                    }
                }
            }

            $stmt = $pdo->prepare(
                "INSERT INTO rooms (room_number, room_type, floor, capacity, price, notes, photo, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            try {
                $stmt->execute([
                    $room_number, $room_type, $floor, $capacity,
                    $price, $notes ?: null, $photo, $status
                ]);
                $success = "Room $room_number created successfully.";
            } catch (PDOException $ex) {
                if ($ex->getCode() === '23000') {
                    $error = "Room number \"$room_number\" already exists.";
                } else {
                    $error = 'Could not create room: ' . $ex->getMessage();
                }
            }
        }

    // ── Update room ────────────────────────────────────────────────
    } elseif ($action === 'update') {
        $rid         = (int)$_POST['room_id'];
        $room_number = trim($_POST['room_number'] ?? '');
        $room_type   = $_POST['room_type']   ?? 'Single';
        $floor       = (int)($_POST['floor'] ?? 1);
        $capacity    = (int)($_POST['capacity'] ?? 1);
        $price       = (float)($_POST['price'] ?? 3000);
        $notes       = trim($_POST['notes'] ?? '');
        $status      = $_POST['status'] ?? 'available';

        if ($room_number === '') {
            $error = 'Room number is required.';
        } else {
            // Fetch current photo
            $cur = $pdo->prepare("SELECT photo FROM rooms WHERE id = ? AND deleted_at IS NULL LIMIT 1");
            $cur->execute([$rid]);
            $curRow = $cur->fetch();

            $photo = $curRow['photo'] ?? null;
            if (!empty($_FILES['photo']['tmp_name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    $fname = 'room_' . preg_replace('/[^a-z0-9]/i', '_', $room_number)
                           . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $fname)) {
                        // Delete old photo if present
                        if ($photo && file_exists($upload_dir . $photo)) {
                            @unlink($upload_dir . $photo);
                        }
                        $photo = $fname;
                    }
                }
            }

            $stmt = $pdo->prepare(
                "UPDATE rooms
                 SET room_number = ?, room_type = ?, floor = ?, capacity = ?,
                     price = ?, notes = ?, photo = ?, status = ?
                 WHERE id = ? AND deleted_at IS NULL"
            );
            try {
                $stmt->execute([
                    $room_number, $room_type, $floor, $capacity,
                    $price, $notes ?: null, $photo, $status, $rid
                ]);
                $success = "Room $room_number updated.";
            } catch (PDOException $ex) {
                if ($ex->getCode() === '23000') {
                    $error = "Room number \"$room_number\" already exists.";
                } else {
                    $error = 'Could not update room: ' . $ex->getMessage();
                }
            }
        }

    // ── Soft delete ────────────────────────────────────────────────
    } elseif ($action === 'delete') {
        $rid = (int)$_POST['room_id'];
        $stmt = $pdo->prepare(
            "UPDATE rooms SET deleted_at = NOW()
             WHERE id = ? AND status != 'occupied' AND deleted_at IS NULL"
        );
        $stmt->execute([$rid]);
        if ($stmt->rowCount()) {
            $success = 'Room removed.';
        } else {
            $error = 'Cannot delete an occupied room or room not found.';
        }

    // ── Toggle maintenance ─────────────────────────────────────────
    } elseif ($action === 'toggle_maintenance') {
        $rid = (int)$_POST['room_id'];
        $cur = $pdo->prepare(
            "SELECT status FROM rooms WHERE id = ? AND deleted_at IS NULL LIMIT 1"
        );
        $cur->execute([$rid]);
        $curRow = $cur->fetch();
        if ($curRow) {
            $newStatus = $curRow['status'] === 'maintenance' ? 'available' : 'maintenance';
            $stmt = $pdo->prepare(
                "UPDATE rooms SET status = ?
                 WHERE id = ? AND deleted_at IS NULL AND status != 'occupied'"
            );
            $stmt->execute([$newStatus, $rid]);
            if ($stmt->rowCount()) {
                $success = $newStatus === 'maintenance'
                    ? 'Room set to maintenance.'
                    : 'Room set back to available.';
            } else {
                $error = 'Cannot change status of an occupied room.';
            }
        }
    }
}

// ─── Filters & Pagination ─────────────────────────────────────────
$filter_status = $_GET['status'] ?? '';
$filter_floor  = $_GET['floor']  ?? '';
$filter_type   = $_GET['type']   ?? '';
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 10;
$offset        = ($page - 1) * $per_page;

$where  = ['r.deleted_at IS NULL'];
$params = [];

if ($filter_status !== '') {
    $where[]  = 'r.status = ?';
    $params[] = $filter_status;
}
if ($filter_floor !== '') {
    $where[]  = 'r.floor = ?';
    $params[] = (int)$filter_floor;
}
if ($filter_type !== '') {
    $where[]  = 'r.room_type = ?';
    $params[] = $filter_type;
}

$where_sql = implode(' AND ', $where);

// Count total
$cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM rooms r WHERE $where_sql");
$cnt_stmt->execute($params);
$total_rooms = (int)$cnt_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rooms / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

// Fetch page
$params_paged   = $params;
$params_paged[] = $per_page;
$params_paged[] = $offset;
$rooms_stmt = $pdo->prepare(
    "SELECT r.*,
            COUNT(u.id) AS occupant_count,
            GROUP_CONCAT(u.name ORDER BY u.name SEPARATOR ', ') AS occupant_names
     FROM rooms r
     LEFT JOIN users u ON u.room_id = r.id AND u.role = 'student'
     WHERE $where_sql
     GROUP BY r.id
     ORDER BY r.floor ASC, r.room_number ASC
     LIMIT ? OFFSET ?"
);
$rooms_stmt->execute($params_paged);
$rooms = $rooms_stmt->fetchAll();

// Summary counts
$summary = $pdo->query(
    "SELECT
        SUM(deleted_at IS NULL)                                   AS total,
        SUM(deleted_at IS NULL AND status = 'available')         AS available,
        SUM(deleted_at IS NULL AND status = 'occupied')          AS occupied,
        SUM(deleted_at IS NULL AND status = 'maintenance')       AS maintenance
     FROM rooms"
)->fetch();

// Distinct floor options for filter
$floors_res = $pdo->query(
    "SELECT DISTINCT floor FROM rooms WHERE deleted_at IS NULL ORDER BY floor ASC"
)->fetchAll(PDO::FETCH_COLUMN);

// Stock Unsplash images per room type (fallback when no photo uploaded)
function room_stock_img(string $type): string {
    $map = [
        'Single' => 'https://images.unsplash.com/photo-1631049307264-da0ec9d70304?auto=format&fit=crop&q=70&w=600',
        'Double' => 'https://images.unsplash.com/photo-1555854877-bab0e564b8d5?auto=format&fit=crop&q=70&w=600',
        'Triple' => 'https://images.unsplash.com/photo-1484154218962-a197022b5858?auto=format&fit=crop&q=70&w=600',
    ];
    return $map[$type] ?? $map['Single'];
}

function status_badge(string $status): string {
    $cfg = [
        'available'   => ['rcm-status-available',   'Available'],
        'occupied'    => ['rcm-status-occupied',    'Occupied'],
        'maintenance' => ['rcm-status-maintenance', 'Maintenance'],
    ];
    [$cls, $lbl] = $cfg[$status] ?? ['rcm-status-occupied', ucfirst($status)];
    return '<span class="rcm-status-pill ' . $cls . '">' . $lbl . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Rooms - HMS</title>
    <link rel="stylesheet" href="../css/style.css?v=18">
    <style>
        /* ── Add room panel ── */
        .add-panel {
            background: var(--panel);
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        .add-panel-toggle {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.4rem;
            background: none;
            border: none;
            font-family: inherit;
            font-size: .9rem;
            font-weight: 700;
            color: var(--text);
            cursor: pointer;
            text-align: left;
        }
        .add-panel-toggle:hover { background: var(--panel-alt); }
        .add-panel-toggle .apt-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px; height: 28px;
            border-radius: 50%;
            background: var(--primary-soft);
            color: var(--primary);
            font-size: 1.25rem;
            font-weight: 400;
            line-height: 1;
            transition: transform .2s;
        }
        .add-panel.open .apt-icon { transform: rotate(45deg); }
        .add-panel-body {
            display: none;
            padding: 0 1.4rem 1.4rem;
            border-top: 1px solid var(--border);
        }
        .add-panel.open .add-panel-body { display: block; }

        /* ── Form grid ── */
        .fg2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .fg3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; }
        @media (max-width: 640px) {
            .fg2, .fg3 { grid-template-columns: 1fr; }
        }

        /* ── Room card grid ── */
        .room-grid-mgr {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.1rem;
        }
        .room-card-mgr {
            background: var(--panel);
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            transition: box-shadow .2s, transform .2s;
        }
        .room-card-mgr:hover { box-shadow: var(--shadow); transform: translateY(-2px); }

        /* Room image */
        .rcm-img-wrap {
            position: relative;
            aspect-ratio: 16/9;
            overflow: hidden;
        }
        .rcm-img-wrap img {
            width: 100%; height: 100%;
            object-fit: cover;
            display: block;
        }
        .rcm-badge {
            position: absolute;
            top: .65rem; right: .65rem;
        }

        /* Status pill — solid, readable on photos */
        .rcm-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.32rem 0.7rem 0.32rem 0.6rem;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.02em;
            background: #fff;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.18), 0 0 0 1px rgba(15, 23, 42, 0.04);
            white-space: nowrap;
            backdrop-filter: saturate(140%);
        }
        .rcm-status-pill::before {
            content: '';
            width: 7px;
            height: 7px;
            border-radius: 50%;
            flex-shrink: 0;
            background: currentColor;
            box-shadow: 0 0 0 3px rgba(255,255,255,0.4);
        }
        .rcm-status-available   { color: #047857; }
        .rcm-status-occupied    { color: #4338ca; }
        .rcm-status-maintenance { color: #b45309; }

        /* Room body - consistent height for all statuses */
        .rcm-body {
            padding: 1rem;
            display: flex;
            flex-direction: column;
            min-height: 150px;
        }
        .rcm-title {
            font-size: .95rem;
            font-weight: 700;
            margin-bottom: .2rem;
        }
        .rcm-meta {
            font-size: .75rem;
            color: var(--muted);
            display: flex;
            flex-wrap: wrap;
            gap: 0;
            margin-bottom: .6rem;
        }
        /* Separator dots between meta items */
        .rcm-meta span { white-space: nowrap; }
        .rcm-meta span + span::before { content: ' · '; opacity: .5; }

        .rcm-price {
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--text);
            letter-spacing: -0.02em;
            margin-bottom: .5rem;
            line-height: 1;
        }
        .rcm-price small { font-size:.72rem; font-weight:500; color:var(--muted); }

        /* Occupant row - always rendered for uniform card height */
        .rcm-occupant {
            display: flex;
            align-items: center;
            gap: .4rem;
            font-size: .78rem;
            margin-bottom: .6rem;
            min-height: 20px;
        }
        .rcm-occupant.filled { color: var(--text); font-weight: 600; }
        .rcm-occupant.empty  { color: rgba(15,23,42,.22); font-style: italic; }

        .rcm-actions {
            display: flex;
            gap: .4rem;
            flex-wrap: wrap;
            align-items: center;
            margin-top: auto;
            padding-top: .6rem;
            border-top: 1px solid var(--border);
        }

        /* Inline edit form */
        .rcm-edit-form {
            display: none;
            border-top: 1px solid var(--border);
            padding: 1.1rem 1.15rem 1.15rem;
            background: var(--panel-alt);
        }
        .rcm-edit-form.open { display: block; }
        .rcm-edit-form .rcm-edit-title {
            display: flex; align-items: center; gap: 0.5rem;
            font-size: 0.78rem;
            font-weight: 800;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 0.9rem;
            padding-bottom: 0.6rem;
            border-bottom: 1px dashed var(--border);
        }
        .rcm-edit-form .rcm-edit-title svg { width: 14px; height: 14px; stroke: currentColor; fill: none; stroke-width: 2; }
        .rcm-edit-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.85rem 0.75rem;
            margin-bottom: 0.95rem;
        }
        .rcm-edit-grid .form-group { margin-bottom: 0; }
        .rcm-edit-grid .form-group label {
            display: block;
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--muted);
            margin-bottom: 0.3rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        .rcm-edit-grid .form-group input,
        .rcm-edit-grid .form-group select {
            width: 100%;
            padding: 0.5rem 0.7rem;
            font-size: 0.85rem;
            font-weight: 600;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            background: #fff;
            color: var(--text);
            transition: border 0.12s ease, box-shadow 0.12s ease;
        }
        .rcm-edit-grid .form-group input:focus,
        .rcm-edit-grid .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99,102,241,0.15);
        }
        .rcm-edit-grid .form-group.full { grid-column: 1 / -1; }
        .rcm-edit-grid .form-group input[type="file"] {
            padding: 0.4rem;
            font-size: 0.78rem;
            font-weight: 500;
            background: #fff;
        }
        .rcm-edit-actions {
            display: flex;
            gap: 0.5rem;
            padding-top: 0.85rem;
            border-top: 1px dashed var(--border);
        }
        .rcm-edit-actions .btn-primary { flex: 1; }

        /* Filters bar */
        .filter-bar {
            display: flex;
            gap: .6rem;
            flex-wrap: wrap;
            align-items: center;
            margin-bottom: 1.25rem;
        }
        .filter-bar select,
        .filter-bar input[type="text"] {
            width: auto;
            min-width: 130px;
            padding: .45rem .75rem;
            font-size: .82rem;
        }

        /* Pagination */
        .pag {
            display: flex;
            align-items: center;
            gap: .4rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }
        .pag a, .pag span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 34px; height: 34px;
            border-radius: 8px;
            border: 1px solid var(--border);
            font-size: .82rem;
            font-weight: 600;
            color: var(--muted);
            text-decoration: none;
            padding: 0 .6rem;
            transition: background .15s, color .15s;
        }
        .pag a:hover { background: var(--primary-soft); color: var(--primary); border-color: transparent; }
        .pag span.current {
            background: var(--primary);
            color: #fff;
            border-color: transparent;
        }
        .pag span.disabled { opacity: .4; cursor: default; }

        /* btn-sm */
        .btn-sm { min-height: 30px; padding: .3rem .75rem; font-size: .78rem; }
    </style>
</head>
<body>
<?= render_sidebar('manage_rooms.php') ?>
<?= render_topbar() ?>
<div class="container">

    <div class="page-header">
        <h1>Manage Rooms</h1>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <!-- Summary stats -->
    <div class="stats-grid" style="margin-bottom:1.5rem;">
        <div class="stat-box stat-indigo">
            <div class="stat-icon-bubble"><?= get_svg_icon('bed') ?></div>
            <div class="stat-label">Total Rooms</div>
            <div class="stat-value"><?= (int)$summary['total'] ?></div>
            <div class="stat-meta">In service</div>
        </div>
        <div class="stat-box stat-green">
            <div class="stat-icon-bubble"><?= get_svg_icon('checkmark') ?></div>
            <div class="stat-label">Available</div>
            <div class="stat-value"><?= (int)$summary['available'] ?></div>
            <div class="stat-meta">Ready to book</div>
        </div>
        <div class="stat-box stat-amber">
            <div class="stat-icon-bubble"><?= get_svg_icon('users') ?></div>
            <div class="stat-label">Occupied</div>
            <div class="stat-value"><?= (int)$summary['occupied'] ?></div>
            <div class="stat-meta">Currently assigned</div>
        </div>
        <div class="stat-box stat-red">
            <div class="stat-icon-bubble"><?= get_svg_icon('alert') ?></div>
            <div class="stat-label">Maintenance</div>
            <div class="stat-value"><?= (int)$summary['maintenance'] ?></div>
            <div class="stat-meta">Temporarily unavailable</div>
        </div>
    </div>

    <!-- Add room panel -->
    <div class="add-panel" id="add-panel">
        <button type="button" class="add-panel-toggle" onclick="toggleAddPanel()">
            <span>Add New Room</span>
            <span class="apt-icon">+</span>
        </button>
        <div class="add-panel-body">
            <form method="POST" enctype="multipart/form-data" style="margin-top:1rem;">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="create">
                <div class="fg3">
                    <div class="form-group">
                        <label>Room Number *</label>
                        <input type="text" name="room_number" placeholder="e.g. 101" required maxlength="20">
                    </div>
                    <div class="form-group">
                        <label>Type</label>
                        <select name="room_type">
                            <option value="Single">Single</option>
                            <option value="Double">Double</option>
                            <option value="Triple">Triple</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Floor</label>
                        <input type="number" name="floor" value="1" min="0" max="20">
                    </div>
                </div>
                <div class="fg3">
                    <div class="form-group">
                        <label>Capacity</label>
                        <input type="number" name="capacity" value="1" min="1" max="10">
                    </div>
                    <div class="form-group">
                        <label>Price (NPR/month)</label>
                        <input type="number" name="price" value="5000" min="0" step="500">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="available">Available</option>
                            <option value="maintenance">Maintenance</option>
                        </select>
                    </div>
                </div>
                <div class="fg2">
                    <div class="form-group">
                        <label>Notes</label>
                        <input type="text" name="notes" placeholder="Optional notes (e.g. attached bathroom)" maxlength="255">
                    </div>
                    <div class="form-group">
                        <label>Room Photo</label>
                        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Create Room</button>
            </form>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-bar">
        <form method="GET" style="display:contents;">
            <select name="status" onchange="this.form.submit()">
                <option value="" <?= $filter_status === '' ? 'selected' : '' ?>>All Status</option>
                <option value="available"   <?= $filter_status === 'available'   ? 'selected' : '' ?>>Available</option>
                <option value="occupied"    <?= $filter_status === 'occupied'    ? 'selected' : '' ?>>Occupied</option>
                <option value="maintenance" <?= $filter_status === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
            </select>
            <select name="floor" onchange="this.form.submit()">
                <option value="" <?= $filter_floor === '' ? 'selected' : '' ?>>All Floors</option>
                <?php foreach ($floors_res as $f): ?>
                    <option value="<?= (int)$f ?>" <?= (string)$filter_floor === (string)$f ? 'selected' : '' ?>>
                        Floor <?= (int)$f ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="type" onchange="this.form.submit()">
                <option value="" <?= $filter_type === '' ? 'selected' : '' ?>>All Types</option>
                <option value="Single" <?= $filter_type === 'Single' ? 'selected' : '' ?>>Single</option>
                <option value="Double" <?= $filter_type === 'Double' ? 'selected' : '' ?>>Double</option>
                <option value="Triple" <?= $filter_type === 'Triple' ? 'selected' : '' ?>>Triple</option>
            </select>
            <?php if ($filter_status || $filter_floor || $filter_type): ?>
                <a href="manage_rooms.php" class="btn btn-ghost btn-sm">Clear</a>
            <?php endif; ?>
        </form>
        <span style="font-size:.82rem;color:var(--muted);margin-left:auto;">
            <?= $total_rooms ?> room<?= $total_rooms !== 1 ? 's' : '' ?>
        </span>
    </div>

    <!-- Room cards -->
    <?php if (empty($rooms)): ?>
        <div class="card" style="text-align:center;padding:3rem;color:var(--muted);">
            No rooms found. Add one above or adjust your filters.
        </div>
    <?php else: ?>
        <div class="room-grid-mgr">
            <?php foreach ($rooms as $r): ?>
                <?php
                    $img_src = $r['photo'] && file_exists($upload_dir . $r['photo'])
                        ? '../uploads/rooms/' . rawurlencode($r['photo'])
                        : room_stock_img($r['room_type']);
                    $edit_id = 'edit-' . $r['id'];
                ?>
                <div class="room-card-mgr" id="card-<?= $r['id'] ?>">
                    <!-- Image with status badge overlay -->
                    <div class="rcm-img-wrap">
                        <img src="<?= $img_src ?>"
                             alt="Room <?= e($r['room_number']) ?>"
                             onerror="this.src='<?= room_stock_img($r['room_type']) ?>'">
                        <div class="rcm-badge"><?= status_badge($r['status']) ?></div>
                    </div>

                    <!-- Card body -->
                    <div class="rcm-body">
                        <div class="rcm-title">Room <?= e($r['room_number']) ?></div>
                        <div class="rcm-meta">
                            <span><?= e($r['room_type']) ?></span>
                            <span>Floor <?= (int)$r['floor'] ?></span>
                            <span><?= (int)$r['capacity'] ?> bed<?= $r['capacity']>1?'s':'' ?></span>
                        </div>
                        <div class="rcm-price">NPR <?= number_format($r['price'], 0) ?><small> /mo</small></div>
                        <!-- Occupant row - always shown for consistent card height -->
                        <div class="rcm-occupant <?= !empty($r['occupant_names']) ? 'filled' : 'empty' ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                 style="width:13px;height:13px;flex-shrink:0;opacity:<?= !empty($r['occupant_names'])?'0.6':'0.25'?>;">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                            </svg>
                            <?= !empty($r['occupant_names']) ? e($r['occupant_names']) : 'Unoccupied' ?>
                        </div>
                        <?php if ($r['notes']): ?>
                            <div style="font-size:.72rem;color:var(--muted);margin-bottom:.5rem;padding:.3rem .5rem;background:var(--panel-alt);border-radius:5px;">
                                <?= e($r['notes']) ?>
                            </div>
                        <?php endif; ?>
                        <div class="rcm-actions">
                            <button type="button" class="btn btn-ghost btn-sm"
                                    onclick="toggleEditForm('<?= $edit_id ?>', this)">
                                Edit
                            </button>
                            <!-- Toggle maintenance -->
                            <?php if ($r['status'] !== 'occupied'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="action"  value="toggle_maintenance">
                                    <input type="hidden" name="room_id" value="<?= $r['id'] ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm">
                                        <?= $r['status'] === 'maintenance' ? 'Set Available' : 'Maintenance' ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                            <!-- Delete -->
                            <?php if ($r['status'] !== 'occupied'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="action"  value="delete">
                                    <input type="hidden" name="room_id" value="<?= $r['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm"
                                            data-confirm="Delete Room <?= e($r['room_number']) ?>? This cannot be undone.">
                                        Delete
                                    </button>
                                </form>
                            <?php else: ?>
                                <span style="font-size:0.78rem;color:var(--muted);">Occupied</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Collapsible edit form -->
                    <div class="rcm-edit-form" id="<?= $edit_id ?>">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action"  value="update">
                            <input type="hidden" name="room_id" value="<?= $r['id'] ?>">

                            <div class="rcm-edit-title">
                                <svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                Edit Room <?= e($r['room_number']) ?>
                            </div>

                            <div class="rcm-edit-grid">
                                <div class="form-group full">
                                    <label>Room Number *</label>
                                    <input type="text" name="room_number"
                                           value="<?= e($r['room_number']) ?>"
                                           required maxlength="20">
                                </div>
                                <div class="form-group">
                                    <label>Type</label>
                                    <select name="room_type">
                                        <?php foreach (['Single','Double','Triple'] as $rt): ?>
                                            <option value="<?= $rt ?>"
                                                <?= $r['room_type'] === $rt ? 'selected' : '' ?>>
                                                <?= $rt ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Floor</label>
                                    <input type="number" name="floor"
                                           value="<?= (int)$r['floor'] ?>" min="0" max="20">
                                </div>
                                <div class="form-group">
                                    <label>Capacity</label>
                                    <input type="number" name="capacity"
                                           value="<?= (int)$r['capacity'] ?>" min="1" max="10">
                                </div>
                                <div class="form-group">
                                    <label>Price (NPR/mo)</label>
                                    <input type="number" name="price"
                                           value="<?= (int)$r['price'] ?>" min="0" step="500">
                                </div>
                                <div class="form-group full">
                                    <label>Status</label>
                                    <select name="status">
                                        <?php foreach (['available','occupied','maintenance'] as $st): ?>
                                            <option value="<?= $st ?>"
                                                <?= $r['status'] === $st ? 'selected' : '' ?>>
                                                <?= ucfirst($st) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group full">
                                    <label>Notes</label>
                                    <input type="text" name="notes"
                                           value="<?= e($r['notes'] ?? '') ?>"
                                           placeholder="Optional notes" maxlength="255">
                                </div>
                                <div class="form-group full">
                                    <label>Replace Photo (optional)</label>
                                    <input type="file" name="photo"
                                           accept="image/jpeg,image/png,image/webp">
                                </div>
                            </div>

                            <div class="rcm-edit-actions">
                                <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
                                <button type="button" class="btn btn-ghost btn-sm"
                                        onclick="toggleEditForm('<?= $edit_id ?>', null, true)">
                                    Cancel
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1):
            $qs = function($pg) use ($filter_status, $filter_floor, $filter_type) {
                $p = ['page' => $pg];
                if ($filter_status) $p['status'] = $filter_status;
                if ($filter_floor)  $p['floor']  = $filter_floor;
                if ($filter_type)   $p['type']   = $filter_type;
                return 'manage_rooms.php?' . http_build_query($p);
            };
        ?>
            <div class="pag">
                <?php if ($page > 1): ?>
                    <a href="<?= $qs($page - 1) ?>">&lsaquo; Prev</a>
                <?php else: ?>
                    <span class="disabled">&lsaquo; Prev</span>
                <?php endif; ?>

                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                    <?php if ($i === $page): ?>
                        <span class="current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="<?= $qs($i) ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="<?= $qs($page + 1) ?>">Next &rsaquo;</a>
                <?php else: ?>
                    <span class="disabled">Next &rsaquo;</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

</div><!-- /.container -->

<script src="../js/script.js"></script>
<script>
// Toggle Add Room panel
function toggleAddPanel() {
    var panel = document.getElementById('add-panel');
    panel.classList.toggle('open');
}

// Toggle edit form on a card
function toggleEditForm(id, triggerBtn, forceClose) {
    var form = document.getElementById(id);
    if (!form) return;
    var isOpen = form.classList.contains('open');
    // Close all other open edit forms
    document.querySelectorAll('.rcm-edit-form.open').forEach(function (f) {
        f.classList.remove('open');
    });
    document.querySelectorAll('.rcm-actions .btn-ghost').forEach(function (b) {
        if (b.textContent.trim() === 'Close') b.textContent = 'Edit';
    });
    if (!isOpen && !forceClose) {
        form.classList.add('open');
        if (triggerBtn) triggerBtn.textContent = 'Close';
        // Scroll card into view
        form.closest('.room-card-mgr').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } else {
        if (triggerBtn) triggerBtn.textContent = 'Edit';
    }
}

// data-confirm is wired by ../js/script.js (uses styled hmsConfirm modal)
</script>
</body>
</html>
