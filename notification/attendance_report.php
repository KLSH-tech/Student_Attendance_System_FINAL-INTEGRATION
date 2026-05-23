<?php require_once __DIR__ . '/../includes/guard.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance Reports - Attendance System</title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<div class="sidebar">
    <h1>📊 Attendance Reports</h1>
    <p>View attendance records with late tracking</p>
</div>

<?php
// ── Bootstrap ─────────────────────────────────────────────────────────────────
require_once 'scanner.php';
$conn = connectDB();

$selected_date       = isset($_GET['date'])       ? $_GET['date']       : date('Y-m-d');
$class_filter_value  = (isset($_GET['class_code']) && $_GET['class_code'] !== '') ? $_GET['class_code'] : '';
$class_label         = $class_filter_value ?: 'All Classes';

// Validate date format to prevent injection
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
    $selected_date = date('Y-m-d');
}

// ── CSV Export: Attendance logs ───────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if ($class_filter_value) {
        $stmt = $conn->prepare("
            SELECT scan_time, full_name, student_id, class_code, attendance_status, scanned_by, notification_sent
            FROM attendance_logs
            WHERE DATE(scan_time) = ? AND class_code = ?
            ORDER BY scan_time DESC
        ");
        $stmt->bind_param("ss", $selected_date, $class_filter_value);
    } else {
        $stmt = $conn->prepare("
            SELECT scan_time, full_name, student_id, class_code, attendance_status, scanned_by, notification_sent
            FROM attendance_logs
            WHERE DATE(scan_time) = ?
            ORDER BY scan_time DESC
        ");
        $stmt->bind_param("s", $selected_date);
    }
    $stmt->execute();
    $rows = $stmt->get_result();

    $safe_label = preg_replace('/[^a-zA-Z0-9_-]/', '_', $class_label);
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"attendance_{$selected_date}_{$safe_label}.csv\"");
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Time', 'Student Name', 'Student ID', 'Class Code', 'Status', 'Scanned By', 'SMS Sent']);
    while ($r = $rows->fetch_assoc()) {
        fputcsv($out, [
            $r['scan_time'],
            $r['full_name'],
            $r['student_id'],
            $r['class_code'] ?? 'N/A',
            strtoupper($r['attendance_status']),
            ucfirst($r['scanned_by']),
            $r['notification_sent'] ? 'Yes' : 'No'
        ]);
    }
    fclose($out);
    $conn->close();
    exit;
}

// ── CSV Export: Absent students ───────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'absent_csv') {
    if ($class_filter_value) {
        $stmt = $conn->prepare("SELECT DISTINCT student_id FROM attendance_logs WHERE DATE(scan_time) = ? AND class_code = ?");
        $stmt->bind_param("ss", $selected_date, $class_filter_value);
    } else {
        $stmt = $conn->prepare("SELECT DISTINCT student_id FROM attendance_logs WHERE DATE(scan_time) = ?");
        $stmt->bind_param("s", $selected_date);
    }
    $stmt->execute();
    $present_result = $stmt->get_result();
    $present_ids    = [];
    while ($r = $present_result->fetch_assoc()) {
        $present_ids[] = "'" . $conn->real_escape_string($r['student_id']) . "'";
    }
    $not_in_clause   = count($present_ids) > 0 ? "AND student_id NOT IN (" . implode(',', $present_ids) . ")" : "";
    $absent_students = $conn->query("SELECT student_id, full_name, course, year_level, contact FROM students WHERE 1=1 {$not_in_clause} ORDER BY full_name");

    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"absent_students_{$selected_date}.csv\"");
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student ID', 'Full Name', 'Course', 'Year Level', 'Contact']);
    if ($absent_students) {
        while ($r = $absent_students->fetch_assoc()) {
            fputcsv($out, [$r['student_id'], $r['full_name'], $r['course'], $r['year_level'], $r['contact'] ?: '—']);
        }
    }
    fclose($out);
    $conn->close();
    exit;
}

// ── Statistics ────────────────────────────────────────────────────────────────
$total_students = $conn->query("SELECT COUNT(*) AS c FROM students")->fetch_assoc()['c'] ?? 0;

if ($class_filter_value) {
    $s1 = $conn->prepare("SELECT COUNT(DISTINCT student_id) AS c FROM attendance_logs WHERE DATE(scan_time)=? AND class_code=?");
    $s1->bind_param("ss", $selected_date, $class_filter_value); $s1->execute();
    $present_today = $s1->get_result()->fetch_assoc()['c'] ?? 0;

    $s2 = $conn->prepare("SELECT COUNT(*) AS c FROM attendance_logs WHERE DATE(scan_time)=? AND attendance_status='late' AND class_code=?");
    $s2->bind_param("ss", $selected_date, $class_filter_value); $s2->execute();
    $late_today = $s2->get_result()->fetch_assoc()['c'] ?? 0;

    $s3 = $conn->prepare("SELECT COUNT(*) AS c FROM attendance_logs WHERE DATE(scan_time)=? AND attendance_status='on_time' AND class_code=?");
    $s3->bind_param("ss", $selected_date, $class_filter_value); $s3->execute();
    $on_time_today = $s3->get_result()->fetch_assoc()['c'] ?? 0;
} else {
    $s1 = $conn->prepare("SELECT COUNT(DISTINCT student_id) AS c FROM attendance_logs WHERE DATE(scan_time)=?");
    $s1->bind_param("s", $selected_date); $s1->execute();
    $present_today = $s1->get_result()->fetch_assoc()['c'] ?? 0;

    $s2 = $conn->prepare("SELECT COUNT(*) AS c FROM attendance_logs WHERE DATE(scan_time)=? AND attendance_status='late'");
    $s2->bind_param("s", $selected_date); $s2->execute();
    $late_today = $s2->get_result()->fetch_assoc()['c'] ?? 0;

    $s3 = $conn->prepare("SELECT COUNT(*) AS c FROM attendance_logs WHERE DATE(scan_time)=? AND attendance_status='on_time'");
    $s3->bind_param("s", $selected_date); $s3->execute();
    $on_time_today = $s3->get_result()->fetch_assoc()['c'] ?? 0;
}

$absent_today     = $total_students - $present_today;
$attendance_rate  = $total_students > 0 ? round(($present_today / $total_students) * 100, 1) : 0;
$late_percentage  = $present_today  > 0 ? round(($late_today  / $present_today)  * 100, 1) : 0;

// Build export query string (preserve date & class_code filters)
$export_params = ['date' => $selected_date];
if ($class_filter_value) $export_params['class_code'] = $class_filter_value;
$export_qs       = http_build_query(array_merge($export_params, ['export' => 'csv']));
$absent_export_qs = http_build_query(array_merge($export_params, ['export' => 'absent_csv']));
?>

<div class="reports-container">

    <!-- ── Filter Bar ──────────────────────────────────────────────────────── -->
    <div class="report-header">
        <h2>Attendance Summary Report</h2>
        <form method="GET" class="filter-bar">
            <input type="date" name="date"
                   value="<?php echo htmlspecialchars($selected_date); ?>">

            <select name="class_code">
                <option value="">All Classes</option>
                <?php
                $classes = $conn->query("SELECT DISTINCT class_code, section FROM classes ORDER BY class_code");
                if ($classes) {
                    while ($cl = $classes->fetch_assoc()) {
                        $sel = ($class_filter_value === $cl['class_code']) ? 'selected' : '';
                        echo "<option value='" . htmlspecialchars($cl['class_code']) . "' {$sel}>"
                           . htmlspecialchars($cl['class_code']) . " — " . htmlspecialchars($cl['section'])
                           . "</option>";
                    }
                }
                ?>
            </select>

            <button type="submit">🔍 Filter</button>
            <button type="button" onclick="window.print()" class="print-btn">🖨️ Print</button>
            <a href="?<?php echo htmlspecialchars($export_qs); ?>" class="filter-bar-link export-btn">⬇️ Export Attendance CSV</a>
            <a href="?<?php echo htmlspecialchars($absent_export_qs); ?>" class="filter-bar-link export-btn" style="background:#6366f1;">⬇️ Export Absent CSV</a>
            <a href="index.php" class="back-btn">← Back to Scanner</a>
        </form>
    </div>

    <!-- ── Summary Cards ──────────────────────────────────────────────────── -->
    <div class="summary-cards">
        <div class="summary-card"><h3>Total Students</h3><div class="big-number blue"><?php echo $total_students; ?></div></div>
        <div class="summary-card"><h3>Present Today</h3><div class="big-number green"><?php echo $present_today; ?></div></div>
        <div class="summary-card"><h3>Absent Today</h3><div class="big-number red"><?php echo $absent_today; ?></div></div>
        <div class="summary-card"><h3>On Time</h3><div class="big-number green"><?php echo $on_time_today; ?></div></div>
        <div class="summary-card"><h3>Late</h3><div class="big-number orange"><?php echo $late_today; ?></div></div>
        <div class="summary-card"><h3>Attendance Rate</h3><div class="big-number blue"><?php echo $attendance_rate; ?>%</div></div>
        <div class="summary-card"><h3>Late Rate</h3><div class="big-number orange"><?php echo $late_percentage; ?>%</div></div>
    </div>

    <!-- ── Attendance Logs Table ──────────────────────────────────────────── -->
    <?php
    if ($class_filter_value) {
        $stmt = $conn->prepare("
            SELECT *, DATE_FORMAT(scan_time, '%h:%i:%s %p') AS formatted_time
            FROM attendance_logs
            WHERE DATE(scan_time) = ? AND class_code = ?
            ORDER BY scan_time DESC
        ");
        $stmt->bind_param("ss", $selected_date, $class_filter_value);
    } else {
        $stmt = $conn->prepare("
            SELECT *, DATE_FORMAT(scan_time, '%h:%i:%s %p') AS formatted_time
            FROM attendance_logs
            WHERE DATE(scan_time) = ?
            ORDER BY scan_time DESC
        ");
        $stmt->bind_param("s", $selected_date);
    }
    $stmt->execute();
    $logs = $stmt->get_result();
    ?>

    <div class="attendance-table">
        <h3>📋 Detailed Attendance Records —
            <?php echo date('F j, Y', strtotime($selected_date)); ?>
            <span style="font-size:14px; color:#6b7280; font-weight:400;">(<?php echo htmlspecialchars($class_label); ?>)</span>
        </h3>
        <table>
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Student Name</th>
                    <th>Student ID</th>
                    <th>Class Code</th>
                    <th>Status</th>
                    <th>Scanned By</th>
                    <th>SMS Sent</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($logs && $logs->num_rows > 0): ?>
                    <?php while ($log = $logs->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($log['formatted_time']); ?></td>
                        <td><?php echo htmlspecialchars($log['full_name']); ?></td>
                        <td><?php echo htmlspecialchars($log['student_id']); ?></td>
                        <td><?php echo htmlspecialchars($log['class_code'] ?? 'N/A'); ?></td>
                        <td>
                            <?php if ($log['attendance_status'] === 'late'): ?>
                                <span class="late-badge">⚠️ LATE</span>
                            <?php else: ?>
                                <span class="ontime-badge">✅ ON TIME</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-<?php echo htmlspecialchars($log['scanned_by']); ?>">
                                <?php echo $log['scanned_by'] === 'teacher' ? '👨‍🏫 Teacher' : '👨‍🎓 Student'; ?>
                            </span>
                        </td>
                        <td><?php echo $log['notification_sent'] ? '✅ Yes' : '❌ No'; ?></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align:center; padding:30px; color:#6b7280;">
                            No attendance records for this date.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ── Absent Students Table ──────────────────────────────────────────── -->
    <?php
    if ($class_filter_value) {
        $stmt = $conn->prepare("SELECT DISTINCT student_id FROM attendance_logs WHERE DATE(scan_time)=? AND class_code=?");
        $stmt->bind_param("ss", $selected_date, $class_filter_value);
    } else {
        $stmt = $conn->prepare("SELECT DISTINCT student_id FROM attendance_logs WHERE DATE(scan_time)=?");
        $stmt->bind_param("s", $selected_date);
    }
    $stmt->execute();
    $present_result2 = $stmt->get_result();
    $present_ids2    = [];
    while ($r = $present_result2->fetch_assoc()) {
        $present_ids2[] = "'" . $conn->real_escape_string($r['student_id']) . "'";
    }
    $not_in2       = count($present_ids2) > 0 ? "AND student_id NOT IN (" . implode(',', $present_ids2) . ")" : "";
    $absent_students = $conn->query("SELECT student_id, full_name, course, year_level, contact FROM students WHERE 1=1 {$not_in2} ORDER BY full_name");
    ?>

    <div class="attendance-table" style="margin-top:25px;">
        <h3>🚫 Absent Students — <?php echo date('F j, Y', strtotime($selected_date)); ?>
            <span style="font-size:14px; color:#ef4444; font-weight:400;">(<?php echo $absent_today; ?> absent)</span>
        </h3>
        <table>
            <thead>
                <tr>
                    <th>Student ID</th>
                    <th>Full Name</th>
                    <th>Course</th>
                    <th>Year Level</th>
                    <th>Contact</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($absent_students && $absent_students->num_rows > 0): ?>
                    <?php while ($s = $absent_students->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($s['student_id']); ?></td>
                        <td><?php echo htmlspecialchars($s['full_name']); ?></td>
                        <td><?php echo htmlspecialchars($s['course']); ?></td>
                        <td>Year <?php echo intval($s['year_level']); ?></td>
                        <td><?php echo htmlspecialchars($s['contact'] ?: '—'); ?></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" style="text-align:center; padding:30px; color:#10b981; font-weight:600;">
                            🎉 All students are present!
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ── SMS Message History Table ──────────────────────────────────────── -->
    <?php
    if ($class_filter_value) {
        $stmt = $conn->prepare("
            SELECT mh.*, DATE_FORMAT(mh.scan_time, '%h:%i:%s %p') AS formatted_time
            FROM message_history mh
            JOIN attendance_logs al ON al.log_id = mh.attendance_log_id
            WHERE DATE(mh.scan_time) = ? AND al.class_code = ?
            ORDER BY mh.scan_time DESC
        ");
        $stmt->bind_param("ss", $selected_date, $class_filter_value);
    } else {
        $stmt = $conn->prepare("
            SELECT *, DATE_FORMAT(scan_time, '%h:%i:%s %p') AS formatted_time
            FROM message_history
            WHERE DATE(scan_time) = ?
            ORDER BY scan_time DESC
        ");
        $stmt->bind_param("s", $selected_date);
    }
    $stmt->execute();
    $messages = $stmt->get_result();
    ?>

    <div class="attendance-table" style="margin-top:25px;">
        <h3>📱 SMS Notification History — <?php echo date('F j, Y', strtotime($selected_date)); ?></h3>
        <table>
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Student</th>
                    <th>Parent</th>
                    <th>Contact</th>
                    <th>Attendance</th>
                    <th>SMS Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($messages && $messages->num_rows > 0): ?>
                    <?php while ($msg = $messages->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($msg['formatted_time']); ?></td>
                        <td><?php echo htmlspecialchars($msg['student_name']); ?></td>
                        <td><?php echo htmlspecialchars($msg['parent_name']); ?></td>
                        <td><?php echo htmlspecialchars($msg['parent_contact']); ?></td>
                        <td>
                            <?php if ($msg['attendance_status'] === 'late'): ?>
                                <span class="late-badge">⚠️ LATE</span>
                            <?php else: ?>
                                <span class="ontime-badge">✅ ON TIME</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($msg['status'] === 'sent'): ?>
                                <span class="status-badge status-sent">✅ Sent</span>
                            <?php else: ?>
                                <span class="status-badge status-failed">❌ Failed</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" style="text-align:center; padding:30px; color:#6b7280;">
                            No SMS records for this date.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<?php $conn->close(); ?>

<style>
.filter-bar-link {
    display: inline-block;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s ease;
    color: white;
}
.export-btn { background: #10b981; }
.export-btn:hover { background: #059669; transform: translateY(-2px); }
.print-btn {
    padding: 10px 20px;
    background: #7c3aed;
    color: white;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    font-size: 14px;
    transition: all 0.2s ease;
}
.print-btn:hover { background: #6d28d9; transform: translateY(-2px); }
</style>

</body>
</html>
