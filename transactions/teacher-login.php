<?php
// ============================================================
// teacher-login.php — Teacher Portal Login
// Transaction / Request Management Subsystem · Group 7
// Southland College
// ============================================================
require_once __DIR__ . '/config.php';

// Already logged in → go to dashboard
if (isTeacher()) {
    header('Location: dashboard.php');
    exit;
}

$error   = '';
$success = '';

// Handle logout / auth messages
$reason = $_GET['reason'] ?? '';
if ($reason === 'auth')   $error   = 'Please log in to access the Teacher Portal.';
if ($_GET['logout'] ?? '') $success = 'You have been signed out successfully.';

// ── Handle login POST ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf'] ?? '')) {
        $error = 'Security token mismatch. Please refresh and try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Please enter your username and password.';
        } elseif (teacherLogin($username, $password)) {
            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Invalid username or password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Teacher Login · TRMS · Southland College</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --navy:        #0c4a6e;
    --navy-dark:   #082f49;
    --navy-deep:   #041a28;
    --blue:        #0369a1;
    --blue-light:  #0ea5e9;
    --accent:      #38bdf8;
    --white:       #ffffff;
    --slate-100:   #f0f9ff;
    --slate-200:   #e0f2fe;
    --slate-400:   #7dd3fc;
    --slate-500:   #38bdf8;
    --muted:       rgba(255,255,255,0.45);
    --border:      rgba(255,255,255,0.1);
    --danger:      #f87171;
    --success:     #4ade80;
    --mono:        'JetBrains Mono', monospace;
}

html, body {
    height: 100%;
    font-family: 'Sora', sans-serif;
    background: var(--navy-deep);
    color: var(--white);
    overflow-x: hidden;
}

/* ── Background ──────────────────────────────────────────────────────────────── */
.bg-layer {
    position: fixed;
    inset: 0;
    z-index: 0;
    background:
        radial-gradient(ellipse 80% 60% at 20% 10%,  rgba(3,105,161,0.45) 0%, transparent 60%),
        radial-gradient(ellipse 60% 50% at 80% 80%,  rgba(12,74,110,0.5)  0%, transparent 60%),
        radial-gradient(ellipse 40% 40% at 60% 20%,  rgba(56,189,248,0.1) 0%, transparent 50%),
        var(--navy-deep);
}

/* Subtle grid overlay */
.bg-grid {
    position: fixed;
    inset: 0;
    z-index: 0;
    background-image:
        linear-gradient(rgba(56,189,248,0.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(56,189,248,0.04) 1px, transparent 1px);
    background-size: 48px 48px;
}

/* Floating orbs */
.orb {
    position: fixed;
    border-radius: 50%;
    filter: blur(80px);
    opacity: 0.15;
    z-index: 0;
    animation: drift 20s ease-in-out infinite;
}
.orb-1 { width: 400px; height: 400px; background: var(--blue-light); top: -10%; left: -5%; animation-delay: 0s; }
.orb-2 { width: 300px; height: 300px; background: var(--accent);     bottom: -5%; right: -5%; animation-delay: -8s; }
.orb-3 { width: 200px; height: 200px; background: var(--navy);        top: 50%;   left: 50%; animation-delay: -15s; }

@keyframes drift {
    0%,100% { transform: translate(0,0) scale(1); }
    33%      { transform: translate(30px,-20px) scale(1.05); }
    66%      { transform: translate(-20px,30px) scale(0.95); }
}

/* ── Page layout ─────────────────────────────────────────────────────────────── */
.page {
    position: relative;
    z-index: 1;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

/* ── Top bar ─────────────────────────────────────────────────────────────────── */
.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 40px;
    border-bottom: 1px solid var(--border);
    background: rgba(4,26,40,0.4);
    backdrop-filter: blur(12px);
}
.topbar-brand {
    display: flex;
    align-items: center;
    gap: 12px;
}
.brand-icon {
    width: 40px; height: 40px;
    background: linear-gradient(135deg, var(--blue), var(--blue-light));
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    box-shadow: 0 4px 15px rgba(14,165,233,0.3);
}
.brand-text-main {
    font-size: 15px;
    font-weight: 700;
    color: var(--white);
    letter-spacing: -.02em;
}
.brand-text-sub {
    font-size: 10px;
    color: var(--muted);
    letter-spacing: .06em;
    text-transform: uppercase;
    margin-top: 1px;
}
.topbar-right {
    display: flex;
    align-items: center;
    gap: 16px;
}
.topbar-badge {
    font-size: 11px;
    font-weight: 600;
    color: var(--slate-400);
    background: rgba(56,189,248,0.1);
    border: 1px solid rgba(56,189,248,0.2);
    border-radius: 20px;
    padding: 5px 14px;
    letter-spacing: .04em;
}
.back-link {
    font-size: 12px;
    color: var(--muted);
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: color .2s;
}
.back-link:hover { color: var(--accent); }

/* ── Main content ─────────────────────────────────────────────────────────────── */
.main {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 48px 20px;
}

.login-wrapper {
    width: 100%;
    max-width: 460px;
    animation: fadeUp .6s cubic-bezier(.16,1,.3,1) both;
}

@keyframes fadeUp {
    from { opacity: 0; transform: translateY(24px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ── Header ─────────────────────────────────────────────────────────────────── */
.login-header {
    text-align: center;
    margin-bottom: 36px;
}
.login-icon {
    width: 72px; height: 72px;
    margin: 0 auto 20px;
    background: linear-gradient(135deg, var(--blue), var(--blue-light));
    border-radius: 20px;
    display: flex; align-items: center; justify-content: center;
    font-size: 30px;
    box-shadow: 0 8px 32px rgba(14,165,233,0.35), 0 0 0 1px rgba(56,189,248,0.2);
    position: relative;
}
.login-icon::after {
    content: '';
    position: absolute;
    inset: -8px;
    border-radius: 28px;
    border: 1px solid rgba(56,189,248,0.15);
}
.login-title {
    font-size: 28px;
    font-weight: 800;
    color: var(--white);
    letter-spacing: -.03em;
    line-height: 1.1;
    margin-bottom: 8px;
}
.login-subtitle {
    font-size: 13px;
    color: var(--muted);
    font-weight: 400;
}

/* ── Card ─────────────────────────────────────────────────────────────────────── */
.login-card {
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 20px;
    padding: 36px;
    backdrop-filter: blur(20px);
    box-shadow:
        0 24px 64px rgba(0,0,0,0.4),
        inset 0 1px 0 rgba(255,255,255,0.08);
}

/* ── Alerts ──────────────────────────────────────────────────────────────────── */
.alert {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 24px;
    line-height: 1.5;
}
.alert-error {
    background: rgba(248,113,113,0.12);
    border: 1px solid rgba(248,113,113,0.25);
    color: #fca5a5;
}
.alert-success {
    background: rgba(74,222,128,0.1);
    border: 1px solid rgba(74,222,128,0.2);
    color: #86efac;
}
.alert-icon { font-size: 15px; flex-shrink: 0; margin-top: 1px; }

/* ── Form fields ─────────────────────────────────────────────────────────────── */
.field-group { margin-bottom: 20px; }

.field-label {
    display: block;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--slate-400);
    margin-bottom: 8px;
}

.field-wrap {
    position: relative;
}
.field-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 15px;
    pointer-events: none;
    z-index: 1;
}
.field-input {
    width: 100%;
    padding: 13px 14px 13px 42px;
    background: rgba(255,255,255,0.06);
    border: 1.5px solid rgba(255,255,255,0.1);
    border-radius: 10px;
    color: var(--white);
    font-family: 'Sora', sans-serif;
    font-size: 14px;
    font-weight: 500;
    transition: border-color .2s, background .2s, box-shadow .2s;
    outline: none;
}
.field-input::placeholder { color: rgba(255,255,255,0.25); }
.field-input:focus {
    border-color: var(--accent);
    background: rgba(56,189,248,0.06);
    box-shadow: 0 0 0 3px rgba(56,189,248,0.12);
}

/* Password toggle */
.toggle-pw {
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: var(--muted);
    cursor: pointer;
    font-size: 15px;
    padding: 4px;
    transition: color .2s;
    z-index: 1;
}
.toggle-pw:hover { color: var(--accent); }

/* ── Submit button ───────────────────────────────────────────────────────────── */
.submit-btn {
    width: 100%;
    padding: 14px;
    margin-top: 8px;
    background: linear-gradient(135deg, var(--blue) 0%, var(--blue-light) 100%);
    border: none;
    border-radius: 10px;
    color: white;
    font-family: 'Sora', sans-serif;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    transition: transform .15s, box-shadow .2s, filter .2s;
    box-shadow: 0 4px 20px rgba(14,165,233,0.35);
    letter-spacing: -.01em;
    position: relative;
    overflow: hidden;
}
.submit-btn::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(255,255,255,0.1), transparent);
    opacity: 0;
    transition: opacity .2s;
}
.submit-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 28px rgba(14,165,233,0.45); filter: brightness(1.05); }
.submit-btn:hover::after { opacity: 1; }
.submit-btn:active { transform: translateY(0); }

/* ── Divider & demo hint ─────────────────────────────────────────────────────── */
.divider {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 24px 0 20px;
    color: rgba(255,255,255,0.15);
    font-size: 11px;
    letter-spacing: .06em;
    text-transform: uppercase;
}
.divider::before, .divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: rgba(255,255,255,0.08);
}

.demo-hint {
    background: rgba(56,189,248,0.06);
    border: 1px solid rgba(56,189,248,0.15);
    border-radius: 10px;
    padding: 14px 16px;
    font-size: 12px;
    color: var(--slate-400);
    line-height: 1.7;
}
.demo-hint strong { color: var(--accent); }
.demo-hint code {
    font-family: var(--mono);
    font-size: 11px;
    background: rgba(56,189,248,0.1);
    padding: 1px 6px;
    border-radius: 4px;
    color: var(--accent);
}

/* ── Footer ─────────────────────────────────────────────────────────────────── */
.login-footer {
    text-align: center;
    margin-top: 24px;
    font-size: 11px;
    color: rgba(255,255,255,0.2);
    letter-spacing: .03em;
}

/* ── Page footer ─────────────────────────────────────────────────────────────── */
.page-footer {
    text-align: center;
    padding: 20px;
    border-top: 1px solid var(--border);
    font-size: 11px;
    color: rgba(255,255,255,0.18);
    background: rgba(4,26,40,0.3);
}

/* ── Responsive ──────────────────────────────────────────────────────────────── */
@media (max-width: 500px) {
    .topbar { padding: 16px 20px; }
    .login-card { padding: 24px 20px; }
    .login-title { font-size: 22px; }
}
</style>
</head>
<body>

<div class="bg-layer"></div>
<div class="bg-grid"></div>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>
<div class="orb orb-3"></div>

<div class="page">

    <!-- Top bar -->
    <header class="topbar">
        <div class="topbar-brand">
            <div class="brand-icon">🎓</div>
            <div>
                <div class="brand-text-main">TRMS · Southland College</div>
                <div class="brand-text-sub">Transaction / Request Management Subsystem</div>
            </div>
        </div>
        <div class="topbar-right">
            <span class="topbar-badge">Group 7</span>
            <a href="index.php" class="back-link">← Student Portal</a>
        </div>
    </header>

    <!-- Main -->
    <main class="main">
        <div class="login-wrapper">

            <!-- Header -->
            <div class="login-header">
                <div class="login-icon">🔐</div>
                <h1 class="login-title">Teacher Portal</h1>
                <p class="login-subtitle">Sign in with your teacher credentials to continue</p>
            </div>

            <!-- Card -->
            <div class="login-card">

                <?php if ($error): ?>
                <div class="alert alert-error">
                    <span class="alert-icon">⚠️</span>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
                <?php endif; ?>

                <?php if ($success): ?>
                <div class="alert alert-success">
                    <span class="alert-icon">✅</span>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <?php echo csrfField(); ?>

                    <div class="field-group">
                        <label class="field-label" for="username">Username</label>
                        <div class="field-wrap">
                            <span class="field-icon">👤</span>
                            <input type="text" id="username" name="username" class="field-input"
                                   placeholder="e.g. maria.clara"
                                   value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                                   required autofocus autocomplete="username">
                        </div>
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="password">Password</label>
                        <div class="field-wrap">
                            <span class="field-icon">🔒</span>
                            <input type="password" id="password" name="password" class="field-input"
                                   placeholder="Enter your password"
                                   required autocomplete="current-password">
                            <button type="button" class="toggle-pw" onclick="togglePassword()" title="Show/hide password">
                                👁
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="submit-btn">
                        Sign In to Portal →
                    </button>
                </form>

                <div class="divider">Demo Credentials</div>

                <div class="demo-hint">
                    <strong>Available accounts</strong> (password: <code>teacher123</code>)<br>
                    <code>maria.clara</code> &nbsp;·&nbsp; <code>jose.santos</code> &nbsp;·&nbsp; <code>admin</code><br><br>
                    Students cannot access this portal.
                </div>

            </div><!-- /.login-card -->

            <div class="login-footer">
                Teacher-only access &nbsp;·&nbsp; Unauthorized access is prohibited
            </div>

        </div>
    </main>

    <!-- Page footer -->
    <footer class="page-footer">
        &copy; <?php echo date('Y'); ?> &nbsp;·&nbsp; Group 7 — Transaction / Request Management Subsystem &nbsp;·&nbsp; Southland College
    </footer>

</div><!-- /.page -->

<script>
function togglePassword() {
    const pw  = document.getElementById('password');
    const btn = document.querySelector('.toggle-pw');
    if (pw.type === 'password') {
        pw.type = 'text';
        btn.textContent = '🙈';
    } else {
        pw.type = 'password';
        btn.textContent = '👁';
    }
}
</script>
</body>
</html>