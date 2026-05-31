<?php
require_once __DIR__ . '/config.php';
requireTeacher();   // guard: only signed-in teachers/admins past this point

$pdo = db();

$dateFrom  = $_GET['from']    ?? date('Y-m-01');
$dateTo    = $_GET['to']      ?? date('Y-m-d');
$studentId = (int)($_GET['student'] ?? 0);
$status    = $_GET['status']  ?? '';
$export    = $_GET['export']  ?? '';

// Build query - FIXED: changed a.user_id to a.student_id, and s.user_id to s.id
$where  = ["a.date BETWEEN ? AND ?"];
$params = [$dateFrom, $dateTo];
if ($studentId) { 
    $where[] = "a.student_id = ?";  
    $params[] = $studentId; 
}
if ($status)    { 
    $where[] = "a.status = ?";    
    $params[] = $status; 
}
$wSql = implode(' AND ', $where);

// FIXED: Changed JOIN condition from a.user_id=s.user_id to a.student_id=s.id
// FIXED: Changed a.time to a.time_in
$records = $pdo->prepare("
    SELECT a.*, s.id as student_db_id, s.full_name, s.student_number, s.course, s.year_level, s.section, s.email
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    WHERE $wSql
    ORDER BY a.date DESC, s.full_name
");
$records->execute($params);
$records = $records->fetchAll();

// CSV export - FIXED: changed a.time to a.time_in
if ($export === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="attendance_report_' . date('Y-m-d') . '.csv"');
    $f = fopen('php://output','w');
    fputcsv($f, ['ID','Date','Day','Time In','Time Out','Status','Student No.','Full Name','Course','Year','Section','Email']);
    foreach ($records as $r) {
        fputcsv($f, [
            '#' . $r['attendance_id'],
            date('Y-m-d', strtotime($r['date'])),
            date('l', strtotime($r['date'])),
            $r['time_in'] ? date('g:i A', strtotime($r['time_in'])) : '—',
            $r['time_out'] ? date('g:i A', strtotime($r['time_out'])) : '—',
            $r['status'],
            $r['student_number'],
            $r['full_name'],
            $r['course'],
            $r['year_level'],
            $r['section'],
            $r['email'],
        ]);
    }
    fclose($f);
    exit;
}

// Summary breakdown
$total   = count($records);
$present = array_sum(array_map(fn($r) => $r['status'] === 'Present' ? 1 : 0, $records));
$late    = array_sum(array_map(fn($r) => $r['status'] === 'Late'    ? 1 : 0, $records));
$absent  = array_sum(array_map(fn($r) => $r['status'] === 'Absent'  ? 1 : 0, $records));
$rate    = $total > 0 ? round(($present / $total) * 100, 1) : 0;

// Per-student summary in range - FIXED: changed a.user_id to a.student_id, and s.user_id to s.id
$perStudent = $pdo->prepare("
    SELECT s.id as student_id, s.full_name, s.student_number, s.course, s.section,
           COUNT(*) as total, 
           SUM(a.status = 'Present') as present,
           SUM(a.status = 'Late') as late, 
           SUM(a.status = 'Absent') as absent,
           ROUND(SUM(a.status = 'Present') / NULLIF(COUNT(*), 0) * 100, 1) as rate
    FROM attendance a 
    JOIN students s ON a.student_id = s.id
    WHERE a.date BETWEEN ? AND ?
    GROUP BY s.id 
    ORDER BY rate ASC
");
$perStudent->execute([$dateFrom, $dateTo]);
$perStudent = $perStudent->fetchAll();

// Dropdown data - FIXED: changed user_id to id
$studentList = $pdo->query("SELECT id, full_name, student_number FROM students ORDER BY full_name")->fetchAll();

$pageTitle    = 'Reports & Export';
$pageSubtitle = 'Attendance data from ' . date('M d', strtotime($dateFrom)) . ' to ' . date('M d, Y', strtotime($dateTo));
$activePage   = 'reports';
include 'layout.php';
?>

<div class="page-header">
    <div>
        <h2>Reports &amp; Export</h2>
        <p>Filter attendance data and download as CSV.</p>
    </div>
    <?php if ($total > 0): ?>
    <div class="page-actions">
        <a href="?<?php echo http_build_query(['from'=>$dateFrom,'to'=>$dateTo,'student'=>$studentId,'status'=>$status,'export'=>'csv']); ?>"
           class="btn btn-export">⬇ Download CSV (<?php echo $total; ?> records)</a>
    </div>
    <?php endif; ?>
</div>

<!-- Filter form -->
<div class="card" style="margin-bottom:24px;">
    <div class="card-header"><h3>Report Filters</h3></div>
    <form method="GET" class="filter-bar">
        <div class="filter-group">
            <span class="filter-label">From</span>
            <input type="date" name="from" class="filter-input" style="min-width:160px;"
                   value="<?php echo $dateFrom; ?>" max="<?php echo date('Y-m-d'); ?>">
        </div>
        <div class="filter-group">
            <span class="filter-label">To</span>
            <input type="date" name="to" class="filter-input" style="min-width:160px;"
                   value="<?php echo $dateTo; ?>" max="<?php echo date('Y-m-d'); ?>">
        </div>
        <div class="filter-group">
            <span class="filter-label">Student</span>
            <select name="student" class="filter-select">
                <option value="">All Students</option>
                <?php foreach ($studentList as $st): ?>
                    <option value="<?php echo $st['id']; ?>" <?php echo $studentId === $st['id'] ? 'selected' : ''; ?>>
                        <?php echo e($st['full_name']); ?> (<?php echo e($st['student_number']); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <span class="filter-label">Status</span>
            <select name="status" class="filter-select">
                <option value="">All</option>
                <option value="Present" <?php echo $status === 'Present' ? 'selected' : ''; ?>>Present</option>
                <option value="Late"    <?php echo $status === 'Late'    ? 'selected' : ''; ?>>Late</option>
                <option value="Absent"  <?php echo $status === 'Absent'  ? 'selected' : ''; ?>>Absent</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Generate</button>
        <a href="reports.php" class="btn btn-ghost">Reset</a>
    </form>
</div>

<?php if ($total > 0): ?>

<!-- Summary stats -->
<div class="stats-row" style="margin-bottom:24px;">
    <div class="stat-card c-blue"><div class="stat-num"><?php echo $total; ?></div><div class="stat-label">Total Records</div></div>
    <div class="stat-card c-green"><div class="stat-num"><?php echo $present; ?></div><div class="stat-label">Present</div></div>
    <div class="stat-card c-amber"><div class="stat-num"><?php echo $late; ?></div><div class="stat-label">Late</div></div>
    <div class="stat-card c-red"><div class="stat-num"><?php echo $absent; ?></div><div class="stat-label">Absent</div></div>
</div>

<!-- Rate bar -->
<div class="card" style="margin-bottom:24px;">
    <div class="card-body">
        <div style="display:flex;justify-content:space-between;margin-bottom:10px;">
            <span style="font-size:13px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;">Overall Attendance Rate</span>
            <span style="font-size:20px;font-weight:800;color:var(--accent);font-family:var(--mono);"><?php echo $rate; ?>%</span>
        </div>
        <div style="height:12px;background:var(--border);border-radius:6px;overflow:hidden;">
            <div style="height:100%;width:<?php echo $rate; ?>%;background:linear-gradient(90deg,var(--accent),var(--accent-light));border-radius:6px;"></div>
        </div>
        <div style="display:flex;gap:20px;margin-top:10px;font-size:12px;font-weight:600;flex-wrap:wrap;">
            <span style="color:var(--success);">● Present: <?php echo $present; ?></span>
            <span style="color:var(--warning);">● Late: <?php echo $late; ?></span>
            <span style="color:var(--danger);">● Absent: <?php echo $absent; ?></span>
            <span style="color:var(--muted);">Total: <?php echo $total; ?></span>
        </div>
    </div>
</div>

<!-- Per-student breakdown -->
<?php if (!$studentId && !empty($perStudent)): ?>
<div class="card" style="margin-bottom:24px;">
    <div class="card-header"><h3>Per-Student Summary</h3><p><?php echo date('M d',strtotime($dateFrom)).' – '.date('M d, Y',strtotime($dateTo)); ?></p></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Student</th><th>Course</th><th>Present</th><th>Late</th><th>Absent</th><th>Rate</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($perStudent as $ps):
                $pr = (float)($ps['rate'] ?? 0);
            ?>
                <tr>
                    <td><strong><?php echo e($ps['full_name']); ?></strong><small><?php echo e($ps['student_number']); ?></small></td>
                    <td style="font-size:12px;"><?php echo e($ps['course']); ?></td>
                    <td style="color:var(--success);font-weight:700;"><?php echo (int)$ps['present']; ?></td>
                    <td style="color:var(--warning);font-weight:700;"><?php echo (int)$ps['late']; ?></td>
                    <td style="color:var(--danger);font-weight:700;"><?php echo (int)$ps['absent']; ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span class="badge <?php echo rateClass($pr); ?>"><?php echo $pr; ?>%</span>
                            <div class="mini-bar"><div class="mini-bar-fill" style="width:<?php echo $pr; ?>%;background:<?php echo $pr>=80?'var(--success)':($pr>=60?'var(--warning)':'var(--danger)'); ?>;"></div></div>
                        </div>
                    </td
                    <td><a href="student-record.php?id=<?php echo $ps['student_id']; ?>" class="btn btn-ghost btn-sm">View</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Detailed records preview -->
<div class="card">
    <div class="card-header">
        <div><h3>Records Preview</h3><p><?php echo $total; ?> records</p></div>
        <a href="?<?php echo http_build_query(['from'=>$dateFrom,'to'=>$dateTo,'student'=>$studentId,'status'=>$status,'export'=>'csv']); ?>"
           class="btn btn-export btn-sm">⬇ Download</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Date</th><th>Student</th><th>Course</th><th>Time In</th><th>Time Out</th><th>Status</th></tr>
            </thead>
            <tbody>
            <?php foreach (array_slice($records, 0, 50) as $r): ?>
                <tr>
                    <td class="mono"><?php echo date('M d, Y', strtotime($r['date'])); ?></td>
                    <td><strong><?php echo e($r['full_name']); ?></strong><small><?php echo e($r['student_number']); ?></small></td>
                    <td style="font-size:12px;"><?php echo e($r['course']); ?></td>
                    <td class="mono"><?php echo $r['time_in'] ? date('g:i A', strtotime($r['time_in'])) : '—'; ?></td>
                    <td class="mono"><?php echo $r['time_out'] ? date('g:i A', strtotime($r['time_out'])) : '—'; ?></td>
                    <td><span class="badge <?php echo statusClass($r['status']); ?>"><?php echo $r['status']; ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($total > 50): ?>
                <tr><td colspan="6" style="text-align:center;padding:16px;color:var(--muted);font-size:13px;">
                    Showing 50 of <?php echo $total; ?> records. Download CSV for full data.
                 </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>
<div class="card">
    <div class="empty-state">
        <div class="empty-icon">📊</div>
        <h4>No records found</h4>
        <p>Set your date range above and click <strong>Generate</strong> to build a report.</p>
    </div>
</div>
<?php endif; ?>

<?php include 'layout-end.php'; ?>