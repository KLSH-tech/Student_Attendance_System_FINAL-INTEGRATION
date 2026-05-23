<?php
// ============================================================
// dashboard.php - Teacher Portal Dashboard (FIXED)
// ============================================================

// Load config ONLY - it contains everything we need
require_once __DIR__ . '/config.php';

// Verify teacher is logged in (function is now available from config.php)
requireTeacher();

$pdo = db();
$today    = date('Y-m-d');

// ── Key metrics ───────────────────────────────────────────────────────────────
$totalStudents  = (int) $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$markedToday    = (int) $pdo->query("SELECT COUNT(*) FROM attendance WHERE date = CURDATE()")->fetchColumn();
$presentToday   = (int) $pdo->query("SELECT COUNT(*) FROM attendance WHERE date = CURDATE() AND status = 'Present'")->fetchColumn();
$lateToday      = (int) $pdo->query("SELECT COUNT(*) FROM attendance WHERE date = CURDATE() AND status = 'Late'")->fetchColumn();
$absentToday    = (int) $pdo->query("SELECT COUNT(*) FROM attendance WHERE date = CURDATE() AND status = 'Absent'")->fetchColumn();
$notMarked      = $totalStudents - $markedToday;
$pendingDisputes = pendingDisputeCount();

$totalRecords  = (int) $pdo->query("SELECT COUNT(*) FROM attendance")->fetchColumn();
$totalPresent  = (int) $pdo->query("SELECT COUNT(*) FROM attendance WHERE status='Present'")->fetchColumn();

// ── Today's student list (without rate column) ─────────────────────────────────
$todayStudents = $pdo->query("
    SELECT s.id as student_db_id, s.full_name, s.student_number, s.course, s.section,
           a.status as today_status, a.time_in as today_time
    FROM students s
    LEFT JOIN attendance a ON s.id = a.student_id AND a.date = CURDATE()
    ORDER BY a.status IS NULL DESC, s.full_name
")->fetchAll();

// ── Recent disputes ───────────────────────────────────────────────────────────
$recentDisputes = $pdo->query("
    SELECT dr.*, a.date att_date, a.status att_status,
           s.full_name student_name, s.student_number
    FROM dispute_requests dr
    JOIN attendance a ON dr.attendance_id = a.attendance_id
    JOIN students s ON dr.student_id = s.id
    WHERE dr.status IN ('Pending','Under Review')
    ORDER BY dr.submitted_at DESC LIMIT 5
")->fetchAll();

// ── Weekly trend (last 7 days) ────────────────────────────────────────────────
$weekly = $pdo->query("
    SELECT date,
           COUNT(*) total,
           SUM(status='Present') present,
           SUM(status='Late') late,
           SUM(status='Absent') absent
    FROM attendance
    WHERE date >= CURDATE()-INTERVAL 6 DAY
    GROUP BY date ORDER BY date
")->fetchAll();

// ── At-risk students ──────────────────────────────────────────────────────────
$atRisk = $pdo->query("
    SELECT student_id, full_name, student_number, course, total, pres
    FROM (
        SELECT s.id as student_id, s.full_name, s.student_number, s.course,
               COUNT(a.attendance_id) AS total,
               SUM(a.status='Present') AS pres
        FROM students s
        JOIN attendance a ON s.id = a.student_id
        GROUP BY s.id
    ) sub
    WHERE total >= 3 AND (pres/total)*100 < 70
    ORDER BY (pres/total) ASC LIMIT 5
")->fetchAll();

$pageTitle    = 'Dashboard';
$pageSubtitle = 'Overview for ' . date('l, F j, Y');
$activePage   = 'dashboard';
include 'layout.php';
?>

<!-- ── Stats row ─────────────────────────────────────────────────────────────── -->
<div class="stats-row">
    <div class="stat-card c-blue">
        <span class="stat-icon-display">👥</span>
        <div class="stat-num"><?php echo $totalStudents; ?></div>
        <div class="stat-label">Total Students</div>
        <div class="stat-trend"><?php echo $markedToday; ?> checked in today</div>
    </div>
    <div class="stat-card c-green">
        <span class="stat-icon-display">✅</span>
        <div class="stat-num"><?php echo $presentToday; ?></div>
        <div class="stat-label">Present Today</div>
        <div class="stat-trend" style="color:var(--success);">+<?php echo $lateToday; ?> Late</div>
    </div>
    <div class="stat-card c-red">
        <span class="stat-icon-display">❌</span>
        <div class="stat-num"><?php echo $absentToday + $notMarked; ?></div>
        <div class="stat-label">Absent / Not Marked</div>
        <div class="stat-trend"><?php echo $absentToday; ?> absent, <?php echo $notMarked; ?> no record</div>
    </div>
    <div class="stat-card <?php echo $pendingDisputes ? 'c-amber' : 'c-purple'; ?>">
        <span class="stat-icon-display">📋</span>
        <div class="stat-num"><?php echo $pendingDisputes; ?></div>
        <div class="stat-label">Pending Disputes</div>
        <div class="stat-trend"><?php echo $pendingDisputes ? 'Requires your review' : 'All clear'; ?></div>
    </div>
</div>

<?php if ($pendingDisputes): ?>
<div class="alert alert-warning">
    ⚠️ <strong><?php echo $pendingDisputes; ?> dispute request<?php echo $pendingDisputes > 1 ? 's' : ''; ?></strong>
    awaiting your review. <a href="disputes.php" style="font-weight:700;text-decoration:underline;">Review now →</a>
</div>
<?php endif; ?>

<!-- ── Dashboard grid ────────────────────────────────────────────────────────── -->
<div class="dash-grid dash-grid-3">

    <!-- Today's student status (without Rate column) -->
    <div class="card">
        <div class="card-header">
            <div>
                <h3>Today's Attendance</h3>
                <p><?php echo date('l, F j, Y'); ?></p>
            </div>
            <a href="attendance.php" class="btn btn-ghost btn-sm">View All</a>
        </div>
        <div class="table-wrap">
            <table class="attendance-table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Time</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($todayStudents as $s): ?>
                    <tr>
                        <td>
                            <strong><?php echo e($s['full_name']); ?></strong>
                            <small><?php echo e($s['student_number']); ?></small>
                        </td>
                        <td class="mono"><?php echo $s['today_time'] ? date('g:i A', strtotime($s['today_time'])) : '—'; ?></td>
                        <td>
                            <?php if ($s['today_status']): ?>
                                <span class="badge <?php echo statusClass($s['today_status']); ?>"><?php echo $s['today_status']; ?></span>
                            <?php else: ?>
                                <span class="badge badge-default">Not Marked</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($todayStudents)): ?>
                    <tr>
                        <td colspan="3" style="text-align:center;padding:30px;color:var(--muted);">No students found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Right column -->
    <div style="display:flex;flex-direction:column;gap:20px;">

        <!-- At-risk students -->
        <?php if (!empty($atRisk)): ?>
        <div class="card">
            <div class="card-header">
                <div><h3>⚠ At-Risk Students</h3><p>Rate below 70%</p></div>
            </div>
            <div class="table-wrap">
                <table class="at-risk-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($atRisk as $ar):
                        $r = $ar['total'] > 0 ? round(($ar['pres']/$ar['total'])*100,1) : 0;
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo e($ar['full_name']); ?></strong>
                                <small><?php echo e($ar['student_number']); ?></small>
                            </td>
                            <td><span class="badge <?php echo rateClass($r); ?>"><?php echo $r; ?>%</span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Pending disputes -->
        <?php if (!empty($recentDisputes)): ?>
        <div class="card">
            <div class="card-header">
                <div><h3>Pending Disputes</h3></div>
                <a href="disputes.php" class="btn btn-ghost btn-sm">All</a>
            </div>
            <div class="table-wrap">
                <table class="disputes-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Date</th>
                            <th>Submitted</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentDisputes as $d): ?>
                        <tr>
                            <td>
                                <strong><?php echo e($d['student_name']); ?></strong>
                                <small><?php echo e($d['student_number']); ?></small>
                            </td>
                            <td class="mono"><?php echo date('M d', strtotime($d['att_date'])); ?></td>
                            <td style="color:var(--muted);font-size:12px;"><?php echo timeAgo($d['submitted_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.attendance-table,
.at-risk-table,
.disputes-table {
    width: 100%;
    border-collapse: collapse;
}
.attendance-table th,
.attendance-table td,
.at-risk-table th,
.at-risk-table td,
.disputes-table th,
.disputes-table td {
    padding: 10px 12px;
    text-align: left;
    border-bottom: 1px solid var(--border);
}
.attendance-table th,
.at-risk-table th,
.disputes-table th {
    background: var(--dark);
    color: white;
    font-weight: 600;
}
</style>

<?php include 'layout-end.php'; ?>