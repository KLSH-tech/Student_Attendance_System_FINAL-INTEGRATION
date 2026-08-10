<?php
// ============================================================================
// includes/mailer.php — Centralized e-mail notification module (PHPMailer/SMTP)
// ----------------------------------------------------------------------------
// One place that knows how to:
//   • build the professional, responsive attendance e-mail (buildAttendanceEmail)
//   • send any e-mail over SMTP with an automatic retry  (sendMailSMTP)
//   • turn a scan into a parent notification end-to-end   (notifyParentByEmail)
//       - resolves the recipient (parent e-mail → student e-mail fallback)
//       - blocks duplicate sends inside MAIL_DEDUP_SECONDS
//       - logs every attempt to the `email_log` table
//   • re-send previously failed messages                  (retryFailedEmails)
//
// It reuses the shared PDO from includes/db.php (no new DB connection) and the
// SMTP settings from includes/config.php. PHPMailer is vendored locally in
// includes/PHPMailer/ so NO Composer is required under XAMPP.
// ============================================================================

require_once __DIR__ . '/db.php';   // pulls in config.php + db()

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;

/* ---------------------------------------------------------------------------
 * 1) Low-level: send one e-mail over SMTP. Returns ['ok'=>bool,'error'=>string].
 *    Retries once on transient failure. Honours MAIL_TEST_MODE / MAIL_ENABLED.
 * ------------------------------------------------------------------------- */
function sendMailSMTP(string $toEmail, string $toName, string $subject, string $html, string $altText = ''): array
{
    if (!defined('MAIL_ENABLED') || !MAIL_ENABLED) {
        return ['ok' => false, 'error' => 'Email disabled (MAIL_ENABLED=false)', 'skipped' => true];
    }
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid recipient e-mail: ' . $toEmail];
    }
    if (defined('MAIL_TEST_MODE') && MAIL_TEST_MODE) {
        error_log("📧 MAIL_TEST_MODE: would send to {$toEmail} — {$subject}");
        return ['ok' => true, 'test' => true, 'error' => ''];
    }

    $attempts   = 0;
    $maxTries   = 2;            // initial try + 1 retry
    $lastError  = '';

    while ($attempts < $maxTries) {
        $attempts++;
        $mail = new PHPMailer(true);
        $mail->SMTPDebug = 2;
$mail->Debugoutput = function ($str, $level) {
    $logFile = __DIR__ . '/../logs/email_debug.log';

    if (!file_exists(dirname($logFile))) {
        mkdir(dirname($logFile), 0777, true);
    }

    file_put_contents(
        $logFile,
        '[' . date('Y-m-d H:i:s') . "] SMTP DEBUG: {$str}\n",
        FILE_APPEND
    );
};
        try {
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->Port       = SMTP_PORT;
            $mail->SMTPAuth   = SMTP_AUTH;
            if (SMTP_AUTH) {
                $mail->Username = SMTP_USER;
                $mail->Password = SMTP_PASS;
            }
            if (SMTP_SECURE === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif (SMTP_SECURE === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }
            $mail->Timeout    = defined('MAIL_TIMEOUT') ? MAIL_TIMEOUT : 15;
            $mail->SMTPOptions = [
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true
    ]
];
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
            if (defined('MAIL_REPLY_TO') && MAIL_REPLY_TO) {
                $mail->addReplyTo(MAIL_REPLY_TO, MAIL_FROM_NAME);
            }
            $mail->addAddress($toEmail, $toName ?: $toEmail);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = $altText !== '' ? $altText : trim(strip_tags(str_replace(['<br>', '</p>', '</tr>'], "\n", $html)));

            error_log("📧 Attempting SMTP send to: {$toEmail}");
            $mail->send();
            return ['ok' => true, 'attempts' => $attempts, 'error' => ''];
        } catch (MailException $e) {
    $lastError = $mail->ErrorInfo ?: $e->getMessage();

    $debugMessage = "\n================ EMAIL FAILURE ================\n";
    $debugMessage .= "Time: " . date('Y-m-d H:i:s') . "\n";
    $debugMessage .= "Recipient: {$toEmail}\n";
    $debugMessage .= "Subject: {$subject}\n";
    $debugMessage .= "Attempt: {$attempts}\n";
    $debugMessage .= "SMTP Host: " . SMTP_HOST . "\n";
    $debugMessage .= "SMTP Port: " . SMTP_PORT . "\n";
    $debugMessage .= "SMTP Secure: " . SMTP_SECURE . "\n";
    $debugMessage .= "SMTP User: " . SMTP_USER . "\n";
    $debugMessage .= "Error: {$lastError}\n";
    $debugMessage .= "===============================================\n\n";

    error_log($debugMessage);

    $logFile = __DIR__ . '/../logs/email_errors.log';

    if (!file_exists(dirname($logFile))) {
        mkdir(dirname($logFile), 0777, true);
    }

    file_put_contents($logFile, $debugMessage, FILE_APPEND);

    if ($attempts < $maxTries) {
        usleep(400000);
    }
}
    }
    return ['ok' => false, 'attempts' => $attempts, 'error' => $lastError];
}

/* ---------------------------------------------------------------------------
 * 2) Build the responsive HTML e-mail body for an attendance event.
 *    $d keys: school_name, student_name, student_number, subject_name,
 *             subject_code, teacher_name, room, section, date_str, time_str,
 *             status_text, action ('in'|'out'), is_late (bool)
 * ------------------------------------------------------------------------- */
function buildAttendanceEmail(array $d): array
{
    $school   = e($d['school_name']   ?? APP_NAME);
    $name     = e($d['student_name']  ?? '');
    $sid      = e($d['student_number'] ?? '');
    $subject  = e($d['subject_name']  ?? '—');
    $code     = e($d['subject_code']  ?? '');
    $teacher  = e($d['teacher_name']  ?? 'TBA');
    $room     = e($d['room']          ?? 'TBA');
    $section  = e($d['section']       ?? '');
    $dateStr  = e($d['date_str']      ?? '');
    $timeStr  = e($d['time_str']      ?? '');
    $action   = ($d['action'] ?? 'in') === 'out' ? 'out' : 'in';
    $isLate   = !empty($d['is_late']);
    // ABSENT e-mails reuse this same template (requirement: notify like Present/Late).
    $isAbsent = (($d['action'] ?? '') === 'absent')
             || (strtolower((string)($d['status_text'] ?? '')) === 'absent');

    // Status colour + label
    if ($isAbsent) {
        $accent = '#b91c1c'; $bg = '#dc2626'; $scanType = 'ABSENT';
        $statusText = $d['status_text'] ?? 'Absent';
        $headline   = 'was marked ABSENT (no scan within the grace period)';
    } elseif ($action === 'out') {
        $accent = '#475569'; $bg = '#475569'; $scanType = 'TIME OUT';
        $statusText = $d['status_text'] ?? 'Checked Out';
        $headline   = 'has checked OUT';
    } elseif ($isLate) {
        $accent = '#b45309'; $bg = '#d97706'; $scanType = 'TIME IN (LATE)';
        $statusText = $d['status_text'] ?? 'Late';
        $headline   = 'arrived LATE and checked IN';
    } else {
        $accent = '#15803d'; $bg = '#16a34a'; $scanType = 'TIME IN';
        $statusText = $d['status_text'] ?? 'On Time';
        $headline   = 'has checked IN';
    }

    $subjectLine = $subject . ($code ? " ({$code})" : '');

    // Single-column, inline-styled, table-based layout = renders well in Gmail,
    // Outlook, Apple Mail, and on mobile. No external CSS, no images.
    $rows = function (string $label, string $value) {
        return '<tr>'
             . '<td style="padding:9px 0;border-bottom:1px solid #eef1f5;color:#64748b;font-size:13px;width:42%;vertical-align:top;">' . $label . '</td>'
             . '<td style="padding:9px 0;border-bottom:1px solid #eef1f5;color:#0f172a;font-size:14px;font-weight:600;">' . ($value !== '' ? $value : '—') . '</td>'
             . '</tr>';
    };

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 2px 10px rgba(15,23,42,0.06);">
        <!-- Header -->
        <tr><td style="background:{$bg};padding:26px 28px;">
          <div style="color:#ffffff;font-size:13px;letter-spacing:1px;opacity:.85;text-transform:uppercase;">{$school}</div>
          <div style="color:#ffffff;font-size:22px;font-weight:700;margin-top:4px;">Attendance Notification</div>
        </td></tr>
        <!-- Status banner -->
        <tr><td style="padding:22px 28px 6px;">
          <span style="display:inline-block;background:{$accent};color:#fff;font-size:12px;font-weight:700;letter-spacing:.5px;padding:6px 12px;border-radius:999px;">● {$scanType}</span>
          <p style="margin:16px 0 0;font-size:16px;color:#0f172a;line-height:1.5;">
            Good day! This is to inform you that <strong>{$name}</strong> {$headline} for class.
          </p>
        </td></tr>
        <!-- Details -->
        <tr><td style="padding:14px 28px 4px;">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            {$rows('Student Name', $name)}
            {$rows('Student ID', $sid)}
            {$rows('Subject / Course', $subjectLine)}
            {$rows('Section', $section)}
            {$rows('Teacher', $teacher)}
            {$rows('Room', $room)}
            {$rows('Date', $dateStr)}
            {$rows('Time', $timeStr)}
            {$rows('Scan Type', $scanType)}
            {$rows('Status', $statusText)}
          </table>
        </td></tr>
        <!-- Footer -->
        <tr><td style="padding:20px 28px 26px;">
          <p style="margin:0;font-size:12px;color:#94a3b8;line-height:1.6;">
            This is an automated message from the {$school}. Please do not reply directly to this e-mail.
            If you believe this notification was sent in error, contact the school administration.
          </p>
        </td></tr>
      </table>
      <div style="max-width:560px;margin:14px auto 0;color:#94a3b8;font-size:11px;text-align:center;">
        &copy; {$school}
      </div>
    </td></tr>
  </table>
</body></html>
HTML;

    $subjectHdr = "[{$school}] {$name} — {$scanType} ({$subjectLine})";
    $alt = "{$school}\nAttendance Notification\n\n{$name} {$headline}.\n"
         . "Student ID: {$d['student_number']}\nSubject: {$subjectLine}\nSection: {$section}\n"
         . "Teacher: {$teacher}\nRoom: {$room}\nDate: {$dateStr}\nTime: {$timeStr}\n"
         . "Scan Type: {$scanType}\nStatus: {$statusText}\n";

    return ['subject' => $subjectHdr, 'html' => $html, 'alt' => $alt];
}

/* ---------------------------------------------------------------------------
 * 3) High level: notify the parent/guardian about one attendance scan.
 *    Resolves recipient, de-duplicates, sends, and logs to `email_log`.
 *    Returns ['status'=>'sent|failed|skipped|test','recipient'=>..,'error'=>..].
 * ------------------------------------------------------------------------- */
error_log("📧 notifyParentByEmail triggered for student ID: " . ($ctx['student_id'] ?? 'unknown'));
function notifyParentByEmail(array $ctx): array
{
    $pdo       = db();
    $studentId = (int)($ctx['student_id'] ?? 0);
    // Preserve 'absent' (alongside 'in'/'out') so absence e-mails dedup and log
    // under their own action bucket instead of being mistaken for a time-in.
    $rawAction = (string)($ctx['action'] ?? 'in');
    $action    = in_array($rawAction, ['in', 'out', 'absent'], true) ? $rawAction : 'in';
    $logId     = isset($ctx['log_id']) ? (int)$ctx['log_id'] : null;

    // ── Resolve recipient: parent e-mail first, student e-mail as fallback ──
    $parentEmail  = trim((string)($ctx['parent_email']  ?? ''));
    $studentEmail = trim((string)($ctx['student_email'] ?? ''));
    $recipient    = '';
    $recipientType = 'parent';

    if (filter_var($parentEmail, FILTER_VALIDATE_EMAIL)) {
        $recipient = $parentEmail;
        $recipientType = 'parent';
    } elseif (defined('MAIL_FALLBACK_TO_STUDENT') && MAIL_FALLBACK_TO_STUDENT
              && filter_var($studentEmail, FILTER_VALIDATE_EMAIL)) {
        $recipient = $studentEmail;
        $recipientType = 'student';
    }

    if ($recipient === '') { error_log("❌ No valid parent email found for student ID {$studentId}");
        logEmailAttempt($pdo, $studentId, $logId, '', $recipientType, '',
            $action, $ctx['status_text'] ?? '', 'skipped', 'No valid e-mail on file', 0);
        return ['status' => 'skipped', 'recipient' => '', 'error' => 'No valid e-mail on file'];
    }

    // ── De-dup: same student + action + recipient already mailed recently? ──
    $dedup = defined('MAIL_DEDUP_SECONDS') ? (int)MAIL_DEDUP_SECONDS : 120;
    if ($dedup > 0) {
        $chk = $pdo->prepare(
            "SELECT email_id FROM email_log
             WHERE student_id = ? AND action = ? AND recipient = ?
               AND status IN ('sent','test')
               AND sent_at >= (NOW() - INTERVAL ? SECOND)
             ORDER BY email_id DESC LIMIT 1"
        );
        $chk->execute([$studentId, $action, $recipient, $dedup]);
        if ($chk->fetchColumn()) {
            return ['status' => 'skipped', 'recipient' => $recipient, 'error' => 'Duplicate suppressed'];
        }
    }

    // ── Build + send ──
    $built = buildAttendanceEmail($ctx);
    $res   = sendMailSMTP($recipient, $ctx['parent_name'] ?? '', $built['subject'], $built['html'], $built['alt']);

    $status  = $res['ok'] ? (!empty($res['test']) ? 'test' : 'sent') : (!empty($res['skipped']) ? 'skipped' : 'failed');
    $attempts = $res['attempts'] ?? 1;
    $error   = $res['error'] ?? '';

    logEmailAttempt($pdo, $studentId, $logId, $recipient, $recipientType, $built['subject'],
        $action, $ctx['status_text'] ?? '', $status, $error, $attempts);

        error_log("📧 Email result: {$status} | Recipient: {$recipient} | Error: {$error}");
    return ['status' => $status, 'recipient' => $recipient, 'error' => $error];
}

/* ---------------------------------------------------------------------------
 * 4) Insert one row into email_log (best-effort; never throws to the caller).
 * ------------------------------------------------------------------------- */
function logEmailAttempt(PDO $pdo, int $studentId, ?int $logId, string $recipient, string $recipientType,
                         string $subject, string $action, string $statusText,
                         string $status, string $error, int $attempts): void
{
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO email_log
                (student_id, log_id, recipient, recipient_type, subject, action,
                 attendance_status, status, error_message, attempts)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $studentId ?: null,
            $logId ?: null,
            $recipient ?: null,
            $recipientType,
            $subject ?: null,
            $action,
            $statusText ?: null,
            $status,
            $error !== '' ? mb_substr($error, 0, 1000) : null,
            max(1, $attempts),
        ]);
    } catch (Throwable $t) {
        error_log('email_log insert failed: ' . $t->getMessage());
    }
}

/* ---------------------------------------------------------------------------
 * 5) Retry failed e-mails (call from a cron job or an admin "Retry" button).
 *    Re-sends rows whose status='failed' and attempts < $maxAttempts.
 *    Returns ['retried'=>n,'sent'=>n,'still_failed'=>n].
 * ------------------------------------------------------------------------- */
function retryFailedEmails(int $limit = 20, int $maxAttempts = 4): array
{
    $pdo  = db();
    $rows = $pdo->prepare(
        "SELECT el.*, s.full_name, s.student_number, s.email AS student_email
         FROM email_log el
         LEFT JOIN students s ON s.id = el.student_id
         WHERE el.status = 'failed' AND el.attempts < ?
         ORDER BY el.email_id ASC LIMIT ?"
    );
    $rows->bindValue(1, $maxAttempts, PDO::PARAM_INT);
    $rows->bindValue(2, $limit, PDO::PARAM_INT);
    $rows->execute();

    $retried = 0; $sent = 0; $stillFailed = 0;
    foreach ($rows->fetchAll() as $r) {
        if (!filter_var($r['recipient'], FILTER_VALIDATE_EMAIL)) continue;
        $retried++;
        // Minimal rebuild: we only kept the subject, so resend a compact body.
        $built = buildAttendanceEmail([
            'student_name'   => $r['full_name'] ?? '',
            'student_number' => $r['student_number'] ?? '',
            'action'         => $r['action'],
            'status_text'    => $r['attendance_status'] ?? '',
            'is_late'        => ($r['attendance_status'] ?? '') === 'Late',
        ]);
        $res = sendMailSMTP($r['recipient'], '', $r['subject'] ?: $built['subject'], $built['html'], $built['alt']);
        $newAttempts = (int)$r['attempts'] + ($res['attempts'] ?? 1);
        if ($res['ok']) {
            $sent++;
            $pdo->prepare("UPDATE email_log SET status='sent', attempts=?, error_message=NULL WHERE email_id=?")
                ->execute([$newAttempts, $r['email_id']]);
        } else {
            $stillFailed++;
            $pdo->prepare("UPDATE email_log SET attempts=?, error_message=? WHERE email_id=?")
                ->execute([$newAttempts, mb_substr($res['error'] ?? '', 0, 1000), $r['email_id']]);
        }
    }
    return ['retried' => $retried, 'sent' => $sent, 'still_failed' => $stillFailed];
}