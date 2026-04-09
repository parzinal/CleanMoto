<?php
/**
 * Debug Password Reset - DELETE AFTER DEBUGGING
 */
require_once __DIR__ . '/config/config.php';

echo "<h2>Password Reset Debug</h2>";

$db = Database::getInstance()->getConnection();

// Check table structure
echo "<h3>Table Structure:</h3>";
$stmt = $db->query("DESCRIBE password_resets");
echo "<table border='1' cellpadding='5'><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['Field']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Default'] ?? 'NULL') . "</td>";
    echo "</tr>";
}
echo "</table>";

// Check current records
echo "<h3>Current Password Reset Records:</h3>";
$stmt = $db->query("SELECT pr.*, u.email FROM password_resets pr JOIN users u ON pr.user_id = u.id ORDER BY pr.created_at DESC LIMIT 5");
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($records)) {
    echo "<p>No password reset records found.</p>";
} else {
    echo "<table border='1' cellpadding='5'><tr><th>ID</th><th>User Email</th><th>Token (first 20)</th><th>Code</th><th>Code Length</th><th>Code Type</th><th>Expires</th><th>Used</th><th>Created</th></tr>";
    foreach ($records as $row) {
        $expired = strtotime($row['expires_at']) < time() ? ' (EXPIRED)' : '';
        $code = $row['verification_code'] ?? 'NULL';
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . htmlspecialchars($row['email']) . "</td>";
        echo "<td>" . substr($row['token'], 0, 20) . "...</td>";
        echo "<td><strong style='font-size: 18px; color: blue;'>" . htmlspecialchars($code) . "</strong></td>";
        echo "<td>" . ($code !== 'NULL' ? strlen($code) : 'N/A') . "</td>";
        echo "<td>" . ($code !== 'NULL' ? gettype($code) : 'N/A') . "</td>";
        echo "<td>" . $row['expires_at'] . $expired . "</td>";
        echo "<td>" . ($row['used'] ? 'YES' : 'NO') . "</td>";
        echo "<td>" . $row['created_at'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Show the actual verification code for the latest record
    if (!empty($records[0])) {
        $latest = $records[0];
        echo "<div style='background: #ffffcc; padding: 15px; margin-top: 20px; border: 2px solid #ff9900; border-radius: 5px;'>";
        echo "<h4 style='margin-top: 0;'>📋 Latest Verification Code (Copy This):</h4>";
        echo "<p style='font-size: 32px; font-weight: bold; color: #0066cc; letter-spacing: 5px;'>" . htmlspecialchars($latest['verification_code'] ?? 'NULL') . "</p>";
        echo "<p><strong>Email:</strong> " . htmlspecialchars($latest['email']) . "</p>";
        echo "<p><strong>Status:</strong> " . ($latest['used'] ? '❌ Already Used' : '✅ Available') . "</p>";
        echo "<p><strong>Expires:</strong> " . $latest['expires_at'] . (strtotime($latest['expires_at']) < time() ? ' ❌ EXPIRED' : ' ✅ Valid') . "</p>";
        echo "</div>";
    }
}

// Check session data
echo "<h3>Session Data:</h3>";
echo "<pre>";
echo "reset_email: " . ($_SESSION['reset_email'] ?? 'NOT SET') . "\n";
echo "reset_token: " . (isset($_SESSION['reset_token']) ? substr($_SESSION['reset_token'], 0, 20) . '...' : 'NOT SET') . "\n";
echo "reset_verified: " . ($_SESSION['reset_verified'] ?? 'NOT SET') . "\n";
echo "</pre>";

// Check current server time vs database time
echo "<h3>Time Check:</h3>";
$stmt = $db->query("SELECT NOW() as db_time");
$dbTime = $stmt->fetch(PDO::FETCH_ASSOC);
echo "<p>PHP Time: " . date('Y-m-d H:i:s') . "</p>";
echo "<p>Database Time: " . $dbTime['db_time'] . "</p>";

echo "<hr><p style='color: orange;'><strong>DELETE THIS FILE AFTER DEBUGGING!</strong></p>";
