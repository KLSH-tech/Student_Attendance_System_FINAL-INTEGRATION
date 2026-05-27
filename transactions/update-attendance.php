<?php
require_once __DIR__ . '/config.php';

$pdo = db();
$teacher_id = (int)($_SESSION['t_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: attendance.php');
    exit;
}

if (!verifyCsrf($_POST['csrf'] ?? '')) {
    $_SESSION['error'] = 'Security token mismatch.';
    header('Location: attendance.php');
    exit;
}

$attendance_id = (int)($_POST['attendance_id'] ?? 0);
$new_status    = $_POST['new_status'] ?? '';
$reason        = trim($_POST['reason'] ?? '');

if (!$attendance_id || !in_array($new_status, ['Present','Late','Absent'])) {
    $_SESSION['error'] = 'Invalid request.';
    header('Location: attendance.php');
    exit;
}

$stmt = $pdo->prepare("SELECT status, date FROM attendance WHERE attendance_id = ?");
$stmt->execute([$attendance_id]);
$old = $stmt->fetch();
if (!$old) {
    $_SESSION['error'] = 'Attendance record not found.';
    header('Location: attendance.php');
    exit;
}

$pdo->prepare("UPDATE attendance SET status = ? WHERE attendance_id = ?")
    ->execute([$new_status, $attendance_id]);

// Optional log table (skip if not exists)
try {
    $pdo->prepare("INSERT INTO attendance_logs (attendance_id, changed_by, old_status, new_status, reason, changed_at)
                   VALUES (?, ?, ?, ?, ?, NOW())")
        ->execute([$attendance_id, $teacher_id, $old['status'], $new_status, $reason]);
} catch (PDOException $e) {
    // log table might not exist – ignore
}

$_SESSION['success'] = "Attendance changed from {$old['status']} to <strong>$new_status</strong>.";
header('Location: attendance.php?date=' . $old['date']);
exit;