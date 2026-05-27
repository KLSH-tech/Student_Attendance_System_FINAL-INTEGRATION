<?php
// setup.php — Teacher Portal standalone setup
// Run once to create tables and seed demo data.
require_once __DIR__ . '/config.php';
   // ← destructive (CREATE TABLE/seed): never allow anonymous URL access
$log = [];

function ok(string $m)  { global $log; $log[] = ['ok',  $m]; }
function err(string $m) { global $log; $log[] = ['err', $m]; }
function info(string $m){ global $log; $log[] = ['info',$m]; }

try {
    $pdo = db();
    ok('Connected to database: ' . DB_NAME);

    // ── teachers ──────────────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS teachers (
        teacher_id     INT AUTO_INCREMENT PRIMARY KEY,
        full_name      VARCHAR(100) NOT NULL,
        username       VARCHAR(50)  NOT NULL UNIQUE,
        password       VARCHAR(255) NOT NULL,
        teacher_number VARCHAR(50),
        department     VARCHAR(100),
        role           VARCHAR(50)  NOT NULL DEFAULT 'teacher',
        is_active      TINYINT(1)   NOT NULL DEFAULT 1,
        last_login     TIMESTAMP    NULL,
        created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    )");
    ok('Table: teachers ✓');

    // ── students ──────────────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS students (
        student_id     INT AUTO_INCREMENT PRIMARY KEY,
        user_id        INT NOT NULL UNIQUE,
        full_name      VARCHAR(100) NOT NULL,
        student_number VARCHAR(50)  NOT NULL UNIQUE,
        username       VARCHAR(50)  UNIQUE,
        course         VARCHAR(100),
        year_level     INT DEFAULT 1,
        section        VARCHAR(20),
        email          VARCHAR(100),
        password       VARCHAR(255),
        created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    ok('Table: students ✓');

    // ── attendance ────────────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance (
        attendance_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id       INT NOT NULL,
        date          DATE NOT NULL,
        time          TIME NOT NULL,
        status        ENUM('Present','Late','Absent') NOT NULL DEFAULT 'Present',
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_date (user_id, date),
        KEY idx_date   (date),
        KEY idx_status (status)
    )");
    ok('Table: attendance ✓');

    // ── dispute_requests ──────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS dispute_requests (
        dispute_id       INT AUTO_INCREMENT PRIMARY KEY,
        attendance_id    INT NOT NULL,
        student_id       INT NOT NULL,
        reason           TEXT NOT NULL,
        status           ENUM('Pending','Under Review','Approved','Rejected') NOT NULL DEFAULT 'Pending',
        reviewed_by      INT NULL,
        resolution_notes TEXT NULL,
        submitted_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved_at      DATETIME NULL,
        KEY idx_status (status)
    )");
    ok('Table: dispute_requests ✓');

    // ── Seed teachers ─────────────────────────────────────────────────────────
    $tc = (int)$pdo->query("SELECT COUNT(*) FROM teachers")->fetchColumn();
    if ($tc === 0) {
        $hp = password_hash('teacher123', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO teachers(full_name,username,password,teacher_number,department,role) VALUES(?,?,?,?,?,?)")
            ->execute(['Maria Clara','maria.clara',$hp,'TCH-2024-001','Computer Science','teacher']);
        $pdo->prepare("INSERT INTO teachers(full_name,username,password,teacher_number,department,role) VALUES(?,?,?,?,?,?)")
            ->execute(['Jose Santos','jose.santos',$hp,'TCH-2024-002','Information Technology','teacher']);
        $pdo->prepare("INSERT INTO teachers(full_name,username,password,teacher_number,department,role) VALUES(?,?,?,?,?,?)")
            ->execute(['Admin User','admin',$hp,'ADM-2024-001','Administration','admin']);
        ok('Teachers seeded (password: teacher123)');
    } else {
        info("Teachers already exist ($tc rows) — skipped");
    }

    // ── Seed students ─────────────────────────────────────────────────────────
    $sc = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
    if ($sc === 0) {
        $sp = password_hash('student123', PASSWORD_DEFAULT);
        $st = $pdo->prepare("INSERT INTO students(user_id,full_name,student_number,username,course,year_level,section,email,password) VALUES(?,?,?,?,?,?,?,?,?)");
        foreach ([
            [1001,'Juan Dela Cruz',  'STU-2024-001','juan',  'BS Computer Science',      3,'CS-3A', 'juan@student.edu'],
            [1002,'Maria Santos',    'STU-2024-002','maria', 'BS Information Technology',3,'IT-3A', 'maria@student.edu'],
            [1003,'Pedro Reyes',     'STU-2024-003','pedro', 'BS Computer Science',      2,'CS-2A', 'pedro@student.edu'],
            [1004,'Ana Gonzales',    'STU-2024-004','ana',   'BS Computer Engineering',  4,'CpE-4A','ana@student.edu'],
            [1005,'Jose Rizal',      'STU-2024-005','jose',  'BS Information Systems',   3,'IS-3A', 'jose@student.edu'],
            [1006,'Luisa Flores',    'STU-2024-006','luisa', 'BS Information Technology',2,'IT-2A', 'luisa@student.edu'],
            [1007,'Ramon Garcia',    'STU-2024-007','ramon', 'BS Computer Science',      1,'CS-1A', 'ramon@student.edu'],
            [1008,'Elena Cruz',      'STU-2024-008','elena', 'BS Computer Engineering',  3,'CpE-3A','elena@student.edu'],
            [1009,'Daniel Pongyan',  'STU-2024-009','daniel','BS Information Technology',3,'IT-3A', 'daniel@student.edu'],
            [1010,'Mark Galut',      'STU-2024-010','mark',  'BS Information Technology',3,'IT-3A', 'mark@student.edu'],
        ] as $row) {
            $st->execute([...$row, $sp]);
        }
        ok('10 students seeded (password: student123)');
    } else {
        info("Students already exist ($sc rows) — skipped");
    }

    // ── Seed attendance ───────────────────────────────────────────────────────
    $ac = (int)$pdo->query("SELECT COUNT(*) FROM attendance")->fetchColumn();
    if ($ac === 0) {
        $pdo->exec("INSERT IGNORE INTO attendance(user_id,date,time,status) VALUES
            (1001,CURDATE(),'07:45:00','Present'),(1001,CURDATE()-INTERVAL 1 DAY,'08:10:00','Late'),
            (1001,CURDATE()-INTERVAL 2 DAY,'07:55:00','Present'),(1001,CURDATE()-INTERVAL 3 DAY,'08:25:00','Absent'),
            (1001,CURDATE()-INTERVAL 4 DAY,'07:50:00','Present'),(1001,CURDATE()-INTERVAL 7 DAY,'07:48:00','Present'),
            (1002,CURDATE(),'08:05:00','Late'),(1002,CURDATE()-INTERVAL 1 DAY,'07:48:00','Present'),
            (1002,CURDATE()-INTERVAL 2 DAY,'08:20:00','Absent'),(1002,CURDATE()-INTERVAL 3 DAY,'07:52:00','Present'),
            (1003,CURDATE(),'08:22:00','Absent'),(1003,CURDATE()-INTERVAL 1 DAY,'07:40:00','Present'),
            (1003,CURDATE()-INTERVAL 2 DAY,'08:18:00','Late'),(1003,CURDATE()-INTERVAL 3 DAY,'08:30:00','Absent'),
            (1004,CURDATE(),'07:58:00','Present'),(1004,CURDATE()-INTERVAL 1 DAY,'07:42:00','Present'),
            (1004,CURDATE()-INTERVAL 2 DAY,'07:51:00','Present'),(1004,CURDATE()-INTERVAL 3 DAY,'07:45:00','Present'),
            (1005,CURDATE(),'08:14:00','Late'),(1005,CURDATE()-INTERVAL 1 DAY,'07:53:00','Present'),
            (1005,CURDATE()-INTERVAL 2 DAY,'08:28:00','Absent'),(1006,CURDATE(),'07:52:00','Present'),
            (1006,CURDATE()-INTERVAL 1 DAY,'08:11:00','Late'),(1007,CURDATE(),'08:22:00','Absent'),
            (1007,CURDATE()-INTERVAL 1 DAY,'07:49:00','Present'),(1008,CURDATE(),'07:41:00','Present'),
            (1008,CURDATE()-INTERVAL 1 DAY,'07:55:00','Present'),(1009,CURDATE(),'08:17:00','Late'),
            (1009,CURDATE()-INTERVAL 1 DAY,'07:52:00','Present'),(1010,CURDATE(),'07:47:00','Present')");
        ok('Attendance records seeded');
    } else {
        info("Attendance already exists ($ac rows) — skipped");
    }

    // ── Seed disputes ─────────────────────────────────────────────────────────
    $dc = (int)$pdo->query("SELECT COUNT(*) FROM dispute_requests")->fetchColumn();
    if ($dc === 0) {
        $ab = $pdo->query("SELECT attendance_id FROM attendance WHERE status='Absent' LIMIT 2")->fetchAll();
        if (!empty($ab)) {
            $pdo->prepare("INSERT INTO dispute_requests(attendance_id,student_id,reason,status,submitted_at) VALUES(?,?,?,?,NOW())")
                ->execute([$ab[0]['attendance_id'],1001,'I was present but had a connectivity issue marking attendance.','Pending']);
            if (isset($ab[1])) {
                $pdo->prepare("INSERT INTO dispute_requests(attendance_id,student_id,reason,status,submitted_at) VALUES(?,?,?,?,NOW())")
                    ->execute([$ab[1]['attendance_id'],1003,'I arrived before the late cutoff but the window appeared closed.','Pending']);
            }
            ok('Sample disputes seeded');
        }
    } else {
        info("Disputes already exist ($dc rows) — skipped");
    }

    ok('✅ Setup complete — Teacher Portal is ready!');

} catch (Throwable $e) {
    err('Fatal error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Setup — Teacher Portal</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=JetBrains+Mono:wght@400&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;background:#f0f9ff;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
.wrap{width:100%;max-width:640px;}
.header{background:linear-gradient(135deg,#0c4a6e,#0369a1);color:white;padding:28px 32px;border-radius:16px 16px 0 0;}
.header h1{font-size:22px;font-weight:800;margin-bottom:4px;}
.header p{opacity:.8;font-size:13px;}
.body{background:white;padding:28px 32px;border-radius:0 0 16px 16px;box-shadow:0 20px 60px rgba(3,105,161,.15);}
.item{display:flex;align-items:flex-start;gap:12px;padding:11px 16px;border-radius:9px;margin-bottom:8px;font-size:13px;font-family:'JetBrains Mono',monospace;}
.ok  {background:#d1fae5;color:#065f46;}
.err {background:#fee2e2;color:#991b1b;font-weight:700;}
.info{background:#e0f2fe;color:#0c4a6e;}
.icon{flex-shrink:0;font-size:15px;}
.actions{display:flex;gap:12px;margin-top:24px;flex-wrap:wrap;}
.btn{padding:11px 22px;border-radius:9px;font-size:14px;font-weight:700;text-decoration:none;display:inline-block;font-family:'Inter',sans-serif;}
.btn-primary{background:#0369a1;color:white;}
.btn-ghost{background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;}
.creds{margin-top:20px;background:#fef3c7;border-radius:10px;padding:16px 18px;font-size:13px;color:#78350f;}
.creds strong{display:block;margin-bottom:6px;font-size:14px;}
code{background:#fff;padding:2px 8px;border-radius:5px;font-family:'JetBrains Mono',monospace;font-size:12px;}
</style>
</head>
<body>
<div class="wrap">
    <div class="header">
        <h1>Teacher Portal Setup</h1>
        <p>Group 7 · Transaction / Request Management Subsystem · Southland College</p>
    </div>
    <div class="body">
        <?php foreach ($log as [$type, $msg]): ?>
            <div class="item <?php echo $type; ?>">
                <span class="icon"><?php echo $type==='ok'?'✓':($type==='err'?'✗':'ℹ'); ?></span>
                <span><?php echo htmlspecialchars($msg); ?></span>
            </div>
        <?php endforeach; ?>

        <div class="actions">
            <a href="index.php"     class="btn btn-primary">Go to Login</a>
            <a href="dashboard.php" class="btn btn-ghost">Dashboard</a>
        </div>

        <div class="creds">
            <strong>Teacher Login Credentials</strong>
            Username: <code>maria.clara</code> &nbsp; Password: <code>teacher123</code><br><br>
            Also available: <code>jose.santos</code> / <code>admin</code> (same password)<br><br>
            <em>Students cannot access this portal.</em>
        </div>
    </div>
</div>
</body>
</html>
