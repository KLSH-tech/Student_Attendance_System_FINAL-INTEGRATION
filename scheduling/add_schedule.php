<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$configPath = __DIR__ . '/../includes/config.php';
if (!file_exists($configPath)) {
    die("config.php not found at: " . $configPath);
}
require_once $configPath;
require_once __DIR__ . '/../includes/auth.php';

// Roles allowed to manage schedules. Keep this in sync with schedule.php.
$manageRoles = ['admin', 'super_admin', 'teacher'];

if (!isLoggedIn() || !in_array(currentUser()['role'], $manageRoles)) {
    header('Location: schedule.php');
    exit;
}

$pdo = db();
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject_name = trim($_POST['subject_name'] ?? '');
    $course_code  = trim($_POST['course_code'] ?? '');
    $section      = trim($_POST['section'] ?? '');
    $teacher_name = trim($_POST['teacher_name'] ?? '');
    $day          = $_POST['day'] ?? '';
    $start_time   = $_POST['start_time'] ?? '';
    $end_time     = $_POST['end_time'] ?? '';
    $room         = trim($_POST['room'] ?? '');

    if (!$subject_name) {
        $error = 'Please enter a subject name.';
    } elseif (!$course_code) {
        $error = 'Please enter a course code.';
    } elseif (!$section) {
        $error = 'Please enter a section.';
    } elseif (!$teacher_name) {
        $error = 'Please enter the teacher name.';
    } elseif (!$day) {
        $error = 'Please select a day.';
    } elseif (!$start_time || !$end_time) {
        $error = 'Please provide start and end times.';
    } elseif (!$room) {
        $error = 'Please provide a room.';
    } elseif ($start_time >= $end_time) {
        $error = 'End time must be after start time.';
    } else {
        // Room/time conflict check (independent of subject)
        $stmt = $pdo->prepare("
            SELECT schedule_id FROM schedules
            WHERE day = ? AND room = ? AND (
                (start_time < ? AND end_time > ?) OR
                (start_time < ? AND end_time > ?) OR
                (start_time >= ? AND end_time <= ?)
            )
        ");
        $stmt->execute([$day, $room, $end_time, $start_time, $end_time, $start_time, $start_time, $end_time]);

        if ($stmt->fetch()) {
            $error = "Schedule conflict: Room $room already occupied on $day during that time.";
        } else {
            try {
                $pdo->beginTransaction();

                // 1. Find or create the subject (keyed by course_code).
                $sub = $pdo->prepare("SELECT course_code FROM subjects WHERE course_code = ?");
                $sub->execute([$course_code]);
                if (!$sub->fetch()) {
                    $pdo->prepare("INSERT INTO subjects (course_code, subject_name) VALUES (?, ?)")
                        ->execute([$course_code, $subject_name]);
                }

                // 2. Find or create the teacher (by name). NOTE: this inserts only
                //    name + subject; it assumes the other teacher columns are nullable
                //    or have defaults. If your schema is stricter, this will throw a
                //    "Database error" below and you can tell me which columns to fill.
                $tq = $pdo->prepare("SELECT id FROM teachers WHERE LOWER(name) = LOWER(?) LIMIT 1");
                $tq->execute([$teacher_name]);
                $trow = $tq->fetch();
                if ($trow) {
                    $teacher_id = $trow['id'];
                } else {
                    $pdo->prepare("INSERT INTO teachers (name, subject) VALUES (?, ?)")
                        ->execute([$teacher_name, $subject_name]);
                    $teacher_id = $pdo->lastInsertId();
                }

                // 3. Find or create the class (course_code + section) and make sure
                //    the chosen teacher is assigned to it.
                $cls = $pdo->prepare("SELECT class_id FROM classes WHERE course_code = ? AND section = ?");
                $cls->execute([$course_code, $section]);
                $row = $cls->fetch();
                if ($row) {
                    $class_id = $row['class_id'];
                    $pdo->prepare("UPDATE classes SET teacher_id = ? WHERE class_id = ?")
                        ->execute([$teacher_id, $class_id]);
                } else {
                    $pdo->prepare("INSERT INTO classes (course_code, section, teacher_id) VALUES (?, ?, ?)")
                        ->execute([$course_code, $section, $teacher_id]);
                    $class_id = $pdo->lastInsertId();
                }

                // 4. Insert the schedule.
                $pdo->prepare("INSERT INTO schedules (class_id, day, start_time, end_time, room) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$class_id, $day, $start_time, $end_time, $room]);

                $pdo->commit();
                $success = 'Schedule added successfully!';
                $_POST = [];
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Schedule</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<nav class="topnav">
    <a href="index.php" class="logo"><div class="logo-dot">S</div> Schedule</a>
    <a href="schedule.php" class="nav-back">&larr; Schedule</a>
</nav>
<div class="page page-sm">
    <div class="page-title">Add Class Schedule</div>
    <div class="page-sub">Type the class details, then assign a time slot and room</div>

    <?php if ($success): ?>
        <div class="alert ok">✓ <?= htmlspecialchars($success) ?> <a href="schedule.php">View all schedules &rarr;</a></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert err">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <form method="POST">
                <div class="fg">
                    <div class="f full">
                        <label>Subject Name *</label>
                        <input type="text" name="subject_name" required placeholder="e.g. Introduction to Programming" value="<?= htmlspecialchars($_POST['subject_name'] ?? '') ?>">
                    </div>
                    <div class="f">
                        <label>Course Code *</label>
                        <input type="text" name="course_code" required placeholder="e.g. CS101" value="<?= htmlspecialchars($_POST['course_code'] ?? '') ?>">
                    </div>
                    <div class="f">
                        <label>Section *</label>
                        <input type="text" name="section" required placeholder="e.g. BSIT-1A" value="<?= htmlspecialchars($_POST['section'] ?? '') ?>">
                    </div>
                    <div class="f full">
                        <label>Teacher Name *</label>
                        <input type="text" name="teacher_name" required placeholder="e.g. Juan Dela Cruz" value="<?= htmlspecialchars($_POST['teacher_name'] ?? '') ?>">
                    </div>
                    <div class="f">
                        <label>Day *</label>
                        <select name="day" required>
                            <option value="">-- Select Day --</option>
                            <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $d): ?>
                                <option value="<?= $d ?>" <?= (isset($_POST['day']) && $_POST['day'] == $d) ? 'selected' : '' ?>><?= $d ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="f">
                        <label>Start Time *</label>
                        <input type="time" name="start_time" required value="<?= htmlspecialchars($_POST['start_time'] ?? '') ?>">
                    </div>
                    <div class="f">
                        <label>End Time *</label>
                        <input type="time" name="end_time" required value="<?= htmlspecialchars($_POST['end_time'] ?? '') ?>">
                    </div>
                    <div class="f">
                        <label>Room *</label>
                        <input type="text" name="room" required placeholder="e.g. COM LAB A" value="<?= htmlspecialchars($_POST['room'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-foot">
                    <button type="submit" class="btn btn-blue">➕ Add Schedule</button>
                    <a href="schedule.php" class="btn btn-ghost">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>