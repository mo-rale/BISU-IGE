<?php
// api/get_order_info.php - Get order info for returns
require_once '../includes/config.php';
require_once '../includes/session.php';

SessionManager::requireManagerOrStaff();

header('Content-Type: application/json');

if (!isset($_GET['order_id'])) {
    echo json_encode(['success' => false, 'message' => 'Order ID required']);
    exit();
}

$order_id = (int)$_GET['order_id'];

try {
    $db = (new Database())->getConnection();
    
    // Get order with buyer info
    $orderSql = "SELECT o.*, u.full_name as buyer_name, u.email, u.department 
                 FROM orders o JOIN users u ON o.user_id = u.user_id 
                 WHERE o.order_id = :id";
    $stmt = $db->prepare($orderSql);
    $stmt->execute([':id' => $order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit();
    }
    
    // Get products with return info
    $productsSql = "SELECT oi.product_id, oi.quantity, oi.price_per_kg, fp.fish_name,
                           COALESCE((SELECT SUM(return_quantity) FROM product_returns WHERE order_id = oi.order_id AND product_id = oi.product_id AND return_status IN ('pending','approved','refunded')), 0) as returned_qty
                    FROM order_items oi 
                    JOIN fish_products fp ON oi.product_id = fp.product_id 
                    WHERE oi.order_id = :id";
    $pStmt = $db->prepare($productsSql);
    $pStmt->execute([':id' => $order_id]);
    $products = $pStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'order' => [
            'order_id' => $order['order_id'],
            'user_id' => $order['user_id'],
            'buyer_name' => $order['buyer_name'],
            'order_date' => date('M d, Y', strtotime($order['order_date'] ?? $order['created_at'])),
            'payment_method' => ucfirst($order['payment_method'])
        ],
        'products' => $products
    ]);
    
} catch (Exception $e) {
    error_log("API error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error']);
}