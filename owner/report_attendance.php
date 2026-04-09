<?php
// ─────────────────────────────────────────────────
// owner/report_attendance.php — Full Attendance Report
// ─────────────────────────────────────────────────

require_once '../db.php';
require_role('owner');

$name = $_SESSION['user_name'];

// Safe validated filters
$f_date    = trim($_GET['filter_date']    ?? '');
$f_status  = trim($_GET['filter_status']  ?? '');
$f_student = trim($_GET['filter_student'] ?? '');

// Validate date format
if ($f_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date)) {
    $f_date = '';
}
// Validate status value
if (!in_array($f_status, ['', 'present', 'absent'])) {
    $f_status = '';
}

// Build query with prepared statement
$params      = [];
$param_types = '';
$where       = ["u.role = 'student'"];

if ($f_date) {
    $where[]      = "a.date = ?";
    $params[]     = $f_date;
    $param_types .= 's';
}
if ($f_status) {
    $where[]      = "a.status = ?";
    $params[]     = $f_status;
    $param_types .= 's';
}
if ($f_student) {
    $where[]      = "u.name LIKE ?";
    $like         = '%' . $f_student . '%';
    $params[]     = $like;
    $param_types .= 's';
}

$sql = "SELECT u.name, u.student_phone, a.date, a.status
        FROM users u
        LEFT JOIN attendance a ON u.id = a.student_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY a.date DESC, u.name ASC";

$stmt = mysqli_prepare($conn, $sql);
if ($params) {
    mysqli_stmt_bind_param($stmt, $param_types, ...$params);
}
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$records = [];
$total = $present = 0;
while ($r = mysqli_fetch_assoc($res)) {
    $records[] = $r;
    if ($r['date']) {
        $total++;
        if ($r['status'] === 'present') $present++;
    }
}
mysqli_stmt_close($stmt);
$absent = $total - $present;

// Get distinct dates for filter dropdown
$dates = [];
$dr = mysqli_query($conn,
    "SELECT DISTINCT date FROM attendance
     ORDER BY date DESC");
while ($d = mysqli_fetch_assoc($dr)) $dates[] = $d['date'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Report — HMS</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        @media print {
            .no-print { display:none !important; }
            .container { margin:0; padding:0; max-width:100%; }
            body { background:#fff; }
        }
    </style>
</head>
<body>
<?php echo render_sidebar('report_attendance.php'); ?>
<div class="container">
    <div class="page-header">
        <h1>Attendance Report</h1>
        <p>Full attendance records for all students</p>
    </div>

    <!-- Summary Cards -->
    <div class="stats-grid">
        <div class="stat-box">
            <div class="stat-label">Total Records</div>
            <div class="stat-value"><?= $total ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Present</div>
            <div class="stat-value" style="color:#10b981;">
                <?= $present ?>
            </div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Absent</div>
            <div class="stat-value" style="color:#ef4444;">
                <?= $absent ?>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card no-print">
        <div class="card-header">
            <h2 class="card-title">Filter Records</h2>
            <button onclick="window.print()"
                    class="btn btn-secondary"
                    style="font-size:0.8rem;">Print Report</button>
        </div>
        <form method="get"
              style="display:flex; gap:1rem; flex-wrap:wrap; align-items:flex-end;">
            <div class="form-group" style="margin-bottom:0; flex:1; min-width:140px;">
                <label>By Date</label>
                <select name="filter_date"
                        style="width:100%; padding:0.5rem;
                               border:1px solid var(--border); border-radius:8px;">
                    <option value="">All Dates</option>
                    <?php foreach ($dates as $dt): ?>
                        <option value="<?= e($dt) ?>"
                            <?= $f_date === $dt ? 'selected' : '' ?>>
                            <?= e(date('d M Y', strtotime($dt))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:0; flex:1; min-width:120px;">
                <label>By Status</label>
                <select name="filter_status"
                        style="width:100%; padding:0.5rem;
                               border:1px solid var(--border); border-radius:8px;">
                    <option value="">All</option>
                    <option value="present" <?= $f_status === 'present' ? 'selected' : '' ?>>
                        Present
                    </option>
                    <option value="absent"  <?= $f_status === 'absent'  ? 'selected' : '' ?>>
                        Absent
                    </option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:0; flex:2; min-width:160px;">
                <label>Search Student</label>
                <input type="text" name="filter_student"
                       value="<?= e($f_student) ?>"
                       placeholder="Search by name..."
                       style="width:100%; padding:0.5rem;
                              border:1px solid var(--border); border-radius:8px;">
            </div>
            <button type="submit" class="btn btn-primary"
                    style="padding:0.5rem 1.25rem;">Filter</button>
            <a href="report_attendance.php"
               class="btn btn-secondary"
               style="padding:0.5rem 1.25rem;">Reset</a>
        </form>
    </div>

    <!-- Records Table -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Attendance Records</h2>
            <span style="font-size:0.8rem; color:var(--text-muted);">
                <?= $total ?> record(s) found
            </span>
        </div>
        <?php if ($records): ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student Name</th>
                        <th>Phone</th>
                        <th>Date</th>
                        <th>Day</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($records as $i => $r): ?>
                    <tr>
                        <td style="color:var(--text-muted);">
                            <?= $i + 1 ?>
                        </td>
                        <td style="font-weight:700;">
                            <?= e($r['name']) ?>
                        </td>
                        <td style="color:var(--text-muted);">
                            <?= e($r['student_phone'] ?? '—') ?>
                        </td>
                        <td style="font-weight:600;">
                            <?= $r['date']
                                ? e(date('d M Y', strtotime($r['date'])))
                                : '—' ?>
                        </td>
                        <td style="color:var(--text-muted);">
                            <?= $r['date']
                                ? e(date('l', strtotime($r['date'])))
                                : '—' ?>
                        </td>
                        <td>
                            <?php if ($r['status']): ?>
                                <span class="badge badge-<?= $r['status'] === 'present'
                                    ? 'green' : 'red' ?>">
                                    <?= e(ucfirst($r['status'])) ?>
                                </span>
                            <?php else: ?>
                                <span style="color:var(--text-muted);">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color:var(--text-muted); text-align:center; padding:2rem;">
                No attendance records found.
            </p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
