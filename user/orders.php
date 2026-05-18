<?php
// user/orders.php - Professional UI with Mark as Claimed
require_once '../includes/config.php';
require_once '../includes/session.php';

SessionManager::requireLogin();

$userId = SessionManager::getUserId();
$message = $_GET['message'] ?? '';
$messageType = $_GET['type'] ?? '';
$statusFilter = $_GET['status'] ?? 'all';

try {
    $db = (new Database())->getConnection();

    $sql = "SELECT 
                o.order_id,
                o.order_status,
                o.payment_method,
                o.total_amount,
                o.remarks,
                o.order_date,
                o.confirmed_at,
                o.claimed_at,
                o.cancelled_at,
                COUNT(DISTINCT oi.order_item_id) as item_count,
                COALESCE(SUM(oi.quantity), 0) as total_quantity
            FROM orders o
            LEFT JOIN order_items oi ON o.order_id = oi.order_id
            WHERE o.user_id = :user_id";

    if ($statusFilter !== 'all') {
        $sql .= " AND o.order_status = :status";
    }

    $sql .= " GROUP BY o.order_id ORDER BY o.order_date DESC";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);

    if ($statusFilter !== 'all') {
        $stmt->bindValue(':status', $statusFilter);
    }

    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Orders page error: " . $e->getMessage());
    $orders = [];
}

function getOrderStatusBadge($status) {
    $badges = [
        'pending' => ['bg-amber-50', 'text-amber-700', 'border-amber-200', 'fa-clock', 'Pending', 'Awaiting confirmation'],
        'confirmed' => ['bg-emerald-50', 'text-emerald-700', 'border-emerald-200', 'fa-check-circle', 'Confirmed', 'Ready for pickup'],
        'claimed' => ['bg-blue-50', 'text-blue-700', 'border-blue-200', 'fa-hand-peace', 'Claimed', 'Order received'],
        'cancelled' => ['bg-red-50', 'text-red-700', 'border-red-200', 'fa-times-circle', 'Cancelled', 'Order cancelled']
    ];
    return $badges[$status] ?? ['bg-gray-50', 'text-gray-700', 'border-gray-200', 'fa-question', ucfirst($status), ucfirst($status)];
}

function getTimelineSteps($order) {
    $steps = [
        'order_placed' => ['label' => 'Order Placed', 'icon' => 'fa-shopping-cart', 'date' => $order['order_date']],
        'confirmed' => ['label' => 'Confirmed', 'icon' => 'fa-check-circle', 'date' => $order['confirmed_at']],
        'claimed' => ['label' => 'Claimed', 'icon' => 'fa-hand-peace', 'date' => $order['claimed_at']],
        'completed' => ['label' => 'Completed', 'icon' => 'fa-star', 'date' => $order['claimed_at']]
    ];

    if ($order['order_status'] == 'cancelled') {
        return [];
    }

    $activeSteps = [];
    foreach ($steps as $key => $step) {
        if ($step['date']) {
            $activeSteps[$key] = $step;
        } elseif ($order['order_status'] == 'pending' && $key == 'order_placed') {
            $activeSteps[$key] = $step;
        } elseif ($order['order_status'] == 'confirmed' && ($key == 'order_placed' || $key == 'confirmed')) {
            $activeSteps[$key] = $step;
            if ($key == 'confirmed') $step['date'] = 'Processing...';
        }
    }
    return $activeSteps;
}

$counts = [
    'all' => count($orders),
    'pending' => 0,
    'confirmed' => 0,
    'claimed' => 0
];

foreach ($orders as $order) {
    if ($order['order_status'] == 'pending') $counts['pending']++;
    if ($order['order_status'] == 'confirmed') $counts['confirmed']++;
    if ($order['order_status'] == 'claimed') $counts['claimed']++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders - BISU IGE Aquaculture</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                        display: ['Playfair Display', 'serif'],
                    },
                    colors: {
                        brand: {
                            50: '#f0f9ff',
                            100: '#e0f2fe',
                            200: '#bae6fd',
                            300: '#7dd3fc',
                            400: '#38bdf8',
                            500: '#0ea5e9',
                            600: '#0284c7',
                            700: '#0369a1',
                            800: '#075985',
                            900: '#0c4a6e',
                        },
                    }
                }
            }
        }
    </script>
    <style>
        * { font-family: 'Inter', sans-serif; }
        h1, h2, h3, .font-display { font-family: 'Playfair Display', serif; }
        
        body { background-color: #f8fafc; }
        
        .transition-smooth { transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); }
        
        .pro-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .pro-card:hover {
            box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.08);
            border-color: #cbd5e1;
        }
        
        .order-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .order-card:hover {
            box-shadow: 0 8px 30px -4px rgba(0, 0, 0, 0.1);
            border-color: #cbd5e1;
        }
        
        .btn-brand {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.875rem;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-brand:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(14, 165, 233, 0.3); }
        
        .btn-claim {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.875rem;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-claim:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3); }
        
        .btn-outline {
            background: transparent;
            color: #475569;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.875rem;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-outline:hover { background: #f8fafc; border-color: #cbd5e1; color: #0f172a; }
        
        .btn-danger {
            background: #fef2f2;
            color: #dc2626;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.875rem;
            border: 1px solid #fecaca;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-danger:hover { background: #dc2626; color: white; border-color: #dc2626; }
        
        .badge-pro {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.6875rem;
            font-weight: 600;
            border: 1px solid;
        }
        
        .filter-tab {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s ease;
            background: white;
            color: #475569;
            border: 1px solid #e2e8f0;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .filter-tab:hover { background: #f8fafc; border-color: #cbd5e1; color: #0f172a; }
        
        .filter-tab.active {
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
            color: white;
            border-color: transparent;
        }
        
        .filter-count {
            background: #f1f5f9;
            border-radius: 9999px;
            padding: 0.125rem 0.5rem;
            font-size: 0.6875rem;
            font-weight: 700;
        }
        
        .filter-tab.active .filter-count { background: rgba(255,255,255,0.2); color: white; }
        
        .hero-section {
            background: linear-gradient(135deg, #0c4a6e 0%, #075985 50%, #0369a1 100%);
            position: relative;
            overflow: hidden;
        }
        
        .hero-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(255,255,255,0.06) 0%, transparent 70%);
            border-radius: 50%;
        }
        
        .info-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 0.75rem;
            transition: all 0.2s ease;
        }
        
        .info-card:hover { border-color: #cbd5e1; }
        
        .timeline-step {
            position: relative;
            flex: 1;
            text-align: center;
        }
        
        .timeline-step:not(:last-child):before {
            content: '';
            position: absolute;
            top: 16px;
            left: 50%;
            width: 100%;
            height: 2px;
            background: #e2e8f0;
            z-index: 0;
        }
        
        .timeline-icon {
            width: 32px;
            height: 32px;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 6px;
            position: relative;
            z-index: 1;
            font-size: 0.75rem;
        }
        
        .timeline-step.completed .timeline-icon { background: #059669; border-color: #059669; color: white; }
        .timeline-step.active .timeline-icon { background: #0ea5e9; border-color: #0ea5e9; color: white; transform: scale(1.1); }
        
        .flash-msg {
            padding: 1rem;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            animation: slideDown 0.3s ease;
            border: 1px solid;
            background: white;
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-12px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .empty-icon {
            width: 5rem;
            height: 5rem;
            background: #f1f5f9;
            border-radius: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            color: #94a3b8;
            font-size: 2rem;
        }
        
        ::-webkit-scrollbar { width: 5px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>

    <?php if ($message): ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-6">
        <div class="flash-msg shadow-sm" style="border-left-color: <?php echo $messageType == 'success' ? '#10b981' : '#ef4444'; ?>; border-left-width: 4px;">
            <div class="flex items-center gap-3">
                <div class="w-7 h-7 rounded-lg flex items-center justify-center <?php echo $messageType == 'success' ? 'bg-green-50 text-green-600' : 'bg-red-50 text-red-600'; ?>">
                    <i class="fas <?php echo $messageType == 'success' ? 'fa-check' : 'fa-exclamation'; ?> text-xs"></i>
                </div>
                <p class="text-sm font-medium text-gray-700"><?php echo htmlspecialchars($message); ?></p>
            </div>
            <button onclick="this.parentElement.remove()" class="text-gray-400 hover:text-gray-600 w-6 h-6 flex items-center justify-center rounded-lg hover:bg-gray-50 transition">
                <i class="fas fa-times text-xs"></i>
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Hero Section -->
    <div class="hero-section py-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-6">
                <div>
                    <p class="text-brand-300 text-sm font-medium tracking-wide uppercase mb-2">Orders</p>
                    <h1 class="text-2xl md:text-3xl font-bold text-white font-display">My Orders</h1>
                    <p class="text-brand-200/80 mt-2 text-sm">Track and manage your fish orders. View order details and status updates.</p>
                </div>
                <a href="products.php" class="btn-outline" style="border-color: rgba(255,255,255,0.2); color: white; background: rgba(255,255,255,0.1);">
                    <i class="fas fa-fish text-sm"></i> Continue Shopping
                </a>
            </div>
        </div>
    </div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

        <!-- Filter Tabs -->
        <div class="mb-6">
            <div class="flex flex-wrap gap-2">
                <a href="?status=all" class="filter-tab <?php echo $statusFilter == 'all' ? 'active' : ''; ?>">
                    <i class="fas fa-list text-xs"></i> All Orders
                    <span class="filter-count ml-1.5"><?php echo $counts['all']; ?></span>
                </a>
                <a href="?status=pending" class="filter-tab <?php echo $statusFilter == 'pending' ? 'active' : ''; ?>">
                    <i class="fas fa-clock text-xs"></i> Pending
                    <span class="filter-count ml-1.5"><?php echo $counts['pending']; ?></span>
                </a>
                <a href="?status=confirmed" class="filter-tab <?php echo $statusFilter == 'confirmed' ? 'active' : ''; ?>">
                    <i class="fas fa-check-circle text-xs"></i> Confirmed
                    <span class="filter-count ml-1.5"><?php echo $counts['confirmed']; ?></span>
                </a>
                <a href="?status=claimed" class="filter-tab <?php echo $statusFilter == 'claimed' ? 'active' : ''; ?>">
                    <i class="fas fa-hand-peace text-xs"></i> Claimed
                    <span class="filter-count ml-1.5"><?php echo $counts['claimed']; ?></span>
                </a>
            </div>
        </div>

        <?php if (empty($orders)): ?>
            <div class="pro-card p-12 text-center">
                <div class="empty-icon"><i class="fas fa-shopping-bag"></i></div>
                <h3 class="text-base font-semibold text-gray-800 mb-1">No orders yet</h3>
                <p class="text-sm text-gray-500 mb-5">You haven't placed any orders yet.</p>
                <a href="products.php" class="btn-brand"><i class="fas fa-fish"></i> Browse Products</a>
            </div>
        <?php else: ?>
            <div class="space-y-5">
                <?php foreach ($orders as $order): 
                    list($bgColor, $textColor, $borderColor, $icon, $statusText, $statusDesc) = getOrderStatusBadge($order['order_status']);
                    $timelineSteps = getTimelineSteps($order);
                ?>
                    <div class="order-card">
                        <div class="px-5 py-3 border-b border-gray-100 bg-gray-50/50">
                            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                                <div class="flex items-center gap-4 flex-wrap">
                                    <div class="flex items-center gap-2">
                                        <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-brand-500 to-brand-700 flex items-center justify-center">
                                            <i class="fas fa-hashtag text-white text-xs"></i>
                                        </div>
                                        <div>
                                            <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider">Order Number</p>
                                            <p class="font-bold text-gray-800 text-sm">#<?php echo str_pad($order['order_id'], 6, '0', STR_PAD_LEFT); ?></p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2 text-xs">
                                        <i class="far fa-calendar-alt text-gray-400"></i>
                                        <div>
                                            <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider">Order Date</p>
                                            <p class="font-medium text-gray-700"><?php echo date('M d, Y', strtotime($order['order_date'])); ?></p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2 text-xs">
                                        <i class="fas fa-weight-hanging text-gray-400"></i>
                                        <div>
                                            <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider">Quantity</p>
                                            <p class="font-medium text-gray-700"><?php echo number_format($order['total_quantity'] ?? $order['item_count'], 1); ?> kg</p>
                                        </div>
                                    </div>
                                </div>
                                <span class="badge-pro <?php echo $bgColor . ' ' . $textColor . ' ' . $borderColor; ?>">
                                    <i class="fas <?php echo $icon; ?> text-[8px]"></i>
                                    <?php echo $statusText; ?>
                                </span>
                            </div>
                        </div>

                        <div class="p-5">
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
                                <div class="info-card">
                                    <div class="flex items-center gap-2 mb-1">
                                        <div class="w-6 h-6 rounded-lg bg-emerald-50 flex items-center justify-center text-emerald-600">
                                            <i class="fas fa-wallet text-[9px]"></i>
                                        </div>
                                        <span class="text-[9px] font-semibold text-gray-400 uppercase tracking-wider">Payment</span>
                                    </div>
                                    <p class="font-semibold text-gray-800 text-sm capitalize">
                                        <?php echo str_replace('_', ' ', $order['payment_method'] == 'salary_deduction' ? 'Salary Deduction' : $order['payment_method']); ?>
                                    </p>
                                </div>
                                <div class="info-card">
                                    <div class="flex items-center gap-2 mb-1">
                                        <div class="w-6 h-6 rounded-lg bg-amber-50 flex items-center justify-center text-amber-600">
                                            <i class="fas fa-tag text-[9px]"></i>
                                        </div>
                                        <span class="text-[9px] font-semibold text-gray-400 uppercase tracking-wider">Status</span>
                                    </div>
                                    <p class="font-semibold text-gray-800 text-sm"><?php echo $statusDesc; ?></p>
                                </div>
                                <div class="info-card">
                                    <div class="flex items-center gap-2 mb-1">
                                        <div class="w-6 h-6 rounded-lg bg-brand-50 flex items-center justify-center text-brand-600">
                                            <i class="fas fa-weight-hanging text-[9px]"></i>
                                        </div>
                                        <span class="text-[9px] font-semibold text-gray-400 uppercase tracking-wider">Total Qty</span>
                                    </div>
                                    <p class="font-semibold text-gray-800 text-sm"><?php echo number_format($order['total_quantity'] ?? $order['item_count'], 2); ?> kg</p>
                                </div>
                                <div class="info-card" style="background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%); border: none;">
                                    <div class="flex items-center gap-2 mb-1">
                                        <div class="w-6 h-6 rounded-lg bg-white/15 flex items-center justify-center text-white">
                                            <i class="fas fa-coins text-[9px]"></i>
                                        </div>
                                        <span class="text-[9px] font-semibold text-brand-100 uppercase tracking-wider">Total Amount</span>
                                    </div>
                                    <p class="font-bold text-white text-base">₱<?php echo number_format($order['total_amount'], 2); ?></p>
                                </div>
                            </div>

                            <!-- Timeline -->
                            <?php if ($order['order_status'] != 'cancelled' && !empty($timelineSteps)): ?>
                                <div class="bg-gray-50 rounded-xl p-4 mb-4">
                                    <div class="flex items-center gap-2 mb-3">
                                        <div class="w-6 h-6 rounded-lg bg-brand-50 flex items-center justify-center text-brand-600">
                                            <i class="fas fa-chart-line text-[9px]"></i>
                                        </div>
                                        <p class="text-[9px] font-semibold text-gray-500 uppercase tracking-wider">Order Timeline</p>
                                    </div>
                                    <div class="flex justify-between items-start px-2">
                                        <?php 
                                        $stepIndex = 0;
                                        $totalSteps = count($timelineSteps);
                                        foreach ($timelineSteps as $key => $step):
                                            $isCompleted = !empty($step['date']) && $step['date'] != 'Processing...';
                                            $isActive = ($key == 'confirmed' && $order['order_status'] == 'confirmed');
                                            $stepClass = $isCompleted ? 'completed' : ($isActive ? 'active' : 'pending');
                                        ?>
                                            <div class="timeline-step <?php echo $stepClass; ?>">
                                                <div class="timeline-icon">
                                                    <i class="fas <?php echo $step['icon']; ?> text-[10px]"></i>
                                                </div>
                                                <p class="text-[10px] font-semibold text-gray-700"><?php echo $step['label']; ?></p>
                                                <p class="text-[9px] text-gray-400 mt-0.5">
                                                    <?php 
                                                    if ($step['date'] && $step['date'] != 'Processing...') {
                                                        echo date('M d', strtotime($step['date']));
                                                    } elseif ($step['date'] == 'Processing...') {
                                                        echo '<i class="fas fa-spinner fa-pulse"></i>';
                                                    } else {
                                                        echo 'Pending';
                                                    }
                                                    ?>
                                                </p>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Remarks -->
                            <?php if (!empty($order['remarks'])): ?>
                                <div class="bg-amber-50 rounded-xl p-3 mb-4 border border-amber-100">
                                    <div class="flex items-start gap-2">
                                        <div class="w-6 h-6 rounded-lg bg-amber-100 flex items-center justify-center flex-shrink-0">
                                            <i class="fas fa-comment-dots text-amber-600 text-[9px]"></i>
                                        </div>
                                        <div>
                                            <p class="text-[9px] font-semibold text-amber-700 uppercase tracking-wider">Remarks</p>
                                            <p class="text-xs text-gray-700 mt-1 leading-relaxed"><?php echo htmlspecialchars($order['remarks']); ?></p>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Actions -->
                            <div class="flex flex-wrap justify-end gap-2 pt-3 border-t border-gray-100">
                                <a href="order_details.php?id=<?php echo $order['order_id']; ?>" class="btn-outline text-sm py-1.5">
                                    <i class="fas fa-eye text-xs"></i> View Details
                                </a>
                                
                                <?php if ($order['order_status'] == 'pending'): ?>
                                    <button onclick="cancelOrder(<?php echo $order['order_id']; ?>)" class="btn-danger text-sm py-1.5">
                                        <i class="fas fa-times-circle text-xs"></i> Cancel Order
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($order['order_status'] == 'confirmed'): ?>
                                    <button onclick="markAsClaimed(<?php echo $order['order_id']; ?>)" class="btn-claim text-sm py-1.5">
                                        <i class="fas fa-hand-peace text-xs"></i> Mark as Claimed
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($order['order_status'] == 'claimed'): ?>
                                    <span class="text-xs text-green-600 font-medium bg-green-50 px-3 py-1.5 rounded-full">
                                        <i class="fas fa-check-circle mr-1"></i> Order Completed
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
        function cancelOrder(orderId) {
            if (confirm('Are you sure you want to cancel this order? This action cannot be undone.')) {
                const btn = event.target.closest('button');
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-pulse"></i> Cancelling...';
                btn.disabled = true;

                fetch('../api/cancel_order.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'order_id=' + orderId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Order cancelled successfully!', 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showNotification(data.message || 'Failed to cancel order', 'error');
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                    }
                })
                .catch(() => {
                    showNotification('An error occurred. Please try again.', 'error');
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                });
            }
        }

        function markAsClaimed(orderId) {
            if (confirm('Have you received and claimed this order?\n\nOnce claimed, you will not be able to cancel this order.')) {
                const btn = event.target.closest('button');
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-pulse"></i> Processing...';
                btn.disabled = true;

                fetch('../api/claim_order.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'order_id=' + orderId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Order claimed successfully! Thank you for your purchase.', 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showNotification(data.message || 'Failed to claim order', 'error');
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                    }
                })
                .catch(() => {
                    showNotification('An error occurred. Please try again.', 'error');
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                });
            }
        }

        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `fixed top-20 right-4 z-50 p-3 rounded-lg shadow-lg transition-all transform translate-x-0 ${
                type === 'success' ? 'bg-green-500' : 'bg-red-500'
            } text-white`;
            notification.innerHTML = `
                <div class="flex items-center gap-2">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'} text-sm"></i>
                    <span class="text-xs font-medium">${message}</span>
                </div>
            `;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.opacity = '0';
                notification.style.transform = 'translateX(100px)';
                setTimeout(() => notification.remove(), 300);
            }, 4000);
        }

        setTimeout(() => {
            document.querySelectorAll('.flash-msg').forEach(msg => {
                msg.style.transition = 'all 0.4s ease';
                msg.style.opacity = '0';
                msg.style.transform = 'translateY(-8px)';
                setTimeout(() => msg.remove(), 400);
            });
        }, 5000);
    </script>
</body>
</html>