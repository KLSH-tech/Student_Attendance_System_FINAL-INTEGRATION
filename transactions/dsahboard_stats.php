<?php
// ============================================================
// dashboard_stats.php — LIVE attendance counters (JSON)
// ------------------------------------------------------------
// Reads from attendance_logs (where the barcode scanner writes),
// so the dashboard cards update right after a student scans.
// The dashboard auto-refresh in layout-end.php fetches THIS file.
// (Delete the old, misspelled "dsahboard_stats.php".)
// ============================================================

require_once __DIR__ . '/config.php';
                    // same shared session as the dashboard

header('Content-Type: application/json');

try {
    $pdo = db();

    // Total enrolled students
    $totalStudents = (int) $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();

    // Per-student status for TODAY, derived from scan logs.
    // A student counts as "late" if ANY of today's time-in scans was late,
    // otherwise "present". Students with no time-in today are not-marked/absent.
    $row = $pdo->query("
        SELECT
            COALESCE(SUM(day_status = 'present'), 0) AS present,
            COALESCE(SUM(day_status = 'late'),    0) AS late
        FROM (
            SELECT student_id,
                   CASE WHEN MAX(attendance_status = 'late') = 1
                        THEN 'late' ELSE 'present' END AS day_status
            FROM attendance_logs
            WHERE action = 'in'
              AND DATE(logged_at) = CURDATE()
            GROUP BY student_id
        ) AS day
    ")->fetch();

    $present     = (int) ($row['present'] ?? 0);
    $late        = (int) ($row['late'] ?? 0);
    $markedToday = $present + $late;                    // distinct students seen today
    $notMarked   = max(0, $totalStudents - $markedToday);
    $absent      = 0;                                   // scans never record a plain "Absent"

    echo json_encode([
        'present'       => $present,
        'late'          => $late,
        'absent'        => $absent,
        'notMarked'     => $notMarked,
        'totalStudents' => $totalStudents,
        'markedToday'   => $markedToday,
    ]);
} catch (Throwable $ex) {
    http_response_code(500);
    error_log('dashboard_stats error: ' . $ex->getMessage());
    echo json_encode(['error' => 'stats_failed']);
}