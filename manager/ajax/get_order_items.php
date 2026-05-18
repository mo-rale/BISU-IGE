<?php
/**
 * manager/ajax/get_order_items.php
 * Returns order items as JSON for the order items modal.
 */

require_once '../../includes/config.php';
require_once '../../includes/session.php';

header('Content-Type: application/json');

SessionManager::requireManagerOrStaff();

$orderId = (int)($_GET['order_id'] ?? 0);
if (!$orderId) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID.']);
    exit;
}

$db = (new Database())->getConnection();

$stmt = $db->prepare("
    SELECT
        oi.order_item_id,
        oi.quantity,
        oi.price_per_kg,
        oi.subtotal,
        fp.fish_name,
        COALESCE(SUM(hc.quantity_used), 0) AS confirmed_consumed
    FROM order_items oi
    JOIN fish_products fp ON fp.product_id = oi.product_id
    LEFT JOIN harvest_consumption hc ON hc.order_item_id = oi.order_item_id
    WHERE oi.order_id = :oid
    GROUP BY oi.order_item_id, oi.quantity, oi.price_per_kg, oi.subtotal, fp.fish_name
    ORDER BY fp.fish_name
");
$stmt->execute([':oid' => $orderId]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = array_sum(array_column($items, 'subtotal'));

echo json_encode([
    'success' => true,
    'items'   => $items,
    'total'   => number_format($total, 2),
]);
