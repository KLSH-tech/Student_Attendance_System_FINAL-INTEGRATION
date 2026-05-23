# Integration Notes — Landing Page, Global Nav & Admin Access Control

This refactor changes **how the system is entered and protected**, without
rewriting any working subsystem logic.

---

## 1. What changed at a glance

| Goal | How it was met |
|------|----------------|
| Landing page = **Scanner** | Root `index.php` now redirects to `scanner/attendance_scanner.php` |
| **Global navigation bar** | New reusable component `includes/nav.php` (`renderNav()`) |
| **Scanner public, everything else admin-only** | New `includes/guard.php` + per-page guards |
| **Admin Login highlight / Logout swap** | Built into the nav (glowing button → Logout + name/role) |
| **Don't break working features** | No working logic touched; all 45 PHP files pass `php -l` |

**New files:** `includes/nav.php`, `includes/guard.php`.

---

## 2. The foundation was already there

The project already had a clean, centralized layer in `includes/`:

- `config.php` — single DB credentials + auto-detected `BASE_URL` (handles the
  spaces in the folder name).
- `db.php` — one shared PDO via `db()`.
- `auth.php` — one session (`SAMS_SESSION`), `isLoggedIn()`, `requireRole()`,
  `attemptLogin()`, CSRF.
- `helpers.php` — `e()`, `statusClass()`, etc.

The refactor **reuses** this layer rather than introducing anything parallel —
no new/duplicate DB connections were created.

---

## 3. Access-control matrix

| Area | Access | Enforced by |
|------|--------|-------------|
| `scanner/attendance_scanner.php` | **Public** | (intentionally no guard) |
| `auth/login.php`, `auth/logout.php` | Public | login/logout pages |
| `transactions/index.php`, `transactions/teacher-login.php` | Public | they *are* login pages |
| `admin/*` | Admin | `require_admin()` → `requireRole('admin','super_admin')` |
| `profiles/*` | Admin | `requireRole(...)` / JSON-403 in `crud.php` |
| `reports/index.php` | Admin | **added** `requireRole(...)` (was unprotected) |
| `reports/reports.php` | Admin | **added** `guard.php` |
| `reports/api.php` | Admin | **added** JSON-403 guard |
| `scheduling/index.php` | Admin | `requireRole(...)` |
| `scheduling/schedule.php`, `add_schedule.php`, `edit_schedule.php` | Admin | **added** `guard.php` |
| `notification/index.php`, `notification/attendance_report.php` | Admin | **added** `guard.php` |
| `transactions/*` (dashboard, students, attendance, disputes, reports, …) | Admin/Teacher | `requireTeacher()` (now bridged to the unified session) |
| `transactions/setup.php` | Admin | **added** `requireTeacher()` (it runs `CREATE TABLE`/seeds) |

Guests who hit any protected URL are redirected to the unified login.

---

## 4. The global navigation bar (`includes/nav.php`)

- Links: **Scanner · Dashboard · Profiles · Scheduling · Reports · Notifications · Transactions** + auth control.
- **Logged out:** the *Admin Login* button **glows** (pulsing accent); protected
  links show a 🔒.
- **Logged in:** that button becomes **Logout**, and the admin's **name + role**
  are shown.
- Self-contained, scoped styles (`.gnav*`) + its own dark palette, so it drops
  onto both the dark scanner theme and the light admin pages without clashing.
  Responsive (collapses to a toggle under 820px).

Injected into: scanner, admin dashboard, profiles, scheduling, reports,
notification. **Not** injected into the Transaction portal (that subsystem keeps
its own sidebar and a different `BASE_URL`); a relative *“Scanner (Home)”* link
was added to its sidebar instead.

Usage on any unified page:
```php
<?php require_once __DIR__ . '/../includes/nav.php'; renderNav('reports'); ?>
```

---

## 5. Transaction portal bridge (why it was broken, how it's fixed)

Most `transactions/*` pages were including the unified `includes/config.php`
(constants only) and then calling `requireTeacher()`/`db()` — which live in the
**local** `transactions/config.php`. Result: fatal “undefined function”.

Fix (mirrors the existing `admin/config.php` bridge pattern):
- Pages now load their **local** `config.php` again (like `attendance.php` always did).
- `transactions/config.php` now shares the unified session name (`SAMS_SESSION`),
  so **one admin login flows into the portal**.
- `isTeacher()` accepts a unified `admin`/`super_admin`/`teacher` session;
  `requireTeacher()` sends guests to the single unified login.
- DB/`BASE_URL` defines are `!defined()`-guarded to avoid any clash.

---

## 6. Small correctness fixes (low-risk)

- `roleHome()` and `admin/config.php` used `/Admin/…` (capital A) but the folder
  is `admin` → fixed to `/admin/…` (broke redirects on case-sensitive servers).
- Converted leading `<?` short tags to `<?php` in `profiles/index.php` and
  `scheduling/index.php` (works regardless of `short_open_tag`).
- Fixed stylesheet paths (`/assets/style.css` → `/assets/css/style.css`) in
  `auth/login.php` and `admin/subsystem.php`.

---

## 7. Known caveat (pre-existing — NOT introduced here)

Several **legacy** pages still point at databases that do **not** exist in the
provided dump (only `student_attendance_system` is created):

- `scheduling/schedule.php`, `add_schedule.php`, `edit_schedule.php` → `school_db`
- `reports/reports.php`, `reports/api.php` → `G6_reports_db`
- `notification/*` → `attendance_system_db`

These are now **access-protected**, but they will still error for an admin until
their data is migrated into `student_attendance_system` — the migration step the
schema file itself flags as the next task. That migration was intentionally left
out of scope (it changes data, not architecture).

---

## 8. How to test

1. Visit the project root → you land on the **Scanner** (no login needed).
2. Scan/enter a student number → attendance still records (unchanged logic).
3. Click any other nav item while logged out → redirected to **login**.
4. Log in as the seeded admin (`admin`, `super_admin`) → nav shows **Logout +
   name/role**, and Dashboard/Profiles/Scheduling/Reports become reachable.
5. Try a protected URL directly while logged out (e.g. `/reports/index.php`) →
   redirected to login.
