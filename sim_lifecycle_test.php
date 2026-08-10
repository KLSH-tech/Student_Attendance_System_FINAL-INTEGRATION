<?php
// ============================================================================
// sim_lifecycle_test.php — SIMULATION of the intelligent attendance lifecycle.
// Mirrors the NEW scanner state machine (CASE 1/2/3 + absent→late conversion)
// and the absent-email flag logic, using SQLite/PDO so it runs with no MySQL.
// Only MySQL-only date funcs are swapped for SQLite equivalents; the relational
// logic is identical to scanner/attendance_scanner.php + includes/absent_processor.php.
// ============================================================================

$pass = 0; $fail = 0;
function check(string $label, bool $cond) {
    global $pass, $fail; echo ($cond ? "  PASS  " : "  FAIL  ") . $label . PHP_EOL; $cond ? $pass++ : $fail++;
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$db->exec("
CREATE TABLE students (id INTEGER PRIMARY KEY, student_number TEXT, full_name TEXT, section TEXT, email TEXT);
CREATE TABLE classes (class_id INTEGER PRIMARY KEY, course_code TEXT, section TEXT, teacher_id INTEGER, grace_period_minutes INTEGER DEFAULT 15);
CREATE TABLE schedules (schedule_id INTEGER PRIMARY KEY, class_id INTEGER, day TEXT, start_time TEXT, end_time TEXT, room TEXT);
CREATE TABLE student_schedule (id INTEGER PRIMARY KEY AUTOINCREMENT, student_id INTEGER, schedule_id INTEGER, class_id INTEGER, UNIQUE(student_id,schedule_id,class_id));
CREATE TABLE attendance_logs (log_id INTEGER PRIMARY KEY AUTOINCREMENT, student_id INTEGER, student_number TEXT, class_id INTEGER, schedule_id INTEGER, scanned_by TEXT, action TEXT, attendance_status TEXT, notification_sent INTEGER DEFAULT 0, sms_sent INTEGER DEFAULT 0, email_sent INTEGER DEFAULT 0, logged_at TEXT);
CREATE TABLE attendance (attendance_id INTEGER PRIMARY KEY AUTOINCREMENT, student_id INTEGER, class_id INTEGER, teacher_id INTEGER, date TEXT, time_in TEXT, time_out TEXT, status TEXT DEFAULT 'Present', UNIQUE(student_id,class_id,date));
");

// class 1 = IT11 / BSIT 1A / teacher 16 / grace 15 ; schedule 8 = Mon 09:30-10:30
$db->exec("INSERT INTO classes VALUES (1,'IT11','BSIT 1A',16,15)");
$db->exec("INSERT INTO schedules VALUES (8,1,'Monday','09:30:00','10:30:00','COM LAB A')");
$db->exec("INSERT INTO students VALUES
  (1,'B2017586','Alsado, Kean Rose M.','BSIT 1A',NULL),
  (3,'20250815','Barinos, John Homer N.','BSIT 1A',NULL),
  (5,'20250508','Encaja, Karl Timothy E.','BSIT 1A','karl@example.com'),
  (7,'20250634','Garsula, Polo James A.','BSIT 1A',NULL)");
$db->exec("INSERT INTO student_schedule (student_id,schedule_id,class_id) VALUES (1,8,1),(3,8,1),(5,8,1),(7,8,1)");
$today = '2026-05-25'; // Monday

// ── Auto-absence sweep (same logic as includes/absent_processor.php) ────────
function runSweep(PDO $db, string $nowDT) {
    $day=date('l',strtotime($nowDT)); $date=date('Y-m-d',strtotime($nowDT)); $time=date('H:i:s',strtotime($nowDT));
    $due="time(:t) > time(sch.start_time,'+'||COALESCE(c.grace_period_minutes,15)||' minutes')";
    $sel=$db->prepare("
      SELECT ss.student_id, st.student_number, ss.class_id, ss.schedule_id,
             :d1||' '||time(sch.start_time,'+'||COALESCE(c.grace_period_minutes,15)||' minutes') AS logged_at
      FROM student_schedule ss
      JOIN students st ON st.id=ss.student_id
      JOIN schedules sch ON sch.schedule_id=ss.schedule_id
      JOIN classes c ON c.class_id=ss.class_id
      WHERE sch.day=:day AND $due
        AND NOT EXISTS (SELECT 1 FROM attendance_logs al WHERE al.student_id=ss.student_id
              AND al.schedule_id=ss.schedule_id AND al.class_id=ss.class_id AND date(al.logged_at)=:d2)");
    $sel->execute([':day'=>$day,':t'=>$time,':d1'=>$date,':d2'=>$date]);
    foreach ($sel->fetchAll() as $r) {
        $db->prepare("INSERT INTO attendance_logs (student_id,student_number,class_id,schedule_id,scanned_by,action,attendance_status,notification_sent,sms_sent,email_sent,logged_at)
                      VALUES (?,?,?,?, 'system_auto','absent','absent', 0,0,0, ?)")
           ->execute([(int)$r['student_id'],$r['student_number'],(int)$r['class_id'],(int)$r['schedule_id'],$r['logged_at']]);
    }
    $db->prepare("INSERT OR IGNORE INTO attendance (student_id,class_id,teacher_id,date,status)
      SELECT DISTINCT ss.student_id, ss.class_id, c.teacher_id, :d, 'Absent'
      FROM student_schedule ss JOIN schedules sch ON sch.schedule_id=ss.schedule_id JOIN classes c ON c.class_id=ss.class_id
      WHERE sch.day=:day AND $due")->execute([':d'=>$date,':day'=>$day,':t'=>$time]);
}

// ── syncAttendanceSummary (same logic as scanner/attendance_sync.php) ───────
function syncSummary(PDO $db, int $sid, int $cid, int $tid, string $action, bool $isLate, string $nowDT) {
    $date=date('Y-m-d',strtotime($nowDT)); $tn=date('H:i:s',strtotime($nowDT)); $status=$isLate?'Late':'Present';
    $row=$db->prepare("SELECT attendance_id,time_in FROM attendance WHERE student_id=? AND class_id=? AND date=? LIMIT 1");
    $row->execute([$sid,$cid,$date]); $r=$row->fetch();
    if ($action==='in') {
        if (!$r) $db->prepare("INSERT INTO attendance (student_id,class_id,teacher_id,date,time_in,status) VALUES (?,?,?,?,?,?)")
                    ->execute([$sid,$cid,$tid,$date,$tn,$status]);
        else $db->prepare("UPDATE attendance SET time_in=COALESCE(time_in,?), status=? WHERE attendance_id=?")
                ->execute([$tn,$status,(int)$r['attendance_id']]);
    } else {
        if ($r) $db->prepare("UPDATE attendance SET time_out=? WHERE attendance_id=?")->execute([$tn,(int)$r['attendance_id']]);
        else $db->prepare("INSERT INTO attendance (student_id,class_id,teacher_id,date,time_out,status) VALUES (?,?,?,?,?,'Present')")
                ->execute([$sid,$cid,$tid,$date,$tn]);
    }
}

// ── simulateScan (same state machine as the NEW scanner) ────────────────────
function simulateScan(PDO $db, int $studentId, string $nowDT): array {
    $day=date('l',strtotime($nowDT)); $date=date('Y-m-d',strtotime($nowDT));
    $nowTs=strtotime($nowDT);
    // student's schedules today
    $q=$db->prepare("SELECT sch.*, c.grace_period_minutes, c.teacher_id, c.section
                     FROM student_schedule ss JOIN schedules sch ON sch.schedule_id=ss.schedule_id
                     JOIN classes c ON c.class_id=ss.class_id
                     WHERE ss.student_id=? AND sch.day=?");
    $q->execute([$studentId,$day]); $today=$q->fetchAll();
    if (!$today) return ['result'=>'no_class'];

    $active=null;
    foreach ($today as $s) {
        $st=strtotime($date.' '.$s['start_time']); $en=strtotime($date.' '.$s['end_time']);
        if ($nowTs>=$st && $nowTs<=$en) { $active=$s; break; }
    }
    if (!$active) {
        $ended=null;
        foreach ($today as $s) {
            $en=strtotime($date.' '.$s['end_time']);
            if ($nowTs>$en) { if($ended===null || $en>strtotime($date.' '.$ended['end_time'])) $ended=$s; }
        }
        return $ended ? ['result'=>'closed','schedule'=>$ended] : ['result'=>'no_active'];
    }

    // active: compute lateness
    $graceCut=strtotime($date.' '.$active['start_time'])+($active['grace_period_minutes']*60);
    $isLate=$nowTs>$graceCut;
    $sid=$studentId; $cid=(int)$active['class_id']; $schid=(int)$active['schedule_id'];

    // last real action (ignore absent)
    $lr=$db->prepare("SELECT action FROM attendance_logs WHERE student_id=? AND schedule_id=? AND class_id=? AND date(logged_at)=? AND action IN('in','out') ORDER BY log_id DESC LIMIT 1");
    $lr->execute([$sid,$schid,$cid,$date]); $lastAction=$lr->fetch()['action']??null;
    $action=(!$lastAction||$lastAction==='out')?'in':'out';

    $absentRowId=0;
    if ($lastAction===null) {
        $a=$db->prepare("SELECT log_id FROM attendance_logs WHERE student_id=? AND schedule_id=? AND class_id=? AND date(logged_at)=? AND action='absent' AND attendance_status='absent' ORDER BY log_id DESC LIMIT 1");
        $a->execute([$sid,$schid,$cid,$date]); $absentRowId=(int)($a->fetch()['log_id']??0);
    }
    $previouslyAbsent=($absentRowId>0 && $action==='in');

    $status=$isLate?'late':'on_time';
    if ($action==='out'){ $isLate=false; $status='on_time'; }
    $loggedAt=date('Y-m-d H:i:s',$nowTs);

    if ($previouslyAbsent) {
        $db->prepare("UPDATE attendance_logs SET action='in', attendance_status=?, scanned_by='student_terminal', logged_at=? WHERE log_id=?")
           ->execute([$status,$loggedAt,$absentRowId]);
    } else {
        $db->prepare("INSERT INTO attendance_logs (student_id,student_number,class_id,schedule_id,scanned_by,action,attendance_status,sms_sent,logged_at)
                      VALUES (?,?,?,?,'student_terminal',?,?,0,?)")
           ->execute([$sid,'',$cid,$schid,$action,$status,$loggedAt]);
    }
    syncSummary($db,$sid,$cid,(int)$active['teacher_id'],$action,$isLate,$nowDT);
    return ['result'=>'success','action'=>$action,'is_late'=>$isLate,'status'=>$status,'was_absent'=>$previouslyAbsent];
}

// ── simulate sendPendingAbsentEmails flag logic ─────────────────────────────
function sendAbsentEmails(PDO $db, string $date): array {
    $rows=$db->query("SELECT log_id,student_id FROM attendance_logs WHERE action='absent' AND attendance_status='absent' AND email_sent=0 AND date(logged_at)='$date' ORDER BY log_id")->fetchAll();
    $ids=[];
    foreach ($rows as $r){ $ids[]=(int)$r['student_id'];
        $db->prepare("UPDATE attendance_logs SET email_sent=1, notification_sent=1 WHERE log_id=?")->execute([(int)$r['log_id']]); }
    return $ids;
}

echo "================= CASE 1 — within grace (09:35) → PRESENT =================\n";
$r=simulateScan($db,1,"$today 09:35:00");
check("student 1 scan 09:35 → success, action=in, not late", $r['result']==='success' && $r['action']==='in' && $r['is_late']===false);
check("student 1 NOT previously absent", $r['was_absent']===false);
check("student 1 summary = Present",
      (string)$db->query("SELECT status FROM attendance WHERE student_id=1 AND class_id=1 AND date='$today'")->fetchColumn()==='Present');

echo "\n================= SWEEP at 09:50 (grace ended 09:45) =================\n";
runSweep($db,"$today 09:50:00");
$abs=$db->query("SELECT student_id FROM attendance_logs WHERE action='absent' ORDER BY student_id")->fetchAll(PDO::FETCH_COLUMN);
check("sweep marked 3,5,7 absent (NOT student 1 who scanned)", $abs==[3,5,7] || $abs==['3','5','7']);

echo "\n================= CASE 2 — after grace before end (09:55) → ABSENT→LATE =================\n";
$r=simulateScan($db,5,"$today 09:55:00");
check("student 5 scan 09:55 → success, action=in, IS late", $r['result']==='success' && $r['action']==='in' && $r['is_late']===true);
check("student 5 detected as previously absent (conversion)", $r['was_absent']===true);
check("student 5 has NO remaining 'absent' log row (converted, not duplicated)",
      (int)$db->query("SELECT COUNT(*) FROM attendance_logs WHERE student_id=5 AND schedule_id=8 AND action='absent'")->fetchColumn()===0);
check("student 5 has exactly ONE log row for schedule 8 (no duplicate)",
      (int)$db->query("SELECT COUNT(*) FROM attendance_logs WHERE student_id=5 AND schedule_id=8")->fetchColumn()===1);
check("student 5 that row is action=in / status=late",
      (string)$db->query("SELECT action||'/'||attendance_status FROM attendance_logs WHERE student_id=5 AND schedule_id=8")->fetchColumn()==='in/late');
check("student 5 summary updated Absent→Late (not duplicated)",
      (string)$db->query("SELECT status FROM attendance WHERE student_id=5 AND class_id=1 AND date='$today'")->fetchColumn()==='Late'
   && (int)$db->query("SELECT COUNT(*) FROM attendance WHERE student_id=5 AND class_id=1 AND date='$today'")->fetchColumn()===1);

echo "\n--- CASE 2 follow-up: converted student can still TIME-OUT (IN/OUT preserved) ---\n";
$r=simulateScan($db,5,"$today 10:05:00");
check("student 5 second scan toggles to action=out", $r['result']==='success' && $r['action']==='out');
check("student 5 now has 2 log rows (in + out), still none 'absent'",
      (int)$db->query("SELECT COUNT(*) FROM attendance_logs WHERE student_id=5 AND schedule_id=8")->fetchColumn()===2
   && (int)$db->query("SELECT COUNT(*) FROM attendance_logs WHERE student_id=5 AND schedule_id=8 AND action='absent'")->fetchColumn()===0);

echo "\n--- student 1 second scan 10:00 → TIME-OUT (existing IN/OUT unaffected) ---\n";
$r=simulateScan($db,1,"$today 10:00:00");
check("student 1 toggles to out (absent feature didn't break IN/OUT)", $r['result']==='success' && $r['action']==='out');

echo "\n================= CASE 3 — after class end (10:45) → CLOSED =================\n";
$before=$db->query("SELECT COUNT(*) FROM attendance_logs")->fetchColumn();
$r=simulateScan($db,3,"$today 10:45:00");
check("student 3 scan 10:45 → result=closed (attendance window expired)", $r['result']==='closed');
check("CASE 3 made NO attendance change (row count unchanged)",
      (int)$db->query("SELECT COUNT(*) FROM attendance_logs")->fetchColumn()===(int)$before);
check("student 3 still ABSENT (CASE 3 did NOT convert to late)",
      (string)$db->query("SELECT action||'/'||attendance_status FROM attendance_logs WHERE student_id=3 AND schedule_id=8")->fetchColumn()==='absent/absent');

echo "\n================= ABSENT EMAIL flagging (reuses existing pipeline) =================\n";
$emailed = sendAbsentEmails($db,$today);
sort($emailed);
// Still-absent rows now: student 3 and student 7 (student 5 converted away).
check("absent e-mails target only still-absent students (3 & 7)", $emailed==[3,7] || $emailed==['3','7']);
check("re-run sends 0 (email_sent flag prevents duplicates)", count(sendAbsentEmails($db,$today))===0);
check("converted student 5 was NOT absent-emailed", !in_array(5,$emailed,true) && !in_array('5',$emailed,true));

echo "\n================= EDGE: scan with no class window (e.g., 12:00 between classes) =================\n";
// Add an afternoon schedule so 12:00 is 'between', not 'after last'.
$db->exec("INSERT INTO schedules VALUES (99,1,'Monday','13:00:00','14:00:00','LAB')");
$db->exec("INSERT INTO student_schedule (student_id,schedule_id,class_id) VALUES (1,99,1)");
$r=simulateScan($db,1,"$today 12:00:00");
check("between classes (after sched8 end, before sched99 start) → result=closed (most-recent ended)", $r['result']==='closed');
$r=simulateScan($db,1,"$today 08:00:00");
check("before first class (08:00, before 09:30) → result=no_active (not closed)", $r['result']==='no_active');

echo "\n================= RESULTS =================\n";
echo "PASSED: $pass   FAILED: $fail\n";
exit($fail===0?0:1);
