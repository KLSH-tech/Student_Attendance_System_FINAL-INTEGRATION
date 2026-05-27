<?php
// ============================================================================
// login.php — Single unified login (replaces Admin, Teacher, and any other login)
// ============================================================================
require_once __DIR__ . '/../includes/auth.php';

// Already signed in → go to the right home
if (isLoggedIn()) {
    header('Location: ' . roleHome(currentUser()['role']));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf'] ?? '')) {
        $error = 'Security token mismatch. Please refresh and try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if ($username === '' || $password === '') {
            $error = 'Please enter your username and password.';
        } elseif (attemptLogin($username, $password)) {
            header('Location: ' . roleHome(currentUser()['role']));
            exit;
        } else {
            $error = 'Invalid credentials.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login — <?php echo e(APP_NAME); ?></title>
  <link rel="stylesheet" href="<?php echo e(BASE_URL); ?>/assets/css/style.css">
  <style>
    body{display:grid;place-items:center;min-height:100vh;margin:0;background:#0f172a;font-family:system-ui,sans-serif}
    .login-card{background:#fff;padding:32px;border-radius:12px;width:340px;box-shadow:0 20px 60px rgba(0,0,0,.4)}
    .login-card h1{font-size:18px;margin:0 0 4px}
    .login-card p.sub{margin:0 0 20px;color:#64748b;font-size:13px}
    .login-card label{display:block;font-size:12px;font-weight:600;margin:12px 0 4px;color:#334155}
    .login-card input{width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box}
    .login-card button{width:100%;margin-top:18px;padding:11px;border:0;border-radius:8px;background:#0369a1;color:#fff;font-weight:600;cursor:pointer}
    .err{background:#fee2e2;color:#991b1b;padding:10px 12px;border-radius:8px;font-size:13px;margin-bottom:12px}
    .back-link{display:block;text-align:center;margin-top:16px;font-size:12px;color:#64748b;text-decoration:none}
    .back-link:hover{color:#0369a1}
  </style>
</head>
<body>
  <form class="login-card" method="post">
    <h1><?php echo e(APP_NAME); ?></h1>
    <p class="sub">Sign in to continue</p>
    <?php if ($error): ?><div class="err"><?php echo e($error); ?></div><?php endif; ?>
    <?php echo csrfField(); ?>
    <label for="username">Username</label>
    <input id="username" name="username" type="text" required autofocus value="<?php echo e($_POST['username'] ?? ''); ?>">
    <label for="password">Password</label>
    <input id="password" name="password" type="password" required>
    <button type="submit">Sign In</button>
    <a class="back-link" href="<?php echo e(BASE_URL); ?>/transactions/dashboard.php">&larr; Back to Scanner</a>
  </form>
</body>
</html>
