<?php
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

requireRole('super_admin');

$pdo = db();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student & Teacher Management | Modern Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php require_once __DIR__ . '/../includes/nav.php'; renderNav('profiles'); ?>

<!-- Toast Container -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1100;">
    <div id="liveToast" class="toast" role="alert" aria-live="assertive" aria-atomic="true" data-bs-autohide="true" data-bs-delay="3000">
        <div class="toast-header">
            <i class="bi bi-bell-fill me-2"></i>
            <strong class="me-auto">Notification</strong>
            <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
        </div>
        <div class="toast-body" id="toastMessage">Message</div>
    </div>
</div>

<!-- Modern Header -->
<div class="modern-header">
    <div class="container-fluid">
        <div class="d-flex flex-wrap align-items-center justify-content-between">
            <div>
                <h1 class="display-6 fw-semibold"><i class="bi bi-calendar-check"></i> Student & Teacher Records</h1>
                <p class="lead mb-0">Manage students, teachers, and class schedules</p>
            </div>
            <div class="mt-2 mt-md-0 d-flex gap-2">
                <button class="btn btn-light" onclick="openAddStudentModal()"><i class="bi bi-person-plus"></i> Add Student</button>
                <button class="btn btn-outline-light" onclick="openTeacherModal()"><i class="bi bi-person-badge"></i> Teacher Profiles</button>
                <span class="badge bg-light text-dark px-3 py-2" id="totalStudentsBadge">
                    <i class="bi bi-people"></i> Total: <span id="totalStudentsCount">0</span>
                </span>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid mt-4">
    <div class="row g-4">
        <!-- LEFT SIDE: Filters -->
        <div class="col-lg-3">
            <div class="filter-card">
                <div class="card-header-custom">
                    <h4 class="mb-0"><i class="bi bi-funnel-fill"></i> Filters</h4>
                </div>
                <div class="card-body-custom">
                    <div class="filter-section">
                        <label class="form-label fw-semibold"><i class="bi bi-book"></i> Subject</label>
                        <select id="subjectFilter" class="form-select modern-select" onchange="applyFilter()">
                            <option value="all">All Subjects</option>
                        </select>
                    </div>
                    <div class="filter-section mt-3">
                        <label class="form-label fw-semibold"><i class="bi bi-search"></i> Search Student</label>
                        <div class="search-wrapper">
                            <input type="text" id="searchInput" class="form-control modern-input" placeholder="Type name..." autocomplete="off">
                            <i class="bi bi-x-circle-fill clear-search" id="clearSearchBtn"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT SIDE: Students & Schedule Display -->
        <div class="col-lg-9">
            <div class="day-selector-card mb-4">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="me-2 fw-semibold"><i class="bi bi-calendar-week"></i> Filter by day:</span>
                    <button class="day-pill active" data-day="all">All Days</button>
                    <button class="day-pill" data-day="Monday">Monday</button>
                    <button class="day-pill" data-day="Tuesday">Tuesday</button>
                    <button class="day-pill" data-day="Wednesday">Wednesday</button>
                    <button class="day-pill" data-day="Thursday">Thursday</button>
                    <button class="day-pill" data-day="Friday">Friday</button>
                </div>
            </div>

            <h4 class="mb-3"><i class="bi bi-mortarboard"></i> Students by Subject</h4>
            <div id="studentsBySubject">
                <div class="text-center py-5"><div class="spinner-border text-primary"></div><p class="mt-2">Loading students...</p></div>
            </div>
        </div>
    </div>
</div>

<!-- ==================== MODALS ==================== -->

<!-- Add Student Modal -->
<div class="modal fade" id="addStudentModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-person-plus"></i> Add New Student</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="addStudentForm">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Student ID *</label><input type="text" name="student_id" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label">Full Name *</label><input type="text" name="full_name" class="form-control" required></div>
                        <div class="col-md-4"><label class="form-label">Gender *</label><select name="gender" class="form-select" required><option value="">Select</option><option value="M">Male</option><option value="F">Female</option></select></div>
                        <div class="col-md-4"><label class="form-label">Course *</label><input type="text" name="course" class="form-control" placeholder="BSIT" required></div>
                        <div class="col-md-4"><label class="form-label">Year Level *</label><select name="year_level" class="form-select" required><option value="">Select</option><option value="1">1st Year</option><option value="2">2nd Year</option><option value="3">3rd Year</option><option value="4">4th Year</option></select></div>
                        <div class="col-md-6"><label class="form-label">Contact Number *</label><input type="tel" name="contact" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label">Email *</label><input type="email" name="email" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label">Parent/Guardian Name *</label><input type="text" name="parent_name" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label">Parent Contact *</label><input type="text" name="parent_contact" class="form-control" required></div>
                        <div class="col-md-12"><label class="form-label">Select Subject (Class Schedule) *</label>
                            <select id="classSelect" name="class_id" class="form-select" required>
                                <option value="">Choose subject & schedule</option>
                            </select>
                        </div>
                        <input type="hidden" name="schedule_id" id="scheduleIdField">
                    </div>
                </form>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitAddStudent()">Save Student</button>
            </div>
        </div>
    </div>
</div>

<!-- Student Modal -->
<div class="modal fade" id="studentModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-gradient-primary text-white">
                <h5 class="modal-title"><i class="bi bi-person-badge"></i> Student Profile</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div id="modeBanner" class="mode-banner view-mode mb-4"><i class="bi bi-eye"></i> <span>View Mode</span></div>
                <form id="studentForm">
                    <input type="hidden" id="editStudentId" name="id">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Student ID</label><div class="view-field" id="view_student_id"></div><input type="text" class="form-control edit-field d-none" id="modalStudentId" name="student_id" readonly></div>
                        <div class="col-md-6"><label class="form-label">Full Name</label><div class="view-field" id="view_full_name"></div><input type="text" class="form-control edit-field d-none" id="modalFullName" name="full_name"></div>
                        <div class="col-md-4"><label class="form-label">Gender</label><div class="view-field" id="view_gender"></div><select class="form-select edit-field d-none" id="modalGender" name="gender"><option value="">Select</option><option value="M">Male</option><option value="F">Female</option></select></div>
                        <div class="col-md-4"><label class="form-label">Course</label><div class="view-field" id="view_course"></div><input type="text" class="form-control edit-field d-none" id="modalCourse" name="course"></div>
                        <div class="col-md-4"><label class="form-label">Year Level</label><div class="view-field" id="view_year_level"></div><select class="form-select edit-field d-none" id="modalYear" name="year_level"><option value="">Select</option><option value="1">1st Year</option><option value="2">2nd Year</option><option value="3">3rd Year</option><option value="4">4th Year</option></select></div>
                        <div class="col-md-6"><label class="form-label">Contact</label><div class="view-field" id="view_contact"></div><input type="text" class="form-control edit-field d-none" id="modalContact" name="contact"></div>
                        <div class="col-md-6"><label class="form-label">Email</label><div class="view-field" id="view_email"></div><input type="email" class="form-control edit-field d-none" id="modalEmail" name="email"></div>
                        <div class="col-md-6"><label class="form-label">Parent Name</label><div class="view-field" id="view_parent_name"></div><input type="text" class="form-control edit-field d-none" id="modalParentName" name="parent_name"></div>
                        <div class="col-md-6"><label class="form-label">Parent Contact</label><div class="view-field" id="view_parent_contact"></div><input type="text" class="form-control edit-field d-none" id="modalParentContact" name="parent_contact"></div>
                        <div class="col-12"><label class="form-label">Date Added</label><div class="view-field" id="view_created_at"></div></div>
                    </div>
                </form>
            </div>
            <div class="modal-footer bg-light">
                <div id="footerViewMode" class="d-flex w-100 justify-content-between">
                    <div><button class="btn btn-danger" onclick="deleteStudent()"><i class="bi bi-trash"></i> Delete Student</button></div>
                    <div><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button class="btn btn-primary" onclick="enableEditMode()">Edit Information</button></div>
                </div>
                <div id="footerEditMode" class="d-flex w-100 justify-content-between d-none">
                    <button class="btn btn-outline-danger" onclick="disableEditMode()">Cancel</button>
                    <button class="btn btn-success" onclick="saveStudent()">Save Changes</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Teacher Profile Modal -->
<div class="modal fade" id="teacherModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-person-badge"></i> Teacher Profiles</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="row g-0">
                    <div class="col-md-4 border-end bg-light" style="max-height: 70vh; overflow-y: auto;">
                        <div class="p-3">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="mb-0"><i class="bi bi-people"></i> Teachers</h6>
                                <button class="btn btn-sm btn-primary" onclick="openAddTeacherModal()"><i class="bi bi-plus"></i> Add New</button>
                            </div>
                            <div id="teacherListSidebar"></div>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div id="teacherProfileDetail" class="p-4">
                            <div class="text-center text-muted py-5">
                                <i class="bi bi-person-circle display-1"></i>
                                <p class="mt-3">Select a teacher from the list</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Teacher Form Modal (No profile picture field) -->
<div class="modal fade" id="teacherFormModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="teacherFormTitle">Add New Teacher</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="teacherForm">
                    <input type="hidden" name="id" id="editTeacherId">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Full Name *</label><input type="text" name="name" id="teacherName" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label">Subject *</label><input type="text" name="subject" id="teacherSubject" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label">Teacher Number</label><input type="text" name="teacher_number" id="teacherNumber" class="form-control" placeholder="Optional"></div>
                        <div class="col-md-6"><label class="form-label">Email *</label><input type="email" name="email" id="teacherEmail" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label">Contact Number *</label><input type="text" name="contact" id="teacherContact" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label">Age *</label><input type="number" name="age" id="teacherAge" class="form-control" required></div>
                        <div class="col-12"><label class="form-label">Address *</label><textarea name="address" id="teacherAddress" rows="2" class="form-control" required></textarea></div>
                        <div class="col-12"><label class="form-label">Bio</label><textarea name="bio" id="teacherBio" rows="3" class="form-control"></textarea></div>
                    </div>
                </form>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveTeacher()">Save Teacher</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // ========== TOAST ==========
    let toastEl, toastInstance;
    function showNotification(message, type = 'success') {
        if (!toastEl) toastEl = document.getElementById('liveToast');
        if (!toastInstance) toastInstance = new bootstrap.Toast(toastEl, { autohide: true, delay: 3000 });
        const header = toastEl.querySelector('.toast-header');
        header.querySelector('i').className = type === 'success' ? 'bi bi-check-circle-fill me-2 text-success' : 'bi bi-exclamation-triangle-fill me-2 text-danger';
        header.querySelector('strong').textContent = type === 'success' ? 'Success' : 'Error';
        document.getElementById('toastMessage').innerHTML = message;
        toastInstance.show();
    }

    // ========== GLOBAL ==========
    let currentDay = 'all';
    let currentStudentData = {};
    let classesList = [];

    // ========== SUBJECT FILTER (dynamic) ==========
    function loadSubjectFilter() {
        fetch('crud.php?action=get_subject_list')
            .then(res => res.json())
            .then(subjects => {
                const select = document.getElementById('subjectFilter');
                while (select.options.length > 1) select.remove(1);
                subjects.forEach(sub => {
                    const option = document.createElement('option');
                    option.value = sub;
                    option.textContent = sub;
                    select.appendChild(option);
                });
                applyFilter();
            })
            .catch(err => console.error('Failed to load subjects:', err));
    }

    // ========== DAY SELECTOR ==========
    function attachDaySelectorEvents() {
        document.querySelectorAll('.day-pill').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.day-pill').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                currentDay = this.getAttribute('data-day');
                loadStudentsBySubject();
            });
        });
    }

    function setupClearSearchButton() {
        const searchInput = document.getElementById('searchInput');
        const clearBtn = document.getElementById('clearSearchBtn');
        const toggle = () => clearBtn.style.display = searchInput.value.length ? 'flex' : 'none';
        searchInput.addEventListener('input', () => { toggle(); applyFilter(); });
        clearBtn.addEventListener('click', () => { searchInput.value = ''; toggle(); applyFilter(); searchInput.focus(); });
        toggle();
    }

    function applyFilter() {
        loadStudentsBySubject();
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>]/g, m => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;' }[m]));
    }

    function loadStudentsBySubject() {
        const subject = document.getElementById('subjectFilter').value;
        const search = document.getElementById('searchInput').value;
        const container = document.getElementById('studentsBySubject');
        container.innerHTML = `<div class="text-center py-5"><div class="spinner-border text-primary"></div><p>Loading...</p></div>`;

        fetch(`crud.php?action=get_students_by_subject&subject=${encodeURIComponent(subject)}&search=${encodeURIComponent(search)}&day=${currentDay}`)
            .then(res => res.json())
            .then(data => {
                container.innerHTML = '';
                let total = 0;
                if (Object.keys(data).length === 0) {
                    container.innerHTML = '<div class="alert alert-info">No students found.</div>';
                    document.getElementById('totalStudentsCount').innerText = '0';
                    return;
                }
                for (let subjectName in data) {
                    const students = data[subjectName];
                    total += students.length;
                    const first = students[0];
                    let html = `<div class="subject-group">
                        <div class="subject-header"><div><i class="bi bi-book-fill"></i> ${escapeHtml(first.subject_name)} <span class="badge bg-light text-dark ms-2">${students.length} students</span></div></div>
                        <div class="schedule-badge">
                            <span><i class="bi bi-code-slash"></i> ${escapeHtml(first.subject_code)}</span>
                            <span><i class="bi bi-calendar-day"></i> ${escapeHtml(first.day)}</span>
                            <span><i class="bi bi-clock"></i> ${escapeHtml(first.time)}</span>
                            <span><i class="bi bi-geo-alt"></i> ${escapeHtml(first.room)}</span>
                            <span><i class="bi bi-person-badge"></i> ${escapeHtml(first.instructor)}</span>
                        </div>
                        <div class="row g-2 mt-2">`;
                    students.forEach(s => {
                        html += `<div class="col-md-6 col-xl-4">
                            <div class="student-card" onclick="openStudentModal(${s.student_db_id})">
                                <div class="student-name">${escapeHtml(s.full_name)}</div>
                                <div class="student-meta"><i class="bi bi-hash"></i> ${escapeHtml(s.student_id)}<br><i class="bi bi-book"></i> ${escapeHtml(s.course)} - Year ${escapeHtml(s.year_level)}</div>
                            </div>
                        </div>`;
                    });
                    html += `</div></div>`;
                    container.innerHTML += html;
                }
                document.getElementById('totalStudentsCount').innerText = total;
            })
            .catch(err => { console.error(err); container.innerHTML = '<div class="alert alert-danger">Error loading students.</div>'; showNotification('Failed to load students', 'error'); });
    }

    // ========== STUDENT MODAL ==========
    function openStudentModal(id) {
        fetch(`crud.php?action=get_profile&id=${id}`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'error') { showNotification(data.message, 'error'); return; }
                currentStudentData = data;
                populateModal(data);
                setViewMode();
                new bootstrap.Modal(document.getElementById('studentModal')).show();
            });
    }

    function populateModal(d) {
        document.getElementById('view_student_id').innerText = d.student_id || '—';
        document.getElementById('view_full_name').innerText = d.full_name || '—';
        document.getElementById('view_gender').innerText = d.gender === 'M' ? 'Male' : d.gender === 'F' ? 'Female' : '—';
        document.getElementById('view_course').innerText = d.course || '—';
        let yl = d.year_level ? d.year_level + (d.year_level==1?'st':d.year_level==2?'nd':d.year_level==3?'rd':'th') + ' Year' : '—';
        document.getElementById('view_year_level').innerText = yl;
        document.getElementById('view_contact').innerText = d.contact || '—';
        document.getElementById('view_email').innerText = d.email || '—';
        document.getElementById('view_parent_name').innerText = d.parent_name || '—';
        document.getElementById('view_parent_contact').innerText = d.parent_contact || '—';
        const createdDate = d.created_at ? new Date(d.created_at).toLocaleString() : '—';
        document.getElementById('view_created_at').innerText = createdDate;

        document.getElementById('editStudentId').value = d.id;
        document.getElementById('modalStudentId').value = d.student_id;
        document.getElementById('modalFullName').value = d.full_name;
        document.getElementById('modalGender').value = d.gender;
        document.getElementById('modalCourse').value = d.course;
        document.getElementById('modalYear').value = d.year_level;
        document.getElementById('modalContact').value = d.contact || '';
        document.getElementById('modalEmail').value = d.email || '';
        document.getElementById('modalParentName').value = d.parent_name || '';
        document.getElementById('modalParentContact').value = d.parent_contact || '';
    }

    function setViewMode() {
        document.querySelectorAll('.view-field').forEach(el => el.classList.remove('d-none'));
        document.querySelectorAll('.edit-field').forEach(el => el.classList.add('d-none'));
        document.getElementById('footerViewMode').classList.remove('d-none');
        document.getElementById('footerEditMode').classList.add('d-none');
        document.getElementById('modeBanner').innerHTML = '<i class="bi bi-eye"></i> <span>View Mode</span>';
        document.getElementById('modeBanner').className = 'mode-banner view-mode mb-4';
    }
    function enableEditMode() {
        document.querySelectorAll('.view-field').forEach(el => el.classList.add('d-none'));
        document.querySelectorAll('.edit-field').forEach(el => el.classList.remove('d-none'));
        document.getElementById('footerViewMode').classList.add('d-none');
        document.getElementById('footerEditMode').classList.remove('d-none');
        document.getElementById('modeBanner').innerHTML = '<i class="bi bi-pencil-square"></i> <span>Edit Mode — make changes and save</span>';
        document.getElementById('modeBanner').className = 'mode-banner edit-mode mb-4';
    }
    function disableEditMode() { populateModal(currentStudentData); setViewMode(); }

    function saveStudent() {
        const fd = new FormData(document.getElementById('studentForm'));
        fd.append('action', 'update_profile');
        fetch('crud.php', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    showNotification('Student updated successfully');
                    bootstrap.Modal.getInstance(document.getElementById('studentModal')).hide();
                    loadStudentsBySubject();
                } else showNotification('Update failed', 'error');
            });
    }

    function deleteStudent() {
        if (confirm('Are you sure you want to delete this student permanently?')) {
            const fd = new FormData();
            fd.append('action', 'delete_student');
            fd.append('id', currentStudentData.id);
            fetch('crud.php', { method: 'POST', body: fd })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showNotification('Student deleted successfully');
                        bootstrap.Modal.getInstance(document.getElementById('studentModal')).hide();
                        loadStudentsBySubject();
                    } else {
                        showNotification(data.message, 'error');
                    }
                });
        }
    }

    // ========== ADD STUDENT ==========
    function loadClasses() {
        fetch('crud.php?action=get_classes')
            .then(res => res.json())
            .then(classes => {
                classesList = classes;
                const classSelect = document.getElementById('classSelect');
                classSelect.innerHTML = '<option value="">Select Subject & Schedule</option>';
                classes.forEach(cls => {
                    classSelect.innerHTML += `<option value="${cls.class_id}" data-schedule="${cls.schedule_id}">
                        ${escapeHtml(cls.subject_name)} (${escapeHtml(cls.section)}) - ${cls.day} ${cls.time} | Teacher: ${escapeHtml(cls.instructor)}
                    </option>`;
                });
            });
    }

    document.getElementById('classSelect')?.addEventListener('change', function() {
        const selected = this.options[this.selectedIndex];
        const scheduleId = selected.getAttribute('data-schedule');
        if (scheduleId) document.getElementById('scheduleIdField').value = scheduleId;
    });

    function openAddStudentModal() {
        loadClasses();
        document.getElementById('addStudentForm').reset();
        new bootstrap.Modal(document.getElementById('addStudentModal')).show();
    }

    function submitAddStudent() {
        const form = document.getElementById('addStudentForm');
        const formData = new FormData(form);
        const classId = formData.get('class_id');
        if (!classId) { showNotification('Please select a subject/class', 'error'); return; }

        const selectedClass = classesList.find(c => c.class_id == classId);
        if (!selectedClass) { showNotification('Invalid class selection', 'error'); return; }
        formData.set('schedule_id', selectedClass.schedule_id);

        formData.append('action', 'add_student');
        fetch('crud.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    showNotification(data.message);
                    bootstrap.Modal.getInstance(document.getElementById('addStudentModal')).hide();
                    form.reset();
                    loadStudentsBySubject();
                } else {
                    showNotification(data.message, 'error');
                }
            });
    }

    // ========== TEACHER MANAGEMENT ==========
    let teachersList = [];

    function openTeacherModal() {
        loadTeacherListForSidebar();
        new bootstrap.Modal(document.getElementById('teacherModal')).show();
    }

    function loadTeacherListForSidebar() {
        fetch('crud.php?action=get_teachers')
            .then(res => res.json())
            .then(teachers => {
                teachersList = teachers;
                const sidebar = document.getElementById('teacherListSidebar');
                if (!teachers.length) {
                    sidebar.innerHTML = '<div class="text-muted text-center">No teachers added yet.<br><button class="btn btn-sm btn-primary mt-2" onclick="openAddTeacherModal()">Add First Teacher</button></div>';
                    document.getElementById('teacherProfileDetail').innerHTML = '<div class="text-center text-muted py-5"><i class="bi bi-person-circle display-1"></i><p class="mt-3">No teachers available</p></div>';
                    return;
                }
                let html = '<div class="list-group">';
                teachers.forEach(t => {
                    html += `<a href="#" class="list-group-item list-group-item-action" onclick="showTeacherProfile(${t.id}); return false;">
                                <div class="d-flex align-items-center">
                                    <img src="https://ui-avatars.com/api/?name=${encodeURIComponent(t.name)}&background=2a5298&color=fff&rounded=true" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover; margin-right: 10px;">
                                    <div><strong>${escapeHtml(t.name)}</strong><br><small>${escapeHtml(t.subject)}</small></div>
                                </div>
                            </a>`;
                });
                html += '</div>';
                sidebar.innerHTML = html;
                if (teachers.length) showTeacherProfile(teachers[0].id);
            });
    }

    function showTeacherProfile(id) {
        fetch(`crud.php?action=get_teacher&id=${id}`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'error') { showNotification(data.message, 'error'); return; }
                const t = data.data;
                const pic = 'https://ui-avatars.com/api/?name='+encodeURIComponent(t.name)+'&background=2a5298&color=fff&rounded=true';
                const detailHtml = `
                    <div class="text-center">
                        <img src="${pic}" class="rounded-circle mb-3" style="width: 120px; height: 120px; object-fit: cover; border: 3px solid #2a5298;">
                        <h3>${escapeHtml(t.name)}</h3>
                        <p class="text-muted"><i class="bi bi-book"></i> ${escapeHtml(t.subject)}</p>
                    </div>
                    <hr>
                    <div class="row mt-3">
                        <div class="col-md-6"><i class="bi bi-person-badge"></i> <strong>Teacher Number:</strong><br>${escapeHtml(t.teacher_number) || '—'}</div>
                        <div class="col-md-6"><i class="bi bi-envelope"></i> <strong>Email:</strong><br>${escapeHtml(t.email) || '—'}</div>
                        <div class="col-md-6 mt-2"><i class="bi bi-telephone"></i> <strong>Contact:</strong><br>${escapeHtml(t.contact) || '—'}</div>
                        <div class="col-md-6 mt-2"><i class="bi bi-calendar"></i> <strong>Age:</strong><br>${t.age || '—'}</div>
                        <div class="col-md-12 mt-2"><i class="bi bi-geo-alt"></i> <strong>Address:</strong><br>${escapeHtml(t.address) || '—'}</div>
                        <div class="col-12 mt-3"><i class="bi bi-info-circle"></i> <strong>Bio:</strong><br><p class="mt-1">${escapeHtml(t.bio) || 'No bio provided.'}</p></div>
                    </div>
                    <div class="mt-4 text-end">
                        <button class="btn btn-outline-primary btn-sm" onclick="editTeacherFromModal(${t.id})"><i class="bi bi-pencil"></i> Edit Profile</button>
                    </div>
                `;
                document.getElementById('teacherProfileDetail').innerHTML = detailHtml;
            });
    }

    function openAddTeacherModal() {
        document.getElementById('teacherFormTitle').innerText = 'Add New Teacher';
        document.getElementById('teacherForm').reset();
        document.getElementById('editTeacherId').value = '';
        new bootstrap.Modal(document.getElementById('teacherFormModal')).show();
    }

    function editTeacherFromModal(id) {
        fetch(`crud.php?action=get_teacher&id=${id}`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    const t = data.data;
                    document.getElementById('teacherFormTitle').innerText = 'Edit Teacher';
                    document.getElementById('editTeacherId').value = t.id;
                    document.getElementById('teacherName').value = t.name;
                    document.getElementById('teacherSubject').value = t.subject;
                    document.getElementById('teacherNumber').value = t.teacher_number || '';
                    document.getElementById('teacherEmail').value = t.email || '';
                    document.getElementById('teacherContact').value = t.contact || '';
                    document.getElementById('teacherAge').value = t.age || '';
                    document.getElementById('teacherAddress').value = t.address || '';
                    document.getElementById('teacherBio').value = t.bio || '';
                    new bootstrap.Modal(document.getElementById('teacherFormModal')).show();
                } else showNotification(data.message, 'error');
            });
    }

    function saveTeacher() {
        const form = document.getElementById('teacherForm');
        const fd = new FormData(form);
        const id = document.getElementById('editTeacherId').value;
        fd.append('action', id ? 'update_teacher' : 'add_teacher');
        fetch('crud.php', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    showNotification(data.message);
                    bootstrap.Modal.getInstance(document.getElementById('teacherFormModal')).hide();
                    loadTeacherListForSidebar();
                } else showNotification(data.message, 'error');
            });
    }

    // Initialisation
    document.addEventListener('DOMContentLoaded', () => {
        toastEl = document.getElementById('liveToast');
        toastInstance = new bootstrap.Toast(toastEl, { autohide: true, delay: 3000 });
        loadSubjectFilter();
        attachDaySelectorEvents();
        setupClearSearchButton();
    });
</script>
</body>
</html>