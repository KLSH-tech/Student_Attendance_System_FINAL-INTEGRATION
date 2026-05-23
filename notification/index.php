<?php require_once __DIR__ . '/../includes/guard.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance System - Barcode Scanner</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php require_once __DIR__ . '/../includes/nav.php'; renderNav('notifications'); ?>

<div class="sidebar">
    <h1>📢 Student Attendance System</h1>
    <p>Barcode Scan &amp; Notification System with Late Detection</p>
</div>

<div class="scanner-container">

    <div class="scanner-mode-selector">
        <button class="mode-btn active" id="btn-student" onclick="setMode('student', this)">👨‍🎓 Student Scan (With Notification)</button>
    </div>

    <div class="scanner-form">
        <form method="POST" action="">
            <input type="hidden" name="scan_mode" id="scan_mode"
                   value="<?php echo htmlspecialchars($_POST['scan_mode'] ?? 'student'); ?>">

            <label>🔍 Scan Barcode / Enter Student ID:</label>
            <input type="text"
                   name="barcode"
                   id="barcode-input"
                   autofocus
                   autocomplete="off"
                   placeholder="Scan or enter Student ID here..."
                   value=""/>

            <button type="submit">✅ Submit Attendance</button>
        </form>

        <?php
        // Include scanner functions once — processScan() handles POST internally
        require_once 'scanner.php';
        processScan();
        ?>
    </div>

    <div class="nav-links">
        <a href="attendance_report.php">📊 View Attendance Reports</a>
    </div>
</div>

<script>
// Restore active mode button on page reload after POST
(function () {
    const savedMode = document.getElementById('scan_mode').value;
    if (savedMode === 'teacher') {
        document.getElementById('btn-teacher').classList.add('active');
        document.getElementById('btn-student').classList.remove('active');
    }
})();

function setMode(mode, btn) {
    document.getElementById('scan_mode').value = mode;
    document.querySelectorAll('.mode-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}

// Auto-focus the barcode input after the page loads (helps after a scan POST)
window.addEventListener('load', function () {
    const input = document.getElementById('barcode-input');
    if (input) {
        input.focus();
        input.select();
    }
});
</script>

</body>
</html>
