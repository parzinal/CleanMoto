<?php
require_once __DIR__ . '/../../config/config.php';

// Check if user is logged in and has admin role
if (!isLoggedIn()) {
    redirect('login.php');
}

if (!isAdmin()) {
    redirect('login.php');
}

$db = Database::getInstance()->getConnection();
$message = '';
$messageType = '';
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = 'staff'; // Force role to be staff
        $status = $_POST['status'] ?? 'active';
        
        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Invalid email address.';
            $messageType = 'error';
            if ($isAjax) {
                echo json_encode(['success' => false, 'message' => $message]);
                exit;
            }
        } else {
            // Check if email already exists
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $message = 'Email address already exists.';
                $messageType = 'error';
                if ($isAjax) {
                    echo json_encode(['success' => false, 'message' => $message]);
                    exit;
                }
            } else {
                try {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("INSERT INTO users (name, email, password, role, status) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $email, $hashedPassword, $role, $status]);
                    $newId = $db->lastInsertId();
                    $message = 'Staff account created successfully!';
                    $messageType = 'success';
                    
                    if ($isAjax) {
                        echo json_encode([
                            'success' => true,
                            'message' => $message,
                            'user' => [
                                'id' => $newId,
                                'name' => $name,
                                'email' => $email,
                                'role' => $role,
                                'status' => $status,
                                'created_at' => date('Y-m-d H:i:s')
                            ]
                        ]);
                        exit;
                    }
                } catch (PDOException $e) {
                    $message = 'Error creating staff account: ' . $e->getMessage();
                    $messageType = 'error';
                    if ($isAjax) {
                        echo json_encode(['success' => false, 'message' => $message]);
                        exit;
                    }
                }
            }
        }
    }
    
    if ($action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'user';
        $status = $_POST['status'] ?? 'active';
        $password = $_POST['password'] ?? '';
        
        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Invalid email address.';
            $messageType = 'error';
            if ($isAjax) {
                echo json_encode(['success' => false, 'message' => $message]);
                exit;
            }
        } else {
            // Check if email already exists for other users
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $id]);
            if ($stmt->fetch()) {
                $message = 'Email address already exists.';
                $messageType = 'error';
                if ($isAjax) {
                    echo json_encode(['success' => false, 'message' => $message]);
                    exit;
                }
            } else {
                try {
                    if (!empty($password)) {
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("UPDATE users SET name = ?, email = ?, password = ?, role = ?, status = ? WHERE id = ?");
                        $stmt->execute([$name, $email, $hashedPassword, $role, $status, $id]);
                    } else {
                        $stmt = $db->prepare("UPDATE users SET name = ?, email = ?, role = ?, status = ? WHERE id = ?");
                        $stmt->execute([$name, $email, $role, $status, $id]);
                    }
                    $message = 'User updated successfully!';
                    $messageType = 'success';
                    
                    if ($isAjax) {
                        echo json_encode([
                            'success' => true,
                            'message' => $message,
                            'user' => [
                                'id' => $id,
                                'name' => $name,
                                'email' => $email,
                                'role' => $role,
                                'status' => $status
                            ]
                        ]);
                        exit;
                    }
                } catch (PDOException $e) {
                    $message = 'Error updating user: ' . $e->getMessage();
                    $messageType = 'error';
                    if ($isAjax) {
                        echo json_encode(['success' => false, 'message' => $message]);
                        exit;
                    }
                }
            }
        }
    }
    
    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        
        // Prevent deleting own account
        if ($id == $_SESSION['user_id']) {
            $message = 'You cannot delete your own account.';
            $messageType = 'error';
            if ($isAjax) {
                echo json_encode(['success' => false, 'message' => $message]);
                exit;
            }
        } else {
            try {
                $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$id]);
                $message = 'User deleted successfully!';
                $messageType = 'success';
                
                if ($isAjax) {
                    echo json_encode(['success' => true, 'message' => $message, 'id' => $id]);
                    exit;
                }
            } catch (PDOException $e) {
                $message = 'Error deleting user: ' . $e->getMessage();
                $messageType = 'error';
                if ($isAjax) {
                    echo json_encode(['success' => false, 'message' => $message]);
                    exit;
                }
            }
        }
    }
    
    // Handle AJAX get user request
    if ($action === 'get') {
        $id = intval($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT id, name, email, role, status, created_at FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if ($user) {
            echo json_encode(['success' => true, 'user' => $user]);
        } else {
            echo json_encode(['success' => false, 'message' => 'User not found']);
        }
        exit;
    }
}

// Filter by role
$roleFilter = $_GET['role'] ?? '';
$statusFilter = $_GET['status'] ?? '';

// Build query
$query = "SELECT * FROM users WHERE 1=1";
$params = [];

if ($roleFilter) {
    $query .= " AND role = ?";
    $params[] = $roleFilter;
}

if ($statusFilter) {
    $query .= " AND status = ?";
    $params[] = $statusFilter;
}

$query .= " ORDER BY created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Get user for editing
$editUser = null;
if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$editId]);
    $editUser = $stmt->fetch();
}

// Check if creating new staff
$createStaff = isset($_GET['create']) && $_GET['create'] === 'staff';

// Count users by role
$roleCounts = $db->query("SELECT role, COUNT(*) as count FROM users GROUP BY role")->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .btn-add-staff {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            background: var(--gradient-red);
            color: var(--cream);
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            transition: transform 0.3s, box-shadow 0.3s;
            box-shadow: 0 4px 15px rgba(230, 57, 70, 0.3);
        }
        
        .btn-add-staff:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(230, 57, 70, 0.4);
        }
        
        .btn-add-staff svg {
            flex-shrink: 0;
        }
        
        .users-container {
            display: block;
        }
        
        .table-card {
            background: var(--dark-card);
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid var(--border-color);
        }
        
        .table-card h2 {
            margin-bottom: 1.5rem;
            color: var(--cream);
            font-size: 1.25rem;
        }
        
        /* Edit Modal */
        .edit-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .edit-modal.active {
            display: flex;
        }
        
        .edit-modal-content {
            background: var(--dark-card);
            border-radius: 12px;
            padding: 2rem;
            width: 100%;
            max-width: 500px;
            border: 1px solid var(--border-color);
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .edit-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .edit-modal-header h2 {
            color: var(--cream);
            font-size: 1.25rem;
            margin: 0;
        }
        
        .modal-close {
            background: none;
            border: none;
            color: var(--gray-text);
            cursor: pointer;
            padding: 5px;
        }
        
        .modal-close:hover {
            color: var(--cream);
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--gray-text);
            font-size: 0.9rem;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            background: var(--dark-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--cream);
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary-red);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .btn-submit {
            width: 100%;
            padding: 12px;
            background: var(--gradient-red);
            border: none;
            border-radius: 8px;
            color: var(--cream);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(230, 57, 70, 0.3);
        }
        
        .btn-cancel {
            width: 100%;
            padding: 12px;
            background: transparent;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--gray-text);
            font-size: 1rem;
            cursor: pointer;
            margin-top: 0.5rem;
            text-decoration: none;
            display: block;
            text-align: center;
        }
        
        .btn-cancel:hover {
            border-color: var(--gray-text);
            color: var(--cream);
        }
        
        .users-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .users-table th,
        .users-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        .users-table th {
            color: var(--gray-text);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
        }
        
        .users-table td {
            color: var(--cream);
        }
        
        .users-table tr:hover {
            background: var(--dark-hover);
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--gradient-red);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--cream);
        }
        
        .role-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .role-admin {
            background: rgba(230, 57, 70, 0.2);
            color: var(--primary-red);
        }
        
        .role-staff {
            background: rgba(6, 150, 214, 0.2);
            color: #0696d6;
        }
        
        .role-user {
            background: rgba(6, 214, 160, 0.2);
            color: var(--success);
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-active {
            background: rgba(6, 214, 160, 0.2);
            color: var(--success);
        }
        
        .status-inactive {
            background: rgba(168, 168, 168, 0.2);
            color: var(--gray-text);
        }
        
        .status-suspended {
            background: rgba(239, 71, 111, 0.2);
            color: var(--error);
        }
        
        .action-btns {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-edit, .btn-delete {
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 0.85rem;
            cursor: pointer;
            border: none;
            transition: all 0.3s;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-decoration: none;
            min-width: 90px;
            height: 36px;
            box-sizing: border-box;
        }
        
        .btn-edit {
            background: rgba(6, 150, 214, 0.2);
            color: #0696d6;
        }
        
        .btn-edit:hover {
            background: rgba(6, 150, 214, 0.3);
        }
        
        .btn-delete {
            background: rgba(239, 71, 111, 0.2);
            color: var(--error);
        }
        
        .btn-delete:hover {
            background: rgba(239, 71, 111, 0.3);
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }
        
        .alert-success {
            background: rgba(6, 214, 160, 0.2);
            color: var(--success);
            border: 1px solid rgba(6, 214, 160, 0.3);
        }
        
        .alert-error {
            background: rgba(239, 71, 111, 0.2);
            color: var(--error);
            border: 1px solid rgba(239, 71, 111, 0.3);
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray-text);
        }
        
        .empty-state svg {
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .filter-bar {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        
        .filter-bar select {
            padding: 8px 16px;
            background: var(--dark-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--cream);
            font-size: 0.9rem;
        }
        
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .stat-card {
            background: var(--dark-bg);
            padding: 1rem;
            border-radius: 8px;
            text-align: center;
            border: 1px solid var(--border-color);
        }
        
        .stat-card .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--cream);
        }
        
        .stat-card .stat-label {
            font-size: 0.8rem;
            color: var(--gray-text);
            text-transform: uppercase;
        }
        
        .password-note {
            font-size: 0.8rem;
            color: var(--gray-text);
            margin-top: 0.25rem;
        }

        @media (max-width: 768px) {
            .table-card {
                padding: 1rem;
            }

            .dashboard-header {
                align-items: flex-start;
            }

            .btn-add-staff {
                width: 100%;
                justify-content: center;
                box-sizing: border-box;
            }

            .filter-bar {
                flex-direction: column;
            }

            .filter-bar > * {
                width: 100%;
            }

            .users-table {
                min-width: 0;
            }

            .table-responsive {
                overflow: visible;
            }

            .users-table thead {
                display: none;
            }

            .users-table,
            .users-table tbody,
            .users-table tr,
            .users-table td {
                display: block;
                width: 100%;
            }

            .users-table tr {
                background: var(--dark-bg);
                border: 1px solid var(--border-color);
                border-radius: 12px;
                padding: 0.85rem;
                margin-bottom: 0.85rem;
            }

            .users-table td {
                border-bottom: none;
                padding: 0.35rem 0;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 0.75rem;
                text-align: left;
            }

            .users-table td::before {
                color: var(--gray-text);
                font-size: 0.74rem;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                font-weight: 700;
                flex-shrink: 0;
                min-width: 70px;
            }

            .users-table td:nth-child(1) {
                display: block;
                padding-top: 0;
                margin-bottom: 0.3rem;
            }

            .users-table td:nth-child(1)::before {
                content: none;
            }

            .users-table td:nth-child(2)::before { content: 'Email'; }
            .users-table td:nth-child(3)::before { content: 'Role'; }
            .users-table td:nth-child(4)::before { content: 'Status'; }
            .users-table td:nth-child(5)::before { content: 'Joined'; }
            .users-table td:nth-child(6)::before { content: 'Actions'; }

            .users-table td:nth-child(6) {
                display: block;
                padding-bottom: 0;
            }

            .action-btns {
                display: flex;
                flex-wrap: wrap;
                gap: 0.5rem;
            }

            .btn-edit,
            .btn-delete {
                flex: 1 1 calc(50% - 0.25rem);
                min-width: 0;
                padding: 8px 10px;
            }

            .edit-modal {
                padding: 0.75rem;
            }

            .edit-modal-content {
                max-width: 100%;
                padding: 1rem;
            }

            .form-row {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .stats-cards {
                grid-template-columns: 1fr 1fr;
            }

            .users-table td {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.3rem;
            }

            .users-table td::before {
                min-width: 0;
            }

            .btn-edit,
            .btn-delete {
                flex: 1 1 100%;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <?php include '../includes/sidebar.php'; ?>
        <?php include '../includes/header.php'; ?>
        
        <div class="sidebar-overlay" onclick="toggleSidebar()"></div>
        
        <main class="main-content">
            <div class="dashboard-header">
                <div>
                    <h1>User Management</h1>
                    <p>Manage system users and their roles</p>
                </div>
                <button type="button" class="btn-add-staff" onclick="openCreateModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="8.5" cy="7" r="4"></circle>
                        <line x1="20" y1="8" x2="20" y2="14"></line>
                        <line x1="23" y1="11" x2="17" y2="11"></line>
                    </svg>
                    Add Staff
                </button>
            </div>
            
            <?php if ($message && !$isAjax): ?>
                <div class="alert alert-<?php echo $messageType; ?>" id="alertMessage">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php else: ?>
                <div class="alert" id="alertMessage" style="display: none;"></div>
            <?php endif; ?>
            
            <div class="users-container">
                <!-- Users Table -->
                <div class="table-card">
                    <h2>All Users</h2>
                    
                    <!-- Stats -->
                    <div class="stats-cards">
                        <div class="stat-card">
                            <div class="stat-value"><?php echo array_sum($roleCounts); ?></div>
                            <div class="stat-label">Total</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $roleCounts['admin'] ?? 0; ?></div>
                            <div class="stat-label">Admins</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $roleCounts['staff'] ?? 0; ?></div>
                            <div class="stat-label">Staff</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $roleCounts['user'] ?? 0; ?></div>
                            <div class="stat-label">Users</div>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <form method="GET" class="filter-bar">
                        <select name="role" onchange="this.form.submit()">
                            <option value="">All Roles</option>
                            <option value="admin" <?php echo $roleFilter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            <option value="staff" <?php echo $roleFilter === 'staff' ? 'selected' : ''; ?>>Staff</option>
                            <option value="user" <?php echo $roleFilter === 'user' ? 'selected' : ''; ?>>User</option>
                        </select>
                        <select name="status" onchange="this.form.submit()">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="suspended" <?php echo $statusFilter === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                        </select>
                        <?php if ($roleFilter || $statusFilter): ?>
                            <a href="user-management.php" style="color: var(--gray-text); text-decoration: none; padding: 8px;">Clear Filters</a>
                        <?php endif; ?>
                    </form>
                    
                    <?php if (empty($users)): ?>
                        <div class="empty-state">
                            <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="9" cy="7" r="4"></circle>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                            </svg>
                            <p>No users found.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="users-table" id="usersTable">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Joined</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="usersTableBody">
                                    <?php foreach ($users as $user): 
                                        $initials = '';
                                        $nameParts = explode(' ', $user['name']);
                                        foreach ($nameParts as $part) {
                                            $initials .= strtoupper(substr($part, 0, 1));
                                        }
                                        $initials = substr($initials, 0, 2);
                                    ?>
                                        <tr data-id="<?php echo $user['id']; ?>">
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 10px;">
                                                    <div class="user-avatar"><?php echo $initials; ?></div>
                                                    <span class="user-name"><?php echo htmlspecialchars($user['name']); ?></span>
                                                </div>
                                            </td>
                                            <td class="user-email"><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td>
                                                <span class="role-badge role-<?php echo $user['role']; ?>">
                                                    <?php echo ucfirst($user['role']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $user['status']; ?>">
                                                    <?php echo ucfirst($user['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                            <td>
                                                <div class="action-btns">
                                                    <button type="button" class="btn-edit" onclick="editUser(<?php echo $user['id']; ?>)">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                                        </svg>
                                                    </button>
                                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                    <button type="button" class="btn-delete" onclick="deleteUser(<?php echo $user['id']; ?>)">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                            <polyline points="3 6 5 6 21 6"></polyline>
                                                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                                            <line x1="10" y1="11" x2="10" y2="17"></line>
                                                            <line x1="14" y1="11" x2="14" y2="17"></line>
                                                        </svg>
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Create Staff Modal -->
            <div class="edit-modal" id="createModal">
                <div class="edit-modal-content">
                    <div class="edit-modal-header">
                        <h2>Add Staff Account</h2>
                        <button type="button" class="modal-close" onclick="closeCreateModal()">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg>
                        </button>
                    </div>
                    <form id="createForm">
                        <input type="hidden" name="action" value="create">
                        
                        <div class="form-group">
                            <label for="create_name">Full Name</label>
                            <input type="text" id="create_name" name="name" required 
                                   placeholder="Enter full name">
                        </div>
                        
                        <div class="form-group">
                            <label for="create_email">Email Address</label>
                            <input type="email" id="create_email" name="email" required 
                                   placeholder="Enter email address">
                        </div>
                        
                        <div class="form-group">
                            <label for="create_password">Password</label>
                            <input type="password" id="create_password" name="password" required
                                   placeholder="Enter password">
                        </div>
                        
                        <div class="form-group">
                            <label for="create_status">Status</label>
                            <select id="create_status" name="status">
                                <option value="active" selected>Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn-submit" id="createSubmitBtn">Create Staff Account</button>
                        <button type="button" class="btn-cancel" onclick="closeCreateModal()">Cancel</button>
                    </form>
                </div>
            </div>
            
            <!-- Edit User Modal -->
            <div class="edit-modal" id="editModal">
                <div class="edit-modal-content">
                    <div class="edit-modal-header">
                        <h2>Edit User</h2>
                        <button type="button" class="modal-close" onclick="closeEditModal()">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg>
                        </button>
                    </div>
                    <form id="editForm">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" id="edit_id" value="">
                        
                        <div class="form-group">
                            <label for="edit_name">Full Name</label>
                            <input type="text" id="edit_name" name="name" required 
                                   placeholder="Enter full name">
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_email">Email Address</label>
                            <input type="email" id="edit_email" name="email" required 
                                   placeholder="Enter email address">
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_password">Password</label>
                            <input type="password" id="edit_password" name="password" 
                                   placeholder="Leave blank to keep current">
                            <p class="password-note">Leave blank to keep the current password</p>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="edit_role">Role</label>
                                <select id="edit_role" name="role">
                                    <option value="user">User</option>
                                    <option value="staff">Staff</option>
                                    <option value="admin">Admin</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_status">Status</label>
                                <select id="edit_status" name="status">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="suspended">Suspended</option>
                                </select>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn-submit" id="editSubmitBtn">Update User</button>
                        <button type="button" class="btn-cancel" onclick="closeEditModal()">Cancel</button>
                    </form>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        const currentUserId = <?php echo $_SESSION['user_id']; ?>;
        
        // Show alert message
        function showAlert(message, type) {
            const alertEl = document.getElementById('alertMessage');
            alertEl.textContent = message;
            alertEl.className = `alert alert-${type}`;
            alertEl.style.display = 'block';
            
            setTimeout(() => {
                alertEl.style.display = 'none';
            }, 5000);
        }
        
        // Get initials from name
        function getInitials(name) {
            return name.split(' ').map(part => part.charAt(0).toUpperCase()).slice(0, 2).join('');
        }
        
        // Format date
        function formatDate(dateStr) {
            const date = new Date(dateStr);
            return date.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
        }
        
        // Create table row HTML
        function createTableRow(user) {
            const initials = getInitials(user.name);
            const deleteBtn = user.id != currentUserId ? `
                <button type="button" class="btn-delete" onclick="deleteUser(${user.id})">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="3 6 5 6 21 6"></polyline>
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                        <line x1="10" y1="11" x2="10" y2="17"></line>
                        <line x1="14" y1="11" x2="14" y2="17"></line>
                    </svg>
                    Delete
                </button>` : '';
            
            return `
                <tr data-id="${user.id}">
                    <td>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div class="user-avatar">${initials}</div>
                            <span class="user-name">${user.name}</span>
                        </div>
                    </td>
                    <td class="user-email">${user.email}</td>
                    <td>
                        <span class="role-badge role-${user.role}">${user.role.charAt(0).toUpperCase() + user.role.slice(1)}</span>
                    </td>
                    <td>
                        <span class="status-badge status-${user.status}">${user.status.charAt(0).toUpperCase() + user.status.slice(1)}</span>
                    </td>
                    <td>${formatDate(user.created_at)}</td>
                    <td>
                        <div class="action-btns">
                            <button type="button" class="btn-edit" onclick="editUser(${user.id})">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                </svg>
                                Edit
                            </button>
                            ${deleteBtn}
                        </div>
                    </td>
                </tr>
            `;
        }
        
        // Open create modal
        function openCreateModal() {
            document.getElementById('createForm').reset();
            document.getElementById('createModal').classList.add('active');
        }
        
        // Close create modal
        function closeCreateModal() {
            document.getElementById('createModal').classList.remove('active');
        }
        
        // Close edit modal
        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
        }
        
        // Edit user
        function editUser(id) {
            const formData = new FormData();
            formData.append('action', 'get');
            formData.append('id', id);
            
            fetch('user-management.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const user = data.user;
                    document.getElementById('edit_id').value = user.id;
                    document.getElementById('edit_name').value = user.name;
                    document.getElementById('edit_email').value = user.email;
                    document.getElementById('edit_password').value = '';
                    document.getElementById('edit_role').value = user.role;
                    document.getElementById('edit_status').value = user.status;
                    
                    document.getElementById('editModal').classList.add('active');
                } else {
                    showAlert(data.message, 'error');
                }
            })
            .catch(error => {
                showAlert('An error occurred while fetching user data', 'error');
            });
        }
        
        // Delete user
        function deleteUser(id) {
            if (!confirm('Are you sure you want to delete this user?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);
            
            fetch('user-management.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const row = document.querySelector(`tr[data-id="${id}"]`);
                    if (row) {
                        row.style.transition = 'opacity 0.3s';
                        row.style.opacity = '0';
                        setTimeout(() => row.remove(), 300);
                    }
                    showAlert(data.message, 'success');
                    updateStats();
                } else {
                    showAlert(data.message, 'error');
                }
            })
            .catch(error => {
                showAlert('An error occurred while deleting the user', 'error');
            });
        }
        
        // Update stats (simple reload approach)
        function updateStats() {
            // For simplicity, we'll just update after a brief delay to allow DOM changes
            // In production, you'd fetch updated counts via AJAX
        }
        
        // Handle create form submission
        document.getElementById('createForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const submitBtn = document.getElementById('createSubmitBtn');
            const originalText = submitBtn.textContent;
            
            submitBtn.textContent = 'Processing...';
            submitBtn.disabled = true;
            
            fetch('user-management.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    
                    // Add new row to table
                    const tbody = document.getElementById('usersTableBody');
                    if (tbody) {
                        tbody.insertAdjacentHTML('afterbegin', createTableRow(data.user));
                    }
                    
                    closeCreateModal();
                    this.reset();
                } else {
                    showAlert(data.message, 'error');
                }
            })
            .catch(error => {
                showAlert('An error occurred while creating the staff account', 'error');
            })
            .finally(() => {
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            });
        });
        
        // Handle edit form submission
        document.getElementById('editForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const submitBtn = document.getElementById('editSubmitBtn');
            const originalText = submitBtn.textContent;
            
            submitBtn.textContent = 'Processing...';
            submitBtn.disabled = true;
            
            fetch('user-management.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    
                    // Update existing row
                    const row = document.querySelector(`tr[data-id="${data.user.id}"]`);
                    if (row) {
                        // Update name
                        const nameSpan = row.querySelector('.user-name');
                        if (nameSpan) nameSpan.textContent = data.user.name;
                        
                        // Update avatar initials
                        const avatar = row.querySelector('.user-avatar');
                        if (avatar) avatar.textContent = getInitials(data.user.name);
                        
                        // Update email
                        const emailTd = row.querySelector('.user-email');
                        if (emailTd) emailTd.textContent = data.user.email;
                        
                        // Update role badge
                        const roleBadge = row.querySelector('.role-badge');
                        if (roleBadge) {
                            roleBadge.className = `role-badge role-${data.user.role}`;
                            roleBadge.textContent = data.user.role.charAt(0).toUpperCase() + data.user.role.slice(1);
                        }
                        
                        // Update status badge
                        const statusBadge = row.querySelector('.status-badge');
                        if (statusBadge) {
                            statusBadge.className = `status-badge status-${data.user.status}`;
                            statusBadge.textContent = data.user.status.charAt(0).toUpperCase() + data.user.status.slice(1);
                        }
                    }
                    
                    closeEditModal();
                } else {
                    showAlert(data.message, 'error');
                }
            })
            .catch(error => {
                showAlert('An error occurred while updating the user', 'error');
            })
            .finally(() => {
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            });
        });
        
        // Close modal when clicking outside
        document.getElementById('createModal').addEventListener('click', function(e) {
            if (e.target === this) closeCreateModal();
        });
        
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) closeEditModal();
        });
        
        // Close modal on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeCreateModal();
                closeEditModal();
            }
        });
    </script>
</body>
</html>
