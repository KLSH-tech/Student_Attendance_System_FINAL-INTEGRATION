<?php
$configPath = __DIR__ . '/../includes/config.php';
if (!file_exists($configPath)) {
    die("config.php not found at: " . $configPath);
}
require_once $configPath;
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    header('Location: ../login.php');
    exit;
}

$pdo = db();

// Roles allowed to manage (add/edit/delete) schedules.
// Adjust this list if your role values differ.
$manageRoles = ['admin', 'super_admin', 'teacher'];
$canManage   = in_array(currentUser()['role'], $manageRoles);

$search = $_GET['search'] ?? '';
$delete_id = $_GET['delete'] ?? 0;

if ($delete_id && $canManage) {
    $stmt = $pdo->prepare("DELETE FROM schedules WHERE schedule_id = ?");
    $stmt->execute([$delete_id]);
    $success = "Schedule deleted.";
}

// Query schedules with class, subject, teacher details
$sql = "
    SELECT s.*, 
           c.class_id, c.section,
           sub.subject_name, sub.course_code,
           t.name as teacher_name
    FROM schedules s
    JOIN classes c ON s.class_id = c.class_id
    LEFT JOIN subjects sub ON c.course_code = sub.course_code
    LEFT JOIN teachers t ON c.teacher_id = t.id
    WHERE 1=1
";
$params = [];
if ($search) {
    $sql .= " AND (sub.subject_name LIKE ? OR sub.course_code LIKE ? OR s.room LIKE ?)";
    $like = "%$search%";
    $params = [$like, $like, $like];
}
$sql .= " ORDER BY FIELD(s.day,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), s.start_time";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$schedules = $stmt->fetchAll();

$grouped = [];
foreach ($schedules as $sch) {
    $grouped[$sch['day']][] = $sch;
}
$daysOrder = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Class Schedule</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<nav class="topnav">
    <a href="index.php" class="logo"><div class="logo-dot">S</div> Schedule</a>
    <a href="index.php" class="nav-back">&larr; Home</a>
</nav>
<div class="page">
    <div class="page-top">
        <div>
            <div class="page-title">Class Schedule</div>
            <div class="page-sub">All schedules</div>
        </div>
        <?php if ($canManage): ?>
            <a href="add_schedule.php" class="btn btn-blue">+ Add Schedule</a>
        <?php endif; ?>
    </div>

    <!-- Search bar -->
    <div class="card" style="margin-bottom:20px;">
        <div class="card-body">
            <form method="GET" class="search-form" style="display:flex;gap:10px;flex-wrap:wrap;">
                <input type="text" name="search" class="search-input" placeholder="Search subject, code or room..." value="<?= htmlspecialchars($search) ?>" style="flex:1;">
                <button type="submit" class="btn btn-blue">Search</button>
                <?php if ($search): ?>
                    <a href="schedule.php" class="btn btn-ghost">Clear</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if (empty($grouped)): ?>
        <div class="card"><div class="empty"><div class="empty-i">📅</div><p>No schedules found.</p></div></div>
    <?php else: ?>
        <div class="tbl-wrap">
            <table class="schedule-table">
                <thead>
                    <tr><th>Subject</th><th>Section</th><th>Instructor</th><th>Day</th><th>Time</th><th>Room</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($daysOrder as $day): ?>
                    <?php if (empty($grouped[$day])) continue; ?>
                    <tr class="day-row"><th colspan="7"><?= strtoupper($day) ?> · <?= count($grouped[$day]) ?> class(es)</th></tr>
                    <?php foreach ($grouped[$day] as $s): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($s['subject_name'] ?? 'No Subject') ?></strong><br><small><?= htmlspecialchars($s['course_code'] ?? '') ?></small></td>
                            <td><?= htmlspecialchars($s['section']) ?></td>
                            <td><?= htmlspecialchars($s['teacher_name'] ?? 'Not assigned') ?></td>
                            <td><span class="badge b-<?= strtolower($day) ?>"><?= $day ?></span></td>
                            <td><?= date('h:i A', strtotime($s['start_time'])) ?> – <?= date('h:i A', strtotime($s['end_time'])) ?></td>
                            <td><?= htmlspecialchars($s['room']) ?></td>
                            <td class="acts">
                                <?php if ($canManage): ?>
                                    <a href="edit_schedule.php?id=<?= $s['schedule_id'] ?>" class="bi bi-edit" title="Edit">✏️</a>
                                    <a href="#" onclick="openConfirmModal('schedule.php?delete=<?= $s['schedule_id'] ?>'); return false;" class="bi bi-del" title="Delete">🗑️</a>
                                <?php else: ?>
                                    <span class="c3">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div id="confirm-modal" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <h3>Delete Schedule</h3>
        <p>Are you sure you want to delete this schedule?</p>
        <div class="modal-actions">
            <button onclick="closeConfirmModal()" class="btn btn-ghost">Cancel</button>
            <a id="confirm-delete-btn" href="#" class="btn btn-red">Delete</a>
        </div>
    </div>
</div>

<script>
function openConfirmModal(url) {
    document.getElementById('confirm-delete-btn').href = url;
    document.getElementById('confirm-modal').style.display = 'flex';
}
function closeConfirmModal() {
    document.getElementById('confirm-modal').style.display = 'none';
}
<?php if (isset($success)): ?>
    alert('<?= addslashes($success) ?>');
<?php endif; ?>
</script>
</body>
</html>