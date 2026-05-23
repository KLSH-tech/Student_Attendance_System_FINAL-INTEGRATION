<?php
// ================================
// SET PHILIPPINES TIMEZONE
// ================================
date_default_timezone_set('Asia/Manila');

// ================================
// CONNECT DB (singleton guard)
// ================================
function connectDB() {
    static $conn = null;

    if ($conn !== null && $conn->ping()) {
        return $conn;
    }

    $conn = new mysqli("localhost", "root", "", "attendance_system_db");

    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    $conn->set_charset("utf8mb4");
    $conn->query("SET time_zone = '+08:00'");

    return $conn;
}

// ================================
// VERIFY STUDENT
// ================================
function verifyStudent($conn, $barcode) {
    $stmt = $conn->prepare("SELECT * FROM students WHERE student_id = ?");
    if (!$stmt) return null;

    $stmt->bind_param("s", $barcode);
    $stmt->execute();

    return $stmt->get_result()->fetch_assoc();
}

// ================================
// GET PARENT CONTACT
// ================================
function getParentContact($conn, $student_id) {
    $stmt = $conn->prepare("SELECT * FROM parents WHERE student_id = ? LIMIT 1");
    if (!$stmt) return null;

    $stmt->bind_param("s", $student_id);
    $stmt->execute();

    return $stmt->get_result()->fetch_assoc();
}

// ================================
// DETECT CLASS + STATUS
// Finds nearest class for today using TIME() comparison
// ================================
function detectClassAndStatus($conn) {
    $now_time = date('H:i:s');
    $now_day  = date('l'); // e.g. "Monday"

    // Try to find a class scheduled for today
    $stmt = $conn->prepare("
        SELECT c.class_code, c.time_in, c.grace_period_minutes
        FROM classes c
        JOIN schedules s ON s.class_code = c.class_code
        WHERE s.day = ?
        ORDER BY ABS(TIMESTAMPDIFF(MINUTE, c.time_in, ?))
        LIMIT 1
    ");

    $class = null;

    if ($stmt) {
        $stmt->bind_param("ss", $now_day, $now_time);
        $stmt->execute();
        $class = $stmt->get_result()->fetch_assoc();
    }

    // Fallback: nearest class regardless of day
    if (!$class) {
        $stmt2 = $conn->prepare("
            SELECT class_code, time_in, grace_period_minutes
            FROM classes
            ORDER BY ABS(TIMESTAMPDIFF(MINUTE, time_in, ?))
            LIMIT 1
        ");

        if ($stmt2) {
            $stmt2->bind_param("s", $now_time);
            $stmt2->execute();
            $class = $stmt2->get_result()->fetch_assoc();
        }
    }

    if (!$class) {
        return ['class_code' => null, 'attendance_status' => 'on_time'];
    }

    $grace        = intval($class['grace_period_minutes']);
    $class_time   = strtotime($class['time_in']);
    $cutoff_time  = $class_time + ($grace * 60);
    $now_seconds  = strtotime($now_time);
    $status       = ($now_seconds > $cutoff_time) ? 'late' : 'on_time';

    return [
        'class_code'        => $class['class_code'],
        'attendance_status' => $status
    ];
}

// ================================
// CHECK DUPLICATE SCAN TODAY
// Prevents logging the same student twice in < 5 minutes
// ================================
function hasDuplicateScan($conn, $student_id, $class_code) {
    // Block re-scan within 5 minutes regardless of class
    $stmt = $conn->prepare("
        SELECT log_id FROM attendance_logs
        WHERE student_id = ?
          AND scan_time >= NOW() - INTERVAL 5 MINUTE
        LIMIT 1
    ");

    if (!$stmt) return false;

    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return $row !== null;
}

// ================================
// WRITE ATTENDANCE
// ================================
function writeAttendanceRecord(
    $conn,
    $student_data,
    $scan_mode,
    $notification_sent = 0,
    $class_code = null,
    $attendance_status = 'on_time'
) {
    $stmt = $conn->prepare("
        INSERT INTO attendance_logs
            (student_id, full_name, class_code, scanned_by, attendance_status, notification_sent, scan_time)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");

    if (!$stmt) return false;

    $stmt->bind_param(
        "sssssi",
        $student_data['student_id'],
        $student_data['full_name'],
        $class_code,
        $scan_mode,
        $attendance_status,
        $notification_sent
    );

    return $stmt->execute() ? $conn->insert_id : false;
}

// ================================
// SAVE MESSAGE HISTORY
// ================================
function saveMessageHistory(
    $conn,
    $student_data,
    $parent,
    $attendance_status,
    $sms_status,
    $api_response,
    $log_id
) {
    $send_time    = date('Y-m-d H:i:s');
    $display_time = date('g:i A');

    $message_body = "SC BSIT Notification: Your child {$student_data['full_name']} arrived at school. Time: {$display_time}";
    $api_json     = json_encode($api_response);

    $stmt = $conn->prepare("
        INSERT INTO message_history
            (student_id, student_name, parent_name, parent_contact, message_body,
             attendance_status, scan_time, status, api_response, attendance_log_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) return;

    $stmt->bind_param(
        "sssssssssi",
        $student_data['student_id'],
        $student_data['full_name'],
        $parent['parent_name'],
        $parent['contact_number'],
        $message_body,
        $attendance_status,
        $send_time,
        $sms_status,
        $api_json,
        $log_id
    );

    $stmt->execute();
}

// ================================
// SEND SMS — iPROG API
// ================================
function sendSMS($toNumber, $student_name) {
    $send_time_display = date('g:i A');

    $url       = 'https://www.iprogsms.com/api/v1/sms_messages';
    $api_token = 'f698db38ba9a33f90c9c7b6266bdd4123f0b04db'; // Replace with your live token

    $message = "SC BSIT Notification: Your child {$student_name} arrived at school. Time: {$send_time_display}";

    $data = [
        'api_token'    => $api_token,
        'message'      => $message,
        'phone_number' => str_replace('+', '', $toNumber)
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['status' => 'failed', 'error' => $error];
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['status' => 'success', 'response' => $decoded];
    }

    return ['status' => 'failed', 'response' => $decoded, 'raw' => $response];
}

// ================================
// FORMAT PHONE NUMBER → +63 format
// ================================
function formatPhoneNumber($number) {
    if (empty($number)) return '';

    $number = preg_replace('/[^0-9]/', '', $number);

    if (strlen($number) === 10)                                       return "+63{$number}";
    if (strlen($number) === 11 && $number[0] === '0')                return "+63" . substr($number, 1);
    if (strlen($number) === 12 && substr($number, 0, 2) === '63')    return "+{$number}";
    if (strlen($number) === 13 && substr($number, 0, 3) === '+63')   return $number;

    return "+63{$number}";
}

// ================================
// TODAY'S ATTENDANCE SUMMARY
// ================================
function getTodayReport($conn) {
    $total = $conn->query("
        SELECT COUNT(DISTINCT student_id) AS total FROM students
    ")->fetch_assoc()['total'] ?? 0;

    $present = $conn->query("
        SELECT COUNT(DISTINCT student_id) AS present
        FROM attendance_logs
        WHERE DATE(scan_time) = CURDATE()
    ")->fetch_assoc()['present'] ?? 0;

    $late = $conn->query("
        SELECT COUNT(*) AS late
        FROM attendance_logs
        WHERE DATE(scan_time) = CURDATE()
          AND attendance_status = 'late'
    ")->fetch_assoc()['late'] ?? 0;

    return [
        'total'   => $total,
        'present' => $present,
        'absent'  => $total - $present,
        'late'    => $late
    ];
}

// ================================
// PROCESS SCAN  (called from index.php)
// ================================
function processScan() {
    if (!isset($_POST['barcode'])) return;

    $conn      = connectDB();
    $barcode   = trim($_POST['barcode']);
    $scan_mode = $_POST['scan_mode'] ?? 'student';

    if ($barcode === '') return;

    echo "<div class='scan-process'>";

    // ── Step 1: Verify student ──────────────────────────────────────────────
    echo "<div class='process-step'>🔍 1. Verifying student record...</div>";

    $student = verifyStudent($conn, $barcode);

    if (!$student) {
        echo "<div class='process-step error'>❌ Student not found with ID: " . htmlspecialchars($barcode) . "</div>";
        echo "</div>";
        return;
    }

    echo "<div class='process-step success'>✅ Student verified: <strong>" . htmlspecialchars($student['full_name']) . "</strong></div>";

    echo "<div class='student-info'>
            📚 " . htmlspecialchars($student['course']) . " — Year {$student['year_level']}<br>
            🆔 " . htmlspecialchars($student['student_id']) . "
          </div>";

    // ── Step 2: Detect class & status ──────────────────────────────────────
    $class_info    = detectClassAndStatus($conn);
    $class_code    = $class_info['class_code'];
    $attend_status = $class_info['attendance_status'];

    if ($class_code) {
        $status_label = $attend_status === 'late'
            ? "<span class='late-badge'>⚠️ LATE</span>"
            : "<span class='ontime-badge'>✅ ON TIME</span>";
        echo "<div class='process-step info'>🏫 Class: <strong>" . htmlspecialchars($class_code) . "</strong> — {$status_label}</div>";
    }

    // ── Step 3: Duplicate scan guard ───────────────────────────────────────
    if (hasDuplicateScan($conn, $student['student_id'], $class_code)) {
        echo "<div class='process-step warning'>⚠️ Already scanned within the last 5 minutes — skipped.</div>";
        echo "</div>";
        return;
    }

    // ── Teacher mode ───────────────────────────────────────────────────────
    if ($scan_mode === 'teacher') {
        echo "<div class='process-step'>👨‍🏫 Teacher mode — No notification</div>";
        echo "<div class='process-step'>📝 Recording attendance...</div>";

        $log_id = writeAttendanceRecord($conn, $student, 'teacher', 0, $class_code, $attend_status);

        echo $log_id
            ? "<div class='process-step success'>✅ Attendance recorded!</div>"
            : "<div class='process-step error'>❌ Failed to record attendance.</div>";

    // ── Student mode ───────────────────────────────────────────────────────
    } else {
        echo "<div class='process-step'>👨‍🎓 Student mode — Will notify parent</div>";
        echo "<div class='process-step'>📞 Getting parent contact...</div>";

        $parent = getParentContact($conn, $student['student_id']);

        if ($parent && !empty($parent['contact_number'])) {
            echo "<div class='process-step success'>✅ Parent: " . htmlspecialchars($parent['parent_name']) . " (" . htmlspecialchars($parent['relationship']) . ")</div>";
            echo "<div class='process-step'>📝 Recording attendance...</div>";

            $log_id = writeAttendanceRecord($conn, $student, 'student', 1, $class_code, $attend_status);

            if ($log_id) {
                echo "<div class='process-step success'>✅ Attendance recorded at " . date('g:i A') . "</div>";
                echo "<div class='process-step'>📱 Sending SMS notification...</div>";

                $formattedNumber = formatPhoneNumber($parent['contact_number']);
                $response        = sendSMS($formattedNumber, $student['full_name']);
                $sms_status      = (isset($response['status']) && strtolower($response['status']) === 'success') ? 'sent' : 'failed';

                saveMessageHistory($conn, $student, $parent, $attend_status, $sms_status, $response, $log_id);

                if ($sms_status === 'sent') {
                    echo "<div class='process-step success'>📩 SMS sent to " . htmlspecialchars($parent['parent_name']) . " ({$formattedNumber})</div>";
                } else {
                    echo "<div class='process-step warning'>⚠️ SMS failed — logged in message history</div>";
                }

            } else {
                echo "<div class='process-step error'>❌ Failed to record attendance.</div>";
            }

        } else {
            echo "<div class='process-step warning'>⚠️ No parent contact found — recording without SMS</div>";
            $log_id = writeAttendanceRecord($conn, $student, 'student', 0, $class_code, $attend_status);
            echo $log_id
                ? "<div class='process-step success'>✅ Attendance recorded (no SMS)</div>"
                : "<div class='process-step error'>❌ Failed to record attendance.</div>";
        }
    }

    // ── Today's summary ────────────────────────────────────────────────────
    $report = getTodayReport($conn);

    echo "
    <div class='attendance-report'>
        <h3>📊 Today's Summary</h3>
        <div class='report-stats'>
            <div class='stat'>Total<strong>{$report['total']}</strong></div>
            <div class='stat'>Present<strong>{$report['present']}</strong></div>
            <div class='stat'>Absent<strong>{$report['absent']}</strong></div>
            <div class='stat'>Late<strong>{$report['late']}</strong></div>
            <div class='stat'>Date<strong>" . date('M j, Y') . "</strong></div>
        </div>
    </div>";

    echo "</div>";
}
?>
