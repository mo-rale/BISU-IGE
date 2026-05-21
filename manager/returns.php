<?php
// manager/returns.php - Professional UI
require_once '../includes/config.php';
require_once '../includes/session.php';
require_once '../includes/FifoStock.php';

// Only allow managers
SessionManager::requireManagerOrStaff();

$functions = new SystemFunctions();
$userId = SessionManager::getUserId();
$db = (new Database())->getConnection();

$message = '';
$messageType = '';

// Handle return request actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'approve':
                try {
                    $db->beginTransaction();

                    $sql = "UPDATE return_requests 
                            SET return_status = 'approved', 
                                processed_by = :processed_by, 
                                processed_date = NOW(),
                                processed_remarks = :processed_remarks
                            WHERE return_id = :return_id AND return_status = 'pending'";

                    $stmt = $db->prepare($sql);
                    $stmt->execute([
                        ':processed_by' => $userId,
                        ':processed_remarks' => trim($_POST['manager_notes'] ?? ''),
                        ':return_id' => $_POST['return_id']
                    ]);

                    if ($stmt->rowCount() > 0) {
                        $db->commit();
                        $message = "Return request approved successfully!";
                        $messageType = 'success';
                    } else {
                        throw new Exception("No pending return request found");
                    }
                } catch (Exception $e) {
                    $db->rollBack();
                    $message = "Error approving return: " . $e->getMessage();
                    $messageType = 'error';
                    error_log("Approve return error: " . $e->getMessage());
                }
                break;

            case 'reject':
                try {
                    $db->beginTransaction();

                    $reason = trim($_POST['rejection_reason'] ?? '');
                    if (empty($reason)) {
                        throw new Exception("Rejection reason is required");
                    }

                    $sql = "UPDATE return_requests 
                            SET return_status = 'rejected', 
                                processed_by = :processed_by, 
                                processed_date = NOW(),
                                processed_remarks = :processed_remarks
                            WHERE return_id = :return_id AND return_status = 'pending'";

                    $stmt = $db->prepare($sql);
                    $stmt->execute([
                        ':processed_by' => $userId,
                        ':processed_remarks' => $reason,
                        ':return_id' => $_POST['return_id']
                    ]);

                    if ($stmt->rowCount() > 0) {
                        $db->commit();
                        $message = "Return request rejected successfully!";
                        $messageType = 'success';
                    }
                } catch (Exception $e) {
                    $db->rollBack();
                    $message = "Error rejecting return: " . $e->getMessage();
                    $messageType = 'error';
                    error_log("Reject return error: " . $e->getMessage());
                }
                break;
        }
        
        // Redirect to prevent form resubmission
        if ($messageType === 'success') {
            header("Location: returns.php?message=" . urlencode($message) . "&type=success");
            exit();
        }
    }
}

// Handle message from redirect
if (isset($_GET['message'])) {
    $message = urldecode($_GET['message']);
    $messageType = $_GET['type'] ?? 'success';
}

// Get filter parameters with validation
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : 'all';
$dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$reasonFilter = isset($_GET['reason']) ? trim($_GET['reason']) : 'all';
$sortBy = isset($_GET['sort']) ? trim($_GET['sort']) : 'newest';

// Validate allowed values
$allowedStatuses = ['all', 'pending', 'approved', 'rejected', 'completed'];
$allowedReasons = ['all', 'damaged', 'rotten', 'wrong_item', 'quality', 'other'];
$allowedSorts = ['newest', 'oldest', 'amount_high', 'amount_low'];

if (!in_array($statusFilter, $allowedStatuses)) $statusFilter = 'all';
if (!in_array($reasonFilter, $allowedReasons)) $reasonFilter = 'all';
if (!in_array($sortBy, $allowedSorts)) $sortBy = 'newest';

// Get all return requests with proper joins to orders table
try {
    $sql = "SELECT rr.*, 
               o.order_id, o.order_datetime, o.total_amount as order_total, o.payment_method,
               u.full_name, u.email, u.department
        FROM return_requests rr
        LEFT JOIN orders o ON rr.order_id = o.order_id
        LEFT JOIN users u ON rr.user_id = u.user_id
        WHERE 1=1";

    $params = [];

    // Status filter
    if ($statusFilter !== 'all') {
        $sql .= " AND rr.return_status = :status";
        $params[':status'] = $statusFilter;
    }

    // Reason filter
    if ($reasonFilter !== 'all') {
        $sql .= " AND rr.return_reason = :reason";
        $params[':reason'] = $reasonFilter;
    }

    // Date range filter
    if ($dateFrom) {
        $sql .= " AND DATE(rr.request_date) >= :date_from";
        $params[':date_from'] = $dateFrom;
    }

    if ($dateTo) {
        $sql .= " AND DATE(rr.request_date) <= :date_to";
        $params[':date_to'] = $dateTo;
    }

    // Search filter
    if ($searchQuery) {
        $sql .= " AND (u.full_name ILIKE :search OR 
                       u.email ILIKE :search OR 
                       CAST(o.order_id AS TEXT) ILIKE :search)";
        $params[':search'] = "%$searchQuery%";
    }

    // Sorting
    switch ($sortBy) {
        case 'oldest':
            $sql .= " ORDER BY rr.request_date ASC";
            break;
        case 'amount_high':
            $sql .= " ORDER BY rr.return_amount DESC";
            break;
        case 'amount_low':
            $sql .= " ORDER BY rr.return_amount ASC";
            break;
        case 'newest':
        default:
            $sql .= " ORDER BY rr.request_date DESC";
            break;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get statistics
    $statsSql = "SELECT 
                    COUNT(CASE WHEN return_status = 'pending' THEN 1 END) as pending_count,
                    COUNT(CASE WHEN return_status = 'approved' THEN 1 END) as approved_count,
                    COUNT(CASE WHEN return_status = 'rejected' THEN 1 END) as rejected_count,
                    COUNT(CASE WHEN return_status = 'completed' THEN 1 END) as completed_count,
                    COALESCE(SUM(CASE WHEN return_status IN ('approved', 'completed') THEN return_amount END), 0) as total_refund_amount
                 FROM return_requests";
    $statsStmt = $db->query($statsSql);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    // Get reason statistics
    $reasonStatsSql = "SELECT return_reason as reason, COUNT(*) as count 
                       FROM return_requests
                       GROUP BY return_reason 
                       ORDER BY count DESC";
    $reasonStatsStmt = $db->query($reasonStatsSql);
    $reasonStats = $reasonStatsStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Returns fetch error: " . $e->getMessage());
    $returns = [];
    $stats = [
        'pending_count' => 0,
        'approved_count' => 0,
        'rejected_count' => 0,
        'completed_count' => 0,
        'total_refund_amount' => 0
    ];
    $reasonStats = [];
}

// Status options for filtering
$statusOptions = [
    'all' => 'All Returns',
    'pending' => 'Pending',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'completed' => 'Completed'
];

// Reason options
$reasonOptions = [
    'all' => 'All Reasons',
    'damaged' => 'Damaged Product',
    'rotten' => 'Rotten / Spoiled',
    'wrong_item' => 'Wrong Item',
    'quality' => 'Poor Quality',
    'other' => 'Other'
];

// Sort options
$sortOptions = [
    'newest' => 'Newest First',
    'oldest' => 'Oldest First',
    'amount_high' => 'Highest Amount',
    'amount_low' => 'Lowest Amount'
];

// Return status config
$returnStatusConfig = [
    'pending' => [
        'color' => 'bg-amber-50 text-amber-700 border-amber-200',
        'icon' => 'fa-clock',
        'dot' => 'bg-amber-500'
    ],
    'approved' => [
        'color' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'icon' => 'fa-check-circle',
        'dot' => 'bg-emerald-500'
    ],
    'rejected' => [
        'color' => 'bg-red-50 text-red-700 border-red-200',
        'icon' => 'fa-times-circle',
        'dot' => 'bg-red-500'
    ],
    'completed' => [
        'color' => 'bg-blue-50 text-blue-700 border-blue-200',
        'icon' => 'fa-check-double',
        'dot' => 'bg-blue-500'
    ]
];

// Reason config
$reasonConfig = [
    'damaged' => [
        'label' => 'Damaged Product',
        'icon' => 'fa-box-open',
        'color' => 'text-rose-600'
    ],
    'rotten' => [
        'label' => 'Rotten / Spoiled',
        'icon' => 'fa-skull',
        'color' => 'text-violet-600'
    ],
    'wrong_item' => [
        'label' => 'Wrong Item',
        'icon' => 'fa-question-circle',
        'color' => 'text-amber-600'
    ],
    'quality' => [
        'label' => 'Poor Quality',
        'icon' => 'fa-star-half-alt',
        'color' => 'text-orange-600'
    ],
    'other' => [
        'label' => 'Other',
        'icon' => 'fa-ellipsis-h',
        'color' => 'text-slate-600'
    ]
];

// Counts for filter badges
$counts = [
    'pending' => $stats['pending_count'] ?? 0,
    'approved' => $stats['approved_count'] ?? 0,
    'rejected' => $stats['rejected_count'] ?? 0,
    'completed' => $stats['completed_count'] ?? 0
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Returns - BISU IGE Aquaculture</title>
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
        :root {
            --bg-primary: #f8fafc;
            --bg-secondary: #ffffff;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --brand: #0ea5e9;
            --brand-dark: #0284c7;
            --success: #059669;
            --warning: #d97706;
            --danger: #dc2626;
        }

        body {
            background-color: var(--bg-primary);
            font-family: 'Inter', system-ui, sans-serif;
            color: var(--text-primary);
            min-height: 100vh;
        }

        .transition-smooth {
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

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

        .stat-card {
            background: var(--bg-secondary);
            border-radius: 12px;
            padding: 1rem;
            border: 1px solid var(--border);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px -4px rgba(0, 0, 0, 0.1);
            border-color: #cbd5e1;
        }

        .btn-brand {
            display: inline-flex;
            align-items: center;
            justify-content: center;
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
        }

        .btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: white;
            color: #475569;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.8125rem;
            text-decoration: none;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-secondary:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #0f172a;
        }

        .btn-success-pro {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: #ecfdf5;
            color: #059669;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.75rem;
            border: 1px solid #a7f3d0;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-success-pro:hover {
            background: #059669;
            color: white;
            border-color: #059669;
        }

        .btn-danger-pro {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: #fef2f2;
            color: #dc2626;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.75rem;
            border: 1px solid #fecaca;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-danger-pro:hover {
            background: #dc2626;
            color: white;
            border-color: #dc2626;
        }

        .filter-input, .filter-select {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            transition: all 0.2s ease;
            background: white;
            width: 100%;
        }

        .filter-input:focus, .filter-select:focus {
            outline: none;
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
        }

        .filter-tab-pro {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s ease;
            cursor: pointer;
            background: white;
            color: #475569;
            border: 1px solid #e2e8f0;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-tab-pro:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #0f172a;
        }

        .filter-tab-pro.active {
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
            color: white;
            border-color: transparent;
            box-shadow: 0 1px 3px rgba(14, 165, 233, 0.2);
        }

        .filter-count {
            background: #f1f5f9;
            border-radius: 9999px;
            padding: 0.125rem 0.5rem;
            font-size: 0.6875rem;
            font-weight: 700;
            min-width: 1.5rem;
            text-align: center;
        }

        .filter-tab-pro.active .filter-count {
            background: rgba(255,255,255,0.2);
            color: white;
        }

        .badge-pro {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.6875rem;
            font-weight: 600;
            letter-spacing: 0.025em;
            border: 1px solid;
        }

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

        .modal {
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            position: fixed;
            inset: 0;
            z-index: 50;
        }
        
        .modal.show {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 16px;
            max-width: 500px;
            width: 90%;
            padding: 1.5rem;
            animation: modalSlideIn 0.3s ease;
        }
        
        .modal-content-large {
            max-width: 700px;
        }
        
        @keyframes modalSlideIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }

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

        .info-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 0.875rem 1rem;
            transition: all 0.2s ease;
        }

        .info-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .data-table {
            width: 100%;
        }
        
        .data-table th {
            padding: 0.75rem 1rem;
            text-align: left;
            font-size: 0.6875rem;
            font-weight: 600;
            color: #64748b;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .data-table td {
            padding: 0.875rem 1rem;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .data-table tr:hover {
            background: #fafcff;
        }

        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        
        .reason-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.25rem 0.625rem;
            border-radius: 6px;
            font-size: 0.6875rem;
            font-weight: 500;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }
        
        .action-btn {
            width: 2rem;
            height: 2rem;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            background: #f8fafc;
            color: #64748b;
            border: 1px solid #e2e8f0;
            cursor: pointer;
        }
        
        .action-btn:hover { 
            transform: translateY(-1px); 
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        
        .action-btn.approve:hover { 
            background: #ecfdf5; 
            color: #059669; 
            border-color: #a7f3d0; 
        }
        
        .action-btn.reject:hover { 
            background: #fef2f2; 
            color: #dc2626; 
            border-color: #fecaca; 
        }
        
        .action-btn.view:hover { 
            background: #f0f9ff; 
            color: #0284c7; 
            border-color: #bae6fd; 
        }
        
        .amount-cell {
            font-weight: 600;
            letter-spacing: -0.02em;
        }
        
        .id-badge {
            font-family: 'Inter', monospace;
            font-size: 0.6875rem;
            font-weight: 600;
            background: #f1f5f9;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            color: #475569;
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>

    <!-- Flash Messages -->
    <?php if ($message): ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
        <div class="flash-msg bg-white shadow-sm" style="border-color: <?php echo $messageType == 'success' ? '#d1fae5' : '#fee2e2'; ?>">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center <?php echo $messageType == 'success' ? 'bg-green-50 text-green-600' : 'bg-red-50 text-red-600'; ?>">
                    <i class="fas <?php echo $messageType == 'success' ? 'fa-check' : 'fa-exclamation'; ?> text-sm"></i>
                </div>
                <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($message); ?></p>
            </div>
            <button onclick="this.parentElement.remove()" class="text-gray-400 hover:text-gray-600 w-6 h-6 flex items-center justify-center rounded-lg hover:bg-gray-50 transition-colors">
                <i class="fas fa-times text-xs"></i>
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Hero Section -->
    <div class="hero-section py-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6">
                <div>
                    <p class="text-brand-300 text-sm font-medium tracking-wide uppercase mb-2">Returns Management</p>
                    <h1 class="text-2xl md:text-3xl font-bold text-white font-display">
                        Manage Returns
                    </h1>
                    <p class="text-brand-200/80 mt-2 text-sm max-w-md">Process customer return requests and manage refund workflows.</p>
                </div>
                <div class="flex gap-3">
                    <a href="dashboard.php" class="btn-secondary" style="border-color: rgba(255,255,255,0.2); color: white; background: rgba(255,255,255,0.1);">
                        <i class="fas fa-chart-line text-sm"></i> Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <!-- Statistics Cards -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
            <div class="stat-card">
                <div class="flex items-center gap-2 mb-2">
                    <div class="w-8 h-8 rounded-lg bg-amber-50 flex items-center justify-center text-amber-600">
                        <i class="fas fa-clock text-xs"></i>
                    </div>
                    <p class="text-xs text-gray-500 font-medium">Pending</p>
                </div>
                <p class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['pending_count']); ?></p>
            </div>
            <div class="stat-card">
                <div class="flex items-center gap-2 mb-2">
                    <div class="w-8 h-8 rounded-lg bg-emerald-50 flex items-center justify-center text-emerald-600">
                        <i class="fas fa-check-circle text-xs"></i>
                    </div>
                    <p class="text-xs text-gray-500 font-medium">Approved</p>
                </div>
                <p class="text-2xl font-bold text-emerald-600"><?php echo number_format($stats['approved_count']); ?></p>
            </div>
            <div class="stat-card">
                <div class="flex items-center gap-2 mb-2">
                    <div class="w-8 h-8 rounded-lg bg-red-50 flex items-center justify-center text-red-600">
                        <i class="fas fa-times-circle text-xs"></i>
                    </div>
                    <p class="text-xs text-gray-500 font-medium">Rejected</p>
                </div>
                <p class="text-2xl font-bold text-red-600"><?php echo number_format($stats['rejected_count']); ?></p>
            </div>
            <div class="stat-card">
                <div class="flex items-center gap-2 mb-2">
                    <div class="w-8 h-8 rounded-lg bg-blue-50 flex items-center justify-center text-blue-600">
                        <i class="fas fa-check-double text-xs"></i>
                    </div>
                    <p class="text-xs text-gray-500 font-medium">Completed</p>
                </div>
                <p class="text-2xl font-bold text-blue-600"><?php echo number_format($stats['completed_count']); ?></p>
            </div>
            <div class="stat-card">
                <div class="flex items-center gap-2 mb-2">
                    <div class="w-8 h-8 rounded-lg bg-purple-50 flex items-center justify-center text-purple-600">
                        <i class="fas fa-money-bill-wave text-xs"></i>
                    </div>
                    <p class="text-xs text-gray-500 font-medium">Total Refunded</p>
                </div>
                <p class="text-2xl font-bold text-purple-600">₱<?php echo number_format($stats['total_refund_amount'], 2); ?></p>
            </div>
        </div>

        <!-- Filter Tabs -->
        <div class="mb-6">
            <div class="flex flex-wrap gap-2">
                <a href="?status=all" class="filter-tab-pro <?php echo $statusFilter == 'all' ? 'active' : ''; ?>">
                    <i class="fas fa-list text-[10px]"></i>
                    All Returns
                    <span class="filter-count ml-1.5"><?php echo array_sum($stats); ?></span>
                </a>
                <a href="?status=pending" class="filter-tab-pro <?php echo $statusFilter == 'pending' ? 'active' : ''; ?>">
                    <i class="fas fa-clock text-[10px]"></i>
                    Pending
                    <span class="filter-count ml-1.5"><?php echo $stats['pending_count']; ?></span>
                </a>
                <a href="?status=approved" class="filter-tab-pro <?php echo $statusFilter == 'approved' ? 'active' : ''; ?>">
                    <i class="fas fa-check-circle text-[10px]"></i>
                    Approved
                    <span class="filter-count ml-1.5"><?php echo $stats['approved_count']; ?></span>
                </a>
                <a href="?status=rejected" class="filter-tab-pro <?php echo $statusFilter == 'rejected' ? 'active' : ''; ?>">
                    <i class="fas fa-times-circle text-[10px]"></i>
                    Rejected
                    <span class="filter-count ml-1.5"><?php echo $stats['rejected_count']; ?></span>
                </a>
            </div>
        </div>

        <!-- Advanced Filters -->
        <div class="pro-card p-5 mb-6">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-6 gap-4">
                <div class="md:col-span-2">
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($searchQuery); ?>" 
                               placeholder="Search by name, email, order #..." 
                               class="filter-input pl-10">
                    </div>
                </div>

                <div>
                    <select name="reason" class="filter-select" onchange="this.form.submit()">
                        <?php foreach ($reasonOptions as $value => $label): ?>
                            <option value="<?php echo $value; ?>" <?php echo $reasonFilter == $value ? 'selected' : ''; ?>>
                                <?php echo $label; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <select name="sort" class="filter-select" onchange="this.form.submit()">
                        <?php foreach ($sortOptions as $value => $label): ?>
                            <option value="<?php echo $value; ?>" <?php echo $sortBy == $value ? 'selected' : ''; ?>>
                                <?php echo $label; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <div class="flex gap-2">
                        <input type="date" name="date_from" value="<?php echo $dateFrom; ?>" class="filter-input" placeholder="From">
                        <span class="text-gray-400 self-center text-xs">→</span>
                        <input type="date" name="date_to" value="<?php echo $dateTo; ?>" class="filter-input" placeholder="To">
                    </div>
                </div>
                        <br>
                    <button type="submit" class="btn-brand flex-1 justify-center text-sm">
                        <i class="fas fa-filter text-xs"></i> Filter
                    </button>
                    <a href="returns.php" class="btn-secondary justify-center" title="Reset filters">
                        <i class="fas fa-redo-alt text-xs"></i>
                    </a>
            </form>
        </div>

        <!-- Reason Statistics -->
        <?php if (!empty($reasonStats)): ?>
        <div class="bg-white rounded-xl border border-gray-200 p-4 mb-6">
            <h3 class="text-xs font-semibold text-gray-700 mb-3 flex items-center gap-2 uppercase tracking-wide">
                <i class="fas fa-chart-pie text-brand-500"></i>
                Return Reasons
            </h3>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($reasonStats as $reason): 
                    $reasonInfo = $reasonConfig[$reason['reason']] ?? ['label' => ucfirst($reason['reason']), 'icon' => 'fa-question-circle', 'color' => 'text-slate-600'];
                    $isActive = $reasonFilter == $reason['reason'];
                ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['reason' => $reason['reason'], 'page' => 1])); ?>" 
                       class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-all <?php echo $isActive ? 'bg-brand-500 text-white shadow-sm' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                        <i class="fas <?php echo $reasonInfo['icon']; ?> text-[10px]"></i>
                        <?php echo $reasonInfo['label']; ?> 
                        <span class="tabular-nums <?php echo $isActive ? 'bg-white/20' : 'bg-white'; ?> px-1.5 py-0.5 rounded text-[10px] font-bold"><?php echo $reason['count']; ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Returns Table -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden border border-gray-100">
            <div class="px-5 py-3 bg-gray-50/50 border-b border-gray-100">
                <h3 class="font-semibold text-gray-900 text-sm flex items-center gap-2">
                    <i class="fas fa-list text-brand-500"></i>
                    Return Requests
                    <span class="text-xs font-medium text-gray-500 bg-gray-100 px-2 py-0.5 rounded-full"><?php echo count($returns); ?></span>
                </h3>
            </div>

            <?php if (empty($returns)): ?>
                <div class="text-center py-12">
                    <div class="empty-icon mx-auto">
                        <i class="fas fa-inbox"></i>
                    </div>
                    <h3 class="text-base font-semibold text-gray-700 mb-1">No return requests found</h3>
                    <p class="text-sm text-gray-400">Try adjusting your filters or search criteria</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th class="px-4 py-3">Return #</th>
                                <th class="px-4 py-3">Customer</th>
                                <th class="px-4 py-3">Order</th>
                                <th class="px-4 py-3">Reason</th>
                                <th class="px-4 py-3 text-right">Amount</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Date</th>
                                <th class="px-4 py-3 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($returns as $return): 
                                $statusInfo = $returnStatusConfig[$return['return_status']] ?? $returnStatusConfig['pending'];
                                $reasonInfo = $reasonConfig[$return['return_reason']] ?? ['label' => ucfirst($return['return_reason']), 'icon' => 'fa-question-circle'];
                                $refundPercent = ($return['return_amount'] / max($return['order_total'], 1)) * 100;
                            ?>
                                <tr class="hover:bg-gray-50 transition-smooth">
                                    <td class="px-4 py-3">
                                        <span class="id-badge">
                                            #<?php echo str_pad($return['return_id'], 5, '0', STR_PAD_LEFT); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-gray-900 text-sm"><?php echo htmlspecialchars($return['full_name'] ?? 'Unknown'); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($return['email'] ?? ''); ?></div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="id-badge">
                                            #<?php echo str_pad($return['order_id'], 6, '0', STR_PAD_LEFT); ?>
                                        </span>
                                        <div class="text-[10px] text-gray-400 mt-1">
                                            <?php echo date('M d, Y', strtotime($return['order_datetime'] ?? $return['request_date'])); ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="reason-chip">
                                            <i class="fas <?php echo $reasonInfo['icon']; ?> <?php echo $reasonInfo['color']; ?> text-[10px]"></i>
                                            <?php echo $reasonInfo['label']; ?>
                                        </span>
                                        <?php if ($refundPercent < 99): ?>
                                            <div class="text-[10px] text-gray-400 mt-1"><?php echo round($refundPercent, 0); ?>% of order</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="amount-cell text-gray-900">₱<?php echo number_format($return['return_amount'], 2); ?></div>
                                        <div class="text-[10px] text-gray-400">of ₱<?php echo number_format($return['order_total'], 2); ?></div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="badge-pro <?php echo $statusInfo['color']; ?>">
                                            <i class="fas <?php echo $statusInfo['icon']; ?> text-[8px]"></i>
                                            <?php echo ucfirst($return['return_status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-500">
                                        <?php echo date('M d, Y', strtotime($return['request_date'])); ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center justify-center gap-1.5">
                                            <button onclick='viewReturn(<?php echo json_encode($return); ?>)' 
                                                    class="action-btn view" title="View Details">
                                                <i class="fas fa-eye text-xs"></i>
                                            </button>

                                            <?php if ($return['return_status'] === 'pending'): ?>
                                                <button onclick='openApproveModal(<?php echo json_encode($return); ?>)' 
                                                        class="action-btn approve" title="Approve">
                                                    <i class="fas fa-check text-xs"></i>
                                                </button>
                                                <button onclick="openRejectModal(<?php echo $return['return_id']; ?>)" 
                                                        class="action-btn reject" title="Reject">
                                                    <i class="fas fa-times text-xs"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- View Return Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content modal-content-large">
            <div class="flex justify-between items-center mb-4 pb-3 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fas fa-undo-alt text-brand-500"></i>
                    Return Request Details
                </h3>
                <button onclick="closeViewModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            <div id="returnDetails" class="space-y-4 max-h-[60vh] overflow-y-auto"></div>
            <div class="mt-5 pt-3 border-t border-gray-100 flex justify-end">
                <button onclick="closeViewModal()" class="btn-secondary text-sm">Close</button>
            </div>
        </div>
    </div>

    <!-- Approve Return Modal -->
    <div id="approveModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4 pb-3 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fas fa-check-circle text-emerald-500"></i>
                    Approve Return Request
                </h3>
                <button onclick="closeApproveModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="return_id" id="approve_return_id">

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Manager Notes <span class="text-gray-400 text-xs">(Optional)</span></label>
                    <textarea name="manager_notes" rows="3" class="filter-input w-full resize-none" placeholder="Add any notes about this approval..."></textarea>
                </div>

                <div class="bg-emerald-50 rounded-lg p-3 mb-4 border border-emerald-100">
                    <p class="text-xs text-emerald-700 flex items-start gap-2">
                        <i class="fas fa-info-circle mt-0.5 text-emerald-500"></i>
                        <span>Approving this return will mark it as approved for refund processing.</span>
                    </p>
                </div>

                <div class="flex justify-end gap-3 pt-3 border-t border-gray-100">
                    <button type="button" onclick="closeApproveModal()" class="btn-secondary text-sm">Cancel</button>
                    <button type="submit" class="btn-success-pro text-sm">
                        <i class="fas fa-check"></i> Approve Return
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reject Return Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4 pb-3 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fas fa-times-circle text-red-500"></i>
                    Reject Return Request
                </h3>
                <button onclick="closeRejectModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="return_id" id="reject_return_id">

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Rejection Reason <span class="text-red-500">*</span></label>
                    <textarea name="rejection_reason" rows="3" required 
                              class="filter-input w-full resize-none"
                              placeholder="Please explain why this return is being rejected..."></textarea>
                </div>

                <div class="bg-red-50 rounded-lg p-3 mb-4 border border-red-100">
                    <p class="text-xs text-red-700 flex items-start gap-2">
                        <i class="fas fa-exclamation-triangle mt-0.5 text-red-500"></i>
                        <span>This action cannot be undone. The customer will be notified of the rejection.</span>
                    </p>
                </div>

                <div class="flex justify-end gap-3 pt-3 border-t border-gray-100">
                    <button type="button" onclick="closeRejectModal()" class="btn-secondary text-sm">Cancel</button>
                    <button type="submit" class="btn-danger-pro text-sm">
                        <i class="fas fa-times"></i> Reject Return
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
        function viewReturn(returnData) {
            const details = document.getElementById('returnDetails');
            const refundPercent = (returnData.return_amount / Math.max(returnData.order_total, 1) * 100).toFixed(0);
            
            const statusColor = returnData.return_status === 'pending' ? 'bg-amber-50 text-amber-700 border-amber-200' :
                               returnData.return_status === 'approved' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' :
                               returnData.return_status === 'rejected' ? 'bg-red-50 text-red-700 border-red-200' : 
                               'bg-blue-50 text-blue-700 border-blue-200';

            details.innerHTML = `
                <div class="grid grid-cols-2 gap-3">
                    <div class="info-card p-2">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wide">Return ID</p>
                        <p class="font-mono font-semibold text-gray-900 text-sm">#${String(returnData.return_id).padStart(5, '0')}</p>
                    </div>
                    <div class="info-card p-2">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wide">Order ID</p>
                        <p class="font-mono font-semibold text-gray-900 text-sm">#${String(returnData.order_id).padStart(6, '0')}</p>
                    </div>
                    <div class="info-card p-2">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wide">Customer Name</p>
                        <p class="font-medium text-gray-900 text-sm">${escapeHtml(returnData.full_name || 'Unknown')}</p>
                    </div>
                    <div class="info-card p-2">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wide">Email</p>
                        <p class="text-sm text-gray-600">${escapeHtml(returnData.email || 'N/A')}</p>
                    </div>
                    <div class="info-card p-2">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wide">Department</p>
                        <p class="text-sm text-gray-600">${escapeHtml(returnData.department || 'N/A')}</p>
                    </div>
                    <div class="info-card p-2">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wide">Payment Method</p>
                        <p class="text-sm text-gray-600 capitalize">${escapeHtml(returnData.payment_method || 'N/A').replace('_', ' ')}</p>
                    </div>
                    <div class="info-card p-2">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wide">Return Reason</p>
                        <p class="font-medium text-gray-900 text-sm capitalize">${escapeHtml((returnData.return_reason || '').replace('_', ' '))}</p>
                    </div>
                    <div class="info-card p-2">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wide">Refund %</p>
                        <p class="font-medium text-gray-900 text-sm">${refundPercent}% of order</p>
                    </div>
                    <div class="info-card p-2 bg-emerald-50 border-emerald-100">
                        <p class="text-[10px] text-emerald-600 uppercase tracking-wide">Return Amount</p>
                        <p class="amount-cell text-base text-emerald-700">₱${Number(returnData.return_amount).toLocaleString(undefined, {minimumFractionDigits: 2})}</p>
                    </div>
                    <div class="info-card p-2">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wide">Order Total</p>
                        <p class="amount-cell text-gray-700">₱${Number(returnData.order_total).toLocaleString(undefined, {minimumFractionDigits: 2})}</p>
                    </div>
                    <div class="info-card p-2">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wide">Request Date</p>
                        <p class="text-sm text-gray-600">${new Date(returnData.request_date).toLocaleString()}</p>
                    </div>
                    <div class="info-card p-2">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wide">Status</p>
                        <span class="badge-pro ${statusColor} text-xs">
                            <i class="fas ${returnData.return_status === 'pending' ? 'fa-clock' : returnData.return_status === 'approved' ? 'fa-check-circle' : returnData.return_status === 'rejected' ? 'fa-times-circle' : 'fa-check-double'}"></i>
                            ${returnData.return_status ? returnData.return_status.charAt(0).toUpperCase() + returnData.return_status.slice(1) : 'N/A'}
                        </span>
                    </div>
                    ${returnData.return_description ? `
                    <div class="col-span-2 info-card p-3">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wide mb-1">Description</p>
                        <p class="text-sm text-gray-600 leading-relaxed">${escapeHtml(returnData.return_description)}</p>
                    </div>
                    ` : ''}
                    ${returnData.processed_remarks ? `
                    <div class="col-span-2 info-card p-3 bg-gray-50">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wide mb-1">Manager Remarks</p>
                        <p class="text-sm text-gray-600 leading-relaxed">${escapeHtml(returnData.processed_remarks)}</p>
                        ${returnData.processed_date ? `<p class="text-[10px] text-gray-400 mt-2">Processed: ${new Date(returnData.processed_date).toLocaleString()}</p>` : ''}
                    </div>
                    ` : ''}
                </div>
            `;

            document.getElementById('viewModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function closeViewModal() { 
            document.getElementById('viewModal').classList.remove('show');
            document.body.style.overflow = 'auto';
        }

        function openApproveModal(returnData) {
            document.getElementById('approve_return_id').value = returnData.return_id;
            document.getElementById('approveModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeApproveModal() { 
            document.getElementById('approveModal').classList.remove('show');
            document.body.style.overflow = 'auto';
        }

        function openRejectModal(returnId) {
            document.getElementById('reject_return_id').value = returnId;
            document.getElementById('rejectModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeRejectModal() { 
            document.getElementById('rejectModal').classList.remove('show');
            document.body.style.overflow = 'auto';
        }

        // Auto-dismiss flash messages
        setTimeout(() => {
            document.querySelectorAll('.flash-msg').forEach(msg => {
                msg.style.transition = 'all 0.4s ease';
                msg.style.opacity = '0';
                msg.style.transform = 'translateY(-8px)';
                setTimeout(() => msg.remove(), 400);
            });
        }, 5000);

        // Close modals on Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeViewModal();
                closeApproveModal();
                closeRejectModal();
            }
        });
    </script>
</body>
</html>