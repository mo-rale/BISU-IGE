<?php
// user/request_return.php - Orders eligible if claimed 1-5 days ago (based on claim date + 1-5 days window)
require_once '../includes/config.php';
require_once '../includes/session.php';

SessionManager::requireLogin();

$db = (new Database())->getConnection();
$userId = SessionManager::getUserId();
$message = '';
$messageType = '';

// Enable PDO exceptions for debugging
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Get filter parameters for return history
$historyStatusFilter = $_GET['history_status'] ?? 'all';
$historyDateFrom = $_GET['history_date_from'] ?? '';
$historyDateTo = $_GET['history_date_to'] ?? '';
$historySort = $_GET['history_sort'] ?? 'newest';

$allowedStatuses = ['all', 'pending', 'approved', 'rejected', 'completed'];
$allowedSorts = ['newest', 'oldest'];

if (!in_array($historyStatusFilter, $allowedStatuses)) $historyStatusFilter = 'all';
if (!in_array($historySort, $allowedSorts)) $historySort = 'newest';

// ========== DETECT CORRECT DATE COLUMN NAME ==========
try {
    $checkColumnsSql = "SELECT column_name 
                        FROM information_schema.columns 
                        WHERE table_name = 'orders' 
                        AND column_name IN ('order_datetime', 'order_date', 'created_at')";
    $checkStmt = $db->prepare($checkColumnsSql);
    $checkStmt->execute();
    $existingColumns = $checkStmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (in_array('order_datetime', $existingColumns)) {
        $dateColumn = 'order_datetime';
    } elseif (in_array('order_date', $existingColumns)) {
        $dateColumn = 'order_date';
    } else {
        $dateColumn = 'created_at';
    }
} catch (PDOException $e) {
    error_log("Failed to detect column schema: " . $e->getMessage());
    $dateColumn = 'order_datetime';
}

// ========== HELPER FUNCTION TO GET USER PAID AMOUNT ==========
function getUserPaidAmountForOrder($db, $order_id, $user_id) {
    try {
        // First, get the deduction_id for this order
        $deductionSql = "SELECT deduction_id, total_amount, amount_paid, remaining_balance 
                         FROM salary_deductions 
                         WHERE order_id = :order_id AND user_id = :user_id";
        $deductionStmt = $db->prepare($deductionSql);
        $deductionStmt->execute([':order_id' => $order_id, ':user_id' => $user_id]);
        $deduction = $deductionStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($deduction) {
            // Return the amount already paid by the user
            return [
                'paid_amount' => (float)$deduction['amount_paid'],
                'total_amount' => (float)$deduction['total_amount'],
                'remaining_balance' => (float)$deduction['remaining_balance'],
                'deduction_id' => $deduction['deduction_id']
            ];
        }
        
        // If no salary deduction record, check if order was paid via other methods
        $orderSql = "SELECT total_amount, payment_method 
                     FROM orders 
                     WHERE order_id = :order_id AND user_id = :user_id";
        $orderStmt = $db->prepare($orderSql);
        $orderStmt->execute([':order_id' => $order_id, ':user_id' => $user_id]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($order && $order['payment_method'] != 'salary_deduction') {
            // For cash, GCash, etc., assume full amount paid
            return [
                'paid_amount' => (float)$order['total_amount'],
                'total_amount' => (float)$order['total_amount'],
                'remaining_balance' => 0,
                'deduction_id' => null
            ];
        }
        
        // Default fallback
        return [
            'paid_amount' => 0,
            'total_amount' => 0,
            'remaining_balance' => 0,
            'deduction_id' => null
        ];
    } catch (Exception $e) {
        error_log("Error getting paid amount for order {$order_id}: " . $e->getMessage());
        return [
            'paid_amount' => 0,
            'total_amount' => 0,
            'remaining_balance' => 0,
            'deduction_id' => null
        ];
    }
}

// ========== GET ELIGIBLE ORDERS (CLAIM DATE BETWEEN 1-5 DAYS AGO) ==========
$orders = [];

try {
    // FIXED: Orders are eligible if claimed_date is between (CURRENT_DATE - 5) AND (CURRENT_DATE - 1)
    // This means: if today is May 10, eligible claim dates are May 5-9 (1-5 days ago)
    // Alternative interpretation: claimed date must be at least 1 day ago and at most 5 days ago
    $ordersSql = "SELECT o.order_id, 
                         o.{$dateColumn} as order_date, 
                         o.total_amount, 
                         o.payment_method, 
                         o.order_status, 
                         o.claimed_at,
                         DATE_PART('day', CURRENT_DATE - DATE(o.claimed_at)) as days_since_claimed,
                         EXTRACT(EPOCH FROM (NOW() - o.claimed_at)) / 3600 as hours_since_claimed
                  FROM orders o
                  WHERE o.user_id = :user_id 
                  AND o.claimed_at IS NOT NULL
                  AND DATE(o.claimed_at) <= (CURRENT_DATE - INTERVAL '1 day')
                  AND DATE(o.claimed_at) >= (CURRENT_DATE - INTERVAL '5 days')
                  AND NOT EXISTS (
                      SELECT 1 FROM return_requests rr 
                      WHERE rr.order_id = o.order_id 
                      AND rr.user_id = o.user_id 
                      AND rr.return_status IN ('pending', 'approved')
                  )
                  ORDER BY o.claimed_at DESC
                  LIMIT 20";
    
    $ordersStmt = $db->prepare($ordersSql);
    $ordersStmt->execute([':user_id' => $userId]);
    $ordersData = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Enrich orders with paid amount information
    foreach ($ordersData as $order) {
        $paidInfo = getUserPaidAmountForOrder($db, $order['order_id'], $userId);
        
        $orders[] = array_merge($order, [
            'user_paid_amount' => $paidInfo['paid_amount'],
            'refundable_amount' => min($paidInfo['paid_amount'], $order['total_amount']),
            'deduction_id' => $paidInfo['deduction_id']
        ]);
        
        error_log("Order #{$order['order_id']} - Total: {$order['total_amount']} - User Paid: {$paidInfo['paid_amount']} - Refundable: " . min($paidInfo['paid_amount'], $order['total_amount']));
    }
    
    // Get order items for each order
    foreach ($orders as &$order) {
        $itemsSql = "SELECT oi.product_id, oi.quantity, oi.price_per_kg, 
                            COALESCE(fp.fish_name, 'Fish Product') as fish_name
                     FROM order_items oi
                     LEFT JOIN fish_products fp ON oi.product_id = fp.product_id
                     WHERE oi.order_id = :order_id";
        $itemsStmt = $db->prepare($itemsSql);
        $itemsStmt->execute([':order_id' => $order['order_id']]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($items)) {
            $order['items'] = $items;
        } else {
            $order['items'] = [[
                'product_id' => $order['order_id'],
                'fish_name' => 'Fish Product',
                'quantity' => 1,
                'price_per_unit' => $order['total_amount']
            ]];
        }
    }
    unset($order);
    
    // Calculate eligible date range for display (claims from 1-5 days ago)
    $eligibleStartDate = date('F j, Y', strtotime('-5 days'));
    $eligibleEndDate = date('F j, Y', strtotime('-1 days'));
    
} catch (PDOException $e) {
    error_log("Orders error: " . $e->getMessage());
    $orders = [];
    $message = "Unable to fetch your orders. Please contact support.";
    $messageType = 'error';
}

// ========== GET EXISTING RETURN REQUESTS ==========
$existingReturns = [];
try {
    $returnsSql = "SELECT rr.*, 
                          o.{$dateColumn} as order_date, 
                          o.total_amount as order_total, 
                          o.claimed_at
                   FROM return_requests rr
                   LEFT JOIN orders o ON rr.order_id = o.order_id
                   WHERE rr.user_id = :user_id";
    
    $params = [':user_id' => $userId];
    
    if ($historyStatusFilter !== 'all') {
        $returnsSql .= " AND rr.return_status = :status";
        $params[':status'] = $historyStatusFilter;
    }
    
    if ($historyDateFrom && preg_match('/^\d{4}-\d{2}-\d{2}$/', $historyDateFrom)) {
        $returnsSql .= " AND DATE(rr.request_date) >= :date_from";
        $params[':date_from'] = $historyDateFrom;
    }
    
    if ($historyDateTo && preg_match('/^\d{4}-\d{2}-\d{2}$/', $historyDateTo)) {
        $returnsSql .= " AND DATE(rr.request_date) <= :date_to";
        $params[':date_to'] = $historyDateTo;
    }
    
    if ($historySort === 'oldest') {
        $returnsSql .= " ORDER BY rr.request_date ASC";
    } else {
        $returnsSql .= " ORDER BY rr.request_date DESC";
    }
    
    $returnsSql .= " LIMIT 50";
    
    $returnsStmt = $db->prepare($returnsSql);
    $returnsStmt->execute($params);
    $existingReturns = $returnsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $statsSql = "SELECT 
                    COUNT(*) as total,
                    COUNT(CASE WHEN return_status = 'pending' THEN 1 END) as pending,
                    COUNT(CASE WHEN return_status = 'approved' THEN 1 END) as approved,
                    COUNT(CASE WHEN return_status = 'rejected' THEN 1 END) as rejected,
                    COUNT(CASE WHEN return_status = 'completed' THEN 1 END) as completed,
                    COALESCE(SUM(return_amount), 0) as total_refund
                 FROM return_requests 
                 WHERE user_id = :user_id";
    $statsStmt = $db->prepare($statsSql);
    $statsStmt->execute([':user_id' => $userId]);
    $historyStats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Returns error: " . $e->getMessage());
    $existingReturns = [];
    $historyStats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'completed' => 0, 'total_refund' => 0];
}

// ========== HANDLE FORM SUBMISSION ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_return') {
    try {
        $order_id = (int)$_POST['order_id'];
        $return_reason = $_POST['return_reason'];
        $return_description = trim($_POST['return_description'] ?? '');
        $return_percentage = (float)$_POST['return_percentage'];
        
        // Verify order belongs to user and is eligible (claimed 1-5 days ago)
        $verifySql = "SELECT order_id, total_amount, claimed_at, order_status, payment_method,
                             DATE_PART('day', CURRENT_DATE - DATE(claimed_at)) as days_since_claimed
                      FROM orders 
                      WHERE order_id = :oid AND user_id = :uid AND claimed_at IS NOT NULL";
        
        $verifyStmt = $db->prepare($verifySql);
        $verifyStmt->execute([':oid' => $order_id, ':uid' => $userId]);
        $orderData = $verifyStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$orderData) {
            throw new Exception("Invalid order or order not yet claimed");
        }
        
        // Check if order was claimed between 1 and 5 days ago
        $daysOld = (int)$orderData['days_since_claimed'];
        
        if ($daysOld < 1 || $daysOld > 5) {
            throw new Exception(sprintf(
                "Returns are only allowed for orders claimed 1 to 5 days ago. " .
                "This order was claimed %d day(s) ago on %s.",
                $daysOld,
                date('Y-m-d', strtotime($orderData['claimed_at']))
            ));
        }
        
        // Get user's paid amount for this order
        $paidInfo = getUserPaidAmountForOrder($db, $order_id, $userId);
        $userPaidAmount = $paidInfo['paid_amount'];
        $orderTotal = (float)$orderData['total_amount'];
        
        // Calculate refund amount based on user's actual paid amount
        $refundableBase = min($userPaidAmount, $orderTotal);
        $refundAmount = $refundableBase * ($return_percentage / 100);
        
        if ($refundAmount <= 0) {
            throw new Exception("Refund amount must be greater than 0. You have paid ₱" . number_format($userPaidAmount, 2) . " for this order.");
        }
        
        if ($return_percentage <= 0 || $return_percentage > 100) {
            throw new Exception("Return percentage must be between 1% and 100%");
        }
        
        // Check existing returns
        $checkSql = "SELECT COALESCE(SUM(return_percentage), 0) as returned_percentage 
                     FROM return_requests 
                     WHERE order_id = :oid AND user_id = :uid 
                     AND return_status != 'rejected'";
        $checkStmt = $db->prepare($checkSql);
        $checkStmt->execute([':oid' => $order_id, ':uid' => $userId]);
        $alreadyReturnedPercentage = (float)$checkStmt->fetchColumn();
        
        $availablePercentage = 100 - $alreadyReturnedPercentage;
        
        if ($return_percentage > $availablePercentage + 0.01) {
            throw new Exception("Return percentage cannot exceed " . round($availablePercentage) . "% of the order (already returned " . round($alreadyReturnedPercentage) . "%)");
        }
        
        // Insert return request
        $insertSql = "INSERT INTO return_requests (
                        order_id, user_id, deduction_id, return_reason, return_description,
                        return_percentage, return_amount, return_status, request_date, created_at, updated_at
                      ) VALUES (
                        :order_id, :user_id, :deduction_id, :return_reason, :return_description,
                        :return_percentage, :return_amount, 'pending', NOW(), NOW(), NOW()
                      )";
        
        $insertStmt = $db->prepare($insertSql);
        $insertStmt->execute([
            ':order_id' => $order_id,
            ':user_id' => $userId,
            ':deduction_id' => $paidInfo['deduction_id'],
            ':return_reason' => $return_reason,
            ':return_description' => $return_description,
            ':return_percentage' => $return_percentage,
            ':return_amount' => $refundAmount
        ]);
        
        $message = "Return request submitted successfully! Refund amount: ₱" . number_format($refundAmount, 2) . " - Waiting for manager approval.";
        $messageType = 'success';
        
        $redirectParams = [];
        if ($historyStatusFilter !== 'all') $redirectParams['history_status'] = $historyStatusFilter;
        if ($historyDateFrom) $redirectParams['history_date_from'] = $historyDateFrom;
        if ($historyDateTo) $redirectParams['history_date_to'] = $historyDateTo;
        if ($historySort !== 'newest') $redirectParams['history_sort'] = $historySort;
        
        $queryString = http_build_query($redirectParams);
        $redirectUrl = "request_return.php?message=" . urlencode($message) . "&type=success";
        if ($queryString) $redirectUrl .= "&" . $queryString;
        
        header("Location: " . $redirectUrl);
        exit();
        
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $messageType = 'error';
    }
}

if (isset($_GET['message'])) {
    $message = $_GET['message'];
    $messageType = $_GET['type'] ?? 'info';
}

// Calculate eligible date range for display (orders claimed 1-5 days ago)
$eligibleStartDate = date('F j, Y', strtotime('-5 days'));
$eligibleEndDate = date('F j, Y', strtotime('-1 days'));

// Status colors
$statusColors = [
    'pending' => 'bg-yellow-100 text-yellow-800',
    'approved' => 'bg-green-100 text-green-800',
    'rejected' => 'bg-red-100 text-red-800',
    'completed' => 'bg-blue-100 text-blue-800'
];

function formatClaimedDate($claimed_at) {
    if (!$claimed_at) return 'Not claimed';
    return date('M d, Y g:i A', strtotime($claimed_at));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Return - BISU IGE Aquaculture</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { font-family: 'Inter', sans-serif; }
        .brand-heading { font-family: 'Playfair Display', serif; }
        .card { background: white; border-radius: 1rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; }
        .form-input { width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #e2e8f0; border-radius: 0.5rem; transition: all 0.2s; }
        .form-input:focus { outline: none; border-color: #0ea5e9; box-shadow: 0 0 0 3px rgba(14,165,233,0.1); }
        .btn-primary { background: #0ea5e9; color: white; padding: 0.5rem 1rem; border-radius: 0.5rem; font-weight: 600; transition: background 0.2s; }
        .btn-primary:hover { background: #0284c7; }
        .btn-secondary { background: #f1f5f9; color: #334155; padding: 0.5rem 1rem; border-radius: 0.5rem; font-weight: 500; transition: background 0.2s; }
        .btn-secondary:hover { background: #e2e8f0; }
        .status-badge { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; }
        .filter-input, .filter-select { border: 1px solid #e2e8f0; border-radius: 0.5rem; padding: 0.5rem 0.75rem; font-size: 0.875rem; background: white; }
        .return-window-banner { background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%); border-left: 4px solid #22c55e; }
        .eligible-badge { background: #10b981; color: white; padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.7rem; font-weight: 600; }
        .info-badge { background: #e0f2fe; color: #0284c7; padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.7rem; font-weight: 500; }
    </style>
</head>
<body class="bg-gray-50">
<?php include '../includes/navbar.php'; ?>

<div class="max-w-6xl mx-auto px-4 py-8">
    
    <?php if ($message): ?>
    <div class="mb-4 p-4 rounded-lg flex items-center justify-between <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700 border-l-4 border-green-500' : ($messageType === 'error' ? 'bg-red-100 text-red-700 border-l-4 border-red-500' : 'bg-blue-100 text-blue-700 border-l-4 border-blue-500'); ?>">
        <div class="flex items-center gap-2">
            <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : ($messageType === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'); ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
        <button onclick="this.closest('div').remove()" class="text-gray-500 hover:text-gray-700">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="mb-6">
        <h1 class="brand-heading text-2xl font-bold text-gray-900">Request Product Return</h1>
        <p class="text-gray-500 text-sm mt-1">Submit a return request for eligible orders (claimed 1-5 days ago)</p>
    </div>

    <!-- Return Window Banner -->
    <div class="return-window-banner rounded-lg p-4 mb-6">
        <div class="flex items-start gap-3">
            <i class="fas fa-fish text-green-600 text-xl mt-0.5"></i>
            <div class="flex-1">
                <h3 class="font-semibold text-green-800">Return Eligibility: Orders Claimed 1 to 5 Days Ago</h3>
                <p class="text-sm text-green-700 mt-1">
                    <strong>Refunds are based on the amount you have actually paid.</strong>
                    <br>Orders are eligible for return if they were <strong>claimed between 
                    <?php echo $eligibleStartDate; ?> and <?php echo $eligibleEndDate; ?></strong> (1-5 days ago).
                    <br><span class="text-xs mt-1 block">✓ Day 1 (24 hours) to Day 5 (120 hours) after claim • Refund calculated on paid amount only</span>
                </p>
            </div>
            <i class="fas fa-info-circle text-green-500 text-lg"></i>
        </div>
    </div>

    <!-- Return Request Form -->
    <div class="card p-6 mb-8">
        <h2 class="text-lg font-semibold mb-4 flex items-center">
            <i class="fas fa-undo-alt text-blue-500 mr-2"></i>
            New Return Request
        </h2>
        
        <form method="POST" id="returnForm">
            <input type="hidden" name="action" value="submit_return">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Select Eligible Order *</label>
                    <select name="order_id" id="order_id" required class="form-input">
                        <option value="">Choose an order...</option>
                        <?php foreach ($orders as $order): 
                            $daysOld = $order['days_since_claimed'];
                            $userPaid = $order['user_paid_amount'];
                            $refundableBase = $order['refundable_amount'];
                            $isSalaryDeduction = ($order['deduction_id'] !== null);
                        ?>
                        <option value="<?php echo $order['order_id']; ?>" 
                                data-total="<?php echo $order['total_amount']; ?>" 
                                data-paid="<?php echo $userPaid; ?>"
                                data-refundable="<?php echo $refundableBase; ?>"
                                data-claimed="<?php echo htmlspecialchars($order['claimed_at']); ?>"
                                data-days="<?php echo $daysOld; ?>"
                                data-is-salary="<?php echo $isSalaryDeduction ? '1' : '0'; ?>"
                                data-items='<?php echo htmlspecialchars(json_encode($order['items']), ENT_QUOTES); ?>'>
                            Order #<?php echo str_pad($order['order_id'], 6, '0', STR_PAD_LEFT); ?> — 
                            ₱<?php echo number_format($order['total_amount'], 2); ?> total — 
                            Paid: ₱<?php echo number_format($userPaid, 2); ?> — 
                            Claimed <?php echo $daysOld; ?> day(s) ago (<?php echo date('M d', strtotime($order['claimed_at'])); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($orders)): ?>
                        <div class="mt-3 p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                            <p class="text-sm text-yellow-800">
                                <i class="fas fa-info-circle mr-1"></i>
                                <strong>No eligible orders found for return.</strong>
                            </p>
                            <p class="text-xs text-yellow-600 mt-2">
                                Orders are eligible for return if they were <strong>claimed 1 to 5 days ago</strong>.
                                <br>Currently eligible: Orders claimed between <strong><?php echo $eligibleStartDate; ?></strong> and <strong><?php echo $eligibleEndDate; ?></strong>
                                <br><br>If you claimed an order today, it will be eligible for return starting tomorrow.
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-1">Return Reason *</label>
                    <select name="return_reason" required class="form-input">
                        <option value="">Select reason...</option>
                        <option value="damaged">Damaged Product</option>
                        <option value="rotten">Rotten / Spoiled</option>
                        <option value="wrong_item">Wrong Item Delivered</option>
                        <option value="quality">Poor Quality</option>
                        <option value="expired">Expired Product</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>
            
            <?php if (!empty($orders)): ?>
            <div id="orderDetails" class="mb-4 hidden">
                <div class="bg-blue-50 rounded-lg p-3 border border-blue-200">
                    <div class="flex justify-between items-center mb-2">
                        <p class="text-sm font-medium text-blue-800">
                            <i class="fas fa-shopping-basket mr-1"></i> Order Items:
                        </p>
                        <span class="eligible-badge">
                            <i class="fas fa-check-circle text-xs"></i> Eligible (1-5 days)
                        </span>
                    </div>
                    <div id="orderItemsList" class="text-sm text-blue-700 space-y-1"></div>
                    <div id="paymentInfo" class="mt-2 text-xs bg-white rounded p-2">
                        <div class="grid grid-cols-3 gap-2">
                            <div><span class="text-gray-500">Order Total:</span><br><strong id="displayTotal">₱0.00</strong></div>
                            <div><span class="text-gray-500">You Paid:</span><br><strong id="displayPaid" class="text-green-600">₱0.00</strong></div>
                            <div><span class="text-gray-500">Refundable Base:</span><br><strong id="displayRefundable" class="text-blue-600">₱0.00</strong></div>
                        </div>
                    </div>
                    <div id="ageInfo" class="mt-2 text-xs">
                        <i class="fas fa-calendar-week"></i> <span id="daysOldDisplay"></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Return Percentage (%) *</label>
                    <input type="number" name="return_percentage" id="return_percentage" step="1" min="1" max="100" required 
                           class="form-input" placeholder="e.g., 25 for 25%">
                    <p class="text-xs text-gray-500 mt-1">Enter as percentage (1 to 100)</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-1">Refund Amount</label>
                    <input type="text" id="refund_amount" readonly 
                           class="form-input bg-gray-50 font-semibold text-green-600" 
                           placeholder="₱0.00">
                    <p class="text-xs text-gray-500 mt-1">Based on your paid amount</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-1">&nbsp;</label>
                    <button type="submit" class="btn-primary w-full">
                        <i class="fas fa-paper-plane mr-2"></i> Submit Return Request
                    </button>
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-1">Description (Optional)</label>
                <textarea name="return_description" rows="2" class="form-input resize-none" 
                          placeholder="Please describe the issue with the fish product (e.g., smell, texture, appearance)..."></textarea>
            </div>
        </form>
    </div>

    <!-- Return History -->
    <div class="card p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-semibold flex items-center">
                <i class="fas fa-history text-blue-500 mr-2"></i>
                Return History
                <span class="ml-2 text-sm font-normal text-gray-500">(<?php echo $historyStats['total']; ?> total)</span>
            </h2>
        </div>
        
        <!-- Filter Bar -->
        <div class="bg-gray-50 rounded-lg p-4 mb-4">
            <form method="GET" class="flex flex-wrap gap-3 items-end">
                <div class="flex-1 min-w-[150px]">
                    <label class="block text-xs font-medium text-gray-600 mb-1">Date From</label>
                    <input type="date" name="history_date_from" value="<?php echo htmlspecialchars($historyDateFrom); ?>" class="filter-input w-full text-sm">
                </div>
                
                <div class="flex-1 min-w-[150px]">
                    <label class="block text-xs font-medium text-gray-600 mb-1">Date To</label>
                    <input type="date" name="history_date_to" value="<?php echo htmlspecialchars($historyDateTo); ?>" class="filter-input w-full text-sm">
                </div>
                
                <div class="w-[130px]">
                    <label class="block text-xs font-medium text-gray-600 mb-1">Sort By</label>
                    <select name="history_sort" class="filter-select w-full text-sm">
                        <option value="newest" <?php echo $historySort == 'newest' ? 'selected' : ''; ?>>Newest First</option>
                        <option value="oldest" <?php echo $historySort == 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                    </select>
                </div>
                
                <div>
                    <button type="submit" class="btn-primary text-sm py-2 px-4">
                        <i class="fas fa-filter mr-1"></i> Apply
                    </button>
                    <a href="request_return.php" class="btn-secondary text-sm py-2 px-4 ml-2">
                        <i class="fas fa-redo-alt mr-1"></i> Reset
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Returns Table -->
        <?php if (empty($existingReturns)): ?>
            <div class="text-center py-12">
                <i class="fas fa-inbox text-gray-300 text-5xl mb-3"></i>
                <p class="text-gray-500">No return requests found</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200">
                        <tr class="bg-gray-50">
                            <th class="text-left py-3 px-3 font-semibold text-gray-600">Return #</th>
                            <th class="text-left py-3 px-3 font-semibold text-gray-600">Order</th>
                            <th class="text-left py-3 px-3 font-semibold text-gray-600">Reason</th>
                            <th class="text-right py-3 px-3 font-semibold text-gray-600">Return %</th>
                            <th class="text-right py-3 px-3 font-semibold text-gray-600">Refund</th>
                            <th class="text-left py-3 px-3 font-semibold text-gray-600">Status</th>
                            <th class="text-left py-3 px-3 font-semibold text-gray-600">Date</th>
                        \)`
                    </thead>
                    <tbody>
                        <?php foreach ($existingReturns as $ret): 
                            $refundPercent = round($ret['return_percentage'], 0);
                            $statusClass = $statusColors[$ret['return_status']] ?? 'bg-gray-100 text-gray-800';
                        ?>
                            <tr class="border-b border-gray-100 hover:bg-gray-50 transition">
                                <td class="py-3 px-3">
                                    <span class="font-mono text-xs font-semibold bg-gray-100 px-2 py-1 rounded">
                                        #<?php echo str_pad($ret['return_id'], 5, '0', STR_PAD_LEFT); ?>
                                    </span>
                                </td>
                                <td class="py-3 px-3">
                                    <span class="font-mono text-xs bg-blue-50 text-blue-700 px-2 py-1 rounded">
                                        #<?php echo str_pad($ret['order_id'], 6, '0', STR_PAD_LEFT); ?>
                                    </span>
                                </td>
                                <td class="py-3 px-3 capitalize">
                                    <?php echo str_replace('_', ' ', $ret['return_reason']); ?>
                                </td>
                                <td class="py-3 px-3 text-right font-mono">
                                    <?php echo $refundPercent; ?>%
                                </td>
                                <td class="py-3 px-3 text-right font-semibold text-green-600">
                                    ₱<?php echo number_format($ret['return_amount'], 2); ?>
                                </td>
                                <td class="py-3 px-3">
                                    <span class="status-badge <?php echo $statusClass; ?>">
                                        <?php echo ucfirst($ret['return_status']); ?>
                                    </span>
                                </td>
                                <td class="py-3 px-3 text-gray-500 text-xs whitespace-nowrap">
                                    <?php echo date('M d, Y', strtotime($ret['request_date'])); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="border-t-2 border-gray-200 bg-gray-50">
                        <tr>
                            <td colspan="4" class="py-3 px-3 font-semibold text-gray-700">Total Refund</td>
                            <td class="py-3 px-3 text-right font-bold text-green-700 text-lg">
                                ₱<?php echo number_format($historyStats['total_refund'], 2); ?>
                            </td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
const orderSelect = document.getElementById('order_id');
const percentageInput = document.getElementById('return_percentage');
const refundAmountInput = document.getElementById('refund_amount');
const orderDetailsDiv = document.getElementById('orderDetails');
const orderItemsList = document.getElementById('orderItemsList');
const daysOldDisplay = document.getElementById('daysOldDisplay');
const displayTotal = document.getElementById('displayTotal');
const displayPaid = document.getElementById('displayPaid');
const displayRefundable = document.getElementById('displayRefundable');

function displayOrderDetails() {
    const selectedOption = orderSelect.options[orderSelect.selectedIndex];
    
    if (selectedOption && selectedOption.value) {
        try {
            const items = JSON.parse(selectedOption.dataset.items || '[]');
            const total = parseFloat(selectedOption.dataset.total) || 0;
            const paid = parseFloat(selectedOption.dataset.paid) || 0;
            const refundable = parseFloat(selectedOption.dataset.refundable) || 0;
            const claimedDate = selectedOption.dataset.claimed || '';
            const daysOld = parseInt(selectedOption.dataset.days) || 0;
            const isSalary = selectedOption.dataset.isSalary === '1';
            
            if (items.length > 0) {
                let itemsHtml = '';
                items.forEach(item => {
                    const quantity = parseFloat(item.quantity || 0).toFixed(2);
                    const price = parseFloat(item.price_per_kg || 0).toFixed(2);
                    itemsHtml += `<div class="flex justify-between items-center py-1 border-b border-blue-100 last:border-0">
                        <div class="flex-1">
                            <strong>${escapeHtml(item.fish_name || 'Fish Product')}</strong>
                            <span class="text-xs text-blue-600 ml-2">${quantity} kg × ₱${price}</span>
                        </div>
                        <div class="text-right">₱${(quantity * price).toLocaleString(undefined, {minimumFractionDigits: 2})}</div>
                    </div>`;
                });
                itemsHtml += `<div class="flex justify-between items-center pt-2 mt-1 border-t border-blue-200 font-semibold">
                    <span>Total Amount:</span>
                    <span>₱${total.toLocaleString(undefined, {minimumFractionDigits: 2})}</span>
                </div>`;
                orderItemsList.innerHTML = itemsHtml;
                
                // Display payment info
                displayTotal.textContent = '₱' + total.toLocaleString(undefined, {minimumFractionDigits: 2});
                displayPaid.textContent = '₱' + paid.toLocaleString(undefined, {minimumFractionDigits: 2});
                displayRefundable.textContent = '₱' + refundable.toLocaleString(undefined, {minimumFractionDigits: 2});
                
                if (isSalary) {
                    displayPaid.innerHTML = '₱' + paid.toLocaleString(undefined, {minimumFractionDigits: 2}) + ' <span class="text-xs text-gray-400">(salary deduction)</span>';
                }
                
                if (daysOldDisplay) {
                    daysOldDisplay.innerHTML = `📅 Claimed ${daysOld} day(s) ago on ${formatDate(claimedDate)}`;
                }
                
                orderDetailsDiv.classList.remove('hidden');
            } else {
                orderDetailsDiv.classList.add('hidden');
            }
        } catch(e) {
            console.error('Error parsing items:', e);
            orderDetailsDiv.classList.add('hidden');
        }
    } else {
        orderDetailsDiv.classList.add('hidden');
    }
}

function formatDate(dateString) {
    if (!dateString) return 'Unknown';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function calculateRefund() {
    const selectedOption = orderSelect.options[orderSelect.selectedIndex];
    const percentage = parseFloat(percentageInput.value) || 0;
    
    if (selectedOption && selectedOption.value && percentage > 0 && percentage <= 100) {
        const refundableBase = parseFloat(selectedOption.dataset.refundable) || 0;
        const amount = refundableBase * (percentage / 100);
        refundAmountInput.value = '₱' + amount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        
        // Add visual feedback for percentage input
        if (percentage > 100) {
            percentageInput.style.borderColor = '#ef4444';
        } else {
            percentageInput.style.borderColor = '#e2e8f0';
        }
    } else {
        refundAmountInput.value = '';
    }
}

if (orderSelect) {
    orderSelect.addEventListener('change', () => {
        displayOrderDetails();
        calculateRefund();
    });
}

if (percentageInput) {
    percentageInput.addEventListener('input', calculateRefund);
}

if (orderSelect && orderSelect.value) {
    displayOrderDetails();
}

// Form validation
const returnForm = document.getElementById('returnForm');
if (returnForm) {
    returnForm.addEventListener('submit', function(e) {
        const orderId = orderSelect ? orderSelect.value : null;
        const reason = document.querySelector('[name="return_reason"]') ? document.querySelector('[name="return_reason"]').value : null;
        const percentage = percentageInput ? parseFloat(percentageInput.value) : 0;
        
        if (!orderId) {
            e.preventDefault();
            alert('Please select an eligible order to return');
            return false;
        }
        
        if (!reason) {
            e.preventDefault();
            alert('Please select a return reason');
            return false;
        }
        
        if (isNaN(percentage) || percentage <= 0 || percentage > 100) {
            e.preventDefault();
            alert('Return percentage must be between 1% and 100%');
            return false;
        }
        
        const selectedOption = orderSelect.options[orderSelect.selectedIndex];
        const daysOld = parseInt(selectedOption.dataset.days) || 0;
        const refundableBase = parseFloat(selectedOption.dataset.refundable) || 0;
        const refundAmount = refundableBase * (percentage / 100);
        
        if (daysOld < 1 || daysOld > 5) {
            e.preventDefault();
            alert(`Returns are only allowed for orders claimed 1 to 5 days ago.\n\nThis order was claimed ${daysOld} day(s) ago.\n\nEligible window: Day 1 through Day 5 after claim date.`);
            return false;
        }
        
        if (!confirm(`Submit return request for ${percentage}% of the order?\n\nRefund amount: ₱${refundAmount.toLocaleString(undefined, {minimumFractionDigits: 2})}\nOrder claimed: ${daysOld} days ago\n\nThis request will be reviewed by a manager.`)) {
            e.preventDefault();
            return false;
        }
        
        return true;
    });
}

setTimeout(() => {
    const flashMsg = document.querySelector('.bg-green-100, .bg-red-100, .bg-blue-100');
    if (flashMsg) {
        flashMsg.style.transition = 'opacity 0.5s';
        flashMsg.style.opacity = '0';
        setTimeout(() => {
            if (flashMsg && flashMsg.parentNode) flashMsg.remove();
        }, 500);
    }
}, 5000);
</script>
</body>
</html>