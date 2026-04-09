<?php
require_once __DIR__ . '/../../config/config.php';

// Check authentication
if (!isLoggedIn() || getUserRole() !== 'admin') {
    redirect('login.php');
}

$db = Database::getInstance()->getConnection();
$userId = $_SESSION['user_id'];
$error = '';
$success = '';

// Check for success message from redirect
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'avatar_updated') {
        $success = 'Avatar updated successfully';
    } elseif ($_GET['success'] === 'profile_updated') {
        $success = 'Profile updated successfully';
    }
}

// Get current user data
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    redirect('login.php');
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
        // Update profile information
        $name = sanitize($_POST['name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        
        if (empty($name)) {
            $error = 'Name is required';
        } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Valid email is required';
        } else {
            // Check if email is already taken by another user
            $checkStmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $checkStmt->execute([$email, $userId]);
            
            if ($checkStmt->fetch()) {
                $error = 'Email is already taken';
            } else {
                try {
                    $updateStmt = $db->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
                    $updateStmt->execute([$name, $email, $userId]);
                    
                    // Update session
                    $_SESSION['user_name'] = $name;
                    $_SESSION['user_email'] = $email;
                    
                    // Log activity
                    $logStmt = $db->prepare("INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
                    $logStmt->execute([
                        $userId,
                        'profile_update',
                        'Profile information updated',
                        $_SERVER['REMOTE_ADDR'] ?? '',
                        $_SERVER['HTTP_USER_AGENT'] ?? ''
                    ]);
                    
                    $success = 'Profile updated successfully';
                    $user['name'] = $name;
                    $user['email'] = $email;
                    
                    // Redirect to refresh the page and show updated info
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?success=profile_updated');
                    exit;
                } catch (PDOException $e) {
                    $error = 'Failed to update profile';
                    error_log($e->getMessage());
                }
            }
        }
    } elseif ($action === 'change_password') {
        // Change password
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $error = 'All password fields are required';
        } elseif (!password_verify($currentPassword, $user['password'])) {
            $error = 'Current password is incorrect';
        } elseif (strlen($newPassword) < 8) {
            $error = 'New password must be at least 8 characters';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'New passwords do not match';
        } else {
            try {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $updateStmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                $updateStmt->execute([$hashedPassword, $userId]);
                
                // Log activity
                $logStmt = $db->prepare("INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
                $logStmt->execute([
                    $userId,
                    'password_change',
                    'Password changed',
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);
                
                $success = 'Password changed successfully';
            } catch (PDOException $e) {
                $error = 'Failed to change password';
                error_log($e->getMessage());
            }
        }
    } elseif ($action === 'upload_avatar') {
        // Handle avatar upload
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['avatar'];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $maxSize = 5 * 1024 * 1024; // 5MB
            
            if (!in_array($file['type'], $allowedTypes)) {
                $error = 'Invalid file type. Only JPG, PNG, GIF, and WebP are allowed';
            } elseif ($file['size'] > $maxSize) {
                $error = 'File size must not exceed 5MB';
            } else {
                // Create uploads directory if it doesn't exist
                $uploadDir = __DIR__ . '/../../assets/uploads/avatars/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                // Generate unique filename
                $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'avatar_' . $userId . '_' . time() . '.' . $extension;
                $filepath = $uploadDir . $filename;
                
                if (move_uploaded_file($file['tmp_name'], $filepath)) {
                    // Delete old avatar if exists
                    if (!empty($user['avatar']) && file_exists(__DIR__ . '/../../' . $user['avatar'])) {
                        unlink(__DIR__ . '/../../' . $user['avatar']);
                    }
                    
                    // Update database
                    $avatarPath = 'assets/uploads/avatars/' . $filename;
                    $updateStmt = $db->prepare("UPDATE users SET avatar = ? WHERE id = ?");
                    $updateStmt->execute([$avatarPath, $userId]);
                    
                    // Update session
                    $_SESSION['user_avatar'] = $avatarPath;
                    
                    // Log activity
                    $logStmt = $db->prepare("INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
                    $logStmt->execute([
                        $userId,
                        'avatar_update',
                        'Avatar updated',
                        $_SERVER['REMOTE_ADDR'] ?? '',
                        $_SERVER['HTTP_USER_AGENT'] ?? ''
                    ]);
                    
                    $success = 'Avatar updated successfully';
                    $user['avatar'] = $avatarPath;
                    
                    // Redirect to refresh the page and show updated avatar
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?success=avatar_updated');
                    exit;
                } else {
                    $error = 'Failed to upload avatar';
                }
            }
        } else {
            $error = 'Please select an image file';
        }
    } elseif ($action === 'remove_avatar') {
        // Remove avatar
        if (!empty($user['avatar'])) {
            $avatarPath = __DIR__ . '/../../' . $user['avatar'];
            if (file_exists($avatarPath)) {
                unlink($avatarPath);
            }
            
            $updateStmt = $db->prepare("UPDATE users SET avatar = NULL WHERE id = ?");
            $updateStmt->execute([$userId]);
            
            // Update session
            $_SESSION['user_avatar'] = null;
            
            // Log activity
            $logStmt = $db->prepare("INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
            $logStmt->execute([
                $userId,
                'avatar_remove',
                'Avatar removed',
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
            
            $success = 'Avatar removed successfully';
            $user['avatar'] = null;
        }
    }
}

// Get initials for avatar fallback
$initials = '';
$nameParts = explode(' ', $user['name']);
foreach ($nameParts as $part) {
    $initials .= strtoupper(substr($part, 0, 1));
}
$initials = substr($initials, 0, 2);

// Activity logs pagination
$logsPage = isset($_GET['logs_page']) ? max(1, intval($_GET['logs_page'])) : 1;
$logsPerPage = 5;
$logsOffset = ($logsPage - 1) * $logsPerPage;

// Get total count of activity logs
$totalLogsStmt = $db->prepare("SELECT COUNT(*) FROM activity_logs WHERE user_id = ?");
$totalLogsStmt->execute([$userId]);
$totalLogs = (int) $totalLogsStmt->fetchColumn();
$totalLogsPages = max(1, ceil($totalLogs / $logsPerPage));

// Ensure current page is within bounds
if ($logsPage > $totalLogsPages) {
    $logsPage = $totalLogsPages;
    $logsOffset = ($logsPage - 1) * $logsPerPage;
}

// Get paginated activity logs
$logsStmt = $db->prepare("
    SELECT action, description, ip_address, created_at 
    FROM activity_logs 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT ? OFFSET ?
");
$logsStmt->bindValue(1, $userId, PDO::PARAM_INT);
$logsStmt->bindValue(2, $logsPerPage, PDO::PARAM_INT);
$logsStmt->bindValue(3, $logsOffset, PDO::PARAM_INT);
$logsStmt->execute();
$activityLogs = $logsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Settings - <?php echo APP_NAME; ?></title>
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
                <h1>Profile Settings</h1>
                <p>Manage your account information and security</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <div class="profile-container">
                <!-- Left Column -->
                <div class="profile-left-column">
                    <!-- Avatar Section -->
                    <div class="profile-card avatar-card">
                        <h3>Profile Picture</h3>
                        <div class="avatar-section">
                            <div class="avatar-preview">
                                <?php if ($user['avatar']): ?>
                                    <img src="../../<?php echo htmlspecialchars($user['avatar']); ?>" alt="Avatar" id="avatarImage">
                                <?php else: ?>
                                    <div class="avatar-initials-large" id="avatarInitials"><?php echo $initials; ?></div>
                                <?php endif; ?>
                            </div>
                            
                            <form method="POST" enctype="multipart/form-data" id="avatarForm">
                                <input type="hidden" name="action" value="upload_avatar">
                                <input type="file" name="avatar" id="avatarInput" accept="image/*" style="display: none;">
                                <div class="avatar-actions">
                                    <button type="button" class="btn-primary" onclick="document.getElementById('avatarInput').click()">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                            <polyline points="17 8 12 3 7 8"></polyline>
                                            <line x1="12" y1="3" x2="12" y2="15"></line>
                                        </svg>
                                        Upload Photo
                                    </button>
                                    <?php if ($user['avatar']): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="remove_avatar">
                                            <button type="submit" class="btn-secondary">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <polyline points="3 6 5 6 21 6"></polyline>
                                                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                                </svg>
                                                Remove
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <p class="avatar-hint">JPG, PNG, GIF or WebP. Max 5MB.</p>
                            </form>
                        </div>
                    </div>

                    <!-- Account Information -->
                    <div class="profile-card">
                        <h3>Account Information</h3>
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">User ID</span>
                                <span class="info-value">#<?php echo $user['id']; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Member Since</span>
                                <span class="info-value"><?php echo date('F d, Y', strtotime($user['created_at'])); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Last Login</span>
                                <span class="info-value">
                                    <?php echo $user['last_login'] ? date('M d, Y h:i A', strtotime($user['last_login'])) : 'Never'; ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Last Updated</span>
                                <span class="info-value"><?php echo date('M d, Y h:i A', strtotime($user['updated_at'])); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="profile-right-column">
                    <!-- Profile Information -->
                    <div class="profile-card">
                        <h3>Profile Information</h3>
                        <form method="POST" class="profile-form">
                            <input type="hidden" name="action" value="update_profile">
                            
                            <div class="form-group">
                                <label for="name">Full Name</label>
                                <input type="text" id="name" name="name" class="form-input" value="<?php echo htmlspecialchars($user['name']); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="email">Email Address</label>
                                <input type="email" id="email" name="email" class="form-input" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Role</label>
                                    <input type="text" class="form-input" value="<?php echo ucfirst($user['role']); ?>" disabled>
                                </div>

                                <div class="form-group">
                                    <label>Account Status</label>
                                    <div class="status-badge-inline <?php echo $user['status']; ?>">
                                        <?php echo ucfirst($user['status']); ?>
                                    </div>
                                </div>
                            </div>

                            <button type="submit" class="btn-primary">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                                    <polyline points="17 21 17 13 7 13 7 21"></polyline>
                                    <polyline points="7 3 7 8 15 8"></polyline>
                                </svg>
                                Save Changes
                            </button>
                        </form>
                    </div>

                    <!-- Change Password -->
                    <div class="profile-card">
                        <h3>Change Password</h3>
                        <form method="POST" class="profile-form">
                            <input type="hidden" name="action" value="change_password">
                            
                            <div class="form-group">
                                <label for="current_password">Current Password</label>
                                <input type="password" id="current_password" name="current_password" class="form-input" required>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="new_password">New Password</label>
                                    <input type="password" id="new_password" name="new_password" class="form-input" minlength="8" required>
                                    <small class="form-hint">Min 8 characters</small>
                                </div>

                                <div class="form-group">
                                    <label for="confirm_password">Confirm Password</label>
                                    <input type="password" id="confirm_password" name="confirm_password" class="form-input" required>
                                </div>
                            </div>

                            <button type="submit" class="btn-primary">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                </svg>
                                Update Password
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Activity Log Section (Full Width) -->
            <div class="profile-card activity-log-card">
                <div class="activity-log-header">
                    <h3>Recent Activity</h3>
                    <span class="activity-count"><?php echo $totalLogs; ?> total activities</span>
                </div>
                <div class="activity-log-list">
                    <?php if (!empty($activityLogs)): ?>
                        <?php foreach ($activityLogs as $log): ?>
                            <div class="activity-item">
                                <div class="activity-icon <?php echo $log['action']; ?>">
                                    <?php 
                                    $icon = match($log['action']) {
                                        'login' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path><polyline points="10 17 15 12 10 7"></polyline><line x1="15" y1="12" x2="3" y2="12"></line></svg>',
                                        'logout' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>',
                                        'profile_update' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>',
                                        'password_change' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>',
                                        'avatar_update' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>',
                                        'avatar_remove' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>',
                                        'password_reset_request' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>',
                                        default => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>'
                                    };
                                    echo $icon;
                                    ?>
                                </div>
                                <div class="activity-content">
                                    <p class="activity-description"><?php echo htmlspecialchars($log['description']); ?></p>
                                    <div class="activity-meta">
                                        <span class="activity-time">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <circle cx="12" cy="12" r="10"></circle>
                                                <polyline points="12 6 12 12 16 14"></polyline>
                                            </svg>
                                            <?php 
                                            $timestamp = strtotime($log['created_at']);
                                            $diff = time() - $timestamp;
                                            if ($diff < 60) {
                                                echo 'Just now';
                                            } elseif ($diff < 3600) {
                                                echo floor($diff / 60) . ' minutes ago';
                                            } elseif ($diff < 86400) {
                                                echo floor($diff / 3600) . ' hours ago';
                                            } elseif ($diff < 604800) {
                                                echo floor($diff / 86400) . ' days ago';
                                            } else {
                                                echo date('M d, Y h:i A', $timestamp);
                                            }
                                            ?>
                                        </span>
                                        <span class="activity-ip">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <circle cx="12" cy="12" r="10"></circle>
                                                <line x1="2" y1="12" x2="22" y2="12"></line>
                                                <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
                                            </svg>
                                            <?php echo htmlspecialchars($log['ip_address'] ?: 'Unknown'); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="activity-empty">
                            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="8" x2="12" y2="12"></line>
                                <line x1="12" y1="16" x2="12.01" y2="16"></line>
                            </svg>
                            <p>No recent activity found</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Activity Logs Pagination -->
                <?php if ($totalLogsPages > 1): ?>
                <div class="activity-pagination">
                    <?php if ($logsPage > 1): ?>
                        <a href="?logs_page=<?php echo $logsPage - 1; ?>" class="page-btn-sm">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="15 18 9 12 15 6"></polyline>
                            </svg>
                            Prev
                        </a>
                    <?php endif; ?>
                    
                    <div class="page-info">
                        Page <?php echo $logsPage; ?> of <?php echo $totalLogsPages; ?>
                    </div>
                    
                    <?php if ($logsPage < $totalLogsPages): ?>
                        <a href="?logs_page=<?php echo $logsPage + 1; ?>" class="page-btn-sm">
                            Next
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="9 18 15 12 9 6"></polyline>
                            </svg>
                        </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        // Avatar preview
        document.getElementById('avatarInput').addEventListener('change', function(e) {
            if (e.target.files && e.target.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.querySelector('.avatar-preview');
                    const img = document.getElementById('avatarImage');
                    const initials = document.getElementById('avatarInitials');
                    
                    if (img) {
                        img.src = e.target.result;
                    } else if (initials) {
                        preview.innerHTML = '<img src="' + e.target.result + '" alt="Avatar" id="avatarImage">';
                    }
                }
                reader.readAsDataURL(e.target.files[0]);
                
                // Auto-submit the form
                document.getElementById('avatarForm').submit();
            }
        });

        // Password confirmation validation
        document.querySelector('form[action="change_password"]')?.addEventListener('submit', function(e) {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (newPassword !== confirmPassword) {
                e.preventDefault();
                alert('New passwords do not match!');
            }
        });
    </script>

    <style>
        /* Profile Container Layout */
        .profile-container {
            display: grid;
            grid-template-columns: 380px 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .profile-left-column {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .profile-right-column {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .profile-card {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
        }

        .profile-card h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--cream);
            margin: 0 0 1.5rem 0;
        }

        /* Avatar Section */
        .avatar-card {
            height: fit-content;
        }

        .avatar-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1.5rem;
        }

        .avatar-preview {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            overflow: hidden;
            background: linear-gradient(135deg, var(--primary-red), #a61c28);
            display: flex;
            align-items: center;
            justify-content: center;
            border: 4px solid var(--border-color);
        }

        .avatar-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-initials-large {
            font-size: 3rem;
            font-weight: 700;
            color: white;
        }

        .avatar-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            justify-content: center;
            width: 100%;
        }

        .avatar-hint {
            font-size: 0.85rem;
            color: var(--gray-text);
            text-align: center;
            margin: 0;
        }

        /* Forms */
        .profile-form {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-group label {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--cream);
        }

        .form-input {
            padding: 0.75rem 1rem;
            background: var(--dark-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--cream);
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary-red);
            box-shadow: 0 0 0 3px rgba(230, 57, 70, 0.1);
        }

        .form-input:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .form-hint {
            font-size: 0.8rem;
            color: var(--gray-text);
        }

        /* Buttons */
        .btn-primary, .btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, #E63946 0%, #C72736 100%);
            color: white;
        }

        .btn-primary:hover {
            box-shadow: 0 4px 15px rgba(230, 57, 70, 0.3);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: var(--dark-hover);
            color: var(--gray-text);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: #2a2a2a;
            color: var(--cream);
        }

        /* Status Badge */
        .status-badge-inline {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            width: fit-content;
        }

        .status-badge-inline.active {
            background: rgba(6, 214, 160, 0.2);
            color: #06D6A0;
        }

        .status-badge-inline.inactive {
            background: rgba(168, 168, 168, 0.2);
            color: #A8A8A8;
        }

        .status-badge-inline.suspended {
            background: rgba(239, 71, 111, 0.2);
            color: #EF476F;
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            gap: 1rem;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            background: var(--dark-bg);
            border-radius: 8px;
        }

        .info-label {
            font-size: 0.9rem;
            color: var(--gray-text);
        }

        .info-value {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--cream);
        }

        /* Alerts */
        .alert {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem 1.25rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-size: 0.95rem;
        }

        .alert-error {
            background: rgba(239, 71, 111, 0.1);
            border: 1px solid rgba(239, 71, 111, 0.3);
            color: #EF476F;
        }

        .alert-success {
            background: rgba(6, 214, 160, 0.1);
            border: 1px solid rgba(6, 214, 160, 0.3);
            color: #06D6A0;
        }

        /* Activity Log */
        .activity-log-card {
            margin-top: 0;
        }

        .activity-log-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .activity-log-header h3 {
            margin: 0;
        }

        .activity-count {
            font-size: 0.85rem;
            color: var(--gray-text);
            background: var(--dark-bg);
            padding: 0.5rem 1rem;
            border-radius: 20px;
        }

        .activity-log-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .activity-item {
            display: flex;
            gap: 1rem;
            padding: 1rem;
            background: var(--dark-bg);
            border-radius: 8px;
            border: 1px solid transparent;
            transition: all 0.3s ease;
        }

        .activity-item:hover {
            border-color: var(--border-color);
            background: var(--dark-hover);
        }

        .activity-icon {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .activity-icon.login {
            background: rgba(6, 214, 160, 0.2);
            color: #06D6A0;
        }

        .activity-icon.logout {
            background: rgba(168, 168, 168, 0.2);
            color: #A8A8A8;
        }

        .activity-icon.profile_update {
            background: rgba(33, 150, 243, 0.2);
            color: #2196F3;
        }

        .activity-icon.password_change {
            background: rgba(255, 193, 7, 0.2);
            color: #FFC107;
        }

        .activity-icon.avatar_update {
            background: rgba(156, 39, 176, 0.2);
            color: #9C27B0;
        }

        .activity-icon.avatar_remove {
            background: rgba(239, 71, 111, 0.2);
            color: #EF476F;
        }

        .activity-icon.password_reset_request {
            background: rgba(255, 152, 0, 0.2);
            color: #FF9800;
        }

        .activity-content {
            flex: 1;
            min-width: 0;
        }

        .activity-description {
            font-size: 0.95rem;
            color: var(--cream);
            margin: 0 0 0.5rem 0;
        }

        .activity-meta {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
        }

        .activity-time,
        .activity-ip {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            color: var(--gray-text);
        }

        .activity-time svg,
        .activity-ip svg {
            flex-shrink: 0;
        }

        .activity-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 3rem 1rem;
            color: var(--gray-text);
            text-align: center;
        }

        .activity-empty svg {
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .activity-empty p {
            margin: 0;
            font-size: 0.95rem;
        }

        /* Activity Logs Pagination */
        .activity-pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
        }

        .page-btn-sm {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.5rem 0.85rem;
            background: var(--dark-bg);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--cream);
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .page-btn-sm:hover {
            background: var(--primary-red);
            border-color: var(--primary-red);
        }

        .page-info {
            font-size: 0.85rem;
            color: var(--gray-text);
            padding: 0 0.5rem;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .profile-container {
                grid-template-columns: 1fr;
            }

            .form-row {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .avatar-actions {
                flex-direction: column;
            }

            .avatar-actions button,
            .avatar-actions form {
                width: 100%;
            }

            .activity-meta {
                flex-direction: column;
                gap: 0.5rem;
            }
        }
    </style>
</body>
</html>
