<?php
require_once '../includes/auth.php';
require_once '../includes/helpers.php';



$pdo = db();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>ScheduleSys</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body>
<?php require_once __DIR__ . '/../includes/nav.php'; renderNav('scheduling'); ?>
<nav class="topnav">
  <a href="index.php" class="logo">
    <div class="logo-dot">S</div> Schedule
  </a>
</nav>
<div class="home">
  <div class="home-icon">&#128197;</div>
  <h1>Scheduling &amp; Attendance</h1>
  <p class="home-sub">Teacher Panel &mdash; Manage your classes with ease</p>
  <div class="grid2">
    <a href="add_schedule.php" class="menu-card">
      <div class="menu-card-icon">&#43;</div>
      <h3>Add Schedule</h3>
      <p>Create a new class schedule entry</p>
    </a>
    <a href="schedule.php" class="menu-card">
      <div class="menu-card-icon">&#9776;</div>
      <h3>View Schedule</h3>
      <p>View, edit, and manage all schedules</p>
    </a>
  </div>
</div>
</body>
</html>
