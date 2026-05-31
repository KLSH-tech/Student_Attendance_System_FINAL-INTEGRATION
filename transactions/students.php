<?php
require_once __DIR__ . '/config.php';
requireTeacher();   // guard: only signed-in teachers/admins past this point


$pdo = db();

// Get all subjects/classes with their teachers
$subjects = $pdo->query("
    SELECT DISTINCT 
        c.class_id,
        c.course_code,
        sub.subject_name,
        c.section,
        GROUP_CONCAT(DISTINCT sched.day ORDER BY FIELD(sched.day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday') SEPARATOR ', ') as days,
        GROUP_CONCAT(DISTINCT CONCAT(TIME_FORMAT(sched.start_time, '%h:%i %p'), ' - ', TIME_FORMAT(sched.end_time, '%h:%i %p')) SEPARATOR ', ') as time_slots,
        GROUP_CONCAT(DISTINCT sched.room SEPARATOR ', ') as rooms,
        t.name as instructor_name,
        t.id as teacher_id,
        t.teacher_number
    FROM classes c
    INNER JOIN subjects sub ON sub.course_code = c.course_code
    LEFT JOIN schedules sched ON sched.class_id = c.class_id
    LEFT JOIN teachers t ON t.id = c.teacher_id
    GROUP BY c.class_id
    ORDER BY sub.subject_name
")->fetchAll();

// Get selected subject filter
$selectedClass = isset($_GET['class']) ? (int)$_GET['class'] : 0;

// Get students for selected subject
$studentsBySubject = [];
$classInfo = null;

if ($selectedClass) {
    // Get class info with teacher
    $stmt = $pdo->prepare("
        SELECT c.*, sub.subject_name, 
               t.name as instructor_name, 
               t.id as teacher_id,
               t.teacher_number,
               t.department,
               t.contact as teacher_contact
        FROM classes c
        INNER JOIN subjects sub ON sub.course_code = c.course_code
        LEFT JOIN teachers t ON t.id = c.teacher_id
        WHERE c.class_id = ?
    ");
    $stmt->execute([$selectedClass]);
    $classInfo = $stmt->fetch();
    
    // Get students enrolled in this class with today's status
    $stmt = $pdo->prepare("
        SELECT DISTINCT 
            s.id, 
            s.student_number, 
            s.full_name, 
            s.course, 
            s.year_level, 
            s.section,
            s.email,
            (SELECT status FROM attendance WHERE student_id = s.id AND class_id = ? AND date = CURDATE() LIMIT 1) as today_status
        FROM students s
        INNER JOIN student_schedule ss ON ss.student_id = s.id
        WHERE ss.class_id = ?
        ORDER BY s.full_name
    ");
    $stmt->execute([$selectedClass, $selectedClass]);
    $studentsBySubject = $stmt->fetchAll();
}

$pageTitle = 'Students by Subject';
$activePage = 'students';
include 'layout.php';
?>

<div class="page-header">
    <div>
        <h2>Students by Subject</h2>
        <p>Select a subject to view enrolled students</p>
    </div>
</div>

<div class="two-column-layout">
    <!-- Left Sidebar: Subject List -->
    <div class="subject-sidebar">
        <div class="card">
            <div class="card-header">
                <h3>📚 Subjects</h3>
            </div>
            <div class="subject-list">
                <?php foreach ($subjects as $subject): ?>
                <a href="?class=<?php echo $subject['class_id']; ?>" 
                   class="subject-item <?php echo $selectedClass == $subject['class_id'] ? 'active' : ''; ?>">
                    <div class="subject-name">
                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                    </div>
                    <div class="subject-details">
                        <small><?php echo htmlspecialchars($subject['course_code']); ?></small>
                        <small><?php echo htmlspecialchars($subject['section']); ?></small>
                    </div>
                    <div class="subject-schedule">
                        <small>
                            <?php 
                            if ($subject['days']) {
                                echo htmlspecialchars($subject['days']) . ' • ' . htmlspecialchars($subject['time_slots']);
                            } else {
                                echo 'Schedule TBA';
                            }
                            ?>
                        </small>
                    </div>
                    <div class="subject-teacher">
                        <small>👨‍🏫 <strong>Instructor:</strong> 
                            <?php 
                            // FIX: Show "Not assigned" if instructor name is empty or null
                            $instructorName = trim($subject['instructor_name'] ?? '');
                            if (!empty($subject['teacher_id']) && $instructorName !== ''): ?>
                                <a href="instructor_record.php?id=<?php echo $subject['teacher_id']; ?>" 
                                   style="color: var(--accent); text-decoration: none;">
                                    <?php echo htmlspecialchars($instructorName); ?>
                                </a>
                            <?php else: ?>
                                <span style="color: var(--muted);">Not assigned</span>
                            <?php endif; ?>
                        </small>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Right Content: Student List -->
    <div class="student-content">
        <?php if ($selectedClass && $classInfo): ?>
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3><?php echo htmlspecialchars($classInfo['subject_name']); ?></h3>
                        <p>
                            <?php echo htmlspecialchars($classInfo['course_code']); ?> | 
                            Section: <?php echo htmlspecialchars($classInfo['section']); ?>
                        </p>
                        <p style="margin-top: 5px; color: var(--accent);">
                            👨‍🏫 <strong>Instructor:</strong> 
                            <?php 
                            $instructorName = trim($classInfo['instructor_name'] ?? '');
                            if (!empty($classInfo['teacher_id']) && $instructorName !== ''): ?>
                                <a href="instructor_record.php?id=<?php echo $classInfo['teacher_id']; ?>" 
                                   style="color: var(--accent); text-decoration: none;">
                                    <?php echo htmlspecialchars($instructorName); ?>
                                </a>
                            <?php else: ?>
                                <span style="color: var(--muted);">Not assigned</span>
                            <?php endif; ?>
                            <?php if ($classInfo['teacher_number']): ?>
                                (<?php echo htmlspecialchars($classInfo['teacher_number']); ?>)
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="student-count">
                        Total: <strong><?php echo count($studentsBySubject); ?></strong> students
                    </div>
                </div>
                
                <?php if (empty($studentsBySubject)): ?>
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
                                <th>Today's Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($studentsBySubject as $index => $student): 
                                $statusClass = match($student['today_status'] ?? '') {
                                    'Present' => 'badge-present',
                                    'Late' => 'badge-late',
                                    'Absent' => 'badge-absent',
                                    default => 'badge-default'
                                };
                                $statusText = $student['today_status'] ?? 'Not Recorded';
                            ?>
                            <tr>
                                <td data-label="#"><?php echo $index + 1; ?></td>
                                <td data-label="Student Number" class="mono"><?php echo htmlspecialchars($student['student_number']); ?></td>
                                <td data-label="Full Name">
                                    <strong><?php echo htmlspecialchars($student['full_name']); ?></strong>
                                    <?php if ($student['email']): ?>
                                        <br><small><?php echo htmlspecialchars($student['email']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Course"><?php echo htmlspecialchars($student['course']); ?></td>
                                <td data-label="Year Level"><?php echo $student['year_level']; ?></td>
                                <td data-label="Today's Status">
                                    <span class="badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
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
                    <h4>Select a Subject</h4>
                    <p>Choose a subject from the left sidebar to view enrolled students.</p>
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
    width: 320px;
    flex-shrink: 0;
}
.student-content {
    flex: 1;
    min-width: 0;
}
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
.subject-teacher {
    margin-top: 6px;
}
.subject-teacher small {
    font-size: 10px;
    color: var(--success);
}
.subject-teacher a:hover {
    text-decoration: underline !important;
}
.student-count {
    background: var(--accent-subtle);
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 13px;
}
.student-table th,
.student-table td {
    padding: 12px 15px;
    text-align: left;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}
.student-table th {
    background: var(--dark);
    color: white;
    font-weight: 600;
    font-size: 0.9rem;
}
.student-table tbody tr:hover {
    background: var(--bg-subtle);
}
.mono {
    font-family: 'Courier New', monospace;
    font-size: 0.9em;
}
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
    .table-wrap {
        overflow-x: auto;
    }
    .student-table th,
    .student-table td {
        white-space: nowrap;
    }
}
@media (max-width: 480px) {
    .student-table thead {
        display: none;
    }
    .student-table tbody tr {
        display: block;
        margin-bottom: 1rem;
        border: 1px solid var(--border);
        border-radius: 8px;
    }
    .student-table td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px;
        border-bottom: 1px solid var(--border);
        white-space: normal;
    }
    .student-table td::before {
        content: attr(data-label);
        font-weight: bold;
        width: 40%;
        color: var(--dark);
    }
    .student-table td:last-child {
        border-bottom: none;
    }
}
</style>

<?php include 'layout-end.php'; ?>