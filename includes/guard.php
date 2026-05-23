<?php
// ============================================================================
// includes/guard.php — Centralized "admins only" middleware.
// ----------------------------------------------------------------------------
// Put this ONE line at the very top of any protected page (before ANY output):
//     require_once __DIR__ . '/../includes/guard.php';
//
// It boots the unified session, then redirects guests to the login page and
// blocks non-admin roles. This is the single place that defines what
// "protected" means, so access rules stay consistent across every subsystem.
// (The scanner page deliberately does NOT include this — it is the public,
//  default landing page.)
// ============================================================================

require_once __DIR__ . '/auth.php';

requireRole('admin', 'super_admin');
