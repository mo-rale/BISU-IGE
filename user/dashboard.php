<?php
// user/dashboard.php - Professional Dashboard UI
require_once '../includes/config.php';
require_once '../includes/session.php';

// Only allow standard users
SessionManager::requireStandard();

$functions = new SystemFunctions();
$userId = SessionManager::getUserId();

$user = $functions->getUserById($userId);
$stats = $functions->getUserOrderStats($userId);
$recentOrders = $functions->getUserOrders($userId, 5);
$GLOBALS['sql_errors'] = [];

// Get harvests directly from harvest table
// Get harvests directly from harvest table - FULL FIXED VERSION
try {
    $db = (new Database())->getConnection();
    
    // Check if harvest table exists
    $tableCheck = $db->query("SELECT to_regclass('public.harvest')");
    $harvestTableExists = $tableCheck && $tableCheck->fetchColumn();
    
    if (!$harvestTableExists) {
        $recentHarvests = [];
    } else {
        // Query to get unique latest harvests with their linked product info
        $harvestsSql = "SELECT DISTINCT
                        h.harvest_id,
                        h.batch_no,
                        h.harvest_date,
                        h.location,
                        h.total_quantity,
                        h.remaining_quantity,
                        h.status,
                        h.created_at,
                        h.updated_at,
                        h.fish_product_id,
                        fp.fish_name,
                        fp.price_per_kg,
                        fp.description
                    FROM harvest h
                    LEFT JOIN fish_products fp ON fp.product_id = h.fish_product_id
                    WHERE h.status = 'active'
                    ORDER BY 
                        h.harvest_date DESC,
                        h.created_at DESC
                    LIMIT 5";
        
        $harvestsStmt = $db->prepare($harvestsSql);
        $harvestsStmt->execute();
        $harvestsResult = $harvestsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Remove any potential duplicates by harvest_id
        $uniqueHarvests = [];
        foreach ($harvestsResult as $harvest) {
            if (!isset($uniqueHarvests[$harvest['harvest_id']])) {
                $uniqueHarvests[$harvest['harvest_id']] = $harvest;
            }
        }
        
        // Limit to 2 harvests and reset array keys
        $recentHarvests = array_slice(array_values($uniqueHarvests), 0, 2);
        
        // Process each harvest
        foreach ($recentHarvests as &$harvest) {
            // Get fish name from either linked product or extract from batch number
            if (!empty($harvest['fish_name'])) {
                $harvest['display_name'] = $harvest['fish_name'];
            } elseif (!empty($harvest['batch_no'])) {
                $batchParts = explode('-', $harvest['batch_no']);
                $fishType = strtolower($batchParts[0]);
                $fishNames = [
                    'tilapia' => 'Tilapia',
                    'bangus' => 'Bangus',
                    'milkfish' => 'Bangus',
                    'shrimp' => 'Shrimp',
                    'crab' => 'Crab',
                    'grouper' => 'Grouper',
                    'mackerel' => 'Mackerel',
                    'catfish' => 'Catfish',
                    'carp' => 'Carp',
                    'tuna' => 'Tuna'
                ];
                $harvest['display_name'] = $fishNames[$fishType] ?? ucfirst($batchParts[0]);
            } else {
                $harvest['display_name'] = 'Fresh Fish';
            }
            
            // Process harvest date
            if (!empty($harvest['harvest_date'])) {
                try {
                    $harvest_date = new DateTime($harvest['harvest_date']);
                    $today = new DateTime();

                    if ($harvest_date > $today) {
                        $interval = $today->diff($harvest_date);
                        $harvest['days_until'] = $interval->days;
                        $harvest['is_upcoming'] = true;

                        if ($harvest['days_until'] <= 3) {
                            $harvest['urgency'] = 'Very Soon';
                            $harvest['urgency_color'] = 'red';
                        } elseif ($harvest['days_until'] <= 7) {
                            $harvest['urgency'] = 'This Week';
                            $harvest['urgency_color'] = 'orange';
                        } elseif ($harvest['days_until'] <= 14) {
                            $harvest['urgency'] = 'Coming Soon';
                            $harvest['urgency_color'] = 'yellow';
                        } else {
                            $harvest['urgency'] = 'Upcoming';
                            $harvest['urgency_color'] = 'blue';
                        }
                    } else {
                        $harvest['is_upcoming'] = false;
                        $harvest['days_ago'] = $today->diff($harvest_date)->days;
                    }

                    $harvest['formatted_harvest_date'] = date('M d, Y', strtotime($harvest['harvest_date']));
                } catch (Exception $e) {
                    $harvest['formatted_harvest_date'] = date('M d, Y', strtotime($harvest['harvest_date']));
                    $harvest['is_upcoming'] = false;
                    $harvest['days_ago'] = 0;
                }
            } else {
                $harvest['formatted_harvest_date'] = 'Date not set';
                $harvest['is_upcoming'] = false;
                $harvest['days_ago'] = 0;
            }

            // Calculate available percentage
            if ($harvest['total_quantity'] > 0) {
                $harvest['available_percentage'] = round(($harvest['remaining_quantity'] / $harvest['total_quantity']) * 100);
            } else {
                $harvest['available_percentage'] = 0;
            }

            // Determine stock status
            if ($harvest['remaining_quantity'] <= 0) {
                $harvest['stock_status'] = 'Sold Out';
                $harvest['stock_color'] = 'red';
            } elseif ($harvest['remaining_quantity'] < $harvest['total_quantity'] * 0.2) {
                $harvest['stock_status'] = 'Limited Stock';
                $harvest['stock_color'] = 'orange';
            } else {
                $harvest['stock_status'] = 'In Stock';
                $harvest['stock_color'] = 'green';
            }
        }
        unset($harvest); // Break reference
    }

} catch (PDOException $e) {
    error_log("Dashboard harvests query error: " . $e->getMessage());
    $recentHarvests = [];
} catch (Exception $e) {
    error_log("Dashboard harvests processing error: " . $e->getMessage());
    $recentHarvests = [];
}

// Ensure $recentHarvests is always an array
if (!isset($recentHarvests) || !is_array($recentHarvests)) {
    $recentHarvests = [];
}

// Get return requests
$recentReturns = [];
$approvedReturnsCount = 0;
try {
    $db = (new Database())->getConnection();

    $tableCheck = $db->query("SELECT to_regclass('public.return_requests')");
    $tableExists = $tableCheck && $tableCheck->fetchColumn();

    if ($tableExists) {
        $approvedCountSql = "SELECT COUNT(*) as approved_count FROM return_requests WHERE user_id = :user_id AND return_status = 'approved'";
        $approvedCountStmt = $db->prepare($approvedCountSql);
        $approvedCountStmt->execute([':user_id' => $userId]);
        $approvedReturnsCount = $approvedCountStmt->fetch(PDO::FETCH_ASSOC)['approved_count'] ?? 0;

        $returnsSql = "SELECT rr.*, o.order_id, o.total_amount as order_total, o.order_date,
                              fp.fish_name as fish_type
                       FROM return_requests rr
                       LEFT JOIN orders o ON rr.order_id = o.order_id
                       LEFT JOIN order_items oi ON o.order_id = oi.order_id
                       LEFT JOIN fish_products fp ON oi.product_id = fp.product_id
                       WHERE rr.user_id = :user_id
                       ORDER BY rr.created_at DESC
                       LIMIT 3";
        $returnsStmt = $db->prepare($returnsSql);
        $returnsStmt->execute([':user_id' => $userId]);
        $recentReturns = $returnsStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("Dashboard returns error: " . $e->getMessage());
}

// Get total paid from deduction_history
$totalPaid = 0;
$totalPendingBalance = 0;
$totalOrders = 0;
$activeDeductionsCount = 0;

try {
    $db = (new Database())->getConnection();

    $checkTable = $db->query("SELECT to_regclass('public.salary_deductions')");
    $tableExists = $checkTable && $checkTable->fetchColumn();

    if ($tableExists) {
        $paidSql = "SELECT COALESCE(SUM(dh.amount_deducted), 0) as total_paid
                    FROM deduction_history dh
                    JOIN salary_deductions sd ON dh.deduction_id = sd.deduction_id
                    WHERE sd.user_id = :user_id";
        $paidStmt = $db->prepare($paidSql);
        $paidStmt->execute([':user_id' => $userId]);
        $totalPaid = $paidStmt->fetch(PDO::FETCH_ASSOC)['total_paid'] ?? 0;

        $balanceSql = "SELECT 
                            COALESCE(SUM(
                                CASE 
                                    WHEN sd.deduction_status IN ('pending', 'partial', 'active') 
                                    THEN sd.remaining_balance 
                                    ELSE 0 
                                END
                            ), 0) as total_balance,
                            COUNT(CASE 
                                WHEN sd.deduction_status IN ('pending', 'partial', 'active') 
                                THEN 1 
                            END) as active_count
                       FROM salary_deductions sd
                       WHERE sd.user_id = :user_id
                       AND sd.deduction_id IN (
                           SELECT MAX(deduction_id) 
                           FROM salary_deductions 
                           WHERE deduction_status IN ('pending', 'partial', 'active', 'completed')
                           GROUP BY order_id
                       )";
        $balanceStmt = $db->prepare($balanceSql);
        $balanceStmt->execute([':user_id' => $userId]);
        $balanceResult = $balanceStmt->fetch(PDO::FETCH_ASSOC);
        $totalPendingBalance = $balanceResult['total_balance'] ?? 0;
        $activeDeductionsCount = $balanceResult['active_count'] ?? 0;

        $ordersSql = "SELECT COUNT(DISTINCT sd.order_id) as total_orders
                      FROM salary_deductions sd
                      WHERE sd.user_id = :user_id
                      AND EXISTS (SELECT 1 FROM deduction_history dh WHERE dh.deduction_id = sd.deduction_id)";
        $ordersStmt = $db->prepare($ordersSql);
        $ordersStmt->execute([':user_id' => $userId]);
        $totalOrders = $ordersStmt->fetch(PDO::FETCH_ASSOC)['total_orders'] ?? 0;
    }

} catch (PDOException $e) {
    error_log("Dashboard total paid error: " . $e->getMessage());
}

// Get order counts
try {
    $db = (new Database())->getConnection();

    $countSql = "SELECT 
                    COUNT(CASE WHEN order_status = 'pending' THEN 1 END) as pending_count,
                    COUNT(CASE WHEN order_status = 'confirmed' THEN 1 END) as confirmed_count,
                    COUNT(CASE WHEN order_status = 'claimed' THEN 1 END) as claimed_count,
                    COUNT(CASE WHEN order_status IN ('processing', 'ready_for_pickup') THEN 1 END) as processing_count
                 FROM orders 
                 WHERE user_id = :user_id";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute([':user_id' => $userId]);
    $orderCounts = $countStmt->fetch(PDO::FETCH_ASSOC);

    $pendingCount = $orderCounts['pending_count'] ?? 0;
    $confirmedCount = $orderCounts['confirmed_count'] ?? 0;
    $claimedCount = $orderCounts['claimed_count'] ?? 0;
    $processingCount = $orderCounts['processing_count'] ?? 0;

} catch (PDOException $e) {
    error_log("Dashboard counts error: " . $e->getMessage());
    $pendingCount = $stats['orders']['pending_count'] ?? 0;
    $confirmedCount = $stats['orders']['confirmed_count'] ?? 0;
    $claimedCount = $stats['orders']['completed_count'] ?? 0;
    $processingCount = ($stats['orders']['processing_count'] ?? 0) + ($stats['orders']['ready_count'] ?? 0);
}

$hour = date('G');
if ($hour < 12) {
    $greeting = 'Welcome back';
} elseif ($hour < 18) {
    $greeting = 'Welcome back';
} else {
    $greeting = 'Welcome back';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - BISU IGE Aquaculture</title>
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
                        slate: {
                            850: '#1e293b',
                            950: '#020617',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        :root {
            --bg-primary: #f8fafc;
            --bg-secondary: #ffffff;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --border-subtle: #f1f5f9;
            --brand: #0ea5e9;
            --brand-dark: #0284c7;
            --success: #059669;
            --success-bg: #ecfdf5;
            --warning: '#d97706',
            --warning-bg: '#fffbeb',
            --danger: '#dc2626',
            --danger-bg: '#fef2f2',
        }

        body {
            background-color: var(--bg-primary);
            font-family: 'Inter', system-ui, sans-serif;
            color: var(--text-primary);
            min-height: 100vh;
        }

        /* Smooth transitions */
        .transition-smooth {
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Professional Card */
        .pro-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 12px;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .pro-card:hover {
            box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.08);
            border-color: #cbd5e1;
        }

        /* Stat Card */
        .stat-card-pro {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.5rem;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .stat-card-pro::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--brand) 0%, var(--brand-dark) 100%);
            opacity: 0;
            transition: opacity 0.25s ease;
        }

        .stat-card-pro:hover {
            box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.08);
            transform: translateY(-2px);
        }

        .stat-card-pro:hover::before {
            opacity: 1;
        }

        /* Quick Action Button */
        .quick-action-pro {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 1.25rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            text-decoration: none;
            position: relative;
            overflow: hidden;
        }

        .quick-action-pro::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
            opacity: 0;
            transition: opacity 0.25s ease;
            z-index: 0;
        }

        .quick-action-pro:hover {
            border-color: var(--brand);
            box-shadow: 0 4px 20px -2px rgba(14, 165, 233, 0.15);
        }

        .quick-action-pro:hover::after {
            opacity: 1;
        }

        .quick-action-pro:hover .qa-icon,
        .quick-action-pro:hover .qa-title,
        .quick-action-pro:hover .qa-desc {
            color: white;
            position: relative;
            z-index: 1;
        }

        .quick-action-pro:hover .qa-icon {
            background: rgba(255, 255, 255, 0.2) !important;
        }

        .qa-icon {
            width: 2.75rem;
            height: 2.75rem;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.125rem;
            transition: all 0.25s ease;
            flex-shrink: 0;
            position: relative;
            z-index: 1;
        }

        .qa-title {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.9375rem;
            position: relative;
            z-index: 1;
            transition: color 0.25s ease;
        }

        .qa-desc {
            color: var(--text-muted);
            font-size: 0.8125rem;
            position: relative;
            z-index: 1;
            transition: color 0.25s ease;
        }

        /* Order Item */
        .order-row {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 1.25rem;
            margin-bottom: 0.75rem;
            transition: all 0.2s ease;
        }

        .order-row:hover {
            border-color: #cbd5e1;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        /* Status Badge */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.875rem;
            border-radius: 9999px;
            font-size: 0.6875rem;
            font-weight: 600;
            letter-spacing: 0.025em;
            text-transform: uppercase;
        }

        .badge-pending { background: #fffbeb; color: #92400e; border: 1px solid #fef3c7; }
        .badge-confirmed { background: #ecfdf5; color: #065f46; border: 1px solid #d1fae5; }
        .badge-processing { background: #f0f9ff; color: #075985; border: 1px solid #e0f2fe; }
        .badge-completed { background: #f0fdf4; color: #166534; border: 1px solid #dcfce7; }
        .badge-cancelled { background: #fef2f2; color: #991b1b; border: 1px solid #fee2e2; }

        /* Announcement Card */
        .announcement-card {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
            border: 1px solid #fbbf24;
        }

        .announcement-card::before {
            content: '🎉';
            position: absolute;
            bottom: -10px;
            right: -10px;
            font-size: 80px;
            opacity: 0.15;
            transform: rotate(-15deg);
        }

        .harvest-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 1.25rem;
            margin-bottom: 0.75rem;
            transition: all 0.2s ease;
            position: relative;
        }

        .harvest-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 1rem;
            bottom: 1rem;
            width: 3px;
            border-radius: 0 3px 3px 0;
            background: var(--brand);
        }

        .harvest-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        /* Progress Bar */
        .progress-track {
            height: 6px;
            border-radius: 3px;
            background: #f1f5f9;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.4s ease;
        }

        /* Return Card */
        .return-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 1.25rem;
            margin-bottom: 0.75rem;
            transition: all 0.2s ease;
            position: relative;
        }

        .return-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 1rem;
            bottom: 1rem;
            width: 3px;
            border-radius: 0 3px 3px 0;
            background: #ef4444;
        }

        .return-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        /* Buttons */
        .btn-brand {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
            color: white;
            padding: 0.625rem 1.25rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.875rem;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(14, 165, 233, 0.2);
        }

        .btn-brand:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(14, 165, 233, 0.3);
            color: white;
        }

        .btn-ghost {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: transparent;
            color: #475569;
            padding: 0.625rem 1.25rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.875rem;
            text-decoration: none;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-ghost:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #0f172a;
        }

        /* Section Header */
        .section-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }

        .section-icon {
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
        }

        /* Flash Message */
        .flash-msg {
            padding: 1rem 1.25rem;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            animation: slideDown 0.3s ease;
            border: 1px solid;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-12px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Hero Section */
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

        .hero-section::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -5%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(255,255,255,0.04) 0%, transparent 70%);
            border-radius: 50%;
        }

        /* Empty State */
        .empty-state-pro {
            text-align: center;
            padding: 3rem 1.5rem;
        }

        .empty-icon {
            width: 4rem;
            height: 4rem;
            background: #f1f5f9;
            border-radius: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.25rem;
            color: #94a3b8;
            font-size: 1.5rem;
        }

        /* Link Arrow */
        .link-arrow {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            font-size: 0.8125rem;
            font-weight: 500;
            color: var(--brand);
            text-decoration: none;
            transition: gap 0.2s ease;
        }

        .link-arrow:hover {
            gap: 0.625rem;
            color: var(--brand-dark);
        }

        /* Divider */
        .divider {
            height: 1px;
            background: linear-gradient(90deg, transparent 0%, #e2e8f0 50%, transparent 100%);
        }

        /* Scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
        }
        ::-webkit-scrollbar-track {
            background: transparent;
        }
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
    </style>
</head>
<body class="antialiased">
    <?php include '../includes/navbar.php'; ?>

    <?php if ($message = SessionManager::getMessage()): ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-6">
        <div class="flash-msg bg-white shadow-sm" style="border-color: <?php echo $message['type'] == 'success' ? '#d1fae5' : '#fee2e2'; ?>">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center <?php echo $message['type'] == 'success' ? 'bg-green-50 text-green-600' : 'bg-red-50 text-red-600'; ?>">
                    <i class="fas <?php echo $message['type'] == 'success' ? 'fa-check' : 'fa-exclamation'; ?> text-sm"></i>
                </div>
                <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($message['message']); ?></p>
            </div>
            <button onclick="this.parentElement.remove()" class="text-gray-400 hover:text-gray-600 w-6 h-6 flex items-center justify-center rounded-lg hover:bg-gray-50 transition-colors">
                <i class="fas fa-times text-xs"></i>
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Hero Section -->
    <div class="hero-section py-14">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-6">
                <div>
                    <p class="text-brand-300 text-sm font-medium tracking-wide uppercase mb-2">Dashboard</p>
                    <h1 class="text-3xl md:text-4xl font-bold text-white font-display">
                        <?php echo $greeting; ?>, <span class="text-brand-300"><?php echo htmlspecialchars($user['full_name'] ?? $user['first_name'] ?? 'User'); ?></span>
                    </h1>
                    <p class="text-brand-200/80 mt-2 text-sm max-w-md">Manage your orders, track harvests, and monitor your payment history all in one place.</p>
                </div>
                <div class="flex gap-3">
                    <a href="products.php" class="btn-brand">
                        <i class="fas fa-fish text-sm"></i>
                        Shop Now
                    </a>
                    <a href="orders.php" class="btn-ghost" style="border-color: rgba(255,255,255,0.2); color: white;">
                        <i class="fas fa-shopping-bag text-sm"></i>
                        My Orders
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

        <!-- Announcement Card - Latest Harvest Fish Names -->
        <?php if (!empty($recentHarvests)): 
            $announcementHarvest = $recentHarvests[0];
            $priceDisplay = !empty($announcementHarvest['price_per_kg']) ? '₱' . number_format($announcementHarvest['price_per_kg'], 2) . '/kg' : '';
        ?>
        <div class="announcement-card">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="flex-1">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="bg-amber-700 text-white text-xs font-bold px-2 py-0.5 rounded-full">NEW HARVEST</span>
                        <span class="text-amber-700 text-xs font-semibold">✨ Just In</span>
                    </div>
                    <h2 class="text-xl md:text-2xl font-bold text-amber-900 mb-1">
                        <?php echo htmlspecialchars($announcementHarvest['display_name']); ?> is Here!
                    </h2>
                    <p class="text-amber-800 text-sm mb-2">
                        <?php 
                        if (!empty($announcementHarvest['description'])) {
                            echo htmlspecialchars($announcementHarvest['description']);
                        } else {
                            echo "Fresh from our latest harvest! Premium quality " . htmlspecialchars($announcementHarvest['display_name']) . " now available.";
                        }
                        ?>
                    </p>
                    <?php if ($priceDisplay): ?>
                        <p class="text-amber-900 font-bold text-lg mb-3"><?php echo $priceDisplay; ?></p>
                    <?php endif; ?>
                    <div class="flex flex-wrap gap-3">
                        <a href="products.php" class="bg-amber-700 hover:bg-amber-800 text-white px-4 py-2 rounded-lg text-sm font-semibold transition-colors inline-flex items-center gap-2">
                            <i class="fas fa-shopping-cart"></i> Order Now
                        </a>
                        <?php if (!empty($announcementHarvest['remaining_quantity'])): ?>
                            <span class="bg-white/50 text-amber-800 px-3 py-2 rounded-lg text-xs font-medium inline-flex items-center gap-1">
                                <i class="fas fa-weight-hanging"></i> <?php echo number_format($announcementHarvest['remaining_quantity'], 1); ?> kg available
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="hidden md:block text-center">
                    <div class="w-20 h-20 bg-amber-200/50 rounded-full flex items-center justify-center mx-auto">
                        <i class="fas fa-fish text-4xl text-amber-700"></i>
                    </div>
                    <?php if (!empty($announcementHarvest['location'])): ?>
                        <p class="text-amber-700 text-xs mt-2">
                            <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($announcementHarvest['location']); ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
            <div class="stat-card-pro">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Pending</p>
                        <p class="text-2xl font-bold text-gray-900 mt-1"><?php echo $pendingCount; ?></p>
                    </div>
                    <div class="w-10 h-10 rounded-lg bg-amber-50 flex items-center justify-center text-amber-600">
                        <i class="fas fa-clock text-sm"></i>
                    </div>
                </div>
                <a href="orders.php?status=pending" class="link-arrow text-amber-600 hover:text-amber-700">
                    View Orders <i class="fas fa-arrow-right text-[10px]"></i>
                </a>
            </div>

            <div class="stat-card-pro">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Confirmed</p>
                        <p class="text-2xl font-bold text-gray-900 mt-1"><?php echo $confirmedCount; ?></p>
                    </div>
                    <div class="w-10 h-10 rounded-lg bg-emerald-50 flex items-center justify-center text-emerald-600">
                        <i class="fas fa-check-circle text-sm"></i>
                    </div>
                </div>
                <a href="orders.php?status=confirmed" class="link-arrow text-emerald-600 hover:text-emerald-700">
                    View Orders <i class="fas fa-arrow-right text-[10px]"></i>
                </a>
            </div>

            <div class="stat-card-pro">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Claimed</p>
                        <p class="text-2xl font-bold text-gray-900 mt-1"><?php echo $claimedCount; ?></p>
                    </div>
                    <div class="w-10 h-10 rounded-lg bg-violet-50 flex items-center justify-center text-violet-600">
                        <i class="fas fa-gift text-sm"></i>
                    </div>
                </div>
                <a href="orders.php?status=completed" class="link-arrow text-violet-600 hover:text-violet-700">
                    View Orders <i class="fas fa-arrow-right text-[10px]"></i>
                </a>
            </div>

            <div class="stat-card-pro">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Approved Returns</p>
                        <p class="text-2xl font-bold text-gray-900 mt-1"><?php echo $approvedReturnsCount; ?></p>
                    </div>
                    <div class="w-10 h-10 rounded-lg bg-emerald-50 flex items-center justify-center text-emerald-600">
                        <i class="fas fa-check-double text-sm"></i>
                    </div>
                </div>
                <a href="returns.php?status=approved" class="link-arrow text-emerald-600 hover:text-emerald-700">
                    View Returns <i class="fas fa-arrow-right text-[10px]"></i>
                </a>
            </div>

            <div class="stat-card-pro">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Total Paid</p>
                        <p class="text-2xl font-bold text-gray-900 mt-1">₱<?php echo number_format($totalPaid, 2); ?></p>
                    </div>
                    <div class="w-10 h-10 rounded-lg bg-brand-50 flex items-center justify-center text-brand-600">
                        <i class="fas fa-money-bill-wave text-sm"></i>
                    </div>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-xs text-gray-500">Balance: <span class="font-semibold <?php echo $totalPendingBalance > 0 ? 'text-amber-600' : 'text-emerald-600'; ?>">₱<?php echo number_format($totalPendingBalance, 2); ?></span></span>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="pro-card p-6 mb-8">
            <div class="section-header">
                <div class="section-icon bg-brand-50 text-brand-600">
                    <i class="fas fa-bolt"></i>
                </div>
                <h2 class="text-base font-bold text-gray-900">Quick Actions</h2>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <a href="products.php" class="quick-action-pro">
                    <div class="qa-icon bg-brand-50 text-brand-600">
                        <i class="fas fa-fish"></i>
                    </div>
                    <div>
                        <p class="qa-title">Order Fish</p>
                        <p class="qa-desc">Browse products</p>
                    </div>
                </a>
                <a href="orders.php" class="quick-action-pro">
                    <div class="qa-icon bg-emerald-50 text-emerald-600">
                        <i class="fas fa-shopping-bag"></i>
                    </div>
                    <div>
                        <p class="qa-title">My Orders</p>
                        <p class="qa-desc">Track orders</p>
                    </div>
                </a>
                <a href="request_return.php" class="quick-action-pro">
                    <div class="qa-icon bg-red-50 text-red-600">
                        <i class="fas fa-undo-alt"></i>
                    </div>
                    <div>
                        <p class="qa-title">Return Request</p>
                        <p class="qa-desc">For issues</p>
                    </div>
                </a>
                <a href="deduction_history.php" class="quick-action-pro">
                    <div class="qa-icon bg-amber-50 text-amber-600">
                        <i class="fas fa-receipt"></i>
                    </div>
                    <div>
                        <p class="qa-title">Payment History</p>
                        <p class="qa-desc">View deductions</p>
                    </div>
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Recent Orders -->
            <div class="lg:col-span-2">
                <div class="pro-card overflow-hidden">
                    <div class="p-5 border-b border-gray-100 flex items-center justify-between">
                        <div class="section-header mb-0">
                            <div class="section-icon bg-brand-50 text-brand-600">
                                <i class="fas fa-history"></i>
                            </div>
                            <h2 class="text-base font-bold text-gray-900">Recent Orders</h2>
                        </div>
                        <a href="orders.php" class="link-arrow">
                            View All <i class="fas fa-arrow-right text-[10px]"></i>
                        </a>
                    </div>
                    <?php if (empty($recentOrders)): ?>
                        <div class="empty-state-pro">
                            <div class="empty-icon">
                                <i class="fas fa-shopping-bag"></i>
                            </div>
                            <h3 class="text-base font-semibold text-gray-900 mb-1">No orders yet</h3>
                            <p class="text-sm text-gray-500 mb-4">Start by browsing our available products</p>
                            <a href="products.php" class="btn-brand">
                                <i class="fas fa-fish text-sm"></i>Shop Now
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="p-5">
                            <?php foreach ($recentOrders as $order): 
                                $statusClass = $order['order_status'] == 'pending' ? 'badge-pending' : ($order['order_status'] == 'confirmed' ? 'badge-confirmed' : ($order['order_status'] == 'completed' ? 'badge-completed' : 'badge-processing'));
                                $statusText = ucfirst(str_replace('_', ' ', $order['order_status']));
                            ?>
                                <div class="order-row">
                                    <div class="flex flex-wrap justify-between items-start gap-3 mb-3">
                                        <div>
                                            <span class="text-[11px] font-medium text-gray-400 uppercase tracking-wider">Order #</span>
                                            <p class="text-sm font-bold text-gray-900">#<?php echo str_pad($order['order_id'], 6, '0', STR_PAD_LEFT); ?></p>
                                        </div>
                                        <span class="badge <?php echo $statusClass; ?>">
                                            <i class="fas fa-circle text-[6px]"></i>
                                            <?php echo $statusText; ?>
                                        </span>
                                    </div>
                                    <div class="grid grid-cols-3 gap-4 mb-3">
                                        <div>
                                            <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider mb-0.5">Total</p>
                                            <p class="text-sm font-bold text-brand-600">₱<?php echo number_format($order['total_amount'] ?? 0, 2); ?></p>
                                        </div>
                                        <div>
                                            <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider mb-0.5">Payment</p>
                                            <p class="text-sm font-medium text-gray-700"><?php echo str_replace('_', ' ', $order['payment_method'] ?? 'N/A'); ?></p>
                                        </div>
                                        <div>
                                            <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider mb-0.5">Date</p>
                                            <p class="text-sm text-gray-700"><?php echo date('M d, Y', strtotime($order['order_date'])); ?></p>
                                        </div>
                                    </div>
                                    <div class="flex justify-end pt-2 border-t border-gray-50">
                                        <a href="order_details.php?id=<?php echo $order['order_id']; ?>" class="link-arrow">
                                            View Details <i class="fas fa-arrow-right text-[10px]"></i>
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Sidebar -->
            <div class="space-y-6">

<!-- Latest Harvests -->
<div class="pro-card overflow-hidden">
    <div class="p-5 border-b border-gray-100 bg-gradient-to-r from-amber-50/50 to-transparent">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-amber-100 flex items-center justify-center text-amber-600">
                    <i class="fas fa-fish text-xs"></i>
                </div>
                <div>
                    <h2 class="text-sm font-bold text-gray-900">Latest Harvests</h2>
                    <p class="text-[11px] text-gray-500">Newest harvests shown first</p>
                </div>
            </div>
            <a href="products.php" class="link-arrow text-amber-600 hover:text-amber-700">
                Shop <i class="fas fa-arrow-right text-[10px]"></i>
            </a>
        </div>
    </div>
    <?php if (empty($recentHarvests) || count($recentHarvests) == 0): ?>
        <div class="p-8 text-center">
            <div class="empty-icon">
                <i class="fas fa-fish"></i>
            </div>
            <p class="text-sm text-gray-400">No harvests available</p>
            <p class="text-xs text-gray-400 mt-1">Check back later for fresh catches!</p>
        </div>
    <?php else: ?>
        <div class="p-4">
            <?php foreach ($recentHarvests as $index => $harvest): ?>
                <?php if ($index < 2): // Safety limit ?>
                <div class="harvest-card">
                    <div class="flex justify-between items-start">
                        <div>
                            <h3 class="font-semibold text-gray-800 text-sm">
                                <?php echo htmlspecialchars($harvest['display_name'] ?? 'Fresh Fish'); ?>
                                <?php if (!empty($harvest['batch_no'])): ?>
                                    <span class="text-xs text-gray-400 ml-1 font-normal">(<?php echo htmlspecialchars($harvest['batch_no']); ?>)</span>
                                <?php endif; ?>
                            </h3>
                            <?php if (!empty($harvest['price_per_kg']) && floatval($harvest['price_per_kg']) > 0): ?>
                                <p class="text-brand-600 font-semibold text-xs mt-0.5">₱<?php echo number_format(floatval($harvest['price_per_kg']), 2); ?>/kg</p>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($harvest['status'])): ?>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-gray-100 text-gray-600 uppercase tracking-wider">
                                <?php echo ucfirst($harvest['status']); ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($harvest['location'])): ?>
                        <div class="flex items-center gap-2 mt-2">
                            <span class="text-xs text-gray-500">
                                <i class="fas fa-map-marker-alt text-gray-400 mr-1 text-[10px]"></i>
                                <?php echo htmlspecialchars($harvest['location']); ?>
                            </span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($harvest['harvest_date'])): ?>
                        <div class="flex items-center gap-2 mt-2 text-xs text-gray-500">
                            <i class="fas fa-calendar-alt text-gray-400 text-[10px]"></i>
                            <span>
                                <?php if (!empty($harvest['is_upcoming'])): ?>
                                    Harvest: <?php echo $harvest['formatted_harvest_date']; ?>
                                    <span class="text-amber-600 font-semibold ml-1">(in <?php echo $harvest['days_until']; ?> days)</span>
                                <?php else: ?>
                                    Harvested: <?php echo $harvest['formatted_harvest_date']; ?>
                                    <span class="text-gray-400 ml-1">(<?php echo $harvest['days_ago']; ?> days ago)</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endif; ?>

                    <div class="mt-3">
                        <div class="flex justify-between text-xs mb-1.5">
                            <span class="text-gray-500 font-medium">Available Stock</span>
                            <span class="font-semibold <?php echo ($harvest['stock_color'] ?? 'green') == 'red' ? 'text-red-600' : (($harvest['stock_color'] ?? 'green') == 'orange' ? 'text-amber-600' : 'text-emerald-600'); ?>">
                                <?php echo number_format(floatval($harvest['remaining_quantity'] ?? 0), 1); ?> / <?php echo number_format(floatval($harvest['total_quantity'] ?? 0), 1); ?> kg
                            </span>
                        </div>
                        <div class="progress-track">
                            <div class="progress-fill <?php echo ($harvest['stock_color'] ?? 'green') == 'red' ? 'bg-red-500' : (($harvest['stock_color'] ?? 'green') == 'orange' ? 'bg-amber-500' : 'bg-emerald-500'); ?>" 
                                 style="width: <?php echo $harvest['available_percentage'] ?? 0; ?>%"></div>
                        </div>
                    </div>

                    <div class="mt-3 flex justify-end">
                        <a href="products.php" class="link-arrow text-amber-600 hover:text-amber-700">
                            Order Now <i class="fas fa-arrow-right text-[10px]"></i>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

                <!-- Return Requests -->
                <div class="pro-card overflow-hidden">
                    <div class="p-5 border-b border-gray-100 bg-gradient-to-r from-red-50/50 to-transparent">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <div class="w-8 h-8 rounded-lg bg-red-100 flex items-center justify-center text-red-600">
                                    <i class="fas fa-undo-alt text-xs"></i>
                                </div>
                                <div>
                                    <h2 class="text-sm font-bold text-gray-900">Return Requests</h2>
                                </div>
                            </div>
                            <a href="returns.php" class="link-arrow text-red-600 hover:text-red-700">
                                View All <i class="fas fa-arrow-right text-[10px]"></i>
                            </a>
                        </div>
                    </div>
                    <?php if (empty($recentReturns)): ?>
                        <div class="p-8 text-center">
                            <div class="empty-icon">
                                <i class="fas fa-undo-alt"></i>
                            </div>
                            <p class="text-sm text-gray-400">No returns yet</p>
                            <a href="returns.php" class="link-arrow text-brand-600 hover:text-brand-700 mt-2 inline-block">
                                Create return request <i class="fas fa-arrow-right text-[10px]"></i>
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="p-4">
                            <?php foreach ($recentReturns as $ret): ?>
                                <div class="return-card">
                                    <div class="flex justify-between items-start">
                                        <span class="font-semibold text-gray-800 text-sm">Order #<?php echo str_pad($ret['order_id'] ?? 0, 6, '0', STR_PAD_LEFT); ?></span>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wider <?php echo $ret['return_status'] == 'pending' ? 'bg-amber-50 text-amber-700 border border-amber-200' : ($ret['return_status'] == 'approved' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-red-50 text-red-700 border border-red-200'); ?>">
                                            <?php echo ucfirst($ret['return_status'] ?? 'Pending'); ?>
                                        </span>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-1.5"><?php echo htmlspecialchars($ret['fish_type'] ?? 'Product'); ?></p>
                                    <p class="text-xs text-gray-400 mt-1">
                                        <i class="far fa-calendar-alt mr-1"></i>
                                        <?php echo isset($ret['created_at']) ? date('M d, Y', strtotime($ret['created_at'])) : 'N/A'; ?>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<?php include '../includes/footer.php'; ?>

    <script>
        // Flash message auto-dismiss
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