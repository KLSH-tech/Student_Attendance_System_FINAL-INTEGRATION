<?php
// ============================================================================
// includes/nav.php — Global navigation bar (reusable component)
// ----------------------------------------------------------------------------
// Drop-in header used across the public scanner + the unified admin subsystems.
//   • Scanner is PUBLIC. Every other module requires an admin session.
//   • When logged out → the "Admin Login" button glows (call to action).
//   • When logged in  → it becomes a Logout button + shows the admin name/role.
//   • Self-contained, scoped styles (.gnav*) so it never clashes with a page's
//     own CSS, and it carries its OWN dark palette so it looks right on both
//     the dark scanner theme and the light admin/bootstrap themes.
//
// Usage (place right after <body>):
//     require_once __DIR__ . '/../includes/nav.php';
//     renderNav('profiles');   // pass the active page key (see $items below)
// ============================================================================

require_once __DIR__ . '/auth.php';   // BASE_URL, isLoggedIn(), currentUser(), e()

if (!function_exists('renderNav')) {

    function renderNav(string $active = ''): void
    {
        $loggedIn = isLoggedIn();
        $user     = currentUser();

        // key => [label, path, public?]
        $items = [
            'scanner'       => ['Scanner',       BASE_URL . '/scanner/attendance_scanner.php', true],
            'dashboard'     => ['Dashboard',     BASE_URL . '/admin/dashboard.php',            false],
            'profiles'      => ['Profiles',      BASE_URL . '/profiles/index.php',             false],
            'scheduling'    => ['Scheduling',    BASE_URL . '/scheduling/index.php',           false],
            'reports'       => ['Reports',       BASE_URL . '/reports/index.php',              false],
            'notifications' => ['Notifications', BASE_URL . '/notification/index.php',         false],
            'transactions'  => ['Transactions',  BASE_URL . '/transactions/dashboard.php',     false],
        ];

        // Print the scoped stylesheet only once per request.
        static $cssPrinted = false;
        if (!$cssPrinted) {
            $cssPrinted = true;
            echo self_navStyles();
        }
        ?>
        <nav class="gnav" id="gnav">
          <a class="gnav-brand" href="<?php echo e($items['scanner'][1]); ?>">
            SCAN<span>TRACK</span>
          </a>

          <button class="gnav-toggle" type="button" aria-label="Toggle menu"
                  onclick="document.getElementById('gnav').classList.toggle('gnav-open')">
            <span></span><span></span><span></span>
          </button>

          <div class="gnav-links">
            <?php foreach ($items as $key => [$label, $href, $isPublic]): ?>
              <?php
                $classes = 'gnav-link';
                if ($key === $active)            { $classes .= ' gnav-active'; }
                if (!$isPublic && !$loggedIn)    { $classes .= ' gnav-locked'; }
              ?>
              <a class="<?php echo $classes; ?>" href="<?php echo e($href); ?>">
                <?php echo e($label); ?><?php if (!$isPublic && !$loggedIn): ?><span class="gnav-lock" title="Admin login required">&#128274;</span><?php endif; ?>
              </a>
            <?php endforeach; ?>

            <?php if ($loggedIn): ?>
              <span class="gnav-user" title="Signed in">
                <span class="gnav-user-name"><?php echo e($user['name']); ?></span>
                <span class="gnav-user-role"><?php echo e($user['role'] ?? ''); ?></span>
              </span>
              <a class="gnav-auth gnav-logout" href="<?php echo e(BASE_URL . '/auth/logout.php'); ?>">Logout</a>
            <?php else: ?>
              <a class="gnav-auth gnav-login-glow <?php echo $active === 'login' ? 'gnav-active' : ''; ?>"
                 href="<?php echo e(BASE_URL . '/auth/login.php'); ?>">Admin&nbsp;Login</a>
            <?php endif; ?>
          </div>
        </nav>
        <?php
    }

    /** Scoped, self-contained styles for the global nav. */
    function self_navStyles(): string
    {
        return <<<CSS
<style id="gnav-styles">
  .gnav{position:sticky;top:0;z-index:9999;display:flex;align-items:center;gap:18px;
        width:100%;box-sizing:border-box;padding:10px 22px;
        background:rgba(8,15,28,.92);backdrop-filter:blur(10px);
        border-bottom:1px solid #14304f;font-family:'Segoe UI',system-ui,sans-serif;}
  .gnav *{box-sizing:border-box;}
  .gnav-brand{font-weight:900;letter-spacing:.18em;font-size:.95rem;color:#00c8ff;
              text-decoration:none;white-space:nowrap;text-shadow:0 0 14px rgba(0,200,255,.45);}
  .gnav-brand span{color:#00ff9d;}
  .gnav-links{display:flex;align-items:center;gap:6px;margin-left:auto;flex-wrap:wrap;}
  .gnav-link{position:relative;color:#bcd6ec;text-decoration:none;font-size:.82rem;font-weight:600;
             padding:8px 12px;border-radius:8px;letter-spacing:.02em;white-space:nowrap;
             transition:background .15s,color .15s;}
  .gnav-link:hover{background:rgba(0,200,255,.10);color:#eaf6ff;}
  .gnav-active{background:rgba(0,200,255,.16);color:#eaf6ff;}
  .gnav-locked{opacity:.62;}
  .gnav-lock{font-size:.62rem;margin-left:5px;vertical-align:middle;}
  .gnav-user{display:flex;flex-direction:column;line-height:1.1;text-align:right;
             margin-left:8px;padding-right:6px;}
  .gnav-user-name{color:#eaf6ff;font-size:.8rem;font-weight:700;}
  .gnav-user-role{color:#5f87a8;font-size:.66rem;text-transform:uppercase;letter-spacing:.08em;}
  .gnav-auth{text-decoration:none;font-size:.82rem;font-weight:700;padding:8px 16px;border-radius:8px;white-space:nowrap;}
  .gnav-logout{background:#1e2f45;color:#cfe6ff;border:1px solid #2b4a6b;}
  .gnav-logout:hover{background:#26405f;}
  .gnav-login-glow{background:#00c8ff;color:#04263a;border:0;animation:gnavPulse 1.8s ease-in-out infinite;}
  .gnav-login-glow:hover{background:#3ad4ff;}
  @keyframes gnavPulse{
    0%,100%{box-shadow:0 0 0 0 rgba(0,200,255,.55);}
    50%    {box-shadow:0 0 16px 4px rgba(0,200,255,.55);}
  }
  .gnav-toggle{display:none;flex-direction:column;gap:4px;background:none;border:0;cursor:pointer;padding:6px;margin-left:auto;}
  .gnav-toggle span{width:22px;height:2px;background:#bcd6ec;border-radius:2px;}
  @media(max-width:820px){
    .gnav-toggle{display:flex;}
    .gnav-links{display:none;width:100%;flex-direction:column;align-items:stretch;margin-left:0;margin-top:8px;}
    .gnav.gnav-open .gnav-links{display:flex;}
    .gnav{flex-wrap:wrap;}
    .gnav-link,.gnav-auth{width:100%;}
    .gnav-user{flex-direction:row;gap:8px;align-items:baseline;text-align:left;margin:6px 0;}
  }
</style>
CSS;
    }
}
