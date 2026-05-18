<?php
// api/notifications.php - USES SAME WORKING PATTERN AS user/notifications.php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Disable error output (but log them)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Use the same includes that work in user/notifications.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';  // Changed from SessionManager.php

// Start session if needed
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper function for JSON response
function sendJsonResponse($success, $data = null, $message = '', $statusCode = 200) {
    http_response_code($statusCode);
    $response = ['success' => $success];
    if ($data !== null) $response['data'] = $data;
    if ($message) $response['message'] = $message;
    echo json_encode($response);
    exit;
}

// Check if user is logged in using the same method as the working page
if (!SessionManager::isLoggedIn()) {
    sendJsonResponse(false, null, 'Unauthorized', 401);
}

$userId = SessionManager::getUserId();
$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = (new Database())->getConnection();
    
    // GET - Fetch notifications
    if ($method === 'GET') {
        $limit = isset($_GET['limit']) ? min(50, intval($_GET['limit'])) : 10;
        $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
        
        // Get unread count
        $countStmt = $db->prepare("SELECT COUNT(*) as unread_count FROM notifications WHERE user_id = :user_id AND is_read = false");
        $countStmt->execute([':user_id' => $userId]);
        $unreadCount = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['unread_count'];
        
        // Get notifications - SAME QUERY as working page
        $stmt = $db->prepare("
            SELECT notification_id, user_id, title, message, type, is_read, created_at 
            FROM notifications 
            WHERE user_id = :user_id 
            ORDER BY created_at DESC 
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format notifications
        $formatted = [];
        foreach ($notifications as $n) {
            $formatted[] = [
                'notification_id' => (int)$n['notification_id'],
                'title' => $n['title'],
                'message' => $n['message'],
                'type' => $n['type'],
                'is_read' => (bool)$n['is_read'],
                'created_at' => $n['created_at']
            ];
        }
        
        sendJsonResponse(true, [
            'notifications' => $formatted,
            'unread_count' => $unreadCount,
            'total' => count($formatted)
        ]);
    }
    
    // POST - Mark all as read
    elseif ($method === 'POST') {
        $action = $_GET['action'] ?? null;
        
        if ($action === 'mark_all_read') {
            $stmt = $db->prepare("UPDATE notifications SET is_read = true WHERE user_id = :user_id AND is_read = false");
            $stmt->execute([':user_id' => $userId]);
            sendJsonResponse(true, ['updated_count' => $stmt->rowCount()], 'All notifications marked as read');
        }
        
        sendJsonResponse(false, null, 'Invalid action', 400);
    }
    
    // PUT - Mark single notification as read
    elseif ($method === 'PUT') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data || !isset($data['notification_id'])) {
            sendJsonResponse(false, null, 'Notification ID required', 400);
        }
        
        $notificationId = (int)$data['notification_id'];
        
        // Verify ownership (same as working page)
        $stmt = $db->prepare("UPDATE notifications SET is_read = true WHERE notification_id = :id AND user_id = :user_id");
        $stmt->execute([':id' => $notificationId, ':user_id' => $userId]);
        
        if ($stmt->rowCount() > 0) {
            sendJsonResponse(true, null, 'Notification marked as read');
        } else {
            sendJsonResponse(false, null, 'Notification not found or already read', 404);
        }
    }
    
    else {
        sendJsonResponse(false, null, 'Method not allowed', 405);
    }
    
} catch (PDOException $e) {
    error_log("Notifications API Error: " . $e->getMessage());
    sendJsonResponse(false, null, 'Database error occurred', 500);
} catch (Exception $e) {
    error_log("Notifications API Error: " . $e->getMessage());
    sendJsonResponse(false, null, 'Server error occurred', 500);
}
?>