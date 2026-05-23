<?php
require_once __DIR__ . '/config.php';
requireTeacher();

$pdo = db();

$dateFilter   = $_GET['date']   ?? date('Y-m-d');
$statusFilter = $_GET['status'] ?? '';
$classFilter  = isset($_GET['class']) ? (int)$_GET['class'] : 0;
$export       = $_GET['export'] ?? '';

// Build query
$where = ["a.date = ?"];
$params = [$dateFilter];

if ($statusFilter) { 
    $where[] = "a.status = ?"; 
    $params[] = $statusFilter; 
}

if ($classFilter) {
    $where[] = "a.class_id = ?";
    $params[] = $classFilter;
}

$wSql = implode(' AND ', $where);

$records = $pdo->prepare("
    SELECT a.*, s.full_name, s.student_number, s.course, s.section, s.id as student_db_id
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    WHERE $wSql
    ORDER BY a.date DESC, a.time_in DESC
");
$records->execute($params);
$records = $records->fetchAll();

// CSV export
if ($export === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="attendance_' . $dateFilter . '.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, ['ID','Student No.','Name','Course','Section','Date','Day','Time In','Time Out','Status']);
    foreach ($records as $r) {
        fputcsv($out, [
            $r['attendance_id'], 
            $r['student_number'], 
            $r['full_name'],
            $r['course'], 
            $r['section'],
            date('Y-m-d', strtotime($r['date'])),
            date('l', strtotime($r['date'])),
            $r['time_in'] ? date('g:i A', strtotime($r['time_in'])) : '—',
            $r['time_out'] ? date('g:i A', strtotime($r['time_out'])) : '—',
            $r['status']
        ]);
    }
    fclose($out);
    exit;
}

// Stats for selected date
$present = array_sum(array_map(fn($r) => $r['status'] === 'Present' ? 1 : 0, $records));
$late    = array_sum(array_map(fn($r) => $r['status'] === 'Late'    ? 1 : 0, $records));
$absent  = array_sum(array_map(fn($r) => $r['status'] === 'Absent'  ? 1 : 0, $records));
$total   = count($records);

// Get list of classes for filter dropdown
$classes = $pdo->query("
    SELECT DISTINCT c.class_id, sub.subject_name, c.section
    FROM classes c
    INNER JOIN subjects sub ON sub.course_code = c.course_code
    ORDER BY sub.subject_name
")->fetchAll();

// Available dates for quick jump
$dates = $pdo->query("SELECT DISTINCT date FROM attendance ORDER BY date DESC LIMIT 30")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle    = 'Attendance';
$pageSubtitle = date('l, F j, Y', strtotime($dateFilter));
$activePage   = 'attendance';
include 'layout.php';
?>

<div class="page-header">
    <div>
        <h2>Attendance Records</h2>
        <p><?php echo date('l, F j, Y', strtotime($dateFilter)); ?> — <?php echo $total; ?> record(s)</p>
    </div>
    <div class="page-actions">
        <a href="?<?php echo http_build_query(['date'=>$dateFilter,'status'=>$statusFilter,'class'=>$classFilter,'export'=>'csv']); ?>"
           class="btn btn-export">⬇ Export CSV</a>
    </div>
</div>

<!-- Filter bar -->
<div class="card" style="margin-bottom:24px;">
    <form method="GET" class="filter-bar">
        <div class="filter-group">
            <span class="filter-label">Date</span>
            <input type="date" name="date" class="filter-input" style="min-width:180px;"
                   value="<?php echo $dateFilter; ?>" max="<?php echo date('Y-m-d'); ?>">
        </div>
        <div class="filter-group">
            <span class="filter-label">Subject</span>
            <select name="class" class="filter-select">
                <option value="">All Subjects</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?php echo $c['class_id']; ?>" <?php echo $classFilter==$c['class_id']?'selected':''; ?>>
                        <?php echo e($c['subject_name']); ?> - <?php echo e($c['section']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <span class="filter-label">Status</span>
            <select name="status" class="filter-select">
                <option value="">All</option>
                <option value="Present" <?php echo $statusFilter==='Present'?'selected':''; ?>>Present</option>
                <option value="Late"    <?php echo $statusFilter==='Late'   ?'selected':''; ?>>Late</option>
                <option value="Absent"  <?php echo $statusFilter==='Absent' ?'selected':''; ?>>Absent</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">View</button>
        <a href="attendance.php" class="btn btn-ghost">Reset</a>
    </form>
</div>

<!-- Summary stats -->
<div class="stats-row" style="grid-template-columns:repeat(4,1fr);margin-bottom:24px;">
    <div class="stat-card c-blue">
        <div class="stat-num"><?php echo $total; ?></div>
        <div class="stat-label">Total Records</div>
    </div>
    <div class="stat-card c-green">
        <div class="stat-num"><?php echo $present; ?></div>
        <div class="stat-label">Present</div>
    </div>
    <div class="stat-card c-amber">
        <div class="stat-num"><?php echo $late; ?></div>
        <div class="stat-label">Late</div>
    </div>
    <div class="stat-card c-red">
        <div class="stat-num"><?php echo $absent; ?></div>
        <div class="stat-label">Absent</div>
    </div>
</div>

<!-- Records table -->
<div class="card">
    <div class="card-header">
        <h3>Records for <?php echo date('F j, Y', strtotime($dateFilter)); ?></h3>
    </div>
    <?php if (empty($records)): ?>
        <div class="empty-state">
            <div class="empty-icon">📅</div>
            <h4>No records for this date</h4>
            <p>No attendance was recorded on <?php echo date('F j, Y', strtotime($dateFilter)); ?>.</p>
        </div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Student</th>
                    <th>Course</th>
                    <th>Section</th>
                    <th>Time In</th>
                    <th>Time Out</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($records as $r): ?>
                <tr>
                    <td class="mono">#<?php echo $r['attendance_id']; ?></td>
                    <td>
                        <strong><?php echo e($r['full_name']); ?></strong>
                        <small><?php echo e($r['student_number']); ?></small>
                    </td>
                    <td style="font-size:12px;"><?php echo e($r['course']); ?></td>
                    <td style="font-size:12px;"><?php echo e($r['section']); ?></td>
                    <td class="mono"><?php echo $r['time_in'] ? date('g:i A', strtotime($r['time_in'])) : '—'; ?></td>
                    <td class="mono"><?php echo $r['time_out'] ? date('g:i A', strtotime($r['time_out'])) : '—'; ?></td>
                    <td><span class="badge <?php echo statusClass($r['status']); ?>"><?php echo $r['status']; ?></span></td>
                    <td>
                        <a href="student-record.php?id=<?php echo $r['student_db_id']; ?>" class="btn btn-ghost btn-sm">Profile</a>
                    </td
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include 'layout-end.php'; ?>