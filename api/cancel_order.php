<?php
// api/cancel_order.php — Updated for harvest-based FIFO schema
require_once '../includes/config.php';
require_once '../includes/session.php';
require_once '../includes/FifoStock.php';

header('Content-Type: application/json');

if (!SessionManager::isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$userId  = SessionManager::getUserId();
$orderId = (int)($_POST['order_id'] ?? $_POST['order_id'] ?? 0);

if (!$orderId) {
    echo json_encode(['success' => false, 'message' => 'Order ID is required']);
    exit();
}

try {
    $db   = (new Database())->getConnection();
    $fifo = new FifoStock($db);

    // Check order exists and user has permission
    $checkStmt = $db->prepare("SELECT order_id, user_id, order_status FROM orders WHERE order_id = :oid");
    $checkStmt->execute([':oid' => $orderId]);
    $order = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit();
    }

    $isOwner   = (int)$order['user_id'] === (int)$userId;
    $isManager = SessionManager::isManager() || SessionManager::isStaff();

    if (!$isOwner && !$isManager) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit();
    }

    if ($order['order_status'] !== 'pending') {
        echo json_encode(['success' => false, 'message' => 'Only pending orders can be cancelled']);
        exit();
    }

    $db->beginTransaction();

    // Reverse FIFO stock deductions
    $itemsStmt = $db->prepare("SELECT order_item_id FROM order_items WHERE order_id = :oid");
    $itemsStmt->execute([':oid' => $orderId]);
    foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $fifo->reverseDeduction((int)$item['order_item_id']);
    }

    // Cancel the order
    $db->prepare("
        UPDATE orders
        SET order_status = 'cancelled', cancelled_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
        WHERE order_id = :oid
    ")->execute([':oid' => $orderId]);

    // Cancel related salary deduction
    $db->prepare("
        UPDATE salary_deductions
        SET deduction_status = 'cancelled', updated_at = CURRENT_TIMESTAMP
        WHERE order_id = :oid AND deduction_status IN ('pending', 'active')
    ")->execute([':oid' => $orderId]);

    $db->commit();

    echo json_encode(['success' => true, 'message' => 'Order cancelled successfully. Stock has been restored.']);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('cancel_order.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}
