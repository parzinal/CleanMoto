<?php
require_once __DIR__ . '/../config/config.php';

echo "<pre>";
echo "====================================\n";
echo "    Creating User Accounts\n";
echo "====================================\n\n";

try {
    $db = Database::getInstance()->getConnection();
    
    $users = [
        ['Administrator', 'admin@xeroclaro.com', 'admin123', 'admin'],
        ['Staff Member', 'staff@xeroclaro.com', 'staff123', 'staff'],
        ['Regular User', 'user@xeroclaro.com', 'user123', 'user']
    ];
    
    $stmt = $db->prepare("INSERT INTO users (name, email, password, role, status) 
                          VALUES (?, ?, ?, ?, 'active') 
                          ON DUPLICATE KEY UPDATE password = VALUES(password), name = VALUES(name)");
    
    foreach ($users as $u) {
        $hash = password_hash($u[2], PASSWORD_DEFAULT);
        $stmt->execute([$u[0], $u[1], $hash, $u[3]]);
        echo "✓ Created: {$u[1]} / {$u[2]} ({$u[3]})\n";
    }
    
    echo "\n====================================\n";
    echo "✅ Done! You can now login with:\n\n";
    echo "  Admin: admin@xeroclaro.com / admin123\n";
    echo "  Staff: staff@xeroclaro.com / staff123\n";
    echo "  User:  user@xeroclaro.com / user123\n";
    echo "====================================\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "</pre>";
