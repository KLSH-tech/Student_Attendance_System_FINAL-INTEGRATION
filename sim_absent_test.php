<?php
// ============================================================================
// sim_absent_test.php — Standalone SIMULATION of the auto-absence logic.
// Uses SQLite (PDO) so it runs with no MySQL. It mirrors the REAL schema, REAL
// seed data and the SAME query logic from includes/absent_processor.php; only
// the MySQL-only date functions are swapped for their SQLite equivalents:
//     TIME(x)                         -> time(x)
//     ADDTIME(t, SEC_TO_TIME(n*60))   -> time(t, '+n minutes')
//     TIMESTAMP(d, t)                 -> d || ' ' || t
//     INSERT IGNORE                   -> INSERT OR IGNORE
//     DATE(x)                         -> date(x)
// The relational logic (assigned − scanned = absent, NOT EXISTS dedup, the
// UNIQUE(student_id,class_id,date) override-guard, the section join) is identical.
// ============================================================================

$pass = 0; $fail = 0;
function check(string $label, bool $cond) {
    global $pass, $fail;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . PHP_EOL;
    $cond ? $pass++ : $fail++;
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// ── Schema (same columns; TEXT stands in for MySQL ENUM) ────────────────────
$db->exec("
CREATE TABLE students (
    id INTEGER PRIMARY KEY, student_number TEXT, full_name TEXT, section TEXT, email TEXT
);
CREATE TABLE classes (
    class_id INTEGER PRIMARY KEY, course_code TEXT, section TEXT,
    teacher_id INTEGER, grace_period_minutes INTEGER DEFAULT 15
);
CREATE TABLE schedules (
    schedule_id INTEGER PRIMARY KEY, class_id INTEGER, day TEXT,
    start_time TEXT, end_time TEXT, room TEXT
);
CREATE TABLE student_schedule (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER, schedule_id INTEGER, class_id INTEGER,
    UNIQUE(student_id, schedule_id, class_id)
);
CREATE TABLE attendance_logs (
    log_id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER, student_number TEXT, class_id INTEGER, schedule_id INTEGER,
    scanned_by TEXT, action TEXT, attendance_status TEXT,
    notification_sent INTEGER DEFAULT 0, sms_sent INTEGER DEFAULT 0, email_sent INTEGER DEFAULT 0,
    logged_at TEXT
);
CREATE TABLE attendance (
    attendance_id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER, class_id INTEGER, teacher_id INTEGER,
    date TEXT, time_in TEXT, time_out TEXT, status TEXT DEFAULT 'Present',
    UNIQUE(student_id, class_id, date)            -- = uq_attendance
);
");

// ── Seed: real slice from student_attendance_system.sql ─────────────────────
// classes: class 1 = IT11 / BSIT 1A / teacher 16 / grace 15
//          class 3 = ELECIT103 / BSIT 2A / teacher 17 / grace 15
$db->exec("INSERT INTO classes (class_id,course_code,section,teacher_id,grace_period_minutes) VALUES
  (1,'IT11','BSIT 1A',16,15), (3,'ELECIT103','BSIT 2A',17,15)");

// schedules: 8 = class1 Mon 09:30-10:30 ; 10 & 11 = class3 Mon (two same-day blocks)
$db->exec("INSERT INTO schedules (schedule_id,class_id,day,start_time,end_time,room) VALUES
  (8, 1,'Monday','09:30:00','10:30:00','COM LAB A'),
  (10,3,'Monday','13:00:00','14:30:00','COM LAB A'),
  (11,3,'Monday','14:30:00','16:30:00','COM LAB A')");

// students (subset). BSIT 1A: 1,2,3,4,5,7  | BSIT 2A: 27,28,29
$db->exec("INSERT INTO students (id,student_number,full_name,section,email) VALUES
  (1,'B2017586','Alsado, Kean Rose M.','BSIT 1A',NULL),
  (2,'20250249','Arriola, Adrian James T.','BSIT 1A','vasel@example.com'),
  (3,'20250815','Barinos, John Homer N.','BSIT 1A',NULL),
  (4,'20250279','Diosoy, Vincent Jay S.','BSIT 1A',NULL),
  (5,'20250508','Encaja, Karl Timothy E.','BSIT 1A',NULL),
  (7,'20250634','Garsula, Polo James A.','BSIT 1A',NULL),
  (27,'20220003','Abella, Mark Allexi T.','BSIT 2A',NULL),
  (28,'20240295','Aquino, Zurich Clyde O.','BSIT 2A',NULL),
  (29,'20240225','Bagaporo, Alexa P.','BSIT 2A',NULL)");

// student_schedule assignments
$db->exec("INSERT INTO student_schedule (student_id,schedule_id,class_id) VALUES
  (1,8,1),(2,8,1),(3,8,1),(4,8,1),(5,8,1),(7,8,1),
  (27,10,3),(28,10,3),(29,10,3),
  (27,11,3),(28,11,3),(29,11,3)");

// Existing SCANS for schedule 8 today (Mon 2026-05-25): students 1,2,3,4 scanned.
//   • student 2 scanned LATE; 1,3,4 on_time. 5 and 7 did NOT scan.
$today = '2026-05-25';   // a Monday
$db->exec("INSERT INTO attendance_logs (student_id,student_number,class_id,schedule_id,scanned_by,action,attendance_status,logged_at) VALUES
  (1,'B2017586',1,8,'student_terminal','in','on_time','$today 09:31:00'),
  (1,'B2017586',1,8,'student_terminal','out','on_time','$today 10:00:00'),
  (2,'20250249',1,8,'student_terminal','in','late','$today 09:50:00'),
  (3,'20250815',1,8,'student_terminal','in','on_time','$today 09:33:00'),
  (4,'20250279',1,8,'student_terminal','in','on_time','$today 09:34:00')");
// Mirror those scans into the summary table (as the scanner's syncAttendanceSummary would)
$db->exec("INSERT OR IGNORE INTO attendance (student_id,class_id,teacher_id,date,time_in,status) VALUES
  (1,1,16,'$today','09:31:00','Present'),
  (2,1,16,'$today','09:50:00','Late'),
  (3,1,16,'$today','09:33:00','Present'),
  (4,1,16,'$today','09:34:00','Present')");

// For class 3: student 27 scanned IN for schedule 11 (the afternoon block).
//   When we later process schedule 10's absence, 27 must NOT be marked Absent in
//   the SUMMARY (same class/day), but the per-schedule LOG for sched 10 is its own grain.
$db->exec("INSERT INTO attendance_logs (student_id,student_number,class_id,schedule_id,scanned_by,action,attendance_status,logged_at) VALUES
  (27,'20220003',3,11,'student_terminal','in','on_time','$today 14:40:00')");
$db->exec("INSERT OR IGNORE INTO attendance (student_id,class_id,teacher_id,date,time_in,status) VALUES
  (27,3,17,'$today','14:40:00','Present')");

// ── The processor logic (SQLite dialect; same relational logic) ─────────────
function runSweep(PDO $db, string $nowDateTime, ?int $onlySched = null): array {
    $day  = date('l', strtotime($nowDateTime));
    $date = date('Y-m-d', strtotime($nowDateTime));
    $time = date('H:i:s', strtotime($nowDateTime));

    $schedFilter = ''; $bind = [':day'=>$day, ':t'=>$time, ':d1'=>$date, ':d2'=>$date];
    if ($onlySched !== null) { $schedFilter = " AND sch.schedule_id = :only "; $bind[':only'] = $onlySched; }

    // due = time(:t) > time(start, '+grace minutes')
    $due = "time(:t) > time(sch.start_time, '+' || COALESCE(c.grace_period_minutes,15) || ' minutes')";

    // 1) raw logs — TWO STEP (mirrors production: SELECT candidates, then INSERT)
    $selSql = "
      SELECT ss.student_id, st.student_number, ss.class_id, ss.schedule_id,
             :d1 || ' ' || time(sch.start_time, '+' || COALESCE(c.grace_period_minutes,15) || ' minutes') AS logged_at
      FROM student_schedule ss
      JOIN students  st  ON st.id           = ss.student_id
      JOIN schedules sch ON sch.schedule_id = ss.schedule_id
      JOIN classes   c   ON c.class_id      = ss.class_id
      WHERE sch.day = :day {$schedFilter} AND {$due}
        AND NOT EXISTS (
            SELECT 1 FROM attendance_logs al
            WHERE al.student_id=ss.student_id AND al.schedule_id=ss.schedule_id
              AND al.class_id=ss.class_id AND date(al.logged_at)=:d2)";
    $sel = $db->prepare($selSql); $sel->execute($bind); $cands = $sel->fetchAll();
    $logs = 0;
    foreach ($cands as $row) {
        $ins = $db->prepare("INSERT INTO attendance_logs
            (student_id, student_number, class_id, schedule_id, scanned_by, action,
             attendance_status, notification_sent, sms_sent, email_sent, logged_at)
            VALUES (?,?,?,?, 'system_auto','absent','absent', 0,0,0, ?)");
        $ins->execute([(int)$row['student_id'], $row['student_number'], (int)$row['class_id'],
                       (int)$row['schedule_id'], $row['logged_at']]);
        $logs += $ins->rowCount();
    }

    // 2) summary (INSERT OR IGNORE relies on UNIQUE(student_id,class_id,date))
    $b2 = [':d'=>$date, ':day'=>$day, ':t'=>$time];
    if ($onlySched !== null) { $b2[':only'] = $onlySched; }
    $sumSql = "
      INSERT OR IGNORE INTO attendance (student_id, class_id, teacher_id, date, status)
      SELECT DISTINCT ss.student_id, ss.class_id, c.teacher_id, :d, 'Absent'
      FROM student_schedule ss
      JOIN schedules sch ON sch.schedule_id = ss.schedule_id
      JOIN classes   c   ON c.class_id      = ss.class_id
      WHERE sch.day = :day {$schedFilter} AND {$due}";
    $s2 = $db->prepare($sumSql); $s2->execute($b2); $sum = $s2->rowCount();

    return ['logs'=>$logs, 'summary'=>$sum, 'day'=>$day, 'date'=>$date, 'time'=>$time];
}

echo "================= SIMULATION A: schedule 8, now = Mon 09:46 (grace ended 09:45) =================\n";
$r = runSweep($db, '2026-05-25 09:46:00');
echo "  due day={$r['day']} date={$r['date']} time={$r['time']}  | logs+{$r['logs']} summary+{$r['summary']}\n";

// Schedule 8 has 6 assigned (1,2,3,4,5,7). Scanned: 1,2,3,4. Unscanned: 5,7 -> 2 absents.
check("schedule 8: exactly 2 absent LOG rows inserted (students 5 & 7)", $r['logs'] === 2);

$absLogs = $db->query("SELECT student_id FROM attendance_logs WHERE schedule_id=8 AND attendance_status='absent' ORDER BY student_id")
              ->fetchAll(PDO::FETCH_COLUMN);
check("absent logs are for students 5 and 7", $absLogs === ['5','7'] || $absLogs === [5,7]);

check("scanned students (1,2,3,4) NOT marked absent in logs",
      (int)$db->query("SELECT COUNT(*) FROM attendance_logs WHERE schedule_id=8 AND attendance_status='absent' AND student_id IN (1,2,3,4)")->fetchColumn() === 0);

check("absent log uses scanned_by='system_auto' and action='absent'",
      (int)$db->query("SELECT COUNT(*) FROM attendance_logs WHERE schedule_id=8 AND attendance_status='absent' AND scanned_by='system_auto' AND action='absent'")->fetchColumn() === 2);

check("absent log has notification_sent=0, sms_sent=0, email_sent=0 initially",
      (int)$db->query("SELECT COUNT(*) FROM attendance_logs WHERE schedule_id=8 AND attendance_status='absent' AND notification_sent=0 AND sms_sent=0 AND email_sent=0")->fetchColumn() === 2);

check("logged_at stamped at grace cutoff 09:45:00",
      (string)$db->query("SELECT DISTINCT time(logged_at) FROM attendance_logs WHERE schedule_id=8 AND attendance_status='absent'")->fetchColumn() === '09:45:00');

// Summary: students 5 & 7 should now be Absent; 1,3,4 Present; 2 Late.
check("summary: 2 new Absent rows inserted", $r['summary'] === 2);
$sumAbs = $db->query("SELECT student_id FROM attendance WHERE class_id=1 AND date='$today' AND status='Absent' ORDER BY student_id")->fetchAll(PDO::FETCH_COLUMN);
check("summary Absent rows are students 5 and 7", ($sumAbs === ['5','7'] || $sumAbs === [5,7]));
check("summary did NOT overwrite student 2's 'Late'",
      (string)$db->query("SELECT status FROM attendance WHERE student_id=2 AND class_id=1 AND date='$today'")->fetchColumn() === 'Late');
check("summary did NOT overwrite student 1/3/4 'Present'",
      (int)$db->query("SELECT COUNT(*) FROM attendance WHERE student_id IN(1,3,4) AND class_id=1 AND date='$today' AND status='Present'")->fetchColumn() === 3);

echo "\n--- SECTION + display fields visible via the portal's existing join ---\n";
$display = $db->query("
   SELECT s.full_name, s.section, c.course_code AS subject, a.status,
          sch.start_time||'-'||sch.end_time AS sched_time, c.teacher_id, a.date
   FROM attendance a
   JOIN students s  ON s.id = a.student_id
   JOIN classes  c  ON c.class_id = a.class_id
   JOIN schedules sch ON sch.class_id = c.class_id AND sch.day='Monday'
   WHERE a.class_id=1 AND a.date='$today' AND a.status='Absent'
   ORDER BY s.full_name")->fetchAll();
foreach ($display as $d) {
    echo "   • {$d['full_name']} | section={$d['section']} | subj={$d['subject']} | {$d['status']} | {$d['sched_time']} | teacher#{$d['teacher_id']} | {$d['date']}\n";
}
check("every absent row exposes a non-empty SECTION", count($display) === 2 && !in_array('', array_column($display,'section'), true));

echo "\n================= SIMULATION B: RE-RUN (idempotency / duplicate prevention) =================\n";
$r2 = runSweep($db, '2026-05-25 09:46:00');
echo "  re-run | logs+{$r2['logs']} summary+{$r2['summary']}\n";
check("re-run inserts 0 new LOG rows (no duplicates)", $r2['logs'] === 0);
check("re-run inserts 0 new SUMMARY rows (no duplicates)", $r2['summary'] === 0);
check("total absent logs for schedule 8 still exactly 2",
      (int)$db->query("SELECT COUNT(*) FROM attendance_logs WHERE schedule_id=8 AND attendance_status='absent'")->fetchColumn() === 2);

echo "\n================= SIMULATION C: not-yet-due schedule is ignored =================\n";
// At 09:46, schedule 10 (class 3, starts 13:00) is NOT due. Process only sched 10.
$r3 = runSweep($db, '2026-05-25 09:46:00', 10);
check("schedule 10 not due at 09:46 → 0 log rows", $r3['logs'] === 0);
check("schedule 10 not due at 09:46 → 0 summary rows", $r3['summary'] === 0);

echo "\n================= SIMULATION D: afternoon sweep, class with TWO schedules same day =================\n";
// Now = 16:45 (Mon). Schedules 10 (13:00) & 11 (14:30) both due. Class 3 students: 27,28,29.
// Student 27 already scanned IN for schedule 11 → has a Present summary row for class 3 today.
$r4 = runSweep($db, '2026-05-25 16:45:00');
echo "  afternoon | logs+{$r4['logs']} summary+{$r4['summary']}\n";

// LOG grain = per schedule. For sched 10: 27,28,29 all unscanned -> 3 absents.
// For sched 11: 27 scanned, 28 & 29 unscanned -> 2 absents. Total 5 new log rows.
check("class-3 afternoon: 5 new LOG rows (sched10:3 + sched11:2)", $r4['logs'] === 5);
check("sched 11: student 27 (scanned) NOT absent, but 28 & 29 are",
      (int)$db->query("SELECT COUNT(*) FROM attendance_logs WHERE schedule_id=11 AND attendance_status='absent' AND student_id=27")->fetchColumn() === 0
   && (int)$db->query("SELECT COUNT(*) FROM attendance_logs WHERE schedule_id=11 AND attendance_status='absent' AND student_id IN(28,29)")->fetchColumn() === 2);

// SUMMARY grain = per class/day. Class 3 today: 27 Present (kept), 28 & 29 Absent. => 2 new summary rows only.
check("class-3 SUMMARY: only 2 new Absent rows (28 & 29); 27 kept Present", $r4['summary'] === 2);
check("summary student 27 still Present (not overwritten by sched-10 absence)",
      (string)$db->query("SELECT status FROM attendance WHERE student_id=27 AND class_id=3 AND date='$today'")->fetchColumn() === 'Present');
check("summary has exactly one row per (student,class,date) for class 3 (unique key holds)",
      (int)$db->query("SELECT COUNT(*) FROM attendance WHERE class_id=3 AND date='$today'")->fetchColumn() === 3);

echo "\n================= RESULTS =================\n";
echo "PASSED: $pass   FAILED: $fail\n";
exit($fail === 0 ? 0 : 1);