<?php
// ============================================================================
// includes/auth.php — Centralized session + authentication + role handling
// Include this at the TOP of EVERY page. It pulls in config + db automatically.
// ============================================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

// ── ONE session for the whole app (replaces TEACHER_PORTAL_SESSION etc.) ─────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    session_name('SAMS_SESSION');
    session_start();
}

// ── State checks ─────────────────────────────────────────────────────────────
function isLoggedIn(): bool {
    return !empty($_SESSION['uid']);
}

function currentUser(): array {
    return [
        'id'       => $_SESSION['uid']    ?? null,
        'name'     => $_SESSION['uname']  ?? 'Guest',
        'role'     => $_SESSION['urole']  ?? null,
        'username' => $_SESSION['ulogin'] ?? '',
    ];
}

// ── Guards (call at top of protected pages) ─────────────────────────────────
function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/auth/login.php');
        exit;
    }
}

/** Allow only the listed roles. Example: requireRole('admin','super_admin'); */
function requireRole(string ...$roles): void {
    requireLogin();
    if (!in_array($_SESSION['urole'], $roles, true)) {
        http_response_code(403);
        die('Access denied: insufficient privileges.');
    }
}

// ── Login / logout ───────────────────────────────────────────────────────────
/** Verifies credentials against the users table. Returns true on success. */
function attemptLogin(string $username, string $password): bool {
    $stmt = db()->prepare(
        'SELECT id, username, password_hash, full_name, role, status
         FROM users WHERE username = ? LIMIT 1'
    );
    $stmt->execute([$username]);
    $u = $stmt->fetch();

    if (!$u || $u['status'] !== 'active' || !password_verify($password, $u['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);            // prevent session fixation
    $_SESSION['uid']    = $u['id'];
    $_SESSION['uname']  = $u['full_name'];
    $_SESSION['urole']  = $u['role'];
    $_SESSION['ulogin'] = $u['username'];

    db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$u['id']]);
    return true;
}

function logoutUser(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}

/** Where to send a user after login — MATCHES THE REAL FOLDER NAMES. */
function roleHome(string $role): string {
    return BASE_URL . match ($role) {
        'super_admin', 'admin' => '/admin/dashboard.php',
        'teacher'              => '/transactions/dashboard.php',
        'student'              => '/scanner/attendance_scanner.php',
        default                => '/auth/login.php',
    };
}

// ── CSRF (adopted from the Transaction group — the one team that had it) ─────
function csrf(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function csrfField(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrf()) . '">';
}
function verifyCsrf(?string $token): bool {
    return !empty($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
}