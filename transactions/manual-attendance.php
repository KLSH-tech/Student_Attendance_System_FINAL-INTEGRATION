<?php
require_once __DIR__ . '/config.php';
requireTeacher();   // guard: only signed-in teachers/admins past this point

$pdo        = db();
$teacher_id = (int)($_SESSION['t_id'] ?? 0);
$success    = '';
$error      = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf'] ?? '')) {
        $error = 'Security token mismatch.';
    } else {
        $student_id = (int)($_POST['student_id'] ?? 0);
        $date       = $_POST['date'] ?? '';
        $new_status = $_POST['status'] ?? '';
        $reason     = trim($_POST['reason'] ?? '');

        if (!$student_id || !$date || !in_array($new_status, ['Present','Late','Absent'])) {
            $error = 'Please select a student, date, and valid status.';
        } else {
            // Check if attendance record exists for that student & date
            $stmt = $pdo->prepare("SELECT attendance_id, status FROM attendance WHERE student_id = ? AND date = ?");
            $stmt->execute([$student_id, $date]);
            $existing = $stmt->fetch();

            $pdo->beginTransaction();
            try {
                if ($existing) {
                    // Update existing record
                    $pdo->prepare("UPDATE attendance SET status = ?, time_in = NULL, time_out = NULL WHERE attendance_id = ?")
                        ->execute([$new_status, $existing['attendance_id']]);
                    $att_id = $existing['attendance_id'];
                } else {
                    // Create new attendance record if none exists
                    $pdo->prepare("INSERT INTO attendance (student_id, date, status, created_by) VALUES (?, ?, ?, ?)")
                        ->execute([$student_id, $date, $new_status, $teacher_id]);
                    $att_id = $pdo->lastInsertId();
                }

                // Log the manual override (optional: create a dispute_requests entry as “Teacher Override”)
                $log = $pdo->prepare("
                    INSERT INTO attendance_logs (attendance_id, changed_by, old_status, new_status, reason, changed_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $log->execute([$att_id, $teacher_id, $existing['status'] ?? 'None', $new_status, $reason]);

                $pdo->commit();
                $success = "Attendance for " . date('F j, Y', strtotime($date)) . " updated to <strong>$new_status</strong>.";
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = 'Database error: ' . $e->getMessage();
                error_log($e->getMessage());
            }
        }
    }
}

// Get list of students for dropdown
$students = $pdo->query("SELECT id, full_name, student_number, course, section FROM students ORDER BY full_name")->fetchAll();

$pageTitle = 'Manual Attendance Adjustment';
$activePage = 'attendance'; // or 'manual'
include 'layout.php';
?>

<div class="page-header">
    <div>
        <h2>📝 Manual Attendance Override</h2>
        <p>Change a student's attendance status (for excuse letters, corrections, etc.)</p>
    </div>
</div>

<?php if ($error):   ?><div class="alert alert-error">⚠ <?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success">✅ <?php echo $success; ?></div><?php endif; ?>

<div class="card" style="max-width:700px; margin:0 auto;">
    <form method="POST">
        <?php echo csrfField(); ?>

        <div class="form-group">
            <label>Student *</label>
            <select name="student_id" required class="form-control">
                <option value="">-- Select Student --</option>
                <?php foreach ($students as $s): ?>
                    <option value="<?php echo $s['id']; ?>">
                        <?php echo e($s['full_name']); ?> (<?php echo e($s['student_number']); ?>) – <?php echo e($s['course']); ?> <?php echo e($s['section']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Date *</label>
            <input type="date" name="date" required value="<?php echo date('Y-m-d'); ?>" class="form-control">
        </div>

        <div class="form-group">
            <label>New Status *</label>
            <select name="status" required class="form-control">
                <option value="Present">✅ Present</option>
                <option value="Late">⏰ Late</option>
                <option value="Absent">❌ Absent</option>
            </select>
        </div>

        <div class="form-group">
            <label>Reason / Note (e.g., “Excuse letter received”)</label>
            <textarea name="reason" rows="3" class="form-control" placeholder="Optional – will be stored in log"></textarea>
        </div>

        <button type="submit" class="btn btn-primary">Update Attendance</button>
    </form>
</div>

<?php include 'layout-end.php'; ?>