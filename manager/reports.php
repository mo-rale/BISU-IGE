<?php
// manager/reports.php - Professional Reports with Print functionality
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

    try {
        $db->exec("UPDATE salary_deductions SET deduction_status = 'duplicate', updated_at = NOW()
                   WHERE deduction_id NOT IN (SELECT MAX(deduction_id) FROM salary_deductions WHERE deduction_status IN ('pending','partial','active','completed') GROUP BY order_id)
                   AND deduction_status IN ('pending','partial','active','completed')");
    } catch (Exception $e) {
        error_log("Cleanup: " . $e->getMessage());
    }

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

        $deptStmt = $db->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department");
        $departments = $deptStmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        if ($reportType === 'daily') {
            // FIXED: Include completed salary deductions properly
            $sql = "SELECT * FROM (
                        -- Regular orders (cash, gcash, bank, card, pay_later)
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

                        -- Completed salary deductions (fully paid)
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
            // Date range report
            $sql = "SELECT * FROM (
                        -- Regular orders (cash, gcash, bank, card, pay_later)
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

                        -- Completed salary deductions (fully paid)
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

        // Build sales ledger by product
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

        // Calculate payment method totals
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

        if ($reportType === 'total') {
            // Daily sales trend including salary deductions
            $dailySql = "SELECT sale_date, SUM(transaction_count) as transaction_count, SUM(daily_total) as daily_total
                         FROM (
                            SELECT DATE(order_date) as sale_date, COUNT(DISTINCT order_id) as transaction_count, SUM(total_amount) as daily_total
                            FROM orders 
                            WHERE DATE(order_date) BETWEEN :df1 AND :dt1 
                            AND order_status = 'completed' 
                            AND payment_method != 'salary_deduction'
                            GROUP BY DATE(order_date)
                            
                            UNION ALL
                            
                            SELECT DATE(completed_at) as sale_date, COUNT(DISTINCT order_id) as transaction_count, SUM(total_amount) as daily_total
                            FROM salary_deductions 
                            WHERE DATE(completed_at) BETWEEN :df2 AND :dt2 
                            AND deduction_status = 'completed'
                            GROUP BY DATE(completed_at)
                         ) combined
                         GROUP BY sale_date ORDER BY sale_date ASC";
            $dst = $db->prepare($dailySql);
            $dst->execute([':df1'=>$dateFrom, ':dt1'=>$dateTo, ':df2'=>$dateFrom, ':dt2'=>$dateTo]);
            $dailySalesData = $dst->fetchAll(PDO::FETCH_ASSOC);

            // Top products including salary deductions
            $topSql = "SELECT product_name, SUM(order_count) as order_count, SUM(total_quantity) as total_quantity, SUM(total_revenue) as total_revenue
                       FROM (
                            -- Regular orders
                            SELECT fp.fish_name as product_name, COUNT(DISTINCT o.order_id) as order_count, SUM(oi.quantity) as total_quantity, SUM(oi.subtotal) as total_revenue
                            FROM order_items oi 
                            JOIN fish_products fp ON oi.product_id = fp.product_id 
                            JOIN orders o ON oi.order_id = o.order_id
                            WHERE DATE(o.order_date) BETWEEN :df1 AND :dt1 
                            AND o.order_status = 'completed' 
                            AND o.payment_method != 'salary_deduction'
                            GROUP BY fp.product_id, fp.fish_name
                            
                            UNION ALL
                            
                            -- Completed salary deductions
                            SELECT fp.fish_name as product_name, COUNT(DISTINCT sd.order_id) as order_count, SUM(oi.quantity) as total_quantity, SUM(oi.quantity * oi.price_per_kg) as total_revenue
                            FROM salary_deductions sd 
                            JOIN order_items oi ON sd.order_id = oi.order_id 
                            JOIN fish_products fp ON oi.product_id = fp.product_id
                            WHERE DATE(sd.completed_at) BETWEEN :df2 AND :dt2 
                            AND sd.deduction_status = 'completed'
                            GROUP BY fp.product_id, fp.fish_name
                       ) combined
                       GROUP BY product_name ORDER BY total_revenue DESC LIMIT 10";
            $tst = $db->prepare($topSql);
            $tst->execute([':df1'=>$dateFrom, ':dt1'=>$dateTo, ':df2'=>$dateFrom, ':dt2'=>$dateTo]);
            $topProducts = $tst->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (PDOException $e) {
    error_log("Reports error: " . $e->getMessage());
    $transactions = []; 
    $salesLedger = []; 
    $unpaidDeductions = [];
}

$periodDisplay = $reportType === 'daily' 
    ? date('F d, Y', strtotime($reportDate))
    : ($reportType === 'unpaid' ? 'Active Unpaid Deductions' : date('M d, Y', strtotime($dateFrom)) . ' - ' . date('M d, Y', strtotime($dateTo)));

$grandTotalQty = 0; 
$grandTotalRev = 0;
foreach ($salesLedger as $ledger) {
    $grandTotalQty += $ledger['total_quantity'];
    $grandTotalRev += $ledger['total_revenue'];
}

// Build print URL parameters
$printParams = http_build_query([
    'type' => $reportType,
    'date' => $reportDate,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'unpaid_employee' => $unpaidFilterEmployee,
    'unpaid_department' => $unpaidFilterDepartment,
    'unpaid_status' => $unpaidFilterStatus
]);
$printUrl = "print_report.php?$printParams";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $reportType==='unpaid'?'Unpaid Deductions':'Sales Ledger';?> - BISU IGE</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --brand-primary: #0ea5e9;
            --brand-primary-dark: #0284c7;
            --brand-secondary: #0f172a;
            --brand-bg: #f8fafc;
            --brand-card: #ffffff;
            --brand-border: #e2e8f0;
            --brand-text: #1e293b;
            --brand-text-secondary: #64748b;
            --brand-success: #10b981;
            --brand-warning: #f59e0b;
            --brand-danger: #ef4444;
            --brand-info: #6366f1;
        }

        * { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; }

        body { 
            background: var(--brand-bg); 
            color: var(--brand-text);
        }

        .brand-heading {
            font-family: 'Playfair Display', serif;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .page-header {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(14, 165, 233, 0.15) 0%, transparent 70%);
            border-radius: 50%;
        }

        .page-header::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -5%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.1) 0%, transparent 70%);
            border-radius: 50%;
        }

        .stat-card {
            background: white;
            border-radius: 1rem;
            border: 1px solid var(--brand-border);
            padding: 1.25rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--brand-primary), var(--brand-primary-dark));
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.1);
        }

        .stat-card:hover::before {
            opacity: 1;
        }

        .stat-card .stat-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
            margin-bottom: 0.75rem;
        }

        .stat-card .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--brand-text);
            letter-spacing: -0.02em;
        }

        .stat-card .stat-label {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--brand-text-secondary);
            margin-top: 0.25rem;
        }

        .tab-button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            border-radius: 0.75rem;
            font-size: 0.875rem;
            font-weight: 600;
            transition: all 0.2s ease;
            border: 1.5px solid transparent;
        }

        .tab-button:hover {
            background: #f1f5f9;
            color: var(--brand-text);
        }

        .tab-button.active {
            background: linear-gradient(135deg, var(--brand-primary), var(--brand-primary-dark));
            color: white;
            box-shadow: 0 4px 14px rgba(14, 165, 233, 0.3);
            border-color: transparent;
        }

        .tab-button.active:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(14, 165, 233, 0.4);
        }

        .card {
            background: var(--brand-card);
            border-radius: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.02);
            border: 1px solid var(--brand-border);
            overflow: hidden;
        }

        .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--brand-border);
            background: linear-gradient(to right, #f8fafc, #ffffff);
        }

        .form-input {
            padding: 0.5rem 0.875rem;
            border: 1px solid var(--brand-border);
            border-radius: 0.625rem;
            font-size: 0.875rem;
            color: var(--brand-text);
            background: white;
            transition: all 0.2s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--brand-primary);
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
        }

        .btn-print {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            background: linear-gradient(135deg, #059669, #047857);
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
            border-radius: 0.75rem;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.25);
            text-decoration: none;
        }

        .btn-print:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(5, 150, 105, 0.35);
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            background: rgba(255,255,255,0.1);
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
            border-radius: 0.75rem;
            border: 1px solid rgba(255,255,255,0.2);
            transition: all 0.2s ease;
            backdrop-filter: blur(10px);
            text-decoration: none;
        }

        .btn-back:hover {
            background: rgba(255,255,255,0.2);
        }

        .btn-primary-small {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: linear-gradient(135deg, var(--brand-primary), var(--brand-primary-dark));
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
            border-radius: 0.625rem;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-primary-small:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(14, 165, 233, 0.3);
        }

        .filter-card {
            background: white;
            border: 1px solid var(--brand-border);
            border-radius: 0.875rem;
            padding: 1.25rem;
        }

        .filter-label {
            font-size: 0.6875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--brand-text-secondary);
            margin-bottom: 0.375rem;
            display: block;
        }

        .ledger-table { 
            width: 100%; 
            border-collapse: separate;
            border-spacing: 0;
            font-size: 0.8125rem; 
        }

        .ledger-table th { 
            background: #f8fafc; 
            font-weight: 700; 
            text-align: center; 
            padding: 0.875rem 1rem;
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--brand-text-secondary);
            border-bottom: 1px solid var(--brand-border);
        }

        .ledger-table td { 
            padding: 0.875rem 1rem; 
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.15s ease;
        }

        .ledger-table tbody tr:hover td {
            background: #f8fafc;
        }

        .product-section-header {
            background: linear-gradient(135deg, #0f172a, #1e293b) !important;
            color: white !important;
        }

        .product-section-header td {
            background: linear-gradient(135deg, #0f172a, #1e293b) !important;
            color: white !important;
            font-weight: 700;
            font-size: 0.75rem;
            padding: 0.75rem 1rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }

        .subtotal-row { 
            background: #e0f2fe !important; 
        }

        .subtotal-row td { 
            background: #e0f2fe !important; 
            font-weight: 700; 
            border-top: 2px solid var(--brand-primary);
            color: #0c4a6e;
        }

        .grand-total-row { 
            background: linear-gradient(135deg, #0f172a, #1e293b) !important; 
        }

        .grand-total-row td { 
            background: transparent !important; 
            color: white !important; 
            font-weight: 700; 
            font-size: 0.9375rem;
            padding: 1rem;
        }

        .status-pending { background: #fef3c7; color: #92400e; }
        .status-active { background: #fee2e2; color: #991b1b; }
        .status-partial { background: #fef3c7; color: #92400e; }
        .status-completed { background: #d1fae5; color: #065f46; }

        .source-cash { background: #dbeafe; color: #1e40af; }
        .source-salary_deduction { background: #fef3c7; color: #92400e; }

        .chart-container { 
            position: relative; 
            height: 280px; 
            width: 100%; 
        }

        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--brand-text-secondary);
        }

        .empty-state-icon {
            width: 4rem;
            height: 4rem;
            margin: 0 auto 1rem;
            background: #f1f5f9;
            border-radius: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
        }

        .section-icon {
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 0.625rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            color: white;
            font-size: 0.875rem;
        }

        .section-icon.gray {
            background: #f1f5f9;
            color: #64748b;
        }

        .section-icon.red {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }

        .section-icon.green {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .section-icon.purple {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
        }

        .section-icon.orange {
            background: linear-gradient(135deg, #f59e0b, #d97706);
        }

        .custom-scroll::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        .custom-scroll::-webkit-scrollbar-track {
            background: transparent;
        }
        .custom-scroll::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }
        .custom-scroll::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .animate-fade-in {
            animation: fadeIn 0.4s ease forwards;
        }

        @media print {
            .no-print, .tab-button, button, a.btn-back, a.btn-print, .btn-print, nav, .navbar, .page-header, .stat-card .stat-icon, .filter-card, .btn-primary-small { display: none !important; }
        }
    </style>
</head>
<body class="min-h-screen">
<?php include '../includes/navbar.php'; ?>

<!-- Professional Page Header -->
<div class="page-header py-8">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 mb-2">
                    <span class="text-sky-400 text-xs font-bold uppercase tracking-widest">Reports</span>
                    <span class="text-slate-500">/</span>
                    <span class="text-slate-400 text-xs font-medium uppercase tracking-widest"><?php echo $reportType==='unpaid'?'Unpaid Deductions':'Sales Ledger';?></span>
                </div>
                <h1 class="brand-heading text-2xl sm:text-3xl text-white mb-1">
                    <?php echo $reportType==='unpaid'?'Unpaid Salary Deductions':'Sales Ledger';?>
                </h1>
                <p class="text-slate-400 text-sm">
                    <?php echo $reportType==='unpaid'?'Outstanding employee balances and pending deductions':'Cash orders and completed salary deductions by product';?>
                </p>
            </div>
            <div class="flex gap-3 no-print">
                <a href="dashboard.php" class="btn-back">
                    <i class="fas fa-arrow-left text-xs"></i>
                    Back
                </a>
                <a href="<?php echo $printUrl; ?>" target="_blank" class="btn-print">
                    <i class="fas fa-print text-xs"></i>
                    Print Report
                </a>
            </div>
        </div>
    </div>
</div>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 animate-fade-in">

    <!-- Tabs -->
    <div class="card p-2 mb-6 no-print">
        <div class="flex flex-wrap gap-2">
            <a href="?type=daily&date=<?php echo $reportDate;?>" class="tab-button <?php echo $reportType==='daily'?'active':'text-gray-600 hover:bg-gray-100';?>">
                <i class="fas fa-calendar-day text-xs"></i>
                Daily
            </a>
            <a href="?type=total&date_from=<?php echo $dateFrom;?>&date_to=<?php echo $dateTo;?>" class="tab-button <?php echo $reportType==='total'?'active':'text-gray-600 hover:bg-gray-100';?>">
                <i class="fas fa-calendar-alt text-xs"></i>
                Date Range
            </a>
            <a href="?type=unpaid" class="tab-button <?php echo $reportType==='unpaid'?'active':'text-red-600 bg-red-50 hover:bg-red-100';?>">
                <i class="fas fa-exclamation-triangle text-xs"></i>
                Unpaid
            </a>
        </div>
    </div>

    <!-- Date Filter -->
    <div class="card p-5 mb-6 no-print">
        <form method="GET" class="flex flex-wrap items-end gap-4">
            <input type="hidden" name="type" value="<?php echo $reportType;?>">
            <?php if($reportType==='daily'):?>
                <div class="flex-1 min-w-[200px]">
                    <label class="filter-label">Report Date</label>
                    <input type="date" name="date" value="<?php echo $reportDate;?>" 
                           class="form-input w-full" onchange="this.form.submit()">
                </div>
            <?php elseif($reportType!=='unpaid'):?>
                <div class="flex-1 min-w-[160px]">
                    <label class="filter-label">From Date</label>
                    <input type="date" name="date_from" value="<?php echo $dateFrom;?>" class="form-input w-full">
                </div>
                <div class="flex-1 min-w-[160px]">
                    <label class="filter-label">To Date</label>
                    <input type="date" name="date_to" value="<?php echo $dateTo;?>" class="form-input w-full">
                </div>
                <button type="submit" class="btn-primary-small">
                    <i class="fas fa-search text-xs"></i>
                    Generate
                </button>
            <?php endif;?>
            <div class="ml-auto flex items-center gap-2 text-sm text-gray-500">
                <i class="far fa-calendar-alt"></i>
                <span class="font-medium"><?php echo $periodDisplay;?></span>
            </div>
        </form>
    </div>
    <!-- ============ UNPAID DEDUCTIONS ============ -->
    <?php if($reportType==='unpaid'):?>

    <!-- Stats Row -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="stat-card">
            <div class="stat-icon" style="background: #fef2f2; color: #dc2626;">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="stat-value"><?php echo $summary['total_unpaid_count'];?></div>
            <div class="stat-label">Unpaid Records</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #fef3c7; color: #d97706;">
                <i class="fas fa-money-bill-wave"></i>
            </div>
            <div class="stat-value">₱<?php echo number_format($summary['total_unpaid'],2);?></div>
            <div class="stat-label">Total Outstanding</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #dbeafe; color: #2563eb;">
                <i class="fas fa-calculator"></i>
            </div>
            <div class="stat-value">₱<?php echo number_format($summary['total_unpaid_count'] > 0 ? $summary['total_unpaid'] / $summary['total_unpaid_count'] : 0, 2);?></div>
            <div class="stat-label">Average Balance</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #d1fae5; color: #059669;">
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-value"><?php echo count(array_unique(array_column($unpaidDeductions, 'department')));?></div>
            <div class="stat-label">Departments</div>
        </div>
    </div>

    <!-- Filter Card for Unpaid -->
    <div class="filter-card mb-6 no-print">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
            <input type="hidden" name="type" value="unpaid">
            <div>
                <label class="filter-label">Employee Search</label>
                <div class="relative">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                    <input type="text" name="unpaid_employee" value="<?php echo htmlspecialchars($unpaidFilterEmployee);?>" 
                           placeholder="Name, email, or ID..." 
                           class="form-input pl-8 w-full">
                </div>
            </div>
            <div>
                <label class="filter-label">Department</label>
                <select name="unpaid_department" class="form-input w-full">
                    <option value="">All Departments</option>
                    <?php foreach($departments as $dept): ?>
                        <option value="<?php echo htmlspecialchars($dept);?>" <?php echo $unpaidFilterDepartment==$dept?'selected':'';?>>
                            <?php echo htmlspecialchars($dept);?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="filter-label">Status</label>
                <select name="unpaid_status" class="form-input w-full">
                    <option value="">All Status</option>
                    <option value="pending" <?php echo $unpaidFilterStatus=='pending'?'selected':'';?>>Pending</option>
                    <option value="active" <?php echo $unpaidFilterStatus=='active'?'selected':'';?>>Active</option>
                    <option value="partial" <?php echo $unpaidFilterStatus=='partial'?'selected':'';?>>Partial</option>
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn-primary-small flex-1">
                    <i class="fas fa-filter text-xs"></i>
                    Apply
                </button>
                <a href="?type=unpaid" class="flex items-center justify-center px-4 py-2 bg-gray-100 text-gray-600 rounded-xl font-semibold text-sm hover:bg-gray-200 transition-all">
                    <i class="fas fa-undo text-xs mr-1"></i>
                    Reset
                </a>
            </div>
        </form>
    </div>

    <!-- Unpaid Table -->
    <div class="card">
        <div class="card-header flex justify-between items-center">
            <div class="flex items-center gap-3">
                <div class="section-icon red">
                    <i class="fas fa-exclamation-triangle text-sm"></i>
                </div>
                <div>
                    <h2 class="font-bold text-gray-900">Unpaid Balances</h2>
                    <p class="text-xs text-gray-500 mt-0.5">Employee salary deduction records</p>
                </div>
            </div>
            <span class="text-sm font-semibold text-gray-500">
                <?php echo $summary['total_unpaid_count'];?> records
            </span>
        </div>

        <?php if(empty($unpaidDeductions)):?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="fas fa-check-circle text-2xl"></i>
                </div>
                <p class="font-semibold text-gray-900 mb-1">No unpaid deductions found</p>
                <p class="text-sm">
                    <?php if(!empty($unpaidFilterEmployee) || !empty($unpaidFilterDepartment) || !empty($unpaidFilterStatus)):?>
                        Try adjusting your filters to see more results.
                    <?php else: ?>
                        All employee deductions are currently paid or completed.
                    <?php endif;?>
                </p>
                <?php if(!empty($unpaidFilterEmployee) || !empty($unpaidFilterDepartment) || !empty($unpaidFilterStatus)):?>
                    <a href="?type=unpaid" class="inline-flex items-center gap-2 mt-4 px-4 py-2 bg-sky-50 text-sky-600 rounded-lg text-sm font-semibold hover:bg-sky-100 transition-colors">
                        <i class="fas fa-eraser text-xs"></i>
                        Clear all filters
                    </a>
                <?php endif;?>
            </div>
        <?php else:?>
            <div class="overflow-x-auto custom-scroll">
                <table class="ledger-table">
                    <thead>
                        <tr>
                            <th class="text-left">Employee</th>
                            <th class="text-left">Department</th>
                            <th class="text-center">Order #</th>
                            <th class="text-right">Total</th>
                            <th class="text-right">Paid</th>
                            <th class="text-right">Balance</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($unpaidDeductions as $d):?>
                        <tr>
                            <td>
                                <span class="font-semibold text-sm text-gray-900"><?php echo htmlspecialchars($d['customer_name']);?></span>
                                <div class="text-xs text-gray-400 mt-0.5"><?php echo htmlspecialchars($d['email']);?></div>
                                <?php if(!empty($d['employee_id'])):?>
                                <div class="text-xs text-gray-400">ID: <?php echo htmlspecialchars($d['employee_id']);?></div>
                                <?php endif;?>
                            </td>
                            <td class="text-sm text-gray-700"><?php echo htmlspecialchars($d['department']??'N/A');?></td>
                            <td class="text-center font-mono text-sm font-semibold text-gray-700">#<?php echo str_pad($d['order_id'],6,'0',STR_PAD_LEFT);?></td>
                            <td class="text-right font-mono text-sm">₱<?php echo number_format($d['total_amount'],2);?></td>
                            <td class="text-right font-mono text-sm text-green-600 font-semibold">₱<?php echo number_format($d['amount_paid']+($d['total_paid']??0),2);?></td>
                            <td class="text-right font-mono text-sm font-bold text-red-600">₱<?php echo number_format($d['remaining_balance'],2);?></td>
                            <td class="text-center">
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold status-<?php echo $d['deduction_status'];?>">
                                    <?php if($d['deduction_status']==='pending'):?><i class="fas fa-clock text-[9px]"></i>
                                    <?php elseif($d['deduction_status']==='active'):?><i class="fas fa-play text-[9px]"></i>
                                    <?php elseif($d['deduction_status']==='partial'):?><i class="fas fa-adjust text-[9px]"></i>
                                    <?php endif;?>
                                    <?php echo ucfirst($d['deduction_status']);?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach;?>
                    </tbody>
                    <tfoot>
                        <tr class="grand-total-row">
                            <td colspan="3" class="text-right">TOTAL OUTSTANDING:</td>
                            <td class="text-right font-mono">₱<?php echo number_format(array_sum(array_column($unpaidDeductions, 'total_amount')),2);?></td>
                            <td class="text-right font-mono">₱<?php echo number_format(array_sum(array_column($unpaidDeductions, 'amount_paid')),2);?></td>
                            <td class="text-right font-mono font-bold">₱<?php echo number_format($summary['total_unpaid'],2);?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif;?>
    </div>

    <!-- ============ SALES LEDGER ============ -->
    <?php elseif(!empty($salesLedger)):?>

    <!-- Stats Row -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="stat-card">
            <div class="stat-icon" style="background: #dbeafe; color: #2563eb;">
                <i class="fas fa-peso-sign"></i>
            </div>
            <div class="stat-value">₱<?php echo number_format($summary['total_sales'],2);?></div>
            <div class="stat-label">Total Sales</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #d1fae5; color: #059669;">
                <i class="fas fa-cash-register"></i>
            </div>
            <div class="stat-value">₱<?php echo number_format($summary['cash_payments'],2);?></div>
            <div class="stat-label">Cash Payments</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #e0e7ff; color: #6366f1;">
                <i class="fas fa-money-check"></i>
            </div>
            <div class="stat-value">₱<?php echo number_format($summary['salary_payments'],2);?></div>
            <div class="stat-label">Salary Deduct</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #fef3c7; color: #d97706;">
                <i class="fas fa-receipt"></i>
            </div>
            <div class="stat-value"><?php echo $summary['total_transactions'];?></div>
            <div class="stat-label">Transactions</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="flex items-center gap-3">
                <div class="section-icon purple">
                    <i class="fas fa-book text-sm"></i>
                </div>
                <div>
                    <h2 class="font-bold text-gray-900">Sales Ledger</h2>
                    <p class="text-xs text-gray-500 mt-0.5"><?php echo count($salesLedger);?> product(s) | <?php echo $summary['total_transactions'];?> transaction(s)</p>
                </div>
            </div>
        </div>

        <div class="overflow-x-auto custom-scroll">
            <?php $gtq=0; $gtr=0; foreach($salesLedger as $pn=>$ledger): ?>
            <table class="ledger-table">
                <thead>
                    <tr class="product-section-header">
                        <td colspan="8">
                            <div class="flex items-center gap-2">
                                <i class="fas fa-fish text-xs opacity-70"></i>
                                PRODUCT: <?php echo strtoupper(htmlspecialchars($pn));?>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th>Date</th>
                        <th>Buyer</th>
                        <th>Dept</th>
                        <th class="text-right">Qty (kg)</th>
                        <th class="text-right">Price/kg</th>
                        <th class="text-right">Total</th>
                        <th class="text-center">Payment</th>
                        <th class="text-center">Source</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $ptq=0; $ptr=0;
                    foreach($ledger['transactions'] as $t):
                        $qty=$t['quantity']??0; $sub=$t['subtotal']??0;
                        $ptq+=$qty; $ptr+=$sub; $gtq+=$qty; $gtr+=$sub;
                        $src=$t['source']??'cash'; $method=$t['payment_method']??'N/A';
                    ?>
                    <tr>
                        <td class="whitespace-nowrap"><?php echo date('M d, Y',strtotime($t['order_date']));?></td>
                        <td>
                            <span class="font-semibold text-sm text-gray-900"><?php echo htmlspecialchars($t['customer_name']);?></span>
                            <div class="text-xs text-gray-400"><?php echo htmlspecialchars($t['email']??'');?></div>
                        </td>
                        <td class="text-sm text-gray-600"><?php echo htmlspecialchars($t['department']??'N/A');?></td>
                        <td class="text-right font-mono text-sm font-semibold"><?php echo number_format($qty,2);?></td>
                        <td class="text-right font-mono text-sm">₱<?php echo number_format($t['price_per_kg']??0,2);?></td>
                        <td class="text-right font-mono text-sm font-bold text-gray-900">₱<?php echo number_format($sub,2);?></td>
                        <td class="text-center">
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold <?php echo $method==='cash'?'bg-green-100 text-green-700':($method==='salary_deduction'?'bg-purple-100 text-purple-700':'bg-gray-100 text-gray-600');?>">
                                <?php echo $method==='salary_deduction'?'Salary Deduct':ucfirst($method);?>
                            </span>
                        </td>
                        <td class="text-center">
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-semibold <?php echo $src==='cash'?'source-cash':'source-salary_deduction';?>">
                                <?php echo $src==='cash'?'Cash Sale':'Salary Deduct';?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach;?>
                    <tr class="subtotal-row">
                        <td colspan="3" class="text-right">Subtotal for <?php echo htmlspecialchars($pn);?>:</td>
                        <td class="text-right font-mono"><?php echo number_format($ptq,2);?> kg</td>
                        <td></td>
                        <td class="text-right font-mono font-bold">₱<?php echo number_format($ptr,2);?></td>
                        <td colspan="2"></td>
                    </tr>
                </tbody>
            </table>
            <div class="h-2"></div>
            <?php endforeach;?>

            <table class="ledger-table">
                <tfoot>
                    <tr class="grand-total-row">
                        <td colspan="3" class="text-right">GRAND TOTAL:</td>
                        <td class="text-right font-mono"><?php echo number_format($gtq,2);?> kg</td>
                        <td></td>
                        <td class="text-right font-mono font-bold">₱<?php echo number_format($gtr,2);?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <?php if($reportType==='total'&&!empty($dailySalesData)):?>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
        <div class="card p-5">
            <div class="flex items-center gap-3 mb-4">
                <div class="section-icon" style="background: linear-gradient(135deg, #3b82f6, #2563eb);">
                    <i class="fas fa-chart-line text-sm"></i>
                </div>
                <div>
                    <h3 class="font-bold text-gray-900">Daily Sales Trend</h3>
                    <p class="text-xs text-gray-500">Revenue over time</p>
                </div>
            </div>
            <div class="chart-container">
                <canvas id="dailyChart"></canvas>
            </div>
        </div>
        <div class="card p-5">
            <div class="flex items-center gap-3 mb-4">
                <div class="section-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                    <i class="fas fa-chart-pie text-sm"></i>
                </div>
                <div>
                    <h3 class="font-bold text-gray-900">Payment Methods</h3>
                    <p class="text-xs text-gray-500">Distribution by method</p>
                </div>
            </div>
            <div class="chart-container">
                <canvas id="paymentChart"></canvas>
            </div>
        </div>
    </div>
    <?php endif;?>

    <?php else:?>
    <div class="card">
        <div class="empty-state">
            <div class="empty-state-icon">
                <i class="fas fa-receipt text-2xl"></i>
            </div>
            <p class="font-semibold text-gray-900 mb-1">No transactions found</p>
            <p class="text-sm">No transactions were recorded for the selected period.</p>
        </div>
    </div>
    <?php endif;?>
</div>
<?php include '../includes/footer.php'; ?>
<script>
<?php if($reportType==='total'&&!empty($dailySalesData)):?>
document.addEventListener('DOMContentLoaded',function(){
    var c1=document.getElementById('dailyChart'),c2=document.getElementById('paymentChart');
    if(c1)new Chart(c1,{type:'line',data:{labels:<?php echo json_encode(array_column($dailySalesData,'sale_date'));?>,datasets:[{label:'Daily Sales',data:<?php echo json_encode(array_column($dailySalesData,'daily_total'));?>,borderColor:'#3b82f6',backgroundColor:'rgba(59,130,246,0.1)',fill:true,tension:0.4}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}}}});
    if(c2&&<?php echo json_encode(array_sum($paymentMethodData)>0);?>){
        var l=[],d=[],c=[];
        <?php foreach($paymentMethodData as $m=>$a):if($a>0):?>
        l.push('<?php echo ucfirst(str_replace('_',' ',$m));?>');d.push(<?php echo $a;?>);c.push('<?php echo $m==='cash'?'#10b981':($m==='gcash'?'#3b82f6':($m==='salary_deduction'?'#8b5cf6':'#f59e0b'));?>');
        <?php endif;endforeach;?>
        if(l.length)new Chart(c2,{type:'doughnut',data:{labels:l,datasets:[{data:d,backgroundColor:c,borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{usePointStyle:true,padding:15}}}}}});
    }
});
<?php endif;?>
</script>
</body>
</html>