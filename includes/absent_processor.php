<?php
// ============================================================================
// includes/absent_processor.php — AUTOMATIC ABSENCE MARKING (additive feature)
// ----------------------------------------------------------------------------
// After a class's GRACE PERIOD ends, every student assigned to that
// schedule/section who has NOT scanned yet is automatically marked ABSENT.
//
// DESIGN (matches the existing scanner exactly):
//   The barcode scanner writes BOTH:
//       • a raw row in `attendance_logs`  (per student / schedule / day)
//       • a summary row in `attendance`   (per student / class   / day, read by
//         the teacher portal dashboard, attendance page, reports, records)
//   …so this processor writes an ABSENT record into BOTH tables the same way.
//   That makes the absence appear everywhere the portal already shows
//   attendance — student name, SECTION, subject, status, schedule time,
//   teacher and date — with NO change to any display query.
//
// SAFETY GUARANTEES (see the SQL comments below):
//   • Never duplicates an absent record.
//   • Never overrides an existing scan (Present / Late / earlier Absent).
//   • Never touches a student who already scanned (any IN/OUT) for that
//     schedule on that day.
//   • A failure here can NEVER break the scanner, e-mail, SMS or late logic —
//     everything is wrapped in try/catch and runs in its own transaction.
//
// It is intentionally PURE LOGIC: no output, no headers. Callers (the cron
// entry point and the scanner's best-effort hook) decide what to do with the
// returned summary array.
// ============================================================================

require_once __DIR__ . '/db.php';   // db() — the single shared PDO connection
require_once __DIR__ . '/mailer.php'; // reuse the EXISTING email pipeline for absences

if (!function_exists('absentLog')) {
    /** Best-effort debug log. Never throws. */
    function absentLog(string $msg): void
    {
        try {
            $dir  = __DIR__ . '/../cron';
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
            @file_put_contents($dir . '/absent_processor.log', $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            error_log('absentLog failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('absentColumnExists')) {
    /** True if $table has column $col in the current database. Cached per request. */
    function absentColumnExists(PDO $pdo, string $table, string $col): bool
    {
        static $cache = [];
        $key = $table . '.' . $col;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        try {
            $st = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = ?
                   AND COLUMN_NAME  = ?"
            );
            $st->execute([$table, $col]);
            return $cache[$key] = ((int) $st->fetchColumn() > 0);
        } catch (\Throwable $e) {
            // If we can't tell, assume present only for columns we know ship in
            // the base schema; be conservative for upgrade-only columns.
            return $cache[$key] = false;
        }
    }
}

if (!function_exists('processAutomaticAbsences')) {
    /**
     * Mark absent every assigned-but-unscanned student for every class whose
     * grace period has already ended for the given moment.
     *
     * @param DateTime|null $asOf          The "current time" to evaluate against.
     *                                      Defaults to now (Asia/Manila). Passing
     *                                      a value lets you SIMULATE any moment.
     * @param int|null      $onlyScheduleId Restrict to a single schedule (testing).
     * @return array {
     *     ok:            bool,
     *     day:           string  (weekday name evaluated, e.g. "Monday"),
     *     date:          string  (Y-m-d evaluated),
     *     logs_inserted: int     (rows added to attendance_logs),
     *     summary_inserted: int  (rows added to attendance summary),
     *     schedules:     int     (due schedules considered),
     *     error:         string  (empty on success)
     * }
     */
    function processAutomaticAbsences(?DateTime $asOf = null, ?int $onlyScheduleId = null): array
    {
        $tz   = new DateTimeZone(date_default_timezone_get() ?: 'Asia/Manila');
        $now  = $asOf ? (clone $asOf) : new DateTime('now', $tz);
        $day  = $now->format('l');           // weekday name: Monday … Saturday
        $date = $now->format('Y-m-d');
        $time = $now->format('H:i:s');

        $result = [
            'ok'               => false,
            'day'              => $day,
            'date'             => $date,
            'logs_inserted'    => 0,
            'summary_inserted' => 0,
            'emails_sent'      => 0,
            'schedules'        => 0,
            'error'            => '',
        ];

        $pdo = db();

        try {
            // Does this DB carry the e-mail-upgrade column? (additive feature)
            $hasEmailSent = absentColumnExists($pdo, 'attendance_logs', 'email_sent');

            // Optional single-schedule restriction (for targeted testing).
            $schedFilter = '';
            $bindSched   = [];
            if ($onlyScheduleId !== null) {
                $schedFilter = " AND sch.schedule_id = :only_sched ";
                $bindSched['only_sched'] = $onlyScheduleId;
            }

            // How many DUE schedules are we acting on? (for reporting/logging)
            $cntSql = "
                SELECT COUNT(*)
                FROM schedules sch
                JOIN classes c ON c.class_id = sch.class_id
                WHERE sch.day = :day
                  {$schedFilter}
                  AND TIME(:t) > ADDTIME(sch.start_time,
                          SEC_TO_TIME(COALESCE(c.grace_period_minutes, 15) * 60))
            ";
            $cnt = $pdo->prepare($cntSql);
            $cnt->execute(array_merge([':day' => $day, ':t' => $time], $bindSched));
            $result['schedules'] = (int) $cnt->fetchColumn();

            if ($result['schedules'] === 0) {
                $result['ok'] = true;        // nothing due yet — perfectly normal
                return $result;
            }

            $pdo->beginTransaction();

            // ──────────────────────────────────────────────────────────────
            // 1) RAW LOGS  → attendance_logs  (grain: student / schedule / day)
            //    Done in two portable steps: (a) SELECT the unscanned students
            //    for every due schedule, then (b) plain-INSERT them. The dedup
            //    guard (NOT EXISTS on today's logs for the same student+schedule)
            //    is what prevents duplicates and protects students who already
            //    scanned (any IN/OUT/absent row today is skipped) — so we never
            //    duplicate an absent record and never overwrite a real scan.
            //    logged_at is stamped at the class's grace-cutoff moment so the
            //    timestamp is meaningful in reports.
            // ──────────────────────────────────────────────────────────────
            // (a) READ the candidates with a PURE SELECT. Doing the dedup
            //     NOT EXISTS check here (a read) — rather than inside the INSERT
            //     — deliberately avoids MySQL error 1093 ("can't specify target
            //     table for update in FROM"), which can fire when an
            //     INSERT … SELECT references its own target table in a subquery.
            //     This two-step form is portable across MySQL 5.7/8 & MariaDB.
            $selSql = "
                SELECT
                    ss.student_id,
                    st.student_number,
                    ss.class_id,
                    ss.schedule_id,
                    TIMESTAMP(:d1, ADDTIME(sch.start_time,
                        SEC_TO_TIME(COALESCE(c.grace_period_minutes, 15) * 60))) AS logged_at
                FROM student_schedule ss
                JOIN students  st  ON st.id           = ss.student_id
                JOIN schedules sch ON sch.schedule_id = ss.schedule_id
                JOIN classes   c   ON c.class_id      = ss.class_id
                WHERE sch.day = :day
                  {$schedFilter}
                  AND TIME(:t) > ADDTIME(sch.start_time,
                          SEC_TO_TIME(COALESCE(c.grace_period_minutes, 15) * 60))
                  AND NOT EXISTS (
                        SELECT 1 FROM attendance_logs al
                        WHERE al.student_id  = ss.student_id
                          AND al.schedule_id = ss.schedule_id
                          AND al.class_id    = ss.class_id
                          AND DATE(al.logged_at) = :d2
                  )
            ";
            $sel = $pdo->prepare($selSql);
            $sel->execute(array_merge(
                [':day' => $day, ':t' => $time, ':d1' => $date, ':d2' => $date],
                $bindSched
            ));
            $candidates = $sel->fetchAll();

            // (b) WRITE them with a plain INSERT (no self-reference). Chunked so
            //     the placeholder count stays well within MySQL's limits even
            //     for very large sections.
            if ($candidates) {
                $emailCol = $hasEmailSent ? ', email_sent' : '';
                $emailVal = $hasEmailSent ? ', 0'           : '';
                $rowTpl   = "(?, ?, ?, ?, 'system_auto', 'absent', 'absent', 0, 0{$emailVal}, ?)";
                $insHead  = "INSERT INTO attendance_logs
                    (student_id, student_number, class_id, schedule_id, scanned_by,
                     action, attendance_status, notification_sent, sms_sent{$emailCol}, logged_at)
                    VALUES ";

                foreach (array_chunk($candidates, 200) as $chunk) {
                    $placeholders = implode(', ', array_fill(0, count($chunk), $rowTpl));
                    $params = [];
                    foreach ($chunk as $row) {
                        $params[] = (int) $row['student_id'];
                        $params[] = $row['student_number'];
                        $params[] = (int) $row['class_id'];
                        $params[] = (int) $row['schedule_id'];
                        $params[] = $row['logged_at'];
                    }
                    $ins = $pdo->prepare($insHead . $placeholders);
                    $ins->execute($params);
                    $result['logs_inserted'] += $ins->rowCount();
                }
            }

            // ──────────────────────────────────────────────────────────────
            // 2) SUMMARY  → attendance  (grain: student / class / day)
            //    This is the table the teacher portal reads, so writing here is
            //    what makes the absence show up on the dashboard, attendance
            //    page, reports and student records — WITH the section, because
            //    those pages already JOIN students for section.
            //    `INSERT IGNORE` relies on the table's UNIQUE(student_id,
            //    class_id, date) key: if the student already has ANY summary row
            //    for that class+day (Present / Late / Absent), the row is
            //    silently skipped — so we never overwrite a scan and never
            //    create a duplicate. (A class can have several schedules in a
            //    day; the unique key correctly collapses them to one summary.)
            // ──────────────────────────────────────────────────────────────
            $sumSql = "
                INSERT IGNORE INTO attendance
                    (student_id, class_id, teacher_id, `date`, status)
                SELECT DISTINCT
                    ss.student_id, ss.class_id, c.teacher_id, :d AS d, 'Absent'
                FROM student_schedule ss
                JOIN schedules sch ON sch.schedule_id = ss.schedule_id
                JOIN classes   c   ON c.class_id      = ss.class_id
                WHERE sch.day = :day
                  {$schedFilter}
                  AND TIME(:t) > ADDTIME(sch.start_time,
                          SEC_TO_TIME(COALESCE(c.grace_period_minutes, 15) * 60))
            ";
            $stmt2 = $pdo->prepare($sumSql);
            $stmt2->execute(array_merge(
                [':d' => $date, ':day' => $day, ':t' => $time],
                $bindSched
            ));
            $result['summary_inserted'] = $stmt2->rowCount();

            $pdo->commit();
            $result['ok'] = true;

            absentLog(sprintf(
                'Sweep %s %s: %d due schedule(s) → %d log row(s), %d summary row(s)%s.',
                $day, $time, $result['schedules'],
                $result['logs_inserted'], $result['summary_inserted'],
                $onlyScheduleId !== null ? " [schedule {$onlyScheduleId}]" : ''
            ));

            // E-MAIL: after the (fast, committed) inserts, notify parents of any
            // absent rows that haven't been e-mailed yet. Done OUTSIDE the
            // transaction and best-effort, so a slow/failed SMTP can never roll
            // back or break the marking. Idempotent: only rows with email_sent=0.
            try {
                $result['emails_sent'] = sendPendingAbsentEmails($date);
            } catch (\Throwable $mailEx) {
                absentLog('absent e-mail step failed: ' . $mailEx->getMessage());
            }
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $result['error'] = $e->getMessage();
            absentLog('ERROR: ' . $e->getMessage());
            error_log('processAutomaticAbsences failed: ' . $e->getMessage());
        }

        return $result;
    }
}

if (!function_exists('sendPendingAbsentEmails')) {
    /**
     * Notify parents/guardians of ABSENT students, reusing the EXISTING e-mail
     * pipeline (notifyParentByEmail → buildAttendanceEmail → email_log). One
     * e-mail per auto-absent log row whose email_sent is still 0. Idempotent and
     * best-effort: each send is wrapped, and the row's flags are stamped so it
     * is never e-mailed twice.
     *
     * @param string|null $date  Y-m-d to process (defaults to today).
     * @param int         $limit Max e-mails per call (drains across sweeps so one
     *                           run never blocks on dozens of SMTP sends).
     * @return int number of e-mails actually sent (or test-logged).
     */
    function sendPendingAbsentEmails(?string $date = null, int $limit = 40): int
    {
        if (defined('MAIL_ENABLED') && !MAIL_ENABLED) {
            return 0;   // master switch off — respect it
        }
        if (!function_exists('notifyParentByEmail')) {
            return 0;   // mailer not available
        }

        $pdo  = db();
        $date = $date ?: (new DateTime('now',
                    new DateTimeZone(date_default_timezone_get() ?: 'Asia/Manila')))->format('Y-m-d');
        $sent = 0;

        // Pull the absent rows that still need an e-mail, with everything the
        // template needs (name, section, subject, teacher, schedule time, date).
        // Parent name/e-mail come from correlated sub-queries so the join can't
        // multiply rows when a student has several parent contacts.
        $sql = "
            SELECT
                al.log_id, al.student_id, al.class_id, al.schedule_id,
                s.full_name, s.student_number, s.section, s.email AS student_email,
                c.course_code,
                sub.subject_name,
                sch.start_time, sch.end_time, sch.room,
                COALESCE(t.name, u.full_name, 'TBA') AS teacher_name,
                (SELECT pp.email FROM parents pp
                   WHERE pp.student_id = s.id AND pp.email IS NOT NULL AND pp.email <> ''
                   ORDER BY pp.parent_id LIMIT 1) AS parent_email,
                (SELECT pp.parent_name FROM parents pp
                   WHERE pp.student_id = s.id
                   ORDER BY pp.parent_id LIMIT 1) AS parent_name
            FROM attendance_logs al
            JOIN students  s   ON s.id            = al.student_id
            JOIN classes   c   ON c.class_id      = al.class_id
            LEFT JOIN subjects  sub ON sub.course_code = c.course_code
            LEFT JOIN schedules sch ON sch.schedule_id = al.schedule_id
            LEFT JOIN teachers  t   ON t.id            = c.teacher_id
            LEFT JOIN users     u   ON u.id            = t.user_id
            WHERE al.action = 'absent'
              AND al.attendance_status = 'absent'
              AND al.email_sent = 0
              AND DATE(al.logged_at) = :d
            ORDER BY al.log_id
            LIMIT {$limit}
        ";
        $st = $pdo->prepare($sql);
        $st->execute([':d' => $date]);
        $rows = $st->fetchAll();

        foreach ($rows as $r) {
            try {
                $subjectName = $r['subject_name'] ?: ($r['course_code'] ?? '—');
                $timeRange   = ($r['start_time'] && $r['end_time'])
                    ? date('h:i A', strtotime($r['start_time'])) . ' – ' . date('h:i A', strtotime($r['end_time']))
                    : '—';

                $res = notifyParentByEmail([
    'student_id'     => (int) $r['student_id'],
    'log_id'         => (int) $r['log_id'],
    'student_name'   => $r['full_name'],
    'student_number' => $r['student_number'],
    'parent_name'    => $r['parent_name']  ?? '',
    'parent_email'   => $r['parent_email'] ?? '',
    'student_email'  => $r['student_email'] ?? '',
    'subject_name'   => $subjectName,
    'subject_code'   => $r['course_code'] ?? '',
    'teacher_name'   => $r['teacher_name'] ?? 'TBA',
    'room'           => $r['room'] ?? 'TBA',
    'section'        => $r['section'] ?? '',
    'date_str'       => date('l, F j, Y', strtotime($date)),
    'time_str'       => $timeRange,
    'status_text'    => 'Absent',
    'action'         => 'absent',
    'is_late'        => false,
    'school_name'    => defined('APP_NAME') ? APP_NAME : 'Student Attendance Monitoring System',

    // Email debug data
    'email_status'   => $res['status'] ?? '',
    'email_error'    => $res['error'] ?? '',
    'email_recipient'=> $res['recipient'] ?? '',
]);

error_log(
    "📧 ABSENT EMAIL RESULT | Student ID: {$r['student_id']} | " .
    "Status: " . ($res['status'] ?? 'unknown') . " | " .
    "Error: " . ($res['error'] ?? 'none')
);

                $status = $res['status'] ?? 'failed';
                $ok     = in_array($status, ['sent', 'test'], true);

                // sent/test → mark e-mailed + notified.
                // skipped (no e-mail on file / deduped) → mark handled so we
                //         don't retry every sweep, but notification_sent stays 0.
                // failed → leave email_sent=0 so a later sweep retries it.
                if ($ok || $status === 'skipped') {
                    $emailFlag = 1;                    // mark the row as handled either way
                    $notifFlag = $ok ? 1 : 0;
                    $pdo->prepare(
                        "UPDATE attendance_logs SET email_sent = ?, notification_sent = ? WHERE log_id = ?"
                    )->execute([$emailFlag, $notifFlag, (int) $r['log_id']]);
                }
                if ($ok) { $sent++; }
            } catch (\Throwable $e) {
                absentLog('absent e-mail to student ' . ($r['student_id'] ?? '?') . ' failed: ' . $e->getMessage());
            }
        }

        if ($sent > 0) {
            absentLog("Absent e-mails sent: {$sent} (date {$date}).");
        }
        return $sent;
    }
}

if (!function_exists('maybeRunAbsenceSweep')) {
    /**
     * THROTTLED, BEST-EFFORT wrapper used by the scanner so absences still get
     * filled even on a setup with no cron (XAMPP demo). Runs the full sweep at
     * most once every $minIntervalSeconds, using a tiny timestamp file as a
     * lock. ANY failure is swallowed — this must never affect a live scan.
     *
     * @return bool true if a sweep actually ran this call.
     */
    function maybeRunAbsenceSweep(int $minIntervalSeconds = 120): bool
    {
        try {
            // Prefer the project's cron/ dir; fall back to the system temp dir
            // if the web server can't write there, so throttling still works.
            $dir = __DIR__ . '/../cron';
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            if (!is_dir($dir) || !is_writable($dir)) {
                $dir = sys_get_temp_dir();
            }
            $stamp = rtrim($dir, '/\\') . '/sams_last_absent_sweep';

            $last = is_file($stamp) ? (int) @file_get_contents($stamp) : 0;
            if ((time() - $last) < $minIntervalSeconds) {
                return false;   // ran recently — skip to keep scans snappy
            }
            // Claim the slot BEFORE running so a burst of scans can't stack.
            @file_put_contents($stamp, (string) time(), LOCK_EX);

            processAutomaticAbsences();   // uses "now"
            return true;
        } catch (\Throwable $e) {
            error_log('maybeRunAbsenceSweep failed: ' . $e->getMessage());
            return false;
        }
    }
}