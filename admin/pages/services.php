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

// Create addons table if not exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS addons (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        price DECIMAL(10, 2) NOT NULL,
        description TEXT,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {
    // Table might already exist
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Handle image upload
    function handleImageUpload($file) {
        if ($file['error'] === UPLOAD_ERR_OK) {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($file['type'], $allowedTypes)) {
                return ['error' => 'Invalid file type. Only JPG, PNG, GIF, and WEBP are allowed.'];
            }
            
            $maxSize = 5 * 1024 * 1024; // 5MB
            if ($file['size'] > $maxSize) {
                return ['error' => 'File size too large. Maximum 5MB allowed.'];
            }
            
            $uploadDir = __DIR__ . '/../../assets/image/services/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = uniqid('service_') . '.' . $extension;
            $filepath = $uploadDir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                return ['success' => $filename];
            } else {
                return ['error' => 'Failed to upload file.'];
            }
        }
        return ['error' => null]; // No file uploaded
    }
    
    if ($action === 'create') {
        $label = trim($_POST['label'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $price = floatval($_POST['price'] ?? 0);
        $duration = intval($_POST['duration'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $status = 'active';
        
        $image = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploadResult = handleImageUpload($_FILES['image']);
            if (isset($uploadResult['error']) && $uploadResult['error']) {
                $message = $uploadResult['error'];
                $messageType = 'error';
            } else if (isset($uploadResult['success'])) {
                $image = $uploadResult['success'];
            }
        }
        
        if (empty($message)) {
            try {
                $stmt = $db->prepare("INSERT INTO services (label, name, price, duration, image, description, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$label, $name, $price, $duration, $image, $description, $status]);
                $newId = $db->lastInsertId();
                $message = 'Service created successfully!';
                $messageType = 'success';
                
                if ($isAjax) {
                    echo json_encode([
                        'success' => true,
                        'message' => $message,
                        'service' => [
                            'id' => $newId,
                            'label' => $label,
                            'name' => $name,
                            'price' => $price,
                            'duration' => $duration,
                            'image' => $image,
                            'description' => $description
                        ]
                    ]);
                    exit;
                }
            } catch (PDOException $e) {
                $message = 'Error creating service: ' . $e->getMessage();
                $messageType = 'error';
                if ($isAjax) {
                    echo json_encode(['success' => false, 'message' => $message]);
                    exit;
                }
            }
        } else if ($isAjax) {
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }
    }
    
    if ($action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $price = floatval($_POST['price'] ?? 0);
        $duration = intval($_POST['duration'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $status = 'active';
        
        $image = $_POST['current_image'] ?? null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploadResult = handleImageUpload($_FILES['image']);
            if (isset($uploadResult['error']) && $uploadResult['error']) {
                $message = $uploadResult['error'];
                $messageType = 'error';
            } else if (isset($uploadResult['success'])) {
                // Delete old image if exists
                if ($image && file_exists(__DIR__ . '/../../assets/image/services/' . $image)) {
                    unlink(__DIR__ . '/../../assets/image/services/' . $image);
                }
                $image = $uploadResult['success'];
            }
        }
        
        if (empty($message)) {
            try {
                $stmt = $db->prepare("UPDATE services SET label = ?, name = ?, price = ?, duration = ?, image = ?, description = ?, status = ? WHERE id = ?");
                $stmt->execute([$label, $name, $price, $duration, $image, $description, $status, $id]);
                $message = 'Service updated successfully!';
                $messageType = 'success';
                
                if ($isAjax) {
                    echo json_encode([
                        'success' => true,
                        'message' => $message,
                        'service' => [
                            'id' => $id,
                            'label' => $label,
                            'name' => $name,
                            'price' => $price,
                            'duration' => $duration,
                            'image' => $image,
                            'description' => $description
                        ]
                    ]);
                    exit;
                }
            } catch (PDOException $e) {
                $message = 'Error updating service: ' . $e->getMessage();
                $messageType = 'error';
                if ($isAjax) {
                    echo json_encode(['success' => false, 'message' => $message]);
                    exit;
                }
            }
        } else if ($isAjax) {
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }
    }
    
    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        try {
            // Get image filename first
            $stmt = $db->prepare("SELECT image FROM services WHERE id = ?");
            $stmt->execute([$id]);
            $service = $stmt->fetch();
            
            // Delete service
            $stmt = $db->prepare("DELETE FROM services WHERE id = ?");
            $stmt->execute([$id]);
            
            // Delete image file if exists
            if ($service && $service['image'] && file_exists(__DIR__ . '/../../assets/image/services/' . $service['image'])) {
                unlink(__DIR__ . '/../../assets/image/services/' . $service['image']);
            }
            
            $message = 'Service deleted successfully!';
            $messageType = 'success';
            
            if ($isAjax) {
                echo json_encode(['success' => true, 'message' => $message, 'id' => $id]);
                exit;
            }
        } catch (PDOException $e) {
            $message = 'Error deleting service: ' . $e->getMessage();
            $messageType = 'error';
            if ($isAjax) {
                echo json_encode(['success' => false, 'message' => $message]);
                exit;
            }
        }
    }
    
    // Handle AJAX get service request
    if ($action === 'get') {
        $id = intval($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM services WHERE id = ?");
        $stmt->execute([$id]);
        $service = $stmt->fetch();
        if ($service) {
            echo json_encode(['success' => true, 'service' => $service]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Service not found']);
        }
        exit;
    }
    
    // ========== ADD-ON CRUD OPERATIONS ==========
    
    if ($action === 'addon_create') {
        $name = trim($_POST['addon_name'] ?? '');
        $price = floatval($_POST['addon_price'] ?? 0);
        $description = trim($_POST['addon_description'] ?? '');
        
        try {
            $stmt = $db->prepare("INSERT INTO addons (name, price, description, status) VALUES (?, ?, ?, 'active')");
            $stmt->execute([$name, $price, $description]);
            $newId = $db->lastInsertId();
            $message = 'Add-on created successfully!';
            $messageType = 'success';
            
            if ($isAjax) {
                echo json_encode([
                    'success' => true,
                    'message' => $message,
                    'addon' => [
                        'id' => $newId,
                        'name' => $name,
                        'price' => $price,
                        'description' => $description
                    ]
                ]);
                exit;
            }
        } catch (PDOException $e) {
            $message = 'Error creating add-on: ' . $e->getMessage();
            $messageType = 'error';
            if ($isAjax) {
                echo json_encode(['success' => false, 'message' => $message]);
                exit;
            }
        }
    }
    
    if ($action === 'addon_update') {
        $id = intval($_POST['addon_id'] ?? 0);
        $name = trim($_POST['addon_name'] ?? '');
        $price = floatval($_POST['addon_price'] ?? 0);
        $description = trim($_POST['addon_description'] ?? '');
        
        try {
            $stmt = $db->prepare("UPDATE addons SET name = ?, price = ?, description = ? WHERE id = ?");
            $stmt->execute([$name, $price, $description, $id]);
            $message = 'Add-on updated successfully!';
            $messageType = 'success';
            
            if ($isAjax) {
                echo json_encode([
                    'success' => true,
                    'message' => $message,
                    'addon' => [
                        'id' => $id,
                        'name' => $name,
                        'price' => $price,
                        'description' => $description
                    ]
                ]);
                exit;
            }
        } catch (PDOException $e) {
            $message = 'Error updating add-on: ' . $e->getMessage();
            $messageType = 'error';
            if ($isAjax) {
                echo json_encode(['success' => false, 'message' => $message]);
                exit;
            }
        }
    }
    
    if ($action === 'addon_delete') {
        $id = intval($_POST['addon_id'] ?? 0);
        try {
            $stmt = $db->prepare("DELETE FROM addons WHERE id = ?");
            $stmt->execute([$id]);
            $message = 'Add-on deleted successfully!';
            $messageType = 'success';
            
            if ($isAjax) {
                echo json_encode(['success' => true, 'message' => $message, 'id' => $id]);
                exit;
            }
        } catch (PDOException $e) {
            $message = 'Error deleting add-on: ' . $e->getMessage();
            $messageType = 'error';
            if ($isAjax) {
                echo json_encode(['success' => false, 'message' => $message]);
                exit;
            }
        }
    }
    
    if ($action === 'addon_get') {
        $id = intval($_POST['addon_id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM addons WHERE id = ?");
        $stmt->execute([$id]);
        $addon = $stmt->fetch();
        if ($addon) {
            echo json_encode(['success' => true, 'addon' => $addon]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Add-on not found']);
        }
        exit;
    }
}

// Fetch all services
$services = $db->query("SELECT * FROM services ORDER BY created_at DESC")->fetchAll();

// Fetch all add-ons
$addons = $db->query("SELECT * FROM addons ORDER BY created_at DESC")->fetchAll();

// Get service for editing
$editService = null;
if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    $stmt = $db->prepare("SELECT * FROM services WHERE id = ?");
    $stmt->execute([$editId]);
    $editService = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Services Management - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .services-container {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 2rem;
            min-width: 0;
        }

        .services-container > * {
            min-width: 0;
        }
        
        @media (max-width: 1024px) {
            .services-container {
                grid-template-columns: 1fr;
            }
        }
        
        .form-card, .table-card {
            background: var(--dark-card);
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid var(--border-color);
            min-width: 0;
        }
        
        .form-card h2, .table-card h2 {
            margin-bottom: 1.5rem;
            color: var(--cream);
            font-size: 1.25rem;
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
        .form-group textarea,
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
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary-red);
        }
        
        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .form-group input[type="file"] {
            padding: 10px;
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
        
        .services-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .services-table th,
        .services-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        .services-table th {
            color: var(--gray-text);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
        }
        
        .services-table td {
            color: var(--cream);
        }
        
        .services-table tr:hover {
            background: var(--dark-hover);
        }
        
        .service-image {
            width: 50px;
            height: 50px;
            border-radius: 8px;
            object-fit: cover;
            background: var(--dark-bg);
        }
        
        .service-label {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            background: rgba(230, 57, 70, 0.2);
            color: var(--primary-red);
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
            min-width: 42px;
            height: 36px;
            box-sizing: border-box;
        }
        
        .btn-edit svg, .btn-delete svg {
            flex-shrink: 0;
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
        
        .current-image {
            margin-top: 0.5rem;
        }
        
        .current-image img {
            width: 80px;
            height: 80px;
            border-radius: 8px;
            object-fit: cover;
        }
        
        .table-responsive {
            overflow-x: auto;
            width: 100%;
            -webkit-overflow-scrolling: touch;
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
        
        /* Section Tabs */
        .section-tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0;
        }
        
        .section-tab {
            padding: 1rem 1.5rem;
            background: none;
            border: none;
            color: var(--gray-text);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            position: relative;
            transition: all 0.3s;
        }
        
        .section-tab:hover {
            color: var(--cream);
        }
        
        .section-tab.active {
            color: var(--primary-red);
        }
        
        .section-tab.active::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--primary-red);
            border-radius: 3px 3px 0 0;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* Add-ons specific styles */
        .addons-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
        }
        
        .addon-card {
            background: var(--dark-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.25rem;
            transition: all 0.3s;
        }
        
        .addon-card:hover {
            border-color: var(--primary-red);
        }
        
        .addon-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
        }
        
        .addon-name {
            font-size: 1rem;
            font-weight: 600;
            color: var(--cream);
            margin: 0;
        }
        
        .addon-price {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary-red);
        }
        
        .addon-description {
            font-size: 0.85rem;
            color: var(--gray-text);
            margin-bottom: 1rem;
            line-height: 1.5;
        }
        
        .addon-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .addon-actions .btn-edit,
        .addon-actions .btn-delete {
            flex: 1;
            justify-content: center;
        }

        @media (max-width: 768px) {
            .services-container {
                gap: 1rem;
            }

            .form-card,
            .table-card {
                padding: 1rem;
            }

            .section-tabs {
                gap: 0.25rem;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                white-space: nowrap;
            }

            .section-tab {
                flex: 0 0 auto;
                padding: 0.85rem 1rem;
                font-size: 0.95rem;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .form-group input[type="file"] {
                width: 100%;
                max-width: 100%;
            }

            .services-table {
                min-width: 680px;
            }

            .table-responsive {
                margin: 0 -0.25rem;
            }

            .action-btns {
                flex-wrap: wrap;
            }

            .btn-edit,
            .btn-delete {
                min-width: 40px;
                padding: 8px 10px;
            }
        }

        @media (max-width: 480px) {
            .services-container {
                gap: 1rem;
            }

            .table-responsive {
                margin: 0;
            }

            .section-tab {
                padding: 0.75rem 0.85rem;
                font-size: 0.9rem;
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
                <h1>Services Management</h1>
                <p>Manage helmet cleaning services and add-ons</p>
            </div>
            
            <?php if ($message && !$isAjax): ?>
                <div class="alert alert-<?php echo $messageType; ?>" id="alertMessage">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php else: ?>
                <div class="alert" id="alertMessage" style="display: none;"></div>
            <?php endif; ?>
            
            <!-- Section Tabs -->
            <div class="section-tabs">
                <button class="section-tab active" onclick="switchTab('services')">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 8px;">
                        <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path>
                    </svg>
                    Services
                </button>
                <button class="section-tab" onclick="switchTab('addons')">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 8px;">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="16"></line>
                        <line x1="8" y1="12" x2="16" y2="12"></line>
                    </svg>
                    Add-ons
                </button>
            </div>
            <div class="swipe-hint">Swipe for more →</div>
            
            <!-- Services Tab Content -->
            <div class="tab-content active" id="services-tab">
            <div class="services-container">
                <!-- Add/Edit Form -->
                <div class="form-card">
                    <h2 id="formTitle"><?php echo $editService ? 'Edit Service' : 'Add New Service'; ?></h2>
                    <form method="POST" enctype="multipart/form-data" id="serviceForm">
                        <input type="hidden" name="action" id="formAction" value="<?php echo $editService ? 'update' : 'create'; ?>">
                        <input type="hidden" name="id" id="serviceId" value="<?php echo $editService['id'] ?? ''; ?>">
                        <input type="hidden" name="current_image" id="currentImage" value="<?php echo htmlspecialchars($editService['image'] ?? ''); ?>">
                        
                        <div class="form-group">
                            <label for="label">Label</label>
                            <input type="text" id="label" name="label" required 
                                   placeholder="e.g., X1, X2, X3"
                                   value="<?php echo htmlspecialchars($editService['label'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="name">Service Name</label>
                            <input type="text" id="name" name="name" required 
                                   placeholder="e.g., Basic Helmet Cleaning"
                                   value="<?php echo htmlspecialchars($editService['name'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="price">Price (₱)</label>
                                <input type="number" id="price" name="price" required 
                                       step="0.01" min="0" placeholder="0.00"
                                       value="<?php echo htmlspecialchars($editService['price'] ?? ''); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="duration">Duration (minutes)</label>
                                <input type="number" id="duration" name="duration" required 
                                       min="1" placeholder="30"
                                       value="<?php echo htmlspecialchars($editService['duration'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="image">Image</label>
                            <input type="file" id="image" name="image" accept="image/*">
                            <div class="current-image" id="currentImagePreview" style="<?php echo ($editService && $editService['image']) ? '' : 'display: none;'; ?>">
                                <p style="color: var(--gray-text); font-size: 0.85rem; margin-bottom: 0.5rem;">Current image:</p>
                                <img src="<?php echo ($editService && $editService['image']) ? '../../assets/image/services/' . htmlspecialchars($editService['image']) : ''; ?>" 
                                     alt="Current service image"
                                     id="currentImageImg"
                                     onerror="this.style.display='none'">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" 
                                      placeholder="Describe the service..."><?php echo htmlspecialchars($editService['description'] ?? ''); ?></textarea>
                        </div>
                        
                        <button type="submit" class="btn-submit" id="submitBtn">
                            <?php echo $editService ? 'Update Service' : 'Add Service'; ?>
                        </button>
                        
                        <button type="button" class="btn-cancel" id="cancelBtn" style="<?php echo $editService ? '' : 'display: none;'; ?>" onclick="resetForm()">Cancel</button>
                    </form>
                </div>
                
                <!-- Services Table -->
                <div class="table-card">
                    <h2>All Services</h2>
                    
                    <?php if (empty($services)): ?>
                        <div class="empty-state">
                            <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path>
                            </svg>
                            <p>No services found. Add your first service!</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="services-table" id="servicesTable">
                                <thead>
                                    <tr>
                                        <th>Image</th>
                                        <th>Label</th>
                                        <th>Name</th>
                                        <th>Price</th>
                                        <th>Duration</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="servicesTableBody">
                                    <?php foreach ($services as $service): ?>
                                        <tr data-id="<?php echo $service['id']; ?>">
                                            <td>
                                                <?php if ($service['image']): ?>
                                                    <img src="../../assets/image/services/<?php echo htmlspecialchars($service['image']); ?>" 
                                                         alt="<?php echo htmlspecialchars($service['name']); ?>" 
                                                         class="service-image"
                                                         onerror="this.src='../../assets/image/placeholder.png'">
                                                <?php else: ?>
                                                    <div class="service-image" style="display: flex; align-items: center; justify-content: center; color: var(--gray-text);">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                                            <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                                            <polyline points="21 15 16 10 5 21"></polyline>
                                                        </svg>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="service-label"><?php echo htmlspecialchars($service['label']); ?></span></td>
                                            <td><?php echo htmlspecialchars($service['name']); ?></td>
                                            <td>₱<?php echo number_format($service['price'], 2); ?></td>
                                            <td><?php echo $service['duration']; ?> min</td>
                                            <td>
                                                <div class="action-btns">
                                                    <button type="button" class="btn-edit" onclick="editService(<?php echo $service['id']; ?>)">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                                        </svg>
                                                    </button>
                                                    <button type="button" class="btn-delete" onclick="deleteService(<?php echo $service['id']; ?>)">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                            <polyline points="3 6 5 6 21 6"></polyline>
                                                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                                            <line x1="10" y1="11" x2="10" y2="17"></line>
                                                            <line x1="14" y1="11" x2="14" y2="17"></line>
                                                        </svg>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="swipe-hint">Swipe for more →</div>
                    <?php endif; ?>
                </div>
            </div>
            </div><!-- End Services Tab Content -->
            
            <!-- Add-ons Tab Content -->
            <div class="tab-content" id="addons-tab">
                <div class="services-container">
                    <!-- Add/Edit Add-on Form -->
                    <div class="form-card">
                        <h2 id="addonFormTitle">Add New Add-on</h2>
                        <form method="POST" id="addonForm">
                            <input type="hidden" name="action" id="addonFormAction" value="addon_create">
                            <input type="hidden" name="addon_id" id="addonId" value="">
                            
                            <div class="form-group">
                                <label for="addon_name">Add-on Name</label>
                                <input type="text" id="addon_name" name="addon_name" required 
                                       placeholder="e.g., Visor Coating, Anti-fog Treatment">
                            </div>
                            
                            <div class="form-group">
                                <label for="addon_price">Price (₱)</label>
                                <input type="number" id="addon_price" name="addon_price" required 
                                       step="0.01" min="0" placeholder="0.00">
                            </div>
                            
                            <div class="form-group">
                                <label for="addon_description">Description</label>
                                <textarea id="addon_description" name="addon_description" 
                                          placeholder="Describe the add-on..."></textarea>
                            </div>
                            
                            <button type="submit" class="btn-submit" id="addonSubmitBtn">Add Add-on</button>
                            <button type="button" class="btn-cancel" id="addonCancelBtn" style="display: none;" onclick="resetAddonForm()">Cancel</button>
                        </form>
                    </div>
                    
                    <!-- Add-ons List -->
                    <div class="table-card">
                        <h2>All Add-ons</h2>
                        
                        <?php if (empty($addons)): ?>
                            <div class="empty-state">
                                <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <line x1="12" y1="8" x2="12" y2="16"></line>
                                    <line x1="8" y1="12" x2="16" y2="12"></line>
                                </svg>
                                <p>No add-ons found. Add your first add-on!</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="services-table" id="addonsTable">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Price</th>
                                            <th>Description</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="addonsTableBody">
                                        <?php foreach ($addons as $addon): ?>
                                            <tr data-id="<?php echo $addon['id']; ?>">
                                                <td><?php echo htmlspecialchars($addon['name']); ?></td>
                                                <td>₱<?php echo number_format($addon['price'], 2); ?></td>
                                                <td><?php echo htmlspecialchars($addon['description'] ?? '-'); ?></td>
                                                <td>
                                                    <div class="action-btns">
                                                        <button type="button" class="btn-edit" onclick="editAddon(<?php echo $addon['id']; ?>)">
                                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                                            </svg>
                                                        </button>
                                                        <button type="button" class="btn-delete" onclick="deleteAddon(<?php echo $addon['id']; ?>)">
                                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                                <polyline points="3 6 5 6 21 6"></polyline>
                                                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                                                <line x1="10" y1="11" x2="10" y2="17"></line>
                                                                <line x1="14" y1="11" x2="14" y2="17"></line>
                                                            </svg>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="swipe-hint">Swipe for more →</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div><!-- End Add-ons Tab Content -->
        </main>
    </div>
    
    <script>
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
        
        // Format price
        function formatPrice(price) {
            return '₱' + parseFloat(price).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        
        // Create table row HTML
        function createTableRow(service) {
            const imageHtml = service.image 
                ? `<img src="../../assets/image/services/${service.image}" alt="${service.name}" class="service-image" onerror="this.src='../../assets/image/placeholder.png'">`
                : `<div class="service-image" style="display: flex; align-items: center; justify-content: center; color: var(--gray-text);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                        <polyline points="21 15 16 10 5 21"></polyline>
                    </svg>
                </div>`;
            
            return `
                <tr data-id="${service.id}">
                    <td>${imageHtml}</td>
                    <td><span class="service-label">${service.label}</span></td>
                    <td>${service.name}</td>
                    <td>${formatPrice(service.price)}</td>
                    <td>${service.duration} min</td>
                    <td>
                        <div class="action-btns">
                            <button type="button" class="btn-edit" onclick="editService(${service.id})">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                </svg>
                            </button>
                            <button type="button" class="btn-delete" onclick="deleteService(${service.id})">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="3 6 5 6 21 6"></polyline>
                                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                    <line x1="10" y1="11" x2="10" y2="17"></line>
                                    <line x1="14" y1="11" x2="14" y2="17"></line>
                                </svg>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        }
        
        // Reset form to create mode
        function resetForm() {
            document.getElementById('serviceForm').reset();
            document.getElementById('formAction').value = 'create';
            document.getElementById('serviceId').value = '';
            document.getElementById('currentImage').value = '';
            document.getElementById('formTitle').textContent = 'Add New Service';
            document.getElementById('submitBtn').textContent = 'Add Service';
            document.getElementById('cancelBtn').style.display = 'none';
            document.getElementById('currentImagePreview').style.display = 'none';
        }
        
        // Edit service
        function editService(id) {
            const formData = new FormData();
            formData.append('action', 'get');
            formData.append('id', id);
            
            fetch('services.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const service = data.service;
                    document.getElementById('formAction').value = 'update';
                    document.getElementById('serviceId').value = service.id;
                    document.getElementById('label').value = service.label;
                    document.getElementById('name').value = service.name;
                    document.getElementById('price').value = service.price;
                    document.getElementById('duration').value = service.duration;
                    document.getElementById('description').value = service.description || '';
                    document.getElementById('currentImage').value = service.image || '';
                    
                    document.getElementById('formTitle').textContent = 'Edit Service';
                    document.getElementById('submitBtn').textContent = 'Update Service';
                    document.getElementById('cancelBtn').style.display = 'block';
                    
                    if (service.image) {
                        document.getElementById('currentImagePreview').style.display = 'block';
                        document.getElementById('currentImageImg').src = '../../assets/image/services/' + service.image;
                    } else {
                        document.getElementById('currentImagePreview').style.display = 'none';
                    }
                    
                    // Scroll to form
                    document.querySelector('.form-card').scrollIntoView({ behavior: 'smooth' });
                } else {
                    showAlert(data.message, 'error');
                }
            })
            .catch(error => {
                showAlert('An error occurred while fetching service data', 'error');
            });
        }
        
        // Delete service
        function deleteService(id) {
            if (!confirm('Are you sure you want to delete this service?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);
            
            fetch('services.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Remove row from table
                    const row = document.querySelector(`tr[data-id="${id}"]`);
                    if (row) {
                        row.style.transition = 'opacity 0.3s';
                        row.style.opacity = '0';
                        setTimeout(() => row.remove(), 300);
                    }
                    showAlert(data.message, 'success');
                    
                    // Check if table is empty
                    setTimeout(() => {
                        const tbody = document.getElementById('servicesTableBody');
                        if (tbody && tbody.children.length === 0) {
                            location.reload();
                        }
                    }, 400);
                } else {
                    showAlert(data.message, 'error');
                }
            })
            .catch(error => {
                showAlert('An error occurred while deleting the service', 'error');
            });
        }
        
        // Handle form submission
        document.getElementById('serviceForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const action = document.getElementById('formAction').value;
            const submitBtn = document.getElementById('submitBtn');
            const originalText = submitBtn.textContent;
            
            submitBtn.textContent = 'Processing...';
            submitBtn.disabled = true;
            
            fetch('services.php', {
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
                    
                    if (action === 'create') {
                        // Add new row to table
                        const tbody = document.getElementById('servicesTableBody');
                        if (tbody) {
                            tbody.insertAdjacentHTML('afterbegin', createTableRow(data.service));
                        } else {
                            // Table doesn't exist, reload to show it
                            location.reload();
                        }
                        resetForm();
                    } else {
                        // Update existing row
                        const row = document.querySelector(`tr[data-id="${data.service.id}"]`);
                        if (row) {
                            row.outerHTML = createTableRow(data.service);
                        }
                        resetForm();
                    }
                } else {
                    showAlert(data.message, 'error');
                }
            })
            .catch(error => {
                showAlert('An error occurred while saving the service', 'error');
            })
            .finally(() => {
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            });
        });
        
        // ========== TAB SWITCHING ==========
        function switchTab(tabName) {
            // Update tab buttons
            document.querySelectorAll('.section-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.currentTarget.classList.add('active');
            
            // Update tab content
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.getElementById(tabName + '-tab').classList.add('active');
        }
        
        // ========== ADD-ON FUNCTIONS ==========
        
        // Create addon table row HTML
        function createAddonRow(addon) {
            return `
                <tr data-id="${addon.id}">
                    <td>${addon.name}</td>
                    <td>${formatPrice(addon.price)}</td>
                    <td>${addon.description || '-'}</td>
                    <td>
                        <div class="action-btns">
                            <button type="button" class="btn-edit" onclick="editAddon(${addon.id})">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                </svg>
                            </button>
                            <button type="button" class="btn-delete" onclick="deleteAddon(${addon.id})">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="3 6 5 6 21 6"></polyline>
                                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                    <line x1="10" y1="11" x2="10" y2="17"></line>
                                    <line x1="14" y1="11" x2="14" y2="17"></line>
                                </svg>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        }
        
        // Reset addon form
        function resetAddonForm() {
            document.getElementById('addonForm').reset();
            document.getElementById('addonFormAction').value = 'addon_create';
            document.getElementById('addonId').value = '';
            document.getElementById('addonFormTitle').textContent = 'Add New Add-on';
            document.getElementById('addonSubmitBtn').textContent = 'Add Add-on';
            document.getElementById('addonCancelBtn').style.display = 'none';
        }
        
        // Edit addon
        function editAddon(id) {
            const formData = new FormData();
            formData.append('action', 'addon_get');
            formData.append('addon_id', id);
            
            fetch('services.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const addon = data.addon;
                    document.getElementById('addonFormAction').value = 'addon_update';
                    document.getElementById('addonId').value = addon.id;
                    document.getElementById('addon_name').value = addon.name;
                    document.getElementById('addon_price').value = addon.price;
                    document.getElementById('addon_description').value = addon.description || '';
                    
                    document.getElementById('addonFormTitle').textContent = 'Edit Add-on';
                    document.getElementById('addonSubmitBtn').textContent = 'Update Add-on';
                    document.getElementById('addonCancelBtn').style.display = 'block';
                    
                    // Scroll to form
                    document.querySelector('#addons-tab .form-card').scrollIntoView({ behavior: 'smooth' });
                } else {
                    showAlert(data.message, 'error');
                }
            })
            .catch(error => {
                showAlert('An error occurred while fetching add-on data', 'error');
            });
        }
        
        // Delete addon
        function deleteAddon(id) {
            if (!confirm('Are you sure you want to delete this add-on?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'addon_delete');
            formData.append('addon_id', id);
            
            fetch('services.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Remove row from table
                    const row = document.querySelector(`#addonsTableBody tr[data-id="${id}"]`);
                    if (row) {
                        row.style.transition = 'opacity 0.3s';
                        row.style.opacity = '0';
                        setTimeout(() => row.remove(), 300);
                    }
                    showAlert(data.message, 'success');
                    
                    // Check if table is empty
                    setTimeout(() => {
                        const tbody = document.getElementById('addonsTableBody');
                        if (tbody && tbody.children.length === 0) {
                            location.reload();
                        }
                    }, 400);
                } else {
                    showAlert(data.message, 'error');
                }
            })
            .catch(error => {
                showAlert('An error occurred while deleting the add-on', 'error');
            });
        }
        
        // Handle addon form submission
        document.getElementById('addonForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const action = document.getElementById('addonFormAction').value;
            const submitBtn = document.getElementById('addonSubmitBtn');
            const originalText = submitBtn.textContent;
            
            submitBtn.textContent = 'Processing...';
            submitBtn.disabled = true;
            
            fetch('services.php', {
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
                    
                    if (action === 'addon_create') {
                        // Add new row to table
                        const tbody = document.getElementById('addonsTableBody');
                        if (tbody) {
                            tbody.insertAdjacentHTML('afterbegin', createAddonRow(data.addon));
                        } else {
                            // Table doesn't exist, reload to show it
                            location.reload();
                        }
                        resetAddonForm();
                    } else {
                        // Update existing row
                        const row = document.querySelector(`#addonsTableBody tr[data-id="${data.addon.id}"]`);
                        if (row) {
                            row.outerHTML = createAddonRow(data.addon);
                        }
                        resetAddonForm();
                    }
                } else {
                    showAlert(data.message, 'error');
                }
            })
            .catch(error => {
                showAlert('An error occurred while saving the add-on', 'error');
            })
            .finally(() => {
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            });
        });
    </script>
</body>
</html>