<?php
/* ============================================================================
 *  ATTENDANCE REPORTS  ·  attendance_report.php
 *  ---------------------------------------------------------------------------
 *  Modernized to share the Notification Center design system, and repointed to
 *  the unified `student_attendance_system` schema (normalized tables) so every
 *  report, filter and CSV export works on the real database.
 *
 *  Read-only. No DB structure is modified. Every query goes through dbq(),
 *  which fails soft (returns null) so a missing table renders a clean empty
 *  state instead of a fatal error.
 * ========================================================================== */

date_default_timezone_set('Asia/Manila');

// ── Optional auth guard from the parent system (kept if present) ────────────
$__guard = __DIR__ . '/../includes/guard.php';
if (file_exists($__guard)) { require_once $__guard; }

// Database name for the unified Attendance System (change here if different).
$DB_NAME = 'student_attendance_system';

// ── Connection (tolerant; read-only) ────────────────────────────────────────
$conn = null;
try {
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = new mysqli("localhost", "root", "", $DB_NAME);
    if ($conn->connect_errno) { $conn = null; }
    else { $conn->set_charset("utf8mb4"); $conn->query("SET time_zone = '+08:00'"); }
} catch (\Throwable $e) { $conn = null; }

/** Safe prepared query → mysqli_result on SELECT, or null on any failure. */
function dbq($conn, $sql, $types = '', $params = []) {
    if (!$conn) return null;
    $st = $conn->prepare($sql);
    if (!$st) return null;
    if ($types !== '') { $st->bind_param($types, ...$params); }
    if (!$st->execute()) { return null; }
    $r = $st->get_result();
    return $r ?: null;
}
function dbcount($conn, $sql, $types = '', $params = []) {
    $r = dbq($conn, $sql, $types, $params);
    return $r ? (int) ($r->fetch_assoc()['c'] ?? 0) : 0;
}

// Detect optional e-mail audit table.
$hasEmailLog = false;
if ($conn && ($r = $conn->query("SHOW TABLES LIKE 'email_log'"))) { $hasEmailLog = $r->num_rows > 0; $r->free(); }

// ── Inputs ──────────────────────────────────────────────────────────────────
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) { $selected_date = date('Y-m-d'); }
$class_filter = (isset($_GET['class_id']) && $_GET['class_id'] !== '') ? (int) $_GET['class_id'] : 0;

// Resolve class label (code · section).
$class_label = 'All Classes';
if ($class_filter) {
    $r = dbq($conn, "SELECT class_code, section FROM classes WHERE class_id = ?", "i", [$class_filter]);
    if ($r && ($cl = $r->fetch_assoc())) {
        $class_label = trim(($cl['class_code'] ?? '') . ($cl['section'] ? ' · ' . $cl['section'] : ''));
    }
}

/* ── CSV EXPORT: Attendance logs ─────────────────────────────────────────── */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $sql = "SELECT al.logged_at, s.full_name, s.student_number, c.class_code,
                   sub.subject_name, al.attendance_status, al.action, al.scanned_by,
                   al.notification_sent, al.sms_sent
            FROM attendance_logs al
            LEFT JOIN students s ON s.id = al.student_id
            LEFT JOIN classes  c ON c.class_id = al.class_id
            LEFT JOIN subjects sub ON sub.course_code = c.course_code
            WHERE DATE(al.logged_at) = ? " . ($class_filter ? "AND al.class_id = ? " : "") . "
            ORDER BY al.logged_at DESC";
    $rows = $class_filter ? dbq($conn, $sql, "si", [$selected_date, $class_filter])
                          : dbq($conn, $sql, "s",  [$selected_date]);
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $class_label);
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"attendance_{$selected_date}_{$safe}.csv\"");
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Time','Student Name','Student No','Class','Subject','Status','Action','Recorded By','SMS Sent']);
    if ($rows) while ($r = $rows->fetch_assoc()) {
        fputcsv($out, [$r['logged_at'], $r['full_name'], $r['student_number'], $r['class_code'] ?? 'N/A',
            $r['subject_name'] ?? 'N/A', strtoupper($r['attendance_status'] ?: 'on_time'),
            strtoupper($r['action'] ?: 'in'), $r['scanned_by'] ?: 'terminal',
            ($r['sms_sent'] || $r['notification_sent']) ? 'Yes' : 'No']);
    }
    fclose($out); if ($conn) $conn->close(); exit;
}

/* ── CSV EXPORT: Absent students ─────────────────────────────────────────── */
if (isset($_GET['export']) && $_GET['export'] === 'absent_csv') {
    $present = [];
    $sql = "SELECT DISTINCT student_id FROM attendance_logs WHERE DATE(logged_at)=? AND action='in' " . ($class_filter ? "AND class_id=?" : "");
    $pr = $class_filter ? dbq($conn, $sql, "si", [$selected_date, $class_filter]) : dbq($conn, $sql, "s", [$selected_date]);
    if ($pr) while ($row = $pr->fetch_assoc()) { $present[] = (int) $row['student_id']; }
    $not_in = count($present) ? "WHERE id NOT IN (" . implode(',', $present) . ")" : "";
    $absent = $conn ? $conn->query("SELECT student_number, full_name, course, year_level, section, contact FROM students {$not_in} ORDER BY full_name") : false;
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"absent_students_{$selected_date}.csv\"");
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student No','Full Name','Course','Year Level','Section','Contact']);
    if ($absent) while ($r = $absent->fetch_assoc()) {
        fputcsv($out, [$r['student_number'], $r['full_name'], $r['course'], $r['year_level'], $r['section'], $r['contact'] ?: '—']);
    }
    fclose($out); if ($conn) $conn->close(); exit;
}

/* ── Statistics ──────────────────────────────────────────────────────────── */
$total_students = dbcount($conn, "SELECT COUNT(*) c FROM students");
$cls = $class_filter ? "AND class_id = ?" : "";
$mk = function ($where) use ($conn, $selected_date, $class_filter, $cls) {
    $sql = "SELECT COUNT(DISTINCT student_id) c FROM attendance_logs WHERE DATE(logged_at)=? AND action='in' {$where} {$cls}";
    return $class_filter ? dbcount($conn, $sql, "si", [$selected_date, $class_filter]) : dbcount($conn, $sql, "s", [$selected_date]);
};
$present_today = $mk("");
$late_today    = $mk("AND attendance_status='late'");
$on_time_today = $mk("AND attendance_status='on_time'");
$absent_today    = max(0, $total_students - $present_today);
$attendance_rate = $total_students > 0 ? round($present_today / $total_students * 100, 1) : 0;
$late_rate       = $present_today  > 0 ? round($late_today  / $present_today  * 100, 1) : 0;

$base = ['date' => $selected_date]; if ($class_filter) $base['class_id'] = $class_filter;
$export_qs        = http_build_query(array_merge($base, ['export' => 'csv']));
$absent_export_qs = http_build_query(array_merge($base, ['export' => 'absent_csv']));

function ic($p, $w = 2) { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="'.$w.'" stroke-linecap="round" stroke-linejoin="round">'.$p.'</svg>'; }
$pretty_date = date('F j, Y', strtotime($selected_date));
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance Reports · Student Attendance System</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="ambient"></div>

<?php
$__nav = __DIR__ . '/../includes/nav.php';
if (file_exists($__nav)) { require_once $__nav; if (function_exists('renderNav')) { renderNav('notifications'); } }
?>

<header class="rep-header">
  <div class="rep-header-inner">
    <div class="rep-titleblock">
      <div class="rep-icon"><?php echo ic('<path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/>', 1.8); ?></div>
      <div>
        <div class="rep-title">Attendance Reports</div>
        <div class="rep-subtitle"><?php echo htmlspecialchars($pretty_date . ' · ' . $class_label); ?></div>
      </div>
    </div>
    <a href="index.php" class="rep-back"><?php echo ic('<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>'); ?> Notification Center</a>
  </div>
</header>

<div class="rep-wrap">

  <form method="GET" class="rep-filter">
    <div class="rep-field"><input type="date" name="date" value="<?php echo htmlspecialchars($selected_date); ?>"></div>
    <div class="rep-field">
      <select name="class_id">
        <option value="">All Classes</option>
        <?php
        $cls_rows = $conn ? $conn->query("SELECT c.class_id, c.class_code, c.section, sub.subject_name FROM classes c LEFT JOIN subjects sub ON sub.course_code = c.course_code ORDER BY c.class_code") : false;
        if ($cls_rows) while ($c = $cls_rows->fetch_assoc()) {
            $sel = ($class_filter === (int)$c['class_id']) ? 'selected' : '';
            $txt = $c['class_code'] . ($c['section'] ? ' · ' . $c['section'] : '') . ($c['subject_name'] ? ' — ' . $c['subject_name'] : '');
            echo "<option value='".(int)$c['class_id']."' {$sel}>".htmlspecialchars($txt)."</option>";
        }
        ?>
      </select>
      <?php echo ic('<path d="m6 9 6 6 6-6"/>'); ?>
    </div>
    <button type="submit" class="rep-btn rep-btn-primary"><?php echo ic('<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>'); ?> Apply</button>
    <div class="rep-spacer"></div>
    <button type="button" onclick="window.print()" class="rep-btn rep-btn-soft"><?php echo ic('<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/>'); ?> Print</button>
    <a href="?<?php echo htmlspecialchars($export_qs); ?>" class="rep-btn rep-btn-export"><?php echo ic('<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/>'); ?> Attendance CSV</a>
    <a href="?<?php echo htmlspecialchars($absent_export_qs); ?>" class="rep-btn rep-btn-soft"><?php echo ic('<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/>'); ?> Absent CSV</a>
  </form>

  <div class="rep-stats">
    <div class="rep-stat blue"><div class="rep-stat-label">Total Students</div><div class="rep-stat-value"><?php echo $total_students; ?></div></div>
    <div class="rep-stat green"><div class="rep-stat-label">Present</div><div class="rep-stat-value"><?php echo $present_today; ?></div></div>
    <div class="rep-stat red"><div class="rep-stat-label">Absent</div><div class="rep-stat-value"><?php echo $absent_today; ?></div></div>
    <div class="rep-stat green"><div class="rep-stat-label">On Time</div><div class="rep-stat-value"><?php echo $on_time_today; ?></div></div>
    <div class="rep-stat amber"><div class="rep-stat-label">Late</div><div class="rep-stat-value"><?php echo $late_today; ?></div></div>
    <div class="rep-stat blue"><div class="rep-stat-label">Attendance Rate</div><div class="rep-stat-value"><?php echo $attendance_rate; ?>%</div></div>
    <div class="rep-stat amber"><div class="rep-stat-label">Late Rate</div><div class="rep-stat-value"><?php echo $late_rate; ?>%</div></div>
  </div>

  <?php
  $logs_sql = "SELECT al.logged_at, al.action, al.attendance_status, al.scanned_by, al.notification_sent, al.sms_sent,
                      s.full_name, s.student_number, c.class_code, c.section, sub.subject_name,
                      DATE_FORMAT(al.logged_at, '%h:%i %p') AS t
               FROM attendance_logs al
               LEFT JOIN students s ON s.id = al.student_id
               LEFT JOIN classes  c ON c.class_id = al.class_id
               LEFT JOIN subjects sub ON sub.course_code = c.course_code
               WHERE DATE(al.logged_at) = ? " . ($class_filter ? "AND al.class_id = ? " : "") . "
               ORDER BY al.logged_at DESC";
  $logs = $class_filter ? dbq($conn, $logs_sql, "si", [$selected_date, $class_filter]) : dbq($conn, $logs_sql, "s", [$selected_date]);
  ?>
  <div class="rep-card">
    <div class="rep-card-head">
      <h3><?php echo ic('<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>', 1.8); ?> Attendance Activity</h3>
      <span class="rep-card-meta"><?php echo htmlspecialchars($pretty_date . ' · ' . $class_label); ?></span>
    </div>
    <div class="rep-tablewrap">
      <table class="rep-table">
        <thead><tr><th>Time</th><th>Student</th><th>Student No</th><th>Class · Subject</th><th>Status</th><th>Action</th><th>Recorded By</th><th>Notified</th></tr></thead>
        <tbody>
        <?php if ($logs && $logs->num_rows): while ($l = $logs->fetch_assoc()):
            $stt = $l['attendance_status'] ?: 'on_time';
            $pill = $stt === 'late' ? 'late' : ($stt === 'absent' ? 'absent' : 'ontime');
            $plabel = $stt === 'late' ? 'Late' : ($stt === 'absent' ? 'Absent' : 'On Time');
            $sub = $l['subject_name'] ?: ($l['class_code'] ?: 'N/A');
            $notified = ($l['sms_sent'] || $l['notification_sent']);
        ?>
          <tr>
            <td class="rep-mono"><?php echo htmlspecialchars($l['t']); ?></td>
            <td class="rep-name"><?php echo htmlspecialchars($l['full_name'] ?: '—'); ?></td>
            <td class="rep-mono"><?php echo htmlspecialchars($l['student_number'] ?: '—'); ?></td>
            <td><?php echo htmlspecialchars(($l['class_code'] ?: '—') . ' · ' . $sub); ?><?php echo $l['section'] ? " <span class='rep-mono'>".htmlspecialchars($l['section'])."</span>" : ''; ?></td>
            <td><span class="rep-pill <?php echo $pill; ?>"><?php echo $plabel; ?></span></td>
            <td><span class="rep-pill neutral"><?php echo strtoupper(htmlspecialchars($l['action'] ?: 'in')); ?></span></td>
            <td><?php echo htmlspecialchars($l['scanned_by'] ?: 'terminal'); ?></td>
            <td><?php echo $notified ? '<span class="rep-pill sent">Sent</span>' : '<span class="rep-pill neutral">—</span>'; ?></td>
          </tr>
        <?php endwhile; else: ?>
          <tr class="rep-empty-row"><td colspan="8">No attendance activity for this date.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php
  $present = [];
  $psql = "SELECT DISTINCT student_id FROM attendance_logs WHERE DATE(logged_at)=? AND action='in' " . ($class_filter ? "AND class_id=?" : "");
  $pr = $class_filter ? dbq($conn, $psql, "si", [$selected_date, $class_filter]) : dbq($conn, $psql, "s", [$selected_date]);
  if ($pr) while ($row = $pr->fetch_assoc()) { $present[] = (int) $row['student_id']; }
  $not_in = count($present) ? "WHERE id NOT IN (" . implode(',', $present) . ")" : "";
  $absent_students = $conn ? $conn->query("SELECT student_number, full_name, course, year_level, section, contact FROM students {$not_in} ORDER BY full_name") : false;
  ?>
  <div class="rep-card">
    <div class="rep-card-head">
      <h3><?php echo ic('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m17 8 5 5M22 8l-5 5"/>', 1.8); ?> Absent Students</h3>
      <span class="rep-card-meta"><?php echo $absent_today; ?> absent · <?php echo htmlspecialchars($pretty_date); ?></span>
    </div>
    <div class="rep-tablewrap">
      <table class="rep-table">
        <thead><tr><th>Student No</th><th>Full Name</th><th>Course</th><th>Year</th><th>Section</th><th>Contact</th></tr></thead>
        <tbody>
        <?php if ($absent_students && $absent_students->num_rows): while ($s = $absent_students->fetch_assoc()): ?>
          <tr>
            <td class="rep-mono"><?php echo htmlspecialchars($s['student_number']); ?></td>
            <td class="rep-name"><?php echo htmlspecialchars($s['full_name']); ?></td>
            <td><?php echo htmlspecialchars($s['course'] ?: '—'); ?></td>
            <td><?php echo $s['year_level'] ? 'Year ' . intval($s['year_level']) : '—'; ?></td>
            <td><?php echo htmlspecialchars($s['section'] ?: '—'); ?></td>
            <td class="rep-mono"><?php echo htmlspecialchars($s['contact'] ?: '—'); ?></td>
          </tr>
        <?php endwhile; elseif ($conn && $total_students > 0): ?>
          <tr class="rep-empty-row good"><td colspan="6">Everyone is present.</td></tr>
        <?php else: ?>
          <tr class="rep-empty-row"><td colspan="6">No student records for this date.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php
  $history = [];
  $hsql = "SELECT mh.sent_at, mh.status, mh.attendance_status, mh.parent_contact AS recipient, s.full_name, p.parent_name
           FROM message_history mh
           LEFT JOIN students s ON s.id = mh.student_id
           LEFT JOIN parents  p ON p.student_id = mh.student_id
           " . ($class_filter ? "LEFT JOIN attendance_logs al ON al.log_id = mh.log_id WHERE DATE(mh.sent_at)=? AND al.class_id=?" : "WHERE DATE(mh.sent_at)=?") . "
           ORDER BY mh.sent_at DESC LIMIT 200";
  $rs = $class_filter ? dbq($conn, $hsql, "si", [$selected_date, $class_filter]) : dbq($conn, $hsql, "s", [$selected_date]);
  if ($rs) while ($r = $rs->fetch_assoc()) {
      $history[] = ['channel'=>'SMS','t'=>$r['sent_at'],'student'=>$r['full_name'],'to'=>$r['parent_name'] ?: $r['recipient'],'status'=>$r['status'],'attendance'=>$r['attendance_status']];
  }
  if ($hasEmailLog) {
      $esql = "SELECT el.sent_at, el.status, el.attendance_status, el.recipient, s.full_name
               FROM email_log el LEFT JOIN students s ON s.id = el.student_id
               " . ($class_filter ? "LEFT JOIN attendance_logs al ON al.log_id = el.log_id WHERE DATE(el.sent_at)=? AND al.class_id=?" : "WHERE DATE(el.sent_at)=?") . "
               ORDER BY el.sent_at DESC LIMIT 200";
      $rs = $class_filter ? dbq($conn, $esql, "si", [$selected_date, $class_filter]) : dbq($conn, $esql, "s", [$selected_date]);
      if ($rs) while ($r = $rs->fetch_assoc()) {
          $history[] = ['channel'=>'Email','t'=>$r['sent_at'],'student'=>$r['full_name'],'to'=>$r['recipient'],'status'=>$r['status'],'attendance'=>$r['attendance_status']];
      }
  }
  usort($history, fn($a,$b) => strcmp((string)$b['t'], (string)$a['t']));
  ?>
  <div class="rep-card">
    <div class="rep-card-head">
      <h3><?php echo ic('<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>', 1.8); ?> Notification History</h3>
      <span class="rep-card-meta"><?php echo count($history); ?> sent · <?php echo htmlspecialchars($pretty_date); ?></span>
    </div>
    <div class="rep-tablewrap">
      <table class="rep-table">
        <thead><tr><th>Time</th><th>Channel</th><th>Student</th><th>Recipient</th><th>Attendance</th><th>Delivery</th></tr></thead>
        <tbody>
        <?php if (count($history)): foreach ($history as $h):
            $st_ok = strtolower((string)$h['status']) === 'sent';
            $att = strtolower((string)$h['attendance']);
            $apill = $att === 'late' ? 'late' : ($att === 'absent' ? 'absent' : 'ontime');
            $alabel = $att === 'late' ? 'Late' : ($att === 'absent' ? 'Absent' : 'On Time');
        ?>
          <tr>
            <td class="rep-mono"><?php echo htmlspecialchars(date('h:i A', strtotime($h['t']))); ?></td>
            <td><span class="rep-pill <?php echo $h['channel']==='Email'?'teacher':'neutral'; ?>"><?php echo htmlspecialchars($h['channel']); ?></span></td>
            <td class="rep-name"><?php echo htmlspecialchars($h['student'] ?: '—'); ?></td>
            <td class="rep-mono"><?php echo htmlspecialchars($h['to'] ?: '—'); ?></td>
            <td><span class="rep-pill <?php echo $apill; ?>"><?php echo $alabel; ?></span></td>
            <td><?php echo $st_ok ? '<span class="rep-pill sent">Sent</span>' : '<span class="rep-pill failed">'.htmlspecialchars(ucfirst($h['status'] ?: 'failed')).'</span>'; ?></td>
          </tr>
        <?php endforeach; else: ?>
          <tr class="rep-empty-row"><td colspan="6">No notification activity for this date.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<?php if ($conn) { $conn->close(); } ?>

<script>
(function(){ var LS='nc_theme'; var t; try{t=localStorage.getItem(LS);}catch(e){}
  if(!t) t = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark':'light';
  document.documentElement.dataset.theme = t; })();
</script>
</body>
</html>