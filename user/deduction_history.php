<?php
// user/deduction_history.php - Professional UI (WITH NOTIFICATIONS)
require_once '../includes/config.php';
require_once '../includes/session.php';

// Only allow standard users
SessionManager::requireStandard();

$userId = SessionManager::getUserId();

// Debug: Check if user is logged in
if (!$userId) {
    error_log("Deduction history: No user ID found");
    SessionManager::setError("You must be logged in to view deduction history.");
    header('Location: ../login.php');
    exit();
}

$message = $_GET['message'] ?? '';
$messageType = $_GET['type'] ?? '';

// Check if this is a redirect after deduction processing
if (isset($_GET['deduction_processed']) && $_GET['deduction_processed'] == 1) {
    $message = "Your salary deduction has been processed successfully. Payment has been deducted from your salary.";
    $messageType = 'success';
}

// Get filter parameters
$statusFilter = $_GET['status'] ?? 'all';
$yearFilter = $_GET['year'] ?? 'all';

// Helper function to safely format date
function safeDateFormat($date, $format = 'M d, Y') {
    if (empty($date) || $date === null) {
        return 'N/A';
    }
    try {
        return date($format, strtotime($date));
    } catch (Exception $e) {
        return 'N/A';
    }
}

function safeDateTime($date) {
    if (empty($date) || $date === null) {
        return 'N/A';
    }
    try {
        return date('F d, Y', strtotime($date));
    } catch (Exception $e) {
        return 'N/A';
    }
}

// Function to create notification for user
function createDeductionNotification($userId, $orderId, $amountDeducted, $remainingBalance) {
    try {
        $db = (new Database())->getConnection();
        
        $title = "Salary Deduction Processed";
        $message = "₱" . number_format($amountDeducted, 2) . " has been deducted from your salary for Order #" . str_pad($orderId, 6, '0', STR_PAD_LEFT) . ". ";
        
        if ($remainingBalance <= 0) {
            $message .= "Your balance is now fully paid. Thank you!";
        } else {
            $message .= "Remaining balance: ₱" . number_format($remainingBalance, 2);
        }
        
        $type = "salary_deduction";
        
        $stmt = $db->prepare("
            INSERT INTO notifications (user_id, title, message, type, is_read, created_at)
            VALUES (:user_id, :title, :message, :type, false, NOW())
        ");
        
        $stmt->execute([
            ':user_id' => $userId,
            ':title' => $title,
            ':message' => $message,
            ':type' => $type
        ]);
        
        return true;
    } catch (Exception $e) {
        error_log("Failed to create deduction notification: " . $e->getMessage());
        return false;
    }
}

try {
    $db = (new Database())->getConnection();

    // Check if salary_deductions table exists
    $checkTable = $db->query("SELECT to_regclass('public.salary_deductions')");
    $tableExists = $checkTable && $checkTable->fetchColumn();

    if (!$tableExists) {
        $deductions = [];
        $message = "Salary deductions feature is not available yet.";
        $messageType = 'info';
    } else {
        // Get DISTINCT salary deductions (no duplicates)
        $sql = "SELECT DISTINCT
                    sd.deduction_id,
                    sd.order_id,
                    sd.user_id,
                    sd.total_amount,
                    sd.amount_paid,
                    sd.remaining_balance,
                    sd.deduction_status,
                    sd.deduction_start_date,
                    sd.deduction_end_date,
                    sd.remarks as deduction_remarks,
                    sd.created_at as deduction_created_at,
                    sd.updated_at,
                    o.order_date,
                    o.payment_method,
                    o.order_status,
                    o.total_amount as order_total
                FROM salary_deductions sd
                LEFT JOIN orders o ON sd.order_id = o.order_id
                WHERE sd.user_id = :user_id";

        $params = [':user_id' => $userId];

        // Apply status filter - pending and completed only
        if ($statusFilter !== 'all') {
            $sql .= " AND sd.deduction_status = :status";
            $params[':status'] = $statusFilter;
        }

        // Apply year filter
        if ($yearFilter !== 'all' && !empty($yearFilter) && is_numeric($yearFilter)) {
            $sql .= " AND EXTRACT(YEAR FROM sd.created_at) = :year";
            $params[':year'] = (int)$yearFilter;
        }

        $sql .= " ORDER BY sd.created_at DESC";

        $stmt = $db->prepare($sql);

        // Bind all parameters
        foreach ($params as $key => $value) {
            if ($key === ':user_id' || $key === ':year') {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $value);
            }
        }

        $stmt->execute();
        $deductions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get payment history and calculate actual paid amount from deduction_history
        $newDeductionsProcessed = false;
        
        foreach ($deductions as $key => $deduction) {
            // Get all payments with amount_deducted for this deduction
            $historySql = "SELECT 
                                history_id,
                                deduction_id,
                                amount_deducted,
                                payroll_period,
                                remarks,
                                created_at
                           FROM deduction_history
                           WHERE deduction_id = :deduction_id
                           ORDER BY created_at DESC";

            $historyStmt = $db->prepare($historySql);
            $historyStmt->execute([':deduction_id' => $deduction['deduction_id']]);
            $history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Check if there are NEW payments (from last 5 minutes) that haven't been notified
            foreach ($history as $payment) {
                $paymentTime = strtotime($payment['created_at']);
                $currentTime = time();
                $timeDiff = $currentTime - $paymentTime;
                
                // If payment was made in the last 5 minutes and no notification flag exists
                if ($timeDiff < 300 && $timeDiff > 0) {
                    // Check if we've already notified for this payment
                    $notifCheckSql = "SELECT COUNT(*) FROM notifications 
                                      WHERE user_id = :user_id 
                                      AND message LIKE :search_pattern 
                                      AND created_at > :payment_time";
                    $searchPattern = '%' . number_format($payment['amount_deducted'], 2) . '%';
                    $paymentTimeStr = date('Y-m-d H:i:s', strtotime($payment['created_at']) - 60);
                    
                    $notifCheck = $db->prepare($notifCheckSql);
                    $notifCheck->execute([
                        ':user_id' => $userId,
                        ':search_pattern' => $searchPattern,
                        ':payment_time' => $paymentTimeStr
                    ]);
                    
                    $notificationExists = $notifCheck->fetchColumn() > 0;
                    
                    if (!$notificationExists && $payment['amount_deducted'] > 0) {
                        // Calculate new remaining balance
                        $newRemaining = max(0, $deduction['total_amount'] - ($deduction['amount_paid'] ?? 0));
                        createDeductionNotification($userId, $deduction['order_id'], $payment['amount_deducted'], $newRemaining);
                        $newDeductionsProcessed = true;
                    }
                }
            }

            // Update the array properly using index
            $deductions[$key]['payment_history'] = $history;
            $deductions[$key]['total_paid_from_history'] = array_sum(array_column($history, 'amount_deducted'));
            $deductions[$key]['payment_count'] = count($history);

            // Calculate remaining balance based on total amount minus paid amount
            $deductions[$key]['calculated_remaining_balance'] = max(0, $deduction['total_amount'] - $deductions[$key]['total_paid_from_history']);

            // Calculate payment progress percentage
            $deductions[$key]['payment_percentage'] = $deduction['total_amount'] > 0 
                ? ($deductions[$key]['total_paid_from_history'] / $deduction['total_amount']) * 100 
                : 0;

            // Update status based on payment progress
            if ($deductions[$key]['calculated_remaining_balance'] <= 0 && $deduction['total_amount'] > 0) {
                $deductions[$key]['deduction_status'] = 'completed';
            } elseif ($deductions[$key]['total_paid_from_history'] > 0 && $deductions[$key]['calculated_remaining_balance'] > 0) {
                $deductions[$key]['deduction_status'] = 'pending';
            }
        }
        
        // Redirect to show notification message if new deductions were processed
        if ($newDeductionsProcessed && !isset($_GET['deduction_processed'])) {
            header('Location: deduction_history.php?deduction_processed=1&status=' . urlencode($statusFilter) . '&year=' . urlencode($yearFilter));
            exit();
        }

        // Remove any duplicate deduction_ids (just in case)
        $uniqueDeductions = [];
        $seenIds = [];
        foreach ($deductions as $deduction) {
            if (!in_array($deduction['deduction_id'], $seenIds)) {
                $seenIds[] = $deduction['deduction_id'];
                $uniqueDeductions[] = $deduction;
            }
        }
        $deductions = $uniqueDeductions;

        // Get summary statistics based on actual payments
        $totalDeductions = array_sum(array_column($deductions, 'total_amount'));
        $totalPaid = array_sum(array_column($deductions, 'total_paid_from_history'));
        $totalRemaining = array_sum(array_column($deductions, 'calculated_remaining_balance'));

        // Count pending and completed
        $pendingCount = 0;
        $completedCount = 0;
        foreach ($deductions as $d) {
            if ($d['deduction_status'] == 'pending') {
                $pendingCount++;
            }
            if ($d['deduction_status'] == 'completed') {
                $completedCount++;
            }
        }

        // Get available years for filter
        $yearsSql = "SELECT DISTINCT EXTRACT(YEAR FROM created_at) as year 
                     FROM salary_deductions 
                     WHERE user_id = :user_id 
                     ORDER BY year DESC";
        $yearsStmt = $db->prepare($yearsSql);
        $yearsStmt->execute([':user_id' => $userId]);
        $availableYears = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);
    }

} catch (PDOException $e) {
    error_log("Deduction history error: " . $e->getMessage());
    $deductions = [];
    $message = "Database error: " . $e->getMessage();
    $messageType = 'error';
}

function getDeductionStatusBadge($status) {
    $badges = [
        'pending' => ['bg-amber-50', 'text-amber-700', 'border-amber-200', 'fa-clock', 'Pending', 'Partially paid / Ongoing'],
        'completed' => ['bg-emerald-50', 'text-emerald-700', 'border-emerald-200', 'fa-check-circle', 'Completed', 'Fully paid'],
        'cancelled' => ['bg-red-50', 'text-red-700', 'border-red-200', 'fa-times-circle', 'Cancelled', 'Order cancelled']
    ];
    return $badges[$status] ?? ['bg-amber-50', 'text-amber-700', 'border-amber-200', 'fa-clock', 'Pending', 'Awaiting deduction'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Salary Deduction History - BISU IGE Aquaculture</title>
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

        /* Deduction Card */
        .deduction-card-pro {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .deduction-card-pro:hover {
            box-shadow: 0 8px 30px -4px rgba(0, 0, 0, 0.1);
            border-color: #cbd5e1;
            transform: translateY(-2px);
        }

        /* Buttons */
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
            color: white;
        }

        .btn-ghost {
            display: inline-flex;
            align-items: center;
            justify-content: center;
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

        .btn-outline-brand {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: white;
            color: var(--brand);
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.8125rem;
            text-decoration: none;
            border: 1.5px solid var(--brand);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-outline-brand:hover {
            background: var(--brand);
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(14, 165, 233, 0.2);
        }

        /* Status Badge */
        .badge-pro {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.875rem;
            border-radius: 9999px;
            font-size: 0.6875rem;
            font-weight: 600;
            letter-spacing: 0.025em;
            text-transform: uppercase;
            border: 1px solid;
        }

        /* Filter Tabs */
        .filter-tab-pro {
            padding: 0.625rem 1.25rem;
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
            transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Info Card */
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

        /* History Item */
        .history-item-pro {
            background: #fafafa;
            border: 1px solid #f1f5f9;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 0.5rem;
            transition: all 0.2s ease;
        }

        .history-item-pro:hover {
            background: #f8fafc;
            border-color: #e2e8f0;
            transform: translateX(3px);
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

        /* Section Header */
        .section-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
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

        /* Scrollbar */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        /* Year Select */
        .year-select {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.5rem 2.5rem 0.5rem 0.75rem;
            font-size: 0.875rem;
            color: #475569;
            background: white;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2364748b'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.5rem center;
            background-size: 1rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .year-select:focus {
            outline: none;
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
        }

        /* Notification animation */
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .notification-animation {
            animation: pulse 0.5s ease-in-out;
        }
    </style>
</head>
<body class="antialiased">
    <?php include '../includes/navbar.php'; ?>

    <?php if ($message): ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-6">
        <div class="flash-msg bg-white shadow-sm notification-animation" style="border-color: <?php echo $messageType == 'success' ? '#10b981' : ($messageType == 'info' ? '#0ea5e9' : '#ef4444'); ?>; border-left-width: 4px;">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center <?php echo $messageType == 'success' ? 'bg-emerald-50 text-emerald-600' : ($messageType == 'info' ? 'bg-sky-50 text-sky-600' : 'bg-red-50 text-red-600'); ?>">
                    <i class="fas <?php echo $messageType == 'success' ? 'fa-check-circle' : ($messageType == 'info' ? 'fa-info-circle' : 'fa-exclamation-triangle'); ?> text-sm"></i>
                </div>
                <div>
                    <p class="text-sm font-semibold text-gray-900"><?php echo $messageType == 'success' ? 'Payment Processed!' : 'Information'; ?></p>
                    <p class="text-sm text-gray-700"><?php echo htmlspecialchars($message); ?></p>
                </div>
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
                    <p class="text-brand-300 text-sm font-medium tracking-wide uppercase mb-2">Payments</p>
                    <h1 class="text-3xl md:text-4xl font-bold text-white font-display">
                        Salary Deduction History
                    </h1>
                    <p class="text-brand-200/80 mt-2 text-sm max-w-md">Track your salary deduction payments, monitor balances, and view payment history.</p>
                </div>
                <div class="flex gap-3">
                    <a href="dashboard.php" class="btn-ghost" style="border-color: rgba(255,255,255,0.2); color: white;">
                        <i class="fas fa-chart-line text-sm"></i>
                        Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

        <?php if (empty($deductions)): ?>
            <div class="pro-card p-12 text-center">
                <div class="empty-icon">
                    <i class="fas fa-receipt"></i>
                </div>
                <h3 class="text-base font-semibold text-gray-900 mb-1">
                    <?php 
                    if ($statusFilter == 'pending') echo 'No pending salary deductions found';
                    elseif ($statusFilter == 'completed') echo 'No completed salary deductions found';
                    else echo 'No salary deductions found';
                    ?>
                </h3>
                <p class="text-sm text-gray-500 mb-5">
                    <?php 
                    if ($statusFilter == 'pending') echo 'You dont have any pending salary deduction records.';
                    elseif ($statusFilter == 'completed') echo 'You dont have any completed salary deduction records yet.';
                    else echo 'You dont have any salary deduction records yet.';
                    ?>
                </p>
                <a href="products.php" class="btn-brand">
                    <i class="fas fa-fish text-sm"></i> Browse Products
                </a>
            </div>
        <?php else: ?>

        <!-- Summary Statistics -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <div class="stat-card-pro">
                <div class="flex items-start justify-between mb-3">
                    <div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Total Deductions</p>
                        <p class="text-2xl font-bold text-gray-900 mt-1">₱<?php echo number_format($totalDeductions, 2); ?></p>
                    </div>
                    <div class="w-10 h-10 rounded-lg bg-violet-50 flex items-center justify-center text-violet-600">
                        <i class="fas fa-calculator text-sm"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card-pro">
                <div class="flex items-start justify-between mb-3">
                    <div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Total Paid</p>
                        <p class="text-2xl font-bold text-emerald-600 mt-1">₱<?php echo number_format($totalPaid, 2); ?></p>
                    </div>
                    <div class="w-10 h-10 rounded-lg bg-emerald-50 flex items-center justify-center text-emerald-600">
                        <i class="fas fa-check-circle text-sm"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card-pro">
                <div class="flex items-start justify-between mb-3">
                    <div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Remaining Balance</p>
                        <p class="text-2xl font-bold text-red-600 mt-1">₱<?php echo number_format($totalRemaining, 2); ?></p>
                    </div>
                    <div class="w-10 h-10 rounded-lg bg-red-50 flex items-center justify-center text-red-600">
                        <i class="fas fa-clock text-sm"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card-pro">
                <div class="flex items-start justify-between mb-3">
                    <div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">
                            <?php echo $statusFilter == 'pending' ? 'Pending Count' : ($statusFilter == 'completed' ? 'Completed Count' : 'Pending Deductions'); ?>
                        </p>
                        <p class="text-2xl font-bold text-amber-600 mt-1">
                            <?php 
                            if ($statusFilter == 'pending') echo $pendingCount;
                            elseif ($statusFilter == 'completed') echo $completedCount;
                            else echo $pendingCount;
                            ?>
                        </p>
                    </div>
                    <div class="w-10 h-10 rounded-lg bg-amber-50 flex items-center justify-center text-amber-600">
                        <i class="fas <?php echo $statusFilter == 'completed' ? 'fa-check-circle' : 'fa-clock'; ?> text-sm"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Tabs -->
        <div class="mb-8">
            <div class="flex flex-wrap gap-2 items-center justify-between">
                <div class="flex flex-wrap gap-2">
                    <a href="?status=all&year=<?php echo urlencode($yearFilter); ?>" 
                       class="filter-tab-pro <?php echo $statusFilter == 'all' ? 'active' : ''; ?>">
                        <i class="fas fa-list text-[10px]"></i>
                        All
                        <span class="filter-count ml-1.5"><?php echo count($deductions); ?></span>
                    </a>
                    <a href="?status=pending&year=<?php echo urlencode($yearFilter); ?>" 
                       class="filter-tab-pro <?php echo $statusFilter == 'pending' ? 'active' : ''; ?>">
                        <i class="fas fa-clock text-[10px]"></i>
                        Pending
                        <span class="filter-count ml-1.5"><?php echo $pendingCount; ?></span>
                    </a>
                    <a href="?status=completed&year=<?php echo urlencode($yearFilter); ?>" 
                       class="filter-tab-pro <?php echo $statusFilter == 'completed' ? 'active' : ''; ?>">
                        <i class="fas fa-check-circle text-[10px]"></i>
                        Completed
                        <span class="filter-count ml-1.5"><?php echo $completedCount; ?></span>
                    </a>
                </div>

                <?php if (!empty($availableYears)): ?>
                <form method="GET" class="flex items-center gap-2" id="yearFilterForm">
                    <?php if ($statusFilter !== 'all'): ?>
                        <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                    <?php endif; ?>
                    <select name="year" class="year-select" onchange="document.getElementById('yearFilterForm').submit()">
                        <option value="all" <?php echo $yearFilter == 'all' ? 'selected' : ''; ?>>All Years</option>
                        <?php foreach ($availableYears as $year): ?>
                            <option value="<?php echo $year; ?>" <?php echo $yearFilter == $year ? 'selected' : ''; ?>>
                                <?php echo $year; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Deductions List -->
        <div class="space-y-5">
            <?php foreach ($deductions as $deduction): 
                list($bgColor, $textColor, $borderColor, $icon, $statusText, $statusDesc) = getDeductionStatusBadge($deduction['deduction_status']);
                $paymentPercentage = $deduction['payment_percentage'];
                $progressColor = $paymentPercentage >= 100 ? 'bg-emerald-500' : ($paymentPercentage >= 50 ? 'bg-amber-500' : 'bg-brand-500');
            ?>
                <div class="deduction-card-pro">
                    <!-- Card Header -->
                    <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/80">
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                            <div class="flex items-center gap-4 flex-wrap">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-violet-500 to-violet-700 flex items-center justify-center shadow-sm">
                                        <i class="fas fa-receipt text-white text-sm"></i>
                                    </div>
                                    <div>
                                        <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider">Deduction ID</p>
                                        <p class="font-bold text-gray-900 text-base">#<?php echo $deduction['deduction_id']; ?></p>
                                    </div>
                                </div>

                                <div class="flex items-center gap-2 text-sm">
                                    <i class="fas fa-shopping-cart text-gray-400 text-xs"></i>
                                    <div>
                                        <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider">Order #</p>
                                        <p class="font-medium text-gray-700">#<?php echo str_pad($deduction['order_id'], 6, '0', STR_PAD_LEFT); ?></p>
                                    </div>
                                </div>

                                <div class="flex items-center gap-2 text-sm">
                                    <i class="far fa-calendar-alt text-gray-400 text-xs"></i>
                                    <div>
                                        <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider">Started</p>
                                        <p class="font-medium text-gray-700"><?php echo safeDateFormat($deduction['deduction_start_date'], 'M d, Y'); ?></p>
                                    </div>
                                </div>
                            </div>

                            <span class="badge-pro <?php echo $bgColor . ' ' . $textColor . ' ' . $borderColor; ?>">
                                <i class="fas <?php echo $icon; ?> text-[8px]"></i>
                                <?php echo $statusText; ?>
                            </span>
                        </div>
                    </div>

                    <!-- Card Body -->
                    <div class="p-6">
                        <!-- Amount Summary -->
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">
                            <div class="info-card">
                                <div class="flex items-center gap-2 mb-1">
                                    <div class="w-7 h-7 rounded-lg bg-violet-50 flex items-center justify-center text-violet-600">
                                        <i class="fas fa-chart-line text-[10px]"></i>
                                    </div>
                                    <span class="text-[11px] font-semibold text-gray-400 uppercase tracking-wider">Total Amount</span>
                                </div>
                                <p class="font-bold text-violet-600 text-lg">₱<?php echo number_format($deduction['total_amount'], 2); ?></p>
                            </div>

                            <div class="info-card">
                                <div class="flex items-center gap-2 mb-1">
                                    <div class="w-7 h-7 rounded-lg bg-emerald-50 flex items-center justify-center text-emerald-600">
                                        <i class="fas fa-check-circle text-[10px]"></i>
                                    </div>
                                    <span class="text-[11px] font-semibold text-gray-400 uppercase tracking-wider">Amount Paid</span>
                                </div>
                                <p class="font-bold text-emerald-600 text-lg">₱<?php echo number_format($deduction['total_paid_from_history'], 2); ?></p>
                            </div>

                            <div class="info-card">
                                <div class="flex items-center gap-2 mb-1">
                                    <div class="w-7 h-7 rounded-lg bg-red-50 flex items-center justify-center text-red-600">
                                        <i class="fas fa-clock text-[10px]"></i>
                                    </div>
                                    <span class="text-[11px] font-semibold text-gray-400 uppercase tracking-wider">Remaining</span>
                                </div>
                                <p class="font-bold text-red-600 text-lg">₱<?php echo number_format($deduction['calculated_remaining_balance'], 2); ?></p>
                            </div>
                        </div>

                        <!-- Payment Progress -->
                        <div class="mb-5">
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-xs font-medium text-gray-500 uppercase tracking-wider">Payment Progress</span>
                                <span class="text-sm font-bold text-gray-900"><?php echo number_format($paymentPercentage, 1); ?>%</span>
                            </div>
                            <div class="progress-track">
                                <div class="progress-fill <?php echo $progressColor; ?>" style="width: 0%" data-width="<?php echo $paymentPercentage; ?>"></div>
                            </div>
                        </div>

                        <!-- Deduction Period -->
                        <div class="info-card mb-5">
                            <div class="flex flex-wrap justify-between gap-4">
                                <div>
                                    <p class="text-[11px] font-semibold text-gray-400 uppercase tracking-wider">Start Date</p>
                                    <p class="font-medium text-gray-700 text-sm"><?php echo safeDateTime($deduction['deduction_start_date']); ?></p>
                                </div>
                                <div>
                                    <p class="text-[11px] font-semibold text-gray-400 uppercase tracking-wider">End Date</p>
                                    <p class="font-medium text-gray-700 text-sm"><?php echo safeDateTime($deduction['deduction_end_date']); ?></p>
                                </div>
                                <div>
                                    <p class="text-[11px] font-semibold text-gray-400 uppercase tracking-wider">Status</p>
                                    <p class="font-medium text-gray-700 text-sm"><?php echo $statusDesc; ?></p>
                                </div>
                            </div>
                        </div>

                        <!-- Payment History -->
                        <?php if (!empty($deduction['payment_history'])): ?>
                            <div class="mb-5">
                                <div class="flex items-center gap-2 mb-3">
                                    <div class="w-7 h-7 rounded-lg bg-brand-50 flex items-center justify-center text-brand-600">
                                        <i class="fas fa-history text-[10px]"></i>
                                    </div>
                                    <h4 class="text-sm font-bold text-gray-900">Payment History</h4>
                                    <span class="text-xs text-gray-400">(<?php echo $deduction['payment_count']; ?> deduction<?php echo $deduction['payment_count'] > 1 ? 's' : ''; ?>)</span>
                                </div>
                                <div class="space-y-2">
                                    <?php foreach ($deduction['payment_history'] as $payment): ?>
                                        <div class="history-item-pro">
                                            <div class="flex flex-wrap justify-between items-center gap-2">
                                                <div class="flex items-center gap-3">
                                                    <div class="w-8 h-8 rounded-lg bg-emerald-50 flex items-center justify-center text-emerald-600 flex-shrink-0">
                                                        <i class="fas fa-money-bill-wave text-[10px]"></i>
                                                    </div>
                                                    <div>
                                                        <p class="font-semibold text-gray-900 text-sm">
                                                            <span class="text-emerald-600">₱<?php echo number_format($payment['amount_deducted'], 2); ?></span> deducted
                                                        </p>
                                                        <p class="text-xs text-gray-500">
                                                            Payroll Period: <?php echo htmlspecialchars($payment['payroll_period'] ?? 'N/A'); ?>
                                                        </p>
                                                        <p class="text-xs text-gray-400">
                                                            <?php echo safeDateFormat($payment['created_at'], 'F d, Y'); ?>
                                                        </p>
                                                    </div>
                                                </div>
                                                <?php if (strtotime($payment['created_at']) > strtotime('-1 day')): ?>
                                                    <span class="text-[10px] font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full">NEW</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($payment['remarks'])): ?>
                                                <p class="text-xs text-gray-500 mt-1.5 ml-11">
                                                    <i class="fas fa-comment text-[10px] mr-1 text-gray-400"></i> <?php echo htmlspecialchars($payment['remarks']); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="info-card mb-5 text-center py-6">
                                <div class="empty-icon" style="width: 3rem; height: 3rem; margin-bottom: 0.75rem;">
                                    <i class="fas fa-info-circle text-lg"></i>
                                </div>
                                <p class="text-sm text-gray-500">No payment records yet. Payments will appear here once deductions start.</p>
                            </div>
                        <?php endif; ?>

                        <!-- Remarks -->
                        <?php if (!empty($deduction['deduction_remarks'])): ?>
                            <div class="notice-box rounded-xl p-3 mb-5 border border-amber-100">
                                <div class="flex items-start gap-2.5">
                                    <div class="w-7 h-7 rounded-lg bg-amber-100 flex items-center justify-center flex-shrink-0">
                                        <i class="fas fa-comment-dots text-amber-600 text-[10px]"></i>
                                    </div>
                                    <div>
                                        <p class="text-[11px] font-semibold text-amber-700 uppercase tracking-wider">Remarks</p>
                                        <p class="text-sm text-gray-700 mt-1 leading-relaxed"><?php echo htmlspecialchars($deduction['deduction_remarks']); ?></p>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Actions -->
                        <div class="flex flex-wrap justify-end gap-2 pt-4 border-t border-gray-100">
                            <a href="order_details.php?id=<?php echo $deduction['order_id']; ?>" class="btn-outline-brand">
                                <i class="fas fa-eye text-[10px]"></i>
                                View Order
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
        // Auto-dismiss flash messages
        setTimeout(() => {
            document.querySelectorAll('.flash-msg').forEach(msg => {
                msg.style.transition = 'all 0.4s ease';
                msg.style.opacity = '0';
                msg.style.transform = 'translateY(-8px)';
                setTimeout(() => msg.remove(), 400);
            });
        }, 8000); // Longer timeout for deduction notifications

        // Animate progress bars on load
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(() => {
                document.querySelectorAll('.progress-fill[data-width]').forEach(bar => {
                    bar.style.width = bar.getAttribute('data-width') + '%';
                });
            }, 200);
        });
    </script>
</body>
</html>