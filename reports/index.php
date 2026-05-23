<?php
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

requireRole('admin', 'super_admin');   // ← protect: Reports is an admin-only module

$pdo = db();
// $db = new PDO('mysql:host=localhost;dbname=G6_reports_db', 'root', '');
// $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$today      = date('Y-m-d');
$lastMonday = date('N') == 1 ? $today : date('Y-m-d', strtotime('last monday'));

function getYearFromSection(string $section): string {
    $first = $section[0] ?? '0';
    return is_numeric($first) ? $first : '0';
}

function yearLabel(string $year): string {
    switch ($year) {
        case '1': return 'First Year';
        case '2': return 'Second Year';
        case '3': return 'Third Year';
        case '4': return 'Fourth Year';
        default:  return 'Other Subjects';
    }
}

function groupFlatByYear(array $rows): array {
    $grouped = [];
    foreach ($rows as $row) {
        $year = getYearFromSection((string)($row['section'] ?? ''));
        if (!isset($grouped[$year])) $grouped[$year] = [];
        $grouped[$year][] = $row;
    }
    ksort($grouped);
    return $grouped;
}

// Monday stats
$stats = $db->prepare("
    SELECT
        COUNT(*)              as total,
        SUM(status='Present') as present,
        SUM(status='Absent')  as absent,
        SUM(status='Late')    as late
    FROM attendance_data
    WHERE date = ?
");
$stats->execute([$lastMonday]);
$mondayStats = $stats->fetch(PDO::FETCH_ASSOC) ?: [
    'total' => 0,
    'present' => 0,
    'absent' => 0,
    'late' => 0
];

// Monday trend (last 4 Mondays)
$mondayTrend = $db->query("
    SELECT
        date,
        COUNT(*)              as total,
        SUM(status='Present') as present,
        SUM(status='Absent')  as absent,
        SUM(status='Late')    as late
    FROM attendance_data
    WHERE DAYOFWEEK(date) = 2
    GROUP BY date
    ORDER BY date DESC
    LIMIT 4
")->fetchAll(PDO::FETCH_ASSOC);
$mondayTrend = array_reverse($mondayTrend);

// Subjects
$subjects = $db->query("
    SELECT * FROM subjects ORDER BY subject_code ASC, section ASC
")->fetchAll(PDO::FETCH_ASSOC);

$groupedSubjects = [];
foreach ($subjects as $subject) {
    $year = getYearFromSection((string)($subject['section'] ?? ''));
    $code = $subject['subject_code'];

    if (!isset($groupedSubjects[$year])) $groupedSubjects[$year] = [];
    if (!isset($groupedSubjects[$year][$code])) $groupedSubjects[$year][$code] = [];

    $groupedSubjects[$year][$code][] = $subject;
}
ksort($groupedSubjects);

// Total students
$totalStudents = $db->query("SELECT COUNT(*) FROM student_data")->fetchColumn();

// Students per section
$sectionCounts = $db->query("
    SELECT section, COUNT(*) as count
    FROM student_data
    GROUP BY section
    ORDER BY section
")->fetchAll(PDO::FETCH_ASSOC);

// Compact breakdown builder using subjects first
function getStatusBreakdownCompact(PDO $db, string $date, string $status): array {
    $sql = "
        SELECT
            s.subject_id,
            s.subject_code,
            s.subject_name,
            s.section,
            COALESCE(b.total_count, 0) AS total_count
        FROM subjects s
        LEFT JOIN (
            SELECT
                se.subject_id,
                COUNT(DISTINCT a.user_id) AS total_count
            FROM attendance_data a
            INNER JOIN subject_enrollment se ON a.user_id = se.user_id
            WHERE a.date = ?
              AND a.status = ?
            GROUP BY se.subject_id
        ) b ON s.subject_id = b.subject_id
        ORDER BY s.section ASC, s.subject_code ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$date, $status]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$presentBreakdown = getStatusBreakdownCompact($db, $lastMonday, 'Present');
$lateBreakdown    = getStatusBreakdownCompact($db, $lastMonday, 'Late');
$absentBreakdown  = getStatusBreakdownCompact($db, $lastMonday, 'Absent');

$presentGrouped = groupFlatByYear($presentBreakdown);
$lateGrouped    = groupFlatByYear($lateBreakdown);
$absentGrouped  = groupFlatByYear($absentBreakdown);

$presentRate = ($mondayStats['total'] ?? 0) > 0
    ? round((($mondayStats['present'] ?? 0) / $mondayStats['total']) * 100, 1)
    : 0;

// Precompute subject stats for the lower cards
$subjectStatRows = $db->prepare("
    SELECT
        se.subject_id,
        SUM(a.status='Present') as present,
        SUM(a.status='Absent')  as absent,
        SUM(a.status='Late')    as late,
        COUNT(a.id)             as total
    FROM subject_enrollment se
    LEFT JOIN attendance_data a
        ON a.user_id = se.user_id
       AND a.date = ?
    GROUP BY se.subject_id
");
$subjectStatRows->execute([$lastMonday]);
$subjectStatRows = $subjectStatRows->fetchAll(PDO::FETCH_ASSOC);

$subjectStatsMap = [];
foreach ($subjectStatRows as $r) {
    $subjectStatsMap[$r['subject_id']] = [
        'present' => (int)($r['present'] ?? 0),
        'absent'  => (int)($r['absent'] ?? 0),
        'late'    => (int)($r['late'] ?? 0),
        'total'   => (int)($r['total'] ?? 0),
    ];
}

$enrolledRows = $db->query("
    SELECT subject_id, COUNT(*) AS enrolled
    FROM subject_enrollment
    GROUP BY subject_id
")->fetchAll(PDO::FETCH_ASSOC);

$enrolledMap = [];
foreach ($enrolledRows as $r) {
    $enrolledMap[$r['subject_id']] = (int)$r['enrolled'];
}

$trendLabels  = array_map(fn($m) => date('M j', strtotime($m['date'])), $mondayTrend);
$trendPresent = array_map(fn($m) => (int)($m['present'] ?? 0), $mondayTrend);
$trendLate    = array_map(fn($m) => (int)($m['late'] ?? 0), $mondayTrend);
$trendAbsent  = array_map(fn($m) => (int)($m['absent'] ?? 0), $mondayTrend);

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

        .api-card {
            background: linear-gradient(180deg, #ffffff 0%, #f9fbff 100%);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            padding: 20px;
        }

        .api-row {
            padding: 10px 0;
            border-bottom: 1px solid #edf1f7;
        }

        .api-row:last-child { border-bottom: none; }

        code {
            background: #f1f4fa;
            color: var(--primary);
            border-radius: 8px;
            padding: 2px 8px;
        }

        @media (max-width: 991px) {
            .analytics-grid {
                grid-template-columns: 1fr;
            }

            .analytics-card-head {
                flex-direction: column;
            }

            .mini-analytic-stat {
                align-items: flex-start;
            }
        }

        @media (max-width: 768px) {
            .trend-chart-wrap { height: 240px; }
            .distribution-chart-wrap,
            .section-chart-wrap { height: 240px; }
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
                <p class="hero-subtitle">
                    Attendance insights, subject monitoring, and weekly classroom analytics.
                </p>
            </div>
            <div class="hero-badge">
                MONDAY ATTENDANCE ONLY · <?= date('F j, Y', strtotime($lastMonday)) ?>
            </div>
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
                <div class="metric-value text-success"><?= $mondayStats['present'] ?? 0 ?></div>
                <div class="metric-rate"><?= $presentRate ?>% attendance rate</div>
                <div class="metric-hint">Click to open or close</div>
            </div>
        </div>

        <div class="col-md-3 mb-3">
            <div class="metric-card warning clickable" onclick="toggleBreakdown('late')">
                <div class="metric-label">Late</div>
                <div class="metric-value text-warning"><?= $mondayStats['late'] ?? 0 ?></div>
                <div class="metric-extra">Late arrivals for the current Monday</div>
                <div class="metric-hint">Click to open or close</div>
            </div>
        </div>

        <div class="col-md-3 mb-3">
            <div class="metric-card danger clickable" onclick="toggleBreakdown('absent')">
                <div class="metric-label">Absent</div>
                <div class="metric-value text-danger"><?= $mondayStats['absent'] ?? 0 ?></div>
                <div class="metric-extra">Students with no attendance present</div>
                <div class="metric-hint">Click to open or close</div>
            </div>
        </div>
    </div>

    <div id="present-breakdown" class="breakdown-box">
        <div class="breakdown-header">
            <div class="breakdown-title text-success">Present Breakdown by Subject</div>
            <button class="close-breakdown" onclick="hideAllBreakdowns()">&times;</button>
        </div>

        <?php if (empty($presentGrouped)): ?>
            <p class="no-data">No present records found for this Monday.</p>
        <?php else: ?>
            <?php foreach ($presentGrouped as $year => $rows): ?>
                <?php if ((int)$year >= 1 && (int)$year <= 3): ?>
                    <div class="mini-year-block">
                        <div class="mini-year-title"><?= yearLabel($year) ?></div>
                        <div class="row">
                            <?php foreach ($rows as $row): ?>
                                <div class="col-md-3 mb-3">
                                    <div class="mini-stat-card mini-present">
                                        <div class="mini-code"><?= htmlspecialchars($row['subject_code']) ?> - <?= htmlspecialchars($row['section']) ?></div>
                                        <div class="mini-count"><?= (int)$row['total_count'] ?></div>
                                        <div><?= htmlspecialchars($row['subject_name']) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div id="late-breakdown" class="breakdown-box">
        <div class="breakdown-header">
            <div class="breakdown-title text-warning">Late Breakdown by Subject</div>
            <button class="close-breakdown" onclick="hideAllBreakdowns()">&times;</button>
        </div>

        <?php if (empty($lateGrouped)): ?>
            <p class="no-data">No late records found for this Monday.</p>
        <?php else: ?>
            <?php foreach ($lateGrouped as $year => $rows): ?>
                <?php if ((int)$year >= 1 && (int)$year <= 3): ?>
                    <div class="mini-year-block">
                        <div class="mini-year-title"><?= yearLabel($year) ?></div>
                        <div class="row">
                            <?php foreach ($rows as $row): ?>
                                <div class="col-md-3 mb-3">
                                    <div class="mini-stat-card mini-late">
                                        <div class="mini-code"><?= htmlspecialchars($row['subject_code']) ?> - <?= htmlspecialchars($row['section']) ?></div>
                                        <div class="mini-count"><?= (int)$row['total_count'] ?></div>
                                        <div><?= htmlspecialchars($row['subject_name']) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div id="absent-breakdown" class="breakdown-box">
        <div class="breakdown-header">
            <div class="breakdown-title text-danger">Absent Breakdown by Subject</div>
            <button class="close-breakdown" onclick="hideAllBreakdowns()">&times;</button>
        </div>

        <?php if (empty($absentGrouped)): ?>
            <p class="no-data">No absent records found for this Monday.</p>
        <?php else: ?>
            <?php foreach ($absentGrouped as $year => $rows): ?>
                <?php if ((int)$year >= 1 && (int)$year <= 3): ?>
                    <div class="mini-year-block">
                        <div class="mini-year-title"><?= yearLabel($year) ?></div>
                        <div class="row">
                            <?php foreach ($rows as $row): ?>
                                <div class="col-md-3 mb-3">
                                    <div class="mini-stat-card mini-absent">
                                        <div class="mini-code"><?= htmlspecialchars($row['subject_code']) ?> - <?= htmlspecialchars($row['section']) ?></div>
                                        <div class="mini-count"><?= (int)$row['total_count'] ?></div>
                                        <div><?= htmlspecialchars($row['subject_name']) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="section-title">
        <span>Analytics Dashboard</span>
        <span class="section-sub">Styled to match the attached dashboard reference</span>
    </div>

    <div class="analytics-shell mb-5">
        <div class="analytics-card analytics-trend-card">
            <div class="analytics-card-head">
                <div>
                    <h5>Monday Trends</h5>
                    <p>Weekly attendance movement for present, late, and absent</p>
                </div>
                <div class="chart-stats">
                    <div class="mini-analytic-stat">
                        <span class="legend-dot present"></span>
                        <strong><?= (int)($mondayStats['present'] ?? 0) ?></strong>
                        <small>Present</small>
                    </div>
                    <div class="mini-analytic-stat">
                        <span class="legend-dot late"></span>
                        <strong><?= (int)($mondayStats['late'] ?? 0) ?></strong>
                        <small>Late</small>
                    </div>
                    <div class="mini-analytic-stat">
                        <span class="legend-dot absent"></span>
                        <strong><?= (int)($mondayStats['absent'] ?? 0) ?></strong>
                        <small>Absent</small>
                    </div>
                </div>
            </div>

            <?php if (count($mondayTrend) > 0): ?>
                <div class="trend-chart-wrap">
                    <canvas id="trendChart"></canvas>
                </div>
            <?php else: ?>
                <p class="text-center no-data mt-5">No trend data yet.</p>
            <?php endif; ?>
        </div>

        <div class="analytics-grid">
            <div class="analytics-card">
                <div class="analytics-card-head">
                    <div>
                        <h5>Monday Distribution</h5>
                        <p>Distribution of attendance status for the latest Monday</p>
                    </div>
                </div>
                <?php if (($mondayStats['total'] ?? 0) > 0): ?>
                    <div class="distribution-chart-wrap">
                        <canvas id="mondayChart"></canvas>
                    </div>
                <?php else: ?>
                    <p class="text-center no-data mt-5">No attendance data yet for this Monday.</p>
                <?php endif; ?>
            </div>

            <div class="analytics-card">
                <div class="analytics-card-head">
                    <div>
                        <h5>Students per Section</h5>
                        <p>Section population with the same visual style as the sample card</p>
                    </div>
                </div>
                <div class="section-chart-wrap">
                    <canvas id="sectionChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="section-title">
        <span>Subjects and Teachers</span>
        <span class="section-sub">Click a section to open its attendance report</span>
    </div>

    <?php if (empty($groupedSubjects)): ?>
        <p class="no-data">No subjects found. Make sure the subjects table is seeded.</p>
    <?php endif; ?>

    <?php foreach ($groupedSubjects as $yearLevel => $subjectGroups): ?>
        <div class="year-header"><?= yearLabel($yearLevel) ?></div>

        <div class="row">
        <?php foreach ($subjectGroups as $subjectCode => $subjectRows):
            $main = $subjectRows[0];
        ?>
        <div class="col-md-6">
            <div class="subject-card">
                <div class="subject-head">
                    <div>
                        <div class="subject-code"><?= htmlspecialchars($main['subject_code']) ?></div>
                        <h5 class="mb-1 text-dark"><?= htmlspecialchars($main['subject_name']) ?></h5>
                        <p class="teacher-name">
                            Teacher: <strong><?= htmlspecialchars($main['teacher_name']) ?></strong>
                            &nbsp;·&nbsp; <?= htmlspecialchars($main['course']) ?>
                        </p>
                    </div>
                </div>

                <?php foreach ($subjectRows as $subject):
                    $subjectId = $subject['subject_id'];
                    $s = $subjectStatsMap[$subjectId] ?? ['present' => 0, 'absent' => 0, 'late' => 0, 'total' => 0];
                    $enrolledCount = $enrolledMap[$subjectId] ?? 0;
                ?>
                <a href="reports.php?type=subject&subject_id=<?= $subject['subject_id'] ?>&date=<?= $lastMonday ?>"
                   class="text-decoration-none text-dark">
                    <div class="sub-section-box">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <strong>Section <?= htmlspecialchars($subject['section']) ?></strong>
                                <small class="d-block text-muted">Enrolled: <?= $enrolledCount ?></small>
                            </div>
                            <div class="stats-row">
                                <div class="stat-item">
                                    <span class="stat-present"><?= $s['present'] ?></span>
                                    <small class="d-block text-muted">Present</small>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-absent"><?= $s['absent'] ?></span>
                                    <small class="d-block text-muted">Absent</small>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-late"><?= $s['late'] ?></span>
                                    <small class="d-block text-muted">Late</small>
                                </div>
                                <div class="stat-item">
                                    <span class="text-primary fw-bold"><?= $s['total'] ?></span>
                                    <small class="d-block text-muted">Recorded</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <div class="section-title mt-4">
        <span>Other Reports</span>
        <span class="section-sub">Quick access to report pages</span>
    </div>

    <div class="list-card mb-4">
        <div class="list-group list-group-flush">
            <a href="reports.php?type=daily&date=<?= $lastMonday ?>" class="list-group-item list-group-item-action">All Monday Attendance</a>
            <a href="reports.php?type=summary" class="list-group-item list-group-item-action">Student Summary</a>
            <a href="reports.php?type=statistics" class="list-group-item list-group-item-action">Statistics by Course</a>
        </div>
    </div>

</div>

<script>
function hideAllBreakdowns() {
    document.getElementById('present-breakdown').classList.remove('active');
    document.getElementById('late-breakdown').classList.remove('active');
    document.getElementById('absent-breakdown').classList.remove('active');
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

<?php if (($mondayStats['total'] ?? 0) > 0): ?>
new Chart(document.getElementById('mondayChart'), {
    type: 'doughnut',
    data: {
        labels: ['Present', 'Late', 'Absent'],
        datasets: [{
            data: [
                <?= (int)($mondayStats['present'] ?? 0) ?>,
                <?= (int)($mondayStats['late'] ?? 0) ?>,
                <?= (int)($mondayStats['absent'] ?? 0) ?>
            ],
            backgroundColor: ['#1f3b73', '#3f8efc', '#f4c542'],
            borderWidth: 0,
            hoverOffset: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '68%',
        plugins: {
            legend: {
                position: 'right',
                labels: {
                    usePointStyle: true,
                    pointStyle: 'circle',
                    boxWidth: 9,
                    boxHeight: 9,
                    padding: 18
                }
            }
        }
    }
});
<?php endif; ?>

<?php if (count($mondayTrend) > 0): ?>
new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($trendLabels) ?>,
        datasets: [
            {
                label: 'Present',
                data: <?= json_encode($trendPresent) ?>,
                borderColor: '#1f3b73',
                backgroundColor: 'rgba(31,59,115,0.07)',
                fill: true,
                tension: 0.42,
                pointRadius: 0,
                pointHoverRadius: 4,
                borderWidth: 2.2
            },
            {
                label: 'Late',
                data: <?= json_encode($trendLate) ?>,
                borderColor: '#6db7ff',
                backgroundColor: 'rgba(109,183,255,0.07)',
                fill: true,
                tension: 0.42,
                pointRadius: 0,
                pointHoverRadius: 4,
                borderWidth: 2
            },
            {
                label: 'Absent',
                data: <?= json_encode($trendAbsent) ?>,
                borderColor: '#98a6bb',
                backgroundColor: 'rgba(152,166,187,0.04)',
                fill: true,
                tension: 0.42,
                pointRadius: 0,
                pointHoverRadius: 4,
                borderWidth: 2
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: {
                position: 'top',
                align: 'end',
                labels: {
                    usePointStyle: true,
                    pointStyle: 'circle',
                    boxWidth: 8,
                    boxHeight: 8
                }
            },
            tooltip: {
                backgroundColor: '#1e293b',
                padding: 12,
                cornerRadius: 10
            }
        },
        scales: {
            x: {
                grid: { display: false, drawBorder: false },
                ticks: { color: '#9aa6b2' }
            },
            y: {
                beginAtZero: true,
                grid: {
                    color: 'rgba(148,163,184,0.12)',
                    drawBorder: false
                },
                ticks: {
                    color: '#9aa6b2',
                    stepSize: 1
                }
            }
        }
    }
});
<?php endif; ?>

new Chart(document.getElementById('sectionChart'), {
    data: {
        labels: <?= json_encode($sectionLabels) ?>,
        datasets: [
            {
                type: 'bar',
                label: 'Students',
                data: <?= json_encode($sectionValues) ?>,
                backgroundColor: 'rgba(255, 205, 210, 0.35)',
                borderColor: '#ef9aa5',
                borderWidth: 1.4,
                borderRadius: 8,
                order: 2
            },
            {
                type: 'line',
                label: 'Students Trend',
                data: <?= json_encode($sectionValues) ?>,
                borderColor: '#1f3b73',
                backgroundColor: '#1f3b73',
                tension: 0.38,
                pointRadius: 3,
                pointHoverRadius: 5,
                borderWidth: 2.2,
                fill: false,
                order: 1
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'top',
                align: 'end',
                labels: {
                    usePointStyle: true,
                    pointStyle: 'circle',
                    boxWidth: 8,
                    boxHeight: 8
                }
            }
        },
        scales: {
            x: {
                grid: { display: false, drawBorder: false },
                ticks: { color: '#9aa6b2' }
            },
            y: {
                beginAtZero: true,
                grid: {
                    color: 'rgba(148,163,184,0.12)',
                    drawBorder: false
                },
                ticks: {
                    color: '#9aa6b2',
                    stepSize: 1
                }
            }
        }
    }
});
</script>
</body>
</html>s
