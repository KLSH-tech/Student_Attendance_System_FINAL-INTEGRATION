<?php
// ============================================================
// Teacher Portal — config.php (COMPLETE FIXED VERSION)
// ============================================================

// ── Database ──────────────────────────────────────────────────────────────────
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'student_attendance_system');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');

// ── Attendance windows ────────────────────────────────────────────────────────
define('CLASS_START_TIME',       '08:00:00');
define('PRESENT_WINDOW_MINUTES', 0);
define('LATE_WINDOW_MINUTES',    15);
define('BASE_URL', 'http://localhost/Integration_Final_EMAIL_UPGRADE');

// ── Base URL ──────────────────────────────────────────────────────────────────
if (!defined('BASE_URL')) {
    define('BASE_URL', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https' : 'http')
        . '://' . $_SERVER['HTTP_HOST']
        . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'));
}

// ── Session bootstrap ─────────────────────────────────────────────────────────
// BRIDGED: use the same session name as the unified foundation (includes/auth.php)
// so ONE admin login carries into the teacher portal too. The scanner stays
// public; every other module — this one included — needs that shared session.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    session_name('SAMS_SESSION');
    session_start();
}

// ── CSRF ──────────────────────────────────────────────────────────────────────
function csrf(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function verifyCsrf(string $token): bool {
    return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}
function csrfField(): string {
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrf()) . '">';
}

// ── Auth guards ───────────────────────────────────────────────────────────────
function isTeacher(): bool {
    // Native teacher-portal login (set by this subsystem's teacher-login.php)…
    if (!empty($_SESSION['teacher_auth']) && $_SESSION['teacher_auth'] === true) {
        return true;
    }
    // …OR a unified session created by /auth/login.php (shared SAMS_SESSION).
    // An admin/super_admin/teacher signed in there can use the portal directly.
    return !empty($_SESSION['uid'])
        && in_array($_SESSION['urole'] ?? '', ['admin', 'super_admin', 'teacher'], true);
}

/** Call at the top of every protected page */
function requireTeacher(): void {
    if (!isTeacher()) {
        // Send guests to the SINGLE unified admin login. A relative path keeps
        // this working no matter what the project folder is named.
        header('Location: ../auth/login.php');
        exit;
    }
}

// ── Teacher login function ────────────────────────────────────────────────────
function teacherLogin(string $username, string $password): bool {
    try {
        $pdo = db();
        
        $stmt = $pdo->prepare("
            SELECT 
                u.id as user_id,
                u.username,
                u.password_hash,
                u.full_name,
                u.email,
                u.role,
                u.status as user_status,
                t.id as teacher_db_id,
                t.teacher_number,
                t.department,
                t.contact,
                t.name as teacher_name
            FROM users u
            LEFT JOIN teachers t ON t.user_id = u.id
            WHERE u.username = ? 
            AND u.role IN ('teacher', 'admin', 'super_admin') 
            AND u.status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            try {
                $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")
                    ->execute([$user['user_id']]);
            } catch (PDOException $e) {
                // Silently fail
            }

            // Write the canonical portal session keys via the SHARED helper, so
            // this password login and the scanner's badge login stay identical.
            require_once __DIR__ . '/../includes/teacher_portal_session.php';
            setTeacherPortalSession([
                'teacher_db_id'  => $user['teacher_db_id'] ?? null,
                'user_id'        => $user['user_id'],
                'teacher_name'   => $user['teacher_name'] ?? '',
                'full_name'      => $user['full_name'] ?? '',
                'teacher_number' => $user['teacher_number'] ?? '',
                'department'     => $user['department'] ?? '',
                'role'           => $user['role'],
                'username'       => $user['username'],
                'email'          => $user['email'] ?? '',
            ]);

            return true;
        }
        return false;
    } catch (PDOException $e) {
        error_log('[TeacherPortal] Login error: ' . $e->getMessage());
        return false;
    }
}

// ── DB singleton ──────────────────────────────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER, DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            die('<div style="font-family:sans-serif;padding:40px;max-width:520px;margin:60px auto;
                background:#fee2e2;border-radius:12px;border:1px solid #fca5a5;">
                <h3 style="color:#991b1b;margin:0 0 10px;">⚠ Database Connection Failed</h3>
                <p style="color:#7f1d1d;font-size:14px;">Check DB_HOST / DB_NAME / DB_USER / DB_PASS in config.php</p>
                </div>');
        }
    }
    return $pdo;
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function statusClass(string $s): string {
    return match($s) {
        'Present'      => 'badge-present',
        'Late'         => 'badge-late',
        'Absent'       => 'badge-absent',
        'Approved'     => 'badge-approved',
        'Rejected'     => 'badge-rejected',
        'Pending'      => 'badge-pending',
        'Under Review' => 'badge-review',
        default        => 'badge-default'
    };
}

function rateClass(float $r): string {
    if ($r >= 80) return 'rate-good';
    if ($r >= 60) return 'rate-warn';
    return 'rate-bad';
}

function timeAgo(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)    return 'just now';
    if ($diff < 3600)  return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}

function pendingDisputeCount(): int {
    try {
        $pdo = db();
        $result = $pdo->query("SHOW TABLES LIKE 'dispute_requests'");
        if ($result->rowCount() > 0) {
            $count = $pdo->query(
                "SELECT COUNT(*) FROM dispute_requests WHERE status IN ('Pending','Under Review')"
            )->fetchColumn();
            return (int) $count;
        }
        return 0;
    } catch (PDOException $e) {
        error_log('pendingDisputeCount error: ' . $e->getMessage());
        return 0;
    }
}

function initials(string $name): string {
    $parts = explode(' ', trim($name));
    $out   = '';
    foreach ($parts as $p) $out .= strtoupper(substr($p, 0, 1));
    return substr($out, 0, 2);
}

function e(string $s): string { 
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); 
}