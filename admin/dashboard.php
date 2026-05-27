<?php
require_once 'config.php';   // bridged → foundation
           // now = requireRole('admin','super_admin')
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin Dashboard — <?php echo e(APP_NAME); ?></title>
  <!-- CSS via BASE_URL so it loads from the project root regardless of folder name -->
  <link rel="stylesheet" href="<?php echo e(BASE_URL); ?>/assets/css/style.css">
  <link rel="stylesheet" href="<?php echo e(BASE_URL); ?>/assets/css/nav.css">
</head>
<body>
<?php require_once __DIR__ . '/../includes/nav.php'; renderNav('dashboard'); ?>
  <div class="app">
    <main class="main">
      <?php render_topbar('Dashboard'); ?>
      <section class="cards">
        <?php foreach ($SUBSYSTEMS as $key => $sub) : ?>
          <a class="card" href="<?php echo e(subsystem_link($key)); ?>">
            <div class="card-title"><?php echo e($sub['label']); ?></div>
            <div class="card-count"><?php echo e($counts[$key] ?? 0); ?></div>
            <div class="card-sub">Records</div>
          </a>
        <?php endforeach; ?>
      </section>
    </main>
  </div>
</body>
</html>
