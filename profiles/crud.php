<?php
// ============================================================================
// profiles/crud.php — JSON API for Records / Profile Management
// ============================================================================
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$user = currentUser();
$role = $user['role'] ?? '';
$isAdmin = in_array($role, ['admin', 'super_admin'], true);

$pdo = db();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Define actions that require admin privileges (write operations)
$adminOnly = [
    'add_student', 'update_profile', 'delete_student',
    'add_teacher', 'update_teacher', 'delete_teacher'
];
if (in_array($action, $adminOnly) && !$isAdmin) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Admin rights required for this action']);
    exit;
}

try {
    switch ($action) {
        // ========== STUDENT: get students grouped by subject ==========
        case 'get_students_by_subject':
            $subject_code = trim($_GET['subject'] ?? 'all');
            $search = trim($_GET['search'] ?? '');
            $day = $_GET['day'] ?? '';

            $sql = "SELECT s.id AS student_db_id, s.student_number AS student_id, s.full_name, s.gender,
                           s.course, s.year_level, s.contact, s.email,
                           p.parent_name, p.contact_number AS parent_contact,
                           c.class_id, c.section, sub.course_code AS subject_code, sub.subject_name,
                           sch.schedule_id, sch.day, 
                           CONCAT(TIME_FORMAT(sch.start_time,'%l:%i %p'),' - ',TIME_FORMAT(sch.end_time,'%l:%i %p')) AS time,
                           sch.room,
                           COALESCE(u.full_name, 'Not assigned') AS instructor
                    FROM student_schedule ss
                    JOIN students s ON ss.student_id = s.id
                    LEFT JOIN parents p ON p.student_id = s.id
                    JOIN classes c ON ss.class_id = c.class_id
                    JOIN subjects sub ON c.course_code = sub.course_code
                    JOIN schedules sch ON ss.schedule_id = sch.schedule_id
                    LEFT JOIN teachers t ON c.teacher_id = t.id
                    LEFT JOIN users u ON t.user_id = u.id
                    WHERE 1=1";

            $params = [];
            if ($subject_code !== 'all') {
                $sql .= " AND UPPER(TRIM(sub.course_code)) = UPPER(TRIM(?))";
                $params[] = $subject_code;
            }
            if ($search !== '') {
                $sql .= " AND s.full_name LIKE ?";
                $params[] = "%$search%";
            }
            if ($day !== '' && $day !== 'all') {
                $sql .= " AND sch.day = ?";
                $params[] = $day;
            }
            $sql .= " ORDER BY sub.course_code, s.full_name ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $grouped = [];
            foreach ($rows as $row) {
                $grouped[$row['subject_name']][] = $row;
            }
            echo json_encode($grouped);
            break;

        // ========== READ: classes + schedules (for add‑student dropdown) ==========
        case 'get_classes':
            // NOTE: COALESCE so classes whose class_code is NULL (e.g. those created
            // by add_schedule.php) are NOT silently excluded. `NULL != 'A28'` is not
            // TRUE in SQL, which previously dropped every newly added class.
            $stmt = $pdo->query("
                SELECT c.class_id, sub.course_code AS subject_code, sub.subject_name, c.section,
                       sch.schedule_id, sch.day, 
                       CONCAT(TIME_FORMAT(sch.start_time,'%l:%i %p'),' - ',TIME_FORMAT(sch.end_time,'%l:%i %p')) AS time,
                       sch.room, u.full_name AS instructor
                FROM classes c
                JOIN subjects sub ON c.course_code = sub.course_code
                JOIN schedules sch ON sch.class_id = c.class_id
                LEFT JOIN teachers t ON c.teacher_id = t.id
                LEFT JOIN users u ON t.user_id = u.id
                WHERE COALESCE(c.class_code, '') != 'A28'
                ORDER BY sub.subject_name, sch.day
            ");
            echo json_encode($stmt->fetchAll());
            break;

        // ========== READ: subjects for filter dropdown (code + name) ==========
        case 'get_subject_list':
            // Returns objects { code, name } so the filter can DISPLAY the subject
            // name while still FILTERING by course_code.
            $stmt = $pdo->query("
                SELECT DISTINCT TRIM(course_code) AS code, subject_name AS name
                FROM subjects
                WHERE course_code IS NOT NULL AND TRIM(course_code) <> ''
                ORDER BY subject_name
            ");
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        // ========== ADD student (admin only) ==========
        case 'add_student':
            // Collect + trim inputs
            $student_number = trim($_POST['student_id'] ?? '');   // form field name is student_id
            $full_name      = trim($_POST['full_name'] ?? '');
            $gender         = trim($_POST['gender'] ?? '');
            $course         = trim($_POST['course'] ?? '');
            $year_level     = trim($_POST['year_level'] ?? '');
            $contact        = trim($_POST['contact'] ?? '');
            $email          = trim($_POST['email'] ?? '');
            $parent_name    = trim($_POST['parent_name'] ?? '');
            $parent_contact = trim($_POST['parent_contact'] ?? '');
            $class_id       = intval($_POST['class_id'] ?? 0);
            $schedule_id    = intval($_POST['schedule_id'] ?? 0);

            // Validate required fields
            if ($student_number === '' || $full_name === '' || $gender === '' || $course === ''
                || $year_level === '' || $contact === '' || $email === ''
                || $parent_name === '' || $parent_contact === '' || !$class_id || !$schedule_id) {
                echo json_encode(['status'=>'error','message'=>'All fields are required, including a subject/class.']);
                exit;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['status'=>'error','message'=>'Invalid email format']);
                exit;
            }

            // Reject duplicate student number
            $dup = $pdo->prepare("SELECT id FROM students WHERE student_number = ? LIMIT 1");
            $dup->execute([$student_number]);
            if ($dup->fetch()) {
                echo json_encode(['status'=>'error','message'=>'A student with that Student ID already exists.']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                // 1. Student record
                $pdo->prepare(
                    "INSERT INTO students (student_number, full_name, gender, course, year_level, contact, email, created_at)
                     VALUES (?,?,?,?,?,?,?, NOW())"
                )->execute([$student_number, $full_name, $gender, $course, $year_level, $contact, $email]);
                $new_student_id = $pdo->lastInsertId();

                // 2. Parent / guardian record
                $pdo->prepare(
                    "INSERT INTO parents (student_id, parent_name, contact_number) VALUES (?,?,?)"
                )->execute([$new_student_id, $parent_name, $parent_contact]);

                // 3. Enrollment in the chosen class + schedule
                $pdo->prepare(
                    "INSERT INTO student_schedule (student_id, class_id, schedule_id) VALUES (?,?,?)"
                )->execute([$new_student_id, $class_id, $schedule_id]);

                $pdo->commit();
                echo json_encode(['status'=>'success','message'=>'Student added successfully']);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                echo json_encode(['status'=>'error','message'=>'Database error: '.$e->getMessage()]);
            }
            break;

        // ========== READ one student ==========
        case 'get_profile':
            $id = $_GET['id'] ?? 0;
            if (!$id) { echo json_encode(['status'=>'error','message'=>'Invalid ID']); exit; }
            $stmt = $pdo->prepare("
                SELECT s.id, s.student_number AS student_id, s.full_name, s.gender, s.course, s.year_level,
                       s.contact, s.email, p.parent_name, p.contact_number AS parent_contact, s.created_at
                FROM students s
                LEFT JOIN parents p ON p.student_id = s.id
                WHERE s.id = ?
            ");
            $stmt->execute([$id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode($data ? array_merge(['status'=>'success'], $data) : ['status'=>'error','message'=>'Student not found']);
            break;

        // ========== UPDATE student (admin only) ==========
        case 'update_profile':
            $id = $_POST['id'] ?? 0;
            if (!$id) { echo json_encode(['status'=>'error','message'=>'Student ID required']); exit; }
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE students SET full_name=?, gender=?, course=?, year_level=?, contact=?, email=? WHERE id=?")
                    ->execute([$_POST['full_name'], $_POST['gender'], $_POST['course'], $_POST['year_level'], $_POST['contact'], $_POST['email'], $id]);
                $exists = $pdo->prepare("SELECT parent_id FROM parents WHERE student_id=? LIMIT 1");
                $exists->execute([$id]);
                if ($exists->fetchColumn()) {
                    $pdo->prepare("UPDATE parents SET parent_name=?, contact_number=? WHERE student_id=?")
                        ->execute([$_POST['parent_name'] ?? '', $_POST['parent_contact'] ?? '', $id]);
                } else {
                    $pdo->prepare("INSERT INTO parents (student_id, parent_name, contact_number) VALUES (?,?,?)")
                        ->execute([$id, $_POST['parent_name'] ?? '', $_POST['parent_contact'] ?? '']);
                }
                $pdo->commit();
                echo json_encode(['status'=>'success']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['status'=>'error','message'=>'Database error: '.$e->getMessage()]);
            }
            break;

        // ========== DELETE student (admin only) ==========
        case 'delete_student':
            $id = $_POST['id'] ?? 0;
            if (!$id) { echo json_encode(['status'=>'error','message'=>'Student ID required']); exit; }
            $pdo->prepare("DELETE FROM students WHERE id = ?")->execute([$id]);
            echo json_encode(['status'=>'success','message'=>'Student deleted successfully']);
            break;

        // ========== TEACHER ACTIONS ==========
        case 'get_teachers':
            $stmt = $pdo->query("SELECT id, name, subject, email, contact, bio, age, address, teacher_number FROM teachers ORDER BY name");
            echo json_encode($stmt->fetchAll());
            break;

        case 'get_teacher':
            $id = $_GET['id'] ?? 0;
            if (!$id) { echo json_encode(['status'=>'error','message'=>'Invalid teacher ID']); exit; }
            $stmt = $pdo->prepare("SELECT * FROM teachers WHERE id = ?");
            $stmt->execute([$id]);
            $teacher = $stmt->fetch();
            echo json_encode($teacher ? ['status'=>'success','data'=>$teacher] : ['status'=>'error','message'=>'Teacher not found']);
            break;

        case 'add_teacher':
            // Admin only – already enforced
            $requiredFields = ['name', 'subject', 'email', 'contact', 'age', 'address'];
            foreach ($requiredFields as $field) {
                if (empty(trim($_POST[$field] ?? ''))) {
                    echo json_encode(['status'=>'error','message'=>"$field is required"]);
                    exit;
                }
            }
            if (!filter_var(trim($_POST['email']), FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['status'=>'error','message'=>'Invalid email format']);
                exit;
            }
            if (!is_numeric(trim($_POST['age']))) {
                echo json_encode(['status'=>'error','message'=>'Age must be a number']);
                exit;
            }

            $name = trim($_POST['name']);
            $email = trim($_POST['email']);

            $check = $pdo->prepare("SELECT id FROM teachers WHERE LOWER(name) = LOWER(?) OR LOWER(email) = LOWER(?)");
            $check->execute([$name, $email]);
            if ($check->fetch()) {
                echo json_encode(['status'=>'error','message'=>'Teacher already exists (same name or email)']);
                exit;
            }

            $stmt = $pdo->prepare(
                "INSERT INTO teachers (name, subject, email, contact, bio, age, address, teacher_number)
                 VALUES (?,?,?,?,?,?,?,?)"
            );
            $stmt->execute([
                $name,
                trim($_POST['subject']),
                $email,
                trim($_POST['contact']),
                trim($_POST['bio'] ?? ''),
                trim($_POST['age']),
                trim($_POST['address']),
                trim($_POST['teacher_number'] ?? '')
            ]);
            echo json_encode(['status'=>'success','message'=>'Teacher added']);
            break;

        case 'update_teacher':
            // Admin only – already enforced
            $id = $_POST['id'] ?? 0;
            if (!$id) { echo json_encode(['status'=>'error','message'=>'Teacher ID required']); exit; }

            $requiredFields = ['name', 'subject', 'email', 'contact', 'age', 'address'];
            foreach ($requiredFields as $field) {
                if (empty(trim($_POST[$field] ?? ''))) {
                    echo json_encode(['status'=>'error','message'=>"$field is required"]);
                    exit;
                }
            }
            if (!filter_var(trim($_POST['email']), FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['status'=>'error','message'=>'Invalid email format']);
                exit;
            }
            if (!is_numeric(trim($_POST['age']))) {
                echo json_encode(['status'=>'error','message'=>'Age must be a number']);
                exit;
            }

            $name = trim($_POST['name']);
            $email = trim($_POST['email']);

            $check = $pdo->prepare("SELECT id FROM teachers WHERE (LOWER(name) = LOWER(?) OR LOWER(email) = LOWER(?)) AND id != ?");
            $check->execute([$name, $email, $id]);
            if ($check->fetch()) {
                echo json_encode(['status'=>'error','message'=>'Teacher already exists (same name or email)']);
                exit;
            }

            $stmt = $pdo->prepare(
                "UPDATE teachers SET name=?, subject=?, email=?, contact=?, bio=?, age=?, address=?, teacher_number=?
                 WHERE id=?"
            );
            $stmt->execute([
                $name,
                trim($_POST['subject']),
                $email,
                trim($_POST['contact']),
                trim($_POST['bio'] ?? ''),
                trim($_POST['age']),
                trim($_POST['address']),
                trim($_POST['teacher_number'] ?? ''),
                $id
            ]);
            echo json_encode(['status'=>'success','message'=>'Teacher updated']);
            break;

        case 'delete_teacher':
            // Admin only – already enforced
            $id = $_POST['id'] ?? 0;
            if (!$id) { echo json_encode(['status'=>'error','message'=>'ID required']); exit; }
            $pdo->prepare("DELETE FROM teachers WHERE id = ?")->execute([$id]);
            echo json_encode(['status'=>'success','message'=>'Teacher deleted']);
            break;

        default:
            echo json_encode(['status'=>'error','message'=>'Invalid Action']);
    }
} catch (Exception $e) {
    error_log("CRUD Error: " . $e->getMessage());
    echo json_encode(['status'=>'error','message'=>'Server error: '.$e->getMessage()]);
}
?>