<?php
// Shared Sidebar for admin, staff and user
$sidebarUserName = $_SESSION['user_name'] ?? 'User';
$sidebarUserRole = $_SESSION['user_role'] ?? 'user';
$sidebarUserAvatar = $_SESSION['user_avatar'] ?? null;

// Get initials for avatar fallback
$sidebarInitials = '';
$sidebarNameParts = explode(' ', $sidebarUserName);
foreach ($sidebarNameParts as $part) {
    $sidebarInitials .= strtoupper(substr($part, 0, 1));
}
$sidebarInitials = substr($sidebarInitials, 0, 2);

// Build base URL for role pages (use APP_URL from config if available)
$appBase = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
$roleBase = $appBase . '/' . $sidebarUserRole . '/pages';

// Current script filename for active link detection
$currentFile = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Define role-specific links
$links = [];
if ($sidebarUserRole === 'admin') {
    $links = [
        ['label' => 'Dashboard', 'icon' => 'dashboard.php', 'href' => $roleBase . '/dashboard.php', 'file' => 'dashboard.php'],
        ['label' => 'Services', 'icon' => 'services.php', 'href' => $roleBase . '/services.php', 'file' => 'services.php'],
        ['label' => 'User Management', 'icon' => 'user-management.php', 'href' => $roleBase . '/user-management.php', 'file' => 'user-management.php'],
        ['label' => 'Appointments', 'icon' => 'appointments.php', 'href' => $roleBase . '/appointments.php', 'file' => 'appointments.php'],
        ['label' => 'Walk-in Calendar', 'icon' => 'walkin-calendar.php', 'href' => $roleBase . '/walkin-calendar.php', 'file' => 'walkin-calendar.php'],
        ['label' => 'Profile', 'icon' => 'profile.php', 'href' => $roleBase . '/profile.php', 'file' => 'profile.php'],
    ];
} elseif ($sidebarUserRole === 'staff') {
    $links = [
        ['label' => 'Dashboard', 'icon' => 'dashboard.php', 'href' => $roleBase . '/dashboard.php', 'file' => 'dashboard.php'],
        ['label' => 'QR Scanner', 'icon' => 'scanner.php', 'href' => $roleBase . '/scanner.php', 'file' => 'scanner.php'],
        ['label' => 'Appointments', 'icon' => 'appointments.php', 'href' => $roleBase . '/appointments.php', 'file' => 'appointments.php'],
        ['label' => 'Walk-in Calendar', 'icon' => 'walkin-calendar.php', 'href' => $roleBase . '/walkin-calendar.php', 'file' => 'walkin-calendar.php'],
        ['label' => 'Profile', 'icon' => 'profile.php', 'href' => $roleBase . '/profile.php', 'file' => 'profile.php'],
    ];
} else {
    // default to regular user
    $links = [
        ['label' => 'Dashboard', 'icon' => 'dashboard.php', 'href' => $roleBase . '/dashboard.php', 'file' => 'dashboard.php'],
        ['label' => 'Book Appointment', 'icon' => 'appointment.php', 'href' => $roleBase . '/appointment.php', 'file' => 'appointment.php'],
        ['label' => 'My Appointments', 'icon' => 'my-appointments.php', 'href' => $roleBase . '/my-appointments.php', 'file' => 'my-appointments.php'],
        ['label' => 'Profile', 'icon' => 'profile.php', 'href' => $roleBase . '/profile.php', 'file' => 'profile.php'],
    ];
}

?>
<aside class="sidebar" id="sidebar">
    <!-- User Profile in Sidebar -->
    <div class="sidebar-user">
        <div class="sidebar-avatar">
                <?php
                // Build absolute URL for avatar if needed
                function _build_sidebar_asset_url($path) {
                    if (empty($path)) return '';
                    if (preg_match('#^https?://#i', $path)) return $path;
                    $base = rtrim(APP_URL, '/');
                    if (strpos($path, '/') === 0) return $base . $path;
                    return $base . '/' . ltrim($path, '/');
                }
                $sidebarAvatarUrl = $sidebarUserAvatar ? _build_sidebar_asset_url($sidebarUserAvatar) : '';
                ?>
                <?php if ($sidebarAvatarUrl): ?>
                    <img src="<?php echo htmlspecialchars($sidebarAvatarUrl); ?>" alt="<?php echo htmlspecialchars($sidebarUserName); ?>">
                <?php else: ?>
                    <span class="avatar-initials"><?php echo $sidebarInitials; ?></span>
                <?php endif; ?>
            </div>
        <div class="sidebar-user-info">
            <span class="sidebar-user-name"><?php echo htmlspecialchars($sidebarUserName); ?></span>
            <span class="sidebar-user-role"><?php echo ucfirst($sidebarUserRole); ?></span>
        </div>
    </div>
    
    <nav class="sidebar-nav">
        <ul class="nav-menu">
            <?php foreach ($links as $link): ?>
                <li class="nav-item">
                    <a href="<?php echo htmlspecialchars($link['href']); ?>" class="nav-link <?php echo $currentFile === $link['file'] ? 'active' : ''; ?>">
                        <span class="nav-icon">
                                <?php
                                switch ($link['file']) {
                                    case 'dashboard.php':
                                        ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="3" width="7" height="9"></rect>
                                            <rect x="14" y="3" width="7" height="5"></rect>
                                            <rect x="14" y="12" width="7" height="9"></rect>
                                            <rect x="3" y="16" width="7" height="5"></rect>
                                        </svg>
                                        <?php
                                        break;
                                    case 'services.php':
                                        ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M14.7 6.3a7 7 0 0 1 3 3L21 6l-3.7 0.7a7 7 0 0 1-2.6-0.4z"></path>
                                            <path d="M3 21l4-4"></path>
                                            <path d="M7 17l6-6"></path>
                                        </svg>
                                        <?php
                                        break;
                                    case 'user-management.php':
                                        ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"></path>
                                            <circle cx="11" cy="7" r="4"></circle>
                                            <path d="M21 21v-2"></path>
                                            <path d="M21 11v-2"></path>
                                        </svg>
                                        <?php
                                        break;
                                    case 'appointments.php':
                                    case 'appointment.php':
                                        ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                            <line x1="16" y1="2" x2="16" y2="6"></line>
                                            <line x1="8" y1="2" x2="8" y2="6"></line>
                                            <line x1="3" y1="10" x2="21" y2="10"></line>
                                        </svg>
                                        <?php
                                        break;
                                    case 'walkin-calendar.php':
                                        ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                            <line x1="16" y1="2" x2="16" y2="6"></line>
                                            <line x1="8" y1="2" x2="8" y2="6"></line>
                                            <line x1="3" y1="10" x2="21" y2="10"></line>
                                            <path d="M8 14h.01"></path>
                                            <path d="M12 14h.01"></path>
                                            <path d="M16 14h.01"></path>
                                            <path d="M8 18h.01"></path>
                                            <path d="M12 18h.01"></path>
                                            <path d="M16 18h.01"></path>
                                        </svg>
                                        <?php
                                        break;
                                    case 'my-appointments.php':
                                        ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="10"></circle>
                                            <polyline points="12 6 12 12 16 14"></polyline>
                                        </svg>
                                        <?php
                                        break;
                                    case 'scanner.php':
                                        ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="3" width="7" height="7"></rect>
                                            <rect x="14" y="3" width="7" height="7"></rect>
                                            <rect x="3" y="14" width="7" height="7"></rect>
                                            <rect x="14" y="14" width="3" height="3"></rect>
                                            <line x1="21" y1="14" x2="21" y2="14.01"></line>
                                            <line x1="21" y1="21" x2="21" y2="21.01"></line>
                                            <line x1="14" y1="21" x2="14" y2="21.01"></line>
                                        </svg>
                                        <?php
                                        break;
                                    case 'profile.php':
                                    default:
                                        ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                            <circle cx="12" cy="7" r="4"></circle>
                                        </svg>
                                        <?php
                                        break;
                                }
                                ?>
                            </span>
                        <span class="nav-text"><?php echo htmlspecialchars($link['label']); ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>
    
    <div class="sidebar-footer">
        <a href="../../auth.php?action=logout" class="logout-btn">
            <span class="nav-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                    <polyline points="16 17 21 12 16 7"></polyline>
                    <line x1="21" y1="12" x2="9" y2="12"></line>
                </svg>
            </span>
            <span>Logout</span>
        </a>
    </div>
</aside>
