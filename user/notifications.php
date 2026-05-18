<?php
// user/notifications.php - Professional UI with Fixed Filtering
require_once '../includes/config.php';
require_once '../includes/session.php';

SessionManager::requireLogin();

$db = (new Database())->getConnection();
$userId = SessionManager::getUserId();
$functions = new SystemFunctions();

// Handle actions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'mark_read':
                try {
                    $stmt = $db->prepare("UPDATE notifications SET is_read = true WHERE notification_id = :id AND user_id = :user_id");
                    $stmt->execute([
                        ':id' => $_POST['notification_id'],
                        ':user_id' => $userId
                    ]);
                    $message = "Notification marked as read.";
                    $messageType = 'success';
                } catch (PDOException $e) {
                    $message = "Error: " . $e->getMessage();
                    $messageType = 'error';
                }
                break;
                
            case 'mark_all_read':
                try {
                    $stmt = $db->prepare("UPDATE notifications SET is_read = true WHERE user_id = :user_id AND is_read = false");
                    $stmt->execute([':user_id' => $userId]);
                    $message = "All notifications marked as read.";
                    $messageType = 'success';
                } catch (PDOException $e) {
                    $message = "Error: " . $e->getMessage();
                    $messageType = 'error';
                }
                break;
                
            case 'delete_all':
                try {
                    $stmt = $db->prepare("DELETE FROM notifications WHERE user_id = :user_id");
                    $stmt->execute([':user_id' => $userId]);
                    $message = "All notifications deleted.";
                    $messageType = 'success';
                } catch (PDOException $e) {
                    $message = "Error: " . $e->getMessage();
                    $messageType = 'error';
                }
                break;
                
            case 'delete':
                try {
                    $stmt = $db->prepare("DELETE FROM notifications WHERE notification_id = :id AND user_id = :user_id");
                    $stmt->execute([
                        ':id' => $_POST['notification_id'],
                        ':user_id' => $userId
                    ]);
                    $message = "Notification deleted.";
                    $messageType = 'success';
                } catch (PDOException $e) {
                    $message = "Error: " . $e->getMessage();
                    $messageType = 'error';
                }
                break;
        }
    }
}

// Get filter with proper validation
$filter = $_GET['filter'] ?? 'all';
$allowedFilters = ['all', 'unread', 'read'];
if (!in_array($filter, $allowedFilters)) {
    $filter = 'all';
}

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

// ===== FIXED FILTERING LOGIC =====
// Detect column type and use appropriate comparison
// For PostgreSQL BOOLEAN: use true/false
// For INTEGER: use 1/0
// For VARCHAR: use '1'/'0' or 't'/'f'

try {
    // First, detect the actual data type of is_read column
    $typeCheck = $db->query("
        SELECT data_type 
        FROM information_schema.columns 
        WHERE table_name = 'notifications' AND column_name = 'is_read'
    ");
    $columnType = $typeCheck->fetch(PDO::FETCH_ASSOC);
    $isReadType = $columnType['data_type'] ?? 'boolean';
    
    // Define values based on column type
    if ($isReadType === 'boolean') {
        $unreadValue = 'false';
        $readValue = 'true';
    } elseif ($isReadType === 'integer' || $isReadType === 'int4' || $isReadType === 'int2') {
        $unreadValue = '0';
        $readValue = '1';
    } elseif ($isReadType === 'character varying' || $isReadType === 'text') {
        // Check actual values in the column
        $sampleCheck = $db->query("SELECT DISTINCT is_read FROM notifications LIMIT 5");
        $samples = $sampleCheck->fetchAll(PDO::FETCH_COLUMN);
        
        if (in_array('f', $samples) || in_array('t', $samples)) {
            $unreadValue = "'f'";
            $readValue = "'t'";
        } elseif (in_array('0', $samples) || in_array('1', $samples)) {
            $unreadValue = "'0'";
            $readValue = "'1'";
        } elseif (in_array('false', $samples) || in_array('true', $samples)) {
            $unreadValue = "'false'";
            $readValue = "'true'";
        } else {
            // Default fallback
            $unreadValue = '0';
            $readValue = '1';
        }
    } else {
        // Default fallback for unknown types
        $unreadValue = '0';
        $readValue = '1';
    }
    
    // Build SQL with proper values (no parameter binding for is_read to avoid type issues)
    $countSql = "SELECT COUNT(*) as total FROM notifications WHERE user_id = :user_id";
    $countParams = [':user_id' => $userId];
    
    $sql = "SELECT * FROM notifications WHERE user_id = :user_id";
    $params = [':user_id' => $userId];
    
    // Apply filter with proper values based on column type
    if ($filter === 'unread') {
        $sql .= " AND is_read = " . $unreadValue;
        $countSql .= " AND is_read = " . $unreadValue;
    } elseif ($filter === 'read') {
        $sql .= " AND is_read = " . $readValue;
        $countSql .= " AND is_read = " . $readValue;
    }
    
    // Get total count
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($countParams);
    $totalRecords = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalRecords / $perPage);
    
    // Get notifications with pagination
    $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get counts for stats (using the same detection logic)
    $statsSql = "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_read = " . $unreadValue . " THEN 1 ELSE 0 END) as unread,
        SUM(CASE WHEN is_read = " . $readValue . " THEN 1 ELSE 0 END) as read_count
        FROM notifications WHERE user_id = :user_id";
    $statsStmt = $db->prepare($statsSql);
    $statsStmt->execute([':user_id' => $userId]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    // Ensure stats have values
    $stats['total'] = $stats['total'] ?? 0;
    $stats['unread'] = $stats['unread'] ?? 0;
    $stats['read_count'] = $stats['read_count'] ?? 0;
    
} catch (PDOException $e) {
    error_log("Notifications error: " . $e->getMessage());
    $notifications = [];
    $stats = ['total' => 0, 'unread' => 0, 'read_count' => 0];
    $totalPages = 0;
    $totalRecords = 0;
}

// Helper function for time ago
function timeAgo($datetime) {
    if (!$datetime) return 'Unknown';
    $timestamp = strtotime($datetime);
    $now = time();
    $diff = $now - $timestamp;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M d, Y', $timestamp);
    }
}

// Helper function for notification icon
function getNotificationIcon($title, $message, $type = null) {
    $titleLower = strtolower($title ?? '');
    $messageLower = strtolower($message ?? '');
    
    if ($type === 'order') return 'fa-shopping-cart';
    if ($type === 'return') return 'fa-undo-alt';
    if ($type === 'payment') return 'fa-credit-card';
    if ($type === 'welcome') return 'fa-hand-peace';
    if ($type === 'password') return 'fa-key';
    if ($type === 'profile') return 'fa-user-circle';
    
    if (strpos($titleLower, 'order') !== false || strpos($messageLower, 'order') !== false) {
        return 'fa-shopping-cart';
    } elseif (strpos($titleLower, 'password') !== false) {
        return 'fa-key';
    } elseif (strpos($titleLower, 'profile') !== false) {
        return 'fa-user-circle';
    } elseif (strpos($titleLower, 'welcome') !== false) {
        return 'fa-hand-peace';
    } elseif (strpos($titleLower, 'harvest') !== false) {
        return 'fa-fish';
    } elseif (strpos($titleLower, 'return') !== false) {
        return 'fa-undo-alt';
    } elseif (strpos($titleLower, 'payment') !== false || strpos($titleLower, 'deduction') !== false) {
        return 'fa-money-bill-wave';
    } else {
        return 'fa-bell';
    }
}

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

// Get user data
$userData = $functions->getUserById($userId);
$displayName = $userData['full_name'] ?? explode('@', $userData['email'] ?? 'User')[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - BISU IGE Aquaculture</title>
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
        * { font-family: 'Inter', sans-serif; }
        h1, h2, h3, .font-display { font-family: 'Playfair Display', serif; }
        
        body { background-color: #f8fafc; }
        
        .transition-smooth { transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); }
        
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
            text-align: center;
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
        
        .notification-item {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            transition: all 0.2s ease;
            border: 1px solid #e2e8f0;
        }
        
        .notification-item:hover {
            transform: translateX(4px);
            border-color: #cbd5e1;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }
        
        .notification-item.unread {
            border-left: 3px solid #0ea5e9;
            background: linear-gradient(90deg, #f0f9ff 0%, white 100%);
        }
        
        .notification-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
            flex-shrink: 0;
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
            background: white;
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
        
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>

    <!-- Flash Messages -->
    <?php if ($message): ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
        <div class="flash-msg shadow-sm" style="border-left-color: <?php echo $messageType == 'success' ? '#10b981' : '#ef4444'; ?>; border-left-width: 4px;">
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
                    <p class="text-brand-300 text-sm font-medium tracking-wide uppercase mb-2">Stay Updated</p>
                    <h1 class="text-2xl md:text-3xl font-bold text-white font-display">
                        Notifications
                    </h1>
                    <p class="text-brand-200/80 mt-2 text-sm max-w-md">Stay updated with your orders and system announcements.</p>
                </div>
                <div class="flex gap-3">
                    <?php if ($stats['unread'] > 0): ?>
                    <form method="POST" class="inline" onsubmit="return confirm('Mark all notifications as read?')">
                        <input type="hidden" name="action" value="mark_all_read">
                        <button type="submit" class="btn-secondary" style="border-color: rgba(255,255,255,0.2); color: white; background: rgba(255,255,255,0.1);">
                            <i class="fas fa-check-double text-sm"></i> Mark All Read
                        </button>
                    </form>
                    <?php endif; ?>
                    <?php if ($stats['total'] > 0): ?>
                    <form method="POST" class="inline" onsubmit="return confirm('Delete all notifications? This action cannot be undone.')">
                        <input type="hidden" name="action" value="delete_all">
                        <button type="submit" class="btn-secondary" style="border-color: rgba(255,255,255,0.2); color: white; background: rgba(255,255,255,0.1);">
                            <i class="fas fa-trash-alt text-sm"></i> Delete All
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="stat-card">
                <div class="flex items-center justify-center gap-2 mb-1">
                    <div class="w-8 h-8 rounded-lg bg-brand-50 flex items-center justify-center text-brand-600">
                        <i class="fas fa-bell text-xs"></i>
                    </div>
                    <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">Total</p>
                </div>
                <p class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['total']); ?></p>
            </div>
            <div class="stat-card">
                <div class="flex items-center justify-center gap-2 mb-1">
                    <div class="w-8 h-8 rounded-lg bg-amber-50 flex items-center justify-center text-amber-600">
                        <i class="fas fa-envelope text-xs"></i>
                    </div>
                    <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">Unread</p>
                </div>
                <p class="text-2xl font-bold text-amber-600"><?php echo number_format($stats['unread']); ?></p>
            </div>
            <div class="stat-card">
                <div class="flex items-center justify-center gap-2 mb-1">
                    <div class="w-8 h-8 rounded-lg bg-emerald-50 flex items-center justify-center text-emerald-600">
                        <i class="fas fa-check-circle text-xs"></i>
                    </div>
                    <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">Read</p>
                </div>
                <p class="text-2xl font-bold text-emerald-600"><?php echo number_format($stats['read_count']); ?></p>
            </div>
        </div>

        <!-- Filter Tabs -->
        <div class="mb-6">
            <div class="flex flex-wrap gap-2">
                <a href="?filter=all" class="filter-tab-pro <?php echo $filter === 'all' ? 'active' : ''; ?>">
                    <i class="fas fa-list text-[10px]"></i>
                    All
                    <span class="filter-count ml-1.5"><?php echo $stats['total']; ?></span>
                </a>
                <a href="?filter=unread" class="filter-tab-pro <?php echo $filter === 'unread' ? 'active' : ''; ?>">
                    <i class="fas fa-envelope text-[10px]"></i>
                    Unread
                    <span class="filter-count ml-1.5"><?php echo $stats['unread']; ?></span>
                </a>
                <a href="?filter=read" class="filter-tab-pro <?php echo $filter === 'read' ? 'active' : ''; ?>">
                    <i class="fas fa-check-circle text-[10px]"></i>
                    Read
                    <span class="filter-count ml-1.5"><?php echo $stats['read_count']; ?></span>
                </a>
            </div>
        </div>

        <!-- Notifications List -->
        <div class="space-y-3">
            <?php if (empty($notifications)): ?>
                <div class="pro-card p-12 text-center">
                    <div class="empty-icon">
                        <i class="fas fa-bell-slash"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">No notifications</h3>
                    <p class="text-sm text-gray-500 mb-5">
                        <?php if ($filter === 'unread'): ?>
                            You have no unread notifications.
                        <?php elseif ($filter === 'read'): ?>
                            You have no read notifications.
                        <?php else: ?>
                            You don't have any notifications yet.
                        <?php endif; ?>
                    </p>
                    <a href="dashboard.php" class="btn-brand">
                        <i class="fas fa-home text-sm"></i> Go to Dashboard
                    </a>
                </div>
            <?php else: ?>
                <?php foreach ($notifications as $notification): 
                    $isUnread = !$notification['is_read'];
                    // Handle different is_read value types
                    if (is_bool($notification['is_read'])) {
                        $isUnread = !$notification['is_read'];
                    } elseif (is_numeric($notification['is_read'])) {
                        $isUnread = $notification['is_read'] == 0;
                    } elseif (is_string($notification['is_read'])) {
                        $isUnread = $notification['is_read'] === '0' || $notification['is_read'] === 'f' || $notification['is_read'] === 'false';
                    }
                    $icon = getNotificationIcon($notification['title'], $notification['message'], $notification['type'] ?? null);
                ?>
                    <div class="notification-item <?php echo $isUnread ? 'unread' : ''; ?>">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-start gap-3 flex-1">
                                <div class="notification-icon <?php echo $isUnread ? 'bg-brand-50 text-brand-600' : 'bg-gray-100 text-gray-500'; ?>">
                                    <i class="fas <?php echo $icon; ?>"></i>
                                </div>
                                <div class="flex-1">
                                    <div class="flex flex-wrap items-center gap-2 mb-1">
                                        <h3 class="font-semibold text-gray-900 text-sm <?php echo $isUnread ? 'text-brand-700' : ''; ?>">
                                            <?php echo htmlspecialchars($notification['title']); ?>
                                        </h3>
                                        <?php if ($isUnread): ?>
                                            <span class="text-[10px] bg-brand-100 text-brand-700 px-2 py-0.5 rounded-full font-medium">New</span>
                                        <?php endif; ?>
                                        <span class="text-[10px] text-gray-400">
                                            <i class="far fa-clock mr-1"></i><?php echo timeAgo($notification['created_at']); ?>
                                        </span>
                                    </div>
                                    <p class="text-sm text-gray-600 leading-relaxed">
                                        <?php echo nl2br(htmlspecialchars($notification['message'])); ?>
                                    </p>
                                </div>
                            </div>
                            <div class="flex gap-1.5 flex-shrink-0">
                                <?php if ($isUnread): ?>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="mark_read">
                                    <input type="hidden" name="notification_id" value="<?php echo $notification['notification_id']; ?>">
                                    <button type="submit" class="w-7 h-7 rounded-lg bg-brand-50 text-brand-600 hover:bg-brand-100 transition-colors" title="Mark as read">
                                        <i class="fas fa-check text-xs"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <form method="POST" class="inline" onsubmit="return confirm('Delete this notification?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="notification_id" value="<?php echo $notification['notification_id']; ?>">
                                    <button type="submit" class="w-7 h-7 rounded-lg bg-red-50 text-red-500 hover:bg-red-100 transition-colors" title="Delete">
                                        <i class="fas fa-trash-alt text-xs"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="mt-6 pt-2">
                    <?php 
                    $queryParams = $_GET;
                    unset($queryParams['page']);
                    echo buildPaginationLinks($page, $totalPages, $queryParams); 
                    ?>
                    <div class="text-center text-xs text-gray-400 mt-2">
                        Page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo number_format($totalRecords); ?> notifications)
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
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
        }, 5000);
    </script>
</body>
</html>