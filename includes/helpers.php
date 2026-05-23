<?php
// ============================================================================
// includes/helpers.php — Shared utilities (deduped from all subsystems)
// No DB or session here — pure helpers, safe to include anywhere.
// ============================================================================

/** HTML-escape. Use on every value echoed into markup. */
function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** CSS class for an attendance/dispute status badge (from Transaction group). */
function statusClass(string $s): string {
    return match ($s) {
        'Present'      => 'badge-present',
        'Late'         => 'badge-late',
        'Absent'       => 'badge-absent',
        'Approved'     => 'badge-approved',
        'Rejected'     => 'badge-rejected',
        'Pending'      => 'badge-pending',
        'Under Review' => 'badge-review',
        default        => 'badge-default',
    };
}

/** "5m ago" style relative time. */
function timeAgo(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)    return 'just now';
    if ($diff < 3600)  return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}

/** Two-letter initials from a full name (for avatars). */
function initials(string $name): string {
    $out = '';
    foreach (explode(' ', trim($name)) as $p) {
        if ($p !== '') $out .= strtoupper($p[0]);
    }
    return substr($out, 0, 2);
}

/** Normalise a course code so "IT15", "it 15", "IT 15" all match. */
function normalizeCode(string $code): string {
    return strtoupper(preg_replace('/\s+/', '', trim($code)));
}
