<?php
// ============================================================================
// profiles/db.php — RECREATED as a bridge to the unified foundation.
// (The original file was missing, which made crud.php fatal on require.)
// crud.php expects a $pdo PDO handle — we hand it the shared one.
// ============================================================================
require_once __DIR__ . '/../includes/auth.php';   // session, db(), requireRole, helpers

// Profiles is admin-managed data → guard it.
requireRole('admin', 'super_admin');

// crud.php and index.php use $pdo directly.
$pdo = db();
