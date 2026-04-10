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
