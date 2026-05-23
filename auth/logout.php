<?php
// ============================================================================
// logout.php — Single unified logout (replaces all per-subsystem logouts)
// ============================================================================
require_once __DIR__ . '/../includes/auth.php';
logoutUser();
