<?php
// api/claim_order.php
require_once '../includes/config.php';
require_once '../includes/session.php';

header('Content-Type: application/json');

SessionManager::requireLogin();

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Invalid request method';
    echo json_encode($response);
    exit();
}

$order_id = (int)($_POST['order_id'] ?? 0);
$user_id = SessionManager::getUserId();

if (!$order_id) {
    $response['message'] = 'Order ID is required';
    echo json_encode($response);
    exit();
}

try {
    $db = (new Database())->getConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $functions = new SystemFunctions();
    
    $db->beginTransaction();
    
    // Verify order belongs to user and is in confirmed status
    $checkSql = "SELECT order_id, order_status, payment_method, total_amount 
                 FROM orders 
                 WHERE order_id = :order_id AND user_id = :user_id 
                 AND order_status = 'confirmed'
                 FOR UPDATE";
    $checkStmt = $db->prepare($checkSql);
    $checkStmt->execute([':order_id' => $order_id, ':user_id' => $user_id]);
    $order = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        throw new Exception("Order not found or not eligible for claiming. Only confirmed orders can be claimed.");
    }
    
    // Update order status to 'claimed'
    $updateSql = "UPDATE orders 
                  SET order_status = 'claimed', 
                      claimed_at = NOW(), 
                      updated_at = NOW() 
                  WHERE order_id = :order_id";
    $updateStmt = $db->prepare($updateSql);
    $updateStmt->execute([':order_id' => $order_id]);
    
    // If payment method is salary_deduction, create or update salary deduction record
    if ($order['payment_method'] === 'salary_deduction') {
        // Check if deduction already exists
        $checkDeductionSql = "SELECT deduction_id FROM salary_deductions WHERE order_id = :order_id AND user_id = :user_id";
        $checkDeductionStmt = $db->prepare($checkDeductionSql);
        $checkDeductionStmt->execute([':order_id' => $order_id, ':user_id' => $user_id]);
        $existingDeduction = $checkDeductionStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existingDeduction) {
            $deductionSql = "INSERT INTO salary_deductions (
                                order_id, user_id, total_amount, amount_paid, 
                                remaining_balance, deduction_status, 
                                deduction_start_date, deduction_end_date, 
                                remarks, created_at, updated_at
                            ) VALUES (
                                :order_id, :user_id, :total_amount, 0, 
                                :total_amount, 'pending', 
                                CURRENT_DATE, CURRENT_DATE + INTERVAL '3 months',
                                'Auto-generated from claimed order #" . str_pad($order_id, 6, '0', STR_PAD_LEFT) . "', NOW(), NOW()
                            )";
            $deductionStmt = $db->prepare($deductionSql);
            $deductionStmt->execute([
                ':order_id' => $order_id,
                ':user_id' => $user_id,
                ':total_amount' => $order['total_amount']
            ]);
        }
    }

    $functions->createNotification(
        $user_id,
        'order',
        'Order Claimed',
        'Your order #' . str_pad($order_id, 6, '0', STR_PAD_LEFT) . ' has been marked as claimed.',
        $order_id
    );
    
    $db->commit();
    
    $response['success'] = true;
    $response['message'] = 'Order claimed successfully!';
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    $response['message'] = $e->getMessage();
    error_log("Claim order error: " . $e->getMessage());
}

echo json_encode($response);
?>