<?php require_once __DIR__ . '/../includes/guard.php'; ?>
<?php
// reports.php - G6 Detailed Reports

$db = new PDO('mysql:host=localhost;dbname=G6_reports_db;charset=utf8mb4', 'root', '');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

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

// Fetch all available Mondays
$availableMondays = $db->query("
    SELECT DISTINCT date
    FROM attendance_data
    WHERE DAYOFWEEK(date) = 2
    ORDER BY date DESC
")->fetchAll(PDO::FETCH_COLUMN);

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
    <title>G6 - Reports</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
    <style>
        body           { background: #f8f9fc; }
        .section-title { border-bottom: 3px solid #4e73df; padding-bottom: 10px; margin-bottom: 20px; color: #4e73df; }
        .badge-present { background: #1cc88a; }
        .badge-absent  { background: #e74a3b; }
        .badge-late    { background: #f6c23e; color: #000; }
        .badge-excused { background: #858796; }
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
    </style>
</head>
<body class="p-4">
<div class="container">

    <div class="print-header">
        <h3>G6 - Reports & Analytics Subsystem</h3>
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
    </div>

    <?php

    if ($type === 'daily'):
        $countStmt = $db->prepare("
            SELECT COUNT(*)
            FROM attendance_data a
            LEFT JOIN student_data sd ON a.user_id = sd.user_id
            WHERE a.date = ?
        ");
        $countStmt->execute([$date]);
        $totalRows  = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

        $rows = $db->prepare("
            SELECT sd.name, sd.course, sd.section, a.user_id, a.status, a.check_in
            FROM attendance_data a
            LEFT JOIN student_data sd ON a.user_id = sd.user_id
            WHERE a.date = :date
            ORDER BY sd.section, sd.name
            LIMIT :limit OFFSET :offset
        ");
        $rows->bindValue(':date', $date);
        $rows->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $rows->bindValue(':offset', $offset, PDO::PARAM_INT);
        $rows->execute();
        $records = $rows->fetchAll();

        $totals = $db->prepare("
            SELECT COUNT(*) AS total,
                   SUM(status='Present') AS present,
                   SUM(status='Absent')  AS absent,
                   SUM(status='Late')    AS late
            FROM attendance_data
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
                        $bc = match($row['status']) { 'Present'=>'badge-present','Absent'=>'badge-absent','Late'=>'badge-late',default=>'badge-excused' };
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

    elseif ($type === 'summary'):
        $totalRows  = (int)$db->query("SELECT COUNT(*) FROM student_data")->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

        $studentsStmt = $db->prepare("
            SELECT user_id, name, course, section
            FROM student_data
            ORDER BY section, name
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
                        $att = $db->prepare("
                            SELECT COUNT(*) AS total,
                                   SUM(status='Present') AS present,
                                   SUM(status='Absent')  AS absent,
                                   SUM(status='Late')    AS late
                            FROM attendance_data
                            WHERE user_id = ? AND DAYOFWEEK(date) = 2
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

    elseif ($type === 'student' && $userId):
        $profile = $db->prepare("SELECT user_id, name, course, section, gender, contact FROM student_data WHERE user_id = ?");
        $profile->execute([$userId]);
        $info = $profile->fetch();

        $countStmt = $db->prepare("SELECT COUNT(*) FROM attendance_data WHERE user_id = ? AND DAYOFWEEK(date) = 2");
        $countStmt->execute([$userId]);
        $totalRows  = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

        $history = $db->prepare("
            SELECT date, status, check_in
            FROM attendance_data
            WHERE user_id = :user_id AND DAYOFWEEK(date) = 2
            ORDER BY date DESC
            LIMIT :limit OFFSET :offset
        ");
        $history->bindValue(':user_id', $userId);
        $history->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $history->bindValue(':offset', $offset, PDO::PARAM_INT);
        $history->execute();
        $records = $history->fetchAll();

        $attStats = $db->prepare("
            SELECT COUNT(*) AS total,
                   SUM(status='Present') AS present,
                   SUM(status='Absent')  AS absent,
                   SUM(status='Late')    AS late
            FROM attendance_data
            WHERE user_id = ? AND DAYOFWEEK(date) = 2
        ");
        $attStats->execute([$userId]);
        $as   = $attStats->fetch();
        $rate = ($as['total'] ?? 0) > 0 ? round(($as['present'] / $as['total']) * 100, 1) : 0;
    ?>
        <h4 class="section-title"><?= htmlspecialchars($info['name'] ?? $userId) ?>'s Monday History</h4>

        <?php if ($info): ?>
        <div class="row mb-3">
            <div class="col-md-6">
                <p class="text-muted mb-1">
                    <strong>Course:</strong> <?= htmlspecialchars($info['course']) ?>
                    &nbsp;|&nbsp; <strong>Section:</strong> <?= htmlspecialchars($info['section']) ?>
                    &nbsp;|&nbsp; <strong>ID:</strong> <?= htmlspecialchars($info['user_id']) ?>
                </p>
                <p class="text-muted mb-1">
                    <strong>Gender:</strong> <?= ($info['gender'] ?? '') == 'M' ? 'Male' : 'Female' ?>
                    &nbsp;|&nbsp; <strong>Contact:</strong> <?= htmlspecialchars($info['contact'] ?? 'N/A') ?>
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
        <?php endif; ?>

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
                        $bc = match($r['status']) { 'Present'=>'badge-present','Absent'=>'badge-absent','Late'=>'badge-late',default=>'badge-excused' };
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
        <?php endif; ?>

    <?php

    elseif ($type === 'statistics'):
        $countStmt = $db->query("
            SELECT COUNT(*) FROM (
                SELECT sd.course, sd.section
                FROM attendance_data a
                JOIN student_data sd ON a.user_id = sd.user_id
                WHERE DAYOFWEEK(a.date) = 2
                GROUP BY sd.course, sd.section
            ) AS g
        ");
        $totalRows  = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

        $statsStmt = $db->prepare("
            SELECT sd.course, sd.section,
                   COUNT(*) AS total_records,
                   SUM(a.status='Present') AS present,
                   SUM(a.status='Absent')  AS absent,
                   SUM(a.status='Late')    AS late,
                   ROUND(SUM(a.status='Present') / COUNT(*) * 100, 2) AS rate
            FROM attendance_data a
            JOIN student_data sd ON a.user_id = sd.user_id
            WHERE DAYOFWEEK(a.date) = 2
            GROUP BY sd.course, sd.section
            ORDER BY sd.course, sd.section
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

    elseif ($type === 'subject' && $subjectId):
        $sub = $db->prepare("SELECT subject_id, subject_code, subject_name, teacher_name, course, section, room, schedule FROM subjects WHERE subject_id = ?");
        $sub->execute([$subjectId]);
        $subject = $sub->fetch();

        $countStmt = $db->prepare("SELECT COUNT(*) FROM subject_enrollment WHERE subject_id = ?");
        $countStmt->execute([$subjectId]);
        $totalRows  = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

        $rows = $db->prepare("
            SELECT sd.name, sd.user_id, sd.course, sd.section, a.status, a.check_in
            FROM subject_enrollment se
            JOIN student_data sd ON se.user_id = sd.user_id
            LEFT JOIN attendance_data a ON a.user_id = se.user_id AND a.date = :date
            WHERE se.subject_id = :subject_id
            ORDER BY sd.name
            LIMIT :limit OFFSET :offset
        ");
        $rows->bindValue(':date', $date);
        $rows->bindValue(':subject_id', (int)$subjectId, PDO::PARAM_INT);
        $rows->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $rows->bindValue(':offset', $offset, PDO::PARAM_INT);
        $rows->execute();
        $records = $rows->fetchAll();

        $summaryRows = $db->prepare("
            SELECT a.status
            FROM subject_enrollment se
            JOIN student_data sd ON se.user_id = sd.user_id
            LEFT JOIN attendance_data a ON a.user_id = se.user_id AND a.date = ?
            WHERE se.subject_id = ?
        ");
        $summaryRows->execute([$date, $subjectId]);
        $allSubjectRows = $summaryRows->fetchAll();

        $present = $absent = $late = $noRecord = 0;
        foreach ($allSubjectRows as $r) {
            $st = $r['status'] ?? 'No Record';
            if ($st === 'Present') $present++;
            elseif ($st === 'Absent') $absent++;
            elseif ($st === 'Late') $late++;
            else $noRecord++;
        }
    ?>
        <h4 class="section-title">
            <?= htmlspecialchars($subject['subject_code'] ?? '') ?> &mdash; <?= htmlspecialchars($subject['subject_name'] ?? '') ?>
        </h4>

        <?php if ($subject): ?>
        <p class="text-muted">
            <strong><?= htmlspecialchars($subject['teacher_name']) ?></strong>
            &nbsp;|&nbsp; Section <?= htmlspecialchars($subject['section']) ?>
            &nbsp;|&nbsp; <span class="badge bg-success"><?= htmlspecialchars($subject['room'] ?? 'COM LAB A') ?></span>
            &nbsp;|&nbsp; <?= htmlspecialchars($subject['schedule'] ?? 'Schedule not available') ?>
        </p>
        <p class="text-muted">Date: <strong><?= date('F j, Y', strtotime($date)) ?></strong></p>
        <div class="row mb-3">
            <div class="col-auto"><span class="badge bg-secondary">Enrolled: <?= count($allSubjectRows) ?></span></div>
            <div class="col-auto"><span class="badge badge-present">Present: <?= $present ?></span></div>
            <div class="col-auto"><span class="badge badge-absent">Absent: <?= $absent ?></span></div>
            <div class="col-auto"><span class="badge badge-late">Late: <?= $late ?></span></div>
            <div class="col-auto"><span class="badge bg-secondary">No Record: <?= $noRecord ?></span></div>
        </div>
        <?php endif; ?>

        <?php if (empty($records)): ?>
            <p class="no-data">No enrollment or attendance data for this subject yet.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered table-hover bg-white" id="reportTable">
                <thead class="table-primary">
                    <tr><th>#</th><th>Student Name</th><th>User ID</th><th>Course / Section</th><th>Status</th><th>Check-in Time</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $i => $row):
                        $rowStatus = $row['status'] ?? 'No Record';
                        $bc = match($rowStatus) { 'Present'=>'badge-present','Absent'=>'badge-absent','Late'=>'badge-late','Excused'=>'badge-excused',default=>'bg-secondary' };
                    ?>
                    <tr>
                        <td><?= $offset + $i + 1 ?></td>
                        <td><?= htmlspecialchars($row['name']) ?></td>
                        <td><?= htmlspecialchars($row['user_id']) ?></td>
                        <td><?= htmlspecialchars($row['course']) ?>-<?= htmlspecialchars($row['section']) ?></td>
                        <td><span class="badge <?= $bc ?>"><?= htmlspecialchars($rowStatus) ?></span></td>
                        <td><?= htmlspecialchars($row['check_in'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php renderPagination($page, $totalPages); ?>
        <?php endif; ?>

    <?php

    elseif ($type === 'status_breakdown' && in_array($status, ['Present', 'Absent', 'Late'])):

        $countStmt = $db->prepare("
            SELECT COUNT(*)
            FROM attendance_data a
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

        $stmt = $db->prepare("
            SELECT sd.name, sd.user_id, sd.course, sd.section, a.status, a.check_in
            FROM attendance_data a
            LEFT JOIN student_data sd ON sd.user_id = a.user_id
            WHERE a.date = ?
              AND a.status = ?
            ORDER BY sd.section, sd.name
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(1, $date);
        $stmt->bindValue(2, $status);
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
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>User ID</th>
                        <th>Course</th>
                        <th>Section</th>
                        <th>Status</th>
                        <th>Check-in Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $i => $row):
                        $bc = match($row['status']) { 'Present'=>'badge-present','Absent'=>'badge-absent','Late'=>'badge-late',default=>'badge-excused' };
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
    doc.text('G6 - Reports & Analytics Subsystem', 14, 15);

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
    if (!table) { alert('No table data to export!'); return; }

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

    const pageCount = doc.internal.getNumberOfPages();
    for (let i = 1; i <= pageCount; i++) {
        doc.setPage(i);
        doc.setFontSize(8);
        doc.setTextColor(150);
        doc.text(
            'G6 Reports & Analytics | Page ' + i + ' of ' + pageCount,
            doc.internal.pageSize.getWidth() / 2,
            doc.internal.pageSize.getHeight() - 8,
            { align: 'center' }
        );
    }

    doc.save('G6_<?= $type ?>_<?= $date ?>.pdf');
}
</script>
</body>
</html>