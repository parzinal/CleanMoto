<?php
/**
 * Shared Notifications Page Component
 * Used by admin, staff and user pages to view all notifications
 */
require_once __DIR__ . '/../../config/config.php';

// Check authentication
if (!isLoggedIn()) {
    redirect('login.php');
}

$db = Database::getInstance()->getConnection();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? 'user';

// Pagination settings
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Handle mark as read actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'mark_read' && isset($_POST['id'])) {
        $nid = intval($_POST['id']);
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND (user_id IS NULL OR user_id = ? OR role = ?)");
        $stmt->execute([$nid, $userId, $userRole]);
    } elseif ($action === 'mark_all_read') {
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE (user_id IS NULL OR user_id = ? OR role = ?)");
        $stmt->execute([$userId, $userRole]);
    } elseif ($action === 'delete' && isset($_POST['id'])) {
        $nid = intval($_POST['id']);
        // Only allow deleting personal notifications
        $stmt = $db->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
        $stmt->execute([$nid, $userId]);
    }
    
    // Redirect to avoid form resubmission
    header("Location: notifications.php?page=$page");
    exit;
}

$countStmt = null;
// Get total count for pagination
if ($userRole === 'admin') {
    // Admins can view all notifications
    $countStmt = $db->prepare("SELECT COUNT(*) FROM notifications");
    $countStmt->execute();
    $totalNotifications = (int) $countStmt->fetchColumn();
} else {
    $countStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE ((user_id IS NULL AND (role IS NULL OR role = :role)) OR user_id = :uid OR role = :role2)");
    $countStmt->execute([':uid' => $userId, ':role' => $userRole, ':role2' => $userRole]);
    $totalNotifications = (int) $countStmt->fetchColumn();
}
$totalPages = max(1, ceil($totalNotifications / $perPage));

// Ensure current page is within bounds
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

// Get notifications with pagination
$notifications = [];
if ($userRole === 'admin') {
    $stmt = $db->prepare("SELECT * FROM notifications ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $db->prepare("
    SELECT * FROM notifications 
    WHERE ((user_id IS NULL AND (role IS NULL OR role = :role)) OR user_id = :uid OR role = :role2) 
    ORDER BY created_at DESC 
    LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':role', $userRole, PDO::PARAM_STR);
    $stmt->bindValue(':role2', $userRole, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get unread count
if ($userRole === 'admin') {
    $unreadStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE is_read = 0");
    $unreadStmt->execute();
    $unreadCount = (int) $unreadStmt->fetchColumn();
} else {
    $unreadStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND ((user_id IS NULL AND (role IS NULL OR role = :role)) OR user_id = :uid OR role = :role2)");
    $unreadStmt->execute([':uid' => $userId, ':role' => $userRole, ':role2' => $userRole]);
    $unreadCount = (int) $unreadStmt->fetchColumn();
}

// Helper function to format time ago
function timeAgo($timestamp) {
    $time = strtotime($timestamp);
    $diff = time() - $time;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M d, Y h:i A', $time);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <div class="dashboard-layout">
        <!-- Sidebar -->
        <?php include '../includes/sidebar.php'; ?>
        
        <!-- Header -->
        <?php include '../includes/header.php'; ?>
        
        <!-- Mobile Overlay -->
        <div class="sidebar-overlay" onclick="toggleSidebar()"></div>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="dashboard-header">
                <h1>Notifications</h1>
                <p>Stay updated with your latest activities and alerts</p>
            </div>

            <!-- Notification Stats -->
            <div class="notification-stats">
                <div class="stat-item">
                    <span class="stat-number"><?php echo $totalNotifications; ?></span>
                    <span class="stat-label">Total Notifications</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number unread"><?php echo $unreadCount; ?></span>
                    <span class="stat-label">Unread</span>
                </div>
                <?php if ($unreadCount > 0): ?>
                <form method="POST" style="margin-left: auto;">
                    <input type="hidden" name="action" value="mark_all_read">
                    <button type="submit" class="btn-secondary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="9 11 12 14 22 4"></polyline>
                            <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                        </svg>
                        Mark All as Read
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <!-- Notifications List -->
            <div class="notifications-container">
                <?php if (!empty($notifications)): ?>
                    <?php foreach ($notifications as $n): ?>
                        <?php
                            $isUnread = ($n['is_read'] == 0);
                            $time = timeAgo($n['created_at']);
                            $title = $n['title'] ?? 'Notification';
                            $body = $n['body'] ?? '';
                            $url = $n['url'] ?? '#';
                            $isGlobal = is_null($n['user_id']);
                        ?>
                        <div class="notification-card <?php echo $isUnread ? 'unread' : ''; ?>">
                            <div class="notification-icon-large">
                                <?php if ($isGlobal): ?>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10"></circle>
                                        <line x1="12" y1="16" x2="12" y2="12"></line>
                                        <line x1="12" y1="8" x2="12.01" y2="8"></line>
                                    </svg>
                                <?php else: ?>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                                        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                                    </svg>
                                <?php endif; ?>
                            </div>
                            <div class="notification-body">
                                <div class="notification-header-row">
                                    <h4><?php echo htmlspecialchars($title); ?></h4>
                                    <?php if ($isUnread): ?>
                                        <span class="unread-badge">New</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($body): ?>
                                    <p><?php echo htmlspecialchars($body); ?></p>
                                <?php endif; ?>
                                <div class="notification-footer">
                                    <span class="notification-time">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="10"></circle>
                                            <polyline points="12 6 12 12 16 14"></polyline>
                                        </svg>
                                        <?php echo $time; ?>
                                    </span>
                                    <?php if ($isGlobal): ?>
                                        <span class="notification-type global">System</span>
                                    <?php else: ?>
                                        <span class="notification-type personal">Personal</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="notification-actions">
                                <?php if ($url && $url !== '#'): ?>
                                    <button type="button" class="btn-icon view-link" data-href="<?php echo htmlspecialchars($url); ?>" data-id="<?php echo intval($n['id']); ?>" title="View"></button>
                                <?php endif; ?>
                                <?php if ($isUnread): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="mark_read">
                                        <input type="hidden" name="id" value="<?php echo $n['id']; ?>">
                                        <button type="submit" class="btn-icon" title="Mark as Read">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <polyline points="20 6 9 17 4 12"></polyline>
                                            </svg>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>" class="page-btn">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="15 18 9 12 15 6"></polyline>
                                </svg>
                                Previous
                            </a>
                        <?php else: ?>
                            <span class="page-btn disabled">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="15 18 9 12 15 6"></polyline>
                                </svg>
                                Previous
                            </span>
                        <?php endif; ?>
                        
                        <div class="page-numbers">
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            
                            if ($startPage > 1): ?>
                                <a href="?page=1" class="page-num">1</a>
                                <?php if ($startPage > 2): ?>
                                    <span class="page-ellipsis">...</span>
                                <?php endif; ?>
                            <?php endif;
                            
                            for ($i = $startPage; $i <= $endPage; $i++): ?>
                                <a href="?page=<?php echo $i; ?>" class="page-num <?php echo $i === $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor;
                            
                            if ($endPage < $totalPages): ?>
                                <?php if ($endPage < $totalPages - 1): ?>
                                    <span class="page-ellipsis">...</span>
                                <?php endif; ?>
                                <a href="?page=<?php echo $totalPages; ?>" class="page-num"><?php echo $totalPages; ?></a>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?php echo $page + 1; ?>" class="page-btn">
                                Next
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="9 18 15 12 9 6"></polyline>
                                </svg>
                            </a>
                        <?php else: ?>
                            <span class="page-btn disabled">
                                Next
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="9 18 15 12 9 6"></polyline>
                                </svg>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="pagination-info">
                        Showing <?php echo min($offset + 1, $totalNotifications); ?> - <?php echo min($offset + $perPage, $totalNotifications); ?> of <?php echo $totalNotifications; ?> notifications
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                            <line x1="1" y1="1" x2="23" y2="23"></line>
                        </svg>
                        <h3>No Notifications</h3>
                        <p>You're all caught up! Check back later for updates.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const isOpen = !(sidebar && sidebar.classList.contains('open'));
            if (typeof setSidebarOpenState === 'function') {
                setSidebarOpenState(isOpen);
                return;
            }
            document.querySelector('.sidebar').classList.toggle('open');
            document.querySelector('.sidebar-overlay').classList.toggle('open');
            if (window.matchMedia('(max-width: 768px)').matches) {
                document.body.classList.toggle('sidebar-open', isOpen);
            }
        }
    </script>

    <style>
        .notification-stats {
            display: flex;
            align-items: center;
            gap: 2rem;
            padding: 1.5rem;
            background: var(--dark-card);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            margin-bottom: 1.5rem;
        }

        .notification-stats .stat-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .notification-stats .stat-number {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--cream);
        }

        .notification-stats .stat-number.unread {
            color: var(--primary-red);
        }

        .notification-stats .stat-label {
            font-size: 0.85rem;
            color: var(--gray-text);
        }

        .notifications-container {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .notification-card {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1.25rem;
            background: var(--dark-card);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .notification-card:hover {
            border-color: var(--primary-red);
            box-shadow: 0 4px 15px rgba(230, 57, 70, 0.1);
        }

        .notification-card.unread {
            border-left: 4px solid var(--primary-red);
            background: linear-gradient(135deg, rgba(230, 57, 70, 0.05) 0%, var(--dark-card) 100%);
        }

        .notification-icon-large {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: rgba(230, 57, 70, 0.15);
            color: var(--primary-red);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .notification-body {
            flex: 1;
            min-width: 0;
        }

        .notification-header-row {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.5rem;
        }

        .notification-header-row h4 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--cream);
            margin: 0;
        }

        .unread-badge {
            font-size: 0.7rem;
            font-weight: 600;
            padding: 0.2rem 0.5rem;
            background: var(--primary-red);
            color: white;
            border-radius: 4px;
            text-transform: uppercase;
        }

        .notification-body p {
            font-size: 0.9rem;
            color: var(--gray-text);
            margin: 0 0 0.75rem 0;
            line-height: 1.5;
        }

        .notification-footer {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .notification-time {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.8rem;
            color: var(--gray-text);
        }

        .notification-type {
            font-size: 0.75rem;
            font-weight: 500;
            padding: 0.2rem 0.6rem;
            border-radius: 4px;
        }

        .notification-type.global {
            background: rgba(33, 150, 243, 0.15);
            color: #2196F3;
        }

        .notification-type.personal {
            background: rgba(6, 214, 160, 0.15);
            color: #06D6A0;
        }

        .notification-actions {
            display: flex;
            gap: 0.5rem;
            flex-shrink: 0;
        }

        .btn-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: var(--dark-hover);
            border: 1px solid var(--border-color);
            color: var(--gray-text);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .btn-icon:hover {
            background: var(--primary-red);
            border-color: var(--primary-red);
            color: white;
        }

        .btn-secondary {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1rem;
            background: var(--dark-hover);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--cream);
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-secondary:hover {
            background: var(--primary-red);
            border-color: var(--primary-red);
        }

        /* Pagination */
        .pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
        }

        .page-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1rem;
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--cream);
            font-size: 0.9rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .page-btn:hover {
            background: var(--primary-red);
            border-color: var(--primary-red);
        }

        .page-numbers {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .page-num {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--gray-text);
            font-size: 0.9rem;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .page-num:hover, .page-num.active {
            background: var(--primary-red);
            border-color: var(--primary-red);
            color: white;
        }

        .page-ellipsis {
            color: var(--gray-text);
            padding: 0 0.25rem;
        }

        .page-btn.disabled {
            opacity: 0.4;
            cursor: not-allowed;
            pointer-events: none;
        }

        .pagination-info {
            text-align: center;
            color: var(--gray-text);
            font-size: 0.85rem;
            margin-top: 1rem;
        }

        /* Empty State */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 4rem 2rem;
            background: var(--dark-card);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            text-align: center;
        }

        .empty-state svg {
            color: var(--gray-text);
            opacity: 0.5;
            margin-bottom: 1.5rem;
        }

        .empty-state h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--cream);
            margin: 0 0 0.5rem 0;
        }

        .empty-state p {
            font-size: 0.95rem;
            color: var(--gray-text);
            margin: 0;
        }

        @media (max-width: 768px) {
            .notification-stats {
                flex-wrap: wrap;
                gap: 1rem;
            }

            .notification-stats form {
                width: 100%;
                margin-left: 0;
            }

            .notification-stats .btn-secondary {
                width: 100%;
                justify-content: center;
            }

            .notification-card {
                flex-direction: column;
            }

            .notification-actions {
                width: 100%;
                justify-content: flex-end;
                margin-top: 0.75rem;
                padding-top: 0.75rem;
                border-top: 1px solid var(--border-color);
            }

            .pagination {
                flex-wrap: wrap;
            }
        }
    </style>
</body>
</html>

<script>
// Handle view button clicks: mark as read via API then navigate
document.addEventListener('DOMContentLoaded', function() {
    const api = '<?php echo APP_URL; ?>/api/notifications.php';
    document.querySelectorAll('.view-link').forEach(btn => {
        btn.addEventListener('click', function(e) {
            const href = btn.getAttribute('data-href') || '#';
            const id = parseInt(btn.getAttribute('data-id') || 0, 10);
            if (id > 0) {
                // mark read then navigate
                fetch(api, { method: 'POST', body: new URLSearchParams({ action: 'mark_read', id: id }) })
                    .then(r => r.json())
                    .catch(() => {})
                    .finally(() => {
                        if (href && href !== '#') window.location.href = href;
                    });
            } else {
                if (href && href !== '#') window.location.href = href;
            }
        });
    });
});
</script>
