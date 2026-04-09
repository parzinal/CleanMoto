<?php
/**
 * Password Hash Helper
 * Use this to update plain text passwords to hashed passwords
 */

require_once __DIR__ . '/../config/config.php';

echo "<pre>";
echo "====================================\n";
echo "    Password Hash Helper\n";
echo "====================================\n\n";

try {
    $db = Database::getInstance()->getConnection();
    
    // Define users with their plain text passwords
    $users = [
        ['email' => 'admin@gmail.com', 'password' => 'admin123'],
        ['email' => 'staff@gmail.com', 'password' => 'staff123'],
        ['email' => 'jared@gmail.com', 'password' => 'user123']  // Update with actual password
    ];
    
    $stmt = $db->prepare("UPDATE users SET password = ? WHERE email = ?");
    
    foreach ($users as $user) {
        $hashedPassword = password_hash($user['password'], PASSWORD_DEFAULT);
        $stmt->execute([$hashedPassword, $user['email']]);
        
        echo "✓ Updated password for: {$user['email']}\n";
        echo "  New hashed password: {$hashedPassword}\n\n";
    }
    
    echo "\n====================================\n";
    echo "Passwords updated successfully!\n";
    echo "You can now login with:\n";
    echo "- admin@gmail.com / admin123\n";
    echo "- staff@gmail.com / staff123\n";
    echo "- jared@gmail.com / user123\n";
    echo "====================================\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "</pre>";
