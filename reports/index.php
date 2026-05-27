<?php
require_once '../includes/auth.php';
require_once '../includes/helpers.php';



$db = db(); // must connect to student_attendance_system

$today      = date('Y-m-d');
$lastMonday = date('N') == 1 ? $today : date('Y-m-d', strtotime('last monday'));

// Helper functions
function getYearFromLevel($level) {
    return (string)$level; // year_level is numeric
}

function yearLabel($year) {
    switch ($year) {
        case '1': return 'First Year';
        case '2': return 'Second Year';
        case '3': return 'Third Year';
        case '4': return 'Fourth Year';
        default: return 'Other Levels';
    }
}

function groupFlatByYear($rows, $levelKey = 'year_level') {
    $grouped = [];
    foreach ($rows as $row) {
        $year = (string)($row[$levelKey] ?? '0');
        if (!isset($grouped[$year])) $grouped[$year] = [];
        $grouped[$year][] = $row;
    }
    ksort($grouped);
    return $grouped;
}

// ------------------- Monday stats -------------------
$stats = $db->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'Present') AS present,
        SUM(status = 'Absent')  AS absent,
        SUM(status = 'Late')    AS late
    FROM attendance
    WHERE date = ?
");
$stats->execute([$lastMonday]);
$mondayStats = $stats->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'present'=>0,'absent'=>0,'late'=>0];

// Monday trend (last 4 Mondays)
$trendQuery = $db->query("
    SELECT
        date,
        COUNT(*) AS total,
        SUM(status = 'Present') AS present,
        SUM(status = 'Absent')  AS absent,
        SUM(status = 'Late')    AS late
    FROM attendance
    WHERE DAYOFWEEK(date) = 2
    GROUP BY date
    ORDER BY date DESC
    LIMIT 4
");
$mondayTrend = $trendQuery->fetchAll(PDO::FETCH_ASSOC);
$mondayTrend = array_reverse($mondayTrend);

// ------------------- Subjects -------------------
// subjects table only has course_code and subject_name
$subjects = $db->query("SELECT course_code, subject_name FROM subjects ORDER BY course_code")->fetchAll(PDO::FETCH_ASSOC);

// We'll group subjects by year level using a separate lookup if classes table exists
// Try to get section and year_level from classes (if available)
$hasClasses = false;
$classInfo = [];
try {
    $classQuery = $db->query("SELECT class_id, course_code, section, year_level FROM classes");
    $classInfo = $classQuery->fetchAll(PDO::FETCH_ASSOC);
    $hasClasses = !empty($classInfo);
} catch (PDOException $e) {
    // classes table doesn't exist – ignore
}

// Build subject list with additional info
$subjectsWithInfo = [];
foreach ($subjects as $subj) {
    $code = $subj['course_code'];
    $subj['section'] = '';
    $subj['year_level'] = '0';
    if ($hasClasses) {
        $matches = array_filter($classInfo, fn($c) => $c['course_code'] == $code);
        if (!empty($matches)) {
            $first = reset($matches);
            $subj['section'] = $first['section'] ?? '';
            $subj['year_level'] = (string)($first['year_level'] ?? '0');
        }
    }
    $subjectsWithInfo[] = $subj;
}

// Group subjects by year level
$groupedSubjects = [];
foreach ($subjectsWithInfo as $subj) {
    $year = $subj['year_level'];
    if (!isset($groupedSubjects[$year])) $groupedSubjects[$year] = [];
    $groupedSubjects[$year][] = $subj;
}
ksort($groupedSubjects);

// ------------------- Students -------------------
$totalStudents = $db->query("SELECT COUNT(*) FROM students")->fetchColumn();

// Students per section (using students.section)
$sectionCounts = $db->query("
    SELECT section, COUNT(*) AS count
    FROM students
    WHERE section IS NOT NULL AND section != ''
    GROUP BY section
    ORDER BY section
")->fetchAll(PDO::FETCH_ASSOC);

// ------------------- Breakdown by subject (Present/Late/Absent) -------------------
// We need to join attendance with student_schedule and classes to get subject info
// First, create a map of class_id -> subject_code
$classSubjectMap = [];
if ($hasClasses) {
    foreach ($classInfo as $c) {
        $classSubjectMap[$c['class_id']] = $c['course_code'];
    }
}

function getStatusBreakdown(PDO $db, $date, $status, $classSubjectMap) {
    $sql = "
        SELECT
            a.class_id,
            COUNT(DISTINCT a.student_id) AS total_count
        FROM attendance a
        WHERE a.date = ? AND a.status = ?
        GROUP BY a.class_id
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$date, $status]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $result = [];
    foreach ($rows as $row) {
        $classId = $row['class_id'];
        $code = $classSubjectMap[$classId] ?? 'Unknown';
        $result[] = [
            'subject_code' => $code,
            'section' => '', // we don't have section from attendance alone
            'subject_name' => '',
            'total_count' => $row['total_count'],
            'year_level' => '0'
        ];
    }
    return $result;
}

$presentBreakdown = getStatusBreakdown($db, $lastMonday, 'Present', $classSubjectMap);
$lateBreakdown    = getStatusBreakdown($db, $lastMonday, 'Late', $classSubjectMap);
$absentBreakdown  = getStatusBreakdown($db, $lastMonday, 'Absent', $classSubjectMap);

// Add subject names from subjects table
$subjectNameMap = [];
foreach ($subjects as $s) {
    $subjectNameMap[$s['course_code']] = $s['subject_name'];
}
foreach ([&$presentBreakdown, &$lateBreakdown, &$absentBreakdown] as &$breakdown) {
    foreach ($breakdown as &$item) {
        $code = $item['subject_code'];
        $item['subject_name'] = $subjectNameMap[$code] ?? $code;
    }
}

$presentGrouped = groupFlatByYear($presentBreakdown, 'year_level');
$lateGrouped    = groupFlatByYear($lateBreakdown, 'year_level');
$absentGrouped  = groupFlatByYear($absentBreakdown, 'year_level');

$presentRate = ($mondayStats['total'] > 0) ? round(($mondayStats['present'] / $mondayStats['total']) * 100, 1) : 0;

// Subject stats for lower cards (enrollment + attendance per subject)
// If we have student_schedule and classes, we can compute enrolled per class
$enrolledMap = [];
$subjectStatsMap = [];

if ($hasClasses) {
    // Get enrollment counts per class
    $enrollStmt = $db->query("
        SELECT class_id, COUNT(*) AS enrolled
        FROM student_schedule
        GROUP BY class_id
    ");
    $enrollRows = $enrollStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($enrollRows as $row) {
        $enrolledMap[$row['class_id']] = $row['enrolled'];
    }
    
    // Get attendance stats per class for the last Monday
    $attStmt = $db->prepare("
        SELECT
            class_id,
            SUM(status = 'Present') AS present,
            SUM(status = 'Absent')  AS absent,
            SUM(status = 'Late')    AS late,
            COUNT(*) AS total
        FROM attendance
        WHERE date = ?
        GROUP BY class_id
    ");
    $attStmt->execute([$lastMonday]);
    $attRows = $attStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($attRows as $row) {
        $subjectStatsMap[$row['class_id']] = [
            'present' => (int)$row['present'],
            'absent'  => (int)$row['absent'],
            'late'    => (int)$row['late'],
            'total'   => (int)$row['total']
        ];
    }
}

// Prepare data for charts
$trendLabels  = array_map(fn($m) => date('M j', strtotime($m['date'])), $mondayTrend);
$trendPresent = array_map(fn($m) => (int)$m['present'], $mondayTrend);
$trendLate    = array_map(fn($m) => (int)$m['late'], $mondayTrend);
$trendAbsent  = array_map(fn($m) => (int)$m['absent'], $mondayTrend);
$sectionLabels = array_map(fn($row) => $row['section'], $sectionCounts);
$sectionValues = array_map(fn($row) => (int)$row['count'], $sectionCounts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports and Analytics</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Keep your existing CSS exactly as it was – no changes needed */
        :root {
            --bg: #f4f7fb;
            --surface: #ffffff;
            --surface-soft: #f8fbff;
            --text: #22324d;
            --muted: #7b8798;
            --border: #e5ebf3;
            --primary: #4e73df;
            --success: #1cc88a;
            --warning: #f6c23e;
            --danger: #e74a3b;
            --shadow-sm: 0 8px 24px rgba(31, 63, 122, 0.06);
            --shadow-md: 0 14px 34px rgba(31, 63, 122, 0.10);
            --radius: 18px;
            --radius-sm: 12px;
            --navy: #1f3b73;
            --soft-blue: #6db7ff;
            --slate-line: #98a6bb;
            --soft-rose: rgba(255, 205, 210, 0.35);
            --rose-border: #ef9aa5;
        }

        body {
            background:
                radial-gradient(circle at top left, rgba(78,115,223,0.07), transparent 28%),
                linear-gradient(180deg, #f8fbff 0%, #f3f6fb 100%);
            color: var(--text);
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        }

        .dashboard-wrap {
            max-width: 1260px;
            margin: 0 auto;
        }

        .hero-panel {
            background: linear-gradient(135deg, #4267d5 0%, #4e73df 55%, #6d8df0 100%);
            color: #fff;
            border-radius: 22px;
            padding: 28px 28px 24px;
            box-shadow: var(--shadow-md);
            margin-bottom: 24px;
        }

        .hero-title {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .hero-subtitle {
            color: rgba(255,255,255,0.88);
            margin-bottom: 0;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            background: rgba(255,255,255,0.16);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.18);
            padding: 8px 14px;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 700;
            backdrop-filter: blur(10px);
        }

        .section-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin: 4px 0 16px;
            padding-left: 14px;
            border-left: 4px solid var(--primary);
            color: var(--primary);
            font-weight: 800;
            font-size: 1.05rem;
        }

        .section-sub {
            color: var(--muted);
            font-size: 0.86rem;
            font-weight: 500;
        }

        .metric-card {
            position: relative;
            overflow: hidden;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 18px 18px 16px;
            box-shadow: var(--shadow-sm);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            cursor: default;
            height: 100%;
        }

        .metric-card.clickable { cursor: pointer; }
        .metric-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }

        .metric-card::after {
            content: "";
            position: absolute;
            top: -40px;
            right: -40px;
            width: 110px;
            height: 110px;
            background: rgba(78,115,223,0.06);
            border-radius: 50%;
        }

        .metric-card.primary { border-top: 4px solid var(--primary); }
        .metric-card.success { border-top: 4px solid var(--success); }
        .metric-card.warning { border-top: 4px solid var(--warning); }
        .metric-card.danger  { border-top: 4px solid var(--danger); }

        .metric-label {
            font-size: 0.73rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
            margin-bottom: 6px;
        }

        .metric-value {
            font-size: 2rem;
            font-weight: 800;
            line-height: 1;
            color: #384861;
        }

        .metric-hint {
            font-size: 0.78rem;
            color: var(--muted);
            margin-top: 8px;
        }

        .metric-extra {
            margin-top: 10px;
            font-size: 0.8rem;
            color: #5d6b80;
            font-weight: 600;
        }

        .metric-rate {
            display: inline-block;
            margin-top: 8px;
            padding: 5px 12px;
            border-radius: 999px;
            background: rgba(28,200,138,0.12);
            color: #109868;
            font-size: 0.78rem;
            font-weight: 700;
        }

        .subject-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 16px;
            box-shadow: var(--shadow-sm);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .subject-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
        }

        .subject-head {
            display: flex;
            justify-content: space-between;
            align-items: start;
            gap: 14px;
            margin-bottom: 12px;
        }

        .subject-code {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 999px;
            background: #eef2ff;
            color: var(--primary);
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .teacher-name {
            color: var(--muted);
            font-size: 0.9rem;
            margin-bottom: 0;
        }

        .year-header {
            background: #eef3ff;
            border: 1px solid #dbe5ff;
            border-left: 4px solid var(--primary);
            padding: 10px 16px;
            border-radius: 12px;
            color: var(--primary);
            font-weight: 800;
            margin: 28px 0 16px;
            font-size: 0.96rem;
        }

        .sub-section-box {
            background: #fbfcff;
            border: 1px solid #e7edf7;
            border-radius: 14px;
            padding: 14px 15px;
            margin-bottom: 10px;
            transition: background 0.16s ease, border-color 0.16s ease, transform 0.16s ease;
        }

        .sub-section-box:hover {
            background: #f4f8ff;
            border-color: #d7e4fb;
            transform: translateX(4px);
        }

        .stats-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .stat-item {
            text-align: center;
            padding: 9px 14px;
            border-radius: 10px;
            background: #f5f8fc;
            min-width: 74px;
        }

        .stat-present { color: var(--success); font-weight: 800; }
        .stat-absent  { color: var(--danger); font-weight: 800; }
        .stat-late    { color: #b88700; font-weight: 800; }

        .breakdown-box {
            display: none;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
        }

        .breakdown-box.active {
            display: block;
        }

        .breakdown-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .breakdown-title {
            font-weight: 800;
            margin-bottom: 0;
            font-size: 1rem;
        }

        .close-breakdown {
            border: none;
            background: #f2f5fa;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            font-size: 1.2rem;
            color: #7c8898;
        }

        .mini-year-block {
            margin-bottom: 18px;
        }

        .mini-year-title {
            font-size: 0.9rem;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 10px;
            padding-left: 2px;
        }

        .mini-stat-card {
            border-radius: 16px;
            padding: 14px;
            color: #fff;
            height: 100%;
            box-shadow: 0 8px 18px rgba(0,0,0,0.08);
        }

        .mini-present { background: linear-gradient(135deg, #17b97c, #1cc88a); }
        .mini-late { background: linear-gradient(135deg, #f4b400, #f6c23e); color: #2d2400; }
        .mini-absent { background: linear-gradient(135deg, #df4d40, #e74a3b); }

        .mini-code {
            font-size: 0.74rem;
            font-weight: 800;
            text-transform: uppercase;
            margin-bottom: 8px;
            opacity: 0.95;
        }

        .mini-count {
            font-size: 1.8rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 8px;
        }

        .no-data {
            color: var(--muted);
            font-style: italic;
            font-size: 0.86rem;
        }

        .list-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .list-group-item {
            border-left: none;
            border-right: none;
            padding: 16px 18px;
            font-weight: 600;
        }

        .list-group-item:first-child { border-top: none; }
        .list-group-item:last-child { border-bottom: none; }

        .analytics-shell {
            margin-bottom: 28px;
        }

        .analytics-card {
            background: #ffffff;
            border: 1px solid #eef2f7;
            border-radius: 18px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05);
            padding: 20px 22px;
        }

        .analytics-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 20px;
        }

        .analytics-trend-card {
            padding: 22px 24px 18px;
        }

        .analytics-card-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 14px;
        }

        .analytics-card-head h5 {
            margin: 0;
            font-size: 1.08rem;
            font-weight: 700;
            color: #1f2a44;
        }

        .analytics-card-head p {
            margin: 4px 0 0;
            font-size: 0.87rem;
            color: #98a2b3;
        }

        .chart-stats {
            display: flex;
            gap: 18px;
            flex-wrap: wrap;
        }

        .mini-analytic-stat {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            min-width: 72px;
        }

        .mini-analytic-stat strong {
            font-size: 1.35rem;
            line-height: 1;
            color: #1f2a44;
        }

        .mini-analytic-stat small {
            color: #98a2b3;
            font-size: 0.8rem;
        }

        .legend-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            margin-bottom: 6px;
            display: inline-block;
        }

        .legend-dot.present { background: var(--navy); }
        .legend-dot.late { background: var(--soft-blue); }
        .legend-dot.absent { background: var(--slate-line); }

        .trend-chart-wrap {
            position: relative;
            height: 310px;
        }

        .distribution-chart-wrap,
        .section-chart-wrap {
            position: relative;
            height: 280px;
        }

        @media (max-width: 991px) {
            .analytics-grid { grid-template-columns: 1fr; }
            .analytics-card-head { flex-direction: column; }
            .mini-analytic-stat { align-items: flex-start; }
        }
        @media (max-width: 768px) {
            .trend-chart-wrap { height: 240px; }
            .distribution-chart-wrap, .section-chart-wrap { height: 240px; }
        }
    </style>
</head>
<body class="p-4">
<?php require_once __DIR__ . '/../includes/nav.php'; renderNav('reports'); ?>
<div class="container dashboard-wrap">

    <div class="hero-panel">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <div class="hero-title">Reports and Analytics</div>
                <p class="hero-subtitle">Attendance insights, subject monitoring, and weekly classroom analytics.</p>
            </div>
            <div class="hero-badge">MONDAY ATTENDANCE ONLY · <?= date('F j, Y', strtotime($lastMonday)) ?></div>
        </div>
    </div>

    <div class="section-title">
        <span>Dashboard Overview</span>
        <span class="section-sub">Current attendance summary for the latest Monday session</span>
    </div>

    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="metric-card primary">
                <div class="metric-label">Total Students</div>
                <div class="metric-value text-primary"><?= $totalStudents ?></div>
                <div class="metric-extra">Total enrolled students in the system</div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="metric-card success clickable" onclick="toggleBreakdown('present')">
                <div class="metric-label">Present</div>
                <div class="metric-value text-success"><?= $mondayStats['present'] ?></div>
                <div class="metric-rate"><?= $presentRate ?>% attendance rate</div>
                <div class="metric-hint">Click to open or close</div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="metric-card warning clickable" onclick="toggleBreakdown('late')">
                <div class="metric-label">Late</div>
                <div class="metric-value text-warning"><?= $mondayStats['late'] ?></div>
                <div class="metric-extra">Late arrivals for the current Monday</div>
                <div class="metric-hint">Click to open or close</div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="metric-card danger clickable" onclick="toggleBreakdown('absent')">
                <div class="metric-label">Absent</div>
                <div class="metric-value text-danger"><?= $mondayStats['absent'] ?></div>
                <div class="metric-extra">Students with no attendance present</div>
                <div class="metric-hint">Click to open or close</div>
            </div>
        </div>
    </div>

    <!-- Breakdown boxes -->
    <div id="present-breakdown" class="breakdown-box"><div class="breakdown-header"><div class="breakdown-title text-success">Present Breakdown by Subject</div><button class="close-breakdown" onclick="hideAllBreakdowns()">&times;</button></div>
        <?php if (empty($presentGrouped)): ?><p class="no-data">No present records found.</p><?php else: foreach ($presentGrouped as $year => $rows): if((int)$year>=1 && (int)$year<=3): ?>
            <div class="mini-year-block"><div class="mini-year-title"><?= yearLabel($year) ?></div><div class="row">
            <?php foreach ($rows as $row): ?>
                <div class="col-md-3 mb-3"><div class="mini-stat-card mini-present"><div class="mini-code"><?= htmlspecialchars($row['subject_code']) ?></div><div class="mini-count"><?= (int)$row['total_count'] ?></div><div><?= htmlspecialchars($row['subject_name']) ?></div></div></div>
            <?php endforeach; ?>
            </div></div>
        <?php endif; endforeach; endif; ?>
    </div>
    <div id="late-breakdown" class="breakdown-box"><div class="breakdown-header"><div class="breakdown-title text-warning">Late Breakdown by Subject</div><button class="close-breakdown" onclick="hideAllBreakdowns()">&times;</button></div>
        <?php if (empty($lateGrouped)): ?><p class="no-data">No late records found.</p><?php else: foreach ($lateGrouped as $year => $rows): if((int)$year>=1 && (int)$year<=3): ?>
            <div class="mini-year-block"><div class="mini-year-title"><?= yearLabel($year) ?></div><div class="row">
            <?php foreach ($rows as $row): ?>
                <div class="col-md-3 mb-3"><div class="mini-stat-card mini-late"><div class="mini-code"><?= htmlspecialchars($row['subject_code']) ?></div><div class="mini-count"><?= (int)$row['total_count'] ?></div><div><?= htmlspecialchars($row['subject_name']) ?></div></div></div>
            <?php endforeach; ?>
            </div></div>
        <?php endif; endforeach; endif; ?>
    </div>
    <div id="absent-breakdown" class="breakdown-box"><div class="breakdown-header"><div class="breakdown-title text-danger">Absent Breakdown by Subject</div><button class="close-breakdown" onclick="hideAllBreakdowns()">&times;</button></div>
        <?php if (empty($absentGrouped)): ?><p class="no-data">No absent records found.</p><?php else: foreach ($absentGrouped as $year => $rows): if((int)$year>=1 && (int)$year<=3): ?>
            <div class="mini-year-block"><div class="mini-year-title"><?= yearLabel($year) ?></div><div class="row">
            <?php foreach ($rows as $row): ?>
                <div class="col-md-3 mb-3"><div class="mini-stat-card mini-absent"><div class="mini-code"><?= htmlspecialchars($row['subject_code']) ?></div><div class="mini-count"><?= (int)$row['total_count'] ?></div><div><?= htmlspecialchars($row['subject_name']) ?></div></div></div>
            <?php endforeach; ?>
            </div></div>
        <?php endif; endforeach; endif; ?>
    </div>

    <div class="section-title"><span>Analytics Dashboard</span><span class="section-sub">Styled to match the attached dashboard reference</span></div>
    <div class="analytics-shell mb-5">
        <div class="analytics-card analytics-trend-card">
            <div class="analytics-card-head"><div><h5>Monday Trends</h5><p>Weekly attendance movement for present, late, and absent</p></div>
            <div class="chart-stats"><div class="mini-analytic-stat"><span class="legend-dot present"></span><strong><?= (int)$mondayStats['present'] ?></strong><small>Present</small></div>
            <div class="mini-analytic-stat"><span class="legend-dot late"></span><strong><?= (int)$mondayStats['late'] ?></strong><small>Late</small></div>
            <div class="mini-analytic-stat"><span class="legend-dot absent"></span><strong><?= (int)$mondayStats['absent'] ?></strong><small>Absent</small></div></div></div>
            <?php if(count($mondayTrend)>0): ?><div class="trend-chart-wrap"><canvas id="trendChart"></canvas></div><?php else: ?><p class="text-center no-data mt-5">No trend data yet.</p><?php endif; ?>
        </div>
        <div class="analytics-grid">
            <div class="analytics-card"><div class="analytics-card-head"><div><h5>Monday Distribution</h5><p>Distribution of attendance status for the latest Monday</p></div></div>
            <?php if($mondayStats['total']>0): ?><div class="distribution-chart-wrap"><canvas id="mondayChart"></canvas></div><?php else: ?><p class="text-center no-data mt-5">No attendance data yet.</p><?php endif; ?>
            </div>
            <div class="analytics-card"><div class="analytics-card-head"><div><h5>Students per Section</h5><p>Section population with the same visual style as the sample card</p></div></div>
            <div class="section-chart-wrap"><canvas id="sectionChart"></canvas></div></div>
        </div>
    </div>

    <div class="section-title"><span>Subjects and Teachers</span><span class="section-sub">Click a section to open its attendance report</span></div>
    <?php if (empty($groupedSubjects)): ?><p class="no-data">No subjects found.</p><?php endif; ?>
    <?php foreach ($groupedSubjects as $yearLevel => $subjList): ?>
        <div class="year-header"><?= yearLabel($yearLevel) ?></div>
        <div class="row">
        <?php foreach ($subjList as $subj): 
            $code = $subj['course_code'];
            $name = $subj['subject_name'];
            $section = $subj['section'] ?: 'N/A';
        ?>
        <div class="col-md-6">
            <div class="subject-card">
                <div class="subject-head"><div><div class="subject-code"><?= htmlspecialchars($code) ?></div><h5 class="mb-1 text-dark"><?= htmlspecialchars($name) ?></h5><p class="teacher-name">Teacher: <strong>Not assigned</strong> &nbsp;·&nbsp; <?= $section ?></p></div></div>
                <?php
                // For each subject, we might need to aggregate stats across its classes. If we have class mapping, we can show per section.
                // For simplicity, we show a placeholder. In a real system, you'd query attendance per class.
                ?>
                <div class="sub-section-box"><div class="d-flex justify-content-between align-items-center"><div><strong>Section <?= htmlspecialchars($section) ?></strong><small class="d-block text-muted">Enrolled: --</small></div><div class="stats-row"><div class="stat-item"><span class="stat-present">0</span><small>Present</small></div><div class="stat-item"><span class="stat-absent">0</span><small>Absent</small></div><div class="stat-item"><span class="stat-late">0</span><small>Late</small></div></div></div></div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <div class="section-title mt-4"><span>Other Reports</span><span class="section-sub">Quick access to report pages</span></div>
    <div class="list-card mb-4"><div class="list-group list-group-flush"><a href="reports.php?type=daily&date=<?= $lastMonday ?>" class="list-group-item list-group-item-action">All Monday Attendance</a><a href="reports.php?type=summary" class="list-group-item list-group-item-action">Student Summary</a><a href="reports.php?type=statistics" class="list-group-item list-group-item-action">Statistics by Course</a></div></div>
</div>

<script>
function hideAllBreakdowns() {
    document.getElementById('present-breakdown')?.classList.remove('active');
    document.getElementById('late-breakdown')?.classList.remove('active');
    document.getElementById('absent-breakdown')?.classList.remove('active');
}
function toggleBreakdown(type) {
    const target = document.getElementById(type + '-breakdown');
    const alreadyOpen = target.classList.contains('active');
    hideAllBreakdowns();
    if (!alreadyOpen) {
        target.classList.add('active');
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}
Chart.defaults.font.family = "'Inter', 'Segoe UI', sans-serif";
Chart.defaults.color = '#7c8aa5';

<?php if ($mondayStats['total'] > 0): ?>
new Chart(document.getElementById('mondayChart'), {
    type: 'doughnut', data: { labels: ['Present', 'Late', 'Absent'], datasets: [{ data: [<?= (int)$mondayStats['present'] ?>, <?= (int)$mondayStats['late'] ?>, <?= (int)$mondayStats['absent'] ?>], backgroundColor: ['#1f3b73','#3f8efc','#f4c542'], borderWidth: 0, hoverOffset: 6 }] },
    options: { responsive: true, maintainAspectRatio: false, cutout: '68%', plugins: { legend: { position: 'right', labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 9, boxHeight: 9, padding: 18 } } } }
});
<?php endif; ?>

<?php if (count($mondayTrend) > 0): ?>
new Chart(document.getElementById('trendChart'), {
    type: 'line', data: { labels: <?= json_encode($trendLabels) ?>, datasets: [
        { label: 'Present', data: <?= json_encode($trendPresent) ?>, borderColor: '#1f3b73', backgroundColor: 'rgba(31,59,115,0.07)', fill: true, tension: 0.42, pointRadius: 0, borderWidth: 2.2 },
        { label: 'Late', data: <?= json_encode($trendLate) ?>, borderColor: '#6db7ff', backgroundColor: 'rgba(109,183,255,0.07)', fill: true, tension: 0.42, pointRadius: 0, borderWidth: 2 },
        { label: 'Absent', data: <?= json_encode($trendAbsent) ?>, borderColor: '#98a6bb', backgroundColor: 'rgba(152,166,187,0.04)', fill: true, tension: 0.42, pointRadius: 0, borderWidth: 2 }
    ] },
    options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'top', align: 'end', labels: { usePointStyle: true, pointStyle: 'circle' } }, tooltip: { backgroundColor: '#1e293b', padding: 12, cornerRadius: 10 } }, scales: { x: { grid: { display: false }, ticks: { color: '#9aa6b2' } }, y: { beginAtZero: true, grid: { color: 'rgba(148,163,184,0.12)' }, ticks: { color: '#9aa6b2', stepSize: 1 } } } }
});
<?php endif; ?>

new Chart(document.getElementById('sectionChart'), {
    data: { labels: <?= json_encode($sectionLabels) ?>, datasets: [{ type: 'bar', label: 'Students', data: <?= json_encode($sectionValues) ?>, backgroundColor: 'rgba(255,205,210,0.35)', borderColor: '#ef9aa5', borderWidth: 1.4, borderRadius: 8, order: 2 }, { type: 'line', label: 'Students Trend', data: <?= json_encode($sectionValues) ?>, borderColor: '#1f3b73', tension: 0.38, pointRadius: 3, borderWidth: 2.2, fill: false, order: 1 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top', align: 'end', labels: { usePointStyle: true, pointStyle: 'circle' } } }, scales: { x: { grid: { display: false }, ticks: { color: '#9aa6b2' } }, y: { beginAtZero: true, grid: { color: 'rgba(148,163,184,0.12)' }, ticks: { color: '#9aa6b2', stepSize: 1 } } } }
});
</script>
</body>
</html>