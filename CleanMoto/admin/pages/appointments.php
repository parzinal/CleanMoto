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

