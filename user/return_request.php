<?php
// user/request_return.php - Professional Return Request Form with Return History Filtering
require_once '../includes/config.php';
require_once '../includes/session.php';

SessionManager::requireLogin();

$db = (new Database())->getConnection();
$userId = SessionManager::getUserId();
$message = '';
$messageType = '';

// Get filter parameters for return history
$historyStatusFilter = $_GET['history_status'] ?? 'all';
$historyDateFrom = $_GET['history_date_from'] ?? '';
$historyDateTo = $_GET['history_date_to'] ?? '';
$historySort = $_GET['history_sort'] ?? 'newest';

// Allowed values
$allowedStatuses = ['all', 'pending', 'approved', 'rejected', 'completed'];
$allowedSorts = ['newest', 'oldest'];

if (!in_array($historyStatusFilter, $allowedStatuses)) $historyStatusFilter = 'all';
if (!in_array($historySort, $allowedSorts)) $historySort = 'newest';

// Get user's completed orders (claimed_at IS NOT NULL means completed/picked up)
$orders = [];
try {
    $ordersSql = "SELECT order_id, order_datetime as order_date, total_amount, payment_method
                  FROM orders 
                  WHERE user_id = :user_id 
                  AND claimed_at IS NOT NULL 
                  AND order_status = 'completed'
                  AND NOT EXISTS (
                      SELECT 1 FROM return_requests rr 
                      WHERE rr.order_id = orders.order_id 
                      AND rr.user_id = orders.user_id 
                      AND rr.return_status IN ('pending', 'approved')
                  )
                  ORDER BY order_datetime DESC
                  LIMIT 20";
    
    $ordersStmt = $db->prepare($ordersSql);
    $ordersStmt->execute([':user_id' => $userId]);
    $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Create placeholder items for each order
    foreach ($orders as &$order) {
        $order['items'] = [[
            'product_id' => $order['order_id'],
            'fish_name' => 'Fish Product',
            'quantity' => 1,
            'price_per_kg' => $order['total_amount']
        ]];
    }
    unset($order);
} catch (PDOException $e) {
    error_log("Orders error: " . $e->getMessage());
    $orders = [];
}

// Get existing return requests with filters
$existingReturns = [];
try {
    $returnsSql = "SELECT rr.*, o.order_datetime as order_date, o.total_amount as order_total
                   FROM return_requests rr
                   LEFT JOIN orders o ON rr.order_id = o.order_id
                   WHERE rr.user_id = :user_id";
    
    $params = [':user_id' => $userId];
    
    // Status filter
    if ($historyStatusFilter !== 'all') {
        $returnsSql .= " AND rr.return_status = :status";
        $params[':status'] = $historyStatusFilter;
    }
    
    // Date range filter
    if ($historyDateFrom) {
        $returnsSql .= " AND DATE(rr.request_date) >= :date_from";
        $params[':date_from'] = $historyDateFrom;
    }
    
    if ($historyDateTo) {
        $returnsSql .= " AND DATE(rr.request_date) <= :date_to";
        $params[':date_to'] = $historyDateTo;
    }
    
    // Sorting
    if ($historySort === 'oldest') {
        $returnsSql .= " ORDER BY rr.request_date ASC";
    } else {
        $returnsSql .= " ORDER BY rr.request_date DESC";
    }
    
    $returnsSql .= " LIMIT 50";
    
    $returnsStmt = $db->prepare($returnsSql);
    $returnsStmt->execute($params);
    $existingReturns = $returnsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get statistics for the filter summary
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_return') {
    try {
        $order_id = (int)$_POST['order_id'];
        $product_id = (int)$_POST['product_id'];
        $return_reason = $_POST['return_reason'];
        $return_description = trim($_POST['return_description'] ?? '');
        $return_quantity = (float)$_POST['return_quantity'];
        
        // Verify order belongs to user and is claimed
        $verifySql = "SELECT order_id, total_amount, claimed_at FROM orders WHERE order_id = :oid AND user_id = :uid AND claimed_at IS NOT NULL";
        $verifyStmt = $db->prepare($verifySql);
        $verifyStmt->execute([':oid' => $order_id, ':uid' => $userId]);
        $orderData = $verifyStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$orderData) {
            throw new Exception("Invalid order or order not yet claimed");
        }
        
        // Calculate return amount as percentage of order total
        $original_quantity = 1;
        $original_price = $orderData['total_amount'];
        $return_amount = $return_quantity * $original_price;
        
        // Check existing returns for this order
        $checkSql = "SELECT COALESCE(SUM(return_quantity), 0) as returned_qty 
                     FROM return_requests 
                     WHERE order_id = :oid AND user_id = :uid 
                     AND return_status != 'rejected'";
        $checkStmt = $db->prepare($checkSql);
        $checkStmt->execute([':oid' => $order_id, ':uid' => $userId]);
        $alreadyReturned = (float)$checkStmt->fetchColumn();
        
        $availableQty = 1 - $alreadyReturned;
        
        if ($return_quantity > $availableQty) {
            throw new Exception("Return percentage cannot exceed " . ($availableQty * 100) . "% of the order total");
        }
        
        if ($return_quantity <= 0 || $return_quantity > 1) {
            throw new Exception("Return percentage must be between 1% and 100%");
        }
        
        // Insert return request
        $insertSql = "INSERT INTO return_requests (
                        order_id, user_id, deduction_id, return_reason, return_description,
                        return_quantity, return_amount, product_id, original_quantity, original_price,
                        return_status, request_date, created_at, updated_at
                      ) VALUES (
                        :order_id, :user_id, :deduction_id, :return_reason, :return_description,
                        :return_quantity, :return_amount, :product_id, :original_quantity, :original_price,
                        'pending', NOW(), NOW(), NOW()
                      )";
        
        $insertStmt = $db->prepare($insertSql);
        $insertStmt->execute([
            ':order_id' => $order_id,
            ':user_id' => $userId,
            ':deduction_id' => null,
            ':return_reason' => $return_reason,
            ':return_description' => $return_description,
            ':return_quantity' => $return_quantity,
            ':return_amount' => $return_amount,
            ':product_id' => $product_id,
            ':original_quantity' => $original_quantity,
            ':original_price' => $original_price
        ]);
        
        $message = "Return request submitted successfully! Waiting for manager approval.";
        $messageType = 'success';
        
        // Preserve current filters when redirecting
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

// Status options for history filter
$statusOptions = [
    'all' => 'All Status',
    'pending' => 'Pending',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'completed' => 'Completed'
];

// Status colors
$statusColors = [
    'pending' => 'bg-yellow-100 text-yellow-800',
    'approved' => 'bg-green-100 text-green-800',
    'rejected' => 'bg-red-100 text-red-800',
    'completed' => 'bg-blue-100 text-blue-800'
];
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
        .form-input { width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #e2e8f0; border-radius: 0.5rem; }
        .form-input:focus { outline: none; border-color: #0ea5e9; ring: 2px solid #0ea5e9; }
        .btn-primary { background: #0ea5e9; color: white; padding: 0.5rem 1rem; border-radius: 0.5rem; font-weight: 600; }
        .btn-primary:hover { background: #0284c7; }
        .btn-secondary { background: #f1f5f9; color: #334155; padding: 0.5rem 1rem; border-radius: 0.5rem; font-weight: 500; }
        .btn-secondary:hover { background: #e2e8f0; }
        .status-badge { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; }
        .filter-input, .filter-select { border: 1px solid #e2e8f0; border-radius: 0.5rem; padding: 0.5rem 0.75rem; font-size: 0.875rem; }
        .filter-input:focus, .filter-select:focus { outline: none; border-color: #0ea5e9; }
        .stat-chip { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; background: #f8fafc; border-radius: 0.75rem; }
    </style>
</head>
<body class="bg-gray-50">
<?php include '../includes/navbar.php'; ?>

<div class="max-w-6xl mx-auto px-4 py-8">
    
    <?php if ($message): ?>
    <div class="mb-4 p-4 rounded-lg <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="mb-6">
        <h1 class="brand-heading text-2xl font-bold text-gray-900">Request Product Return</h1>
        <p class="text-gray-500 text-sm mt-1">Submit a return request for claimed orders</p>
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
                    <label class="block text-sm font-medium mb-1">Select Order *</label>
                    <select name="order_id" id="order_id" required class="form-input">
                        <option value="">Choose an order...</option>
                        <?php foreach ($orders as $order): ?>
                        <option value="<?php echo $order['order_id']; ?>" data-total="<?php echo $order['total_amount']; ?>">
                            Order #<?php echo str_pad($order['order_id'], 6, '0', STR_PAD_LEFT); ?> — 
                            ₱<?php echo number_format($order['total_amount'], 2); ?> — 
                            <?php echo date('M d, Y', strtotime($order['order_date'])); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($orders)): ?>
                        <p class="text-sm text-red-600 mt-1">No eligible orders found. Only claimed orders can be returned.</p>
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
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Return Percentage (%) *</label>
                    <input type="number" name="return_quantity" id="return_quantity" step="0.01" min="0.01" max="1" required 
                           class="form-input" placeholder="e.g., 0.50 for 50%">
                    <p class="text-xs text-gray-500 mt-1">Enter as decimal (0.01 to 1.00)</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-1">Refund Amount</label>
                    <input type="text" id="refund_amount" readonly 
                           class="form-input bg-gray-50 font-semibold" 
                           placeholder="₱0.00">
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-1">&nbsp;</label>
                    <button type="submit" class="btn-primary w-full">
                        <i class="fas fa-paper-plane"></i> Submit Return Request
                    </button>
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-1">Description (Optional)</label>
                <textarea name="return_description" rows="2" class="form-input resize-none" 
                          placeholder="Describe the issue in detail..."></textarea>
            </div>
        </form>
    </div>

    <!-- Return History with Filters -->
    <div class="card p-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-4">
            <h2 class="text-lg font-semibold flex items-center">
                <i class="fas fa-history text-blue-500 mr-2"></i>
                Return History
                <span class="ml-2 text-sm font-normal text-gray-500">(<?php echo $historyStats['total']; ?> total)</span>
            </h2>
            
            <!-- Statistics Chips -->
            <div class="flex flex-wrap gap-2 mt-2 md:mt-0">
                <a href="?history_status=all&history_sort=<?php echo $historySort; ?>" 
                   class="stat-chip text-xs <?php echo $historyStatusFilter == 'all' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                    All (<?php echo $historyStats['total']; ?>)
                </a>
                <a href="?history_status=pending&history_sort=<?php echo $historySort; ?>" 
                   class="stat-chip text-xs <?php echo $historyStatusFilter == 'pending' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                    <i class="fas fa-clock"></i> Pending (<?php echo $historyStats['pending']; ?>)
                </a>
                <a href="?history_status=approved&history_sort=<?php echo $historySort; ?>" 
                   class="stat-chip text-xs <?php echo $historyStatusFilter == 'approved' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                    <i class="fas fa-check-circle"></i> Approved (<?php echo $historyStats['approved']; ?>)
                </a>
                <a href="?history_status=rejected&history_sort=<?php echo $historySort; ?>" 
                   class="stat-chip text-xs <?php echo $historyStatusFilter == 'rejected' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                    <i class="fas fa-times-circle"></i> Rejected (<?php echo $historyStats['rejected']; ?>)
                </a>
                <a href="?history_status=completed&history_sort=<?php echo $historySort; ?>" 
                   class="stat-chip text-xs <?php echo $historyStatusFilter == 'completed' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                    <i class="fas fa-check-double"></i> Completed (<?php echo $historyStats['completed']; ?>)
                </a>
            </div>
        </div>
        
        <!-- Filter Bar -->
        <div class="bg-gray-50 rounded-lg p-4 mb-4">
            <form method="GET" class="flex flex-wrap gap-3 items-end">
                <input type="hidden" name="history_status" value="<?php echo $historyStatusFilter; ?>">
                
                <div class="flex-1 min-w-[150px]">
                    <label class="block text-xs font-medium text-gray-600 mb-1">Date From</label>
                    <input type="date" name="history_date_from" value="<?php echo $historyDateFrom; ?>" class="filter-input w-full text-sm">
                </div>
                
                <div class="flex-1 min-w-[150px]">
                    <label class="block text-xs font-medium text-gray-600 mb-1">Date To</label>
                    <input type="date" name="history_date_to" value="<?php echo $historyDateTo; ?>" class="filter-input w-full text-sm">
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
                        <i class="fas fa-filter"></i> Apply
                    </button>
                    <a href="request_return.php" class="btn-secondary text-sm py-2 px-4 ml-2">
                        <i class="fas fa-redo-alt"></i> Reset
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Active Filters Display -->
        <?php if ($historyStatusFilter != 'all' || $historyDateFrom || $historyDateTo || $historySort != 'newest'): ?>
        <div class="flex flex-wrap gap-2 mb-4">
            <span class="text-xs text-gray-500">Active filters:</span>
            <?php if ($historyStatusFilter != 'all'): ?>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-blue-100 text-blue-700 rounded-full text-xs">
                    Status: <?php echo ucfirst($historyStatusFilter); ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['history_status' => 'all'])); ?>" class="hover:text-blue-900">&times;</a>
                </span>
            <?php endif; ?>
            <?php if ($historyDateFrom): ?>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-blue-100 text-blue-700 rounded-full text-xs">
                    From: <?php echo $historyDateFrom; ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['history_date_from' => ''])); ?>" class="hover:text-blue-900">&times;</a>
                </span>
            <?php endif; ?>
            <?php if ($historyDateTo): ?>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-blue-100 text-blue-700 rounded-full text-xs">
                    To: <?php echo $historyDateTo; ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['history_date_to' => ''])); ?>" class="hover:text-blue-900">&times;</a>
                </span>
            <?php endif; ?>
            <?php if ($historySort != 'newest'): ?>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-blue-100 text-blue-700 rounded-full text-xs">
                    Sort: <?php echo $historySort == 'oldest' ? 'Oldest First' : 'Newest First'; ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['history_sort' => 'newest'])); ?>" class="hover:text-blue-900">&times;</a>
                </span>
            <?php endif; ?>
            <a href="request_return.php" class="text-xs text-red-500 hover:text-red-700">Clear all</a>
        </div>
        <?php endif; ?>
        
        <!-- Returns Table -->
        <?php if (empty($existingReturns)): ?>
            <div class="text-center py-8">
                <i class="fas fa-inbox text-gray-300 text-4xl mb-2"></i>
                <p class="text-gray-500">No return requests found</p>
                <?php if ($historyStatusFilter != 'all' || $historyDateFrom || $historyDateTo): ?>
                    <p class="text-sm text-gray-400 mt-1">Try adjusting your filters</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200">
                        <tr class="bg-gray-50">
                            <th class="text-left py-2 px-3 font-semibold text-gray-600">Return #</th>
                            <th class="text-left py-2 px-3 font-semibold text-gray-600">Order</th>
                            <th class="text-left py-2 px-3 font-semibold text-gray-600">Reason</th>
                            <th class="text-right py-2 px-3 font-semibold text-gray-600">Return %</th>
                            <th class="text-right py-2 px-3 font-semibold text-gray-600">Refund</th>
                            <th class="text-left py-2 px-3 font-semibold text-gray-600">Status</th>
                            <th class="text-left py-2 px-3 font-semibold text-gray-600">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($existingReturns as $ret): 
                            $refundPercent = round($ret['return_quantity'] * 100, 0);
                            $statusClass = $statusColors[$ret['return_status']] ?? 'bg-gray-100 text-gray-800';
                        ?>
                            <tr class="border-b border-gray-100 hover:bg-gray-50">
                                <td class="py-2 px-3">
                                    <span class="font-mono text-xs font-semibold bg-gray-100 px-2 py-1 rounded">
                                        #<?php echo str_pad($ret['return_id'], 5, '0', STR_PAD_LEFT); ?>
                                    </span>
                                </td>
                                <td class="py-2 px-3">
                                    <span class="font-mono text-xs bg-blue-50 text-blue-700 px-2 py-1 rounded">
                                        #<?php echo str_pad($ret['order_id'], 6, '0', STR_PAD_LEFT); ?>
                                    </span>
                                </td>
                                <td class="py-2 px-3 capitalize">
                                    <?php echo str_replace('_', ' ', $ret['return_reason']); ?>
                                    <?php if ($ret['return_description']): ?>
                                        <i class="fas fa-comment text-gray-400 text-xs ml-1" title="<?php echo htmlspecialchars($ret['return_description']); ?>"></i>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2 px-3 text-right font-mono">
                                    <?php echo $refundPercent; ?>%
                                </td>
                                <td class="py-2 px-3 text-right font-semibold text-green-600">
                                    ₱<?php echo number_format($ret['return_amount'], 2); ?>
                                </td>
                                <td class="py-2 px-3">
                                    <span class="status-badge <?php echo $statusClass; ?>">
                                        <?php echo ucfirst($ret['return_status']); ?>
                                    </span>
                                </td>
                                <td class="py-2 px-3 text-gray-500 text-xs whitespace-nowrap">
                                    <?php echo date('M d, Y', strtotime($ret['request_date'])); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="border-t border-gray-200 bg-gray-50">
                        <tr>
                            <td colspan="3" class="py-2 px-3 font-semibold text-gray-700">Total Refund</td>
                            <td class="py-2 px-3 text-right"></td>
                            <td class="py-2 px-3 text-right font-bold text-green-700">
                                ₱<?php echo number_format($historyStats['total_refund'], 2); ?>
                            </td>
                            <td colspan="2" class="py-2 px-3"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Calculate refund amount based on selected order and percentage
const orderSelect = document.getElementById('order_id');
const quantityInput = document.getElementById('return_quantity');
const refundAmountInput = document.getElementById('refund_amount');

function calculateRefund() {
    const selectedOption = orderSelect.options[orderSelect.selectedIndex];
    const percentage = parseFloat(quantityInput.value) || 0;
    
    if (selectedOption && selectedOption.dataset.total && percentage > 0 && percentage <= 1) {
        const total = parseFloat(selectedOption.dataset.total);
        const amount = total * percentage;
        refundAmountInput.value = '₱' + amount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    } else {
        refundAmountInput.value = '';
    }
}

orderSelect.addEventListener('change', calculateRefund);
quantityInput.addEventListener('input', calculateRefund);

// Form validation
document.getElementById('returnForm').addEventListener('submit', function(e) {
    const orderId = orderSelect.value;
    const reason = document.querySelector('[name="return_reason"]').value;
    const percentage = parseFloat(quantityInput.value);
    
    if (!orderId || !reason || isNaN(percentage) || percentage <= 0 || percentage > 1) {
        e.preventDefault();
        alert('Please fill in all required fields. Return percentage must be between 1% and 100%');
        return false;
    }
    
    if (!confirm(`Submit return request for ${(percentage * 100).toFixed(0)}% of order total?`)) {
        e.preventDefault();
        return false;
    }
    
    return true;
});
</script>
</body>
</html>