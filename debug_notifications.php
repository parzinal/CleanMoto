<?php
require_once __DIR__ . '/config/config.php';

// Only staff or admin may view this debug page
if (!isLoggedIn() || (!isStaff() && !isAdmin())) {
    http_response_code(403);
    echo "Forbidden: login as staff or admin to view this page.";
    exit;
}

$db = Database::getInstance()->getConnection();

try {
    $stmt = $db->query("SELECT * FROM notifications ORDER BY created_at DESC LIMIT 200");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    echo "Error reading notifications: " . htmlspecialchars($e->getMessage());
    exit;
}

?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Debug Notifications</title>
<link rel="stylesheet" href="/assets/css/style.css">
<style>table{width:100%;border-collapse:collapse}td,th{padding:8px;border:1px solid #ddd;text-align:left;font-family:Inter,Arial}</style>
</head>
<body>
<h1>Notifications (debug)</h1>
<p>Logged in as <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'unknown'); ?> (<?php echo htmlspecialchars($_SESSION['user_role'] ?? 'unknown'); ?>)</p>
<p><a href="/debug_create_notification.php">Insert a test notification</a></p>
<table>
<thead><tr><th>id</th><th>user_id</th><th>role</th><th>type</th><th>title</th><th>body</th><th>url</th><th>is_read</th><th>created_at</th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
<td><?php echo intval($r['id']); ?></td>
<td><?php echo htmlspecialchars($r['user_id']); ?></td>
<td><?php echo htmlspecialchars($r['role']); ?></td>
<td><?php echo htmlspecialchars($r['type']); ?></td>
<td><?php echo htmlspecialchars($r['title']); ?></td>
<td><?php echo htmlspecialchars($r['body']); ?></td>
<td><?php echo htmlspecialchars($r['url']); ?></td>
<td><?php echo intval($r['is_read']); ?></td>
<td><?php echo htmlspecialchars($r['created_at']); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</body>
</html>
