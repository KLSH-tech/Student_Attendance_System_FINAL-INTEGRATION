<?php
// ============================================================================
// profiles/crud.php — JSON API for Records / Profile Management
// Ported to the unified database (student_attendance_system) + compat views.
// ============================================================================
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');

// ── Foundation: session + db() (define $pdo HERE so it is never "undefined") ─
require_once __DIR__ . '/../includes/auth.php';

// JSON-safe auth guard (do NOT redirect — an AJAX caller expects JSON, not HTML)
if (!isLoggedIn() || !in_array(currentUser()['role'], ['admin', 'super_admin'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Not authorized']);
    exit;
}

$pdo = db();   // <-- the shared PDO connection, now a real local variable

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {

        // ── READ: students grouped by subject (reads via compat views) ──────
        case 'get_students_by_subject':
            $subject_code = $_GET['subject'] ?? 'all';
            $search       = $_GET['search']  ?? '';
            $day          = $_GET['day']     ?? '';

            $sql = "SELECT
                        s.id AS student_db_id, s.student_id, s.full_name, s.gender,
                        s.course, s.year_level, s.contact, s.email,
                        s.parent_name, s.parent_contact,
                        c.class_id, c.section, c.subject_code, c.subject_name,
                        sch.schedule_id, sch.day, sch.time, sch.room, sch.instructor
                    FROM student_schedule ss
                    JOIN v_students  s   ON ss.student_id  = s.id
                    JOIN v_classes   c   ON ss.class_id    = c.class_id
                    JOIN v_schedules sch ON ss.schedule_id = sch.schedule_id
                    WHERE 1=1
                      AND c.class_code != 'A28'";
            $params = [];
            if ($subject_code !== 'all') { $sql .= " AND c.subject_code = ?"; $params[] = $subject_code; }
            if ($search !== '')          { $sql .= " AND s.full_name LIKE ?"; $params[] = "%$search%"; }
            if ($day !== '' && $day !== 'all') { $sql .= " AND sch.day = ?";  $params[] = $day; }
            $sql .= " ORDER BY c.subject_code, s.full_name ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $grouped = [];
            foreach ($stmt->fetchAll() as $row) {
                $grouped[$row['subject_name']][] = $row;
            }
            echo json_encode($grouped);
            break;

        // ── READ: classes + their schedules (for the add-student dropdowns) ─
        case 'get_classes':
            $stmt = $pdo->query(
                "SELECT c.class_id, c.subject_code, c.subject_name, c.section,
                        sch.schedule_id, sch.day, sch.time, sch.instructor
                 FROM v_classes c
                 JOIN v_schedules sch ON sch.class_id = c.class_id
                 WHERE c.class_code != 'A28'
                 ORDER BY c.subject_name, sch.day"
            );
            echo json_encode($stmt->fetchAll());
            break;

        // ── CREATE student → write to REAL tables (students + parents) ──────
        case 'add_student':
            $required = ['student_id','full_name','gender','course','year_level','contact','email','parent_name','parent_contact','class_id','schedule_id'];
            foreach ($required as $f) {
                if (empty($_POST[$f])) { echo json_encode(['status'=>'error','message'=>"Field '$f' is required"]); exit; }
            }
            if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['status'=>'error','message'=>'Invalid email format']); exit;
            }

            $pdo->beginTransaction();
            try {
                // students.student_number holds the school ID (the form's student_id)
                $stmt = $pdo->prepare(
                    "INSERT INTO students (student_number, full_name, gender, course, year_level, contact, email)
                     VALUES (?,?,?,?,?,?,?)"
                );
                $stmt->execute([
                    $_POST['student_id'], $_POST['full_name'], $_POST['gender'], $_POST['course'],
                    $_POST['year_level'], $_POST['contact'], $_POST['email']
                ]);
                $newId = $pdo->lastInsertId();

                // parent info goes to the normalized parents table
                $pdo->prepare("INSERT INTO parents (student_id, parent_name, contact_number) VALUES (?,?,?)")
                    ->execute([$newId, $_POST['parent_name'], $_POST['parent_contact']]);

                // enrollment
                $pdo->prepare("INSERT INTO student_schedule (student_id, schedule_id, class_id) VALUES (?,?,?)")
                    ->execute([$newId, $_POST['schedule_id'], $_POST['class_id']]);

                $pdo->commit();
                echo json_encode(['status'=>'success','message'=>'Student added successfully']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['status'=>'error','message'=>'Database error: '.$e->getMessage()]);
            }
            break;

        // ── READ one student (view gives parent fields for the edit form) ───
        case 'get_profile':
            $id = $_GET['id'] ?? 0;
            if (!$id) { echo json_encode(['status'=>'error','message'=>'Invalid ID']); exit; }
            $stmt = $pdo->prepare("SELECT * FROM v_students WHERE id = ?");
            $stmt->execute([$id]);
            $data = $stmt->fetch();
            if ($data) { $data['status'] = 'success'; echo json_encode($data); }
            else       { echo json_encode(['status'=>'error','message'=>'Student not found']); }
            break;

        // ── UPDATE student → real students table + upsert parents ───────────
        case 'update_profile':
            $id = $_POST['id'] ?? 0;
            if (!$id) { echo json_encode(['status'=>'error','message'=>'Student ID required']); exit; }

            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    "UPDATE students SET full_name=?, gender=?, course=?, year_level=?, contact=?, email=? WHERE id=?"
                )->execute([
                    $_POST['full_name'], $_POST['gender'], $_POST['course'],
                    $_POST['year_level'], $_POST['contact'], $_POST['email'], $id
                ]);

                // upsert the parent row
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

        // ── DELETE student → real table; parents + enrollment cascade ───────
        case 'delete_student':
            $id = $_POST['id'] ?? 0;
            if (!$id) { echo json_encode(['status'=>'error','message'=>'Student ID required']); exit; }
            $pdo->prepare("DELETE FROM students WHERE id = ?")->execute([$id]);
            echo json_encode(['status'=>'success','message'=>'Student deleted successfully']);
            break;

        // ── Teacher actions (need profiles_compat.sql columns: name/subject/email) ─
        case 'get_teachers':
            $stmt = $pdo->query("SELECT id, name, subject, email, contact, profile_picture, bio, age, address FROM teachers ORDER BY name");
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
            if (empty($_POST['name']) || empty($_POST['subject'])) {
                echo json_encode(['status'=>'error','message'=>'Name and Subject are required']); exit;
            }
            $stmt = $pdo->prepare("INSERT INTO teachers (name, subject, email, contact, profile_picture, bio, age, address) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $_POST['name'], $_POST['subject'], $_POST['email'] ?? '', $_POST['contact'] ?? '',
                $_POST['profile_picture'] ?? '', $_POST['bio'] ?? '', $_POST['age'] ?: null, $_POST['address'] ?? ''
            ]);
            echo json_encode(['status'=>'success','message'=>'Teacher added']);
            break;

        case 'update_teacher':
            $id = $_POST['id'] ?? 0;
            if (!$id) { echo json_encode(['status'=>'error','message'=>'Teacher ID required']); exit; }
            $stmt = $pdo->prepare("UPDATE teachers SET name=?, subject=?, email=?, contact=?, profile_picture=?, bio=?, age=?, address=? WHERE id=?");
            $stmt->execute([
                $_POST['name'], $_POST['subject'], $_POST['email'] ?? '', $_POST['contact'] ?? '',
                $_POST['profile_picture'] ?? '', $_POST['bio'] ?? '', $_POST['age'] ?: null, $_POST['address'] ?? '', $id
            ]);
            echo json_encode(['status'=>'success','message'=>'Teacher updated']);
            break;

        case 'delete_teacher':
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