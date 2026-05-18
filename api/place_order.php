<?php
// api/place_order.php — New FIFO-based order placement
// Called by user/products.php place_order action
require_once '../includes/config.php';
require_once '../includes/session.php';
require_once '../includes/FifoStock.php';

header('Content-Type: application/json');

if (!SessionManager::isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit();
}

$userId = SessionManager::getUserId();
$input  = json_decode(file_get_contents('php://input'), true);

// Also support form POST
if (empty($input)) {
    $input = $_POST;
}

$items         = $input['items']          ?? [];
$paymentMethod = $input['payment_method'] ?? 'salary_deduction';
$remarks       = trim($input['remarks']   ?? '');

if (empty($items)) {
    echo json_encode(['success' => false, 'message' => 'No items provided']); exit();
}

$db   = (new Database())->getConnection();
$fifo = new FifoStock($db);

try {
    $db->beginTransaction();

    $totalAmount = 0;
    $productData = [];

    foreach ($items as $item) {
        $productId  = (int)   $item['product_id'];
        $quantityKg = (float) $item['quantity_kg'];

        $pStmt = $db->prepare("SELECT product_id, fish_name, price_per_kg FROM fish_products WHERE product_id = :id");
        $pStmt->execute([':id' => $productId]);
        $product = $pStmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => "Product {$productId} not found"]); exit();
        }

        $available = $fifo->getAvailableStock($productId);
        if ($available < $quantityKg) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => "Insufficient stock for {$product['fish_name']}. Available: {$available} kg"]); exit();
        }

        $productData[$productId] = $product;
        $totalAmount += $quantityKg * (float)$product['price_per_kg'];
    }

    // Create order
    $oStmt = $db->prepare("
        INSERT INTO orders (user_id, order_status, payment_method, total_amount, remarks, order_date, created_at, updated_at)
        VALUES (:uid, 'pending', :pm, :total, :remarks, NOW(), NOW(), NOW())
        RETURNING order_id
    ");
    $oStmt->execute([':uid' => $userId, ':pm' => $paymentMethod, ':total' => $totalAmount, ':remarks' => $remarks ?: null]);
    $orderId = (int)$oStmt->fetchColumn();

    foreach ($items as $item) {
        $productId  = (int)   $item['product_id'];
        $quantityKg = (float) $item['quantity_kg'];
        $pricePerKg = (float) $productData[$productId]['price_per_kg'];
        $subtotal   = $quantityKg * $pricePerKg;

        $iStmt = $db->prepare("
            INSERT INTO order_items (order_id, product_id, quantity, price_per_kg, subtotal, created_at, updated_at)
            VALUES (:oid, :pid, :qty, :ppkg, :sub, NOW(), NOW())
            RETURNING order_item_id
        ");
        $iStmt->execute([':oid'=>$orderId,':pid'=>$productId,':qty'=>$quantityKg,':ppkg'=>$pricePerKg,':sub'=>$subtotal]);
        $orderItemId = (int)$iStmt->fetchColumn();

        $result = $fifo->deductStock($productId, $orderItemId, $quantityKg);
        if (!$result['success']) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => $result['message']]); exit();
        }
    }

    // Create salary deduction record
    if ($paymentMethod === 'salary_deduction') {
        $db->prepare("
            INSERT INTO salary_deductions (order_id, user_id, total_amount, amount_paid, remaining_balance, deduction_status, deduction_start_date, created_at, updated_at)
            VALUES (:oid, :uid, :amt, 0, :bal, 'pending', CURRENT_DATE, NOW(), NOW())
        ")->execute([':oid'=>$orderId,':uid'=>$userId,':amt'=>$totalAmount,':bal'=>$totalAmount]);
    }

    $db->commit();

    echo json_encode([
        'success'   => true,
        'message'   => 'Order placed successfully',
        'order_id'  => $orderId,
        'total'     => $totalAmount,
    ]);

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('place_order.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A database error occurred']);
}
