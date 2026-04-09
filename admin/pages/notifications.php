<?php
require_once __DIR__ . '/../../config/config.php';

// Check authentication
if (!isLoggedIn() || getUserRole() !== 'admin') {
    redirect('login.php');
}

// Include the shared notifications page
require_once __DIR__ . '/../../includes/shared/notifications.php';
