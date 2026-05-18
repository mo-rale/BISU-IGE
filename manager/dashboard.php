<?php
// manager/dashboard.php - UPDATED: Fixed fonts and customers with 'standard' role
require_once '../includes/config.php';
require_once '../includes/session.php';

$userId = SessionManager::getUserId();
// Only allow managers
SessionManager::requireManagerOrStaff();

$functions = new SystemFunctions();

// Get manager data
$user = $functions->getUserById($userId);

// Pagination for pending orders
$currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 5;
$offset = ($currentPage - 1) * $perPage;

// Initialize variables
$totalProducts = 0;
$pendingOrdersCount = 0;
$confirmedOrdersCount = 0;
$activeOrdersCount = 0;
$pendingReturns = 0;
$totalUsers = 0;
$totalPaid = 0;
$lowStockItems = [];
$paginatedOrders = [];
$recentOrders = [];
$pendingReturnRequests = [];
$recentPaidOrders = [];
$availableProducts = [];
$announcements = [];
$totalPages = 1;

try {
    $db = (new Database())->getConnection();
    
    // Test connection and get counts with error handling
    try {
        // Total available products
        $stmt = $db->query("SELECT COUNT(*) as total FROM fish_products");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalProducts = $result ? ($result['total'] ?? 0) : 0;
    } catch (Exception $e) {
        error_log("Products count error: " . $e->getMessage());
        $totalProducts = 0;
    }
    
    try {
        // Pending orders count
        $stmt = $db->query("SELECT COUNT(*) as total FROM orders WHERE order_status = 'pending'");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $pendingOrdersCount = $result ? ($result['total'] ?? 0) : 0;
    } catch (Exception $e) {
        error_log("Pending orders count error: " . $e->getMessage());
        $pendingOrdersCount = 0;
    }
    
    try {
        // Confirmed orders count
        $stmt = $db->query("SELECT COUNT(*) as total FROM orders WHERE order_status = 'confirmed'");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $confirmedOrdersCount = $result ? ($result['total'] ?? 0) : 0;
    } catch (Exception $e) {
        error_log("Confirmed orders count error: " . $e->getMessage());
        $confirmedOrdersCount = 0;
    }
    
    $activeOrdersCount = $pendingOrdersCount + $confirmedOrdersCount;
    
    try {
        // Pending returns
        $stmt = $db->query("SELECT COUNT(*) as total FROM return_requests WHERE return_status = 'pending'");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $pendingReturns = $result ? ($result['total'] ?? 0) : 0;
    } catch (Exception $e) {
        error_log("Returns count error: " . $e->getMessage());
        $pendingReturns = 0;
    }
    
    // FIXED: Total customers with role = 'standard' (not admin/manager)
    try {
        $stmt = $db->query("SELECT COUNT(*) as total FROM users WHERE role = 'standard'");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalUsers = $result ? ($result['total'] ?? 0) : 0;
    } catch (Exception $e) {
        error_log("Users count error: " . $e->getMessage());
        // Fallback to original query
        try {
            $stmt = $db->query("SELECT COUNT(*) as total FROM users WHERE user_role = 'customer' AND is_active = true");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $totalUsers = $result ? ($result['total'] ?? 0) : 0;
        } catch (Exception $e2) {
            $totalUsers = 0;
        }
    }
    
    // Total paid from salary_deductions table
    try {
        $stmt = $db->query("SELECT COALESCE(SUM(amount_paid), 0) as total FROM salary_deductions WHERE deduction_status != 'cancelled'");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalPaid = $result ? ($result['total'] ?? 0) : 0;
    } catch (Exception $e) {
        error_log("Total paid from salary_deductions error: " . $e->getMessage());
        // Fallback to orders table if salary_deductions doesn't exist yet
        try {
            $stmt = $db->query("SELECT COALESCE(SUM(total_amount), 0) as total FROM orders WHERE claimed_at IS NOT NULL");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $totalPaid = $result ? ($result['total'] ?? 0) : 0;
        } catch (Exception $e2) {
            error_log("Fallback total paid error: " . $e2->getMessage());
            $totalPaid = 0;
        }
    }
    
    // Low stock items
    try {
        $lowStockSql = "SELECT product_id, available_quantity, fish_name, price_per_kg
                        FROM fish_products 
                        WHERE available_quantity < 10
                        ORDER BY available_quantity ASC
                        LIMIT 5";
        $lowStockStmt = $db->query($lowStockSql);
        $lowStockItems = $lowStockStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Low stock error: " . $e->getMessage());
        $lowStockItems = [];
    }
    
    // Pending Orders with Pagination
    if ($activeOrdersCount > 0) {
        try {
            $pendingOrdersSql = "SELECT 
                                    o.order_id,
                                    o.total_amount,
                                    o.order_status,
                                    o.payment_method,
                                    o.order_date,
                                    o.created_at,
                                    o.remarks,
                                    o.claimed_at,
                                    u.user_id,
                                    u.first_name, 
                                    u.last_name, 
                                    u.email,
                                    u.contact_number
                                FROM orders o
                                LEFT JOIN users u ON o.user_id = u.user_id
                                WHERE o.order_status IN ('pending', 'confirmed') 
                                AND o.claimed_at IS NULL
                                ORDER BY 
                                    CASE o.order_status 
                                        WHEN 'pending' THEN 1
                                        WHEN 'confirmed' THEN 2
                                        ELSE 3
                                    END,
                                    o.created_at ASC
                                LIMIT :limit OFFSET :offset";
            
            $pendingStmt = $db->prepare($pendingOrdersSql);
            $pendingStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $pendingStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $pendingStmt->execute();
            $paginatedOrders = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);
            $totalPages = ceil($activeOrdersCount / $perPage);
        } catch (Exception $e) {
            error_log("Pending orders fetch error: " . $e->getMessage());
            $paginatedOrders = [];
        }
    }
    
    // Recent Orders (last 5)
    try {
        $recentOrdersSql = "SELECT 
                                o.order_id,
                                o.total_amount,
                                o.order_status,
                                o.created_at,
                                u.full_name
                            FROM orders o
                            LEFT JOIN users u ON o.user_id = u.user_id
                            ORDER BY o.created_at DESC 
                            LIMIT 4";
        $recentStmt = $db->query($recentOrdersSql);
        $recentOrders = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Recent orders error: " . $e->getMessage());
        $recentOrders = [];
    }
    
    // Pending Returns
    if ($pendingReturns > 0) {
        try {
            $pendingReturnsSql = "SELECT 
                                    rr.return_id,
                                    rr.return_reason as reason,
                                    rr.request_date as created_at,
                                    u.user_id,
                                    u.first_name, 
                                    u.last_name,
                                    o.order_id,
                                    o.total_amount as order_total
                                FROM return_requests rr 
                                LEFT JOIN users u ON rr.user_id = u.user_id 
                                LEFT JOIN orders o ON rr.order_id = o.order_id
                                WHERE rr.return_status = 'pending'
                                ORDER BY rr.request_date DESC 
                                LIMIT 5";
            $pendingReturnsStmt = $db->query($pendingReturnsSql);
            $pendingReturnRequests = $pendingReturnsStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Pending returns fetch error: " . $e->getMessage());
            $pendingReturnRequests = [];
        }
    }
    
    // Recent Paid Orders from salary_deductions
    try {
        $recentPaidSql = "SELECT 
                            sd.deduction_id,
                            sd.order_id,
                            sd.total_amount,
                            sd.amount_paid,
                            sd.deduction_status,
                            sd.updated_at as created_at,
                            o.payment_method,
                            u.first_name,
                            u.last_name
                        FROM salary_deductions sd
                        LEFT JOIN orders o ON sd.order_id = o.order_id
                        LEFT JOIN users u ON sd.user_id = u.user_id
                        WHERE sd.amount_paid > 0 AND sd.deduction_status != 'cancelled'
                        ORDER BY sd.updated_at DESC
                        LIMIT 5";
        $recentPaidStmt = $db->query($recentPaidSql);
        $recentPaidOrders = $recentPaidStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Recent paid orders error: " . $e->getMessage());
        $recentPaidOrders = [];
    }
    
    // Available Products (last 2)
    try {
        $availableProductsSql = "SELECT 
                                    product_id,
                                    available_quantity,
                                    price_per_kg,
                                    fish_name
                                FROM fish_products 
                                ORDER BY created_at DESC
                                LIMIT 2";
        $availableProductsStmt = $db->query($availableProductsSql);
        $availableProducts = $availableProductsStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Available products error: " . $e->getMessage());
        $availableProducts = [];
    }
    
    // Get announcements
    try {
        $announcements = $functions->getActiveAnnouncements(3);
    } catch (Exception $e) {
        error_log("Announcements error: " . $e->getMessage());
        $announcements = [];
    }
    
} catch (PDOException $e) {
    error_log("Manager dashboard database error: " . $e->getMessage());
}

// Status colors for badges
$statusColors = [
    'pending' => 'bg-amber-50 text-amber-700 border-amber-200',
    'confirmed' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
    'completed' => 'bg-green-50 text-green-700 border-green-200',
    'cancelled' => 'bg-red-50 text-red-700 border-red-200'
];

$statusIcons = [
    'pending' => 'fa-clock',
    'confirmed' => 'fa-check-circle',
    'completed' => 'fa-check-double',
    'cancelled' => 'fa-times-circle'
];

$paymentColors = [
    'cash' => 'bg-green-50 text-green-700 border-green-200',
    'gcash' => 'bg-blue-50 text-blue-700 border-blue-200',
    'bank_transfer' => 'bg-purple-50 text-purple-700 border-purple-200',
    'card' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
    'pay_later' => 'bg-amber-50 text-amber-700 border-amber-200',
    'salary_deduction' => 'bg-purple-50 text-purple-700 border-purple-200'
];

// Pagination helper
function buildPaginationLinks($currentPage, $totalPages, $queryParams = []) {
    if ($totalPages <= 1) return '';
    
    $links = '<div class="flex items-center gap-2 flex-wrap justify-center">';
    
    if ($currentPage > 1) {
        $queryParams['page'] = $currentPage - 1;
        $links .= '<a href="?' . http_build_query($queryParams) . '" class="px-3 py-2 rounded-lg bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 text-sm font-medium transition-smooth">« Prev</a>';
    } else {
        $links .= '<span class="px-3 py-2 rounded-lg bg-gray-100 text-gray-400 cursor-not-allowed text-sm font-medium">« Prev</span>';
    }
    
    $startPage = max(1, $currentPage - 2);
    $endPage = min($totalPages, $currentPage + 2);
    
    if ($startPage > 1) {
        $queryParams['page'] = 1;
        $links .= '<a href="?' . http_build_query($queryParams) . '" class="px-3 py-2 rounded-lg bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 text-sm font-medium transition-smooth">1</a>';
        if ($startPage > 2) $links .= '<span class="px-2 text-gray-500 text-sm">...</span>';
    }
    
    for ($i = $startPage; $i <= $endPage; $i++) {
        if ($i == $currentPage) {
            $links .= '<span class="px-3 py-2 rounded-lg bg-brand-600 text-white text-sm font-medium shadow-sm">' . $i . '</span>';
        } else {
            $queryParams['page'] = $i;
            $links .= '<a href="?' . http_build_query($queryParams) . '" class="px-3 py-2 rounded-lg bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 text-sm font-medium transition-smooth">' . $i . '</a>';
        }
    }
    
    if ($endPage < $totalPages) {
        if ($endPage < $totalPages - 1) $links .= '<span class="px-2 text-gray-500 text-sm">...</span>';
        $queryParams['page'] = $totalPages;
        $links .= '<a href="?' . http_build_query($queryParams) . '" class="px-3 py-2 rounded-lg bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 text-sm font-medium transition-smooth">' . $totalPages . '</a>';
    }
    
    if ($currentPage < $totalPages) {
        $queryParams['page'] = $currentPage + 1;
        $links .= '<a href="?' . http_build_query($queryParams) . '" class="px-3 py-2 rounded-lg bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 text-sm font-medium transition-smooth">Next »</a>';
    } else {
        $links .= '<span class="px-3 py-2 rounded-lg bg-gray-100 text-gray-400 cursor-not-allowed text-sm font-medium">Next »</span>';
    }
    
    $links .= '</div>';
    return $links;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Dashboard - BISU IGE Aquaculture</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Playfair+Display:wght@600;700;800&display=swap" rel="stylesheet">
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
        * { 
            font-family: 'Inter', system-ui, sans-serif; 
        }
        
        body {
            font-family: 'Inter', system-ui, sans-serif;
        }
        
        h1, h2, h3, .font-display {
            font-family: 'Playfair Display', serif;
        }
        
        :root {
            --primary: #0ea5e9;
            --primary-dark: #0284c7;
            --secondary: #10b981;
            --accent: #8b5cf6;
            --danger: #ef4444;
            --warning: #f59e0b;
            --surface: #ffffff;
            --background: #f8fafc;
        }

        body { 
            background-color: var(--background); 
        }

        .transition-smooth {
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .dashboard-card {
            background: var(--surface);
            border-radius: 1.25rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            border: 1px solid #e2e8f0;
        }

        .dashboard-card:hover {
            box-shadow: 0 8px 25px -4px rgba(0, 0, 0, 0.08);
            border-color: #cbd5e1;
        }

        .stat-card {
            background: var(--surface);
            border-radius: 1rem;
            padding: 1.25rem;
            border: 1px solid #e2e8f0;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px -4px rgba(0, 0, 0, 0.1);
            border-color: #cbd5e1;
        }

        .stat-icon {
            width: 3rem;
            height: 3rem;
            border-radius: 0.875rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .quick-action-card {
            background: var(--surface);
            border-radius: 1rem;
            padding: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .quick-action-card:hover {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            transform: translateY(-2px);
        }
        
        .quick-action-card:hover div:first-child { 
            background: rgba(255,255,255,0.2) !important; 
            color: white !important; 
        }
        .quick-action-card:hover div:last-child p:first-child { 
            color: white !important; 
        }
        .quick-action-card:hover div:last-child p:last-child { 
            color: rgba(255,255,255,0.8) !important; 
        }

        .order-item {
            background: var(--surface);
            border-radius: 1rem;
            padding: 1rem;
            margin-bottom: 0.75rem;
            border: 1px solid #e2e8f0;
            transition: all 0.2s ease;
        }

        .order-item:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 12px rgba(14, 165, 233, 0.08);
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.6875rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            border: 1px solid;
        }

        .page-header {
            background: linear-gradient(135deg, #0c4a6e 0%, #075985 50%, #0369a1 100%);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(255,255,255,0.06) 0%, transparent 70%);
            border-radius: 50%;
        }

        .page-header::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -5%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(255,255,255,0.04) 0%, transparent 70%);
            border-radius: 50%;
        }

        .btn-primary {
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            color: white;
            border-radius: 0.75rem;
            padding: 0.625rem 1.25rem;
            font-weight: 600;
            font-size: 0.875rem;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            box-shadow: 0 1px 3px rgba(14, 165, 233, 0.2);
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(14, 165, 233, 0.3);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            border-radius: 0.75rem;
            padding: 0.625rem 1.25rem;
            font-weight: 600;
            font-size: 0.875rem;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-outline:hover { 
            background: rgba(255, 255, 255, 0.1); 
        }

        .btn-success {
            background: #ecfdf5;
            color: #059669;
            border-radius: 0.5rem;
            padding: 0.375rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            text-decoration: none;
            border: 1px solid #a7f3d0;
            transition: all 0.2s ease;
        }

        .btn-success:hover {
            background: #059669;
            color: white;
            border-color: #059669;
        }

        .btn-danger {
            background: #fef2f2;
            color: #dc2626;
            border-radius: 0.5rem;
            padding: 0.375rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            text-decoration: none;
            border: 1px solid #fecaca;
            transition: all 0.2s ease;
        }

        .btn-danger:hover {
            background: #dc2626;
            color: white;
            border-color: #dc2626;
        }

        .btn-secondary {
            background: #f1f5f9;
            color: #475569;
            border-radius: 0.5rem;
            padding: 0.375rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            text-decoration: none;
            border: 1px solid #e2e8f0;
            transition: all 0.2s ease;
        }

        .btn-secondary:hover {
            background: #e2e8f0;
        }
        
        .btn-info {
            background: #e0f2fe;
            color: #0369a1;
            border-radius: 0.5rem;
            padding: 0.375rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .btn-info:hover {
            background: #0284c7;
            color: white;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .section-header-icon {
            width: 2rem;
            height: 2rem;
            border-radius: 0.75rem;
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.875rem;
        }

        .section-header-title {
            font-size: 1rem;
            font-weight: 600;
            color: #0f172a;
            font-family: 'Playfair Display', serif;
        }

        .tab-button {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: #64748b;
            border-bottom: 2px solid transparent;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .tab-button.active {
            color: #0ea5e9;
            border-bottom-color: #0ea5e9;
        }

        .low-stock-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.875rem;
            background: #fef2f2;
            border-radius: 0.75rem;
            border: 1px solid #fecaca;
            margin-bottom: 0.5rem;
        }

        .timeline-item {
            position: relative;
            padding-left: 1.75rem;
            padding-bottom: 0.875rem;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: 0.5rem;
            top: 0.5rem;
            bottom: 0;
            width: 1px;
            background: #e2e8f0;
        }

        .timeline-item:last-child::before { display: none; }

        .timeline-dot {
            position: absolute;
            left: 0.25rem;
            top: 0.5rem;
            width: 0.625rem;
            height: 0.625rem;
            border-radius: 50%;
            background: #0ea5e9;
            border: 2px solid white;
            box-shadow: 0 0 0 1px #e2e8f0;
        }

        .fade-in { 
            animation: fadeIn 0.5s ease-out; 
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .orders-container { 
            max-height: 550px; 
            overflow-y: auto; 
        }
        
        ::-webkit-scrollbar { width: 5px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 3px; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        
        .hero-title {
            font-family: 'Playfair Display', serif;
            font-weight: 700;
        }
        
        .card-title {
            font-family: 'Playfair Display', serif;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>

    <!-- Page Header -->
    <div class="page-header py-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                <div class="mb-4 md:mb-0">
                    <p class="text-brand-300 text-sm font-medium tracking-wide uppercase mb-2">Management Dashboard</p>
                    <h1 class="text-3xl md:text-4xl font-bold text-white hero-title">Manager Dashboard</h1>
                    <p class="text-brand-200 mt-2 text-base">
                        Welcome back, <?php echo htmlspecialchars($user['first_name'] ?? 'Manager'); ?>!
                    </p>
                </div>
                <div class="flex gap-3">
                    <a href="products.php" class="btn-outline"><i class="fas fa-box"></i> Manage Products</a>
                    <a href="reports.php" class="btn-primary"><i class="fas fa-chart-bar"></i> Reports</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-5 mb-8 fade-in">
            <div class="stat-card">
                <div class="flex items-start justify-between mb-3">
                    <div>
                        <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">Available Products</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($totalProducts); ?></p>
                    </div>
                    <div class="stat-icon bg-brand-50 text-brand-600"><i class="fas fa-fish"></i></div>
                </div>
                <a href="products.php" class="text-xs text-brand-600 hover:text-brand-700 font-medium flex items-center gap-1 transition-smooth">Manage <i class="fas fa-arrow-right text-[10px]"></i></a>
            </div>
            
            <div class="stat-card">
                <div class="flex items-start justify-between mb-3">
                    <div>
                        <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">Pending Orders</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($activeOrdersCount); ?></p>
                    </div>
                    <div class="stat-icon bg-amber-50 text-amber-600"><i class="fas fa-shopping-cart"></i></div>
                </div>
                <a href="orders.php" class="text-xs text-amber-600 hover:text-amber-700 font-medium flex items-center gap-1 transition-smooth">View orders <i class="fas fa-arrow-right text-[10px]"></i></a>
            </div>
            
            <div class="stat-card">
                <div class="flex items-start justify-between mb-3">
                    <div>
                        <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">Pending Returns</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($pendingReturns); ?></p>
                    </div>
                    <div class="stat-icon bg-red-50 text-red-600"><i class="fas fa-undo-alt"></i></div>
                </div>
                <a href="returns.php" class="text-xs text-red-600 hover:text-red-700 font-medium flex items-center gap-1 transition-smooth">Process returns <i class="fas fa-arrow-right text-[10px]"></i></a>
            </div>
            
            <?php if (SessionManager::isManager()): ?>
            <div class="stat-card">
                <div class="flex items-start justify-between mb-3">
                    <div>
                        <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">Total Customers</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($totalUsers); ?></p>
                        <p class="text-[10px] text-gray-400 mt-1">Standard users</p>
                    </div>
                    <div class="stat-icon bg-emerald-50 text-emerald-600"><i class="fas fa-users"></i></div>
                </div>
                <a href="users.php" class="text-xs text-emerald-600 hover:text-emerald-700 font-medium flex items-center gap-1 transition-smooth">Manage users <i class="fas fa-arrow-right text-[10px]"></i></a>
            </div>
            <?php endif; ?>
            
            <div class="stat-card">
                <div class="flex items-start justify-between mb-3">
                    <div>
                        <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">Total Paid</p>
                        <p class="text-2xl font-bold text-purple-600">₱<?php echo number_format($totalPaid, 2); ?></p>
                        <p class="text-[10px] text-gray-400 mt-1">From salary deductions</p>
                    </div>
                    <div class="stat-icon bg-purple-50 text-purple-600"><i class="fas fa-money-bill-wave"></i></div>
                </div>
                <a href="salary_deductions.php" class="text-xs text-purple-600 hover:text-purple-700 font-medium flex items-center gap-1 transition-smooth">View details <i class="fas fa-arrow-right text-[10px]"></i></a>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="dashboard-card p-5 mb-8 fade-in">
            <div class="section-header mb-4">
                <div class="section-header-icon"><i class="fas fa-bolt text-xs"></i></div>
                <h2 class="section-header-title">Quick Actions</h2>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
                <a href="products.php?add=true" class="quick-action-card">
                    <div style="background: rgba(14, 165, 233, 0.1); color: #0ea5e9; padding: 0.625rem; border-radius: 0.75rem;"><i class="fas fa-plus"></i></div>
                    <div><p class="font-semibold text-gray-900 text-sm">Add Product</p><p class="text-[11px] text-gray-500">New fish product</p></div>
                </a>
                <a href="reports.php" class="quick-action-card">
                    <div style="background: rgba(245, 158, 11, 0.1); color: #f50b0b; padding: 0.625rem; border-radius: 0.75rem;"><i class="fas fa-chart-bar"></i></div>
                    <div><p class="font-semibold text-gray-900 text-sm">Reports</p><p class="text-[11px] text-gray-500">Post update</p></div>
                </a>
                <a href="harvest.php" class="quick-action-card">
                    <div style="background: rgba(16, 185, 129, 0.1); color: #10b981; padding: 0.625rem; border-radius: 0.75rem;"><i class="fas fa-clipboard-list"></i></div>
                    <div><p class="font-semibold text-gray-900 text-sm">Harvests</p><p class="text-[11px] text-gray-500">Manage harvests</p></div>
                </a>
                <a href="salary_deductions.php" class="quick-action-card">
                    <div style="background: rgba(139, 92, 246, 0.1); color: #8b5cf6; padding: 0.625rem; border-radius: 0.75rem;"><i class="fas fa-money-bill-wave"></i></div>
                    <div><p class="font-semibold text-gray-900 text-sm">Deductions</p><p class="text-[11px] text-gray-500">Salary deductions</p></div>
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Left Column -->
            <div class="lg:col-span-2 space-y-6">
                
                <!-- Low Stock Alerts -->
                <?php if (!empty($lowStockItems)): ?>
                <div class="dashboard-card overflow-hidden border border-red-200 fade-in">
                    <div class="px-5 py-3 bg-gradient-to-r from-red-50 to-orange-50 border-b border-red-100">
                        <div class="flex justify-between items-center">
                            <h2 class="font-semibold text-red-800 flex items-center gap-2 card-title text-base"><i class="fas fa-exclamation-triangle text-red-600"></i>Low Stock Alert</h2>
                            <span class="px-2 py-0.5 bg-red-100 text-red-700 text-[10px] rounded-full font-semibold"><?php echo count($lowStockItems); ?> items</span>
                        </div>
                    </div>
                    <div class="p-4">
                        <?php foreach ($lowStockItems as $item): ?>
                            <div class="low-stock-item">
                                <div>
                                    <p class="font-medium text-gray-900 text-sm"><?php echo ucfirst($item['fish_name'] ?? 'Unknown Fish'); ?></p>
                                    <p class="text-xs text-red-600 font-medium mt-0.5">Only <?php echo number_format($item['available_quantity'], 2); ?> kg remaining</p>
                                </div>
                                <a href="edit-product.php?id=<?php echo $item['product_id']; ?>" class="btn-danger text-[11px]"><i class="fas fa-plus-circle"></i> Restock</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <!-- Recent Orders -->
                <div class="dashboard-card overflow-hidden fade-in">
                    <div class="px-5 py-3 bg-gray-50/50 border-b border-gray-100">
                        <div class="flex justify-between items-center">
                            <h2 class="font-semibold text-gray-900 flex items-center gap-2 card-title text-base"><i class="fas fa-history text-brand-500"></i>Recent Orders</h2>
                            <a href="orders.php" class="text-xs text-brand-600 hover:text-brand-700 font-medium transition-smooth">View All <i class="fas fa-arrow-right text-[10px]"></i></a>
                        </div>
                    </div>
                    
                    <?php if (empty($recentOrders)): ?>
                        <div class="p-8 text-center">
                            <div class="w-14 h-14 bg-gray-100 rounded-2xl flex items-center justify-center mx-auto mb-3"><i class="fas fa-history text-gray-400 text-xl"></i></div>
                            <p class="text-sm text-gray-500">No recent orders</p>
                        </div>
                    <?php else: ?>
                        <div class="p-4">
                            <?php foreach ($recentOrders as $order): ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot"></div>
                                    <div class="flex items-start justify-between">
                                        <div>
                                            <p class="text-sm font-medium text-gray-900">Order #<?php echo str_pad($order['order_id'], 6, '0', STR_PAD_LEFT); ?></p>
                                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? 'Unknown')); ?></p>
                                            <p class="text-xs text-gray-400 mt-0.5">₱<?php echo number_format($order['total_amount'] ?? 0, 2); ?></p>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-medium <?php echo $statusColors[$order['order_status']] ?? 'bg-gray-50 text-gray-700 border-gray-200'; ?> border">
                                                <?php echo ucfirst($order['order_status'] ?? 'unknown'); ?>
                                            </span>
                                            <span class="text-[10px] text-gray-400"><?php echo !empty($order['created_at']) ? date('M d, H:i', strtotime($order['created_at'])) : ''; ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Return Requests Card (Moved here from sidebar) -->
                <div class="dashboard-card overflow-hidden fade-in">
                    <div class="px-5 py-3 bg-gradient-to-r from-red-50 to-orange-50 border-b border-red-100">
                        <div class="flex justify-between items-center">
                            <h2 class="font-semibold text-gray-900 flex items-center gap-2 card-title text-base"><i class="fas fa-undo-alt text-red-500"></i>Return Requests</h2>
                            <?php if ($pendingReturns > 0): ?>
                                <span class="px-2 py-0.5 bg-red-100 text-red-700 text-[10px] rounded-full font-semibold"><?php echo $pendingReturns; ?> pending</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if (empty($pendingReturnRequests)): ?>
                        <div class="p-6 text-center">
                            <div class="w-12 h-12 bg-gray-100 rounded-xl flex items-center justify-center mx-auto mb-3"><i class="fas fa-check-circle text-gray-400 text-lg"></i></div>
                            <p class="text-sm text-gray-500">No pending returns</p>
                        </div>
                    <?php else: ?>
                        <div class="divide-y divide-gray-100">
                            <?php foreach ($pendingReturnRequests as $return): ?>
                                <div class="p-3 hover:bg-gray-50 transition-smooth">
                                    <div class="flex justify-between items-start mb-2">
                                        <div>
                                            <p class="text-sm font-medium text-gray-900">Order #<?php echo str_pad($return['order_id'], 6, '0', STR_PAD_LEFT); ?></p>
                                            <p class="text-xs text-gray-600"><?php echo htmlspecialchars(($return['first_name'] ?? '') . ' ' . ($return['last_name'] ?? 'Unknown')); ?></p>
                                        </div>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-medium bg-amber-100 text-amber-700"><?php echo ucfirst($return['reason'] ?? 'pending'); ?></span>
                                    </div>
                                    <div class="flex justify-between items-center text-xs mb-2">
                                        <span class="text-gray-500">Amount: ₱<?php echo number_format($return['order_total'] ?? 0, 2); ?></span>
                                        <span class="text-gray-400"><?php echo date('M d', strtotime($return['created_at'])); ?></span>
                                    </div>
                                    <div class="grid grid-cols-2 gap-2 mt-2">
                                        <a href="process-return.php?id=<?php echo $return['return_id']; ?>&action=approve" class="btn-success text-[11px] justify-center"><i class="fas fa-check"></i> Approve</a>
                                        <a href="process-return.php?id=<?php echo $return['return_id']; ?>&action=reject" class="btn-danger text-[11px] justify-center"><i class="fas fa-times"></i> Reject</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="px-4 py-2 bg-gray-50 border-t border-gray-100 text-center">
                            <a href="returns.php" class="text-xs text-brand-600 hover:text-brand-700 font-medium flex items-center justify-center gap-1 transition-smooth">View all returns <i class="fas fa-arrow-right text-[10px]"></i></a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Right Sidebar -->
            <div class="space-y-6">
            
                
                <!-- Available Products -->
                <div class="dashboard-card overflow-hidden fade-in">
                    <div class="px-5 py-3 bg-gradient-to-r from-blue-50 to-cyan-50 border-b border-blue-100">
                        <div class="flex justify-between items-center">
                            <h2 class="font-semibold text-gray-900 flex items-center gap-2 card-title text-base"><i class="fas fa-fish text-blue-500"></i>Available Products</h2>
                            <a href="products.php" class="text-xs text-blue-600 hover:text-blue-700 font-medium transition-smooth">View All</a>
                        </div>
                    </div>
                    
                    <?php if (empty($availableProducts)): ?>
                        <div class="p-6 text-center">
                            <div class="w-12 h-12 bg-gray-100 rounded-xl flex items-center justify-center mx-auto mb-3"><i class="fas fa-fish text-gray-400 text-lg"></i></div>
                            <p class="text-sm text-gray-500 mb-3">No products available</p>
                            <a href="add-product.php" class="text-xs btn-primary py-2 px-3"><i class="fas fa-plus mr-1"></i>Add Product</a>
                        </div>
                    <?php else: ?>
                        <div class="divide-y divide-gray-100">
                            <?php foreach ($availableProducts as $product): ?>
                                <div class="p-3">
                                    <div class="flex items-start justify-between mb-2">
                                        <div>
                                            <h3 class="font-semibold text-gray-900 text-sm"><?php echo ucfirst($product['fish_name'] ?? 'Unknown Fish'); ?></h3>
                                            <div class="grid grid-cols-2 gap-3 mt-2">
                                                <div><p class="text-[10px] text-gray-500">Available</p><p class="text-sm font-medium text-gray-900"><?php echo number_format($product['available_quantity'] ?? 0, 2); ?> kg</p></div>
                                                <div><p class="text-[10px] text-gray-500">Price/kg</p><p class="text-sm font-medium text-green-600">₱<?php echo number_format($product['price_per_kg'] ?? 0, 2); ?></p></div>
                                            </div>
                                        </div>
                                        <?php if (($product['available_quantity'] ?? 0) < 20): ?>
                                            <span class="px-2 py-0.5 bg-orange-100 text-orange-600 text-[10px] rounded-full font-medium">Low Stock</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex justify-end gap-2 mt-2">
                                        <a href="edit-product.php?id=<?php echo $product['product_id']; ?>" class="btn-info text-[11px] py-1"><i class="fas fa-edit"></i> Edit</a>
                                        <a href="view-product.php?id=<?php echo $product['product_id']; ?>" class="btn-secondary text-[11px] py-1"><i class="fas fa-eye"></i> View</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- System Status -->
                <div class="dashboard-card p-4 fade-in">
                    <h2 class="font-semibold text-gray-900 mb-3 flex items-center gap-2 text-sm card-title"><i class="fas fa-server text-gray-500"></i>System Status</h2>
                    <div class="space-y-2">
                        <div class="flex justify-between items-center">
                            <span class="text-xs text-gray-600">Database</span>
                            <span class="px-2 py-0.5 bg-green-100 text-green-700 text-[10px] rounded-full font-medium flex items-center gap-1"><span class="w-1.5 h-1.5 bg-green-500 rounded-full"></span> Online</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-xs text-gray-600">Active Orders</span>
                            <span class="px-2 py-0.5 bg-blue-100 text-blue-700 text-[10px] rounded-full font-medium"><?php echo $activeOrdersCount; ?></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-xs text-gray-600">Pending Returns</span>
                            <span class="px-2 py-0.5 bg-amber-100 text-amber-700 text-[10px] rounded-full font-medium"><?php echo $pendingReturns; ?></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-xs text-gray-600">Total Deductions</span>
                            <span class="px-2 py-0.5 bg-purple-100 text-purple-700 text-[10px] rounded-full font-medium">₱<?php echo number_format($totalPaid, 2); ?></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-xs text-gray-600">Standard Users</span>
                            <span class="px-2 py-0.5 bg-emerald-100 text-emerald-700 text-[10px] rounded-full font-medium"><?php echo number_format($totalUsers); ?></span>
                        </div>
                        <div class="pt-2 border-t border-gray-100 mt-1">
                            <p class="text-[10px] text-gray-400"><i class="fas fa-clock mr-1"></i>Updated: <?php echo date('M d, H:i'); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
        function showTab(status) {
            document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
            const tabs = document.querySelectorAll('.tab-button');
            if (status === 'all') {
                tabs[0].classList.add('active');
                document.querySelectorAll('.order-item').forEach(item => item.style.display = 'block');
            } else if (status === 'pending') {
                tabs[1].classList.add('active');
                document.querySelectorAll('.order-item').forEach(item => {
                    item.style.display = item.dataset.status === 'pending' ? 'block' : 'none';
                });
            } else if (status === 'confirmed') {
                tabs[2].classList.add('active');
                document.querySelectorAll('.order-item').forEach(item => {
                    item.style.display = item.dataset.status === 'confirmed' ? 'block' : 'none';
                });
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            showTab('all');
        });
    </script>
</body>
</html>