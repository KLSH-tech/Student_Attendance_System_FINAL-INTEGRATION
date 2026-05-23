<?php require_once __DIR__ . '/../includes/guard.php'; ?>
<?php
$conn = new mysqli("localhost","root","","school_db");
$ok = $err = "";

$search = isset($_GET['search']) ? trim($_GET['search']) : "";

// Define allowed subjects
$allowedSubjects = [
 'Introduction to Human Computer Interaction',
  'Information Management 1',
 'Fundamentals of Database Systems',
 'Quantitative Methods',
 'Information Assurance & Security 1'
];

// Delete functionality
if(isset($_GET['delete'])){
 $id=(int)$_GET['delete'];
 $q=$conn->prepare("DELETE FROM class_schedule WHERE schedule_id=?");
 $q->bind_param("i",$id);
 $q->execute() ? $ok="Deleted successfully." : $err="Error: ".$conn->error;
 $q->close();
}

// Build WHERE clause for allowed subjects only
$subjectPlaceholders = implode(',', array_fill(0, count($allowedSubjects), '?'));

$query = "SELECT * FROM class_schedule
 WHERE subject IN ($subjectPlaceholders)";

if ($search !== "") {
 $query .= " AND (subject LIKE ? OR course_code LIKE ?)";
}

$query .= " ORDER BY FIELD(day,'Monday','Tuesday','Wednesday','Thursday','Friday'), start_time ASC";

$stmt = $conn->prepare($query);
$types = str_repeat('s', count($allowedSubjects));

$params = $allowedSubjects;

if ($search !== "") {
   $like = "%{$search}%";
 $types .= "ss";
 $params[] = $like;
 $params[] = $like;
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

// Get counts by day
$countQuery = "SELECT day, COUNT(*) c
FROM class_schedule
WHERE subject IN ($subjectPlaceholders)";

if ($search !== "") {
 $countQuery .= " AND (subject LIKE ? OR course_code LIKE ?)";
}

$countQuery .= " GROUP BY day
ORDER BY FIELD(day,'Monday','Tuesday','Wednesday','Thursday','Friday')";

$countStmt = $conn->prepare($countQuery);
$countTypes = str_repeat('s', count($allowedSubjects));
$countParams = $allowedSubjects;

if ($search !== "") {
 $countTypes .= "ss";
  $countParams[] = $like;
 $countParams[] = $like;
}

$countStmt->bind_param($countTypes, ...$countParams);
$countStmt->execute();
$sc = $countStmt->get_result();

$dc=[];
while($s=$sc->fetch_assoc()) $dc[$s['day']]=$s['c'];
$total=array_sum($dc);

$rows=[];
while($r=$res->fetch_assoc()) $rows[$r['day']][]=$r;

$dord=['Monday','Tuesday','Wednesday','Thursday','Friday'];
$bcls=['Monday'=>'b-mon','Tuesday'=>'b-tue','Wednesday'=>'b-wed','Thursday'=>'b-thu','Friday'=>'b-fri'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
 <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
 <title>Class Schedule</title>
 <link rel="stylesheet" href="styles.css">
</head>
<body>
<nav class="topnav">
 <a href="index.php" class="logo"><div class="logo-dot">S</div> Schedule</a>
<a href="index.php" class="nav-back">&larr; Home</a> 
</nav>

<div class="page">
 <div class="page-top">
 <div>
 <div class="page-title">Class Schedule - Major Subjects</div>
 <div class="page-sub">Showing 5 core subjects only</div>
 <a href="add_schedule.php" class="btn btn-blue">+ Add Schedule</a>
 </div>
 </div>

 <div class="card" style="margin-bottom:20px;">
 <div class="card-body" style="padding:16px;">
<form method="GET" class="search-form" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
<input
type="text"
 name="search"
class="search-input"
placeholder="Search subject or subject code..."
 value="<?= htmlspecialchars($search) ?>"
 style="flex:1;min-width:240px;padding:12px 14px;border:1px solid #d7dfe8;border-radius:10px;outline:none;"
>
 <button type="submit" class="btn btn-blue">Search</button>
<?php if($search !== ""): ?>
<a href="schedule.php" class="btn btn-ghost">Clear</a>
<?php endif; ?>
</form>
 </div>
</div>

 <div class="card" style="margin-bottom:20px;">
<div class="card-body" style="padding:16px;">
<div style="font-size:11px;font-weight:600;color:var(--ink2);letter-spacing:.5px;text-transform:uppercase;margin-bottom:10px;">Filtered Subjects:</div>
<div style="display:flex;flex-wrap:wrap;gap:8px;">
<?php foreach($allowedSubjects as $subj): ?>
 <span style="background:var(--blue-s);color:var(--blue);padding:4px 12px;border-radius:20px;font-size:11px;font-weight:500;">
<?=htmlspecialchars($subj)?>
 </span>
<?php endforeach; ?>
</div>
 </div>
 </div>

 <?php if(empty($rows)): ?>
 <div class="card"><div class="empty"><div class="empty-i">&#128217;</div><p>No schedules yet. <a href="add_schedule.php">Add one &rarr;</a></p></div></div>
<?php else: ?>
<div class="tbl-wrap"><table>
<thead><tr>
 <th>Subject</th>
<th>Instructor</th>
<th>Day</th>
<th>Time Schedule</th>
 <th>Room</th>
<th></th>
 </tr></thead>
<tbody>
<?php foreach($dord as $day): if(empty($rows[$day])) continue; $bc=$bcls[$day]; ?>
<tr class="day-row"><th colspan="6"><?=strtoupper($day)?> &middot; <?=count($rows[$day])?> class<?=count($rows[$day])>1?'es':''?></th></tr>
<?php foreach($rows[$day] as $r):
$st=date("h:i A",strtotime($r['start_time']));
 $en=date("h:i A",strtotime($r['end_time']));
?>
<tr>
<td class="fw5" style="max-width:250px;">
<div style="line-height:1.4;"><?=htmlspecialchars($r['subject'])?></div>
 <?php if($r['course_code']): ?>
 <div style="font-size:11px;color:var(--ink3);margin-top:2px;"><?=htmlspecialchars($r['course_code'])?></div>
<?php endif; ?>
</td>
<td class="c2 fw5"><?=htmlspecialchars($r['teacher']??'TBA')?></td>
<td><span class="badge <?=$bc?>"><?=$day?></span></td>
<td class="c3 fs12" style="white-space:nowrap;">
<div><?=$st?> &ndash; <?=$en?></div>
<div style="font-size:11px;color:var(--ink3);margin-top:2px;">
<?php
 $start = strtotime($r['start_time']);
 $end = strtotime($r['end_time']);
 $duration = ($end - $start) / 3600;
 echo number_format($duration, 1) . ' hrs';
 ?>
 </div>
</td>
 <td class="fw6"><?=$r['room']?htmlspecialchars($r['room']):'<span class="c3" style="font-weight:400">&mdash;</span>'?></td>
 <td>
 <div class="acts">
<a href="edit_schedule.php?id=<?=$r['schedule_id']?>" class="bi bi-edit" title="Edit">&#9998;</a>
<a href="#" class="bi bi-del" onclick="openConfirmModal('schedule.php?delete=<?=$r['schedule_id']?>'); return false;" title="Delete">&#128465;</a>
</div>
</td>
</tr>
 <?php endforeach; endforeach; ?>
</tbody>
</table></div>
<?php endif; ?>
</div>

<div id="toast-container" class="toast-container"></div>

<div id="confirm-modal" class="modal-overlay" style="display:none;">
<div class="modal-box">
<h3>Delete Schedule</h3>
<p>Are you sure you want to delete this? This action cannot be undone.</p>
 <div class="modal-actions">
 <button onclick="closeConfirmModal()" class="btn btn-ghost">Cancel</button>
<a id="confirm-delete-btn" href="#" class="btn btn-red">Delete</a>
</div>
</div>
</div>

<script src="script.js"></script>
<script>
const modal = document.getElementById('confirm-modal');
 const deleteLink = document.getElementById('confirm-delete-btn');
 function openConfirmModal(url) {
 deleteLink.setAttribute('href', url);
modal.style.display = 'flex';
}
 function closeConfirmModal() {
modal.style.display = 'none';
 }
<?php if($ok): ?> showToast('<?=addslashes($ok)?>', 'success'); <?php endif; ?>
<?php if($err): ?> showToast('<?=addslashes($err)?>', 'error'); <?php endif; ?>
</script>
</body>
</html>