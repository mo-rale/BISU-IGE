<?php
// manager/process_returns.php - Manager Return Request Processing
require_once '../includes/config.php';
require_once '../includes/session.php';
require_once '../includes/FifoStock.php';

SessionManager::requireManagerOrStaff();

$db = (new Database())->getConnection();
$functions = new SystemFunctions();
$userId = SessionManager::getUserId();

$message = '';
$messageType = '';

// Get all return requests
$returnRequests = [];
try {
    $sql = "SELECT rr.*, 
                   u.full_name as customer_name, u.email, u.department,
                   fp.fish_name,
                   o.order_date, o.payment_method as order_payment_method,
                   sd.deduction_status, sd.remaining_balance,
                   pu.full_name as processor_name
            FROM return_requests rr
            JOIN users u ON rr.user_id = u.user_id
            LEFT JOIN fish_products fp ON rr.product_id = fp.product_id
            LEFT JOIN orders o ON rr.order_id = o.order_id
            LEFT JOIN salary_deductions sd ON rr.deduction_id = sd.deduction_id
            LEFT JOIN users pu ON rr.processed_by = pu.user_id
            ORDER BY 
                CASE rr.return_status 
                    WHEN 'pending' THEN 1 
                    WHEN 'approved' THEN 2 
                    ELSE 3 
                END,
                rr.created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute();
    $returnRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Returns error: " . $e->getMessage());
}

// Process return action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $return_id = (int)$_POST['return_id'];
        $action = $_POST['action'];

        $db->beginTransaction();

        // Get return request
        $reqSql = "SELECT * FROM return_requests WHERE return_id = :id FOR UPDATE";
        $reqStmt = $db->prepare($reqSql);
        $reqStmt->execute([':id' => $return_id]);
        $request = $reqStmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            throw new Exception("Return request not found");
        }

        if ($action === 'approve') {
            $refund_method = $_POST['refund_method'];
            $refund_amount = (float)$_POST['refund_amount'];
            $processed_remarks = trim($_POST['processed_remarks'] ?? '');

            // Update return request
            $updateSql = "UPDATE return_requests 
                          SET return_status = 'approved',
                              refund_method = :refund_method,
                              refund_amount = :refund_amount,
                              processed_by = :processed_by,
                              processed_date = NOW(),
                              processed_remarks = :remarks,
                              updated_at = NOW()
                          WHERE return_id = :id";
            $updateStmt = $db->prepare($updateSql);
            $updateStmt->execute([
                ':refund_method' => $refund_method,
                ':refund_amount' => $refund_amount,
                ':processed_by' => $userId,
                ':remarks' => $processed_remarks,
                ':id' => $return_id
            ]);

            // If deduction reversal, update salary_deductions
            if ($refund_method === 'deduction_reversal' && $request['deduction_id']) {
                $dedSql = "UPDATE salary_deductions 
                           SET total_amount = total_amount - :refund,
                               remaining_balance = remaining_balance - :refund2,
                               updated_at = NOW()
                           WHERE deduction_id = :did";
                $dedStmt = $db->prepare($dedSql);
                $dedStmt->execute([
                    ':refund' => $refund_amount,
                    ':refund2' => $refund_amount,
                    ':did' => $request['deduction_id']
                ]);
            }

            $message = "Return request #RET-" . str_pad($return_id, 5, '0', STR_PAD_LEFT) . " approved successfully!";
            $messageType = 'success';

        } elseif ($action === 'reject') {
            $processed_remarks = trim($_POST['processed_remarks'] ?? '');

            $updateSql = "UPDATE return_requests 
                          SET return_status = 'rejected',
                              processed_by = :processed_by,
                              processed_date = NOW(),
                              processed_remarks = :remarks,
                              updated_at = NOW()
                          WHERE return_id = :id";
            $updateStmt = $db->prepare($updateSql);
            $updateStmt->execute([
                ':processed_by' => $userId,
                ':remarks' => $processed_remarks,
                ':id' => $return_id
            ]);

            $message = "Return request rejected.";
            $messageType = 'info';

        } elseif ($action === 'complete') {
            $updateSql = "UPDATE return_requests 
                          SET return_status = 'completed',
                              refund_date = NOW(),
                              updated_at = NOW()
                          WHERE return_id = :id";
            $updateStmt = $db->prepare($updateSql);
            $updateStmt->execute([':id' => $return_id]);

            $message = "Return marked as completed.";
            $messageType = 'success';
        }

        $db->commit();

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $message = "Error: " . $e->getMessage();
        $messageType = 'error';
    }

    header("Location: process_returns.php?message=" . urlencode($message) . "&type=" . $messageType);
    exit();
}

if (isset($_GET['message'])) {
    $message = urldecode($_GET['message']);
    $messageType = $_GET['type'] ?? 'info';
}

// Status config
$statusConfig = [
    'pending' => [
        'color' => 'bg-amber-50 text-amber-700 ring-1 ring-amber-600/20',
        'icon' => 'fa-clock',
        'dot' => 'bg-amber-500',
        'border' => 'border-amber-400',
        'label' => 'Pending'
    ],
    'approved' => [
        'color' => 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-600/20',
        'icon' => 'fa-check-circle',
        'dot' => 'bg-emerald-500',
        'border' => 'border-emerald-400',
        'label' => 'Approved'
    ],
    'rejected' => [
        'color' => 'bg-rose-50 text-rose-700 ring-1 ring-rose-600/20',
        'icon' => 'fa-times-circle',
        'dot' => 'bg-rose-500',
        'border' => 'border-rose-400',
        'label' => 'Rejected'
    ],
    'completed' => [
        'color' => 'bg-sky-50 text-sky-700 ring-1 ring-sky-600/20',
        'icon' => 'fa-check-double',
        'dot' => 'bg-sky-500',
        'border' => 'border-sky-400',
        'label' => 'Completed'
    ]
];

// Reason config
$reasonConfig = [
    'damaged' => ['label' => 'Damaged Product', 'icon' => 'fa-box-open', 'color' => 'text-rose-600'],
    'rotten' => ['label' => 'Rotten / Spoiled', 'icon' => 'fa-skull', 'color' => 'text-violet-600'],
    'wrong_item' => ['label' => 'Wrong Item', 'icon' => 'fa-question-circle', 'color' => 'text-orange-600'],
    'quality' => ['label' => 'Poor Quality', 'icon' => 'fa-star-half-alt', 'color' => 'text-amber-600'],
    'other' => ['label' => 'Other', 'icon' => 'fa-ellipsis-h', 'color' => 'text-slate-600']
];

// Refund method config
$refundMethodConfig = [
    'cash' => ['label' => 'Cash Refund', 'icon' => 'fa-money-bill-wave', 'color' => 'text-emerald-600'],
    'deduction_reversal' => ['label' => 'Deduction Reversal', 'icon' => 'fa-undo', 'color' => 'text-violet-600'],
    'replacement' => ['label' => 'Product Replacement', 'icon' => 'fa-sync-alt', 'color' => 'text-sky-600']
];

// Count stats
$pendingCount = count(array_filter($returnRequests, fn($r) => $r['return_status'] === 'pending'));
$approvedCount = count(array_filter($returnRequests, fn($r) => $r['return_status'] === 'approved'));
$completedCount = count(array_filter($returnRequests, fn($r) => $r['return_status'] === 'completed'));
$rejectedCount = count(array_filter($returnRequests, fn($r) => $r['return_status'] === 'rejected'));
$totalRefund = array_sum(array_column(array_filter($returnRequests, fn($r) => in_array($r['return_status'], ['approved', 'completed'])), 'refund_amount'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Process Returns - BISU IGE Aquaculture</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
        .tabular-nums { font-family: 'IBM Plex Mono', 'SF Mono', 'Segoe UI Mono', monospace; font-variant-numeric: tabular-nums; }
        body { background: #f8fafc; }

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
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(239, 68, 68, 0.12) 0%, transparent 70%);
            border-radius: 50%;
        }
        .page-header::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -5%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(14, 165, 233, 0.08) 0%, transparent 70%);
            border-radius: 50%;
        }

        .stat-card {
            background: white;
            border-radius: 0.875rem;
            padding: 1.25rem;
            border: 1px solid #e2e8f0;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
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
            background: linear-gradient(90deg, #0ea5e9, #0284c7);
            opacity: 0;
            transition: opacity 0.25s ease;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 32px -8px rgba(15, 23, 42, 0.08);
            border-color: #cbd5e1;
        }
        .stat-card:hover::before { opacity: 1; }

        .stat-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.625rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .return-card {
            background: white;
            border-radius: 0.875rem;
            border: 1px solid #e2e8f0;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            position: relative;
        }
        .return-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px -4px rgba(15, 23, 42, 0.06);
            border-color: #cbd5e1;
        }
        .return-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            transition: width 0.2s ease;
        }
        .return-card:hover::before { width: 6px; }
        .return-card.pending::before { background: linear-gradient(180deg, #f59e0b, #d97706); }
        .return-card.approved::before { background: linear-gradient(180deg, #10b981, #059669); }
        .return-card.rejected::before { background: linear-gradient(180deg, #ef4444, #dc2626); }
        .return-card.completed::before { background: linear-gradient(180deg, #0ea5e9, #0284c7); }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.875rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.01em;
        }
        .status-badge .status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            display: inline-block;
        }

        .id-badge {
            font-family: 'IBM Plex Mono', 'SF Mono', monospace;
            font-variant-numeric: tabular-nums;
            font-size: 0.6875rem;
            font-weight: 600;
            letter-spacing: 0.02em;
        }

        .amount-cell {
            font-family: 'IBM Plex Mono', 'SF Mono', monospace;
            font-variant-numeric: tabular-nums;
            font-weight: 600;
            letter-spacing: -0.02em;
        }

        .btn-success {
            background: #10b981;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 0.625rem;
            font-weight: 500;
            font-size: 0.8125rem;
            transition: all 0.2s ease;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-success:hover { background: #059669; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25); }

        .btn-danger {
            background: #ef4444;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 0.625rem;
            font-weight: 500;
            font-size: 0.8125rem;
            transition: all 0.2s ease;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-danger:hover { background: #dc2626; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(239, 68, 68, 0.25); }

        .btn-secondary {
            background: #f1f5f9;
            color: #475569;
            padding: 0.5rem 1rem;
            border-radius: 0.625rem;
            font-weight: 500;
            font-size: 0.8125rem;
            transition: all 0.2s ease;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-secondary:hover { background: #e2e8f0; }

        .btn-ghost {
            background: transparent;
            color: #64748b;
            padding: 0.5rem 1rem;
            border-radius: 0.625rem;
            font-weight: 500;
            font-size: 0.8125rem;
            transition: all 0.2s ease;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-ghost:hover { background: #f8fafc; }

        .info-pill {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
            padding: 0.625rem 0.875rem;
            transition: all 0.15s ease;
        }
        .info-pill:hover { background: #f1f5f9; border-color: #cbd5e1; }

        .reason-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.25rem 0.625rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 500;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }

        .modal {
            background: rgba(15, 23, 42, 0.5);
            backdrop-filter: blur(6px);
        }

        .flash-message {
            animation: slideIn 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-12px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .empty-state {
            text-align: center;
            padding: 3.5rem 2rem;
        }
        .empty-state-icon {
            width: 4rem;
            height: 4rem;
            background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
            border-radius: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.5rem;
            color: #94a3b8;
        }

        .filter-select {
            border: 1px solid #e2e8f0;
            border-radius: 0.625rem;
            padding: 0.5rem 0.75rem;
            font-size: 0.8125rem;
            background: white;
            transition: all 0.2s ease;
        }
        .filter-select:focus {
            outline: none;
            border-color: #0ea5e9;
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.08);
        }

        .filter-input {
            border: 1px solid #e2e8f0;
            border-radius: 0.625rem;
            padding: 0.5rem 0.75rem;
            font-size: 0.8125rem;
            background: white;
            transition: all 0.2s ease;
            width: 100%;
        }
        .filter-input:focus {
            outline: none;
            border-color: #0ea5e9;
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.08);
        }
    </style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<?php if ($message): ?>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
    <div class="flash-message rounded-xl p-4 <?php echo $messageType==='success'?'bg-emerald-50 border border-emerald-200':($messageType==='error'?'bg-rose-50 border border-rose-200':'bg-sky-50 border border-sky-200');?>">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-full <?php echo $messageType==='success'?'bg-emerald-100':($messageType==='error'?'bg-rose-100':'bg-sky-100');?> flex items-center justify-center">
                <i class="fas <?php echo $messageType==='success'?'fa-check text-emerald-600':($messageType==='error'?'fa-exclamation text-rose-600':'fa-info text-sky-600');?> text-sm"></i>
            </div>
            <p class="text-sm font-medium <?php echo $messageType==='success'?'text-emerald-800':($messageType==='error'?'text-rose-800':'text-sky-800');?>">
                <?php echo htmlspecialchars($message);?>
            </p>
            <button onclick="this.closest('.flash-message').remove()" class="ml-auto text-gray-400 hover:text-gray-600 w-6 h-6 flex items-center justify-center rounded-lg hover:bg-gray-100 transition">
                <i class="fas fa-times text-xs"></i>
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Page Header -->
<div class="page-header py-10">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <div class="flex items-center gap-3 mb-2">
                    <div class="w-10 h-10 rounded-xl bg-white/10 backdrop-blur flex items-center justify-center">
                        <i class="fas fa-cogs text-white text-lg"></i>
                    </div>
                    <h1 class="text-2xl font-bold text-white tracking-tight">Process Return Requests</h1>
                </div>
                <p class="text-slate-400 text-sm ml-[3.25rem]">Review, approve, and manage customer return workflows</p>
            </div>
            <div class="flex items-center gap-3">
                <a href="returns.php" class="btn-ghost bg-white/10 text-white border-white/20 hover:bg-white/20">
                    <i class="fas fa-undo-alt"></i>
                    Manage Returns
                </a>
                <a href="dashboard.php" class="btn-ghost bg-white/10 text-white border-white/20 hover:bg-white/20">
                    <i class="fas fa-chart-line"></i>
                    Dashboard
                </a>
            </div>
        </div>
    </div>
</div>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <!-- Statistics Cards -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
        <div class="stat-card">
            <div class="flex items-center justify-between mb-3">
                <div class="stat-icon bg-amber-50 text-amber-600">
                    <i class="fas fa-clock"></i>
                </div>
                <span class="text-xs font-medium text-slate-400">Pending</span>
            </div>
            <p class="tabular-nums text-2xl font-bold text-slate-900"><?php echo number_format($pendingCount); ?></p>
        </div>
        <div class="stat-card">
            <div class="flex items-center justify-between mb-3">
                <div class="stat-icon bg-emerald-50 text-emerald-600">
                    <i class="fas fa-check-circle"></i>
                </div>
                <span class="text-xs font-medium text-slate-400">Approved</span>
            </div>
            <p class="tabular-nums text-2xl font-bold text-emerald-600"><?php echo number_format($approvedCount); ?></p>
        </div>
        <div class="stat-card">
            <div class="flex items-center justify-between mb-3">
                <div class="stat-icon bg-rose-50 text-rose-600">
                    <i class="fas fa-times-circle"></i>
                </div>
                <span class="text-xs font-medium text-slate-400">Rejected</span>
            </div>
            <p class="tabular-nums text-2xl font-bold text-rose-600"><?php echo number_format($rejectedCount); ?></p>
        </div>
        <div class="stat-card">
            <div class="flex items-center justify-between mb-3">
                <div class="stat-icon bg-sky-50 text-sky-600">
                    <i class="fas fa-check-double"></i>
                </div>
                <span class="text-xs font-medium text-slate-400">Completed</span>
            </div>
            <p class="tabular-nums text-2xl font-bold text-sky-600"><?php echo number_format($completedCount); ?></p>
        </div>
        <div class="stat-card">
            <div class="flex items-center justify-between mb-3">
                <div class="stat-icon bg-violet-50 text-violet-600">
                    <i class="fas fa-peso-sign"></i>
                </div>
                <span class="text-xs font-medium text-slate-400">Total Refunded</span>
            </div>
            <p class="tabular-nums text-2xl font-bold text-violet-600">₱<?php echo number_format($totalRefund, 2); ?></p>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="bg-white rounded-xl border border-gray-200 p-4 mb-6 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <span class="text-sm font-medium text-slate-600">
                    <i class="fas fa-list text-slate-400 mr-1"></i>
                    <?php echo count($returnRequests); ?> return request(s)
                </span>
            </div>
            <div class="flex items-center gap-2">
                <select id="statusFilter" class="filter-select" onchange="filterCards()">
                    <option value="all">All Statuses</option>
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                    <option value="completed">Completed</option>
                </select>
            </div>
        </div>
    </div>

    <?php if (empty($returnRequests)): ?>
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="fas fa-inbox"></i>
                </div>
                <h3 class="text-base font-semibold text-slate-700 mb-1">No return requests</h3>
                <p class="text-sm text-slate-400">There are no return requests to process at this time</p>
            </div>
        </div>
    <?php else: ?>
        <div class="space-y-4" id="returnsContainer">
            <?php foreach ($returnRequests as $req): 
                $status = $req['return_status'];
                $config = $statusConfig[$status] ?? $statusConfig['pending'];
                $reasonInfo = $reasonConfig[$req['return_reason']] ?? ['label' => ucfirst(str_replace('_', ' ', $req['return_reason'])), 'icon' => 'fa-question-circle', 'color' => 'text-slate-600'];
                $refundInfo = $refundMethodConfig[$req['refund_method']] ?? null;
            ?>
            <div class="return-card <?php echo $status; ?>" data-status="<?php echo $status; ?>">
                <div class="p-5 pl-6">
                    <!-- Header Row -->
                    <div class="flex flex-wrap justify-between items-start gap-3 mb-4">
                        <div class="flex items-center gap-3 flex-wrap">
                            <span class="id-badge bg-slate-100 text-slate-600 px-2.5 py-1 rounded-md">
                                #RET-<?php echo str_pad($req['return_id'], 5, '0', STR_PAD_LEFT); ?>
                            </span>
                            <span class="id-badge bg-sky-50 text-sky-700 px-2.5 py-1 rounded-md">
                                Order #<?php echo str_pad($req['order_id'], 6, '0', STR_PAD_LEFT); ?>
                            </span>
                            <span class="status-badge <?php echo $config['color']; ?>">
                                <span class="status-dot <?php echo $config['dot']; ?>"></span>
                                <?php echo $config['label']; ?>
                            </span>
                        </div>
                        <div class="text-right">
                            <p class="text-xs text-slate-400 tabular-nums">
                                <i class="far fa-calendar-alt mr-1"></i>
                                <?php echo date('M d, Y g:i A', strtotime($req['created_at'])); ?>
                            </p>
                            <?php if ($req['processed_date']): ?>
                                <p class="text-[10px] text-slate-400 mt-0.5 tabular-nums">
                                    Processed <?php echo date('M d, Y', strtotime($req['processed_date'])); ?> 
                                    by <span class="font-medium text-slate-500"><?php echo htmlspecialchars($req['processor_name'] ?? 'N/A'); ?></span>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Customer Info -->
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-slate-100 to-slate-200 flex items-center justify-center text-slate-500 font-bold text-sm">
                            <?php echo strtoupper(substr($req['customer_name'], 0, 1)); ?>
                        </div>
                        <div>
                            <p class="font-semibold text-slate-900 text-sm"><?php echo htmlspecialchars($req['customer_name']); ?></p>
                            <p class="text-xs text-slate-400">
                                <?php echo htmlspecialchars($req['department'] ?? 'N/A'); ?> • 
                                <?php echo htmlspecialchars($req['email']); ?>
                            </p>
                        </div>
                    </div>

                    <!-- Info Grid -->
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mb-4">
                        <div class="info-pill">
                            <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-0.5">Product</p>
                            <p class="text-sm font-medium text-slate-700 truncate"><?php echo htmlspecialchars($req['fish_name'] ?? 'N/A'); ?></p>
                        </div>
                        <div class="info-pill">
                            <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-0.5">Reason</p>
                            <p class="text-sm font-medium text-slate-700 flex items-center gap-1">
                                <i class="fas <?php echo $reasonInfo['icon']; ?> <?php echo $reasonInfo['color']; ?> text-[10px]"></i>
                                <?php echo $reasonInfo['label']; ?>
                            </p>
                        </div>
                        <div class="info-pill">
                            <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-0.5">Quantity</p>
                            <p class="text-sm font-medium text-slate-700 tabular-nums">
                                <?php echo number_format($req['return_quantity'], 2); ?> kg
                                <span class="text-slate-400 text-xs">/ <?php echo number_format($req['original_quantity'], 2); ?> kg</span>
                            </p>
                        </div>
                        <div class="info-pill">
                            <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-0.5">Return Amount</p>
                            <p class="amount-cell text-sm text-rose-600">₱<?php echo number_format($req['return_amount'], 2); ?></p>
                        </div>
                    </div>

                    <!-- Description -->
                    <?php if ($req['return_description']): ?>
                    <div class="bg-slate-50 rounded-lg p-3 mb-4 border border-slate-100">
                        <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-1">Customer Description</p>
                        <p class="text-sm text-slate-600 leading-relaxed"><?php echo nl2br(htmlspecialchars($req['return_description'])); ?></p>
                    </div>
                    <?php endif; ?>

                    <!-- Refund Info -->
                    <?php if ($refundInfo && $req['refund_amount'] > 0): ?>
                    <div class="bg-emerald-50 rounded-lg p-3 mb-4 border border-emerald-100">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <i class="fas <?php echo $refundInfo['icon']; ?> <?php echo $refundInfo['color']; ?> text-sm"></i>
                                <span class="text-sm font-medium text-emerald-800"><?php echo $refundInfo['label']; ?></span>
                            </div>
                            <span class="amount-cell text-emerald-700 font-bold">₱<?php echo number_format($req['refund_amount'], 2); ?></span>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Deduction Info -->
                    <?php if ($req['deduction_id']): ?>
                    <div class="bg-violet-50 rounded-lg p-3 mb-4 border border-violet-100">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <i class="fas fa-file-invoice-dollar text-violet-500 text-sm"></i>
                                <span class="text-sm font-medium text-violet-800">Linked Salary Deduction</span>
                            </div>
                            <span class="id-badge text-violet-700">#DED-<?php echo str_pad($req['deduction_id'], 3, '0', STR_PAD_LEFT); ?></span>
                        </div>
                        <?php if ($req['remaining_balance'] !== null): ?>
                        <p class="text-xs text-violet-600 mt-1 tabular-nums">Remaining Balance: ₱<?php echo number_format($req['remaining_balance'], 2); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Action Buttons -->
                    <?php if ($status === 'pending'): ?>
                    <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
                        <button onclick="openModal(<?php echo $req['return_id']; ?>, 'approve', <?php echo $req['return_amount']; ?>, <?php echo $req['deduction_id'] ?? 0; ?>)" class="btn-success">
                            <i class="fas fa-check text-xs"></i> Approve
                        </button>
                        <button onclick="openModal(<?php echo $req['return_id']; ?>, 'reject', 0, 0)" class="btn-danger">
                            <i class="fas fa-times text-xs"></i> Reject
                        </button>
                    </div>
                    <?php elseif ($status === 'approved'): ?>
                    <div class="flex justify-end pt-3 border-t border-slate-100">
                        <button onclick="completeReturn(<?php echo $req['return_id']; ?>)" class="btn-success">
                            <i class="fas fa-check-double text-xs"></i> Mark as Completed
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Process Modal -->
<div id="processModal" class="modal fixed inset-0 hidden items-center justify-center z-50">
    <div class="bg-white rounded-2xl max-w-lg w-full mx-4 shadow-2xl overflow-hidden">
        <div class="border-b border-gray-200 px-6 py-4 flex justify-between items-center bg-slate-50/50">
            <h3 class="text-lg font-semibold flex items-center gap-2 text-slate-800" id="modalTitle">
                <i class="fas fa-cogs text-slate-400"></i>
                Process Return
            </h3>
            <button onclick="closeModal()" class="w-8 h-8 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-400 hover:text-slate-600 transition">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" id="processForm" class="p-6">
            <input type="hidden" name="action" id="modalAction">
            <input type="hidden" name="return_id" id="modalReturnId">

            <div id="approveFields" style="display:none;">
                <div class="mb-4 p-4 bg-rose-50 rounded-xl border border-rose-100">
                    <p class="text-xs font-semibold text-rose-400 uppercase tracking-wider mb-1">Refund Amount</p>
                    <p class="amount-cell text-2xl font-bold text-rose-600" id="modalAmount"></p>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-semibold text-slate-700 mb-2">Refund Method <span class="text-rose-500">*</span></label>
                    <select name="refund_method" id="refundMethod" class="filter-select" onchange="toggleDeductionWarning()">
                        <option value="cash">Cash Refund</option>
                        <option value="deduction_reversal">Deduction Reversal</option>
                        <option value="replacement">Product Replacement</option>
                    </select>
                    <input type="hidden" name="refund_amount" id="modalRefundAmount">
                </div>

                <div id="deductionWarning" class="mb-4 p-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800" style="display:none;">
                    <div class="flex items-start gap-2">
                        <i class="fas fa-exclamation-triangle text-amber-500 mt-0.5"></i>
                        <span>This will reduce the employee's salary deduction balance.</span>
                    </div>
                </div>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-semibold text-slate-700 mb-2">Processing Remarks <span class="text-rose-500">*</span></label>
                <textarea name="processed_remarks" rows="3" required class="filter-input resize-none" placeholder="Enter your processing notes..."></textarea>
            </div>

            <div class="flex justify-end gap-3">
                <button type="button" onclick="closeModal()" class="btn-secondary">Cancel</button>
                <button type="submit" class="btn-success" id="modalSubmitBtn">
                    <i class="fas fa-check"></i> Confirm
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let hasDeduction = false;

function openModal(returnId, action, amount, deductionId) {
    document.getElementById('modalReturnId').value = returnId;
    document.getElementById('modalAction').value = action;
    hasDeduction = deductionId > 0;

    if (action === 'approve') {
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-check-circle text-emerald-500 mr-2"></i> Approve Return Request';
        document.getElementById('approveFields').style.display = 'block';
        document.getElementById('modalAmount').textContent = '₱' + parseFloat(amount).toLocaleString(undefined, {minimumFractionDigits: 2});
        document.getElementById('modalRefundAmount').value = amount;
        document.getElementById('refundMethod').value = 'cash';
        document.getElementById('deductionWarning').style.display = 'none';
        document.getElementById('modalSubmitBtn').className = 'btn-success';
        document.getElementById('modalSubmitBtn').innerHTML = '<i class="fas fa-check mr-1"></i> Approve';
    } else {
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-times-circle text-rose-500 mr-2"></i> Reject Return Request';
        document.getElementById('approveFields').style.display = 'none';
        document.getElementById('modalSubmitBtn').className = 'btn-danger';
        document.getElementById('modalSubmitBtn').innerHTML = '<i class="fas fa-times mr-1"></i> Reject';
    }

    document.getElementById('processModal').classList.remove('hidden');
    document.getElementById('processModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    document.getElementById('processModal').classList.add('hidden');
    document.getElementById('processModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

function toggleDeductionWarning() {
    const method = document.getElementById('refundMethod').value;
    document.getElementById('deductionWarning').style.display = (method === 'deduction_reversal' && hasDeduction) ? 'block' : 'none';
}

function completeReturn(returnId) {
    if (confirm('Mark this return as completed? This action cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `<input type="hidden" name="action" value="complete">
                         <input type="hidden" name="return_id" value="${returnId}">`;
        document.body.appendChild(form);
        form.submit();
    }
}

function filterCards() {
    const filter = document.getElementById('statusFilter').value;
    const cards = document.querySelectorAll('.return-card');
    let visible = 0;

    cards.forEach(card => {
        const status = card.getAttribute('data-status');
        if (filter === 'all' || status === filter) {
            card.style.display = 'block';
            visible++;
        } else {
            card.style.display = 'none';
        }
    });

    // Update count display
    const countEl = document.querySelector('.filter-bar-count');
    if (countEl) countEl.textContent = visible + ' shown';
}

document.getElementById('processForm').addEventListener('submit', function(e) {
    const remarks = document.querySelector('[name="processed_remarks"]').value.trim();
    if (!remarks) {
        e.preventDefault();
        alert('Please enter processing remarks');
        return false;
    }
    return true;
});

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
window.onclick = e => { if (e.target === document.getElementById('processModal')) closeModal(); };

// Auto-hide flash messages
setTimeout(() => {
    document.querySelectorAll('.flash-message').forEach(msg => {
        msg.style.transition = 'opacity 0.5s, transform 0.5s';
        msg.style.opacity = '0';
        msg.style.transform = 'translateY(-12px)';
        setTimeout(() => msg.remove(), 500);
    });
}, 4000);
</script>
</body>
</html>