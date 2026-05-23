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

if(isset($_POST['submit'])){

  $s = trim($_POST['subject']); 
  $c = trim($_POST['course_code']);
  $t = trim($_POST['teacher']);
  $d = $_POST['day']; 
  $st = $_POST['start_time'];
  $en = $_POST['end_time']; 
  $r = trim($_POST['room']);

  // Validate subject
  if(!in_array($s, $allowedSubjects)){

    $err = "Invalid subject selected.";

  } elseif($st >= $en){

    $err = "End time must be greater than start time.";

  } else {

    // CHECK CONFLICT TIME
    $conflict = $conn->prepare("
      SELECT * FROM class_schedule
      WHERE day = ?
      AND (
            (? < end_time) 
            AND 
            (? > start_time)
          )
    ");

    $conflict->bind_param("sss", $d, $st, $en);
    $conflict->execute();
    $result = $conflict->get_result();

    if($result->num_rows > 0){

      $err = "Cannot add schedule because that time is already occupied.";

    } else {

      $q = $conn->prepare("
        INSERT INTO class_schedule
        (subject,course_code,teacher,day,start_time,end_time,room)
        VALUES(?,?,?,?,?,?,?)
      ");

      $q->bind_param("sssssss",$s,$c,$t,$d,$st,$en,$r);

      if($q->execute()){
        $ok = "Schedule added!";
      } else {
        $err = "Error: ".$conn->error;
      }

      $q->close();
    }

    $conflict->close();
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Add Schedule</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body>

<nav class="topnav">
  <a href="index.php" class="logo">
    <div class="logo-dot">S</div> Schedule
  </a>
  <a href="schedule.php" class="nav-back">&larr; Schedule</a>
</nav>

<div class="page page-sm">

  <div style="margin-bottom:24px;">
    <div class="page-title">Add Class Schedule</div>
    <div class="page-sub">Select from 5 major subjects</div>
  </div>

  <?php if($ok): ?>
    <div class="alert ok">
      &#10003; <?=htmlspecialchars($ok)?>
      &nbsp;<a href="schedule.php">View all &rarr;</a>
    </div>
  <?php endif; ?>

  <?php if($err): ?>
    <div class="alert err">
      &#9888; <?=htmlspecialchars($err)?>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-body">

      <form method="POST">

        <div class="fg">

          <div class="f full">
            <label for="subject">Subject *</label>

            <select id="subject" name="subject" required>
              <option value="">-- Select Subject --</option>

              <?php foreach($allowedSubjects as $subj): ?>
                <option value="<?=htmlspecialchars($subj)?>"
                  <?=isset($_POST['subject']) && $_POST['subject']===$subj ? 'selected' : ''?>>

                  <?=htmlspecialchars($subj)?>

                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="f">
            <label for="course_code">Course Code</label>

            <input
              type="text"
              id="course_code"
              name="course_code"
              placeholder="e.g. ITC 15"
              value="<?=isset($_POST['course_code']) ? htmlspecialchars($_POST['course_code']) : ''?>"
            >

            <small style="font-size:11px;color:var(--ink3);display:block;margin-top:4px;">
              Optional - e.g., ITC 15, IT 16
            </small>
          </div>

          <div class="f">
            <label for="teacher">Instructor *</label>

            <input
              type="text"
              id="teacher"
              name="teacher"
              placeholder="e.g. Intud, RB"
              required
              value="<?=isset($_POST['teacher']) ? htmlspecialchars($_POST['teacher']) : ''?>"
            >
          </div>

          <div class="f">
            <label for="room">Room *</label>

            <input
              type="text"
              id="room"
              name="room"
              placeholder="e.g. A28"
              required
              value="<?=isset($_POST['room']) ? htmlspecialchars($_POST['room']) : ''?>"
            >
          </div>

          <div class="f">
            <label for="day">Day *</label>

            <select id="day" name="day" required>

              <?php foreach(['Monday','Tuesday','Wednesday','Thursday','Friday'] as $dv): ?>

                <option value="<?=$dv?>"
                  <?=isset($_POST['day']) && $_POST['day']===$dv ? 'selected' : ''?>>

                  <?=$dv?>

                </option>

              <?php endforeach; ?>

            </select>
          </div>

          <div class="f">
            <label for="start_time">Start Time *</label>

            <input
              type="time"
              id="start_time"
              name="start_time"
              required
              value="<?=isset($_POST['start_time']) ? htmlspecialchars($_POST['start_time']) : ''?>"
            >
          </div>

          <div class="f">
            <label for="end_time">End Time *</label>

            <input
              type="time"
              id="end_time"
              name="end_time"
              required
              value="<?=isset($_POST['end_time']) ? htmlspecialchars($_POST['end_time']) : ''?>"
            >
          </div>

        </div>

        <div class="form-foot">
          <button type="submit" name="submit" class="btn btn-blue">
            Add Schedule
          </button>

          <a href="schedule.php" class="btn btn-ghost">
            View All
          </a>
        </div>

      </form>

    </div>
  </div>

</div>

</body>
</html>