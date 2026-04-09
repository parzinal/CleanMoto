<?php
/**
 * Shared Dashboard Header Component
 * Used by admin, staff and user pages to render a consistent topbar
 */

// Make sure session is started and user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

$userName = $_SESSION['user_name'] ?? 'User';
$userEmail = $_SESSION['user_email'] ?? '';
$userRole = $_SESSION['user_role'] ?? 'user';
$userAvatar = $_SESSION['user_avatar'] ?? null;

// Helper to build absolute URLs for assets/uploads
function _build_asset_url($path) {
    if (empty($path)) return '';
    // If already an absolute URL, return as-is
    if (preg_match('#^https?://#i', $path)) return $path;
    // If path starts with a slash, append to APP_URL base
    $base = rtrim(APP_URL, '/');
    if (strpos($path, '/') === 0) {
        return $base . $path;
    }
    return $base . '/' . ltrim($path, '/');
}

// Normalize a notification/link URL to an absolute URL using APP_URL
function normalize_url($link) {
    if (empty($link)) return '#';
    if (preg_match('#^https?://#i', $link)) return $link;
    $base = rtrim(APP_URL, '/');
    if (strpos($link, '/') === 0) return $base . $link;
    return $base . '/' . ltrim($link, '/');
}

$userAvatarUrl = $userAvatar ? _build_asset_url($userAvatar) : '';

// DB connection for notifications
$db = null;
try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    // leave $db null — notifications will fallback to empty
}

// Prepare notifications based on notifications table if present, otherwise fallback to appointments
$notifications = [];
$unreadCount = 0;
if ($db) {
    try {
        // check if notifications table exists
        $hasNotificationsTable = false;
        try {
            $chk = $db->query("SELECT 1 FROM notifications LIMIT 1");
            $hasNotificationsTable = true;
        } catch (Exception $ee) { $hasNotificationsTable = false; }

        $uid = $_SESSION['user_id'] ?? 0;
        if ($hasNotificationsTable) {
            if ($userRole === 'admin') {
                // Admins see all recent notifications
                $stmt = $db->prepare("SELECT * FROM notifications ORDER BY created_at DESC LIMIT 6");
                $stmt->execute();
                $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $countStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE is_read = 0");
                $countStmt->execute();
                $unreadCount = (int) $countStmt->fetchColumn();
            } else {
                // fetch notifications targeted to this user or role or global (only if role matches)
                // logic: include rows where
                //  - user_id is null AND (role is null OR role = :role)  --> global for this role
                //  - OR user_id = :uid
                //  - OR role = :role2
                $stmt = $db->prepare("SELECT * FROM notifications WHERE ((user_id IS NULL AND (role IS NULL OR role = :role)) OR user_id = :uid OR role = :role2) ORDER BY created_at DESC LIMIT 6");
                $stmt->execute([':uid' => $uid, ':role' => $userRole, ':role2' => $userRole]);
                $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $countStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND ((user_id IS NULL AND (role IS NULL OR role = :role)) OR user_id = :uid OR role = :role2)");
                $countStmt->execute([':uid' => $uid, ':role' => $userRole, ':role2' => $userRole]);
                $unreadCount = (int) $countStmt->fetchColumn();
            }
        } else {
            if (in_array($userRole, ['staff', 'admin'])) {
                // For staff/admin show recent pending check-ins or recent activity
                $stmt = $db->prepare("SELECT a.id, a.reference, a.status, a.scheduled_at, u.name as user_name, a.created_at FROM appointments a LEFT JOIN users u ON a.user_id = u.id WHERE a.status IN ('pending','checked_in') ORDER BY a.created_at DESC LIMIT 6");
                $stmt->execute();
                $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $countStmt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE status = 'pending'");
                $countStmt->execute();
                $unreadCount = (int) $countStmt->fetchColumn();
            } else {
                // For regular users show recent personal appointment updates
                $stmt = $db->prepare("SELECT id, reference, status, scheduled_at, created_at FROM appointments WHERE user_id = ? ORDER BY created_at DESC LIMIT 6");
                $stmt->execute([$uid]);
                $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
                // unread = upcoming confirmed appointments
                $countStmt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE user_id = ? AND status IN ('confirmed','upcoming')");
                $countStmt->execute([$uid]);
                $unreadCount = (int) $countStmt->fetchColumn();
            }
        }
    } catch (Exception $e) {
        // ignore, leave notifications empty
    }
}

// Get initials for avatar fallback
$initials = '';
$nameParts = explode(' ', $userName);
foreach ($nameParts as $part) {
    $initials .= strtoupper(substr($part, 0, 1));
}
$initials = substr($initials, 0, 2);
?>

<header class="dashboard-topbar">
    <div class="topbar-left">
        <!-- Mobile Toggle Button -->
        <button class="sidebar-toggle-btn" onclick="toggleSidebar()" title="Toggle Sidebar">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="3" y1="12" x2="21" y2="12"></line>
                <line x1="3" y1="6" x2="21" y2="6"></line>
                <line x1="3" y1="18" x2="21" y2="18"></line>
            </svg>
        </button>
        <!-- Desktop Collapse Button -->
        <button class="sidebar-collapse-btn" id="sidebarCollapseBtn" onclick="toggleSidebarCollapse()" title="Toggle Sidebar">
            <svg class="collapse-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="11 17 6 12 11 7"></polyline>
                <polyline points="18 17 13 12 18 7"></polyline>
            </svg>
        </button>
        <a href="<?php echo APP_URL; ?>" class="topbar-brand" style="display:flex;align-items:center;gap:12px;text-decoration:none;">
            <img src="<?php echo APP_URL; ?>/assets/image/CleanMoto_Logo.png" alt="<?php echo APP_NAME; ?>" style="height:36px;">
            <h2 class="topbar-title"><?php echo APP_NAME; ?></h2>
        </a>
    </div>
    
    <div class="topbar-right">
        <!-- Theme Toggle -->
        <div class="topbar-theme-toggle">
            <button class="theme-toggle-btn" onclick="toggleTheme()" title="Toggle Theme">
                <svg class="sun-icon" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="5"></circle>
                    <line x1="12" y1="1" x2="12" y2="3"></line>
                    <line x1="12" y1="21" x2="12" y2="23"></line>
                    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                    <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                    <line x1="1" y1="12" x2="3" y2="12"></line>
                    <line x1="21" y1="12" x2="23" y2="12"></line>
                    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                    <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
                </svg>
                <svg class="moon-icon" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                </svg>
            </button>
        </div>
        
        <!-- Notification Bell -->
        <div class="topbar-notification">
            <button class="notification-btn" onclick="toggleNotifications()">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                </svg>
                <span class="notification-badge"><?php echo $unreadCount > 0 ? $unreadCount : ''; ?></span>
            </button>
            
            <!-- Notifications Dropdown -->
            <div class="notification-dropdown" id="notificationDropdown">
                <div class="dropdown-header">
                    <h4>Notifications</h4>
                    <a href="#" id="markAllNotifications">Mark all as read</a>
                </div>
                <div class="dropdown-body">
                    <?php if (!empty($notifications)): ?>
                        <?php foreach ($notifications as $n): ?>
                            <?php
                                // Determine unread using is_read when available
                                if (isset($n['is_read'])) {
                                    $isUnread = ($n['is_read'] == 0);
                                } else {
                                    $isUnread = in_array($n['status'] ?? '', ['pending','confirmed','upcoming','checked_in']);
                                }

                                // Time to show
                                $time = '';
                                if (!empty($n['created_at'])) {
                                    $time = date('M j, Y H:i', strtotime($n['created_at']));
                                } elseif (!empty($n['scheduled_at'])) {
                                    $time = date('M j, Y H:i', strtotime($n['scheduled_at']));
                                }

                                // Title and subtitle
                                $title = '';
                                $subtitle = '';
                                if (!empty($n['title'])) {
                                    $title = $n['title'];
                                } elseif (!empty($n['reference'])) {
                                    $title = 'Appointment: ' . $n['reference'];
                                } elseif (!empty($n['status'])) {
                                    $title = ucfirst($n['status']);
                                } else {
                                    $title = 'Update';
                                }

                                if (!empty($n['body'])) {
                                    $subtitle = strlen($n['body']) > 120 ? substr($n['body'],0,117) . '...' : $n['body'];
                                } elseif (!empty($n['user_name'])) {
                                    $subtitle = $n['user_name'];
                                } elseif (!empty($n['status'])) {
                                    $subtitle = ucfirst($n['status']);
                                }

                                // Link if URL provided (normalize to absolute)
                                $link = !empty($n['url']) ? normalize_url($n['url']) : '#';
                            ?>
                            <div class="notification-item <?php echo $isUnread ? 'unread' : ''; ?>" data-id="<?php echo intval($n['id'] ?? 0); ?>">
                                <div class="notification-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                                        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                                    </svg>
                                </div>
                                <div class="notification-content">
                                    <p><?php echo htmlspecialchars($title); ?><?php if (!empty($subtitle)) echo ' — '.htmlspecialchars($subtitle); ?></p>
                                    <small><?php echo htmlspecialchars($time); ?></small>
                                </div>
                                <?php if ($link && $link !== '#'): ?>
                                <a href="<?php echo htmlspecialchars($link); ?>" class="btn-icon view-link" title="View"></a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="notification-item">
                            <div class="notification-icon">🔕</div>
                            <div class="notification-content">
                                <p>No notifications</p>
                                <small>You're all caught up</small>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="dropdown-footer">
                    <a href="../pages/notifications.php">View all notifications</a>
                </div>
            </div>
        </div>
        
        <!-- User Profile Dropdown -->
        <div class="topbar-profile">
            <button class="profile-btn" onclick="toggleProfileDropdown()">
                <div class="profile-avatar">
                    <?php if ($userAvatarUrl): ?>
                        <img src="<?php echo htmlspecialchars($userAvatarUrl); ?>" alt="<?php echo htmlspecialchars($userName); ?>">
                    <?php else: ?>
                        <span class="avatar-initials"><?php echo $initials; ?></span>
                    <?php endif; ?>
                </div>
                <div class="profile-info">
                    <span class="profile-name"><?php echo htmlspecialchars($userName); ?></span>
                    <span class="profile-role"><?php echo ucfirst($userRole); ?></span>
                </div>
                <svg class="dropdown-arrow" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="6 9 12 15 18 9"></polyline>
                </svg>
            </button>
            
            <!-- Profile Dropdown -->
            <div class="profile-dropdown" id="profileDropdown">
                <div class="dropdown-user-info">
                    <div class="profile-avatar large">
                        <?php if ($userAvatarUrl): ?>
                            <img src="<?php echo htmlspecialchars($userAvatarUrl); ?>" alt="<?php echo htmlspecialchars($userName); ?>">
                        <?php else: ?>
                            <span class="avatar-initials"><?php echo $initials; ?></span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <strong><?php echo htmlspecialchars($userName); ?></strong>
                        <small><?php echo htmlspecialchars($userEmail); ?></small>
                    </div>
                </div>
                <div class="dropdown-divider"></div>
                <a href="../pages/profile.php" class="dropdown-item">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                    </svg>
                    My Profile
                </a>
                <a href="../pages/settings.php" class="dropdown-item">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3"></circle>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                    </svg>
                    Settings
                </a>
                <div class="dropdown-divider"></div>
                <a href="../../auth.php?action=logout" class="dropdown-item logout">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                        <polyline points="16 17 21 12 16 7"></polyline>
                        <line x1="21" y1="12" x2="9" y2="12"></line>
                    </svg>
                    Logout
                </a>
            </div>
        </div>
    </div>
</header>

<script>
function toggleNotifications() {
    const dropdown = document.getElementById('notificationDropdown');
    const profileDropdown = document.getElementById('profileDropdown');
    profileDropdown.classList.remove('show');
    dropdown.classList.toggle('show');
}

function toggleProfileDropdown() {
    const dropdown = document.getElementById('profileDropdown');
    const notificationDropdown = document.getElementById('notificationDropdown');
    notificationDropdown.classList.remove('show');
    dropdown.classList.toggle('show');
}

document.addEventListener('click', function(e) {
    const profileBtn = document.querySelector('.profile-btn');
    const notificationBtn = document.querySelector('.notification-btn');
    const profileDropdown = document.getElementById('profileDropdown');
    const notificationDropdown = document.getElementById('notificationDropdown');
    if (profileBtn && profileDropdown && !profileBtn.contains(e.target) && !profileDropdown.contains(e.target)) {
        profileDropdown.classList.remove('show');
    }
    if (notificationBtn && notificationDropdown && !notificationBtn.contains(e.target) && !notificationDropdown.contains(e.target)) {
        notificationDropdown.classList.remove('show');
    }
});

// Notifications AJAX handlers
document.addEventListener('DOMContentLoaded', function() {
    const api = '<?php echo APP_URL; ?>/api/notifications.php';
    const badgeEl = document.querySelector('.notification-badge');
    const dropdownBody = document.querySelector('.notification-dropdown .dropdown-body');

    function updateBadge(count) {
        if (!badgeEl) return;
        badgeEl.textContent = count > 0 ? count : '';
        // Add/remove pulse animation based on unread count
        if (count > 0) {
            badgeEl.classList.add('has-unread');
        } else {
            badgeEl.classList.remove('has-unread');
        }
    }

    const APP_BASE = '<?php echo rtrim(APP_URL, '/'); ?>';

    // Refresh notifications from server
    function refreshNotifications() {
        fetch(api + '?limit=6', { 
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            if (data && data.success) {
                updateBadge(data.unread || 0);
                
                // Update dropdown content if we have notifications array
                if (dropdownBody && data.notifications) {
                    if (data.notifications.length === 0) {
                        dropdownBody.innerHTML = `
                            <div class="notification-item">
                                <div class="notification-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                                        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                                    </svg>
                                </div>
                                <div class="notification-content">
                                    <p>No notifications</p>
                                    <small>You're all caught up</small>
                                </div>
                            </div>
                        `;
                    } else {
                        let html = '';
                        data.notifications.forEach(n => {
                            const isUnread = n.is_read == 0;
                            const title = n.title || 'Notification';
                            let subtitle = n.body || '';
                            if (subtitle.length > 120) subtitle = subtitle.substring(0, 117) + '...';
                            const time = n.created_at ? new Date(n.created_at).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : '';
                            let link = n.url || '#';
                            // normalize relative links to absolute using APP_BASE
                            if (link && link !== '#' && !/^https?:\/\//i.test(link)) {
                                if (link.charAt(0) === '/') link = APP_BASE + link;
                                else link = APP_BASE + '/' + link.replace(/^\/+/, '');
                            }

                            html += `
                                <div class="notification-item ${isUnread ? 'unread' : ''}" data-id="${n.id || 0}">
                                    <div class="notification-icon">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                                            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                                        </svg>
                                    </div>
                                    <div class="notification-content">
                                        <p>${escapeHtml(title)}${subtitle ? ' — ' + escapeHtml(subtitle) : ''}</p>
                                        <small>${escapeHtml(time)}</small>
                                    </div>
                                    ${link && link !== '#' ? `<a href="${escapeHtml(link)}" class="btn-icon view-link" title="View"></a>` : ''}
                                </div>
                            `;
                        });
                        dropdownBody.innerHTML = html;
                    }
                }
            }
        })
        .catch(() => {});
    }
    
    // Helper to escape HTML
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Poll for new notifications every 10 seconds
    setInterval(refreshNotifications, 10000);
    
    // Also refresh when dropdown is opened
    const notifBtn = document.querySelector('.notification-btn');
    if (notifBtn) {
        notifBtn.addEventListener('click', function() {
            // Small delay to let dropdown open, then refresh
            setTimeout(refreshNotifications, 100);
        });
    }

    // Mark all as read
    const markAll = document.getElementById('markAllNotifications');
    if (markAll) {
        markAll.addEventListener('click', function(e) {
            e.preventDefault();
            fetch(api, { method: 'POST', body: new URLSearchParams({ action: 'mark_all_read' }) })
                .then(r => r.json()).then(data => {
                    if (data && data.success) {
                        document.querySelectorAll('.notification-item.unread').forEach(el => el.classList.remove('unread'));
                        updateBadge(0);
                    }
                }).catch(() => {});
        });
    }

    // Per-item view button: mark read then navigate
    const dropdown = document.getElementById('notificationDropdown');
    if (dropdown) {
        dropdown.addEventListener('click', function(e) {
            const viewBtn = e.target.closest('.view-link');
            if (!viewBtn) return; // only handle clicks on the view button
            e.preventDefault();
            const item = viewBtn.closest('.notification-item');
            if (!item) return;
            const id = parseInt(item.dataset.id || 0, 10);
            const href = viewBtn.getAttribute('href') || '#';
            if (id > 0 && item.classList.contains('unread')) {
                fetch(api, { method: 'POST', body: new URLSearchParams({ action: 'mark_read', id: id }) })
                    .then(r => r.json()).then(data => {
                        if (data && data.success) {
                            item.classList.remove('unread');
                            let val = parseInt(badgeEl.textContent || 0, 10) || 0;
                            val = Math.max(0, val - 1);
                            updateBadge(val);
                        }
                    }).catch(() => {}).finally(() => {
                        if (href && href !== '#') window.location.href = href;
                    });
            } else {
                if (href && href !== '#') window.location.href = href;
            }
        });
    }
});

function toggleSidebarCollapse() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.querySelector('.main-content');
    const topbar = document.querySelector('.dashboard-topbar');
    const collapseBtn = document.getElementById('sidebarCollapseBtn');
    if (sidebar) sidebar.classList.toggle('collapsed');
    if (mainContent) mainContent.classList.toggle('sidebar-collapsed');
    if (topbar) topbar.classList.toggle('sidebar-collapsed');
    if (collapseBtn) collapseBtn.classList.toggle('rotated');
    const isCollapsed = sidebar && sidebar.classList.contains('collapsed');
    localStorage.setItem('sidebarCollapsed', isCollapsed);
}

function toggleSidebar() {
    const sb = document.querySelector('.sidebar');
    const isOpen = !(sb && sb.classList.contains('open'));
    setSidebarOpenState(isOpen);
}

function closeSidebar() {
    setSidebarOpenState(false);
}

function setSidebarOpenState(isOpen) {
    const sb = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    if (sb) sb.classList.toggle('open', isOpen);
    if (overlay) overlay.classList.toggle('open', isOpen);
    if (window.matchMedia('(max-width: 768px)').matches) {
        document.body.classList.toggle('sidebar-open', isOpen);
    } else {
        document.body.classList.remove('sidebar-open');
    }
}

const DRAG_SCROLL_SELECTOR = [
    '.table-responsive-wrapper',
    '.table-responsive',
    '.filter-buttons',
    '.filter-buttons-wrap',
    '.status-tabs',
    '.section-tabs',
    '.fpills',
    '.sd-filter-btns',
    '.filter-tabs',
    '.cal-grid-scroll',
    '.twrap',
    '.sd-table-wrap'
].join(',');

function getDragScrollTargets(root = document) {
    const targets = [];
    if (root && root.nodeType === 1 && root.matches && root.matches(DRAG_SCROLL_SELECTOR)) {
        targets.push(root);
    }
    if (root && root.querySelectorAll) {
        root.querySelectorAll(DRAG_SCROLL_SELECTOR).forEach(el => targets.push(el));
    }
    return targets;
}

function refreshDragScrollState(el) {
    if (document.body && document.body.classList.contains('allow-page-x-scroll')) {
        el.classList.remove('drag-scroll-enabled');
        return;
    }

    const canScrollX = (el.scrollWidth - el.clientWidth) > 6;
    el.classList.toggle('drag-scroll-enabled', canScrollX);
}

function attachDragScroll(el) {
    if (!el || el.dataset.dragScrollInit === '1') {
        if (el) refreshDragScrollState(el);
        return;
    }

    el.dataset.dragScrollInit = '1';
    el.classList.add('drag-scroll');

    let dragging = false;
    let moved = false;
    let startX = 0;
    let startY = 0;
    let startScrollLeft = 0;
    let axisLock = '';
    let activePointerId = null;
    let touchActive = false;
    let touchMode = '';
    let touchStartX = 0;
    let touchStartY = 0;
    let touchStartScrollLeft = 0;
    let touchMoved = false;

    el.addEventListener('pointerdown', function(e) {
        if (!e.isPrimary) return;
        if (e.pointerType === 'touch') return;
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        refreshDragScrollState(el);
        if (!el.classList.contains('drag-scroll-enabled')) return;

        dragging = true;
        moved = false;
        axisLock = '';
        activePointerId = e.pointerId;
        startX = e.clientX;
        startY = e.clientY;
        startScrollLeft = el.scrollLeft;

        try {
            el.setPointerCapture(e.pointerId);
        } catch (_) {}
    });

    el.addEventListener('pointermove', function(e) {
        if (!dragging) return;
        if (activePointerId !== null && e.pointerId !== activePointerId) return;

        const deltaX = e.clientX - startX;
        const deltaY = e.clientY - startY;

        if (!axisLock) {
            if (Math.abs(deltaX) < 4 && Math.abs(deltaY) < 4) return;
            axisLock = Math.abs(deltaX) >= Math.abs(deltaY) ? 'x' : 'y';
        }

        if (axisLock !== 'x') return;

        if (Math.abs(deltaX) > 3) moved = true;
        el.classList.add('dragging');
        el.scrollLeft = startScrollLeft - deltaX;
        e.preventDefault();
    });

    function stopDragging(e) {
        if (!dragging) return;
        if (activePointerId !== null && e && typeof e.pointerId !== 'undefined' && e.pointerId !== activePointerId) {
            return;
        }
        dragging = false;
        axisLock = '';
        activePointerId = null;
        el.classList.remove('dragging');
        if (e && typeof e.pointerId !== 'undefined') {
            try {
                el.releasePointerCapture(e.pointerId);
            } catch (_) {}
        }
    }

    el.addEventListener('pointerup', stopDragging);
    el.addEventListener('pointercancel', stopDragging);
    el.addEventListener('lostpointercapture', stopDragging);

    el.addEventListener('touchstart', function(e) {
        if (!e.touches || e.touches.length !== 1) return;
        refreshDragScrollState(el);
        if (!el.classList.contains('drag-scroll-enabled')) return;

        const touch = e.touches[0];
        touchActive = true;
        touchMode = '';
        touchMoved = false;
        touchStartX = touch.clientX;
        touchStartY = touch.clientY;
        touchStartScrollLeft = el.scrollLeft;
    }, { passive: true });

    el.addEventListener('touchmove', function(e) {
        if (!touchActive || !e.touches || e.touches.length !== 1) return;

        const touch = e.touches[0];
        const deltaX = touch.clientX - touchStartX;
        const deltaY = touch.clientY - touchStartY;

        if (!touchMode) {
            if (Math.abs(deltaX) < 8 && Math.abs(deltaY) < 8) return;
            touchMode = Math.abs(deltaX) > Math.abs(deltaY) * 1.2 ? 'x' : 'y';

            // Hand control back to native vertical scrolling immediately.
            if (touchMode === 'y') {
                touchActive = false;
                return;
            }
        }

        if (touchMode !== 'x') return;

        const maxScrollLeft = Math.max(0, el.scrollWidth - el.clientWidth);
        if (maxScrollLeft <= 0) return;

        const nextScrollLeft = Math.max(0, Math.min(maxScrollLeft, touchStartScrollLeft - deltaX));
        if (nextScrollLeft !== el.scrollLeft) {
            touchMoved = true;
            el.scrollLeft = nextScrollLeft;
            e.preventDefault();
        }
    }, { passive: false });

    function stopTouchDrag() {
        touchActive = false;
        touchMode = '';
    }

    el.addEventListener('touchend', stopTouchDrag, { passive: true });
    el.addEventListener('touchcancel', stopTouchDrag, { passive: true });

    el.addEventListener('click', function(e) {
        if (moved || touchMoved) {
            e.preventDefault();
            e.stopPropagation();
            moved = false;
            touchMoved = false;
        }
    }, true);

    refreshDragScrollState(el);
}

function initDragScrollableAreas(root = document) {
    getDragScrollTargets(root).forEach(attachDragScroll);
}

function refreshDragScrollableAreas(root = document) {
    getDragScrollTargets(root).forEach(refreshDragScrollState);
}

window.initDragScrollableAreas = initDragScrollableAreas;
window.refreshDragScrollableAreas = refreshDragScrollableAreas;

// Theme Toggle Function
function toggleTheme() {
    const html = document.documentElement;
    const currentTheme = html.getAttribute('data-theme') || 'dark';
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
    
    html.setAttribute('data-theme', newTheme);
    localStorage.setItem('theme', newTheme);
    
    // Add smooth transition effect
    html.style.transition = 'background-color 0.3s ease';
    setTimeout(() => {
        html.style.transition = '';
    }, 300);
}

// Initialize theme on page load
(function initTheme() {
    const savedTheme = localStorage.getItem('theme') || 'dark';
    document.documentElement.setAttribute('data-theme', savedTheme);
})();

document.addEventListener('DOMContentLoaded', function() {
    // Ensure mobile scroll is not locked by stale sidebar-open state on reload.
    closeSidebar();

    const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
    if (isCollapsed) {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.querySelector('.main-content');
        const topbar = document.querySelector('.dashboard-topbar');
        const collapseBtn = document.getElementById('sidebarCollapseBtn');
        if (sidebar) sidebar.classList.add('collapsed');
        if (mainContent) mainContent.classList.add('sidebar-collapsed');
        if (topbar) topbar.classList.add('sidebar-collapsed');
        if (collapseBtn) collapseBtn.classList.add('rotated');
    }

    const overlay = document.querySelector('.sidebar-overlay');
    if (overlay) {
        overlay.addEventListener('click', closeSidebar);
    }

    document.querySelectorAll('.sidebar .nav-link, .sidebar .logout-btn').forEach(link => {
        link.addEventListener('click', function() {
            if (window.matchMedia('(max-width: 768px)').matches) {
                closeSidebar();
            }
        });
    });

    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) {
            closeSidebar();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeSidebar();
        }
    });

    initDragScrollableAreas(document);
    refreshDragScrollableAreas(document);

    const dragScrollObserver = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            mutation.addedNodes.forEach(function(node) {
                if (node.nodeType === 1) {
                    initDragScrollableAreas(node);
                    refreshDragScrollableAreas(node);
                }
            });
        });
    });

    if (document.body) {
        dragScrollObserver.observe(document.body, { childList: true, subtree: true });
    }

    window.addEventListener('resize', function() {
        refreshDragScrollableAreas(document);
    });
});
</script>
