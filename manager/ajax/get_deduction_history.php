<?php
/**
 * manager/ajax/get_deduction_history.php
 * Returns payment history for a salary deduction as JSON.
 */

require_once '../../includes/config.php';
require_once '../../includes/session.php';

header('Content-Type: application/json');
SessionManager::requireManagerOrStaff();

$deductionId = (int)($_GET['deduction_id'] ?? 0);
if (!$deductionId) {
    echo json_encode(['success' => false, 'message' => 'Invalid deduction ID.']);
    exit;
}

$db = (new Database())->getConnection();

$stmt = $db->prepare("
    SELECT
        history_id,
        amount_deducted,
        deduction_date,
        payroll_period,
        remarks,
        created_at
    FROM deduction_history
    WHERE deduction_id = :did
    ORDER BY deduction_date DESC, history_id DESC
");
$stmt->execute([':did' => $deductionId]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPaid = array_sum(array_column($history, 'amount_deducted'));

echo json_encode([
    'success'    => true,
    'history'    => $history,
    'total_paid' => number_format($totalPaid, 2),
]);
