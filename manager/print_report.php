<?php
// manager/print_report.php - Professional Print Layout with A4 Compatibility
require_once '../includes/config.php';
require_once '../includes/session.php';

SessionManager::requireManagerOrStaff();

$functions = new SystemFunctions();
$userId = SessionManager::getUserId();
$user = $functions->getUserById($userId);

$reportType = $_GET['type'] ?? 'daily';
$reportDate = $_GET['date'] ?? date('Y-m-d');
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

$unpaidFilterEmployee = $_GET['unpaid_employee'] ?? '';
$unpaidFilterDepartment = $_GET['unpaid_department'] ?? '';
$unpaidFilterStatus = $_GET['unpaid_status'] ?? '';

$transactions = [];
$salesLedger = [];
$unpaidDeductions = [];
$summary = [
    'total_sales' => 0, 'total_transactions' => 0,
    'cash_payments' => 0, 'gcash_payments' => 0,
    'bank_payments' => 0, 'card_payments' => 0,
    'paylater_payments' => 0, 'salary_payments' => 0,
    'total_quantity' => 0, 'average_transaction' => 0,
    'total_unpaid' => 0, 'total_unpaid_count' => 0
];

$dailySalesData = [];
$paymentMethodData = [];
$topProducts = [];

try {
    $db = (new Database())->getConnection();

    if ($reportType === 'unpaid') {
        $whereConditions = ["sd.deduction_status IN ('pending','partial','active')"];
        $params = [];

        if (!empty($unpaidFilterEmployee)) {
            $whereConditions[] = "(u.full_name LIKE :employee OR u.email LIKE :employee OR u.employee_id LIKE :employee)";
            $params[':employee'] = '%' . $unpaidFilterEmployee . '%';
        }

        if (!empty($unpaidFilterDepartment)) {
            $whereConditions[] = "u.department = :department";
            $params[':department'] = $unpaidFilterDepartment;
        }

        if (!empty($unpaidFilterStatus)) {
            $whereConditions[] = "sd.deduction_status = :status";
            $params[':status'] = $unpaidFilterStatus;
        }

        $whereClause = "WHERE " . implode(" AND ", $whereConditions);

        $unpaidSql = "SELECT sd.*, u.full_name as customer_name, u.email, u.department, u.position, u.employee_id,
                             o.order_date, o.total_amount as order_total, o.payment_method, o.remarks as order_remarks
                      FROM salary_deductions sd
                      JOIN users u ON sd.user_id = u.user_id
                      LEFT JOIN orders o ON sd.order_id = o.order_id
                      $whereClause
                      AND sd.deduction_id IN (SELECT MAX(deduction_id) FROM salary_deductions WHERE deduction_status IN ('pending','partial','active') GROUP BY order_id)
                      ORDER BY sd.remaining_balance DESC";
        $stmt = $db->prepare($unpaidSql);
        $stmt->execute($params);
        $unpaidDeductions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $summary['total_unpaid_count'] = count($unpaidDeductions);
        $summary['total_unpaid'] = array_sum(array_column($unpaidDeductions, 'remaining_balance'));

        foreach ($unpaidDeductions as &$deduction) {
            $h = $db->prepare("SELECT * FROM deduction_history WHERE deduction_id = :id ORDER BY created_at DESC");
            $h->execute([':id' => $deduction['deduction_id']]);
            $deduction['payment_history'] = $h->fetchAll(PDO::FETCH_ASSOC);
            $deduction['total_paid'] = array_sum(array_column($deduction['payment_history'], 'amount_deducted'));
        }
        unset($deduction);

    } else {
        if ($reportType === 'daily') {
            $sql = "SELECT * FROM (
                        SELECT 
                            o.order_id, 
                            o.user_id, 
                            o.total_amount, 
                            o.payment_method,
                            o.order_status, 
                            o.order_date, 
                            o.created_at, 
                            o.remarks,
                            u.full_name as customer_name, 
                            u.email, 
                            u.department,
                            oi.quantity, 
                            oi.subtotal, 
                            fp.fish_name, 
                            fp.price_per_kg,
                            'cash' as source,
                            o.order_date as transaction_date
                        FROM orders o
                        JOIN users u ON o.user_id = u.user_id
                        JOIN order_items oi ON o.order_id = oi.order_id
                        JOIN fish_products fp ON oi.product_id = fp.product_id
                        WHERE DATE(o.order_date) = :report_date
                        AND o.order_status = 'completed'
                        AND o.payment_method != 'salary_deduction'

                        UNION ALL

                        SELECT 
                            sd.order_id, 
                            sd.user_id, 
                            sd.total_amount, 
                            'salary_deduction' as payment_method,
                            'completed' as order_status, 
                            sd.completed_at as order_date, 
                            sd.created_at,
                            sd.remarks,
                            u.full_name as customer_name, 
                            u.email, 
                            u.department,
                            oi.quantity, 
                            (oi.quantity * oi.price_per_kg) as subtotal,
                            fp.fish_name, 
                            oi.price_per_kg as price_per_kg,
                            'salary_deduction' as source,
                            sd.completed_at as transaction_date
                        FROM salary_deductions sd
                        JOIN users u ON sd.user_id = u.user_id
                        JOIN order_items oi ON sd.order_id = oi.order_id
                        JOIN fish_products fp ON oi.product_id = fp.product_id
                        WHERE DATE(sd.completed_at) = :report_date2
                        AND sd.deduction_status = 'completed'
                        AND sd.deduction_id IN (
                            SELECT MAX(deduction_id) 
                            FROM salary_deductions 
                            WHERE deduction_status = 'completed' 
                            GROUP BY order_id
                        )
                    ) combined
                    ORDER BY order_date DESC, order_id";
            $stmt = $db->prepare($sql);
            $stmt->execute([':report_date' => $reportDate, ':report_date2' => $reportDate]);
        } else {
            $sql = "SELECT * FROM (
                        SELECT 
                            o.order_id, 
                            o.user_id, 
                            o.total_amount, 
                            o.payment_method,
                            o.order_status, 
                            o.order_date, 
                            o.created_at, 
                            o.remarks,
                            u.full_name as customer_name, 
                            u.email, 
                            u.department,
                            oi.quantity, 
                            oi.subtotal, 
                            fp.fish_name, 
                            fp.price_per_kg,
                            'cash' as source,
                            o.order_date as transaction_date
                        FROM orders o
                        JOIN users u ON o.user_id = u.user_id
                        JOIN order_items oi ON o.order_id = oi.order_id
                        JOIN fish_products fp ON oi.product_id = fp.product_id
                        WHERE DATE(o.order_date) BETWEEN :date_from AND :date_to
                        AND o.order_status = 'completed'
                        AND o.payment_method != 'salary_deduction'

                        UNION ALL

                        SELECT 
                            sd.order_id, 
                            sd.user_id, 
                            sd.total_amount, 
                            'salary_deduction' as payment_method,
                            'completed' as order_status, 
                            sd.completed_at as order_date, 
                            sd.created_at,
                            sd.remarks,
                            u.full_name as customer_name, 
                            u.email, 
                            u.department,
                            oi.quantity, 
                            (oi.quantity * oi.price_per_kg) as subtotal,
                            fp.fish_name, 
                            oi.price_per_kg as price_per_kg,
                            'salary_deduction' as source,
                            sd.completed_at as transaction_date
                        FROM salary_deductions sd
                        JOIN users u ON sd.user_id = u.user_id
                        JOIN order_items oi ON sd.order_id = oi.order_id
                        JOIN fish_products fp ON oi.product_id = fp.product_id
                        WHERE DATE(sd.completed_at) BETWEEN :date_from2 AND :date_to2
                        AND sd.deduction_status = 'completed'
                        AND sd.deduction_id IN (
                            SELECT MAX(deduction_id) 
                            FROM salary_deductions 
                            WHERE deduction_status = 'completed' 
                            GROUP BY order_id
                        )
                    ) combined
                    ORDER BY order_date DESC, order_id";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':date_from' => $dateFrom, ':date_to' => $dateTo,
                ':date_from2' => $dateFrom, ':date_to2' => $dateTo
            ]);
        }

        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $salesLedger = [];
        foreach ($transactions as $t) {
            $productName = $t['fish_name'] ?? 'Unknown Product';
            if (!isset($salesLedger[$productName])) {
                $salesLedger[$productName] = [
                    'product' => $productName, 
                    'transactions' => [], 
                    'total_quantity' => 0, 
                    'total_revenue' => 0, 
                    'transactions_count' => 0
                ];
            }
            $salesLedger[$productName]['transactions'][] = $t;
            $salesLedger[$productName]['total_quantity'] += $t['quantity'] ?? 0;
            $salesLedger[$productName]['total_revenue'] += $t['subtotal'] ?? 0;
            $salesLedger[$productName]['transactions_count']++;
        }

        $summary['total_transactions'] = count($transactions);
        $summary['total_quantity'] = array_sum(array_column($transactions, 'quantity'));
        $summary['total_sales'] = array_sum(array_column($transactions, 'subtotal'));

        foreach ($transactions as $t) {
            $method = $t['payment_method'] ?? '';
            $amount = $t['subtotal'] ?? 0;
            switch($method) {
                case 'cash': $summary['cash_payments'] += $amount; break;
                case 'gcash': $summary['gcash_payments'] += $amount; break;
                case 'bank_transfer': $summary['bank_payments'] += $amount; break;
                case 'card': $summary['card_payments'] += $amount; break;
                case 'pay_later': $summary['paylater_payments'] += $amount; break;
                case 'salary_deduction': $summary['salary_payments'] += $amount; break;
            }
        }

        $paymentMethodData = [
            'cash' => $summary['cash_payments'], 
            'gcash' => $summary['gcash_payments'],
            'bank_transfer' => $summary['bank_payments'], 
            'card' => $summary['card_payments'],
            'pay_later' => $summary['paylater_payments'], 
            'salary_deduction' => $summary['salary_payments']
        ];

        $summary['average_transaction'] = $summary['total_transactions'] > 0 ? $summary['total_sales'] / $summary['total_transactions'] : 0;
    }
} catch (PDOException $e) {
    error_log("Reports error: " . $e->getMessage());
    $transactions = [];
    $salesLedger = [];
    $unpaidDeductions = [];
}

$periodDisplay = $reportType === 'daily' 
    ? date('F d, Y', strtotime($reportDate))
    : ($reportType === 'unpaid' ? 'Active Unpaid Deductions' : date('F d, Y', strtotime($dateFrom)) . ' - ' . date('F d, Y', strtotime($dateTo)));

$grandTotalQty = 0; 
$grandTotalRev = 0;
foreach ($salesLedger as $ledger) {
    $grandTotalQty += $ledger['total_quantity'];
    $grandTotalRev += $ledger['total_revenue'];
}

$preparedBy = htmlspecialchars($user['full_name'] ?? 'System Administrator');
$preparedDate = date('F d, Y');

// Build current URL parameters for actions
$currentParams = http_build_query([
    'type' => $reportType,
    'date' => $reportDate,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'unpaid_employee' => $unpaidFilterEmployee,
    'unpaid_department' => $unpaidFilterDepartment,
    'unpaid_status' => $unpaidFilterStatus
]);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $reportTitle; ?> - BISU IGE</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        /* =========================
           GLOBAL - ALL BLACK TEXT
        ========================= */
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10px;
            line-height: 1.3;
            color: #000000;
            background: #f1f5f9;
            padding: 0;
            margin: 0;
        }

        /* Force all text to be black */
        body, body * {
            color: #000000 !important;
        }

        /* Exception for white text on dark backgrounds */
        .data-table th, 
        .product-header td,
        .grand-total-row td,
        .grand-total-row td *,
        .data-table th * {
            color: #ffffff !important;
        }

        /* =========================
           A4 SETTINGS
        ========================= */
        @page {
            size: A4 portrait;
            margin: 10mm;
        }

        @media print {
            body {
                background: white;
                padding: 0;
                margin: 0;
            }
            .no-print {
                display: none !important;
            }
            .print-container {
                width: 100%;
                max-width: 100%;
                box-shadow: none;
                margin: 0;
                padding: 0;
            }
            .print-content {
                padding: 0;
            }
            table, tr, td, th {
                page-break-inside: avoid;
            }
            .product-section {
                page-break-inside: avoid;
            }
            .data-table td, .data-table th {
                padding: 3px 4px !important;
            }
            .action-buttons {
                display: none;
            }
        }

        /* =========================
           MAIN CONTAINER
        ========================= */
        .print-container {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            background: #fff;
            position: relative;
        }
        .print-content {
            padding: 8mm;
        }

        /* =========================
           FLOATING ACTION BUTTONS - LARGER
        ========================= */
        .action-buttons {
            position: fixed;
            bottom: 25px;
            right: 25px;
            display: flex;
            gap: 12px;
            z-index: 1000;
            background: rgba(0,0,0,0.85);
            padding: 12px 18px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            border: 1px solid #333;
        }
        .action-btn {
            padding: 10px 22px;
            border: none;
            font-size: 13px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
            color: white !important;
            border-radius: 6px;
            transition: all 0.2s ease;
        }
        .btn-print { 
            background: #1e3a8a; 
        }
        .btn-pdf { 
            background: #b91c1c; 
        }
        .btn-back { 
            background: #4b5563; 
        }
        .action-btn:hover {
            opacity: 0.85;
            transform: scale(1.02);
        }

        /* =========================
           HEADER
        ========================= */
        .header-image {
            text-align: center;
            border-bottom: 2px solid #1e3a8a;
            padding-bottom: 8px;
            margin-bottom: 15px;
        }
        .header-image img {
            max-width: 100%;
            max-height: 80px;
        }

        /* =========================
           REPORT TITLE
        ========================= */
        .report-title {
            text-align: center;
            margin-bottom: 15px;
        }
        .report-title h1 {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 4px;
            letter-spacing: 0.5px;
        }
        .report-title .subtitle {
            font-size: 9px;
        }

        /* =========================
           META GRID
        ========================= */
        .meta-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
            margin-bottom: 15px;
            border: 1px solid #000000;
            padding: 8px;
        }
        .meta-item {
            padding: 2px;
        }
        .meta-label {
            font-size: 7px;
            font-weight: bold;
            margin-bottom: 2px;
            text-transform: uppercase;
        }
        .meta-value {
            font-size: 9px;
            font-weight: bold;
        }

        /* =========================
           SUMMARY CARDS
        ========================= */
        .summary-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin-bottom: 15px;
        }
        .stat-card {
            border: 1px solid #000000;
            padding: 6px;
            text-align: center;
        }
        .stat-value {
            font-size: 13px;
            font-weight: bold;
        }
        .stat-label {
            margin-top: 2px;
            font-size: 7px;
            text-transform: uppercase;
        }

        /* =========================
           SECTION HEADERS
        ========================= */
        .section-header {
            margin: 15px 0 8px;
            border-bottom: 2px solid #1e3a8a;
            padding-bottom: 3px;
        }
        .section-header h2 {
            font-size: 12px;
            font-weight: bold;
        }

        /* =========================
           TABLES - FIXED LAYOUT FOR A4
        ========================= */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
            table-layout: fixed;
            font-size: 7.5px;
        }
        .data-table th,
        .data-table td {
            border: 1px solid #000000;
            padding: 4px 5px;
            vertical-align: middle;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        .data-table th {
            background: #0f2b5c;
            text-align: center;
            font-weight: bold;
            font-size: 7.5px;
        }
        .data-table td {
            text-align: left;
        }
        
        /* Sales Ledger Column Widths */
        .sales-table th:nth-child(1) { width: 12%; }
        .sales-table th:nth-child(2) { width: 22%; }
        .sales-table th:nth-child(3) { width: 12%; }
        .sales-table th:nth-child(4) { width: 10%; }
        .sales-table th:nth-child(5) { width: 10%; }
        .sales-table th:nth-child(6) { width: 12%; }
        .sales-table th:nth-child(7) { width: 12%; }
        .sales-table th:nth-child(8) { width: 10%; }
        
        /* Unpaid Report Column Widths */
        .unpaid-table th:nth-child(1) { width: 5%; }
        .unpaid-table th:nth-child(2) { width: 25%; }
        .unpaid-table th:nth-child(3) { width: 15%; }
        .unpaid-table th:nth-child(4) { width: 10%; }
        .unpaid-table th:nth-child(5) { width: 12%; }
        .unpaid-table th:nth-child(6) { width: 12%; }
        .unpaid-table th:nth-child(7) { width: 12%; }
        .unpaid-table th:nth-child(8) { width: 9%; }

        /* Alignment helpers */
        .text-center {
            text-align: center;
        }
        .text-right {
            text-align: right;
        }
        .text-left {
            text-align: left;
        }
        .amount {
            text-align: right;
        }
        .amount strong, .text-right strong {
            font-weight: bold;
        }

        /* Product header row */
        .product-header td {
            background: #1e3a8a;
            font-weight: bold;
            font-size: 8px;
            padding: 5px 6px;
        }
        
        /* Subtotal row */
        .subtotal-row td {
            background: #e5e7eb;
            font-weight: bold;
        }
        
        /* Grand total row */
        .grand-total-row td {
            background: #0f2b5c;
            font-weight: bold;
        }

        /* Status badges - keep backgrounds but text black */
        .status-badge {
            border: 1px solid #000000;
            padding: 2px 5px;
            font-size: 7px;
            font-weight: bold;
            display: inline-block;
            border-radius: 2px;
        }
        .status-pending { background: #fef3c7; }
        .status-active { background: #fee2e2; }
        .status-partial { background: #ffedd5; }
        .status-completed { background: #d1fae5; }

        /* Summary section */
        .summary-section {
            border: 1px solid #000000;
            padding: 10px;
            margin-top: 15px;
        }
        .summary-section h3 {
            margin-bottom: 8px;
            font-size: 10px;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        .payment-row {
            display: flex;
            justify-content: space-between;
            padding: 2px 0;
            border-bottom: 1px solid #000000;
            font-size: 8px;
        }

        /* Certification */
        .certification {
            margin-top: 18px;
            border: 1px solid #000000;
            padding: 10px;
        }
        .certification h3 {
            margin-bottom: 6px;
            font-size: 10px;
        }
        .certification p {
            font-size: 7.5px;
            text-align: justify;
            line-height: 1.3;
        }

        /* Signatures */
        .signature-section {
            margin-top: 25px;
        }
        .signature-grid {
            display: flex;
            justify-content: space-between;
            gap: 25px;
        }
        .signature-box {
            width: 45%;
            text-align: center;
        }
        .signature-line {
            border-bottom: 1px solid #000000;
            height: 30px;
            margin-bottom: 5px;
        }
        .signature-name {
            font-weight: bold;
            font-size: 8px;
        }
        .signature-title, .signature-date {
            font-size: 7px;
        }

        /* Footer */
        .print-footer {
            margin-top: 18px;
            padding-top: 6px;
            border-top: 1px solid #000000;
            text-align: center;
            font-size: 6.5px;
        }

        /* Small text override - force black */
        small, .small, .meta-label, .stat-label, .signature-title, .signature-date {
            color: #000000 !important;
        }

        /* Mobile responsive */
        @media screen and (max-width: 768px) {
            body { padding: 0; }
            .print-container { width: 100%; min-height: auto; }
            .print-content { padding: 8px; }
            .meta-grid, .summary-stats, .summary-grid, .signature-grid {
                grid-template-columns: 1fr;
            }
            .data-table th, .data-table td {
                padding: 3px;
                font-size: 6.5px;
            }
            .action-buttons {
                bottom: 15px;
                right: 15px;
                padding: 8px 12px;
                gap: 8px;
            }
            .action-btn {
                padding: 8px 16px;
                font-size: 11px;
            }
        }
    </style>
</head>
<body>
    <div class="print-container">
        <!-- Floating Action Buttons (overlay, no white space, larger) -->
        <div class="action-buttons no-print">
            <button onclick="window.print();" class="action-btn btn-print">
                PRINT REPORT
            </button>
            <button onclick="saveAsPDF();" class="action-btn btn-pdf">
                SAVE AS PDF
            </button>
            <a href="reports.php?<?php echo $currentParams; ?>" class="action-btn btn-back">
                BACK TO REPORTS
            </a>
        </div>

        <div class="print-content" id="reportContent">
            <!-- Header Image -->
            <div class="header-image">
                <img src="../assets/header.jpg" alt="BISU Header" 
                     onerror="this.style.display='none'; this.parentElement.innerHTML='<div style=\'text-align:center;padding:8px;\'><h2 style=\'color:#0f2b5c;margin:0;font-size:13pt;\'>BOHOL ISLAND STATE UNIVERSITY</h2><p style=\'margin:2px 0;font-size:8pt;\'>Candijay Campus<br>Balance | Integrity | Stewardship | Uprightness</p></div>';">
            </div>

            <!-- Meta Information -->
            <div class="meta-grid">
                <div class="meta-item">
                    <div class="meta-label">REPORT PERIOD</div>
                    <div class="meta-value"><?php echo $periodDisplay; ?></div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">GENERATED ON</div>
                    <div class="meta-value"><?php echo date('F d, Y \a\t g:i A'); ?></div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">PREPARED BY</div>
                    <div class="meta-value"><?php echo $preparedBy; ?></div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">DOCUMENT ID</div>
                    <div class="meta-value"><small><?php echo 'RPT-' . strtoupper($reportType) . '-' . date('Ymd-His'); ?></small></div>
                </div>
            </div>

            <?php if ($reportType === 'unpaid'): ?>
                <!-- UNPAID REPORT -->
                <div class="section-header">
                    <h2>Outstanding Employee Balances</h2>
                </div>

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
                        <div class="stat-value">₱<?php echo number_format($summary['total_unpaid_count'] > 0 ? $summary['total_unpaid'] / $summary['total_unpaid_count'] : 0, 2); ?></div>
                        <div class="stat-label">Average Balance</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo count($unpaidDeductions); ?></div>
                        <div class="stat-label">Active Deductions</div>
                    </div>
                </div>

                <table class="data-table unpaid-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Employee</th>
                            <th>Department</th>
                            <th>Order #</th>
                            <th>Total Amount</th>
                            <th>Amount Paid</th>
                            <th>Balance</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $counter = 1; ?>
                        <?php foreach ($unpaidDeductions as $deduction): ?>
                        <tr>
                            <td class="text-center"><?php echo $counter++; ?></td>
                            <td class="text-left">
                                <strong><?php echo htmlspecialchars($deduction['customer_name']); ?></strong><br>
                                <small><?php echo htmlspecialchars($deduction['employee_id'] ?? ''); ?></small>
                            </td>
                            <td class="text-center"><?php echo htmlspecialchars($deduction['department'] ?? 'N/A'); ?></td>
                            <td class="text-center"><?php echo htmlspecialchars($deduction['order_id']); ?></td>
                            <td class="amount">₱<?php echo number_format($deduction['total_amount'], 2); ?></td>
                            <td class="amount">₱<?php echo number_format($deduction['total_paid'] ?? 0, 2); ?></td>
                            <td class="amount"><strong>₱<?php echo number_format($deduction['remaining_balance'], 2); ?></strong></td>
                            <td class="text-center">
                                <span class="status-badge status-<?php echo $deduction['deduction_status']; ?>">
                                    <?php echo ucfirst($deduction['deduction_status']); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($unpaidDeductions)): ?>
                        <tr>
                            <td colspan="8" class="text-center">No unpaid deductions found.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="grand-total-row">
                            <td colspan="4" class="text-right"><strong>TOTAL OUTSTANDING:</strong></td>
                            <td class="amount">₱<?php echo number_format(array_sum(array_column($unpaidDeductions, 'total_amount')), 2); ?></td>
                            <td class="amount">₱<?php echo number_format(array_sum(array_column($unpaidDeductions, 'total_paid')), 2); ?></td>
                            <td class="amount"><strong>₱<?php echo number_format($summary['total_unpaid'], 2); ?></strong></td>
                            <td class="text-center"></td>
                        </tr>
                    </tfoot>
                </table>

            <?php elseif (!empty($salesLedger)): ?>
                <!-- SALES LEDGER REPORT -->
                <div class="section-header">
                    <h2>Sales Transaction Details</h2>
                </div>

                <div class="summary-stats">
                    <div class="stat-card">
                        <div class="stat-value">₱<?php echo number_format($summary['total_sales'], 2); ?></div>
                        <div class="stat-label">Total Sales</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value">₱<?php echo number_format($summary['cash_payments'], 2); ?></div>
                        <div class="stat-label">Cash Payments</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value">₱<?php echo number_format($summary['salary_payments'], 2); ?></div>
                        <div class="stat-label">Salary Deduction</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $summary['total_transactions']; ?></div>
                        <div class="stat-label">Transactions</div>
                    </div>
                </div>

                <?php 
                $grandTotalQty = 0; 
                $grandTotalRev = 0; 
                foreach ($salesLedger as $pn => $ledger): 
                    $productTotalQty = 0;
                    $productTotalRev = 0;
                ?>
                    <div class="product-section">
                        <table class="data-table sales-table">
                            <thead>
                                <tr class="product-header">
                                    <td colspan="8"><strong>PRODUCT: <?php echo strtoupper(htmlspecialchars($pn)); ?></strong></td>
                                </tr>
                                <tr>
                                    <th>Date</th>
                                    <th>Buyer</th>
                                    <th>Department</th>
                                    <th>Qty (kg)</th>
                                    <th>Price/kg</th>
                                    <th>Total</th>
                                    <th>Payment</th>
                                    <th>Source</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ledger['transactions'] as $t):
                                    $qty = $t['quantity'] ?? 0;
                                    $sub = $t['subtotal'] ?? 0;
                                    $productTotalQty += $qty;
                                    $productTotalRev += $sub;
                                    $grandTotalQty += $qty;
                                    $grandTotalRev += $sub;
                                    $method = $t['payment_method'] ?? 'N/A';
                                    $src = $t['source'] ?? 'cash';
                                ?>
                                <tr>
                                    <td class="text-center"><?php echo date('M d, Y', strtotime($t['order_date'])); ?></td>
                                    <td class="text-left">
                                        <strong><?php echo htmlspecialchars($t['customer_name']); ?></strong><br>
                                        <small><?php echo htmlspecialchars($t['email'] ?? ''); ?></small>
                                    </td>
                                    <td class="text-center"><?php echo htmlspecialchars($t['department'] ?? 'N/A'); ?></td>
                                    <td class="amount"><?php echo number_format($qty, 2); ?> kg</td>
                                    <td class="amount">₱<?php echo number_format($t['price_per_kg'] ?? 0, 2); ?></td>
                                    <td class="amount"><strong>₱<?php echo number_format($sub, 2); ?></strong></td>
                                    <td class="text-center"><?php echo $method === 'salary_deduction' ? 'Salary Deduct' : ucfirst($method); ?></td>
                                    <td class="text-center"><?php echo $src === 'cash' ? 'Cash' : 'Salary'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="subtotal-row">
                                    <td colspan="3" class="text-right"><strong>Subtotal:</strong></td>
                                    <td class="amount"><strong><?php echo number_format($productTotalQty, 2); ?> kg</strong></td>
                                    <td class="text-center"></td>
                                    <td class="amount"><strong>₱<?php echo number_format($productTotalRev, 2); ?></strong></td>
                                    <td colspan="2"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>

                <table class="data-table sales-table">
                    <tfoot>
                        <tr class="grand-total-row">
                            <td colspan="3" class="text-right"><strong>GRAND TOTAL:</strong></td>
                            <td class="amount"><strong><?php echo number_format($grandTotalQty, 2); ?> kg</strong></td>
                            <td class="text-center"></td>
                            <td class="amount"><strong>₱<?php echo number_format($grandTotalRev, 2); ?></strong></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>

                <div class="summary-section">
                    <h3>Payment Method Breakdown</h3>
                    <div class="summary-grid">
                        <div>
                            <div class="payment-row"><span>Cash:</span><span>₱<?php echo number_format($summary['cash_payments'], 2); ?></span></div>
                            <div class="payment-row"><span>GCash:</span><span>₱<?php echo number_format($summary['gcash_payments'], 2); ?></span></div>
                            <div class="payment-row"><span>Bank Transfer:</span><span>₱<?php echo number_format($summary['bank_payments'], 2); ?></span></div>
                        </div>
                        <div>
                            <div class="payment-row"><span>Card:</span><span>₱<?php echo number_format($summary['card_payments'], 2); ?></span></div>
                            <div class="payment-row"><span>Pay Later:</span><span>₱<?php echo number_format($summary['paylater_payments'], 2); ?></span></div>
                            <div class="payment-row"><span>Salary Deduction:</span><span>₱<?php echo number_format($summary['salary_payments'], 2); ?></span></div>
                        </div>
                    </div>
                    <div style="padding-top: 8px; border-top: 1px solid #000000; margin-top: 8px;">
                        <p><strong>Total Transactions:</strong> <?php echo $summary['total_transactions']; ?> &nbsp;|&nbsp;
                           <strong>Total Quantity:</strong> <?php echo number_format($summary['total_quantity'], 2); ?> kg &nbsp;|&nbsp;
                           <strong>Average per Transaction:</strong> ₱<?php echo number_format($summary['average_transaction'], 2); ?></p>
                    </div>
                </div>

            <?php else: ?>
                <div style="text-align: center; padding: 30px 20px; background: #f8fafc; border-radius: 6px; border: 1px solid #000000;">
                    <div style="font-size: 36px; margin-bottom: 8px;">X</div>
                    <h3 style="color: #000000;">No Records Found</h3>
                    <p style="color: #000000;">No transactions were recorded for the selected period.</p>
                </div>
            <?php endif; ?>

            <!-- Certification -->
            <div class="certification">
                <h3>CERTIFICATION</h3>
                <p>I hereby certify that the foregoing report is a true and correct record of all <?php echo $reportType === 'unpaid' ? 'unpaid salary deductions' : 'sales transactions'; ?> for the period covered, as shown in the Aquaculture Management System database. All entries have been verified and are supported by official receipts and system logs.</p>
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
                        <div class="signature-title">Manager / Authorized Signatory</div>
                        <div class="signature-date">Date: _______________</div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="print-footer">
                <p><strong>BISU IGE Aquaculture Management System</strong> - This is a system-generated official document</p>
                <p><?php echo 'RPT-' . strtoupper($reportType) . '-' . date('Ymd-His'); ?></p>
                <br>
                <br>
            </div>
        </div>
    </div>

    <script>
        function saveAsPDF() {
            // Use browser's print with PDF destination - preserves layout perfectly
            const originalTitle = document.title;
            document.title = '<?php echo strtolower(str_replace(' ', '_', $reportTitle)) . '_' . date('Y-m-d_His'); ?>';
            
            // Trigger print - user can select "Save as PDF" as destination
            window.print();
            
            // Restore title after print dialog closes
            setTimeout(function() {
                document.title = originalTitle;
            }, 1000);
        }
    </script>
</body>
</html>