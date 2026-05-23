<?php
require_once __DIR__ . '/config.php';
requireTeacher();

$pdo        = db();
$student_id = (int)($_GET['id'] ?? 0);
$success    = '';
$error      = '';

if (!$student_id) { header('Location: students.php'); exit; }

// FIXED: Use id instead of user_id
$student = $pdo->prepare("SELECT * FROM students WHERE id = ? LIMIT 1");
$student->execute([$student_id]);
$student = $student->fetch();
if (!$student) { header('Location: students.php'); exit; }

// ── Handle status override ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['override'])) {
    if (!verifyCsrf($_POST['csrf'] ?? '')) {
        $error = 'Security token mismatch.';
    } else {
        $att_id    = (int)($_POST['attendance_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        if (!in_array($newStatus, ['Present','Late','Absent'])) {
            $error = 'Invalid status.';
        } else {
            // FIXED: Use student_id instead of user_id
            $check = $pdo->prepare("SELECT attendance_id FROM attendance WHERE attendance_id=? AND student_id=?");
            $check->execute([$att_id, $student_id]);
            if (!$check->fetch()) {
                $error = 'Record not found for this student.';
            } else {
                $pdo->prepare("UPDATE attendance SET status=? WHERE attendance_id=?")->execute([$newStatus, $att_id]);
                $success = "Record #$att_id updated to <strong>$newStatus</strong>.";
            }
        }
    }
}

// ── Stats ─────────────────────────────────────────────────────────────────────
// FIXED: Use student_id instead of user_id
$stats = $pdo->prepare("
    SELECT COUNT(*) total,
           SUM(status='Present') present,
           SUM(status='Late')    late,
           SUM(status='Absent')  absent
    FROM attendance WHERE student_id = ?
");
$stats->execute([$student_id]);
$s    = $stats->fetch();
$tot  = (int)($s['total']   ?? 0);
$pres = (int)($s['present'] ?? 0);
$late = (int)($s['late']    ?? 0);
$abs  = (int)($s['absent']  ?? 0);
$rate = $tot > 0 ? round(($pres/$tot)*100,1) : 0;

// ── Monthly breakdown ─────────────────────────────────────────────────────────
$monthly = $pdo->prepare("
    SELECT DATE_FORMAT(date,'%b %Y') as lbl,
           DATE_FORMAT(date,'%Y-%m') as ym,
           COUNT(*) total,
           SUM(status='Present') present,
           SUM(status='Late')    late,
           SUM(status='Absent')  absent
    FROM attendance WHERE student_id = ?
    GROUP BY ym ORDER BY ym DESC LIMIT 6
");
$monthly->execute([$student_id]);
$monthly = $monthly->fetchAll();

// ── Filter records ────────────────────────────────────────────────────────────
$mf  = $_GET['month']  ?? '';
$sf  = $_GET['status'] ?? '';
$rq  = "SELECT * FROM attendance WHERE student_id = ?";
$rp  = [$student_id];
if ($mf) { $rq .= " AND DATE_FORMAT(date,'%Y-%m')=?"; $rp[] = $mf; }
if ($sf) { $rq .= " AND status=?";                     $rp[] = $sf; }
$rq .= " ORDER BY date DESC, time_in DESC";
$recStmt = $pdo->prepare($rq);
$recStmt->execute($rp);
$records = $recStmt->fetchAll();

// ── Dispute history ───────────────────────────────────────────────────────────
$disputes = $pdo->prepare("
    SELECT dr.*, a.date att_date, a.time_in att_time, a.status att_status, u.full_name reviewer
    FROM dispute_requests dr
    JOIN attendance a ON dr.attendance_id = a.attendance_id
    LEFT JOIN teachers t ON dr.reviewed_by = t.id
    LEFT JOIN users u ON u.id = t.user_id
    WHERE dr.student_id = ?
    ORDER BY dr.submitted_at DESC
");
$disputes->execute([$student_id]);
$disputes = $disputes->fetchAll();

// Month dropdown
$monthOpts = $pdo->prepare("SELECT DISTINCT DATE_FORMAT(date,'%Y-%m') m FROM attendance WHERE student_id = ? ORDER BY m DESC");
$monthOpts->execute([$student_id]);
$monthOpts = $monthOpts->fetchAll(PDO::FETCH_COLUMN);

$pageTitle    = e($student['full_name']);
$pageSubtitle = 'Student Record';
$activePage   = 'students';
include 'layout.php';
?>

<!-- Alerts -->
<?php if ($error):   ?><div class="alert alert-error">⚠ <?php echo $error; ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success">✅ <?php echo $success; ?></div><?php endif; ?>

<!-- Back link -->
<div style="margin-bottom:20px;">
    <a href="students.php" class="btn btn-ghost btn-sm">← Back to Students</a>
</div>

<!-- ── Profile banner ──────────────────────────────────────────────────────── -->
<div class="profile-banner">
    <div class="profile-big-avatar"><?php echo initials($student['full_name']); ?></div>
    <div class="profile-info" style="flex:1;">
        <h2><?php echo e($student['full_name']); ?></h2>
        <p><?php echo e($student['course']); ?> &mdash; Year <?php echo $student['year_level']; ?>, Section <?php echo e($student['section']); ?></p>
        <div class="profile-meta">
            <div class="profile-meta-item">
                <span class="profile-meta-label">Student No.</span>
                <span class="profile-meta-value"><?php echo e($student['student_number']); ?></span>
            </div>
            <?php if ($student['email']): ?>
            <div class="profile-meta-item">
                <span class="profile-meta-label">Email</span>
                <span class="profile-meta-value"><?php echo e($student['email']); ?></span>
            </div>
            <?php endif; ?>
            <div class="profile-meta-item">
                <span class="profile-meta-label">Att. Rate</span>
                <span class="profile-meta-value"><?php echo $rate; ?>%</span>
            </div>
            <div class="profile-meta-item">
                <span class="profile-meta-label">Total Records</span>
                <span class="profile-meta-value"><?php echo $tot; ?></span>
            </div>
        </div>
    </div>
    <div style="text-align:right;">
        <a href="reports.php?student=<?php echo $student_id; ?>" class="btn btn-export btn-sm">⬇ Export CSV</a>
    </div>
</div>

<!-- ── Quick stats ─────────────────────────────────────────────────────────── -->
<div class="qstats" style="margin-bottom:24px;">
    <div class="qstat">
        <div class="qstat-num"><?php echo $tot; ?></div>
        <div class="qstat-label">Total</div>
    </div>
    <div class="qstat" style="border-color:var(--success);">
        <div class="qstat-num" style="color:var(--success);"><?php echo $pres; ?></div>
        <div class="qstat-label">Present</div>
    </div>
    <div class="qstat" style="border-color:var(--warning);">
        <div class="qstat-num" style="color:var(--warning);"><?php echo $late; ?></div>
        <div class="qstat-label">Late</div>
    </div>
    <div class="qstat" style="border-color:var(--danger);">
        <div class="qstat-num" style="color:var(--danger);"><?php echo $abs; ?></div>
        <div class="qstat-label">Absent</div>
    </div>
    <div class="qstat" style="border-color:var(--accent);flex:2;">
        <div class="qstat-num" style="color:var(--accent);"><?php echo $rate; ?>%</div>
        <div class="qstat-label">Attendance Rate</div>
        <div style="height:6px;background:var(--border);border-radius:3px;margin-top:8px;overflow:hidden;">
            <div style="height:100%;width:<?php echo $rate; ?>%;background:<?php echo $rate>=80?'var(--success)':($rate>=60?'var(--warning)':'var(--danger)'); ?>;border-radius:3px;"></div>
        </div>
    </div>
</div>

<!-- ── Two-col grid ─────────────────────────────────────────────────────────── -->
<div class="dash-grid" style="margin-bottom:24px;">

    <!-- Monthly breakdown -->
    <div class="card">
        <div class="card-header"><h3>Monthly Breakdown</h3></div>
        <?php if (empty($monthly)): ?>
            <div class="empty-state" style="padding:30px;"><p>No records yet.</p></div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Month</th><th>Present</th><th>Late</th><th>Absent</th><th>Rate</th></tr></thead>
                <tbody>
                <?php foreach ($monthly as $m):
                    $mr = $m['total'] > 0 ? round(($m['present']/$m['total'])*100,1) : 0;
                ?>
                    <tr>
                        <td><strong><?php echo $m['lbl']; ?></strong></td>
                        <td style="color:var(--success);font-weight:700;"><?php echo (int)$m['present']; ?></td>
                        <td style="color:var(--warning);font-weight:700;"><?php echo (int)$m['late']; ?></td>
                        <td style="color:var(--danger);font-weight:700;"><?php echo (int)$m['absent']; ?></td>
                        <td><span class="badge <?php echo rateClass($mr); ?>"><?php echo $mr; ?>%</span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Dispute history -->
    <div class="card">
        <div class="card-header"><h3>Dispute History</h3><p><?php echo count($disputes); ?> request(s)</p></div>
        <?php if (empty($disputes)): ?>
            <div class="empty-state" style="padding:30px;"><p>No disputes submitted.</p></div>
        <?php else: ?>
        <div class="table-wrap">
            <tr>
                <thead><tr><th>Date</th><th>Orig.</th><th>Status</th><th>Resolved</th></tr></thead>
                <tbody>
                <?php foreach ($disputes as $d): ?>
                    <tr>
                        <td class="mono"><?php echo date('M d', strtotime($d['att_date'])); ?></td>
                        <td><span class="badge <?php echo statusClass($d['att_status']); ?>"><?php echo $d['att_status']; ?></span></td>
                        <td><span class="badge <?php echo statusClass($d['status']); ?>"><?php echo $d['status']; ?></span></td>
                        <td style="font-size:12px;color:var(--muted);">
                            <?php echo $d['resolved_at'] ? date('M d', strtotime($d['resolved_at'])) : '—'; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Full attendance records with override ─────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <div><h3>Attendance Records</h3><p><?php echo count($records); ?> record(s) shown</p></div>
        <a href="reports.php?student=<?php echo $student_id; ?>" class="btn btn-export btn-sm">⬇ CSV</a>
    </div>

    <!-- Filter bar -->
    <form method="GET" class="filter-bar" style="border-top:none;">
        <input type="hidden" name="id" value="<?php echo $student_id; ?>">
        <div class="filter-group">
            <span class="filter-label">Month</span>
            <select name="month" class="filter-select">
                <option value="">All Months</option>
                <?php foreach ($monthOpts as $mo): ?>
                    <option value="<?php echo $mo; ?>" <?php echo $mf===$mo?'selected':''; ?>>
                        <?php echo date('F Y', strtotime($mo.'-01')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <span class="filter-label">Status</span>
            <select name="status" class="filter-select">
                <option value="">All</option>
                <option value="Present" <?php echo $sf==='Present'?'selected':''; ?>>Present</option>
                <option value="Late"    <?php echo $sf==='Late'   ?'selected':''; ?>>Late</option>
                <option value="Absent"  <?php echo $sf==='Absent' ?'selected':''; ?>>Absent</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <a href="student-record.php?id=<?php echo $student_id; ?>" class="btn btn-ghost btn-sm">Reset</a>
    </form>

    <?php if (empty($records)): ?>
        <div class="empty-state"><div class="empty-icon">📅</div><h4>No records found</h4><p>Try changing the filter.</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>ID</th><th>Date</th><th>Day</th><th>Time In</th><th>Status</th><th>Override Status</th></tr>
            </thead>
            <tbody>
            <?php foreach ($records as $r): ?>
                <tr>
                    <td class="mono">#<?php echo $r['attendance_id']; ?></td>
                    <td><strong><?php echo date('M d, Y', strtotime($r['date'])); ?></strong></td>
                    <td style="color:var(--muted);"><?php echo date('D', strtotime($r['date'])); ?></td>
                    <td class="mono"><?php echo $r['time_in'] ? date('g:i A', strtotime($r['time_in'])) : '—'; ?></td>
                    <td><span class="badge <?php echo statusClass($r['status']); ?>"><?php echo $r['status']; ?></span></td>
                    <td>
                        <form method="POST" style="display:inline-flex;gap:6px;align-items:center;">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="override" value="1">
                            <input type="hidden" name="attendance_id" value="<?php echo $r['attendance_id']; ?>">
                            <select name="new_status" class="filter-select" style="padding:5px 8px;font-size:12px;min-width:95px;">
                                <option value="Present" <?php echo $r['status']==='Present'?'selected':''; ?>>Present</option>
                                <option value="Late"    <?php echo $r['status']==='Late'   ?'selected':''; ?>>Late</option>
                                <option value="Absent"  <?php echo $r['status']==='Absent' ?'selected':''; ?>>Absent</option>
                            </select>
                            <button type="submit" class="btn btn-primary btn-sm"
                                    onclick="return confirm('Override this record?')">Set</button>
                        </form>
                    </td
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include 'layout-end.php'; ?>