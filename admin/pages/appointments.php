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

// Handle AJAX requests
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    // Get filter parameters
    $periodFilter = $_GET['period'] ?? 'all';
    $statusFilter = $_GET['status'] ?? 'all';
    $searchQuery = $_GET['search'] ?? '';
    $dateFrom = $_GET['from'] ?? '';
    $dateTo = $_GET['to'] ?? '';
    
    // Build the query with filters
    $whereConditions = [];
    $params = [];
    
    // Period filter
    if ($periodFilter === 'today') {
        $whereConditions[] = "DATE(a.appointment_date) = CURDATE()";
    } elseif ($periodFilter === 'week') {
        $whereConditions[] = "a.appointment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    } elseif ($periodFilter === 'month') {
        $whereConditions[] = "a.appointment_date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
    } elseif ($periodFilter === 'year') {
        $whereConditions[] = "a.appointment_date >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
    }
    
    // Custom date range
    if (!empty($dateFrom) && !empty($dateTo)) {
        $whereConditions[] = "DATE(a.appointment_date) BETWEEN :dateFrom AND :dateTo";
        $params[':dateFrom'] = $dateFrom;
        $params[':dateTo'] = $dateTo;
    }
    
    // Status filter
    if ($statusFilter !== 'all') {
        $whereConditions[] = "a.status = :status";
        $params[':status'] = $statusFilter;
    }
    
    // Search filter
    if (!empty($searchQuery)) {
        $whereConditions[] = "(a.full_name LIKE :search OR a.contact LIKE :search2 OR a.id LIKE :search3)";
        $params[':search'] = "%$searchQuery%";
        $params[':search2'] = "%$searchQuery%";
        $params[':search3'] = "%$searchQuery%";
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    $appointments = [];
    $statusCounts = ['all' => 0, 'pending' => 0, 'confirmed' => 0, 'in_progress' => 0, 'completed' => 0, 'cancelled' => 0];
    
    try {
        // Get total counts per status
        $countStmt = $db->query("SELECT status, COUNT(*) as count FROM appointments GROUP BY status");
        while ($row = $countStmt->fetch()) {
            $statusCounts[$row['status']] = $row['count'];
            $statusCounts['all'] += $row['count'];
        }
        
        // Fetch filtered appointments
        $sql = "
            SELECT a.*, s.name as service_name, s.label as service_label, s.price as service_price,
                   u.name as user_name, u.email as user_email
            FROM appointments a 
            LEFT JOIN services s ON a.service_id = s.id 
            LEFT JOIN users u ON a.user_id = u.id
            $whereClause
            ORDER BY a.appointment_date DESC, a.appointment_time DESC
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error']);
        exit;
    }
    
    // Calculate filtered revenue
    $filteredRevenue = 0;
    foreach ($appointments as $apt) {
        if ($apt['status'] === 'completed') {
            $filteredRevenue += $apt['service_price'] * $apt['quantity'];
        }
    }
    
    echo json_encode([
        'success' => true,
        'appointments' => $appointments,
        'totalCount' => count($appointments),
        'filteredRevenue' => $filteredRevenue,
        'statusCounts' => $statusCounts
    ]);
    exit;
}

// Get filter parameters for initial page load
$periodFilter = $_GET['period'] ?? 'all';
$statusFilter = $_GET['status'] ?? 'all';
$searchQuery = $_GET['search'] ?? '';
$dateFrom = $_GET['from'] ?? '';
$dateTo = $_GET['to'] ?? '';

// Build the query with filters
$whereConditions = [];
$params = [];

// Period filter
if ($periodFilter === 'today') {
    $whereConditions[] = "DATE(a.appointment_date) = CURDATE()";
} elseif ($periodFilter === 'week') {
    $whereConditions[] = "a.appointment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
} elseif ($periodFilter === 'month') {
    $whereConditions[] = "a.appointment_date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
} elseif ($periodFilter === 'year') {
    $whereConditions[] = "a.appointment_date >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
}

// Custom date range
if (!empty($dateFrom) && !empty($dateTo)) {
    $whereConditions[] = "DATE(a.appointment_date) BETWEEN :dateFrom AND :dateTo";
    $params[':dateFrom'] = $dateFrom;
    $params[':dateTo'] = $dateTo;
}

// Status filter
if ($statusFilter !== 'all') {
    $whereConditions[] = "a.status = :status";
    $params[':status'] = $statusFilter;
}

// Search filter
if (!empty($searchQuery)) {
    $whereConditions[] = "(a.full_name LIKE :search OR a.contact LIKE :search2 OR a.id LIKE :search3)";
    $params[':search'] = "%$searchQuery%";
    $params[':search2'] = "%$searchQuery%";
    $params[':search3'] = "%$searchQuery%";
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Fetch appointments
$appointments = [];
$totalCount = 0;
$statusCounts = ['all' => 0, 'pending' => 0, 'confirmed' => 0, 'in_progress' => 0, 'completed' => 0, 'cancelled' => 0];

try {
    // Get total counts per status
    $countStmt = $db->query("
        SELECT status, COUNT(*) as count FROM appointments GROUP BY status
    ");
    while ($row = $countStmt->fetch()) {
        $statusCounts[$row['status']] = $row['count'];
        $statusCounts['all'] += $row['count'];
    }
    
    // Fetch filtered appointments
    $sql = "
        SELECT a.*, s.name as service_name, s.label as service_label, s.price as service_price,
               u.name as user_name, u.email as user_email
        FROM appointments a 
        LEFT JOIN services s ON a.service_id = s.id 
        LEFT JOIN users u ON a.user_id = u.id
        $whereClause
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalCount = count($appointments);
    
} catch (PDOException $e) {
    // Table might not exist yet
}

// Calculate filtered revenue
$filteredRevenue = 0;
foreach ($appointments as $apt) {
    if ($apt['status'] === 'completed') {
        $filteredRevenue += $apt['service_price'] * $apt['quantity'];
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="allow-page-x-scroll">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointments - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .filter-section {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }
        
        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
        }
        
        .filter-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            min-width: 0;
        }
        
        .filter-group label {
            color: var(--gray-text);
            font-size: 0.85rem;
            white-space: nowrap;
        }
        
        .filter-buttons {
            display: flex;
            gap: 0.25rem;
            background: var(--dark-bg);
            padding: 4px;
            border-radius: 8px;
        }
        
        .filter-btn {
            padding: 8px 14px;
            border: none;
            background: transparent;
            color: var(--gray-text);
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.2s;
        }
        
        .filter-btn:hover {
            background: var(--dark-hover);
            color: var(--cream);
        }
        
        .filter-btn.active {
            background: var(--primary-red);
            color: white;
        }
        
        .status-tabs {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            min-width: 0;
        }
        
        .status-tab {
            padding: 8px 16px;
            border: 1px solid var(--border-color);
            background: transparent;
            color: var(--gray-text);
            border-radius: 20px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .status-tab:hover {
            border-color: var(--primary-red);
            color: var(--cream);
        }
        
        .status-tab.active {
            background: var(--primary-red);
            border-color: var(--primary-red);
            color: white;
        }
        
        .status-tab .count {
            background: rgba(255,255,255,0.2);
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
        }
        
        .status-tab.active .count {
            background: rgba(255,255,255,0.3);
        }
        
        .date-input {
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            background: var(--dark-bg);
            color: var(--cream);
            border-radius: 6px;
            font-size: 0.85rem;
            color-scheme: dark;
        }
        
        .date-input::-webkit-calendar-picker-indicator {
            filter: invert(1);
            cursor: pointer;
        }
        
        .date-input:focus {
            outline: none;
            border-color: var(--primary-red);
        }
        
        .search-box {
            flex: 1;
            min-width: 0;
            max-width: 360px;
        }
        
        .search-box input {
            width: 100%;
            padding: 10px 14px 10px 38px;
            border: 1px solid var(--border-color);
            background: var(--dark-bg);
            color: var(--cream);
            border-radius: 8px;
            font-size: 0.9rem;
        }
        
        .search-box input:focus {
            outline: none;
            border-color: var(--primary-red);
        }
        
        .search-box {
            position: relative;
        }
        
        .search-box svg {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-text);
        }
        
        .summary-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .summary-bar .result-count {
            color: var(--gray-text);
            font-size: 0.9rem;
        }
        
        .summary-bar .result-count strong {
            color: var(--cream);
        }
        
        .summary-bar .revenue-badge {
            background: rgba(76, 175, 80, 0.15);
            color: #4caf50;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .appointments-card {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
        }
        
        .appointments-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .appointments-table thead th {
            text-align: left;
            padding: 14px 12px;
            color: var(--gray-text);
            font-weight: 600;
            font-size: 0.85rem;
            background: rgba(0,0,0,0.2);
            border-bottom: 1px solid var(--border-color);
        }
        
        .appointments-table tbody td {
            padding: 14px 12px;
            color: var(--cream);
            border-bottom: 1px solid rgba(255,255,255,0.03);
            font-size: 0.9rem;
        }
        
        .appointments-table tbody tr:hover {
            background: var(--dark-hover);
        }
        
        .reference-code {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            color: var(--primary-red);
        }
        
        .badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: capitalize;
        }
        
        .badge-pending { background: rgba(255,193,7,0.15); color: #ffc107; }
        .badge-confirmed { background: rgba(33,150,243,0.15); color: #2196f3; }
        .badge-in_progress { background: rgba(255,152,0,0.15); color: #ff9800; }
        .badge-completed { background: rgba(76,175,80,0.15); color: #4caf50; }
        .badge-cancelled { background: rgba(244,67,54,0.15); color: #f44336; }
        
        .btn-view {
            padding: 6px 12px;
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--gray-text);
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.8rem;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-view:hover {
            border-color: var(--primary-red);
            color: var(--primary-red);
        }
        
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--gray-text);
        }
        
        .empty-state svg {
            opacity: 0.3;
            margin-bottom: 1rem;
        }
        
        .empty-state h3 {
            color: var(--cream);
            margin-bottom: 0.5rem;
        }
        
        .small-text {
            font-size: 0.85rem;
            color: var(--gray-text);
        }
        
        /* Modal styles */
        .modal {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.7);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            backdrop-filter: blur(4px);
        }
        
        .modal.active { display: flex; }
        
        .modal-content {
            background: var(--dark-card);
            padding: 24px;
            border-radius: 16px;
            width: 600px;
            max-width: 95%;
            border: 1px solid var(--border-color);
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .modal-header h3 {
            color: var(--cream);
            font-size: 1.25rem;
        }
        
        .modal-close {
            background: transparent;
            border: none;
            color: var(--gray-text);
            font-size: 1.5rem;
            cursor: pointer;
        }
        
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }
        
        .detail-item {
            padding: 1rem;
            background: var(--dark-bg);
            border-radius: 8px;
        }
        
        .detail-item label {
            display: block;
            color: var(--gray-text);
            font-size: 0.8rem;
            margin-bottom: 0.5rem;
        }
        
        .detail-item span {
            color: var(--cream);
            font-weight: 500;
        }
        
        .detail-item.full-width {
            grid-column: span 2;
        }

        @media (hover: none) and (pointer: coarse) {
            .filter-row {
                flex-wrap: wrap;
                overflow: visible;
                gap: 0.75rem;
            }

            .filter-group {
                width: 100%;
                min-width: 0;
                flex: 1 1 100%;
                flex-wrap: wrap;
            }

            .filter-buttons,
            .status-tabs,
            .table-responsive-wrapper {
                max-width: 100%;
                overflow-x: auto;
                overflow-y: hidden;
                -webkit-overflow-scrolling: touch;
                overscroll-behavior-x: contain;
                scrollbar-width: none;
            }

            .filter-buttons::-webkit-scrollbar,
            .status-tabs::-webkit-scrollbar,
            .table-responsive-wrapper::-webkit-scrollbar {
                display: none;
            }

            .filter-buttons,
            .status-tabs {
                flex-wrap: nowrap;
            }

            .filter-buttons .filter-btn,
            .status-tab {
                flex: 0 0 auto;
                white-space: nowrap;
            }

            .status-tabs {
                min-width: 0;
            }

            .table-responsive-wrapper > .appointments-table {
                min-width: 760px;
            }
        }
        
        @media (max-width: 768px) {
            .filter-row {
                flex-direction: column;
                flex-wrap: nowrap;
                align-items: stretch;
                overflow: visible;
                padding-bottom: 0;
                gap: 0.75rem;
            }

            .filter-group {
                width: 100%;
                min-width: 0;
                flex: 1 1 100%;
                flex-wrap: wrap;
            }

            .filter-group:first-child {
                display: flex;
                align-items: flex-start;
                flex-wrap: wrap;
            }

            .filter-group:first-child label {
                display: block;
                margin-bottom: 0.35rem;
            }

            .filter-group:first-child .filter-buttons {
                width: 100%;
            }

            .filter-group .date-input {
                flex: 1 1 140px;
                min-width: 132px;
            }

            .filter-group .filter-btn {
                flex: 0 0 auto;
                white-space: nowrap;
            }
            
            .filter-buttons {
                justify-content: flex-start;
                overflow-x: auto;
                overflow-y: hidden;
                max-width: none;
                -webkit-overflow-scrolling: touch;
                overscroll-behavior-x: contain;
                flex-wrap: nowrap;
                padding: 4px;
                gap: 0.25rem;
                background: var(--dark-bg);
                border-radius: 8px;
                scrollbar-width: none;
            }

            .filter-buttons::-webkit-scrollbar {
                display: none;
            }

            .filter-buttons .filter-btn {
                flex: 0 0 auto;
                min-width: 78px;
                white-space: nowrap;
                text-align: center;
            }
            
            .status-tabs {
                justify-content: flex-start;
                flex-wrap: nowrap;
                overflow-x: auto;
                overflow-y: hidden;
                padding-bottom: 0.35rem;
                flex: 1 1 auto;
                min-width: 0;
                max-width: 100%;
                -webkit-overflow-scrolling: touch;
                overscroll-behavior-x: contain;
                gap: 0.5rem;
                scrollbar-width: none;
            }

            .status-tabs::-webkit-scrollbar {
                display: none;
            }

            .status-tab {
                flex: 0 0 auto;
                white-space: nowrap;
            }
            
            .search-box {
                flex: 1 1 auto;
                width: 100%;
                max-width: 100%;
            }

            .status-tabs {
                width: 100%;
            }

            .appointments-card {
                overflow: visible;
            }

            .table-responsive-wrapper > .appointments-table {
                min-width: 760px;
            }
            
            .detail-grid {
                grid-template-columns: 1fr;
            }
            
            .detail-item.full-width {
                grid-column: span 1;
            }
        }

        @media (max-width: 640px) {
            .appointments-card {
                overflow: visible;
            }

            .table-responsive-wrapper {
                width: 100%;
                max-width: 100%;
                margin: 0;
                overflow-x: scroll;
                overflow-y: hidden;
                -webkit-overflow-scrolling: touch;
                overscroll-behavior-x: contain;
                overscroll-behavior-y: auto;
                scroll-padding-inline: 0.35rem;
                scrollbar-width: thin;
                padding: 0 0.35rem 0.1rem;
            }

            .table-responsive-wrapper::-webkit-scrollbar {
                height: 6px;
            }

            .table-responsive-wrapper::-webkit-scrollbar-thumb {
                background: rgba(255, 255, 255, 0.25);
                border-radius: 999px;
            }

            .table-responsive-wrapper > .appointments-table {
                min-width: 900px;
            }

            .appointments-table thead th,
            .appointments-table tbody td {
                white-space: nowrap;
            }

            .appointments-table tbody td .small-text {
                display: block;
                white-space: nowrap;
            }

        }

        @media (max-width: 480px) {
            .filter-section {
                padding: 0.9rem;
            }

            .filter-row {
                gap: 0.6rem;
            }

            .filter-group .date-input {
                min-width: 124px;
            }

            .search-box {
                flex-basis: 230px;
                width: 230px;
            }

            .status-tab {
                padding: 7px 12px;
                font-size: 0.8rem;
            }
        }
    </style>
</head>
<body class="allow-page-x-scroll">
    <div class="dashboard-layout">
        <?php include '../includes/sidebar.php'; ?>
        <?php include '../includes/header.php'; ?>
        
        <div class="sidebar-overlay" onclick="toggleSidebar()"></div>
        
        <main class="main-content">
            <div class="dashboard-header">
                <h1>Appointments</h1>
                <p>View and manage all customer appointments</p>
            </div>
            
            <!-- Filter Section -->
            <div class="filter-section">
                <div class="filter-row" style="margin-bottom: 1rem;">
                    <div class="filter-group">
                        <label>Period:</label>
                        <div class="filter-buttons drag-scroll" id="periodFilterButtons">
                            <button type="button" data-period="all" class="filter-btn <?php echo $periodFilter === 'all' ? 'active' : ''; ?>">All</button>
                            <button type="button" data-period="today" class="filter-btn <?php echo $periodFilter === 'today' ? 'active' : ''; ?>">Today</button>
                            <button type="button" data-period="week" class="filter-btn <?php echo $periodFilter === 'week' ? 'active' : ''; ?>">Week</button>
                            <button type="button" data-period="month" class="filter-btn <?php echo $periodFilter === 'month' ? 'active' : ''; ?>">Month</button>
                            <button type="button" data-period="year" class="filter-btn <?php echo $periodFilter === 'year' ? 'active' : ''; ?>">Year</button>
                        </div>
                        <div class="swipe-hint">Swipe for more →</div>
                    </div>
                    
                    <div class="filter-group">
                        <label>From:</label>
                        <input type="date" name="from" class="date-input" value="<?php echo htmlspecialchars($dateFrom); ?>">
                        <label>To:</label>
                        <input type="date" name="to" class="date-input" value="<?php echo htmlspecialchars($dateTo); ?>">
                        <button type="button" id="applyDateBtn" class="filter-btn" style="background: var(--primary-red); color: white;">Apply</button>
                        <button type="button" id="clearDateBtn" class="filter-btn">Clear</button>
                    </div>
                    
                    <div class="search-box">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"></circle>
                            <path d="m21 21-4.35-4.35"></path>
                        </svg>
                        <input type="text" name="search" placeholder="Search name, contact, ID..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                    </div>
                </div>
                
                <div class="filter-row">
                    <label style="color: var(--gray-text); font-size: 0.85rem;">Status:</label>
                    <div class="status-tabs drag-scroll" id="statusTabs">
                        <button type="button" class="status-tab <?php echo $statusFilter === 'all' ? 'active' : ''; ?>" data-status="all">
                            All <span class="count"><?php echo $statusCounts['all']; ?></span>
                        </button>
                        <button type="button" class="status-tab <?php echo $statusFilter === 'pending' ? 'active' : ''; ?>" data-status="pending">
                            Pending <span class="count"><?php echo $statusCounts['pending']; ?></span>
                        </button>
                        <button type="button" class="status-tab <?php echo $statusFilter === 'confirmed' ? 'active' : ''; ?>" data-status="confirmed">
                            Confirmed <span class="count"><?php echo $statusCounts['confirmed']; ?></span>
                        </button>
                        <button type="button" class="status-tab <?php echo $statusFilter === 'in_progress' ? 'active' : ''; ?>" data-status="in_progress">
                            In Progress <span class="count"><?php echo $statusCounts['in_progress']; ?></span>
                        </button>
                        <button type="button" class="status-tab <?php echo $statusFilter === 'completed' ? 'active' : ''; ?>" data-status="completed">
                            Completed <span class="count"><?php echo $statusCounts['completed']; ?></span>
                        </button>
                        <button type="button" class="status-tab <?php echo $statusFilter === 'cancelled' ? 'active' : ''; ?>" data-status="cancelled">
                            Cancelled <span class="count"><?php echo $statusCounts['cancelled']; ?></span>
                        </button>
                    </div>
                    <div class="swipe-hint">Swipe for more →</div>
                </div>
            </div>
            
            <!-- Summary Bar -->
            <div class="summary-bar">
                <div class="result-count" id="resultCount">
                    Showing <strong><?php echo $totalCount; ?></strong> appointment<?php echo $totalCount !== 1 ? 's' : ''; ?>
                    <?php if ($statusFilter !== 'all'): ?>
                        with status <strong><?php echo ucfirst(str_replace('_', ' ', $statusFilter)); ?></strong>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Appointments Table -->
            <div class="appointments-card" id="tableContainer">
                <!-- Table content loaded via AJAX -->
                <div style="text-align:center;padding:3rem;color:var(--gray-text);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation: spin 1s linear infinite;">
                        <path d="M21 12a9 9 0 1 1-6.219-8.56"></path>
                    </svg>
                    <p style="margin-top:1rem;">Loading appointments...</p>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Appointment Details Modal -->
    <div class="modal" id="detailsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Appointment Details</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div id="modalBody">
                <!-- Filled by JavaScript -->
            </div>
        </div>
    </div>
    
    <script>
        // Current filter state
        let currentFilters = {
            period: '<?php echo $periodFilter; ?>',
            status: '<?php echo $statusFilter; ?>',
            search: '<?php echo addslashes($searchQuery); ?>',
            from: '<?php echo $dateFrom; ?>',
            to: '<?php echo $dateTo; ?>'
        };
        
        let searchTimeout = null;
        
        // Initialize event listeners
        document.addEventListener('DOMContentLoaded', function() {
            // Load initial data
            fetchAppointments();
            
            // Period filter buttons
            document.querySelectorAll('.filter-btn[data-period]').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    currentFilters.period = this.dataset.period;
                    currentFilters.from = '';
                    currentFilters.to = '';
                    document.querySelector('input[name="from"]').value = '';
                    document.querySelector('input[name="to"]').value = '';
                    fetchAppointments();
                    updatePeriodButtons();
                });
            });
            
            // Status tabs
            document.querySelectorAll('.status-tab').forEach(tab => {
                tab.addEventListener('click', function() {
                    currentFilters.status = this.dataset.status;
                    fetchAppointments();
                    updateStatusTabs();
                });
            });
            
            // Search input with debounce
            document.querySelector('input[name="search"]').addEventListener('input', function() {
                clearTimeout(searchTimeout);
                const value = this.value;
                searchTimeout = setTimeout(() => {
                    currentFilters.search = value;
                    fetchAppointments();
                }, 300);
            });
            
            // Date range apply button
            document.getElementById('applyDateBtn').addEventListener('click', function(e) {
                e.preventDefault();
                currentFilters.from = document.querySelector('input[name="from"]').value;
                currentFilters.to = document.querySelector('input[name="to"]').value;
                if (currentFilters.from && currentFilters.to) {
                    currentFilters.period = 'custom';
                    updatePeriodButtons();
                }
                fetchAppointments();
            });
            
            // Clear date button
            document.getElementById('clearDateBtn').addEventListener('click', function(e) {
                e.preventDefault();
                currentFilters.from = '';
                currentFilters.to = '';
                document.querySelector('input[name="from"]').value = '';
                document.querySelector('input[name="to"]').value = '';
                fetchAppointments();
            });
        });
        
        function updatePeriodButtons() {
            document.querySelectorAll('.filter-btn[data-period]').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.period === currentFilters.period);
            });
        }
        
        function updateStatusTabs() {
            document.querySelectorAll('.status-tab').forEach(tab => {
                tab.classList.toggle('active', tab.dataset.status === currentFilters.status);
            });
        }
        
        function fetchAppointments() {
            const params = new URLSearchParams({
                period: currentFilters.period,
                status: currentFilters.status,
                search: currentFilters.search,
                from: currentFilters.from,
                to: currentFilters.to
            });
            
            // Show loading state
            document.getElementById('tableContainer').innerHTML = `
                <div style="text-align:center;padding:3rem;color:var(--gray-text);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation: spin 1s linear infinite;">
                        <path d="M21 12a9 9 0 1 1-6.219-8.56"></path>
                    </svg>
                    <p style="margin-top:1rem;">Loading appointments...</p>
                </div>
            `;
            
            fetch(`appointments.php?${params.toString()}`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateTable(data.appointments);
                    updateSummary(data.totalCount, data.filteredRevenue, data.statusCounts);
                    updateStatusCounts(data.statusCounts);
                }
            })
            .catch(error => {
                console.error('Error fetching appointments:', error);
                document.getElementById('tableContainer').innerHTML = `
                    <div class="empty-state">
                        <h3>Error loading appointments</h3>
                        <p>Please try again later.</p>
                    </div>
                `;
            });
        }
        
        function updateTable(appointments) {
            const container = document.getElementById('tableContainer');
            
            if (appointments.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                            <line x1="16" y1="2" x2="16" y2="6"></line>
                            <line x1="8" y1="2" x2="8" y2="6"></line>
                            <line x1="3" y1="10" x2="21" y2="10"></line>
                        </svg>
                        <h3>No appointments found</h3>
                        <p>No appointments match your current filters. Try adjusting the filters above.</p>
                    </div>
                `;
                return;
            }
            
            const statusLabels = {
                'pending': 'Pending',
                'confirmed': 'Confirmed',
                'in_progress': 'In Progress',
                'completed': 'Completed',
                'cancelled': 'Cancelled'
            };
            
            let html = `
                <div class="table-responsive-wrapper drag-scroll" id="appointmentsTableScroller">
                    <table class="appointments-table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Customer</th>
                                <th>Service</th>
                                <th>Date & Time</th>
                                <th>Quantity</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            appointments.forEach(apt => {
                const amount = apt.service_price * apt.quantity;
                const dateObj = new Date(apt.appointment_date);
                const formattedDate = dateObj.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
                const statusLabel = statusLabels[apt.status] || apt.status;
                
                html += `
                    <tr>
                        <td>
                            <span class="reference-code">APT-${String(apt.id).padStart(6, '0')}</span>
                        </td>
                        <td>
                            <div>${escapeHtml(apt.full_name)}</div>
                            <div class="small-text">${escapeHtml(apt.contact)}</div>
                        </td>
                        <td>
                            <div>${escapeHtml(apt.service_label + ' - ' + apt.service_name)}</div>
                            <div class="small-text">${escapeHtml(apt.helmet_type || 'N/A')}</div>
                        </td>
                        <td>
                            <div>${formattedDate}</div>
                            <div class="small-text">${apt.appointment_time}</div>
                        </td>
                        <td>${apt.quantity}</td>
                        <td>₱${parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                        <td>
                            <span class="badge badge-${apt.status}">${statusLabel}</span>
                        </td>
                        <td>
                            <button class="btn-view" onclick='showDetails(${JSON.stringify(apt).replace(/'/g, "\\'")})'>
                                View
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            html += '</tbody></table></div><div class="swipe-hint">Swipe for more →</div>';
            container.innerHTML = html;

            if (window.initDragScrollableAreas) {
                window.initDragScrollableAreas(container);
            }
            if (window.refreshDragScrollableAreas) {
                window.refreshDragScrollableAreas(container);
            }
        }
        
        function updateSummary(totalCount, revenue, statusCounts) {
            const statusFilter = currentFilters.status;
            const statusLabel = statusFilter !== 'all' ? statusFilter.replace('_', ' ') : '';
            
            let summaryHtml = `Showing <strong>${totalCount}</strong> appointment${totalCount !== 1 ? 's' : ''}`;
            if (statusFilter !== 'all') {
                summaryHtml += ` with status <strong>${statusLabel.charAt(0).toUpperCase() + statusLabel.slice(1)}</strong>`;
            }
            
            document.getElementById('resultCount').innerHTML = summaryHtml;
        }
        
        function updateStatusCounts(counts) {
            document.querySelectorAll('.status-tab').forEach(tab => {
                const status = tab.dataset.status;
                const countEl = tab.querySelector('.count');
                if (countEl && counts[status] !== undefined) {
                    countEl.textContent = counts[status];
                }
            });
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function showDetails(apt) {
            const statusLabels = {
                'pending': 'Pending',
                'confirmed': 'Confirmed',
                'in_progress': 'In Progress',
                'completed': 'Completed',
                'cancelled': 'Cancelled'
            };
            
            const amount = apt.service_price * apt.quantity;
            const date = new Date(apt.appointment_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
            const time = apt.appointment_time;
            
            document.getElementById('modalBody').innerHTML = `
                <div class="detail-grid">
                    <div class="detail-item">
                        <label>Reference ID</label>
                        <span style="font-family: monospace; color: var(--primary-red);">APT-${String(apt.id).padStart(6, '0')}</span>
                    </div>
                    <div class="detail-item">
                        <label>Status</label>
                        <span class="badge badge-${apt.status}">${statusLabels[apt.status] || apt.status}</span>
                    </div>
                    <div class="detail-item">
                        <label>Customer Name</label>
                        <span>${escapeHtml(apt.full_name)}</span>
                    </div>
                    <div class="detail-item">
                        <label>Contact</label>
                        <span>${escapeHtml(apt.contact)}</span>
                    </div>
                    <div class="detail-item">
                        <label>Service</label>
                        <span>${escapeHtml(apt.service_label)} - ${escapeHtml(apt.service_name)}</span>
                    </div>
                    <div class="detail-item">
                        <label>Helmet Type</label>
                        <span>${escapeHtml(apt.helmet_type) || 'N/A'}</span>
                    </div>
                    <div class="detail-item">
                        <label>Appointment Date</label>
                        <span>${date}</span>
                    </div>
                    <div class="detail-item">
                        <label>Appointment Time</label>
                        <span>${time}</span>
                    </div>
                    <div class="detail-item">
                        <label>Quantity</label>
                        <span>${apt.quantity}</span>
                    </div>
                    <div class="detail-item">
                        <label>Total Amount</label>
                        <span style="color: #4caf50; font-weight: 600;">₱${parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</span>
                    </div>
                    ${apt.notes ? `
                    <div class="detail-item full-width">
                        <label>Notes</label>
                        <span>${escapeHtml(apt.notes)}</span>
                    </div>
                    ` : ''}
                    <div class="detail-item">
                        <label>Created At</label>
                        <span style="font-size: 0.85rem;">${apt.created_at || 'N/A'}</span>
                    </div>
                    <div class="detail-item">
                        <label>Last Updated</label>
                        <span style="font-size: 0.85rem;">${apt.updated_at || 'N/A'}</span>
                    </div>
                </div>
            `;
            
            document.getElementById('detailsModal').classList.add('active');
        }
        
        function closeModal() {
            document.getElementById('detailsModal').classList.remove('active');
        }
        
        // Close modal on backdrop click
        document.getElementById('detailsModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
        
        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeModal();
        });
    </script>
    
    <style>
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    </style>
</body>
</html>
