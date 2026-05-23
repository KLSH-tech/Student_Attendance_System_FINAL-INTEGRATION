<?php
// layout.php for Teacher Portal
$pageTitle = $pageTitle ?? 'Teacher Portal';
$activePage = $activePage ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - Teacher Portal</title>
    <style>
        :root {
            --accent: #0369a1;
            --accent-light: #0ea5e9;
            --accent-dark: #0c4a6e;
            --accent-subtle: #e0f2fe;
            --success: #059669;
            --warning: #d97706;
            --danger: #dc2626;
            --dark: #0f172a;
            --slate: #334155;
            --muted: #64748b;
            --border: #e2e8f0;
            --bg-page: #f0f9ff;
            --bg-card: #ffffff;
            --bg-subtle: #f8fafc;
            --radius: 12px;
            --shadow: 0 4px 12px rgba(0,0,0,.09);
            --mono: 'Courier New', monospace;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: var(--bg-page);
            color: var(--dark);
            line-height: 1.5;
        }
        .app-header {
            background: white;
            border-bottom: 1px solid var(--border);
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .logo {
            font-size: 18px;
            font-weight: 700;
            color: var(--accent);
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .nav-bar {
            background: var(--accent-dark);
            padding: 0 24px;
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }
        .nav-link {
            padding: 12px 20px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .nav-link:hover, .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        .main-content {
            padding: 24px;
            max-width: 1400px;
            margin: 0 auto;
        }
        .card {
            background: var(--bg-card);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            overflow: hidden;
            box-shadow: var(--shadow);
        }
        .card-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            background: var(--bg-subtle);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .card-header h3 {
            font-size: 16px;
            font-weight: 600;
        }
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: white;
            border-radius: var(--radius);
            padding: 20px;
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
        }
        .stat-card.c-blue::before { background: var(--accent); }
        .stat-card.c-green::before { background: var(--success); }
        .stat-card.c-amber::before { background: var(--warning); }
        .stat-card.c-red::before { background: var(--danger); }
        .stat-card.c-purple::before { background: #7c3aed; }
        .stat-num {
            font-size: 32px;
            font-weight: 700;
            font-family: var(--mono);
        }
        .stat-label {
            font-size: 12px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-top: 4px;
        }
        .stat-trend {
            font-size: 12px;
            color: var(--muted);
            margin-top: 8px;
        }
        .stat-icon-display {
            position: absolute;
            top: 18px;
            right: 20px;
            font-size: 28px;
            opacity: 0.12;
        }
        .page-header {
            margin-bottom: 24px;
        }
        .page-header h2 {
            font-size: 24px;
            font-weight: 700;
        }
        .page-header p {
            color: var(--muted);
            margin-top: 4px;
        }
        .btn {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            border: none;
        }
        .btn-primary {
            background: var(--accent);
            color: white;
        }
        .btn-primary:hover {
            background: var(--accent-dark);
        }
        .btn-ghost {
            background: var(--bg-subtle);
            color: var(--slate);
            border: 1px solid var(--border);
        }
        .btn-export {
            background: #16a34a;
            color: white;
        }
        .btn-sm {
            padding: 4px 8px;
            font-size: 11px;
        }
        .table-wrap {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        th {
            background: var(--dark);
            color: white;
            font-weight: 600;
        }
        .mono {
            font-family: var(--mono);
            font-size: 12px;
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-present, .badge-approved { background: #d1fae5; color: #065f46; }
        .badge-late, .badge-pending { background: #fed7aa; color: #9a3412; }
        .badge-absent, .badge-rejected { background: #fee2e2; color: #991b1b; }
        .badge-default { background: #f1f5f9; color: #475569; }
        .badge-review { background: #ede9fe; color: #5b21b6; }
        .empty-state {
            text-align: center;
            padding: 60px 24px;
            color: var(--muted);
        }
        .empty-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }
        .dash-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
        }
        .filter-bar {
            display: flex;
            gap: 12px;
            align-items: flex-end;
            flex-wrap: wrap;
            padding: 18px 24px;
            background: var(--bg-subtle);
            border-bottom: 1px solid var(--border);
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .filter-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--muted);
        }
        .filter-input, .filter-select {
            padding: 8px 12px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
            background: white;
        }
        .alert {
            padding: 14px 18px;
            border-radius: var(--radius);
            margin-bottom: 20px;
        }
        .alert-warning {
            background: #fef3c7;
            color: #78350f;
            border-left: 4px solid var(--warning);
        }
        .profile-banner {
            background: linear-gradient(135deg, var(--accent-dark), var(--accent));
            border-radius: var(--radius);
            padding: 24px;
            color: white;
            display: flex;
            align-items: center;
            gap: 24px;
            margin-bottom: 24px;
        }
        .profile-big-avatar {
            width: 70px;
            height: 70px;
            background: rgba(255,255,255,0.2);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: 800;
            font-family: var(--mono);
        }
        .profile-meta {
            display: flex;
            gap: 20px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        .profile-meta-label {
            font-size: 10px;
            opacity: 0.7;
            text-transform: uppercase;
        }
        .profile-meta-value {
            font-size: 13px;
            font-weight: 600;
        }
        .qstats {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }
        .qstat {
            flex: 1;
            background: var(--bg-subtle);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px;
            text-align: center;
        }
        .qstat-num {
            font-size: 28px;
            font-weight: 800;
            font-family: var(--mono);
        }
        .rate-good { background: #d1fae5; color: #065f46; }
        .rate-warn { background: #fed7aa; color: #9a3412; }
        .rate-bad { background: #fee2e2; color: #991b1b; }
        .footer {
            text-align: center;
            padding: 20px;
            border-top: 1px solid var(--border);
            margin-top: 40px;
            color: var(--muted);
            font-size: 12px;
        }
        /* Two column layout for students page */
        .two-column-layout {
            display: flex;
            gap: 24px;
        }
        .subject-sidebar {
            width: 300px;
            flex-shrink: 0;
        }
        .subject-list {
            display: flex;
            flex-direction: column;
        }
        .subject-item {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border);
            text-decoration: none;
            transition: all 0.2s;
            display: block;
        }
        .subject-item:hover {
            background: var(--bg-subtle);
        }
        .subject-item.active {
            background: var(--accent-subtle);
            border-left: 3px solid var(--accent);
        }
        .subject-name {
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 4px;
        }
        .subject-details {
            display: flex;
            gap: 8px;
            margin-bottom: 4px;
        }
        .subject-details small {
            font-size: 11px;
            color: var(--muted);
        }
        .subject-schedule small {
            font-size: 10px;
            color: var(--accent);
        }
        .student-content {
            flex: 1;
        }
        .student-count {
            background: var(--accent-subtle);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
        }
        .student-table th,
        .student-table td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
            text-align: left;
        }
        .student-table th {
            background: var(--dark);
            color: white;
        }
    </style>
</head>
<body>
    <header class="app-header">
        <div class="logo">📚 Teacher Portal | Southland College</div>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['t_name'] ?? $_SESSION['full_name'] ?? 'Teacher'); ?></span>
            <a href="../auth/logout.php" class="btn btn-primary">Logout</a>
        </div>
    </header>

    <nav class="nav-bar">
        <a href="../scanner/attendance_scanner.php" class="nav-link" target="_blank">
    <span>🏷️</span> Barcode Scanner
        S</a>
        <a href="dashboard.php" class="nav-link <?php echo $activePage === 'dashboard' ? 'active' : ''; ?>">
            <span>📊</span> Dashboard
        </a>
        <a href="students.php" class="nav-link <?php echo $activePage === 'students' ? 'active' : ''; ?>">
            <span>👥</span> Students by Subject
        </a>
        <a href="attendance.php" class="nav-link <?php echo $activePage === 'attendance' ? 'active' : ''; ?>">
            <span>📅</span> Attendance
        </a>
        <a href="disputes.php" class="nav-link <?php echo $activePage === 'disputes' ? 'active' : ''; ?>">
            <span>📋</span> Disputes
        </a>
        <a href="reports.php" class="nav-link <?php echo $activePage === 'reports' ? 'active' : ''; ?>">
            <span>📊</span> Reports
        </a>
    </nav>
    
    <main class="main-content">