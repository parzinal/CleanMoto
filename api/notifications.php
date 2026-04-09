<?php
require_once __DIR__ . '/../config/config.php';
header('Content-Type: application/json');

$db = Database::getInstance()->getConnection();

// Session is already started in config.php
$userId = $_SESSION['user_id'] ?? null;
$userRole = $_SESSION['user_role'] ?? 'user';

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
    // list notifications for current user/role
    try {
        $limit = intval($_GET['limit'] ?? 20);
        // Allow admins to fetch all notifications; others get targeted/global based on role
        if ($userRole === 'admin') {
            $stmt = $db->prepare("SELECT * FROM notifications ORDER BY created_at DESC LIMIT :lim");
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // unread count for admin is total unread
            $cstmt = $db->prepare("SELECT COUNT(*) as c FROM notifications WHERE is_read = 0");
            $cstmt->execute();
            $count = (int) $cstmt->fetchColumn();
        } else {
            // same selection logic as header: include global notifications only when role matches
            $stmt = $db->prepare("SELECT * FROM notifications WHERE ((user_id IS NULL AND (role IS NULL OR role = :role)) OR user_id = :uid OR role = :role2) ORDER BY created_at DESC LIMIT :lim");
            $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':role', $userRole, PDO::PARAM_STR);
            $stmt->bindValue(':role2', $userRole, PDO::PARAM_STR);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // unread count
            $cstmt = $db->prepare("SELECT COUNT(*) as c FROM notifications WHERE is_read = 0 AND ((user_id IS NULL AND (role IS NULL OR role = :role)) OR user_id = :uid OR role = :role2)");
            $cstmt->execute([':uid' => $userId, ':role' => $userRole, ':role2' => $userRole]);
            $count = (int) $cstmt->fetchColumn();
        }

        echo json_encode(['success' => true, 'notifications' => $rows, 'unread' => $count]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to fetch notifications']);
    }
    exit;
}

if ($method === 'POST') {
    $action = $_POST['action'] ?? ($_GET['action'] ?? '');

    if ($action === 'create') {
        // create notification (admin/system use)
        $title = $_POST['title'] ?? '';
        $body = $_POST['body'] ?? '';
        $url = $_POST['url'] ?? null;
        $role = $_POST['role'] ?? null; // target role
        $targetUser = isset($_POST['user_id']) ? intval($_POST['user_id']) : null;

        if (!$title) { echo json_encode(['success'=>false,'message'=>'Title required']); exit; }
        try {
            $stmt = $db->prepare("INSERT INTO notifications (user_id, role, title, body, url) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$targetUser, $role, $title, $body, $url]);
            echo json_encode(['success'=>true, 'id' => $db->lastInsertId()]);
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'message'=>'Insert failed']);
        }
        exit;
    }

    if ($action === 'mark_read') {
        $nid = intval($_POST['id'] ?? 0);
        if (!$nid) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit; }
        try {
            // only allow marking notifications belonging to user or global/role
            $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND (user_id IS NULL OR user_id = ? OR role = ?)");
            $stmt->execute([$nid, $userId, $userRole]);
            echo json_encode(['success'=>true]);
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'message'=>'Update failed']);
        }
        exit;
    }

    if ($action === 'mark_all_read') {
        try {
            $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE (user_id IS NULL OR user_id = ? OR role = ?)");
            $stmt->execute([$userId, $userRole]);
            echo json_encode(['success'=>true]);
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'message'=>'Update failed']);
        }
        exit;
    }
}

http_response_code(400);
echo json_encode(['success'=>false,'message'=>'Unsupported method/action']);

