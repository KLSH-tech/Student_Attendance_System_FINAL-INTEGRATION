<?php require_once __DIR__ . '/../includes/guard.php'; ?>
<?php
$conn = new mysqli("localhost","root","","school_db");
$ok = $err = "";

// Define allowed subjects
$allowedSubjects = [
    'Introduction to Human Computer Interaction',
    'Information Management 1',
    'Fundamentals of Database Systems',
    'Quantitative Methods',
    'Information Assurance & Security 1'
];

if(!isset($_GET['id'])||!is_numeric($_GET['id'])){header("Location:schedule.php");exit;}
$id=(int)$_GET['id'];
$q=$conn->prepare("SELECT * FROM class_schedule WHERE schedule_id=?");
$q->bind_param("i",$id); $q->execute();
$sc=$q->get_result()->fetch_assoc(); $q->close();
if(!$sc){header("Location:schedule.php");exit;}

if(isset($_POST['submit'])){
  $s=trim($_POST['subject']);
  $t=trim($_POST['teacher']);
  $d=$_POST['day'];
  $st=$_POST['start_time'];
  $en=$_POST['end_time'];
  $r=trim($_POST['room']);
  
  // Validate subject
  if(!in_array($s, $allowedSubjects)){
    $err = "Invalid subject selected.";
  } else {
    $q=$conn->prepare("UPDATE class_schedule SET subject=?,teacher=?,day=?,start_time=?,end_time=?,room=? WHERE schedule_id=?");
    $q->bind_param("ssssssi",$s,$t,$d,$st,$en,$r,$id);
    if($q->execute()){
      $ok="Updated!";
      $sc=array_merge($sc,['subject'=>$s,'teacher'=>$t,'day'=>$d,'start_time'=>$st,'end_time'=>$en,'room'=>$r]);
    } else {
      $err="Error: ".$conn->error;
    } 
    $q->close();
  }
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Edit Schedule</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body>
<nav class="topnav">
  <a href="index.php" class="logo"><div class="logo-dot">S</div> ScheduleSys</a>
  <a href="schedule.php" class="nav-back">&larr; Schedule</a>
</nav>
<div class="page page-sm">
  <div style="margin-bottom:24px;">
    <div class="page-title">Edit Schedule</div>
    <div class="page-sub">Update this class entry</div>
  </div>
  <?php if($ok): ?><div class="alert ok">&#10003; <?=htmlspecialchars($ok)?> &nbsp;<a href="schedule.php">View all &rarr;</a></div><?php endif; ?>
  <?php if($err): ?><div class="alert err">&#9888; <?=htmlspecialchars($err)?></div><?php endif; ?>
  <div class="card"><div class="card-body">
    <form method="POST">
      <div class="fg">
        <div class="f full">
          <label>Subject *</label>
          <select name="subject" required>
            <option value="">-- Select Subject --</option>
            <?php foreach($allowedSubjects as $subj): ?>
            <option value="<?=htmlspecialchars($subj)?>" <?=$sc['subject']===$subj?'selected':''?>>
              <?=htmlspecialchars($subj)?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="f">
          <label>Instructor *</label>
          <input type="text" name="teacher" required value="<?=htmlspecialchars($sc['teacher'])?>">
        </div>
        <div class="f">
          <label>Room *</label>
          <input type="text" name="room" placeholder="e.g. A28" required value="<?=htmlspecialchars($sc['room']??'')?>">
        </div>
        <div class="f">
          <label>Day *</label>
          <select name="day" required>
            <?php foreach(['Monday','Tuesday','Wednesday','Thursday','Friday'] as $dv): ?>
            <option <?=$sc['day']===$dv?'selected':''?>><?=$dv?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="f">
          <label>Start Time *</label>
          <input type="time" name="start_time" required value="<?=htmlspecialchars($sc['start_time'])?>">
        </div>
        <div class="f">
          <label>End Time *</label>
          <input type="time" name="end_time" required value="<?=htmlspecialchars($sc['end_time'])?>">
        </div>
      </div>
      <div class="form-foot">
        <button type="submit" name="submit" class="btn btn-green">Save Changes</button>
        <a href="schedule.php" class="btn btn-ghost">Cancel</a>
        <a href="schedule.php?delete=<?=$id?>" class="btn btn-red ml-a" onclick="return confirm('Delete permanently?')">Delete</a>
      </div>
    </form>
  </div></div>
</div>
</body></html>