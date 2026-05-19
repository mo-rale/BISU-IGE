<?php
// manager/orders.php - Professional UI with Pagination (FIXED FILTERING)
require_once '../includes/config.php';
require_once '../includes/session.php';
require_once '../includes/FifoStock.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Only allow admin and manager roles - FIXED to use existing method
SessionManager::requireOfficeUser(); // This allows both manager and staff

$functions = new SystemFunctions();
$db = (new Database())->getConnection();

// FIX: Ensure PDO throws exceptions for all errors
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Helper function to detect quantity column
function detectQuantityColumn($db) {
    try {
        $checkColumns = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'order_items' AND column_name IN ('quantity', 'quantity_numeric')");
        $existingColumns = $checkColumns->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('quantity', $existingColumns)) {
            return 'quantity';
        } elseif (in_array('quantity_numeric', $existingColumns)) {
            return 'quantity_numeric';
        }
    } catch (Exception $e) {
        // Fallback to default
    }
    return 'quantity_numeric';
}

/**
 * Create salary deduction record when order is claimed
 */
function createSalaryDeduction($db, $order_id, $user_id, $total_amount) {
    $checkSql = "SELECT deduction_id FROM salary_deductions WHERE order_id = :order_id";
    $checkStmt = $db->prepare($checkSql);
    $checkStmt->execute([':order_id' => $order_id]);
    
    if ($checkStmt->fetch()) {
        return true;
    }
    
    $userSql = "SELECT department, position, full_name as name, email FROM users WHERE user_id = :user_id";
    $userStmt = $db->prepare($userSql);
    $userStmt->execute([':user_id' => $user_id]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    $startDate = date('Y-m-d');
    $endDate = date('Y-m-d', strtotime('+3 months'));
    
    $sql = "INSERT INTO salary_deductions (
                order_id, user_id, total_amount, amount_paid, 
                remaining_balance, deduction_status, deduction_start_date, 
                deduction_end_date, remarks, created_at, updated_at
            ) VALUES (
                :order_id, :user_id, :total_amount, 0, 
                :total_amount, 'pending', :start_date, 
                :end_date, :remarks, NOW(), NOW()
            )";
    
    $remarks = "Auto-generated from order #" . str_pad($order_id, 6, '0', STR_PAD_LEFT) . " for " . ($user['name'] ?? 'User');
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':order_id' => $order_id,
        ':user_id' => $user_id,
        ':total_amount' => $total_amount,
        ':start_date' => $startDate,
        ':end_date' => $endDate,
        ':remarks' => $remarks
    ]);
    
    $getIdSql = "SELECT deduction_id FROM salary_deductions WHERE order_id = :order_id ORDER BY created_at DESC LIMIT 1";
    $getIdStmt = $db->prepare($getIdSql);
    $getIdStmt->execute([':order_id' => $order_id]);
    $deduction = $getIdStmt->fetch(PDO::FETCH_ASSOC);
    $deduction_id = $deduction ? $deduction['deduction_id'] : null;
    
    return $deduction_id;
}

// Get filter parameters
$statusFilter = $_GET['order_status'] ?? 'all';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$searchTerm = $_GET['search'] ?? '';
$paymentFilter = $_GET['payment_method'] ?? 'all';

// Pagination - 7 per page
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 7;
$offset = ($page - 1) * $perPage;

$message = '';
$messageType = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Check for existing transaction
    if ($db->inTransaction()) {
        try {
            $db->rollBack();
        } catch (Exception $e) {
            $message = "Database error: " . $e->getMessage();
            $messageType = 'error';
            header("Location: orders.php?status=" . urlencode($statusFilter) . "&message=" . urlencode($message) . "&type=error");
            exit();
        }
    }
    
    try {
        $db->beginTransaction();
    } catch (Exception $e) {
        error_log("Transaction begin error: " . $e->getMessage());
        $message = "Failed to start transaction: " . $e->getMessage();
        $messageType = 'error';
        header("Location: orders.php?status=" . urlencode($statusFilter) . "&message=" . urlencode($message) . "&type=error");
        exit();
    }
    
    try {
        switch ($action) {
            case 'update_status':
                $status = $_POST['status'];
                $order_id = (int)$_POST['order_id'];
                $manager_note = $_POST['manager_note'] ?? 'Status updated by manager';

                $orderSql = "SELECT user_id, payment_method, total_amount, order_status FROM orders WHERE order_id = ?";
                $orderStmt = $db->prepare($orderSql);
                $orderStmt->execute([$order_id]);
                $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$order) {
                    throw new Exception("Order not found");
                }
                
                // Handle claimed order - create salary deduction
                if ($status == 'claimed' && $order['payment_method'] == 'salary_deduction') {
                    createSalaryDeduction($db, $order_id, $order['user_id'], $order['total_amount']);
                }
                
                // Process cancelled order - restore stock
                if ($status == 'cancelled') {
                    $quantityColumn = detectQuantityColumn($db);
                    
                    // FIFO: reverse stock deductions back to original harvest batches
                    $fifo = new FifoStock($db);
                    $itemsStmt2 = $db->prepare("SELECT order_item_id FROM order_items WHERE order_id = ?");
                    $itemsStmt2->execute([$order_id]);
                    foreach ($itemsStmt2->fetchAll(PDO::FETCH_ASSOC) as $cancelItem) {
                        $fifo->reverseDeduction((int)$cancelItem['order_item_id']);
                    }
                    
                    if ($order['payment_method'] == 'salary_deduction') {
                        $updateDeductionSql = "UPDATE salary_deductions SET deduction_status = 'cancelled', updated_at = NOW() WHERE order_id = :order_id";
                        $updateDeductionStmt = $db->prepare($updateDeductionSql);
                        $updateDeductionStmt->execute([':order_id' => $order_id]);
                    }
                }

                // Update order status based on case
                switch ($status) {
                    case 'cancelled':
                        $sql = "UPDATE orders SET order_status = ?, cancelled_at = NOW(), updated_at = NOW(), remarks = COALESCE(remarks,'') || E'\nManager note: ' || ? WHERE order_id = ?";
                        break;
                    case 'claimed':
                        $sql = "UPDATE orders SET order_status = ?, claimed_at = COALESCE(claimed_at, NOW()), updated_at = NOW(), remarks = COALESCE(remarks,'') || E'\nManager note: ' || ? WHERE order_id = ?";
                        break;
                    case 'confirmed':
                        $sql = "UPDATE orders SET order_status = ?, confirmed_at = NOW(), updated_at = NOW(), remarks = COALESCE(remarks,'') || E'\nManager note: ' || ? WHERE order_id = ?";
                        break;
                    default:
                        $sql = "UPDATE orders SET order_status = ?, updated_at = NOW(), remarks = COALESCE(remarks,'') || E'\nManager note: ' || ? WHERE order_id = ?";
                        break;
                }

                $stmt = $db->prepare($sql);
                $stmt->execute([$status, $manager_note, $order_id]);

                // Update deduction status after claim
                if ($status == 'claimed' && $order['payment_method'] == 'salary_deduction') {
                    $updateDeductionSql = "UPDATE salary_deductions SET deduction_status = 'pending', updated_at = NOW() WHERE order_id = :order_id";
                    $updateDeductionStmt = $db->prepare($updateDeductionSql);
                    $updateDeductionStmt->execute([':order_id' => $order_id]);
                }

                if (in_array($status, ['confirmed', 'claimed'])) {
                    $notificationTitle = $status === 'confirmed' ? 'Order Confirmed' : 'Order Claimed';
                    $notificationMessage = $status === 'confirmed'
                        ? "Your order #" . str_pad($order_id, 6, '0', STR_PAD_LEFT) . " has been confirmed by the manager and is being prepared."
                        : "Your order #" . str_pad($order_id, 6, '0', STR_PAD_LEFT) . " has been marked as claimed.";

                    $functions->createNotification(
                        $order['user_id'],
                        'order',
                        $notificationTitle,
                        $notificationMessage,
                        $order_id
                    );
                }

                $message = "Order status updated successfully.";
                $messageType = 'success';
                break;
                
            case 'add_note':
                $order_id = (int)$_POST['order_id'];
                $note = $_POST['note'];
                
                $sql = "UPDATE orders SET remarks = COALESCE(remarks,'') || E'\n[Manager Note - " . date('Y-m-d H:i:s') . "]: ' || :note, updated_at = NOW() WHERE order_id = :order_id";
                $stmt = $db->prepare($sql);
                $stmt->execute([':note' => $note, ':order_id' => $order_id]);
                
                $message = "Note added successfully.";
                $messageType = 'success';
                break;
                
            case 'export':
                if ($db->inTransaction()) {
                    $db->commit();
                }
                exportOrders();
                exit();
        }
        
        if ($db->inTransaction()) {
            $db->commit();
        }
        
        header("Location: orders.php?status=" . urlencode($statusFilter) . "&payment_method=" . urlencode($paymentFilter) . "&date_from=" . urlencode($dateFrom) . "&date_to=" . urlencode($dateTo) . "&search=" . urlencode($searchTerm) . "&page=" . $page . "&message=" . urlencode($message) . "&type=" . $messageType);
        exit();
        
    } catch (Exception $e) {
        error_log("Manager order transaction error: " . $e->getMessage());
        
        if ($db->inTransaction()) {
            try {
                $db->rollBack();
            } catch (Exception $rollbackError) {
                error_log("Rollback error: " . $rollbackError->getMessage());
            }
        }
        
        $message = "Error: " . $e->getMessage();
        $messageType = 'error';
        
        header("Location: orders.php?status=" . urlencode($statusFilter) . "&message=" . urlencode($message) . "&type=error");
        exit();
    }
}

// Handle message from redirect
if (isset($_GET['message'])) {
    $message = urldecode($_GET['message']);
    $messageType = $_GET['type'] ?? 'info';
}

// Export function
function exportOrders() {
    global $db;
    $quantityColumn = detectQuantityColumn($db);
    
    $sql = "SELECT o.order_id, u.full_name as customer_name, u.email, u.department,
            SUM(oi.{$quantityColumn}) as total_quantity, o.total_amount, 
            o.order_status as status, o.payment_method, o.order_date, o.remarks
            FROM orders o
            JOIN users u ON o.user_id = u.user_id
            LEFT JOIN order_items oi ON o.order_id = oi.order_id
            WHERE 1=1
            GROUP BY o.order_id, u.user_id
            ORDER BY o.order_date DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=orders_export_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, ['Order ID', 'Customer', 'Email', 'Department', 'Quantity (kg)', 'Amount', 'Status', 'Payment', 'Date', 'Remarks']);
    
    foreach ($orders as $row) {
        fputcsv($output, [
            str_pad($row['order_id'], 6, '0', STR_PAD_LEFT),
            $row['customer_name'] ?? 'Unknown',
            $row['email'] ?? '',
            $row['department'] ?? 'N/A',
            number_format($row['total_quantity'] ?? 0, 2),
            number_format($row['total_amount'] ?? 0, 2),
            ucfirst($row['status']),
            str_replace('_', ' ', $row['payment_method'] ?? 'N/A'),
            $row['order_date'],
            $row['remarks'] ?? ''
        ]);
    }
    fclose($output);
    exit();
}

// Get total count 
try {
    $countSql = "SELECT COUNT(*) as total FROM orders o LEFT JOIN users u ON o.user_id = u.user_id WHERE 1=1";
    $countParams = [];
    
    if ($statusFilter !== 'all') {
        $countSql .= " AND o.order_status = :status";
        $countParams[':status'] = $statusFilter;
    }
    if (!empty($dateFrom)) {
        $countSql .= " AND DATE(o.order_date) >= :date_from";
        $countParams[':date_from'] = $dateFrom;
    }
    if (!empty($dateTo)) {
        $countSql .= " AND DATE(o.order_date) <= :date_to";
        $countParams[':date_to'] = $dateTo;
    }
    if (!empty($searchTerm)) {
        $countSql .= " AND (u.email ILIKE :search OR u.full_name ILIKE :search OR CAST(o.order_id AS TEXT) ILIKE :search)";
        $countParams[':search'] = "%$searchTerm%";
    }
    if ($paymentFilter !== 'all') {
        $countSql .= " AND o.payment_method = :payment_method";
        $countParams[':payment_method'] = $paymentFilter;
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

// Get statistics
try {
    $statsSql = "SELECT 
                    COUNT(*) as total_orders,
                    COUNT(CASE WHEN order_status = 'pending' THEN 1 END) as pending_count,
                    COUNT(CASE WHEN order_status = 'confirmed' THEN 1 END) as confirmed_count,
                    COUNT(CASE WHEN order_status = 'claimed' THEN 1 END) as claimed_count,
                    COUNT(CASE WHEN order_status = 'cancelled' THEN 1 END) as cancelled_count,
                    COALESCE(SUM(CASE WHEN order_status = 'claimed' THEN total_amount END), 0) as total_revenue,
                    COUNT(DISTINCT user_id) as unique_customers
                 FROM orders";
    $statsStmt = $db->query($statsSql);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    $dailyStatsSql = "SELECT 
                        DATE(order_date) as date,
                        COUNT(*) as total,
                        COUNT(CASE WHEN order_status = 'claimed' THEN 1 END) as completed
                      FROM orders
                      WHERE order_date >= CURRENT_DATE - INTERVAL '7 days'
                      GROUP BY DATE(order_date)
                      ORDER BY date ASC";
    $dailyStatsStmt = $db->prepare($dailyStatsSql);
    $dailyStatsStmt->execute();
    $dailyStats = $dailyStatsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // FIXED: Get department statistics - handle NULL or empty departments
    $deptSql = "SELECT 
                    CASE 
                        WHEN u.department IS NULL OR u.department = '' THEN 'Not Specified'
                        ELSE u.department
                    END as department,
                    COUNT(*) as order_count
                FROM orders o
                INNER JOIN users u ON o.user_id = u.user_id
                GROUP BY 
                    CASE 
                        WHEN u.department IS NULL OR u.department = '' THEN 'Not Specified'
                        ELSE u.department
                    END
                ORDER BY order_count DESC";
    $deptStmt = $db->query($deptSql);
    $departmentStats = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no departments found, show sample data
    if (empty($departmentStats)) {
        $departmentStats = [['department' => 'No Data', 'order_count' => 1]];
    }
    
} catch (PDOException $e) {
    error_log("Statistics error: " . $e->getMessage());
    $stats = ['total_orders' => 0, 'pending_count' => 0, 'confirmed_count' => 0, 'claimed_count' => 0, 'cancelled_count' => 0, 'total_revenue' => 0, 'unique_customers' => 0];
    $dailyStats = [];
    $departmentStats = [['department' => 'No Data', 'order_count' => 1]];
}

$quantityColumn = detectQuantityColumn($db);

// Get orders with pagination - FIXED: Better filtering
$orders = [];
try {
    $sql = "SELECT 
                o.order_id, o.user_id, o.order_status, o.payment_method, o.total_amount,
                o.remarks, o.order_date, o.confirmed_at, o.claimed_at, o.cancelled_at,
                COALESCE((SELECT SUM({$quantityColumn}) FROM order_items oi WHERE oi.order_id = o.order_id), 0) as total_quantity,
                (SELECT STRING_AGG(fp.fish_name, ', ') FROM order_items oi 
                 JOIN fish_products fp ON oi.product_id = fp.product_id 
                 WHERE oi.order_id = o.order_id) as product_names,
                COALESCE(u.full_name, u.email, 'Unknown') as customer_name,
                u.email, 
                CASE 
                    WHEN u.department IS NULL OR u.department = '' THEN 'Not Specified'
                    ELSE u.department
                END as department
            FROM orders o
            LEFT JOIN users u ON o.user_id = u.user_id
            WHERE 1=1";
    
    $params = [];
    
    if ($statusFilter !== 'all') {
        $sql .= " AND o.order_status = :status";
        $params[':status'] = $statusFilter;
    }
    if (!empty($dateFrom)) {
        $sql .= " AND DATE(o.order_date) >= :date_from";
        $params[':date_from'] = $dateFrom;
    }
    if (!empty($dateTo)) {
        $sql .= " AND DATE(o.order_date) <= :date_to";
        $params[':date_to'] = $dateTo;
    }
    if (!empty($searchTerm)) {
        $sql .= " AND (u.email ILIKE :search OR u.full_name ILIKE :search OR CAST(o.order_id AS TEXT) ILIKE :search)";
        $params[':search'] = "%$searchTerm%";
    }
    if ($paymentFilter !== 'all') {
        $sql .= " AND o.payment_method = :payment_method";
        $params[':payment_method'] = $paymentFilter;
    }
    
    $sql .= " ORDER BY o.order_date DESC LIMIT :limit OFFSET :offset";
    $params[':limit'] = $perPage;
    $params[':offset'] = $offset;
    
    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $paramType = ($key == ':limit' || $key == ':offset') ? PDO::PARAM_INT : PDO::PARAM_STR;
        $stmt->bindValue($key, $value, $paramType);
    }
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Orders fetch error: " . $e->getMessage());
    error_log("SQL: " . $sql);
    $orders = [];
}

// Status badges
$statusBadges = [
    'pending' => '<span class="badge-pro bg-amber-50 text-amber-700 border-amber-200"><i class="fas fa-clock mr-1"></i>Pending</span>',
    'confirmed' => '<span class="badge-pro bg-emerald-50 text-emerald-700 border-emerald-200"><i class="fas fa-check-circle mr-1"></i>Confirmed</span>',
    'claimed' => '<span class="badge-pro bg-violet-50 text-violet-700 border-violet-200"><i class="fas fa-hand-peace mr-1"></i>Claimed</span>',
    'ready_for_pickup' => '<span class="badge-pro bg-violet-50 text-violet-700 border-violet-200"><i class="fas fa-box-open mr-1"></i>Ready</span>',
    'completed' => '<span class="badge-pro bg-green-50 text-green-700 border-green-200"><i class="fas fa-check-double mr-1"></i>Completed</span>',
    'cancelled' => '<span class="badge-pro bg-red-50 text-red-700 border-red-200"><i class="fas fa-times-circle mr-1"></i>Cancelled</span>'
];

$paymentMethodConfig = [
    'salary_deduction' => ['label' => 'Salary Deduction', 'color' => 'bg-purple-50 text-purple-700 border-purple-200', 'icon' => 'fa-money-bill-wave'],
    'cash' => ['label' => 'Cash', 'color' => 'bg-green-50 text-green-700 border-green-200', 'icon' => 'fa-money-bill'],
    'card' => ['label' => 'Card', 'color' => 'bg-blue-50 text-blue-700 border-blue-200', 'icon' => 'fa-credit-card'],
    'gcash' => ['label' => 'GCash', 'color' => 'bg-blue-50 text-blue-700 border-blue-200', 'icon' => 'fa-mobile-alt'],
    'bank_transfer' => ['label' => 'Bank Transfer', 'color' => 'bg-purple-50 text-purple-700 border-purple-200', 'icon' => 'fa-university']
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

// Counts for filter badges
$counts = [
    'all' => $stats['total_orders'] ?? 0,
    'pending' => $stats['pending_count'] ?? 0,
    'confirmed' => $stats['confirmed_count'] ?? 0,
    'claimed' => $stats['claimed_count'] ?? 0,
    'cancelled' => $stats['cancelled_count'] ?? 0
];

// Prepare department chart data as JSON for JavaScript
$departmentLabels = json_encode(array_column($departmentStats, 'department'));
$departmentData = json_encode(array_column($departmentStats, 'order_count'));

// Color palette for departments
$departmentColors = ['#0ea5e9', '#059669', '#8b5cf6', '#f59e0b', '#ef4444', '#ec4899', '#06b6d4', '#84cc16', '#f97316', '#6366f1'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Orders - BISU IGE Aquaculture</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            font-family: 'Inter', sans-serif;
        }
        
        h1, h2, h3, .font-display, .hero-title {
            font-family: 'Playfair Display', serif;
        }
        
        body {
            background-color: #f8fafc;
        }

        .transition-smooth {
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .pro-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px -4px rgba(0, 0, 0, 0.1);
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
            font-weight: 500;
            font-size: 0.875rem;
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

        .filter-input {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            transition: all 0.2s ease;
            background: white;
            width: 100%;
        }
        
        .filter-input:focus {
            outline: none;
            border-color: #0ea5e9;
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
        }

        .filter-tab-pro {
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

        .orders-table {
            width: 100%;
        }
        
        .orders-table th {
            padding: 0.75rem 1rem;
            text-align: left;
            font-size: 0.75rem;
            font-weight: 600;
            color: #64748b;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .orders-table td {
            padding: 0.875rem 1rem;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .orders-table tr:hover {
            background: #fafcff;
        }

        .chart-container {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            border: 1px solid #e2e8f0;
            transition: all 0.2s ease;
        }
        
        .chart-container:hover {
            border-color: #cbd5e1;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
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
            background: white;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-12px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .modal {
            background: rgba(0,0,0,0.5);
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
        
        @keyframes modalSlideIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
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

        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

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
        
        .pagination-info {
            font-size: 0.875rem;
            color: #64748b;
        }

        /* Ensure buttons are clickable */
        .orders-table button, 
        .orders-table a,
        .btn-brand,
        .btn-secondary {
            cursor: pointer;
            position: relative;
            z-index: 10;
        }

        .orders-table td .flex {
            pointer-events: auto;
        }
    </style>
</head>
<body class="antialiased">
    <?php include '../includes/navbar.php'; ?>

    <!-- Flash Messages -->
    <?php if ($message): ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
        <div class="flash-msg" style="border-left-color: <?php echo $messageType == 'success' ? '#10b981' : '#ef4444'; ?>; border-left-width: 4px;">
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
            <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-6">
                <div>
                    <p class="text-brand-300 text-sm font-medium tracking-wide uppercase mb-2">Management</p>
                    <h1 class="text-2xl md:text-3xl font-bold text-white font-display">
                        Manage Orders
                    </h1>
                    <p class="text-brand-200/80 mt-2 text-sm max-w-md">View, manage orders, and process salary deductions.</p>
                </div>
                <div class="flex gap-3">
                    <button onclick="openExportModal()" class="btn-secondary" style="border-color: rgba(255,255,255,0.2); color: white; background: rgba(255,255,255,0.1);">
                        <i class="fas fa-download text-sm"></i> Export
                    </button>
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
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
            <div class="stat-card">
                <div class="flex items-center gap-2 mb-1">
                    <div class="w-8 h-8 rounded-lg bg-brand-50 flex items-center justify-center text-brand-600">
                        <i class="fas fa-shopping-cart text-xs"></i>
                    </div>
                    <p class="text-xs text-gray-500 font-medium">Total Orders</p>
                </div>
                <p class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['total_orders'] ?? 0); ?></p>
            </div>
            <div class="stat-card">
                <div class="flex items-center gap-2 mb-1">
                    <div class="w-8 h-8 rounded-lg bg-amber-50 flex items-center justify-center text-amber-600">
                        <i class="fas fa-clock text-xs"></i>
                    </div>
                    <p class="text-xs text-gray-500 font-medium">Pending</p>
                </div>
                <p class="text-2xl font-bold text-amber-600"><?php echo number_format($stats['pending_count'] ?? 0); ?></p>
            </div>
            <div class="stat-card">
                <div class="flex items-center gap-2 mb-1">
                    <div class="w-8 h-8 rounded-lg bg-emerald-50 flex items-center justify-center text-emerald-600">
                        <i class="fas fa-check-circle text-xs"></i>
                    </div>
                    <p class="text-xs text-gray-500 font-medium">Confirmed</p>
                </div>
                <p class="text-2xl font-bold text-emerald-600"><?php echo number_format($stats['confirmed_count'] ?? 0); ?></p>
            </div>
            <div class="stat-card">
                <div class="flex items-center gap-2 mb-1">
                    <div class="w-8 h-8 rounded-lg bg-purple-50 flex items-center justify-center text-purple-600">
                        <i class="fas fa-chart-line text-xs"></i>
                    </div>
                    <p class="text-xs text-gray-500 font-medium">Revenue</p>
                </div>
                <p class="text-2xl font-bold text-purple-600">₱<?php echo number_format($stats['total_revenue'] ?? 0, 2); ?></p>
            </div>
            <div class="stat-card">
                <div class="flex items-center gap-2 mb-1">
                    <div class="w-8 h-8 rounded-lg bg-blue-50 flex items-center justify-center text-blue-600">
                        <i class="fas fa-users text-xs"></i>
                    </div>
                    <p class="text-xs text-gray-500 font-medium">Customers</p>
                </div>
                <p class="text-2xl font-bold text-blue-600"><?php echo number_format($stats['unique_customers'] ?? 0); ?></p>
            </div>
        </div>

        <!-- Charts -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-6">
            <div class="chart-container">
                <div class="flex items-center gap-2 mb-3">
                    <div class="w-7 h-7 rounded-lg bg-brand-50 flex items-center justify-center text-brand-600">
                        <i class="fas fa-chart-line text-xs"></i>
                    </div>
                    <h3 class="font-semibold text-gray-900 text-sm">Last 7 Days Activity</h3>
                </div>
                <div class="relative" style="height: 220px;">
                    <canvas id="activityChart"></canvas>
                </div>
            </div>
            <div class="chart-container">
                <div class="flex items-center gap-2 mb-3">
                    <div class="w-7 h-7 rounded-lg bg-purple-50 flex items-center justify-center text-purple-600">
                        <i class="fas fa-building text-xs"></i>
                    </div>
                    <h3 class="font-semibold text-gray-900 text-sm">Orders by Department</h3>
                </div>
                <div class="relative" style="height: 320px;">
                    <canvas id="departmentChart"></canvas>
                </div>
                <div class="mt-5 text-center text-xs text-gray-1000">
                    Total: <?php echo array_sum(array_column($departmentStats, 'order_count')); ?> orders
                </div>
            </div>
        </div>

        <!-- Filter Tabs -->
        <div class="mb-6">
            <div class="flex flex-wrap gap-2">
                <a href="?order_status=all&<?php echo http_build_query(array_filter(['payment_method'=>$paymentFilter,'date_from'=>$dateFrom,'date_to'=>$dateTo,'search'=>$searchTerm])); ?>" class="filter-tab-pro <?php echo $statusFilter == 'all' ? 'active' : ''; ?>">
                    <i class="fas fa-list text-[10px]"></i>
                    All Orders
                    <span class="filter-count ml-1.5"><?php echo $counts['all']; ?></span>
                </a>
                <a href="?order_status=pending&<?php echo http_build_query(array_filter(['payment_method'=>$paymentFilter,'date_from'=>$dateFrom,'date_to'=>$dateTo,'search'=>$searchTerm])); ?>" class="filter-tab-pro <?php echo $statusFilter == 'pending' ? 'active' : ''; ?>">
                    <i class="fas fa-clock text-[10px]"></i>
                    Pending
                    <span class="filter-count ml-1.5"><?php echo $counts['pending']; ?></span>
                </a>
                <a href="?order_status=confirmed&<?php echo http_build_query(array_filter(['payment_method'=>$paymentFilter,'date_from'=>$dateFrom,'date_to'=>$dateTo,'search'=>$searchTerm])); ?>" class="filter-tab-pro <?php echo $statusFilter == 'confirmed' ? 'active' : ''; ?>">
                    <i class="fas fa-check-circle text-[10px]"></i>
                    Confirmed
                    <span class="filter-count ml-1.5"><?php echo $counts['confirmed']; ?></span>
                </a>
                <a href="?order_status=claimed&<?php echo http_build_query(array_filter(['payment_method'=>$paymentFilter,'date_from'=>$dateFrom,'date_to'=>$dateTo,'search'=>$searchTerm])); ?>" class="filter-tab-pro <?php echo $statusFilter == 'claimed' ? 'active' : ''; ?>">
                    <i class="fas fa-hand-peace text-[10px]"></i>
                    Claimed
                    <span class="filter-count ml-1.5"><?php echo $counts['claimed']; ?></span>
                </a>
                <a href="?order_status=cancelled&<?php echo http_build_query(array_filter(['payment_method'=>$paymentFilter,'date_from'=>$dateFrom,'date_to'=>$dateTo,'search'=>$searchTerm])); ?>" class="filter-tab-pro <?php echo $statusFilter == 'cancelled' ? 'active' : ''; ?>">
                    <i class="fas fa-times-circle text-[10px]"></i>
                    Cancelled
                    <span class="filter-count ml-1.5"><?php echo $counts['cancelled']; ?></span>
                </a>
            </div>
        </div>

        <!-- Filters -->
        <div class="pro-card mb-6 p-4">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-3">
                <input type="hidden" name="order_status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                <input type="text" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" placeholder="Search by name/email/order ID..." class="filter-input text-sm md:col-span-2">
                <select name="payment_method" class="filter-input text-sm" onchange="this.form.submit()">
                    <option value="all" <?php echo $paymentFilter == 'all' ? 'selected' : ''; ?>>All Payments</option>
                    <option value="salary_deduction" <?php echo $paymentFilter == 'salary_deduction' ? 'selected' : ''; ?>>Salary Deduction</option>
                    <option value="cash" <?php echo $paymentFilter == 'cash' ? 'selected' : ''; ?>>Cash</option>
                    <option value="gcash" <?php echo $paymentFilter == 'gcash' ? 'selected' : ''; ?>>GCash</option>
                </select>
                <input type="date" name="date_from" value="<?php echo $dateFrom; ?>" class="filter-input text-sm">
                <input type="date" name="date_to" value="<?php echo $dateTo; ?>" class="filter-input text-sm">
                <div class="flex gap-2 md:col-span-5 justify-end">
                    <button type="submit" class="btn-brand text-sm px-3 py-1"><i class="fas fa-search"></i> Filter</button>
                    <a href="orders.php" class="btn-secondary text-sm px-3 py-1"><i class="fas fa-redo-alt"></i> Reset</a>
                </div>
            </form>
        </div>
        
        <!-- Orders Table -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden border border-gray-100">
            <table class="orders-table">
                <thead>
                    <tr>
                        <th class="px-4 py-3">Order #</th>
                        <th class="px-4 py-3">Customer</th>
                        <th class="px-4 py-3">Products</th>
                        <th class="px-4 py-3">Qty</th>
                        <th class="px-4 py-3">Total</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Payment</th>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-12 text-gray-400">
                            <div class="w-12 h-12 bg-gray-100 rounded-xl flex items-center justify-center mx-auto mb-3">
                                <i class="fas fa-shopping-cart text-xl"></i>
                            </div>
                            <p>No orders found</p>
                        </td>
                    </tr>
                    <?php else: foreach ($orders as $order): ?>
                    <tr class="hover:bg-gray-50 transition-smooth">
                        <td class="px-4 py-3 font-mono text-xs font-semibold text-gray-700">#<?php echo str_pad($order['order_id'], 6, '0', STR_PAD_LEFT); ?></td>
                        <td class="px-4 py-3">
                            <p class="font-medium text-sm text-gray-900"><?php echo htmlspecialchars($order['customer_name'] ?? 'N/A'); ?></p>
                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($order['email'] ?? ''); ?></p>
                        </td>
                        <td class="px-4 py-3">
                            <p class="text-xs text-gray-700"><?php echo htmlspecialchars(substr($order['product_names'] ?? 'N/A', 0, 35)); ?></p>
                            <p class="text-xs text-gray-400 mt-0.5"><?php echo htmlspecialchars($order['department'] ?? ''); ?></p>
                        </td>
                        <td class="px-4 py-3 text-xs font-medium text-gray-700"><?php echo number_format($order['total_quantity'] ?? 0, 2); ?> kg</td>
                        <td class="px-4 py-3 font-bold text-brand-600 text-sm">₱<?php echo number_format($order['total_amount'] ?? 0, 2); ?></td>
                        <td class="px-4 py-3"><?php echo $statusBadges[$order['order_status']] ?? $statusBadges['pending']; ?></td>
                        <td class="px-4 py-3">
                            <span class="badge-pro <?php echo $paymentMethodConfig[$order['payment_method']]['color'] ?? 'bg-gray-50 text-gray-700 border-gray-200'; ?> text-xs">
                                <i class="fas <?php echo $paymentMethodConfig[$order['payment_method']]['icon'] ?? 'fa-question'; ?>"></i>
                                <?php echo $paymentMethodConfig[$order['payment_method']]['label'] ?? ucfirst($order['payment_method'] ?? 'N/A'); ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-600"><?php echo date('M d, Y', strtotime($order['order_date'])); ?></td>
                        <td class="px-4 py-3">
                            <div class="flex gap-1.5">
                                <button onclick='viewOrder(<?php echo json_encode($order); ?>);' class="text-brand-600 hover:text-brand-800 transition-colors" title="View Details" type="button">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php if ($order['order_status'] == 'pending'): ?>
                                    <button onclick="openStatusModal(<?php echo $order['order_id']; ?>, 'confirmed')" class="text-emerald-600 hover:text-emerald-800 transition-colors" title="Confirm Order" type="button">
                                        <i class="fas fa-check-circle"></i>
                                    </button>
                                <?php endif; ?>
                                <?php if ($order['order_status'] == 'confirmed'): ?>
                                    <button onclick="openStatusModal(<?php echo $order['order_id']; ?>, 'claimed')" class="text-violet-600 hover:text-violet-800 transition-colors" title="Mark as Claimed" type="button">
                                        <i class="fas fa-hand-peace"></i>
                                    </button>
                                <?php endif; ?>
                                <?php if (!in_array($order['order_status'], ['cancelled', 'claimed'])): ?>
                                    <button onclick="openStatusModal(<?php echo $order['order_id']; ?>, 'cancelled')" class="text-red-600 hover:text-red-800 transition-colors" title="Cancel Order" type="button">
                                        <i class="fas fa-times-circle"></i>
                                    </button>
                                <?php endif; ?>
                                <button onclick="openNoteModal(<?php echo $order['order_id']; ?>)" class="text-gray-500 hover:text-gray-700 transition-colors" title="Add Note" type="button">
                                    <i class="fas fa-sticky-note"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="px-4 py-3 border-t border-gray-100 bg-gray-50/50">
                <?php 
                    $queryParams = $_GET; 
                    unset($queryParams['page']); 
                    echo buildPaginationLinks($page, $totalPages, $queryParams); 
                ?>
                <div class="text-center text-xs text-gray-500 mt-2">
                    Page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo number_format($totalRecords); ?> orders)
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modals -->
    <div id="viewModal" class="modal">
        <div class="modal-content max-w-2xl">
            <div class="flex justify-between items-center mb-4 pb-3 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-gray-900">Order Details</h3>
                <button onclick="closeViewModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            <div id="orderDetails"></div>
            <div class="mt-5 flex justify-end">
                <button onclick="closeViewModal()" class="btn-secondary text-sm">Close</button>
            </div>
        </div>
    </div>

    <div id="statusModal" class="modal">
        <div class="modal-content">
            <h3 class="text-lg font-semibold text-gray-900 mb-4" id="statusModalTitle">Update Status</h3>
            <form method="POST" id="statusForm">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="order_id" id="status_order_id">
                <input type="hidden" name="status" id="status_value">
                <textarea name="manager_note" rows="3" class="filter-input w-full mb-4 text-sm" placeholder="Add a note (optional)..."></textarea>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="closeStatusModal()" class="btn-secondary text-sm">Cancel</button>
                    <button type="submit" class="btn-brand text-sm">Update Status</button>
                </div>
            </form>
        </div>
    </div>

    <div id="noteModal" class="modal">
        <div class="modal-content">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Add Note</h3>
            <form method="POST" id="noteForm">
                <input type="hidden" name="action" value="add_note">
                <input type="hidden" name="order_id" id="note_order_id">
                <textarea name="note" rows="4" class="filter-input w-full mb-4 text-sm" required placeholder="Enter your note..."></textarea>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="closeNoteModal()" class="btn-secondary text-sm">Cancel</button>
                    <button type="submit" class="btn-brand text-sm">Add Note</button>
                </div>
            </form>
        </div>
    </div>

    <div id="exportModal" class="modal">
        <div class="modal-content">
            <h3 class="text-lg font-semibold text-gray-900 mb-3">Export Orders</h3>
            <form method="POST" id="exportForm">
                <input type="hidden" name="action" value="export">
                <p class="text-gray-600 text-sm mb-4">Export all orders matching current filters to CSV format.</p>
                <div class="bg-gray-50 rounded-lg p-3 mb-4 text-xs text-gray-500">
                    <i class="fas fa-info-circle mr-1"></i> File includes: Order ID, Customer, Email, Department, Quantity, Amount, Status, Payment, Date, Remarks
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="closeExportModal()" class="btn-secondary text-sm">Cancel</button>
                    <button type="submit" class="btn-brand text-sm"><i class="fas fa-download mr-1"></i> Export CSV</button>
                </div>
            </form>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
        // Wait for DOM to be fully loaded
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM fully loaded');
            
            // Initialize Activity Chart
            initializeActivityChart();
            
            // Initialize Department Chart
            initializeDepartmentChart();
        });
        
        function initializeActivityChart() {
            const dailyData = <?php echo json_encode($dailyStats); ?>;
            const canvas = document.getElementById('activityChart');
            
            if (!canvas) {
                console.error('Activity chart canvas not found');
                return;
            }
            
            if (dailyData && dailyData.length > 0) {
                try {
                    const ctx = canvas.getContext('2d');
                    new Chart(ctx, { 
                        type: 'line', 
                        data: { 
                            labels: dailyData.map(d => {
                                const date = new Date(d.date);
                                return date.toLocaleDateString('en-US', {month:'short', day:'numeric'});
                            }), 
                            datasets: [
                                { 
                                    label: 'Total Orders', 
                                    data: dailyData.map(d => parseInt(d.total) || 0), 
                                    borderColor: '#0ea5e9', 
                                    backgroundColor: 'rgba(14, 165, 233, 0.05)',
                                    borderWidth: 2.5, 
                                    pointRadius: 4,
                                    pointBackgroundColor: '#0ea5e9',
                                    pointBorderColor: '#fff',
                                    pointHoverRadius: 6,
                                    fill: true,
                                    tension: 0.3
                                }, 
                                { 
                                    label: 'Completed Orders', 
                                    data: dailyData.map(d => parseInt(d.completed) || 0), 
                                    borderColor: '#059669', 
                                    borderWidth: 2.5, 
                                    pointRadius: 4,
                                    pointBackgroundColor: '#059669',
                                    pointBorderColor: '#fff',
                                    pointHoverRadius: 6,
                                    fill: false,
                                    tension: 0.3
                                }
                            ] 
                        }, 
                        options: { 
                            responsive: true, 
                            maintainAspectRatio: true, 
                            plugins: { 
                                legend: { 
                                    position: 'top', 
                                    labels: { font: { size: 11, weight: '500' }, usePointStyle: true, boxWidth: 8 } 
                                },
                                tooltip: { backgroundColor: '#1e293b', titleColor: '#fff', bodyColor: '#cbd5e1' }
                            },
                            scales: {
                                y: { 
                                    beginAtZero: true, 
                                    grid: { color: '#e2e8f0', drawBorder: false },
                                    ticks: { stepSize: 1, font: { size: 10 } }
                                },
                                x: { 
                                    grid: { display: false },
                                    ticks: { font: { size: 10 } }
                                }
                            }
                        } 
                    });
                    console.log('Activity chart initialized');
                } catch(e) {
                    console.error('Activity chart error:', e);
                    canvas.parentElement.innerHTML = '<div class="flex items-center justify-center h-full text-gray-400 text-sm"><i class="fas fa-chart-line mr-2"></i>Error loading chart</div>';
                }
            } else {
                canvas.parentElement.innerHTML = '<div class="flex items-center justify-center h-full text-gray-400 text-sm"><i class="fas fa-chart-line mr-2"></i>No activity data available</div>';
            }
        }
        
        function initializeDepartmentChart() {
            const deptLabels = <?php echo $departmentLabels; ?>;
            const deptData = <?php echo $departmentData; ?>;
            const canvas = document.getElementById('departmentChart');
            
            if (!canvas) {
                console.error('Department chart canvas not found');
                return;
            }
            
            if (deptLabels && deptLabels.length > 0 && deptLabels[0] !== 'No Data') {
                try {
                    const ctx2 = canvas.getContext('2d');
                    const colorPalette = ['#0ea5e9', '#059669', '#8b5cf6', '#f59e0b', '#ef4444', '#ec4899', '#06b6d4', '#84cc16', '#f97316', '#6366f1'];
                    const backgroundColors = deptLabels.map((_, i) => colorPalette[i % colorPalette.length]);
                    
                    new Chart(ctx2, { 
                        type: 'doughnut', 
                        data: { 
                            labels: deptLabels, 
                            datasets: [{ 
                                data: deptData.map(v => parseInt(v) || 0), 
                                backgroundColor: backgroundColors,
                                borderWidth: 0,
                                hoverOffset: 8
                            }] 
                        }, 
                        options: { 
                            responsive: true, 
                            maintainAspectRatio: true,
                            cutout: '55%',
                            plugins: { 
                                legend: { 
                                    position: 'right', 
                                    labels: { 
                                        font: { size: 11, weight: '500' },
                                        boxWidth: 12,
                                        padding: 10,
                                        usePointStyle: true
                                    } 
                                },
                                tooltip: { 
                                    backgroundColor: '#1e293b',
                                    titleColor: '#fff',
                                    bodyColor: '#cbd5e1',
                                    callbacks: {
                                        label: function(context) {
                                            const label = context.label || '';
                                            const value = context.raw || 0;
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                            return `${label}: ${value} orders (${percentage}%)`;
                                        }
                                    }
                                }
                            } 
                        } 
                    });
                    console.log('Department chart initialized');
                } catch(e) {
                    console.error('Department chart error:', e);
                    canvas.parentElement.innerHTML = '<div class="flex items-center justify-center h-full text-gray-400 text-sm"><i class="fas fa-chart-pie mr-2"></i>Error loading chart</div>';
                }
            } else if (deptLabels && deptLabels[0] === 'No Data') {
                canvas.parentElement.innerHTML = '<div class="flex items-center justify-center h-full text-gray-400 text-sm"><i class="fas fa-chart-pie mr-2"></i>No department data available</div>';
            }
        }
        
        // Modal functions
        function viewOrder(order) {
            console.log('Viewing order:', order);
            let deductionHtml = order.payment_method === 'salary_deduction' ? 
                '<div class="mt-3 p-3 bg-purple-50 rounded-lg border border-purple-100"><p class="font-semibold text-purple-800 text-sm"><i class="fas fa-money-bill-wave mr-1"></i> Salary Deduction</p><p class="text-xs text-purple-600 mt-0.5">This order will be deducted from salary.</p></div>' : '';
            
            const statusLabels = {
                'pending': 'Pending Confirmation',
                'confirmed': 'Order Confirmed',
                'claimed': 'Order Claimed',
                'cancelled': 'Order Cancelled'
            };
            
            document.getElementById('orderDetails').innerHTML = `
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div class="info-card p-2">
                        <p class="text-gray-500 text-xs">Order #</p>
                        <p class="font-semibold text-gray-900">#${String(order.order_id).padStart(6,'0')}</p>
                    </div>
                    <div class="info-card p-2">
                        <p class="text-gray-500 text-xs">Customer</p>
                        <p class="font-semibold text-gray-900">${escapeHtml(order.customer_name)}</p>
                    </div>
                    <div class="info-card p-2">
                        <p class="text-gray-500 text-xs">Email</p>
                        <p class="font-semibold text-gray-900 text-xs">${escapeHtml(order.email)}</p>
                    </div>
                    <div class="info-card p-2">
                        <p class="text-gray-500 text-xs">Department</p>
                        <p class="font-semibold text-gray-900">${escapeHtml(order.department) || 'N/A'}</p>
                    </div>
                    <div class="info-card p-2">
                        <p class="text-gray-500 text-xs">Products</p>
                        <p class="font-semibold text-gray-900 text-xs">${escapeHtml(order.product_names)}</p>
                    </div>
                    <div class="info-card p-2">
                        <p class="text-gray-500 text-xs">Quantity</p>
                        <p class="font-semibold text-gray-900">${parseFloat(order.total_quantity).toFixed(2)} kg</p>
                    </div>
                    <div class="info-card p-2">
                        <p class="text-gray-500 text-xs">Total Amount</p>
                        <p class="font-bold text-brand-600">₱${parseFloat(order.total_amount).toFixed(2)}</p>
                    </div>
                    <div class="info-card p-2">
                        <p class="text-gray-500 text-xs">Status</p>
                        <p class="font-semibold capitalize text-gray-900">${statusLabels[order.order_status] || order.order_status}</p>
                    </div>
                    <div class="info-card p-2">
                        <p class="text-gray-500 text-xs">Payment Method</p>
                        <p class="font-semibold text-gray-900">${order.payment_method?.replace('_',' ') || 'N/A'}</p>
                    </div>
                    <div class="info-card p-2">
                        <p class="text-gray-500 text-xs">Order Date</p>
                        <p class="font-semibold text-gray-900 text-xs">${new Date(order.order_date).toLocaleString()}</p>
                    </div>
                </div>
                ${order.remarks ? `
                <div class="mt-3 p-3 bg-amber-50 rounded-lg border border-amber-100">
                    <p class="text-gray-500 text-xs flex items-center gap-1"><i class="fas fa-comment-dots text-amber-500"></i> Remarks</p>
                    <p class="text-sm text-gray-700 mt-1">${escapeHtml(order.remarks)}</p>
                </div>` : ''}
                ${deductionHtml}
            `;
            document.getElementById('viewModal').classList.add('show');
        }
        
        function closeViewModal() { 
            document.getElementById('viewModal').classList.remove('show'); 
        }
        
        function openStatusModal(id, status) { 
            document.getElementById('status_order_id').value = id; 
            document.getElementById('status_value').value = status; 
            const titles = { 
                'confirmed': 'Confirm Order', 
                'claimed': 'Mark as Claimed', 
                'cancelled': 'Cancel Order' 
            }; 
            document.getElementById('statusModalTitle').textContent = titles[status] || 'Update Status'; 
            document.getElementById('statusModal').classList.add('show'); 
        }
        
        function closeStatusModal() { 
            document.getElementById('statusModal').classList.remove('show'); 
        }
        
        function openNoteModal(id) { 
            document.getElementById('note_order_id').value = id; 
            document.getElementById('noteModal').classList.add('show'); 
        }
        
        function closeNoteModal() { 
            document.getElementById('noteModal').classList.remove('show'); 
        }
        
        function openExportModal() { 
            document.getElementById('exportModal').classList.add('show'); 
        }
        
        function closeExportModal() { 
            document.getElementById('exportModal').classList.remove('show'); 
        }
        
        function escapeHtml(str) { 
            if (!str) return ''; 
            return String(str).replace(/[&<>]/g, m => m==='&'?'&amp;':m==='<'?'&lt;':'&gt;'); 
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) { 
            if(e.key === 'Escape') { 
                closeViewModal(); 
                closeStatusModal(); 
                closeNoteModal(); 
                closeExportModal(); 
            } 
        });
        
        // Auto-dismiss flash messages
        setTimeout(function() { 
            document.querySelectorAll('.flash-msg').forEach(function(msg) {
                msg.style.transition = 'all 0.4s ease';
                msg.style.opacity = '0';
                msg.style.transform = 'translateY(-8px)';
                setTimeout(function() { msg.remove(); }, 400);
            });
        }, 5000);
    </script>
</body>
</html>