<?php
require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../includes/db.php';

// Use the main database connection
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$type      = $_GET['type'] ?? 'daily';
$today     = date('Y-m-d');
$date      = $_GET['date'] ?? (date('N') == 1 ? $today : date('Y-m-d', strtotime('last monday')));
$userId    = $_GET['user_id'] ?? '';
$subjectId = $_GET['subject_id'] ?? '';
$status    = $_GET['status'] ?? '';

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset  = ($page - 1) * $perPage;

// Ensure date is always a Monday
if (date('N', strtotime($date)) != 1) {
    $date = date('Y-m-d', strtotime('last monday', strtotime($date)));
}

// Fetch all available Mondays from attendance table
$availableMondays = $pdo->prepare("
    SELECT DISTINCT date
    FROM attendance
    WHERE DAYOFWEEK(date) = 2
    ORDER BY date DESC
");
$availableMondays->execute();
$availableMondays = $availableMondays->fetchAll(PDO::FETCH_COLUMN);

function buildPageUrl($pageNumber) {
    $params = $_GET;
    $params['page'] = $pageNumber;
    return '?' . http_build_query($params);
}

function renderPagination($page, $totalPages) {
    if ($totalPages <= 1) return;
    echo '<div class="d-flex justify-content-between align-items-center mt-3 no-print">';
    echo '<small class="text-muted">Page ' . $page . ' of ' . $totalPages . '</small>';
    echo '<nav><ul class="pagination pagination-sm mb-0">';
    if ($page > 1) {
        echo '<li class="page-item"><a class="page-link" href="' . buildPageUrl($page - 1) . '">Previous</a></li>';
    } else {
        echo '<li class="page-item disabled"><span class="page-link">Previous</span></li>';
    }
    if ($page < $totalPages) {
        echo '<li class="page-item"><a class="page-link" href="' . buildPageUrl($page + 1) . '">Next</a></li>';
    } else {
        echo '<li class="page-item disabled"><span class="page-link">Next</span></li>';
    }
    echo '</ul></nav></div>';
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Attendance Reports</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
    <style>
        body           { background: #f8f9fc; }
        .section-title { border-bottom: 3px solid #4e73df; padding-bottom: 10px; margin-bottom: 20px; color: #4e73df; }
        .badge-present { background: #1cc88a; color: white; }
        .badge-absent  { background: #e74a3b; color: white; }
        .badge-late    { background: #f6c23e; color: #000; }
        .badge-excused { background: #858796; color: white; }
        .no-data       { color: #858796; font-style: italic; }

        @media print {
            .no-print      { display: none !important; }
            body           { background: white; padding: 0; }
            .container     { max-width: 100%; }
            .table         { font-size: 11px; }
            .section-title { color: #000 !important; border-color: #000 !important; }
            .badge         { border: 1px solid #000; color: #000 !important; background: none !important; }
            .print-header  { display: block !important; }
        }

        .print-header { display: none; text-align: center; margin-bottom: 20px; }
        .print-header h3, .print-header p { margin: 0; }
        .print-header p { font-size: 13px; color: #555; }

        .date-picker-bar {
            background: white;
            border-radius: 8px;
            padding: 12px 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        .monday-only-note { font-size: 0.78rem; color: #858796; }
        .pagination .page-link { min-width: 90px; text-align: center; }
        
        /* Subject grouping styles */
        .subject-group {
            margin-bottom: 30px;
            border: 1px solid #e3e6f0;
            border-radius: 8px;
            overflow: hidden;
        }
        .subject-header {
            background: #4e73df;
            color: white;
            padding: 12px 15px;
            font-weight: 600;
            font-size: 1.1rem;
        }
        .subject-header i { margin-right: 8px; }
        .subject-schedule {
            background: #f8f9fc;
            padding: 8px 15px;
            font-size: 0.85rem;
            border-bottom: 1px solid #e3e6f0;
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        .subject-schedule span { color: #5a5c69; }
        .student-list { padding: 0 15px 15px 15px; }
        .student-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .student-row:last-child { border-bottom: none; }
        .student-name { font-weight: 500; }
        .student-id { color: #858796; font-size: 0.85rem; }
        .student-status { font-size: 0.8rem; }
    </style>
</head>
<body class="p-4">
<div class="container">

    <div class="print-header">
        <h3>Attendance Reports & Analytics</h3>
        <p>Integrated Classroom Attendance Management System</p>
        <p>Generated: <?= date('F j, Y h:i A') ?></p>
        <hr>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <a href="index.php" class="btn btn-sm btn-outline-primary">&larr; Back to Dashboard</a>
        <div class="d-flex gap-2">
            <button onclick="printReport()" class="btn btn-sm btn-outline-secondary">Print</button>
            <button onclick="exportPDF()" class="btn btn-sm btn-danger">Export PDF</button>
        </div>
    </div>

    <div class="date-picker-bar no-print">
        <label class="fw-bold mb-0" style="white-space:nowrap;">Select Monday:</label>
        <form method="GET" class="d-flex align-items-center gap-2 flex-wrap">
            <input type="hidden" name="type"  value="<?= htmlspecialchars($type) ?>">
            <?php if ($subjectId): ?><input type="hidden" name="subject_id" value="<?= htmlspecialchars($subjectId) ?>"><?php endif; ?>
            <?php if ($userId): ?><input type="hidden" name="user_id" value="<?= htmlspecialchars($userId) ?>"><?php endif; ?>
            <?php if ($status): ?><input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>"><?php endif; ?>
            <input type="date" name="date" value="<?= htmlspecialchars($date) ?>"
                   class="form-control form-control-sm" style="width:180px;" onchange="this.form.submit()">
            <span class="monday-only-note">Only Mondays have attendance records</span>
        </form>

        <?php if (!empty($availableMondays)): ?>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="fw-bold" style="font-size:0.85rem; white-space:nowrap;">Quick Pick:</span>
            <?php foreach (array_slice($availableMondays, 0, 5) as $m): ?>
                <a href="?type=<?= urlencode($type) ?><?= $subjectId ? '&subject_id='.urlencode($subjectId) : '' ?><?= $userId ? '&user_id='.urlencode($userId) : '' ?><?= $status ? '&status='.urlencode($status) : '' ?>&date=<?= urlencode($m) ?>"
                   class="btn btn-sm <?= $m == $date ? 'btn-primary' : 'btn-outline-primary' ?>">
                    <?= date('M j', strtotime($m)) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <!-- Report type selector -->
        <div class="ms-auto">
            <select class="form-select form-select-sm" onchange="window.location.href='?type='+this.value+'&date=<?= urlencode($date) ?>'">
                <option value="daily" <?= $type == 'daily' ? 'selected' : '' ?>>Daily Attendance</option>
                <option value="summary" <?= $type == 'summary' ? 'selected' : '' ?>>Student Summary</option>
                <option value="by_subject" <?= $type == 'by_subject' ? 'selected' : '' ?>>Students by Subject</option>
                <option value="statistics" <?= $type == 'statistics' ? 'selected' : '' ?>>Statistics by Course</option>
                <option value="status_breakdown" <?= $type == 'status_breakdown' ? 'selected' : '' ?>>Status Breakdown</option>
            </select>
        </div>
    </div>

    <?php

    // ==================== REPORT: STUDENTS BY SUBJECT ====================
    if ($type === 'by_subject'):
        // Get all classes (subjects) with teacher and schedule info
        $subjectsStmt = $pdo->prepare("
            SELECT DISTINCT 
                c.class_id,
                sub.course_code,
                sub.subject_name,
                c.section,
                t.name AS teacher_name,
                (SELECT GROUP_CONCAT(DISTINCT day ORDER BY FIELD(day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday') SEPARATOR ', ') 
                 FROM schedules WHERE class_id = c.class_id) AS days,
                (SELECT GROUP_CONCAT(DISTINCT CONCAT(TIME_FORMAT(start_time,'%l:%i %p'),' - ',TIME_FORMAT(end_time,'%l:%i %p')) SEPARATOR ', ') 
                 FROM schedules WHERE class_id = c.class_id) AS time_slots,
                (SELECT GROUP_CONCAT(DISTINCT room SEPARATOR ', ') 
                 FROM schedules WHERE class_id = c.class_id) AS rooms
            FROM classes c
            JOIN subjects sub ON c.course_code = sub.course_code
            LEFT JOIN teachers t ON c.teacher_id = t.id
            ORDER BY sub.subject_name, c.section
        ");
        $subjectsStmt->execute();
        $subjects = $subjectsStmt->fetchAll();
        
        // For each subject, fetch enrolled students
        if (empty($subjects)):
    ?>
        <h4 class="section-title">Students by Subject</h4>
        <p class="no-data">No subjects found.</p>
    <?php else: ?>
        <h4 class="section-title">Students by Subject</h4>
        <div class="mb-3">
            <a href="reports.php?type=by_subject&date=<?= urlencode($date) ?>" class="btn btn-sm btn-primary">Refresh</a>
        </div>
        
        <?php foreach ($subjects as $subject): 
            // Get enrolled students for this class
            $studentsStmt = $pdo->prepare("
                SELECT s.id, s.student_number, s.full_name, s.course, s.section
                FROM student_schedule ss
                JOIN students s ON ss.student_id = s.id
                WHERE ss.class_id = ?
                ORDER BY s.full_name
            ");
            $studentsStmt->execute([$subject['class_id']]);
            $students = $studentsStmt->fetchAll();
            
            // For the selected Monday, get attendance status for each student
            if (!empty($students)):
                $studentIds = array_column($students, 'id');
                $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
                $attStmt = $pdo->prepare("
                    SELECT student_id, status, time_in
                    FROM attendance
                    WHERE class_id = ? AND date = ? AND student_id IN ($placeholders)
                ");
                $params = array_merge([$subject['class_id'], $date], $studentIds);
                $attStmt->execute($params);
                $attendanceMap = [];
                while ($row = $attStmt->fetch()) {
                    $attendanceMap[$row['student_id']] = ['status' => $row['status'], 'time_in' => $row['time_in']];
                }
        ?>
        <div class="subject-group">
            <div class="subject-header">
                <i class="bi bi-book-fill"></i> <?= htmlspecialchars($subject['subject_name']) ?> 
                <span class="badge bg-light text-dark ms-2"><?= count($students) ?> students</span>
            </div>
            <div class="subject-schedule">
                <span><i class="bi bi-code-slash"></i> <?= htmlspecialchars($subject['course_code']) ?></span>
                <span><i class="bi bi-person-badge"></i> Teacher: <?= htmlspecialchars($subject['teacher_name'] ?? 'Not assigned') ?></span>
                <span><i class="bi bi-calendar-week"></i> <?= htmlspecialchars($subject['days'] ?? 'TBA') ?></span>
                <span><i class="bi bi-clock"></i> <?= htmlspecialchars($subject['time_slots'] ?? 'TBA') ?></span>
                <span><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($subject['rooms'] ?? 'TBA') ?></span>
                <span><i class="bi bi-layout-split"></i> Section: <?= htmlspecialchars($subject['section']) ?></span>
            </div>
            <div class="student-list">
                <div class="row g-2">
                    <?php foreach ($students as $student):
                        $att = $attendanceMap[$student['id']] ?? null;
                        $status = $att['status'] ?? 'No Record';
                        $badgeClass = match($status) {
                            'Present' => 'badge-present',
                            'Absent' => 'badge-absent',
                            'Late' => 'badge-late',
                            default => 'badge-excused'
                        };
                        $timeIn = $att['time_in'] ?? '';
                    ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="student-row">
                            <div>
                                <div class="student-name"><?= htmlspecialchars($student['full_name']) ?></div>
                                <div class="student-id"><?= htmlspecialchars($student['student_number']) ?> | <?= htmlspecialchars($student['course']) ?>-<?= htmlspecialchars($student['section']) ?></div>
                            </div>
                            <div class="student-status">
                                <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($status) ?></span>
                                <?php if ($timeIn): ?><small class="text-muted ms-1"><?= date('g:i A', strtotime($timeIn)) ?></small><?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php 
            endif; // end if students
        endforeach; 
        ?>
    <?php endif; ?>

    <?php
    // ==================== DAILY ATTENDANCE ====================
    elseif ($type === 'daily'):
        $countStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM attendance a
            JOIN students s ON a.student_id = s.id
            WHERE a.date = ?
        ");
        $countStmt->execute([$date]);
        $totalRows  = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

        $stmt = $pdo->prepare("
            SELECT s.full_name AS name, s.course, s.section, s.student_number AS user_id,
                   a.status, a.time_in AS check_in
            FROM attendance a
            JOIN students s ON a.student_id = s.id
            WHERE a.date = :date
            ORDER BY s.section, s.full_name
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':date', $date);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $records = $stmt->fetchAll();

        $totals = $pdo->prepare("
            SELECT COUNT(*) AS total,
                   SUM(status = 'Present') AS present,
                   SUM(status = 'Absent')  AS absent,
                   SUM(status = 'Late')    AS late
            FROM attendance
            WHERE date = ?
        ");
        $totals->execute([$date]);
        $t = $totals->fetch();
    ?>
        <h4 class="section-title">Monday Attendance &mdash; <?= date('F j, Y', strtotime($date)) ?></h4>
        <div class="row mb-3">
            <div class="col-auto"><span class="badge bg-secondary">Total: <?= $t['total'] ?? 0 ?></span></div>
            <div class="col-auto"><span class="badge badge-present">Present: <?= $t['present'] ?? 0 ?></span></div>
            <div class="col-auto"><span class="badge badge-absent">Absent: <?= $t['absent'] ?? 0 ?></span></div>
            <div class="col-auto"><span class="badge badge-late">Late: <?= $t['late'] ?? 0 ?></span></div>
        </div>

        <?php if (empty($records)): ?>
            <p class="no-data">No attendance records for this Monday.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered table-hover bg-white" id="reportTable">
                <thead class="table-primary">
                    <tr><th>#</th><th>Student Name</th><th>User ID</th><th>Course</th><th>Section</th><th>Status</th><th>Check-in Time</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $i => $row):
                        $bc = match($row['status']) { 'Present'=>'badge-present','Absent'=>'badge-absent','Late'=>'badge-late', default=>'badge-excused' };
                    ?>
                    <tr>
                        <td><?= $offset + $i + 1 ?></td>
                        <td><?= htmlspecialchars($row['name'] ?? 'Unknown') ?></td>
                        <td><?= htmlspecialchars($row['user_id']) ?></td>
                        <td><?= htmlspecialchars($row['course'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['section'] ?? '-') ?></td>
                        <td><span class="badge <?= $bc ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                        <td><?= htmlspecialchars($row['check_in'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php renderPagination($page, $totalPages); ?>
        <?php endif; ?>

    <?php
    // ==================== STUDENT SUMMARY ====================
    elseif ($type === 'summary'):
        $totalRows  = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

        $studentsStmt = $pdo->prepare("
            SELECT student_number AS user_id, full_name AS name, course, section
            FROM students
            ORDER BY section, full_name
            LIMIT :limit OFFSET :offset
        ");
        $studentsStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $studentsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $studentsStmt->execute();
        $students = $studentsStmt->fetchAll();
    ?>
        <h4 class="section-title">Student Attendance Summary (Mondays)</h4>

        <?php if (empty($students)): ?>
            <p class="no-data">No students found.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered table-hover bg-white" id="reportTable">
                <thead class="table-primary">
                    <tr><th>#</th><th>Name</th><th>User ID</th><th>Course</th><th>Section</th><th>Present</th><th>Absent</th><th>Late</th><th>Rate</th><th class="no-print">Details</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $i => $stu):
                        $att = $pdo->prepare("
                            SELECT COUNT(*) AS total,
                                   SUM(status = 'Present') AS present,
                                   SUM(status = 'Absent')  AS absent,
                                   SUM(status = 'Late')    AS late
                            FROM attendance
                            WHERE student_id = (SELECT id FROM students WHERE student_number = ?)
                              AND DAYOFWEEK(date) = 2
                        ");
                        $att->execute([$stu['user_id']]);
                        $a    = $att->fetch();
                        $rate = ($a['total'] ?? 0) > 0 ? round(($a['present'] / $a['total']) * 100, 1) : 0;
                    ?>
                    <tr>
                        <td><?= $offset + $i + 1 ?></td>
                        <td><?= htmlspecialchars($stu['name']) ?></td>
                        <td><?= htmlspecialchars($stu['user_id']) ?></td>
                        <td><?= htmlspecialchars($stu['course']) ?></td>
                        <td><?= htmlspecialchars($stu['section']) ?></td>
                        <td class="text-success fw-bold"><?= $a['present'] ?? 0 ?></td>
                        <td class="text-danger fw-bold"><?= $a['absent'] ?? 0 ?></td>
                        <td class="text-warning fw-bold"><?= $a['late'] ?? 0 ?></td>
                        <td>
                            <span class="badge <?= $rate >= 80 ? 'bg-success' : ($rate >= 60 ? 'bg-warning text-dark' : 'bg-danger') ?>">
                                <?= $rate ?>%
                            </span>
                        </td>
                        <td class="no-print">
                            <a href="reports.php?type=student&user_id=<?= urlencode($stu['user_id']) ?>" class="btn btn-sm btn-outline-primary">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php renderPagination($page, $totalPages); ?>
        <?php endif; ?>

    <?php
    // ==================== INDIVIDUAL STUDENT HISTORY ====================
    elseif ($type === 'student' && $userId):
        // Get student internal id
        $studentIdStmt = $pdo->prepare("SELECT id, full_name, course, section, student_number, contact FROM students WHERE student_number = ?");
        $studentIdStmt->execute([$userId]);
        $info = $studentIdStmt->fetch();
        if (!$info) {
            echo '<p class="no-data">Student not found.</p>';
        } else {
            $studentDbId = $info['id'];

            $countStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM attendance
                WHERE student_id = ? AND DAYOFWEEK(date) = 2
            ");
            $countStmt->execute([$studentDbId]);
            $totalRows  = (int)$countStmt->fetchColumn();
            $totalPages = max(1, (int)ceil($totalRows / $perPage));
            if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

            $history = $pdo->prepare("
                SELECT date, status, time_in AS check_in
                FROM attendance
                WHERE student_id = :student_id AND DAYOFWEEK(date) = 2
                ORDER BY date DESC
                LIMIT :limit OFFSET :offset
            ");
            $history->bindValue(':student_id', $studentDbId, PDO::PARAM_INT);
            $history->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $history->bindValue(':offset', $offset, PDO::PARAM_INT);
            $history->execute();
            $records = $history->fetchAll();

            $attStats = $pdo->prepare("
                SELECT COUNT(*) AS total,
                       SUM(status = 'Present') AS present,
                       SUM(status = 'Absent')  AS absent,
                       SUM(status = 'Late')    AS late
                FROM attendance
                WHERE student_id = ? AND DAYOFWEEK(date) = 2
            ");
            $attStats->execute([$studentDbId]);
            $as   = $attStats->fetch();
            $rate = ($as['total'] ?? 0) > 0 ? round(($as['present'] / $as['total']) * 100, 1) : 0;
        ?>
        <h4 class="section-title"><?= htmlspecialchars($info['full_name'] ?? $userId) ?>'s Monday History</h4>

        <div class="row mb-3">
            <div class="col-md-6">
                <p class="text-muted mb-1">
                    <strong>Course:</strong> <?= htmlspecialchars($info['course']) ?>
                    &nbsp;|&nbsp; <strong>Section:</strong> <?= htmlspecialchars($info['section']) ?>
                    &nbsp;|&nbsp; <strong>ID:</strong> <?= htmlspecialchars($info['student_number']) ?>
                </p>
                <p class="text-muted mb-1">
                    <strong>Contact:</strong> <?= htmlspecialchars($info['contact'] ?? 'N/A') ?>
                </p>
            </div>
            <div class="col-md-6">
                <div class="d-flex gap-2 flex-wrap">
                    <span class="badge bg-secondary">Total Mondays: <?= $as['total'] ?? 0 ?></span>
                    <span class="badge badge-present">Present: <?= $as['present'] ?? 0 ?></span>
                    <span class="badge badge-absent">Absent: <?= $as['absent'] ?? 0 ?></span>
                    <span class="badge badge-late">Late: <?= $as['late'] ?? 0 ?></span>
                    <span class="badge <?= $rate >= 80 ? 'bg-success' : ($rate >= 60 ? 'bg-warning text-dark' : 'bg-danger') ?>">
                        Rate: <?= $rate ?>%
                    </span>
                </div>
            </div>
        </div>

        <?php if (empty($records)): ?>
            <p class="no-data">No Monday attendance records found for this student.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered table-hover bg-white" id="reportTable">
                <thead class="table-primary">
                    <tr><th>#</th><th>Monday Date</th><th>Status</th><th>Check-in Time</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $i => $r):
                        $bc = match($r['status']) { 'Present'=>'badge-present','Absent'=>'badge-absent','Late'=>'badge-late', default=>'badge-excused' };
                    ?>
                    <tr>
                        <td><?= $offset + $i + 1 ?></td>
                        <td><?= date('F j, Y', strtotime($r['date'])) ?></td>
                        <td><span class="badge <?= $bc ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                        <td><?= htmlspecialchars($r['check_in'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php renderPagination($page, $totalPages); ?>
        <?php endif; 
        } // end if student found ?>

    <?php
    // ==================== STATISTICS BY COURSE/SECTION ====================
    elseif ($type === 'statistics'):
        $countStmt = $pdo->query("
            SELECT COUNT(*) FROM (
                SELECT s.course, s.section
                FROM attendance a
                JOIN students s ON a.student_id = s.id
                WHERE DAYOFWEEK(a.date) = 2
                GROUP BY s.course, s.section
            ) AS g
        ");
        $totalRows  = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

        $statsStmt = $pdo->prepare("
            SELECT s.course, s.section,
                   COUNT(*) AS total_records,
                   SUM(a.status = 'Present') AS present,
                   SUM(a.status = 'Absent')  AS absent,
                   SUM(a.status = 'Late')    AS late,
                   ROUND(SUM(a.status = 'Present') / COUNT(*) * 100, 2) AS rate
            FROM attendance a
            JOIN students s ON a.student_id = s.id
            WHERE DAYOFWEEK(a.date) = 2
            GROUP BY s.course, s.section
            ORDER BY s.course, s.section
            LIMIT :limit OFFSET :offset
        ");
        $statsStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statsStmt->execute();
        $stats = $statsStmt->fetchAll();
    ?>
        <h4 class="section-title">Attendance Statistics by Course &amp; Section</h4>

        <?php if (empty($stats)): ?>
            <p class="no-data">No statistics available yet.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered table-hover bg-white" id="reportTable">
                <thead class="table-primary">
                    <tr><th>Course</th><th>Section</th><th>Total Records</th><th>Present</th><th>Absent</th><th>Late</th><th>Attendance Rate</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($stats as $s): ?>
                    <tr>
                        <td><?= htmlspecialchars($s['course']) ?></td>
                        <td><?= htmlspecialchars($s['section']) ?></td>
                        <td><?= $s['total_records'] ?></td>
                        <td class="text-success fw-bold"><?= $s['present'] ?></td>
                        <td class="text-danger fw-bold"><?= $s['absent'] ?></td>
                        <td class="text-warning fw-bold"><?= $s['late'] ?></td>
                        <td>
                            <span class="badge <?= $s['rate'] >= 80 ? 'bg-success' : ($s['rate'] >= 60 ? 'bg-warning text-dark' : 'bg-danger') ?>">
                                <?= $s['rate'] ?>%
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php renderPagination($page, $totalPages); ?>
        <?php endif; ?>

    <?php
    // ==================== STATUS BREAKDOWN ====================
    elseif ($type === 'status_breakdown' && in_array($status, ['Present', 'Absent', 'Late'])):

        $countStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM attendance a
            JOIN students s ON a.student_id = s.id
            WHERE a.date = ?
              AND a.status = ?
        ");
        $countStmt->execute([$date, $status]);
        $totalRows = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
        }

        $stmt = $pdo->prepare("
            SELECT s.full_name AS name, s.student_number AS user_id, s.course, s.section, a.status, a.time_in AS check_in
            FROM attendance a
            JOIN students s ON a.student_id = s.id
            WHERE a.date = :date AND a.status = :status
            ORDER BY s.section, s.full_name
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':date', $date);
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $records = $stmt->fetchAll();
    ?>
        <h4 class="section-title">
            <?= htmlspecialchars($status) ?> Students &mdash; <?= date('F j, Y', strtotime($date)) ?>
        </h4>

        <div class="row mb-3">
            <div class="col-auto">
                <span class="badge <?= $status === 'Present' ? 'badge-present' : ($status === 'Absent' ? 'badge-absent' : 'badge-late') ?>">
                    Total <?= htmlspecialchars($status) ?>: <?= $totalRows ?>
                </span>
            </div>
        </div>

        <?php if (empty($records)): ?>
            <p class="no-data">No <?= htmlspecialchars($status) ?> records found for this Monday.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered table-hover bg-white" id="reportTable">
                <thead class="table-primary">
                    <tr><th>#</th><th>Name</th><th>User ID</th><th>Course</th><th>Section</th><th>Status</th><th>Check-in Time</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $i => $row):
                        $bc = match($row['status']) { 'Present'=>'badge-present','Absent'=>'badge-absent','Late'=>'badge-late', default=>'badge-excused' };
                    ?>
                    <tr>
                        <td><?= $offset + $i + 1 ?></td>
                        <td><?= htmlspecialchars($row['name'] ?? 'Unknown') ?></td>
                        <td><?= htmlspecialchars($row['user_id']) ?></td>
                        <td><?= htmlspecialchars($row['course'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['section'] ?? '-') ?></td>
                        <td><span class="badge <?= $bc ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                        <td><?= htmlspecialchars($row['check_in'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php renderPagination($page, $totalPages); ?>
        <?php endif; ?>

    <?php else: ?>
        <p class="no-data">Invalid report type. <a href="index.php">Go back to dashboard.</a></p>
    <?php endif; ?>

</div>

<script>
function printReport() { window.print(); }

function exportPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('l', 'mm', 'a4');

    doc.setFontSize(14);
    doc.setFont('helvetica', 'bold');
    doc.text('Attendance Reports & Analytics', 14, 15);

    doc.setFontSize(10);
    doc.setFont('helvetica', 'normal');
    doc.text('Integrated Classroom Attendance Management System', 14, 22);
    doc.text('Generated: <?= date('F j, Y h:i A') ?>', 14, 28);

    const titleEl = document.querySelector('.section-title');
    if (titleEl) {
        doc.setFont('helvetica', 'bold');
        doc.text(titleEl.innerText.replace(/[^\x00-\x7F]/g, ''), 14, 36);
    }

    const table = document.getElementById('reportTable');
    if (table) {
        const headers = [];
        table.querySelectorAll('thead tr th').forEach(th => {
            if (!th.classList.contains('no-print')) headers.push(th.innerText.trim());
        });

        const rows = [];
        table.querySelectorAll('tbody tr').forEach(tr => {
            const row = [];
            tr.querySelectorAll('td').forEach(td => {
                if (!td.classList.contains('no-print')) row.push(td.innerText.trim());
            });
            rows.push(row);
        });

        doc.autoTable({
            head: [headers],
            body: rows,
            startY: 42,
            styles: { fontSize: 9, cellPadding: 3 },
            headStyles: { fillColor: [78, 115, 223], textColor: 255, fontStyle: 'bold' },
            alternateRowStyles: { fillColor: [245, 247, 255] },
            margin: { left: 14, right: 14 }
        });
    } else {
        // For by_subject report which uses grouped layout, we need a different approach
        // Simple fallback: alert user
        alert('PDF export for grouped subject report is not fully supported. Please use print.');
    }

    const pageCount = doc.internal.getNumberOfPages();
    for (let i = 1; i <= pageCount; i++) {
        doc.setPage(i);
        doc.setFontSize(8);
        doc.setTextColor(150);
        doc.text(
            'Attendance Reports | Page ' + i + ' of ' + pageCount,
            doc.internal.pageSize.getWidth() / 2,
            doc.internal.pageSize.getHeight() - 8,
            { align: 'center' }
        );
    }

    doc.save('Attendance_<?= $type ?>_<?= $date ?>.pdf');
}
</script>
</body>
</html>