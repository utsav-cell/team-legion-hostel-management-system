<?php
// ─────────────────────────────────────────────────
// owner/report_attendance.php — Full Attendance Report
// ─────────────────────────────────────────────────

require_once '../db.php';
require_role_any(['owner', 'warden']);

$name = $_SESSION['user_name'];

// Safe validated filters
$f_date    = trim($_GET['filter_date']    ?? '');
$f_status  = trim($_GET['filter_status']  ?? '');
$f_student = trim($_GET['filter_student'] ?? '');

if ($f_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date)) $f_date = '';
if (!in_array($f_status, ['', 'present', 'absent'])) $f_status = '';

// ── Per-student attendance percentage (for donut buckets) ─────────────────────
$all_students_raw = $pdo->query(
    "SELECT u.id, u.name
     FROM users u WHERE u.role='student' ORDER BY u.name ASC"
)->fetchAll();

$total_days_stmt = $pdo->query("SELECT COUNT(DISTINCT date) AS d FROM attendance");
$days_marked     = (int)($total_days_stmt->fetch()['d'] ?? 0);
$total_students  = count($all_students_raw);

// Bucket counters for donut
$bucket_excellent = 0; // >= 90%
$bucket_good      = 0; // 70–89%
$bucket_average   = 0; // 50–69%
$bucket_poor      = 0; // < 50%
$bucket_unmarked  = 0; // no attendance at all

$grand_present    = 0;
$grand_total_rec  = 0;

if ($days_marked > 0) {
    $per_student_stmt = $pdo->query(
        "SELECT student_id,
                SUM(status='present') AS present_days,
                COUNT(*) AS total_days
         FROM attendance
         GROUP BY student_id"
    );
    $per_student = [];
    foreach ($per_student_stmt->fetchAll() as $r) {
        $per_student[(int)$r['student_id']] = $r;
        $grand_present   += (int)$r['present_days'];
        $grand_total_rec += (int)$r['total_days'];
    }

    foreach ($all_students_raw as $s) {
        $sid = (int)$s['id'];
        if (!isset($per_student[$sid])) {
            $bucket_unmarked++;
            continue;
        }
        $pct = ($per_student[$sid]['total_days'] > 0)
            ? (int)$per_student[$sid]['present_days'] / $per_student[$sid]['total_days'] * 100
            : 0;
        if ($pct >= 90)      $bucket_excellent++;
        elseif ($pct >= 70)  $bucket_good++;
        elseif ($pct >= 50)  $bucket_average++;
        else                 $bucket_poor++;
    }
} else {
    $bucket_unmarked = $total_students;
}

$overall_pct = ($grand_total_rec > 0)
    ? round($grand_present / $grand_total_rec * 100, 1) : 0;

// ── SVG donut helper ───────────────────────────────────────────────────────────
function report_donut_svg(array $segs): string {
    $total = array_sum(array_column($segs, 'val'));
    $r = 75; $cx = 95; $cy = 95;
    $circ = 2 * M_PI * $r;
    if ($total === 0) {
        return sprintf('<circle cx="%d" cy="%d" r="%d" fill="none" stroke="#e2e8f0" stroke-width="20"/>', $cx, $cy, $r);
    }
    $svg = '';
    $offset = 0;
    foreach ($segs as $seg) {
        if ($seg['val'] <= 0) continue;
        $pct  = $seg['val'] / $total;
        $dash = $pct * $circ;
        $gap  = $circ - $dash;
        $rot  = -90 + ($offset / $total) * 360;
        $svg .= sprintf(
            '<circle cx="%d" cy="%d" r="%d" fill="none" stroke="%s" stroke-width="20" '
          . 'stroke-dasharray="%.4f %.4f" '
          . 'transform="rotate(%.4f %d %d)"/>',
            $cx, $cy, $r, $seg['color'],
            $dash, $gap,
            $rot, $cx, $cy
        );
        $offset += $seg['val'];
    }
    return $svg;
}

$donut_segs = [
    ['val' => $bucket_excellent, 'color' => '#10b981', 'label' => '90–100%'],
    ['val' => $bucket_good,      'color' => '#3b82f6', 'label' => '70–89%'],
    ['val' => $bucket_average,   'color' => '#f59e0b', 'label' => '50–69%'],
    ['val' => $bucket_poor,      'color' => '#ef4444', 'label' => '<50%'],
    ['val' => $bucket_unmarked,  'color' => '#e2e8f0', 'label' => 'Unmarked'],
];

// ── Per-student aggregated records (one row per student) ─────────────────────
$today_d = date('Y-m-d');
$f_from  = trim($_GET['from'] ?? date('Y-m-d', strtotime('-29 days')));
$f_to    = trim($_GET['to']   ?? $today_d);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_from)) $f_from = date('Y-m-d', strtotime('-29 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_to))   $f_to   = $today_d;
if ($f_from > $f_to) [$f_from,$f_to] = [$f_to,$f_from];

$sql = "SELECT u.id, u.name, u.email, u.student_phone, u.photo,
               COUNT(a.id) AS marked,
               SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) AS present_cnt,
               SUM(CASE WHEN a.status='absent'  THEN 1 ELSE 0 END) AS absent_cnt,
               MAX(CASE WHEN a.status='present' THEN a.date END)    AS last_present
        FROM users u
        LEFT JOIN attendance a ON a.student_id=u.id AND a.date BETWEEN ? AND ?
        WHERE u.role='student'";
$sq_params = [$f_from, $f_to];
if ($f_student) { $sql .= " AND u.name LIKE ?"; $sq_params[] = '%'.$f_student.'%'; }
$sql .= " GROUP BY u.id ORDER BY u.name ASC";

$stmt = $pdo->prepare($sql); $stmt->execute($sq_params);
$all_rows = $stmt->fetchAll();

// Compute pct + bucket per student
foreach ($all_rows as &$row) {
    $m = (int)$row['marked']; $p = (int)$row['present_cnt'];
    $row['pct'] = $m > 0 ? round($p/$m*100) : null;
    $row['bucket'] = match(true) {
        $row['pct'] === null => 'unmarked',
        $row['pct'] >= 90   => 'excellent',
        $row['pct'] >= 70   => 'good',
        $row['pct'] >= 50   => 'average',
        default             => 'poor',
    };
}
unset($row);

// Pagination
$per_page   = 25;
$page       = max(1,(int)($_GET['page']??1));
$total_rows = count($all_rows);
$total_pages = max(1,(int)ceil($total_rows/$per_page));
$page = min($page,$total_pages);
$paged_rows = array_slice($all_rows,($page-1)*$per_page,$per_page);

function bucket_color_r(string $b): string {
    return match($b) { 'excellent'=>'#10b981','good'=>'#3b82f6','average'=>'#f59e0b','poor'=>'#ef4444',default=>'#94a3b8' };
}
function bucket_label_r(string $b): string {
    return match($b) { 'excellent'=>'Excellent','good'=>'Good','average'=>'Average','poor'=>'Poor',default=>'No data' };
}
function student_inits_r(string $n): string {
    $p=array_values(array_filter(explode(' ',trim($n))));
    return strtoupper(substr($p[0]??'S',0,1)).strtoupper(substr($p[1]??'',0,1));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Report — HMS</title>
    <link rel="stylesheet" href="../css/style.css?v=18">
    <style>
        /* ── Summary card ── */
        .rep-summary-card {
            background: #fff;
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-soft);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        .rep-summary-head {
            padding: 1rem 1.25rem 0.75rem;
            border-bottom: 1px solid var(--border);
        }
        .rep-summary-title { font-size: 0.82rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; color: var(--muted); }

        /* Overall stats row */
        .rep-stats-row {
            display: flex;
            gap: 0;
            border-bottom: 1px solid var(--border);
        }
        .rep-stat-cell {
            flex: 1;
            padding: 0.9rem 1.25rem;
            border-right: 1px solid var(--border);
            text-align: center;
        }
        .rep-stat-cell:last-child { border-right: none; }
        .rep-stat-val { font-size: 1.65rem; font-weight: 900; letter-spacing: -0.03em; color: var(--text); line-height: 1.1; }
        .rep-stat-lbl { font-size: 0.72rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.06em; margin-top: 3px; }

        /* Donut + legend row */
        .rep-chart-row {
            display: grid;
            grid-template-columns: 200px 1px 1fr;
            align-items: center;
            padding: 1.25rem 1.5rem;
            gap: 1.5rem;
        }
        .rep-divider { background: var(--border); align-self: stretch; }
        .rep-legend {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.6rem 1.25rem;
        }
        .rep-legend-row { display: flex; align-items: center; gap: 0.55rem; font-size: 0.82rem; color: var(--muted); }
        .rep-legend-dot { width: 11px; height: 11px; border-radius: 50%; flex-shrink: 0; }
        .rep-legend-lbl { font-weight: 600; color: var(--text); }
        .rep-legend-count { font-weight: 800; color: var(--text); font-size: 0.9rem; }
        .rep-legend-sub  { font-size: 0.72rem; color: var(--muted); }

        @media (max-width: 700px) {
            .rep-chart-row { grid-template-columns: 1fr; }
            .rep-divider { display: none; }
            .rep-stats-row { flex-wrap: wrap; }
            .rep-stat-cell { flex: 1 1 40%; border-bottom: 1px solid var(--border); }
        }

        @media print {
            .no-print { display: none !important; }
            .container { margin: 0; padding: 0; max-width: 100%; }
            body { background: #fff; }
        }
    </style>
</head>
<body>
<?php echo render_sidebar('report_attendance.php'); ?>
<?= render_topbar() ?>
<div class="container">
    <div class="page-header">
        <h1>Attendance Report</h1>
    </div>

    <!-- ── Summary card ── -->
    <div class="rep-summary-card">
        <div class="rep-summary-head">
            <span class="rep-summary-title">Attendance Overview</span>
        </div>

        <!-- Overall stats row -->
        <div class="rep-stats-row">
            <div class="rep-stat-cell">
                <div class="rep-stat-val"><?= $total_students ?></div>
                <div class="rep-stat-lbl">Total Students</div>
            </div>
            <div class="rep-stat-cell">
                <div class="rep-stat-val"><?= $days_marked ?></div>
                <div class="rep-stat-lbl">Days Marked</div>
            </div>
            <div class="rep-stat-cell">
                <div class="rep-stat-val" style="color:#10b981;"><?= $grand_present ?></div>
                <div class="rep-stat-lbl">Total Present</div>
            </div>
            <div class="rep-stat-cell">
                <div class="rep-stat-val" style="color:<?= $overall_pct >= 70 ? '#10b981' : ($overall_pct >= 50 ? '#f59e0b' : '#ef4444') ?>;">
                    <?= $overall_pct ?>%
                </div>
                <div class="rep-stat-lbl">Overall %</div>
            </div>
        </div>

        <!-- Donut + legend -->
        <div class="rep-chart-row">
            <!-- Donut SVG (170×170 viewBox, stroke-width 20) -->
            <div style="display:flex;flex-direction:column;align-items:center;gap:0.5rem;">
                <svg viewBox="0 0 190 190" width="180" height="180">
                    <?= report_donut_svg($donut_segs) ?>
                    <text x="95" y="100" text-anchor="middle" font-size="28" font-weight="900" fill="var(--text)" font-family="inherit"><?= $total_students ?></text>
                    <text x="95" y="117" text-anchor="middle" font-size="10" fill="#64748b" font-family="inherit" font-weight="700" letter-spacing="0.08em">STUDENTS</text>
                </svg>
            </div>

            <div class="rep-divider"></div>

            <!-- 2-column legend -->
            <div class="rep-legend">
                <?php
                $legend_items = [
                    ['color' => '#10b981', 'label' => 'Excellent', 'range' => '90–100%', 'count' => $bucket_excellent],
                    ['color' => '#3b82f6', 'label' => 'Good',      'range' => '70–89%',  'count' => $bucket_good],
                    ['color' => '#f59e0b', 'label' => 'Average',   'range' => '50–69%',  'count' => $bucket_average],
                    ['color' => '#ef4444', 'label' => 'Poor',      'range' => '<50%',    'count' => $bucket_poor],
                    ['color' => '#e2e8f0', 'label' => 'Unmarked',  'range' => 'No data', 'count' => $bucket_unmarked],
                ];
                foreach ($legend_items as $li):
                ?>
                    <div class="rep-legend-row">
                        <span class="rep-legend-dot" style="background:<?= $li['color'] ?>;<?= $li['color'] === '#e2e8f0' ? 'border:1.5px solid #cbd5e1;' : '' ?>"></span>
                        <div>
                            <div style="display:flex;align-items:baseline;gap:0.35rem;">
                                <span class="rep-legend-count"><?= $li['count'] ?></span>
                                <span class="rep-legend-sub">students</span>
                            </div>
                            <div style="font-size:0.75rem;color:var(--muted);line-height:1.2;">
                                <strong style="color:var(--text);font-weight:700;"><?= $li['label'] ?></strong>
                                &nbsp;<?= $li['range'] ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
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
                    <option value="present" <?= $f_status === 'present' ? 'selected' : '' ?>>Present</option>
                    <option value="absent"  <?= $f_status === 'absent'  ? 'selected' : '' ?>>Absent</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:0; flex:2; min-width:160px;">
                <label>Search Student</label>
                <input type="text" name="filter_student"
                       value="<?= e($f_student) ?>"
                       placeholder="Search by name…"
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

    <!-- Per-student aggregated table -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Student Attendance</h2>
            <span style="font-size:0.8rem;color:var(--muted);">
                <?= $total_rows ?> student<?= $total_rows!==1?'s':'' ?>
                &nbsp;&bull;&nbsp; <?= date('d M Y',strtotime($f_from)) ?> to <?= date('d M Y',strtotime($f_to)) ?>
            </span>
        </div>
        <?php if ($paged_rows): ?>
            <div class="table-wrap"><table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student</th>
                        <th>Days Marked</th>
                        <th>Present</th>
                        <th>Absent</th>
                        <th>Last Present</th>
                        <th>Rate</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($paged_rows as $i => $r):
                    $pct = $r['pct'];
                    $color = bucket_color_r($r['bucket']);
                    $absnum = ($page-1)*$per_page + $i + 1;
                    $photo = $r['photo'] ?? 'default.png';
                ?>
                    <tr>
                        <td style="color:var(--muted);font-size:0.82rem;"><?= $absnum ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:0.6rem;">
                                <?php if($photo&&$photo!=='default.png'): ?>
                                    <img src="../uploads/students/<?=e($photo)?>" style="width:32px;height:32px;border-radius:50%;object-fit:cover;" onerror="this.style.display='none'">
                                <?php else: ?>
                                    <div style="width:32px;height:32px;border-radius:50%;background:var(--primary-soft);display:flex;align-items:center;justify-content:center;font-size:0.72rem;font-weight:700;color:var(--primary);flex-shrink:0;"><?=e(student_inits_r($r['name']))?></div>
                                <?php endif; ?>
                                <div>
                                    <div style="font-weight:700;font-size:0.875rem;"><?=e($r['name'])?></div>
                                    <div style="font-size:0.72rem;color:var(--muted);"><?=e($r['email'])?></div>
                                </div>
                            </div>
                        </td>
                        <td><?=(int)$r['marked']?></td>
                        <td style="color:var(--success);font-weight:700;"><?=(int)$r['present_cnt']?></td>
                        <td style="color:var(--danger);font-weight:700;"><?=(int)$r['absent_cnt']?></td>
                        <td style="font-size:0.82rem;color:var(--muted);"><?=$r['last_present']?e(date('d M Y',strtotime($r['last_present']))):'—'?></td>
                        <td style="font-weight:700;color:<?=$color?>;"><?=$pct!==null?$pct.'%':'—'?></td>
                        <td><span class="badge" style="background:<?=$color?>22;color:<?=$color?>;border:1px solid <?=$color?>44;"><?=e(bucket_label_r($r['bucket']))?></span></td>
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
