<?php
// ============================================================================
// scanner/test_email.php — SMTP / e-mail diagnostic tool (ADMIN ONLY)
// Use this to confirm your SMTP settings work BEFORE relying on the scanner.
// It sends a sample attendance e-mail to an address you type in.
// ============================================================================
require_once __DIR__ . '/../includes/guard.php';   // admins/super_admins only
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/mailer.php';

$result = sendMailSMTP(
    'yourtestemail@gmail.com',
    'Test User',
    'SMTP Test',
    '<h1>Email Test Successful</h1>',
    'Email Test Successful'
);

header('Content-Type: application/json');
echo json_encode($result, JSON_PRETTY_PRINT);

$result = null;
$sentTo = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf'] ?? null)) {
        $result = ['ok' => false, 'error' => 'Invalid CSRF token. Refresh and try again.'];
    } else {
        $sentTo = trim($_POST['to'] ?? '');
        $built  = buildAttendanceEmail([
            'school_name'    => APP_NAME,
            'student_name'   => 'Juan Dela Cruz (TEST)',
            'student_number' => '20250000',
            'subject_name'   => 'Introduction to Human Computer Interaction',
            'subject_code'   => 'IT11',
            'teacher_name'   => 'Andico, LJ',
            'room'           => 'COM LAB A',
            'section'        => 'BSIT 1A',
            'date_str'       => date('l, F j, Y'),
            'time_str'       => date('h:i A'),
            'status_text'    => 'On Time',
            'action'         => 'in',
            'is_late'        => false,
        ]);
        $result = sendMailSMTP($sentTo, 'Test Recipient', $built['subject'], $built['html'], $built['alt']);
    }
}

function badge($ok) { return $ok ? '#16a34a' : '#dc2626'; }
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Email Diagnostic | SAMS</title>
<style>
  body{font-family:Segoe UI,Roboto,Arial,sans-serif;background:#0a1628;color:#cce8ff;margin:0;padding:40px 16px;}
  .card{max-width:560px;margin:0 auto;background:#0e1f38;border:1px solid #0e2a4a;border-radius:14px;padding:28px;}
  h1{font-size:20px;margin:0 0 4px;color:#00c8ff;}
  p.sub{color:#4a7a9b;margin:0 0 22px;font-size:14px;}
  label{display:block;font-size:13px;color:#9fc7e6;margin:14px 0 6px;}
  input[type=email]{width:100%;padding:11px 12px;border-radius:8px;border:1px solid #0e2a4a;background:#050d1a;color:#cce8ff;font-size:15px;}
  button{margin-top:18px;width:100%;padding:12px;border:0;border-radius:8px;background:#00c8ff;color:#031018;font-weight:700;font-size:15px;cursor:pointer;}
  .row{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid #0e2a4a;font-size:13px;}
  .row span:first-child{color:#4a7a9b;}
  .result{margin-top:22px;padding:14px 16px;border-radius:10px;font-size:14px;line-height:1.5;}
  code{background:#050d1a;padding:2px 6px;border-radius:5px;color:#00ff9d;}
  .cfg{margin-top:8px;background:#050d1a;border-radius:10px;padding:10px 14px;}
</style></head>
<body>
<div class="card">
  <h1>📧 Email Diagnostic</h1>
  <p class="sub">Sends one sample attendance e-mail using your current SMTP settings.</p>

  <div class="cfg">
    <div class="row"><span>MAIL_ENABLED</span><span><?= MAIL_ENABLED ? 'true' : 'false' ?></span></div>
    <div class="row"><span>MAIL_TEST_MODE</span><span><?= MAIL_TEST_MODE ? 'true (won\'t actually send)' : 'false' ?></span></div>
    <div class="row"><span>SMTP_HOST</span><span><?= e(SMTP_HOST) ?></span></div>
    <div class="row"><span>SMTP_PORT</span><span><?= e(SMTP_PORT) ?> (<?= e(SMTP_SECURE) ?>)</span></div>
    <div class="row"><span>SMTP_USER</span><span><?= e(SMTP_USER) ?></span></div>
    <div class="row"><span>MAIL_FROM</span><span><?= e(MAIL_FROM) ?></span></div>
  </div>

  <form method="post">
    <?= csrfField() ?>
    <label for="to">Send test e-mail to:</label>
    <input type="email" name="to" id="to" required placeholder="you@example.com" value="<?= e($sentTo) ?>">
    <button type="submit">Send test e-mail</button>
  </form>

  <?php if ($result !== null): ?>
    <div class="result" style="background:rgba(<?= $result['ok'] ? '22,163,74' : '220,38,38' ?>,0.12);border:1px solid <?= badge($result['ok']) ?>;">
      <?php if ($result['ok']): ?>
        ✅ <strong>Success.</strong>
        <?= !empty($result['test']) ? 'Test mode is ON, so nothing was actually sent — but the code path works.' : 'E-mail accepted by the SMTP server. Check the inbox (and Spam) of <code>' . e($sentTo) . '</code>.' ?>
      <?php else: ?>
        ❌ <strong>Failed.</strong><br><code><?= e($result['error'] ?? 'Unknown error') ?></code>
        <br><br>Common fixes: use a Gmail <em>App Password</em> (not your login), confirm port 587 + <code>tls</code> (or 465 + <code>ssl</code>), and make sure <code>php_openssl</code> is enabled in <code>php.ini</code>.
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
</body></html>
