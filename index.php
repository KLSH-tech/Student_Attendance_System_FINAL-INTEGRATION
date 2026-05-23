<?php
// ============================================================================
// index.php — Project landing page.
// ----------------------------------------------------------------------------
// NEW BEHAVIOUR: the system now opens on the BARCODE SCANNER (the public,
// attendance-first interface) instead of the admin login. Everything else is
// reachable from the global navigation bar, gated behind the admin session.
// ============================================================================
require_once __DIR__ . '/includes/config.php';   // defines BASE_URL (no session needed)

header('Location: ' . BASE_URL . '/scanner/attendance_scanner.php');
exit;
