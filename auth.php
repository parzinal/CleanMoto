<?php
/**
 * Authentication Handler
 * Handles logout and other authentication operations
 */

require_once __DIR__ . '/config/config.php';

// Get action from URL
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'logout':
        if (isLoggedIn()) {
            $userId = $_SESSION['user_id'];
            
            // Log activity
            try {
                $db = Database::getInstance()->getConnection();
                $logStmt = $db->prepare("INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
                $logStmt->execute([
                    $userId,
                    'logout',
                    'User logged out',
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);
            } catch (PDOException $e) {
                error_log($e->getMessage());
            }
            
            // Clear session
            session_destroy();
            
            // Clear remember me cookie
            if (isset($_COOKIE['remember_token'])) {
                setcookie('remember_token', '', time() - 3600, '/');
            }
        }
        
        redirect('login.php');
        break;
        
    default:
        redirect('');
        break;
}
