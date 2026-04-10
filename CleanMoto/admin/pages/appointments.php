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
