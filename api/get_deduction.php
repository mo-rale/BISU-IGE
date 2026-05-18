<?php
// api/get_deduction.php
require_once '../includes/config.php';
require_once '../includes/session.php';

header('Content-Type: application/json');

if (!SessionManager::isLoggedIn()) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$order_id = $_GET['order_id'] ?? 0;
if (!$order_id) {
    echo json_encode(['exists' => false]);
    exit();
}

try {
    $db = (new Database())->getConnection();
    
    $sql = "SELECT * FROM salary_deductions WHERE order_id = :order_id";
    $stmt = $db->prepare($sql);
    $stmt->execute([':order_id' => $order_id]);
    $deduction = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($deduction) {
        echo json_encode([
            'exists' => true,
            'deduction' => $deduction
        ]);
    } else {
        echo json_encode(['exists' => false]);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>