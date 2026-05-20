<?php
// accounting/dashboard.php - Accounting Dashboard for Unpaid Orders Monitoring
require_once '../includes/config.php';
require_once '../includes/session.php';

SessionManager::requireAccounting();

$functions = new SystemFunctions();
$userId = SessionManager::getUserId();
$user = $functions->getUserById($userId);

// Filter parameters
$filterEmployee = $_GET['employee'] ?? '';
$filterDepartment = $_GET['department'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';
$sortBy = $_GET['sort_by'] ?? 'balance_desc';

$unpaidDeductions = [];
$summary = [
    'total_unpaid_count' => 0,
    'total_unpaid' => 0,
    'total_recovered' => 0,
    'unique_customers' => 0,
    'by_status' => ['pending' => 0, 'partial' => 0, 'active' => 0]
];

try {
    $db = (new Database())->getConnection();

    $whereConditions = ["sd.deduction_status IN ('pending','partial','active')"];
    $params = [];

    if (!empty($filterEmployee)) {
        $whereConditions[] = "(u.full_name LIKE :employee OR u.email LIKE :employee OR u.employee_id LIKE :employee)";
        $params[':employee'] = '%' . $filterEmployee . '%';
    }

    if (!empty($filterDepartment)) {
        $whereConditions[] = "u.department = :department";
        $params[':department'] = $filterDepartment;
    }

    if (!empty($filterStatus)) {
        $whereConditions[] = "sd.deduction_status = :status";
        $params[':status'] = $filterStatus;
    }

    if (!empty($filterDateFrom)) {
        $whereConditions[] = "DATE(o.order_date) >= :date_from";
        $params[':date_from'] = $filterDateFrom;
    }

    if (!empty($filterDateTo)) {
        $whereConditions[] = "DATE(o.order_date) <= :date_to";
        $params[':date_to'] = $filterDateTo;
    }

    $whereClause = "WHERE " . implode(" AND ", $whereConditions);

    // Sorting logic
    $orderBy = "sd.remaining_balance DESC";
    switch ($sortBy) {
        case 'balance_asc':
            $orderBy = "sd.remaining_balance ASC";
            break;
        case 'date_oldest':
            $orderBy = "o.order_date ASC";
            break;
        case 'date_newest':
            $orderBy = "o.order_date DESC";
            break;
        case 'amount_desc':
            $orderBy = "sd.total_amount DESC";
            break;
        case 'amount_asc':
            $orderBy = "sd.total_amount ASC";
            break;
        case 'employee_asc':
            $orderBy = "u.full_name ASC";
            break;
        default:
            $orderBy = "sd.remaining_balance DESC";
    }

    $unpaidSql = "SELECT sd.*, u.full_name as customer_name, u.email, u.department, u.position, u.employee_id,
                         o.order_date, o.total_amount as order_total, o.payment_method, o.remarks as order_remarks
                  FROM salary_deductions sd
                  JOIN users u ON sd.user_id = u.user_id
                  LEFT JOIN orders o ON sd.order_id = o.order_id
                  $whereClause
                  AND sd.deduction_id IN (SELECT MAX(deduction_id) FROM salary_deductions WHERE deduction_status IN ('pending','partial','active') GROUP BY order_id)
                  ORDER BY $orderBy";
    $stmt = $db->prepare($unpaidSql);
    $stmt->execute($params);
    $unpaidDeductions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $summary['total_unpaid_count'] = count($unpaidDeductions);
    $summary['total_unpaid'] = array_sum(array_column($unpaidDeductions, 'remaining_balance'));
    $summary['total_recovered'] = array_sum(array_column($unpaidDeductions, 'amount_paid'));

    $uniqueCustomers = [];
    foreach ($unpaidDeductions as $deduction) {
        $status = $deduction['deduction_status'];
        if (isset($summary['by_status'][$status])) {
            $summary['by_status'][$status]++;
        }
        $uniqueCustomers[$deduction['user_id']] = true;
    }
    $summary['unique_customers'] = count($uniqueCustomers);

    foreach ($unpaidDeductions as &$deduction) {
        $h = $db->prepare("SELECT * FROM deduction_history WHERE deduction_id = :id ORDER BY created_at DESC");
        $h->execute([':id' => $deduction['deduction_id']]);
        $deduction['payment_history'] = $h->fetchAll(PDO::FETCH_ASSOC);
        $deduction['total_paid'] = array_sum(array_column($deduction['payment_history'], 'amount_deducted'));
        
        // Get order items for this deduction
        $itemsStmt = $db->prepare("
            SELECT oi.*, fp.fish_name, fp.price_per_kg 
            FROM order_items oi 
            JOIN fish_products fp ON oi.product_id = fp.product_id 
            WHERE oi.order_id = :order_id
        ");
        $itemsStmt->execute([':order_id' => $deduction['order_id']]);
        $deduction['order_items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($deduction);

    // Get departments for filter dropdown
    $deptStmt = $db->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department");
    $departments = $deptStmt->fetchAll(PDO::FETCH_COLUMN);

} catch (PDOException $e) {
    error_log("Accounting dashboard error: " . $e->getMessage());
    $unpaidDeductions = [];
    $departments = [];
}

$preparedBy = htmlspecialchars($user['full_name'] ?? 'Accounting Staff');
$preparedDate = date('F d, Y');
$reportTitle = 'UNPAID SALARY DEDUCTIONS REPORT';
$reportSubtitle = 'Outstanding Employee Balances - Accounting View';

// Build query string for actions
$currentParams = http_build_query([
    'employee' => $filterEmployee,
    'department' => $filterDepartment,
    'status' => $filterStatus,
    'date_from' => $filterDateFrom,
    'date_to' => $filterDateTo,
    'sort_by' => $sortBy
]);

$periodDisplay = '';
if (!empty($filterDateFrom) || !empty($filterDateTo)) {
    $from = !empty($filterDateFrom) ? date('F d, Y', strtotime($filterDateFrom)) : 'Start';
    $to = !empty($filterDateTo) ? date('F d, Y', strtotime($filterDateTo)) : 'End';
    $periodDisplay = $from . ' - ' . $to;
} else {
    $periodDisplay = 'All Time';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounting Dashboard - Unpaid Orders | BISU IGE</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #e2e8f0;
            color: #0f172a;
        }

        /* ============================================ */
        /* FIXED TOP BAR - Always visible when scrolling */
        /* ============================================ */
        .fixed-top-bar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            background: white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            border-bottom: 1px solid #e2e8f0;
        }

        /* Header inside fixed bar */
        .fixed-header {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: white;
            padding: 12px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .fixed-header .logo h1 {
            font-size: 18px;
            font-weight: 700;
        }

        .fixed-header .logo p {
            font-size: 10px;
            color: #94a3b8;
            margin-top: 2px;
        }

        .fixed-header .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            background: rgba(255,255,255,0.1);
            padding: 6px 15px;
            border-radius: 40px;
        }

        .fixed-header .user-info i {
            font-size: 16px;
            color: #0ea5e9;
        }

        .fixed-header .logout-btn {
            background: #ef4444;
            color: white;
            padding: 6px 14px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 500;
            transition: background 0.2s;
        }

        /* Filter Bar inside fixed top bar */
        .filter-bar {
            background: white;
            padding: 15px 24px;
            border-bottom: 1px solid #e2e8f0;
        }

        .filter-title {
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 12px;
            color: #0f172a;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 12px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .filter-group label {
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            color: #64748b;
            letter-spacing: 0.5px;
        }

        .filter-group input,
        .filter-group select {
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 12px;
            font-family: 'Inter', sans-serif;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #0ea5e9;
            box-shadow: 0 0 0 3px rgba(14,165,233,0.1);
        }

        .filter-actions {
            display: flex;
            gap: 8px;
            align-items: flex-end;
        }

        .btn-filter {
            background: #0ea5e9;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 12px;
            cursor: pointer;
            transition: background 0.2s;
        }

        .btn-filter:hover {
            background: #0284c7;
        }

        .btn-reset {
            background: #e2e8f0;
            color: #475569;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 12px;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.2s;
        }

        .btn-reset:hover {
            background: #cbd5e1;
        }

        /* Sorting Bar inside fixed top bar */
        .sorting-bar {
            background: #f8fafc;
            padding: 10px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            border-bottom: 1px solid #e2e8f0;
        }

        .sort-label {
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
        }

        .sort-options {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .sort-btn {
            padding: 4px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            background: white;
            font-size: 11px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            color: #475569;
        }

        .sort-btn.active {
            background: #0ea5e9;
            border-color: #0ea5e9;
            color: white;
        }

        .sort-btn:hover:not(.active) {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }

        /* Action Buttons inside fixed top bar */
        .action-buttons {
            background: #f8fafc;
            padding: 10px 24px;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            flex-wrap: wrap;
            border-bottom: 1px solid #e2e8f0;
        }

        .action-btn {
            padding: 6px 16px;
            border: none;
            font-size: 12px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
            color: white;
            border-radius: 6px;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-print {
            background: #1e3a8a;
        }

        .btn-pdf {
            background: #b91c1c;
        }

        .btn-export {
            background: #059669;
        }

        .action-btn:hover {
            opacity: 0.85;
            transform: translateY(-1px);
        }

        /* ============================================ */
        /* MAIN CONTENT - Paper View like Microsoft Word */
        /* ============================================ */
        .main-content {
            margin-top: 280px;
            padding: 30px 20px;
            display: flex;
            justify-content: center;
        }

        /* Paper size: 8.5 x 13 inches */
        .paper-container {
            max-width: 8.5in;
            width: 100%;
            margin: 0 auto;
            background: white;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            border-radius: 2px;
        }

        .paper-content {
            padding: 0.8in 0.7in;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11pt;
            line-height: 1.4;
        }

        /* Consistent font sizes for paper content */
        .paper-content h1, .paper-content h2, .paper-content h3, 
        .paper-content p, .paper-content table, .paper-content div,
        .paper-content span, .paper-content td, .paper-content th {
            font-family: Arial, Helvetica, sans-serif;
        }

        /* Header Image - Full Width */
        .header-image {
            width: 100%;
            text-align: center;
            border-bottom: 2px solid #0f2b5c;
            padding-bottom: 8px;
            margin-bottom: 15px;
        }
        
        .header-image img {
            width: 100%;
            max-width: 100%;
            height: auto;
            display: block;
        }

        /* Report Title */
        .report-title {
            text-align: center;
            margin-bottom: 15px;
        }
        
        .report-title h1 {
            font-size: 16pt;
            font-weight: bold;
            margin-bottom: 4px;
        }
        
        .report-title .subtitle {
            font-size: 9pt;
        }

        /* Meta Grid */
        .meta-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
            margin-bottom: 15px;
            border: 1px solid #000;
            padding: 8px;
        }
        
        .meta-label {
            font-size: 7pt;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .meta-value {
            font-size: 9pt;
            font-weight: bold;
        }

        /* Filter Summary */
        .filter-summary {
            margin-bottom: 15px;
            padding: 6px;
            background: #f8fafc;
            border: 1px solid #ddd;
            font-size: 8pt;
        }

        /* Summary Stats */
        .summary-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            border: 1px solid #000;
            padding: 8px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 14pt;
            font-weight: bold;
        }
        
        .stat-label {
            font-size: 7pt;
            text-transform: uppercase;
            margin-top: 4px;
        }

        /* Status Breakdown Stats */
        .status-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 20px;
        }

        /* Section Header */
        .section-header {
            margin: 20px 0 12px;
            border-bottom: 2px solid #1e3a8a;
            padding-bottom: 4px;
        }
        
        .section-header h2 {
            font-size: 12pt;
            font-weight: bold;
            color: #0f2b5c;
        }

        /* Data Table */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        
        .data-table th {
            background: #0f2b5c;
            color: white;
            padding: 6px 8px;
            text-align: left;
            font-weight: 600;
            font-size: 9pt;
            border: 1px solid #1e3a8a;
        }
        
        .data-table td {
            padding: 6px 8px;
            border: 1px solid #ddd;
            vertical-align: middle;
            font-size: 9pt;
        }
        
        .data-table tbody tr:hover {
            background: #f8fafc;
        }

        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 8pt;
            font-weight: 600;
        }
        
        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }
        
        .status-partial {
            background: #ffedd5;
            color: #9a3412;
        }
        
        .status-active {
            background: #fee2e2;
            color: #991b1b;
        }

        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }

        /* Certification */
        .certification {
            margin-top: 20px;
            border: 1px solid #000;
            padding: 10px;
        }
        
        .certification h3 {
            font-size: 10pt;
            margin-bottom: 6px;
        }
        
        .certification p {
            font-size: 8pt;
            text-align: justify;
            line-height: 1.4;
        }

        /* Signature Section */
        .signature-section {
            margin-top: 25px;
        }
        
        .signature-grid {
            display: flex;
            justify-content: space-between;
            gap: 30px;
        }
        
        .signature-box {
            width: 45%;
            text-align: center;
        }
        
        .signature-line {
            border-bottom: 1px solid #000;
            height: 25px;
            margin-bottom: 4px;
        }
        
        .signature-name {
            font-weight: bold;
            font-size: 9pt;
        }
        
        .signature-title, .signature-date {
            font-size: 7pt;
        }

        /* Footer */
        .print-footer {
            margin-top: 18px;
            padding-top: 6px;
            border-top: 1px solid #000;
            text-align: center;
            font-size: 7pt;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1100;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: 16px;
            max-width: 600px;
            width: 90%;
            max-height: 85vh;
            overflow-y: auto;
            padding: 20px;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
        }

        .modal-header h3 {
            font-size: 16px;
            font-weight: 700;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 22px;
            cursor: pointer;
            color: #94a3b8;
        }

        .view-details {
            background: none;
            border: none;
            color: #0ea5e9;
            cursor: pointer;
            font-size: 11pt;
            padding: 4px 8px;
            border-radius: 6px;
            transition: background 0.2s;
        }

        .view-details:hover {
            background: #e0f2fe;
        }

        /* Print Styles */
        @media print {
            body {
                background: white;
                margin: 0;
                padding: 0;
            }
            
            .fixed-top-bar {
                display: none !important;
            }
            
            .main-content {
                margin-top: 0;
                padding: 0;
            }
            
            .paper-container {
                max-width: 100%;
                box-shadow: none;
                margin: 0;
            }
            
            .paper-content {
                padding: 0.5in;
            }
            
            @page {
                size: 8.5in 13in;
                margin: 0.5in;
            }
            
            .view-details {
                display: none;
            }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .main-content {
                margin-top: 380px;
            }
            .summary-stats, .status-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            .filter-grid {
                grid-template-columns: 1fr;
            }
            .sorting-bar {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>

<!-- ============================================ -->
<!-- FIXED TOP BAR - Always visible when scrolling -->
<!-- ============================================ -->
<div class="fixed-top-bar">
    <div class="fixed-header">
        <div class="logo">
            <h1>Accounting Dashboard</h1>
            <p>BISU IGE Aquaculture Management System</p>
        </div>
        <div class="user-info">
            <i class="fas fa-user-circle"></i>
            <span><?php echo htmlspecialchars($user['full_name'] ?? 'Accounting Staff'); ?></span>
            <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="filter-bar">
        <div class="filter-title"><i class="fas fa-filter"></i> Filter Unpaid Orders</div>
        <form method="GET" action="">
            <div class="filter-grid">
                <div class="filter-group">
                    <label>Employee Name / ID</label>
                    <input type="text" name="employee" placeholder="Search employee..." value="<?php echo htmlspecialchars($filterEmployee); ?>">
                </div>
                <div class="filter-group">
                    <label>Department</label>
                    <select name="department">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $filterDepartment == $dept ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="">All Status</option>
                        <option value="pending" <?php echo $filterStatus == 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="partial" <?php echo $filterStatus == 'partial' ? 'selected' : ''; ?>>Partial</option>
                        <option value="active" <?php echo $filterStatus == 'active' ? 'selected' : ''; ?>>Active</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Date From</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($filterDateFrom); ?>">
                </div>
                <div class="filter-group">
                    <label>Date To</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($filterDateTo); ?>">
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn-filter"><i class="fas fa-search"></i> Apply Filters</button>
                    <a href="dashboard.php" class="btn-reset"><i class="fas fa-undo"></i> Reset</a>
                </div>
            </div>
            <input type="hidden" name="sort_by" value="<?php echo htmlspecialchars($sortBy); ?>">
        </form>
    </div>

    <!-- Sorting Bar -->
    <div class="sorting-bar">
        <span class="sort-label"><i class="fas fa-sort"></i> Sort by:</span>
        <div class="sort-options">
            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'balance_desc'])); ?>" class="sort-btn <?php echo $sortBy == 'balance_desc' ? 'active' : ''; ?>">Highest Balance</a>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'balance_asc'])); ?>" class="sort-btn <?php echo $sortBy == 'balance_asc' ? 'active' : ''; ?>">Lowest Balance</a>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'date_newest'])); ?>" class="sort-btn <?php echo $sortBy == 'date_newest' ? 'active' : ''; ?>">Newest First</a>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'date_oldest'])); ?>" class="sort-btn <?php echo $sortBy == 'date_oldest' ? 'active' : ''; ?>">Oldest First</a>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'amount_desc'])); ?>" class="sort-btn <?php echo $sortBy == 'amount_desc' ? 'active' : ''; ?>">Highest Amount</a>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'employee_asc'])); ?>" class="sort-btn <?php echo $sortBy == 'employee_asc' ? 'active' : ''; ?>">Employee Name</a>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="action-buttons">
        <button onclick="window.print();" class="action-btn btn-print"><i class="fas fa-print"></i> Print Report</button>
        <button onclick="saveAsPDF();" class="action-btn btn-pdf"><i class="fas fa-file-pdf"></i> Save as PDF</button>
        <button onclick="exportToCSV();" class="action-btn btn-export"><i class="fas fa-file-csv"></i> Export CSV</button>
    </div>
</div>

<!-- ============================================ -->
<!-- MAIN CONTENT - Paper View like Microsoft Word -->
<!-- ============================================ -->
<div class="main-content">
    <div class="paper-container">
        <div class="paper-content" id="printContent">
            <!-- Full Width Header Image -->
            <div class="header-image">
                <?php if (file_exists('../assets/header.jpg')): ?>
                    <img src="../assets/header.jpg" alt="BISU Header">
                <?php else: ?>
                    <div style="text-align:center;padding:8px;">
                        <h2 style="color:#0f2b5c;margin:0;font-size:13pt;">BOHOL ISLAND STATE UNIVERSITY</h2>
                        <p style="margin:2px 0;font-size:8pt;">Candijay Campus<br>Balance | Integrity | Stewardship | Uprightness</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Report Title -->
            <div class="report-title">
                <h1><?php echo $reportTitle; ?></h1>
                <div class="subtitle"><?php echo $reportSubtitle; ?></div>
            </div>

            <!-- Meta Information -->
            <div class="meta-grid">
                <div>
                    <div class="meta-label">REPORT PERIOD</div>
                    <div class="meta-value"><?php echo $periodDisplay; ?></div>
                </div>
                <div>
                    <div class="meta-label">GENERATED ON</div>
                    <div class="meta-value"><?php echo date('F d, Y \a\t g:i A'); ?></div>
                </div>
                <div>
                    <div class="meta-label">PREPARED BY</div>
                    <div class="meta-value"><?php echo $preparedBy; ?></div>
                </div>
                <div>
                    <div class="meta-label">DOCUMENT ID</div>
                    <div class="meta-value"><?php echo 'ACC-RPT-' . date('Ymd-His'); ?></div>
                </div>
            </div>

            <!-- Filter Summary -->
            <?php if (!empty($filterEmployee) || !empty($filterDepartment) || !empty($filterStatus) || !empty($filterDateFrom) || !empty($filterDateTo)): ?>
            <div class="filter-summary">
                <strong>Applied Filters:</strong>
                <?php if (!empty($filterEmployee)): ?> Employee: <?php echo htmlspecialchars($filterEmployee); ?> | <?php endif; ?>
                <?php if (!empty($filterDepartment)): ?> Department: <?php echo htmlspecialchars($filterDepartment); ?> | <?php endif; ?>
                <?php if (!empty($filterStatus)): ?> Status: <?php echo htmlspecialchars($filterStatus); ?> | <?php endif; ?>
                <?php if (!empty($filterDateFrom) || !empty($filterDateTo)): ?> Date Range: <?php echo $periodDisplay; ?><?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Summary Statistics -->
            <div class="summary-stats">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $summary['total_unpaid_count']; ?></div>
                    <div class="stat-label">Unpaid Records</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">₱<?php echo number_format($summary['total_unpaid'], 2); ?></div>
                    <div class="stat-label">Total Outstanding</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">₱<?php echo number_format($summary['total_recovered'], 2); ?></div>
                    <div class="stat-label">Total Recovered</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $summary['unique_customers']; ?></div>
                    <div class="stat-label">Active Customers</div>
                </div>
            </div>

            <!-- Status Breakdown -->
            <div class="status-stats">
                <div class="stat-card" style="background: #fef3c7;">
                    <div class="stat-value" style="color: #92400e;"><?php echo $summary['by_status']['pending']; ?></div>
                    <div class="stat-label">Pending</div>
                </div>
                <div class="stat-card" style="background: #ffedd5;">
                    <div class="stat-value" style="color: #9a3412;"><?php echo $summary['by_status']['partial']; ?></div>
                    <div class="stat-label">Partial</div>
                </div>
                <div class="stat-card" style="background: #fee2e2;">
                    <div class="stat-value" style="color: #991b1b;"><?php echo $summary['by_status']['active']; ?></div>
                    <div class="stat-label">Active</div>
                </div>
                <div class="stat-card" style="background: #d1fae5;">
                    <div class="stat-value" style="color: #059669;"><?php echo round(($summary['total_recovered'] / max($summary['total_recovered'] + $summary['total_unpaid'], 1)) * 100, 1); ?>%</div>
                    <div class="stat-label">Recovery Rate</div>
                </div>
            </div>

            <!-- Unpaid Orders Table -->
            <div class="section-header">
                <h2>Outstanding Employee Balances</h2>
            </div>

            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 5%;">#</th>
                        <th style="width: 22%;">Employee</th>
                        <th style="width: 15%;">Department</th>
                        <th style="width: 10%;">Order #</th>
                        <th style="width: 10%;">Order Date</th>
                        <th style="width: 12%;">Total Amount</th>
                        <th style="width: 12%;">Amount Paid</th>
                        <th style="width: 12%;">Balance</th>
                        <th style="width: 9%;">Status</th>
                        <th style="width: 8%;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $counter = 1; ?>
                    <?php foreach ($unpaidDeductions as $index => $deduction): ?>
                    <tr>
                        <td class="text-center"><?php echo $counter++; ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($deduction['customer_name']); ?></strong><br>
                            <small><?php echo htmlspecialchars($deduction['employee_id'] ?? ''); ?></small>
                        </td>
                        <td class="text-center"><?php echo htmlspecialchars($deduction['department'] ?? 'N/A'); ?></td>
                        <td class="text-center">#<?php echo $deduction['order_id']; ?></td>
                        <td class="text-center"><?php echo date('M d, Y', strtotime($deduction['order_date'])); ?></td>
                        <td class="text-right">₱<?php echo number_format($deduction['total_amount'], 2); ?></td>
                        <td class="text-right">₱<?php echo number_format($deduction['total_paid'] ?? 0, 2); ?></td>
                        <td class="text-right"><strong>₱<?php echo number_format($deduction['remaining_balance'], 2); ?></strong></td>
                        <td class="text-center">
                            <span class="status-badge status-<?php echo $deduction['deduction_status']; ?>">
                                <?php echo ucfirst($deduction['deduction_status']); ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <button class="view-details" onclick="showOrderDetails(<?php echo $index; ?>)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($unpaidDeductions)): ?>
                    <tr>
                        <td colspan="10" class="text-center" style="padding: 40px;">No unpaid deductions found.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr style="background: #0f2b5c;">
                        <td colspan="5" class="text-right" style="color: white;"><strong>TOTAL:</strong></td>
                        <td class="text-right" style="color: white;">₱<?php echo number_format(array_sum(array_column($unpaidDeductions, 'total_amount')), 2); ?></td>
                        <td class="text-right" style="color: white;">₱<?php echo number_format(array_sum(array_column($unpaidDeductions, 'total_paid')), 2); ?></td>
                        <td class="text-right" style="color: white;"><strong>₱<?php echo number_format($summary['total_unpaid'], 2); ?></strong></td>
                        <td colspan="2" style="color: white;">&nbsp;</td>
                    </tr>
                </tfoot>
            </table>

            <!-- Certification -->
            <div class="certification">
                <h3>CERTIFICATION</h3>
                <p>I hereby certify that the foregoing report is a true and correct record of all unpaid salary deductions for the period covered, as shown in the Aquaculture Management System database. All entries have been verified and are supported by official receipts and system logs.</p>
                <p>This report is system-generated and requires no additional signature for internal purposes. For external use, please affix official signature below.</p>
            </div>

            <!-- Signature Block -->
            <div class="signature-section">
                <div class="signature-grid">
                    <div class="signature-box">
                        <div class="signature-line"></div>
                        <div class="signature-name"><?php echo $preparedBy; ?></div>
                        <div class="signature-title">Prepared by</div>
                        <div class="signature-date">Date: <?php echo $preparedDate; ?></div>
                    </div>
                    <div class="signature-box">
                        <div class="signature-line"></div>
                        <div class="signature-name">_________________________</div>
                        <div class="signature-title">Accounting Head / Authorized Signatory</div>
                        <div class="signature-date">Date: _______________</div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="print-footer">
                <p><strong>BISU IGE Aquaculture Management System</strong> - This is a system-generated official document</p>
                <p><?php echo 'ACC-RPT-' . date('Ymd-His'); ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Order Details Modal -->
<div id="orderModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-receipt"></i> Order Details</h3>
            <button class="close-modal" onclick="closeOrderModal()">&times;</button>
        </div>
        <div id="modalContent"></div>
    </div>
</div>

<script>
    // Data passed from PHP
    const unpaidData = <?php echo json_encode($unpaidDeductions); ?>;
    
    function showOrderDetails(index) {
        const deduction = unpaidData[index];
        if (!deduction) return;
        
        const modal = document.getElementById('orderModal');
        const content = document.getElementById('modalContent');
        
        let orderItemsHtml = '';
        if (deduction.order_items && deduction.order_items.length > 0) {
            orderItemsHtml = '<h4 style="margin: 15px 0 10px;"><i class="fas fa-fish"></i> Order Items</h4>';
            orderItemsHtml += '<div style="background: #f8fafc; border-radius: 12px; padding: 12px;">';
            deduction.order_items.forEach(item => {
                orderItemsHtml += `
                    <div class="order-item" style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #e2e8f0;">
                        <span><strong>${escapeHtml(item.fish_name || 'Product')}</strong> x ${parseFloat(item.quantity).toFixed(2)} kg</span>
                        <span>₱${parseFloat(item.price_per_kg).toFixed(2)} / kg</span>
                        <span><strong>₱${parseFloat(item.subtotal).toFixed(2)}</strong></span>
                    </div>
                `;
            });
            orderItemsHtml += '</div>';
        }
        
        let paymentHistoryHtml = '';
        if (deduction.payment_history && deduction.payment_history.length > 0) {
            paymentHistoryHtml = '<h4 style="margin: 15px 0 10px;"><i class="fas fa-history"></i> Payment History</h4>';
            paymentHistoryHtml += '<div style="background: #f8fafc; border-radius: 12px; padding: 12px;">';
            deduction.payment_history.forEach(payment => {
                paymentHistoryHtml += `
                    <div class="history-item" style="padding: 10px; border-bottom: 1px solid #e2e8f0;">
                        <div><strong>${payment.created_at ? new Date(payment.created_at).toLocaleString() : 'N/A'}</strong></div>
                        <div style="color: #10b981; font-size: 16px; font-weight: 600;">₱${parseFloat(payment.amount_deducted).toFixed(2)}</div>
                        <div style="font-size: 12px; color: #64748b;">Reference: ${escapeHtml(payment.reference_number) || 'N/A'}</div>
                        <div style="font-size: 12px; color: #64748b;">Remarks: ${escapeHtml(payment.remarks) || 'N/A'}</div>
                    </div>
                `;
            });
            paymentHistoryHtml += '</div>';
        } else {
            paymentHistoryHtml = '<p style="text-align: center; padding: 20px; color: #64748b;"><i class="fas fa-info-circle"></i> No payment history available.</p>';
        }
        
        let statusColor = '';
        switch(deduction.deduction_status) {
            case 'pending': statusColor = '#92400e'; break;
            case 'partial': statusColor = '#9a3412'; break;
            case 'active': statusColor = '#991b1b'; break;
            default: statusColor = '#64748b';
        }
        
        let html = `
            <div style="margin-bottom: 16px; padding: 16px; background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border-radius: 12px;">
                <div style="display: grid; gap: 10px;">
                    <div><strong style="color: #0f172a;">Customer:</strong> ${escapeHtml(deduction.customer_name)}</div>
                    <div><strong>Employee ID:</strong> ${escapeHtml(deduction.employee_id || 'N/A')}</div>
                    <div><strong>Department:</strong> ${escapeHtml(deduction.department || 'N/A')}</div>
                    <div><strong>Order ID:</strong> #${deduction.order_id}</div>
                    <div><strong>Order Date:</strong> ${new Date(deduction.order_date).toLocaleDateString()}</div>
                    <div><strong>Payment Method:</strong> ${deduction.payment_method || 'Salary Deduction'}</div>
                    <div><strong>Total Amount:</strong> <span style="font-size: 18px; font-weight: 700;">₱${parseFloat(deduction.total_amount).toFixed(2)}</span></div>
                    <div><strong>Amount Paid:</strong> <span style="color: #059669;">₱${parseFloat(deduction.total_paid || 0).toFixed(2)}</span></div>
                    <div><strong>Remaining Balance:</strong> <span style="color: ${statusColor}; font-size: 18px; font-weight: 700;">₱${parseFloat(deduction.remaining_balance).toFixed(2)}</span></div>
                    <div><strong>Status:</strong> <span class="status-badge status-${deduction.deduction_status}" style="margin-left: 0;">${deduction.deduction_status.toUpperCase()}</span></div>
                    ${deduction.order_remarks ? `<div><strong>Remarks:</strong> ${escapeHtml(deduction.order_remarks)}</div>` : ''}
                </div>
            </div>
            ${orderItemsHtml}
            <h4 style="margin: 15px 0 10px;"><i class="fas fa-credit-card"></i> Payment Transactions</h4>
            ${paymentHistoryHtml}
        `;
        
        content.innerHTML = html;
        modal.style.display = 'flex';
    }
    
    function closeOrderModal() {
        document.getElementById('orderModal').style.display = 'none';
    }
    
    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }
    
    function exportToCSV() {
        let csv = "\uFEFFOrder ID,Employee,Department,Order Date,Total Amount,Amount Paid,Remaining Balance,Status\n";
        <?php foreach ($unpaidDeductions as $index => $deduction): ?>
        csv += `"#<?php echo addslashes($deduction['order_id']); ?>","<?php echo addslashes($deduction['customer_name']); ?>","<?php echo addslashes($deduction['department'] ?? 'N/A'); ?>","<?php echo $deduction['order_date']; ?>","<?php echo $deduction['total_amount']; ?>","<?php echo $deduction['total_paid'] ?? 0; ?>","<?php echo $deduction['remaining_balance']; ?>","<?php echo $deduction['deduction_status']; ?>"\n`;
        <?php endforeach; ?>
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `unpaid_orders_<?php echo date('Y-m-d'); ?>.csv`;
        a.click();
        URL.revokeObjectURL(url);
    }
    
    function saveAsPDF() {
        const originalTitle = document.title;
        document.title = 'unpaid_orders_report_' + new Date().toISOString().slice(0,19).replace(/:/g, '-');
        window.print();
        setTimeout(function() {
            document.title = originalTitle;
        }, 1000);
    }
    
    // Close modal on background click
    window.onclick = function(event) {
        const modal = document.getElementById('orderModal');
        if (event.target === modal) {
            closeOrderModal();
        }
    }
</script>
</body>
</html>