<?php
require_once __DIR__ . '/config.php';


// BASE_URL is already defined in config.php – DO NOT redefine it here

$pdo = db();
$teacher_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$class_id = isset($_GET['class']) ? (int)$_GET['class'] : 0;

// Helper function for status badge class
function statusBadgeClass($status) {
    return match($status) {
        'Present' => 'badge-present',
        'Late'    => 'badge-late',
        'Absent'  => 'badge-absent',
        default   => 'badge-default'
    };
}

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

// Get classes taught by this teacher (aggregated schedules – fixes duplication)
$classes = $pdo->prepare("
    SELECT 
        c.class_id, 
        c.course_code, 
        sub.subject_name, 
        c.section,
        GROUP_CONCAT(DISTINCT sched.day ORDER BY FIELD(sched.day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday') SEPARATOR ', ') as days,
        GROUP_CONCAT(DISTINCT CONCAT(TIME_FORMAT(sched.start_time, '%h:%i %p'), ' - ', TIME_FORMAT(sched.end_time, '%h:%i %p')) SEPARATOR ', ') as time_slots,
        GROUP_CONCAT(DISTINCT sched.room SEPARATOR ', ') as rooms
    FROM classes c
    INNER JOIN subjects sub ON sub.course_code = c.course_code
    LEFT JOIN schedules sched ON sched.class_id = c.class_id
    WHERE c.teacher_id = ?
    GROUP BY c.class_id
    ORDER BY sub.subject_name
");
$classes->execute([$teacher_id]);
$classes = $classes->fetchAll();

// Get selected class or use first class
if ($class_id == 0 && !empty($classes)) {
    $class_id = $classes[0]['class_id'];
}

// Get instructor attendance records for selected date
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

// Get class info for current class (aggregated schedules)
$classInfo = null;
if ($class_id) {
    $stmt = $pdo->prepare("
        SELECT 
            c.*, 
            sub.subject_name,
            GROUP_CONCAT(DISTINCT sched.day ORDER BY FIELD(sched.day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday') SEPARATOR ', ') as days,
            GROUP_CONCAT(DISTINCT CONCAT(TIME_FORMAT(sched.start_time, '%h:%i %p'), ' - ', TIME_FORMAT(sched.end_time, '%h:%i %p')) SEPARATOR ', ') as time_slots,
            GROUP_CONCAT(DISTINCT sched.room SEPARATOR ', ') as rooms
        FROM classes c
        INNER JOIN subjects sub ON sub.course_code = c.course_code
        LEFT JOIN schedules sched ON sched.class_id = c.class_id
        WHERE c.class_id = ?
        GROUP BY c.class_id
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
        <h2> <?php echo htmlspecialchars($teacher['name']); ?></h2>
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
        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
            <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($teacher['name']); ?>&background=2a5298&color=fff&rounded=true"
                 style="width: 120px; height: 120px; object-fit: cover; border-radius: 50%; border: 3px solid #2a5298;">
            <div class="info-grid" style="flex: 1;">
                <div><strong>Full Name:</strong><br><?php echo htmlspecialchars($teacher['name']); ?></div>
                <div><strong>Teacher Number:</strong><br><?php echo htmlspecialchars($teacher['teacher_number'] ?? 'N/A'); ?></div>
                <div><strong>Department:</strong><br><?php echo htmlspecialchars($teacher['department'] ?? 'N/A'); ?></div>
                <div><strong>Email:</strong><br><?php echo htmlspecialchars($teacher['email'] ?? $teacher['user_email'] ?? 'N/A'); ?></div>
                <div><strong>Contact:</strong><br><?php echo htmlspecialchars($teacher['contact'] ?? 'N/A'); ?></div>
                <div><strong>Age:</strong><br><?php echo htmlspecialchars($teacher['age'] ?? 'N/A'); ?></div>
                <div><strong>Address:</strong><br><?php echo htmlspecialchars($teacher['address'] ?? 'N/A'); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Two Column Layout -->
<div class="two-column-layout">
    <!-- Left: Subjects Taught -->
    <div class="subject-sidebar">
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
                            <?php 
                            if (!empty($cls['days'])) {
                                echo htmlspecialchars($cls['days']) . ' • ' . htmlspecialchars($cls['time_slots']);
                            } else {
                                echo 'Schedule TBA';
                            }
                            ?>
                        </small>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Right: Attendance Records -->
    <div class="student-content">
        <!-- Instructor's Own Attendance -->
        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header">
                <h3>Instructor's Attendance - <?php echo date('F j, Y', strtotime($date)); ?></h3>
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
                            <td><span class="badge <?php echo statusBadgeClass($att['status']); ?>"><?php echo htmlspecialchars($att['status']); ?></span></td>
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
                        Schedule: <?php 
                            if (!empty($classInfo['days'])) {
                                echo htmlspecialchars($classInfo['days']) . ' • ' . htmlspecialchars($classInfo['time_slots']);
                            } else {
                                echo 'TBA';
                            }
                        ?>
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
                            $statusText = $student['status'] ?? 'Not Recorded';
                        ?>
                        <tr>
                            <td data-label="#"><?php echo $index + 1; ?></td>
                            <td data-label="Student Number" class="mono"><?php echo htmlspecialchars($student['student_number']); ?></td>
                            <td data-label="Full Name">
                                <strong><?php echo htmlspecialchars($student['full_name']); ?></strong>
                            </td>
                            <td data-label="Course"><?php echo htmlspecialchars($student['course']); ?></td>
                            <td data-label="Year Level"><?php echo $student['year_level']; ?></td>
                            <td data-label="Time In" class="mono"><?php echo $student['time_in'] ? date('g:i A', strtotime($student['time_in'])) : '—'; ?></td>
                            <td data-label="Time Out" class="mono"><?php echo $student['time_out'] ? date('g:i A', strtotime($student['time_out'])) : '—'; ?></td>
                            <td data-label="Status">
                                <span class="badge <?php echo statusBadgeClass($student['status'] ?? ''); ?>"><?php echo htmlspecialchars($statusText); ?></span>
                            </td>
                            <td data-label="Action">
                                <a href="student-record.php?id=<?php echo $student['id']; ?>" class="btn btn-sm btn-primary">View Record</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
            <div class="card">
                <div class="empty-state">
                    <div class="empty-icon">📖</div>
                    <h4>No class selected</h4>
                    <p>Please select a subject from the left sidebar.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
/* Layout */
.two-column-layout {
    display: flex;
    gap: 24px;
    flex-wrap: wrap;
}
.subject-sidebar {
    width: 300px;
    flex-shrink: 0;
}
.student-content {
    flex: 1;
    min-width: 0;
}
.info-grid {
    display: flex;
    gap: 30px;
    flex-wrap: wrap;
}
.info-grid > div {
    flex: 1 1 auto;
}
/* Subject list */
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
/* Badges */
.badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
}
.badge-present {
    background: #d4edda;
    color: #155724;
}
.badge-late {
    background: #fff3cd;
    color: #856404;
}
.badge-absent {
    background: #f8d7da;
    color: #721c24;
}
.badge-default {
    background: #e2e3e5;
    color: #383d41;
}
/* Tables */
.table-wrap {
    overflow-x: auto;
}
.student-table, .attendance-table {
    width: 100%;
    border-collapse: collapse;
}
.student-table th, .student-table td,
.attendance-table th, .attendance-table td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--border);
    text-align: left;
    vertical-align: middle;
}
.student-table th, .attendance-table th {
    background: var(--dark);
    color: white;
    font-weight: 600;
}
.mono {
    font-family: 'Courier New', monospace;
    font-size: 0.9em;
}
.student-count {
    background: var(--accent-subtle);
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 13px;
}
.btn {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 4px;
    text-decoration: none;
    font-size: 12px;
    transition: 0.2s;
}
.btn-sm {
    padding: 4px 8px;
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
.empty-state {
    text-align: center;
    padding: 60px 20px;
}
.empty-icon {
    font-size: 64px;
    margin-bottom: 20px;
    opacity: 0.6;
}
.empty-state h4 {
    margin-bottom: 10px;
    color: var(--dark);
}
.empty-state p {
    color: var(--muted);
}
/* Responsive */
@media (max-width: 768px) {
    .two-column-layout {
        flex-direction: column;
    }
    .subject-sidebar {
        width: 100%;
    }
    .info-grid {
        flex-direction: column;
        gap: 15px;
    }
}
@media (max-width: 640px) {
    .student-table thead, .attendance-table thead {
        display: none;
    }
    .student-table tbody tr, .attendance-table tbody tr {
        display: block;
        margin-bottom: 1rem;
        border: 1px solid var(--border);
        border-radius: 8px;
    }
    .student-table td, .attendance-table td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px;
        border-bottom: 1px solid var(--border);
    }
    .student-table td::before, .attendance-table td::before {
        content: attr(data-label);
        font-weight: bold;
        width: 40%;
        color: var(--dark);
    }
    .student-table td:last-child, .attendance-table td:last-child {
        border-bottom: none;
    }
}
</style>

<?php include 'layout-end.php'; ?>