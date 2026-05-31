<?php
// ============================================================================
// scanner/attendance_scanner.php — Barcode attendance terminal
// WITH IPROGSMS SMS INTEGRATION (JSON API)
// ============================================================================

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/attendance_sync.php';
require_once __DIR__ . '/../includes/mailer.php';   // PHPMailer-based parent e-mail notifications
require_once __DIR__ . '/../includes/absent_processor.php'; // automatic absence marking (additive feature)
require_once __DIR__ . '/../includes/teacher_portal_session.php'; // shared teacher-portal session writer (badge login)

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ========== SMS CONFIGURATION ==========
// I-set sa TRUE kung ayaw munang magpadala ng totoong SMS (testing lang)
// I-set sa FALSE kapag handa na para sa totoong SMS sa mga magulang
define('SMS_TEST_MODE', false);  // <-- PALITAN sa false kapag handa na

// ── AUTOMATIC ABSENCE MARKING (additive) ─────────────────────────────────────
// Fill in any due absences (grace period elapsed) WITHOUT requiring cron on a
// XAMPP/demo box. Registered as a SHUTDOWN task so it runs only AFTER the scan
// response has been sent — it can never delay, alter or break a scan, the
// IN/OUT toggle, late detection, SMS or e-mail. It is also internally throttled
// (runs at most once every ~2 minutes) and fully wrapped in try/catch.
register_shutdown_function(function () {
    if (function_exists('maybeRunAbsenceSweep')) {
        maybeRunAbsenceSweep(120);
    }
});

function getDB() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $conn->connect_error]);
        exit;
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

/**
 * Send SMS via iProgSMS using JSON API
 */
function sendSmsNotification(
    string $toNumber,
    string $studentName,
    string $sendTime,
    bool   $isLate      = false,
    string $subjectName = ''
): array {
    // Defensive: if the cURL extension isn't loaded, fail gracefully instead of
    // throwing a fatal that would abort attendance recording AND the e-mail.
    if (!function_exists('curl_init')) {
        error_log('SMS skipped: cURL extension not available.');
        return ['status' => 'failed', 'error' => 'cURL extension not available'];
    }

    $phone = str_replace('+', '', trim($toNumber));
    if (str_starts_with($phone, '09')) {
        $phone = '63' . substr($phone, 1);
    }
    $lateNote  = $isLate ? ' (arrived late)' : '';
    $classNote = $subjectName ? " for {$subjectName}" : '';
    $message   = "SC BSIT Notification: Your child {$studentName} arrived at school{$classNote}. "
               . "Time: {$sendTime}{$lateNote}.";

    // ========== IDINAGDAG ANG SENDER NAME ==========
    $data = [
        'api_token'    => SMS_API_TOKEN,
        'message'      => $message,
        'phone_number' => $phone,
        'sender_name'  => 'SCBSIT'  // <-- ITO ANG IDINAGDAG
    ];
    // ===============================================

    $ch = curl_init(SMS_API_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     http_build_query($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT,        30);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['status' => 'failed', 'error' => $error];
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode($response, true);

    return ($httpCode >= 200 && $httpCode < 300)
        ? ['status' => 'success', 'response' => $decoded]
        : ['status' => 'failed', 'response' => $decoded, 'raw' => $response];
}

/**
 * Get current day name
 */
function getCurrentDayName(): string {
    $now = new DateTime('now', new DateTimeZone('Asia/Manila'));
    return $now->format('l');
}

/**
 * Get complete student profile
 */
function getStudentProfile(string $scannedId, mysqli $conn): ?array {
    error_log("Searching for student with ID: '" . $scannedId . "'");
    
    $stmt = $conn->prepare("
        SELECT 
            s.id,
            s.student_number,
            s.full_name,
            s.gender,
            s.course,
            s.year_level,
            s.section,
            s.contact,
            s.email,
            s.created_at,
            p.parent_name,
            p.contact_number AS parent_contact,
            p.email AS parent_email,
            p.relationship
        FROM students s
        LEFT JOIN parents p ON p.student_id = s.id
        WHERE s.student_number = ?
        LIMIT 1
    ");
    
    $stmt->bind_param('s', $scannedId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($result) {
        error_log("Student found: " . $result['full_name'] . " (ID: " . $result['id'] . ")");
        return $result;
    }
    
    error_log("Student NOT found with ID: " . $scannedId);
    return null;
}

/**
 * Look up a TEACHER by the scanned barcode (teachers.teacher_number).
 * Returns the teacher row, or null when the scanned code is not a teacher.
 * Kept separate from the student lookup so a teacher badge scan can redirect
 * to the teacher portal instead of being reported as "unrecognized ID".
 */
function getTeacherByNumber(string $scannedId, mysqli $conn): ?array {
    error_log("Searching for teacher with teacher_number: '" . $scannedId . "'");

    // JOIN users because for user-linked teacher rows teachers.name is NULL and
    // the real display name + role live on the users row. COALESCE so a teacher
    // who only has teachers.name (no user_id) still resolves a name. We also pull
    // role/username/email so the scan can build a portal session identical to the
    // one teacherLogin() creates.
    $stmt = $conn->prepare("
        SELECT
            t.id            AS teacher_db_id,
            t.user_id,
            COALESCE(NULLIF(t.name, ''), u.full_name) AS display_name,
            t.subject,
            COALESCE(NULLIF(t.email, ''), u.email)    AS email,
            t.teacher_number,
            t.department,
            t.contact,
            u.username,
            u.role,
            u.status
        FROM teachers t
        LEFT JOIN users u ON u.id = t.user_id
        WHERE t.teacher_number = ?
        LIMIT 1
    ");
    $stmt->bind_param('s', $scannedId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($result) {
        error_log("Teacher found: " . ($result['display_name'] ?? '') . " (teacher id: " . $result['teacher_db_id'] . ")");
        return $result;
    }

    error_log("Teacher NOT found with teacher_number: " . $scannedId);
    return null;
}

/**
 * Get ALL student's schedules
 */
function getAllStudentSchedules(int $studentId, mysqli $conn): array {
    error_log("Getting ALL schedules for student ID: $studentId");
    
    $stmt = $conn->prepare("
        SELECT 
            ss.schedule_id,
            ss.class_id,
            c.class_code,
            c.course_code,
            c.section,
            c.teacher_id,
            c.grace_period_minutes,
            sub.subject_name,
            sub.course_code AS subject_code,
            sched.day,
            sched.start_time,
            sched.end_time,
            sched.room,
            t.name AS instructor_name,
            u.full_name AS instructor_full_name
        FROM student_schedule ss
        INNER JOIN schedules sched ON sched.schedule_id = ss.schedule_id
        INNER JOIN classes c ON c.class_id = ss.class_id
        INNER JOIN subjects sub ON sub.course_code = c.course_code
        LEFT JOIN teachers t ON t.id = c.teacher_id
        LEFT JOIN users u ON u.id = t.user_id
        WHERE ss.student_id = ?
        ORDER BY FIELD(sched.day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'), sched.start_time ASC
    ");
    
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $schedules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    error_log("Total schedules found: " . count($schedules));
    
    foreach ($schedules as $sch) {
        error_log("Schedule: Day={$sch['day']}, Start={$sch['start_time']}, End={$sch['end_time']}, Subject={$sch['subject_name']}");
    }
    
    return $schedules;
}

/**
 * Get today's schedules only
 */
function getTodaySchedules(array $allSchedules, string $today): array {
    return array_filter($allSchedules, function($schedule) use ($today) {
        return $schedule['day'] === $today;
    });
}

/**
 * Format schedules for display
 */
function formatSchedulesForDisplay(array $schedules): array {
    $formatted = [];
    foreach ($schedules as $schedule) {
        $startTime = date('h:i A', strtotime($schedule['start_time']));
        $endTime = date('h:i A', strtotime($schedule['end_time']));
        $timeDisplay = $startTime . ' – ' . $endTime;
        
        $instructor = $schedule['instructor_name'] ?? $schedule['instructor_full_name'] ?? 'TBA';
        
        $formatted[] = trim(
            $schedule['subject_name'] . 
            ' · ' . ($schedule['section'] ?? 'N/A') .
            ' (' . $timeDisplay . ')' .
            ' · Room: ' . ($schedule['room'] ?? 'TBA') .
            ($instructor ? ' · ' . $instructor : '')
        );
    }
    return $formatted;
}

/**
 * Check if current time is within schedule
 */
function isWithinSchedule($schedule, DateTime $currentTime): bool {
    $startTime = DateTime::createFromFormat('H:i:s', $schedule['start_time']);
    $endTime = DateTime::createFromFormat('H:i:s', $schedule['end_time']);
    
    if (!$startTime || !$endTime) {
        return false;
    }
    
    $startTime->setDate(
        (int)$currentTime->format('Y'),
        (int)$currentTime->format('m'),
        (int)$currentTime->format('d')
    );
    $endTime->setDate(
        (int)$currentTime->format('Y'),
        (int)$currentTime->format('m'),
        (int)$currentTime->format('d')
    );
    
    return $currentTime >= $startTime && $currentTime <= $endTime;
}

// ─── AJAX: handle barcode scan POST ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['student_id'])) {
    header('Content-Type: application/json');

    $scannedId = trim($_POST['student_id']);
    error_log("=== NEW SCAN REQUEST ===");
    error_log("Scanned ID: '" . $scannedId . "'");
    
    if (strlen($scannedId) < 3) {
        echo json_encode(['success' => false, 'message' => 'Invalid ID format']);
        exit;
    }

    $conn = getDB();
    
    // Get student profile
    $student = getStudentProfile($scannedId, $conn);
    
    if (!$student) {
        // Not a student — maybe it's a TEACHER badge. If so, LOG THE TEACHER IN
        // (the scanner shares the SAMS_SESSION with the portal) and send them
        // straight to THEIR dashboard, instead of reporting an unrecognized ID.
        $teacher = getTeacherByNumber($scannedId, $conn);
        $conn->close();

        if ($teacher) {
            // Only active accounts may sign in by badge. teachers rows without a
            // linked user have status NULL → treat as allowed (legacy local
            // teacher), but a linked-but-inactive user is rejected.
            $status = $teacher['status'] ?? null;
            if ($status !== null && $status !== 'active') {
                echo json_encode([
                    'success' => false,
                    'message' => 'This teacher account is inactive. Please contact the administrator.',
                    'not_found' => true
                ]);
                exit;
            }

            // Establish the portal session exactly like teacherLogin() does, so
            // every portal page sees a fully authenticated teacher.
            session_regenerate_id(true);   // fresh session id on (badge) login
            setTeacherPortalSession([
                'teacher_db_id'  => (int) $teacher['teacher_db_id'],
                'user_id'        => $teacher['user_id'] !== null ? (int) $teacher['user_id'] : null,
                'teacher_name'   => $teacher['display_name'] ?? '',
                'full_name'      => $teacher['display_name'] ?? '',
                'teacher_number' => $teacher['teacher_number'] ?? '',
                'department'     => $teacher['department'] ?? '',
                'role'           => $teacher['role'] ?? 'teacher',
                'username'       => $teacher['username'] ?? '',
                'email'          => $teacher['email'] ?? '',
            ]);

            $teacherName = $teacher['display_name'] ?: 'Teacher';
            error_log("🎓 TEACHER BADGE LOGIN: {$teacherName} (#{$teacher['teacher_db_id']}) → portal dashboard");

            $portalUrl = (defined('BASE_URL') ? rtrim(BASE_URL, '/') : '') . '/transactions/dashboard.php';
            echo json_encode([
                'success'      => false,
                'is_teacher'   => true,
                'redirect'     => $portalUrl,
                'teacher_name' => $teacherName,
                'message'      => "Welcome, {$teacherName}! Opening your portal…"
            ]);
            exit;
        }

        echo json_encode([
            'success' => false,
            'message' => 'Student not found. Please check your ID number.',
            'not_found' => true
        ]);
        exit;
    }

    $studentPk = (int) $student['id'];
    $studentNumber = $student['student_number'];
    $fullName = $student['full_name'];
    $course = $student['course'] ?? 'BSIT';
    $yearLevel = $student['year_level'] ?? 1;
    $section = $student['section'] ?? 'N/A';
    $guardianPhone = trim($student['parent_contact'] ?? $student['contact'] ?? '');
    
    // Extract first name
    $nameParts = explode(',', $fullName, 2);
    if (count($nameParts) > 1) {
        $firstName = trim(explode(' ', trim($nameParts[1]))[0]);
    } else {
        $firstName = explode(' ', trim($fullName))[0];
    }
    
    $now = new DateTime('now', new DateTimeZone('Asia/Manila'));
    $todayDate = $now->format('Y-m-d');
    $todayName = $now->format('l');
    
    error_log("Current: {$now->format('Y-m-d H:i:s')}, Day: {$todayName}");
    
    // Get ALL schedules for this student
    $allSchedules = getAllStudentSchedules($studentPk, $conn);
    
    if (empty($allSchedules)) {
        $conn->close();
        echo json_encode([
            'success' => false,
            'no_class' => true,
            'message' => 'Student has no enrolled classes.',
            'today_subjects' => [],
            'student_name' => $fullName,
            'student_details' => [
                'course' => $course,
                'year_level' => $yearLevel,
                'section' => $section
            ]
        ]);
        exit;
    }
    
    // Get today's schedules
    $todaySchedules = getTodaySchedules($allSchedules, $todayName);
    $allFormattedSchedules = formatSchedulesForDisplay($allSchedules);
    $todayFormattedSchedules = formatSchedulesForDisplay($todaySchedules);
    
    // ── STATE-AWARE schedule selection ──────────────────────────────────────
    // A class is "active" only while now is within [start_time, end_time]. That
    // single window covers BOTH:
    //   • CASE 1 (within grace): present, and
    //   • CASE 2 (after grace, before end): late / absent→late conversion.
    // If no class is active we must distinguish CASE 3 (a class already ENDED →
    // attendance closed, reject the scan) from simply being between/before
    // classes. The old unconditional "use first schedule" TEST-MODE fallback is
    // removed because it let scans be recorded after a class had ended.
    $activeSchedule = null;
    foreach ($todaySchedules as $schedule) {
        if (isWithinSchedule($schedule, $now)) {
            $activeSchedule = $schedule;
            error_log("Active schedule found: {$schedule['subject_name']}");
            break;
        }
    }

    // When nothing is active, find the most-recently-ENDED class for today.
    $endedSchedule = null;
    if (!$activeSchedule) {
        foreach ($todaySchedules as $schedule) {
            $endDT = DateTime::createFromFormat('H:i:s', $schedule['end_time']);
            if (!$endDT) { continue; }
            $endDT->setDate((int)$now->format('Y'), (int)$now->format('m'), (int)$now->format('d'));
            if ($now > $endDT) {
                if ($endedSchedule === null) {
                    $endedSchedule = $schedule;
                } else {
                    $prevEnd = DateTime::createFromFormat('H:i:s', $endedSchedule['end_time']);
                    $prevEnd->setDate((int)$now->format('Y'), (int)$now->format('m'), (int)$now->format('d'));
                    if ($endDT > $prevEnd) { $endedSchedule = $schedule; }   // keep latest ended
                }
            }
        }
    }

    if (empty($todaySchedules)) {
        $conn->close();
        echo json_encode([
            'success' => false,
            'no_class' => true,
            'message' => "No classes scheduled for {$todayName}. Your classes are on other days.",
            'today_subjects' => [],
            'all_subjects' => $allFormattedSchedules,
            'student_name' => $fullName,
            'student_details' => [
                'course' => $course,
                'year_level' => $yearLevel,
                'section' => $section
            ]
        ]);
        exit;
    }

    if (!$activeSchedule) {
        // CASE 3 — the (most recent) class today has already ended → attendance
        // is CLOSED. We do NOT mark late and do NOT touch any attendance record.
        if ($endedSchedule) {
            $endLabel  = date('h:i A', strtotime($endedSchedule['end_time']));
            $subjLabel = $endedSchedule['subject_name'] ?? 'this subject';
            $conn->close();
            echo json_encode([
                'success'        => false,
                'no_class'       => true,
                'closed'         => true,                       // attendance window expired
                'message'        => "Attendance closed — {$subjLabel} already ended at {$endLabel}.",
                'today_subjects' => $todayFormattedSchedules,
                'all_subjects'   => $allFormattedSchedules,
                'student_name'   => $fullName,
                'student_details'=> [
                    'course'     => $course,
                    'year_level' => $yearLevel,
                    'section'    => $section
                ]
            ]);
            exit;
        }
        // Otherwise: between classes or before the first one — unchanged behavior.
        $conn->close();
        echo json_encode([
            'success' => false,
            'no_class' => true,
            'message' => "No active class at this time ({$now->format('h:i A')}). Your schedules for {$todayName}:",
            'today_subjects' => $todayFormattedSchedules,
            'all_subjects' => $allFormattedSchedules,
            'student_name' => $fullName,
            'student_details' => [
                'course' => $course,
                'year_level' => $yearLevel,
                'section' => $section
            ]
        ]);
        exit;
    }
    
    error_log("Processing attendance for: {$fullName}, Subject: {$activeSchedule['subject_name']}");
    
    // Calculate lateness
    $startTime = DateTime::createFromFormat('H:i:s', $activeSchedule['start_time']);
    $startTime->setDate((int)$now->format('Y'), (int)$now->format('m'), (int)$now->format('d'));
    $graceMinutes = (int)($activeSchedule['grace_period_minutes'] ?? 15);
    $lateThreshold = clone $startTime;
    $lateThreshold->modify("+{$graceMinutes} minutes");
    $isLate = ($now > $lateThreshold);
    $minutesLate = $isLate ? max(1, (int) round(($now->getTimestamp() - $lateThreshold->getTimestamp()) / 60)) : 0;
    
    $scheduleId = (int) $activeSchedule['schedule_id'];
    $classId = (int) $activeSchedule['class_id'];
    
    // ── STATE-AWARE last-action detection ───────────────────────────────────
    // An auto-ABSENT row must NOT count as a real scan for the IN/OUT toggle —
    // otherwise the student's first real scan would wrongly flip to 'out'. So we
    // read the last *real* (in/out) action for the toggle and the debounce, and
    // SEPARATELY detect a convertible auto-absent row.
    $stmt = $conn->prepare("
        SELECT action, logged_at
        FROM attendance_logs
        WHERE student_id = ? AND schedule_id = ? AND class_id = ? AND DATE(logged_at) = ?
          AND action IN ('in','out')
        ORDER BY log_id DESC LIMIT 1
    ");
    $stmt->bind_param('iiis', $studentPk, $scheduleId, $classId, $todayDate);
    $stmt->execute();
    $lastReal = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $lastAction = $lastReal['action'] ?? null;
    $lastLog    = $lastReal;                       // debounce uses real scans only
    $action     = (!$lastAction || $lastAction === 'out') ? 'in' : 'out';

    // Is there an auto-absent row to convert? (Only meaningful when the student
    // has NOT scanned for real yet — i.e. CASE 2: absent first, now scanning in.)
    $absentRowId = 0;
    if ($lastAction === null) {
        $stmt = $conn->prepare("
            SELECT log_id FROM attendance_logs
            WHERE student_id = ? AND schedule_id = ? AND class_id = ? AND DATE(logged_at) = ?
              AND action = 'absent' AND attendance_status = 'absent'
            ORDER BY log_id DESC LIMIT 1
        ");
        $stmt->bind_param('iiis', $studentPk, $scheduleId, $classId, $todayDate);
        $stmt->execute();
        $absentRowId = (int) ($stmt->get_result()->fetch_assoc()['log_id'] ?? 0);
        $stmt->close();
    }
    // CASE 2: previously auto-absent, now scanning in → convert that row to LATE.
    $previouslyAbsent = ($absentRowId > 0 && $action === 'in');

    // Anti double-read only: a barcode scanner often fires the SAME code twice
    // within a fraction of a second. Block just those near-instant repeats so a
    // real time-in → time-out toggle (seconds/minutes apart) still goes through.
    $debounce = defined('SCAN_DEBOUNCE_SECONDS') ? (int) SCAN_DEBOUNCE_SECONDS : 3;
    if ($lastLog) {
        $secsSinceLast = strtotime($now->format('Y-m-d H:i:s')) - strtotime($lastLog['logged_at']);
        if ($secsSinceLast >= 0 && $secsSinceLast < $debounce) {
            $conn->close();
            echo json_encode([
                'success'   => false,
                'message'   => 'Please wait a moment before scanning again.',
                'duplicate' => true
            ]);
            exit;
        }
    }
    
    $status = $isLate ? 'late' : 'on_time';
    if ($action === 'out') {
        $isLate = false;
        $minutesLate = 0;
        $status = 'on_time';
    }
    
    // ========== SEND SMS ==========
        // ========== SEND SMS ==========
    $smsSent = false; 
    $smsResult = null;
    
    // ===== FORCED TEST CONFIGURATION =====
    $forceTestNumber = '09508760485';  // Personal number ninyo para sa testing
    $useForceTest = true;  // true = gamitin ang personal number, false = gamitin ang parent number
    
    if ($action === 'in') {
        // Piliin ang number na gagamitin
        if ($useForceTest) {
            $sendToNumber = $forceTestNumber;
            error_log("🔵 FORCED TEST: Using personal number {$sendToNumber}");
        } else {
            $sendToNumber = $guardianPhone;
            error_log("🔵 Using parent number: {$sendToNumber}");
        }
        
        if (!empty($sendToNumber)) {
            if (SMS_TEST_MODE) {
                // TEST MODE: Hindi magse-send
                $testMessage = "🔵 SMS TEST MODE: Would send to {$sendToNumber}";
                error_log($testMessage);
                $smsSent = true;
                $smsResult = ['status' => 'success', 'test_mode' => true, 'to' => $sendToNumber];
            } else {
                // LIVE MODE: Magpadala ng totoong SMS
                $smsSubject = $activeSchedule['subject_name'] ?? 'Class';
                $smsMessage = "SC BSIT Test: Your child {$firstName} arrived at school for {$smsSubject}. Time: " . $now->format('h:i A');
                if ($isLate) {
                    $smsMessage .= " (arrived late)";
                }
                
                $smsResult = sendSmsNotification($sendToNumber, $firstName, $now->format('h:i A'), $isLate, $smsSubject);
                $smsSent = ($smsResult['status'] === 'success');
                error_log("📱 SMS to {$sendToNumber}: " . ($smsSent ? "SENT ✅" : "FAILED ❌ - " . ($smsResult['error'] ?? '')));
            }
        } else {
            error_log("⚠️ No phone number available for student: {$fullName}");
            $smsResult = ['status' => 'failed', 'error' => 'No phone number'];
        }
    }
    // ========== END SMS ==========
    // ========== END SMS ==========
    // ========== END SMS ==========
    
    // Insert attendance record
    $loggedAt = $now->format('Y-m-d H:i:s');
    $smsSentInt = $smsSent ? 1 : 0;
    $scannedBy = 'student_terminal';

    if ($previouslyAbsent) {
        // ── CASE 2 CONVERSION ────────────────────────────────────────────────
        // The student was auto-marked ABSENT after the grace period; they are
        // now physically scanning in while the class is still in session. We
        // CONVERT the existing auto-absent row in place (action absent→in,
        // status absent→late) instead of inserting a new row. This guarantees
        // there is never a contradictory absent+late pair and never a duplicate.
        $stmt = $conn->prepare("
            UPDATE attendance_logs
            SET action = 'in', attendance_status = ?, scanned_by = ?, sms_sent = ?, logged_at = ?
            WHERE log_id = ?
        ");
        $stmt->bind_param('ssisi', $status, $scannedBy, $smsSentInt, $loggedAt, $absentRowId);
        $stmt->execute();
        $stmt->close();
        $logId = $absentRowId;
        error_log("ABSENT→LATE: converted auto-absent log #{$absentRowId} to late time-in for {$fullName}");
    } else {
        $stmt = $conn->prepare("
            INSERT INTO attendance_logs 
                (student_id, student_number, class_id, schedule_id, scanned_by, action, 
                 attendance_status, sms_sent, logged_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            // i  s  i  i  s  s  s  i  s   ← attendance_status (pos 7) is a STRING.
            // The original 'isiissiis' bound it as an int, so 'on_time'/'late' became
            // 0 and the enum stored '' (see the blank values in the seed data) or, on
            // a strict-mode server, errored with "Data truncated". This is the fix.
            'isiisssis',
            $studentPk, $studentNumber, $classId, $scheduleId, $scannedBy,
            $action, $status, $smsSentInt, $loggedAt
        );
        $stmt->execute();
        $logId = (int) $conn->insert_id;
        $stmt->close();
    }

    // Mirror into the summary table (per student/class/day). On conversion this
    // updates the existing 'Absent' summary row to 'Late' + stamps time_in.
    syncAttendanceSummary(
    $conn,
    $studentPk,
    $classId,
    (int) ($activeSchedule['teacher_id'] ?? 0),
    $action,
    $isLate,
    $now
);

    // ========== SEND EMAIL TO PARENT/GUARDIAN (PHPMailer/SMTP) ==========
    // Fires on BOTH time-in and time-out so the parent gets the full picture.
    $teacherName = $activeSchedule['instructor_name']
                 ?? $activeSchedule['instructor_full_name']
                 ?? 'TBA';
    $statusText  = ($action === 'out') ? 'Checked Out' : ($isLate ? 'Late' : 'On Time');
    if ($previouslyAbsent) {
        // Tell the parent the status changed from the earlier auto-absence.
        $statusText = 'Late (updated from Absent)';
    }

    $emailResult = notifyParentByEmail([
        'student_id'      => $studentPk,
        'log_id'          => $logId,
        'student_name'    => $fullName,
        'student_number'  => $studentNumber,
        'parent_name'     => $student['parent_name']  ?? '',
        'parent_email'    => $student['parent_email'] ?? '',
        'student_email'   => $student['email']        ?? '',
        'subject_name'    => $activeSchedule['subject_name'] ?? '—',
        'subject_code'    => $activeSchedule['subject_code'] ?? $activeSchedule['course_code'] ?? '',
        'teacher_name'    => $teacherName,
        'room'            => $activeSchedule['room'] ?? 'TBA',
        'section'         => $activeSchedule['section'] ?? $section,
        'date_str'        => $now->format('l, F j, Y'),
        'time_str'        => $now->format('h:i A'),
        'status_text'     => $statusText,
        'action'          => $action,
        'is_late'         => $isLate,
        'school_name'     => defined('APP_NAME') ? APP_NAME : 'Student Attendance Monitoring System',
    ]);
    $emailSent   = ($emailResult['status'] === 'sent' || $emailResult['status'] === 'test');
    $emailStatus = $emailResult['status'];
    $emailError = $emailResult['error'] ?? '';

    // Persist notification flags on the log row we just created.
    $emailSentInt = $emailSent ? 1 : 0;
    $notifInt     = ($emailSent || $smsSent) ? 1 : 0;
    $upd = $conn->prepare("UPDATE attendance_logs SET email_sent = ?, notification_sent = ? WHERE log_id = ?");
    $upd->bind_param('iii', $emailSentInt, $notifInt, $logId);
    $upd->execute();
    $upd->close();
    // ========== END EMAIL ==========

    $conn->close();
    
    // Prepare response
    $classTimeDisplay = date('h:i A', strtotime($activeSchedule['start_time'])) . ' – ' . 
                       date('h:i A', strtotime($activeSchedule['end_time']));
    
    $instructor = $activeSchedule['instructor_name'] ?? $activeSchedule['instructor_full_name'] ?? '—';
    
    if ($action === 'in') {
        if ($isLate) {
            $greeting = "You're late, {$firstName}! ({$minutesLate} min past grace)";
            $icon = '⏰';
            $statusLabel = '● LATE ARRIVAL';
        } else {
            $greeting = "Welcome, {$firstName}! Have a great day!";
            $icon = '👋';
            $statusLabel = '● TIME IN';
        }
        if ($previouslyAbsent) {
            // CASE 2 — clearly explain the status change on the terminal.
            $greeting    = "{$firstName} was previously marked ABSENT and is now marked LATE.";
            $icon        = '🔄';
            $statusLabel = '● ABSENT → LATE';
        }
    } else {
        $greeting = "Goodbye, {$firstName}! See you next time!";
        $icon = '🚀';
        $statusLabel = '● TIME OUT';
    }
    
    echo json_encode([
        'success' => true,
        'id' => $studentNumber,
        'full_name' => $fullName,
        'first_name' => $firstName,
        'course' => $course,
        'year_level' => $yearLevel,
        'section' => $section,
        'action' => $action,
        'status_label' => $statusLabel,
        'is_late' => $isLate,
        'minutes_late' => $minutesLate,
        'was_absent' => $previouslyAbsent,   // true when this scan converted an auto-absence to late
        'subject_name' => $activeSchedule['subject_name'],
        'subject_code' => $activeSchedule['subject_code'] ?? $activeSchedule['course_code'],
        'section_name' => $activeSchedule['section'],
        'room' => $activeSchedule['room'] ?? '—',
        'instructor' => $instructor,
        'class_time' => $classTimeDisplay,
        'timeStr' => $now->format('h:i:s A'),
        'dateStr' => $now->format('l, F j, Y'),
        'greeting' => $greeting,
        'icon' => $icon,
        'sms_sent' => $smsSent,
        'sms_result' => $smsResult,
        'sms_to' => $guardianPhone,
        'email_sent' => $emailSent,
        'email_status' => $emailStatus,
        'email_to' => $emailResult['recipient'] ?? '',
        'test_mode' => SMS_TEST_MODE
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Student Attendance Scanner | SCANTRACK</title>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Exo+2:wght@300;400;600&display=swap" rel="stylesheet"/>
    <style>
        :root {
            --bg: #050d1a;
            --panel: #0a1628;
            --border: #0e2a4a;
            --accent: #00c8ff;
            --accent2: #00ff9d;
            --warn: #ff6b35;
            --late: #f5c518;
            --text: #cce8ff;
            --muted: #4a7a9b;
            --glow: 0 0 20px rgba(0,200,255,0.4);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: var(--bg);
            font-family: 'Exo 2', sans-serif;
            color: var(--text);
            min-height: 100vh;
            display: flex; flex-direction: column; align-items: center;
            overflow-x: hidden;
        }
        body::before {
            content: ''; position: fixed; inset: 0;
            background-image: linear-gradient(rgba(0,200,255,0.03) 1px, transparent 1px), linear-gradient(90deg, rgba(0,200,255,0.03) 1px, transparent 1px);
            background-size: 40px 40px;
            pointer-events: none; z-index: 0;
        }
        header {
            position: relative; z-index: 1; width: 100%;
            padding: 28px 40px 20px;
            display: flex; align-items: center; justify-content: space-between;
            border-bottom: 1px solid var(--border);
            background: rgba(5,13,26,0.8); backdrop-filter: blur(10px);
        }
        .logo { font-family: 'Orbitron', monospace; font-size: 1.1rem; font-weight: 900; letter-spacing: 0.2em; color: var(--accent); text-shadow: var(--glow); }
        .logo span { color: var(--accent2); }
        #live-clock { font-family: 'Orbitron', monospace; font-size: 1.3rem; font-weight: 700; color: var(--accent); text-shadow: var(--glow); letter-spacing: 0.1em; }
        #live-date { font-size: 0.78rem; color: var(--muted); text-align: right; letter-spacing: 0.08em; margin-top: 4px; }
        main { position: relative; z-index: 1; width: 100%; max-width: 900px; padding: 40px 20px; display: flex; flex-direction: column; align-items: center; gap: 32px; }
        .scanner-panel { width: 100%; background: var(--panel); border: 1px solid var(--border); border-radius: 16px; padding: 40px 36px; position: relative; overflow: hidden; }
        .scanner-panel::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--accent), var(--accent2), transparent); animation: shimmer 3s infinite; }
        @keyframes shimmer { 0%,100% { opacity: 0.4; } 50% { opacity: 1; } }
        .scanner-title { font-family: 'Orbitron', monospace; font-size: 0.75rem; letter-spacing: 0.3em; color: var(--muted); text-transform: uppercase; margin-bottom: 28px; }
        .barcode-icon { display: flex; justify-content: center; margin-bottom: 24px; }
        .barcode-svg { width: 120px; height: 60px; opacity: 0.7; }
        .scan-label { font-family: 'Orbitron', monospace; font-size: 1.6rem; font-weight: 700; text-align: center; color: var(--text); margin-bottom: 8px; }
        .scan-subtitle { text-align: center; font-size: 0.85rem; color: var(--muted); margin-bottom: 32px; letter-spacing: 0.04em; }
        .input-wrapper { position: relative; display: flex; align-items: center; max-width: 420px; margin: 0 auto; }
        .input-wrapper svg { position: absolute; left: 16px; color: var(--muted); width: 20px; height: 20px; pointer-events: none; }
        #barcode-input { width: 100%; padding: 16px 16px 16px 50px; background: rgba(0,200,255,0.04); border: 1.5px solid var(--border); border-radius: 10px; color: var(--accent); font-family: 'Orbitron', monospace; font-size: 1.1rem; letter-spacing: 0.15em; outline: none; transition: border-color 0.3s, box-shadow 0.3s; caret-color: var(--accent); }
        #barcode-input:focus { border-color: var(--accent); box-shadow: var(--glow), inset 0 0 12px rgba(0,200,255,0.05); }
        #barcode-input::placeholder { color: var(--muted); letter-spacing: 0.08em; font-size: 0.85rem; }
        .scan-hint { text-align: center; font-size: 0.76rem; color: var(--muted); margin-top: 14px; letter-spacing: 0.06em; }
        .scan-hint span { color: var(--accent2); }
        .scan-beam { position: absolute; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent 0%, var(--accent) 30%, var(--accent2) 50%, var(--accent) 70%, transparent 100%); top: 50%; animation: beam 2s ease-in-out infinite; opacity: 0; transition: opacity 0.3s; pointer-events: none; }
        .input-wrapper.scanning .scan-beam { opacity: 1; }
        @keyframes beam { 0% { top: calc(50% - 28px); } 50% { top: calc(50% + 28px); } 100% { top: calc(50% - 28px); } }
        #notification { width: 100%; border-radius: 16px; display: none; position: relative; overflow: hidden; animation: slideIn 0.4s cubic-bezier(0.16,1,0.3,1); }
        @keyframes slideIn { from { opacity: 0; transform: translateY(20px) scale(0.97); } to { opacity: 1; transform: translateY(0) scale(1); } }
        #notification.time-in { background: linear-gradient(135deg, rgba(0,255,157,0.07), rgba(0,200,255,0.05)); border: 1px solid rgba(0,255,157,0.25); }
        #notification.time-out { background: linear-gradient(135deg, rgba(255,107,53,0.08), rgba(255,200,50,0.04)); border: 1px solid rgba(255,107,53,0.3); }
        #notification.late { background: linear-gradient(135deg, rgba(245,197,24,0.09), rgba(255,140,0,0.05)); border: 1px solid rgba(245,197,24,0.4); }
        #notification.not-found { background: linear-gradient(135deg, rgba(255,80,80,0.08), rgba(180,0,0,0.04)); border: 1px solid rgba(255,80,80,0.35); }
        #notification.no-class { background: linear-gradient(135deg, rgba(100,100,200,0.09), rgba(60,60,160,0.05)); border: 1px solid rgba(120,120,255,0.35); }
        .notif-body { padding: 28px 32px; }
        .notif-inner { display: flex; align-items: center; gap: 24px; }
        .notif-icon { width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; flex-shrink: 0; }
        .notif-status { font-family: 'Orbitron', monospace; font-size: 0.7rem; letter-spacing: 0.25em; text-transform: uppercase; margin-bottom: 6px; }
        .notif-greeting { font-size: 1.45rem; font-weight: 600; color: var(--text); margin-bottom: 4px; }
        .notif-id { font-family: 'Orbitron', monospace; font-size: 0.78rem; letter-spacing: 0.08em; margin-bottom: 2px; }
        .notif-time { margin-left: auto; text-align: right; flex-shrink: 0; }
        .notif-timestamp { font-family: 'Orbitron', monospace; font-size: 1.3rem; font-weight: 700; color: var(--text); }
        .notif-datestamp { font-size: 0.75rem; color: var(--muted); margin-top: 4px; letter-spacing: 0.05em; }
        .late-badge, .sms-badge, .email-badge { display: none; margin-top: 6px; padding: 3px 10px; border-radius: 20px; font-size: 0.72rem; letter-spacing: 0.12em; font-family: 'Orbitron', monospace; text-transform: uppercase; }
        .late-badge { background: rgba(245,197,24,0.15); border: 1px solid rgba(245,197,24,0.4); color: var(--late); }
        .sms-badge.sent { background: rgba(0,255,157,0.12); border: 1px solid rgba(0,255,157,0.35); color: var(--accent2); }
        .sms-badge.failed { background: rgba(255,80,80,0.10); border: 1px solid rgba(255,80,80,0.35); color: #ff8080; }
        .email-badge.sent { background: rgba(0,200,255,0.12); border: 1px solid rgba(0,200,255,0.35); color: var(--accent); }
        .email-badge.failed { background: rgba(255,80,80,0.10); border: 1px solid rgba(255,80,80,0.35); color: #ff8080; }
        .email-badge.skipped { background: rgba(74,122,155,0.15); border: 1px solid rgba(74,122,155,0.4); color: var(--muted); }
        .badge-row { display: flex; align-items: center; flex-wrap: wrap; gap: 4px; margin-top: 2px; }
        .subject-strip, .student-details, .schedule-list { margin-top: 18px; padding-top: 16px; border-top: 1px solid rgba(0,200,255,0.1); display: none; flex-wrap: wrap; gap: 20px; }
        .subject-chip { display: flex; flex-direction: column; gap: 2px; }
        .chip-label { font-size: 0.65rem; letter-spacing: 0.2em; color: var(--muted); text-transform: uppercase; }
        .chip-value { font-family: 'Orbitron', monospace; font-size: 0.82rem; color: var(--text); letter-spacing: 0.04em; }
        .schedule-list { border-top-color: rgba(120,120,255,0.15); flex-direction: column; }
        .schedule-label { font-size: 0.7rem; letter-spacing: 0.2em; color: var(--muted); text-transform: uppercase; margin-bottom: 4px; }
        .schedule-item { font-size: 0.82rem; color: #aaaaee; padding: 6px 12px; background: rgba(120,120,255,0.07); border-radius: 6px; border: 1px solid rgba(120,120,255,0.15); letter-spacing: 0.03em; }
        .progress-bar { height: 3px; background: rgba(0,200,255,0.1); overflow: hidden; }
        .progress-fill { height: 100%; width: 100%; transform-origin: left; animation: drain 6s linear forwards; }
        @keyframes drain { from { transform: scaleX(1); } to { transform: scaleX(0); } }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../includes/nav.php'; renderNav('scanner'); ?>

<header>
    <div class="logo">SCAN<span>TRACK</span></div>
    <div>
        <div id="live-clock">00:00:00</div>
        <div id="live-date">—</div>
    </div>
</header>

<main>
    <div class="scanner-panel">
        <div class="scanner-title">// BARCODE ATTENDANCE TERMINAL</div>
        <div class="barcode-icon">
            <svg class="barcode-svg" viewBox="0 0 120 60" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="4" y="6" width="3" height="48" fill="#00c8ff" opacity="0.9"/>
                <rect x="10" y="6" width="5" height="48" fill="#00c8ff" opacity="0.8"/>
                <rect x="18" y="6" width="2" height="48" fill="#00c8ff" opacity="0.9"/>
                <rect x="23" y="6" width="4" height="48" fill="#00c8ff" opacity="0.7"/>
                <rect x="30" y="6" width="3" height="48" fill="#00ff9d" opacity="0.9"/>
                <rect x="36" y="6" width="6" height="48" fill="#00c8ff" opacity="0.8"/>
                <rect x="45" y="6" width="2" height="48" fill="#00c8ff" opacity="0.9"/>
                <rect x="50" y="6" width="4" height="48" fill="#00ff9d" opacity="0.8"/>
                <rect x="57" y="6" width="3" height="48" fill="#00c8ff" opacity="0.9"/>
                <rect x="63" y="6" width="5" height="48" fill="#00c8ff" opacity="0.7"/>
                <rect x="71" y="6" width="2" height="48" fill="#00c8ff" opacity="0.9"/>
                <rect x="76" y="6" width="4" height="48" fill="#00ff9d" opacity="0.9"/>
                <rect x="83" y="6" width="3" height="48" fill="#00c8ff" opacity="0.8"/>
                <rect x="89" y="6" width="6" height="48" fill="#00c8ff" opacity="0.7"/>
                <rect x="98" y="6" width="2" height="48" fill="#00c8ff" opacity="0.9"/>
                <rect x="103" y="6" width="4" height="48" fill="#00ff9d" opacity="0.8"/>
                <rect x="110" y="6" width="6" height="48" fill="#00c8ff" opacity="0.9"/>
                <rect x="0" y="29" width="120" height="2" fill="#00c8ff" opacity="0.5"/>
            </svg>
        </div>
        <div class="scan-label">Scan Your ID</div>
        <div class="scan-subtitle">Scan your school ID to record attendance. SMS notification will be sent to your guardian.</div>
        <div class="input-wrapper" id="input-wrapper">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="18" height="18" rx="2"/>
                <path d="M7 7h2v10H7zM11 7h1v10h-1zM14 7h3v10h-3z" stroke="none" fill="currentColor"/>
            </svg>
            <input id="barcode-input" type="text" inputmode="numeric" autocomplete="off" placeholder="Scan or enter ID number..." autofocus/>
            <div class="scan-beam"></div>
        </div>
        <p class="scan-hint">Scanner input is auto-detected — or press <span>ENTER</span> after typing</p>
    </div>

    <div id="notification">
        <div class="notif-body">
            <div class="notif-inner">
                <div class="notif-icon" id="notif-icon">😊</div>
                <div class="notif-text" style="flex:1;min-width:0;">
                    <div class="notif-status" id="notif-status">TIME IN</div>
                    <div class="notif-greeting" id="notif-greeting">Welcome!</div>
                    <div class="notif-id" id="notif-id">ID: —</div>
                    <div class="badge-row">
                        <div id="late-badge" class="late-badge">⏱ MARKED LATE</div>
                        <div id="sms-badge" class="sms-badge">📱 SMS SENT</div>
                        <div id="email-badge" class="email-badge">📧 EMAIL SENT</div>
                    </div>
                </div>
                <div class="notif-time">
                    <div class="notif-timestamp" id="notif-timestamp">—</div>
                    <div class="notif-datestamp" id="notif-datestamp">—</div>
                </div>
            </div>
            <div class="subject-strip" id="subject-strip">
                <div class="subject-chip"><span class="chip-label">Subject</span><span class="chip-value" id="chip-subject">—</span></div>
                <div class="subject-chip"><span class="chip-label">Code</span><span class="chip-value" id="chip-code">—</span></div>
                <div class="subject-chip"><span class="chip-label">Section</span><span class="chip-value" id="chip-section">—</span></div>
                <div class="subject-chip"><span class="chip-label">Schedule</span><span class="chip-value" id="chip-time">—</span></div>
                <div class="subject-chip"><span class="chip-label">Room</span><span class="chip-value" id="chip-room">—</span></div>
                <div class="subject-chip"><span class="chip-label">Instructor</span><span class="chip-value" id="chip-instructor">—</span></div>
            </div>
            <div class="student-details" id="student-details">
                <div class="subject-chip"><span class="chip-label">Course</span><span class="chip-value" id="student-course">—</span></div>
                <div class="subject-chip"><span class="chip-label">Year Level</span><span class="chip-value" id="student-year">—</span></div>
            </div>
            <div class="schedule-list" id="schedule-list">
                <div class="schedule-label">Today's Enrolled Schedule</div>
                <div id="schedule-items"></div>
            </div>
        </div>
        <div class="progress-bar"><div class="progress-fill" id="progress-fill"></div></div>
    </div>
</main>

<script>
function updateClock() {
    const now = new Date();
    document.getElementById('live-clock').textContent = now.toLocaleTimeString('en-PH', { hour12: false });
    document.getElementById('live-date').textContent = now.toLocaleDateString('en-PH', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
}
updateClock();
setInterval(updateClock, 1000);

const input = document.getElementById('barcode-input');
const wrapper = document.getElementById('input-wrapper');
let clearTimer = null;

document.addEventListener('click', () => input.focus());
document.addEventListener('keydown', () => input.focus());
input.addEventListener('focus', () => wrapper.classList.add('scanning'));
input.addEventListener('blur', () => { wrapper.classList.remove('scanning'); setTimeout(() => input.focus(), 100); });
input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); const val = input.value.trim(); if (val.length >= 3) submitScan(val); } });

function submitScan(id) {
    input.value = '';

    const fd = new FormData();
    fd.append('student_id', id);

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {

            // Email debug data
            const emailStatus = data.email_status || '';
            const emailError = data.email_error || '';
            const emailRecipient = data.email_recipient || '';

            console.log('Email Status:', emailStatus);
            console.log('Email Error:', emailError);
            console.log('Email Recipient:', emailRecipient);

            if (data.success) {
                showSuccess({
                    ...data,
                    email_status: emailStatus,
                    email_error: emailError,
                    email_recipient: emailRecipient
                });
            }
            else if (data.is_teacher) {
                showTeacher(data);
            }
            else if (data.not_found) {
                showNotFound(id);
            }
            else if (data.no_class) {
                showNoClass({
                    ...data,
                    email_status: emailStatus,
                    email_error: emailError,
                    email_recipient: emailRecipient
                }, id);
            }
            else if (data.duplicate) {
                showDuplicate({
                    ...data,
                    email_status: emailStatus,
                    email_error: emailError,
                    email_recipient: emailRecipient
                }, id);
            }
        })
        .catch((err) => {
            console.error('Fetch error:', err);
            showNotFound(id);
        })
        .finally(() => setTimeout(() => input.focus(), 10));
}

function resetNotif() {
    if (clearTimer) clearTimeout(clearTimer);
    const notif = document.getElementById('notification');
    notif.className = '';
    notif.style.display = 'none';
    document.getElementById('late-badge').style.display = 'none';
    document.getElementById('sms-badge').style.display = 'none';
    document.getElementById('sms-badge').className = 'sms-badge';
    document.getElementById('email-badge').style.display = 'none';
    document.getElementById('email-badge').className = 'email-badge';
    document.getElementById('subject-strip').style.display = 'none';
    document.getElementById('student-details').style.display = 'none';
    document.getElementById('schedule-list').style.display = 'none';
    document.getElementById('schedule-items').innerHTML = '';
    const fill = document.getElementById('progress-fill');
    fill.style.animation = 'none';
    void fill.offsetWidth;
    fill.style.animation = '';
}

function revealNotif(cls) {
    const notif = document.getElementById('notification');
    notif.classList.add(cls);
    notif.style.display = 'block';
    clearTimer = setTimeout(() => { notif.style.display = 'none'; }, 8000);
}

function setCommon(icon, statusText, greeting, idLine, timeStr, dateStr) {
    document.getElementById('notif-icon').textContent = icon;
    document.getElementById('notif-status').textContent = statusText;
    document.getElementById('notif-greeting').textContent = greeting;
    document.getElementById('notif-id').textContent = idLine;
    document.getElementById('notif-timestamp').textContent = timeStr;
    document.getElementById('notif-datestamp').textContent = dateStr;
}

function showSuccess(data) {
    resetNotif();
    const cls = data.action === 'out' ? 'time-out' : (data.is_late ? 'late' : 'time-in');
    setCommon(data.icon, data.status_label, data.greeting, 'ID: ' + data.id + '  |  ' + data.full_name, data.timeStr, data.dateStr);
    if (data.is_late) document.getElementById('late-badge').style.display = 'inline-block';
    if (data.action === 'in' && data.sms_result) {
        const smsBadge = document.getElementById('sms-badge');
        smsBadge.style.display = 'inline-block';
        if (data.sms_sent) { smsBadge.classList.add('sent'); smsBadge.textContent = '📱 SMS SENT'; }
        else { smsBadge.classList.add('failed'); smsBadge.textContent = '📵 SMS FAILED'; }
    }
    if (data.email_status && data.email_status !== 'skipped') {
        const emailBadge = document.getElementById('email-badge');
        emailBadge.style.display = 'inline-block';
        if (data.email_sent) { emailBadge.classList.add('sent'); emailBadge.textContent = '📧 EMAIL SENT'; }
        else { emailBadge.classList.add('failed'); emailBadge.textContent = '📭 EMAIL FAILED'; }
    }
    document.getElementById('subject-strip').style.display = 'flex';
    document.getElementById('chip-subject').textContent = data.subject_name;
    document.getElementById('chip-code').textContent = data.subject_code;
    document.getElementById('chip-section').textContent = data.section_name;
    document.getElementById('chip-time').textContent = data.class_time;
    document.getElementById('chip-room').textContent = data.room;
    document.getElementById('chip-instructor').textContent = data.instructor;
    if (data.course && data.year_level) {
        document.getElementById('student-details').style.display = 'flex';
        document.getElementById('student-course').textContent = data.course;
        document.getElementById('student-year').textContent = data.year_level + (data.year_level == 1 ? 'st' : data.year_level == 2 ? 'nd' : data.year_level == 3 ? 'rd' : 'th') + ' Year';
    }
    revealNotif(cls);
}

function showNotFound(id) {
    resetNotif();
    const now = new Date();
    setCommon('⚠️', '● NOT FOUND', 'Unrecognized ID', 'ID: ' + id + ' — not registered', now.toLocaleTimeString('en-PH', { hour12: true }), now.toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }));
    revealNotif('not-found');
}

function showTeacher(data) {
    resetNotif();
    const now = new Date();
    const name = data.teacher_name || 'Teacher';
    setCommon('🎓', '● TEACHER LOGIN', data.message || 'Opening your portal…', 'Signed in as ' + name, now.toLocaleTimeString('en-PH', { hour12: true }), now.toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }));
    revealNotif('time-in');
    // Badge login already established the portal session server-side; go to the
    // teacher's own dashboard. Brief pause so the confirmation is visible.
    if (data.redirect) {
        setTimeout(() => { window.location.href = data.redirect; }, 1000);
    }
}

function showDuplicate(data, id) {
    resetNotif();
    const now = new Date();
    setCommon('⏳', '● PLEASE WAIT', data.message || 'Duplicate scan ignored', 'ID: ' + id, now.toLocaleTimeString('en-PH', { hour12: true }), now.toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }));
    revealNotif('no-class');
}

function showNoClass(data, id) {
    resetNotif();
    const now = new Date();
    const name = data.student_name ? ' — ' + data.student_name : '';
    const hasSchedule = data.today_subjects?.length > 0;
    setCommon('🚫', '● ACCESS DENIED', data.message || 'No active class', 'ID: ' + id + name, now.toLocaleTimeString('en-PH', { hour12: true }), now.toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }));
    if (data.student_details?.course !== 'N/A') {
        document.getElementById('student-details').style.display = 'flex';
        document.getElementById('student-course').textContent = data.student_details.course;
        document.getElementById('student-year').textContent = data.student_details.year_level + ' Year';
    }
    if (hasSchedule) {
        const items = document.getElementById('schedule-items');
        data.today_subjects.forEach(s => { const div = document.createElement('div'); div.className = 'schedule-item'; div.textContent = s; items.appendChild(div); });
        document.getElementById('schedule-list').style.display = 'flex';
    }
    revealNotif('no-class');
}

input.focus();
</script>
</body>
</html>