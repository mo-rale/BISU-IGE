<?php
// manager/process_deduction.php - Professional UI with Pagination (FONT FIXED)
require_once '../includes/config.php';
require_once '../includes/session.php';

SessionManager::requireManagerOrStaff();

$db = (new Database())->getConnection();

$message = '';
$messageType = '';

// ============================
// HELPER FUNCTIONS
// ============================

function getOrderItems($db, $order_id) {
    $sql = "SELECT 
                oi.product_id,
                oi.quantity,
                oi.price_per_kg as price_per_kg,
                fp.fish_name
            FROM order_items oi
            JOIN fish_products fp ON oi.product_id = fp.product_id
            WHERE oi.order_id = :order_id";

    $stmt = $db->prepare($sql);
    $stmt->execute([':order_id' => $order_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTotalPaidFromHistory($db, $deduction_id) {
    $sql = "SELECT COALESCE(SUM(amount_deducted), 0)
            FROM deduction_history
            WHERE deduction_id = :id";

    $stmt = $db->prepare($sql);
    $stmt->execute([':id' => $deduction_id]);
    return (float)$stmt->fetchColumn();
}

// ============================
// NOTIFICATION FUNCTION
// ============================

function createNotification($db, $user_id, $title, $message, $type = 'deduction') {
    $sql = "INSERT INTO notifications (user_id, title, message, type, is_read, created_at)
            VALUES (:user_id, :title, :message, :type, false, NOW())";
    
    $stmt = $db->prepare($sql);
    return $stmt->execute([
        ':user_id' => $user_id,
        ':title' => $title,
        ':message' => $message,
        ':type' => $type
    ]);
}

// ============================
// STEP 1: CLEAN DUPLICATES
// ============================

try {
    $cleanupSql = "UPDATE salary_deductions sd1
                   SET deduction_status = 'duplicate',
                       updated_at = NOW()
                   WHERE sd1.deduction_status IN ('pending','active')
                   AND sd1.deduction_id NOT IN (
                        SELECT MAX(sd2.deduction_id)
                        FROM (SELECT * FROM salary_deductions) sd2
                        WHERE sd2.deduction_status IN ('pending','active')
                        GROUP BY sd2.order_id
                   )";

    $db->prepare($cleanupSql)->execute();

} catch (Exception $e) {
    $dupSql = "SELECT order_id, MAX(deduction_id) as keep_id
               FROM salary_deductions
               WHERE deduction_status IN ('pending','active')
               GROUP BY order_id
               HAVING COUNT(*) > 1";

    $stmt = $db->prepare($dupSql);
    $stmt->execute();
    $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($duplicates as $dup) {
        $update = $db->prepare("
            UPDATE salary_deductions
            SET deduction_status = 'duplicate'
            WHERE order_id = :order_id
            AND deduction_id != :keep_id
        ");
        $update->execute($dup);
    }
}

// ============================
// FILTERING PARAMETERS
// ============================
$search = $_GET['search'] ?? '';
$department = $_GET['department'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 3;
$offset = ($page - 1) * $perPage;

// ============================
// STEP 2: FETCH UNIQUE DATA WITH FILTERS
// ============================

$sql = "SELECT 
            sd.*,
            u.full_name,
            u.email,
            u.department,
            u.position,
            o.order_date,
            o.payment_method
        FROM salary_deductions sd
        LEFT JOIN users u ON sd.user_id = u.user_id
        LEFT JOIN orders o ON sd.order_id = o.order_id
        WHERE sd.deduction_status IN ('pending','active')
        AND sd.deduction_id IN (
            SELECT MAX(deduction_id)
            FROM salary_deductions
            WHERE deduction_status IN ('pending','active')
            GROUP BY order_id
        )";

$params = [];

if (!empty($search)) {
    $sql .= " AND (u.full_name ILIKE :search OR u.email ILIKE :search OR u.department ILIKE :search)";
    $params[':search'] = "%{$search}%";
}

if (!empty($department)) {
    $sql .= " AND u.department = :department";
    $params[':department'] = $department;
}

if (!empty($statusFilter)) {
    if ($statusFilter === 'completed_soon') {
        $sql .= " AND sd.total_amount > 0 AND (sd.amount_paid / sd.total_amount) >= 0.7";
    } elseif ($statusFilter === 'barely_started') {
        $sql .= " AND sd.total_amount > 0 AND (sd.amount_paid / sd.total_amount) < 0.3";
    } elseif ($statusFilter === 'has_balance') {
        $sql .= " AND sd.remaining_balance > 0.009"; // Use small epsilon for float comparison
    }
}

if (!empty($dateFrom)) {
    $sql .= " AND DATE(sd.created_at) >= :date_from";
    $params[':date_from'] = $dateFrom;
}

if (!empty($dateTo)) {
    $sql .= " AND DATE(sd.created_at) <= :date_to";
    $params[':date_to'] = $dateTo;
}

$sql .= " ORDER BY sd.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$allDeductions = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalRecords = count($allDeductions);
$totalPages = ceil($totalRecords / $perPage);

// Apply pagination
$paginatedDeductions = array_slice($allDeductions, $offset, $perPage);

// ============================
// STEP 3: ENRICH DATA
// ============================
$pendingDeductions = [];
foreach ($paginatedDeductions as $d) {
    $historySql = "SELECT * FROM deduction_history
                   WHERE deduction_id = :id
                   ORDER BY created_at DESC";

    $h = $db->prepare($historySql);
    $h->execute([':id' => $d['deduction_id']]);
    $history = $h->fetchAll(PDO::FETCH_ASSOC);

    $totalPaid = (float)array_sum(array_column($history, 'amount_deducted'));

    $d['payment_history'] = $history;
    $d['total_paid'] = $totalPaid;
    
    // Fix: Use proper float comparison with epsilon
    $remaining = (float)$d['total_amount'] - $totalPaid;
    $d['remaining'] = max(0, round($remaining, 2)); // Round to 2 decimal places
    $d['percentage'] = $d['total_amount'] > 0 
        ? ($totalPaid / $d['total_amount']) * 100 : 0;

    $d['order_items'] = getOrderItems($db, $d['order_id']);
    
    // Recalculate status based on actual remaining balance
    if ($d['remaining'] <= 0.009) { // Consider as completed if less than 1 cent
        $d['deduction_status'] = 'completed';
        $d['remaining_balance'] = 0;
    } else {
        $d['remaining_balance'] = $d['remaining'];
    }
    
    $pendingDeductions[] = $d;
}

$deptSql = "SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department";
$deptStmt = $db->prepare($deptSql);
$deptStmt->execute();
$departments = $deptStmt->fetchAll(PDO::FETCH_COLUMN);

// ============================
// PROCESS DEDUCTION
// ============================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {
        $id = (int)$_POST['deduction_id'];
        $amount = round((float)$_POST['amount_deducted'], 2); // Round to 2 decimal places
        $period = trim($_POST['payroll_period']);
        $remarks = $_POST['remarks'] ?? '';

        // Fix: Allow amount >= 0.01 (not just > 0)
        if ($amount < 0.01) {
            throw new Exception("Amount must be at least ₱0.01");
        }
        if (empty($period)) {
            throw new Exception("Payroll period is required");
        }

        $db->beginTransaction();

        $currentPaid = getTotalPaidFromHistory($db, $id);

        $row = $db->prepare("
            SELECT total_amount, order_id, user_id, deduction_status, completed_at
            FROM salary_deductions
            WHERE deduction_id = :id
            FOR UPDATE
        ");
        $row->execute([':id'=>$id]);
        $d = $row->fetch();
        
        if (!$d) {
            throw new Exception("Deduction record not found");
        }
        
        // Check if already completed
        if ($d['deduction_status'] === 'completed') {
            throw new Exception("This deduction is already completed");
        }

        $totalAmount = (float)$d['total_amount'];
        $remaining = round($totalAmount - $currentPaid, 2); // Round to 2 decimal places

        // Allow amount up to remaining (including floating point tolerance)
        if ($amount > ($remaining + 0.009)) { // Allow 0.009 tolerance
            throw new Exception("Amount exceeds remaining balance of ₱" . number_format($remaining, 2));
        }

        // If amount is very close to remaining, adjust to exact remaining
        if (abs($amount - $remaining) <= 0.009) {
            $amount = $remaining;
        }

        $insert = $db->prepare("
            INSERT INTO deduction_history
            (deduction_id, amount_deducted, payroll_period, remarks, created_at)
            VALUES (:id,:amt,:period,:remarks,NOW())
        ");
        $insert->execute([
            ':id'=>$id,
            ':amt'=>$amount,
            ':period'=>$period,
            ':remarks'=>$remarks
        ]);

        $newPaid = round($currentPaid + $amount, 2);
        $newRemaining = round($totalAmount - $newPaid, 2);

        // Determine status and completed_at
        $isComplete = false;
        if ($newRemaining <= 0.009) { // Consider as completed if less than 1 cent
            $status = 'completed';
            $newRemaining = 0;
            $newPaid = $totalAmount; // Set paid to exact total amount
            $isComplete = true;
        } else {
            $status = 'pending';
        }

        // Build the UPDATE query dynamically based on whether it's completed
        if ($isComplete) {
            // If completed, set completed_at to NOW()
            $update = $db->prepare("
                UPDATE salary_deductions
                SET amount_paid = :paid,
                    remaining_balance = :remaining,
                    deduction_status = :status,
                    completed_at = NOW(),
                    updated_at = NOW()
                WHERE deduction_id = :id
            ");
        } else {
            // If not completed, set completed_at to NULL (or keep existing NULL)
            $update = $db->prepare("
                UPDATE salary_deductions
                SET amount_paid = :paid,
                    remaining_balance = :remaining,
                    deduction_status = :status,
                    completed_at = NULL,
                    updated_at = NOW()
                WHERE deduction_id = :id
            ");
        }
        
        $update->execute([
            ':paid'=>$newPaid,
            ':remaining'=>$newRemaining,
            ':status'=>$status,
            ':id'=>$id
        ]);

        // ============================
        // CREATE NOTIFICATION FOR THE USER
        // ============================
        
        // Get user details for notification
        $userSql = "SELECT full_name FROM users WHERE user_id = :user_id";
        $userStmt = $db->prepare($userSql);
        $userStmt->execute([':user_id' => $d['user_id']]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        $employeeName = $user['full_name'] ?? 'Employee';
        
        // Format amounts for notification
        $formattedAmount = '₱' . number_format($amount, 2);
        $formattedRemaining = '₱' . number_format($newRemaining, 2);
        $formattedTotal = '₱' . number_format($totalAmount, 2);
        
        if ($status === 'completed') {
            // Notification for completed deduction (fully paid)
            $notificationTitle = "Salary Deduction Completed";
            $notificationMessage = "Hello {$employeeName}, your salary deduction has been fully paid. Total deduction amount: {$formattedTotal}. Thank you for your payment!";
        } else {
            // Notification for partial payment
            $percentPaid = ($newPaid / $totalAmount) * 100;
            $notificationTitle = "Salary Deduction Processed";
            $notificationMessage = "Hello {$employeeName}, a salary deduction of {$formattedAmount} has been processed (Payroll Period: {$period}). ";
            $notificationMessage .= "Remaining balance: {$formattedRemaining} out of {$formattedTotal} (" . number_format($percentPaid, 1) . "% paid).";
            if (!empty($remarks)) {
                $notificationMessage .= " Remarks: {$remarks}";
            }
        }
        
        createNotification($db, $d['user_id'], $notificationTitle, $notificationMessage, 'deduction');

        $db->commit();

        $msg = "Deduction of ₱" . number_format($amount, 2) . " processed successfully!";
        if ($status === 'completed') {
            $msg .= " The deduction is now completed. A notification has been sent to the employee.";
        } else {
            $msg .= " Remaining balance: ₱" . number_format($newRemaining, 2) . ". A notification has been sent to the employee.";
        }

        $queryParams = [];
        if (!empty($search)) $queryParams['search'] = $search;
        if (!empty($department)) $queryParams['department'] = $department;
        if (!empty($statusFilter)) $queryParams['status'] = $statusFilter;
        if (!empty($dateFrom)) $queryParams['date_from'] = $dateFrom;
        if (!empty($dateTo)) $queryParams['date_to'] = $dateTo;
        $queryParams['page'] = $page;

        $redirectUrl = "process_deduction.php?message=" . urlencode($msg) . "&type=success";
        if (!empty($queryParams)) {
            $redirectUrl .= "&" . http_build_query($queryParams);
        }

        header("Location: " . $redirectUrl);
        exit();

    } catch (Exception $e) {
        $db->rollBack();

        $queryParams = [];
        if (!empty($search)) $queryParams['search'] = $search;
        if (!empty($department)) $queryParams['department'] = $department;
        if (!empty($statusFilter)) $queryParams['status'] = $statusFilter;
        if (!empty($dateFrom)) $queryParams['date_from'] = $dateFrom;
        if (!empty($dateTo)) $queryParams['date_to'] = $dateTo;
        $queryParams['page'] = $page;

        $redirectUrl = "process_deduction.php?message=" . urlencode("Error: " . $e->getMessage()) . "&type=error";
        if (!empty($queryParams)) {
            $redirectUrl .= "&" . http_build_query($queryParams);
        }

        header("Location: " . $redirectUrl);
        exit();
    }
}

if (isset($_GET['message'])) {
    $message = urldecode($_GET['message']);
    $messageType = $_GET['type'] ?? 'info';
}

function safeNumber($n, $decimals = 2) {
    return number_format((float)$n, $decimals);
}

function safeDate($d) {
    return $d ? date('M d, Y', strtotime($d)) : 'N/A';
}

function safeDateTime($d) {
    return $d ? date('F d, Y g:i A', strtotime($d)) : 'N/A';
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
    <title>Process Salary Deductions - BISU IGE Aquaculture</title>
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
        /* CRITICAL FONT FIX: Inter for all body text, Playfair ONLY for headers */
        * {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }
        
        /* Playfair Display ONLY for main headers */
        h1, h2, .hero-title, .section-title, .card-title, .page-header h1,
        .form-heading h2, .brand-headline h1 {
            font-family: 'Playfair Display', serif;
        }
        
        /* Remove serif from ALL other text elements */
        p, span, div, li, td, th, label, input, select, button,
        .badge-pro, .status-badge, .btn-brand, .btn-secondary,
        .stat-card, .deduction-card, .history-item, .order-items-list,
        .filter-label, .filter-input, .filter-select,
        .modal-content, .summary-stats p, .nav-link, .navbar {
            font-family: 'Inter', sans-serif;
        }
        
        /* Numeric displays - keep clean with Inter */
        .num-display, .amount-paid, .amount-remaining {
            font-family: 'Inter', sans-serif !important;
            font-weight: 700;
            letter-spacing: -0.02em;
        }
        
        body {
            background-color: #f8fafc;
        }
        
        .transition-smooth {
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
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
            border: 1px solid #e2e8f0;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-secondary:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }
        
        .btn-success-pro {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.875rem;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            width: 100%;
        }

        .btn-success-pro:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
        }
        
        .filter-input, .filter-select {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            background: white;
            width: 100%;
        }
        
        .filter-input:focus, .filter-select:focus {
            outline: none;
            border-color: #0ea5e9;
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
        }
        
        .filter-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: #475569;
            margin-bottom: 0.375rem;
        }
        
        .pro-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
        }
        
        .deduction-card {
            background: white;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
            overflow: hidden;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .deduction-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px -4px rgba(0, 0, 0, 0.1);
            border-color: #cbd5e1;
        }
        
        .stat-card {
            background: #f8fafc;
            border-radius: 10px;
            padding: 0.75rem;
            border: 1px solid #e2e8f0;
            transition: all 0.2s ease;
        }
        
        .progress-container {
            background: #e2e8f0;
            border-radius: 9999px;
            height: 6px;
            overflow: hidden;
        }

        .progress-fill {
            transition: width 0.8s ease;
            height: 100%;
            border-radius: 9999px;
            background: linear-gradient(90deg, #0ea5e9, #8b5cf6);
        }
        
        .history-item {
            background: #f8fafc;
            border-radius: 8px;
            padding: 0.5rem;
            border: 1px solid #e2e8f0;
            transition: all 0.2s ease;
        }

        .history-item:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
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
        
        @keyframes modalSlideIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
        
        .flash-msg {
            padding: 0.875rem 1rem;
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
        
        .summary-stats {
            background: linear-gradient(135deg, #0369a1, #075985);
            border-radius: 12px;
            padding: 1.25rem;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .summary-stats::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -5%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%);
            pointer-events: none;
        }
        
        .active-filter-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.25rem 0.75rem;
            background: #f0fdf4;
            color: #059669;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            border: 1px solid #bbf7d0;
        }

        .active-filter-badge a {
            color: #059669;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }

        .active-filter-badge a:hover {
            color: #047857;
        }
        
        .quick-amount-btn {
            font-size: 0.75rem;
            font-weight: 500;
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
            background: #f0f9ff;
            color: #0ea5e9;
            border: 1px solid #bae6fd;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .quick-amount-btn:hover {
            background: #0ea5e9;
            color: white;
            border-color: #0ea5e9;
        }
        
        .order-items-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .order-items-list li {
            padding: 0.25rem 0;
            font-size: 0.75rem;
            color: #475569;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .order-items-list li::before {
            content: "•";
            color: #0ea5e9;
            font-weight: bold;
        }
        
        .empty-icon {
            width: 4rem;
            height: 4rem;
            background: #f1f5f9;
            border-radius: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            color: #94a3b8;
            font-size: 1.5rem;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.25rem 0.625rem;
            border-radius: 9999px;
            font-size: 0.6875rem;
            font-weight: 600;
            border: 1px solid;
        }
        
        ::-webkit-scrollbar { width: 5px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 3px; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
    </style>
</head>

<body>

<?php include '../includes/navbar.php'; ?>

<!-- Flash Messages -->
<?php if ($message): ?>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
    <div class="flash-msg" style="border-left-color: <?php echo $messageType == 'success' ? '#10b981' : '#ef4444'; ?>; border-left-width: 4px;">
        <div class="flex items-center gap-3">
            <div class="w-7 h-7 rounded-lg flex items-center justify-center <?php echo $messageType == 'success' ? 'bg-green-50 text-green-600' : 'bg-red-50 text-red-600'; ?>">
                <i class="fas <?php echo $messageType == 'success' ? 'fa-check' : 'fa-exclamation'; ?> text-xs"></i>
            </div>
            <p class="text-sm font-medium text-gray-700"><?php echo htmlspecialchars($message); ?></p>
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
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <p class="text-brand-300 text-xs font-medium tracking-wide uppercase mb-1">Salary Deductions</p>
                <h1 class="text-2xl md:text-3xl font-bold text-white hero-title">
                    Process Salary Deductions
                </h1>
                <p class="text-brand-200/80 mt-1 text-sm">Process monthly salary deductions per order.</p>
            </div>
            <div class="flex gap-3">
                <a href="salary_deductions.php" class="px-4 py-2 bg-white/10 text-white rounded-lg hover:bg-white/20 transition text-sm font-medium">
                    <i class="fas fa-list-ul text-sm mr-2"></i>View All
                </a>
                <a href="dashboard.php" class="px-4 py-2 bg-white/10 text-white rounded-lg hover:bg-white/20 transition text-sm font-medium">
                    <i class="fas fa-chart-pie text-sm mr-2"></i>Dashboard
                </a>
            </div>
        </div>
    </div>
</div>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

    <!-- FILTER SECTION -->
    <div class="pro-card p-4 mb-6">
        <form method="GET" action="" id="filterForm">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                <div>
                    <div class="filter-label">
                        <i class="fas fa-search mr-1 text-xs"></i> Search
                    </div>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="Name, email..." 
                           class="filter-input text-sm">
                </div>

                <div>
                    <div class="filter-label">
                        <i class="fas fa-building mr-1 text-xs"></i> Department
                    </div>
                    <select name="department" class="filter-select text-sm">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $department == $dept ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <div class="filter-label">
                        <i class="fas fa-chart-pie mr-1 text-xs"></i> Progress
                    </div>
                    <select name="status" class="filter-select text-sm">
                        <option value="">All Status</option>
                        <option value="has_balance" <?php echo $statusFilter == 'has_balance' ? 'selected' : ''; ?>>Has Balance</option>
                        <option value="completed_soon" <?php echo $statusFilter == 'completed_soon' ? 'selected' : ''; ?>>70%+ Paid</option>
                        <option value="barely_started" <?php echo $statusFilter == 'barely_started' ? 'selected' : ''; ?>>&lt;30% Paid</option>
                    </select>
                </div>

                <div>
                    <div class="filter-label">
                        <i class="fas fa-calendar-alt mr-1 text-xs"></i> Date Range
                    </div>
                    <div class="flex gap-2">
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>" 
                               class="filter-input text-sm" placeholder="From">
                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>" 
                               class="filter-input text-sm" placeholder="To">
                    </div>
                </div>
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="btn-brand w-400 justify-center text-sm">
                        <i class="fas fa-filter text-xs mr-1"></i> Apply
                    </button>
                    <a href="process_deduction.php" class="btn-secondary justify-center text-sm px-3" title="Reset filters">
                        <i class="fas fa-redo-alt text-xs"></i>
                    </a>
                </div>
            

            <?php if ($search || $department || $statusFilter || $dateFrom || $dateTo): ?>
            <div class="flex flex-wrap gap-2 mt-3 pt-2 border-t border-gray-100">
                <span class="text-xs text-gray-400">Active filters:</span>
                <?php if ($search): ?>
                    <span class="active-filter-badge text-xs">
                        Search: <?php echo htmlspecialchars(substr($search, 0, 20)); ?>
                        <a href="?<?php echo http_build_query(array_filter(['department'=>$department,'status'=>$statusFilter,'date_from'=>$dateFrom,'date_to'=>$dateTo, 'page'=>$page])); ?>">
                            <i class="fas fa-times-circle ml-1 text-[10px]"></i>
                        </a>
                    </span>
                <?php endif; ?>
                <?php if ($department): ?>
                    <span class="active-filter-badge text-xs">
                        Dept: <?php echo htmlspecialchars($department); ?>
                        <a href="?<?php echo http_build_query(array_filter(['search'=>$search,'status'=>$statusFilter,'date_from'=>$dateFrom,'date_to'=>$dateTo, 'page'=>$page])); ?>">
                            <i class="fas fa-times-circle ml-1 text-[10px]"></i>
                        </a>
                    </span>
                <?php endif; ?>
                <?php if ($statusFilter): ?>
                    <span class="active-filter-badge text-xs">
                        <?php echo $statusFilter == 'has_balance' ? 'Has Balance' : ($statusFilter == 'completed_soon' ? 'Near Complete' : 'Just Started'); ?>
                        <a href="?<?php echo http_build_query(array_filter(['search'=>$search,'department'=>$department,'date_from'=>$dateFrom,'date_to'=>$dateTo, 'page'=>$page])); ?>">
                            <i class="fas fa-times-circle ml-1 text-[10px]"></i>
                        </a>
                    </span>
                <?php endif; ?>
                <?php if ($dateFrom): ?>
                    <span class="active-filter-badge text-xs">
                        From: <?php echo date('M d', strtotime($dateFrom)); ?>
                        <a href="?<?php echo http_build_query(array_filter(['search'=>$search,'department'=>$department,'status'=>$statusFilter,'date_to'=>$dateTo, 'page'=>$page])); ?>">
                            <i class="fas fa-times-circle ml-1 text-[10px]"></i>
                        </a>
                    </span>
                <?php endif; ?>
                <?php if ($dateTo): ?>
                    <span class="active-filter-badge text-xs">
                        To: <?php echo date('M d', strtotime($dateTo)); ?>
                        <a href="?<?php echo http_build_query(array_filter(['search'=>$search,'department'=>$department,'status'=>$statusFilter,'date_from'=>$dateFrom, 'page'=>$page])); ?>">
                            <i class="fas fa-times-circle ml-1 text-[10px]"></i>
                        </a>
                    </span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </form>
    </div>

    <?php if (empty($pendingDeductions)): ?>
        <div class="pro-card p-8 text-center">
            <div class="empty-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <h3 class="text-base font-semibold text-gray-800 mb-1">No Pending Deductions Found</h3>
            <p class="text-sm text-gray-500 max-w-md mx-auto">
                <?php echo ($search || $department || $statusFilter || $dateFrom || $dateTo) ? 'No deductions match your filter criteria. Try adjusting your filters.' : 'All salary deductions have been processed. No pending deductions at this time.'; ?>
            </p>
            <?php if ($search || $department || $statusFilter || $dateFrom || $dateTo): ?>
                <a href="process_deduction.php" class="btn-brand inline-flex mt-4 text-sm">
                    <i class="fas fa-undo-alt mr-1"></i> Clear Filters
                </a>
            <?php else: ?>
                <a href="salary_deductions.php" class="btn-brand inline-flex mt-4 text-sm">
                    <i class="fas fa-list-ul mr-1"></i> View All Deductions
                </a>
            <?php endif; ?>
        </div>
    <?php else: ?>

    <!-- Summary Statistics -->
    <?php 
        $totalBalance = array_sum(array_column($pendingDeductions, 'remaining'));
        $totalAmount = array_sum(array_column($pendingDeductions, 'total_amount'));
        $totalPaid = array_sum(array_column($pendingDeductions, 'total_paid'));
        $overallPercentage = $totalAmount > 0 ? ($totalPaid / $totalAmount) * 100 : 0;
    ?>

    <div class="summary-stats mb-6">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 relative z-10">
            <div>
                <p class="text-teal-200 text-[10px] uppercase tracking-wider font-semibold mb-0.5">Pending</p>
                <p class="text-2xl text-white font-bold"><?php echo count($pendingDeductions); ?></p>
            </div>
            <div>
                <p class="text-teal-200 text-[10px] uppercase tracking-wider font-semibold mb-0.5">Total Owed</p>
                <p class="text-2xl text-white font-bold">₱<?php echo safeNumber($totalBalance); ?></p>
            </div>
            <div>
                <p class="text-teal-200 text-[10px] uppercase tracking-wider font-semibold mb-0.5">Total Paid</p>
                <p class="text-2xl text-white font-bold">₱<?php echo safeNumber($totalPaid); ?></p>
            </div>
            <div>
                <p class="text-teal-200 text-[10px] uppercase tracking-wider font-semibold mb-0.5">Progress</p>
                <p class="text-2xl text-white font-bold"><?php echo safeNumber($overallPercentage, 1); ?>%</p>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">

    <?php foreach ($pendingDeductions as $deduction): 

        $totalAmount = $deduction['total_amount'] ?? 0;
        $totalPaid = $deduction['total_paid'] ?? 0;
        $remaining = $deduction['remaining'] ?? 0;
        $percentage = $deduction['percentage'] ?? 0;
        $isCompleted = ($remaining <= 0.009); // Use epsilon for completion check
        $completedAt = $deduction['completed_at'] ?? null;

        $history = $deduction['payment_history'] ?? [];
        $items = $deduction['order_items'] ?? [];
    ?>

    <div class="deduction-card">
        <div class="p-4">

            <!-- HEADER -->
            <div class="flex flex-wrap justify-between items-start gap-2 mb-3">
                <div>
                    <div class="flex items-center gap-2 flex-wrap mb-1">
                        <span class="bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded text-[10px] font-mono font-semibold">
                            #DED-<?php echo str_pad($deduction['deduction_id'], 6, '0', STR_PAD_LEFT); ?>
                        </span>
                        <span class="bg-brand-50 text-brand-600 px-1.5 py-0.5 rounded text-[10px] font-mono font-semibold">
                            #<?php echo str_pad($deduction['order_id'], 6, '0', STR_PAD_LEFT); ?>
                        </span>
                    </div>
                    <div>
                        <p class="font-bold text-gray-800 text-base"><?php echo htmlspecialchars($deduction['full_name'] ?? 'Unknown'); ?></p>
                        <p class="text-[11px] text-gray-500 mt-0.5"><?php echo htmlspecialchars($deduction['email'] ?? ''); ?></p>
                        <p class="text-[10px] text-gray-400 mt-0.5"><?php echo htmlspecialchars($deduction['department'] ?? 'N/A'); ?> &middot; <?php echo htmlspecialchars($deduction['position'] ?? 'N/A'); ?></p>
                        <?php if ($isCompleted && $completedAt): ?>
                            <p class="text-[9px] text-green-600 mt-0.5">
                                <i class="fas fa-check-circle mr-1"></i> Completed: <?php echo date('M d, Y g:i A', strtotime($completedAt)); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="status-badge <?php echo $isCompleted ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-amber-50 text-amber-700 border-amber-200'; ?>">
                    <i class="fas <?php echo $isCompleted ? 'fa-check-circle' : 'fa-clock'; ?> text-[8px]"></i>
                    <?php echo $isCompleted ? 'Completed' : 'Pending'; ?>
                </div>
            </div>

            <!-- ORDER ITEMS -->
            <?php if (!empty($items)): ?>
            <div class="bg-brand-50/40 rounded-lg p-2 mb-3">
                <p class="text-[9px] font-bold text-brand-600 uppercase tracking-wider mb-1.5">
                    <i class="fas fa-box-open mr-1 text-[8px]"></i> Items
                </p>
                <ul class="order-items-list">
                    <?php foreach (array_slice($items, 0, 2) as $item): ?>
                    <li class="text-[10px]">
                        <?php echo htmlspecialchars($item['fish_name'] ?? 'Unknown'); ?>
                        <span class="text-gray-400">&middot;</span>
                        <?php echo number_format($item['quantity'] ?? 0, 1); ?> kg
                    </li>
                    <?php endforeach; ?>
                    <?php if (count($items) > 2): ?>
                        <li class="text-[9px] text-gray-400">+<?php echo count($items) - 2; ?> more item(s)</li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- AMOUNT SUMMARY -->
            <div class="grid grid-cols-3 gap-2 mb-3">
                <div class="stat-card text-center p-1.5">
                    <p class="text-[8px] font-semibold text-gray-400 uppercase">Total</p>
                    <p class="text-xs font-bold text-brand-600">₱<?php echo safeNumber($totalAmount); ?></p>
                </div>
                <div class="stat-card text-center p-1.5">
                    <p class="text-[8px] font-semibold text-gray-400 uppercase">Paid</p>
                    <p class="text-xs font-bold text-green-600">₱<?php echo safeNumber($totalPaid); ?></p>
                </div>
                <div class="stat-card text-center p-1.5">
                    <p class="text-[8px] font-semibold text-gray-400 uppercase">Remaining</p>
                    <p class="text-xs font-bold text-red-600">₱<?php echo safeNumber($remaining); ?></p>
                </div>
            </div>

            <!-- PROGRESS BAR -->
            <div class="mb-3">
                <div class="flex justify-between text-[10px] mb-1">
                    <span class="text-gray-500">Progress</span>
                    <span class="font-semibold text-gray-700"><?php echo safeNumber($percentage, 1); ?>%</span>
                </div>
                <div class="progress-container">
                    <div class="progress-fill" style="width: <?php echo $percentage; ?>%"></div>
                </div>
            </div>

            <!-- PAYMENT HISTORY SUMMARY -->
            <div class="border-t border-gray-100 pt-2 mt-1">
                <div class="flex items-center gap-1 mb-1.5">
                    <i class="fas fa-history text-gray-400 text-[9px]"></i>
                    <span class="text-[8px] font-bold text-gray-500 uppercase tracking-wider">Payments</span>
                    <?php if (!empty($history)): ?>
                        <span class="text-[8px] text-gray-400">(<?php echo count($history); ?>)</span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($history)): ?>
                    <div class="space-y-1 max-h-28 overflow-y-auto">
                        <?php foreach (array_slice($history, 0, 2) as $h): ?>
                            <div class="history-item p-1.5">
                                <div class="flex items-center gap-1.5">
                                    <div class="w-5 h-5 bg-emerald-50 rounded-full flex items-center justify-center flex-shrink-0">
                                        <i class="fas fa-money-bill-wave text-emerald-600 text-[8px]"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="font-semibold text-gray-700 text-[10px]">
                                            ₱<?php echo safeNumber($h['amount_deducted']); ?>
                                            <span class="text-gray-400 text-[8px]"><?php echo htmlspecialchars(substr($h['payroll_period'] ?? '', 0, 15)); ?></span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($history) > 2): ?>
                            <p class="text-[8px] text-gray-400 text-center">+<?php echo count($history) - 2; ?> more</p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-1.5 bg-gray-50 rounded text-gray-400 text-[9px]">
                        No payments yet
                    </div>
                <?php endif; ?>
            </div>

            <!-- PROCESS BUTTON -->
            <?php if (!$isCompleted): ?>
            <div class="mt-3 pt-2 border-t border-gray-100">
                <button onclick="openProcessModal(<?php echo $deduction['deduction_id']; ?>, '<?php echo htmlspecialchars($deduction['full_name'] ?? 'Unknown', ENT_QUOTES); ?>', <?php echo $remaining; ?>)" 
                        class="btn-success-pro w-full text-sm py-1.5">
                    <i class="fas fa-money-bill-wave mr-1 text-xs"></i>
                    Process Deduction
                </button>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <?php endforeach; ?>

    </div>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="mt-6">
        <?php 
        $queryParams = $_GET;
        unset($queryParams['page']);
        echo buildPaginationLinks($page, $totalPages, $queryParams); 
        ?>
        <div class="text-center text-[11px] text-gray-400 mt-2">
            Showing <?php echo count($pendingDeductions); ?> of <?php echo number_format($totalRecords); ?> deductions
            <span class="mx-1">•</span>
            Page <?php echo $page; ?> of <?php echo $totalPages; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>

</div>

<!-- Process Deduction Modal -->
<div id="processModal" class="modal">
    <div class="modal-content">
        <div class="flex justify-between items-center mb-4 pb-2 border-b border-gray-100">
            <div>
                <h3 class="text-base font-semibold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-money-bill-wave text-brand-500"></i>
                    Process Salary Deduction
                </h3>
                <p class="text-[10px] text-gray-500 mt-0.5">Enter deduction details below</p>
            </div>
            <button onclick="closeProcessModal()" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
        </div>

        <form method="POST" id="processForm">
            <input type="hidden" name="deduction_id" id="modal_deduction_id">

            <?php if (!empty($search)): ?>
                <input type="hidden" name="filter_search" value="<?php echo htmlspecialchars($search); ?>">
            <?php endif; ?>
            <?php if (!empty($department)): ?>
                <input type="hidden" name="filter_department" value="<?php echo htmlspecialchars($department); ?>">
            <?php endif; ?>
            <?php if (!empty($statusFilter)): ?>
                <input type="hidden" name="filter_status" value="<?php echo htmlspecialchars($statusFilter); ?>">
            <?php endif; ?>
            <?php if (!empty($dateFrom)): ?>
                <input type="hidden" name="filter_date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
            <?php endif; ?>
            <?php if (!empty($dateTo)): ?>
                <input type="hidden" name="filter_date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
            <?php endif; ?>
            <input type="hidden" name="filter_page" value="<?php echo $page; ?>">

            <div class="mb-3 p-2.5 bg-gray-50 rounded-lg border border-gray-100">
                <p class="text-[9px] text-gray-500 font-medium uppercase tracking-wider mb-0.5">Employee</p>
                <p class="font-semibold text-gray-800 text-sm" id="modal_employee_name"></p>
            </div>

            <div class="mb-4 p-2.5 bg-red-50 rounded-lg border border-red-100">
                <p class="text-[9px] text-red-500 font-medium uppercase tracking-wider mb-0.5">Remaining Balance</p>
                <p class="text-xl font-bold text-red-600" id="modal_remaining_balance"></p>
            </div>

            <div class="mb-3">
                <label class="block text-xs font-semibold text-gray-600 mb-1">Amount to Deduct <span class="text-red-500">*</span></label>
                <div class="relative">
                    <span class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 font-medium text-sm">₱</span>
                    <input type="number" name="amount_deducted" id="modal_amount" 
                           step="0.01" min="0.01" required
                           class="filter-input pl-7 text-sm font-semibold" 
                           placeholder="0.00">
                </div>
                <div class="mt-2 flex gap-2">
                    <button type="button" onclick="setAmount('full')" class="quick-amount-btn text-[11px] py-1 px-2">Full Balance</button>
                    <button type="button" onclick="setAmount('half')" class="quick-amount-btn text-[11px] py-1 px-2">Half</button>
                    <button type="button" onclick="setAmount('quarter')" class="quick-amount-btn text-[11px] py-1 px-2">Quarter</button>
                </div>
            </div>

            <div class="mb-3">
                <label class="block text-xs font-semibold text-gray-600 mb-1">Payroll Period <span class="text-red-500">*</span></label>
                <input type="text" name="payroll_period" id="modal_period" required
                       class="filter-input text-sm" 
                       placeholder="e.g., May 2026 (1st half)" 
                       value="<?php echo date('F Y'); ?>">
            </div>

            <div class="mb-4">
                <label class="block text-xs font-semibold text-gray-600 mb-1">Remarks <span class="text-gray-400 font-normal">(Optional)</span></label>
                <textarea name="remarks" id="modal_remarks" rows="2" class="filter-input text-sm resize-none" 
                          placeholder="Any notes about this deduction..."></textarea>
            </div>

            <div class="flex justify-end gap-2 pt-2 border-t border-gray-100">
                <button type="button" onclick="closeProcessModal()" class="btn-secondary text-sm py-1.5 px-3">
                    Cancel
                </button>
                <button type="submit" class="btn-brand text-sm py-1.5 px-3">
                    <i class="fas fa-check-circle mr-1"></i> Process
                </button>
            </div>
        </form>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
    let currentBalance = 0;

    function openProcessModal(deductionId, employeeName, remainingBalance) {
        currentBalance = parseFloat(remainingBalance);

        document.getElementById('modal_deduction_id').value = deductionId;
        document.getElementById('modal_employee_name').innerHTML = employeeName;
        document.getElementById('modal_remaining_balance').innerHTML = '₱' + currentBalance.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('modal_amount').value = '';
        document.getElementById('modal_amount').step = '0.01';
        document.getElementById('modal_amount').min = '0.01';
        document.getElementById('modal_amount').max = currentBalance;
        document.getElementById('modal_amount').placeholder = 'Max ₱' + currentBalance.toLocaleString();
        document.getElementById('modal_remarks').value = '';
        document.getElementById('processModal').classList.add('show');

        setTimeout(() => document.getElementById('modal_amount').focus(), 100);
    }

    function closeProcessModal() {
        document.getElementById('processModal').classList.remove('show');
    }

    function setAmount(type) {
        let amount = 0;
        switch(type) {
            case 'full':
                amount = currentBalance;
                break;
            case 'half':
                amount = currentBalance / 2;
                break;
            case 'quarter':
                amount = currentBalance / 4;
                break;
        }
        // Round to 2 decimal places
        amount = Math.round(amount * 100) / 100;
        document.getElementById('modal_amount').value = amount.toFixed(2);
    }

    document.getElementById('processForm').addEventListener('submit', function(e) {
        let amount = parseFloat(document.getElementById('modal_amount').value);
        const period = document.getElementById('modal_period').value.trim();

        // Round to handle floating point precision
        amount = Math.round(amount * 100) / 100;
        
        if (isNaN(amount) || amount < 0.01) {
            e.preventDefault();
            alert('Please enter a valid amount. Minimum amount is ₱0.01');
            return false;
        }
        
        // Allow a small tolerance for floating point comparison
        if (amount > (currentBalance + 0.009)) {
            e.preventDefault();
            alert('Amount cannot exceed remaining balance of ₱' + currentBalance.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
            return false;
        }
        
        if (!period) {
            e.preventDefault();
            alert('Please enter a payroll period');
            return false;
        }

        let message = 'Process deduction of ₱' + amount.toLocaleString(undefined, {minimumFractionDigits: 2}) + ' for this employee?';
        if (amount >= (currentBalance - 0.009)) { // Consider as completion if within 1 cent
            message += '\n\n⚠️ This will COMPLETE the deduction.';
        }

        if (confirm(message)) {
            return true;
        }
        e.preventDefault();
        return false;
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeProcessModal();
        }
    });

    window.onclick = function(e) {
        if (e.target === document.getElementById('processModal')) {
            closeProcessModal();
        }
    };

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