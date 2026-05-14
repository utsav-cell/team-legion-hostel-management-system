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

// Cleanup stray room_id assignments (idempotent — only touches bad rows)
// Clears users.room_id that has no approved/active booking behind it.
try {
    $pdo->exec(
        "UPDATE users u
         SET u.room_id = NULL, u.room_status = NULL
         WHERE u.role = 'student'
           AND u.room_id IS NOT NULL
           AND NOT EXISTS (
               SELECT 1 FROM bookings b
               WHERE b.student_id = u.id AND b.room_id = u.room_id
                 AND b.status IN ('approved','active')
           )"
    );

    // Belt-and-suspenders: enforce capacity. If a room still has more students
    // assigned than its capacity, keep only the oldest N (by users.id) and
    // unassign the rest.
    $overfull = $pdo->query(
        "SELECT r.id, r.capacity
         FROM rooms r
         WHERE (SELECT COUNT(*) FROM users WHERE room_id = r.id) > r.capacity"
    )->fetchAll();
    foreach ($overfull as $r) {
        $keep_stmt = $pdo->prepare(
            "SELECT id FROM users WHERE room_id = ? AND role='student' ORDER BY id ASC LIMIT ?"
        );
        $keep_stmt->bindValue(1, (int)$r['id'], PDO::PARAM_INT);
        $keep_stmt->bindValue(2, (int)$r['capacity'], PDO::PARAM_INT);
        $keep_stmt->execute();
        $keepers = array_column($keep_stmt->fetchAll(), 'id');
        if (count($keepers)) {
            $place = implode(',', array_fill(0, count($keepers), '?'));
            $clear = $pdo->prepare(
                "UPDATE users SET room_id = NULL, room_status = NULL
                 WHERE room_id = ? AND role='student' AND id NOT IN ($place)"
            );
            $clear->execute(array_merge([(int)$r['id']], $keepers));
        }
    }

    // Sync rooms.status to actual occupancy.
    //   - 'occupied' room with fewer students than capacity → 'available'
    //     (covers both empty rooms and partially-filled multi-bed rooms
    //     wrongly stuck on 'occupied' by older approval flows)
    //   - 'available' room that has reached capacity → 'occupied'
    //   - 'maintenance' is left untouched (manual override).
    $pdo->exec(
        "UPDATE rooms r
         SET r.status = 'available'
         WHERE r.status = 'occupied'
           AND (SELECT COUNT(*) FROM users WHERE room_id = r.id AND role='student') < r.capacity"
    );
    $pdo->exec(
        "UPDATE rooms r
         SET r.status = 'occupied'
         WHERE r.status = 'available'
           AND (SELECT COUNT(*) FROM users WHERE room_id = r.id AND role='student') >= r.capacity"
    );
} catch (Exception $e) { /* best-effort cleanup */ }

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

// Summary counts. A room is treated as "occupied" if its status is occupied
// OR it has at least one student living in it (partially filled). Otherwise
// available, with maintenance counted separately.
$summary = $pdo->query(
    "SELECT
        SUM(r.deleted_at IS NULL)                                                 AS total,
        SUM(r.deleted_at IS NULL AND r.status = 'maintenance')                    AS maintenance,
        SUM(r.deleted_at IS NULL AND r.status <> 'maintenance'
            AND (r.status = 'occupied' OR
                 (SELECT COUNT(*) FROM users u WHERE u.room_id = r.id) > 0))      AS occupied,
        SUM(r.deleted_at IS NULL AND r.status = 'available'
            AND (SELECT COUNT(*) FROM users u WHERE u.room_id = r.id) = 0)        AS available
     FROM rooms r"
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

/**
 * Build the status badge. Aware of partial occupancy so a triple room with
 * one student doesn't show as "Available" — that's misleading. We keep the
 * DB status as 'available' until the room is fully booked (so the student
 * browse flow still surfaces it) but reflect the real state in the UI.
 */
function status_badge(string $status, int $occupants = 0, int $capacity = 1): string {
    if ($status === 'maintenance') {
        return '<span class="rcm-status-pill rcm-status-maintenance">Maintenance</span>';
    }
    if ($status === 'occupied' || $occupants >= $capacity) {
        return '<span class="rcm-status-pill rcm-status-occupied">Occupied</span>';
    }
    if ($occupants > 0) {
        return '<span class="rcm-status-pill rcm-status-partial">' . $occupants . '/' . $capacity . ' Filled</span>';
    }
    return '<span class="rcm-status-pill rcm-status-available">Available</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Rooms - HMS</title>
    <link rel="stylesheet" href="../css/style.css?v=34">
    <style>
        /* ── Add room panel — warm, paper-like surface ── */
        .add-panel {
            position: relative;
            background: #fffdf8;
            border: 1px solid rgba(99, 80, 50, 0.09);
            border-radius: 22px;
            margin-bottom: 1.75rem;
            overflow: hidden;
            box-shadow:
                0 1px 0 rgba(255,255,255,0.6) inset,
                0 1px 2px rgba(36, 24, 14, 0.04),
                0 12px 28px -18px rgba(36, 24, 14, 0.18);
            transition: box-shadow .3s ease, border-color .3s ease, transform .3s ease;
        }
        html[data-theme="dark"] .add-panel {
            background: #1a2036;
            border-color: rgba(148,163,184,0.16);
            box-shadow:
                0 1px 0 rgba(255,255,255,0.04) inset,
                0 14px 30px -18px rgba(0,0,0,0.55);
        }
        .add-panel.open {
            border-color: rgba(99, 102, 241, 0.28);
            box-shadow:
                0 1px 0 rgba(255,255,255,0.7) inset,
                0 22px 50px -22px rgba(99,102,241,0.28),
                0 4px 14px -8px rgba(36, 24, 14, 0.12);
        }
        .add-panel-toggle {
            position: relative;
            width: 100%;
            display: flex;
            align-items: center;
            gap: 1.1rem;
            padding: 1.2rem 1.55rem;
            background: transparent;
            border: none;
            font-family: inherit;
            font-size: .95rem;
            font-weight: 700;
            color: var(--text);
            cursor: pointer;
            text-align: left;
            transition: background .25s ease;
        }
        .add-panel-toggle:hover { background: rgba(99, 102, 241, 0.04); }
        html[data-theme="dark"] .add-panel-toggle:hover { background: rgba(99, 102, 241, 0.1); }
        .add-panel-toggle .apt-leading {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 46px; height: 46px;
            border-radius: 14px;
            background: linear-gradient(135deg, #fef3c7, #fed7aa);
            color: #92400e;
            box-shadow:
                0 1px 0 rgba(255,255,255,0.6) inset,
                0 4px 10px -4px rgba(146, 64, 14, 0.35);
            flex-shrink: 0;
            transition: transform .4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        html[data-theme="dark"] .add-panel-toggle .apt-leading {
            background: linear-gradient(135deg, rgba(254,243,199,0.22), rgba(254,215,170,0.16));
            color: #fcd34d;
        }
        .add-panel-toggle .apt-leading svg { width: 21px; height: 21px; stroke: currentColor; fill: none; stroke-width: 2; }
        .add-panel.open .apt-leading { transform: rotate(-6deg) scale(1.05); }
        .add-panel-toggle .apt-text { flex: 1; min-width: 0; }
        .add-panel-toggle .apt-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--text);
            letter-spacing: -0.015em;
            line-height: 1.2;
        }
        .add-panel-toggle .apt-chev {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px; height: 34px;
            border-radius: 50%;
            background: rgba(99, 80, 50, 0.05);
            color: var(--muted);
            font-size: 1.5rem;
            font-weight: 300;
            line-height: 1;
            flex-shrink: 0;
            transition: transform .35s cubic-bezier(0.34, 1.56, 0.64, 1), background .25s ease, color .25s ease;
        }
        html[data-theme="dark"] .add-panel-toggle .apt-chev {
            background: rgba(255,255,255,0.05);
        }
        .add-panel-toggle:hover .apt-chev { background: rgba(99,102,241,0.12); color: var(--primary); }
        .add-panel.open .apt-chev { transform: rotate(45deg); background: rgba(99,102,241,0.12); color: var(--primary); }

        .add-panel-body {
            display: none;
            padding: 1.7rem 1.75rem 1.8rem;
            border-top: 1px solid rgba(99, 80, 50, 0.08);
            background:
                radial-gradient(900px circle at 0% 0%, rgba(254,243,199,0.4), transparent 45%),
                radial-gradient(900px circle at 100% 100%, rgba(99,102,241,0.04), transparent 50%);
        }
        html[data-theme="dark"] .add-panel-body {
            border-top-color: rgba(148,163,184,0.14);
            background:
                radial-gradient(900px circle at 0% 0%, rgba(99,102,241,0.06), transparent 45%),
                radial-gradient(900px circle at 100% 100%, rgba(254,215,170,0.03), transparent 50%);
        }
        .add-panel.open .add-panel-body { display: block; animation: apFadeIn .35s cubic-bezier(0.22, 1, 0.36, 1); }
        @keyframes apFadeIn { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: none; } }

        /* Section sub-headers — softer, sentence-cased, conversational */
        .ap-section-title {
            display: flex;
            align-items: center;
            gap: .6rem;
            font-size: .82rem;
            font-weight: 700;
            color: var(--text);
            letter-spacing: -0.005em;
            margin: .25rem 0 1rem;
            padding-bottom: .6rem;
            border-bottom: 1px solid rgba(99, 80, 50, 0.08);
        }
        html[data-theme="dark"] .ap-section-title { border-bottom-color: rgba(148,163,184,0.14); }
        .ap-section-title svg {
            width: 16px; height: 16px; stroke: var(--primary);
            fill: none; stroke-width: 2;
            padding: 4px; box-sizing: content-box;
            background: rgba(99,102,241,0.1);
            border-radius: 7px;
        }
        .ap-section-title:not(:first-child) { margin-top: 1.7rem; }

        /* ── Type chip selector ── */
        .type-chips {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: .55rem;
        }
        .type-chip {
            position: relative;
            display: flex;
            align-items: center;
            gap: .7rem;
            padding: .85rem 1rem;
            border: 1.5px solid rgba(99, 80, 50, 0.12);
            border-radius: 14px;
            background: #fff;
            font-weight: 700;
            font-size: .88rem;
            color: var(--text);
            cursor: pointer;
            transition: border-color .2s var(--ease-out, ease),
                        background-color .2s ease,
                        transform .2s var(--ease-out, ease),
                        box-shadow .25s ease;
            user-select: none;
        }
        .type-chip input { position: absolute; opacity: 0; pointer-events: none; }
        .type-chip .tc-ico {
            width: 32px; height: 32px;
            border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            color: #92400e;
            flex-shrink: 0;
            transition: background .25s ease, color .25s ease, transform .25s var(--ease-out, ease);
        }
        .type-chip .tc-ico svg { width: 15px; height: 15px; stroke: currentColor; fill: none; stroke-width: 2; }
        .type-chip:hover {
            border-color: rgba(99,102,241,0.4);
            transform: translateY(-2px);
            box-shadow: 0 6px 18px -10px rgba(99,102,241,0.4);
        }
        .type-chip.is-active {
            border-color: var(--primary);
            background: linear-gradient(135deg, #eef2ff, rgba(238,242,255,0.5));
            box-shadow: 0 0 0 4px rgba(99,102,241,0.1);
        }
        .type-chip.is-active .tc-ico {
            background: linear-gradient(135deg, var(--primary), var(--primary-strong));
            color: #fff;
            transform: scale(1.05);
        }
        html[data-theme="dark"] .type-chip {
            background: rgba(255,255,255,0.025);
            border-color: rgba(148,163,184,0.18);
        }
        html[data-theme="dark"] .type-chip.is-active { background: rgba(99,102,241,0.18); }
        html[data-theme="dark"] .type-chip .tc-ico {
            background: linear-gradient(135deg, rgba(254,243,199,0.22), rgba(253,230,138,0.16));
            color: #fcd34d;
        }

        /* ── Form grid ── */
        .fg2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .fg3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; }
        @media (max-width: 640px) {
            .fg2, .fg3 { grid-template-columns: 1fr; }
            .type-chips { grid-template-columns: 1fr; }
        }

        /* Friendlier form fields — sentence-cased labels, generous height */
        .ap-field { position: relative; }
        .ap-field label {
            display: block;
            font-size: .82rem;
            font-weight: 600;
            color: var(--text);
            letter-spacing: -0.005em;
            margin-bottom: .45rem;
        }
        .ap-field .ap-field-with-prefix { position: relative; }
        .ap-field .ap-field-with-prefix .ap-prefix {
            position: absolute;
            top: 50%;
            left: 1rem;
            transform: translateY(-50%);
            font-size: .9rem;
            font-weight: 700;
            color: var(--muted);
            pointer-events: none;
        }
        .ap-field input[type="text"],
        .ap-field input[type="number"],
        .ap-field select {
            width: 100%;
            padding: .8rem 1rem;
            font-family: inherit;
            font-size: .92rem;
            font-weight: 500;
            color: var(--text);
            background: #fff;
            border: 1.5px solid rgba(99, 80, 50, 0.12);
            border-radius: 12px;
            transition: border-color .2s ease, box-shadow .2s ease, background .2s ease;
        }
        .ap-field .ap-field-with-prefix input { padding-left: 2.8rem; }
        .ap-field input::placeholder { color: rgba(100,116,139,0.7); font-weight: 400; }
        .ap-field input:hover,
        .ap-field select:hover { border-color: rgba(99,102,241,0.35); }
        .ap-field input:focus,
        .ap-field select:focus {
            outline: none;
            border-color: var(--primary);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(99,102,241,0.12);
        }
        html[data-theme="dark"] .ap-field input[type="text"],
        html[data-theme="dark"] .ap-field input[type="number"],
        html[data-theme="dark"] .ap-field select {
            background: #232a42; color: #f1f5f9; border-color: rgba(148,163,184,0.22);
        }
        html[data-theme="dark"] .ap-field input:focus,
        html[data-theme="dark"] .ap-field select:focus {
            background: #2a3148;
        }

        /* Photo dropzone — warm sketch-card feel */
        .ap-dropzone {
            position: relative;
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1.05rem 1.15rem;
            border: 1.6px dashed rgba(146, 64, 14, 0.28);
            border-radius: 14px;
            background:
                linear-gradient(135deg, rgba(254,243,199,0.4), rgba(254,243,199,0.1)),
                #fffdf8;
            cursor: pointer;
            transition: background .22s ease, border-color .22s ease, transform .22s ease;
        }
        .ap-dropzone:hover {
            border-color: rgba(99,102,241,0.5);
            background: linear-gradient(135deg, rgba(99,102,241,0.06), rgba(99,102,241,0.02)), #fff;
            transform: translateY(-1px);
        }
        .ap-dropzone input[type="file"] {
            position: absolute; inset: 0; opacity: 0; cursor: pointer;
        }
        html[data-theme="dark"] .ap-dropzone {
            background:
                linear-gradient(135deg, rgba(254,215,170,0.05), rgba(254,215,170,0.02)),
                rgba(255,255,255,0.025);
            border-color: rgba(148,163,184,0.28);
        }
        .ap-dz-icon {
            width: 44px; height: 44px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            background: #fff;
            color: #92400e;
            border: 1.5px solid rgba(146, 64, 14, 0.18);
            flex-shrink: 0;
            box-shadow: 0 1px 0 rgba(255,255,255,0.6) inset, 0 2px 6px -2px rgba(146,64,14,0.18);
            transition: color .25s ease, border-color .25s ease;
        }
        .ap-dropzone:hover .ap-dz-icon { color: var(--primary); border-color: rgba(99,102,241,0.32); }
        html[data-theme="dark"] .ap-dz-icon { background: rgba(255,255,255,0.06); color: #fcd34d; }
        .ap-dz-icon svg { width: 19px; height: 19px; stroke: currentColor; fill: none; stroke-width: 2; }
        .ap-dz-text { flex: 1; min-width: 0; }
        .ap-dz-title { font-size: .9rem; font-weight: 700; color: var(--text); letter-spacing: -0.005em; }
        .ap-dz-sub { font-size: .76rem; color: var(--muted); margin-top: 3px; }
        .ap-dz-file { font-size: .8rem; font-weight: 600; color: var(--primary); margin-top: 5px; word-break: break-all; display: none; }
        .ap-dropzone.has-file .ap-dz-file { display: block; }

        /* Submit row */
        .ap-submit-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-top: 1.75rem;
            padding-top: 1.25rem;
            border-top: 1px solid rgba(99, 80, 50, 0.08);
            flex-wrap: wrap;
        }
        html[data-theme="dark"] .ap-submit-row { border-top-color: rgba(148,163,184,0.14); }
        .ap-submit-row .ap-hint {
            font-size: .8rem;
            color: var(--muted);
            display: inline-flex; align-items: center; gap: .45rem;
        }
        .ap-submit-row .ap-hint svg { width: 14px; height: 14px; stroke: currentColor; fill: none; stroke-width: 2; }
        .ap-submit-btn {
            display: inline-flex;
            align-items: center;
            gap: .55rem;
            padding: .85rem 1.7rem;
            font-size: .92rem;
            font-weight: 700;
            border-radius: 12px;
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            color: #fff;
            border: none;
            cursor: pointer;
            letter-spacing: -0.005em;
            box-shadow:
                0 1px 0 rgba(255,255,255,0.18) inset,
                0 10px 24px -10px rgba(79,70,229,0.6),
                0 2px 4px rgba(79,70,229,0.18);
            transition: transform .18s var(--ease-out, ease),
                        box-shadow .25s ease,
                        filter .2s ease;
        }
        .ap-submit-btn:hover {
            transform: translateY(-2px);
            box-shadow:
                0 1px 0 rgba(255,255,255,0.22) inset,
                0 16px 30px -10px rgba(79,70,229,0.75),
                0 4px 8px rgba(79,70,229,0.22);
            filter: brightness(1.05);
        }
        .ap-submit-btn:active { transform: translateY(0); }
        .ap-submit-btn svg { width: 15px; height: 15px; stroke: currentColor; fill: none; stroke-width: 2.4; }

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
        .rcm-status-partial     { color: #b45309; }
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

        .rcm-actions {
            display: flex;
            gap: .4rem;
            flex-wrap: wrap;
            align-items: center;
            margin-top: auto;
            padding-top: .6rem;
            border-top: 1px solid var(--border);
        }

        /* Inline edit form — works in both light + dark mode */
        .rcm-edit-form {
            display: none;
            border-top: 1px solid var(--border);
            padding: 1.2rem 1.25rem 1.25rem;
            background: var(--panel-alt);
        }
        html[data-theme="dark"] .rcm-edit-form { background: rgba(255,255,255,0.025); }
        .rcm-edit-form.open { display: block; }
        .rcm-edit-form .rcm-edit-title {
            display: flex; align-items: center; gap: 0.55rem;
            font-size: 0.78rem;
            font-weight: 800;
            color: var(--text);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 1rem;
            padding-bottom: 0.7rem;
            border-bottom: 1px dashed var(--border);
        }
        .rcm-edit-form .rcm-edit-title svg {
            width: 14px; height: 14px; stroke: var(--primary); fill: none; stroke-width: 2;
        }
        .rcm-edit-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.9rem 0.85rem;
            margin-bottom: 1.1rem;
        }
        .rcm-edit-grid .form-group { margin-bottom: 0; min-width: 0; }
        .rcm-edit-grid .form-group label {
            display: block;
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--muted);
            margin-bottom: 0.35rem;
            text-transform: uppercase;
            letter-spacing: 0.07em;
        }
        .rcm-edit-grid .form-group input,
        .rcm-edit-grid .form-group select {
            width: 100%;
            padding: 0.55rem 0.75rem;
            font-size: 0.88rem;
            font-weight: 600;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            background: var(--panel);
            color: var(--text);
            font-family: inherit;
            transition: border 0.12s ease, box-shadow 0.12s ease;
        }
        html[data-theme="dark"] .rcm-edit-grid .form-group input,
        html[data-theme="dark"] .rcm-edit-grid .form-group select {
            background: #232a42;
            color: #f1f5f9;
            border-color: rgba(148,163,184,0.28);
        }
        .rcm-edit-grid .form-group input:focus,
        .rcm-edit-grid .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99,102,241,0.18);
        }
        .rcm-edit-grid .form-group.full { grid-column: 1 / -1; }
        .rcm-edit-grid .form-group input[type="file"] {
            padding: 0.45rem;
            font-size: 0.78rem;
            font-weight: 500;
        }
        html[data-theme="dark"] .rcm-edit-grid .form-group input[type="file"] {
            background: #1a2036;
            color: #cbd5e1;
        }
        .rcm-edit-actions {
            display: flex;
            gap: 0.55rem;
            padding-top: 0.95rem;
            border-top: 1px dashed var(--border);
        }
        .rcm-edit-actions .btn-primary { flex: 1; }
        .rcm-edit-actions .btn { font-size: 0.85rem; padding: 0.55rem 1.1rem; min-height: 0; border-radius: 9px; font-weight: 700; }

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
        <button type="button" class="add-panel-toggle" onclick="toggleAddPanel()" aria-expanded="false">
            <span class="apt-leading">
                <svg viewBox="0 0 24 24"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V9.5z"/></svg>
            </span>
            <span class="apt-text">
                <span class="apt-title">Add a New Room</span>
            </span>
            <span class="apt-chev">+</span>
        </button>
        <div class="add-panel-body">
            <form method="POST" enctype="multipart/form-data" id="ap-form">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="create">

                <!-- Room type chips -->
                <div class="ap-section-title">
                    <svg viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M3 11h18M7 7V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v2"/></svg>
                    Room Type
                </div>
                <div class="type-chips" id="ap-type-chips">
                    <label class="type-chip is-active" data-cap="1">
                        <input type="radio" name="room_type" value="Single" checked>
                        <span class="tc-ico"><svg viewBox="0 0 24 24"><path d="M20 9V7a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v2"/><path d="M2 11h20v6a2 2 0 0 1-2 2h0v2M4 19v2"/></svg></span>
                        <span>Single<small style="display:block;font-size:.68rem;color:var(--muted);font-weight:500;margin-top:1px;">1 bed</small></span>
                    </label>
                    <label class="type-chip" data-cap="2">
                        <input type="radio" name="room_type" value="Double">
                        <span class="tc-ico"><svg viewBox="0 0 24 24"><path d="M2 12h20M2 17h20M5 12V8a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v4"/></svg></span>
                        <span>Double<small style="display:block;font-size:.68rem;color:var(--muted);font-weight:500;margin-top:1px;">2 beds</small></span>
                    </label>
                    <label class="type-chip" data-cap="3">
                        <input type="radio" name="room_type" value="Triple">
                        <span class="tc-ico"><svg viewBox="0 0 24 24"><circle cx="9" cy="7" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3 21v-1a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v1M21 21v-1a3 3 0 0 0-3-3"/></svg></span>
                        <span>Triple<small style="display:block;font-size:.68rem;color:var(--muted);font-weight:500;margin-top:1px;">3 beds</small></span>
                    </label>
                </div>

                <!-- Core details -->
                <div class="ap-section-title">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                    The basics
                </div>
                <div class="fg3">
                    <div class="ap-field">
                        <label>Room Number *</label>
                        <input type="text" name="room_number" placeholder="e.g. 101" required maxlength="20">
                    </div>
                    <div class="ap-field">
                        <label>Floor</label>
                        <input type="number" name="floor" value="1" min="0" max="20">
                    </div>
                    <div class="ap-field">
                        <label>Capacity</label>
                        <input type="number" name="capacity" id="ap-capacity" value="1" min="1" max="10">
                    </div>
                </div>

                <div class="fg2" style="margin-top:1rem;">
                    <div class="ap-field">
                        <label>Monthly Price (NPR)</label>
                        <div class="ap-field-with-prefix">
                            <span class="ap-prefix">रू</span>
                            <input type="number" name="price" value="5000" min="0" step="500">
                        </div>
                    </div>
                    <div class="ap-field">
                        <label>Initial Status</label>
                        <select name="status">
                            <option value="available">Available — open for booking</option>
                            <option value="maintenance">Maintenance — hidden from students</option>
                        </select>
                    </div>
                </div>

                <!-- Notes & photo -->
                <div class="ap-section-title">
                    <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    A bit more
                </div>
                <div class="fg2">
                    <div class="ap-field">
                        <label>Anything worth knowing?</label>
                        <input type="text" name="notes" placeholder="attached bathroom, balcony, sunny side…" maxlength="255">
                    </div>
                    <div class="ap-field">
                        <label>A photo of the room</label>
                        <label class="ap-dropzone" id="ap-dropzone">
                            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" id="ap-file">
                            <span class="ap-dz-icon">
                                <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            </span>
                            <span class="ap-dz-text">
                                <span class="ap-dz-title">Pick one from your computer</span>
                                <span class="ap-dz-sub">jpg, png or webp &middot; keep it under 5 MB</span>
                                <span class="ap-dz-file" id="ap-dz-file"></span>
                            </span>
                        </label>
                    </div>
                </div>

                <!-- Submit row -->
                <div class="ap-submit-row">
                    <span class="ap-hint">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                        Picking a type sets the bed count — change it any time
                    </span>
                    <button type="submit" class="ap-submit-btn">
                        <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                        Add this room
                    </button>
                </div>
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
                        <div class="rcm-badge"><?= status_badge($r['status'], (int)$r['occupant_count'], (int)$r['capacity']) ?></div>
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
                        <!-- Occupant row - reserved space kept for consistent card height even when empty -->
                        <?php if (!empty($r['occupant_names'])): ?>
                            <div class="rcm-occupant filled">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                     style="width:13px;height:13px;flex-shrink:0;opacity:0.6;">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                                </svg>
                                <?= e($r['occupant_names']) ?>
                            </div>
                        <?php else: ?>
                            <div class="rcm-occupant" aria-hidden="true"></div>
                        <?php endif; ?>
                        <?php if ($r['notes']): ?>
                            <div style="font-size:.72rem;color:var(--muted);margin-bottom:.5rem;padding:.3rem .5rem;background:var(--panel-alt);border-radius:5px;">
                                <?= e($r['notes']) ?>
                            </div>
                        <?php endif; ?>
                        <?php
                            // A room that has any students living in it (even partially
                            // filled triples/doubles) cannot be deleted or moved to
                            // maintenance without first relocating those students.
                            $has_occupants = (int)$r['occupant_count'] > 0;
                            $is_locked     = $has_occupants || $r['status'] === 'occupied';
                        ?>
                        <div class="rcm-actions">
                            <button type="button" class="btn btn-ghost btn-sm"
                                    onclick="toggleEditForm('<?= $edit_id ?>', this)">
                                Edit
                            </button>
                            <!-- Toggle maintenance -->
                            <?php if (!$is_locked): ?>
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
                            <?php if (!$is_locked): ?>
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
                                <span style="font-size:0.78rem;color:var(--muted);">In use</span>
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
    var btn   = panel.querySelector('.add-panel-toggle');
    panel.classList.toggle('open');
    if (btn) btn.setAttribute('aria-expanded', panel.classList.contains('open') ? 'true' : 'false');
}

// Room-type chip selector: clicking a chip selects its radio, applies active
// styling, and syncs the capacity field to the chip's default bed count.
(function () {
    var chipsWrap = document.getElementById('ap-type-chips');
    var capInput  = document.getElementById('ap-capacity');
    if (!chipsWrap) return;
    chipsWrap.querySelectorAll('.type-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            chipsWrap.querySelectorAll('.type-chip').forEach(function (c) {
                c.classList.remove('is-active');
            });
            chip.classList.add('is-active');
            var radio = chip.querySelector('input[type="radio"]');
            if (radio) radio.checked = true;
            if (capInput) {
                var cap = chip.getAttribute('data-cap');
                if (cap) capInput.value = cap;
            }
        });
    });
})();

// File dropzone — show selected file name
(function () {
    var dz = document.getElementById('ap-dropzone');
    var fi = document.getElementById('ap-file');
    var fn = document.getElementById('ap-dz-file');
    if (!dz || !fi || !fn) return;
    fi.addEventListener('change', function () {
        if (fi.files && fi.files[0]) {
            fn.textContent = fi.files[0].name;
            dz.classList.add('has-file');
        } else {
            fn.textContent = '';
            dz.classList.remove('has-file');
        }
    });
})();

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
