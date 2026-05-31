<?php
require_once __DIR__ . '/config.php';
requireTeacher();   // guard: only signed-in teachers/admins past this point

$pdo        = db();
$teacher_id = (int)($_SESSION['t_id'] ?? 0);
$success    = '';
$error      = '';

// ── Handle approve / reject with teacher validation ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf'] ?? '')) {
        $error = 'Security token mismatch.';
    } else {
        $did        = (int)($_POST['dispute_id'] ?? 0);
        $action     = $_POST['action'] ?? '';
        $notes      = trim($_POST['resolution_notes'] ?? '');
        $newStatus  = $_POST['new_attendance_status'] ?? '';

        if (!in_array($action, ['approve','reject']) || !$did) {
            $error = 'Invalid request.';
        } elseif (!in_array($newStatus, ['Present','Late','Absent'])) {
            $error = 'Please select a valid new attendance status.';
        } else {
            // Validate teacher exists in database to satisfy FK constraint
            $reviewedBy = null;
            if ($teacher_id > 0) {
                $chk = $pdo->prepare("SELECT id FROM teachers WHERE id = ?");
                $chk->execute([$teacher_id]);
                if ($chk->fetch()) {
                    $reviewedBy = $teacher_id;
                } else {
                    $error = 'Your teacher account is not properly linked. Please contact admin.';
                }
            }

            if (!$error) {
                $ds = $pdo->prepare(
                    "SELECT dr.*, a.attendance_id FROM dispute_requests dr
                     JOIN attendance a ON dr.attendance_id = a.attendance_id
                     WHERE dr.dispute_id = ? AND dr.status IN ('Pending','Under Review') LIMIT 1"
                );
                $ds->execute([$did]);
                $dispute = $ds->fetch();

                if (!$dispute) {
                    $error = 'Dispute not found or already resolved.';
                } else {
                    $disputeStatus = $action === 'approve' ? 'Approved' : 'Rejected';
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare(
                            "UPDATE dispute_requests 
                             SET status = ?, reviewed_by = ?, resolution_notes = ?, resolved_at = NOW()
                             WHERE dispute_id = ?"
                        )->execute([$disputeStatus, $reviewedBy, $notes, $did]);

                        $pdo->prepare("UPDATE attendance SET status = ? WHERE attendance_id = ?")
                            ->execute([$newStatus, $dispute['attendance_id']]);

                        $pdo->commit();
                        $success = "Dispute #$did has been <strong>$disputeStatus</strong>. Attendance changed to <strong>$newStatus</strong>.";
                    } catch (PDOException $e) {
                        $pdo->rollBack();
                        $error = 'Database error: ' . $e->getMessage();
                        error_log('Dispute error: ' . $e->getMessage());
                    }
                }
            }
        }
    }
}

// ── Filter ─────────────────────────────────────────────────────────────────────
$filter = $_GET['filter'] ?? 'pending';
if (!in_array($filter, ['pending','approved','rejected','all'])) $filter = 'pending';

$wMap = [
    'pending'  => "dr.status IN ('Pending','Under Review')",
    'approved' => "dr.status = 'Approved'",
    'rejected' => "dr.status = 'Rejected'",
    'all'      => '1=1',
];

$disputes = $pdo->query("
    SELECT 
        dr.*,
        a.date as att_date, 
        a.time_in as att_time, 
        a.status as att_status,
        COALESCE(s.id, 0) as student_db_id,
        COALESCE(s.full_name, 'Unknown Student') as student_name, 
        COALESCE(s.student_number, 'N/A') as student_number, 
        COALESCE(s.course, '—') as course, 
        COALESCE(s.section, '—') as section,
        u.full_name as reviewer_name
    FROM dispute_requests dr
    LEFT JOIN attendance a ON dr.attendance_id = a.attendance_id
    LEFT JOIN students s ON dr.student_id = s.id
    LEFT JOIN teachers t ON dr.reviewed_by = t.id
    LEFT JOIN users u ON t.user_id = u.id
    WHERE {$wMap[$filter]}
    ORDER BY dr.submitted_at DESC
")->fetchAll();

$counts = $pdo->query("
    SELECT
        SUM(CASE WHEN status IN ('Pending','Under Review') THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected,
        COUNT(*) as total
    FROM dispute_requests
")->fetch();

$pageTitle    = 'Disputes';
$pageSubtitle = ($counts['pending'] ?? 0) . ' pending review';
$activePage   = 'disputes';
include 'layout.php';
?>

<!-- the rest of your HTML remains unchanged (starts with <div class="page-header">) -->
<div class="page-header">
    <div>
        <h2>Dispute Requests</h2>
        <p>Review student attendance disputes – you may change the attendance status to Present, Late, or Absent.</p>
    </div>
</div>

<?php if ($error):   ?><div class="alert alert-error">⚠ <?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success">✅ <?php echo $success; ?></div><?php endif; ?>

<!-- Summary stats -->
<div class="stats-row" style="margin-bottom:24px;">
    <div class="stat-card c-blue"><div class="stat-num"><?php echo $counts['total'] ?? 0; ?></div><div class="stat-label">Total</div></div>
    <div class="stat-card c-amber"><div class="stat-num"><?php echo $counts['pending'] ?? 0; ?></div><div class="stat-label">Pending</div></div>
    <div class="stat-card c-green"><div class="stat-num"><?php echo $counts['approved'] ?? 0; ?></div><div class="stat-label">Approved</div></div>
    <div class="stat-card c-red"><div class="stat-num"><?php echo $counts['rejected'] ?? 0; ?></div><div class="stat-label">Rejected</div></div>
</div>

<!-- Tab bar -->
<div class="tab-bar">
    <?php foreach (['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','all'=>'All'] as $k=>$lbl): ?>
        <a href="?filter=<?php echo $k; ?>" class="tab-btn <?php echo $filter === $k ? 'active' : ''; ?>">
            <?php echo $lbl; ?>
            <?php if ($k === 'pending' && ($counts['pending'] ?? 0)): ?>
                <span class="tab-count"><?php echo $counts['pending']; ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- Disputes list -->
<?php if (empty($disputes)): ?>
    <div class="card"><div class="empty-state">
        <div class="empty-icon">📋</div>
        <h4>No <?php echo $filter; ?> disputes</h4>
        <p>All caught up!</p>
    </div></div>
<?php else: ?>
    <?php foreach ($disputes as $d): ?>
    <div class="dispute-card <?php echo in_array($d['status'], ['Pending','Under Review']) ? 'is-pending' : ''; ?>">

        <!-- Header -->
        <div class="dispute-header">
            <div class="dispute-student">
                <div class="dispute-avatar"><?php echo initials($d['student_name']); ?></div>
                <div>
                    <strong style="font-size:15px;color:var(--dark);"><?php echo e($d['student_name']); ?></strong><br>
                    <small style="color:var(--muted);"><?php echo e($d['student_number']); ?> &mdash; <?php echo e($d['course']); ?>, <?php echo e($d['section']); ?></small>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:12px;">
                <span class="badge <?php echo statusClass($d['status']); ?>"><?php echo $d['status']; ?></span>
                <span style="font-size:12px;color:var(--muted);"><?php echo timeAgo($d['submitted_at']); ?></span>
                <a href="student-record.php?id=<?php echo $d['student_db_id']; ?>" class="btn btn-ghost btn-sm">View Profile</a>
            </div>
        </div>

        <!-- Body: record details + reason -->
        <div class="dispute-body">
            <div class="dispute-grid">
                <div class="dispute-field">
                    <span class="dispute-field-label">Record Date</span>
                    <span class="dispute-field-value"><?php echo date('F j, Y', strtotime($d['att_date'])); ?></span>
                </div>
                <div class="dispute-field">
                    <span class="dispute-field-label">Day</span>
                    <span class="dispute-field-value"><?php echo date('l', strtotime($d['att_date'])); ?></span>
                </div>
                <div class="dispute-field">
                    <span class="dispute-field-label">Recorded Time</span>
                    <span class="dispute-field-value"><?php echo $d['att_time'] ? date('g:i A', strtotime($d['att_time'])) : '—'; ?></span>
                </div>
                <div class="dispute-field">
                    <span class="dispute-field-label">Current Status</span>
                    <span><span class="badge <?php echo statusClass($d['att_status']); ?>"><?php echo $d['att_status']; ?></span></span>
                </div>
                <div class="dispute-field">
                    <span class="dispute-field-label">Dispute ID</span>
                    <span class="dispute-field-value mono">#<?php echo $d['dispute_id']; ?></span>
                </div>
                <div class="dispute-field">
                    <span class="dispute-field-label">Submitted</span>
                    <span class="dispute-field-value"><?php echo date('M j, Y g:i A', strtotime($d['submitted_at'])); ?></span>
                </div>
            </div>

            <div style="margin-bottom:12px;">
                <div class="dispute-field-label" style="margin-bottom:6px;">Student's Reason</div>
                <div class="reason-block"><?php echo nl2br(e($d['reason'])); ?></div>
            </div>

            <?php if ($d['reviewer_name']): ?>
            <div>
                <div class="dispute-field-label" style="margin-bottom:6px;">
                    Resolution by <?php echo e($d['reviewer_name']); ?>
                    <?php if ($d['resolved_at']): ?>
                        — <?php echo date('M j, Y g:i A', strtotime($d['resolved_at'])); ?>
                    <?php endif; ?>
                </div>
                <div class="reason-block" style="border-color:<?php echo $d['status'] === 'Approved' ? 'var(--success)' : 'var(--danger)'; ?>;">
                    <?php echo nl2br(e($d['resolution_notes'] ?: '(No notes provided)')); ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Review form (pending only) -->
        <?php if (in_array($d['status'], ['Pending','Under Review'])): ?>
        <form method="POST" class="review-form">
            <?php echo csrfField(); ?>
            <input type="hidden" name="dispute_id" value="<?php echo $d['dispute_id']; ?>">
            
            <div style="display:flex; flex-wrap:wrap; gap:15px; align-items:flex-end;">
                <div style="flex:2; min-width:160px;">
                    <div class="dispute-field-label" style="margin-bottom:5px;">New Attendance Status</div>
                    <select name="new_attendance_status" class="form-select" required style="width:100%; padding:8px;">
                        <option value="Present" <?php echo $d['att_status'] == 'Present' ? 'selected' : ''; ?>>Present</option>
                        <option value="Late"    <?php echo $d['att_status'] == 'Late'    ? 'selected' : ''; ?>>Late</option>
                        <option value="Absent"  <?php echo $d['att_status'] == 'Absent'  ? 'selected' : ''; ?>>Absent</option>
                    </select>
                </div>
                <div style="flex:3; min-width:200px;">
                    <div class="dispute-field-label" style="margin-bottom:5px;">Resolution Notes (optional)</div>
                    <input type="text" name="resolution_notes" class="review-notes"
                           placeholder="Add a note for the student (e.g., reason for approval/rejection)…" maxlength="500">
                </div>
                <div style="display:flex; gap:8px;">
                    <button type="submit" name="action" value="approve" class="btn btn-success"
                            onclick="return confirm('Approve this dispute? Attendance will be updated to the selected status.')">
                        ✓ Approve
                    </button>
                    <button type="submit" name="action" value="reject" class="btn btn-danger"
                            onclick="return confirm('Reject this dispute request? Attendance will be updated to the selected status.')">
                        ✗ Reject
                    </button>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php include 'layout-end.php'; ?>