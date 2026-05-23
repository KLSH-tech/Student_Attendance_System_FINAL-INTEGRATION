<?php
// ============================================================================
// Admin/config.php — BRIDGED to the unified foundation (Phase 1)
// ----------------------------------------------------------------------------
// BEFORE: connected to the deleted `modular_admin` DB + used its own session
//         + checked $_SESSION['admin_id']  → caused "Database connection failed"
//         and bounced you back to the old login.
// NOW:    reuses includes/auth.php (one DB, one session, one role system).
//         The legacy admin pages (dashboard.php, subsystem.php, users.php)
//         keep working WITHOUT being rewritten.
// ============================================================================

require_once __DIR__ . '/../includes/auth.php';   // session, e(), db(), requireRole, BASE_URL

// ── Legacy mysqli handle — now points at the UNIFIED database ───────────────
// Kept only so the old generic-CRUD helpers below still work as-is.
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_errno) {
    http_response_code(500);
    die('Database connection failed (unified DB). Check includes/config.php.');
}
$mysqli->set_charset('utf8mb4');

// NOTE: e() already exists in includes/helpers.php — we do NOT redeclare it,
// and session_start() is already handled by auth.php. (Both were the old bugs.)

// ── Bridge the old guard to the new role system ─────────────────────────────
if (!function_exists('require_admin')) {
    function require_admin() {
        requireRole('admin', 'super_admin');
    }
}

// ── Legacy mysqli CRUD helpers (unchanged signatures) ───────────────────────
if (!function_exists('stmt_bind')) {
    function stmt_bind($stmt, $types, $params) {
        $bind = [$types];
        foreach ($params as $k => $v) { $bind[] = &$params[$k]; }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
    function db_execute($mysqli, $sql, $types = '', $params = []) {
        $stmt = $mysqli->prepare($sql);
        if ($stmt === false) { throw new Exception('Prepare failed.'); }
        if ($types !== '') { stmt_bind($stmt, $types, $params); }
        $stmt->execute();
        return $stmt;
    }
    function db_fetch_all($mysqli, $sql, $types = '', $params = []) {
        $r = db_execute($mysqli, $sql, $types, $params)->get_result();
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }
    function db_fetch_one($mysqli, $sql, $types = '', $params = []) {
        $r = db_execute($mysqli, $sql, $types, $params)->get_result();
        return $r ? $r->fetch_assoc() : null;
    }
}

$ROLE_OPTIONS   = ['super_admin', 'admin', 'teacher', 'student'];
$STATUS_OPTIONS = ['active', 'inactive'];

// ── Dashboard cards → link to the REAL integrated subsystem folders ─────────
//    'table' is the real unified table used for the live count.
$SUBSYSTEMS = [
    'users' => [
        'label' => 'User & Identity Management',
        'table' => 'users',
        'path'  => BASE_URL . '/scanner/attendance_scanner.php',
    ],
    'notifications' => [
        'label' => 'Notification & Communication',
        'table' => 'message_history',
        'path'  => BASE_URL . '/notification/index.php',
    ],
    'profiles' => [
        'label' => 'Records / Profile Management',
        'table' => 'students',
        'path'  => BASE_URL . '/profiles/index.php',
    ],
    'schedules' => [
        'label' => 'Scheduling & Resource Management',
        'table' => 'schedules',
        'path'  => BASE_URL . '/scheduling/index.php',
    ],
    'reports' => [
        'label' => 'Reports & Analytics',
        'table' => 'attendance',
        'path'  => BASE_URL . '/reports/index.php',
    ],
    'transactions' => [
        'label' => 'Transaction / Request Management',
        'table' => 'dispute_requests',
        'path'  => BASE_URL . '/transactions/dashboard.php',
    ],
];

// ── Live record counts (safe: missing/empty tables just show 0) ─────────────
$counts = [];
foreach ($SUBSYSTEMS as $key => $sub) {
    try {
        $counts[$key] = (int) db()->query("SELECT COUNT(*) FROM `{$sub['table']}`")->fetchColumn();
    } catch (Throwable $ex) {
        $counts[$key] = 0;
    }
}

// ── Navigation helpers (now role-aware + absolute URLs) ─────────────────────
function subsystem_link($key) {
    global $SUBSYSTEMS;
    return $SUBSYSTEMS[$key]['path'] ?? (BASE_URL . '/admin/dashboard.php');
}

function render_sidebar($active = '') {
    global $SUBSYSTEMS;
    echo '<aside class="sidebar">';
    echo '<div class="sidebar-brand">' . e(APP_NAME) . '</div>';
    echo '<nav class="nav">';
    $dash = $active === 'dashboard' ? 'active' : '';
    echo '<a class="nav-link ' . $dash . '" href="' . e(BASE_URL . '/admin/dashboard.php') . '">Dashboard</a>';
    foreach ($SUBSYSTEMS as $key => $sub) {
        $class = $active === $key ? 'active' : '';
        echo '<a class="nav-link ' . $class . '" href="' . e(subsystem_link($key)) . '">' . e($sub['label']) . '</a>';
    }
    echo '</nav>';
    echo '</aside>';
}

function render_topbar($title) {
    $username = e(currentUser()['name']);   // new session, not admin_username
    echo '<div class="topbar">';
    echo '<div class="topbar-title">' . e($title) . '</div>';
    echo '<div class="topbar-actions">';
    echo '<span class="topbar-user">Signed in as ' . $username . '</span>';
    echo '<a class="btn btn-secondary" href="' . e(BASE_URL . '/auth/logout.php') . '">Logout</a>';
    echo '</div>';
    echo '</div>';
}