<?php
require_once __DIR__ . '/config.php';
requireTeacher();

$pdo = db();
$teacher_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$class_id = isset($_GET['class']) ? (int)$_GET['class'] : 0;

// Get teacher info
$teacher = $pdo->prepare("
    SELECT t.*, u.username, u.email as user_email
    FROM teachers t
    LEFT JOIN users u ON u.id = t.user_id
    WHERE t.id = ?
");
$teacher->execute([$teacher_id]);
$teacher = $teacher->fetch();

if (!$teacher) {
    header('Location: students.php');
    exit;
}

// Get classes taught by this teacher
$classes = $pdo->prepare("
    SELECT DISTINCT c.class_id, c.course_code, sub.subject_name, c.section,
           sched.day, sched.start_time, sched.end_time, sched.room
    FROM classes c
    INNER JOIN subjects sub ON sub.course_code = c.course_code
    LEFT JOIN schedules sched ON sched.class_id = c.class_id
    WHERE c.teacher_id = ?
    ORDER BY sub.subject_name
");
$classes->execute([$teacher_id]);
$classes = $classes->fetchAll();

// Get selected class or use first class
if ($class_id == 0 && !empty($classes)) {
    $class_id = $classes[0]['class_id'];
}

// Get instructor attendance records for selected date (kung may data)
$instructorAttendance = $pdo->prepare("
    SELECT * FROM attendance 
    WHERE teacher_id = ? AND date = ?
    ORDER BY time_in DESC
");
$instructorAttendance->execute([$teacher_id, $date]);
$instructorAttendance = $instructorAttendance->fetchAll();

// Get students attendance for selected class and date
$studentsAttendance = [];
if ($class_id) {
    $stmt = $pdo->prepare("
        SELECT s.id, s.student_number, s.full_name, s.course, s.year_level, s.section,
               a.status, a.time_in, a.time_out, a.attendance_id
        FROM students s
        INNER JOIN student_schedule ss ON ss.student_id = s.id
        LEFT JOIN attendance a ON a.student_id = s.id AND a.class_id = ? AND a.date = ?
        WHERE ss.class_id = ?
        ORDER BY s.full_name
    ");
    $stmt->execute([$class_id, $date, $class_id]);
    $studentsAttendance = $stmt->fetchAll();
}

// Get class info for current class
$classInfo = null;
if ($class_id) {
    $stmt = $pdo->prepare("
        SELECT c.*, sub.subject_name, sched.day, sched.start_time, sched.end_time, sched.room
        FROM classes c
        INNER JOIN subjects sub ON sub.course_code = c.course_code
        LEFT JOIN schedules sched ON sched.class_id = c.class_id
        WHERE c.class_id = ?
    ");
    $stmt->execute([$class_id]);
    $classInfo = $stmt->fetch();
}

$pageTitle = $teacher['name'] . ' - Attendance Record';
$activePage = 'students';
include 'layout.php';
?>

<div class="page-header">
    <div>
        <h2>👨‍🏫 <?php echo htmlspecialchars($teacher['name']); ?></h2>
        <p>
            <?php echo htmlspecialchars($teacher['teacher_number'] ?? ''); ?> | 
            Department: <?php echo htmlspecialchars($teacher['department'] ?? 'N/A'); ?>
        </p>
    </div>
    <div class="page-actions">
        <input type="date" id="datePicker" value="<?php echo $date; ?>" 
               onchange="window.location.href='?id=<?php echo $teacher_id; ?>&class=<?php echo $class_id; ?>&date='+this.value">
        <a href="students.php" class="btn btn-ghost btn-sm">← Back to Students</a>
    </div>
</div>

<!-- Teacher Info Card -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header">
        <h3>📋 Instructor Information</h3>
    </div>
    <div class="card-body" style="padding: 20px;">
        <div style="display: flex; gap: 30px; flex-wrap: wrap;">
            <div><strong>Full Name:</strong><br><?php echo htmlspecialchars($teacher['name']); ?></div>
            <div><strong>Teacher Number:</strong><br><?php echo htmlspecialchars($teacher['teacher_number'] ?? 'N/A'); ?></div>
            <div><strong>Department:</strong><br><?php echo htmlspecialchars($teacher['department'] ?? 'N/A'); ?></div>
            <div><strong>Email:</strong><br><?php echo htmlspecialchars($teacher['email'] ?? $teacher['user_email'] ?? 'N/A'); ?></div>
            <div><strong>Contact:</strong><br><?php echo htmlspecialchars($teacher['contact'] ?? 'N/A'); ?></div>
        </div>
    </div>
</div>

<!-- Two Column Layout -->
<div class="two-column-layout" style="display: flex; gap: 24px;">
    
    <!-- Left: Subjects Taught -->
    <div class="subject-sidebar" style="width: 300px; flex-shrink: 0;">
        <div class="card">
            <div class="card-header">
                <h3>📚 Subjects Taught</h3>
            </div>
            <div class="subject-list">
                <?php foreach ($classes as $cls): ?>
                <a href="?id=<?php echo $teacher_id; ?>&class=<?php echo $cls['class_id']; ?>&date=<?php echo $date; ?>" 
                   class="subject-item <?php echo $class_id == $cls['class_id'] ? 'active' : ''; ?>">
                    <div class="subject-name">
                        <?php echo htmlspecialchars($cls['subject_name']); ?>
                    </div>
                    <div class="subject-details">
                        <small><?php echo htmlspecialchars($cls['course_code']); ?></small>
                        <small><?php echo htmlspecialchars($cls['section']); ?></small>
                    </div>
                    <div class="subject-schedule">
                        <small>
                            <?php echo htmlspecialchars($cls['day'] ?? 'TBA'); ?> • 
                            <?php echo $cls['start_time'] ? date('h:i A', strtotime($cls['start_time'])) . ' - ' . date('h:i A', strtotime($cls['end_time'])) : 'TBA'; ?>
                        </small>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Right: Attendance Records -->
    <div class="student-content" style="flex: 1;">
        
        <!-- Instructor's Own Attendance -->
        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header">
                <h3>👨‍🏫 Instructor's Attendance - <?php echo date('F j, Y', strtotime($date)); ?></h3>
            </div>
            <?php if (empty($instructorAttendance)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📅</div>
                    <h4>No attendance record for this date</h4>
                    <p>The instructor has not logged any attendance on <?php echo date('F j, Y', strtotime($date)); ?>.</p>
                </div>
            <?php else: ?>
            <div class="table-wrap">
                <table class="attendance-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($instructorAttendance as $att): ?>
                        <tr>
                            <td class="mono"><?php echo date('M d, Y', strtotime($att['date'])); ?></td>
                            <td class="mono"><?php echo $att['time_in'] ? date('g:i A', strtotime($att['time_in'])) : '—'; ?></td>
                            <td class="mono"><?php echo $att['time_out'] ? date('g:i A', strtotime($att['time_out'])) : '—'; ?></td>
                            <td><span class="badge <?php echo statusClass($att['status']); ?>"><?php echo $att['status']; ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Students Attendance for Selected Subject -->
        <?php if ($classInfo): ?>
        <div class="card">
            <div class="card-header">
                <div>
                    <h3>📖 <?php echo htmlspecialchars($classInfo['subject_name']); ?></h3>
                    <p>
                        <?php echo htmlspecialchars($classInfo['course_code']); ?> | 
                        Section: <?php echo htmlspecialchars($classInfo['section']); ?> |
                        Schedule: <?php echo $classInfo['day'] ? htmlspecialchars($classInfo['day']) . ' • ' . date('h:i A', strtotime($classInfo['start_time'])) . ' - ' . date('h:i A', strtotime($classInfo['end_time'])) : 'TBA'; ?>
                    </p>
                </div>
                <div class="student-count">
                    Total: <strong><?php echo count($studentsAttendance); ?></strong> students
                </div>
            </div>
            
            <?php if (empty($studentsAttendance)): ?>
                <div class="empty-state">
                    <div class="empty-icon">👥</div>
                    <h4>No students enrolled</h4>
                    <p>This subject has no enrolled students yet.</p>
                </div>
            <?php else: ?>
            <div class="table-wrap">
                <table class="student-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student Number</th>
                            <th>Full Name</th>
                            <th>Course</th>
                            <th>Year Level</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($studentsAttendance as $index => $student): 
                            $statusClass = match($student['status'] ?? '') {
                                'Present' => 'badge-present',
                                'Late' => 'badge-late',
                                'Absent' => 'badge-absent',
                                default => 'badge-default'
                            };
                            $statusText = $student['status'] ?? 'Not Recorded';
                        ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td class="mono"><?php echo htmlspecialchars($student['student_number']); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($student['full_name']); ?></strong>
                            </td
                            <td><?php echo htmlspecialchars($student['course']); ?></td
                            <td><?php echo $student['year_level']; ?></td
                            <td class="mono"><?php echo $student['time_in'] ? date('g:i A', strtotime($student['time_in'])) : '—'; ?></td>
                            <td class="mono"><?php echo $student['time_out'] ? date('g:i A', strtotime($student['time_out'])) : '—'; ?></td>
                            <td>
                                <span class="badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                            </td
                            <td>
                                <a href="student-record.php?id=<?php echo $student['id']; ?>" class="btn btn-sm btn-primary">View Record</a>
                            </td
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.subject-list {
    display: flex;
    flex-direction: column;
}
.subject-item {
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
    text-decoration: none;
    transition: all 0.2s;
    display: block;
}
.subject-item:hover {
    background: var(--bg-subtle);
}
.subject-item.active {
    background: var(--accent-subtle);
    border-left: 3px solid var(--accent);
}
.subject-name {
    font-weight: 700;
    color: var(--dark);
    margin-bottom: 4px;
}
.subject-details {
    display: flex;
    gap: 8px;
    margin-bottom: 4px;
}
.subject-details small {
    font-size: 11px;
    color: var(--muted);
}
.subject-schedule small {
    font-size: 10px;
    color: var(--accent);
}
.student-count {
    background: var(--accent-subtle);
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 13px;
}
.student-table th,
.student-table td,
.attendance-table th,
.attendance-table td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--border);
    text-align: left;
    vertical-align: middle;
}
.student-table th,
.attendance-table th {
    background: var(--dark);
    color: white;
}
.btn-primary {
    background: var(--accent);
    color: white;
}
.btn-primary:hover {
    background: var(--accent-dark);
}
.btn-ghost {
    background: var(--bg-subtle);
    color: var(--slate);
    border: 1px solid var(--border);
}
</style>

<?php include 'layout-end.php'; ?>