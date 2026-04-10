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
        
