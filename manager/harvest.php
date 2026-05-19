<?php
// manager/harvest.php - Professional UI (Simplified - Finished Harvest only)
require_once '../includes/config.php';
require_once '../includes/session.php';

// Only allow managers
SessionManager::requireManagerOrStaff();

$functions = new SystemFunctions();
$userId = SessionManager::getUserId();
$db = (new Database())->getConnection();

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_harvest':
                try {
                    $db->beginTransaction();
                    
                    $checkSql = "SELECT harvest_id FROM harvest WHERE batch_no = :batch_no";
                    $checkStmt = $db->prepare($checkSql);
                    $checkStmt->execute([':batch_no' => $_POST['batch_no']]);
                    
                    if ($checkStmt->fetch()) {
                        throw new Exception("A harvest with this batch number already exists.");
                    }
                    
                    $fishProductId = !empty($_POST['fish_product_id']) ? (int)$_POST['fish_product_id'] : null;
                    $totalQuantity = floatval($_POST['total_quantity']);
                    
                    // FIXED: Removed duplicate created_at and updated_at - the table already has these with defaults
                    $sql = "INSERT INTO harvest (fish_product_id, batch_no, location, total_quantity, remaining_quantity, status) 
                            VALUES (:fpid, :batch_no, :location, :total_quantity, :remaining_quantity, 'completed') 
                            RETURNING harvest_id";
                    $stmt = $db->prepare($sql);
                    $stmt->execute([
                        ':fpid'               => $fishProductId,
                        ':batch_no'           => $_POST['batch_no'],
                        ':location'           => $_POST['location'],
                        ':total_quantity'     => $totalQuantity,
                        ':remaining_quantity' => $totalQuantity
                    ]);
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $harvestId = $result['harvest_id'];
                    
                    $db->commit();
                    
                    $message = "Harvest added successfully! Batch #" . htmlspecialchars($_POST['batch_no']) . " has been recorded as completed harvest.";
                    $messageType = 'success';
                    
                } catch (Exception $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $message = "Error adding harvest: " . $e->getMessage();
                    $messageType = 'error';
                }
                break;
                
            case 'update_harvest':
                try {
                    $totalQuantity = floatval($_POST['total_quantity']);
                    $remainingQuantity = floatval($_POST['remaining_quantity']);
                    
                    if ($remainingQuantity > $totalQuantity) {
                        throw new Exception("Remaining quantity cannot exceed total quantity.");
                    }
                    
                    // Update status based on remaining quantity
                    $newStatus = ($remainingQuantity <= 0) ? 'depleted' : 'completed';
                    
                    $sql = "UPDATE harvest SET 
                            batch_no = :batch_no,
                            location = :location,
                            total_quantity = :total_quantity,
                            remaining_quantity = :remaining_quantity,
                            status = :status,
                            updated_at = NOW()
                            WHERE harvest_id = :harvest_id";
                    $stmt = $db->prepare($sql);
                    $stmt->execute([
                        ':batch_no' => $_POST['batch_no'],
                        ':location' => $_POST['location'],
                        ':total_quantity' => $totalQuantity,
                        ':remaining_quantity' => $remainingQuantity,
                        ':status' => $newStatus,
                        ':harvest_id' => $_POST['harvest_id']
                    ]);
                    
                    $message = "Harvest updated successfully!";
                    $messageType = 'success';
                } catch (Exception $e) {
                    $message = $e->getMessage();
                    $messageType = 'error';
                }
                break;
        }
    }
}

// Fetch fish products for the dropdown in Add Harvest form
try {
    $fishProductsStmt = $db->query("SELECT product_id, fish_name FROM fish_products ORDER BY fish_name ASC");
    $fishProductsList = $fishProductsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $fishProductsList = [];
}

// Get filter parameters
$statusFilter = $_GET['status'] ?? 'all';
$monthFilter = $_GET['month'] ?? '';
$yearFilter = $_GET['year'] ?? '';
$searchQuery = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 9;
$offset = ($page - 1) * $perPage;

// Get total count for pagination
try {
    $countSql = "SELECT COUNT(*) as total FROM harvest WHERE 1=1";
    $countParams = [];
    
    if ($statusFilter !== 'all') {
        $countSql .= " AND status = :status";
        $countParams[':status'] = $statusFilter;
    }
    
    if ($monthFilter && $yearFilter) {
        $countSql .= " AND DATE_PART('year', created_at) = :year AND DATE_PART('month', created_at) = :month";
        $countParams[':year'] = $yearFilter;
        $countParams[':month'] = $monthFilter;
    }
    
    if ($searchQuery) {
        $countSql .= " AND batch_no ILIKE :search";
        $countParams[':search'] = "%$searchQuery%";
    }
    
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($countParams);
    $totalRecords = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalRecords / $perPage);
    
} catch (PDOException $e) {
    error_log("Count error: " . $e->getMessage());
    $totalRecords = 0;
    $totalPages = 0;
}

// Get harvests with pagination
try {
    $sql = "SELECT 
                h.*,
                fp.fish_name,
                COALESCE(SUM(hc.quantity_used), 0) as total_consumed,
                CASE WHEN h.fish_product_id IS NOT NULL THEN 1 ELSE 0 END as total_products,
                h.remaining_quantity as total_available_quantity
            FROM harvest h
            LEFT JOIN fish_products fp ON fp.product_id = h.fish_product_id
            LEFT JOIN harvest_consumption hc ON hc.harvest_id = h.harvest_id
            WHERE 1=1";
    
    $params = [];
    
    if ($statusFilter !== 'all') {
        $sql .= " AND h.status = :status";
        $params[':status'] = $statusFilter;
    }
    
    if ($monthFilter && $yearFilter) {
        $sql .= " AND DATE_PART('year', h.created_at) = :year AND DATE_PART('month', h.created_at) = :month";
        $params[':year'] = $yearFilter;
        $params[':month'] = $monthFilter;
    }
    
    if ($searchQuery) {
        $sql .= " AND h.batch_no ILIKE :search";
        $params[':search'] = "%$searchQuery%";
    }
    
    $sql .= " GROUP BY h.harvest_id, fp.fish_name ORDER BY h.created_at DESC, h.harvest_id DESC LIMIT :limit OFFSET :offset";
    $params[':limit'] = $perPage;
    $params[':offset'] = $offset;
    
    $stmt = $db->prepare($sql);
    
    foreach ($params as $key => $value) {
        if ($key == ':limit' || $key == ':offset') {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    
    $stmt->execute();
    $harvests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get available months for filter
    $monthsSql = "SELECT DISTINCT 
                    EXTRACT(YEAR FROM created_at) as year,
                    EXTRACT(MONTH FROM created_at) as month,
                    TO_CHAR(created_at, 'YYYY-MM') as month_key,
                    TO_CHAR(created_at, 'FMMonth YYYY') as month_name
                  FROM harvest 
                  ORDER BY year DESC, month DESC";
    $monthsStmt = $db->query($monthsSql);
    $availableMonths = $monthsStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Harvests fetch error: " . $e->getMessage());
    $harvests = [];
    $availableMonths = [];
    $message = "Database Error: " . $e->getMessage();
    $messageType = 'error';
}

$statusOptions = [
    'all' => 'All Harvests',
    'completed' => 'Completed',
    'depleted' => 'Depleted'
];

$statusColors = [
    'completed' => 'bg-green-50 text-green-700 border-green-200',
    'depleted' => 'bg-gray-50 text-gray-700 border-gray-200'
];

$statusIcons = [
    'completed' => 'fa-check-circle',
    'depleted' => 'fa-ban'
];

function getUsagePercentage($total, $remaining) {
    if ($total <= 0) return 0;
    $used = $total - $remaining;
    return ($used / $total) * 100;
}

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
    <title>Manage Harvests - BISU IGE Aquaculture</title>
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

        .harvest-card {
            background: var(--bg-secondary);
            border-radius: 16px;
            border: 1px solid var(--border);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .harvest-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 30px -8px rgba(0, 0, 0, 0.12);
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
            border-radius: 10px;
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

        .btn-outline-brand {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: transparent;
            border: 1.5px solid rgba(255, 255, 255, 0.3);
            color: white;
            padding: 0.625rem 1.25rem;
            border-radius: 10px;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-outline-brand:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.5);
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
            font-size: 0.75rem;
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

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.6875rem;
            font-weight: 600;
            letter-spacing: 0.025em;
            text-transform: uppercase;
            border: 1px solid;
        }

        .filter-input {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 0.625rem 0.875rem;
            font-size: 0.875rem;
            transition: all 0.2s ease;
            background: white;
            width: 100%;
        }
        
        .filter-input:focus {
            outline: none;
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
        }

        .filter-select {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 0.625rem 0.875rem;
            font-size: 0.875rem;
            background: white;
            width: 100%;
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%236b7280'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 1rem;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
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
            border-radius: 20px;
            max-width: 500px;
            width: 90%;
            padding: 1.5rem;
            animation: modalSlideIn 0.3s ease;
        }
        
        @keyframes modalSlideIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }

        .flash-msg {
            padding: 1rem 1.25rem;
            border-radius: 12px;
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

        .progress-bar {
            background: linear-gradient(90deg, #0ea5e9, #8b5cf6);
            height: 100%;
            border-radius: 20px;
            transition: width 0.3s ease;
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

        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        .info-note {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 10px;
            padding: 0.75rem;
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>

    <!-- Flash Messages -->
    <?php if ($message): ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
        <div class="flash-msg bg-white shadow-sm" style="border-left: 4px solid <?php echo $messageType == 'success' ? '#10b981' : ($messageType == 'warning' ? '#f59e0b' : '#ef4444'); ?>">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center <?php echo $messageType == 'success' ? 'bg-green-50 text-green-600' : ($messageType == 'warning' ? 'bg-amber-50 text-amber-600' : 'bg-red-50 text-red-600'); ?>">
                    <i class="fas <?php echo $messageType == 'success' ? 'fa-check' : ($messageType == 'warning' ? 'fa-exclamation-triangle' : 'fa-exclamation'); ?> text-sm"></i>
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
    <div class="hero-section py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6">
                <div>
                    <p class="text-brand-300 text-sm font-medium tracking-wide uppercase mb-2">Inventory Management</p>
                    <h1 class="text-3xl md:text-4xl font-bold text-white font-display">
                        Manage Harvests
                    </h1>
                    <p class="text-brand-200/80 mt-2 text-sm max-w-md">Record completed harvest batches. All harvests are automatically marked as finished.</p>
                </div>
                <button onclick="openAddHarvestModal()" class="btn-outline-brand">
                    <i class="fas fa-plus text-sm"></i>
                    Add New Harvest
                </button>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <!-- Filters Section -->
        <div class="pro-card p-5 mb-8">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                <div class="md:col-span-2">
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($searchQuery); ?>" 
                               placeholder="Search batch number..." 
                               class="filter-input pl-10">
                    </div>
                </div>
                
                <div>
                    <select name="status" class="filter-select" onchange="this.form.submit()">
                        <?php foreach ($statusOptions as $value => $label): ?>
                            <option value="<?php echo $value; ?>" <?php echo $statusFilter == $value ? 'selected' : ''; ?>>
                                <?php echo $label; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <select name="month" class="filter-select" id="monthSelect">
                        <option value="">All Months</option>
                        <?php foreach ($availableMonths as $month): ?>
                            <option value="<?php echo $month['month']; ?>" 
                                    data-year="<?php echo $month['year']; ?>"
                                    <?php echo ($monthFilter == $month['month'] && $yearFilter == $month['year']) ? 'selected' : ''; ?>>
                                <?php echo $month['month_name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="year" id="yearFilter" value="<?php echo $yearFilter; ?>">
                </div>
                
                <div>
                    <button type="submit" class="btn-brand w-full justify-center">
                        <i class="fas fa-filter text-sm"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>

        <!-- Harvests Grid -->
        <?php if (empty($harvests)): ?>
            <div class="pro-card p-12 text-center">
                <div class="empty-icon">
                    <i class="fas fa-tractor"></i>
                </div>
                <h3 class="text-lg font-semibold text-gray-900 mb-2">No harvests found</h3>
                <p class="text-sm text-gray-500 mb-6">There are no harvests matching your criteria.</p>
                <button onclick="openAddHarvestModal()" class="btn-brand">
                    <i class="fas fa-plus text-sm"></i>
                    Add New Harvest
                </button>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($harvests as $harvest): 
                    $statusValue = $harvest['status'] ?? 'completed';
                    $statusColor = $statusColors[$statusValue] ?? 'bg-blue-50 text-blue-700 border-blue-200';
                    $statusIcon = $statusIcons[$statusValue] ?? 'fa-check-circle';
                    $usagePercentage = getUsagePercentage($harvest['total_quantity'], $harvest['remaining_quantity']);
                    $isLowStock = $harvest['remaining_quantity'] < ($harvest['total_quantity'] * 0.2);
                ?>
                    <div class="harvest-card">
                        <div class="p-5 flex flex-col h-full">
                            <!-- Header -->
                            <div class="flex justify-between items-start mb-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-xl bg-gradient-to-r from-brand-500 to-brand-700 flex items-center justify-center shadow-sm">
                                        <i class="fas fa-tag text-white text-sm"></i>
                                    </div>
                                    <div>
                                        <h3 class="font-bold text-gray-900">
                                            <?php echo htmlspecialchars($harvest['batch_no']); ?>
                                        </h3>
                                        <div class="flex items-center gap-2 mt-1">
                                            <i class="far fa-calendar-alt text-gray-400 text-xs"></i>
                                            <span class="text-xs text-gray-500">
                                                <?php echo date('M d, Y', strtotime($harvest['created_at'])); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <span class="status-badge <?php echo $statusColor; ?>">
                                    <i class="fas <?php echo $statusIcon; ?> text-[8px]"></i>
                                    <?php echo ucfirst($statusValue); ?>
                                </span>
                            </div>
                            
                            <!-- Location -->
                            <?php if (!empty($harvest['location'])): ?>
                            <div class="mb-4 flex items-center gap-2 text-sm text-gray-600">
                                <i class="fas fa-map-marker-alt text-gray-400 text-xs"></i>
                                <span><?php echo htmlspecialchars($harvest['location']); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Fish Product Info -->
                            <?php if (!empty($harvest['fish_name'])): ?>
                            <div class="mb-3 p-2 bg-gray-50 rounded-lg">
                                <div class="flex items-center gap-2 text-xs text-gray-600">
                                    <i class="fas fa-fish text-brand-500"></i>
                                    <span><?php echo htmlspecialchars($harvest['fish_name']); ?></span>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Quantity Stats -->
                            <div class="bg-gray-50 rounded-xl p-3 mb-4">
                                <div class="flex justify-between mb-2">
                                    <span class="text-xs text-gray-500">Total Quantity</span>
                                    <span class="text-sm font-bold text-gray-900"><?php echo number_format($harvest['total_quantity'], 2); ?> kg</span>
                                </div>
                                <div class="flex justify-between mb-2">
                                    <span class="text-xs text-gray-500">Remaining</span>
                                    <span class="text-sm font-bold <?php echo $isLowStock ? 'text-amber-600' : 'text-emerald-600'; ?>">
                                        <?php echo number_format($harvest['remaining_quantity'], 2); ?> kg
                                    </span>
                                </div>
                                <div class="mt-2">
                                    <div class="w-full bg-gray-200 rounded-full h-1.5">
                                        <div class="progress-bar" style="width: <?php echo $usagePercentage; ?>%"></div>
                                    </div>
                                    <p class="text-[10px] text-gray-400 mt-1">
                                        <?php echo number_format($usagePercentage, 1); ?>% used
                                    </p>
                                </div>
                            </div>
                            
                            <!-- Products Stats -->
                            <?php if (isset($harvest['total_products']) && intval($harvest['total_products']) > 0): ?>
                            <div class="mb-4 p-3 bg-brand-50 rounded-xl">
                                <div class="flex justify-between items-center">
                                    <span class="text-xs text-gray-600">
                                        <i class="fas fa-box text-brand-500 mr-1"></i>
                                        Products Created
                                    </span>
                                    <span class="text-sm font-bold text-brand-600">
                                        <?php echo intval($harvest['total_products']); ?> products
                                    </span>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Action Buttons -->
                            <div class="grid grid-cols-2 gap-2 mt-auto">
                                <a href="products.php?harvest_id=<?php echo $harvest['harvest_id']; ?>" 
                                   class="btn-secondary text-sm">
                                    <i class="fas fa-box"></i>
                                    Products
                                </a>
                                <button onclick='editHarvest(<?php echo json_encode($harvest); ?>)' 
                                        class="btn-secondary text-sm">
                                    <i class="fas fa-edit"></i>
                                    Edit
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="mt-8">
                <?php 
                $queryParams = $_GET;
                unset($queryParams['page']);
                echo buildPaginationLinks($page, $totalPages, $queryParams); 
                ?>
                <div class="text-center text-xs text-gray-400 mt-3">
                    Showing <?php echo count($harvests); ?> of <?php echo number_format($totalRecords); ?> harvests
                    <span class="mx-2">•</span>
                    Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Add Harvest Modal -->
    <div id="addHarvestModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-5 pb-3 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fas fa-plus-circle text-brand-500"></i>
                    Record Finished Harvest
                </h3>
                <button onclick="closeAddHarvestModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            
            <form method="POST" action="" id="addHarvestForm">
                <input type="hidden" name="action" value="add_harvest">
                
                <div class="space-y-4">
                    <div class="info-note text-xs text-brand-700">
                        <i class="fas fa-info-circle mr-1"></i> 
                        Harvest will be automatically recorded with today's date and marked as <strong>Completed</strong>.
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            Fish Product <span class="text-gray-400">(Optional)</span>
                        </label>
                        <select name="fish_product_id" class="filter-select">
                            <option value="">— Select fish product —</option>
                            <?php foreach ($fishProductsList as $fp): ?>
                            <option value="<?= $fp['product_id'] ?>"><?= htmlspecialchars($fp['fish_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-[10px] text-gray-400 mt-1">Link this batch to an existing fish product</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            Batch Number <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="batch_no" required 
                               class="filter-input" placeholder="e.g., H-2024-001, TILAPIA-BATCH-1">
                        <p class="text-[10px] text-gray-400 mt-1">Unique identifier for this harvest batch</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            Location <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="location" required 
                               class="filter-input" placeholder="e.g., Pond A, Cage 1, etc.">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            Total Quantity (kg) <span class="text-red-500">*</span>
                        </label>
                        <input type="number" name="total_quantity" required 
                               step="0.01" min="0.01" class="filter-input" placeholder="e.g., 1000.00">
                        <p class="text-[10px] text-gray-400 mt-1">Total harvest weight in kilograms</p>
                    </div>
                </div>
                
                <div class="flex justify-end gap-3 mt-6 pt-3 border-t border-gray-100">
                    <button type="button" onclick="closeAddHarvestModal()" class="btn-secondary">Cancel</button>
                    <button type="submit" class="btn-brand"><i class="fas fa-save"></i> Record Harvest</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Harvest Modal -->
    <div id="editHarvestModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-5 pb-3 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fas fa-edit text-amber-500"></i>
                    Edit Harvest
                </h3>
                <button onclick="closeEditHarvestModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            
            <form method="POST" action="" id="editHarvestForm">
                <input type="hidden" name="action" value="update_harvest">
                <input type="hidden" name="harvest_id" id="edit_harvest_id">
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            Batch Number <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="batch_no" id="edit_batch_no" required class="filter-input">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            Location <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="location" id="edit_location" required class="filter-input">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            Total Quantity (kg) <span class="text-red-500">*</span>
                        </label>
                        <input type="number" name="total_quantity" id="edit_total_quantity" required 
                               step="0.01" min="0.01" class="filter-input">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            Remaining Quantity (kg) <span class="text-red-500">*</span>
                        </label>
                        <input type="number" name="remaining_quantity" id="edit_remaining_quantity" required 
                               step="0.01" min="0" class="filter-input">
                        <p class="text-[10px] text-gray-400 mt-1">Current available quantity from this harvest</p>
                    </div>
                </div>
                
                <div class="flex justify-end gap-3 mt-6 pt-3 border-t border-gray-100">
                    <button type="button" onclick="closeEditHarvestModal()" class="btn-secondary">Cancel</button>
                    <button type="submit" class="btn-brand"><i class="fas fa-save"></i> Update Harvest</button>
                </div>
            </form>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
        function openAddHarvestModal() {
            document.getElementById('addHarvestModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function closeAddHarvestModal() {
            document.getElementById('addHarvestModal').classList.remove('show');
            document.body.style.overflow = 'auto';
        }
        
        function editHarvest(harvest) {
            document.getElementById('edit_harvest_id').value = harvest.harvest_id;
            document.getElementById('edit_batch_no').value = harvest.batch_no;
            document.getElementById('edit_location').value = harvest.location || '';
            document.getElementById('edit_total_quantity').value = harvest.total_quantity;
            document.getElementById('edit_remaining_quantity').value = harvest.remaining_quantity;
            
            document.getElementById('editHarvestModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function closeEditHarvestModal() {
            document.getElementById('editHarvestModal').classList.remove('show');
            document.body.style.overflow = 'auto';
        }
        
        // Month filter with year
        const monthSelect = document.getElementById('monthSelect');
        const yearInput = document.getElementById('yearFilter');
        
        if (monthSelect) {
            monthSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const year = selectedOption.dataset.year;
                if (year) {
                    yearInput.value = year;
                } else {
                    yearInput.value = '';
                }
                this.form.submit();
            });
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
                closeAddHarvestModal();
                closeEditHarvestModal();
            }
        });
        
        // Validate remaining quantity
        const remainingInput = document.getElementById('edit_remaining_quantity');
        const totalInput = document.getElementById('edit_total_quantity');
        
        function validateRemaining() {
            const total = parseFloat(totalInput.value) || 0;
            const remaining = parseFloat(remainingInput.value) || 0;
            if (remaining > total) {
                remainingInput.value = total;
                alert('Remaining quantity cannot exceed total quantity.');
            }
        }
        
        if (remainingInput && totalInput) {
            remainingInput.addEventListener('change', validateRemaining);
            remainingInput.addEventListener('input', validateRemaining);
        }
    </script>
</body>
</html> 