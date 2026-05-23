<?php
// api.php - G6 Reports & Analytics
// POST: Receives from G4 (students) and G7 (attendance)
// GET:  Sends reports to G1 Admin

header('Content-Type: application/json');

// ── Access control: this endpoint exposes report data → admins only. ─────────
// JSON-safe guard (return 403 JSON, never an HTML redirect) so any programmatic
// caller fails cleanly instead of being silently exposed to the public URL.
require_once __DIR__ . '/../includes/auth.php';
if (!isLoggedIn() || !in_array(currentUser()['role'], ['admin', 'super_admin'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Not authorized']);
    exit;
}

$db = new PDO('mysql:host=localhost;dbname=G6_reports_db', 'root', '');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ═══════════════════════════════════════════════════
// POST - Receive data from G4 and G7
// ═══════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    $type    = $payload['type'] ?? '';

    try {
        // ── FROM G4: Student Profiles ──────────────────
        if ($type === 'students') {
            if (empty($payload['data'])) {
                echo json_encode(['status' => 'error', 'message' => 'No student data received']);
                exit;
            }
            $stmt = $db->prepare("
                INSERT INTO student_data (g4_profile_id, user_id, name, course, section, contact)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    name    = VALUES(name),
                    course  = VALUES(course),
                    section = VALUES(section),
                    contact = VALUES(contact)
            ");
            foreach ($payload['data'] as $s) {
                $stmt->execute([
                    $s['profile_id'],
                    $s['user_id'],
                    $s['name']    ?? '',
                    $s['course']  ?? '',
                    $s['section'] ?? '',
                    $s['contact'] ?? ''
                ]);
            }
            echo json_encode([
                'status'  => 'success',
                'message' => 'Student profiles received from G4',
                'synced'  => count($payload['data'])
            ]);

        // ── FROM G7: Attendance Records ────────────────
        } elseif ($type === 'attendance') {
            if (empty($payload['data'])) {
                echo json_encode(['status' => 'error', 'message' => 'No attendance data received']);
                exit;
            }

            // Validate all records are Monday
            foreach ($payload['data'] as $record) {
                if (date('N', strtotime($record['date'])) != 1) {
                    echo json_encode([
                        'status'   => 'error',
                        'message'  => 'Only Monday attendance accepted by G6',
                        'received' => $record['date'],
                        'day'      => date('l', strtotime($record['date']))
                    ]);
                    exit;
                }
            }

            $stmt = $db->prepare("
                INSERT INTO attendance_data (g4_record_id, user_id, date, status, check_in)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    status   = VALUES(status),
                    check_in = VALUES(check_in)
            ");
            foreach ($payload['data'] as $r) {
                $stmt->execute([
                    $r['record_id']  ?? 0,
                    $r['user_id'],
                    $r['date'],
                    $r['status'],
                    $r['check_in']   ?? null
                ]);
            }
            echo json_encode([
                'status'  => 'success',
                'message' => 'Attendance records received from G7',
                'synced'  => count($payload['data'])
            ]);

        } else {
            echo json_encode(['status' => 'error', 'message' => 'Unknown type. Use: students or attendance']);
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }

// ═══════════════════════════════════════════════════
// GET - Send reports to G1 Admin
// ═══════════════════════════════════════════════════
} else {
    $action = $_GET['action'] ?? '';
    $today  = date('Y-m-d');
    $monday = date('N') == 1 ? $today : date('Y-m-d', strtotime('last monday'));
    if (!empty($_GET['date'])) {
        $monday = date('N', strtotime($_GET['date'])) == 1
            ? $_GET['date']
            : date('Y-m-d', strtotime('last monday', strtotime($_GET['date'])));
    }

    switch ($action) {

        // ── Full Monday Report (for G1 Admin) ─────────
        case 'monday_report':
        case 'daily_report':
            $stats = $db->prepare("
                SELECT
                    COUNT(*)               as total,
                    SUM(status='Present')  as present,
                    SUM(status='Absent')   as absent,
                    SUM(status='Late')     as late
                FROM attendance_data WHERE date = ?
            ");
            $stats->execute([$monday]);
            $summary = $stats->fetch(PDO::FETCH_ASSOC);

            $records = $db->prepare("
                SELECT sd.name, sd.course, sd.section, a.user_id, a.date, a.status, a.check_in
                FROM attendance_data a
                LEFT JOIN student_data sd ON a.user_id = sd.user_id
                WHERE a.date = ?
                ORDER BY sd.section, sd.name
            ");
            $records->execute([$monday]);

            $report = [
                'source'      => 'G6 - Reports & Analytics',
                'report_type' => 'Monday Attendance',
                'date'        => $monday,
                'summary'     => $summary,
                'records'     => $records->fetchAll(PDO::FETCH_ASSOC)
            ];

            // Save to reports table
            $db->prepare("
                INSERT INTO reports (report_type, report_date, data, sent_to_admin)
                VALUES (?, ?, ?, TRUE)
                ON DUPLICATE KEY UPDATE data = VALUES(data), sent_to_admin = TRUE
            ")->execute(['monday', $monday, json_encode($report)]);

            echo json_encode($report);
            break;

        // ── Student Summary ────────────────────────────
        case 'student_summary':
            $userId = $_GET['user_id'] ?? '';
            if (!$userId) {
                echo json_encode(['status' => 'error', 'message' => 'user_id required']);
                exit;
            }
            $profile = $db->prepare("SELECT * FROM student_data WHERE user_id = ?");
            $profile->execute([$userId]);

            $history = $db->prepare("
                SELECT date, status, check_in
                FROM attendance_data
                WHERE user_id = ? AND DAYOFWEEK(date) = 2
                ORDER BY date DESC
            ");
            $history->execute([$userId]);

            echo json_encode([
                'source'      => 'G6',
                'user_id'     => $userId,
                'profile'     => $profile->fetch(PDO::FETCH_ASSOC),
                'mondays_only'=> true,
                'history'     => $history->fetchAll(PDO::FETCH_ASSOC)
            ]);
            break;

        // ── Statistics by Course/Section ──────────────
        case 'statistics':
            $stats = $db->query("
                SELECT
                    sd.course,
                    sd.section,
                    COUNT(*)                                    as total_records,
                    SUM(a.status='Present')                     as present,
                    SUM(a.status='Absent')                      as absent,
                    SUM(a.status='Late')                        as late,
                    ROUND(SUM(a.status='Present')/COUNT(*)*100, 2) as attendance_rate
                FROM attendance_data a
                JOIN student_data sd ON a.user_id = sd.user_id
                WHERE DAYOFWEEK(a.date) = 2
                GROUP BY sd.course, sd.section
                ORDER BY sd.course, sd.section
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'source'      => 'G6',
                'mondays_only'=> true,
                'statistics'  => $stats
            ]);
            break;

        default:
            echo json_encode([
                'status'    => 'error',
                'message'   => 'Unknown action',
                'available' => ['monday_report', 'student_summary', 'statistics']
            ]);
    }
}
?>