<?php
// user/order_details.php - View order details
require_once '../includes/config.php';
require_once '../includes/session.php';

SessionManager::requireStandard();

$userId = SessionManager::getUserId();
$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$orderId) {
    header('Location: orders.php');
    exit();
}

try {
    $db = (new Database())->getConnection();
    
    // Get order details with verification that it belongs to the user
    $sql = "SELECT * FROM orders 
            WHERE order_id = :order_id AND user_id = :user_id";
    $stmt = $db->prepare($sql);
    $stmt->execute([':order_id' => $orderId, ':user_id' => $userId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        header('Location: orders.php');
        exit();
    }
    
    // Get order items
    $itemsSql = "SELECT oi.*, fp.fish_name, 'kg' as unit
                 FROM order_items oi
                 JOIN fish_products fp ON oi.product_id = fp.product_id
                 WHERE oi.order_id = :order_id";
    $itemsStmt = $db->prepare($itemsSql);
    $itemsStmt->execute([':order_id' => $orderId]);
    $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Order details error: " . $e->getMessage());
    $order = null;
    $orderItems = [];
}

function getOrderStatusBadge($status) {
    $badges = [
        'pending' => ['bg-yellow-100', 'text-yellow-800', 'fa-clock', 'Pending'],
        'processing' => ['bg-blue-100', 'text-blue-800', 'fa-spinner', 'Processing'],
        'confirmed' => ['bg-green-100', 'text-green-800', 'fa-check-circle', 'Confirmed'],
        'ready_for_pickup' => ['bg-purple-100', 'text-purple-800', 'fa-box-open', 'Ready for Pickup'],
        'completed' => ['bg-green-100', 'text-green-800', 'fa-check-double', 'Completed'],
        'cancelled' => ['bg-red-100', 'text-red-800', 'fa-times-circle', 'Cancelled']
    ];
    $badge = $badges[$status] ?? ['bg-gray-100', 'text-gray-800', 'fa-question', ucfirst($status)];
    return [$badge[0], $badge[1], $badge[2], $badge[3]];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order #<?php echo str_pad($orderId, 6, '0', STR_PAD_LEFT); ?> - BISU IGE Aquaculture</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #0ea5e9;
            --accent: #8b5cf6;
        }
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        }
        .details-card {
            background: white;
            border-radius: 1.5rem;
            border: 1px solid rgba(203, 213, 225, 0.2);
            overflow: hidden;
        }
        .timeline-item {
            position: relative;
            padding-left: 2rem;
            padding-bottom: 1.5rem;
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: 0.5rem;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e2e8f0;
        }
        .timeline-item:last-child::before {
            display: none;
        }
        .timeline-icon {
            position: absolute;
            left: -0.25rem;
            top: 0;
            width: 1.5rem;
            height: 1.5rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
            border: 2px solid;
        }
        .btn-outline {
            background: transparent;
            border: 1px solid #e2e8f0;
            color: #4b5563;
            border-radius: 1rem;
            padding: 0.5rem 1rem;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }
        .btn-outline:hover {
            background: #f1f5f9;
            border-color: var(--primary);
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>

    <!-- Page Header -->
    <div class="bg-gradient-to-r from-slate-800 to-slate-900 py-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                <div>
                    <a href="orders.php" class="text-slate-400 hover:text-white transition mb-2 inline-block">
                        <i class="fas fa-arrow-left mr-2"></i>
                        Back to Orders
                    </a>
                    <h1 class="text-2xl font-bold text-white">
                        Order #<?php echo str_pad($orderId, 6, '0', STR_PAD_LEFT); ?>
                    </h1>
                </div>
            </div>
        </div>
    </div>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php if ($order): ?>
            <!-- Order Status -->
            <?php list($bgColor, $textColor, $icon, $statusText) = getOrderStatusBadge($order['order_status']); ?>
            <div class="details-card mb-6">
                <div class="p-6 border-b border-gray-100">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                        <div>
                            <span class="text-sm text-gray-500">Order Status</span>
                            <div class="status-badge inline-flex items-center gap-2 px-3 py-1 rounded-full <?php echo $bgColor . ' ' . $textColor; ?> mt-1">
                                <i class="fas <?php echo $icon; ?>"></i>
                                <?php echo $statusText; ?>
                            </div>
                        </div>
                        <div class="mt-3 md:mt-0 text-right">
                            <span class="text-sm text-gray-500">Placed on</span>
                            <p class="font-medium"><?php echo date('F d, Y g:i A', strtotime($order['order_date'])); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Order Timeline -->
            <div class="details-card mb-6">
                <div class="p-6">
                    <h3 class="font-semibold text-gray-900 mb-4 flex items-center gap-2">
                        <i class="fas fa-timeline text-primary"></i>
                        Order Timeline
                    </h3>
                    <div class="space-y-0">
                        <div class="timeline-item">
                            <div class="timeline-icon border-green-500 bg-green-50">
                                <i class="fas fa-check text-green-500 text-xs"></i>
                            </div>
                            <div class="ml-4">
                                <p class="font-medium text-gray-900">Order Placed</p>
                                <p class="text-sm text-gray-500"><?php echo date('F d, Y g:i A', strtotime($order['order_date'])); ?></p>
                            </div>
                        </div>
                        
                        <?php if ($order['confirmed_at']): ?>
                        <div class="timeline-item">
                            <div class="timeline-icon border-green-500 bg-green-50">
                                <i class="fas fa-check-circle text-green-500 text-xs"></i>
                            </div>
                            <div class="ml-4">
                                <p class="font-medium text-gray-900">Order Confirmed</p>
                                <p class="text-sm text-gray-500"><?php echo date('F d, Y g:i A', strtotime($order['confirmed_at'])); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($order['claimed_at']): ?>
                        <div class="timeline-item">
                            <div class="timeline-icon border-blue-500 bg-blue-50">
                                <i class="fas fa-hand-peace text-blue-500 text-xs"></i>
                            </div>
                            <div class="ml-4">
                                <p class="font-medium text-gray-900">Order Claimed</p>
                                <p class="text-sm text-gray-500"><?php echo date('F d, Y g:i A', strtotime($order['claimed_at'])); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($order['cancelled_at']): ?>
                        <div class="timeline-item">
                            <div class="timeline-icon border-red-500 bg-red-50">
                                <i class="fas fa-times-circle text-red-500 text-xs"></i>
                            </div>
                            <div class="ml-4">
                                <p class="font-medium text-gray-900">Order Cancelled</p>
                                <p class="text-sm text-gray-500"><?php echo date('F d, Y g:i A', strtotime($order['cancelled_at'])); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Order Items -->
            <div class="details-card mb-6">
                <div class="p-6">
                    <h3 class="font-semibold text-gray-900 mb-4 flex items-center gap-2">
                        <i class="fas fa-fish text-primary"></i>
                        Order Items
                    </h3>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="border-b border-gray-200">
                                <tr class="text-left text-sm text-gray-500">
                                    <th class="pb-3">Product</th>
                                    <th class="pb-3 text-center">Quantity</th>
                                    <th class="pb-3 text-right">Unit Price</th>
                                    <th class="pb-3 text-right">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orderItems as $item): ?>
                                <tr class="border-b border-gray-100">
                                    <td class="py-3">
                                        <span class="font-medium text-gray-900"><?php echo htmlspecialchars($item['fish_name']); ?></span>
                                    </td>
                                    <td class="py-3 text-center">
                                        <?php echo number_format($item['quantity'], 2); ?> <?php echo $item['unit'] ?? 'kg'; ?>
                                    </td>
                                    <td class="py-3 text-right">
                                        ₱<?php echo number_format($item['price_per_kg'], 2); ?>
                                    </td>
                                    <td class="py-3 text-right font-medium">
                                        ₱<?php echo number_format($item['subtotal'], 2); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="border-t border-gray-200">
                                    <td colspan="3" class="pt-4 text-right font-semibold text-gray-900">
                                        Total Amount:
                                    </td>
                                    <td class="pt-4 text-right text-xl font-bold text-primary">
                                        ₱<?php echo number_format($order['total_amount'], 2); ?>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Order Information -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="details-card">
                    <div class="p-6">
                        <h3 class="font-semibold text-gray-900 mb-3 flex items-center gap-2">
                            <i class="fas fa-credit-card text-primary"></i>
                            Payment Information
                        </h3>
                        <p class="text-gray-600">
                            <span class="font-medium">Method:</span> 
                            <?php echo ucfirst(str_replace('_', ' ', $order['payment_method'])); ?>
                        </p>
                    </div>
                </div>
                
                <div class="details-card">
                    <div class="p-6">
                        <h3 class="font-semibold text-gray-900 mb-3 flex items-center gap-2">
                            <i class="fas fa-info-circle text-primary"></i>
                            Additional Information
                        </h3>
                        <?php if (!empty($order['remarks'])): ?>
                            <p class="text-gray-600">
                                <span class="font-medium">Remarks:</span><br>
                                <?php echo nl2br(htmlspecialchars($order['remarks'])); ?>
                            </p>
                        <?php else: ?>
                            <p class="text-gray-500 italic">No remarks provided</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <?php if ($order['order_status'] == 'pending'): ?>
            <div class="mt-6 flex justify-end">
                <button onclick="cancelOrder(<?php echo $order['order_id']; ?>)" class="px-6 py-3 bg-red-500 text-white rounded-xl font-medium hover:bg-red-600 transition flex items-center gap-2">
                    <i class="fas fa-times-circle"></i>
                    Cancel Order
                </button>
            </div>
            <?php endif; ?>
            
        <?php else: ?>
            <div class="details-card p-12 text-center">
                <i class="fas fa-exclamation-triangle text-5xl text-yellow-500 mb-4"></i>
                <h3 class="text-xl font-semibold text-gray-900 mb-2">Order Not Found</h3>
                <p class="text-gray-500 mb-6">The order you're looking for doesn't exist or you don't have permission to view it.</p>
                <a href="orders.php" class="btn-outline inline-flex">
                    <i class="fas fa-arrow-left"></i>
                    Back to Orders
                </a>
            </div>
        <?php endif; ?>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
        function cancelOrder(orderId) {
            if (confirm('Are you sure you want to cancel this order? This action cannot be undone.')) {
                fetch('../api/cancel_order.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'order_id=' + orderId
                }).then(response => response.json())
                  .then(data => {
                      if (data.success) {
                          window.location.href = 'orders.php?message=Order cancelled successfully&type=success';
                      } else {
                          alert(data.message || 'Failed to cancel order');
                      }
                  }).catch(() => {
                      alert('An error occurred');
                  });
            }
        }
    </script>
</body>
</html>