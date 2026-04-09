<?php
require_once __DIR__ . '/../../config/config.php';

// Check authentication
if (!isLoggedIn()) {
    redirect('login.php');
}

if (!isUser()) {
    // Redirect to appropriate dashboard based on role
    if (isAdmin()) {
        redirect('admin/pages/notifications.php');
    } elseif (isStaff()) {
        redirect('staff/pages/notifications.php');
    }
}

// Include the shared notifications page
require_once __DIR__ . '/../../includes/shared/notifications.php';
