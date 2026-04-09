<?php
require_once __DIR__ . '/config/config.php';

// Only staff or admin may run test insert (protect from public use)
if (!isLoggedIn() || (!isStaff() && !isAdmin())) {
    http_response_code(403);
    echo "Forbidden: login as staff or admin to use this page.";
    exit;
}

$db = Database::getInstance()->getConnection();

try {
    $db->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        role VARCHAR(50) NULL,
        type VARCHAR(100) NULL,
        title VARCHAR(255) NOT NULL,
        body TEXT NULL,
        url VARCHAR(255) NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id),
        INDEX (role),
        INDEX (is_read)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $title = 'Debug: Test appointment notification';
    $body = 'This is a test notification inserted by debug_create_notification.php';
    $url = rtrim(APP_URL, '/') . '/staff/pages/appointments.php';

    $stmt = $db->prepare("INSERT INTO notifications (user_id, role, type, title, body, url) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([null, 'staff', 'debug.test', $title, $body, $url]);

    echo "Inserted test notification. <a href=\"/debug_notifications.php\">View notifications</a>";
} catch (Exception $e) {
    echo "Failed to insert test notification: " . htmlspecialchars($e->getMessage());
}
