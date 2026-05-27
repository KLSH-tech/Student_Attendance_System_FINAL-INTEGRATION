<?php
ini_set('display_errors', 1);
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
$id = $_GET['id'] ?? 0;
if (!$id) {
    header('Location: schedule.php');
    exit;
}

// Fetch schedule data (teacher_name comes from the class's assigned teacher)
$stmt = $pdo->prepare("
    SELECT s.*, c.class_id, c.section, sub.subject_name, sub.course_code, t.name as teacher_name
    FROM schedules s
    JOIN classes c ON s.class_id = c.class_id
    LEFT JOIN subjects sub ON c.course_code = sub.course_code
    LEFT JOIN teachers t ON c.teacher_id = t.id
    WHERE s.schedule_id = ?
");
$stmt->execute([$id]);
$schedule = $stmt->fetch();
if (!$schedule) {
    header('Location: schedule.php');
    exit;
}

// All classes for dropdown
$classes = $pdo->query("
    SELECT c.class_id, c.section, c.course_code,
           COALESCE(sub.subject_name, 'Unknown Subject') AS subject_name,
           COALESCE(t.name, 'Not assigned') AS teacher_name
    FROM classes c
    LEFT JOIN subjects sub ON c.course_code = sub.course_code
    LEFT JOIN teachers t ON c.teacher_id = t.id
    ORDER BY sub.subject_name, c.section
")->fetchAll();

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $class_id     = intval($_POST['class_id'] ?? 0);
    $teacher_name = trim($_POST['teacher_name'] ?? '');
    $day          = $_POST['day'] ?? '';
    $start_time   = $_POST['start_time'] ?? '';
    $end_time     = $_POST['end_time'] ?? '';
    $room         = trim($_POST['room'] ?? '');

    if (!$class_id || !$teacher_name || !$day || !$start_time || !$end_time || !$room) {
        $error = 'All fields are required.';
    } elseif ($start_time >= $end_time) {
        $error = 'End time must be after start time.';
    } else {
        // Conflict check excluding current schedule
        $conflict = $pdo->prepare("
            SELECT schedule_id FROM schedules
            WHERE day = ? AND room = ? AND schedule_id != ? AND (
                (start_time < ? AND end_time > ?) OR
                (start_time < ? AND end_time > ?) OR
                (start_time >= ? AND end_time <= ?)
            )
        ");
        $conflict->execute([$day, $room, $id, $end_time, $start_time, $end_time, $start_time, $start_time, $end_time]);
        if ($conflict->fetch()) {
            $error = "Schedule conflict: Room $room already occupied on $day during that time.";
        } else {
            try {
                $pdo->beginTransaction();

                // Subject of the selected class (used as the teacher's subject if created)
                $subLookup = $pdo->prepare("
                    SELECT COALESCE(sub.subject_name, '') AS subject_name
                    FROM classes c
                    LEFT JOIN subjects sub ON c.course_code = sub.course_code
                    WHERE c.class_id = ?
                ");
                $subLookup->execute([$class_id]);
                $subjName = $subLookup->fetchColumn() ?: '';

                // Find or create the teacher (by name). Inserts only name + subject;
                // assumes the other teacher columns are nullable / defaulted. If your
                // schema is stricter you'll get a "Database error" below.
                $tq = $pdo->prepare("SELECT id FROM teachers WHERE LOWER(name) = LOWER(?) LIMIT 1");
                $tq->execute([$teacher_name]);
                $trow = $tq->fetch();
                if ($trow) {
                    $teacher_id = $trow['id'];
                } else {
                    $pdo->prepare("INSERT INTO teachers (name, subject) VALUES (?, ?)")
                        ->execute([$teacher_name, $subjName]);
                    $teacher_id = $pdo->lastInsertId();
                }

                // Assign that teacher to the selected class
                $pdo->prepare("UPDATE classes SET teacher_id = ? WHERE class_id = ?")
                    ->execute([$teacher_id, $class_id]);

                // Update the schedule
                $pdo->prepare("UPDATE schedules SET class_id = ?, day = ?, start_time = ?, end_time = ?, room = ? WHERE schedule_id = ?")
                    ->execute([$class_id, $day, $start_time, $end_time, $room, $id]);

                $pdo->commit();
                $success = 'Schedule updated successfully!';

                // Refresh data so the form shows the saved values (incl. new teacher)
                $stmt->execute([$id]);
                $schedule = $stmt->fetch();
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
    <title>Edit Schedule</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<nav class="topnav">
    <a href="index.php" class="logo"><div class="logo-dot">S</div> Schedule</a>
    <a href="schedule.php" class="nav-back">&larr; Schedule</a>
</nav>
<div class="page page-sm">
    <div class="page-title">Edit Schedule</div>

    <?php if ($success): ?>
        <div class="alert ok">✓ <?= htmlspecialchars($success) ?> <a href="schedule.php">Back to schedule &rarr;</a></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert err">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <form method="POST">
                <div class="fg">
                    <div class="f full">
                        <label>Class</label>
                        <select name="class_id" required>
                            <?php foreach ($classes as $c): ?>
                                <option value="<?= $c['class_id'] ?>" <?= ($schedule['class_id'] == $c['class_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['subject_name']) ?> (<?= htmlspecialchars($c['course_code'] ?? 'N/A') ?>) - Section <?= htmlspecialchars($c['section']) ?> | Teacher: <?= htmlspecialchars($c['teacher_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="f full">
                        <label>Teacher Name</label>
                        <input type="text" name="teacher_name" required placeholder="e.g. Juan Dela Cruz" value="<?= htmlspecialchars($schedule['teacher_name'] ?? '') ?>">
                    </div>
                    <div class="f">
                        <label>Day</label>
                        <select name="day" required>
                            <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $d): ?>
                                <option value="<?= $d ?>" <?= ($schedule['day'] == $d) ? 'selected' : '' ?>><?= $d ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="f">
                        <label>Start Time</label>
                        <input type="time" name="start_time" required value="<?= htmlspecialchars($schedule['start_time']) ?>">
                    </div>
                    <div class="f">
                        <label>End Time</label>
                        <input type="time" name="end_time" required value="<?= htmlspecialchars($schedule['end_time']) ?>">
                    </div>
                    <div class="f">
                        <label>Room</label>
                        <input type="text" name="room" required value="<?= htmlspecialchars($schedule['room']) ?>">
                    </div>
                </div>
                <div class="form-foot">
                    <button type="submit" class="btn btn-blue">Update Schedule</button>
                    <a href="schedule.php" class="btn btn-ghost">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>